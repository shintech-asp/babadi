<?php
// listings.php - SERVICE LISTINGS PAGE
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Build query based on filters
$where = ["sl.status = 'active'"];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(sl.title LIKE :search OR sl.description LIKE :search OR p.company_name LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}

if (isset($_GET['category_id']) && !empty($_GET['category_id'])) {
    $where[] = "sl.category_id = :category_id";
    $params[':category_id'] = $_GET['category_id'];
}

if (isset($_GET['location']) && !empty($_GET['location'])) {
    $where[] = "(p.city LIKE :location OR p.state LIKE :location)";
    $params[':location'] = '%' . $_GET['location'] . '%';
}

$where_clause = implode(' AND ', $where);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM service_listings sl 
               JOIN providers p ON sl.provider_id = p.id 
               JOIN service_categories sc ON sl.category_id = sc.id
               WHERE $where_clause";
$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 9;
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;

// Get services with pagination
$query = "SELECT sl.*, p.company_name, p.logo_url, p.city, p.state, sc.name as category_name,
          (SELECT AVG(overall_rating) FROM reviews WHERE provider_id = p.id) as avg_rating,
          (SELECT COUNT(*) FROM reviews WHERE provider_id = p.id) as review_count
          FROM service_listings sl 
          JOIN providers p ON sl.provider_id = p.id 
          JOIN service_categories sc ON sl.category_id = sc.id
          WHERE $where_clause 
          ORDER BY sl.created_at DESC
          LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$query = "SELECT * FROM service_categories ORDER BY name";
