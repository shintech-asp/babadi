<?php
// admin/providers.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle provider actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $provider_id = isset($_POST['provider_id']) ? intval($_POST['provider_id']) : 0;
        $action = $_POST['action'];
        
        switch ($action) {
            case 'toggle_status':
                $new_status = $_POST['new_status'];
                $query = "UPDATE providers SET status = :status WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':id', $provider_id);
                if ($stmt->execute()) {
                    $success_message = "Provider status updated to " . ucfirst($new_status) . "!";
                } else {
                    $error_message = "Failed to update provider status.";
                }
                break;
        }
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query
$query = "SELECT p.*, u.first_name, u.last_name, u.email, u.phone,
          (SELECT AVG(overall_rating) FROM reviews WHERE provider_id = p.id) as avg_rating,
          (SELECT COUNT(*) FROM services WHERE provider_id = p.id AND status = 'active') as service_count
          FROM providers p 
          JOIN users u ON p.user_id = u.id
          WHERE 1=1";

if ($filter_status != 'all') {
    $query .= " AND p.status = :status";
}

if (!empty($search)) {
    $query .= " AND (p.company_name LIKE :search OR u.email LIKE :search)";
}

$query .= " ORDER BY p.company_name";

$stmt = $db->prepare($query);

if ($filter_status != 'all') {
    $stmt->bindParam(':status', $filter_status);
}

if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

