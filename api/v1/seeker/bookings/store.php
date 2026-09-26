<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user = require_seeker();

$listing_id     = (int)req_inp('listing_id', 'Listing ID');
$preferred_date = req_inp('preferred_date', 'Preferred Date');
$preferred_time = req_inp('preferred_time', 'Preferred Time');
$full_name      = inp('full_name', null);
$contact_number = inp('contact_number', null);
$payment_method = inp('payment_method', 'full_payment');
$total_amount   = (float)inp('total_amount', 0);
$notes          = inp('notes', null);
$address        = inp('address', null);

// Fall back to seeker's profile for name and contact if not provided
if (empty($full_name)) {
    $full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}
if (empty($contact_number)) {
    $contact_number = $user['phone'] ?? '';
}

// Normalise payment_method: accept both 'full' (Flutter) and 'full_payment' (web)
if ($payment_method === 'full') {
    $payment_method = 'full_payment';
}

// Validate payment_method
if (!in_array($payment_method, ['full_payment', 'downpayment'], true)) {
    fail('payment_method must be full_payment or downpayment.');
}

// Validate preferred_date
$date_obj = DateTime::createFromFormat('Y-m-d', $preferred_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $preferred_date) {
    fail('preferred_date must be in Y-m-d format.', 400);
}
$today = new DateTime('today');
if ($date_obj < $today) {
    fail('preferred_date must be today or a future date.', 400);
}

// Validate preferred_time
$time_obj = DateTime::createFromFormat('H:i:s', $preferred_time);
if (!$time_obj || $time_obj->format('H:i:s') !== $preferred_time) {
    $time_obj = DateTime::createFromFormat('H:i', $preferred_time);
    if (!$time_obj || $time_obj->format('H:i') !== $preferred_time) {
        fail('preferred_time must be in H:i or H:i:s format.', 400);
    }
    $preferred_time = $time_obj->format('H:i:s');
}

// Fallback address from user profile
if ($address === null || $address === '') {
    $address = $user['address'] ?? '';
}

// Cavite-only restriction (mirrors server-side check in the web flow)
if (stripos((string)$address, 'cavite') === false) {
    fail('Service address must be in Cavite. Please provide a Cavite address.', 422);
}

$pdo = db();

// Fetch listing — must exist and be active. service_listings was merged into
// services (see CLAUDE.md's "Recent Work Log") — service_name AS title keeps
// every $listing['title'] read below working unchanged, and means a booking
// created here now stores a real services.id in service_id, resolvable by
// both web and mobile instead of only mobile.
$stmt = $pdo->prepare('SELECT *, service_name AS title FROM services WHERE id = :id AND status = :status LIMIT 1');
$stmt->execute([':id' => $listing_id, ':status' => 'active']);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    fail('Service listing not found or is not active.', 404);
}

// Fetch provider
$stmt = $pdo->prepare('SELECT * FROM providers WHERE id = :provider_id LIMIT 1');
$stmt->execute([':provider_id' => $listing['provider_id']]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    fail('Provider not found.', 404);
}

// Use listing price as total if not supplied (or if client sent 0)
if ($total_amount <= 0) {
    $total_amount = (float)$listing['price'];
}

if ($total_amount <= 0) {
    fail('Invalid total amount.', 400);
}

// Calculate downpayment / remaining
$dp_amount = 0.0;
$remaining = 0.0;
if ($payment_method === 'downpayment') {
    $dp_amount = round($total_amount * 0.5, 2);  // 50% downpayment
    $remaining = round($total_amount - $dp_amount, 2);
}

$pay_now = ($payment_method === 'downpayment') ? $dp_amount : $total_amount;

// Listings opted into the two-date inspection flow (see CLAUDE.md's
// "Recent Work Log"): the date the seeker picked here is treated as the
// requested Inspection Date, not a firm Working Date/price — the total
// above is only an estimate. No payment is collected until the seeker
// agrees to the technician's post-inspection report (mirrors the web's
// seeker/request-service.php + my-requests.php agree/request-changes flow).
$requires_inspection = !empty($listing['requires_inspection']);