$stmt = $db->prepare($query);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Pest Control Services - Pestify</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .listings-header {
            background: linear-gradient(135deg, #1a1f3a 0%, #2d3561 100%);
            color: white;
            padding: 4rem 0;
            margin-bottom: 3rem;
        }
        
        .listings-header h1 {
            color: white;
            margin-bottom: 1rem;
        }
        
        .listings-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-top: -6rem;
            position: relative;
            z-index: 1;
        }
        
        .filters-sidebar {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-xl);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .filters-sidebar h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neutral-dark);
            margin-bottom: 1.5rem;
        }
        
        .filter-section {
            margin-bottom: 1.75rem;
        }
        
        .filter-section h3 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--neutral-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-section .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
            background: white;
            transition: all 0.2s ease;
        }
        
        .filter-section .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-section label {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            cursor: pointer;
            color: #4B5563;
            font-size: 0.9375rem;
            padding: 0.5rem 0;
            transition: color 0.2s ease;
        }
        
        .filter-section label:hover {
            color: var(--neutral-dark);
        }
        
        .filter-section input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        
        .btn-primary.btn-block {
            width: 100%;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            font-size: 0.9375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-outline.btn-block {
            width: 100%;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            font-size: 0.9375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 8px;
            background: white;
            border: 2px solid #E5E7EB;
            color: #6B7280;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .btn-outline.btn-block:hover {
            background: #F9FAFB;
            border-color: #D1D5DB;
            color: var(--neutral-dark);
        }
        
        .mt-3 {
            margin-top: 1rem;
        }
        
        .mt-2 {
            margin-top: 0.5rem;
        }
        
        .listings-main {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
        }
        
        .listings-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
        }
        
        .sort-select {
            padding: 0.5rem 1rem;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            background: white;
            color: var(--neutral-gray);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--neutral-light);
        }
        
        .page-link {
            padding: 0.5rem 1rem;
            background: var(--neutral-soft);
            color: var(--neutral-dark);
            border-radius: var(--radius);
            text-decoration: none;
            transition: all var(--transition);
        }
        
        .page-link:hover {
            background: var(--primary-light);
            color: var(--primary);
        }
        
        .page-link.active {
            background: var(--primary);
            color: white;
        }
        
        @media (max-width: 1024px) {
            .listings-container {
                grid-template-columns: 1fr;
            }
            
            .filters-sidebar {
                position: static;
                margin-bottom: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php 
    $current_page = 'listings';
    include '../includes/header.php'; 
    ?>
    
    <div class="listings-header">
        <div class="container">
            <h1>Find Pest Control Services</h1>
            <p>Browse and compare professional pest control services in your area</p>
        </div>
    </div>
    
    <div class="container">
        <div class="listings-container">
            <!-- Filters Sidebar -->
            <aside class="filters-sidebar">
                <h2 style="margin-bottom: 1.5rem;">Filter Services</h2>
                <form method="GET" action="" id="filterForm">
                    <div class="filter-section">
                        <h3>Search</h3>
                        <input type="text" name="search" 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" 
                               class="form-control" placeholder="Search services...">
                    </div>
                    
                    <div class="filter-section">
                        <h3>Category</h3>
                        <select name="category_id" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo (isset($_GET['category_id']) && $_GET['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-section">
                        <h3>Location</h3>
                        <input type="text" name="location" 
                               value="<?php echo isset($_GET['location']) ? htmlspecialchars($_GET['location']) : ''; ?>" 
                               class="form-control" placeholder="City or state...">
                    </div>
                    
                    <div class="filter-section">
                        <h3>Features</h3>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label>
                                <input type="checkbox" name="emergency" value="1" 
                                    <?php echo isset($_GET['emergency']) ? 'checked' : ''; ?>>
                                <span>Emergency Service</span>
                            </label>
                            <label>
                                <input type="checkbox" name="eco_friendly" value="1" 
                                    <?php echo isset($_GET['eco_friendly']) ? 'checked' : ''; ?>>
                                <span>Eco-Friendly</span>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary btn-block mt-3">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="listings.php" class="btn-outline btn-block mt-2">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                </form>
            </aside>
            
            <!-- Main Content -->
            <main class="listings-main">
                <div class="listings-toolbar">
                    <div>
                        <h3 style="margin: 0;"><?php echo $totalCount; ?> Services Found</h3>
                        <?php if(isset($_GET['search']) && !empty($_GET['search'])): ?>
                            <p style="margin: 0.5rem 0 0; color: var(--neutral-gray);">
                                Results for: "<?php echo htmlspecialchars($_GET['search']); ?>"
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <select class="sort-select" id="sortSelect">
                            <option value="newest">Newest First</option>
                            <option value="price_low">Price: Low to High</option>
                            <option value="price_high">Price: High to Low</option>
                            <option value="rating">Highest Rated</option>
                        </select>
                    </div>
                </div>
                
                <?php if(count($services) > 0): ?>
                    <div class="service-grid">
                        <?php foreach($services as $service): ?>
                            <?php 
                            $images = $service['images'] ? json_decode($service['images'], true) : [];
                            $firstImage = !empty($images) ? $images[0] : null;
                            ?>
                            <div class="service-card">
                                <?php if($service['is_emergency_available']): ?>
                                    <div class="service-badge">
                                        <i class="fas fa-bolt"></i> Emergency
                                    </div>
                                <?php endif; ?>
                                
                                <div class="service-image">
                                    <?php if($firstImage): ?>
                                        <img src="<?php echo htmlspecialchars($firstImage); ?>" 
                                             alt="<?php echo htmlspecialchars($service['title']); ?>">
                                    <?php else: ?>
                                        <div class="no-image">
                                            <i class="fas fa-bug"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="service-content">
                                    <div class="service-header">
                                        <div>
                                            <h3 class="service-title"><?php echo htmlspecialchars($service['title']); ?></h3>
                                            <div class="company-info">
                                                <?php if($service['logo_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($service['logo_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($service['company_name']); ?>" 
                                                         class="company-logo">
                                                <?php endif; ?>
                                                <span class="company-name"><?php echo htmlspecialchars($service['company_name']); ?></span>
                                            </div>
                                        </div>
                                        <?php if($service['avg_rating']): ?>
                                            <div class="rating">
                                                <i class="fas fa-star"></i>
                                                <span><?php echo number_format($service['avg_rating'], 1); ?></span>
                                                <small>(<?php echo $service['review_count']; ?>)</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="service-description">
                                        <?php echo substr(htmlspecialchars($service['description']), 0, 120); ?>...
                                    </p>
                                    
                                    <div class="service-footer">
                                        <div>
                                            <div class="service-price">â‚±<?php echo number_format($service['price'], 2); ?></div>
                                            <span class="service-price-type"><?php echo ucfirst($service['pricing_type']); ?></span>
                                        </div>
                                        <a href="<?php echo appUrl('listing-details.php'); ?>?id=<?php echo $service['id']; ?>" class="btn-secondary">
                                            View <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="page-link">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php elseif($i == $page - 3 || $i == $page + 3): ?>
                                    <span class="page-link">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="page-link">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center p-5">
                        <div style="font-size: 4rem; color: var(--neutral-light); margin-bottom: 1rem;">
                            <i class="fas fa-search"></i>
                        </div>
                        <h3>No Services Found</h3>
                        <p style="color: var(--neutral-gray); margin-bottom: 2rem;">
                            Try adjusting your search filters or browse all services
                        </p>
                        <a href="listings.php" class="btn-primary">
                            <i class="fas fa-list"></i> Browse All Services
                        </a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script>
        // Filter form submission
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            // Remove empty fields from submission
            const inputs = this.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (!input.value && input.type !== 'checkbox') {
                    input.disabled = true;
                }
            });
        });
        
        // Sort functionality
        document.getElementById('sortSelect').addEventListener('change', function() {
            const sortValue = this.value;
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortValue);
            window.location.href = url.toString();
        });
        
        // Set current sort value
        const urlParams = new URLSearchParams(window.location.search);
        const currentSort = urlParams.get('sort') || 'newest';
        document.getElementById('sortSelect').value = currentSort;
        
        // Initialize service card animations
        document.addEventListener('DOMContentLoaded', function() {
            const serviceCards = document.querySelectorAll('.service-card');
            serviceCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
