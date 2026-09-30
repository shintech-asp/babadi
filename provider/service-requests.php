<?php
chdir(dirname(__DIR__));
// service-requests.php - Provider's Service Requests
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/payment_receipt_helper.php');
require_once appPath('includes/availed_booking_helper.php');
require_once appPath('includes/booking_workflow_helper.php');
require_once appPath('config/send_email.php');
require_once appPath('includes/transaction_chat_helper.php');

$loginUrl = appUrl('login.php');
$providerSetupUrl = appUrl('provider-setup.php');
$serviceRequestsUrl = appUrl('service-requests.php');

// Ensure provider is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'provider') {
    header('Location: ' . $loginUrl);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$is_local_test_mode = in_array($remoteAddr, ['127.0.0.1', '::1'], true)
    || $host === 'localhost'
    || str_starts_with($host, 'localhost:')
    || $host === '127.0.0.1'
    || str_starts_with($host, '127.0.0.1:');
$test_service_day_booking_id = $is_local_test_mode
    ? max(0, (int)($_GET['test_service_day_booking'] ?? 0))
    : 0;
// getProviderSettingValue(), proposedTimeFitsProviderSchedule(), and
// providerHasBookingConflict() now live in includes/booking_workflow_helper.php
// (required above) so api/v1/provider/requests/reschedule.php's mobile
// equivalent shares the exact same validation instead of a second,
// independently-drifting copy. normalizeWorkflowStatus() made the same move
// earlier for the same reason (seeker-side pages needed it too).

// Resolve provider_id from session or fetch by user_id
$provider_id = $_SESSION['provider_id'] ?? null;
if (!$provider_id) {
    $stmtP = $db->prepare('SELECT id, business_registration_file, license_file, address, city, state FROM providers WHERE user_id = :uid');
    $stmtP->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmtP->execute();
    $prov = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($prov) {
        $provider_id = (int)$prov['id'];
        $_SESSION['provider_id'] = $provider_id;
        if (empty($prov['business_registration_file']) || empty($prov['license_file']) ||
            empty($prov['address']) || empty($prov['city']) ||
            strcasecmp(trim((string)($prov['state'] ?? '')), 'Cavite') !== 0) {
            header('Location: ' . $providerSetupUrl);
            exit();
        }
    } else {
        header('Location: ' . $loginUrl);
        exit();
    }
}

// Field technicians eligible to be assigned to a booking at the Preparing
// step — any active employee flagged 'field'. No portal login (provider_staff)
// required; they log in directly via the employee self-service portal.
$fieldStaffOptions = [];
try {
    $fsStmt = $db->prepare(
        "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name
         FROM employees
         WHERE provider_id = :pid AND status = 'active' AND staff_type = 'field'
         ORDER BY first_name"
    );
    $fsStmt->execute([':pid' => $provider_id]);
    $fieldStaffOptions = $fsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* employees table may not have matching rows yet */ }
$fieldStaffIds = array_map('intval', array_column($fieldStaffOptions, 'id'));

// Inventory available for the Preparing step's equipment/consumables picker.
// Equipment isn't consumed — quantity_available is the total owned, so what's
// actually free right now is that minus whatever's currently checked out on
// other active bookings (getCheckedOutQuantity(), from booking_workflow_helper.php).
// Consumables' quantity_available already IS the live remaining stock.
$prepInventory = [];
try {
    $invStmt = $db->prepare(
        "SELECT id, item_name, item_type, quantity_available, unit
         FROM inventory_items WHERE provider_id = ? AND is_archived = 0 ORDER BY item_type, item_name"
    );
    $invStmt->execute([$provider_id]);
    $prepInventory = $invStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* inventory module may not be set up yet */ }
foreach ($prepInventory as &$piRow) {
    $piRow['available_now'] = $piRow['item_type'] === 'equipment'
        ? max(0, (int)$piRow['quantity_available'] - getCheckedOutQuantity($db, (int)$piRow['id']))
        : (int)$piRow['quantity_available'];
}
unset($piRow);
// Not filtered to available_now > 0 — an item already fully checked out to
// OTHER bookings must still appear (at 0) so the "Edit Equipment & Staff"
// flow on a booking that's already Preparing can still find its row and
// raise the ceiling by what that booking itself already holds (see
// openPrepareBooking()'s isEdit branch below).
$prepEquipment   = array_values(array_filter($prepInventory, fn($i) => $i['item_type'] === 'equipment'));
$prepConsumables = array_values(array_filter($prepInventory, fn($i) => $i['item_type'] === 'consumable'));

$sidebar_provider = [
    'company_name' => $_SESSION['company_name'] ?? ($_SESSION['first_name'] ?? 'Provider'),
    'email' => $_SESSION['email'] ?? '',
    'profile_image' => $_SESSION['profile_image'] ?? '',
    'logo_url' => ''
];
try {
    $sidebaeStmt = $db->prepare(
        "SELECT p.company_name, p.logo_url, u.email, u.profile_image
         FROM providers p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.id = :pid
         LIMIT 1"
    );
    $sidebaeStmt->execute([':pid' => $provider_id]);
    $sidebaeRow = $sidebaeStmt->fetch(PDO::FETCH_ASSOC);
    if ($sidebaeRow) {
        $sidebar_provider['company_name'] = $sidebaeRow['company_name'] ?: $sidebar_provider['company_name'];
        $sidebar_provider['email'] = $sidebaeRow['email'] ?: $sidebar_provider['email'];
        $sidebar_provider['profile_image'] = $sidebaeRow['profile_image'] ?: $sidebar_provider['profile_image'];
        $sidebar_provider['logo_url'] = $sidebaeRow['logo_url'] ?: $sidebar_provider['logo_url'];
    }
} catch (Exception $e) {}

// â”€â”€ Ensure availed_services table exists â”€â”€
try {
    $db->exec("CREATE TABLE IF NOT EXISTS availed_services (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        provider_id     INT NOT NULL,
        service_id      INT DEFAULT NULL,
        service_name    VARCHAR(255) DEFAULT NULL,
        seeker_user_id  INT DEFAULT NULL,
        full_name       VARCHAR(255) NOT NULL,
        contact_number  VARCHAR(50)  NOT NULL,
        preferred_date  DATE         NOT NULL,
        preferred_time  TIME         NOT NULL,
        address         TEXT         NOT NULL,
        notes           TEXT         DEFAULT NULL,
        status          VARCHAR(60) DEFAULT 'pending',
        is_read         TINYINT(1) DEFAULT 0,
        provider_arrival_proof_photo VARCHAR(255) DEFAULT NULL,
        provider_arrival_proof_uploaded_at DATETIME DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $db->exec("ALTER TABLE availed_services MODIFY COLUMN status VARCHAR(60) DEFAULT 'pending'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_user_id INT DEFAULT NULL"); } catch(Exception $e) {}
} catch(Exception $e) {}

// â”€â”€ Ensure seeker_notifications table exists â”€â”€
try {
    $db->exec("CREATE TABLE IF NOT EXISTS seeker_notifications (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        seeker_user_id  INT NOT NULL,
        avail_id        INT NOT NULL,
        provider_id     INT NOT NULL,
        service_name    VARCHAR(255) DEFAULT NULL,
        type            VARCHAR(30) NOT NULL COMMENT 'accepted or cancelled',
        message         TEXT NOT NULL,
        is_read         TINYINT(1) DEFAULT 0,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

// Ensure direct messages table exists (shared by Messages page/API)
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender_receiver (sender_id, receiver_id),
            INDEX idx_receiver_read (receiver_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch(Exception $e) {}

// AJAX: Provider sends message to seeker from Service Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_seeker_message_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $availId = (int)($_POST['avail_id'] ?? 0);
    $body = trim((string)($_POST['message'] ?? ''));
    $sendeeId = (int)($_SESSION['user_id'] ?? 0);

    if ($availId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid booking reference.']);
        exit;
    }
    if ($sendeeId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }
    if ($body === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter a message.']);
        exit;
    }
    if (mb_strlen($body) > 500) {
        echo json_encode(['ok' => false, 'error' => 'Message is too long (max 500 characters).']);
        exit;
    }

    try {
        $bkStmt = $db->prepare(
            "SELECT id, seeker_user_id, full_name, service_name
             FROM availed_services
             WHERE id = :id AND provider_id = :pid
             LIMIT 1"
        );
        $bkStmt->execute([':id' => $availId, ':pid' => $provider_id]);
        $bk = $bkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bk) {
            echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
            exit;
        }

        $seekerId = (int)($bk['seeker_user_id'] ?? 0);
        if ($seekerId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'This booking is not linked to a seeker account.']);
            exit;
        }

        // Scoped to this booking (request_id) so it shows up in the same
        // transaction-scoped thread as provider/messages-provider.php —
        // also enforces the "closed once completed/cancelled" rule.
        $sendResult = sendBookingMessage($db, $availId, $sendeeId, $seekerId, $body);
        if (!$sendResult['ok']) {
            echo json_encode($sendResult);
            exit;
        }

        $serviceTitle = trim((string)($bk['service_name'] ?? ''));
        $notifMessage = 'Message from your provider'
            . ($serviceTitle !== '' ? ' about "' . $serviceTitle . '"' : '')
            . ': ' . $body;

        $notifInseet = $db->prepare(
            "INSERT INTO seeker_notifications
                (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
             VALUES (:suid, :avid, :pid, :sname, 'message', :msg, 0)"
        );
        $notifInseet->execute([
            ':suid' => $seekerId,
            ':avid' => $availId,
            ':pid' => $provider_id,
            ':sname' => $serviceTitle,
            ':msg' => $notifMessage,
        ]);

        echo json_encode([
            'ok' => true,
            'recipient_name' => (string)($bk['full_name'] ?? 'Seeker'),
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Failed to send message. Please try again.']);
        exit;
    }
}

// â”€â”€ Ensure control number columns exist â”€â”€
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS control_number VARCHAR(30) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS provider_control_number VARCHAR(30) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_verified_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS provider_verified_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS dual_verified_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_satisfaction_confirmed_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS emergency_now_requested TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS emergency_now_requested_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS emergency_now_accepted_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS provider_arrival_proof_photo VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS provider_arrival_proof_uploaded_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS qr_token VARCHAR(64) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS qr_scanned_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
ensureAvailedRescheduleColumns($db);

// ── QR scan AJAX ──
if (isset($_POST['action']) && $_POST['action'] === 'scan_qr') {
    header('Content-Type: application/json');
    $token = strtoupper(trim((string)($_POST['token'] ?? '')));
    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'No token provided.']);
        exit;
    }
    $result = scanQrAndStartService($db, $token, (int)$provider_id);
    echo json_encode($result);
    exit;
}

// â”€â”€ Valid statuses â”€â”€
$valid_statuses = [
    'pending',
    'accepted',
    'preparing',
    'starting',
    'ongoing',
    'waiting_remaining_payment',
    'waiting_seeker_confirmation',
    'waiting_seeker_information',
    'waiting_provider_confirmation',
    'completed',
    'cancelled'
];

$auto_cancel_unaccepted_24h = getProviderSettingValue($db, (int)$provider_id, 'auto_cancel_unaccepted_24h', '0') === '1';
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
                        $notifyStmt->execute([
                            ':suid'  => (int)$row['seeker_user_id'],
                            ':avid'  => (int)$row['id'],
                            ':pid'   => $provider_id,
                            ':sname' => $row['service_name'] ?? '',
                            ':msg'   => 'Your service request was automatically cancelled because it was not accepted by the provider within 24 hours.',
                        ]);
                    }
                }
            }
        }
    } catch (Exception $e) {}
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  DUAL CONTROL NUMBER VERIFICATION  (provider submits seeker code)
//  Flow:
//    1. Provider acceptance  â†’ generates control_number (seeker) +
//                              provider_control_number (technician)
//    2. Seeker submits their code  â†’ seeker_verified_at stamped
//    3. Provider submits seeker code â†’ provider_verified_at stamped
//    4. Both verified             â†’ dual_verified_at stamped,
//                                   status advances to 'starting'
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
$ctrl_result = '';
$ctrl_error  = '';
$ctrl_allow_anytime_test = false;

if (isset($_POST['verify_control_number']) && isset($_POST['avail_id']) && isset($_POST['control_number_input'])) {
    $ctelAvailId = intval($_POST['avail_id']);
    $ctelInput   = (string)$_POST['control_number_input'];
    $allowAnytimeTest = $is_local_test_mode && (($_POST['test_service_day_anytime'] ?? '0') === '1');
    $ctrl_allow_anytime_test = $allowAnytimeTest;
    $ctelEarlyStart = (($_POST['early_start'] ?? '0') === '1');

    // Shared with provider-portal/my-services.php's field-tech self-service
    // equivalent — see includes/booking_workflow_helper.php.
    $ctelResult = verifyProviderSeekerCode($db, $ctelAvailId, $provider_id, $ctelInput, $allowAnytimeTest, $ctelEarlyStart);
    $ctrl_result = $ctelResult['result'];
    $ctrl_error  = $ctelResult['error'];
    // NOTE: $action_msg/$action_type/$action_label get reset to '' by the
    // "Handle Accept / Cancel action" block immediately below regardless of
    // what's set here, so $ctelResult['message'/'action_type'/'action_label']
    // is intentionally not assigned into them — only $ctrl_result/$ctrl_error
    // actually reach the page's ctel-modal rendering further down.
}

// â”€â”€ Handle Accept / Cancel action â”€â”€
$action_msg   = '';
$action_type  = '';
$action_label = '';
if ($auto_cancelled_count > 0) {
    $action_type = 'cancelled';
    $action_label = 'Auto-cancelled';
    $action_msg = $auto_cancelled_count === 1
        ? '1 pending request was automatically cancelled because it was not accepted within 24 hours.'
        : $auto_cancelled_count . ' pending requests were automatically cancelled because they were not accepted within 24 hours.';
}

if (isset($_POST['accept_cancel_action']) && isset($_POST['avail_id'])) {
    $avail_id      = intval($_POST['avail_id']);
    $ac_action     = (string)($_POST['accept_cancel_action'] ?? '');
    $cancel_reason = trim((string)($_POST['cancel_reason'] ?? ''));

    if ($avail_id > 0 && in_array($ac_action, ['accepted', 'cancelled'], true)) {
        try {
            $fetchStmt = $db->prepare(
                "SELECT *
                 FROM availed_services
                 WHERE id = :id AND provider_id = :pid AND status = 'pending'
                 LIMIT 1"
            );
            $fetchStmt->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $avail_row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$avail_row) {
                $action_msg = 'This request is no longer pending and cannot be updated.';
                $action_type = 'error';
            } elseif ($ac_action === 'accepted') {
                $accepted = acceptAvailedBooking(
                    $db,
                    $avail_id,
                    $provider_id,
                    (int)($_SESSION['user_id'] ?? 0),
                    'provider',
                    'Service request accepted by provider from Service Requests.'
                );

                if ($accepted['ok']) {
                    $action_type = 'accepted';
                    $action_label = 'Accepted';
                    $action_msg = 'Service request accepted. The seeker has been notified.';
                } else {
                    $action_msg = $accepted['error'] ?? 'This request is no longer pending and cannot be updated.';
                    $action_type = 'error';
                }
            } else {
                $declined = declineAvailedBooking(
                    $db,
                    $avail_id,
                    $provider_id,
                    (int)($_SESSION['user_id'] ?? 0),
                    'provider',
                    $cancel_reason
                );

                if ($declined['ok']) {
                    $action_type = 'cancelled';
                    $action_label = 'Cancelled';
                    $action_msg = 'Service request cancelled. The seeker has been notified.';
                } else {
                    $action_msg = $declined['error'] ?? 'This request is no longer pending and cannot be updated.';
                    $action_type = 'error';
                }
            }
        } catch (Exception $e) {
            $action_msg = 'Failed to update: ' . $e->getMessage();
            $action_type = 'error';
        }
    }
}

if (false && isset($_POST['accept_cancel_action']) && isset($_POST['avail_id'])) {
    $avail_id      = intval($_POST['avail_id']);
    $ac_action     = $_POST['accept_cancel_action'];
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    if ($avail_id && in_array($ac_action, ['accepted', 'cancelled'])) {
        try {
            $fetchStmt = $db->prepare("SELECT * FROM availed_services WHERE id = :id AND provider_id = :pid AND status = 'pending'");
            $fetchStmt->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $avail_row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if ($avail_row) {
                $newStatus = $ac_action;

                // â”€â”€ Generate DUAL control numbers on acceptance â”€â”€
                $generated_ctrl          = null;   // seeker code
                $generated_prov_ctrl     = null;   // provider / technician code

                if ($ac_action === 'accepted') {
                    // Re-fetch to get latest control numbers (may have been set already)
                    $eeStmt = $db->prepare("SELECT control_number, provider_control_number FROM availed_services WHERE id = :id LIMIT 1");
                    $eeStmt->execute([':id' => $avail_id]);
                    $eeRow = $eeStmt->fetch(PDO::FETCH_ASSOC);

                    $generated_ctrl      = !empty($eeRow['control_number'])
                        ? $eeRow['control_number']
                        : 'PCF-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                    $generated_prov_ctrl = !empty($eeRow['provider_control_number'])
                        ? $eeRow['provider_control_number']
                        : 'PCV-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                    $upStmt = $db->prepare(
                        "UPDATE availed_services
                         SET status = :status, is_read = 1,
                             control_number = :ctrl,
                             provider_control_number = :pctel,
                             seeker_verified_at = NULL,
                             provider_verified_at = NULL,
                             dual_verified_at = NULL,
                             updated_at = NOW()
                         WHERE id = :id AND provider_id = :pid"
                    );
                    $upStmt->execute([
                        ':status' => $newStatus,
                        ':ctrl'   => $generated_ctrl,
                        ':pctel'  => $generated_prov_ctrl,
                        ':id'     => $avail_id,
                        ':pid'    => $provider_id,
                    ]);
                } else {
                    $upStmt = $db->prepare("UPDATE availed_services SET status = :status, is_read = 1, updated_at = NOW() WHERE id = :id AND provider_id = :pid");
                    $upStmt->execute([':status' => $newStatus, ':id' => $avail_id, ':pid' => $provider_id]);
                }

                if ($ac_action === 'accepted') {
                    $notif_message = "Your service request for \"" . ($avail_row['service_name'] ?? 'Service') . "\" has been ACCEPTED by the provider. "
                        . ($generated_ctrl
                            ? "Your Seeker Control Number is: " . $generated_ctrl . ". On service day, keep this code ready because the provider will verify this seeker code before service starts."
                            : "Please wait for further updates.");
                    $notif_type    = 'accepted';
                } else {
                    $reason_text   = $cancel_reason ? " Reason: " . $cancel_reason : "";
                    $notif_message = "We're soeey, your service request for \"" . ($avail_row['service_name'] ?? 'Service') . "\" has been CANCELLED by the provider." . $reason_text;
                    $notif_type    = 'cancelled';
                }

                if (!empty($avail_row['seeker_user_id'])) {
                    $nInseet = $db->prepare("INSERT INTO seeker_notifications (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                        VALUES (:suid, :avid, :pid, :sname, :type, :msg, 0)");
                    $nInseet->execute([
                        ':suid'  => $avail_row['seeker_user_id'],
                        ':avid'  => $avail_id,
                        ':pid'   => $provider_id,
                        ':sname' => $avail_row['service_name'] ?? '',
                        ':type'  => $notif_type,
                        ':msg'   => $notif_message,
                    ]);
                }

                $action_type  = $ac_action;
                $action_label = ($ac_action === 'accepted') ? 'Accepted' : 'Cancelled';
                $action_msg   = ($ac_action === 'accepted')
                    ? 'Service request accepted! The seeker has been notified.'
                    : 'Service request cancelled. The seeker has been notified.';
            } else {
                $action_msg = 'This request is no longer pending and cannot be updated.';
                $action_type = 'error';
            }
        } catch(Exception $e) {
            $action_msg  = 'Failed to update: ' . $e->getMessage();
            $action_type = 'error';
        }
    }
}