$stmt->execute();
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count providers by status
$active_count = $db->query("SELECT COUNT(*) FROM providers WHERE status = 'active'")->fetchColumn();
$inactive_count = $db->query("SELECT COUNT(*) FROM providers WHERE status = 'inactive'")->fetchColumn();
$suspended_count = $db->query("SELECT COUNT(*) FROM providers WHERE status = 'suspended'")->fetchColumn();
$total_count = $db->query("SELECT COUNT(*) FROM providers")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Providers Management - Pestify Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .provider-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--neutral-light);
            display: flex;
            gap: 1.5rem;
            transition: all var(--transition);
        }

        .provider-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .provider-logo-container {
            flex-shrink: 0;
        }

        .provider-logo {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--radius);
        }

        .no-logo {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            border-radius: var(--radius);
        }

        .provider-content {
            flex: 1;
        }

        .provider-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .provider-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--neutral-dark);
        }

        .provider-status {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .provider-status.active {
            background: #D1FAE5;
            color: #065F46;
        }

        .provider-status.inactive {
            background: #E5E7EB;
            color: #374151;
        }

        .provider-status.suspended {
            background: #FED7AA;
            color: #9A3412;
        }

        .provider-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9375rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--neutral-gray);
        }

        .meta-item i {
            color: var(--primary);
            width: 20px;
        }

        .provider-rating {
            color: #f39c12;
            font-weight: bold;
        }

        .provider-description {
            color: var(--neutral-gray);
            font-size: 0.9375rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .provider-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: var(--radius);
            border: none;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition);
            font-size: 0.9375rem;
        }

        .action-view {
            color: var(--info);
        }

        .action-view:hover {
            background: #DBEAFE;
        }

        .action-status {
            color: var(--success);
        }

        .action-status:hover {
            background: #D1FAE5;
        }

        .providers-list {
            display: grid;
            gap: 1.5rem;
        }

        .no-providers {
            padding: 4rem 2rem;
            text-align: center;
            color: var(--neutral-gray);
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-light);
        }

        .no-providers i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .no-providers h3 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            color: var(--neutral-dark);
        }

        .no-providers p {
            margin: 0;
        }

        @media (max-width: 768px) {
            .provider-card {
                flex-direction: column;
            }

            .provider-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="logo-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Pestify Admin</h3>
                        <p>Control Panel</p>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <div class="menu-section">
                    <h4>Dashboard</h4>
                    <a href="dashboard.php" class="menu-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Overview</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>Content Management</h4>
                    <a href="users.php" class="menu-item">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="providers.php" class="menu-item active">
                        <i class="fas fa-building"></i>
                        <span>Providers</span>
                        <span class="menu-badge"><?php echo $total_count; ?></span>
                    </a>
                    <a href="verify-providers.php" class="menu-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Verify Providers</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>System</h4>
                    <a href="settings.php" class="menu-item">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                <a href="<?php echo appUrl('logout.php'); ?>" class="menu-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Providers Management</h1>
                    <p>Manage and monitor all service providers</p>
                </div>
                
                <div class="header-actions">
                    <form method="GET" action="" class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" placeholder="Search providers..." value="<?php echo htmlspecialchars($search); ?>">
                        <?php if($filter_status != 'all'): ?>
                            <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                        <?php endif; ?>
                    </form>
                    <a href="dashboard.php" class="btn-primary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Providers</h3>
                        <div class="stat-number"><?php echo number_format($total_count); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Active</h3>
                        <div class="stat-number"><?php echo number_format($active_count); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-pause-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Inactive</h3>
                        <div class="stat-number"><?php echo number_format($inactive_count); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Suspended</h3>
                        <div class="stat-number"><?php echo number_format($suspended_count); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?status=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_status == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span>All (<?php echo $total_count; ?>)</span>
                </a>
                
                <a href="?status=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_status == 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i>
                    <span>Active (<?php echo $active_count; ?>)</span>
                </a>
                
                <a href="?status=inactive<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_status == 'inactive' ? 'active' : ''; ?>">
                    <i class="fas fa-pause-circle"></i>
                    <span>Inactive (<?php echo $inactive_count; ?>)</span>
                </a>
                
                <a href="?status=suspended<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_status == 'suspended' ? 'active' : ''; ?>">
                    <i class="fas fa-ban"></i>
                    <span>Suspended (<?php echo $suspended_count; ?>)</span>
                </a>
            </div>
            
            <!-- Providers List -->
            <div class="providers-list">
                <?php if(count($providers) > 0): ?>
                    <?php foreach($providers as $provider): ?>
                        <div class="provider-card">
                            <div class="provider-logo-container">
                                <?php if($provider['logo_url']): ?>
                                    <img src="<?php echo htmlspecialchars($provider['logo_url']); ?>" alt="<?php echo htmlspecialchars($provider['company_name']); ?>" class="provider-logo">
                                <?php else: ?>
                                    <div class="no-logo"><?php echo strtoupper(substr($provider['company_name'], 0, 2)); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="provider-content">
                                <div class="provider-header">
                                    <div>
                                        <h3><?php echo htmlspecialchars($provider['company_name']); ?></h3>
                                        <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: var(--neutral-gray);">
                                            <?php echo htmlspecialchars($provider['email']); ?>
                                        </p>
                                    </div>
                                    <span class="provider-status <?php echo $provider['status']; ?>">
                                        <?php echo ucfirst($provider['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="provider-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-star"></i>
                                        <span class="provider-rating">
                                            <?php echo $provider['avg_rating'] ? number_format($provider['avg_rating'], 1) . ' ★' : 'No ratings'; ?>
                                        </span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-spray-can"></i>
                                        <span><?php echo $provider['service_count'] ?? 0; ?> active services</span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($provider['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($provider['state'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($provider['phone'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                
                                <?php if($provider['description']): ?>
                                    <div class="provider-description">
                                        <?php echo htmlspecialchars(substr($provider['description'], 0, 200)); ?>
                                        <?php if(strlen($provider['description']) > 200): ?>...<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="provider-actions">
                                    <button class="action-btn action-view" 
                                            onclick="viewProvider(<?php echo $provider['id']; ?>)" 
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn action-status" 
                                            onclick="changeStatus(<?php echo $provider['id']; ?>, '<?php echo $provider['status']; ?>', '<?php echo htmlspecialchars($provider['company_name']); ?>')" 
                                            title="Change Status">
                                        <i class="fas fa-toggle-on"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-providers">
                        <i class="fas fa-building"></i>
                        <h3>No Providers Found</h3>
                        <p>There are no providers matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Status Change Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Provider Status</h3>
                <button type="button" class="modal-close" onclick="closeStatusModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="provider_id" id="status_provider_id">
                    
                    <div class="form-group">
                        <label for="new_status">Select New Status</label>
                        <select name="new_status" id="new_status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    
                    <p style="color: var(--neutral-gray); font-size: 0.875rem; margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        Changing the provider status will affect their visibility and ability to receive service requests.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function viewProvider(id) {
            alert('View provider #' + id + ' - Provider details page coming soon');
        }
        
        function changeStatus(id, currentStatus, name) {
            document.getElementById('status_provider_id').value = id;
            document.getElementById('new_status').value = currentStatus;
            document.getElementById('statusModal').classList.add('active');
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.animation = 'slideUp 0.3s ease';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>
