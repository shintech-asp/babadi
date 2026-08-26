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
            background: #f5f7fa;
            padding: 40px 20px;
        }

        .services-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .page-header h1 {
            font-size: 36px;
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .page-header p {
            font-size: 16px;
            color: #666;
        }

        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 20px;
        }

        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
        }

        .filter-item label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }

        .filter-item input,
        .filter-item select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        .filter-item input:focus,
        .filter-item select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-search {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
        }

        .btn-search:hover { background: #2980b9; }

        .btn-reset {
            padding: 10px 20px;
            background: #ecf0f1;
            color: #333;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reset:hover { background: #bdc3c7; }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .results-count {
            font-size: 14px;
            color: #666;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .service-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .service-header {
            background: linear-gradient(135deg, var(--primary), #2980b9);
            padding: 20px;
            color: white;
        }

        .service-category {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .service-name {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .service-provider {
            font-size: 13px;
            opacity: 0.9;
        }

        .service-body {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .service-description {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
            flex-grow: 1;
        }

        .service-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .service-price {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
        }

        .service-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
        }

        .service-rating i { color: #f39c12; }

        .service-location {
            font-size: 13px;
            color: #999;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .service-actions {
            display: flex;
            gap: 10px;
        }

        .service-actions a {
            flex: 1;
            padding: 10px;
            text-align: center;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-request {
            background: var(--primary);
            color: white;
        }

        .btn-request:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }

        .btn-provider {
            background: #ecf0f1;
            color: #333;
        }

        .btn-provider:hover { background: #bdc3c7; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 64px;
            color: #bdc3c7;
            margin-bottom: 20px;
            display: block;
        }

        .empty-state h2 {
            font-size: 24px;
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .empty-state p { color: #666; }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        @media (max-width: 768px) {
            .page-header h1 { font-size: 28px; }
            .filter-group { grid-template-columns: 1fr; }
            .services-grid { grid-template-columns: 1fr; }
            .results-header { flex-direction: column; gap: 15px; align-items: flex-start; }
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
                <h3 class="filter-title">Search &amp; Filter Services</h3>
                <form method="GET" action="<?php echo appUrl('listings.php'); ?>">
                    <div class="filter-group">
                        <div class="filter-item">
                            <label for="search">Service Name</label>
                            <input type="text" id="search" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="e.g., Termite Control, Mosquito Treatment...">
                        </div>

                        <div class="filter-item">
                            <label for="category">Category</label>
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
                            <label for="province">Province</label>
                            <select id="province" name="province">
                                <option value="">-- Select Province --</option>
                                <option value="Cavite" selected>Cavite</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label for="city">City/Municipality</label>
                            <select id="city" name="city" disabled>
                                <option value="">-- Select City/Municipality --</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label for="sort">Sort By</label>
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
                <div class="services-grid">
                    <?php foreach($listings as $listing):
                        // Build payment settings for deep-linking into the avail modal
                        $ps       = json_decode($listing['payment_settings'] ?? '{}', true) ?? [];
                        $ps_mode  = $ps['dp_mode']    ?? 'percent';
                        $ps_pct   = (float)($ps['dp_percent'] ?? 50);
                        $ps_fixed = (float)($ps['dp_fixed']   ?? 0);
                        $svc_price = (float)($listing['price'] ?? 0);
                    ?>
                        <div class="service-card">
                            <div class="service-header">
                                <div class="service-category">
                                    <?php echo htmlspecialchars($listing['category_name'] ?? 'General'); ?>
                                </div>
                                <h3 class="service-name">
                                    <?php echo htmlspecialchars($listing['service_name']); ?>
                                </h3>
                                <p class="service-provider">
                                    by <?php echo htmlspecialchars($listing['company_name']); ?>
                                </p>
                            </div>

                            <div class="service-body">
                                <p class="service-description">
                                    <?php echo htmlspecialchars(substr($listing['description'] ?? '', 0, 80)) . (strlen($listing['description'] ?? '') > 80 ? '...' : ''); ?>
                                </p>

                                <div class="service-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($listing['city'] ?? 'Location not specified'); ?>
                                </div>

                                <div class="service-details">
                                    <div class="service-price">
                                        &#8369;<?php echo number_format($listing['price'], 2); ?>
                                    </div>
                                    <div class="service-rating">
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
                                            <span style="color:#999;">New Provider</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="service-actions">
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
    <script>
        const provinceCities = {
            'Cavite': [
                'Cavite City','Tagaytay City','Trece Martires City','Alfonso','Amadeo',
                'Bacoor','Carmona','Dasmariñas','General Emilio Aguinaldo',
                'General Mariano Alvarez','General Trias','Imus','Indang','Kawit',
                'Magallanes','Maragondon','Mendez','Naic','Noveleta','Rosario',
                'Silang','Tanza','Ternate'
            ]
        };

        document.getElementById('province').addEventListener('change', function() {
            const citySelect = document.getElementById('city');
            citySelect.innerHTML = '<option value="">-- Select City/Municipality --</option>';
            if (this.value && provinceCities[this.value]) {
                citySelect.disabled = false;
                provinceCities[this.value].forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c; opt.textContent = c;
                    citySelect.appendChild(opt);
                });
            } else {
                citySelect.disabled = true;
            }
        });

        window.addEventListener('load', function() {
            const savedCity = '<?php echo htmlspecialchars($city ?? ''); ?>';
            document.getElementById('province').value = 'Cavite';
            document.getElementById('province').dispatchEvent(new Event('change'));
            if (savedCity) {
                setTimeout(() => { document.getElementById('city').value = savedCity; }, 100);
            }
        });
    </script>
</body>
</html>

