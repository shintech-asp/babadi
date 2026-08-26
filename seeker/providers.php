<?php
chdir(dirname(__DIR__));
// providers.php - Browse all providers
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$city   = isset($_GET['city'])   ? trim($_GET['city'])   : '';
$sort   = isset($_GET['sort'])   ? trim($_GET['sort'])   : 'rating';
$page   = isset($_GET['page'])   ? max(1, intval($_GET['page'])) : 1;
$limit  = 12;
$offset = ($page - 1) * $limit;

// -- Ensure service_reviews table exists before querying it --
try {
    $db->exec("CREATE TABLE IF NOT EXISTS service_reviews (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        avail_id        INT NOT NULL,
        seeker_user_id  INT NOT NULL,
        provider_id     INT NOT NULL,
        service_name    VARCHAR(255) DEFAULT NULL,
        rating          TINYINT NOT NULL,
        feedback        TEXT DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_review (avail_id, seeker_user_id)
    )");
} catch (Exception $e) {}

// -- Main query � pulls avg + count from service_reviews --
$query = "SELECT p.*, u.email,
          (SELECT ROUND(AVG(r.rating), 1) FROM service_reviews r WHERE r.provider_id = p.id) AS avg_rating,
          (SELECT COUNT(*)               FROM service_reviews r WHERE r.provider_id = p.id) AS review_count,
          (SELECT COUNT(*)               FROM service_listings sl WHERE sl.provider_id = p.user_id AND sl.status = 'active') AS service_count
          FROM providers p
          JOIN users u ON p.user_id = u.id
          WHERE p.status = 'active'";

$count_query = "SELECT COUNT(DISTINCT p.id) AS total
                FROM providers p
                JOIN users u ON p.user_id = u.id
                WHERE p.status = 'active'";

$params = [];

if (!empty($search)) {
    $query       .= " AND (p.company_name LIKE :search OR p.description LIKE :search OR u.email LIKE :search)";
    $count_query .= " AND (p.company_name LIKE :search OR p.description LIKE :search OR u.email LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($city)) {
    $query       .= " AND p.city LIKE :city";
    $count_query .= " AND p.city LIKE :city";
    $params[':city'] = '%' . $city . '%';
}

$query .= ($sort === 'name') ? " ORDER BY p.company_name ASC" : " ORDER BY avg_rating DESC, service_count DESC";
$query .= " LIMIT :limit OFFSET :offset";

