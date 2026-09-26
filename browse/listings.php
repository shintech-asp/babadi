<?php
chdir(dirname(__DIR__));
// listings.php - Browse available services/listings
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$city = isset($_GET['city']) ? trim($_GET['city']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'recent';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT s.*, p.company_name, p.city, u.email, s.category as category_name,
          (SELECT AVG(overall_rating) FROM reviews WHERE provider_id = p.id) as provider_rating
          FROM services s
          JOIN providers p ON s.provider_id = p.id
          JOIN users u ON p.user_id = u.id
          WHERE s.status = 'active' AND p.status = 'active'";

$count_query = "SELECT COUNT(DISTINCT s.id) as total 
                FROM services s
                JOIN providers p ON s.provider_id = p.id
                WHERE s.status = 'active' AND p.status = 'active'";

$params = [];

if (!empty($search)) {
    $query .= " AND s.service_name LIKE :search";
    $count_query .= " AND s.service_name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($category)) {
    $query .= " AND s.category LIKE :category";
    $count_query .= " AND s.category LIKE :category";
    $params[':category'] = '%' . $category . '%';
}

if (!empty($city)) {
    $query .= " AND p.city LIKE :city";
    $count_query .= " AND p.city LIKE :city";
    $params[':city'] = '%' . $city . '%';
}

// Add sorting
if ($sort === 'price_low') {
    $query .= " ORDER BY s.price ASC";
} elseif ($sort === 'price_high') {
    $query .= " ORDER BY s.price DESC";
} elseif ($sort === 'rating') {
    $query .= " ORDER BY provider_rating DESC";
} else {
    $query .= " ORDER BY s.created_at DESC";
}

$query .= " LIMIT :limit OFFSET :offset";

// Get total count
$stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_listings = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_listings / $limit);

// Get listings
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$category_query = "SELECT DISTINCT category as name FROM services 
                   WHERE status = 'active' AND category IS NOT NULL AND category != ''
                   ORDER BY category";
$stmt = $db->prepare($category_query);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cities for filter
$city_query = "SELECT DISTINCT p.city FROM providers p 
               JOIN services s ON s.provider_id = p.id
               WHERE s.status = 'active' AND p.city IS NOT NULL AND p.city != '' 
               ORDER BY p.city";
$stmt = $db->prepare($city_query);
$stmt->execute();
$cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to build pagination query string
function paginationParams($search, $category, $city, $sort) {
    $parts = [];
    if (!empty($search))   $parts[] = 'search='   . urlencode($search);
    if (!empty($category)) $parts[] = 'category=' . urlencode($category);
    if (!empty($city))     $parts[] = 'city='     . urlencode($city);
    $parts[] = 'sort=' . $sort;
    return implode('&', $parts);
}
$paginationQS = paginationParams($search, $category, $city, $sort);

// Category -> icon, matched against the same category values provider/services.php's
// Add/Edit Service form offers (pest_control, termite, rodent, mosquito, general).
function serviceCategoryIcon(?string $category): string {
    $icons = [
        'pest_control' => 'fa-spray-can-sparkles',
        'termite'      => 'fa-bug',
        'rodent'       => 'fa-paw',
        'mosquito'     => 'fa-mosquito',
        'general'      => 'fa-shield-halved',
    ];
    return $icons[$category] ?? 'fa-shield-halved';
}

