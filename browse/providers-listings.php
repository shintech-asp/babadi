<?php
chdir(dirname(__DIR__));
// provider-details.php
require_once 'config/config.php';
require_once 'config/database.php';

if (!isset($_GET['id'])) {
    redirect('providers.php');
}

$database = new Database();
$db = $database->getConnection();

// Get provider details
$query = "SELECT p.*, 
          (SELECT AVG(overall_rating) FROM reviews WHERE provider_id = p.id) as avg_rating,
          (SELECT COUNT(*) FROM reviews WHERE provider_id = p.id) as review_count
          FROM providers p 
          WHERE p.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $_GET['id']);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    redirect('providers.php');
}

// Get provider's services
$query = "SELECT * FROM service_listings WHERE provider_id = :provider_id AND status = 'active' ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $_GET['id']);
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get provider reviews
$query = "SELECT r.*, u.first_name, u.last_name 
          FROM reviews r 
          JOIN users u ON r.reviewer_id = u.id 
          WHERE r.provider_id = :provider_id AND r.status = 'approved'
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $_GET['id']);
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($provider['company_name']); ?> - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="container">
        <div class="provider-detail">
            <div class="provider-header">
                <?php if($provider['logo_url']): ?>
                    <img src="<?php echo htmlspecialchars($provider['logo_url']); ?>" alt="Logo" class="provider-logo-large">
                <?php endif; ?>
                <div class="provider-info">
                    <h1><?php echo htmlspecialchars($provider['company_name']); ?></h1>
                    <?php if($provider['avg_rating']): ?>
                        <p class="rating">? <?php echo number_format($provider['avg_rating'], 1); ?> (<?php echo $provider['review_count']; ?> reviews)</p>
                    <?php endif; ?>
                    <p><strong>Location:</strong> <?php echo htmlspecialchars($provider['city']); ?>, <?php echo htmlspecialchars($provider['state']); ?></p>
                    <p><strong>Service Radius:</strong> <?php echo $provider['service_radius']; ?> km</p>
                </div>
            </div>
            
            <?php if($provider['description']): ?>
                <div class="provider-description">
                    <h2>About Us</h2>
                    <p><?php echo nl2br(htmlspecialchars($provider['description'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(count($services) > 0): ?>
                <div class="provider-services">
                    <h2>Our Services</h2>
                    <div class="service-grid">
                        <?php foreach($services as $service): ?>
                            <?php 
                            $images = $service['images'] ? json_decode($service['images'], true) : [];
                            ?>
                            <div class="service-card">
                                <?php if(!empty($images) && isset($images[0])): ?>
                                    <img src="<?php echo htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($service['title']); ?>">
                                <?php else: ?>
                                    <div class="no-image">No Image</div>
                                <?php endif; ?>
                                <div class="service-info">
                                    <h3><?php echo htmlspecialchars($service['title']); ?></h3>
                                    <p class="price">?<?php echo number_format($service['price'], 2); ?></p>
                                    <p class="description"><?php echo substr(htmlspecialchars($service['description']), 0, 100); ?>...</p>
                                    <a href="<?php echo appUrl('listing-details.php'); ?>?id=<?php echo $service['id']; ?>" class="btn-view">View Details</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if(count($reviews) > 0): ?>
                <div class="provider-reviews">
                    <h2>Customer Reviews</h2>
                    <?php foreach($reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <strong><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></strong>
                                <span class="rating">
                                    <?php for($i = 0; $i < $review['overall_rating']; $i++) echo '?'; ?>
                                    <?php for($i = $review['overall_rating']; $i < 5; $i++) echo '?'; ?>
                                </span>
                            </div>
                            <?php if($review['comment']): ?>
                                <p><?php echo htmlspecialchars($review['comment']); ?></p>
                            <?php endif; ?>
                            <small><?php echo date('F j, Y', strtotime($review['created_at'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
    
    <style>
        .provider-detail {
            margin: 2rem 0;
        }
        
        .provider-header {
            display: flex;
            gap: 2rem;
            align-items: center;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .provider-logo-large {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .provider-info h1 {
            margin-bottom: 0.5rem;
        }
        
        .provider-info .rating {
            color: #f39c12;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .provider-description,
        .provider-services,
        .provider-reviews {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .provider-services h2,
        .provider-reviews h2 {
            margin-bottom: 1.5rem;
        }
        
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .review-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .provider-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</body>
</html>
