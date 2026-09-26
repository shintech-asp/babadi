<?php
// api/v1/seeker/recommend.php — Decision Support System: "Find My Match"
// for the mobile app. Mirrors seeker/recommend.php's (web) pipeline exactly
// (see dss_helper.php's top comment, which named this exact path as the
// intended mobile counterpart) but returns JSON instead of rendering a page.
//
// Unlike every other file under api/v1/seeker/, auth here is OPTIONAL — the
// feature works for guests, same as the web version; only booking a listing
// (the existing seeker/bookings/store.php flow, unchanged) requires login.
// An Authorization header, if present and valid, is used only to attribute
// the logged query in dss_queries for later analysis.

require_once dirname(__DIR__, 1) . '/_bootstrap.php';
require_once 'includes/dss_helper.php';

allow('GET');

// ── Optional auth ──────────────────────────────────────────────────────────
$userId = null;
$token = bearer();
if ($token) {
    $payload = jwt_parse($token);
    if ($payload && !empty($payload['sub'])) {
        $userId = (int) $payload['sub'];
    }
}

$db = db();

$categories = $db->query('SELECT id, name FROM service_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// ── Input (same shape as the web form's query params) ──────────────────────
$needText   = trim((string) inp('need_text', ''));
$categoryId = inp('category_id') !== null && inp('category_id') !== '' ? (int) inp('category_id') : null;
$budgetMax  = inp('budget_max') !== null && inp('budget_max') !== '' ? (float) inp('budget_max') : null;
$urgencyIn  = (string) inp('urgency', 'flexible');
$urgency    = in_array($urgencyIn, ['flexible', 'soon', 'emergency'], true) ? $urgencyIn : 'flexible';
$priorityIn = (string) inp('priority', 'balanced');
$priority   = in_array($priorityIn, ['balanced', 'best_rated', 'best_value'], true) ? $priorityIn : 'balanced';
$ecoOnly    = in_array(inp('eco'), ['1', 1, true], true);
$city       = trim((string) inp('city', ''));

// ── Free-text → category, same rules-then-Groq cascade as the web ─────────
$parsedBy = 'rules';
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
            $categoryId = $ruleParsed['category_id'];
            $parsedBy = 'rules';
        }
    }
}

$parsedCategoryName = null;
if ($categoryId !== null) {
    foreach ($categories as $c) {
        if ((int) $c['id'] === $categoryId) {
            $parsedCategoryName = $c['name'];
            break;
        }
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

// One batched Groq call for the whole result set (never one per item) —
// falls back to the template sentence per-row on failure or a missing rank.
$groqExplanations = dssGenerateExplanationsGroq($ranked['results']);

$results = [];
foreach ($ranked['results'] as $i => $row) {
    $row['explanation'] = $groqExplanations[$i + 1] ?? dssTemplateExplanation($row);

    $images = $row['images'] ? json_decode($row['images'], true) : [];
    $reviews = array_map(function (array $r): array {
        return [
            'rating'         => (int) $r['rating'],
            'feedback'       => $r['feedback'],
            'feedback_image' => $r['feedback_image'],
            'author'         => trim(($r['first_name'] ?? 'A') . ' ' . substr($r['last_name'] ?? '', 0, 1)),
            'created_at'     => $r['created_at'],
        ];
    }, $row['reviews'] ?? []);

    $results[] = [
        'rank'                   => $i + 1,
        'listing_id'             => (int) $row['listing_id'],
        'provider_id'            => (int) $row['provider_id'],
        'title'                  => $row['title'],
        'company_name'           => $row['company_name'],
        'logo_url'               => $row['logo_url'],
        'city'                   => $row['city'],
        'category_name'          => $row['category_name'],
        'price'                  => (float) $row['price'],
        'images'                 => is_array($images) ? $images : [],
        'is_eco_friendly'        => (bool) $row['is_eco_friendly'],
        'is_emergency_available' => (bool) $row['is_emergency_available'],
        'avg_rating'             => (float) $row['avg_rating'],
        'review_count'           => (int) $row['review_count'],
        'score'                  => (float) $row['score'],
        'breakdown'              => $row['breakdown'],
        'explanation'            => $row['explanation'],
        'reviews'                => $reviews,
    ];
}

dssLogQuery($db, $userId, $filters, $parsedBy, $ranked);

ok([
    'data' => [
        'results'              => $results,
        'candidate_count'      => $ranked['candidate_count'],
        'degraded'             => $ranked['degraded'],
        'parsed_category_id'   => $categoryId,
        'parsed_category_name' => $parsedCategoryName,
        'parsed_by'            => $parsedBy,
    ],
]);