// requires_inspection means the listed price is only an estimate — the real
// price is set after an on-site inspection (see CLAUDE.md's "Pricing Model"
// log entry). Shown as a chip on the price so seekers aren't misled into
// thinking a Custom Quote figure is the final charge.
function servicePricingLabel(array $listing): array {
    if (!empty($listing['requires_inspection'])) {
        return ['label' => 'Estimated price', 'chip' => 'Estimate only'];
    }
    return ['label' => 'Fixed price', 'chip' => 'Fixed price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Pest Control Services - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .services-browse-page {
            min-height: 100vh;
            padding: 40px 20px 60px;
        }

        .services-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .page-header h1 {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .page-header p {
            font-size: 15px;
            color: var(--su-muted, #666);
        }

        .filters-section {
            padding: 22px 24px;
            margin-bottom: 28px;
        }

        .filter-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--su-muted, #666);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-title i { color: var(--su-primary, var(--primary)); }

        .filter-group {
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 18px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
        }

        .filter-item label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--su-muted, #666);
            margin-bottom: 7px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-item label i { font-size: 11px; opacity: 0.75; }

        .filter-item input,
        .filter-item select {
            padding: 11px 13px;
            font-size: 14px;
            font-family: inherit;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            padding-top: 16px;
            border-top: 1px solid var(--su-border, #eee);
        }

        .btn-search,
        .btn-reset {
            padding: 11px 22px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-search { flex: 2; }
        .btn-reset { flex: 1; }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 0 4px;
        }

        .results-count {
            font-size: 14px;
        }

        .results-count strong {
            color: var(--su-text, var(--dark-color));
            font-size: 16px;
        }

        /*
          Card component below uses an "lst-" prefixed namespace on purpose.
          The previous version reused generic names like .service-header,
          which assets/css/style.css (loaded earlier on this page for
          unrelated reasons) also defines with display:flex for a completely
          different card layout — that leaked in silently and broke the
          stacking. These lst- classes don't exist anywhere else in the
          codebase, so there is no shared stylesheet that can collide with
          them. Colors still come from the shared --su-* tokens (set on
          body.seeker-unified) so this still matches the site's palette.
        */
        .lst-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 22px;
            margin-bottom: 40px;
        }

        .lst-card {
            display: flex;
            flex-direction: column;
            background: var(--su-surface, #fff);
            border: 1px solid var(--su-border, #e2e8f0);
            border-radius: var(--su-radius, 14px);
            box-shadow: var(--su-shadow, 0 8px 22px rgba(18,33,58,.08));
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .lst-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--su-shadow-hover, 0 14px 32px rgba(18,33,58,.14));
        }

        .lst-topbar {
            height: 5px;
            width: 100%;
            background: var(--su-gradient, linear-gradient(135deg,#2b6cb0,#3b82f6));
            flex-shrink: 0;
        }

        .lst-content {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            padding: 20px;
        }

        .lst-row-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }

        .lst-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(43, 108, 176, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: var(--su-primary, #2b6cb0);
            flex-shrink: 0;
        }

        .lst-chip {
            font-size: 10px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eaf2ff;
            color: var(--su-primary-dark, #245a96);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .lst-eyebrow {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--su-muted, #888);
            margin-bottom: 5px;
        }

        .lst-title {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
            color: var(--su-text, #1a2744);
            margin-bottom: 4px;
        }

        .lst-by {
            font-size: 12.5px;
            color: var(--su-muted, #667);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 16px;
        }

        .lst-by i { font-size: 11px; }

        .lst-desc {
            font-size: 13.5px;
            color: var(--su-muted, #666);
            line-height: 1.6;
            margin-bottom: 14px;
            flex-grow: 1;
        }

        .lst-loc {
            font-size: 12.5px;
            color: var(--su-muted, #888);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .lst-loc i { color: var(--su-primary, #2b6cb0); font-size: 12px; }

        .lst-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--su-border, #eee);
        }

        .lst-price-block {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .lst-price-eyebrow {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--su-muted, #999);
        }

        .lst-price {
            font-size: 21px;
            font-weight: 800;
            color: var(--su-primary, #2b6cb0);
        }

        .lst-rating {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12.5px;
        }

        .lst-rating i { color: #f39c12; font-size: 12px; }
        .lst-rating span { font-weight: 700; color: var(--su-text, #333); }
        .lst-rating .lst-new { color: var(--su-muted, #999); font-weight: 500; }

        .lst-actions {
            display: flex;
            gap: 10px;
        }

        .lst-actions a {
            flex: 1;
            padding: 11px;
            text-align: center;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 56px;
            color: #c3d3ea;
            margin-bottom: 18px;
            display: block;
        }

        .empty-state h2 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .empty-state p { color: var(--su-muted, #666); margin-bottom: 18px; }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 36px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 9px 15px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
        }

        @media (max-width: 900px) {
            .filter-group { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 600px) {
            .services-browse-page { padding: 24px 14px 40px; }
            .page-header h1 { font-size: 26px; }
            .filter-group { grid-template-columns: 1fr; }
            .filter-buttons { flex-direction: column; }
            .lst-grid { grid-template-columns: 1fr; }
            .results-header { flex-direction: column; gap: 10px; align-items: flex-start; }
        }
    </style>
</head>
<body class="seeker-unified">
    <?php $current_page = 'listings';
    $use_seeker_unified_ui = true;
    include appPath('includes/header.php'); ?>

    <div class="services-browse-page">
        <div class="services-container">
            <div class="page-header">
                <h1>Find Pest Control Services</h1>
                <p>Browse and request services from verified professionals</p>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <h3 class="filter-title"><i class="fas fa-sliders"></i> Search &amp; Filter Services</h3>
                <form method="GET" action="<?php echo appUrl('listings.php'); ?>">
                    <div class="filter-group">
                        <div class="filter-item">
                            <label for="search"><i class="fas fa-magnifying-glass"></i> Service Name</label>
                            <input type="text" id="search" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="e.g., Termite Control, Mosquito Treatment...">
                        </div>

                        <div class="filter-item">
                            <label for="category"><i class="fas fa-tag"></i> Category</label>
                            <select id="category" name="category">
                                <option value="">All Categories</option>
                                <?php foreach($categories as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['name']); ?>"
                                            <?php echo ($category === $c['name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label for="city"><i class="fas fa-location-dot"></i> City/Municipality</label>
                            <select id="city" name="city">
                                <option value="">All Cities</option>
                                <?php foreach($cities as $ct): ?>
                                    <option value="<?php echo htmlspecialchars($ct['city']); ?>"
                                            <?php echo ($city === $ct['city']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ct['city']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label for="sort"><i class="fas fa-arrow-down-wide-short"></i> Sort By</label>
                            <select id="sort" name="sort">
                                <option value="recent"     <?php echo ($sort === 'recent')     ? 'selected' : ''; ?>>Newest</option>
                                <option value="price_low"  <?php echo ($sort === 'price_low')  ? 'selected' : ''; ?>>Price: Low to High</option>
                                <option value="price_high" <?php echo ($sort === 'price_high') ? 'selected' : ''; ?>>Price: High to Low</option>
                                <option value="rating"     <?php echo ($sort === 'rating')     ? 'selected' : ''; ?>>Highest Rated</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i> Search Services
                        </button>
                        <a href="<?php echo appUrl('listings.php'); ?>" class="btn-reset">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Results -->
            <div class="results-header">
                <div class="results-count">
                    <?php if($total_listings > 0): ?>
                        <strong><?php echo $total_listings; ?></strong>
                        <?php echo ($total_listings === 1) ? 'service' : 'services'; ?> available
                    <?php endif; ?>
                </div>
            </div>

            <!-- Services Grid -->
            <?php if(count($listings) > 0): ?>
                <div class="lst-grid">
                    <?php foreach($listings as $listing):
                        // Build payment settings for deep-linking into the avail modal
                        $ps       = json_decode($listing['payment_settings'] ?? '{}', true) ?? [];
                        $ps_mode  = $ps['dp_mode']    ?? 'percent';
                        $ps_pct   = (float)($ps['dp_percent'] ?? 50);
                        $ps_fixed = (float)($ps['dp_fixed']   ?? 0);
                        $svc_price = (float)($listing['price'] ?? 0);

                        $pricingInfo = servicePricingLabel($listing);
                        $categoryLabel = ucwords(str_replace('_', ' ', (string)($listing['category_name'] ?? 'General')));
                    ?>
                        <div class="lst-card">
                            <div class="lst-topbar"></div>
                            <div class="lst-content">
                                <div class="lst-row-top">
                                    <div class="lst-icon-badge"><i class="fas <?php echo serviceCategoryIcon($listing['category_name'] ?? null); ?>"></i></div>
                                    <span class="lst-chip"><i class="fas <?php echo empty($listing['requires_inspection']) ? 'fa-tag' : 'fa-magnifying-glass-dollar'; ?>"></i> <?php echo htmlspecialchars($pricingInfo['chip']); ?></span>
                                </div>

                                <div class="lst-eyebrow"><?php echo htmlspecialchars($categoryLabel); ?></div>
                                <h3 class="lst-title"><?php echo htmlspecialchars($listing['service_name']); ?></h3>
                                <p class="lst-by"><i class="fas fa-building"></i> <?php echo htmlspecialchars($listing['company_name']); ?></p>

                                <p class="lst-desc">
                                    <?php echo htmlspecialchars(substr($listing['description'] ?? '', 0, 120)) . (strlen($listing['description'] ?? '') > 120 ? '...' : ''); ?>
                                </p>

                                <div class="lst-loc">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($listing['city'] ?? 'Location not specified'); ?>
                                </div>

                                <div class="lst-meta-row">
                                    <div class="lst-price-block">
                                        <span class="lst-price-eyebrow"><?php echo htmlspecialchars($pricingInfo['label']); ?></span>
                                        <div class="lst-price">&#8369;<?php echo number_format($listing['price'], 2); ?></div>
                                    </div>
                                    <div class="lst-rating">
                                        <?php if($listing['provider_rating']): ?>
                                            <?php for($i = 1; $i <= 5; $i++): ?>
                                                <?php if($i <= floor($listing['provider_rating'])): ?>
                                                    <i class="fas fa-star"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <span><?php echo number_format($listing['provider_rating'], 1); ?></span>
                                        <?php else: ?>
                                            <span class="lst-new">New Provider</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="lst-actions">
                                    <!--
                                        "Request Service" goes to provider-details.php with:
                                        - avail_service = service ID  → auto-opens the avail modal for that service
                                        No separate request-service.php needed.
                                    -->
                                    <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo (int)$listing['provider_id']; ?>&avail_service=<?php echo (int)$listing['id']; ?>"
                                       class="btn-request">
                                        <i class="fas fa-calendar-plus"></i> Request Service
                                    </a>
                                    <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo (int)$listing['provider_id']; ?>"
                                       class="btn-provider">
                                        <i class="fas fa-user"></i> View Provider
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if($page > 1): ?>
                            <a href="<?php echo appUrl('listings.php'); ?>?page=1&<?php echo $paginationQS; ?>">&laquo; First</a>
                            <a href="<?php echo appUrl('listings.php'); ?>?page=<?php echo $page - 1; ?>&<?php echo $paginationQS; ?>">Previous</a>
                        <?php endif; ?>

                        <?php for($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if($i === $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo appUrl('listings.php'); ?>?page=<?php echo $i; ?>&<?php echo $paginationQS; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="<?php echo appUrl('listings.php'); ?>?page=<?php echo $page + 1; ?>&<?php echo $paginationQS; ?>">Next</a>
                            <a href="<?php echo appUrl('listings.php'); ?>?page=<?php echo $total_pages; ?>&<?php echo $paginationQS; ?>">Last &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h2>No services found</h2>
                    <p>Try adjusting your search filters or browse all services</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include appPath('includes/footer.php'); ?>
</body>
</html>

