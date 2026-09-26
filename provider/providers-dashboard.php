<?php
chdir(dirname(__DIR__));
// provider-dashboard.php - Complete Provider Dashboard
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'provider') {
    header("Location: " . appUrl('login.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();

function getProviderSetting($db, $providerId, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => "provider_{$providerId}_{$key}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setProviderSetting($db, $providerId, $key, $value) {
    $settingKey = "provider_{$providerId}_{$key}";
    try {
        $stmt = $db->prepare(
            "INSERT INTO admin_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([':k' => $settingKey, ':v' => (string)$value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function formatPesoAmount($amount) {
    return '&#8369;' . number_format((float)$amount, 2);
}

// Get provider information
$user_id = $_SESSION['user_id'];
$query = "SELECT p.*, u.email, u.first_name, u.last_name, u.profile_image, u.created_at as user_created
          FROM providers p 
          JOIN users u ON p.user_id = u.id 
          WHERE p.user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    session_destroy();
    header("Location: " . appUrl('login.php'));
    exit();
}

// Check if provider has completed required verification documents
$profile_complete = !empty($provider['business_registration_file'] ?? '') &&
                   !empty($provider['license_file'] ?? '') &&
                   !empty($provider['address'] ?? '') &&
                   !empty($provider['city'] ?? '') &&
                   strcasecmp(trim((string)($provider['state'] ?? '')), 'Cavite') === 0;

if (!$profile_complete) {
    header("Location: provider-setup.php");
    exit();
}

if (($provider['status'] ?? '') !== 'active') {
    header("Location: provider-setup.php");
    exit();
}

$provider_id = $provider['id'];
$view = (($_GET['view'] ?? 'dashboard') === 'settings') ? 'settings' : 'dashboard';
$settingsSaved = isset($_GET['settings_saved']) && $_GET['settings_saved'] === '1';
$settingsError = trim((string)($_GET['settings_error'] ?? ''));
$portalError = trim((string)($_GET['portal_error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form'] ?? '') === 'dashboard_settings')) {
    $preparing_days = max(1, min(30, (int)($_POST['preparing_days'] ?? 1)));
    $max_daily_jobs = max(1, min(100, (int)($_POST['max_daily_jobs'] ?? 10)));
    $working_hours_start = trim((string)($_POST['working_hours_start'] ?? '09:00'));
    $working_hours_end = trim((string)($_POST['working_hours_end'] ?? '17:00'));
    $working_slot_minutes = (int)($_POST['working_slot_minutes'] ?? 60);

    $auto_prepare_on_accept = isset($_POST['auto_prepare_on_accept']) ? '1' : '0';
    $auto_accept_on_request = isset($_POST['auto_accept_on_request']) ? '1' : '0';
    $allow_weekend_preparing = isset($_POST['allow_weekend_preparing']) ? '1' : '0';
    $auto_cancel_unaccepted_24h = isset($_POST['auto_cancel_unaccepted_24h']) ? '1' : '0';
    $notify_new_booking = isset($_POST['notify_new_booking']) ? '1' : '0';
    $notify_status_updates = isset($_POST['notify_status_updates']) ? '1' : '0';

    $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
    if (!preg_match($timePattern, $working_hours_start) || !preg_match($timePattern, $working_hours_end)) {
        header("Location: providers-dashboard.php?view=settings&settings_error=" . urlencode('Working hours must use valid HH:MM values.') . "#dashboard-settings");
        exit();
    }
    $startTs = strtotime('1970-01-01 ' . $working_hours_start . ':00');
    $endTs = strtotime('1970-01-01 ' . $working_hours_end . ':00');
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        header("Location: providers-dashboard.php?view=settings&settings_error=" . urlencode('Working hours end time must be later than start time.') . "#dashboard-settings");
        exit();
    }
    if ($working_slot_minutes < 5 || $working_slot_minutes > 180 || ($working_slot_minutes % 5) !== 0) {
        header("Location: providers-dashboard.php?view=settings&settings_error=" . urlencode('Time slot length must be between 5 and 180 minutes (5-minute steps).') . "#dashboard-settings");
        exit();
    }

    $saved = true;
    $saved = setProviderSetting($db, $provider_id, 'preparing_days', (string)$preparing_days) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'max_daily_jobs', (string)$max_daily_jobs) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'working_hours_start', $working_hours_start) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'working_hours_end', $working_hours_end) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'working_slot_minutes', (string)$working_slot_minutes) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'auto_prepare_on_accept', $auto_prepare_on_accept) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'auto_accept_on_request', $auto_accept_on_request) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'allow_weekend_preparing', $allow_weekend_preparing) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'auto_cancel_unaccepted_24h', $auto_cancel_unaccepted_24h) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'notify_new_booking', $notify_new_booking) && $saved;
    $saved = setProviderSetting($db, $provider_id, 'notify_status_updates', $notify_status_updates) && $saved;

    if ($saved) {
        header("Location: providers-dashboard.php?view=settings&settings_saved=1#dashboard-settings");
    } else {
        header("Location: providers-dashboard.php?view=settings&settings_error=" . urlencode('Unable to save one or more settings.') . "#dashboard-settings");
    }
    exit();
}

