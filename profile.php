<?php
// profile.php
require_once 'config/config.php';
require_once 'config/database.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$database = new Database();
$db = $database->getConnection();
$error = '';
$success = '';
$password_error = '';
$password_success = '';
$service_error = '';
$service_success = '';
$avatar_error = '';
$avatar_success = '';
$has_profile_image_column = false;

try {
    $column_check = $db->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
    if ($column_check && $column_check->rowCount() > 0) {
        $has_profile_image_column = true;
    } else {
        $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL");
        $has_profile_image_column = true;
    }
} catch (Throwable $e) {
    $has_profile_image_column = false;
}

// Get user info
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_avatar'])) {
    if (!$has_profile_image_column) {
        $avatar_error = "Profile photo feature is unavailable right now.";
    } elseif (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        $avatar_error = "Please choose a valid image file.";
    } else {
        $file = $_FILES['profile_image'];
        $image_info = @getimagesize($file['tmp_name']);
        $allowed_mime_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        $max_file_size = 5 * 1024 * 1024;

        if ($image_info === false || !isset($allowed_mime_types[$image_info['mime']])) {
            $avatar_error = "Only JPG, PNG, WEBP, or GIF images are allowed.";
        } elseif ($file['size'] > $max_file_size) {
            $avatar_error = "Image is too large. Maximum file size is 5MB.";
        } else {
            $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                $avatar_error = "Unable to create profile upload folder.";
            } else {
                $extension = $allowed_mime_types[$image_info['mime']];
                $filename = 'user_' . (int)$_SESSION['user_id'] . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
                $target_path = $upload_dir . DIRECTORY_SEPARATOR . $filename;
                $relative_path = 'uploads/profile/' . $filename;

                if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                    $avatar_error = "Unable to upload profile photo.";
                } else {
                    $old_photo = $has_profile_image_column ? ($user['profile_image'] ?? '') : '';
                    $update_avatar = $db->prepare("UPDATE users SET profile_image = :profile_image WHERE id = :user_id");
                    $update_avatar->bindParam(':profile_image', $relative_path);
                    $update_avatar->bindParam(':user_id', $_SESSION['user_id']);

                    if ($update_avatar->execute()) {
                        $user['profile_image'] = $relative_path;
                        $_SESSION['profile_image'] = $relative_path;
                        $avatar_success = "Profile photo updated successfully.";

                        $old_photo_normalized = str_replace('\\', '/', (string)$old_photo);
                        if (!empty($old_photo_normalized) && strpos($old_photo_normalized, 'uploads/profile/') === 0) {
                            $old_photo_absolute = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_photo_normalized);
                            if (is_file($old_photo_absolute)) {
                                @unlink($old_photo_absolute);
                            }
                        }
                    } else {
                        @unlink($target_path);
                        $avatar_error = "Failed to save profile photo.";
                    }
                }
            }
        }
    }
}

// Initialize provider data
$provider = null;
$total_services = 0;
$pending_requests = 0;
$completed_requests = 0;
$total_revenue = 0;
$recent_requests = [];
$provider_services = [];