if (isset($_POST['submit_reschedule_request']) && isset($_POST['avail_id'])) {
    $avail_id = (int)($_POST['avail_id'] ?? 0);
    $proposed_date = trim((string)($_POST['reschedule_date'] ?? ''));
    $proposed_time = trim((string)($_POST['reschedule_time'] ?? ''));
    $reschedule_reason = trim((string)($_POST['reschedule_reason'] ?? ''));

    if ($avail_id > 0) {
        try {
            $bookingStmt = $db->prepare(
                "SELECT id, status, seeker_user_id, service_name, preferred_date, preferred_time
                 FROM availed_services
                 WHERE id = :id AND provider_id = :pid
                 LIMIT 1"
            );
            $bookingStmt->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $action_type = 'error';
                $action_msg = 'Booking not found.';
            } else {
                $currentStatus = normalizeWorkflowStatus((string)($booking['status'] ?? ''));

                if (!in_array($currentStatus, ['accepted', 'preparing'], true)) {
                    $action_type = 'error';
                    $action_msg = 'Rescheduling is only available for accepted or preparing bookings.';
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $proposed_date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $proposed_time)) {
                    $action_type = 'error';
                    $action_msg = 'Please choose a valid reschedule date and time.';
                } elseif ($reschedule_reason === '') {
                    $action_type = 'error';
                    $action_msg = 'Please add a short reason for the reschedule.';
                } else {
                    $proposedStamp = strtotime($proposed_date . ' ' . $proposed_time . ':00');
                    $currentStamp = strtotime((string)$booking['preferred_date'] . ' ' . (string)$booking['preferred_time']);

                    if ($proposedStamp === false || $proposedStamp <= time()) {
                        $action_type = 'error';
                        $action_msg = 'The proposed schedule must be in the future.';
                    } elseif ($currentStamp !== false && $proposed_date === (string)$booking['preferred_date'] && substr((string)$booking['preferred_time'], 0, 5) === $proposed_time) {
                        $action_type = 'error';
                        $action_msg = 'Choose a different schedule before sending a reschedule request.';
                    } elseif (!proposedTimeFitsProviderSchedule($db, $provider_id, $proposed_date, $proposed_time)) {
                        $action_type = 'error';
                        $action_msg = 'The proposed time is outside your configured working hours or slot length.';
                    } elseif (providerHasBookingConflict($db, $provider_id, $avail_id, $proposed_date, $proposed_time . ':00')) {
                        $action_type = 'error';
                        $action_msg = 'That schedule conflicts with another active booking.';
                    } else {
                        $updateStmt = $db->prepare(
                            "UPDATE availed_services
                             SET reschedule_request_status = 'pending',
                                 reschedule_requested_at = NOW(),
                                 reschedule_proposed_date = :proposed_date,
                                 reschedule_proposed_time = :proposed_time,
                                 reschedule_reason = :reason,
                                 reschedule_responded_at = NULL,
                                 provider_verified_at = NULL,
                                 seeker_verified_at = NULL,
                                 dual_verified_at = NULL,
                                 updated_at = NOW()
                             WHERE id = :id AND provider_id = :pid"
                        );
                        $updateStmt->execute([
                            ':proposed_date' => $proposed_date,
                            ':proposed_time' => $proposed_time . ':00',
                            ':reason' => $reschedule_reason,
                            ':id' => $avail_id,
                            ':pid' => $provider_id,
                        ]);

                        if (!empty($booking['seeker_user_id'])) {
                            $currentSchedule = date('M j, Y', strtotime((string)$booking['preferred_date'])) . ' at ' . date('g:i A', strtotime((string)$booking['preferred_time']));
                            $newSchedule = date('M j, Y', strtotime($proposed_date)) . ' at ' . date('g:i A', strtotime($proposed_time));
                            $notifMessage =
                                'Your provider proposed a reschedule for "' . ($booking['service_name'] ?? 'Service') . '". '
                                . 'Current schedule: ' . $currentSchedule . '. '
                                . 'Proposed schedule: ' . $newSchedule . '. '
                                . 'Reason: ' . $reschedule_reason . '. '
                                . 'Please review this in My Bookings.';

                            notifySeekerForAvailedBooking(
                                $db,
                                (int)$booking['seeker_user_id'],
                                $avail_id,
                                $provider_id,
                                (string)($booking['service_name'] ?? ''),
                                'message',
                                $notifMessage
                            );
                        }

                        appendAvailedStatusHistory(
                            $db,
                            $avail_id,
                            $currentStatus,
                            $currentStatus,
                            (int)($_SESSION['user_id'] ?? 0),
                            'provider',
                            'Provider proposed a reschedule to ' . $proposed_date . ' ' . $proposed_time . '. Reason: ' . $reschedule_reason
                        );

                        $action_type = 'accepted';
                        $action_label = 'Reschedule Sent';
                        $action_msg = 'Reschedule request sent to the seeker for confirmation.';
                    }
                }
            }
        } catch (Exception $e) {
            $action_type = 'error';
            $action_msg = 'Failed to send the reschedule request.';
        }
    }
}

// -- Handle Emergency request acceptance (provider side) --
if (isset($_POST['accept_emergency_now']) && isset($_POST['avail_id'])) {
    $avail_id = intval($_POST['avail_id']);
    if ($avail_id > 0) {
        try {
            $fetch = $db->prepare(
                "SELECT id, seeker_user_id, service_name, status,
                        COALESCE(emergency_now_requested,0) AS emergency_now_requested,
                        emergency_now_accepted_at
                 FROM availed_services
                 WHERE id = :id AND provider_id = :pid
                 LIMIT 1"
            );
            $fetch->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $action_type = 'error';
                $action_msg = 'Emergency request not found.';
            } elseif ((int)$row['emergency_now_requested'] !== 1) {
                $action_type = 'error';
                $action_msg = 'This booking has no pending emergency request.';
            } elseif (!empty($row['emergency_now_accepted_at'])) {
                $action_type = 'accepted';
                $action_label = 'Emergency Accepted';
                $action_msg = 'Emergency request was already accepted.';
            } else {
                $up = $db->prepare(
                    "UPDATE availed_services
                     SET emergency_now_accepted_at = NOW(),
                         status = CASE WHEN status = 'accepted' THEN 'preparing' ELSE status END,
                         is_read = 1,
                         updated_at = NOW()
                     WHERE id = :id AND provider_id = :pid"
                );
                $up->execute([':id' => $avail_id, ':pid' => $provider_id]);

                if ($up->rowCount() > 0) {
                    $oldStatus = normalizeWorkflowStatus((string)($row['status'] ?? ''));
                    $newStatus = $oldStatus === 'accepted' ? 'preparing' : $oldStatus;
                    appendAvailedStatusHistory(
                        $db,
                        $avail_id,
                        $oldStatus,
                        $newStatus,
                        (int)($_SESSION['user_id'] ?? 0),
                        'provider',
                        'Emergency Service Now request accepted by provider.'
                    );

                    if (!empty($row['seeker_user_id'])) {
                        try {
                            $db->prepare(
                                "INSERT INTO seeker_notifications
                                    (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                                 VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                            )->execute([
                                ':suid'  => (int)$row['seeker_user_id'],
                                ':avid'  => $avail_id,
                                ':pid'   => $provider_id,
                                ':sname' => $row['service_name'] ?? '',
                                ':msg'   => 'Your provider accepted your Emergency Service Now request and peioeitized your booking.',
                            ]);
                        } catch (Exception $e) {}
                    }
                    $action_type = 'accepted';
                    $action_label = 'Emergency Accepted';
                    $action_msg = 'Emergency request accepted. The seeker has been notified.';
                } else {
                    $action_type = 'error';
                    $action_msg = 'Unable to accept emergency request.';
                }
            }
        } catch (Exception $e) {
            $action_type = 'error';
            $action_msg = 'Failed to accept emergency request.';
        }
    }
}

// ── Handle "Prepare Booking" (Accepted -> Preparing): staff + equipment/
// consumables assignment, now done by the provider instead of the platform
// admin (see admin/booking-preparing.php, superseded by this for providers
// using the service-level staff assignment feature). ──
if (isset($_POST['prepare_booking']) && isset($_POST['avail_id'])) {
    $prepAvailId  = (int)$_POST['avail_id'];
    $prepStaffId  = (int)($_POST['staff_id'] ?? 0);
    $prepCompanionId = !empty($_POST['companion_id']) ? (int)$_POST['companion_id'] : null;
    $prepEquip    = json_decode($_POST['equipment_json'] ?? '[]', true) ?: [];
    $prepCons     = json_decode($_POST['consumables_json'] ?? '[]', true) ?: [];
    $prepNotes    = trim($_POST['operations_notes'] ?? '');

    // Shared with provider-portal/my-services.php's field-tech self-service
    // equivalent — see includes/booking_workflow_helper.php. Also the entry
    // point for re-editing an already-'preparing' booking's assignment, not
    // just the one-shot accepted->preparing trigger.
    $prepResult = prepareAvailedBooking(
        $db, $provider_id, $prepAvailId, $prepStaffId, $fieldStaffIds,
        $prepEquip, $prepCons, $prepNotes,
        (int)($_SESSION['user_id'] ?? 0), 'provider', $prepCompanionId
    );
    $action_type = $prepResult['type'];
    $action_msg  = $prepResult['message'];
}

// ── Handle "Submit Inspection Report" (Accepted/Revising -> Awaiting
//    Agreement): a field technician who visited the site submits a photo,
//    a description, a final price, and a proposed working date. The
//    seeker then agrees (locking those in, re-entering the normal
//    accepted -> preparing pipeline) or requests changes (-> 'revising',
//    looping back here for a new report). See CLAUDE.md's "Recent Work
//    Log" for the full design writeup. ──
if (isset($_POST['submit_inspection_report']) && isset($_POST['avail_id'])) {
    $inspAvailId = (int)$_POST['avail_id'];
    $inspStaffId = (int)($_POST['staff_id'] ?? 0);
    $inspNotes   = trim($_POST['inspection_notes'] ?? '');
    $inspPrice   = (float)($_POST['proposed_price'] ?? 0);
    $inspWorkingDate = trim($_POST['proposed_working_date'] ?? '');

    // Shared with provider-portal/my-services.php's field-tech self-service
    // equivalent — see includes/booking_workflow_helper.php.
    $inspResult = submitProviderInspectionReport(
        $db, $provider_id, $inspAvailId, $inspStaffId, $fieldStaffIds,
        $inspNotes, $inspPrice, $inspWorkingDate,
        (int)($_SESSION['user_id'] ?? 0), 'provider'
    );
    $action_type = $inspResult['type'];
    $action_msg  = $inspResult['message'];
}

// ── Handle status update ──
if (isset($_POST['update_status']) && isset($_POST['avail_id']) && isset($_POST['new_status'])) {
    $avail_id   = intval($_POST['avail_id']);
    // Shared with provider-portal/my-services.php's field-tech self-service
    // equivalent — see includes/booking_workflow_helper.php.
    $advResult = advanceAvailedServiceStatus(
        $db, $provider_id, $avail_id, (string)$_POST['new_status'],
        (int)($_SESSION['user_id'] ?? 0), 'provider', 'Provider'
    );
    $action_type  = $advResult['type'];
    $action_label = $advResult['label'];
    $action_msg   = $advResult['message'];
}

// -- Archive completed/cancelled request --
if (isset($_POST['archive_request']) && isset($_POST['avail_id'])) {
    $archive_id = intval($_POST['avail_id']);
    if ($archive_id > 0) {
        try {
            $archiveStmt = $db->prepare(
                "UPDATE availed_services
                 SET is_archived = 1, archived_at = NOW(), is_read = 1
                 WHERE id = :id
                   AND provider_id = :pid
                   AND status IN ('completed', 'cancelled')
                   AND COALESCE(is_archived, 0) = 0"
            );
            $archiveStmt->execute([':id' => $archive_id, ':pid' => $provider_id]);

            if ($archiveStmt->rowCount() > 0) {
                $action_msg = 'Request archived successfully.';
                $action_type = 'completed';
            } else {
                $action_msg = 'Only completed or cancelled requests can be archived.';
                $action_type = 'error';
            }
        } catch (Exception $e) {
            $action_msg = 'Failed to archive request.';
            $action_type = 'error';
        }
    }
}

// â”€â”€ Mark availed notifications as read â”€â”€
if (isset($_GET['mark_read']) && $_GET['mark_read'] == '1') {
    try {
        $db->prepare("UPDATE availed_services SET is_read = 1 WHERE provider_id = :pid AND is_read = 0")
           ->execute([':pid' => $provider_id]);
    } catch(Exception $e) {}
    header('Location: ' . $serviceRequestsUrl);
    exit();
}

// â”€â”€ Fetch availed notifications â”€â”€
$avail_notifs = [];
$eeceiptRowsByBooking = [];
$unread_count = 0;
try {
    $nStmt = $db->prepare(
        "SELECT *,
                COALESCE(control_number, '')          AS control_number,
                COALESCE(provider_control_number, '') AS provider_control_number,
                COALESCE(emergency_now_requested, 0)  AS emergency_now_requested,
                COALESCE(emergency_now_requested_at, '') AS emergency_now_requested_at,
                COALESCE(emergency_now_accepted_at, '') AS emergency_now_accepted_at,
                COALESCE((
                    SELECT pt.transaction_id
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), '') AS payment_record_reference,
                COALESCE((
                    SELECT pt.payment_type
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), '') AS payment_record_type,
                COALESCE((
                    SELECT pt.payment_method
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), '') AS payment_record_method,
                COALESCE((
                    SELECT pt.status
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), '') AS payment_record_status,
                COALESCE((
                    SELECT pt.amount
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), 0) AS payment_record_amount,
                COALESCE((
                    SELECT DATE_FORMAT(COALESCE(pt.updated_at, pt.created_at), '%Y-%m-%d %H:%i:%s')
                    FROM payment_transactions pt
                    WHERE pt.availed_service_id = availed_services.id
                    ORDER BY COALESCE(pt.updated_at, pt.created_at) DESC, pt.id DESC
                    LIMIT 1
                ), '') AS payment_record_at,
                COALESCE((
                    SELECT sv.assigned_staff_id FROM services sv WHERE sv.id = availed_services.service_id
                ), 0) AS service_assigned_staff_id,
                COALESCE((
                    SELECT CONCAT(e.first_name, ' ', e.last_name)
                    FROM services sv JOIN employees e ON e.id = sv.assigned_staff_id
                    WHERE sv.id = availed_services.service_id
                ), '') AS service_assigned_staff_name,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(sei.inventory_item_id, ':', sei.quantity_needed))
                    FROM service_equipment_items sei
                    WHERE sei.service_id = availed_services.service_id
                ), '') AS service_equipment_ids,
                COALESCE((
                    SELECT sv.requires_inspection FROM services sv WHERE sv.id = availed_services.service_id
                ), 0) AS service_requires_inspection
         FROM availed_services
         WHERE provider_id = :pid
           AND COALESCE(is_archived, 0) = 0
         ORDER BY (CASE
                    WHEN COALESCE(emergency_now_requested, 0) = 1
                     AND emergency_now_accepted_at IS NULL THEN 1
                    ELSE 0
                  END) DESC,
                  COALESCE(emergency_now_requested_at, created_at) DESC,
                  created_at DESC
         LIMIT 50"
    );
    $nStmt->execute([':pid' => $provider_id]);
    $avail_notifs = $nStmt->fetchAll(PDO::FETCH_ASSOC);

    // Auto-heal stale rows: remaining payment already paid but status not advanced yet.
    foreach ($avail_notifs as &$av) {
        $st = strtolower(trim((string)($av['status'] ?? '')));
        $ps = strtolower(trim((string)($av['payment_status'] ?? '')));
        if ($st === 'waiting_remaining_payment' && $ps === 'paid') {
            try {
                $db->prepare(
                    "UPDATE availed_services
                     SET status = 'waiting_provider_confirmation', updated_at = NOW()
                     WHERE id = :id AND provider_id = :pid
                       AND status = 'waiting_remaining_payment'
                       AND payment_status = 'paid'"
                )->execute([':id' => $av['id'], ':pid' => $provider_id]);
            } catch (Exception $e) {}
            $av['status'] = 'waiting_provider_confirmation';
        }

        $seekerConfirmedAt = trim((string)($av['seeker_satisfaction_confirmed_at'] ?? ''));
        $seekerConfirmed = ($seekerConfirmedAt !== '' && $seekerConfirmedAt !== '0000-00-00 00:00:00');
        if ($seekerConfirmed && !in_array($av['status'], ['completed', 'cancelled'], true)) {
            try {
                $db->prepare(
                    "UPDATE availed_services
                     SET status = 'completed',
                         payment_status = CASE
                             WHEN payment_status IN ('paid', 'partial') THEN 'paid'
                             ELSE payment_status
                         END,
                         updated_at = NOW()
                     WHERE id = :id AND provider_id = :pid
                       AND status NOT IN ('completed', 'cancelled')"
                )->execute([':id' => $av['id'], ':pid' => $provider_id]);
            } catch (Exception $e) {}
            $av['status'] = 'completed';
            if (in_array(strtolower(trim((string)($av['payment_status'] ?? ''))), ['paid', 'partial'], true)) {
                $av['payment_status'] = 'paid';
            }
        }
    }
    unset($av);

    if (!empty($avail_notifs)) {
        $eeceiptRowsByBooking = fetchReceiptsForBookings(
            $db,
            array_map(static fn($row) => (int)($row['id'] ?? 0), $avail_notifs)
        );
    }

    $unread_count = count(array_filter($avail_notifs, fn($n) => !$n['is_read']));
} catch(Exception $e) {}

// â”€â”€ Backfill DUAL control numbers for accepted bookings that don't have one yet â”€â”€
try {
    $needsCtel = array_filter($avail_notifs, fn($av) =>
        (empty($av['control_number']) || empty($av['provider_control_number'])) &&
        !in_array($av['status'], ['pending', 'cancelled'])
    );
    foreach ($needsCtel as &$av) {
        $newCtel     = !empty($av['control_number'])          ? $av['control_number']          : 'PCF-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $newProviderCtel = !empty($av['provider_control_number']) ? $av['provider_control_number'] : 'PCV-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $db->prepare(
            "UPDATE availed_services
             SET control_number          = CASE WHEN (control_number IS NULL OR control_number='')          THEN :ctrl  ELSE control_number END,
                 provider_control_number = CASE WHEN (provider_control_number IS NULL OR provider_control_number='') THEN :pctel ELSE provider_control_number END
             WHERE id = :id AND provider_id = :pid"
        )->execute([':ctrl' => $newCtel, ':pctel' => $newProviderCtel, ':id' => $av['id'], ':pid' => $provider_id]);

        $av['control_number']          = $newCtel;
        $av['provider_control_number'] = $newProviderCtel;

        // Send seeker notification if not already sent
        if (!empty($av['seeker_user_id'])) {
            $aleeadySent = $db->prepare(
                "SELECT id FROM seeker_notifications
                 WHERE avail_id = :aid AND seeker_user_id = :suid AND message LIKE '%PCF-%'
                 LIMIT 1"
            );
            $aleeadySent->execute([':aid' => $av['id'], ':suid' => $av['seeker_user_id']]);
            if (!$aleeadySent->fetch()) {
                $ctelMsg = "Your service request for \"" . ($av['service_name'] ?? 'Service') . "\" has been ACCEPTED. "
                         . "Your Seeker Control Number is: " . $newCtel . ". "
                         . "On service day, keep this code ready because the provider will verify this seeker code before service starts.";
                $db->prepare(
                    "INSERT INTO seeker_notifications (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                     VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                )->execute([
                    ':suid'  => $av['seeker_user_id'],
                    ':avid'  => $av['id'],
                    ':pid'   => $provider_id,
                    ':sname' => $av['service_name'] ?? '',
                    ':msg'   => $ctelMsg,
                ]);
            }
        }
    }
    unset($av);
} catch(Exception $e) {}

