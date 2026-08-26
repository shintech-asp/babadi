<?php
// provider-portal/portal-request-action.php
// Called from booking-management.php when owner/CRM accepts or rejects a request.
// Only 'owner' and 'crm' roles can act.
session_start();
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

date_default_timezone_set('Asia/Manila');


// ── Role check — only owner and CRM ──────────────────────────
if (!in_array($portal_role, ['owner', 'crm'])) {
    http_response_code(403);
    $_SESSION['portal_error'] = 'Access denied. Only Owner and CRM can accept or reject service requests.';
    header('Location: booking-management.php'); exit;
}

$booking_id = (int)($_POST['availed_id'] ?? $_GET['id'] ?? 0);
$action     = trim($_POST['action'] ?? $_GET['action'] ?? '');
$reason     = trim($_POST['rejection_reason'] ?? '');

if (!$booking_id || !in_array($action, ['accept', 'reject'])) {
    header('Location: booking-management.php'); exit;
}

// ── Fetch booking ─────────────────────────────────────────────
$bk = $db->prepare("SELECT a.*, p.company_name
                    FROM availed_services a
                    JOIN providers p ON p.id = a.provider_id
                    WHERE a.id = :id AND a.status = 'pending'");
$bk->execute([':id' => $booking_id]);
$booking = $bk->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['portal_error'] = 'Booking not found or already actioned.';
    header('Location: booking-management.php'); exit;
}

$staff_id    = $_SESSION['portal_staff_id'];
$seeker_uid  = (int)($booking['seeker_user_id'] ?? $booking['user_id'] ?? 0);
$provider_id = (int)$_SESSION['portal_provider_id'];