if (isProvider()) {
    // Get provider profile
    $query = "SELECT * FROM providers WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($provider) {
        $provider_id = $provider['id'];
        
        // Get statistics
        $query = "SELECT COUNT(*) as total FROM services WHERE provider_id = :provider_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $total_services = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $query = "SELECT COUNT(*) as total FROM service_requests WHERE provider_id = :provider_id AND status = 'pending'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $query = "SELECT COUNT(*) as total FROM service_requests WHERE provider_id = :provider_id AND status = 'completed'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $completed_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get recent service requests
        $query = "SELECT sr.*, u.first_name, u.last_name, u.email, u.phone as user_phone, 
                  s.service_name, s.price
                  FROM service_requests sr
                  JOIN users u ON sr.user_id = u.id
                  LEFT JOIN services s ON sr.service_id = s.id
                  WHERE sr.provider_id = :provider_id
                  ORDER BY sr.created_at DESC
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total revenue
        $query = "SELECT COALESCE(SUM(s.price), 0) as total_revenue 
                  FROM service_requests sr
                  LEFT JOIN services s ON sr.service_id = s.id
                  WHERE sr.provider_id = :provider_id AND sr.status = 'completed'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_revenue = $revenue_data['total_revenue'] ?? 0;
        
        // Get provider services
        $query = "SELECT id, service_name, description, price, created_at FROM services WHERE provider_id = :provider_id ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $provider_id);
        $stmt->execute();
        $provider_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Handle add service
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_service'])) {
            $service_name = sanitize($_POST['service_name']);
            $description = sanitize($_POST['description']);
            $price = floatval($_POST['price']);
            
            if (empty($service_name) || $price <= 0) {
                $service_error = "Service name and valid price are required.";
            } else {
                $query = "INSERT INTO services (provider_id, service_name, description, price, created_at) 
                         VALUES (:provider_id, :service_name, :description, :price, NOW())";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':provider_id', $provider_id);
                $stmt->bindParam(':service_name', $service_name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':price', $price);
                
                if ($stmt->execute()) {
                    $service_success = "Service added successfully!";
                    // Refresh services
                    $stmt = $db->prepare("SELECT id, service_name, description, price, created_at FROM services WHERE provider_id = :provider_id ORDER BY created_at DESC");
                    $stmt->bindParam(':provider_id', $provider_id);
                    $stmt->execute();
                    $provider_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Refresh total services count
                    $stmt = $db->prepare("SELECT COUNT(*) as total FROM services WHERE provider_id = :provider_id");
                    $stmt->bindParam(':provider_id', $provider_id);
                    $stmt->execute();
                    $total_services = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                } else {
                    $service_error = "Failed to add service.";
                }
            }
        }
        
        // Handle delete service
        if (isset($_GET['delete_service'])) {
            $service_id = intval($_GET['delete_service']);
            $query = "DELETE FROM services WHERE id = :id AND provider_id = :provider_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $service_id);
            $stmt->bindParam(':provider_id', $provider_id);
            
            if ($stmt->execute()) {
                header("Location: profile.php");
                exit();
            }
        }
        
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
            $first_name = sanitize($_POST['first_name']);
            $last_name = sanitize($_POST['last_name']);
            $phone = sanitize($_POST['phone']);
            $company_name = sanitize($_POST['company_name']);
            $address = sanitize($_POST['address']);
            $city = sanitize($_POST['city']);
            $state = sanitize($_POST['state']);
            $zip_code = sanitize($_POST['zip_code']);
            $description = sanitize($_POST['description']);
            $logo_url = sanitize($_POST['logo_url']);
            $service_radius = sanitize($_POST['service_radius']);
            
            // Update user
            $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, phone = :phone WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':first_name', $first_name);
            $stmt->bindParam(':last_name', $last_name);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            // Update provider
            $query = "UPDATE providers SET company_name = :company_name, address = :address, city = :city, state = :state, zip_code = :zip_code, description = :description, logo_url = :logo_url, service_radius = :service_radius, updated_at = NOW() WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':company_name', $company_name);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':city', $city);
            $stmt->bindParam(':state', $state);
            $stmt->bindParam(':zip_code', $zip_code);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':logo_url', $logo_url);
            $stmt->bindParam(':service_radius', $service_radius);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $_SESSION['company_name'] = $company_name;
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $success = "Profile updated successfully!";
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $db->prepare("SELECT * FROM providers WHERE user_id = :user_id");
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->execute();
                $provider = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Failed to update profile.";
            }
        }
    }
} else {
    // Seeker profile
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $phone = sanitize($_POST['phone']);
        
        $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, phone = :phone WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $success = "Profile updated successfully!";
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error = "Failed to update profile.";
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        $password_error = "Current password is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $password_error = "New password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match.";
    } else {
        // Update password
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $query = "UPDATE users SET password = :password WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $new_password_hash);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $password_success = "Password changed successfully!";
        } else {
            $password_error = "Failed to change password.";
        }
    }
}

