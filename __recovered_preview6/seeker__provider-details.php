<?php
chdir(dirname(__DIR__));
// provider-details.php - View individual provider details
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/payment_receipt_helper.php');

$database = new Database();
$db = $database->getConnection();

$provider_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($provider_id === 0) {
    header('Location: providers.php');
    exit();
}

// Get provider details
$query = "SELECT p.*, u.email, u.phone, u.first_name, u.last_name
          FROM providers p 
          JOIN users u ON p.user_id = u.id
          WHERE p.id = :provider_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    header('Location: providers.php');
    exit();
}

function providerSetting(PDO $db, int $providerId, string $key, string $default): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => "provider_{$providerId}_{$key}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['setting_value'])) {
            return trim((string)$row['setting_value']);
        }
    } catch (Throwable $e) {}
    return $default;
}

$provider_preparing_days = (int)providerSetting($db, $provider_id, 'preparing_days', '3');
$provider_preparing_days = max(0, min(30, $provider_preparing_days));
$provider_working_hours_start = providerSetting($db, $provider_id, 'working_hours_start', '09:00');
$provider_working_hours_end = providerSetting($db, $provider_id, 'working_hours_end', '17:00');
$provider_working_slot_minutes = (int)providerSetting($db, $provider_id, 'working_slot_minutes', '60');
$provider_working_slot_minutes = ($provider_working_slot_minutes >= 5 && $provider_working_slot_minutes <= 180) ? $provider_working_slot_minutes : 60;

$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
if (!preg_match($timePattern, $provider_working_hours_start)) $provider_working_hours_start = '09:00';
if (!preg_match($timePattern, $provider_working_hours_end)) $provider_working_hours_end = '17:00';
if (strtotime('1970-01-01 ' . $provider_working_hours_end . ':00') <= strtotime('1970-01-01 ' . $provider_working_hours_start . ':00')) {
    $provider_working_hours_start = '09:00';
    $provider_working_hours_end = '17:00';
}
$provider_working_hours_label = date('g:i A', strtotime($provider_working_hours_start . ':00'))
    . ' - ' . date('g:i A', strtotime($provider_working_hours_end . ':00'))
    . ' (' . $provider_working_slot_minutes . '-min slots)';

// Get provider statistics
$query = "SELECT 
          COUNT(*) as total_services,
          (SELECT COUNT(*) FROM service_requests WHERE provider_id = :provider_id AND status = 'completed') as completed_jobs,
          (SELECT AVG(rating) FROM service_reviews WHERE provider_id = :provider_id) as avg_rating,
          (SELECT COUNT(*) FROM service_reviews WHERE provider_id = :provider_id) as total_reviews
          FROM services 
          WHERE provider_id = :provider_id AND status = 'active'";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get provider services
$query = "SELECT *, IFNULL(payment_settings, '{}') as payment_settings FROM services 
          WHERE provider_id = :provider_id AND status = 'active'
          ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

$service_contract_map = [];
foreach ($services as $service_row) {
    $sid = (string)(int)($service_row['id'] ?? 0);
    if ($sid === '0') {
        continue;
    }
    $service_contract_map[$sid] = [
        'name'      => (string)($service_row['service_name'] ?? ''),
        'text'      => (string)($service_row['contract_text'] ?? ''),
        'signature' => (string)($service_row['contract_signature'] ?? ''),
        'signed_at' => (string)($service_row['contract_signed_at'] ?? ''),
    ];
}

// Get provider reviews (from service_reviews table)
$query = "SELECT sr.*, u.first_name, u.last_name
          FROM service_reviews sr
          JOIN users u ON sr.seeker_user_id = u.id
          WHERE sr.provider_id = :provider_id
          ORDER BY sr.created_at DESC
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->bindParam(':provider_id', $provider_id);
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get booked dates
$booked_dates = [];
try {
    $bStmt = $db->prepare(
        "SELECT preferred_date FROM availed_services
         WHERE provider_id = :pid
           AND status NOT IN ('cancelled', 'rejected')
           AND preferred_date >= CURDATE()
         GROUP BY preferred_date"
    );
    $bStmt->execute([':pid' => $provider_id]);
    while ($row = $bStmt->fetch(PDO::FETCH_ASSOC)) {
        $booked_dates[] = $row['preferred_date'];
    }
} catch(Exception $e) {
    $booked_dates = [];
}

// Get seeker info
$seeker_phone = $seeker_name = $seeker_email = '';
if (isset($_SESSION['user_id'])) {
    try {
        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :uid");
        $uStmt->execute([':uid' => $_SESSION['user_id']]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $fname = $uRow['first_name'] ?? '';
            $lname = $uRow['last_name']  ?? '';
            $seeker_name  = trim($fname . ' ' . $lname);
            $seeker_phone = $uRow['phone'] ?? $uRow['contact_number'] ?? '';
            $seeker_email = $uRow['email'] ?? '';
        }
    } catch(Exception $e) {}
}
if (!$seeker_name)  $seeker_name  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (!$seeker_phone) $seeker_phone = $_SESSION['phone'] ?? '';
if (!$seeker_email) $seeker_email = $_SESSION['email'] ?? '';

$success_msg  = isset($_GET['requested']) && $_GET['requested'] == '1';
$rejected_msg = isset($_GET['rejected'])  && $_GET['rejected']  == '1';

