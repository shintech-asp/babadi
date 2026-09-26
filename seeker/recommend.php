<?php
// seeker/recommend.php — Decision Support System: "Find My Match"
// Structured-filter wizard + deterministic ranking (Phase 1 — no Groq yet).
// Submits via GET to itself so results are bookmarkable and the back
// button works; the parse cache/LLM layer (Phase 3) will key off need_text
// without changing this URL shape.
chdir(dirname(__DIR__));
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/dss_helper.php');

// Open to everyone, including guests — only booking a listing (handled by
// the existing request-service.php flow) requires login, unchanged.
$dssUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$database = new Database();
$db = $database->getConnection();

$categories = $db->query("SELECT id, name FROM service_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = $db->query("SELECT DISTINCT city FROM providers WHERE status = 'active' AND city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
$budgetBounds = $db->query("SELECT MIN(price) AS min_price, MAX(price) AS max_price FROM services WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);

$submitted   = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$needText    = trim((string)($_GET['need_text'] ?? ''));
$categoryId  = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$budgetMax   = isset($_GET['budget_max']) && $_GET['budget_max'] !== '' ? (float)$_GET['budget_max'] : null;
$urgency     = in_array($_GET['urgency'] ?? '', ['flexible', 'soon', 'emergency'], true) ? $_GET['urgency'] : 'flexible';
$priority    = in_array($_GET['priority'] ?? '', ['balanced', 'best_rated', 'best_value'], true) ? $_GET['priority'] : 'balanced';
$ecoOnly     = isset($_GET['eco']) && $_GET['eco'] === '1';
$city        = trim((string)($_GET['city'] ?? ''));

$results = [];
$candidateCount = 0;
$degraded = false;
$parsedCategoryName = null;
$parsedBy = 'rules';

if ($submitted) {
    // Category chip wins over free-text parsing; only run the rule parser
    // when the seeker didn't pick a chip but did type something. The rule
    // parser's keyword map only covers a handful of English/Tagalog terms —
    // Groq is called ONLY when it comes back with low/no confidence, so a
    // typical exact-keyword hit ("termite", "daga") never touches the API
    // at all. This keeps free-tier usage low while still handling indirect
    // or mixed-language (Taglish) phrasing the keyword map can't catch.
    if ($categoryId === null && $needText !== '') {
        $ruleParsed = dssParseNeedRules($needText, $categories);
        if ($ruleParsed['confidence'] >= 0.5) {
            $categoryId = $ruleParsed['category_id'];
            $parsedBy = 'rules';
        } else {
            $groqParsed = dssParseNeedGroq($needText, $categories);
            if ($groqParsed !== null && $groqParsed['category_id'] !== null && $groqParsed['confidence'] >= 0.5) {
                $categoryId = $groqParsed['category_id'];
                $parsedBy = 'groq';
            } elseif ($ruleParsed['category_id'] !== null) {
                // Neither was confident, but the rule parser at least found
                // something — better than no filter at all.
                $categoryId = $ruleParsed['category_id'];
                $parsedBy = 'rules';
            }
        }
    }
    if ($categoryId !== null) {
        foreach ($categories as $c) {
            if ((int)$c['id'] === $categoryId) { $parsedCategoryName = $c['name']; break; }
        }
    }

    $filters = [
        'need_text'   => $needText !== '' ? $needText : null,
        'category_id' => $categoryId,
        'budget_max'  => $budgetMax,
        'urgency'     => $urgency,
        'priority'    => $priority,
        'eco_only'    => $ecoOnly,
        'city'        => $city !== '' ? $city : null,
    ];

    $ranked = dssRank($db, $filters, 8);
    $candidateCount = $ranked['candidate_count'];
    $degraded = $ranked['degraded'];

    // One batched Groq call for every result on the page (not one per
    // item). Falls back to the template sentence per-row on failure or
    // for any rank Groq's response left out.
    $groqExplanations = dssGenerateExplanationsGroq($ranked['results']);

    foreach ($ranked['results'] as $i => $row) {
        $row['explanation'] = $groqExplanations[$i + 1] ?? dssTemplateExplanation($row);
        $results[] = $row;
    }

    dssLogQuery($db, $dssUserId, $filters, $parsedBy, $ranked);
}

$current_page = 'recommend';
$use_seeker_unified_ui = true;
$page_title = 'Find My Match';
ob_start();
?>
    <style>
        .dss-page      { min-height: 100vh; background: #f5f7fa; padding: 40px 20px; }
        .dss-container { max-width: 900px; margin: 0 auto; }
        .dss-header     { text-align: center; margin-bottom: 35px; }
        .dss-header h1  { font-size: 32px; color: var(--dark-color); margin-bottom: 8px; }
        .dss-header p   { font-size: 15px; color: #666; }

        .dss-form { background: white; border-radius: 14px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 35px; }
        .dss-field { margin-bottom: 24px; }
        .dss-field label { display: block; font-size: 14px; font-weight: 700; color: #333; margin-bottom: 10px; }
        .dss-field .hint { font-weight: 400; color: #999; font-size: 12px; }
        .dss-field textarea, .dss-field input[type=number], .dss-field select {
            width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 14px; font-family: inherit;
        }
        .dss-field textarea:focus, .dss-field input:focus, .dss-field select:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }

        .chip-row { display: flex; flex-wrap: wrap; gap: 10px; }
        .chip-option { display: none; }
        .chip-label {
            padding: 9px 16px; border: 1.5px solid #ddd; border-radius: 999px;
            font-size: 13px; font-weight: 600; color: #555; cursor: pointer; transition: all .2s;
        }
        .chip-option:checked + .chip-label {
            background: var(--primary); border-color: var(--primary); color: white;
        }

        .toggle-row { display: flex; align-items: center; gap: 10px; }

        .dss-submit {
            width: 100%; padding: 14px; border: none; border-radius: 8px;
            background: var(--primary); color: white; font-size: 15px; font-weight: 700; cursor: pointer;
        }
        .dss-submit:hover { background: #2980b9; }

        .interp-row {
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
            background: #eaf4fc; border: 1px solid #cde6f7; border-radius: 10px;
            padding: 12px 16px; margin-bottom: 25px; font-size: 13px; color: #1a4a63;
        }
        .interp-pill { background: white; border-radius: 999px; padding: 4px 12px; font-weight: 600; }
        .interp-row a { margin-left: auto; color: var(--primary); font-weight: 600; text-decoration: none; }

        .degraded-note {
            background: #fff8e6; border: 1px solid #ffe6a3; border-radius: 10px;
            padding: 12px 16px; margin-bottom: 25px; font-size: 13px; color: #7d5a00;
        }

        .result-card {
            background: white; border-radius: 14px; padding: 22px; margin-bottom: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; gap: 20px;
        }
        .result-card.top { border: 2px solid var(--primary); }
        .result-rank {
            flex-shrink: 0; width: 56px; height: 56px; border-radius: 50%;
            background: #eaf4fc; color: var(--primary); font-weight: 800; font-size: 20px;
            display: flex; align-items: center; justify-content: center;
        }
        .result-card.top .result-rank { background: var(--primary); color: white; }
        .result-body { flex: 1; min-width: 0; }
        .result-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
        .result-title { font-size: 17px; font-weight: 700; color: #1a1a2e; }
        .result-company { font-size: 13px; color: #888; margin-top: 2px; }
        .result-score { font-size: 22px; font-weight: 800; color: var(--primary); white-space: nowrap; }
        .result-score-label { font-size: 11px; color: #999; font-weight: 500; }
        .result-meta { display: flex; gap: 16px; flex-wrap: wrap; margin: 10px 0; font-size: 13px; color: #555; }
        .result-meta span i { color: var(--primary); margin-right: 4px; }
        .result-explain { font-size: 13.5px; color: #444; line-height: 1.6; margin-bottom: 12px; }

        .review-snippets { margin-bottom: 14px; }
        .review-snippet {
            background: #f8f9fa; border-left: 3px solid #dee2e6; border-radius: 0 8px 8px 0;
            padding: 10px 14px; margin-bottom: 8px;
        }
        .review-snippet:last-child { margin-bottom: 0; }
        .review-snippet-top { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
        .review-snippet-stars i { font-size: 11px; }
        .review-snippet-author { font-size: 11px; color: #999; }
        .review-snippet-text { font-size: 13px; color: #444; line-height: 1.5; font-style: italic; }
        .review-snippet-img {
            display: block; margin-top: 8px; max-width: 160px; max-height: 120px;
            border-radius: 8px; object-fit: cover; cursor: pointer; border: 1px solid #e0e0e0;
            transition: all .2s;
        }
        .review-snippet-img.expanded { max-width: 100%; max-height: 400px; }

        .breakdown-toggle-btn {
            font-size: 12px; font-weight: 600; color: var(--primary); background: none; border: none;
            cursor: pointer; padding: 0; margin-bottom: 10px; display: inline-flex; align-items: center; gap: 5px;
        }
        .breakdown-panel { display: none; background: #fafbfc; border: 1px solid #eee; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
        .breakdown-panel.open { display: block; }
        .bd-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 12px; }
        .bd-row:last-child { margin-bottom: 0; }
        .bd-label { width: 110px; min-width: 110px; font-weight: 600; color: #555; }
        .bd-bar-wrap { flex: 1; background: #e9ecef; border-radius: 99px; height: 7px; overflow: hidden; }
        .bd-bar { height: 100%; background: var(--primary); border-radius: 99px; }
        .bd-note { flex-shrink: 0; color: #888; max-width: 220px; text-align: right; }

        .result-actions a {
            display: inline-block; padding: 9px 18px; border-radius: 7px; text-decoration: none;
            font-size: 13px; font-weight: 700; background: var(--primary); color: white;
        }
        .result-actions a:hover { background: #2980b9; }

        .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 14px; }
        .empty-state i { font-size: 56px; color: #bdc3c7; margin-bottom: 18px; }
        .empty-state h2 { font-size: 22px; color: var(--dark-color); margin-bottom: 8px; }
        .empty-state p { color: #666; margin-bottom: 20px; }

        @media (max-width: 600px) {
            .result-card { flex-direction: column; }
            .bd-note { display: none; }
        }
    </style>
<?php
$extra_head = ob_get_clean();
include appPath('includes/header.php');
?>

    <div class="dss-page">
        <div class="dss-container">
            <div class="dss-header">
                <h1><i class="fas fa-wand-magic-sparkles"></i> Find My Match</h1>
                <p>Tell us what you need — we'll rank providers for you based on reviews, price, and reliability.</p>
            </div>

            <form class="dss-form" method="GET" action="<?= appUrl('recommend.php') ?>">
                <input type="hidden" name="submitted" value="1">

                <div class="dss-field">
                    <label>What's the problem? <span class="hint">(optional — English or Tagalog, or pick a category below)</span></label>
                    <textarea name="need_text" rows="2" placeholder="e.g. I have termites in my kitchen cabinets / May anay sa kusina, kailangan ayusin agad"><?= escape($needText) ?></textarea>
                </div>

                <div class="dss-field">
                    <label>Category <span class="hint">(pick one, or leave blank to let your description decide)</span></label>
                    <div class="chip-row">
                        <?php foreach ($categories as $cat): ?>
                            <input type="radio" class="chip-option" name="category_id" id="cat-<?= $cat['id'] ?>"
                                   value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'checked' : '' ?>>
                            <label class="chip-label" for="cat-<?= $cat['id'] ?>"><?= escape($cat['name']) ?></label>
                        <?php endforeach; ?>
                        <input type="radio" class="chip-option" name="category_id" id="cat-any" value="" <?= $categoryId === null ? 'checked' : '' ?>>
                        <label class="chip-label" for="cat-any">Not sure</label>
                    </div>
                </div>

                <div class="dss-field">
                    <label>Budget — most I'd like to pay <span class="hint">(optional)</span></label>
                    <input type="number" name="budget_max" min="0" step="50"
                           placeholder="e.g. 3000" value="<?= $budgetMax !== null ? escape((string)$budgetMax) : '' ?>">
                    <?php if (!empty($budgetBounds['min_price'])): ?>
                        <div class="hint" style="margin-top:6px;">Typical range on Pestify: ₱<?= number_format($budgetBounds['min_price'], 0) ?> – ₱<?= number_format($budgetBounds['max_price'], 0) ?></div>
                    <?php endif; ?>
                </div>

                <div class="dss-field">
                    <label>How urgent is this?</label>
                    <div class="chip-row">
                        <?php foreach (['flexible' => 'No rush', 'soon' => 'Soon', 'emergency' => 'Emergency — ASAP'] as $val => $lbl): ?>
                            <input type="radio" class="chip-option" name="urgency" id="urg-<?= $val ?>" value="<?= $val ?>" <?= $urgency === $val ? 'checked' : '' ?>>
                            <label class="chip-label" for="urg-<?= $val ?>"><?= $lbl ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="dss-field">
                    <label>What matters most to you?</label>
                    <div class="chip-row">
                        <?php foreach (['balanced' => 'Balanced', 'best_rated' => 'Best rated', 'best_value' => 'Best value'] as $val => $lbl): ?>
                            <input type="radio" class="chip-option" name="priority" id="pri-<?= $val ?>" value="<?= $val ?>" <?= $priority === $val ? 'checked' : '' ?>>
                            <label class="chip-label" for="pri-<?= $val ?>"><?= $lbl ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($urgency === 'emergency'): ?>
                        <div class="hint" style="margin-top:6px;">Since this is an emergency, we'll prioritize providers who can respond fastest regardless of this choice.</div>
                    <?php endif; ?>
                </div>

                <div class="dss-field">
                    <label>City <span class="hint">(optional)</span></label>
                    <select name="city">
                        <option value="">Any city</option>
                        <?php foreach ($cities as $c): ?>
                            <option value="<?= escape($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= escape($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dss-field toggle-row">
                    <input type="checkbox" name="eco" id="eco" value="1" <?= $ecoOnly ? 'checked' : '' ?>>
                    <label for="eco" style="margin:0;">Prefer eco-friendly providers</label>
                </div>

                <button type="submit" class="dss-submit"><i class="fas fa-magnifying-glass"></i> Find My Match</button>
            </form>

            <?php if ($submitted): ?>
                <div class="interp-row">
                    <i class="fas fa-circle-info"></i>
                    <span>Showing matches for:</span>
                    <span class="interp-pill"><?= $parsedCategoryName ? escape($parsedCategoryName) : 'Any category' ?></span>
                    <?php if ($budgetMax !== null): ?><span class="interp-pill">Up to ₱<?= number_format($budgetMax, 0) ?></span><?php endif; ?>
                    <?php if ($urgency !== 'flexible'): ?><span class="interp-pill"><?= $urgency === 'emergency' ? 'Emergency' : 'Soon' ?></span><?php endif; ?>
                    <?php if ($city !== ''): ?><span class="interp-pill"><?= escape($city) ?></span><?php endif; ?>
                    <a href="#top-form" onclick="document.querySelector('.dss-form').scrollIntoView();return false;">Edit</a>
                </div>

                <?php if ($degraded): ?>
                    <div class="degraded-note">
                        <i class="fas fa-triangle-exclamation"></i>
                        No providers found in <?= escape($city) ?> — showing the best matches from all cities instead.
                    </div>
                <?php endif; ?>

                <?php if (empty($results)): ?>
                    <div class="empty-state">
                        <i class="fas fa-magnifying-glass"></i>
                        <h2>No matching services yet</h2>
                        <p>Try widening your budget or picking a different category.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($results as $i => $r): ?>
                        <div class="result-card <?= $i === 0 ? 'top' : '' ?>">
                            <div class="result-rank">#<?= $i + 1 ?></div>
                            <div class="result-body">
                                <div class="result-top-row">
                                    <div>
                                        <div class="result-title"><?= escape($r['title']) ?></div>
                                        <div class="result-company"><i class="fas fa-building"></i> <?= escape($r['company_name']) ?><?= $r['city'] ? ' · ' . escape($r['city']) : '' ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="result-score"><?= number_format($r['score'], 0) ?>%</div>
                                        <div class="result-score-label">match score</div>
                                    </div>
                                </div>
                                <div class="result-meta">
                                    <span><i class="fas fa-tag"></i> ₱<?= number_format((float)$r['price'], 0) ?></span>
                                    <span><i class="fas fa-star"></i> <?= (int)$r['review_count'] > 0 ? round((float)$r['avg_rating'], 1) . ' (' . $r['review_count'] . ')' : 'No reviews yet' ?></span>
                                    <span><i class="fas fa-layer-group"></i> <?= escape($r['category_name'] ?? 'General') ?></span>
                                </div>
                                <div class="result-explain"><?= escape($r['explanation']) ?></div>

                                <?php if (!empty($r['reviews'])): ?>
                                    <div class="review-snippets">
                                        <?php foreach ($r['reviews'] as $rev): ?>
                                            <div class="review-snippet">
                                                <div class="review-snippet-top">
                                                    <span class="review-snippet-stars">
                                                        <?php for ($s = 1; $s <= 5; $s++): ?><i class="fas fa-star" style="color:<?= $s <= (int)$rev['rating'] ? '#f39c12' : '#e0e0e0' ?>;"></i><?php endfor; ?>
                                                    </span>
                                                    <span class="review-snippet-author"><?= escape(trim(($rev['first_name'] ?? 'A') . ' ' . substr($rev['last_name'] ?? '', 0, 1))) ?> · <?= date('M j, Y', strtotime($rev['created_at'])) ?></span>
                                                </div>
                                                <?php if (!empty($rev['feedback'])): ?>
                                                    <div class="review-snippet-text">"<?= escape($rev['feedback']) ?>"</div>
                                                <?php endif; ?>
                                                <?php if (!empty($rev['feedback_image'])): ?>
                                                    <img class="review-snippet-img" src="<?= siteUrl($rev['feedback_image']) ?>" alt="Review photo"
                                                         onclick="this.classList.toggle('expanded')">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <button type="button" class="breakdown-toggle-btn" onclick="var p=this.nextElementSibling;p.classList.toggle('open');this.querySelector('i').classList.toggle('fa-chevron-down');this.querySelector('i').classList.toggle('fa-chevron-up');">
                                    <i class="fas fa-chevron-down"></i> Why this match?
                                </button>
                                <div class="breakdown-panel">
                                    <?php foreach ($r['breakdown'] as $key => $crit): ?>
                                        <div class="bd-row">
                                            <div class="bd-label"><?= ucfirst($key) ?></div>
                                            <div class="bd-bar-wrap"><div class="bd-bar" style="width:<?= round($crit['raw'] * 100) ?>%;"></div></div>
                                            <div class="bd-note"><?= escape($crit['label']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="result-actions">
                                    <a href="<?= appUrl('provider-details.php') . '?id=' . (int)$r['provider_id'] ?>">View Provider &amp; Book →</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
