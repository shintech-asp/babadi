<?php
chdir(dirname(__DIR__));
// dashboard.php
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'provider') {
    header("Location: " . appUrl('login.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();

$provider_id = $_SESSION['provider_id'] ?? 0;

// Get provider info
$query = "SELECT p.*, u.email, u.first_name, u.last_name FROM providers p JOIN users u ON p.user_id = u.id WHERE p.id = :provider_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

// Stats
$query = "SELECT COUNT(*) as total FROM service_listings WHERE provider_id = :provider_id";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$total_services = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT COUNT(*) as total FROM service_requests WHERE provider_id = (SELECT user_id FROM providers WHERE id = :provider_id)";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$total_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT COUNT(*) as total FROM service_requests WHERE provider_id = (SELECT user_id FROM providers WHERE id = :provider_id) AND status = 'pending'";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT COUNT(*) as total FROM service_requests WHERE provider_id = (SELECT user_id FROM providers WHERE id = :provider_id) AND status = 'completed'";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$completed_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT AVG(overall_rating) as avg_rating, COUNT(*) as review_count FROM reviews WHERE provider_id = :provider_id";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$rating_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent requests
$query = "SELECT sr.*, sl.title, u.first_name, u.last_name, u.phone 
          FROM service_requests sr 
          LEFT JOIN service_listings sl ON sr.listing_id = sl.id 
          JOIN users u ON sr.seeker_id = u.id 
          WHERE sr.provider_id = (SELECT user_id FROM providers WHERE id = :provider_id)
          ORDER BY sr.created_at DESC LIMIT 8";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Services
$query = "SELECT sl.*, sc.name as category_name 
          FROM service_listings sl
          JOIN service_categories sc ON sl.category_id = sc.id
          WHERE sl.provider_id = :provider_id 
          ORDER BY sl.created_at DESC LIMIT 6";
$stmt = $db->prepare($query); $stmt->bindParam(':provider_id', $provider_id); $stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --dark: #2c3e50;
            --light-bg: #f5f7fa;
            --white: #ffffff;
            --border: #ecf0f1;
            --text-muted: #7f8c8d;
            --sidebar-from: #1a1f3a;
            --sidebar-to: #2d3561;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--light-bg);
            color: var(--dark);
        }

        /* -- Layout -- */
        .dashboard-container { display: flex; min-height: 100vh; }

        /* -- Sidebar -- */
        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, var(--sidebar-from) 0%, var(--sidebar-to) 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.12);
            z-index: 100;
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            background: rgba(0,0,0,0.1);
        }

        .sidebar-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-header p {
            font-size: 12px;
            color: rgba(255,255,255,0.7);
            margin-top: 4px;
        }

        .sidebar-menu { list-style: none; padding: 15px 0; }
        .sidebar-menu li { margin-bottom: 2px; }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.25s;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.18);
            color: white;
            border-left: 4px solid white;
            padding-left: 16px;
        }

        .sidebar-menu a i { margin-right: 12px; width: 20px; text-align: center; }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            position: absolute;
            bottom: 0;
            width: 100%;
            background: rgba(0,0,0,0.05);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            font-weight: 700;
            color: var(--primary);
            flex-shrink: 0;
        }

        .user-info h4 { font-size: 13px; color: white; font-weight: 600; margin-bottom: 2px; }
        .user-info p  { font-size: 11px; color: rgba(255,255,255,0.7); }

        /* -- Main -- */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 32px;
            padding-bottom: 80px;
        }

        /* -- Page Header -- */
        .page-header { margin-bottom: 28px; }
        .page-header h1 { font-size: 28px; font-weight: 700; color: var(--dark); }
        .page-header p  { font-size: 14px; color: var(--text-muted); margin-top: 4px; }

        /* -- Buttons -- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.25s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(52,152,219,0.3);
        }

        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(52,152,219,0.4); }

        .btn-secondary {
            background: var(--white);
            color: var(--dark);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover { background: #f0f3f5; }
        .btn-sm { padding: 7px 14px; font-size: 13px; }

        /* -- Stats Grid -- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--white);
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.10);
            border-color: var(--primary);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .stat-icon.blue   { background: linear-gradient(135deg, #3498db, #2980b9); }
        .stat-icon.green  { background: linear-gradient(135deg, #27ae60, #16a085); }
        .stat-icon.orange { background: linear-gradient(135deg, #e67e22, #d35400); }
        .stat-icon.purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .stat-icon.gold   { background: linear-gradient(135deg, #f39c12, #e67e22); }

        .stat-info h3 { font-size: 26px; font-weight: 700; color: var(--dark); }
        .stat-info p  { font-size: 13px; color: var(--text-muted); margin-top: 3px; }

        /* -- Quick Actions Bar -- */
        .quick-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        /* -- Content Card -- */
        .content-card {
            background: var(--white);
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            border: 1px solid var(--border);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 26px;
            border-bottom: 1px solid var(--border);
        }

        .card-header h2 {
            font-size: 17px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h2 i { color: var(--primary); }

        /* -- Table -- */
        .table-wrap { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            background: #f8f9fa;
            padding: 13px 18px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
        }

        tbody td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafbfc; }

        /* -- Status Badges -- */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending     { background: #fff3cd; color: #856404; }
        .status-accepted    { background: #cfe2ff; color: #084298; }
        .status-in_progress { background: #d1ecf1; color: #0c5460; }
        .status-completed   { background: #d4edda; color: #155724; }
        .status-rejected    { background: #f8d7da; color: #721c24; }
        .status-cancelled   { background: #e2e3e5; color: #383d41; }
        .status-active      { background: #d4edda; color: #155724; }
        .status-inactive    { background: #e2e3e5; color: #383d41; }

        /* -- Service Cards Grid -- */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 18px;
            padding: 22px 26px;
        }

        .service-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.25s;
            background: var(--white);
        }

        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.10);
            border-color: var(--primary);
        }

        .service-card-img {
            width: 100%;
            height: 140px;
            object-fit: cover;
        }

        .no-image {
            width: 100%;
            height: 140px;
            background: linear-gradient(135deg, #ecf0f1, #dfe6e9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        .no-image i { font-size: 32px; color: #bdc3c7; }

        .service-card-body { padding: 16px; }

        .service-card-body h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .category-tag {
            display: inline-block;
            background: #eaf4fd;
            color: var(--primary);
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 8px;
        }

        .service-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .service-price {
            font-size: 16px;
            font-weight: 700;
            color: #27ae60;
        }

        .service-views {
            font-size: 12px;
            color: var(--text-muted);
        }

        .service-card-footer {
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
        }

        /* -- Empty State -- */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-icon {
            width: 70px;
            height: 70px;
            background: var(--light-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .empty-icon i { font-size: 30px; color: #bdc3c7; }
        .empty-state h3 { font-size: 18px; font-weight: 600; color: var(--dark); margin-bottom: 6px; }
        .empty-state p  { font-size: 14px; margin-bottom: 16px; }

        /* -- Rating Stars -- */
        .rating-display {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stars { color: #f39c12; font-size: 14px; }

        /* -- Scrollbar -- */
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 3px; }

        /* -- Responsive -- */
        @media (max-width: 1024px) {
            .sidebar { width: 220px; }
            .main-content { margin-left: 220px; }
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 20px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .services-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- -- Sidebar -- -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-bug"></i> Pestify</h2>
            <p>Provider Portal</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?php echo appUrl('dashboard.php'); ?>" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo appUrl('services.php'); ?>"><i class="fas fa-briefcase"></i> My Services</a></li>
            <li><a href="<?php echo appUrl('service-requests.php'); ?>"><i class="fas fa-list-check"></i> Requests</a></li>
            <li><a href="<?php echo appUrl('messages.php'); ?>"><i class="fas fa-comments"></i> Messages</a></li>
            <li><a href="<?php echo appUrl('profile.php'); ?>"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="<?php echo appUrl('logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['company_name'] ?? $provider['company_name'] ?? 'P', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['company_name'] ?? $provider['company_name'] ?? 'Provider'); ?></h4>
                    <p><?php echo htmlspecialchars($provider['email'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- -- Main Content -- -->
    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['company_name'] ?? $provider['first_name'] ?? 'Provider'); ?>! ??</h1>
            <p><?php echo date('l, F j, Y'); ?></p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-briefcase"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_services; ?></h3>
                    <p>Total Services</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3><?php echo $pending_requests; ?></h3>
                    <p>Pending Requests</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3><?php echo $completed_requests; ?></h3>
                    <p>Completed Jobs</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-inbox"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_requests; ?></h3>
                    <p>Total Requests</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-star"></i></div>
                <div class="stat-info">
                    <?php if ($rating_stats['avg_rating']): ?>
                        <h3><?php echo number_format($rating_stats['avg_rating'], 1); ?></h3>
                        <p>Avg Rating (<?php echo $rating_stats['review_count']; ?> reviews)</p>
                    <?php else: ?>
                        <h3>—</h3>
                        <p>No reviews yet</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-bar">
            <a href="<?php echo appUrl('create-listing.php'); ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create New Service
            </a>
            <a href="<?php echo appUrl('profile.php'); ?>" class="btn btn-secondary">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>
            <a href="<?php echo appUrl('service-requests.php'); ?>" class="btn btn-secondary">
                <i class="fas fa-list"></i> View All Requests
            </a>
        </div>

        <!-- Recent Requests -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Recent Service Requests</h2>
                <a href="<?php echo appUrl('service-requests.php'); ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-eye"></i> View All
                </a>
            </div>

            <?php if (count($recent_requests) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Service / Pest Type</th>
                            <th>Customer</th>
                            <th>Preferred Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_requests as $r): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:13px;"><strong>#<?php echo $r['id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($r['title'] ?: $r['pest_type']); ?></strong>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?><br>
                                <small style="color:var(--text-muted);"><?php echo htmlspecialchars($r['phone']); ?></small>
                            </td>
                            <td style="font-size:13px; color:var(--text-muted);">
                                <?php if ($r['preferred_date']): ?>
                                    <?php echo date('M j, Y', strtotime($r['preferred_date'])); ?>
                                    <?php if ($r['preferred_time']): ?>
                                        <br><small><?php echo date('g:i A', strtotime($r['preferred_time'])); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Not specified
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $r['status']; ?>">
                                    <?php echo ucfirst($r['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo appUrl('request-details.php'); ?>?id=<?php echo $r['id']; ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                <h3>No service requests yet</h3>
                <p>Requests from customers will appear here once they start coming in.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Services -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-briefcase"></i> My Services</h2>
                <a href="<?php echo appUrl('create-listing.php'); ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Service
                </a>
            </div>

            <?php if (count($services) > 0): ?>
            <div class="services-grid">
                <?php foreach ($services as $service):
                    $images = $service['images'] ? json_decode($service['images'], true) : [];
                ?>
                <div class="service-card">
                    <?php if (!empty($images) && isset($images[0])): ?>
                        <img class="service-card-img" src="<?php echo htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($service['title']); ?>">
                    <?php else: ?>
                        <div class="no-image"><i class="fas fa-image"></i></div>
                    <?php endif; ?>

                    <div class="service-card-body">
                        <h3><?php echo htmlspecialchars($service['title']); ?></h3>
                        <span class="category-tag"><?php echo htmlspecialchars($service['category_name']); ?></span>
                        <div class="service-meta">
                            <span class="service-price">?<?php echo number_format($service['price'], 2); ?></span>
                            <span class="service-views"><i class="fas fa-eye"></i> <?php echo $service['views_count']; ?></span>
                        </div>
                        <div style="margin-top:8px;">
                            <span class="status-badge status-<?php echo $service['status']; ?>">
                                <?php echo ucfirst($service['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="service-card-footer">
                        <a href="<?php echo appUrl('listing-details.php'); ?>?id=<?php echo $service['id']; ?>" class="btn btn-secondary btn-sm" style="flex:1; justify-content:center;">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="<?php echo appUrl('create-listing.php'); ?>?edit=<?php echo $service['id']; ?>" class="btn btn-primary btn-sm" style="flex:1; justify-content:center;">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-briefcase"></i></div>
                <h3>No services yet</h3>
                <p>Create your first service to start receiving requests.</p>
                <a href="<?php echo appUrl('create-listing.php'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Create Your First Service</a>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>
<?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>