$avatar_image_url = '';
if ($has_profile_image_column && !empty($user['profile_image'])) {
    $avatar_image_url = $user['profile_image'];
} elseif (isProvider() && !empty($provider['logo_url'])) {
    $avatar_image_url = $provider['logo_url'];
}

$page_title = 'My Profile';
$body_class = isProvider() ? 'provider-theme' : '';
$hide_discovery_links = true;
ob_start();
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
<style>
        .dashboard-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
            border: 1px solid #f0f0f0;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
            border-color: #3498db;
        }

        .stat-icon {
            width: 65px;
            height: 65px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #27ae60, #16a085);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }

        .stat-info h3 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .stat-info p {
            color: #7f8c8d;
            font-size: 14px;
        }

        .requests-table {
            width: 100%;
            border-collapse: collapse;
        }

        .requests-table th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #ecf0f1;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .requests-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
            color: #2c3e50;
        }

        .requests-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-accepted {
            background: #cfe2ff;
            color: #084298;
        }

        .status-in_progress {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-cancelled {
            background: #e2e3e5;
            color: #383d41;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .service-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
        }

        .service-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
            border-color: #3498db;
            transform: translateY(-3px);
        }

        .service-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .service-header h3 {
            font-size: 18px;
            color: #2c3e50;
            margin: 0;
            flex-grow: 1;
        }

        .btn-delete {
            background: #f8d7da;
            color: #721c24;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-delete:hover {
            background: #f5c6cb;
        }

        .service-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15px;
            min-height: 42px;
        }

        .service-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }

        .service-price {
            font-size: 16px;
            font-weight: bold;
            color: #3498db;
        }

        .service-date {
            font-size: 12px;
            color: #999;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #bdc3c7;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 14px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
    <style>
        .provider-theme {
            font-family: 'Manrope', sans-serif;
            color: #0f172a;
            background:
                radial-gradient(circle at 10% -10%, rgba(14,165,233,.18), transparent 35%),
                radial-gradient(circle at 95% 5%, rgba(16,185,129,.14), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, #f1f6fb 100%);
        }

        .provider-theme .profile-container {
            width: min(1280px, calc(100% - 36px));
            margin: 24px auto;
            gap: 24px;
        }

        .provider-theme .profile-sidebar {
            background: linear-gradient(165deg, #0b1a3a 0%, #132f57 55%, #0d3a58 100%);
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 12px 35px rgba(2, 6, 23, .28);
            border-radius: 18px;
            color: #fff;
        }

        .provider-theme .profile-name,
        .provider-theme .profile-role { color: #fff; }

        .provider-theme .profile-role {
            opacity: .85;
            border-top-color: rgba(255,255,255,.15);
            border-bottom-color: rgba(255,255,255,.15);
        }

        .avatar-upload-form {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .avatar-file-input {
            display: none;
        }

        .profile-avatar.clickable-avatar {
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .profile-avatar.clickable-avatar:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, .2);
        }

        .profile-avatar.clickable-avatar .avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-edit-badge {
            position: absolute;
            left: 50%;
            bottom: 8px;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, .72);
            color: #fff;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            opacity: .95;
        }

        .avatar-save-btn {
            border: 0;
            border-radius: 9px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);
            cursor: pointer;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .avatar-save-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(14,165,233,.3);
        }

        .avatar-save-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
            box-shadow: none;
        }

        .avatar-hint {
            display: block;
            text-align: center;
            color: #64748b;
            font-size: 11px;
            line-height: 1.4;
        }

        .avatar-inline-alert {
            width: 100%;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 12px;
            line-height: 1.4;
            text-align: center;
        }

        .avatar-inline-alert.success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }

        .avatar-inline-alert.error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .provider-theme .avatar-hint {
            color: rgba(255,255,255,.85);
        }

        .provider-theme .menu-item {
            border-radius: 10px;
            margin-bottom: 6px;
            color: rgba(255,255,255,.88);
            font-weight: 600;
            transition: all .2s ease;
        }

        .provider-theme .menu-item:hover,
        .provider-theme .menu-item.active {
            background: rgba(255,255,255,.18);
            color: #fff;
            transform: translateX(2px);
        }

        .provider-theme .profile-main .card {
            border-radius: 18px;
            border: 1px solid #dae7f3;
            box-shadow: 0 20px 40px rgba(15, 23, 42, .08);
            background: linear-gradient(180deg, #fff 0%, #fcfeff 100%);
        }

        .provider-theme .card-header {
            border-bottom: 1px solid #e4edf5;
            margin-bottom: 22px;
        }

        .provider-theme .card-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            letter-spacing: -.3px;
            color: #0f2948;
        }

        .provider-theme .dashboard-stats-grid .stat-card {
            border-radius: 16px;
            border: 1px solid #dfeaf5;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .08);
            background: linear-gradient(180deg, #ffffff 0%, #f9fcff 100%);
        }

        .provider-theme .dashboard-stats-grid .stat-card:hover {
            transform: translateY(-5px);
            border-color: #bfdbfe;
            box-shadow: 0 22px 40px rgba(15, 23, 42, .12);
        }

        .provider-theme .stat-info h3 {
            font-family: 'Space Grotesk', sans-serif;
            color: #0f2948;
        }

        .provider-theme .requests-table th {
            background: #f2f8ff;
            color: #3b536f;
            border-bottom-color: #d9e7f3;
            font-size: 11px;
        }

        .provider-theme .requests-table tr:hover {
            background: #f7fbff;
        }

        .provider-theme .service-card {
            border-radius: 14px;
            border: 1px solid #dce8f4;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .06);
        }

        .provider-theme .service-card:hover {
            border-color: #bfdbfe;
            box-shadow: 0 18px 34px rgba(15, 23, 42, .12);
        }

        .provider-theme .form-control {
            border: 1px solid #cfe0ef;
            border-radius: 10px;
            background: #fbfdff;
        }

        .provider-theme .form-control:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96,165,250,.2);
        }

        .provider-theme .btn-primary {
            border-radius: 11px;
            background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);
            box-shadow: 0 10px 18px rgba(14,165,233,.28);
            border: 0;
        }

        .provider-theme .btn-primary:hover {
            background: linear-gradient(135deg, #0284c7 0%, #075985 100%);
            box-shadow: 0 14px 26px rgba(14,165,233,.32);
            transform: translateY(-2px);
        }

        .provider-theme .alert {
            border-radius: 12px;
            border-left-width: 5px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .07);
        }
    </style>