// â”€â”€ Status label & color helper â”€â”€
// statusInfo() moved to includes/booking_workflow_helper.php so
// provider-portal/my-services.php's own View Details modal (a read-only
// mirror of this page's modal, for field techs) can share the exact same
// label/color map instead of a second copy that could drift.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Requests - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
        body { background: #f5f7fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { width: 100%; padding: 24px; }

        /* â”€â”€ Toast Notification â”€â”€ */
        .toast {
            position: fixed; top: 24px; right: 24px; z-index: 99999;
            min-width: 300px; max-width: 460px; padding: 16px 20px;
            border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,0.15);
            display: flex; align-items: center; gap: 14px;
            font-weight: 600; font-size: 14px;
            animation: slideInToast 0.35s ease, fadeOutToast 0.5s ease 3.5s forwards;
        }
        .toast-status    { background: #e6eef7; color: #1a2d42; border-left: 5px solid #2d6a9f; }
        .toast-completed { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .toast-accepted  { background: #c0f5d8; color: #0a6640; border-left: 5px solid #10b759; }
        .toast-cancelled { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        .toast-error     { background: #fff3cd; color: #856404; border-left: 5px solid #ffc107; }
        .toast-icon  { font-size: 20px; flex-shrink: 0; }
        .toast-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 16px; opacity: 0.6; padding: 0; }
        .toast-close:hover { opacity: 1; }
        @keyframes slideInToast { from { opacity:0; transform:translateX(60px); } to { opacity:1; transform:translateX(0); } }
        @keyframes fadeOutToast  { from { opacity:1; } to { opacity:0; pointer-events:none; } }

        /* â”€â”€ View Details Button â”€â”€ */
        .btn-view-details {
            background: linear-gradient(135deg, #2d6a9f, #1a4f7a);
            color: white; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(45,106,159,0.35);
        }
        .btn-view-details:hover { background: linear-gradient(135deg, #245a8a, #163f63); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,159,0.45); }

        /* â”€â”€ Accept / Cancel Buttons â”€â”€ */
        .btn-accept {
            background: linear-gradient(135deg, #10b759, #0a9648);
            color: white; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(10,150,72,0.35);
        }
        .btn-accept:hover { background: linear-gradient(135deg, #0da852, #097a3a); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(10,150,72,0.45); }

        .btn-cancel-req {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(192,57,43,0.35);
        }
        .btn-cancel-req:hover { background: linear-gradient(135deg, #d44133, #a93226); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(192,57,43,0.45); }

        /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
           â”€â”€ VIEW DETAILS MODAL â”€â”€
        â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
        .viewdetails-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(10, 20, 40, 0.65);
            backdrop-filter: blue(4px);
            z-index: 10001;
            align-items: center; justify-content: center;
            padding: 20px;
        }
        .viewdetails-overlay.active { display: flex; }

        .viewdetails-box {
            background: white; border-radius: 20px;
            max-width: 560px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: popIn 0.25s cubic-bezier(0.34,1.56,0.64,1);
            overflow: hidden;
            max-height: 90vh;
            display: flex; flex-direction: column;
        }

        /* Header band */
        .vd-header {
            background: linear-gradient(135deg, #1e2d40, #2d4a6b);
            padding: 24px 28px 20px;
            color: white;
            position: relative;
            flex-shrink: 0;
        }
        .vd-header-top {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
        }
        .vd-badge-new {
            background: #e74c3c; color: white; font-size: 10px; font-weight: 800;
            padding: 3px 9px; border-radius: 20px; letter-spacing: 0.5px;
            animation: pulse 1.5s infinite; flex-shrink: 0; margin-top: 3px;
        }
        .vd-request-id {
            font-size: 12px; color: rgba(255,255,255,0.6); margin-bottom: 4px; font-weight: 600; letter-spacing: 0.5px;
        }
        .vd-service-title {
            font-size: 20px; font-weight: 800; color: white; line-height: 1.3;
        }
        .vd-close-btn {
            background: rgba(255,255,255,0.15); border: none; color: white;
            width: 34px; height: 34px; border-radius: 50%; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0; transition: background 0.2s;
        }
        .vd-close-btn:hover { background: rgba(255,255,255,0.3); }

        .vd-status-row {
            margin-top: 14px; display: flex; align-items: center; gap: 10px;
        }
        .vd-status-pill {
            padding: 5px 13px; border-radius: 20px; font-size: 12px; font-weight: 700;
        }
        .vd-submitted-time {
            font-size: 11px; color: rgba(255,255,255,0.5);
            display: flex; align-items: center; gap: 5px;
        }

        /* Body — scrollable middle, header and footer stay fixed */
        .vd-body { padding: 24px 28px; overflow-y: auto; flex: 1; min-height: 0; }

        /* Section label */
        .vd-section-label {
            font-size: 10px; font-weight: 800; letter-spacing: 1px;
            text-transform: uppercase; color: #aab; margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .vd-section-label::after {
            content: ''; flex: 1; height: 1px; background: #err;
        }

        /* Info grid */
        .vd-info-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;
        }
        .vd-info-card {
            background: #f8fafc; border: 1.5px solid #e8edf2; border-radius: 12px;
            padding: 14px 16px; display: flex; gap: 12px; align-items: flex-start;
        }
        .vd-info-card.full-width { grid-column: 1 / -1; }
        .vd-info-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; flex-shrink: 0;
        }
        .vd-info-icon.blue   { background: #dbeafe; color: #1d4ed8; }
        .vd-info-icon.green  { background: #dcfce7; color: #16a34a; }
        .vd-info-icon.orange { background: #ffedd5; color: #ea580c; }
        .vd-info-icon.purple { background: #ede9fe; color: #7c3aed; }
        .vd-info-icon.teal   { background: #ccfbf1; color: #0d9488; }
        .vd-info-label {
            font-size: 11px; font-weight: 700; color: #9aa; text-transform: uppercase;
            letter-spacing: 0.4px; margin-bottom: 4px;
        }
        .vd-info-value {
            font-size: 14px; font-weight: 700; color: #1a1a2e; line-height: 1.4;
        }
        .vd-info-value.light {
            font-weight: 500;
            color: #444;
            white-space: per-line;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .address-cell{
            width: 190px;
            min-width: 190px;
            max-width: 190px;
            vertical-align: top;
        }

        .address-stack{
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-height: 88px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .address-line{
            display: block;
            font-size: 13px;
            line-height: 1.35;
            color: #334155;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .vd-address-stack{
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .vd-address-line{
            display: block;
            line-height: 1.4;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        /* Map link */
        .vd-map-link {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 6px; font-size: 12px; font-weight: 700;
            color: #2d6a9f; text-decoration: none; transition: color 0.2s;
        }
        .vd-map-link:hover { color: #1a4f7a; text-decoration: underline; }

        /* Footer actions */
        .vd-footer {
            padding: 16px 28px 24px;
            border-top: 1.5px solid #f0f4f8;
            display: flex; gap: 10px; flex-wrap: wrap;
            flex-shrink: 0;
        }
        .vd-btn-accept {
            flex: 1; min-width: 120px; padding: 13px 20px;
            background: linear-gradient(135deg, #10b759, #0a9648);
            color: white; border: none; border-radius: 10px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s; box-shadow: 0 3px 10px rgba(10,150,72,0.3);
        }
        .vd-btn-accept:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(10,150,72,0.4); }
        .vd-btn-decline {
            flex: 1; min-width: 120px; padding: 13px 20px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white; border: none; border-radius: 10px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s; box-shadow: 0 3px 10px rgba(192,57,43,0.3);
        }
        .vd-btn-decline:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(192,57,43,0.4); }
        .vd-btn-close {
            padding: 13px 20px; background: #f0f4f8; color: #555;
            border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px;
        }
        .vd-btn-close:hover { background: #e2e8f0; }

        /* â”€â”€ Accept Modal â”€â”€ */
        .accept-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 10002;
            align-items: center; justify-content: center;
        }
        .accept-overlay.active { display: flex; }
        .accept-box {
            background: white; border-radius: 18px; padding: 36px 30px 28px;
            max-width: 430px; width: 92%; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: popIn 0.22s ease;
        }
        .accept-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, #d4edda, #c0f5d8);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 32px; color: #0a9648;
        }
        .accept-title { font-size: 20px; font-weight: 800; color: #1a1a2e; margin-bottom: 8px; }
        .accept-subtitle { font-size: 13px; color: #888; line-height: 1.6; margin-bottom: 22px; }
        .accept-info-card {
            background: #f0fff6; border: 1.5px solid #b8f0ce; border-radius: 12px;
            padding: 14px 18px; margin-bottom: 22px; text-align: left;
        }
        .accept-info-row { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 8px; font-size: 13px; color: #333; }
        .accept-info-row:last-child { margin-bottom: 0; }
        .accept-info-row i { color: #0a9648; margin-top: 2px; flex-shrink: 0; width: 14px; }
        .accept-info-label { font-weight: 700; color: #0a6640; margin-right: 4px; }
        .accept-notif-note {
            display: flex; gap: 10px; align-items: flex-start;
            background: #e8f4fd; border-radius: 10px; padding: 12px 14px;
            font-size: 12px; color: #1a6ea3; margin-bottom: 22px; text-align: left;
        }
        .accept-notif-note i { flex-shrink: 0; margin-top: 2px; }
        .accept-actions { display: flex; gap: 10px; justify-content: center; }
        .accept-btn-confirm {
            flex: 1; padding: 12px; background: linear-gradient(135deg, #10b759, #0a9648);
            color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 3px 10px rgba(10,150,72,0.35);
        }
        .accept-btn-confirm:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(10,150,72,0.45); }
        .accept-btn-close {
            padding: 12px 22px; background: #f0f0f0; color: #555;
            border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
        }
        .accept-btn-close:hover { background: #e0e0e0; }

        /* â”€â”€ Cancel Request Modal â”€â”€ */
        .cancelreq-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 10002;
            align-items: center; justify-content: center;
        }
        .cancelreq-overlay.active { display: flex; }
        .cancelreq-box {
            background: white; border-radius: 18px; padding: 36px 30px 28px;
            max-width: 430px; width: 92%; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: popIn 0.22s ease;
        }
        .cancelreq-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, #fdecea, #f8d7da);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 32px; color: #c0392b;
        }
        .cancelreq-title { font-size: 20px; font-weight: 800; color: #1a1a2e; margin-bottom: 8px; }
        .cancelreq-subtitle { font-size: 13px; color: #888; line-height: 1.6; margin-bottom: 20px; }
        .cancelreq-label { font-size: 13px; font-weight: 700; color: #555; text-align: left; margin-bottom: 6px; }
        .cancelreq-reasons { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .cancelreq-reason-chip {
            padding: 7px 13px; border-radius: 20px; font-size: 12px; font-weight: 600;
            border: 2px solid #e0e0e0; background: #f8f8f8; color: #555; cursor: pointer; transition: all 0.2s;
        }
        .cancelreq-reason-chip:hover, .cancelreq-reason-chip.selected {
            border-color: #e74c3c; background: #fdecea; color: #c0392b;
        }
        .cancelreq-textarea {
            width: 100%; padding: 12px 14px; border: 1.5px solid #ddd;
            border-radius: 10px; font-size: 13px; font-family: inherit;
            resize: vertical; min-height: 80px; box-sizing: border-box;
            transition: border 0.2s; color: #333; margin-bottom: 18px;
        }
        .cancelreq-textarea:focus { outline: none; border-color: #e74c3c; box-shadow: 0 0 0 3px rgba(231,76,60,0.1); }
        .cancelreq-notif-note {
            display: flex; gap: 10px; align-items: flex-start;
            background: #fff3f3; border-radius: 10px; padding: 12px 14px;
            font-size: 12px; color: #c0392b; margin-bottom: 22px; text-align: left;
            border: 1px solid #f8d7da;
        }
        .cancelreq-notif-note i { flex-shrink: 0; margin-top: 2px; }
        .cancelreq-actions { display: flex; gap: 10px; justify-content: center; }
        .cancelreq-btn-confirm {
            flex: 1; padding: 12px; background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 3px 10px rgba(192,57,43,0.35);
        }
        .cancelreq-btn-confirm:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(192,57,43,0.45); }
        .cancelreq-btn-close {
            padding: 12px 22px; background: #f0f0f0; color: #555;
            border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
        }
        .cancelreq-btn-close:hover { background: #e0e0e0; }

        /* â”€â”€ Step Steppee â”€â”€ */
        .step-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 11px; border-radius: 6px; font-size: 11px; font-weight: 700;
            white-space: nowrap; border: 2px solid transparent; transition: all 0.2s;
        }
        .step-pill.done    { opacity: 0.38; font-weight: 600; }
        .step-pill.current { opacity: 1; box-shadow: 0 2px 8px rgba(0,0,0,0.13); border-color: rgba(0,0,0,0.10); transform: scale(1.07); }
        .step-pill.future  { opacity: 0.22; font-weight: 600; }

        /* â”€â”€ Error / Lock Modal â”€â”€ */
        .lock-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99997; align-items: center; justify-content: center; }
        .lock-overlay.active { display: flex; }
        .lock-box { background: white; border-radius: 18px; padding: 36px 30px 28px; max-width: 420px; width: 92%; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: popIn 0.22s ease; }
        .lock-icon { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 30px; }
        .lock-title { font-size: 18px; font-weight: 800; color: #1a1a2e; margin-bottom: 10px; }
        .lock-reasons { text-align: left; margin: 16px 0 20px; }
        .lock-reason-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 13px; font-weight: 600; }
        .lock-reason-item.fail { background: #fdecea; color: #c0392b; }
        .lock-reason-item.pass { background: #d4edda; color: #155724; }
        .lock-reason-item i { margin-top: 1px; flex-shrink: 0; }
        .lock-close-btn { padding: 11px 32px; background: #1e2d40; color: white; border: none; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
        .lock-close-btn:hover { background: #16253a; }

        /* â”€â”€ GPS Modal â”€â”€ */
        .gps-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 99998; align-items: center; justify-content: center; }
        .gps-overlay.active { display: flex; }
        .gps-box { background: white; border-radius: 18px; padding: 36px 30px 28px; max-width: 440px; width: 92%; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: popIn 0.22s ease; }
        .gps-spinner { width: 60px; height: 60px; border-radius: 50%; border: 5px solid #e0e7ef; border-top-color: #1e2d40; animation: spin 0.9s linear infinite; margin: 0 auto 18px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .gps-title { font-size: 17px; font-weight: 800; color: #1a2d40; margin-bottom: 8px; }
        .gps-subtitle { font-size: 13px; color: #888; line-height: 1.6; margin-bottom: 20px; }
        .gps-address-box { background: #f4f6f9; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #444; margin-bottom: 20px; text-align: left; display: flex; gap: 10px; align-items: flex-start; }
        .gps-address-box i { color: #1e2d40; margin-top: 2px; flex-shrink: 0; }
        .gps-result { display: none; padding: 14px; border-radius: 10px; font-size: 14px; font-weight: 700; margin-bottom: 18px; }
        .gps-result.success { background: #d4edda; color: #155724; display: flex; align-items: center; gap: 8px; justify-content: center; }
        .gps-result.fail    { background: #fdecea; color: #c0392b; display: flex; align-items: center; gap: 8px; justify-content: center; }
        .gps-manual-check { display: none; text-align: left; margin-bottom: 18px; padding: 14px; background: #fff8e1; border-radius: 10px; border: 2px solid #ffc107; }
        .gps-manual-check label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13px; color: #444; font-weight: 600; }
        .gps-manual-check input[type=checkbox] { width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0; accent-color: #1e2d40; }
        .gps-actions { display: flex; gap: 10px; justify-content: center; }
        .gps-btn-primary { padding: 11px 28px; background: #1e2d40; color: white; border: none; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px; }
        .gps-btn-primary:hover { background: #16253a; }
        .gps-btn-primary:disabled { background: #adb5bd; cursor: not-allowed; }
        .gps-btn-cancel { padding: 11px 22px; background: #f0f0f0; color: #555; border: none; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .gps-btn-cancel:hover { background: #e0e0e0; }
        .step-completed-badge { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: #d4edda; color: #155724; border-radius: 8px; font-size: 12px; font-weight: 700; }

        /* â”€â”€ Confirm Modal â”€â”€ */
        /* â”€â”€ Control Number Button â”€â”€ */
        .btn-verify-ctrl {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(243,156,18,0.4);
        }
        .btn-verify-ctrl:hover { background: linear-gradient(135deg, #e08e0b, #d35400); transform: translateY(-1px); }
        .ctrl-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #1e2d40; color: #fff; font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 6px; font-family: monospace; letter-spacing: .5px;
        }
        /* â”€â”€ Control Number Modal â”€â”€ */
        .ctrl-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 10003; align-items: center; justify-content: center; }
        .ctrl-overlay.active { display: flex; }
        .ctrl-box {
            background: white; border-radius: 20px; padding: 38px 32px 30px;
            max-width: 440px; width: 92%; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: popIn 0.24s ease;
        }
        .ctrl-icon {
            width: 76px; height: 76px; border-radius: 50%;
            background: linear-gradient(135deg, #fff3cd, #fde68a);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px; font-size: 34px; color: #d97706;
            box-shadow: 0 6px 18px rgba(217,119,6,0.2);
        }
        .ctrl-title  { font-size: 20px; font-weight: 800; color: #1a1a2e; margin-bottom: 8px; }
        .ctrl-subtitle { font-size: 13px; color: #888; line-height: 1.6; margin-bottom: 22px; }
        .ctrl-input {
            width: 100%; padding: 14px 18px; border: 2px solid #d1d5db;
            border-radius: 10px; font-size: 18px; font-weight: 700; font-family: monospace;
            text-align: center; text-transform: uppercase; letter-spacing: 2px;
            box-sizing: border-box; transition: border 0.2s; color: #1a1a2e;
            margin-bottom: 8px;
        }
        .ctrl-input:focus { outline: none; border-color: #f39c12; box-shadow: 0 0 0 3px rgba(243,156,18,0.15); }
        .ctrl-error { color: #e74c3c; font-size: 13px; font-weight: 600; min-height: 20px; margin-bottom: 16px; display: none; }
        .ctrl-actions { display: flex; gap: 10px; justify-content: center; }
        .ctrl-btn-verify {
            flex: 1; padding: 12px; background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 3px 10px rgba(243,156,18,0.35); transition: all 0.2s;
        }
        .ctrl-btn-verify:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(243,156,18,0.45); }
        .ctrl-btn-close { padding: 12px 22px; background: #f0f0f0; color: #555; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .ctrl-btn-close:hover { background: #e0e0e0; }
        /* â”€â”€ Bottom toast â”€â”€ */
        .msg-toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: #1e2d40; color: #fff; padding: 14px 24px; border-radius: 12px;
            font-size: 14px; font-weight: 600; z-index: 99999;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
            animation: slideUpToast 0.35s ease, fadeOutToast 0.5s ease 3.5s forwards;
        }
        @keyframes slideUpToast { from{opacity:0;transform:translateX(-50%) translateY(20px)} to{opacity:1;transform:translateX(-50%) translateY(0)} }

        .confirm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9998; align-items: center; justify-content: center; }
        .confirm-overlay.active { display: flex; }
        .confirm-box { background: white; border-radius: 18px; padding: 36px 30px 28px; max-width: 420px; width: 92%; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: popIn 0.22s ease; }
        @keyframes popIn { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .confirm-step-flow { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 18px 0 22px; flex-wrap: wrap; }
        .confirm-step-box { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; }
        .confirm-step-arrow { font-size: 20px; color: #1e2d40; }
        .confirm-title { font-size: 19px; font-weight: 800; color: #1a1a2e; margin-bottom: 6px; }
        .confirm-subtitle { font-size: 13px; color: #888; line-height: 1.5; }
        .confirm-actions { display: flex; gap: 10px; justify-content: center; margin-top: 22px; }
        .confirm-btn { padding: 11px 28px; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s; }
        .confirm-btn-yes { background: #1e2d40; color: white; }
        .confirm-btn-yes:hover { background: #16253a; transform: translateY(-1px); }
        .confirm-btn-no { background: #f0f0f0; color: #555; }
        .confirm-btn-no:hover { background: #e0e0e0; }

        /* -- Archive Confirm Modal -- */
        .archive-confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.55);
            backdrop-filter: blue(2px);
            z-index: 100003;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .archive-confirm-overlay.active { display: flex; }
        .archive-confirm-box {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border: 1px solid #dbe5f1;
            border-radius: 14px;
            box-shadow: 0 20px 44px rgba(15,23,42,0.28);
            padding: 20px;
            animation: popIn 0.2s ease;
        }
        .archive-confirm-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .archive-confirm-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .archive-confirm-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
        }
        .archive-confirm-text {
            font-size: 13px;
            color: #475569;
            line-height: 1.55;
            margin: 0 0 12px;
        }
        .archive-confirm-meta {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            color: #334155;
            margin-bottom: 14px;
        }
        .archive-confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .archive-btn-cancel {
            padding: 9px 14px;
            border-radius: 8px;
            border: 1px solid #dbe5f1;
            background: #f1f5f9;
            color: #475569;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .archive-btn-cancel:hover { background: #e8eef6; }
        .archive-btn-confirm {
            padding: 9px 14px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(37,99,235,0.3);
        }
        .archive-btn-confirm:hover { filter: brightness(1.04); }

        /* â”€â”€ Page Header â”€â”€ */
        .page-header { background: #fff; padding: 20px 24px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); width: 100%; flex-wrap: wrap; gap: 12px; }
        .header-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

        /* â”€â”€ Notification Bell â”€â”€ */
        .notif-bell { position: relative; cursor: pointer; }
        .notif-bell-btn { background: #e8edf3; border: 1.5px solid #c0cdd9; border-radius: 10px; padding: 10px 14px; font-size: 18px; color: #1e2d40; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px; position: relative; }
        .notif-bell-btn:hover { background: #d0dae6; }
        .notif-badge { position: absolute; top: -7px; right: -7px; background: #e74c3c; color: white; font-size: 11px; font-weight: 700; border-radius: 50%; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; border: 2px solid white; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }
        .notif-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 360px; background: white; border-radius: 14px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); z-index: 9999; overflow: hidden; border: 1px solid #e8edf2; }
        .notif-dropdown.open { display: block; animation: deopIn 0.2s ease; }
        @keyframes deopIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .notif-header { padding: 14px 18px; background: linear-gradient(135deg, #1e2d40, #16253a); color: white; display: flex; justify-content: space-between; align-items: center; }
        .notif-header-title { font-weight: 700; font-size: 14px; }
        .notif-mark-read { font-size: 12px; color: rgba(255,255,255,0.85); text-decoration: none; background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 20px; transition: background 0.2s; }
        .notif-mark-read:hover { background: rgba(255,255,255,0.35); color: white; }
        .notif-list { max-height: 380px; overflow-y: auto; }
        .notif-item { padding: 14px 18px; border-bottom: 1px solid #f0f4f8; display: flex; gap: 12px; align-items: flex-start; transition: background 0.15s; }
        .notif-item:hover { background: #f8fbff; }
        .notif-item.unread { background: #eef2f7; border-left: 3px solid #1e2d40; }
        .notif-item.accepted-notif { border-left: 3px solid #10b759 !important; }
        .notif-item.cancelled-notif { border-left: 3px solid #e74c3c !important; }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon { width: 38px; height: 38px; border-radius: 50%; background: #e6eef7; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #1e2d40; flex-shrink: 0; }
        .notif-item.unread .notif-icon { background: #1e2d40; color: white; }
        .notif-item.accepted-notif .notif-icon { background: #c0f5d8; color: #0a9648; }
        .notif-item.cancelled-notif .notif-icon { background: #fdecea; color: #c0392b; }
        .notif-item.completed-notif .notif-icon { background: #d4edda; color: #28a745; }
        .notif-content { flex: 1; min-width: 0; }
        .notif-name { font-weight: 700; font-size: 13px; color: #1a1a2e; margin-bottom: 2px; }
        .notif-detail { font-size: 12px; color: #666; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-time { font-size: 11px; color: #aaa; margin-top: 4px; display: flex; align-items: center; gap: 4px; }
        .notif-unread-dot { width: 8px; height: 8px; background: #1e2d40; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
        .notif-empty { text-align: center; padding: 32px 20px; color: #aaa; font-size: 13px; }
        .notif-empty i { font-size: 36px; margin-bottom: 8px; display: block; color: #ddd; }

        .availed-section { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-top: 24px; overflow: hidden; width: 100%; display: block; }
        .availed-section-header { padding: 20px 28px; background: linear-gradient(135deg, #1e2d40, #16253a); color: white; display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px; flex-wrap: wrap; }
        .availed-table-wrap { overflow-x: auto; width: 100%; display: block; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; table-layout: auto; }
        th { text-align: left; background: #f4f6f9; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; padding: 16px 18px; border-bottom: 2px solid #e4e9f0; color: #555; white-space: nowrap; }
        td { padding: 18px 18px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; font-size: 15px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafcff; }
        @media (max-width: 768px) { .container { padding: 16px; } .page-header { flex-direction: column; align-items: flex-start; } th, td { padding: 12px 10px; font-size: 13px; } }
        .status-badge { padding: 7px 14px; border-radius: 999px; font-size: 13px; font-weight: 700; display: inline-block; }
        .availed-badge-new { background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; animation: pulse 1.5s infinite; display: inline-block; margin-top: 4px; }
        .emergency-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #ffe3e3; color: #b42318;
            border: 1px solid #fda29b; border-radius: 999px;
            padding: 4px 9px; font-size: 10px; font-weight: 800;
            margin-top: 6px;
        }
        .emergency-badge.accepted {
            background: #dcfce7; color: #166534; border-color: #86efac;
        }
        .actions-cell { min-width: 290px; }
        .action-panel {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 240px;
        }
        .action-primary-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .action-flow-card {
            background: linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
            border: 1px solid #d8e3f0;
            border-radius: 16px;
            padding: 12px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .action-flow-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .action-flow-title {
            font-size: 12px;
            font-weight: 800;
            color: #15314f;
            letter-spacing: 0.02em;
        }
        .action-flow-subtitle {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.45;
        }
        .action-flow-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            gap: 8px;
            align-items: stretch;
            margin-top: 10px;
        }
        .action-flow-state {
            background: #fff;
            border: 1px solid #d9e3ee;
            border-radius: 12px;
            padding: 10px;
            min-width: 0;
        }
        .action-flow-state-label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #7c8da3;
            margin-bottom: 7px;
        }
        .action-flow-state .step-pill {
            width: 100%;
            justify-content: center;
            text-align: center;
            padding: 8px 10px;
            border-radius: 999px;
        }
        .action-flow-state .step-pill.current,
        .action-flow-state .step-pill.future {
            transform: none;
            box-shadow: none;
        }
        .action-flow-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: #dce8f5;
            color: #33506f;
            align-self: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        .action-main-btn,
        .action-secondary-row .btn-message,
        .action-secondary-row .btn-archive,
        .action-primary-stack .btn-emergency-accept,
        .action-primary-stack .btn-view-details,
        .action-meta .btn-verify-ctrl {
            width: 100%;
            justify-content: center;
        }
        .action-main-btn {
            border: none;
            border-radius: 12px;
            padding: 11px 14px;
            background: linear-gradient(135deg, #1e2d40, #254566);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(30,45,64,0.18);
            transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
        }
        .action-main-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(30,45,64,0.24);
            filter: brightness(1.03);
        }
        .action-secondary-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-secondary-row > * {
            flex: 1 1 120px;
        }
        .action-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: stretch;
        }
        .action-meta > * {
            flex: 1 1 130px;
        }
        .action-meta .ctrl-badge,
        .action-meta .step-completed-badge {
            justify-content: center;
        }
        .action-note {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #4b5563;
            font-size: 11px;
            line-height: 1.45;
        }
        .action-note i {
            color: #2563eb;
            margin-top: 1px;
            flex-shrink: 0;
        }
        .action-note.action-note-warning {
            background: #fff8e1;
            border-color: #f5d36b;
            color: #9a6700;
        }
        .action-note.action-note-success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }
        .workflow-state-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 11px;
            font-weight: 800;
            border: 1px solid #dbe7f5;
            background: #f8fbff;
            color: #22415f;
        }
        .workflow-state-badge.pending {
            background: #fff8e1;
            border-color: #f5d36b;
            color: #9a6700;
        }
        .workflow-ghost-btn {
            border: 1px solid #cfdbea;
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
            color: #17324d;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .workflow-ghost-btn:hover {
            transform: translateY(-1px);
            border-color: #9db6d5;
            background: #f8fbff;
            box-shadow: 0 8px 18px rgba(31, 54, 85, 0.12);
        }
        .workflow-ghost-btn.compact {
            padding: 9px 11px;
        }
        .reschedule-inline-card {
            display: grid;
            gap: 12px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid #dbe7f5;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
        }
        .reschedule-inline-card.is-hidden {
            display: none;
        }
        .reschedule-inline-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .reschedule-inline-head strong {
            color: #17324d;
            font-size: 13px;
        }
        .reschedule-inline-head p {
            margin: 3px 0 0;
            font-size: 11px;
            color: #64748b;
            line-height: 1.45;
        }
        .reschedule-state-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .reschedule-state-banner.pending {
            background: #fff8e1;
            border: 1px solid #f5d36b;
            color: #9a6700;
        }
        .reschedule-state-banner.rejected {
            background: #f8fafc;
            border: 1px solid #d9e3ee;
            color: #475569;
        }
        .reschedule-inline-form {
            display: grid;
            gap: 12px;
        }
        .reschedule-inline-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .reschedule-field {
            display: grid;
            gap: 6px;
        }
        .reschedule-field span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }
        .reschedule-field input,
        .reschedule-field textarea {
            width: 100%;
            border: 1px solid #d5deea;
            border-radius: 10px;
            padding: 10px 11px;
            font: inherit;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        .reschedule-field input:focus,
        .reschedule-field textarea:focus {
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.14);
        }
        .reschedule-inline-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .reschedule-inline-actions > * {
            flex: 1 1 140px;
        }
        .reschedule-submit-btn {
            border: none;
            border-radius: 12px;
            padding: 10px 12px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(37,99,235,0.2);
            transition: all 0.2s ease;
        }
        .reschedule-submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(37,99,235,0.24);
        }
        .btn-emergency-accept {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff; border: none; border-radius: 7px;
            padding: 7px 12px; font-size: 12px; font-weight: 800; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 10px rgba(220,38,38,0.32);
        }
        .btn-emergency-accept:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(220,38,38,0.42);
            filter: brightness(1.04);
        }
        .btn-message {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 7px 13px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(37,99,235,0.28);
        }
        .btn-message:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.36);
        }
        .btn-archive {
            background: #eef2f7;
            color: #334155;
            border: 1px solid #d5deea;
            border-radius: 7px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        .btn-archive:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        @media (max-width: 768px) {
            .actions-cell { min-width: 250px; }
            .action-panel { min-width: 220px; }
            .action-flow-grid { grid-template-columns: 1fr; }
            .action-flow-arrow { width: 100%; height: 28px; }
            .action-secondary-row > *,
            .action-meta > * { flex-basis: 100%; }
            .reschedule-inline-grid { grid-template-columns: 1fr; }
        }

        /* â”€â”€ Message Modal â”€â”€ */
        .msg-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blue(2px);
            z-index: 9997;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .msg-overlay.active { display: flex; }
        .msg-box {
            background: #fff;
            border: 1px solid #dbe5f1;
            border-radius: 14px;
            padding: 22px;
            max-width: 460px;
            width: 100%;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.24);
            animation: popIn 0.22s ease;
        }
        .msg-header { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .msg-header-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #2563eb;
            flex-shrink: 0;
        }
        .msg-header-info h3 { font-size: 16px; font-weight: 700; color: #1a1a2e; margin-bottom: 2px; }
        .msg-header-info p  { font-size: 12px; color: #888; }
        .msg-textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #d0dae6;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 110px;
            box-sizing: border-box;
            transition: border 0.2s, box-shadow 0.2s;
            color: #1f2937;
            background: #fff;
        }
        .msg-textarea:focus {
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.14);
        }
        .msg-char-count { font-size: 11px; color: #aaa; text-align: right; margin-top: 4px; }
        .msg-contact-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 10px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            color: #475569;
        }
        .msg-contact-row i { color: #2563eb; }
        .msg-actions { display: flex; gap: 10px; margin-top: 18px; }
        .msg-btn-send {
            flex: 1;
            padding: 11px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .msg-btn-send:hover { filter: brightness(1.04); }
        .msg-btn-send:disabled { opacity: 0.65; cursor: not-allowed; filter: grayscale(0.15); }
        .msg-btn-cancel {
            padding: 11px 20px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #dbe5f1;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .msg-btn-cancel:hover { background: #e8eef6; }

        /* Clean floating notice */
        .clean-toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 100001;
            max-width: 370px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fff;
            border: 1px solid #dbe5f1;
            border-left: 4px solid #16a34a;
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.2);
            color: #0f172a;
            font-size: 13px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .clean-toast.show { opacity: 1; transform: translateY(0); }
        .clean-toast.success i { color: #16a34a; }
        .clean-toast.error { border-left-color: #dc2626; }
        .clean-toast.error i { color: #dc2626; }
        .clean-toast.info { border-left-color: #2563eb; }
        .clean-toast.info i { color: #2563eb; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-weight: 600; font-size: 14px; }
        .btn-secondary { background: #1e2d40; color: white; transition: background 0.2s; }
        .btn-secondary:hover { background: #16253a; }
        @media (max-width: 640px) { .container { padding: 12px; } .notif-dropdown { width: 300px; right: -60px; } .page-header { flex-direction: column; gap: 12px; align-items: flex-start; } th, td { padding: 10px 8px; font-size: 12px; } .vd-info-grid { grid-template-columns: 1fr; } }

        /* Pending row highlight */
        tr.row-pending td { background: #fffdf0; }
        tr.row-emergency td { background: #fff5f5; }
    </style>
    <style>
        :root{
            --ui-primary:#0ea5e9;
            --ui-primary-dark:#0369a1;
            --ui-secondary:#10b981;
            --ui-ink:#0f172a;
            --ui-muted:#64748b;
            --ui-glow:0 20px 40px rgba(15,23,42,.08);
        }

        body{
            font-family:'Manrope',sans-serif;
            color:var(--ui-ink);
            background:
                radial-gradient(circle at 10% -10%, rgba(14,165,233,.18), transparent 35%),
                radial-gradient(circle at 95% 5%, rgba(16,185,129,.14), transparent 28%),
                linear-gradient(180deg,#f8fbff 0%,#f1f6fb 100%);
        }

        .dashboard-container{display:flex;min-height:100vh;}

        .sidebar{
            width:260px;
            background:linear-gradient(165deg,#0b1a3a 0%,#132f57 55%,#0d3a58 100%);
            color:#fff;
            position:fixed;
            height:100vh;
            overflow-y:auto;
            border-right:1px solid rgba(255,255,255,.14);
            box-shadow:0 12px 35px rgba(2,6,23,.28);
        }

        .sidebar-header{padding:25px 20px;border-bottom:1px solid rgba(255,255,255,.14);background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.02));}
        .sidebar-header h2{font-family:'Space Grotesk',sans-serif;font-size:28px;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.4px;}
        .sidebar-header p{font-size:12px;color:rgba(255,255,255,.78);margin-top:5px;}

        .sidebar-menu{list-style:none;padding:15px 0;}
        .sidebar-menu li{margin-bottom:2px;}
        .sidebar-menu a{
            display:flex;
            align-items:center;
            gap:10px;
            margin:4px 12px;
            padding:12px 14px;
            color:rgba(255,255,255,.93);
            text-decoration:none;
            border-radius:12px;
            font-weight:600;
            position:relative;
            overflow:hidden;
            transition:all .25s ease;
        }
        .sidebar-menu a i{width:18px;text-align:center;}
        .sidebar-menu a::before{
            content:'';
            position:absolute;
            left:0;top:0;bottom:0;
            width:0;
            background:linear-gradient(180deg,var(--ui-primary),var(--ui-secondary));
            border-radius:10px;
            transition:width .25s ease;
        }
        .sidebar-menu a:hover,
        .sidebar-menu a.active{
            background:rgba(255,255,255,.16);
            backdrop-filter:blur(3px);
        }
        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before{width:4px;}

        .sidebar-notif-badge{
            background:#ef4444;
            color:#fff;
            font-size:10px;
            font-weight:700;
            border-radius:50%;
            min-width:18px;
            height:18px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            margin-left:auto;
        }

        .sidebar-footer{
            position:absolute;
            bottom:0;
            width:100%;
            padding:20px;
            border-top:1px solid rgba(255,255,255,.14);
        }
        .user-profile{
            display:flex;
            align-items:center;
            gap:12px;
            padding:14px;
            border-radius:12px;
            border:1px solid rgba(255,255,255,.16);
            background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.07));
        }
        .user-avatar{
            width:42px;height:42px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            font-weight:800;background:#fff;color:#0b3a64;
            overflow:hidden;
            flex-shrink:0;
        }
        .user-avatar.has-photo{
            background:transparent;
            color:transparent;
        }
        .user-avatar-img{
            width:100%;
            height:100%;
            border-radius:50%;
            object-fit:cover;
            display:block;
        }
        .user-info h4{font-size:13px;color:#fff;margin:0 0 2px;}
        .user-info p{font-size:11px;color:rgba(255,255,255,.8);margin:0;}

        .main-content{
            flex:1;
            margin-left:260px;
            padding:34px 34px 90px;
        }

        .container{padding:0;}

        .page-header{
            border-radius:18px;
            border:1px solid #dae7f3;
            box-shadow:var(--ui-glow);
            background:linear-gradient(180deg,#fff 0%,#fcfeff 100%);
            margin-bottom:20px;
        }

        .page-header h1{
            font-family:'Space Grotesk',sans-serif;
            letter-spacing:-.4px;
        }

        .availed-section{
            border-radius:18px;
            border:1px solid #dae7f3;
            box-shadow:var(--ui-glow);
            background:linear-gradient(180deg,#fff 0%,#fcfeff 100%);
        }

        .availed-section-header{
            background:linear-gradient(135deg,#0f2747,#184a73);
        }

        th{
            background:#f2f8ff;
            color:#3b536f;
            border-bottom:1px solid #d9e7f3;
            font-size:11px;
        }

        td{border-bottom-color:#e7edf4;}
        tr:hover td{background:#f7fbff;}

        .btn-secondary{
            background:#fff;
            border:1px solid #d6e3ef;
            color:#1e3a5f;
            border-radius:11px;
        }

        .btn-secondary:hover{
            background:#f3f9ff;
            border-color:#bbd9ef;
        }

        .notif-bell-btn{
            background:#f0f7ff;
            border:1px solid #d6e6f5;
            color:#1e3a5f;
            border-radius:11px;
        }

        .notif-bell-btn:hover{background:#e4f0fb;}

        @media (max-width:1024px){
            .sidebar{width:220px;}
            .main-content{margin-left:220px;}
        }

        @media (max-width:768px){
            .sidebar{position:relative;width:100%;height:auto;}
            .sidebar-footer{position:relative;}
            .main-content{margin-left:0;padding:20px 16px 78px;}
        }
    </style>
</head>
<body>

    <!-- â”€â”€ Server-side Toast â”€â”€ -->
    <?php if($action_msg): ?>
        <?php
chdir(dirname(__DIR__));
            $toastClass = match($action_type) {
                'accepted'  => 'toast-accepted',
                'cancelled' => 'toast-cancelled',
                'completed' => 'toast-completed',
                'error'     => 'toast-error',
                default     => 'toast-status',
            };
            $toastIcon = match($action_type) {
                'accepted'  => 'fa-check-circle',
                'cancelled' => 'fa-times-circle',
                'completed' => 'fa-check-circle',
                'error'     => 'fa-exclamation-circle',
                default     => 'fa-sync-alt',
            };
            $toastTitle = $action_label !== ''
                ? $action_label
                : (($action_type === 'accepted') ? 'Request Accepted' : (($action_type === 'cancelled') ? 'Request Cancelled' : 'Status Updated'));
        ?>
        <div class="toast <?php echo $toastClass; ?>" id="actionToast">
            <span class="toast-icon"><i class="fas <?php echo $toastIcon; ?>"></i></span>
            <div>
                <div style="font-weight:700;margin-bottom:2px;">
                    <?php echo htmlspecialchars($toastTitle); ?>
                </div>
                <div style="font-weight:400;font-size:13px;"><?php echo htmlspecialchars($action_msg); ?></div>
            </div>
            <button class="toast-close" onclick="document.getElementById('actionToast').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         â”€â”€ VIEW DETAILS MODAL â”€â”€
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <div class="viewdetails-overlay" id="viewDetailsOverlay">
        <div class="viewdetails-box">

            <!-- Header -->
            <div class="vd-header">
                <div class="vd-header-top">
                    <div style="flex:1;">
                        <div class="vd-request-id" id="vdRequestId">Request #-</div>
                        <div class="vd-service-title" id="vdServiceTitle">Service Name</div>
                    </div>
                    <span class="vd-badge-new" id="vdBadgeNew" style="display:none;">NEW</span>
                    <button class="vd-close-btn" onclick="closeViewDetails()"><i class="fas fa-times"></i></button>
                </div>
                <div class="vd-status-row">
                    <span class="vd-status-pill" id="vdStatusPill">Pending</span>
                    <span class="vd-submitted-time"><i class="fas fa-clock"></i><span id="vdSubmittedTime">-</span></span>
                </div>
            </div>

            <!-- Body -->
            <div class="vd-body">

                <!-- Customer Info -->
                <div class="vd-section-label"><i class="fas fa-user"></i> Customer Information</div>
                <div class="vd-info-grid">
                    <div class="vd-info-card">
                        <div class="vd-info-icon blue"><i class="fas fa-user"></i></div>
                        <div>
                            <div class="vd-info-label">Full Name</div>
                            <div class="vd-info-value" id="vdFullName">-</div>
                        </div>
                    </div>
                    <div class="vd-info-card">
                        <div class="vd-info-icon green"><i class="fas fa-phone"></i></div>
                        <div>
                            <div class="vd-info-label">Contact Number</div>
                            <div class="vd-info-value" id="vdContact">-</div>
                        </div>
                    </div>
                </div>

                <!-- Schedule -->
                <div class="vd-section-label"><i class="fas fa-calendar-alt"></i> Schedule</div>
                <div class="vd-info-grid">
                    <div class="vd-info-card">
                        <div class="vd-info-icon orange"><i class="fas fa-calendar-day"></i></div>
                        <div>
                            <div class="vd-info-label">Preferred Date</div>
                            <div class="vd-info-value" id="vdDate">-</div>
                        </div>
                    </div>
                    <div class="vd-info-card">
                        <div class="vd-info-icon purple"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="vd-info-label">Preferred Time</div>
                            <div class="vd-info-value" id="vdTime">-</div>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="vd-section-label"><i class="fas fa-map-marker-alt"></i> Service Address</div>
                <div class="vd-info-grid" style="margin-bottom:16px;">
                    <div class="vd-info-card full-width">
                        <div class="vd-info-icon teal"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="vd-info-label">Address</div>
                            <div class="vd-info-value light" id="vdAddeess">-</div>
                            <a class="vd-map-link" id="vdMapLink" href="#" target="_blank" rel="noopener">
                                <i class="fas fa-external-link-alt"></i> View on Google Maps
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Inspection (only for services requiring on-site inspection first) -->
                <div class="vd-section-label" id="vdInspectionLabel" style="display:none;"><i class="fas fa-magnifying-glass"></i> Inspection &amp; Working Date</div>
                <div class="vd-info-grid" id="vdInspectionSection" style="display:none;margin-bottom:16px;"></div>

                <!-- Payment -->
                <div class="vd-section-label" id="vdPaymentLabel"><i class="fas fa-receipt"></i> Payment</div>
                <div class="vd-info-grid" id="vdPaymentSection" style="margin-bottom:16px;"></div>

                <!-- Control Numbers -->
                <div class="vd-section-label" id="vdCodesLabel" style="display:none;"><i class="fas fa-key"></i> Control Numbers</div>
                <div class="vd-info-grid" id="vdCodesSection" style="display:none;margin-bottom:16px;"></div>

                <!-- Verification -->
                <div class="vd-section-label" id="vdVerifyLabel" style="display:none;"><i class="fas fa-shield-alt"></i> Verification</div>
                <div class="vd-info-grid" id="vdVerifySection" style="display:none;margin-bottom:16px;"></div>

                <!-- Workflow Status (non-pending active) -->
                <div class="vd-section-label" id="vdWorkflowLabel" style="display:none;"><i class="fas fa-tasks"></i> Workflow Status</div>
                <div id="vdWorkflowSection" style="display:none;margin-bottom:12px;"></div>

                <!-- Reschedule form -->
                <div id="vdRescheduleSection" style="display:none;margin-bottom:12px;"></div>

            </div>

            <!-- Footer Actions -->
            <div class="vd-footer" id="vdFootee">
                <!-- Buttons injected by JS depending on status -->
            </div>

        </div>
    </div>

    <!-- â”€â”€ Accept Modal â”€â”€ -->
    <div class="accept-overlay" id="acceptOverlay">
        <div class="accept-box">
            <div class="accept-icon"><i class="fas fa-check-circle"></i></div>
            <div class="accept-title">Accept This Request?</div>
            <div class="accept-subtitle">Accepting will generate <strong>two unique control numbers</strong> - one for the seeker, one for your technician. Both must be entered on service day to start.</div>
            <div class="accept-info-card">
                <div class="accept-info-row">
                    <i class="fas fa-user"></i>
                    <div><span class="accept-info-label">Customer:</span><span id="acceptCustomerName">-</span></div>
                </div>
                <div class="accept-info-row">
                    <i class="fas fa-tools"></i>
                    <div><span class="accept-info-label">Service:</span><span id="acceptServiceName">-</span></div>
                </div>
                <div class="accept-info-row">
                    <i class="fas fa-calendar-alt"></i>
                    <div><span class="accept-info-label">Scheduled:</span><span id="acceptScheduled">-</span></div>
                </div>
                <div class="accept-info-row" style="margin-top:4px;padding-top:8px;border-top:1px solid #c0f5d8;">
                    <i class="fas fa-key" style="color:#f59e0b;"></i>
                    <div style="font-size:12px;color:#555;">
                        <span style="color:#0a6640;font-weight:700;">PCF-...</span> â†’ sent to seeker &nbsp;|&nbsp;
                        <span style="color:#166534;font-weight:700;">Provider verifies PCF-...</span>
                    </div>
                </div>
            </div>
            <div class="accept-notif-note">
                <i class="fas fa-shield-alt"></i>
                <span>The seeker will receive their <strong>Seeker Control Number (PCF-...)</strong>. On service day, use that seeker code in verification to proceed. <em>Both seeker verification and provider verification are required to unlock the service.</em></span>
            </div>
            <div class="accept-actions">
                <button class="accept-btn-close" onclick="closeAccept()"><i class="fas fa-times"></i> Cancel</button>
                <form method="POST" style="flex:1;display:flex;" id="acceptForm">
                    <input type="hidden" name="accept_cancel_action" value="accepted">
                    <input type="hidden" name="avail_id" id="acceptAvailId" value="">
                    <button type="submit" class="accept-btn-confirm" style="width:100%;">
                        <i class="fas fa-check-circle"></i> Yes, Accept &amp; Generate Codes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- â”€â”€ Cancel Request Modal â”€â”€ -->
    <div class="cancelreq-overlay" id="cancelreqOverlay">
        <div class="cancelreq-box">
            <div class="cancelreq-icon"><i class="fas fa-times-circle"></i></div>
            <div class="cancelreq-title">Cancel This Request?</div>
            <div class="cancelreq-subtitle">Please select a reason or provide details. The seeker will be notified about the cancellation.</div>

            <div class="cancelreq-label">Quick Reason:</div>
            <div class="cancelreq-reasons" id="cancelReasonChips">
                <span class="cancelreq-reason-chip" onclick="selectReason(this, 'Schedule conflict')">Schedule conflict</span>
                <span class="cancelreq-reason-chip" onclick="selectReason(this, 'No available staff')">No available staff</span>
                <span class="cancelreq-reason-chip" onclick="selectReason(this, 'Outside service area')">Outside service area</span>
                <span class="cancelreq-reason-chip" onclick="selectReason(this, 'Incomplete information')">Incomplete info</span>
                <span class="cancelreq-reason-chip" onclick="selectReason(this, 'Equipment unavailable')">Equipment unavailable</span>
            </div>

            <div class="cancelreq-label">Additional Details (optional):</div>
            <textarea class="cancelreq-textarea" id="cancelReasonText" placeholder="Type your reason or additional message to the seeker..." maxlength="300"></textarea>

            <div class="cancelreq-notif-note">
                <i class="fas fa-bell"></i>
                <span>The seeker will receive a cancellation notification with your reason included.</span>
            </div>

            <div class="cancelreq-actions">
                <button class="cancelreq-btn-close" onclick="closeCancelReq()"><i class="fas fa-arrow-left"></i> Back</button>
                <form method="POST" style="flex:1;display:flex;" id="cancelreqForm">
                    <input type="hidden" name="accept_cancel_action" value="cancelled">
                    <input type="hidden" name="avail_id"      id="cancelreqAvailId" value="">
                    <input type="hidden" name="cancel_reason" id="cancelreqReason"  value="">
                    <button type="submit" class="cancelreq-btn-confirm" style="width:100%;" onclick="prepareCancelSubmit()">
                        <i class="fas fa-times-circle"></i> Confirm Cancellation
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- â”€â”€ Confirm Modal (step advance) â”€â”€ -->
    <div class="confirm-overlay" id="confirmOverlay">
        <div class="confirm-box">
            <div class="confirm-title">Advance to Next Step?</div>
            <div class="confirm-subtitle" id="confirmSubtitle">For <strong id="confirmCustomerName"></strong></div>
            <div class="confirm-step-flow">
                <div class="confirm-step-box" id="confirmFromBox" style="background:#f0f0f0;color:#555;">Current</div>
                <div class="confirm-step-arrow"><i class="fas fa-arrow-right"></i></div>
                <div class="confirm-step-box" id="confirmToBox" style="background:#d0dff0;color:#1a2d42;">Next</div>
            </div>
            <div class="confirm-actions">
                <button class="confirm-btn confirm-btn-no" onclick="closeConfirm()"><i class="fas fa-times"></i> Cancel</button>
                <form method="POST" style="display:inline;" id="confirmForm">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="avail_id"   id="confirmAvailId"  value="">
                    <input type="hidden" name="new_status" id="confirmNewStatus" value="">
                    <button type="submit" class="confirm-btn confirm-btn-yes"><i class="fas fa-arrow-right"></i> Yes, Advance</button>
                </form>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-bug"></i> Pestify</h2>
                <p>Provider Portal</p>
            </div>
            <ul class="sidebar-menu">
                <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="<?php echo appUrl('services.php'); ?>"><i class="fas fa-briefcase"></i> My Services</a></li>
                <li>
                    <a href="<?php echo appUrl('service-requests.php'); ?>" class="active">
                        <i class="fas fa-list-check"></i> Requests
                        <?php if($unread_count > 0): ?>
                            <span class="sidebar-notif-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="<?php echo appUrl('messages.php'); ?>"><i class="fas fa-comments"></i> Messages</a></li>
                <li><a href="<?php echo appUrl('profile.php'); ?>"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>?view=settings#dashboard-settings"><i class="fas fa-sliders-h"></i> Settings</a></li>
                <li><a href="<?php echo appUrl('logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
            <div class="sidebar-footer">
                <div class="user-profile">
                    <?php
                    // profile_image is a bare app-root-relative path — must be resolved
                    // to an absolute URL before rendering here (this file lives one
                    // directory below the app root). logo_url is already a full external
                    // URL a provider pastes in, so it's left untouched.
                    $provider_avatar = trim((string)($sidebar_provider['profile_image'] ?? ''));
                    if ($provider_avatar !== '') { $provider_avatar = siteUrl($provider_avatar); }
                    if ($provider_avatar === '') { $provider_avatar = trim((string)($sidebar_provider['logo_url'] ?? '')); }
                    ?>
                    <div class="user-avatar <?php echo $provider_avatar !== '' ? 'has-photo' : ''; ?>">
                        <?php if ($provider_avatar !== ''): ?>
                            <img src="<?php echo htmlspecialchars($provider_avatar); ?>" alt="Profile Photo" class="user-avatar-img">
                        <?php else: ?>
                            <?php echo strtoupper(substr((string)$sidebar_provider['company_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars((string)$sidebar_provider['company_name']); ?></h4>
                        <p><?php echo htmlspecialchars((string)$sidebar_provider['email']); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="container">

        <!-- â”€â”€ Page Header â”€â”€ -->
        <div class="page-header">
            <div>
                <h1 style="margin:0; font-size:22px;">Service Requests</h1>
                <p style="margin:4px 0 0; color:#7f8c8d;">Requests assigned to your company</p>
            </div>
            <div class="header-actions">
                <!-- Bell -->
                <div class="notif-bell" id="notifBell">
                    <button class="notif-bell-btn" onclick="toggleNotif(event)">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notif-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDeopdown">
                        <div class="notif-header">
                            <span class="notif-header-title">
                                <i class="fas fa-bell"></i> Notifications
                                <?php if($unread_count > 0): ?>&nbsp;Â·&nbsp;<?php echo $unread_count; ?> new<?php endif; ?>
                            </span>
                            <?php if($unread_count > 0): ?>
                                <a href="?mark_read=1" class="notif-mark-read">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-list">
                            <?php if(count($avail_notifs) > 0): ?>
                                <?php foreach($avail_notifs as $notif): ?>
                                    <?php
chdir(dirname(__DIR__));
                                        $nClass = !$notif['is_read'] ? 'unread' : '';
                                        if($notif['status'] === 'completed') $nClass .= ' completed-notif';
                                        elseif($notif['status'] === 'accepted') $nClass .= ' accepted-notif';
                                        elseif($notif['status'] === 'cancelled') $nClass .= ' cancelled-notif';
                                        $si = statusInfo($notif['status']);
                                    ?>
                                    <div class="notif-item <?php echo trim($nClass); ?>">
                                        <div class="notif-icon">
                                            <?php if($notif['status'] === 'completed'): ?>
                                                <i class="fas fa-check-circle"></i>
                                            <?php elseif($notif['status'] === 'accepted'): ?>
                                                <i class="fas fa-thumbs-up"></i>
                                            <?php elseif($notif['status'] === 'cancelled'): ?>
                                                <i class="fas fa-times-circle"></i>
                                            <?php else: ?>
                                                <i class="fas fa-hand-holding-usd"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-name"><?php echo htmlspecialchars($notif['full_name']); ?></div>
                                            <div class="notif-detail">
                                                <?php echo htmlspecialchars($notif['service_name'] ?? 'Service'); ?> &bull;
                                                <?php echo htmlspecialchars($notif['contact_number']); ?>
                                            </div>
                                            <div class="notif-detail" style="color:<?php echo $si['color']; ?>;font-weight:600;">
                                                <?php echo htmlspecialchars($si['label']); ?>
                                            </div>
                                            <div class="notif-time">
                                                <?php
chdir(dirname(__DIR__));
                                                    $diff = time() - strtotime($notif['created_at']);
                                                    if ($diff < 60) echo 'just now';
                                                    elseif ($diff < 3600) echo floor($diff/60) . 'm ago';
                                                    elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                                                    else echo date('M d', strtotime($notif['created_at']));
                                                ?>
                                            </div>
                                        </div>
                                        <?php if(!$notif['is_read']): ?>
                                            <div class="notif-unread-dot"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">
                                    <i class="fas fa-bell-slash"></i>
                                    No notifications yet
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <a href="<?php echo appUrl('providers-dashboard.php'); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- â”€â”€ Availed Services Table â”€â”€ -->
        <?php if(count($avail_notifs) > 0): ?>
        <div class="availed-section">
            <div class="availed-section-header">
                <i class="fas fa-clipboard-list"></i>
                Availed Service Requests
                <span style="background:rgba(255,255,255,0.25);padding:3px 10px;border-radius:20px;font-size:12px;margin-left:auto;">
                    <?php echo count($avail_notifs); ?> total
                </span>
                <?php if($unread_count > 0): ?>
                    <span style="background:#e74c3c;padding:3px 10px;border-radius:20px;font-size:12px;">
                        <?php echo $unread_count; ?> new
                    </span>
                <?php endif; ?>
            </div>
            <div class="availed-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Date &amp; Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($avail_notifs as $av):
                            $si = statusInfo($av['status']);
                            $isPending = ($av['status'] === 'pending');
                            $isEmergency = !empty($av['emergency_now_requested']) && !in_array($av['status'], ['completed', 'cancelled'], true);
                            $isEmergencyAccepted = $isEmergency && !empty($av['emergency_now_accepted_at']);
                            $eeceiptRows = $eeceiptRowsByBooking[(int)($av['id'] ?? 0)] ?? [];
                            $eowClasses = [];
                            if ($isPending) $eowClasses[] = 'row-pending';
                            if ($isEmergency && !$isEmergencyAccepted) $eowClasses[] = 'row-emergency';

                            // Compute all data needed by the View Details modal
                            $isStepServiceDay = !empty($av['preferred_date']) && $av['preferred_date'] === date('Y-m-d');
                            $isStepTestServiceDay = $is_local_test_mode && !$isStepServiceDay && ((int)$av['id'] === $test_service_day_booking_id);
                            $isStepDualVerified = !empty($av['dual_verified_at']);
                            $paymentStatus_ = strtolower(trim((string)($av['payment_status'] ?? '')));
                            $requiresRemainingPayment = ($paymentStatus_ === 'partial');
                            $hasArrivalProof = !empty($av['provider_arrival_proof_photo']);
                            $rescheduleState = strtolower(trim((string)($av['reschedule_request_status'] ?? '')));
                            $isReschedulePending = $rescheduleState === 'pending' && !empty($av['reschedule_proposed_date']) && !empty($av['reschedule_proposed_time']);
                            $isRescheduleRejected = $rescheduleState === 'rejected' && !empty($av['reschedule_proposed_date']) && !empty($av['reschedule_proposed_time']);
                            $canReschedule = in_array(normalizeWorkflowStatus((string)$av['status']), ['accepted', 'preparing'], true);
                            $seekerVee = !empty($av['seeker_verified_at']);
                            $providerVee = !empty($av['provider_verified_at']);
                            $dualDone = !empty($av['dual_verified_at']);
                            $ctrl = $av['control_number'] ?? '';
                            $pctel = $av['provider_control_number'] ?? '';
                            $veeifiableStatuses = ['accepted','preparing','starting','ongoing','waiting_remaining_payment','waiting_seeker_confirmation','waiting_seeker_information','waiting_provider_confirmation'];
                            $isVerifiable = (bool)($ctrl && in_array($av['status'], $veeifiableStatuses));
                            $showEmergencyAcceptBtn = !empty($av['emergency_now_requested']) && empty($av['emergency_now_accepted_at']) && !in_array($av['status'], ['completed', 'cancelled'], true);

                            $flowStatus = normalizeWorkflowStatus((string)$av['status']);
                            $allSteps = [
                                ['key'=>'accepted',                      'label'=>'Accepted',                       'bg'=>'#c0f5d8','color'=>'#0a6640'],
                                ['key'=>'preparing',                     'label'=>'Preparing',                      'bg'=>'#d1ecf1','color'=>'#0c5460'],
                                ['key'=>'starting',                      'label'=>'Starting',                       'bg'=>'#d6eaf8','color'=>'#1b4f72'],
                                ['key'=>'ongoing',                       'label'=>'Ongoing',                        'bg'=>'#c3e6cb','color'=>'#155724'],
                                ['key'=>'waiting_remaining_payment',     'label'=>'Waiting Payment',                'bg'=>'#fde8d8','color'=>'#7d3200'],
                                ['key'=>'waiting_provider_confirmation', 'label'=>'Awaiting Seeker Confirmation',   'bg'=>'#d6eaf8','color'=>'#1a2d42'],
                            ];
                            $isTerminalStatus = in_array($flowStatus, ['completed', 'cancelled'], true);
                            $currentIdx = array_search($flowStatus, array_column($allSteps, 'key'));
                            if ($currentIdx === false) $currentIdx = 0;
                            $nextIdx = ($currentIdx < count($allSteps) - 1) ? $currentIdx + 1 : null;
                            $nextStep = $nextIdx !== null ? $allSteps[$nextIdx] : null;
                            $prevIdx = $currentIdx > 0 ? $currentIdx - 1 : null;
                            $prevStep = $prevIdx !== null ? $allSteps[$prevIdx] : null;
                            if (!$requiresRemainingPayment && (($allSteps[$currentIdx]['key'] ?? '') === 'ongoing')) {
                                foreach ($allSteps as $stepItem) {
                                    if (($stepItem['key'] ?? '') === 'waiting_provider_confirmation') { $nextStep = $stepItem; break; }
                                }
                            }
                            if (($allSteps[$currentIdx]['key'] ?? '') === 'waiting_provider_confirmation') $nextStep = null;
                            if (($allSteps[$currentIdx]['key'] ?? '') === 'starting') $nextStep = null; // QR scan handles starting→ongoing
                            if ($isReschedulePending) $nextStep = null;
                            $currentStep = $allSteps[$currentIdx] ?? $allSteps[0];
                            // 'completed'/'cancelled' aren't in $allSteps, so array_search above falls through
                            // to index 0 ("Accepted") for them — without this override, a completed/cancelled
                            // booking would show "Accepted" as its current step and "Preparing" as the next
                            // actionable step, contradicting its own status badge and offering a bogus advance button.
                            if ($isTerminalStatus) {
                                $nextStep = null;
                                $currentStep = $flowStatus === 'completed'
                                    ? ['key' => 'completed', 'label' => 'Completed', 'bg' => '#c3e6cb', 'color' => '#155724']
                                    : ['key' => 'cancelled', 'label' => 'Cancelled', 'bg' => '#e2e3e5', 'color' => '#41464b'];
                            }

                            // Inspection flow (opt-in per service, see CLAUDE.md's "Recent Work Log"):
                            // 'awaiting_agreement'/'revising' also aren't in $allSteps, so without this
                            // override they'd fall through to index 0 ("Accepted") the same way
                            // completed/cancelled used to — offering a bogus "Prepare Booking" button
                            // before the seeker has even agreed to a working date and final price.
                            $requiresInspection = !empty($av['service_requires_inspection']);
                            $inspectionAgreed = !empty($av['inspection_agreed_at']);
                            $needsInspectionReport = $requiresInspection && !$inspectionAgreed
                                && in_array($av['status'], ['accepted', 'revising'], true);
                            $awaitingAgreement = ($av['status'] === 'awaiting_agreement');
                            if ($needsInspectionReport || $awaitingAgreement) {
                                $nextStep = null;
                                $currentStep = $awaitingAgreement
                                    ? ['key' => 'awaiting_agreement', 'label' => 'Awaiting Seeker Decision', 'bg' => '#e8daef', 'color' => '#4a235a']
                                    : ['key' => 'revising', 'label' => $av['status'] === 'revising' ? 'Revising After Feedback' : 'Accepted', 'bg' => '#fde8d8', 'color' => '#7d3200'];
                            }

                            $actionNoteText = '';
                            if ($isReschedulePending) {
                                $actionNoteText = 'Waiting for the seeker to respond to your proposed reschedule.';
                            } elseif ($needsInspectionReport) {
                                $actionNoteText = $av['status'] === 'revising'
                                    ? 'The seeker requested changes — submit a revised inspection report.'
                                    : 'Submit an inspection report (photo, notes, and a final price) before this can move to Preparing.';
                            } elseif ($awaitingAgreement) {
                                $actionNoteText = 'Waiting for the seeker to agree to the proposed working date and price, or request changes.';
                            } elseif (($currentStep['key'] ?? '') === 'starting') {
                                $actionNoteText = 'Scan the seeker\'s QR code to move this booking to Ongoing.';
                            } elseif (($currentStep['key'] ?? '') === 'waiting_provider_confirmation') {
                                $actionNoteText = 'Waiting for the seeker to confirm service completion.';
                            } elseif ($av['status'] === 'completed') {
                                $actionNoteText = 'Workflow is complete. You can still message the seeker or archive this record.';
                            } elseif ($av['status'] === 'cancelled') {
                                $actionNoteText = 'This request is closed, but the record can still be archived.';
                            }
                            $rescheduleDateValue = !empty($av['reschedule_proposed_date']) ? date('Y-m-d', strtotime((string)$av['reschedule_proposed_date'])) : (!empty($av['preferred_date']) ? date('Y-m-d', strtotime((string)$av['preferred_date'])) : date('Y-m-d', strtotime('+1 day')));
                            $rescheduleTimeValue = !empty($av['reschedule_proposed_time']) ? date('H:i', strtotime((string)$av['reschedule_proposed_time'])) : (!empty($av['preferred_time']) ? date('H:i', strtotime((string)$av['preferred_time'])) : '09:00');
                            $rescheduleSummary = ($isReschedulePending || $isRescheduleRejected) ? date('M j, Y', strtotime((string)$av['reschedule_proposed_date'])) . ' at ' . date('g:i A', strtotime((string)$av['reschedule_proposed_time'])) : '';

                            $modalData = [
                                'id'            => (int)$av['id'],
                                'isRead'        => (bool)$av['is_read'],
                                'service'       => $av['service_name'] ?? '-',
                                'fullName'      => $av['full_name'],
                                'contact'       => $av['contact_number'],
                                'date'          => date('F d, Y', strtotime($av['preferred_date'])),
                                'time'          => date('h:i A', strtotime($av['preferred_time'])),
                                'rawDate'       => $av['preferred_date'],
                                'rawTime'       => $av['preferred_time'],
                                'address'       => (string)($av['address'] ?? ''),
                                'status'        => $av['status'],
                                'statusLabel'   => $si['label'],
                                'statusBg'      => $si['bg'],
                                'statusColor'   => $si['color'],
                                'submitted'     => date('M d, Y h:i A', strtotime($av['created_at'])),
                                'paymentRef'    => (string)($av['payment_record_reference'] ?? ''),
                                'paymentStatus' => ucfirst((string)($av['payment_record_status'] ?? '')),
                                'paymentType'   => ucwords(str_replace('_', ' ', (string)($av['payment_record_type'] ?? 'payment'))),
                                'paymentAmount' => number_format((float)($av['payment_record_amount'] ?? 0), 2),
                                'receipts'      => array_map(function($r) {
                                    return [
                                        'number' => (string)$r['receipt_number'],
                                        'type'   => paymentReceiptTypeLabel($r['payment_type'] ?? ''),
                                        'amount' => number_format((float)($r['amount'] ?? 0), 2),
                                        'paidAt' => !empty($r['paid_at']) ? date('M j, Y g:i A', strtotime((string)$r['paid_at'])) : 'N/A',
                                    ];
                                }, $eeceiptRows),
                                'seekerCtrl'             => $ctrl,
                                'providerCtrl'           => $pctel,
                                'seekerVerified'         => $seekerVee,
                                'providerVerified'       => $providerVee,
                                'dualVerified'           => $dualDone,
                                'isEmergency'            => (bool)$isEmergency,
                                'showEmergencyAcceptBtn' => (bool)$showEmergencyAcceptBtn,
                                'isPending'              => (bool)$isPending,
                                'isCompleted'            => ($av['status'] === 'completed'),
                                'isCancelled'            => ($av['status'] === 'cancelled'),
                                'canReschedule'          => (bool)$canReschedule,
                                'isReschedulePending'    => (bool)$isReschedulePending,
                                'isRescheduleRejected'   => (bool)$isRescheduleRejected,
                                'rescheduleSummary'      => $rescheduleSummary,
                                'rescheduleDateValue'    => $rescheduleDateValue,
                                'rescheduleTimeValue'    => $rescheduleTimeValue,
                                'rescheduleReason'       => (string)($av['reschedule_reason'] ?? ''),
                                'actionNoteText'         => $actionNoteText,
                                'isStarting'             => ($av['status'] === 'starting'),
                                'isServiceDay'           => (bool)$isStepServiceDay,
                                'isBeforeServiceDay'     => !empty($av['preferred_date']) && ($av['preferred_date'] > date('Y-m-d')),
                                'isTestServiceDay'       => (bool)$isStepTestServiceDay,
                                'isDualVerified'         => (bool)$isStepDualVerified,
                                'isVerifiable'           => (bool)$isVerifiable,
                                // Same "paid or at least a downpayment" bar
                                // verifyProviderSeekerCode() now enforces
                                // server-side — gates both "Start Early" and
                                // "Enter Seeker Code" in the footer below so
                                // the buttons aren't shown only to be
                                // rejected on click.
                                'isPaidEnough'           => in_array($paymentStatus_, ['paid', 'partial'], true),
                                'seekerVerifiedForCtel'  => (bool)$seekerVee,
                                'currentStepKey'         => $currentStep['key'] ?? '',
                                'currentStepLabel'       => $currentStep['label'] ?? '',
                                'currentStepBg'          => $currentStep['bg'] ?? '',
                                'currentStepColor'       => $currentStep['color'] ?? '',
                                'nextStepKey'            => $nextStep ? $nextStep['key'] : null,
                                'nextStepLabel'          => $nextStep ? $nextStep['label'] : null,
                                'nextStepBg'             => $nextStep ? $nextStep['bg'] : null,
                                'nextStepColor'          => $nextStep ? $nextStep['color'] : null,
                                'prevStepLabel'          => $prevStep ? $prevStep['label'] : null,
                                'prevStepBg'             => $prevStep ? $prevStep['bg'] : null,
                                'prevStepColor'          => $prevStep ? $prevStep['color'] : null,
                                'preferredDatetime'      => $av['preferred_date'] . ' ' . $av['preferred_time'],
                                'todayDate'              => date('Y-m-d'),
                                'assignedStaffId'        => (int)($av['service_assigned_staff_id'] ?? 0),
                                'assignedStaffName'      => (string)($av['service_assigned_staff_name'] ?? ''),
                                // This booking's OWN current assignment (as opposed to the
                                // service's default-suggested staff/equipment above) — used
                                // to prefill "Edit Equipment & Staff" on an already-Preparing
                                // booking with what it actually has, not the service defaults.
                                'isPreparing'                => ($av['status'] === 'preparing'),
                                'bookingAssignedEmployeeId'  => (int)($av['assigned_employee_id'] ?? 0),
                                'bookingCompanionEmployeeId' => (int)($av['companion_employee_id'] ?? 0),
                                'operationsNotes'            => (string)($av['operations_notes'] ?? ''),
                                'bookingAssignedEquipment'   => (function() use ($av) {
                                    $decoded = json_decode((string)($av['assigned_equipment'] ?? ''), true);
                                    return is_array($decoded) ? array_map(fn($i) => ['id' => (int)($i['inventory_item_id'] ?? 0), 'qty' => (int)($i['quantity_needed'] ?? 0)], $decoded) : [];
                                })(),
                                'bookingAssignedConsumables' => (function() use ($av) {
                                    $decoded = json_decode((string)($av['assigned_consumables'] ?? ''), true);
                                    return is_array($decoded) ? array_map(fn($i) => ['id' => (int)($i['inventory_item_id'] ?? 0), 'qty' => (int)($i['quantity_needed'] ?? 0)], $decoded) : [];
                                })(),
                                'serviceEquipment'       => (function() use ($av) {
                                    $pairs = array_filter(explode(',', (string)($av['service_equipment_ids'] ?? '')));
                                    $out = [];
                                    foreach ($pairs as $pair) {
                                        [$eqId, $eqQty] = array_pad(explode(':', $pair, 2), 2, 1);
                                        $out[] = ['id' => (int)$eqId, 'qty' => max(1, (int)$eqQty)];
                                    }
                                    return $out;
                                })(),
                                'requiresInspection'          => (bool)$requiresInspection,
                                'needsInspectionReport'       => (bool)$needsInspectionReport,
                                'isRevising'                  => ($av['status'] === 'revising'),
                                'awaitingAgreement'            => (bool)$awaitingAgreement,
                                'inspectionAgreed'             => (bool)$inspectionAgreed,
                                'inspectionDate'                => !empty($av['inspection_date']) ? date('F d, Y', strtotime((string)$av['inspection_date'])) : '',
                                'workingDate'                   => !empty($av['working_date']) ? date('F d, Y', strtotime((string)$av['working_date'])) : '',
                                'inspectionRound'               => (int)($av['inspection_round'] ?? 0),
                                'inspectionReportImage'         => !empty($av['inspection_report_image']) ? siteUrl($av['inspection_report_image']) : '',
                                'inspectionReportNotes'         => (string)($av['inspection_report_notes'] ?? ''),
                                'inspectionProposedPrice'       => number_format((float)($av['inspection_proposed_price'] ?? 0), 2),
                                'inspectionProposedWorkingDate' => !empty($av['inspection_proposed_working_date']) ? date('F d, Y', strtotime((string)$av['inspection_proposed_working_date'])) : '',
                                'inspectionChangeNotes'         => (string)($av['inspection_change_notes'] ?? ''),
                            ];
                        ?>
                            <tr class="<?php echo implode(' ', $eowClasses); ?>" style="<?php echo !$av['is_read'] ? 'background:#f0f7ff;' : ''; ?>">
                                <td><strong>#<?php echo (int)$av['id']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($av['full_name']); ?></strong><br>
                                    <small style="color:#888;"><i class="fas fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($av['contact_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($av['service_name'] ?? '-'); ?></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($av['preferred_date'])); ?><br>
                                    <small style="color:#888;"><?php echo date('h:i A', strtotime($av['preferred_time'])); ?></small>
                                </td>
                                <td>
                                    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">
                                        <span class="status-badge" style="background:<?php echo $si['bg']; ?>;color:<?php echo $si['color']; ?>;">
                                            <?php echo $si['label']; ?>
                                        </span>
                                        <?php if ($isEmergency): ?>
                                            <span class="emergency-badge <?php echo $isEmergencyAccepted ? 'accepted' : ''; ?>">
                                                <i class="fas <?php echo $isEmergencyAccepted ? 'fa-check-circle' : 'fa-bolt'; ?>"></i>
                                                <?php echo $isEmergencyAccepted ? 'Emergency Accepted' : 'Emergency Requested'; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!$av['is_read']): ?>
                                            <span class="availed-badge-new"><?php echo $av['status'] === 'pending' ? 'NEW' : 'UPDATED'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <button class="btn-view-details" data-avail-id="<?php echo (int)$av['id']; ?>" onclick='openViewDetails(<?php echo json_encode($modalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div style="text-align:center;padding:60px 20px;color:#aaa;background:white;border-radius:12px;margin-top:24px;box-shadow:0 2px 10px rgba(0,0,0,0.05);">
                <i class="fas fa-clipboard" style="font-size:48px;color:#ddd;margin-bottom:14px;display:block;"></i>
                <h3 style="color:#888;margin:0 0 8px;">No availed services yet</h3>
                <p style="margin:0;font-size:13px;">When customers avail your services, they will appear here.</p>
            </div>
        <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- â”€â”€ Control Number Verification Modal â”€â”€ -->
    <div class="ctrl-overlay" id="ctelOverlay">
        <div class="ctrl-box">
            <div class="ctrl-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="ctrl-title">Enter Seeker Code</div>
            <p class="ctrl-subtitle" id="ctelSubtitle">Enter the <strong>Seeker Control Number</strong> (PCF-...) below. The service will start only after <em>both</em> seeker verification and provider verification are completed.</p>
            <!-- Dual-step progress indicator -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:18px;justify-content:center;">
                <div id="ctelStepSeeker" style="display:flex;align-items:center;gap:6px;
                    padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;
                    background:#f3f4f6;color:#6b7280;">
                    <i class="fas fa-user"></i> Seeker Code
                </div>
                <i class="fas fa-plus" style="color:#9ca3af;font-size:12px;"></i>
                <div id="ctelStepProvider" style="display:flex;align-items:center;gap:6px;
                    padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;
                    background:#fef3c7;color:#92400e;border:2px solid #fbbf24;">
                    <i class="fas fa-shield-alt"></i> Enter Seeker Code
                </div>
                <i class="fas fa-equals" style="color:#9ca3af;font-size:12px;"></i>
                <div style="display:flex;align-items:center;gap:6px;
                    padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;
                    background:#dcfce7;color:#166534;">
                    <i class="fas fa-play-circle"></i> Start
                </div>
            </div>
            <form method="POST" id="ctelForm">
                <input type="hidden" name="verify_control_number" value="1">
                <input type="hidden" name="avail_id" id="ctelAvailId" value="">
                <input type="hidden" name="test_service_day_anytime" id="ctelTestModeAnytime" value="<?php echo $ctrl_allow_anytime_test ? '1' : '0'; ?>">
                <input type="hidden" name="early_start" id="ctelEarlyStart" value="0">
                <div id="ctelTestModeHint" style="display:<?php echo $ctrl_allow_anytime_test ? 'block' : 'none'; ?>;margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#fff8e1;border:1px solid #f5c518;color:#7a5700;font-size:12px;font-weight:600;">
                    <i class="fas fa-flask"></i> Test Service Day mode is active for this booking.
                </div>
                <div id="ctelEarlyStartHint" style="display:none;margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#eff6ff;border:1px solid #93c5fd;color:#1d4ed8;font-size:12px;font-weight:600;">
                    <i class="fas fa-clock"></i> Early start — verifying before the scheduled service date.
                </div>
                <input class="ctrl-input" type="text" name="control_number_input" id="ctelInput"
                    placeholder="e.g. PCF-2026-XXXXXX" maxlength="20" autocomplete="off"
                    oninput="this.value=this.value.toUpperCase(); document.getElementById('ctelErrorMsg').style.display='none';">
                <div class="ctrl-error" id="ctelErrorMsg"></div>
                <div class="ctrl-actions">
                    <button type="button" class="ctrl-btn-close" onclick="closeCtel()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="ctrl-btn-verify"><i class="fas fa-shield-alt"></i> Verify Seeker Code</button>
                </div>
            </form>
        </div>
    </div>

    <!-- â”€â”€ Lock / Error Modal â”€â”€ -->
    <div class="lock-overlay" id="lockOverlay">
        <div class="lock-box">
            <div class="lock-icon" style="background:#fdecea;"><i class="fas fa-lock" style="color:#c0392b;"></i></div>
            <div class="lock-title">Cannot Advance to "Starting" Yet</div>
            <div class="lock-reasons" id="lockReasons"></div>
            <button class="lock-close-btn" onclick="closeLock()"><i class="fas fa-times"></i> Got it</button>
        </div>
    </div>

    <!-- â”€â”€ GPS Check Modal â”€â”€ -->
    <div class="gps-overlay" id="gpsOverlay">
        <div class="gps-box">
            <div class="gps-spinner" id="gpsSpinner"></div>
            <div class="gps-title" id="gpsTitle">Verifying Your Location...</div>
            <div class="gps-subtitle" id="gpsSubtitle">Please allow location access so we can confirm you are at the seeker's address.</div>
            <div class="gps-address-box" id="gpsAddressBox" style="display:none;"><i class="fas fa-map-marker-alt"></i><span id="gpsAddressText"></span></div>
            <div class="gps-result" id="gpsResult"></div>
            <div class="gps-manual-check" id="gpsManualCheck">
                <label>
                    <input type="checkbox" id="gpsManualCheckbox" onchange="onManualCheck()">
                    I confirm that I am physically present at the seeker's location and ready to start the service.
                </label>
            </div>
            <div class="gps-actions">
                <button class="gps-btn-cancel" onclick="closeGps()"><i class="fas fa-times"></i> Cancel</button>
                <button class="gps-btn-primary" id="gpsProceedBtn" disabled onclick="proceedAfterGps()">
                    <i class="fas fa-arrow-right"></i> Proceed
                </button>
            </div>
        </div>
    </div>

    <!-- â”€â”€ Message Modal â”€â”€ -->
    <div class="msg-overlay" id="msgOverlay">
        <div class="msg-box">
            <div class="msg-header">
                <div class="msg-header-icon"><i class="fas fa-comment-dots"></i></div>
                <div class="msg-header-info">
                    <h3 id="msgRecipientName">Customer Name</h3>
                    <p id="msgServiceLabel">Service</p>
                </div>
            </div>
            <textarea class="msg-textarea" id="msgTextarea" placeholder="Type your message here..." maxlength="500" oninput="updateCharCount()"></textarea>
            <div class="msg-char-count"><span id="msgCharCount">0</span>/500</div>
            <div class="msg-contact-row">
                <i class="fas fa-phone"></i>
                <span id="msgContactNumber">-</span>
                <span style="color:#bbb;margin:0 4px;">Â·</span>
                <i class="fas fa-info-circle" style="font-size:11px;"></i>
                <span style="font-size:11px;color:#aaa;">Message will be saved and sent to the seeker</span>
            </div>
            <div class="msg-actions">
                <button class="msg-btn-cancel" onclick="closeMessage()"><i class="fas fa-times"></i> Cancel</button>
                <button class="msg-btn-send" onclick="sendMessage()"><i class="fas fa-paper-plane"></i> Send Message</button>
            </div>
        </div>
    </div>

    <!-- -- Archive Confirm Modal -- -->
    <div class="archive-confirm-overlay" id="archiveConfirmOverlay">
        <div class="archive-confirm-box">
            <div class="archive-confirm-head">
                <div class="archive-confirm-icon"><i class="fas fa-box-archive"></i></div>
                <h3 class="archive-confirm-title">Archive Request</h3>
            </div>
            <p class="archive-confirm-text" id="archiveConfirmText">
                Are you sure you want to archive this request?
            </p>
            <div class="archive-confirm-meta" id="archiveConfirmMeta">
                This action hides the request from the active list.
            </div>
            <div class="archive-confirm-actions">
                <button type="button" class="archive-btn-cancel" onclick="closeArchiveConfirm()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="archive-btn-confirm" onclick="submitArchiveConfirm()">
                    <i class="fas fa-check"></i> Yes, Archive
                </button>
            </div>
        </div>
    </div>

    <script>
        // â”€â”€ Bell toggle â”€â”€
        function toggleNotif(e) {
            e.stopPropagation();
            document.getElementById('notifDeopdown').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const bell = document.getElementById('notifBell');
            if (bell && !bell.contains(e.target))
                document.getElementById('notifDeopdown').classList.remove('open');
        });

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // â”€â”€ VIEW DETAILS MODAL â”€â”€
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        let _vdData = null;

        function openViewDetails(data) {
            _vdData = data;

            // Header
            document.getElementById('vdRequestId').textContent   = 'Request #' + data.id;
            document.getElementById('vdServiceTitle').textContent = data.service || '-';
            document.getElementById('vdBadgeNew').style.display  = (!data.isRead) ? 'inline-block' : 'none';
            const pill = document.getElementById('vdStatusPill');
            pill.textContent = data.statusLabel; pill.style.background = data.statusBg; pill.style.color = data.statusColor;
            document.getElementById('vdSubmittedTime').textContent = data.submitted;

            // Customer + Schedule
            document.getElementById('vdFullName').textContent = data.fullName;
            document.getElementById('vdContact').textContent  = data.contact;
            document.getElementById('vdDate').textContent = data.date;
            document.getElementById('vdTime').textContent  = data.time;

            // Address
            const vdAddeess = document.getElementById('vdAddeess');
            const rawAddress = (data.address || '').toString();
            const addressParts = rawAddress.split(/[\r\n,]+/).map(p => p.trim()).filter(Boolean);
            if (addressParts.length > 0) {
                vdAddeess.innerHTML = '<div class="vd-address-stack">' +
                    addressParts.map(p => '<span class="vd-address-line">' + escapeHtml(p) + '</span>').join('') + '</div>';
            } else { vdAddeess.textContent = '—'; }
            document.getElementById('vdMapLink').href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(rawAddress);

            // ── Inspection section ──
            const inspSection = document.getElementById('vdInspectionSection');
            const inspLabel   = document.getElementById('vdInspectionLabel');
            if (data.requiresInspection) {
                let ih = '';
                ih += `<div class="vd-info-card"><div class="vd-info-icon" style="background:#f5eef8;"><i class="fas fa-magnifying-glass" style="color:#4a235a;"></i></div><div><div class="vd-info-label">Inspection Date</div><div class="vd-info-value">${escapeHtml(data.inspectionDate || '-')}</div></div></div>`;
                ih += `<div class="vd-info-card"><div class="vd-info-icon" style="background:#eaf6ee;"><i class="fas fa-calendar-check" style="color:#0a6640;"></i></div><div><div class="vd-info-label">Working Date</div><div class="vd-info-value">${escapeHtml(data.workingDate || 'Not yet agreed')}</div></div></div>`;
                if (data.inspectionChangeNotes) {
                    ih += `<div class="vd-info-card full-width" style="background:#fff7ed;border-color:#fdba74;"><div style="font-size:11px;font-weight:700;color:#9a3412;margin-bottom:4px;"><i class="fas fa-comment-dots"></i> Seeker requested changes</div><div style="font-size:12px;color:#7c2d12;">${escapeHtml(data.inspectionChangeNotes)}</div></div>`;
                }
                if (data.inspectionReportImage || data.inspectionReportNotes) {
                    ih += `<div class="vd-info-card full-width" style="flex-direction:column;gap:8px;align-items:flex-start;">
                        <div style="font-size:11px;font-weight:700;color:#4a235a;">Submitted Report (round ${data.inspectionRound || 1})</div>`;
                    if (data.inspectionReportImage) {
                        ih += `<img src="${escapeHtml(data.inspectionReportImage)}" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid #e5e7eb;">`;
                    }
                    if (data.inspectionReportNotes) {
                        ih += `<div style="font-size:12px;color:#334155;">${escapeHtml(data.inspectionReportNotes)}</div>`;
                    }
                    ih += `<div style="font-size:12px;color:#0a6640;font-weight:700;">Proposed price: &#8369;${escapeHtml(data.inspectionProposedPrice || '0.00')} &middot; Proposed working date: ${escapeHtml(data.inspectionProposedWorkingDate || '-')}</div>`;
                    ih += `</div>`;
                }
                inspSection.innerHTML = ih;
                inspSection.style.display = ''; inspLabel.style.display = '';
            } else {
                inspSection.style.display = 'none'; inspLabel.style.display = 'none';
            }

            // ── Payment section ──
            const paySection = document.getElementById('vdPaymentSection');
            document.getElementById('vdPaymentLabel').style.display = '';
            if (data.paymentRef) {
                const sts = (data.paymentStatus || '').toLowerCase();
                const stsColor = sts === 'completed' ? '#166534' : '#92400e';
                let ph = `<div class="vd-info-card full-width" style="flex-direction:column;gap:8px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div class="vd-info-icon green"><i class="fas fa-receipt"></i></div>
                        <div>
                            <div class="vd-info-label">Payment Status</div>
                            <div class="vd-info-value" style="color:${stsColor};font-weight:700;">${escapeHtml(data.paymentStatus)}</div>
                        </div>
                    </div>
                    <div style="font-size:12px;color:#334155;">${escapeHtml(data.paymentType)} &middot; &#8369;${escapeHtml(data.paymentAmount)}</div>
                    <div style="font-size:11px;color:#64748b;">Ref: ${escapeHtml(data.paymentRef)}</div>`;
                if (data.receipts && data.receipts.length > 0) {
                    ph += `<details style="margin-top:4px;"><summary style="cursor:pointer;font-size:11px;font-weight:700;color:#1d4ed8;list-style:none;">${data.receipts.length} receipt${data.receipts.length > 1 ? 's' : ''}</summary><div style="margin-top:6px;display:grid;gap:6px;">`;
                    data.receipts.forEach(r => {
                        ph += `<div style="background:#fff;border:1px solid #dbe5f1;border-radius:8px;padding:7px 8px;"><div style="font-size:11px;font-weight:800;color:#0f172a;">${escapeHtml(r.number)}</div><div style="font-size:10px;color:#475569;">${escapeHtml(r.type)} &middot; &#8369;${escapeHtml(r.amount)}<br>${escapeHtml(r.paidAt)}</div></div>`;
                    });
                    ph += `</div></details>`;
                }
                ph += `</div>`;
                paySection.innerHTML = ph;
            } else {
                paySection.innerHTML = `<div class="vd-info-card full-width"><div style="font-size:12px;color:#9ca3af;"><i class="fas fa-receipt"></i> No payment record yet.</div></div>`;
            }

            // ── Control Numbers section ──
            const codesSection = document.getElementById('vdCodesSection');
            const codesLabel   = document.getElementById('vdCodesLabel');
            if ((data.seekerCtrl || data.providerCtrl) && data.isPaidEnough) {
                let ch = '<div class="vd-info-grid" style="width:100%;">';
                if (data.seekerCtrl) {
                    // Do NOT show the value — provider must get this code from the seeker in person
                    ch += `<div class="vd-info-card"><div class="vd-info-icon" style="background:#fef9c3;"><i class="fas fa-key" style="color:#ca8a04;"></i></div><div><div class="vd-info-label">Seeker Code</div><div class="vd-info-value" style="font-family:'Courier New',monospace;font-size:13px;font-weight:900;letter-spacing:3px;color:#9ca3af;">••••••••••</div><div style="font-size:10px;color:#9ca3af;margin-top:3px;">Get this from the seeker in person</div></div></div>`;
                }
                if (data.providerCtrl) {
                    ch += `<div class="vd-info-card"><div class="vd-info-icon" style="background:#dcfce7;"><i class="fas fa-shield-alt" style="color:#16a34a;"></i></div><div><div class="vd-info-label">Your Code (share with seeker)</div><div class="vd-info-value" style="font-family:'Courier New',monospace;font-size:12px;font-weight:900;letter-spacing:1px;">${escapeHtml(data.providerCtrl)}</div><button onclick="copyCtelCode('${escJs(data.providerCtrl)}',this)" style="background:none;border:1px solid #d1d5db;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:600;color:#6b7280;cursor:pointer;margin-top:4px;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-copy"></i> Copy</button></div></div>`;
                }
                ch += '</div>';
                codesSection.innerHTML = ch;
                codesSection.style.display = ''; codesLabel.style.display = '';
            } else {
                codesSection.style.display = 'none'; codesLabel.style.display = 'none';
            }

            // ── Verification section ──
            const verifySection = document.getElementById('vdVerifySection');
            const verifyLabel   = document.getElementById('vdVerifyLabel');
            if (data.seekerCtrl && data.isPaidEnough) {
                let vh = '';
                if (data.dualVerified) {
                    vh = `<div class="vd-info-card full-width"><span style="display:inline-flex;align-items:center;gap:6px;background:#d1fae5;color:#065f46;font-size:12px;font-weight:700;padding:6px 12px;border-radius:8px;"><i class="fas fa-check-double"></i> Both Verified</span></div>`;
                } else if (data.seekerVerified || data.providerVerified) {
                    vh = `<div class="vd-info-card full-width"><span style="font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;padding:3px 8px;border-radius:6px;display:inline-block;margin-bottom:6px;">Partial Verification</span><div style="display:flex;gap:14px;font-size:11px;margin-top:4px;"><span style="color:${data.seekerVerified?'#065f46':'#9ca3af'};"><i class="fas ${data.seekerVerified?'fa-check-circle':'fa-circle'}"></i> Seeker</span><span style="color:${data.providerVerified?'#065f46':'#9ca3af'};"><i class="fas ${data.providerVerified?'fa-check-circle':'fa-circle'}"></i> Provider</span></div></div>`;
                } else {
                    vh = `<div class="vd-info-card full-width"><span style="font-size:11px;color:#9ca3af;"><i class="fas fa-hourglass-half"></i> Awaiting verification</span></div>`;
                }
                verifySection.innerHTML = vh;
                verifySection.style.display = ''; verifyLabel.style.display = '';
            } else {
                verifySection.style.display = 'none'; verifyLabel.style.display = 'none';
            }

            // ── Workflow section (non-pending active) ──
            const workflowSection = document.getElementById('vdWorkflowSection');
            const workflowLabel   = document.getElementById('vdWorkflowLabel');
            const reschedSection  = document.getElementById('vdRescheduleSection');

            if (!data.isPending && !data.isCompleted && !data.isCancelled) {
                workflowLabel.style.display = ''; workflowSection.style.display = '';
                let wh = `<div class="action-flow-card"><div class="action-flow-top"><div><div class="action-flow-title">Workflow Progress</div><div class="action-flow-subtitle">Current and next status.</div></div>`;
                if (data.isReschedulePending) {
                    wh += `<span class="workflow-state-badge pending"><i class="fas fa-calendar-day"></i> Reschedule Pending</span>`;
                } else if (data.prevStepLabel) {
                    wh += `<span class="step-pill done" style="background:${escapeHtml(data.prevStepBg)};color:${escapeHtml(data.prevStepColor)};"><i class="fas fa-check" style="font-size:9px;"></i> ${escapeHtml(data.prevStepLabel)}</span>`;
                }
                wh += `</div><div class="action-flow-grid"><div class="action-flow-state"><span class="action-flow-state-label">Current</span><span class="step-pill current" style="background:${escapeHtml(data.currentStepBg)};color:${escapeHtml(data.currentStepColor)};">${escapeHtml(data.currentStepLabel)}</span></div><span class="action-flow-arrow"><i class="fas fa-arrow-right"></i></span><div class="action-flow-state"><span class="action-flow-state-label">Next</span>`;
                if (data.nextStepLabel) {
                    wh += `<span class="step-pill future" style="background:${escapeHtml(data.nextStepBg)};color:${escapeHtml(data.nextStepColor)};">${escapeHtml(data.nextStepLabel)}</span>`;
                } else {
                    wh += `<span class="step-pill future" style="background:#e5e7eb;color:#6b7280;">Waiting</span>`;
                }
                wh += `</div></div></div>`;
                if (data.actionNoteText) {
                    const noteIcon = data.currentStepKey === 'starting' ? 'fa-qrcode' : 'fa-hourglass-half';
                    wh += `<div class="action-note action-note-warning" style="margin-top:10px;"><i class="fas ${noteIcon}"></i><span>${escapeHtml(data.actionNoteText)}</span></div>`;
                }
                workflowSection.innerHTML = wh;

                if (data.canReschedule) {
                    reschedSection.style.display = '';
                    const formVisible = data.isReschedulePending ? '' : 'none';
                    let rh = `<button type="button" class="workflow-ghost-btn" style="width:100%;margin-top:8px;" onclick="document.getElementById('vdResForm').style.display=document.getElementById('vdResForm').style.display==='none'?'':'none';">
                        <i class="fas fa-calendar-day"></i> ${data.isReschedulePending ? 'Edit Reschedule Proposal' : 'Propose New Schedule'}
                    </button>
                    <div id="vdResForm" style="display:${formVisible};" class="reschedule-inline-card">
                        <div class="reschedule-inline-head"><div><strong>Reschedule Request</strong><p>Offer another available date when you cannot make the booked schedule.</p></div>`;
                    if (data.isReschedulePending) rh += `<span class="workflow-state-badge pending"><i class="fas fa-clock"></i> Pending</span>`;
                    rh += `</div>`;
                    if (data.isReschedulePending) {
                        rh += `<div class="reschedule-state-banner pending"><i class="fas fa-calendar-check"></i> Proposed: ${escapeHtml(data.rescheduleSummary)}</div>`;
                    } else if (data.isRescheduleRejected) {
                        rh += `<div class="reschedule-state-banner rejected"><i class="fas fa-rotate-left"></i> The last proposal was declined. Send a new one if needed.</div>`;
                    }
                    rh += `<form method="POST" class="reschedule-inline-form">
                        <input type="hidden" name="submit_reschedule_request" value="1">
                        <input type="hidden" name="avail_id" value="${Number(data.id)}">
                        <div class="reschedule-inline-grid">
                            <label class="reschedule-field"><span>New Date</span><input type="date" name="reschedule_date" min="${escapeHtml(data.todayDate)}" value="${escapeHtml(data.rescheduleDateValue)}" required></label>
                            <label class="reschedule-field"><span>New Time</span><input type="time" name="reschedule_time" value="${escapeHtml(data.rescheduleTimeValue)}" required></label>
                        </div>
                        <label class="reschedule-field"><span>Reason</span><textarea name="reschedule_reason" rows="3" maxlength="300" placeholder="Explain why the current booking date no longer works." required>${escapeHtml(data.rescheduleReason)}</textarea></label>
                        <div class="reschedule-inline-actions">
                            <button type="submit" class="reschedule-submit-btn"><i class="fas fa-paper-plane"></i> Send to Seeker</button>
                            <button type="button" class="workflow-ghost-btn compact" onclick="document.getElementById('vdResForm').style.display='none';"><i class="fas fa-xmark"></i> Hide</button>
                        </div>
                    </form></div>`;
                    reschedSection.innerHTML = rh;
                } else {
                    reschedSection.style.display = 'none';
                }
            } else {
                workflowLabel.style.display = 'none'; workflowSection.style.display = 'none';
                reschedSection.style.display = 'none'; reschedSection.innerHTML = '';
            }

            // ── Footer buttons (use _vdData refs to avoid JS string escaping issues) ──
            const footer = document.getElementById('vdFootee');
            let fh = `<button class="vd-btn-close" onclick="closeViewDetails()"><i class="fas fa-times"></i> Close</button>`;

            if (data.isPending) {
                fh += `<button class="vd-btn-decline" onclick="closeViewDetails();openCancelReq(_vdData.id);"><i class="fas fa-times-circle"></i> Decline</button>`;
                fh += `<button class="vd-btn-accept" onclick="closeViewDetails();openAccept(_vdData.id,_vdData.fullName,_vdData.service,_vdData.date+' at '+_vdData.time);"><i class="fas fa-check-circle"></i> Accept</button>`;
            } else {
                fh += `<button class="btn-message" onclick="closeViewDetails();openMessage(_vdData.id,_vdData.fullName,_vdData.contact,_vdData.service);"><i class="fas fa-comment-dots"></i> Message</button>`;
                if (data.isCompleted || data.isCancelled) {
                    fh += `<button class="btn-archive" onclick="closeViewDetails();submitArchiveFromModal(_vdData.id,_vdData.fullName,_vdData.service);"><i class="fas fa-box-archive"></i> Archive</button>`;
                }
                if (data.showEmergencyAcceptBtn) {
                    fh += `<button class="btn-emergency-accept" onclick="closeViewDetails();submitEmergencyAccept(_vdData.id);"><i class="fas fa-bolt"></i> Accept Emergency</button>`;
                }
                if (data.needsInspectionReport) {
                    fh += `<button class="action-main-btn" style="background:linear-gradient(135deg,#4a235a,#6c3483);" onclick="closeViewDetails();openInspectionReportModal(_vdData.id,_vdData.assignedStaffId,_vdData.assignedStaffName,_vdData.isRevising);"><i class="fas fa-clipboard-check"></i> ${data.isRevising ? 'Resubmit Inspection Report' : 'Submit Inspection Report'}</button>`;
                } else if (data.awaitingAgreement) {
                    fh += `<span class="action-main-btn" style="background:#e8daef;color:#4a235a;border:1.5px solid #d7bde2;cursor:default;"><i class="fas fa-hourglass-half"></i> Waiting for Seeker's Decision</span>`;
                } else if (data.isStarting) {
                    fh += `<button class="action-main-btn" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);" onclick="closeViewDetails();openQrScanModal();"><i class="fas fa-qrcode"></i> Scan Seeker QR</button>`;
                } else if (data.nextStepKey) {
                    if (data.nextStepKey === 'starting') {
                        if (!data.isPaidEnough) {
                            fh += `<span class="action-main-btn" style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;cursor:default;"><i class="fas fa-lock"></i> Awaiting Payment</span>`;
                        } else if (data.isBeforeServiceDay && !data.providerVerified) {
                            fh += `<button class="action-main-btn" onclick="closeViewDetails();openCtel(_vdData.id,_vdData.fullName,_vdData.seekerVerifiedForCtel,false,true);"><i class="fas fa-clock"></i> Start Early</button>`;
                        } else if (data.isBeforeServiceDay && data.providerVerified && !data.dualVerified) {
                            fh += `<span class="action-main-btn" style="background:#fef9c3;color:#92400e;border:1.5px solid #fde68a;cursor:default;"><i class="fas fa-hourglass-half"></i> Waiting for Seeker Verification</span>`;
                        }
                    } else if (data.nextStepKey === 'preparing') {
                        fh += `<button class="action-main-btn" onclick="closeViewDetails();openPrepareBooking({availId:_vdData.id, staffId:_vdData.assignedStaffId, companionId:0, serviceEquipment:_vdData.serviceEquipment, isEdit:false, notes:''});"><i class="fas fa-people-carry-box"></i> Prepare Booking</button>`;
                    } else {
                        fh += `<button class="action-main-btn" onclick="closeViewDetails();handleStepNext(null,_vdData.id,_vdData.currentStepLabel,_vdData.currentStepBg,_vdData.currentStepColor,_vdData.nextStepLabel,_vdData.nextStepKey,_vdData.nextStepBg,_vdData.nextStepColor,_vdData.fullName,_vdData.preferredDatetime,_vdData.address,_vdData.isDualVerified,_vdData.isTestServiceDay);"><i class="fas fa-arrow-right"></i> Advance to ${escapeHtml(data.nextStepLabel)}</button>`;
                    }
                }
                if (data.isVerifiable && data.isPaidEnough && !data.isBeforeServiceDay && !data.dualVerified) {
                    fh += `<button class="btn-verify-ctrl" onclick="closeViewDetails();openCtel(_vdData.id,_vdData.fullName,_vdData.seekerVerifiedForCtel,_vdData.isTestServiceDay);"><i class="fas fa-shield-alt"></i> ${data.providerVerified ? 'Code Verified ✓' : 'Enter Seeker Code'}</button>`;
                }
                // Equipment/staff editing isn't tied to the next-step machinery
                // above (a Preparing booking's next step is 'starting', which
                // renders payment/code-verification controls) — offered
                // additively alongside whatever else this status shows.
                if (data.isPreparing) {
                    fh += `<button class="action-main-btn" style="background:linear-gradient(135deg,#0c5460,#0891b2);" onclick="closeViewDetails();openPrepareBooking({availId:_vdData.id, staffId:_vdData.bookingAssignedEmployeeId, companionId:_vdData.bookingCompanionEmployeeId, isEdit:true, currentEquipment:_vdData.bookingAssignedEquipment, currentConsumables:_vdData.bookingAssignedConsumables, notes:_vdData.operationsNotes});"><i class="fas fa-toolbox"></i> Edit Equipment & Staff</button>`;
                }
            }
            footer.innerHTML = fh;

            document.getElementById('viewDetailsOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeViewDetails() {
            document.getElementById('viewDetailsOverlay').classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('viewDetailsOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeViewDetails();
        });

        // Helper: escape JS string
        function escJs(str) {
            return (str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        }

        // Submit emergency accept from modal
        function submitEmergencyAccept(availId) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.innerHTML = '<input type="hidden" name="accept_emergency_now" value="1"><input type="hidden" name="avail_id" value="' + Number(availId) + '">';
            document.body.appendChild(f);
            f.submit();
        }

        // Submit archive from modal (triggers the confirm dialog)
        function submitArchiveFromModal(availId, customerName, serviceName) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.setAttribute('data-customer', customerName);
            f.setAttribute('data-service', serviceName);
            f.innerHTML = '<input type="hidden" name="archive_request" value="1"><input type="hidden" name="avail_id" value="' + Number(availId) + '">';
            document.body.appendChild(f);
            openArchiveConfirm(f);
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // â”€â”€ Accept Modal â”€â”€
        function openAccept(availId, customerName, serviceName, scheduled) {
            document.getElementById('acceptAvailId').value        = availId;
            document.getElementById('acceptCustomerName').textContent = customerName;
            document.getElementById('acceptServiceName').textContent  = serviceName || '-';
            document.getElementById('acceptScheduled').textContent    = scheduled;
            document.getElementById('acceptOverlay').classList.add('active');
        }
        function closeAccept() {
            document.getElementById('acceptOverlay').classList.remove('active');
        }
        document.getElementById('acceptOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeAccept();
        });

        // â”€â”€ Cancel Request Modal â”€â”€
        let _selectedCancelReason = '';
        function openCancelReq(availId) {
            document.getElementById('cancelreqAvailId').value = availId;
            document.getElementById('cancelReasonText').value = '';
            _selectedCancelReason = '';
            document.querySelectorAll('.cancelreq-reason-chip').forEach(c => c.classList.remove('selected'));
            document.getElementById('cancelreqOverlay').classList.add('active');
        }
        function closeCancelReq() {
            document.getElementById('cancelreqOverlay').classList.remove('active');
        }
        function selectReason(el, reason) {
            document.querySelectorAll('.cancelreq-reason-chip').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            _selectedCancelReason = reason;
        }
        function prepareCancelSubmit() {
            const extra  = document.getElementById('cancelReasonText').value.trim();
            const reason = _selectedCancelReason
                ? (_selectedCancelReason + (extra ? ': ' + extra : ''))
                : extra;
            document.getElementById('cancelreqReason').value = reason;
        }
        document.getElementById('cancelreqOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeCancelReq();
        });

        // â”€â”€ Pending GPS/confirm state â”€â”€
        let _pendingConfirm = null;

        function toggleRescheduleForm(availId) {
            const card = document.getElementById('rescheduleForm-' + availId);
            if (!card) return;
            card.classList.toggle('is-hidden');
        }

        // â”€â”€ Main step handler â”€â”€
        function handleStepNext(
            btn, availId, fromLabel, fromBg, fromColor, toLabel, toKey, toBg, toColor,
            customerName, preferredDatetime, address, isDualVerified = false, isTestServiceDay = false
        ) {
            if (toKey === 'starting') {
                const now = new Date();
                const preferred = new Date(preferredDatetime.replace(' ', 'T'));
                const timeOk = now >= preferred;
                const bypassTimeLock = !!isDualVerified || !!isTestServiceDay;
                let reasons = '';
                if (!timeOk) {
                    const diff = preferred - now;
                    const hrs  = Math.floor(diff / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    const timeStr = hrs > 0 ? hrs + 'h ' + mins + 'm' : mins + 'm';
                    reasons += `<div class="lock-reason-item fail"><i class="fas fa-clock"></i><div><strong>Not yet time</strong><br>Scheduled for ${preferredDatetime}. Starts in ${timeStr}.</div></div>`;
                } else {
                    reasons += `<div class="lock-reason-item pass"><i class="fas fa-check-circle"></i><div><strong>Time check passed</strong><br>It is on or past the scheduled time.</div></div>`;
                }
                if (!timeOk && !bypassTimeLock) {
                    document.getElementById('lockReasons').innerHTML = reasons;
                    document.getElementById('lockOverlay').classList.add('active');
                    return;
                }
                _pendingConfirm = { availId, fromLabel, fromBg, fromColor, toLabel, toKey, toBg, toColor, customerName };
                openGpsModal(address);
                return;
            }
            openStepConfirm(availId, fromLabel, fromBg, fromColor, toLabel, toKey, toBg, toColor, customerName);
        }

        // â”€â”€ Lock modal â”€â”€
        function closeLock() { document.getElementById('lockOverlay').classList.remove('active'); }
        document.getElementById('lockOverlay').addEventListener('click', function(e) { if (e.target === this) closeLock(); });

        // â”€â”€ GPS Modal â”€â”€
        function openGpsModal(address) {
            document.getElementById('gpsSpinner').style.display = 'block';
            document.getElementById('gpsTitle').textContent = 'Verifying Your Location...';
            document.getElementById('gpsSubtitle').textContent = 'Please allow location access so we can confirm you are at the seeker\'s address.';
            document.getElementById('gpsAddressBox').style.display = 'none';
            document.getElementById('gpsAddressText').textContent = address;
            document.getElementById('gpsResult').className = 'gps-result';
            document.getElementById('gpsResult').innerHTML = '';
            document.getElementById('gpsManualCheck').style.display = 'none';
            document.getElementById('gpsManualCheckbox').checked = false;
            document.getElementById('gpsProceedBtn').disabled = true;
            document.getElementById('gpsOverlay').classList.add('active');
            if (!navigator.geolocation) { showGpsError('Your browser does not support GPS. Please confirm manually.', address); return; }
            navigator.geolocation.getCurrentPosition(
                function(pos) { onGpsSuccess(pos, address); },
                function(err) { showGpsError('Location access denied or unavailable. Please confirm manually.', address); },
                { timeout: 10000, maximumAge: 0, enableHighAccuracy: true }
            );
        }
        function onGpsSuccess(pos, address) {
            document.getElementById('gpsTitle').textContent = 'Checking Distance...';
            document.getElementById('gpsSubtitle').textContent = 'Comparing your location with the seeker\'s address.';
            document.getElementById('gpsAddressBox').style.display = 'flex';
            const peovLat = pos.coords.latitude;
            const peovLng = pos.coords.longitude;
            fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(address))
                .then(e => e.json())
                .then(data => {
                    if (!data || data.length === 0) { showGpsError('Could not locate the seeker\'s address on the map. Please confirm manually.', address); return; }
                    const seekLat = parseFloat(data[0].lat);
                    const seekLng = parseFloat(data[0].lon);
                    const dist = haversine(peovLat, peovLng, seekLat, seekLng);
                    document.getElementById('gpsSpinner').style.display = 'none';
                    if (dist <= 300) {
                        const result = document.getElementById('gpsResult');
                        result.className = 'gps-result success';
                        result.innerHTML = `<i class="fas fa-check-circle"></i> You are ${Math.round(dist)}m from the seeker's location. âœ…`;
                        document.getElementById('gpsManualCheck').style.display = 'block';
                        document.getElementById('gpsTitle').textContent = 'Almost There!';
                        document.getElementById('gpsSubtitle').textContent = 'GPS confirmed. Please tick the checkbox below to proceed.';
                    } else {
                        const result = document.getElementById('gpsResult');
                        result.className = 'gps-result fail';
                        result.innerHTML = `<i class="fas fa-times-circle"></i> You are ${Math.round(dist)}m away. Must be within 300m of seeker's location.`;
                        document.getElementById('gpsManualCheck').style.display = 'block';
                        document.getElementById('gpsTitle').textContent = 'Too Far Away';
                        document.getElementById('gpsSubtitle').textContent = 'GPS shows you are not yet at the location. If this is incorrect, confirm manually below.';
                    }
                })
                .catch(() => { showGpsError('Could not verify address. Please confirm manually.', address); });
        }
        function showGpsError(msg, address) {
            document.getElementById('gpsSpinner').style.display = 'none';
            document.getElementById('gpsTitle').textContent = 'Location Check Failed';
            document.getElementById('gpsSubtitle').textContent = msg;
            document.getElementById('gpsAddressBox').style.display = 'flex';
            const result = document.getElementById('gpsResult');
            result.className = 'gps-result fail';
            result.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${msg}`;
            document.getElementById('gpsManualCheck').style.display = 'block';
        }
        function onManualCheck() { document.getElementById('gpsProceedBtn').disabled = !document.getElementById('gpsManualCheckbox').checked; }
        function proceedAfterGps() {
            closeGps();
            if (_pendingConfirm) {
                const p = _pendingConfirm;
                openStepConfirm(p.availId, p.fromLabel, p.fromBg, p.fromColor, p.toLabel, p.toKey, p.toBg, p.toColor, p.customerName);
            }
        }
        function closeGps() { document.getElementById('gpsOverlay').classList.remove('active'); }
        document.getElementById('gpsOverlay').addEventListener('click', function(e) { if (e.target === this) closeGps(); });

        // â”€â”€ Haveesine â”€â”€
        function haversine(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const toRad = x => x * Math.PI / 180;
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        // â”€â”€ Step Confirm â”€â”€
        function openStepConfirm(availId, fromLabel, fromBg, fromColor, toLabel, toKey, toBg, toColor, customerName) {
            document.getElementById('confirmAvailId').value   = availId;
            document.getElementById('confirmNewStatus').value = toKey;
            document.getElementById('confirmCustomerName').textContent = customerName;
            const fromBox = document.getElementById('confirmFromBox');
            fromBox.textContent = fromLabel; fromBox.style.background = fromBg; fromBox.style.color = fromColor;
            const toBox = document.getElementById('confirmToBox');
            toBox.textContent = toLabel; toBox.style.background = toBg; toBox.style.color = toColor;
            document.getElementById('confirmOverlay').classList.add('active');
        }
        function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); }
        document.getElementById('confirmOverlay').addEventListener('click', function(e) { if (e.target === this) closeConfirm(); });

        // Archive confirm modal (replaces browser confirm dialog)
        let _archivePendingForm = null;
        function openArchiveConfirm(formEl) {
            if (!formEl) return false;
            _archivePendingForm = formEl;

            const customer = (formEl.getAttribute('data-customer') || 'Customer').trim();
            const service = (formEl.getAttribute('data-service') || 'Service').trim();
            const aidInput = formEl.querySelector('input[name="avail_id"]');
            const bookingId = aidInput ? aidInput.value : '';

            const textEl = document.getElementById('archiveConfirmText');
            const metaEl = document.getElementById('archiveConfirmMeta');
            if (textEl) {
                textEl.textContent = 'Are you sure you want to archive this request?';
            }
            if (metaEl) {
                metaEl.textContent =
                    (bookingId ? ('Booking #' + bookingId + ' — ') : '')
                    + customer + ' • ' + service;
            }

            document.getElementById('archiveConfirmOverlay').classList.add('active');
            return false;
        }
        function closeArchiveConfirm() {
            document.getElementById('archiveConfirmOverlay').classList.remove('active');
            _archivePendingForm = null;
        }
        function submitArchiveConfirm() {
            if (_archivePendingForm) {
                const f = _archivePendingForm;
                _archivePendingForm = null;
                f.submit();
            }
            document.getElementById('archiveConfirmOverlay').classList.remove('active');
        }
        document.getElementById('archiveConfirmOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeArchiveConfirm();
        });

        // Auto-dismiss toast
        setTimeout(function() { const t = document.getElementById('actionToast'); if (t) t.remove(); }, 4000);

        // â”€â”€ Copy control number from table â”€â”€
        function copyCtelCode(code, btn) {
            navigator.clipboard.writeText(code).then(function() {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.style.color = '#059669'; btn.style.borderColor = '#059669';
                setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; btn.style.borderColor = ''; }, 2200);
            }).catch(function() {
                const ta = document.createElement('textarea');
                ta.value = code; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(function() { btn.innerHTML = orig; }, 2200);
            });
        }

        function showCleanToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = 'clean-toast ' + (type === 'error' ? 'error' : (type === 'info' ? 'info' : 'success'));

            const icon = document.createElement('i');
            icon.className = 'fas ' + (type === 'error'
                ? 'fa-circle-exclamation'
                : (type === 'info' ? 'fa-circle-info' : 'fa-circle-check'));
            icon.style.marginTop = '1px';

            const text = document.createElement('span');
            text.textContent = message;

            toast.appendChild(icon);
            toast.appendChild(text);
            document.body.appendChild(toast);

            requestAnimationFrame(() => toast.classList.add('show'));
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 220);
            }, 3400);
        }

        // Message Modal
        let _msgAvailId = 0;

        function openMessage(availId, name, contact, serviceName) {
            _msgAvailId = Number(availId) || 0;
            document.getElementById('msgRecipientName').textContent = name;
            document.getElementById('msgServiceLabel').textContent  = serviceName ? 'Re: ' + serviceName : 'General Message';
            document.getElementById('msgContactNumber').textContent  = contact;
            document.getElementById('msgTextarea').value = '';
            document.getElementById('msgTextarea').style.borderColor = '';
            document.getElementById('msgTextarea').placeholder = 'Type your message here...';
            document.getElementById('msgCharCount').textContent = '0';
            document.getElementById('msgOverlay').classList.add('active');
        }
        function closeMessage() { document.getElementById('msgOverlay').classList.remove('active'); }
        function updateCharCount() { document.getElementById('msgCharCount').textContent = document.getElementById('msgTextarea').value.length; }
        function sendMessage() {
            const textarea = document.getElementById('msgTextarea');
            const sendBtn = document.querySelector('.msg-btn-send');
            const msg = textarea.value.trim();
            const name = document.getElementById('msgRecipientName').textContent;

            if (!msg) {
                textarea.style.borderColor = '#dc2626';
                textarea.placeholder = 'Please type a message first...';
                textarea.focus();
                return;
            }
            if (!_msgAvailId) {
                showCleanToast('Unable to send this message. Please refresh the page.', 'error');
                return;
            }

            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            const body = new URLSearchParams();
            body.set('send_seeker_message_ajax', '1');
            body.set('avail_id', String(_msgAvailId));
            body.set('message', msg);

            fetch('service-requests.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(e => e.json())
            .then(data => {
                if (data && data.ok) {
                    closeMessage();
                    showCleanToast('Message sent to ' + (data.recipient_name || name) + '.');
                } else {
                    showCleanToast((data && data.error) ? data.error : 'Failed to send message.', 'error');
                }
            })
            .catch(() => {
                showCleanToast('Network error while sending message.', 'error');
            })
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Message';
            });
        }
        document.getElementById('msgOverlay').addEventListener('click', function(e) { if (e.target === this) closeMessage(); });
        // â”€â”€ Control Number Modal â”€â”€
        function openCtel(availId, customerName, seekerVerified, isTestServiceDay = false, allowEarlyStart = false) {
            document.getElementById('ctelAvailId').value = availId;
            document.getElementById('ctelSubtitle').textContent =
                (allowEarlyStart ? 'Early start requested. ' : '') +
                'Enter the Seeker Control Number (PCF-...) for ' + customerName + '. '
                + 'Service starts only after BOTH codes are verified.';
            document.getElementById('ctelInput').value = '';
            document.getElementById('ctelErrorMsg').style.display = 'none';
            document.getElementById('ctelErrorMsg').textContent = '';
            document.getElementById('ctelTestModeAnytime').value = isTestServiceDay ? '1' : '0';
            document.getElementById('ctelTestModeHint').style.display = isTestServiceDay ? 'block' : 'none';
            document.getElementById('ctelEarlyStart').value = allowEarlyStart ? '1' : '0';
            document.getElementById('ctelEarlyStartHint').style.display = allowEarlyStart ? 'block' : 'none';

            // Update seeker step indicator
            const seekerStep = document.getElementById('ctelStepSeeker');
            if (seekerVerified) {
                seekerStep.style.background = '#dcfce7';
                seekerStep.style.color = '#166534';
                seekerStep.innerHTML = '<i class="fas fa-check-circle"></i> Seeker âœ…';
            } else {
                seekerStep.style.background = '#f3f4f6';
                seekerStep.style.color = '#6b7280';
                seekerStep.innerHTML = '<i class="fas fa-user"></i> Seeker Code';
            }

            document.getElementById('ctelOverlay').classList.add('active');
            setTimeout(() => document.getElementById('ctelInput').focus(), 150);
        }
        function closeCtel() { document.getElementById('ctelOverlay').classList.remove('active'); }
        document.getElementById('ctelOverlay').addEventListener('click', function(e) { if (e.target === this) closeCtel(); });

        <?php if ($ctrl_result === 'fail' && $ctrl_error): ?>
        window.addEventListener('DOMContentLoaded', function() {
            document.getElementById('ctelOverlay').classList.add('active');
            const eeeEl = document.getElementById('ctelErrorMsg');
            eeeEl.textContent = <?php echo json_encode($ctrl_error); ?>;
            eeeEl.style.display = 'block';
        });
        <?php endif; ?>

        <?php if ($ctrl_result === 'ok'): ?>
        window.addEventListener('DOMContentLoaded', function() {
            showCleanToast('Both control numbers verified. Scan the seeker\'s QR code on arrival to start service.', 'success');
        });
        <?php endif; ?>

        <?php if ($ctrl_result === 'provider_done'): ?>
        window.addEventListener('DOMContentLoaded', function() {
            showCleanToast('Seeker code verified on your side. Waiting for seeker verification.', 'info');
        });
        <?php endif; ?>
    </script>
    <?php include appPath('includes/provider-guide.php'); ?>

<!-- ── QR Scanner Modal ───────────────────────────────────── -->
<div id="qrScanOverlay" style="
    display:none;position:fixed;inset:0;
    background:rgba(10,20,40,0.78);backdrop-filter:blur(6px);
    z-index:21000;align-items:center;justify-content:center;padding:20px;">
  <div style="
    background:#fff;border-radius:22px;max-width:460px;width:100%;
    box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);padding:22px 26px 18px;position:relative;display:flex;align-items:center;gap:14px;">
      <div style="width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;
          display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;">
        <i class="fas fa-qrcode"></i>
      </div>
      <div>
        <h3 style="margin:0 0 2px;color:#fff;font-size:17px;font-weight:800;">Scan Seeker QR Code</h3>
        <p style="margin:0;color:#93c5fd;font-size:12px;">Ask the seeker to open their QR, then scan or paste the token.</p>
      </div>
      <button onclick="closeQrScanModal()" style="
          position:absolute;top:14px;right:14px;
          background:rgba(255,255,255,.15);border:none;color:#fff;
          width:30px;height:30px;border-radius:50%;cursor:pointer;
          display:flex;align-items:center;justify-content:center;font-size:13px;">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Body -->
    <div style="padding:22px 26px;">
      <!-- Result banner -->
      <div id="qrScanResult" style="display:none;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:9px;"></div>

      <!-- Camera scanner -->
      <div style="margin-bottom:18px;">
        <div id="qrScanReader" style="width:100%;border-radius:10px;overflow:hidden;background:#f1f5f9;"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button id="qrStartCamBtn" class="action-main-btn" style="flex:1;justify-content:center;font-size:13px;padding:10px;" onclick="startQrCamera()">
            <i class="fas fa-camera"></i> Start Camera
          </button>
          <button id="qrStopCamBtn" class="action-main-btn" style="flex:1;justify-content:center;font-size:13px;padding:10px;background:#64748b;display:none;" onclick="stopQrCamera()">
            <i class="fas fa-stop-circle"></i> Stop Camera
          </button>
        </div>
      </div>

      <!-- Divider -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;color:#94a3b8;font-size:12px;">
        <div style="flex:1;height:1px;background:#e2e8f0;"></div>or enter token manually<div style="flex:1;height:1px;background:#e2e8f0;"></div>
      </div>

      <!-- Manual entry -->
      <div style="display:flex;gap:8px;">
        <input type="text" id="qrManualToken" placeholder="e.g. ABC123" maxlength="7"
            style="flex:1;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:15px;font-family:'Courier New',monospace;color:#1e293b;letter-spacing:.1em;text-transform:uppercase;"
            oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')"
            onkeydown="if(event.key==='Enter')submitQrToken(this.value)">
        <button class="action-main-btn" style="font-size:13px;padding:10px 18px;" onclick="submitQrToken(document.getElementById('qrManualToken').value)">
          <i class="fas fa-check"></i> Validate
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Inspection Report Modal (photo + description + proposed price/working date) -->
<div id="inspectionReportOverlay" style="
    display:none;position:fixed;inset:0;
    background:rgba(10,20,40,0.78);backdrop-filter:blur(6px);
    z-index:21000;align-items:center;justify-content:center;padding:20px;">
  <div style="
    background:#fff;border-radius:22px;max-width:560px;width:100%;
    max-height:88vh;display:flex;flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden;">

    <div style="background:linear-gradient(135deg,#4a235a,#6c3483);padding:22px 26px 18px;position:relative;display:flex;align-items:center;gap:14px;flex-shrink:0;">
      <div style="width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;
          display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;">
        <i class="fas fa-clipboard-check"></i>
      </div>
      <div>
        <h3 id="inspReportTitle" style="margin:0 0 2px;color:#fff;font-size:17px;font-weight:800;">Submit Inspection Report</h3>
        <p style="margin:0;color:#e8daef;font-size:12px;">Photo, findings, final price, and a proposed working date.</p>
      </div>
      <button onclick="closeInspectionReportModal()" style="
          position:absolute;top:14px;right:14px;
          background:rgba(255,255,255,.15);border:none;color:#fff;
          width:30px;height:30px;border-radius:50%;cursor:pointer;
          display:flex;align-items:center;justify-content:center;font-size:13px;">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <form method="POST" id="inspectionReportForm" enctype="multipart/form-data" style="overflow-y:auto;flex:1;min-height:0;">
      <input type="hidden" name="submit_inspection_report" value="1">
      <input type="hidden" name="avail_id" id="inspAvailId" value="">

      <div style="padding:22px 26px;">
        <div id="inspReportErrorBox" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 12px;font-size:12.5px;margin-bottom:16px;"></div>

        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Field Technician *</label>
          <?php if (empty($fieldStaffOptions)): ?>
            <div style="padding:11px 14px;border-radius:10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;">
              No field technicians yet. In the provider portal's HR module, add an employee with Staff Type "Field Technician".
            </div>
          <?php else: ?>
          <select name="staff_id" id="inspStaffSelect" required style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
            <option value="">— Select field technician —</option>
            <?php foreach ($fieldStaffOptions as $fs): ?>
            <option value="<?php echo (int)$fs['id']; ?>"><?php echo htmlspecialchars($fs['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>

        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;"><i class="fas fa-camera"></i> Inspection Photo *</label>
          <input type="file" name="inspection_image" id="inspImageInput" accept="image/jpeg,image/png,image/webp" required style="width:100%;padding:9px 10px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;">
          <div style="font-size:11px;color:#94a3b8;margin-top:4px;">JPG, PNG, or WEBP — up to 8MB.</div>
        </div>

        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">What did the inspection find? What will happen? *</label>
          <textarea name="inspection_notes" id="inspNotesInput" rows="4" required style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;" placeholder="Describe the site condition, scope of work, and what the seeker should expect on the working date..."></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:6px;">
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Final Price (&#8369;) *</label>
            <input type="number" name="proposed_price" id="inspPriceInput" min="0.01" step="0.01" required style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
          </div>
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Proposed Working Date *</label>
            <input type="date" name="proposed_working_date" id="inspWorkingDateInput" required min="<?php echo date('Y-m-d'); ?>" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
          </div>
        </div>
      </div>

      <div style="padding:16px 26px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;">
        <button type="button" onclick="closeInspectionReportModal()" style="padding:10px 18px;border:1.5px solid #e2e8f0;border-radius:9px;background:#fff;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" class="action-main-btn" style="font-size:13px;padding:10px 20px;background:linear-gradient(135deg,#4a235a,#6c3483);" <?php echo empty($fieldStaffOptions) ? 'disabled' : ''; ?>>
          <i class="fas fa-paper-plane"></i> Send to Seeker
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openInspectionReportModal(availId, assignedStaffId, assignedStaffName, isRevising) {
    document.getElementById('inspAvailId').value = availId;
    document.getElementById('inspReportTitle').textContent = isRevising ? 'Resubmit Inspection Report' : 'Submit Inspection Report';
    const sel = document.getElementById('inspStaffSelect');
    if (sel && assignedStaffId) { sel.value = String(assignedStaffId); }
    document.getElementById('inspectionReportOverlay').style.display = 'flex';
}
function closeInspectionReportModal() {
    document.getElementById('inspectionReportOverlay').style.display = 'none';
}
</script>

<!-- Prepare Booking Modal (staff + equipment/consumables assignment) -->
<div id="prepareBookingOverlay" style="
    display:none;position:fixed;inset:0;
    background:rgba(10,20,40,0.78);backdrop-filter:blur(6px);
    z-index:21000;align-items:center;justify-content:center;padding:20px;">
  <div style="
    background:#fff;border-radius:22px;max-width:560px;width:100%;
    max-height:88vh;display:flex;flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden;">

    <div style="background:linear-gradient(135deg,#0c5460,#0891b2);padding:22px 26px 18px;position:relative;display:flex;align-items:center;gap:14px;flex-shrink:0;">
      <div style="width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;
          display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;">
        <i class="fas fa-people-carry-box"></i>
      </div>
      <div>
        <h3 id="prepareBookingTitle" style="margin:0 0 2px;color:#fff;font-size:17px;font-weight:800;">Prepare Booking</h3>
        <p style="margin:0;color:#a5f3fc;font-size:12px;">Assign a field technician and any equipment/consumables needed.</p>
      </div>
      <button onclick="closePrepareBooking()" style="
          position:absolute;top:14px;right:14px;
          background:rgba(255,255,255,.15);border:none;color:#fff;
          width:30px;height:30px;border-radius:50%;cursor:pointer;
          display:flex;align-items:center;justify-content:center;font-size:13px;">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <form method="POST" id="prepareBookingForm" style="overflow-y:auto;flex:1;min-height:0;">
      <input type="hidden" name="prepare_booking" value="1">
      <input type="hidden" name="avail_id" id="prepAvailId" value="">
      <input type="hidden" name="equipment_json" id="prepEquipJson">
      <input type="hidden" name="consumables_json" id="prepConsJson">

      <div style="padding:22px 26px;">
        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Field Technician *</label>
          <?php if (empty($fieldStaffOptions)): ?>
            <div style="padding:11px 14px;border-radius:10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;">
              No field technicians yet. In the provider portal's HR module, add an employee with Staff Type "Field Technician" — they can log in and see their assigned bookings right away, no promotion needed.
            </div>
          <?php else: ?>
          <select name="staff_id" id="prepStaffSelect" required style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
            <option value="">— Select field technician —</option>
            <?php foreach ($fieldStaffOptions as $fs): ?>
            <option value="<?php echo (int)$fs['id']; ?>"><?php echo htmlspecialchars($fs['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>

        <?php if (!empty($fieldStaffOptions)): ?>
        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Companion (optional co-staff)</label>
          <select name="companion_id" id="prepCompanionSelect" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
            <option value="">— None —</option>
            <?php foreach ($fieldStaffOptions as $fs): ?>
            <option value="<?php echo (int)$fs['id']; ?>"><?php echo htmlspecialchars($fs['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:8px;"><i class="fas fa-toolbox"></i> Equipment</label>
          <?php if (empty($prepEquipment)): ?>
            <p style="color:#94a3b8;font-size:13px;margin:0;">No equipment in inventory.</p>
          <?php else: foreach ($prepEquipment as $item): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
              <div style="flex:1;font-size:13px;"><?php echo htmlspecialchars($item['item_name']); ?>
                <div style="color:#94a3b8;font-size:11px;">Available now: <?php echo (int)$item['available_now']; ?> of <?php echo (int)$item['quantity_available']; ?> owned <?php echo htmlspecialchars($item['unit'] ?? ''); ?></div>
              </div>
              <input type="number" class="prep-equip-qty" min="0" max="<?php echo (int)$item['available_now']; ?>" value="0" data-id="<?php echo (int)$item['id']; ?>"
                  style="width:90px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div style="margin-bottom:18px;">
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:8px;"><i class="fas fa-flask"></i> Consumables</label>
          <?php if (empty($prepConsumables)): ?>
            <p style="color:#94a3b8;font-size:13px;margin:0;">No consumables in inventory.</p>
          <?php else: foreach ($prepConsumables as $item): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
              <div style="flex:1;font-size:13px;"><?php echo htmlspecialchars($item['item_name']); ?>
                <div style="color:#94a3b8;font-size:11px;">Available: <?php echo (int)$item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? ''); ?></div>
              </div>
              <input type="number" class="prep-cons-qty" min="0" max="<?php echo (int)$item['quantity_available']; ?>" value="0" data-id="<?php echo (int)$item['id']; ?>"
                  style="width:90px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">Operations Notes</label>
          <textarea name="operations_notes" id="prepNotesInput" rows="2" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;"></textarea>
        </div>
      </div>

      <div style="padding:16px 26px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;">
        <button type="button" onclick="closePrepareBooking()" style="padding:10px 18px;border:1.5px solid #e2e8f0;border-radius:9px;background:#fff;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" class="action-main-btn" id="prepareBookingSubmitBtn" style="font-size:13px;padding:10px 20px;" <?php echo empty($fieldStaffOptions) ? 'disabled' : ''; ?>>
          <i class="fas fa-check"></i> Save &amp; Set to Preparing
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// opts: {availId, staffId, companionId, serviceEquipment, isEdit,
//        currentEquipment, currentConsumables, notes}
// isEdit=false (fresh Prepare, from 'accepted'): prefills quantities from
// the service's suggested equipment (serviceEquipment).
// isEdit=true (already 'preparing', re-opened via "Edit Equipment & Staff"):
// prefills from what the booking currently holds, and raises each input's
// max by that same held amount — the server excludes this booking's own
// usage from its own availability check the same way (see
// prepareAvailedBooking()'s $excludeAvailedId), so the true ceiling here is
// "available to everyone else" + "already held by this booking".
function openPrepareBooking(opts) {
    document.getElementById('prepAvailId').value = opts.availId;
    document.getElementById('prepareBookingTitle').textContent = opts.isEdit ? 'Edit Equipment & Staff' : 'Prepare Booking';
    document.getElementById('prepareBookingSubmitBtn').innerHTML = opts.isEdit
        ? '<i class="fas fa-check"></i> Save Changes'
        : '<i class="fas fa-check"></i> Save &amp; Set to Preparing';

    const sel = document.getElementById('prepStaffSelect');
    if (sel) { sel.value = opts.staffId ? String(opts.staffId) : ''; }
    const compSel = document.getElementById('prepCompanionSelect');
    if (compSel) { compSel.value = opts.companionId ? String(opts.companionId) : ''; }

    const heldById = {};
    if (opts.isEdit) {
        (opts.currentEquipment || []).forEach(function (e) { heldById[String(e.id)] = e.qty; });
        (opts.currentConsumables || []).forEach(function (e) { heldById[String(e.id)] = e.qty; });
    } else {
        (opts.serviceEquipment || []).forEach(function (e) { heldById[String(e.id)] = e.qty; });
    }
    document.querySelectorAll('.prep-equip-qty, .prep-cons-qty').forEach(function (el) {
        const held = heldById[el.dataset.id] || 0;
        if (opts.isEdit && held > 0) { el.max = String(parseInt(el.max, 10) + held); }
        el.value = held;
    });

    document.getElementById('prepNotesInput').value = opts.notes || '';
    document.getElementById('prepareBookingOverlay').style.display = 'flex';
}
function closePrepareBooking() {
    document.getElementById('prepareBookingOverlay').style.display = 'none';
}
document.getElementById('prepareBookingForm')?.addEventListener('submit', function(e) {
    const staffSel = document.getElementById('prepStaffSelect');
    const compSel = document.getElementById('prepCompanionSelect');
    if (staffSel && compSel && compSel.value && compSel.value === staffSel.value) {
        e.preventDefault();
        alert('Companion must be a different technician than the primary assignee.');
        return;
    }
    const eq = [], cn = [];
    document.querySelectorAll('.prep-equip-qty').forEach(el => { if (+el.value > 0) eq.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });
    document.querySelectorAll('.prep-cons-qty').forEach(el => { if (+el.value > 0) cn.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });
    document.getElementById('prepEquipJson').value = JSON.stringify(eq);
    document.getElementById('prepConsJson').value  = JSON.stringify(cn);
});
</script>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let _qrScanner = null;

function openQrScanModal() {
    document.getElementById('qrScanResult').style.display = 'none';
    document.getElementById('qrManualToken').value = '';
    document.getElementById('qrScanOverlay').style.display = 'flex';
}

function closeQrScanModal() {
    stopQrCamera();
    document.getElementById('qrScanOverlay').style.display = 'none';
}

function startQrCamera() {
    const reader = document.getElementById('qrScanReader');
    reader.innerHTML = '';
    _qrScanner = new Html5Qrcode('qrScanReader');
    _qrScanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 240 },
        decoded => { stopQrCamera(); submitQrToken(decoded); },
        () => {}
    ).then(() => {
        document.getElementById('qrStartCamBtn').style.display = 'none';
        document.getElementById('qrStopCamBtn').style.display = '';
    }).catch(err => {
        _qrScanner = null;
        const errStr = String(err);
        let msg;
        if (errStr.includes('NotFound') || errStr.includes('DevicesNotFound') || errStr.includes('not found')) {
            msg = '<i class="fas fa-camera-slash" style="margin-right:6px;"></i>No camera detected on this device. Use the manual token entry below.';
        } else if (errStr.includes('NotAllowed') || errStr.includes('Permission')) {
            msg = '<i class="fas fa-ban" style="margin-right:6px;"></i>Camera permission denied. Allow camera access or use the manual token entry below.';
        } else {
            msg = '<i class="fas fa-triangle-exclamation" style="margin-right:6px;"></i>Camera unavailable. Use the manual token entry below.';
        }
        showQrResult(false, msg);
        document.getElementById('qrStartCamBtn').style.display = '';
    });
}

function stopQrCamera() {
    if (_qrScanner) {
        _qrScanner.stop().catch(() => {}).finally(() => {
            _qrScanner = null;
            document.getElementById('qrStartCamBtn').style.display = '';
            document.getElementById('qrStopCamBtn').style.display = 'none';
        });
    }
}

function submitQrToken(token) {
    token = (token || '').trim().toUpperCase();
    if (!token) { showQrResult(false, 'Please enter or scan a token first.'); return; }

    showQrResult(null, '<i class="fas fa-spinner fa-spin"></i> Validating…');

    const fd = new FormData();
    fd.append('action', 'scan_qr');
    fd.append('token', token);

    fetch('service-requests.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showQrResult(data.success, data.message);
            if (data.success) {
                setTimeout(() => { closeQrScanModal(); location.reload(); }, 1800);
            }
        })
        .catch(() => showQrResult(false, 'Network error. Please try again.'));
}

function showQrResult(success, msg) {
    const el = document.getElementById('qrScanResult');
    el.style.display = 'flex';
    if (success === null) {
        el.style.cssText = 'display:flex;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;align-items:center;gap:9px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;';
    } else if (success) {
        el.style.cssText = 'display:flex;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;align-items:center;gap:9px;background:#dcfce7;color:#166534;border:1px solid #86efac;';
    } else {
        el.style.cssText = 'display:flex;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;align-items:center;gap:9px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;';
    }
    el.innerHTML = msg;
}

// Deep-link support: provider/messages-provider.php's chat panel opens
// this page in a new tab with ?open_booking=<id> for actions the chat
// itself doesn't handle inline (equipment picker, QR scan, inspection
// photo, etc.) — auto-opens that booking's View Details modal on load so
// the provider lands straight on it instead of the plain table.
(function () {
    const params = new URLSearchParams(window.location.search);
    const openId = params.get('open_booking');
    if (!openId) return;
    const btn = document.querySelector('.btn-view-details[data-avail-id="' + CSS.escape(openId) + '"]');
    if (btn) btn.click();
})();
</script>
</body>
</html>