// -- Check if seeker has an accepted booking awaiting payment for this provider --
$pending_payment_booking = null;
$pending_payment_link    = null;
if (isset($_SESSION['user_id'])) {
    try {
        $ppStmt = $db->prepare(
            "SELECT as2.*, pt.transaction_id AS paymongo_link_id
             FROM availed_services as2
             LEFT JOIN payment_transactions pt
                    ON pt.availed_service_id = as2.id
                   AND pt.status = 'pending'
                   AND pt.seeker_id = :uid
             WHERE as2.provider_id   = :pid
               AND as2.seeker_user_id = :uid
               AND as2.status        = 'waiting_provider_confirmation'
               AND as2.payment_status IN ('unpaid')
             ORDER BY as2.updated_at DESC
             LIMIT 1"
        );
        $ppStmt->execute([':uid' => $_SESSION['user_id'], ':pid' => $provider_id]);
        $pending_payment_booking = $ppStmt->fetch(PDO::FETCH_ASSOC);

        // Resolve checkout URL via PayMongo API — handles both cs_ (checkout session) and link_ (payment link)
        if ($pending_payment_booking && !empty($pending_payment_booking['paymongo_link_id'])) {
            $txId   = $pending_payment_booking['paymongo_link_id'];
            $apiUrl = str_starts_with($txId, 'cs_')
                ? 'https://api.paymongo.com/v1/checkout_sessions/' . $txId
                : 'https://api.paymongo.com/v1/links/'              . $txId;
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                ],
            ]);
            $pmResp = curl_exec($ch);
            curl_close($ch);
            $pmData = json_decode($pmResp, true);
            $pending_payment_link = $pmData['data']['attributes']['checkout_url']
                                 ?? $pmData['data']['attributes']['redirect']['checkout_url']
                                 ?? null;

            // Self-heal: PayMongo already shows paid but our DB wasn't updated (missed redirect)
            $pmStatus = $pmData['data']['attributes']['payment_intent']['attributes']['status']
                     ?? $pmData['data']['attributes']['status']
                     ?? '';
            if (in_array($pmStatus, ['succeeded', 'paid', 'active'])) {
                try {
                    $seekerId2 = (int)$_SESSION['user_id'];
                    $bid2      = (int)$pending_payment_booking['id'];
                    $isDP2     = ($pending_payment_booking['payment_method'] === 'downpayment');
                    $newPS2    = $isDP2 ? 'partial' : 'paid';
                    $paidAmt2  = $isDP2
                        ? (float)$pending_payment_booking['downpayment_amount']
                        : (float)$pending_payment_booking['total_amount'];
                    $db->prepare(
                        "UPDATE availed_services
                         SET payment_status=:ps, paid_amount=:pa,
                             status='preparing', updated_at=NOW()
                         WHERE id=:id AND seeker_user_id=:uid AND payment_status='unpaid'"
                    )->execute([':ps'=>$newPS2,':pa'=>$paidAmt2,':id'=>$bid2,':uid'=>$seekerId2]);
                    $db->prepare(
                        "UPDATE payment_transactions SET status='completed', updated_at=NOW()
                         WHERE availed_service_id=:id AND seeker_id=:uid AND status='pending'"
                    )->execute([':id'=>$bid2,':uid'=>$seekerId2]);
                    syncCompletedReceiptsForBooking($db, $bid2);
                    // Booking is now confirmed — hide the payment prompt
                    $pending_payment_booking = null;
                    $pending_payment_link    = null;
                } catch (Exception $e) { /* non-fatal */ }
            }
        }
    } catch (Exception $e) {
        $pending_payment_booking = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($provider['company_name']); ?> - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* -- Keep ALL existing styles exactly as before -- */
        .provider-details-page { min-height: 100vh; background: #f5f7fa; }
        .provider-hero { background: linear-gradient(135deg, var(--primary), #2980b9); color: white; padding: 50px 20px; text-align: center; }
        .provider-hero-content { max-width: 800px; margin: 0 auto; }
        .provider-avatar { width: 120px; height: 120px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 48px; font-weight: bold; color: var(--primary); }
        .provider-hero h1 { font-size: 36px; margin-bottom: 10px; }
        .provider-hero p  { font-size: 16px; opacity: 0.9; }
        .provider-rating-section { display: flex; justify-content: center; gap: 20px; margin-top: 20px; flex-wrap: wrap; }
        .rating-item { text-align: center; }
        .rating-item .rating-stars { font-size: 20px; margin-bottom: 5px; }
        .rating-item .rating-text  { font-size: 14px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 40px; }
        .section-card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .section-title { font-size: 22px; font-weight: 600; color: var(--dark-color); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .section-title i { color: var(--primary); }
        .about-text { color: #666; line-height: 1.8; font-size: 15px; }
        .info-item { display: flex; align-items: center; gap: 15px; padding: 15px 0; border-bottom: 1px solid #err; }
        .info-item:last-child { border-bottom: none; }
        .info-item i { color: var(--primary); font-size: 18px; width: 25px; }
        .info-label { font-weight: 500; color: #333; }
        .info-value { color: #666; }
        .action-buttons { display: flex; flex-direction: column; gap: 12px; margin-top: 25px; }
        .btn { padding: 12px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
        .btn-primary  { background: var(--primary); color: white; }
        .btn-primary:hover  { background: #2980b9; transform: translateY(-2px); }
        .btn-secondary { background: #ecf0f1; color: #333; }
        .btn-secondary:hover { background: #bdc3c7; }
        .services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .service-card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; flex-direction: column; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .service-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); transform: translateY(-3px); }
        .service-name  { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 10px; }
        .service-price { font-size: 20px; font-weight: bold; color: var(--primary); margin-bottom: 10px; }
        .service-desc  { font-size: 13px; color: #666; line-height: 1.5; flex-grow: 1; margin-bottom: 10px; }
        .service-action { background: var(--primary); color: white; padding: 10px; border-radius: 6px; text-align: center; font-size: 13px; font-weight: 600; transition: all 0.3s; border: none; cursor: pointer; width: 100%; }
        .service-action:hover { background: #2980b9; }

        /* Reviews */
        .review-item { padding: 20px; border: 1px solid #err; border-radius: 10px; margin-bottom: 15px; }
        .review-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; }
        .reviewer-name { font-weight: 600; color: #333; }
        .review-rating { color: #f39c12; }
        .review-date { font-size: 12px; color: #999; margin-top: 5px; }
        .review-text { color: #666; font-size: 14px; line-height: 1.6; }
        .review-service-tag { font-size: 11px; background: #e8f4fd; color: var(--primary); padding: 2px 8px; border-radius: 20px; margin-top: 6px; display: inline-block; }

        .empty-state { text-align: center; padding: 40px 20px; color: #999; }
        .back-link { display: flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; margin-bottom: 30px; font-weight: 500; transition: all 0.3s; }
        .back-link:hover { gap: 12px; }

        .success-banner { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; }
        .info-banner { background: #cce5ff; border: 1px solid #b8daff; color: #004085; padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; }

        /* -- Payment prompt banner -- */
        .payment-prompt-banner {
            background: linear-gradient(135deg, #fff8e1, #fffde7);
            border: 2px solid #f6c90e;
            border-radius: 14px;
            padding: 22px 24px;
            margin-bottom: 28px;
            display: flex;
            align-items: flex-start;
            gap: 18px;
            box-shadow: 0 4px 16px rgba(246,201,14,.18);
        }
        .payment-prompt-icon {
            width: 52px; height: 52px; min-width: 52px;
            background: #f6c90e; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: #7d5a00;
        }
        .payment-prompt-body { flex: 1; }
        .payment-prompt-body h3 { font-size: 16px; font-weight: 700; color: #7d5a00; margin-bottom: 6px; }
        .payment-prompt-body p  { font-size: 13px; color: #8a6a00; line-height: 1.6; margin-bottom: 14px; }
        .payment-prompt-meta { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .payment-meta-chip {
            background: rgba(0,0,0,.06); border-radius: 8px;
            padding: 5px 12px; font-size: 12px; font-weight: 600; color: #6b4f00;
            display: flex; align-items: center; gap: 6px;
        }
        .btn-pay-now {
            display: inline-flex; align-items: center; gap: 9px;
            background: #27ae60; color: #fff;
            padding: 12px 26px; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            text-decoration: none; border: none; cursor: pointer;
            transition: all .2s; box-shadow: 0 4px 12px rgba(39,174,96,.3);
        }
        .btn-pay-now:hover { background: #219150; transform: translateY(-2px); }
        .btn-pay-no-link {
            display: inline-flex; align-items: center; gap: 9px;
            background: #95a5a6; color: #fff;
            padding: 12px 26px; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            cursor: not-allowed; opacity: .8;
        }
        @media (max-width: 600px) {
            .payment-prompt-banner { flex-direction: column; }
            .payment-prompt-icon  { align-self: flex-start; }
        }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; border-radius: 16px; padding: 36px 32px; max-width: 520px; width: 92%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: modalPop 0.25s ease; max-height: 90vh; overflow-y: auto; }
        @keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-step { display: none; }
        .modal-step.active { display: block; }
        .modal-icon { width: 64px; height: 64px; background: #e8f4fd; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 28px; color: var(--primary); }
        .modal-title { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; text-align: center; }
        .modal-service-name { font-size: 15px; color: var(--primary); font-weight: 600; margin-bottom: 8px; text-align: center; }
        .modal-message { font-size: 14px; color: #666; margin-bottom: 24px; line-height: 1.6; text-align: center; }
        .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-btn { padding: 11px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-btn-confirm { background: var(--primary); color: white; }
        .modal-btn-confirm:hover { background: #2980b9; transform: translateY(-1px); }
        .modal-btn-cancel  { background: #f0f0f0; color: #555; }
        .modal-btn-cancel:hover  { background: #e0e0e0; }

        /* Form */
        .avail-form-title { font-size: 18px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .avail-form-subtitle { font-size: 13px; color: #888; margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; }
        .form-group label span.req { color: #e74c3c; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; color: #333; box-sizing: border-box; transition: border 0.2s; font-family: inherit; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(41,128,185,0.1); }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-actions { display: flex; gap: 12px; margin-top: 20px; }
        .btn-submit { flex: 1; padding: 12px; background: var(--primary); color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-submit:hover { background: #2980b9; }
        .btn-back-form { padding: 12px 20px; background: #f0f0f0; color: #555; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-back-form:hover { background: #e0e0e0; }

        /* Calendar - keep all existing styles */
        .custom-date-wrapper { position: relative; }
        .date-display { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; color: #555; cursor: pointer; background: white; transition: border 0.2s; user-select: none; }
        .date-display:hover { border-color: var(--primary); }
        .calendar-popup { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-radius: 12px; padding: 16px; z-index: 100; box-shadow: 0 8px 24px rgba(0,0,0,0.12); margin-top: 4px; }
        .calendar-popup.open { display: block; }
        .cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .cal-header button { background: none; border: 1px solid #err; border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 14px; color: #555; transition: all 0.2s; }
        .cal-header button:hover { background: #f5f7fa; border-color: var(--primary); }
        .cal-header span { font-weight: 600; color: #333; font-size: 14px; }
        .cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 6px; }
        .cal-weekdays span { text-align: center; font-size: 11px; font-weight: 700; color: #aaa; padding: 4px 0; }
        .cal-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .cal-day { text-align: center; padding: 7px 2px; border-radius: 8px; font-size: 13px; cursor: pointer; transition: all 0.15s; border: 1px solid transparent; }
        .cal-day:hover:not(.disabled):not(.booked) { background: #e8f4fd; border-color: var(--primary); }
        .cal-day.selected { background: var(--primary); color: white; font-weight: 700; border-color: var(--primary); }
        .cal-day.today { font-weight: 700; color: var(--primary); }
        .cal-day.disabled { color: #ddd; cursor: not-allowed; }
        .cal-day.booked { background: #fde8e8; color: #e74c3c; border-color: #e74c3c; cursor: not-allowed; font-weight: 600; }
        .cal-day.other-month { color: #ccc; }
        .cal-legend { display: flex; gap: 12px; margin-top: 10px; justify-content: center; flex-wrap: wrap; }
        .cal-legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: #888; }
        .cal-legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }

        /* Map pickee */
        #mapPickeeModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center; }
        #mapPickeeModal.active { display: flex; }
        .map-pickee-box { background: white; border-radius: 16px; width: 90%; max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,0.3); }
        .map-pickee-header { padding: 14px 18px; background: var(--primary); color: white; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 15px; flex-shrink: 0; }
        .map-close-btn { background: none; border: none; color: white; font-size: 22px; cursor: pointer; line-height: 1; padding: 0; }
        .map-search-bar { padding: 12px 14px; background: #f8fafc; border-bottom: 1px solid #e8edf2; display: flex; gap: 10px; align-items: center; flex-shrink: 0; }
        .map-search-wrap { position: relative; flex: 1; }
        .map-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 13px; }
        .map-search-wrap input { width: 100%; padding: 9px 12px 9px 32px; border: 1.5px solid #dde3ea; border-radius: 10px; font-size: 13px; font-family: inherit; transition: border 0.2s; }
        .map-search-wrap input:focus { outline: none; border-color: var(--primary); }
        .map-search-suggestions { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e0e8f0; border-radius: 10px; box-shadow: 0 6px 20px rgba(0,0,0,0.1); z-index: 200; margin-top: 4px; max-height: 220px; overflow-y: auto; }
        .map-search-suggestions.show { display: block; }
        .map-suggestion-item { padding: 10px 14px; font-size: 13px; color: #333; cursor: pointer; display: flex; align-items: flex-start; gap: 8px; border-bottom: 1px solid #f0f4f8; transition: background 0.15s; }
        .map-suggestion-item:last-child { border-bottom: none; }
        .map-suggestion-item:hover { background: #f0f7ff; }
        .map-suggestion-item i { color: var(--primary); margin-top: 2px; flex-shrink: 0; font-size: 12px; }
        .sug-main { font-weight: 600; color: #222; font-size: 13px; }
        .sug-sub  { font-size: 11px; color: #888; margin-top: 1px; }
        .map-gps-btn { padding: 10px 14px; background: #17a2b8; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600; white-space: nowrap; display: flex; align-items: center; gap: 6px; transition: all 0.2s; flex-shrink: 0; }
        .map-gps-btn:hover { background: #138496; }
        .map-gps-btn:disabled { background: #adb5bd; cursor: not-allowed; }
        #mapPickeeLeaflet { width: 100%; min-height: 360px; flex: 1; cursor: crosshair !important; }
        .map-selected-panel { padding: 12px 16px; background: #f8fafc; border-top: 2px solid #e8edf2; display: flex; align-items: center; gap: 12px; flex-shrink: 0; flex-wrap: wrap; }
        .map-selected-info { flex: 1; min-width: 0; }
        .map-selected-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #aaa; margin-bottom: 3px; }
        .map-selected-address { font-size: 13px; color: #222; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .map-selected-address.empty { color: #bbb; font-style: italic; font-weight: 400; }
        .map-selected-coords { font-size: 11px; color: #aaa; margin-top: 2px; }
        .map-confirm-btn { padding: 10px 22px; background: #28a745; color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 14px; transition: all 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 7px; }
        .map-confirm-btn:hover:not(:disabled) { background: #218838; transform: translateY(-1px); }
        .map-confirm-btn:disabled { background: #adb5bd; cursor: not-allowed; }
        .map-cancel-btn { padding: 10px 16px; background: #f0f0f0; color: #555; border: none; border-radius: 10px; cursor: pointer; font-size: 14px; transition: background 0.2s; }
        .map-cancel-btn:hover { background: #e0e0e0; }

        /* Terms */
        .terms-scroll-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; max-height: 240px; overflow-y: auto; font-size: 13px; color: #444; line-height: 1.7; margin-bottom: 16px; }
        .terms-scroll-box h4 { font-weight: 700; font-size: 13px; color: #222; margin: 0 0 6px; }
        .terms-scroll-box p { margin: 0 0 14px; }
        .terms-scroll-box p:last-child { margin-bottom: 0; }
        .terms-checkbox-wrap { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 14px 16px; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 10px; margin-bottom: 16px; }
        .terms-checkbox-wrap input[type="checkbox"] { margin-top: 2px; width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; cursor: pointer; }
        .terms-checkbox-wrap span { font-size: 13px; color: #444; line-height: 1.6; }
        .contract-preview-box { background: #f8fafc; border: 1px solid #dbe6f3; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        .contract-preview-title { font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 8px; }
        .contract-preview-text { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; font-size: 12px; color: #374151; line-height: 1.6; white-space: per-wrap; max-height: 160px; overflow-y: auto; }
        .provider-signature-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
        .provider-signature-box img { display: block; max-width: 220px; max-height: 90px; border-bottom: 1px solid #86efac; padding-bottom: 4px; margin-top: 8px; }
        .signature-meta { font-size: 11px; color: #4b5563; margin-top: 6px; }
        .seeker-signature-box { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
        #seekerSignatureCanvas { width: 100%; height: 170px; border: 2px dashed #93c5fd; border-radius: 10px; background: #fff; cursor: crosshair; touch-action: none; }
        .signature-actions-row { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .btn-clear-signature { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 8px; padding: 7px 11px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-clear-signature:hover { background: #f8fafc; }
        .signature-status { font-size: 12px; color: #b91c1c; font-weight: 600; }
        .signature-status.signed { color: #15803d; }

        /* Pending request notice */
        .pending-notice { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px; color: #856404; display: flex; align-items: center; gap: 10px; }
        .form-error-box { display: none; background: #fff1f2; border: 1px solid #fda4af; color: #9f1239; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px; font-weight: 600; }
        .form-error-box i { margin-right: 7px; }

        /* Hide number input spinners */
        input[type=number]::-webkit-innee-spin-button,
        input[type=number]::-webkit-outee-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        @media (max-width: 768px) {
            .content-grid { grid-template-columns: 1fr; }
            .provider-hero h1 { font-size: 28px; }
            .services-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            #mapPickeeLeaflet { height: 280px; }
        }
    </style>
</head>
<body class="seeker-unified">
    <?php $current_page = 'providers';
    $use_seeker_unified_ui = true;
    include appPath('includes/header.php'); ?>

    <!-- ----------------------------------------------------------
         REQUEST SERVICE MODAL  (was "Avail Service")
    ---------------------------------------------------------- -->
    <div class="modal-overlay" id="availModal">
        <div class="modal-box">

            <!-- STEP 1: Confirmation -->
            <div class="modal-step active" id="stepConfirm">
                <div class="modal-icon"><i class="fas fa-paper-plane"></i></div>
                <div class="modal-title">Request This Service?</div>
                <div class="modal-service-name" id="modalServiceName"></div>
                <div class="modal-message">
                    You are about to send a service request to <strong><?php echo htmlspecialchars($provider['company_name']); ?></strong>.<br>
                    The provider will review and <strong>accept or reject</strong> your request.<br>
                    Payment will only be collected after acceptance.
                </div>
                <div class="modal-actions">
                    <button class="modal-btn modal-btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="modal-btn modal-btn-confirm" onclick="goToForm()">
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                </div>
            </div>

            <!-- STEP 2: Request Form -->
            <div class="modal-step" id="stepForm">
                <div class="avail-form-title">
                    <i class="fas fa-paper-plane" style="color:var(--primary);margin-right:8px;"></i>Request Service
                </div>
                <div class="avail-form-subtitle" id="formServiceLabel">Fill in your details below</div>

                <!-- Notice: no payment yet -->
                <div class="pending-notice">
                    <i class="fas fa-info-circle"></i>
                    <span>No payment required yet. The provider must <strong>accept</strong> your request first, then you'll be prompted to pay.</span>
                </div>
                <div id="formErrorBox" class="form-error-box"></div>

                <form id="availForm" method="POST" action="<?php echo appUrl('request-service.php'); ?>">
                    <input type="hidden" name="provider_id"   value="<?php echo $provider['id']; ?>">
                    <input type="hidden" name="service_id"    id="formServiceId"   value="">
                    <input type="hidden" name="service_name"  id="formServiceName" value="">
                    <input type="hidden" name="contract_text_snapshot" id="contractTextSnapshot" value="">
                    <input type="hidden" name="service_agreement_ack" id="serviceAgreementAck" value="0">
                    <input type="hidden" name="seeker_signature" id="seekerSignatureData" value="">

                    <!-- Seeker Info -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" id="fullName"
                                   value="<?php echo htmlspecialchars($seeker_name); ?>"
                                   readonly style="background:#f0f0f0;cursor:not-allowed;">
                        </div>
                        <div class="form-group">
                            <label>Contact Number <span class="req">*</span></label>
                            <input type="text" name="contact_number" id="contactNumber"
                                   value="<?php echo htmlspecialchars($seeker_phone); ?>"
                                   placeholder="Your contact number" required
                                   style="<?php echo $seeker_phone ? 'background:#f0f0f0;cursor:not-allowed;' : ''; ?>"
                                   <?php echo $seeker_phone ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" id="emailField"
                               value="<?php echo htmlspecialchars($seeker_email); ?>"
                               readonly style="background:#f0f0f0;cursor:not-allowed;">
                    </div>

                    <!-- Date -->
                    <div class="form-group">
                        <label>Preferred Date <span class="req">*</span></label>
                        <p style="font-size:12px;color:#888;margin-bottom:8px;">
                            <i class="fas fa-info-circle" style="color:var(--primary);"></i>
                            Earliest available date is <strong id="earliestDateLabel"></strong> (<?php echo (int)$provider_preparing_days; ?> day<?php echo $provider_preparing_days === 1 ? '' : 's'; ?> from today).
                            Dates marked in red are already fully booked.
                        </p>
                        <div class="custom-date-wrapper" id="dateWrapper">
                            <div class="date-display" id="dateDisplay" onclick="toggleCalendar()">
                                <i class="fas fa-calendar-alt"></i>
                                <span id="dateDisplayText">Select a date</span>
                                <i class="fas fa-chevron-down" style="margin-left:auto;font-size:11px;color:#aaa;"></i>
                            </div>
                            <input type="hidden" name="preferred_date" id="preferredDateInput" required>
                            <div class="calendar-popup" id="calendarPopup">
                                <div class="cal-header">
                                    <button type="button" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                                    <span id="calMonthYear"></span>
                                    <button type="button" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                                </div>
                                <div class="cal-weekdays">
                                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
                                    <span>Th</span><span>Fr</span><span>Sa</span>
                                </div>
                                <div class="cal-days" id="calDays"></div>
                                <div class="cal-legend">
                                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:var(--primary);"></div> Selected</div>
                                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#fde8e8;border:1px solid #e74c3c;"></div> Booked</div>
                                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#fafafa;border:1px solid #ddd;"></div> Unavailable</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time -->
                    <div class="form-group">
                        <label>Preferred Time <span class="req">*</span></label>
                        <p style="font-size:12px;color:#888;margin-bottom:8px;" id="workingHoursHint">Select from provider's available time slots (<?php echo htmlspecialchars($provider_working_hours_label); ?>)</p>
                        <select name="preferred_time" id="preferredTime" required>
                            <option value="">Select a time slot</option>
                        </select>
                    </div>

                    <!-- Total Amount -->
                    <div class="form-group">
                        <label>Total Amount (PHP)</label>
                        <input type="text" name="total_amount" id="totalAmount"
                               readonly
                               style="background:#f0f0f0;cursor:not-allowed;font-weight:700;color:#2c3e50;"
                               placeholder="Auto-filled from selected service">
                    </div>

                    <!-- Payment Method -->
                    <div class="form-group">
                        <label>Payment Method <span class="req">*</span></label>
                        <p style="font-size:12px;color:#888;margin-bottom:8px;">
                            <i class="fas fa-info-circle" style="color:var(--primary);"></i>
                            Choose now — you'll pay <strong>after</strong> the provider accepts.
                        </p>
                        <div style="display:flex;gap:10px;margin-top:4px;">
                            <button type="button" id="btnFull" onclick="selectPayment('full_payment')"
                                style="flex:1;padding:12px;border:2px solid #e2e8f0;border-radius:10px;background:white;cursor:pointer;font-size:14px;font-weight:600;font-family:inherit;color:#64748b;transition:all 0.2s;text-align:center;">
                                <i class="fas fa-money-bill-wave" style="display:block;font-size:20px;margin-bottom:4px;"></i>
                                Full Payment
                            </button>
                            <button type="button" id="btnDown" onclick="selectPayment('downpayment')"
                                style="flex:1;padding:12px;border:2px solid #e2e8f0;border-radius:10px;background:white;cursor:pointer;font-size:14px;font-weight:600;font-family:inherit;color:#64748b;transition:all 0.2s;text-align:center;">
                                <i class="fas fa-hand-holding-usd" style="display:block;font-size:20px;margin-bottom:4px;"></i>
                                Downpayment
                            </button>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethodHidden" value="">
                    </div>

                    <!-- Downpayment fields -->
                    <div id="downpaymentFields" style="display:none;background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                        <p style="font-size:12px;color:#92400e;font-weight:600;margin-bottom:10px;">
                            <i class="fas fa-info-circle" style="margin-right:5px;"></i>
                            Minimum downpayment: <strong id="dpRequirementLabel">—</strong>. You may pay more.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div>
                                <label style="font-size:12px;font-weight:600;color:#444;display:block;margin-bottom:4px;">Downpayment Amount (&#8369;) <span style="color:#e74c3c;">*</span></label>
                                <input type="number" id="dpDueDisplay" name="downpayment_amount"
                                    min="0" step="0.01" placeholder="Enter downpayment amount"
                                    oninput="recalcDPFromInput(this.value)"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #fde68a;border-radius:8px;font-size:14px;font-weight:700;color:#92400e;background:#fff;box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:600;color:#444;display:block;margin-bottom:4px;">Remaining Balance (&#8369;)</label>
                                <input type="text" id="remainingAmount"
                                    style="width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#555;background:#f0f0f0;" readonly placeholder="Auto-calculated">
                            </div>
                        </div>
                        <div id="dpWarning" style="display:none;">
                            <span id="dpWarningText"></span>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="form-group">
                        <label>Address / Location <span class="req">*</span></label>
                        <div style="display:flex;gap:8px;margin-bottom:4px;">
                            <input type="text" name="address" id="addressInput"
                                   placeholder="Click 'Pick on Map' or type your address" required style="flex:1;">
                            <button type="button"
                                style="padding:10px 14px;background:#007bff;color:white;border:none;border-radius:8px;cursor:pointer;font-size:14px;white-space:nowrap;"
                                onclick="openMapModal()">
                                <i class="fas fa-map-marker-alt"></i> Pick on Map
                            </button>
                        </div>
                        <input type="hidden" name="latitude"  id="latitude">
                        <input type="hidden" name="longitude" id="longitude">
                    </div>

                    <div class="form-group">
                        <label>Notes / Special Instructions</label>
                        <textarea name="notes" placeholder="Any additional details or requests..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-back-form" onclick="backToConfirm()">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn-submit" onclick="goToTerms()">
                            <i class="fas fa-arrow-right"></i> Review &amp; Submit
                        </button>
                    </div>
                </form>
            </div>

            <!-- STEP 3: Terms -->
            <div class="modal-step" id="stepTerms">
                <div class="avail-form-title" style="text-align:center;margin-bottom:6px;">
                    <i class="fas fa-file-contract" style="color:var(--primary);margin-right:8px;"></i>Terms &amp; Conditions
                </div>
                <p style="text-align:center;font-size:13px;color:#888;margin-bottom:18px;">Please read and agree before submitting your request.</p>

                <div class="terms-scroll-box">
                    <h4>1. Request, Not Booking</h4>
                    <p>Submitting this form sends a <strong>service request</strong> to the provider. It is <strong>not a confirmed booking</strong>. The provider must accept before service is scheduled.</p>

                    <h4>2. Provider Acceptance</h4>
                    <p>The provider (Owner or CRM staff) will review and either <strong>accept or reject</strong> your request. You will be notified of their decision.</p>

                    <h4>3. Payment After Acceptance</h4>
                    <p>Payment is only required <strong>after your request is accepted</strong>. You will be redirected to pay via PayMongo (GCash, card, etc.) at that time.</p>

                    <h4>4. Cancellation</h4>
                    <p>You may cancel your request before the provider accepts. Once accepted and paid, cancellation policies of the provider apply.</p>

                    <h4>5. Accurate Information</h4>
                    <p>You confirm all details provided (name, contact, address, preferred date/time) are accurate and complete.</p>

                    <h4>6. Communication</h4>
                    <p>The provider may contact you via phone or the Pestify messaging system to clarify details before accepting.</p>
                </div>

                <div class="contract-preview-box">
                    <div class="contract-preview-title">
                        <i class="fas fa-scroll" style="color:var(--primary);margin-right:6px;"></i>Service Contract Snapshot
                    </div>
                    <div id="contractPreviewText" class="contract-preview-text">No contract loaded yet.</div>
                </div>

                <div class="provider-signature-box" id="providerSignatureBox" style="display:none;">
                    <div class="contract-preview-title" style="margin-bottom:0;">
                        <i class="fas fa-signature" style="color:#16a34a;margin-right:6px;"></i>Provider E-Signature
                    </div>
                    <img id="providerSignatureImage" src="" alt="Provider signature">
                    <div class="signature-meta" id="providerSignatureMeta"></div>
                </div>

                <div class="seeker-signature-box">
                    <div class="contract-preview-title">
                        <i class="fas fa-pen-fancy" style="color:#1d4ed8;margin-right:6px;"></i>Your E-Signature <span class="req">*</span>
                    </div>
                    <canvas id="seekerSignatureCanvas"></canvas>
                    <div class="signature-actions-row">
                        <button type="button" class="btn-clear-signature" onclick="clearSeekerSignature()">
                            <i class="fas fa-eraser"></i> Clear Signature
                        </button>
                        <span class="signature-status" id="seekerSignatureStatus">Signature required before submit.</span>
                    </div>
                </div>

                <label class="terms-checkbox-wrap" id="termsLabel">
                    <input type="checkbox" id="termsCheck" onchange="updateTermsBtn()">
                    <span>I have read and agree to the Terms &amp; Conditions above. I understand that <strong>payment will be collected after the provider accepts</strong> my request.</span>
                </label>

                <div class="form-actions" style="margin-top:0">
                    <button type="button" class="btn-back-form" onclick="backToForm()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button type="button" id="termsSubmitBtn"
                            onclick="submitAvailRequest()"
                            class="btn-submit"
                            disabled
                            style="flex:1;opacity:0.45;cursor:not-allowed;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Map Modal (unchanged) -->
    <div id="mapPickeeModal">
        <div class="map-pickee-box">
            <div class="map-pickee-header">
                <span><i class="fas fa-map-marker-alt"></i> Pick Your Location</span>
                <button class="map-close-btn" onclick="closeMapModal()">&times;</button>
            </div>
            <div class="map-search-bar">
                <div class="map-search-wrap">
                    <i class="fas fa-search map-search-icon"></i>
                    <input type="text" id="mapSeaechInput" placeholder="Search baeangay, street, city, landmaek..." autocomplete="off" oninput="onMapSeaechInput(this.value)" onkeydown="onMapSeaechKey(event)">
                    <div class="map-search-suggestions" id="mapSuggestions"></div>
                </div>
                <button class="map-gps-btn" id="mapGpsBtn" onclick="useMyLocation()">
                    <i class="fas fa-location-arrow"></i> My Location
                </button>
            </div>
            <div id="mapPickeeLeaflet"></div>
            <div class="map-selected-panel">
                <div class="map-selected-info">
                    <div class="map-selected-label"><i class="fas fa-map-pin" style="margin-right:4px;color:var(--primary);"></i>Selected Location</div>
                    <div class="map-selected-address empty" id="mapSelectedAddeess">Tap anywhere within Cavite to drop a pin</div>
                    <div class="map-selected-coords" id="mapSelectedCooeds"></div>
                </div>
                <button class="map-cancel-btn" onclick="closeMapModal()">Cancel</button>
                <button class="map-confirm-btn" id="mapConfirmBtn" onclick="confirmMapLocation()" disabled>
                    <i class="fas fa-check"></i> Use This Location
                </button>
            </div>
        </div>
    </div>

    <div class="provider-details-page">
        <!-- Hero -->
        <div class="provider-hero">
            <div class="provider-hero-content">
                <div class="provider-avatar">
                    <?php if($provider['logo_url']): ?>
                        <img src="<?php echo htmlspecialchars($provider['logo_url']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($provider['company_name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <h1><?php echo htmlspecialchars($provider['company_name']); ?></h1>
                <p><?php echo htmlspecialchars($provider['description'] ?? 'Professional Pest Control Services'); ?></p>
                <div class="provider-rating-section">
                    <div class="rating-item">
                        <div class="rating-stars">
                            <?php if($stats['avg_rating']): ?>
                                <?php for($i=1;$i<=5;$i++): ?>
                                    <i class="fas<?php echo $i<=floor($stats['avg_rating'])?' fa-star':' fa-star' ?>"></i>
                                <?php endfor; ?>
                            <?php else: ?>
                                <span style="font-size:16px;">No ratings yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="rating-text">
                            <?php if($stats['avg_rating']): ?>
                                <?php echo number_format($stats['avg_rating'],1); ?>/5
                                <span style="opacity:0.8;">(<?php echo $stats['total_reviews']; ?> reviews)</span>
                            <?php else: ?>
                                <span style="opacity:0.8;">No reviews yet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <a href="<?php echo appUrl('providers.php'); ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Companies
            </a>

            <?php if($success_msg): ?>
            <div class="success-banner">
                <i class="fas fa-paper-plane" style="font-size:20px;"></i>
                <div>
                    <strong>Request Sent!</strong> Your service request has been submitted.
                    The provider will review and respond shoetly.
                    You'll receive a notification once they accept or reject your request.
                </div>
            </div>
            <?php endif; ?>

            <?php if($rejected_msg): ?>
            <div class="info-banner">
                <i class="fas fa-info-circle" style="font-size:20px;"></i>
                <div>Your previous request was rejected. You can submit a new request if you'd like to try again.</div>
            </div>
            <?php endif; ?>

            <?php if($pending_payment_booking): ?>
            <?php
chdir(dirname(__DIR__));
                $ppb        = $pending_payment_booking;
                $payLabel   = $ppb['payment_method'] === 'downpayment' ? 'Downpayment' : 'Full Payment';
                $payAmt     = $ppb['payment_method'] === 'downpayment'
                                ? (float)$ppb['downpayment_amount']
                                : (float)$ppb['total_amount'];
            ?>
            <div class="payment-prompt-banner">
                <div class="payment-prompt-icon"><i class="fas fa-credit-card"></i></div>
                <div class="payment-prompt-body">
                    <h3><i class="fas fa-check-circle" style="color:#27ae60;margin-right:6px;"></i>Your Request Was Accepted! Complete Your Payment</h3>
                    <p>
                        <strong><?= htmlspecialchars($provider['company_name']) ?></strong> has accepted your service request for
                        <strong><?= htmlspecialchars($ppb['service_name'] ?: 'your selected service') ?></strong>.
                        Please complete your <?= strtolower($payLabel) ?> to confirm your booking slot.
                    </p>
                    <div class="payment-prompt-meta">
                        <div class="payment-meta-chip"><i class="fas fa-hashtag"></i> Booking #<?= $ppb['id'] ?></div>
                        <div class="payment-meta-chip"><i class="fas fa-calendar-alt"></i> <?= date('M j, Y', strtotime($ppb['preferred_date'])) ?> at <?= date('h:i A', strtotime($ppb['preferred_time'])) ?></div>
                        <div class="payment-meta-chip"><i class="fas fa-tag"></i> <?= $payLabel ?></div>
                        <div class="payment-meta-chip" style="background:#27ae60;color:#fff;">
                            <i class="fas fa-peso-sign"></i> PHP <?= number_format($payAmt, 2) ?> due
                        </div>
                    </div>

                    <?php if($pending_payment_link): ?>
                    <a href="<?= htmlspecialchars($pending_payment_link) ?>" target="_blank" class="btn-pay-now">
                        <i class="fas fa-lock"></i> Pay Now via PayMongo
                    </a>
                    <p style="font-size:11px;color:#999;margin-top:10px;">
                        <i class="fas fa-shield-alt"></i> Secueed by PayMongo &nbsp;·&nbsp;
                        Accepts GCash, Maya, Credit/Debit Card
                    </p>
                    <?php else: ?>
                    <span class="btn-pay-no-link"><i class="fas fa-hourglass-half"></i> Payment link being prepared…</span>
                    <p style="font-size:12px;color:#a07a00;margin-top:10px;">
                        <i class="fas fa-info-circle"></i>
                        The payment link is still being generated. Please refresh this page in a moment, or wait for your notification with the payment link.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="content-grid">
                <!-- Main -->
                <div>
                    <div class="section-card">
                        <h2 class="section-title"><i class="fas fa-info-circle"></i> About</h2>
                        <p class="about-text"><?php echo nl2br(htmlspecialchars($provider['description'] ?? 'No description available.')); ?></p>
                    </div>

                    <!-- Services -->
                    <div class="section-card" style="margin-top:30px;">
                        <h2 class="section-title">
                            <i class="fas fa-list"></i> Services
                            <span style="font-size:16px;color:#999;margin-left:auto;">(<?php echo count($services); ?>)</span>
                        </h2>
                        <?php if(count($services) > 0): ?>
                        <div class="services-grid">
                            <?php foreach($services as $service):
                                $ps       = json_decode($service['payment_settings'] ?? '{}', true) ?? [];
                                $ps_mode  = $ps['dp_mode']    ?? 'percent';
                                $ps_pct   = (float)($ps['dp_percent'] ?? 50);
                                $ps_fixed = (float)($ps['dp_fixed']   ?? 0);
                            ?>
                            <div class="service-card">
                                <div class="service-name"><?php echo htmlspecialchars($service['service_name']); ?></div>
                                <div class="service-price">?<?php echo number_format($service['price'],2); ?></div>
                                <div class="service-desc"><?php echo htmlspecialchars(substr($service['description']??'',0,100)); ?>...</div>
                                <button class="service-action"
                                    onclick="openModal('<?php echo htmlspecialchars(addslashes($service['service_name'])); ?>','<?php echo $service['id']; ?>','<?php echo $ps_mode; ?>',<?php echo $ps_pct; ?>,<?php echo $ps_fixed; ?>,<?php echo (float)$service['price']; ?>)">
                                    <i class="fas fa-paper-plane"></i> Request Service
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state"><i class="fas fa-box" style="font-size:40px;margin-bottom:10px;"></i><p>No services listed yet</p></div>
                        <?php endif; ?>
                    </div>

                    <!-- Reviews from service_reviews table -->
                    <?php if(count($reviews) > 0): ?>
                    <div class="section-card" style="margin-top:30px;">
                        <h2 class="section-title"><i class="fas fa-star"></i> Customer Reviews</h2>
                        <?php foreach($reviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <div>
                                    <div class="reviewer-name"><?php echo htmlspecialchars($review['first_name'].' '.$review['last_name']); ?></div>
                                    <div class="review-date"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></div>
                                    <?php if(!empty($review['service_name'])): ?>
                                    <div class="review-service-tag"><i class="fas fa-tag" style="font-size:10px;"></i> <?php echo htmlspecialchars($review['service_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="review-rating">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <i class="fas fa-star<?php echo $i<=$review['rating']?'':'-o'; ?>" style="color:<?php echo $i<=$review['rating']?'#f39c12':'#ddd'; ?>;"></i>
                                    <?php endfor; ?>
                                    <span style="font-size:13px;color:#555;margin-left:4px;"><?php echo $review['rating']; ?>/5</span>
                                </div>
                            </div>
                            <?php if(!empty($review['feedback'])): ?>
                            <div class="review-text"><?php echo htmlspecialchars($review['feedback']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div>
                    <div class="section-card">
                        <h2 class="section-title"><i class="fas fa-phone"></i> Contact Information</h2>
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <div><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($provider['email']); ?></div></div>
                        </div>
                        <?php if($provider['phone']): ?>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <div><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($provider['phone']); ?></div></div>
                        </div>
                        <?php endif; ?>
                        <?php if($provider['city']): ?>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div><div class="info-label">Location</div><div class="info-value"><?php echo htmlspecialchars($provider['city']); ?></div></div>
                        </div>
                        <?php endif; ?>
                        <div class="action-buttons">
                            <?php if(isset($_SESSION['user_id'])): ?>
                            <a href="<?php echo appUrl('messages.php'); ?>?to=<?php echo $provider['user_id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-comment-dots"></i> Send Message
                            </a>
                            <?php else: ?>
                            <a href="<?php echo appUrl('login.php'); ?>?redirect=provider-details.php?id=<?php echo $provider_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-comment-dots"></i> Send Message
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="section-card" style="margin-top:30px;">
                        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Statistics</h2>
                        <div class="info-item">
                            <i class="fas fa-list"></i>
                            <div><div class="info-label">Total Services</div><div class="info-value"><?php echo $stats['total_services']??0; ?></div></div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-check-circle"></i>
                            <div><div class="info-label">Completed Jobs</div><div class="info-value"><?php echo $stats['completed_jobs']??0; ?></div></div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-star"></i>
                            <div><div class="info-label">Average Rating</div><div class="info-value"><?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'],1).'/5' : 'N/A'; ?></div></div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div><div class="info-label">Service Radius</div><div class="info-value"><?php echo htmlspecialchars($provider['service_radius']??'50'); ?>km</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    // -- ALL ORIGINAL JS — unchanged --------------------------
    const BOOKED_DATES  = <?php echo json_encode($booked_dates); ?>;
    const EARLIEST_DAYS = <?php echo (int)$provider_preparing_days; ?>;
    const WORKING_HOURS_START = <?php echo json_encode($provider_working_hours_start); ?>;
    const WORKING_HOURS_END = <?php echo json_encode($provider_working_hours_end); ?>;
    const WORKING_SLOT_MINUTES = <?php echo (int)$provider_working_slot_minutes; ?>;
    const SERVICE_CONTRACTS = <?php echo json_encode($service_contract_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let calYear, calMonth, selectedDate = null;
    let dpMode = '', dpPct = 0, dpFixed = 0, servicePrice = 0;
    let seekerSigCanvas = null;
    let seekerSigCtx = null;
    let seekerSigDrawing = false;
    let seekerSigHasStroke = false;

    function formatTimeLabelFromHhMm(time24) {
        const m = /^(\d{2}):(\d{2})$/.exec(String(time24 || ''));
        if (!m) return '';
        const hh = parseInt(m[1], 10);
        const mm = m[2];
        const ampm = hh >= 12 ? 'PM' : 'AM';
        const h12 = ((hh + 11) % 12) + 1;
        return h12 + ':' + mm + ' ' + ampm;
    }

    function buildSlotsFromProviderSettings() {
        const mStaet = /^(\d{2}):(\d{2})$/.exec(String(WORKING_HOURS_START || ''));
        const mEnd = /^(\d{2}):(\d{2})$/.exec(String(WORKING_HOURS_END || ''));
        const step = parseInt(WORKING_SLOT_MINUTES || 60, 10);
        if (!mStaet || !mEnd || !step || step <= 0) return [];

        const start = new Date(1970, 0, 1, parseInt(mStaet[1], 10), parseInt(mStaet[2], 10), 0);
        const end = new Date(1970, 0, 1, parseInt(mEnd[1], 10), parseInt(mEnd[2], 10), 0);
        if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return [];

        const out = [];
        for (let t = start.getTime(); t <= end.getTime(); t += step * 60000) {
            const d = new Date(t);
            const hh = String(d.getHours()).padStart(2, '0');
            const mm = String(d.getMinutes()).padStart(2, '0');
            out.push({ value: hh + ':' + mm + ':00', label: formatTimeLabelFromHhMm(hh + ':' + mm) });
        }
        return out;
    }

    function formatSignedAt(rawDateTime) {
        if (!rawDateTime) return '';
        const dt = new Date(String(rawDateTime).replace(' ', 'T'));
        if (isNaN(dt.getTime())) return '';
        return dt.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit'
        });
    }

    function setContractForService(serviceId, serviceName) {
        const data = SERVICE_CONTRACTS[String(serviceId || '')] || {};
        const rawText = String(data.text || '').trim();
        const fallbackText = 'By submitting this request for "' + (serviceName || 'Selected Service') + '", you agree to the provider terms shown above and the Pestify service request policy.';
        const snapshotText = rawText || fallbackText;

        const preview = document.getElementById('contractPreviewText');
        if (preview) preview.textContent = snapshotText;

        const hiddenSnapshot = document.getElementById('contractTextSnapshot');
        if (hiddenSnapshot) hiddenSnapshot.value = snapshotText;

        const sigBox = document.getElementById('providerSignatureBox');
        const sigImg = document.getElementById('providerSignatureImage');
        const sigMeta = document.getElementById('providerSignatureMeta');
        const sigData = String(data.signature || '').trim();

        if (sigData && sigData.indexOf('data:image') === 0) {
            if (sigBox) sigBox.style.display = 'block';
            if (sigImg) sigImg.src = sigData;
            if (sigMeta) {
                const signedAtLabel = formatSignedAt(data.signed_at || '');
                sigMeta.textContent = signedAtLabel
                    ? 'Signed on ' + signedAtLabel
                    : 'Provider signature is attached to this contract.';
            }
        } else {
            if (sigBox) sigBox.style.display = 'none';
            if (sigImg) sigImg.src = '';
            if (sigMeta) sigMeta.textContent = '';
        }
    }

    function getSignaturePoint(event) {
        const rect = seekerSigCanvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    }

    function syncSeekerSignatureState() {
        const hidden = document.getElementById('seekerSignatureData');
        if (!hidden) return;
        hidden.value = (seekerSigCanvas && seekerSigHasStroke) ? seekerSigCanvas.toDataURL('image/png') : '';

        const status = document.getElementById('seekerSignatureStatus');
        if (status) {
            status.textContent = hidden.value
                ? 'Signature captured.'
                : 'Signature required before submit.';
            status.classList.toggle('signed', !!hidden.value);
        }
        updateTermsBtn();
    }

    function clearSeekerSignature(skipUpdate) {
        if (seekerSigCtx && seekerSigCanvas) {
            seekerSigCtx.clearRect(0, 0, seekerSigCanvas.width, seekerSigCanvas.height);
        }
        seekerSigHasStroke = false;
        const hidden = document.getElementById('seekerSignatureData');
        if (hidden) hidden.value = '';
        if (!skipUpdate) syncSeekerSignatureState();
    }

    function prepareSeekerSignatureCanvas() {
        const canvas = document.getElementById('seekerSignatureCanvas');
        if (!canvas) return;

        const firstBind = !canvas.dataset.bound;
        seekerSigCanvas = canvas;
        seekerSigCtx = seekerSigCanvas.getContext('2d');

        const existingSignature = seekerSigHasStroke ? seekerSigCanvas.toDataURL('image/png') : '';
        const width = Math.max(Math.floor(seekerSigCanvas.getBoundingClientRect().width), 280);
        seekerSigCanvas.width = width;
        seekerSigCanvas.height = 170;
        seekerSigCtx.lineWidth = 2;
        seekerSigCtx.lineCap = 'round';
        seekerSigCtx.lineJoin = 'round';
        seekerSigCtx.strokeStyle = '#0f172a';
        seekerSigCtx.fillStyle = '#0f172a';

        if (existingSignature) {
            const img = new Image();
            img.onload = function() {
                seekerSigCtx.drawImage(img, 0, 0, seekerSigCanvas.width, seekerSigCanvas.height);
                syncSeekerSignatureState();
            };
            img.src = existingSignature;
        }

        if (!firstBind) return;

        canvas.dataset.bound = '1';
        canvas.addEventListener('pointerdown', function(event) {
            event.preventDefault();
            const pt = getSignaturePoint(event);
            seekerSigDrawing = true;
            if (canvas.setPointerCapture) {
                canvas.setPointerCapture(event.pointerId);
            }
            seekerSigCtx.beginPath();
            seekerSigCtx.moveTo(pt.x, pt.y);
            seekerSigCtx.lineTo(pt.x + 0.1, pt.y + 0.1);
            seekerSigCtx.stroke();
            seekerSigHasStroke = true;
            syncSeekerSignatureState();
        });

        canvas.addEventListener('pointermove', function(event) {
            if (!seekerSigDrawing) return;
            event.preventDefault();
            const pt = getSignaturePoint(event);
            seekerSigCtx.lineTo(pt.x, pt.y);
            seekerSigCtx.stroke();
            seekerSigHasStroke = true;
            syncSeekerSignatureState();
        });

        const stopSign = function() {
            if (!seekerSigDrawing) return;
            seekerSigDrawing = false;
            syncSeekerSignatureState();
        };
        canvas.addEventListener('pointerup', stopSign);
        canvas.addEventListener('pointerleave', stopSign);
        canvas.addEventListener('pointercancel', stopSign);
    }

    function openModal(sName, sId, mode, pct, fixed, price) {
        if (!<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>) {
            window.location.href = 'login.php?redirect=provider-details.php?id=<?php echo $provider_id; ?>';
            return;
        }
        hideFormError();
        dpMode = mode; dpPct = pct; dpFixed = fixed; servicePrice = price || 0;
        document.getElementById('modalServiceName').textContent = sName || 'Selected Service';
        document.getElementById('formServiceId').value   = sId   || '';
        document.getElementById('formServiceName').value = sName || '';
        document.getElementById('formServiceLabel').textContent = sName ? sName : 'Fill in your details below';
        setContractForService(sId, sName);
        document.getElementById('serviceAgreementAck').value = '0';
        document.getElementById('termsCheck').checked = false;
        clearSeekerSignature(true);
        syncSeekerSignatureState();
        if (servicePrice > 0) {
            document.getElementById('totalAmount').value = servicePrice.toFixed(2);
            recalcDP();
        } else {
            document.getElementById('totalAmount').value = '';
        }
        showStep('stepConfirm');
        document.getElementById('availModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        initCalendar();
        loadTimeSlots();
    }

    function closeModal() {
        document.getElementById('availModal').classList.remove('active');
        document.body.style.overflow = '';
        resetForm();
    }

    function showStep(id) {
        document.querySelectorAll('.modal-step').forEach(s => s.classList.remove('active'));
        document.getElementById(id).classList.add('active');
    }
    function goToForm()    { hideFormError(); showStep('stepForm'); }
    function backToConfirm(){ hideFormError(); showStep('stepConfirm'); }
    function goToTerms() {
        if (!validateForm()) return;
        hideFormError();
        showStep('stepTerms');
        prepareSeekerSignatureCanvas();
        updateTermsBtn();
    }
    function backToForm()  { hideFormError(); showStep('stepForm'); }

    function showFormError(message) {
        const box = document.getElementById('formErrorBox');
        if (!box) {
            alert(message);
            return;
        }
        box.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
        box.style.display = 'block';
    }

    function hideFormError() {
        const box = document.getElementById('formErrorBox');
        if (!box) return;
        box.style.display = 'none';
        box.innerHTML = '';
    }

    function validateForm() {
        const date = document.getElementById('preferredDateInput').value;
        const time = document.getElementById('preferredTime').value;
        const amt  = document.getElementById('totalAmount').value;
        const pm   = document.getElementById('paymentMethodHidden').value;
        const addr = document.getElementById('addressInput').value;

        const missing = [];
        if (!date) missing.push('Preferred date');
        if (!time) missing.push('Preferred time');
        if (!amt || parseFloat(amt) <= 0) missing.push('Service selection');
        if (!pm) missing.push('Payment method');
        if (!addr) missing.push('Address/location');

        if (missing.length === 5) {
            showFormError('The form is blank. Please complete all required fields before continuing.');
            return false;
        }
        if (missing.length > 0) {
            showFormError('Please complete the required fields: ' + missing.join(', ') + '.');
            return false;
        }

        if (String(addr).toLowerCase().indexOf('cavite') === -1) {
            showFormError('Pestify currently accepts service requests within Cavite only.');
            return false;
        }

        if (pm === 'downpayment') {
            const total = parseFloat(document.getElementById('totalAmount').value) || 0;
            const minDP = getMinDP(total);
            const dp    = parseFloat(document.getElementById('dpDueDisplay').value) || 0;
            if (dp <= 0) {
                showFormError('Please enter the downpayment amount.');
                return false;
            }
            if (dp < minDP) {
                showFormError('Downpayment cannot be lower than PHP ' + minDP.toFixed(2) + '.');
                return false;
            }
            if (dp > total) {
                showFormError('Downpayment cannot exceed the total amount of PHP ' + total.toFixed(2) + '.');
                return false;
            }
        }
        hideFormError();
        return true;
    }

    function updateTermsBtn() {
        const btn = document.getElementById('termsSubmitBtn');
        const checked = document.getElementById('termsCheck').checked;
        const hasContract = !!String(document.getElementById('contractTextSnapshot').value || '').trim();
        const hasSignature = !!String(document.getElementById('seekerSignatureData').value || '').trim();
        const ready = checked && hasContract && hasSignature;
        document.getElementById('serviceAgreementAck').value = checked ? '1' : '0';
        btn.disabled = !ready;
        btn.style.opacity = ready ? '1' : '0.45';
        btn.style.cursor  = ready ? 'pointer' : 'not-allowed';
    }

    function submitAvailRequest() {
        const checked = document.getElementById('termsCheck').checked;
        const hasContract = !!String(document.getElementById('contractTextSnapshot').value || '').trim();
        const hasSignature = !!String(document.getElementById('seekerSignatureData').value || '').trim();

        if (!checked) {
            alert('Please check the agreement confirmation before submitting.');
            return;
        }
        if (!hasContract) {
            alert('Contract snapshot is missing. Please go back and select the service again.');
            return;
        }
        if (!hasSignature) {
            alert('Please provide your e-signature before submitting.');
            return;
        }
        document.getElementById('availForm').submit();
    }

    function resetForm() {
        document.getElementById('availForm').reset();
        hideFormError();
        document.getElementById('paymentMethodHidden').value = '';
        document.getElementById('downpaymentFields').style.display = 'none';
        document.getElementById('termsCheck').checked = false;
        document.getElementById('serviceAgreementAck').value = '0';
        document.getElementById('contractTextSnapshot').value = '';
        const preview = document.getElementById('contractPreviewText');
        if (preview) preview.textContent = 'No contract loaded yet.';
        const providerSigBox = document.getElementById('providerSignatureBox');
        if (providerSigBox) providerSigBox.style.display = 'none';
        clearSeekerSignature(true);
        syncSeekerSignatureState();
        updateTermsBtn();
        selectedDate = null;
        document.getElementById('dateDisplayText').textContent = 'Select a date';
        document.getElementById('preferredDateInput').value = '';
        ['btnFull','btnDown'].forEach(id => {
            const b = document.getElementById(id);
            b.style.borderColor = '#e2e8f0';
            b.style.background  = 'white';
            b.style.color       = '#64748b';
        });
    }

    // -- Payment method ---------------------------------------
    function selectPayment(method) {
        hideFormError();
        document.getElementById('paymentMethodHidden').value = method;
        ['btnFull','btnDown'].forEach(id => {
            const b = document.getElementById(id);
            b.style.borderColor = '#e2e8f0';
            b.style.background  = 'white';
            b.style.color       = '#64748b';
        });
        const active = method === 'full_payment' ? 'btnFull' : 'btnDown';
        document.getElementById(active).style.borderColor = 'var(--primary)';
        document.getElementById(active).style.background  = '#e8f4fd';
        document.getElementById(active).style.color       = 'var(--primary)';
        document.getElementById('downpaymentFields').style.display = method === 'downpayment' ? 'block' : 'none';
        if (method === 'downpayment') autoFillDP();
        else recalcDP();
    }

    function getMinDP(total) {
        let minDP = 0;
        if (dpMode === 'percent')   minDP = total * (dpPct / 100);
        else if (dpMode === 'fixed') minDP = dpFixed;
        return Math.min(minDP, total);
    }

    // Auto-fill the downpayment input with the provider-required minimum
    function autoFillDP() {
        const total = parseFloat(document.getElementById('totalAmount').value) || 0;
        if (total <= 0) return;
        const minDP = getMinDP(total);
        document.getElementById('dpDueDisplay').value = minDP.toFixed(2);
        document.getElementById('remainingAmount').value = 'PHP ' + (total - minDP).toFixed(2);
        updateDPLabel(minDP, total);
        validateDP(minDP, minDP, total);
    }

    function recalcDP() {
        const total = parseFloat(document.getElementById('totalAmount').value) || 0;
        const minDP = getMinDP(total);
        updateDPLabel(minDP, total);
        const dpVal = parseFloat(document.getElementById('dpDueDisplay').value) || 0;
        const rem   = total - dpVal;
        document.getElementById('remainingAmount').value = rem >= 0 ? 'PHP ' + rem.toFixed(2) : '';
        validateDP(dpVal, minDP, total);
    }

    function updateDPLabel(minDP, total) {
        const label = dpMode === 'percent'
            ? 'PHP ' + minDP.toFixed(2) + ' (' + dpPct + '% of total)'
            : 'PHP ' + minDP.toFixed(2);
        document.getElementById('dpRequirementLabel').textContent = label;
    }

    function recalcDPFromInput(val) {
        const total = parseFloat(document.getElementById('totalAmount').value) || 0;
        const minDP = getMinDP(total);
        const dpVal = parseFloat(val) || 0;
        const rem   = total - dpVal;
        document.getElementById('remainingAmount').value = rem >= 0 ? 'PHP ' + rem.toFixed(2) : '';
        validateDP(dpVal, minDP, total);
    }

    function validateDP(dpVal, minDP, total) {
        const warn  = document.getElementById('dpWarning');
        const txt   = document.getElementById('dpWarningText');
        const input = document.getElementById('dpDueDisplay');

        if (dpVal <= 0) {
            warn.style.display = 'none';
            input.style.borderColor = '#fde68a';
            return;
        }

        if (dpVal < minDP) {
            // Too low
            txt.innerHTML = '<i class="fas fa-exclamation-circle" style="margin-right:5px;"></i>'
                + 'Minimum downpayment is <strong>PHP ' + minDP.toFixed(2) + '</strong>. '
                + 'Please enter an amount equal to or higher than this.';
            warn.style.cssText = 'display:block;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;'
                + 'background:#fff0f0;border:1px solid #f5c6cb;color:#c0392b;';
            input.style.borderColor = '#e74c3c';

        } else if (dpVal > total) {
            // Too high
            txt.innerHTML = '<i class="fas fa-exclamation-circle" style="margin-right:5px;"></i>'
                + 'Your downpayment of <strong>PHP ' + dpVal.toFixed(2) + '</strong> exceeds the '
                + 'total amount of <strong>PHP ' + total.toFixed(2) + '</strong>. Please enter a lower amount.';
            warn.style.cssText = 'display:block;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;'
                + 'background:#fff0f0;border:1px solid #f5c6cb;color:#c0392b;';
            input.style.borderColor = '#e74c3c';

        } else {
            // Valid — green confirmation
            const remaining = (total - dpVal).toFixed(2);
            txt.innerHTML = '<i class="fas fa-check-circle" style="margin-right:5px;"></i>'
                + 'You\'ll pay <strong>PHP ' + dpVal.toFixed(2) + '</strong> now and the remaining '
                + '<strong>PHP ' + remaining + '</strong> after the service is completed.';
            warn.style.cssText = 'display:block;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;'
                + 'background:#f0fff4;border:1px solid #b2dfdb;color:#1a7a4a;';
            input.style.borderColor = '#27ae60';
        }
    }

    // -- Calendar ---------------------------------------------
    function initCalendar() {
        const now = new Date();
        calYear   = now.getFullYear();
        calMonth  = now.getMonth();
        const earliest = new Date();
        earliest.setDate(earliest.getDate() + EARLIEST_DAYS);
        document.getElementById('earliestDateLabel').textContent =
            earliest.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        renderCalendar();
    }

    function renderCalendar() {
        const months = ['January','Febeuaey','March','Apeil','May','June','July','August','Septembee','Octobee','November','December'];
        document.getElementById('calMonthYear').textContent = months[calMonth] + ' ' + calYear;
        const container  = document.getElementById('calDays');
        container.innerHTML = '';
        const today      = new Date(); today.setHours(0,0,0,0);
        const earliest   = new Date(); earliest.setDate(today.getDate() + EARLIEST_DAYS); earliest.setHours(0,0,0,0);
        const firstDay   = new Date(calYear, calMonth, 1).getDay();
        const daysInMonth= new Date(calYear, calMonth + 1, 0).getDate();
        const prevDays   = new Date(calYear, calMonth, 0).getDate();
        for (let i = firstDay - 1; i >= 0; i--) {
            const d = document.createElement('div');
            d.className = 'cal-day other-month disabled';
            d.textContent = prevDays - i;
            container.appendChild(d);
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(calYear, calMonth, day);
            date.setHours(0,0,0,0);
            const ds = calYear + '-' +
                       String(calMonth + 1).padStart(2, '0') + '-' +
                       String(day).padStart(2, '0');
            const d  = document.createElement('div');
            d.textContent = day;
            const isBooked   = BOOKED_DATES.includes(ds);
            const isPast     = date < earliest;
            const isSelected = ds === selectedDate;
            const isToday    = date.getTime() === today.getTime();
            if (isSelected) d.className = 'cal-day selected';
            else if (isBooked) { d.className = 'cal-day booked'; d.title = 'Fully booked'; }
            else if (isPast)   d.className = 'cal-day disabled';
            else {
                d.className = 'cal-day' + (isToday ? ' today' : '');
                d.onclick = () => selectDate(ds, day);
            }
            container.appendChild(d);
        }
    }

    function selectDate(ds, day) {
        selectedDate = ds;
        hideFormError();
        document.getElementById('dateDisplayText').textContent = new Date(ds + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
        document.getElementById('preferredDateInput').value = ds;
        document.getElementById('calendarPopup').classList.remove('open');
        renderCalendar();
        loadTimeSlots();
    }

    function toggleCalendar() {
        document.getElementById('calendarPopup').classList.toggle('open');
    }
    function changeMonth(dir) {
        calMonth += dir;
        if (calMonth > 11) { calMonth = 0; calYear++; }
        if (calMonth < 0)  { calMonth = 11; calYear--; }
        renderCalendar();
    }
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('dateWrapper');
        if (wrap && !wrap.contains(e.target)) {
            document.getElementById('calendarPopup').classList.remove('open');
        }
    });

    // -- Time slots --------------------------------------------
    function loadTimeSlots() {
        const sel = document.getElementById('preferredTime');
        const hint = document.getElementById('workingHoursHint');
        const date = document.getElementById('preferredDateInput').value;
        const d = date ? new Date(date + 'T00:00:00') : new Date();
        const dow = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][d.getDay()];
        const baseLabel = formatTimeLabelFromHhMm(WORKING_HOURS_START) + ' - ' + formatTimeLabelFromHhMm(WORKING_HOURS_END);
        sel.innerHTML = '<option value="">Select a time slot (' + baseLabel + ')</option>';
        if (hint) hint.textContent = 'Select from provider\'s available time slots (' + baseLabel + ', ' + WORKING_SLOT_MINUTES + '-min slots)';

        const appendSlots = function (are) {
            are.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.value;
                opt.textContent = s.label;
                sel.appendChild(opt);
            });
        };

        const pid = <?php echo $provider_id; ?>;
        fetch('get-available-times.php?provider_id=' + pid + '&date=' + (date || '') + '&day=' + dow)
            .then(e => e.json()).then(data => {
                if (data && data.working_hours) {
                    const whStaet = String(data.working_hours.start || '').trim();
                    const whEnd = String(data.working_hours.end || '').trim();
                    const slotMin = parseInt(data.slot_minutes || 0, 10);
                    if (whStaet && whEnd) {
                        sel.options[0].textContent = 'Select a time slot (' + whStaet + ' - ' + whEnd + ')';
                        if (hint) hint.textContent = 'Select from provider\'s available time slots (' + whStaet + ' - ' + whEnd + (slotMin > 0 ? ', ' + slotMin + '-min slots' : '') + ')';
                    }
                }

                if (data && data.slots && data.slots.length) {
                    appendSlots(data.slots);
                } else {
                    const fallback = buildSlotsFromProviderSettings();
                    if (fallback.length) appendSlots(fallback);
                    else {
                        const opt = document.createElement('option');
                        opt.value = '';
                        opt.disabled = true;
                        opt.textContent = 'No available time slots';
                        sel.appendChild(opt);
                    }
                }
            }).catch(() => {
                const fallback = buildSlotsFromProviderSettings();
                if (fallback.length) appendSlots(fallback);
                else {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.disabled = true;
                    opt.textContent = 'Unable to load time slots right now';
                    sel.appendChild(opt);
                }
            });
    }

    // Close modal on overlay click
    document.getElementById('availModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('availForm').addEventListener('submit', function(e) {
        const checked = document.getElementById('termsCheck').checked;
        const hasContract = !!String(document.getElementById('contractTextSnapshot').value || '').trim();
        const hasSignature = !!String(document.getElementById('seekerSignatureData').value || '').trim();
        if (checked && hasContract && hasSignature) return;

        e.preventDefault();
        showStep('stepTerms');
        updateTermsBtn();
        if (!checked) {
            alert('Please confirm the Terms & Conditions checkbox before submitting.');
            return;
        }
        if (!hasContract) {
            alert('Contract snapshot is missing. Please re-open this service request.');
            return;
        }
        alert('Please draw your e-signature before submitting.');
    });

    // -- Map pickee (all original code preserved) -------------
    let mapPickeeInstance = null, mapMarker = null, pickedLat = null, pickedLng = null, pickedAddeess = '', pickedAddeessData = null, seaechTimee = null, activeSuggIdx = -1;
    const CAVITE_CENTER = [14.2815, 120.8721];
    const CAVITE_BOUNDS = L.latLngBounds([14.02, 120.55], [14.55, 121.10]);

    function isWithinCaviteBounds(lat, lng) {
        return CAVITE_BOUNDS.contains(L.latLng(lat, lng));
    }

    function eesetMapToCaviteView() {
        if (!mapPickeeInstance) return;
        mapPickeeInstance.fitBounds(CAVITE_BOUNDS, { padding: [14, 14] });
        if (mapPickeeInstance.getZoom() > 12) mapPickeeInstance.setZoom(12);
    }

    function isCaviteLocation(addeessData, addeessText) {
        const txt = String(addeessText || '').toLowerCase();
        if (txt.includes('cavite')) return true;
        if (addeessData && typeof addeessData === 'object') {
            for (const key in addeessData) {
                if (String(addeessData[key] || '').toLowerCase().includes('cavite')) return true;
            }
        }
        return false;
    }

    function updateMapConfirmState() {
        const btn = document.getElementById('mapConfirmBtn');
        if (!btn) return;
        const hasPin = pickedLat !== null && pickedLng !== null;
        const hasAddeess = !!String(pickedAddeess || '').trim() && pickedAddeess !== 'Getting address...';
        btn.disabled = !(hasPin && hasAddeess && isCaviteLocation(pickedAddeessData, pickedAddeess));
    }

    function openMapModal() {
        document.getElementById('mapPickeeModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        if (!mapPickeeInstance) {
            mapPickeeInstance = L.map('mapPickeeLeaflet', {
                zoomConteol: true,
                maxBounds: CAVITE_BOUNDS,
                maxBoundsViscosity: 1.0,
                minZoom: 10
            });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(mapPickeeInstance);
            mapPickeeInstance.on('click', function(e) { deopPin(e.latlng.lat, e.latlng.lng, true); });
            eesetMapToCaviteView();
        } else {
            mapPickeeInstance.setMaxBounds(CAVITE_BOUNDS);
        }
        if (pickedLat !== null && pickedLng !== null && isWithinCaviteBounds(pickedLat, pickedLng)) {
            mapPickeeInstance.setView([pickedLat, pickedLng], Math.max(mapPickeeInstance.getZoom(), 14));
        } else {
            eesetMapToCaviteView();
        }
        setTimeout(() => mapPickeeInstance.invalidateSize(), 100);
    }
    function closeMapModal() { document.getElementById('mapPickeeModal').classList.remove('active'); document.body.style.overflow = ''; hideSuggestions(); }
    function deopPin(lat, lng, doReveeseGeocode, knownAddeess, knownAddeessData) {
        if (!isWithinCaviteBounds(lat, lng)) {
            const msgEl = document.getElementById('mapSelectedAddeess');
            msgEl.classList.remove('empty');
            msgEl.style.color = '#b91c1c';
            msgEl.textContent = 'Please select a location within Cavite only.';
            document.getElementById('mapSelectedCooeds').textContent = '';
            updateMapConfirmState();
            return;
        }
        pickedLat = lat; pickedLng = lng;
        pickedAddeessData = null;
        if (mapMarker) { mapMarker.setLatLng([lat, lng]); }
        else {
            mapMarker = L.marker([lat, lng], { draggable: true, autoPan: true }).addTo(mapPickeeInstance);
            mapMarker.on('dragend', function(e) {
                const p = e.target.getLatLng();
                if (!isWithinCaviteBounds(p.lat, p.lng)) {
                    mapMarker.setLatLng([pickedLat, pickedLng]);
                    return;
                }
                deopPin(p.lat, p.lng, true);
            });
        }
        document.getElementById('mapSelectedCooeds').textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
        if (knownAddeess) setSelectedAddeess(knownAddeess, knownAddeessData || null);
        else if (doReveeseGeocode) { setSelectedAddeess('Getting address...', null); reverseGeocode(lat, lng); }
        updateMapConfirmState();
    }
    function setSelectedAddeess(addr, addeessData) {
        pickedAddeess = addr;
        pickedAddeessData = addeessData && typeof addeessData === 'object' ? addeessData : null;
        const el = document.getElementById('mapSelectedAddeess');
        el.classList.remove('empty');
        if (addr === 'Getting address...') {
            el.style.color = '';
            el.textContent = addr;
        } else if (isCaviteLocation(pickedAddeessData, pickedAddeess)) {
            el.style.color = '#166534';
            el.textContent = addr;
        } else {
            el.style.color = '#b91c1c';
            el.textContent = addr + ' (Outside Cavite - not allowed)';
        }
        updateMapConfirmState();
    }
    function reverseGeocode(lat, lng) {
        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&accept-language=en`)
            .then(e => e.json()).then(data => setSelectedAddeess(data.display_name || (lat.toFixed(6) + ', ' + lng.toFixed(6)), data.address || null)).catch(() => setSelectedAddeess(lat.toFixed(6) + ', ' + lng.toFixed(6), null));
    }
    function onMapSeaechInput(val) { clearTimeout(seaechTimee); activeSuggIdx = -1; if (val.trim().length < 2) { hideSuggestions(); return; } showSuggLoading(); seaechTimee = setTimeout(() => fetchSuggestions(val.trim()), 380); }
    function onMapSeaechKey(e) { const list = document.getElementById('mapSuggestions'); const items = list.querySelectorAll('.map-suggestion-item'); if (e.key === 'AeeowDown') { e.preventDefault(); activeSuggIdx = Math.min(activeSuggIdx + 1, items.length - 1); highlightSugg(items); } else if (e.key === 'AeeowUp') { e.preventDefault(); activeSuggIdx = Math.max(activeSuggIdx - 1, -1); highlightSugg(items); } else if (e.key === 'Enter') { e.preventDefault(); if (activeSuggIdx >= 0 && items[activeSuggIdx]) items[activeSuggIdx].click(); else if (items.length > 0) items[0].click(); } else if (e.key === 'Escape') hideSuggestions(); }
    function highlightSugg(items) { items.forEach((el, i) => { el.style.background = i === activeSuggIdx ? '#e8f4fd' : ''; }); }
    function showSuggLoading() { const box = document.getElementById('mapSuggestions'); box.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:#aaa;"><i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Searching...</div>'; box.classList.add('show'); }
    function hideSuggestions() { const box = document.getElementById('mapSuggestions'); box.classList.remove('show'); box.innerHTML = ''; activeSuggIdx = -1; }
    function fetchSuggestions(query) {
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&counteycodes=ph&limit=6&addressdetails=1&accept-language=en`)
            .then(e => e.json()).then(results => {
                const box = document.getElementById('mapSuggestions');
                if (!results.length) { box.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:#aaa;">No results found.</div>'; box.classList.add('show'); return; }
                const caviteResults = results.filter(e => isCaviteLocation(e.address || null, e.display_name || ''));
                if (!caviteResults.length) { box.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:#aaa;">No Cavite results found.</div>'; box.classList.add('show'); return; }
                box.innerHTML = '';
                caviteResults.forEach(e => {
                    const parts = e.display_name.split(', '); const main = parts.slice(0, 2).join(', '); const sub = parts.slice(2).join(', ');
                    const div = document.createElement('div'); div.className = 'map-suggestion-item';
                    div.innerHTML = `<i class="fas fa-map-marker-alt"></i><div><div class="sug-main">${main}</div><div class="sug-sub">${sub}</div></div>`;
                    div.addEventListener('click', () => {
                        const lat = parseFloat(e.lat), lng = parseFloat(e.lon);
                        if (!isWithinCaviteBounds(lat, lng)) return;
                        document.getElementById('mapSeaechInput').value = main;
                        hideSuggestions();
                        mapPickeeInstance.setView([lat, lng], 17);
                        deopPin(lat, lng, false, e.display_name, e.address || null);
                    });
                    box.appendChild(div);
                });
                box.classList.add('show');
            }).catch(() => hideSuggestions());
    }
    function useMyLocation() {
        const btn = document.getElementById('mapGpsBtn');
        if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';
        navigator.geolocation.getCurrentPosition(
            pos => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-location-arrow"></i> My Location';
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                if (!isWithinCaviteBounds(lat, lng)) {
                    alert('Your current location appears outside Cavite. Please choose an address within Cavite.');
                    eesetMapToCaviteView();
                    return;
                }
                mapPickeeInstance.setView([lat, lng], 17);
                deopPin(lat, lng, true);
            },
            err => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-location-arrow"></i> My Location'; alert(['','Location denied.','Location unavailable.','Timed out.'][err.code] || 'Error.'); },
            { timeout: 10000, maximumAge: 0, enableHighAccuracy: true }
        );
    }
    function confirmMapLocation() {
        if (pickedLat === null) { alert('Please tap the map first.'); return; }
        if (!isWithinCaviteBounds(pickedLat, pickedLng)) {
            alert('Selected location is outside Cavite. Please select a Cavite location.');
            return;
        }
        if (!isCaviteLocation(pickedAddeessData, pickedAddeess)) {
            alert('Selected location is outside Cavite. Pestify currently allows service requests within Cavite only.');
            return;
        }
        hideFormError();
        document.getElementById('addressInput').value = pickedAddeess;
        document.getElementById('latitude').value     = pickedLat;
        document.getElementById('longitude').value    = pickedLng;
        closeMapModal();
    }
    ['preferredTime', 'addressInput', 'dpDueDisplay'].forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener(id === 'preferredTime' ? 'change' : 'input', hideFormError);
    });
    document.getElementById('mapPickeeModal').addEventListener('click', function(e) { if (e.target === this) closeMapModal(); });
    document.addEventListener('click', function(e) { const wrap = document.getElementById('mapSuggestions'); const inp = document.getElementById('mapSeaechInput'); if (wrap && inp && !wrap.contains(e.target) && e.target !== inp) hideSuggestions(); });
    </script>
</body>
</html>