$preparing_days = (int)getProviderSetting($db, $provider_id, 'preparing_days', '1');
$max_daily_jobs = (int)getProviderSetting($db, $provider_id, 'max_daily_jobs', '10');
$working_hours_start = trim((string)getProviderSetting($db, $provider_id, 'working_hours_start', '09:00'));
$working_hours_end = trim((string)getProviderSetting($db, $provider_id, 'working_hours_end', '17:00'));
$working_slot_minutes = (int)getProviderSetting($db, $provider_id, 'working_slot_minutes', '60');
$auto_prepare_on_accept = getProviderSetting($db, $provider_id, 'auto_prepare_on_accept', '0') === '1';
$auto_accept_on_request = getProviderSetting($db, $provider_id, 'auto_accept_on_request', '0') === '1';
$allow_weekend_preparing = getProviderSetting($db, $provider_id, 'allow_weekend_preparing', '1') === '1';
$auto_cancel_unaccepted_24h = getProviderSetting($db, $provider_id, 'auto_cancel_unaccepted_24h', '0') === '1';
$notify_new_booking = getProviderSetting($db, $provider_id, 'notify_new_booking', '1') === '1';
$notify_status_updates = getProviderSetting($db, $provider_id, 'notify_status_updates', '1') === '1';

$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
if (!preg_match($timePattern, $working_hours_start)) { $working_hours_start = '09:00'; }
if (!preg_match($timePattern, $working_hours_end)) { $working_hours_end = '17:00'; }
if ($working_slot_minutes < 5 || $working_slot_minutes > 180) { $working_slot_minutes = 60; }
if (strtotime('1970-01-01 ' . $working_hours_end . ':00') <= strtotime('1970-01-01 ' . $working_hours_start . ':00')) {
    $working_hours_start = '09:00';
    $working_hours_end = '17:00';
}
$working_hours_label = date('g:i A', strtotime($working_hours_start . ':00')) . ' - ' . date('g:i A', strtotime($working_hours_end . ':00'));
$auto_cancelled_count = 0;