// Total count
$stmt = $db->prepare($count_query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$total_providers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages     = ceil($total_providers / $limit);

// Providers
$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindParam(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -- Per-star breakdown for every provider on this page --
// Returns [provider_id => [1=>count, 2=>count, 3=>count, 4=>count, 5=>count]]
$ratingBreakdown = [];
if (count($providers) > 0) {
    $providerIds   = array_column($providers, 'id');
    $placeholders  = implode(',', array_fill(0, count($providerIds), '?'));
    try {
        $brkStmt = $db->prepare(
            "SELECT provider_id, rating, COUNT(*) AS cnt
             FROM service_reviews
             WHERE provider_id IN ($placeholders)
             GROUP BY provider_id, rating"
        );
        $brkStmt->execute($providerIds);
        foreach ($brkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = $row['provider_id'];
            if (!isset($ratingBreakdown[$pid])) {
                $ratingBreakdown[$pid] = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
            }
            $ratingBreakdown[$pid][(int)$row['rating']] = (int)$row['cnt'];
        }
    } catch (Exception $e) {}
}

// -- Recent reviews (up to 2 per provider) for the quote snippets --
$recentReviews = [];
if (count($providers) > 0) {
    $providerIds  = array_column($providers, 'id');
    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    try {
        $rvStmt = $db->prepare(
            "SELECT r.provider_id, r.rating, r.feedback, r.created_at,
                    u.first_name, u.last_name
             FROM service_reviews r
             LEFT JOIN users u ON u.id = r.seeker_user_id
             WHERE r.provider_id IN ($placeholders)
               AND r.feedback IS NOT NULL AND r.feedback != ''
             ORDER BY r.created_at DESC"
        );
        $rvStmt->execute($providerIds);
        foreach ($rvStmt->fetchAll(PDO::FETCH_ASSOC) as $rv) {
            $pid = $rv['provider_id'];
            if (!isset($recentReviews[$pid])) $recentReviews[$pid] = [];
            if (count($recentReviews[$pid]) < 2) $recentReviews[$pid][] = $rv;
        }
    } catch (Exception $e) {}
}

// Cities for filter
$city_stmt = $db->prepare("SELECT DISTINCT city FROM providers WHERE status = 'approved' AND city IS NOT NULL AND city != '' ORDER BY city");
$city_stmt->execute();
$cities = $city_stmt->fetchAll(PDO::FETCH_ASSOC);

// -- Label for each star level --
$ratingLabels = [5 => 'Excellent', 4 => 'Very Good', 3 => 'Good', 2 => 'Fair', 1 => 'Poor'];
$ratingColors = [5 => '#28a745', 4 => '#5cb85c', 3 => '#ffc107', 2 => '#fd7e14', 1 => '#dc3545'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Pest Control Companies - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .providers-browse-page { min-height:100vh; background:#f5f7fa; padding:40px 20px; }
        .providers-container   { max-width:1200px; margin:0 auto; }

        .page-header           { text-align:center; margin-bottom:50px; }
        .page-header h1        { font-size:36px; color:var(--dark-color); margin-bottom:10px; }
        .page-header p         { font-size:16px; color:#666; margin-bottom:30px; }

        .filters-section { background:white; padding:25px; border-radius:12px; margin-bottom:30px; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
        .filter-title    { font-size:16px; font-weight:600; color:var(--dark-color); margin-bottom:20px; }
        .filter-group    { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:20px; margin-bottom:20px; }
        .filter-item     { display:flex; flex-direction:column; }
        .filter-item label   { font-size:14px; font-weight:500; color:#333; margin-bottom:8px; }
        .filter-item input,
        .filter-item select  { padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; font-family:inherit; }
        .filter-item input:focus,
        .filter-item select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(52,152,219,0.1); }
        .filter-buttons { display:flex; gap:10px; margin-top:20px; }
        .filter-buttons button { padding:10px 20px; border:none; border-radius:6px; font-weight:600; cursor:pointer; transition:all 0.3s; }
        .btn-search  { background:var(--primary); color:white; flex:1; }
        .btn-search:hover { background:#2980b9; }
        .btn-reset   { background:#ecf0f1; color:#333; }
        .btn-reset:hover { background:#bdc3c7; }

        .results-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .results-count  { font-size:14px; color:#666; }

        .providers-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:25px; margin-bottom:40px; }

        /* -- Provider Card -- */
        .provider-card {
            background:white; border-radius:12px; overflow:hidden;
            box-shadow:0 2px 8px rgba(0,0,0,0.1); transition:all 0.3s;
            display:flex; flex-direction:column;
        }
        .provider-card:hover { transform:translateY(-5px); box-shadow:0 8px 20px rgba(0,0,0,0.15); }

        .provider-header {
            background:linear-gradient(135deg,var(--primary),#2980b9);
            padding:30px 20px; text-align:center; color:white;
        }
        .provider-logo-large {
            width:80px; height:80px; background:white; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 15px; font-weight:bold; font-size:28px; color:var(--primary);
        }
        .provider-card h3  { font-size:18px; margin-bottom:8px; }
        .provider-header-sub { font-size:12px; opacity:0.85; }

        .provider-body { padding:20px; flex-grow:1; display:flex; flex-direction:column; }

        /* -- Compact star row -- */
        .provider-rating-row {
            display:flex; align-items:center; gap:8px; margin-bottom:14px;
        }
        .stars-inline i  { color:#f39c12; font-size:13px; }
        .rating-avg      { font-size:18px; font-weight:800; color:#1a1a2e; }
        .rating-meta     { font-size:12px; color:#999; }

        /* -- Rating breakdown panel -- */
        .rating-breakdown {
            background:#fafbfc; border:1px solid #eee; border-radius:10px;
            padding:14px 16px; margin-bottom:16px;
        }
        .breakdown-title {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.5px; color:#888; margin-bottom:10px;
        }
        .breakdown-row {
            display:flex; align-items:center; gap:8px; margin-bottom:7px; font-size:12px;
        }
        .breakdown-row:last-child { margin-bottom:0; }
        .breakdown-label {
            width:100px; min-width:100px; font-weight:600; color:#555;
            white-space:nowrap; display:flex; align-items:center; gap:4px;
        }
        .breakdown-label .star-num  { font-weight:700; color:#1a1a2e; font-size:12px; }
        .breakdown-label .star-word { font-size:11px; }
        .breakdown-bar-wrap {
            flex:1; background:#e9ecef; border-radius:99px; height:8px; overflow:hidden;
        }
        .breakdown-bar {
            height:100%; border-radius:99px; transition:width 0.7s ease;
        }
        .breakdown-pct  { width:34px; text-align:right; color:#666; font-size:11px; font-weight:600; }
        .breakdown-count{ width:26px; text-align:right; color:#bbb; font-size:11px; }

        /* -- Overall satisfaction badge -- */
        .satisfaction-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:99px; font-size:11px; font-weight:700;
            margin-bottom:14px;
        }

        /* -- Recent review snippets -- */
        .recent-reviews { margin-bottom:14px; }
        .review-snippet {
            background:#f8f9fa; border-left:3px solid #dee2e6; border-radius:0 6px 6px 0;
            padding:8px 12px; margin-bottom:6px; font-size:12px;
        }
        .review-snippet:last-child { margin-bottom:0; }
        .review-snippet-stars i  { color:#f39c12; font-size:10px; }
        .review-snippet-text     { color:#555; line-height:1.5; margin:3px 0; font-style:italic; }
        .review-snippet-author   { color:#aaa; font-size:11px; }

        .provider-details { font-size:13px; color:#666; margin-bottom:14px; line-height:1.6; }
        .detail-item      { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .detail-item i    { color:var(--primary); width:16px; }

        .provider-description { font-size:13px; color:#777; line-height:1.5; margin-bottom:14px; flex-grow:1; }

        .provider-actions { display:flex; gap:10px; margin-top:auto; }
        .provider-actions a {
            flex:1; padding:10px; text-align:center; border-radius:6px;
            text-decoration:none; font-size:13px; font-weight:600; transition:all 0.3s;
        }
        .btn-view    { background:var(--primary); color:white; }
        .btn-view:hover { background:#2980b9; }
        .btn-contact { background:#ecf0f1; color:#333; }
        .btn-contact:hover { background:#bdc3c7; }

        /* -- Toggle breakdown -- */
        .breakdown-toggle {
            font-size:11px; font-weight:600; color:var(--primary);
            background:none; border:none; cursor:pointer; padding:0;
            margin-bottom:8px; display:inline-flex; align-items:center; gap:4px;
        }
        .breakdown-toggle:hover { text-decoration:underline; }
        .breakdown-body { display:none; }
        .breakdown-body.open { display:block; }

        .no-ratings-msg { font-size:12px; color:#bbb; text-align:center; padding:10px 0; }

        .empty-state { text-align:center; padding:60px 20px; background:white; border-radius:12px; }
        .empty-state i { font-size:64px; color:#bdc3c7; margin-bottom:20px; }
        .empty-state h2 { font-size:24px; color:var(--dark-color); margin-bottom:10px; }
        .empty-state p  { color:#666; margin-bottom:25px; }

        .pagination { display:flex; justify-content:center; gap:10px; margin-top:40px; }
        .pagination a, .pagination span { padding:10px 15px; border-radius:6px; text-decoration:none; color:#333; border:1px solid #ddd; transition:all 0.3s; }
        .pagination a:hover { background:var(--primary); color:white; border-color:var(--primary); }
        .pagination .active { background:var(--primary); color:white; border-color:var(--primary); }

        @media (max-width:768px) {
            .page-header h1     { font-size:28px; }
            .filter-group       { grid-template-columns:1fr; }
            .providers-grid     { grid-template-columns:1fr; }
            .results-header     { flex-direction:column; gap:15px; align-items:flex-start; }
        }
    </style>
</head>
<body class="seeker-unified">
    <?php
chdir(dirname(__DIR__));
    $current_page = 'providers';
    $use_seeker_unified_ui = true;
    include appPath('includes/header.php');
    ?>

    <div class="providers-browse-page">
        <div class="providers-container">

            <div class="page-header">
                <h1>Browse Pest Control Companies</h1>
                <p>Find trusted and verified pest control professionals in your area</p>
            </div>

            <!-- -- Filters -- -->
            <div class="filters-section">
                <h3 class="filter-title">Search &amp; Filter</h3>
                <form method="GET" action="<?php echo appUrl('providers.php'); ?>" id="filterForm">
                    <div class="filter-group">
                        <div class="filter-item">
                            <label for="search">Search Company Name</label>
                            <input type="text" id="search" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Enter company name...">
                        </div>
                        <div class="filter-item">
                            <label for="province">Province</label>
                            <select id="province" name="province">
                                <option value="">-- Select Province --</option>
                                <option value="Cavite" selected>Cavite</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="city">City/Location</label>
                            <select id="city" name="city" disabled>
                                <option value="">-- Select City --</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="sort">Sort By</label>
                            <select id="sort" name="sort">
                                <option value="rating" <?php echo ($sort==='rating')?'selected':''; ?>>Highest Rating</option>
                                <option value="name"   <?php echo ($sort==='name')  ?'selected':''; ?>>Company Name</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                        <a href="<?php echo appUrl('providers.php'); ?>" class="btn-reset" style="text-align:center;text-decoration:none;padding:10px 20px;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- -- Results header -- -->
            <div class="results-header">
                <div class="results-count">
                    <?php if($total_providers > 0): ?>
                        <strong><?php echo $total_providers; ?></strong>
                        <?php echo ($total_providers === 1) ? 'company' : 'companies'; ?> found
                    <?php endif; ?>
                </div>
            </div>

            <!-- -- Providers Grid -- -->
            <?php if(count($providers) > 0): ?>
            <div class="providers-grid">
                <?php foreach($providers as $provider):
                    $pid        = $provider['id'];
                    $avgRating  = (float)($provider['avg_rating'] ?? 0);
                    $revCount   = (int)($provider['review_count'] ?? 0);
                    $breakdown  = $ratingBreakdown[$pid] ?? [1=>0,2=>0,3=>0,4=>0,5=>0];
                    $snippets   = $recentReviews[$pid] ?? [];

                    // Overall satisfaction % = (4? + 5?) / total * 100
                    $satisfiedCount = ($breakdown[4] ?? 0) + ($breakdown[5] ?? 0);
                    $satisfactionPct = $revCount > 0 ? round($satisfiedCount / $revCount * 100) : 0;

                    // Satisfaction label + color
                    if ($satisfactionPct >= 90)     { $satLabel = 'Excellent'; $satColor = '#28a745'; $satBg = '#d4edda'; }
                    elseif ($satisfactionPct >= 75) { $satLabel = 'Very Good'; $satColor = '#20c997'; $satBg = '#d1f7ec'; }
                    elseif ($satisfactionPct >= 60) { $satLabel = 'Good';      $satColor = '#ffc107'; $satBg = '#fff3cd'; }
                    elseif ($satisfactionPct >= 40) { $satLabel = 'Fair';      $satColor = '#fd7e14'; $satBg = '#fde8d8'; }
                    elseif ($revCount > 0)           { $satLabel = 'Poor';      $satColor = '#dc3545'; $satBg = '#f8d7da'; }
                    else                             { $satLabel = '';          $satColor = '';        $satBg = ''; }
                ?>
                <div class="provider-card">
                    <div class="provider-header">
                        <div class="provider-logo-large">
                            <?php if (!empty($provider['logo_url'])): ?>
                                <img src="<?php echo htmlspecialchars($provider['logo_url']); ?>"
                                     alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($provider['company_name'], 0, 2)); ?>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($provider['company_name']); ?></h3>
                        <div class="provider-header-sub">
                            <?php echo $provider['service_count'] ?? 0; ?> active services
                            &nbsp;�&nbsp;
                            <?php echo htmlspecialchars($provider['city'] ?? 'Cavite'); ?>
                        </div>
                    </div>

                    <div class="provider-body">

                        <?php if ($revCount > 0): ?>
                            <!-- -- Star summary row -- -->
                            <div class="provider-rating-row">
                                <div class="stars-inline">
                                    <?php for ($s = 1; $s <= 5; $s++):
                                        if ($s <= floor($avgRating))      echo '<i class="fas fa-star"></i>';
                                        elseif ($s - 0.5 <= $avgRating)   echo '<i class="fas fa-star-half-alt"></i>';
                                        else                               echo '<i class="far fa-star"></i>';
                                    endfor; ?>
                                </div>
                                <span class="rating-avg"><?php echo number_format($avgRating, 1); ?></span>
                                <span class="rating-meta"><?php echo $revCount; ?> review<?php echo $revCount !== 1 ? 's' : ''; ?></span>
                            </div>

                            <!-- -- Satisfaction badge -- -->
                            <?php if ($satLabel): ?>
                            <div>
                                <span class="satisfaction-badge"
                                      style="background:<?php echo $satBg; ?>;color:<?php echo $satColor; ?>;">
                                    <i class="fas fa-thumbs-up"></i>
                                    <?php echo $satisfactionPct; ?>% <?php echo $satLabel; ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <!-- -- Collapsible rating breakdown -- -->
                            <div class="rating-breakdown">
                                <div class="breakdown-title">Rating Breakdown</div>
                                <?php foreach ([5,4,3,2,1] as $star):
                                    $cnt = $breakdown[$star] ?? 0;
                                    $pct = $revCount > 0 ? round($cnt / $revCount * 100) : 0;
                                    $color = $ratingColors[$star];
                                    $label = $ratingLabels[$star];
                                ?>
                                <div class="breakdown-row">
                                    <div class="breakdown-label">
                                        <i class="fas fa-star" style="color:#f39c12;font-size:10px;flex-shrink:0;"></i>
                                        <span class="star-num"><?php echo $star; ?></span>
                                        <span class="star-word" style="color:<?php echo $color; ?>;"><?php echo $label; ?></span>
                                    </div>
                                    <div class="breakdown-bar-wrap">
                                        <div class="breakdown-bar"
                                             style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div>
                                    </div>
                                    <div class="breakdown-pct"><?php echo $pct; ?>%</div>
                                    <div class="breakdown-count">(<?php echo $cnt; ?>)</div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- -- Recent review snippets -- -->
                            <?php if (count($snippets) > 0): ?>
                            <div class="recent-reviews">
                                <div class="breakdown-title" style="margin-bottom:8px;">
                                    <i class="fas fa-quote-left" style="margin-right:4px;"></i>Recent Reviews
                                </div>
                                <?php foreach ($snippets as $snip):
                                    $snipName = trim(($snip['first_name'] ?? '') . ' ' . ($snip['last_name'] ?? ''));
                                    if (!$snipName) $snipName = 'Anonymous';
                                    $snipText = htmlspecialchars(mb_substr($snip['feedback'], 0, 90));
                                    if (mb_strlen($snip['feedback']) > 90) $snipText .= '�';
                                ?>
                                <div class="review-snippet">
                                    <div class="review-snippet-stars">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="<?php echo $s <= $snip['rating'] ? 'fas' : 'far'; ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="review-snippet-text">"<?php echo $snipText; ?>"</div>
                                    <div class="review-snippet-author">
                                        � <?php echo htmlspecialchars($snipName); ?> �
                                        <?php echo date('M j, Y', strtotime($snip['created_at'])); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- No reviews yet -->
                            <div class="no-ratings-msg">
                                <i class="far fa-star" style="font-size:22px;display:block;margin-bottom:4px;color:#ddd;"></i>
                                No ratings yet
                            </div>
                        <?php endif; ?>

                        <!-- -- Provider details -- -->
                        <div class="provider-details">
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($provider['city'] ?? 'Location not specified'); ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-list"></i>
                                <span><?php echo $provider['service_count'] ?? 0; ?> Active Services</span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Verified Service Provider</span>
                            </div>
                        </div>

                        <!-- Description -->
                        <?php if (!empty($provider['description'])): ?>
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#aaa;margin-bottom:4px;">About</div>
                        <p class="provider-description">
                            <?php echo htmlspecialchars(substr($provider['description'], 0, 100))
                                     . (strlen($provider['description']) > 100 ? '...' : ''); ?>
                        </p>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="provider-actions">
                            <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo $provider['id']; ?>" class="btn-view">
                                <i class="fas fa-eye"></i> View Profile
                            </a>
                            <a href="<?php echo appUrl('request-service.php'); ?>?provider_id=<?php echo $provider['id']; ?>" class="btn-contact">
                                <i class="fas fa-envelope"></i> Contact
                            </a>
                        </div>

                    </div><!-- /provider-body -->
                </div><!-- /provider-card -->
                <?php endforeach; ?>
            </div><!-- /providers-grid -->

            <!-- -- Pagination -- -->
            <?php if($total_pages > 1):
                $qs = (!empty($search) ? '&search='.urlencode($search) : '')
                    . (!empty($city)   ? '&city='.urlencode($city)     : '')
                    . '&sort='.$sort;
            ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=1<?php echo $qs; ?>">&laquo; First</a>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $page-1; ?><?php echo $qs; ?>">Previous</a>
                <?php endif; ?>
                <?php for($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                    <?php if($i===$page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $i; ?><?php echo $qs; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if($page < $total_pages): ?>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $page+1;?><?php echo $qs;?>">Next</a>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $total_pages;?><?php echo $qs;?>">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h2>No companies found</h2>
                <p>Try adjusting your search filters or browse all companies</p>
                <a href="<?php echo appUrl('providers.php'); ?>" class="btn-primary" style="display:inline-block;padding:10px 25px;border-radius:6px;text-decoration:none;">
                    View All Companies
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <?php include appPath('includes/footer.php'); ?>
    <script>
        const provinceCities = {
            'Cavite': [
                'Cavite City','Tagaytay City','Trece Martires City','Alfonso','Amadeo',
                'Bacoor','Carmona','Dasmari�as','General Emilio Aguinaldo',
                'General Mariano Alvarez','General Trias','Imus','Indang','Kawit',
                'Magallanes','Maragondon','Mendez','Naic','Noveleta','Rosario',
                'Silang','Tanza','Ternate'
            ]
        };

        document.getElementById('province').addEventListener('change', function() {
            const citySelect = document.getElementById('city');
            citySelect.innerHTML = '<option value="">-- Select City --</option>';
            if (this.value && provinceCities[this.value]) {
                citySelect.disabled = false;
                provinceCities[this.value].forEach(c => {
                    const o = document.createElement('option');
                    o.value = o.textContent = c;
                    citySelect.appendChild(o);
                });
            } else {
                citySelect.disabled = true;
            }
        });

        window.addEventListener('load', function() {
            const provinceSelect = document.getElementById('province');
            const citySelect     = document.getElementById('city');
            const savedCity      = '<?php echo htmlspecialchars($city ?? ''); ?>';
            provinceSelect.value = 'Cavite';
            provinceSelect.dispatchEvent(new Event('change'));
            if (savedCity) setTimeout(() => { citySelect.value = savedCity; }, 100);
        });
    </script>
</body>
</html>