<?php
$extra_head = ob_get_clean();
include 'includes/header.php';
?>

    <div class="profile-container">
        <div class="profile-sidebar">
            <form method="POST" action="" enctype="multipart/form-data" class="avatar-upload-form">
                <input type="file" name="profile_image" id="profile_image" class="avatar-file-input" accept=".jpg,.jpeg,.png,.webp,.gif,image/*" onchange="onAvatarFileSelected(this)">
                <label for="profile_image" class="profile-avatar clickable-avatar" title="Click to change profile photo">
                    <?php if (!empty($avatar_image_url)): ?>
                        <img src="<?php echo htmlspecialchars($avatar_image_url); ?>" alt="Profile Picture" class="avatar-img">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                    <span class="avatar-edit-badge"><i class="fas fa-camera"></i> Change</span>
                </label>
                <button type="submit" name="upload_avatar" id="avatar-save-btn" class="avatar-save-btn" disabled>
                    <i class="fas fa-upload"></i> Save Photo
                </button>
                <small class="avatar-hint" id="avatar-file-name">Click the avatar to choose a photo.</small>
                <?php if (!empty($avatar_error)): ?>
                    <div class="avatar-inline-alert error"><?php echo htmlspecialchars($avatar_error); ?></div>
                <?php endif; ?>
                <?php if (!empty($avatar_success)): ?>
                    <div class="avatar-inline-alert success"><?php echo htmlspecialchars($avatar_success); ?></div>
                <?php endif; ?>
            </form>
            <h3 class="profile-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h3>
            <p class="profile-role">
                <i class="fas fa-<?php echo isProvider() ? 'briefcase' : 'user-circle'; ?>"></i>
                <?php echo isProvider() ? 'Service Provider' : 'Service Seeker'; ?>
            </p>
            
            <div class="profile-menu">
                <?php if (isProvider()): ?>
                <a href="#my-services" class="menu-item active" onclick="showTab(event, 'my-services')">
                    <i class="fas fa-briefcase"></i>
                    <span>My Services</span>
                </a>
                <?php endif; ?>
                <a href="#profile-info" class="menu-item <?php echo !isProvider() ? 'active' : ''; ?>" onclick="showTab(event, 'profile-info')">
                    <i class="fas fa-user"></i>
                    <span>Profile Information</span>
                </a>
                <a href="#password-section" class="menu-item" onclick="showTab(event, 'password-section')">
                    <i class="fas fa-lock"></i>
                    <span>Change Password</span>
                </a>
                <?php if (isProvider()): ?>
                <a href="providers-dashboard.php" class="menu-item" style="margin-top: 20px; background: linear-gradient(135deg, #1a1f3a, #2d3561); color: white; border-radius: 8px;">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="profile-main">
            <?php if (isProvider()): ?>
            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content">
                <div class="dashboard-stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_services; ?></h3>
                            <p>Total Services</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $pending_requests; ?></h3>
                            <p>Pending Requests</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $completed_requests; ?></h3>
                            <p>Completed Jobs</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fas fa-peso-sign"></i>
                        </div>
                        <div class="stat-info">
                            <h3>₱<?php echo number_format($total_revenue, 2); ?></h3>
                            <p>Total Revenue</p>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-list-check"></i> Recent Service Requests</h2>
                    </div>
                    
                    <?php if (count($recent_requests) > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Service</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_requests as $request): ?>
                                <tr>
                                    <td><strong>#<?php echo $request['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['service_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $request['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                        </span>
                                    </td>
                                    <td><strong>₱<?php echo number_format($request['price'] ?? 0, 2); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No service requests yet</h3>
                        <p>Service requests from customers will appear here</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- My Services Tab -->
            <div id="my-services" class="tab-content <?php echo isProvider() ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-plus-circle"></i> Add New Service</h2>
                    </div>
                    
                    <?php if ($service_error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $service_error; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($service_success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $service_success; ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="profile-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-briefcase"></i> Service Name *</label>
                                <input type="text" name="service_name" required class="form-control" placeholder="e.g., Cockroach Extermination">
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-peso-sign"></i> Price (₱) *</label>
                                <input type="number" name="price" required step="0.01" min="0" class="form-control" placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <textarea name="description" rows="4" class="form-control" placeholder="Describe your service..."></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="add_service" class="btn-primary">
                                <i class="fas fa-plus"></i> Add Service
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-list"></i> Your Services</h2>
                    </div>
                    
                    <?php if (count($provider_services) > 0): ?>
                    <div class="services-grid">
                        <?php foreach ($provider_services as $service): ?>
                        <div class="service-card">
                            <div class="service-header">
                                <h3><?php echo htmlspecialchars($service['service_name']); ?></h3>
                                <a href="profile.php?delete_service=<?php echo $service['id']; ?>" class="btn-delete" onclick="return confirm('Delete this service?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                            
                            <p class="service-description"><?php echo htmlspecialchars($service['description']); ?></p>
                            
                            <div class="service-footer">
                                <span class="service-price">
                                    <i class="fas fa-peso-sign"></i>
                                    <?php echo number_format($service['price'], 2); ?>
                                </span>
                                <span class="service-date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($service['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h3>No services yet</h3>
                        <p>Add your first service above to get started</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Profile Information Tab -->
            <div id="profile-info" class="tab-content <?php echo !isProvider() ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-edit"></i> Profile Information</h2>
                    </div>
                    
                    <?php if($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="profile-form">
                        <div class="section-title">
                            <i class="fas fa-id-card"></i>
                            <h3>Personal Information</h3>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> First Name *</label>
                                <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Last Name *</label>
                                <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled class="form-control disabled">
                            <small class="form-hint"><i class="fas fa-info-circle"></i> Email cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone Number *</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required class="form-control">
                        </div>
                        
                        <?php if(isProvider()): ?>
                            <div class="section-title">
                                <i class="fas fa-building"></i>
                                <h3>Company Information</h3>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Company Logo URL</label>
                                <input type="url" name="logo_url" value="<?php echo htmlspecialchars($provider['logo_url']); ?>" class="form-control" placeholder="https://example.com/logo.jpg">
                                <small class="form-hint"><i class="fas fa-info-circle"></i> Enter a URL to your company logo image</small>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Company Name *</label>
                                <input type="text" name="company_name" value="<?php echo htmlspecialchars($provider['company_name']); ?>" required class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-align-left"></i> Description</label>
                                <textarea name="description" rows="4" class="form-control" placeholder="Tell us about your services..."><?php echo htmlspecialchars($provider['description']); ?></textarea>
                            </div>
                            
                            <div class="section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                <h3>Location Details</h3>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> Address *</label>
                                <textarea name="address" required class="form-control" rows="2" placeholder="Street address"><?php echo htmlspecialchars($provider['address']); ?></textarea>
                            </div>
                            
                            <div class="form-row form-row-3">
                                <div class="form-group">
                                    <label><i class="fas fa-city"></i> City *</label>
                                    <input type="text" name="city" value="<?php echo htmlspecialchars($provider['city']); ?>" required class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label><i class="fas fa-flag"></i> State/Province</label>
                                    <input type="text" name="state" value="<?php echo htmlspecialchars($provider['state']); ?>" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label><i class="fas fa-mail-bulk"></i> Zip Code</label>
                                    <input type="text" name="zip_code" value="<?php echo htmlspecialchars($provider['zip_code']); ?>" class="form-control">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-route"></i> Service Radius (km) *</label>
                                <input type="number" name="service_radius" value="<?php echo $provider['service_radius']; ?>" required class="form-control" min="1" max="500">
                                <small class="form-hint"><i class="fas fa-info-circle"></i> Maximum distance you're willing to travel for services</small>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_profile" class="btn-primary">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Password Change Tab -->
            <div id="password-section" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-key"></i> Change Password</h2>
                    </div>
                    
                    <?php if($password_error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $password_error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($password_success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $password_success; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="password-info">
                        <i class="fas fa-shield-alt"></i>
                        <p>Keep your account secure by using a strong password with at least 8 characters.</p>
                    </div>
                    
                    <form method="POST" action="" class="password-form">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Current Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="current_password" required class="form-control" id="current_password">
                                <button type="button" class="toggle-password" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> New Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="new_password" required class="form-control" id="new_password" minlength="8">
                                <button type="button" class="toggle-password" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="form-hint"><i class="fas fa-info-circle"></i> Minimum 8 characters</small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-double"></i> Confirm New Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="confirm_password" required class="form-control" id="confirm_password" minlength="8">
                                <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn-primary">
                                <i class="fas fa-lock"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script>
        function showTab(event, tabId) {
            event.preventDefault();
            
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all menu items
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => item.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function onAvatarFileSelected(input) {
            const saveButton = document.getElementById('avatar-save-btn');
            const fileNameHint = document.getElementById('avatar-file-name');
            const hasFile = input.files && input.files.length > 0;

            if (saveButton) {
                saveButton.disabled = !hasFile;
            }

            if (fileNameHint) {
                fileNameHint.textContent = hasFile
                    ? `Selected: ${input.files[0].name}`
                    : 'Click the avatar to choose a photo.';
            }
        }
        
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
    <?php include 'includes/provider-guide.php'; ?>
</body>
</html>
