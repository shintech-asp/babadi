<?php
chdir(dirname(__DIR__));
// request-service-process.php — in Pestify root
// Saves service request as 'pending', pings provider notification.
// Payment is NOT collected here — only after provider accepts.
session_start();
require_once 'config/config.php';
require_once appPath('includes/availed_booking_helper.php');

$loginUrl = appUrl('login.php');
$providerDetailsUrl = appUrl('provider-details.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $loginUrl); exit;
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

date_default_timezone_set('Asia/Manila');

function requestServiceProviderSetting(PDO $db, int $providerId, string $key, string $default = '0'): string
{
    try {
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => "provider_{$providerId}_{$key}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? trim((string)$row['setting_value']) : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// ── Collect POST ──────────────────────────────────────────────
$user_id        = (int)$_SESSION['user_id'];
$provider_id    = (int)($_POST['provider_id']          ?? 0);
$service_id     = (int)($_POST['service_id']           ?? 0) ?: null;
$service_name   = trim($_POST['service_name']          ?? '');
$full_name      = trim($_POST['full_name']             ?? '');
$contact_number = trim($_POST['contact_number']        ?? '');
$email          = trim($_POST['email']                 ?? $_SESSION['email'] ?? '');
$preferred_date = trim($_POST['preferred_date']        ?? '');
$preferred_time = trim($_POST['preferred_time']        ?? '');
$total_amount   = (float)($_POST['total_amount']       ?? 0);
$payment_method = trim($_POST['payment_method']        ?? 'full_payment');
$dp_amount      = (float)($_POST['downpayment_amount'] ?? 0);
$address        = trim($_POST['address']               ?? '');
$notes          = trim($_POST['notes']                 ?? '');
$contract_text  = trim($_POST['contract_text_snapshot'] ?? '');
$agreement_ack  = (int)($_POST['service_agreement_ack'] ?? 0);
$seeker_signature = trim($_POST['seeker_signature'] ?? '');

// ── Validation ────────────────────────────────────────────────
if (!$provider_id || !$full_name || !$preferred_date || !$preferred_time || !$address || $total_amount <= 0) {
    $_SESSION['avail_error'] = 'Please fill in all required fields.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}
if (stripos($address, 'cavite') === false) {
    $_SESSION['avail_error'] = 'Service requests are limited to Cavite locations only.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}
if ($agreement_ack !== 1) {
    $_SESSION['avail_error'] = 'Please confirm that you understand and agree to the service agreement and contract.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}
if ($contract_text === '') {
    $_SESSION['avail_error'] = 'Contract agreement is missing. Please review the contract and try again.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}
if ($seeker_signature === '' || strpos($seeker_signature, 'data:image/png;base64,') !== 0) {
    $_SESSION['avail_error'] = 'Please provide your seeker signature before submitting.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}

$remaining = ($payment_method === 'downpayment') ? ($total_amount - $dp_amount) : 0;

try {
    $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS contract_text_snapshot LONGTEXT DEFAULT NULL");
    $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_agreement_confirmed TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_signature LONGTEXT DEFAULT NULL");
    $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_signed_at DATETIME DEFAULT NULL");
} catch (Exception $e) {}

ensureAvailedRescheduleColumns($db);

// ── Get provider's user_id for notification ───────────────────
$prov = $db->prepare("SELECT user_id, company_name FROM providers WHERE id = :id");
$prov->execute([':id' => $provider_id]);
$prov = $prov->fetch(PDO::FETCH_ASSOC);
$provider_user_id  = (int)($prov['user_id'] ?? 0);
$company_name      = $prov['company_name'] ?? 'Provider';
$auto_accept_on_request = requestServiceProviderSetting($db, $provider_id, 'auto_accept_on_request', '0') === '1';

// ── Insert booking as 'pending' — NO payment ─────────────────
// availed_services.provider_id = providers.id  (NOT providers.user_id)
// availed_services.user_id     = seeker's users.id (legacy column)
// availed_services.seeker_user_id = seeker's users.id (clean column)
try {
    $db->prepare("
        INSERT INTO availed_services
            (provider_id, user_id, seeker_user_id,
             service_id, service_name, full_name, contact_number,
             preferred_date, preferred_time, address, notes,
             contract_text_snapshot, seeker_agreement_confirmed, seeker_signature, seeker_signed_at,
             status, payment_method, total_amount,
             downpayment_amount, remaining_amount,
             payment_status, paid_amount, is_read, created_at)
        VALUES
            (:pid, :uid, :uid2,
             :sid, :sn, :fn, :cn,
             :pd, :pt, :addr, :notes,
             :cts, :ack, :sig, NOW(),
             'pending', :pm, :ta,
             :dpa, :ra,
             'unpaid', 0, 0, NOW())
    ")->execute([
        ':pid'  => $provider_id,        // providers.id  ← the correct FK
        ':uid'  => $user_id,            // seeker's users.id (legacy user_id column)
        ':uid2' => $user_id,            // seeker's users.id (seeker_user_id column)
        ':sid'  => $service_id,
        ':sn'   => $service_name,
        ':fn'   => $full_name,
        ':cn'   => $contact_number,
        ':pd'   => $preferred_date,
        ':pt'   => $preferred_time,
        ':addr' => $address,
        ':notes'=> $notes,
        ':cts'  => $contract_text,
        ':ack'  => 1,
        ':sig'  => $seeker_signature,
        ':pm'   => $payment_method,
        ':ta'   => $total_amount,
        ':dpa'  => $dp_amount,
        ':ra'   => $remaining,
    ]);
    $booking_id = (int)$db->lastInsertId();
} catch (Exception $e) {
    error_log('Request insert error: ' . $e->getMessage());
    $_SESSION['avail_error'] = 'Could not save your request. Please try again.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}

// ── Notify provider (via notifications table) ─────────────────
// This pings the provider's notification bell in the portal
$notif_title = "New Service Request #$booking_id";
$notif_msg   = htmlspecialchars($full_name) . " has requested your service" .
               ($service_name ? " ({$service_name})" : '') .
               " for " . date('M j, Y', strtotime($preferred_date)) .
               ". Please review and accept or reject.";

try {
    // Notification for the provider user (shows in their seeker-side notif bell if they're a user)
    $db->prepare("
        INSERT INTO notifications
            (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
        VALUES
            (:uid, 'request', :title, :msg, :rid, 'availed_service',
             '/pestify/provider-portal/booking-management.php', 0, NOW())
    ")->execute([
        ':uid'   => $provider_user_id,
        ':title' => $notif_title,
        ':msg'   => $notif_msg,
        ':rid'   => $booking_id,
    ]);

    // Also mark availed_services.is_read = 0 so provider sees the bell badge
    $db->prepare("UPDATE availed_services SET is_read = 0 WHERE id = :id")
       ->execute([':id' => $booking_id]);

} catch (Exception $e) {
    // Non-fatal — notification failed but booking is saved
    error_log('Notification insert error: ' . $e->getMessage());
}

// ── Log status history ────────────────────────────────────────
try {
    $db->prepare("
        INSERT INTO availed_service_status_history
            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
        VALUES (:aid, NULL, 'pending', :uid, 'seeker', 'Service request submitted by seeker', NOW())
    ")->execute([':aid' => $booking_id, ':uid' => $user_id]);
} catch (Exception $e) { /* non-fatal */ }

if ($auto_accept_on_request) {
    $accepted = acceptAvailedBooking(
        $db,
        $booking_id,
        $provider_id,
        $provider_user_id ?: null,
        'system',
        'Automatically accepted because the provider enabled auto-accept for new requests.'
    );

    if ($accepted['ok']) {
        header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id . '&requested=1&auto_accepted=1');
        exit;
    }
}

// ── Redirect back to provider page with success message ───────
header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id . '&requested=1');
exit;
