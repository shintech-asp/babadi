<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_crm    = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
$can_action = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all'); // Accept / Reject privilege
if (!$can_crm) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

/* ── PayMongo helper (Checkout Session with redirect URLs) ───────────────── */
function createPaymongoLink(float $amount, string $description, int $bookingId, string $paymongoSecretKey): array {
    $amountCentavos = (int) round($amount * 100);

    $base       = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
    $successUrl = $base . '/pestify/payment-success.php?booking_id=' . $bookingId;
    $cancelUrl  = $base . '/pestify/payment-cancel.php?booking_id='  . $bookingId;

    $payload = json_encode([
        'data' => [
            'attributes' => [
                'send_email_receipt'   => false,
                'show_description'     => true,
                'show_line_items'      => true,
                'line_items'           => [[
                    'currency'  => 'PHP',
                    'amount'    => $amountCentavos,
                    'name'      => $description,
                    'quantity'  => 1,
                ]],
                'payment_method_types' => ['gcash', 'paymaya', 'card'],
                'description'          => $description,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'metadata'             => ['booking_id' => (string)$bookingId],
                'reference_number'     => 'BOOKING-' . $bookingId,
            ]
        ]
    ]);

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($paymongoSecretKey . ':'),
        ],
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);

    if ($status === 200 && isset($data['data']['attributes']['checkout_url'])) {
        return [
            'success'      => true,
            'checkout_url' => $data['data']['attributes']['checkout_url'],
            'link_id'      => $data['data']['id'],
            'reference_id' => 'BOOKING-' . $bookingId,
        ];
    }
    return ['success' => false, 'error' => $data['errors'][0]['detail'] ?? 'Unknown error'];
}

