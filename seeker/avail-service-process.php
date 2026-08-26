<?php
chdir(dirname(__DIR__));
// avail-service-process.php  — in Pestify root
session_start();
require_once 'config/config.php';

$loginUrl = appUrl('login.php');
$providerDetailsUrl = appUrl('provider-details.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $loginUrl); exit;
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

date_default_timezone_set('Asia/Manila');

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

// Validation
if (!$provider_id || !$full_name || !$preferred_date || !$preferred_time || !$address || $total_amount <= 0) {
    $_SESSION['avail_error'] = 'Please fill in all required fields.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}

$pay_now   = ($payment_method === 'downpayment') ? $dp_amount : $total_amount;
$remaining = ($payment_method === 'downpayment') ? ($total_amount - $dp_amount) : 0;

if ($pay_now <= 0) {
    $_SESSION['avail_error'] = 'Invalid payment amount.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}

// ── Insert pending booking ────────────────────────────────────
try {
    $db->prepare("
        INSERT INTO availed_services
            (provider_id, user_id, service_id, service_name, full_name, contact_number,
             preferred_date, preferred_time, address, notes,
             status, payment_method, total_amount, downpayment_amount, remaining_amount,
             payment_status, paid_amount, created_at)
        VALUES
            (:pid,:uid,:sid,:sn,:fn,:cn,
             :pd,:pt,:addr,:notes,
             'pending',:pm,:ta,:dpa,:ra,
             'unpaid',0,NOW())
    ")->execute([
        ':pid'=>$provider_id, ':uid'=>$user_id, ':sid'=>$service_id,
        ':sn'=>$service_name,  ':fn'=>$full_name, ':cn'=>$contact_number,
        ':pd'=>$preferred_date,':pt'=>$preferred_time,
        ':addr'=>$address,     ':notes'=>$notes,
        ':pm'=>$payment_method,':ta'=>$total_amount,
        ':dpa'=>$dp_amount,    ':ra'=>$remaining,
    ]);
    $booking_id = (int)$db->lastInsertId();
} catch (Exception $e) {
    error_log('Booking insert: ' . $e->getMessage());
    $_SESSION['avail_error'] = 'Booking could not be saved. Please try again.';
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id); exit;
}

// ── PayMongo Checkout Session (supports redirect URLs) ────────
if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY) {
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id . '&availed=1'); exit;
}

$centavos    = (int)round($pay_now * 100);
$success_url = SITE_URL . "/payment-success.php?booking_id=$booking_id";
$cancel_url  = SITE_URL . "/payment-cancel.php?booking_id=$booking_id";
$label       = "Booking #$booking_id" . ($service_name ? " — $service_name" : '');

// Build billing — email is required by checkout sessions
$billing = ['name' => $full_name];
if ($email) $billing['email'] = $email;
if ($contact_number) $billing['phone'] = $contact_number;

$payload = ['data' => ['attributes' => [
    'billing'              => $billing,
    'send_email_receipt'   => false,
    'show_description'     => true,
    'show_line_items'      => true,
    'cancel_url'           => $cancel_url,
    'success_url'          => $success_url,
    'description'          => $label,
    'line_items'           => [[
        'currency'    => 'PHP',
        'amount'      => $centavos,
        'name'        => $service_name ?: 'Pest Control Service',
        'quantity'    => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya'],
    'metadata'             => [
        'booking_id'     => $booking_id,
        'provider_id'    => $provider_id,
        'payment_method' => $payment_method,
    ],
]]];

// ── Use checkout_sessions endpoint (NOT /links) ───────────────
$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['data']['attributes']['checkout_url'])) {
    $checkout_url  = $result['data']['attributes']['checkout_url'];
    $session_id    = $result['data']['id'];  // cs_xxxxxxxx

    // Log transaction — store checkout session ID so payment-success.php can verify
    try {
        $db->prepare("
            INSERT INTO payment_transactions
                (availed_service_id, seeker_id, provider_id, amount, payment_type,
                 payment_method, transaction_id, status, created_at)
            VALUES (:aid,:sid,:pid,:amt,:ptype,'paymongo_checkout',:txn,'pending',NOW())
        ")->execute([
            ':aid'   => $booking_id,
            ':sid'   => $user_id,
            ':pid'   => $provider_id,
            ':amt'   => $pay_now,
            ':ptype' => ($payment_method === 'downpayment') ? 'downpayment' : 'full',
            ':txn'   => $session_id,
        ]);
    } catch (Exception $e) { /* non-fatal */ }

    // Redirect user to PayMongo hosted checkout
    header("Location: $checkout_url"); exit;

} else {
    $err = $result['errors'][0]['detail'] ?? ($result['errors'][0]['code'] ?? 'PayMongo unavailable');
    error_log("PayMongo booking $booking_id HTTP $http_code: $err | $response");

    $_SESSION['avail_warning'] = "Booking saved but online payment could not be initiated ($err). Please pay the provider directly.";
    header('Location: ' . $providerDetailsUrl . '?id=' . $provider_id . '&availed=1'); exit;
}