if ($auto_cancel_unaccepted_24h) {
    try {
        $expiredStmt = $db->prepare(
            "SELECT id, seeker_user_id, service_name
             FROM availed_services
             WHERE provider_id = :pid
               AND status = 'pending'
               AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $expiredStmt->execute([':pid' => $provider_id]);
        $expiredRows = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($expiredRows)) {
            $cancelStmt = $db->prepare(
                "UPDATE availed_services
                 SET status = 'cancelled', is_read = 1
                 WHERE id = :id AND provider_id = :pid AND status = 'pending'"
            );
            $notifyStmt = $db->prepare(
                "INSERT INTO seeker_notifications
                    (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                 VALUES (:suid, :avid, :pid, :sname, 'cancelled', :msg, 0)"
            );

            foreach ($expiredRows as $row) {
                $cancelStmt->execute([
                    ':id'  => (int)$row['id'],
                    ':pid' => $provider_id,
                ]);

                if ($cancelStmt->rowCount() > 0) {
                    $auto_cancelled_count++;
                    if (!empty($row['seeker_user_id'])) {
                        try {
                            $notifyStmt->execute([
                                ':suid'  => (int)$row['seeker_user_id'],
                                ':avid'  => (int)$row['id'],
                                ':pid'   => $provider_id,
                                ':sname' => $row['service_name'] ?? '',
                                ':msg'   => 'Your service request was automatically cancelled because it was not accepted by the provider within 24 hours.',
                            ]);
                        } catch (Exception $e) {}
                    }
                }
            }
        }
    } catch (Exception $e) {}
}

// Get statistics
$query = "SELECT COUNT(*) as total FROM services WHERE provider_id = :provider_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$total_services = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Count pending from service_requests
$query = "SELECT COUNT(*) as total FROM service_requests 
          WHERE provider_id = :provider_id AND status = 'pending'";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$pending_sr = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Count pending from availed_services
$pending_av = 0;
try {
    $avPendStmt = $db->prepare("SELECT COUNT(*) as total FROM availed_services 
                                WHERE provider_id = :provider_id AND status = 'pending'");
    $avPendStmt->execute([':provider_id' => $provider_id]);
    $pending_av = (int)$avPendStmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch(Exception $e) {}

$pending_requests = $pending_sr + $pending_av;

// Count completed from service_requests
$query = "SELECT COUNT(*) as total FROM service_requests 
          WHERE provider_id = :provider_id AND status = 'completed'";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$completed_sr = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Count completed from availed_services
$completed_av = 0;
try {
    $avCompStmt = $db->prepare("SELECT COUNT(*) as total FROM availed_services 
                                WHERE provider_id = :provider_id AND status = 'completed'");
    $avCompStmt->execute([':provider_id' => $provider_id]);
    $completed_av = (int)$avCompStmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch(Exception $e) {}

$completed_requests = $completed_sr + $completed_av;

$query = "SELECT COUNT(*) as total FROM service_requests 
          WHERE provider_id = :provider_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$total_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get recent service requests
$query = "SELECT sr.*, u.first_name, u.last_name, u.email, u.phone as user_phone,
          s.service_name, s.price
          FROM service_requests sr
          JOIN users u ON sr.user_id = u.id
          LEFT JOIN services s ON sr.service_id = s.id
          WHERE sr.provider_id = :provider_id
          ORDER BY sr.created_at DESC
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$serviceRequestRevenue = 0.0;
$availedRevenue = 0.0;

$query = "SELECT COALESCE(SUM(s.price), 0) as total_revenue 
          FROM service_requests sr
          LEFT JOIN services s ON sr.service_id = s.id
          WHERE sr.provider_id = :provider_id AND sr.status = 'completed'";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);
$serviceRequestRevenue = (float)($revenue_data['total_revenue'] ?? 0);

try {
    $availedRevenueStmt = $db->prepare(
        "SELECT COALESCE(SUM(
            CASE
                WHEN total_amount IS NOT NULL AND total_amount > 0 THEN total_amount
                WHEN paid_amount IS NOT NULL AND paid_amount > 0 THEN paid_amount
                ELSE COALESCE(s.price, 0)
            END
        ), 0) AS total_revenue
         FROM availed_services av
         LEFT JOIN services s ON av.service_id = s.id
         WHERE av.provider_id = :provider_id
           AND av.status = 'completed'"
    );
    $availedRevenueStmt->execute([':provider_id' => $provider_id]);
    $availedRevenue = (float)($availedRevenueStmt->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0);
} catch (Exception $e) {
    $availedRevenue = 0.0;
}

$total_revenue = $serviceRequestRevenue + $availedRevenue;

// -- Fetch availed services for this provider (with price) --
$availed_recent = [];
try {
    $avStmt = $db->prepare("
        SELECT av.*,
               s.price as price
        FROM availed_services av
        LEFT JOIN services s ON av.service_id = s.id
        WHERE av.provider_id = :pid
        ORDER BY av.created_at DESC
        LIMIT 10
    ");
    $avStmt->execute([':pid' => $provider_id]);
    $availed_recent = $avStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $availed_recent = [];
}

// Count unread avail notifications for bell badge
$avail_unread = 0;
try {
    $unreadStmt = $db->prepare("SELECT COUNT(*) as c FROM availed_services WHERE provider_id = :pid AND is_read = 0");
    $unreadStmt->execute([':pid' => $provider_id]);
    $avail_unread = (int)$unreadStmt->fetch(PDO::FETCH_ASSOC)['c'];
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Dashboard - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
    <style>
        :root {
            --primary: #3498db;
            --dark-color: #2c3e50;
            --light-bg: #f5f7fa;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--light-bg);
            color: var(--dark-color);
        }
        
        .dashboard-container { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, #1a1f3a 0%, #2d3561 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            background: rgba(0,0,0,0.1);
        }
        
        .sidebar-header h2 { font-size: 24px; color: #fff; display: flex; align-items: center; gap: 10px; }
        .sidebar-header h2 i { color: #fff; }
        .sidebar-header p { font-size: 12px; color: rgba(255,255,255,0.8); margin-top: 5px; }
        
        .sidebar-menu { list-style: none; padding: 15px 0; }
        .sidebar-menu li { margin-bottom: 2px; }
        .sidebar-menu a {
            display: flex; align-items: center; padding: 14px 20px;
            color: rgba(255,255,255,0.9); text-decoration: none; transition: all 0.3s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: rgba(255,255,255,0.2); color: white;
            border-left: 4px solid white; padding-left: 16px;
        }
        .sidebar-menu a i { margin-right: 12px; width: 20px; text-align: center; }

        /* Bell badge in sidebar */
        .sidebar-notif-badge {
            background: #e74c3c; color: white; font-size: 10px; font-weight: 700;
            border-radius: 50%; min-width: 18px; height: 18px;
            display: inline-flex; align-items: center; justify-content: center;
            margin-left: auto; animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.2)} }
        
        .sidebar-footer {
            padding: 20px; border-top: 1px solid rgba(255,255,255,0.15);
            position: absolute; bottom: 0; width: 100%;
        }
        
        .user-profile {
            display: flex; align-items: center; gap: 12px; padding: 15px;
            background: rgba(255,255,255,0.1); border-radius: 10px;
        }
        
        .user-avatar {
            width: 45px; height: 45px; background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: bold; color: var(--primary);
            overflow: hidden;
            flex-shrink: 0;
        }

        .user-avatar.has-photo {
            background: transparent;
            color: transparent;
        }

        .user-avatar-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            transition: transform .25s ease;
        }

        .user-profile:hover .user-avatar-img {
            transform: scale(1.1);
        }
        
        .user-info h4 { font-size: 14px; margin-bottom: 3px; color: white; }
        .user-info p  { font-size: 11px; color: rgba(255,255,255,0.8); }
        
        .main-content { flex: 1; margin-left: 260px; padding: 30px; padding-bottom: 150px; }
        
        .page-header { margin-bottom: 40px; }
        .page-header h1 { font-size: 32px; color: var(--dark-color); margin-bottom: 8px; }
        .page-header p  { font-size: 15px; color: #666; }
        
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; gap: 20px; }
        .welcome-message h1 { font-size: 28px; color: var(--dark-color); margin-bottom: 5px; }
        .welcome-message p  { color: #666; font-size: 14px; }
        .quick-actions { display: flex; gap: 10px; }
        
        .btn {
            padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            font-size: 14px; transition: all 0.3s; font-weight: 600;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #2980b9; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(52,152,219,0.3); }
        .btn-secondary { background: #ecf0f1; color: var(--dark-color); }
        .btn-secondary:hover { background: #bdc3c7; }
        
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px; margin-bottom: 35px;
            overflow: visible;
        }
        
        .stat-card {
            background: white; padding: 25px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex; align-items: center; gap: 20px;
            transition: all 0.3s; border: 1px solid #f0f0f0;
        }
        .stat-card:hover { transform: translate3d(0,-6px,0) scale(1.02); box-shadow: 0 12px 24px rgba(0,0,0,0.14); border-color: var(--primary); }
        
        .stat-icon {
            width: 65px; height: 65px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: white; flex-shrink: 0;
        }
        .stat-icon.blue   { background: linear-gradient(135deg, var(--primary), #2980b9); }
        .stat-icon.green  { background: linear-gradient(135deg, #27ae60, #16a085); }
        .stat-icon.orange { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .stat-icon.purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        
        .stat-info h3 { font-size: 28px; color: var(--dark-color); margin-bottom: 5px; font-weight: bold; }
        .stat-info p  { color: #7f8c8d; font-size: 14px; }
        
        .content-section {
            background: white; padding: 30px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px; border: 1px solid #f0f0f0;
        }
        
        .section-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #ecf0f1;
        }
        .section-header h2 { font-size: 20px; color: var(--dark-color); display: flex; align-items: center; gap: 10px; }
        .section-header h2 i { color: var(--primary); }
        
        .requests-table { width: 100%; border-collapse: collapse; }
        .requests-table th {
            background: #f8f9fa; padding: 15px 12px; text-align: left;
            font-weight: 600; color: var(--dark-color); border-bottom: 2px solid #ecf0f1;
            font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .requests-table td { padding: 16px 12px; border-bottom: 1px solid #ecf0f1; font-size: 14px; color: var(--dark-color); vertical-align: middle; }
        .requests-table tr:hover { background: #f8f9fa; }
        .requests-table tr.availed-row { background: #f0f7ff; }
        .requests-table tr.availed-row:hover { background: #e4f0fb; }
        
        .status-badge {
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600; display: inline-block;
        }
        .status-pending    { background: #fff3cd; color: #856404; }
        .status-accepted   { background: #cfe2ff; color: #084298; }
        .status-in_progress{ background: #d1ecf1; color: #0c5460; }
        .status-completed  { background: #d4edda; color: #155724; }
        .status-rejected   { background: #f8d7da; color: #721c24; }
        .status-cancelled  { background: #e2e3e5; color: #383d41; }

        .source-tag {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 700; padding: 3px 8px;
            border-radius: 999px; margin-left: 6px; vertical-align: middle;
        }
        .source-availed { background: #e8f4fd; color: #2980b9; border: 1px solid #b8d9f0; }
        .source-request { background: #f0f0f0; color: #888; border: 1px solid #ddd; }

        .new-dot {
            width: 8px; height: 8px; background: #e74c3c; border-radius: 50%;
            display: inline-block; margin-left: 6px; animation: pulse 1.5s infinite;
        }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #7f8c8d; }
        .empty-state i { font-size: 64px; margin-bottom: 20px; color: #bdc3c7; }
        .empty-state h3 { font-size: 20px; color: var(--dark-color); margin-bottom: 10px; }
        .empty-state p  { font-size: 14px; }
        
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; border-left: 4px solid; }
        .alert-warning { background: #fff3cd; border-left-color: #ffc107; color: #856404; }
        .alert-info    { background: #d1ecf1; border-left-color: #17a2b8; color: #0c5460; }
        .alert-success { background: #d4edda; border-left-color: #28a745; color: #155724; }
        .alert-error   { background: #f8d7da; border-left-color: #dc3545; color: #842029; }

        .settings-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 18px;
        }
        .setting-item label {
            display: block; font-size: 12px; font-weight: 700; color: #475569; letter-spacing: 0.4px; margin-bottom: 6px; text-transform: uppercase;
        }
        .setting-item input[type="number"],
        .setting-item input[type="time"] {
            width: 100%; padding: 10px 12px; border: 1px solid #dbe1ea; border-radius: 8px; font-size: 14px; color: var(--dark-color);
        }
        .setting-item p {
            margin-top: 6px; font-size: 12px; color: #64748b;
        }
        .settings-toggles {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; margin: 8px 0 20px;
        }
        .settings-check {
            display: flex; align-items: center; gap: 8px; font-size: 14px; color: #334155;
        }
        .settings-check input[type="checkbox"] {
            width: 17px; height: 17px;
        }
        .settings-summary {
            display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;
        }
        .settings-pill {
            padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 700;
            background: #edf6ff; color: #2563eb; border: 1px solid #cde0ff;
        }
        
        .sidebar::-webkit-scrollbar       { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.5); }
        
        @media (max-width: 1024px) {
            .sidebar { width: 220px; }
            .main-content { margin-left: 220px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; height: auto; display: none; }
            .main-content { margin-left: 0; padding: 20px; padding-bottom: 80px; }
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar { flex-direction: column; gap: 15px; align-items: flex-start; }
            .quick-actions { width: 100%; flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .requests-table { font-size: 12px; }
            .requests-table th, .requests-table td { padding: 10px 6px; }
            .page-header h1 { font-size: 24px; }
            .stat-card { flex-direction: column; text-align: center; }
            .stat-icon { margin: 0 auto; }
            .settings-toggles { grid-template-columns: 1fr; }
        }
    </style>
    <style>
        :root{
            --ui-primary:#0ea5e9;
            --ui-primary-dark:#0369a1;
            --ui-secondary:#10b981;
            --ui-ink:#0f172a;
            --ui-muted:#64748b;
            --ui-soft:#e2e8f0;
            --ui-card:#ffffff;
            --ui-glow:0 20px 40px rgba(15, 23, 42, .08);
        }

        body{
            font-family:'Manrope', sans-serif;
            color:var(--ui-ink);
            background:
                radial-gradient(circle at 10% -10%, rgba(14,165,233,.18), transparent 35%),
                radial-gradient(circle at 95% 5%, rgba(16,185,129,.14), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, #f1f6fb 100%);
        }

        .sidebar{
            background: linear-gradient(165deg, #0b1a3a 0%, #132f57 55%, #0d3a58 100%);
            border-right: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 12px 35px rgba(2, 6, 23, .28);
        }

        .sidebar-header{
            background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02));
        }

        .sidebar-header h2{
            font-family:'Space Grotesk', sans-serif;
            font-size: 28px;
            letter-spacing: -.4px;
        }

        .sidebar-menu a{
            border-left: 0;
            border-radius: 12px;
            margin: 4px 12px;
            padding: 12px 14px;
            font-weight: 600;
            position: relative;
            overflow: hidden;
        }

        .sidebar-menu a::before{
            content:'';
            position:absolute;
            left:0;
            top:0;
            bottom:0;
            width:0;
            background: linear-gradient(180deg, var(--ui-primary), var(--ui-secondary));
            transition: width .25s ease;
            border-radius: 10px;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active{
            background: rgba(255,255,255,.16);
            padding-left: 14px;
            backdrop-filter: blur(3px);
        }

        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before{
            width:4px;
        }

        .main-content{
            padding: 34px 34px 120px;
        }

        .page-header h1{
            font-family:'Space Grotesk', sans-serif;
            letter-spacing:-.6px;
            font-size: clamp(2rem, 2.4vw, 2.55rem);
            margin-bottom: 6px;
        }

        .page-header p{
            color: var(--ui-muted);
            font-weight: 500;
        }

        .btn{
            border-radius: 11px;
            font-weight: 700;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .btn-primary{
            background: linear-gradient(135deg, var(--ui-primary) 0%, var(--ui-primary-dark) 100%);
            box-shadow: 0 10px 18px rgba(14,165,233,.28);
        }

        .btn-primary:hover{
            background: linear-gradient(135deg, #0284c7 0%, #075985 100%);
            box-shadow: 0 14px 26px rgba(14,165,233,.32);
            transform: translateY(-2px);
        }

        .btn-secondary{
            background: #fff;
            border: 1px solid #d6e3ef;
            color: #1e3a5f;
        }

        .btn-secondary:hover{
            background: #f3f9ff;
            border-color: #bbd9ef;
        }

        .stat-card{
            border-radius: 18px;
            border: 1px solid #dfeaf5;
            background: linear-gradient(180deg, #ffffff 0%, #f9fcff 100%);
            box-shadow: var(--ui-glow);
            position: relative;
            overflow: hidden;
            transform-origin: center;
            will-change: transform;
            transform: translateZ(0);
            backface-visibility: hidden;
            transition: transform .38s cubic-bezier(0.22, 1, 0.36, 1), box-shadow .38s ease, border-color .38s ease;
            cursor: pointer;
        }

        .stat-icon{
            transition: none;
            transform: none;
            box-shadow: none;
            filter: none;
        }

        .stat-card::after{
            content:'';
            position:absolute;
            left:0;
            right:0;
            top:0;
            height:4px;
            background: linear-gradient(90deg, rgba(14,165,233,.95), rgba(16,185,129,.95));
            opacity:.75;
        }

        .stat-card:hover,
        .stat-card.is-hovered{
            transform: translate3d(0,-7px,0) scale(1.02) !important;
            box-shadow: 0 18px 34px rgba(15, 23, 42, .14) !important;
            border-color: #b9d8ee !important;
        }

        .stat-info h3{
            font-family:'Space Grotesk', sans-serif;
            font-size: 34px;
            letter-spacing: -.5px;
        }

        .content-section{
            border-radius: 18px;
            border: 1px solid #dae7f3;
            box-shadow: var(--ui-glow);
            background: linear-gradient(180deg, #fff 0%, #fcfeff 100%);
        }

        .section-header{
            border-bottom: 1px solid #e4edf5;
        }

        .section-header h2{
            font-family:'Space Grotesk', sans-serif;
            font-size: 24px;
            letter-spacing: -.3px;
        }

        .requests-table th{
            background: #f2f8ff;
            color: #3b536f;
            border-bottom: 1px solid #d9e7f3;
            font-size: 11px;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .requests-table td{
            border-bottom-color: #e7edf4;
        }

        .requests-table tr:hover{
            background: #f7fbff;
        }

        .source-availed{
            background: #e9f6ff;
            border-color: #bae6fd;
            color: #0369a1;
        }

        .source-request{
            background: #edfdf6;
            border-color: #bbf7d0;
            color: #047857;
        }

        .alert{
            border-left-width: 6px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .07);
        }

        #dashboard-settings .section-header{
            align-items: center;
        }

        .settings-summary{
            gap: 8px;
        }

        .settings-pill{
            border-radius: 999px;
            background: linear-gradient(180deg, #f1f8ff, #e6f2ff);
            border-color: #c8defa;
            color: #0f4f91;
        }

        .setting-item input[type="number"]{
            border: 1px solid #cfe0ef;
            border-radius: 10px;
            padding: 11px 12px;
            font-weight: 700;
            background: #fbfdff;
        }

        .setting-item input[type="number"]:focus{
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96,165,250,.2);
        }

        .settings-check{
            background: #f8fbff;
            border: 1px solid #dbe8f5;
            border-radius: 10px;
            padding: 10px 12px;
            font-weight: 600;
        }

        .settings-check input[type="checkbox"]{
            accent-color: #0ea5e9;
        }

        .user-profile{
            border: 1px solid rgba(255,255,255,.16);
            background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.07));
        }

        .stat-card,
        .content-section,
        .alert{
            animation: riseIn .45s ease both;
        }

        .stats-grid .stat-card:nth-child(1){ animation-delay: .02s; }
        .stats-grid .stat-card:nth-child(2){ animation-delay: .08s; }
        .stats-grid .stat-card:nth-child(3){ animation-delay: .14s; }
        .stats-grid .stat-card:nth-child(4){ animation-delay: .20s; }

        @keyframes riseIn{
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1024px){
            .page-header h1{ font-size: 30px; }
            .content-section{ padding: 24px; }
        }

        @media (max-width: 768px){
            .main-content{ padding: 20px 16px 78px; }
            .page-header h1{ font-size: 26px; }
            .section-header h2{ font-size: 20px; }
            .stat-info h3{ font-size: 30px; }
            .settings-check{ padding: 9px 10px; }
        }
    </style>
</head>
<body>
    <?php include appPath('includes/login_success_alert.php'); ?>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-bug"></i> Pestify</h2>
                <p>Provider Portal</p>
            </div>
            
            <ul class="sidebar-menu">
                <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>" class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="<?php echo appUrl('services.php'); ?>"><i class="fas fa-briefcase"></i> My Services</a></li>
                <li>
                    <a href="<?php echo appUrl('service-requests.php'); ?>">
                        <i class="fas fa-list-check"></i> Requests
                        <?php if($avail_unread > 0): ?>
                            <span class="sidebar-notif-badge"><?php echo $avail_unread; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="<?php echo appUrl('messages.php'); ?>"><i class="fas fa-comments"></i> Messages</a></li>
                <li><a href="<?php echo appUrl('profile.php'); ?>"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>?view=settings#dashboard-settings" class="<?php echo $view === 'settings' ? 'active' : ''; ?>"><i class="fas fa-sliders-h"></i> Settings</a></li>
                <li><a href="<?php echo appUrl('logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
            
            <div class="sidebar-footer">
                <div class="user-profile">
                    <?php
                    // profile_image is stored as a bare path relative to the app root
                    // (e.g. "uploads/profile/xxx.jpg") — rendering it as-is only worked
                    // by accident on pages living exactly at the app root; from a
                    // subfolder like provider/ the browser resolved it one directory
                    // too deep, showing a broken image. logo_url, by contrast, is a
                    // full external URL a provider pastes in themselves, so it's left
                    // untouched.
                    $provider_avatar = trim((string)($provider['profile_image'] ?? ''));
                    if ($provider_avatar !== '') { $provider_avatar = siteUrl($provider_avatar); }
                    if ($provider_avatar === '') { $provider_avatar = trim((string)($provider['logo_url'] ?? '')); }
                    ?>
                    <div class="user-avatar <?php echo $provider_avatar !== '' ? 'has-photo' : ''; ?>">
                        <?php if ($provider_avatar !== ''): ?>
                            <img src="<?php echo htmlspecialchars($provider_avatar); ?>" alt="Profile Photo" class="user-avatar-img">
                        <?php else: ?>
                            <?php echo strtoupper(substr($provider['company_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($provider['company_name']); ?></h4>
                        <p><?php echo htmlspecialchars($provider['email']); ?></p>
                    </div>
                </div>
            </div>
        </aside>
        
        <main class="main-content">
            <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;">
                <div>
                    <?php if ($view === 'settings'): ?>
                        <h1>Provider Settings</h1>
                        <p>Manage preparing and booking behavior controls.</p>
                    <?php else: ?>
                        <h1>Welcome back, <?php echo htmlspecialchars($provider['first_name']); ?>! </h1>
                        <p><?php echo date('l, F j, Y'); ?></p>
                    <?php endif; ?>
                </div>
                <div class="quick-actions">
                    <?php if ($view === 'settings'): ?>
                        <a href="<?php echo appUrl('providers-dashboard.php'); ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
                    <?php else: ?>
                        <a href="<?php echo appUrl('providers-dashboard.php'); ?>?view=settings#dashboard-settings" class="btn btn-secondary"><i class="fas fa-sliders-h"></i> Settings</a>
                    <?php endif; ?>
                    <a href="../provider-portal/direct-entry.php"
                       style="display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,#1a2744,#2d3561);color:#fff;text-decoration:none;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:700;box-shadow:0 4px 14px rgba(26,39,68,.35);transition:all .2s;"
                       onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(26,39,68,.45)'"
                       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(26,39,68,.35)'">
                        <span style="background:linear-gradient(135deg,#2E8B57,#27ae60);width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;">
                            <i class="fas fa-building"></i>
                        </span>
                        <span>
                            <span style="display:block;font-size:10px;opacity:.7;font-weight:500;margin-bottom:1px">HR & Finance</span>
                            Management System
                        </span>
                        <i class="fas fa-arrow-right" style="font-size:12px;opacity:.7;margin-left:4px"></i>
                    </a>
                </div>
            </div>
            
            <?php if ($provider['status'] == 'pending'): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Automated Verification In Progress</strong><br>
                    Your documents are being auto-checked by the system. Please refresh after a moment.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($portalError !== ''): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Provider Portal Unavailable</strong><br>
                    <?php echo htmlspecialchars($portalError); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($auto_cancelled_count > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>Auto-cancel applied:</strong>
                    <?php echo (int)$auto_cancelled_count; ?> pending request<?php echo $auto_cancelled_count > 1 ? 's were' : ' was'; ?> cancelled because they were not accepted within 24 hours.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($view === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-tasks"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $total_services; ?></h3>
                        <p>Total Services</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-clock"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $pending_requests; ?></h3>
                        <p>Pending Requests</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $completed_requests; ?></h3>
                        <p>Completed Jobs</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-dollar-sign"></i></div>
                    <div class="stat-info">
                        <h3><?php echo formatPesoAmount($total_revenue); ?></h3>
                        <p>Total Revenue</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($view === 'settings'): ?>
            <?php if ($settingsSaved): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><strong>Settings saved.</strong> Your dashboard preferences were updated.</div>
            </div>
            <?php endif; ?>
            <?php if ($settingsError !== ''): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><strong>Save failed.</strong> <?php echo htmlspecialchars($settingsError); ?></div>
            </div>
            <?php endif; ?>

            <div class="content-section" id="dashboard-settings">
                <div class="section-header">
                    <h2><i class="fas fa-sliders-h"></i> Provider Settings</h2>
                    <span class="btn btn-secondary" style="cursor:default">Basic Controls</span>
                </div>

                <div class="settings-summary">
                    <span class="settings-pill">Preparing Days: <?php echo (int)$preparing_days; ?></span>
                    <span class="settings-pill">Max Daily Jobs: <?php echo (int)$max_daily_jobs; ?></span>
                    <span class="settings-pill">Working Hours: <?php echo htmlspecialchars($working_hours_label); ?></span>
                    <span class="settings-pill">Slot Length: <?php echo (int)$working_slot_minutes; ?> min</span>
                    <span class="settings-pill">Auto-accept: <?php echo $auto_accept_on_request ? 'On' : 'Off'; ?></span>
                    <span class="settings-pill">Auto-cancel (24h): <?php echo $auto_cancel_unaccepted_24h ? 'On' : 'Off'; ?></span>
                </div>

                <form method="POST">
                    <input type="hidden" name="form" value="dashboard_settings">

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label for="preparing_days">Preparing Days</label>
                            <input id="preparing_days" type="number" name="preparing_days" min="1" max="30" value="<?php echo (int)$preparing_days; ?>">
                            <p>How many days your team normally needs for the preparing phase.</p>
                        </div>
                        <div class="setting-item">
                            <label for="max_daily_jobs">Max Daily Jobs</label>
                            <input id="max_daily_jobs" type="number" name="max_daily_jobs" min="1" max="100" value="<?php echo (int)$max_daily_jobs; ?>">
                            <p>Soft limit for how many bookings you prefer to handle per day.</p>
                        </div>
                        <div class="setting-item">
                            <label for="working_hours_start">Working Hours Start</label>
                            <input id="working_hours_start" type="time" name="working_hours_start" value="<?php echo htmlspecialchars($working_hours_start); ?>" required>
                            <p>Start time for seeker booking time slots.</p>
                        </div>
                        <div class="setting-item">
                            <label for="working_hours_end">Working Hours End</label>
                            <input id="working_hours_end" type="time" name="working_hours_end" value="<?php echo htmlspecialchars($working_hours_end); ?>" required>
                            <p>End time for seeker booking time slots.</p>
                        </div>
                        <div class="setting-item">
                            <label for="working_slot_minutes">Time Slot Length (minutes)</label>
                            <input id="working_slot_minutes" type="number" name="working_slot_minutes" min="5" max="180" step="5" value="<?php echo (int)$working_slot_minutes; ?>" required>
                            <p>This controls the interval shown to seekers in Request Service time slots.</p>
                        </div>
                    </div>

                    <div class="settings-toggles">
                        <label class="settings-check"><input type="checkbox" name="auto_accept_on_request" <?php echo $auto_accept_on_request ? 'checked' : ''; ?>> Automatically accept new service requests</label>
                        <label class="settings-check"><input type="checkbox" name="auto_prepare_on_accept" <?php echo $auto_prepare_on_accept ? 'checked' : ''; ?>> Auto-prepare immediately after acceptance</label>
                        <label class="settings-check"><input type="checkbox" name="allow_weekend_preparing" <?php echo $allow_weekend_preparing ? 'checked' : ''; ?>> Allow preparing activities on weekends</label>
                        <label class="settings-check"><input type="checkbox" name="auto_cancel_unaccepted_24h" <?php echo $auto_cancel_unaccepted_24h ? 'checked' : ''; ?>> Auto-cancel pending requests after 24 hours (if not accepted)</label>
                        <label class="settings-check"><input type="checkbox" name="notify_new_booking" <?php echo $notify_new_booking ? 'checked' : ''; ?>> Notify me when a new booking arrives</label>
                        <label class="settings-check"><input type="checkbox" name="notify_status_updates" <?php echo $notify_status_updates ? 'checked' : ''; ?>> Notify me when booking status changes</label>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($view === 'dashboard'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2>
                        <i class="fas fa-clipboard-list"></i> Recent Service Requests
                        <?php if($avail_unread > 0): ?>
                            <span style="background:#e74c3c;color:white;font-size:12px;padding:3px 10px;border-radius:999px;margin-left:6px;">
                                <?php echo $avail_unread; ?> new
                            </span>
                        <?php endif; ?>
                    </h2>
                    <a href="<?php echo appUrl('service-requests.php'); ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View All
                    </a>
                </div>
                
                <?php
chdir(dirname(__DIR__));
                // Merge availed + regular requests, sorted by created_at DESC
                $merged = [];

                foreach ($recent_requests as $r) {
                    $merged[] = [
                        'type'         => 'request',
                        'id'           => $r['id'],
                        'name'         => $r['first_name'] . ' ' . $r['last_name'],
                        'service_name' => $r['service_name'] ?? 'â€”',
                        'created_at'   => $r['created_at'],
                        'status'       => $r['status'],
                        'price'        => formatPesoAmount($r['price'] ?? 0),
                        'is_read'      => 1,
                    ];
                }

                foreach ($availed_recent as $av) {
                    $merged[] = [
                        'type'         => 'availed',
                        'id'           => $av['id'],
                        'name'         => $av['full_name'],
                        'service_name' => $av['service_name'] ?? 'â€”',
                        'created_at'   => $av['created_at'],
                        'status'       => $av['status'],
                        'price'        => isset($av['price']) && $av['price'] !== null ? formatPesoAmount($av['price']) : '&mdash;',
                        'is_read'      => $av['is_read'],
                    ];
                }

                usort($merged, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
                $merged = array_slice($merged, 0, 10);
                ?>

                <?php if (count($merged) > 0): ?>
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($merged as $row): ?>
                            <tr class="<?php echo $row['type'] === 'availed' ? 'availed-row' : ''; ?>">
                                <td>
                                    <strong>#<?php echo $row['id']; ?></strong>
                                    <span class="source-tag <?php echo $row['type'] === 'availed' ? 'source-availed' : 'source-request'; ?>">
                                        <?php echo $row['type'] === 'availed' ? '<i class="fas fa-hand-holding-usd"></i> Availed' : '<i class="fas fa-list"></i> Request'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['name']); ?>
                                    <?php if($row['type'] === 'availed' && !$row['is_read']): ?>
                                        <span class="new-dot" title="New avail request"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($row['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo $row['price']; ?></strong></td>
                                <td>
                                    <a href="<?php echo appUrl('service-requests.php'); ?>" class="btn btn-primary" style="padding:6px 16px;font-size:12px;">
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
                    <i class="fas fa-inbox"></i>
                    <h3>No service requests yet</h3>
                    <p>Service requests from customers will appear here</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var cards = document.querySelectorAll('.stats-grid .stat-card');
            cards.forEach(function (card) {
                card.addEventListener('mouseenter', function () {
                    card.classList.add('is-hovered');
                });
                card.addEventListener('mouseleave', function () {
                    card.classList.remove('is-hovered');
                });
            });
        });
    </script>
    <?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>