// Insert booking — set both user_id and seeker_user_id for web/mobile compat
try {
    $pdo->prepare(
        'INSERT INTO availed_services
            (seeker_user_id, user_id, provider_id, service_id, service_name,
             full_name, contact_number,
             preferred_date, preferred_time, inspection_date, status, notes, address,
             payment_method, total_amount, downpayment_amount, remaining_amount,
             payment_status, paid_amount, created_at)
         VALUES
            (:uid, :uid2, :pid, :lid, :service_name,
             :full_name, :contact_number,
             :date, :time, :idate, \'pending\', :notes, :address,
             :payment_method, :total_amount, :dp_amount, :remaining,
             \'unpaid\', 0, NOW())'
    )->execute([
        ':uid'            => $user['id'],
        ':uid2'           => $user['id'],
        ':pid'            => $listing['provider_id'],
        ':lid'            => $listing['id'],
        ':service_name'   => $listing['title'],
        ':full_name'      => $full_name,
        ':contact_number' => $contact_number,
        ':date'           => $preferred_date,
        ':time'           => $preferred_time,
        ':idate'          => $requires_inspection ? $preferred_date : null,
        ':notes'          => $notes,
        ':address'        => $address,
        ':payment_method' => $payment_method,
        ':total_amount'   => $total_amount,
        ':dp_amount'      => $dp_amount,
        ':remaining'      => $remaining,
    ]);
    $booking_id = (int)$pdo->lastInsertId();
} catch (Exception $e) {
    error_log('[store.php] Booking insert failed: ' . $e->getMessage());
    fail('Booking could not be saved. Please try again.', 500);
}

if ($requires_inspection) {
    // No payment yet — the provider must accept, then have a technician
    // submit an inspection report before there's a final price to pay.
    ok([
        'data' => [
            'id'                  => $booking_id,
            'status'              => 'pending',
            'service_name'        => $listing['title'],
            'checkout_url'        => null,
            'requires_inspection' => true,
        ],
    ], 201);
}

// Create PayMongo checkout session
$centavos    = (int)round($pay_now * 100);
$success_url = SITE_URL . "/payment-success.php?booking_id=$booking_id";
$cancel_url  = SITE_URL . "/payment-cancel.php?booking_id=$booking_id";
$label       = "Booking #$booking_id — " . $listing['title'];

$billing = ['name' => $full_name];
if (!empty($user['email'])) $billing['email'] = $user['email'];
if ($contact_number)        $billing['phone']  = $contact_number;

$pm_payload = ['data' => ['attributes' => [
    'billing'              => $billing,
    'send_email_receipt'   => false,
    'show_description'     => true,
    'show_line_items'      => true,
    'cancel_url'           => $cancel_url,
    'success_url'          => $success_url,
    'description'          => $label,
    'line_items'           => [[
        'currency' => 'PHP',
        'amount'   => $centavos,
        'name'     => $listing['title'] ?: 'Pest Control Service',
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya'],
    'metadata'             => [
        'booking_id'     => $booking_id,
        'provider_id'    => (int)$listing['provider_id'],
        'payment_method' => $payment_method,
    ],
]]];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($pm_payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$pm_response  = curl_exec($ch);
$pm_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$pm_result = json_decode($pm_response, true);

if ($pm_http_code !== 200 || empty($pm_result['data']['attributes']['checkout_url'])) {
    $pm_err = $pm_result['errors'][0]['detail']
           ?? $pm_result['errors'][0]['code']
           ?? 'PayMongo unavailable';
    error_log("[store.php] PayMongo error for Booking #$booking_id HTTP $pm_http_code: $pm_err | $pm_response");
    // Booking is saved — Flutter should show a warning and allow retry
    ok([
        'data' => [
            'id'             => $booking_id,
            'status'         => 'pending',
            'service_name'   => $listing['title'],
            'checkout_url'   => null,
            'paymongo_error' => $pm_err,
        ],
    ], 201);
}

$checkout_url = $pm_result['data']['attributes']['checkout_url'];
$session_id   = $pm_result['data']['id'];

// Log payment transaction
try {
    $pdo->prepare(
        'INSERT INTO payment_transactions
            (availed_service_id, seeker_id, provider_id, amount, payment_type,
             payment_method, transaction_id, status, created_at)
         VALUES (:aid, :sid, :pid, :amt, :ptype, \'paymongo_checkout\', :txn, \'pending\', NOW())'
    )->execute([
        ':aid'   => $booking_id,
        ':sid'   => $user['id'],
        ':pid'   => (int)$listing['provider_id'],
        ':amt'   => $pay_now,
        ':ptype' => ($payment_method === 'downpayment') ? 'downpayment' : 'full',
        ':txn'   => $session_id,
    ]);
} catch (Exception $e) { /* non-fatal */ }

ok([
    'data' => [
        'id'           => $booking_id,
        'status'       => 'pending',
        'service_name' => $listing['title'],
        'checkout_url' => $checkout_url,
    ],
], 201);