// ── ACCEPT ────────────────────────────────────────────────────
if ($action === 'accept') {

    // Update booking status to 'accepted'
    $db->prepare("UPDATE availed_services SET
                    status         = 'accepted',
                    actioned_by_staff = :staff,
                    actioned_at    = NOW(),
                    updated_at     = NOW()
                  WHERE id = :id")
       ->execute([':staff' => $staff_id, ':id' => $booking_id]);

    // Log status history
    try {
        $db->prepare("INSERT INTO availed_service_status_history
                        (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                      VALUES (:aid, 'pending', 'accepted', :by, 'provider', 'Request accepted by portal staff', NOW())")
           ->execute([':aid' => $booking_id, ':by' => $staff_id]);
    } catch (Exception $e) {}

    // ── Generate PayMongo payment link and notify seeker ──────
    $pay_now = ($booking['payment_method'] === 'downpayment')
               ? (float)$booking['downpayment_amount']
               : (float)$booking['total_amount'];

    $payment_url = '';
    $payment_error = '';
    $payment_type = ($booking['payment_method'] === 'downpayment') ? 'downpayment' : 'full';

    if (defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY && $pay_now > 0) {
        $centavos    = (int)round($pay_now * 100);
        $success_url = SITE_URL . "/payment-success.php?booking_id=$booking_id";
        $cancel_url  = SITE_URL . "/payment-cancel.php?booking_id=$booking_id";
        $label       = "Booking #{$booking_id}" . ($booking['service_name'] ? " — {$booking['service_name']}" : '');

        $payload = ['data' => ['attributes' => [
            'billing'              => [
                'name'  => $booking['full_name'],
                'email' => $booking['contact_number'] ? null : null, // seeker email not stored in availed_services
                'phone' => $booking['contact_number'],
            ],
            'send_email_receipt'   => false,
            'show_description'     => true,
            'show_line_items'      => true,
            'cancel_url'           => $cancel_url,
            'success_url'          => $success_url,
            'description'          => $label,
            'line_items'           => [[
                'currency'  => 'PHP',
                'amount'    => $centavos,
                'name'      => $booking['service_name'] ?: 'Pest Control Service',
                'quantity'  => 1,
            ]],
            'payment_method_types' => ['gcash', 'card', 'paymaya'],
            'metadata'             => [
                'booking_id'     => $booking_id,
                'provider_id'    => $provider_id,
                'payment_method' => $booking['payment_method'],
            ],
        ]]];

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
            $payment_url = $result['data']['attributes']['checkout_url'];
            $session_id  = $result['data']['id'];

            // Store payment transaction
            try {
                $db->prepare("INSERT INTO payment_transactions
                    (availed_service_id, seeker_id, provider_id, amount, payment_type,
                     payment_method, transaction_id, status, created_at)
                    VALUES (:aid,:sid,:pid,:amt,:ptype,'paymongo_checkout',:txn,'pending',NOW())")
                  ->execute([
                    ':aid'   => $booking_id,
                    ':sid'   => $seeker_uid,
                    ':pid'   => $provider_id,
                    ':amt'   => $pay_now,
                    ':ptype' => $payment_type,
                    ':txn'   => $session_id,
                ]);
            } catch (Exception $e) {}

            // Update booking to hold the payment URL so seeker can access it
            try {
                $db->prepare("UPDATE availed_services SET operations_notes = CONCAT(IFNULL(operations_notes,''), :n) WHERE id = :id")
                   ->execute([':n' => "\n[PAYMENT_URL: $payment_url]", ':id' => $booking_id]);
            } catch (Exception $e) {}

        } else {
            $err = $result['errors'][0]['detail'] ?? 'PayMongo error';
            error_log("PayMongo checkout for booking $booking_id: HTTP $http_code — $err");
            $payment_error = $err;
        }
    }

    // ── Notify seeker: accepted + payment link ────────────────
    if ($seeker_uid) {
        $notif_msg = "Great news! Your service request #{$booking_id}" .
                     ($booking['service_name'] ? " ({$booking['service_name']})" : '') .
                     " from {$booking['company_name']} has been <strong>accepted</strong>. ";

        if ($payment_url) {
            $notif_msg .= "Please complete your payment of ₱" . number_format($pay_now, 2) . " to confirm your booking.";
            $action_url = $payment_url; // direct to payment
        } else {
            $notif_msg .= "Please proceed to pay to confirm your booking.";
            $action_url = SITE_URL . "/payment-redirect.php?booking_id=$booking_id";
        }

        try {
            // seeker_notifications table
            $db->prepare("INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
                          VALUES (:uid, :aid, :pid, :sn, 'accepted', :msg, 0, NOW())")
               ->execute([
                ':uid' => $seeker_uid,
                ':aid' => $booking_id,
                ':pid' => $provider_id,
                ':sn'  => $booking['service_name'],
                ':msg' => strip_tags($notif_msg),
            ]);

            // Also notifications table (seeker's bell)
            $db->prepare("INSERT INTO notifications
                            (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
                          VALUES (:uid, 'payment', :title, :msg, :rid, 'availed_service', :url, 0, NOW())")
               ->execute([
                ':uid'   => $seeker_uid,
                ':title' => "Request Accepted — Pay Now",
                ':msg'   => strip_tags($notif_msg),
                ':rid'   => $booking_id,
                ':url'   => $payment_url ?: SITE_URL . "/payment-redirect.php?booking_id=$booking_id",
            ]);
        } catch (Exception $e) {
            error_log('Seeker accept notification error: ' . $e->getMessage());
        }
    }

    $_SESSION['portal_success'] = "Request #{$booking_id} accepted." .
        ($payment_url ? " Payment link sent to seeker." : " (Payment link generation failed — $payment_error)");

// ── REJECT ────────────────────────────────────────────────────
} elseif ($action === 'reject') {

    $db->prepare("UPDATE availed_services SET
                    status            = 'rejected',
                    rejection_reason  = :reason,
                    actioned_by_staff = :staff,
                    actioned_at       = NOW(),
                    updated_at        = NOW()
                  WHERE id = :id")
       ->execute([':reason' => $reason, ':staff' => $staff_id, ':id' => $booking_id]);

    // Log status history
    try {
        $db->prepare("INSERT INTO availed_service_status_history
                        (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                      VALUES (:aid, 'pending', 'rejected', :by, 'provider', :notes, NOW())")
           ->execute([':aid' => $booking_id, ':by' => $staff_id, ':notes' => 'Rejected: ' . $reason]);
    } catch (Exception $e) {}

    // Notify seeker: rejected
    if ($seeker_uid) {
        $rej_msg = "Unfortunately, your service request #{$booking_id}" .
                   ($booking['service_name'] ? " ({$booking['service_name']})" : '') .
                   " from {$booking['company_name']} was <strong>rejected</strong>." .
                   ($reason ? " Reason: {$reason}." : '') .
                   " You may submit a new request or contact the provider for more information.";
        try {
            $db->prepare("INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
                          VALUES (:uid, :aid, :pid, :sn, 'rejected', :msg, 0, NOW())")
               ->execute([':uid'=>$seeker_uid,':aid'=>$booking_id,':pid'=>$provider_id,':sn'=>$booking['service_name'],':msg'=>strip_tags($rej_msg)]);

            $db->prepare("INSERT INTO notifications
                            (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
                          VALUES (:uid, 'system', :title, :msg, :rid, 'availed_service', :url, 0, NOW())")
               ->execute([':uid'=>$seeker_uid,':title'=>'Service Request Rejected',':msg'=>strip_tags($rej_msg),':rid'=>$booking_id,':url'=>SITE_URL."/provider-details.php?id=$provider_id&rejected=1"]);
        } catch (Exception $e) {}
    }

    $_SESSION['portal_success'] = "Request #{$booking_id} has been rejected.";
}

header('Location: booking-management.php');
exit;