/* ── POST handlers ───────────────────────────────────────────────────────── */
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // C3: CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh the page.';
    }
    // C2 fix: free tier cannot mutate bookings; gate all write actions server-side
    elseif (!$tier_is_paid) {
        $error = 'Booking management requires a Pro subscription. Upgrade to process bookings.';
    }

    $action = $_POST['action'] ?? '';
    $bid    = (int)($_POST['booking_id'] ?? 0);

    /* ── ACCEPT booking ─────────────────────────────────── */
    if ($action === 'accept_booking' && $can_action && $tier_is_paid) {
        $booking = safeRow($db,
            "SELECT * FROM availed_services WHERE id=:id AND provider_id=:p AND status='pending'",
            [':id'=>$bid,':p'=>$pid]
        );

        if ($booking) {
            try {
                $db->beginTransaction();

                /* 1. Update booking status → waiting_provider_confirmation
                      Generate provider_control_number if not already set */
                $provCN = $booking['provider_control_number'];
                if (empty($provCN)) {
                    $provCN = 'PCP-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                }
                $db->prepare(
                    "UPDATE availed_services
                     SET status='waiting_provider_confirmation',
                         provider_control_number=:pcn,
                         actioned_by_staff=:staff,
                         actioned_at=NOW(),
                         updated_at=NOW()
                     WHERE id=:id AND provider_id=:p"
                )->execute([
                    ':pcn'   => $provCN,
                    ':staff' => $portal_staff_id ?? null,
                    ':id'    => $bid,
                    ':p'     => $pid,
                ]);

                /* 2. Log status history */
                $db->prepare(
                    "INSERT INTO availed_service_status_history
                        (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                     VALUES (:aid,'pending','waiting_provider_confirmation',:by,:role,:notes,NOW())"
                )->execute([
                    ':aid'   => $bid,
                    ':by'    => $portal_staff_id ?? $pid,
                    ':role'  => $portal_role ?? 'staff',
                    ':notes' => 'Request accepted by ' . ($portal_role === 'owner' ? 'owner' : 'CRM staff'),
                ]);

                /* 3. Generate PayMongo payment link */
                $payAmount = ($booking['payment_method'] === 'downpayment')
                    ? (float)$booking['downpayment_amount']
                    : (float)$booking['total_amount'];
                // Ensure total_amount is used when full_payment (stored value) is selected
                if (in_array($booking['payment_method'], ['full_payment', 'full']) && $payAmount <= 0) {
                    $payAmount = (float)$booking['total_amount'];
                }

                                $payLink = null;
                $pmDesc = "Payment for {$booking['service_name']} - Booking #$bid";
                $pmResult = createPaymongoLink($payAmount, $pmDesc, $bid, PAYMONGO_SECRET_KEY);

                if ($pmResult['success']) {
                    $payLink = $pmResult['checkout_url'];
                    /* 4a. Save payment transaction record */
                    $db->prepare(
                        "INSERT INTO payment_transactions
                            (availed_service_id, seeker_id, provider_id, amount, payment_type, payment_method, transaction_id, status, created_at, updated_at)
                         VALUES (:aid,:seeker,:prov,:amt,:ptype,'paymongo_checkout',:tid,'pending',NOW(),NOW())"
                    )->execute([
                        ':aid'    => $bid,
                        ':seeker' => $booking['seeker_user_id'],
                        ':prov'   => $pid,
                        ':amt'    => $payAmount,
                        ':ptype'  => $booking['payment_method'] === 'downpayment' ? 'downpayment' : 'full',
                        ':tid'    => $pmResult['link_id'],
                    ]);
                }
                /* 5. Notify seeker via seeker_notifications */
                $notifMsg = $payLink
                    ? "Your service request for {$booking['service_name']} has been accepted! Please complete your payment to confirm your booking."
                    : "Your service request for {$booking['service_name']} has been accepted by the provider!";

                $db->prepare(
                    "INSERT INTO seeker_notifications
                        (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
                     VALUES (:uid,:aid,:prov,:svc,'accepted',:msg,0,NOW())"
                )->execute([
                    ':uid'  => $booking['seeker_user_id'],
                    ':aid'  => $bid,
                    ':prov' => $pid,
                    ':svc'  => $booking['service_name'],
                    ':msg'  => $notifMsg,
                ]);

                /* 6. Also notify via provider-side notifications table if exists */
                try {
                    $db->prepare(
                        "INSERT INTO notifications
                            (user_id, type, title, message, related_id, related_type, is_read, action_url, created_at)
                         VALUES (:uid,'booking_accepted','Service Request Accepted',:msg,:rid,'availed_service',0,:url,NOW())"
                    )->execute([
                        ':uid' => $booking['seeker_user_id'],
                        ':msg' => $notifMsg,
                        ':rid' => $bid,
                        ':url' => $payLink ?? '/pestify/my-bookings.php',
                    ]);
                } catch(Exception $e) { /* notifications table optional */ }

                $db->commit();

                $success = $payLink
                    ? "Booking #$bid accepted. Payment link sent to seeker. Provider Control Number: <strong>$provCN</strong> — give this to your technician."
                    : "Booking #$bid accepted. Provider Control Number: <strong>$provCN</strong> — give this to your technician.";

            } catch(Exception $e) {
                $db->rollBack();
                $error = "Failed to accept booking: " . $e->getMessage();
            }
        } else {
            $error = "Booking not found or is no longer in Pending status.";
        }
    }

    /* ── REJECT booking ─────────────────────────────────── */
    elseif ($action === 'reject_booking' && $can_action && $tier_is_paid) {
        $reason  = trim($_POST['rejection_reason'] ?? '');
        $booking = safeRow($db,
            "SELECT * FROM availed_services WHERE id=:id AND provider_id=:p AND status='pending'",
            [':id'=>$bid,':p'=>$pid]
        );

        if ($booking) {
            if (!$reason) { $error = "Please provide a rejection reason."; }
            else {
                try {
                    $db->beginTransaction();

                    /* 1. Update status → rejected */
                    $db->prepare(
                        "UPDATE availed_services
                         SET status='rejected',
                             rejection_reason=:r,
                             actioned_by_staff=:staff,
                             actioned_at=NOW(),
                             updated_at=NOW()
                         WHERE id=:id AND provider_id=:p"
                    )->execute([
                        ':r'     => $reason,
                        ':staff' => $portal_staff_id ?? null,
                        ':id'    => $bid,
                        ':p'     => $pid,
                    ]);

                    /* 2. Log status history */
                    $db->prepare(
                        "INSERT INTO availed_service_status_history
                            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                         VALUES (:aid,'pending','rejected',:by,:role,:notes,NOW())"
                    )->execute([
                        ':aid'   => $bid,
                        ':by'    => $portal_staff_id ?? $pid,
                        ':role'  => $portal_role ?? 'staff',
                        ':notes' => "Rejected: $reason",
                    ]);

                    /* 3. Notify seeker */
                    $notifMsg = "Your service request for {$booking['service_name']} was not accepted. Reason: $reason. You may request again or contact the provider.";

                    $db->prepare(
                        "INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
                         VALUES (:uid,:aid,:prov,:svc,'cancelled',:msg,0,NOW())"
                    )->execute([
                        ':uid'  => $booking['seeker_user_id'],
                        ':aid'  => $bid,
                        ':prov' => $pid,
                        ':svc'  => $booking['service_name'],
                        ':msg'  => $notifMsg,
                    ]);

                    try {
                        $db->prepare(
                            "INSERT INTO notifications
                                (user_id, type, title, message, related_id, related_type, is_read, action_url, created_at)
                             VALUES (:uid,'booking_rejected','Service Request Not Accepted',:msg,:rid,'availed_service',0,'/pestify/providers.php',NOW())"
                        )->execute([
                            ':uid' => $booking['seeker_user_id'],
                            ':msg' => $notifMsg,
                            ':rid' => $bid,
                        ]);
                    } catch(Exception $e) {}

                    $db->commit();
                    $success = "Booking #$bid has been rejected and the seeker has been notified.";

                } catch(Exception $e) {
                    $db->rollBack();
                    $error = "Failed to reject booking: " . $e->getMessage();
                }
            }
        } else {
            $error = "Booking not found or is no longer in Pending status.";
        }
    }

    /* ── Provider submits their control number ───────────── */
    elseif ($action === 'verify_control_numbers') {
        $entered_provider = trim($_POST['entered_provider_cn'] ?? '');

        if (!$bid) {
            $error = "Invalid booking reference.";
        } elseif (!$entered_provider) {
            $error = "Please enter the provider control number.";
        } else {
            $booking = safeRow($db,
                "SELECT id, status, control_number, provider_control_number,
                        seeker_verified_at, provider_verified_at, dual_verified_at,
                        seeker_user_id, service_name, full_name
                 FROM availed_services
                 WHERE id=:id AND provider_id=:p",
                [':id' => $bid, ':p' => $pid]
            );

            if (!$booking) {
                $error = "Booking #$bid not found.";
            } elseif ($booking['dual_verified_at']) {
                $error = "Booking #$bid is already verified and in progress.";
            } elseif (empty($booking['provider_control_number'])) {
                $error = "No provider control number assigned to Booking #$bid yet.";
            } elseif (!hash_equals($booking['provider_control_number'], $entered_provider)) {
                $error = "Incorrect provider control number. Please check and try again.";
            } else {
                // Provider CN is correct — stamp provider_verified_at
                $db->prepare(
                    "UPDATE availed_services SET provider_verified_at=NOW(), updated_at=NOW()
                     WHERE id=:id AND provider_id=:p"
                )->execute([':id' => $bid, ':p' => $pid]);

                // Check if seeker has already verified their side
                if (!empty($booking['seeker_verified_at'])) {
                    // Both sides done — unlock service
                    try {
                        $db->beginTransaction();
                        $db->prepare(
                            "UPDATE availed_services
                             SET status='in_progress',
                                 dual_verified_at=NOW(),
                                 service_started_at=NOW(),
                                 updated_at=NOW()
                             WHERE id=:id AND provider_id=:p"
                        )->execute([':id' => $bid, ':p' => $pid]);

                        $db->prepare(
                            "INSERT INTO seeker_notifications
                                (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
                             VALUES (:uid,:aid,:prov,:svc,'in_progress',:msg,0,NOW())"
                        )->execute([
                            ':uid'  => $booking['seeker_user_id'],
                            ':aid'  => $bid,
                            ':prov' => $pid,
                            ':svc'  => $booking['service_name'],
                            ':msg'  => "Your service for {$booking['service_name']} (Booking #{$bid}) has been verified and is now in progress!",
                        ]);

                        $db->commit();
                        $success = "✓ Both sides verified for Booking #$bid. Service is now <strong>In Progress</strong>.";
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = "Verification failed: " . $e->getMessage();
                    }
                } else {
                    // Provider done, waiting on seeker
                    $success = "✓ Provider control number accepted for Booking #$bid. Waiting for the client to verify their code.";
                }
            }
        }
    }

    /* ── Update status ──────────────────────────────────── */
    elseif ($action === 'update_status') {
        $new_status = $_POST['new_status'] ?? '';
        $allowed = ['waiting_provider_confirmation','preparing','on_the_way','in_progress','completed','cancelled'];
        if (in_array($new_status, $allowed)) {
            $extra = '';
            $params = [':s'=>$new_status,':id'=>$bid,':p'=>$pid];
            if ($new_status === 'in_progress')  $extra = ", service_started_at=NOW()";
            if ($new_status === 'completed')    $extra = ", service_ended_at=NOW(), provider_confirmed_at=NOW()";
            $db->prepare("UPDATE availed_services SET status=:s$extra, updated_at=NOW() WHERE id=:id AND provider_id=:p")
               ->execute($params);
            $success = "Booking #$bid status updated to " . ucwords(str_replace('_',' ',$new_status)) . ".";
        }
    }

    /* ── Add operations note ────────────────────────────── */
    elseif ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note) {
            $db->prepare("UPDATE availed_services SET operations_notes=CONCAT(IFNULL(operations_notes,''), :n) WHERE id=:id AND provider_id=:p")
               ->execute([':n' => "\n[".date('M j H:i')."] ".$note, ':id'=>$bid, ':p'=>$pid]);
            $success = "Note added to Booking #$bid.";
        }
    }

    /* ── Backfill missing provider control numbers ───────── */
    elseif ($action === 'backfill_provider_cn') {
        $rows = safeAll($db,
            "SELECT id FROM availed_services
             WHERE provider_id=:p
               AND (provider_control_number IS NULL OR provider_control_number='')
               AND status NOT IN('pending','cancelled','rejected')",
            [':p' => $pid]
        );
        $count = 0;
        foreach ($rows as $r) {
            $cn = 'PCP-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $db->prepare("UPDATE availed_services SET provider_control_number=:cn, updated_at=NOW() WHERE id=:id AND provider_id=:p")
               ->execute([':cn' => $cn, ':id' => $r['id'], ':p' => $pid]);
            $count++;
        }
        $success = $count > 0
            ? "Provider control numbers generated for $count booking(s)."
            : "All active bookings already have a provider control number.";
    }
}


/* ── Filters & data ─────────────────────────────────────────────────────── */
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$where  = "provider_id=:p";
$params = [':p'=>$pid];
if ($status_filter === 'active') {
    $where .= " AND status IN('waiting_provider_confirmation','preparing','on_the_way','in_progress')";
} elseif ($status_filter === 'cancelled') {
    $where .= " AND status IN('cancelled','rejected')";
} elseif ($status_filter) {
    $where .= " AND status=:s"; $params[':s']=$status_filter;
}
if ($search) { $where .= " AND (full_name LIKE :q OR contact_number LIKE :q OR service_name LIKE :q)"; $params[':q']="%$search%"; }

$bookings = safeAll($db,"SELECT * FROM availed_services WHERE $where ORDER BY created_at DESC",$params);

$counts = [
    'all'       => safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p",[':p'=>$pid]),
    'pending'   => safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='pending'",[':p'=>$pid]),
    'active'    => safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('waiting_provider_confirmation','preparing','on_the_way','in_progress')",[':p'=>$pid]),
    'completed' => safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='completed'",[':p'=>$pid]),
    'cancelled' => safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('cancelled','rejected')",[':p'=>$pid]),
];

$active_menu = 'crm_bookings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bookings · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(220,38,38,.2);color:#991b1b}
/* Status tabs */
.status-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.stab{padding:7px 16px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid var(--border);color:var(--muted);background:#fff;transition:all .2s}
.stab:hover{border-color:var(--primary);color:var(--primary)}
.stab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.stab span{background:rgba(0,0,0,.12);border-radius:999px;padding:1px 7px;font-size:10px;margin-left:4px}
.stab.active span{background:rgba(255,255,255,.25)}
/* Filters */
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters input{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff;flex:1;min-width:200px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.btn-success{background:#27ae60;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
/* Table */
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left;white-space:nowrap}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-teal{background:#d1fae5;color:#065f46}
.pill-blue{background:#dbeafe;color:#1e40af}
.pill-orange{background:#fef3c7;color:#92400e}
.pill-red{background:#fee2e2;color:#991b1b}
.pill-gray{background:#f1f5f9;color:#475569}
.pill-purple{background:#ede9fe;color:#5b21b6}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.empty-state i{font-size:36px;opacity:.2;display:block;margin-bottom:10px}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:18px;display:flex;align-items:center;gap:8px}
.detail-row{display:flex;gap:8px;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px}
.detail-row:last-child{border-bottom:none}
.detail-row .dk{font-weight:600;color:var(--muted);min-width:130px;font-size:12px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.form-group select,.form-group textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border);flex-wrap:wrap}

/* Accept / Reject action buttons in table */
.action-group{display:flex;flex-wrap:wrap;gap:4px}
/* Reject reason modal */
.reject-reason-textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;min-height:90px}
/* Pending badge */
.pending-action-hint{font-size:10px;color:#92400e;background:#fef3c7;border-radius:6px;padding:2px 6px;display:block;margin-top:4px;white-space:nowrap}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<?php if (!$tier_is_paid): ?>
<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#3730a3">
    <i class="fas fa-lock" style="flex-shrink:0;font-size:16px"></i>
    <div><strong>Free Tier — View Only.</strong> You can see bookings but actions (accept, decline, advance) require Pro. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to Pro &rarr;</a></div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-calendar-check"></i> Bookings</h1>
        <p><?= count($bookings) ?> booking(s) found</p>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="backfill_provider_cn">
        <input type="hidden" name="booking_id" value="0">
        <button type="submit" class="btn btn-outline" style="font-size:12px" title="Generate provider control numbers for bookings that are missing one">
            <i class="fas fa-key"></i> Generate Missing Provider CNs
        </button>
    </form>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Status Tabs -->
<div class="status-tabs">
    <a href="crm-bookings.php"                   class="stab <?= $status_filter===''         ?'active':'' ?>">All <span><?= $counts['all'] ?></span></a>
    <a href="crm-bookings.php?status=pending"    class="stab <?= $status_filter==='pending'  ?'active':'' ?>">Pending <span><?= $counts['pending'] ?></span></a>
    <a href="crm-bookings.php?status=active"     class="stab <?= $status_filter==='active'   ?'active':'' ?>">Active <span><?= $counts['active'] ?></span></a>
    <a href="crm-bookings.php?status=completed"  class="stab <?= $status_filter==='completed'?'active':'' ?>">Completed <span><?= $counts['completed'] ?></span></a>
    <a href="crm-bookings.php?status=cancelled"  class="stab <?= $status_filter==='cancelled'?'active':'' ?>">Cancelled <span><?= $counts['cancelled'] ?></span></a>
</div>

<!-- Search -->
<form method="GET" class="filters">
    <?php if ($status_filter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
    <input type="text" name="search" placeholder="Search client name, contact, service…" value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="crm-bookings.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
</form>

<!-- Table -->
<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>#</th><th>Client</th><th>Service</th><th>Schedule</th><th>Amount</th><th>Payment</th><th>Status</th><th>Provider CN</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($bookings)): ?>
    <tr><td colspan="8"><div class="empty-state"><i class="fas fa-calendar-check"></i><p>No bookings found.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach ($bookings as $b):
        $st = $b['status'];
        [$pc,$pt] = match(true) {
            $st==='pending'                          => ['pill-orange','Pending'],
            $st==='waiting_provider_confirmation'    => ['pill-purple','Awaiting Confirm'],
            $st==='preparing'                        => ['pill-blue',  'Preparing'],
            $st==='on_the_way'                       => ['pill-blue',  'On the Way'],
            $st==='in_progress'                      => ['pill-teal',  'In Progress'],
            $st==='completed'                        => ['pill-teal',  'Completed'],
            default                                  => ['pill-red',   ucwords(str_replace('_',' ',$st))],
        };
        $ps  = $b['payment_status'];
        $pc2 = $ps==='paid'?'#27ae60':($ps==='partial'?'#e67e22':'#e74c3c');
        $is_pending      = ($st === 'pending');
        $can_verify      = in_array($st, ['preparing','on_the_way','waiting_provider_confirmation'])
                           && !empty($b['provider_control_number'])
                           && empty($b['dual_verified_at'])
                           && empty($b['provider_verified_at']);
    ?>
    <tr>
        <td style="color:var(--muted);font-size:12px">#<?= $b['id'] ?></td>
        <td>
            <strong><?= htmlspecialchars($b['full_name']) ?></strong>
            <br><small style="color:var(--muted)"><?= htmlspecialchars($b['contact_number']) ?></small>
        </td>
        <td style="font-size:12px;max-width:140px"><?= htmlspecialchars($b['service_name']??'—') ?></td>
        <td style="font-size:12px;white-space:nowrap">
            <?= date('M j, Y', strtotime($b['preferred_date'])) ?>
            <br><span style="color:var(--muted)"><?= date('h:i A', strtotime($b['preferred_time'])) ?></span>
        </td>
        <td style="font-weight:600"><?= $b['total_amount']>0?'₱'.number_format($b['total_amount'],0):'—' ?></td>
        <td>
            <span style="font-size:11px;font-weight:700;color:<?= $pc2 ?>"><?= ucfirst($ps) ?></span>
            <?php if ($st === 'waiting_provider_confirmation' && $ps === 'unpaid'): ?>
            <br><span style="font-size:10px;background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 5px;white-space:nowrap"><i class="fas fa-clock"></i> Awaiting payment</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="pill <?= $pc ?>"><?= $pt ?></span>
        </td>
        <td style="font-size:12px;white-space:nowrap">
            <?php if (!empty($b['provider_control_number'])): ?>
                <?php if (!empty($b['dual_verified_at'])): ?>
                <span style="font-weight:700;color:#065f46;background:#d1fae5;border-radius:6px;padding:3px 8px;display:inline-block">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($b['provider_control_number']) ?>
                </span>
                <?php elseif (!empty($b['provider_verified_at'])): ?>
                <span style="font-weight:700;color:#92400e;background:#fef3c7;border-radius:6px;padding:3px 8px;display:inline-block">
                    <i class="fas fa-hourglass-half"></i> <?= htmlspecialchars($b['provider_control_number']) ?>
                </span>
                <br><small style="color:#92400e">Waiting on client</small>
                <?php else: ?>
                <span style="font-weight:700;color:#5b21b6;background:#ede9fe;border-radius:6px;padding:3px 8px;display:inline-block">
                    <i class="fas fa-key"></i> <?= htmlspecialchars($b['provider_control_number']) ?>
                </span>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:var(--muted);font-size:11px">—</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="action-group">
                <!-- View details -->
                <button onclick="openDetail(<?= htmlspecialchars(json_encode($b)) ?>)" class="btn btn-primary btn-sm" title="View Details"><i class="fas fa-eye"></i></button>

                <?php if ($is_pending && $can_action): ?>
                <!-- ── Accept ── -->
                <form method="POST" style="display:inline" id="acceptForm_<?= $b['id'] ?>">
                    <input type="hidden" name="action"     value="accept_booking">
                    <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                </form>
                <button onclick="openAcceptModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['full_name'])) ?>', '<?= htmlspecialchars(addslashes($b['service_name'] ?? '')) ?>', '<?= number_format((float)$b['total_amount'], 2) ?>')"
                        class="btn btn-success btn-sm" title="Accept & Send Payment Link">
                    <i class="fas fa-check"></i> Accept
                </button>
                <!-- ── Reject ── -->
                <button onclick="openRejectModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['full_name'])) ?>')"
                        class="btn btn-danger btn-sm" title="Reject Booking">
                    <i class="fas fa-times"></i> Reject
                </button>
                <?php endif; ?>

                <?php if ($can_verify): ?>
                <!-- ── Dual Verify ── -->
                <button onclick="openDualVerifyModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['full_name'])) ?>')"
                        class="btn btn-sm" style="background:#0ea5e9;color:#fff" title="Verify Control Numbers">
                    <i class="fas fa-shield-halved"></i> Verify
                </button>
                <?php endif; ?>

            </div>
            <?php if ($is_pending): ?>
            <span class="pending-action-hint"><i class="fas fa-clock"></i> Awaiting action</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- ════════════════════════ MODALS ════════════════════════ -->

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal">
<div class="modal">
    <h3><i class="fas fa-calendar-check" style="color:var(--primary)"></i> Booking Details</h3>
    <div id="detailContent"></div>
    <div class="modal-footer">
        <button type="button" onclick="closeModals()" class="btn btn-outline">Close</button>
        <button type="button" onclick="openStatusModal()" class="btn" style="background:#3498db;color:#fff"><i class="fas fa-edit"></i> Update Status</button>
        <button type="button" onclick="openNoteModal()"   class="btn btn-primary"><i class="fas fa-note-sticky"></i> Add Note</button>
    </div>
</div>
</div>

<!-- Accept Confirm Modal -->
<div class="modal-overlay" id="acceptModal">
<div class="modal" style="max-width:420px;text-align:center">
    <div style="width:60px;height:60px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:#27ae60">
        <i class="fas fa-check-circle"></i>
    </div>
    <h3 style="justify-content:center;margin-bottom:8px">Accept this Request?</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:6px">
        Client: <strong id="acceptClientName"></strong>
    </p>
    <p style="font-size:13px;color:var(--muted);margin-bottom:6px" id="acceptServiceRow"></p>
    <p style="font-size:13px;color:var(--muted);margin-bottom:20px">
        A <strong>PayMongo payment link</strong> will be generated and sent to the seeker immediately.
    </p>
    <div style="display:flex;gap:10px;justify-content:center">
        <button type="button" onclick="closeModals()" class="btn btn-outline" style="min-width:110px">
            <i class="fas fa-times"></i> Cancel
        </button>
        <button type="button" onclick="submitAccept()" class="btn btn-success" style="min-width:110px">
            <i class="fas fa-check"></i> Accept
        </button>
    </div>
</div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
<div class="modal" style="max-width:460px">
    <h3><i class="fas fa-times-circle" style="color:#e74c3c"></i> Reject Booking</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
        You are about to reject the booking from <strong id="rejectClientName"></strong>.<br>
        The seeker will be notified with your reason.
    </p>
    <form method="POST">
        <input type="hidden" name="action"     value="reject_booking">
        <input type="hidden" name="booking_id" id="reject_bid">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        <div class="form-group">
            <label>Reason for Rejection <span style="color:#e74c3c">*</span></label>
            <textarea name="rejection_reason" class="reject-reason-textarea"
                      placeholder="e.g. Schedule conflict, outside service area, incomplete details…" required></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModals()" class="btn btn-outline">Cancel</button>
            <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Confirm Rejection</button>
        </div>
    </form>
</div>
</div>

<!-- Status Update Modal -->
<div class="modal-overlay" id="statusModal">
<div class="modal">
    <h3><i class="fas fa-edit" style="color:#3498db"></i> Update Booking Status</h3>
    <form method="POST">
    <input type="hidden" name="action"     value="update_status">
    <input type="hidden" name="booking_id" id="status_bid">
    <div class="form-group">
        <label>New Status</label>
        <select name="new_status" id="status_select">
            <option value="waiting_provider_confirmation">Awaiting Confirmation</option>
            <option value="preparing">Preparing</option>
            <option value="on_the_way">On the Way</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModals()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div>
    </form>
</div>
</div>

<!-- Verify Modal (Provider side) -->
<div class="modal-overlay" id="dualVerifyModal">
<div class="modal" style="max-width:420px">
    <h3><i class="fas fa-shield-halved" style="color:#0ea5e9"></i> Enter Provider Control Number</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:18px">
        Booking for: <strong id="dualVerifyClientName"></strong><br>
        Enter the technician's control number to confirm their presence on-site. The service will start once the client also verifies their code.
    </p>
    <form method="POST">
        <input type="hidden" name="action"     value="verify_control_numbers">
        <input type="hidden" name="booking_id" id="dual_verify_bid">
        <div class="form-group">
            <label>Provider Control Number <span style="color:#e74c3c">*</span></label>
            <input type="text" name="entered_provider_cn" id="entered_provider_cn"
                   placeholder="e.g. PCP-2026-XXXXXX"
                   style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;letter-spacing:1px"
                   autocomplete="off" required>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModals()" class="btn btn-outline">Cancel</button>
            <button type="submit" class="btn" style="background:#0ea5e9;color:#fff"><i class="fas fa-shield-halved"></i> Confirm</button>
        </div>
    </form>
</div>
</div>

<!-- Note Modal -->
<div class="modal-overlay" id="noteModal">
<div class="modal">
    <h3><i class="fas fa-note-sticky" style="color:var(--primary)"></i> Add Operations Note</h3>
    <form method="POST">
    <input type="hidden" name="action"     value="add_note">
    <input type="hidden" name="booking_id" id="note_bid">
    <div class="form-group">
        <label>Note</label>
        <textarea name="note" rows="4" placeholder="Enter operations note…" required style="resize:vertical"></textarea>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModals()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Note</button>
    </div>
    </form>
</div>
</div>


<script>
let currentBooking = null;

function openDetail(b) {
    currentBooking = b;
    const statusMap = {
        'pending':'Pending','waiting_provider_confirmation':'Awaiting Confirmation',
        'preparing':'Preparing','on_the_way':'On the Way',
        'in_progress':'In Progress','completed':'Completed',
        'cancelled':'Cancelled','rejected':'Rejected'
    };
    const pmLabel = b.payment_method === 'downpayment' ? 'Downpayment' : 'Full Payment';
    const rows = [
        ['Booking #',    '#'+b.id],
        ['Client',       b.full_name],
        ['Contact',      b.contact_number],
        ['Service',      b.service_name || '—'],
        ['Date',         b.preferred_date],
        ['Time',         b.preferred_time],
        ['Address',      b.address],
        ['Total Amount', b.total_amount > 0 ? '₱'+parseFloat(b.total_amount).toLocaleString() : '—'],
        ['Payment Mode', pmLabel],
        ['Downpayment',  b.downpayment_amount > 0 ? '₱'+parseFloat(b.downpayment_amount).toLocaleString() : '—'],
        ['Payment Status', b.payment_status],
        ['Status',       statusMap[b.status] || b.status],
        ['Rejection',    b.rejection_reason || '—'],
        ['Notes',        b.notes || '—'],
        ['Ops Notes',    b.operations_notes || '—'],
        ['Seeker CN',    b.control_number || '—'],
        ['Provider CN',  b.provider_control_number || '—'],
        ['Dual Verified',b.dual_verified_at || (b.control_number ? '⏳ Pending verification' : '—')],
    ];
    document.getElementById('detailContent').innerHTML = rows.map(([k,v])=>
        `<div class="detail-row"><span class="dk">${k}</span><span>${v||'—'}</span></div>`
    ).join('');
    document.getElementById('detailModal').classList.add('active');
}

let currentAcceptFormId = null;

function openAcceptModal(bookingId, clientName, serviceName, amount) {
    currentAcceptFormId = bookingId;
    document.getElementById('acceptClientName').textContent = clientName;
    const serviceRow = document.getElementById('acceptServiceRow');
    serviceRow.innerHTML = serviceName
        ? `Service: <strong>${serviceName}</strong> &nbsp;·&nbsp; Amount: <strong>₱${amount}</strong>`
        : `Amount: <strong>₱${amount}</strong>`;
    closeModals();
    document.getElementById('acceptModal').classList.add('active');
}

function submitAccept() {
    if (!currentAcceptFormId) return;
    document.getElementById('acceptForm_' + currentAcceptFormId).submit();
}

function openRejectModal(bookingId, clientName) {
    document.getElementById('reject_bid').value      = bookingId;
    document.getElementById('rejectClientName').textContent = clientName;
    document.querySelector('#rejectModal textarea').value = '';
    closeModals();
    document.getElementById('rejectModal').classList.add('active');
}

function openStatusModal() {
    if (!currentBooking) return;
    document.getElementById('status_bid').value    = currentBooking.id;
    document.getElementById('status_select').value = currentBooking.status;
    closeModals();
    document.getElementById('statusModal').classList.add('active');
}

function openNoteModal() {
    if (!currentBooking) return;
    document.getElementById('note_bid').value = currentBooking.id;
    closeModals();
    document.getElementById('noteModal').classList.add('active');
}


function openDualVerifyModal(bookingId, clientName) {
    document.getElementById('dual_verify_bid').value              = bookingId;
    document.getElementById('dualVerifyClientName').textContent   = clientName;
    document.getElementById('entered_provider_cn').value          = '';
    closeModals();
    document.getElementById('dualVerifyModal').classList.add('active');
}

function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(o => o.classList.remove('active'));
}
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', function(e){ if(e.target===this) closeModals(); })
);
</script>
</body></html>
