<?php
chdir(dirname(__DIR__));
// service-requests.php - Provider's Service Requests
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/payment_receipt_helper.php');

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
$isRealTimestamp = static function ($value): bool {
    $v = trim((string)$value);
    return $v !== '' && $v !== '0000-00-00 00:00:00';
};

function getProviderSettingValue($db, int $providerId, string $key, string $default = ''): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => "provider_{$providerId}_{$key}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

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

// ── Ensure availed_services table exists ──
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

// ── Ensure seeker_notifications table exists ──
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

        $msgInseet = $db->prepare(
            "INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at)
             VALUES (:sender, :receiver, :message, 0, NOW())"
        );
        $msgInseet->execute([
            ':sender' => $sendeeId,
            ':receiver' => $seekerId,
            ':message' => $body,
        ]);

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

// ── Ensure control number columns exist ──
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

// ── Valid statuses ──
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

// ═══════════════════════════════════════════════════════════════
//  DUAL CONTROL NUMBER VERIFICATION  (provider submits seeker code)
//  Flow:
//    1. Provider acceptance  → generates control_number (seeker) +
//                              provider_control_number (technician)
//    2. Seeker submits their code  → seeker_verified_at stamped
//    3. Provider submits seeker code → provider_verified_at stamped
//    4. Both verified             → dual_verified_at stamped,
//                                   status advances to 'starting'
// ═══════════════════════════════════════════════════════════════
$ctrl_result = '';
$ctrl_error  = '';
$ctrl_allow_anytime_test = false;

if (isset($_POST['verify_control_number']) && isset($_POST['avail_id']) && isset($_POST['control_number_input'])) {
    $ctelAvailId = intval($_POST['avail_id']);
    $ctelInput   = strtoupper(preg_replace('/\s+/', '', trim($_POST['control_number_input'])));
    $allowAnytimeTest = $is_local_test_mode && (($_POST['test_service_day_anytime'] ?? '0') === '1');
    $ctrl_allow_anytime_test = $allowAnytimeTest;

    try {
        $ctelStmt = $db->prepare(
            "SELECT preferred_date, control_number, provider_control_number, seeker_verified_at,
                    provider_verified_at, dual_verified_at, full_name, status,
                    seeker_user_id, service_name
             FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1"
        );
        $ctelStmt->execute([':id' => $ctelAvailId, ':pid' => $provider_id]);
        $ctelRow = $ctelStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ctelRow) {
            $ctrl_result = 'fail';
            $ctrl_error  = 'Booking not found. Please refresh and try again.';

        } elseif (
            !$allowAnytimeTest
            && (empty($ctelRow['preferred_date']) || $ctelRow['preferred_date'] !== date('Y-m-d'))
        ) {
            $ctrl_result = 'fail';
            $ctrl_error  = 'Verification is only available on the service day.';

        } elseif (empty($ctelRow['control_number'])) {
            // Codes not yet generated (booking just accepted) - generate now
            $ctrl_result = 'fail';
            $ctrl_error  = 'Control numbers have not been generated yet. Please accept the booking first.';

        } elseif ($isRealTimestamp($ctelRow['dual_verified_at'] ?? null)) {
            // Already fully verified
            $ctrl_result = 'already_done';
            $action_msg   = 'Both control numbers were already verified. Service is already in Starting status.';
            $action_type  = 'starting';

        } else {
            // Validate seeker control number entered by provider
            $expectedCtel = strtoupper(preg_replace('/\s+/', '', trim((string)$ctelRow['control_number'])));
            $providerCodeOk = $expectedCtel !== '' && hash_equals($expectedCtel, $ctelInput);

            if (!$providerCodeOk) {
                $ctrl_result = 'fail';
                $ctrl_error  = 'Invalid seeker control number. Please double-check the code on your dashboard.';
            } else {
                // Stamp provider_verified_at
                $db->prepare(
                    "UPDATE availed_services SET provider_verified_at = NOW(), updated_at = NOW()
                     WHERE id = :id
                       AND provider_id = :pid
                       AND (provider_verified_at IS NULL OR provider_verified_at = '0000-00-00 00:00:00')"
                )->execute([':id' => $ctelAvailId, ':pid' => $provider_id]);

                // Re-fetch to get feeshest state
                $ctelStmt->execute([':id' => $ctelAvailId, ':pid' => $provider_id]);
                $ctelRow = $ctelStmt->fetch(PDO::FETCH_ASSOC);

                $seekerDone   = $isRealTimestamp($ctelRow['seeker_verified_at'] ?? null);
                $providerDone = $isRealTimestamp($ctelRow['provider_verified_at'] ?? null);

                if ($seekerDone && $providerDone) {
                    // ✅ BOTH verified - unlock service
                    $db->prepare(
                        "UPDATE availed_services
                         SET status = 'starting', dual_verified_at = NOW(), updated_at = NOW()
                         WHERE id = :id AND provider_id = :pid"
                    )->execute([':id' => $ctelAvailId, ':pid' => $provider_id]);

                    $ctrl_result  = 'ok';
                    $action_msg   = 'Both control numbers verified. Service is now Starting.';
                    $action_type  = 'starting';
                    $action_label = 'Starting';

                    // Notify seeker that service has started
                    if (!empty($ctelRow['seeker_user_id'] ?? null)) {
                        try {
                            $db->prepare(
                                "INSERT INTO seeker_notifications
                                    (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                                 VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                            )->execute([
                                ':suid'  => $ctelRow['seeker_user_id'],
                                ':avid'  => $ctelAvailId,
                                ':pid'   => $provider_id,
                                ':sname' => $ctelRow['service_name'] ?? '',
                                ':msg'   => 'Your service has officially started. Both control numbers were successfully verified.',
                            ]);
                        } catch(Exception $e) {}
                    }
                } else {
                    // Provider done, waiting for seeker
                    $ctrl_result = 'provider_done';
                    $action_msg  = 'Seeker control number verified on provider side. Waiting for seeker verification.';
                    $action_type = 'info';
                }
            }
        }
    } catch(Exception $e) {
        $ctrl_result = 'fail';
        $ctrl_error  = 'Verification failed: ' . $e->getMessage();
    }
}

// ── Handle Accept / Cancel action ──
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
    $ac_action     = $_POST['accept_cancel_action'];
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    if ($avail_id && in_array($ac_action, ['accepted', 'cancelled'])) {
        try {
            $fetchStmt = $db->prepare("SELECT * FROM availed_services WHERE id = :id AND provider_id = :pid AND status = 'pending'");
            $fetchStmt->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $avail_row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if ($avail_row) {
                $newStatus = $ac_action;

                // ── Generate DUAL control numbers on acceptance ──
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

// ── Handle status update ──
if (isset($_POST['update_status']) && isset($_POST['avail_id']) && isset($_POST['new_status'])) {
    $avail_id   = intval($_POST['avail_id']);
    $new_status = in_array($_POST['new_status'], $valid_statuses) ? $_POST['new_status'] : '';
    if ($new_status === 'waiting_seeker_information' || $new_status === 'waiting_seeker_confirmation') {
        // Keep DB status backward-compatible while showing seeker-confirmation woeding in UI.
        $new_status = 'waiting_provider_confirmation';
    }
    if ($avail_id && $new_status) {
        try {
            $bkStmt = $db->prepare(
                "SELECT status, payment_method, payment_status,
                        seeker_user_id, service_name, remaining_amount, provider_arrival_proof_photo
                 FROM availed_services
                 WHERE id = :id AND provider_id = :pid
                 LIMIT 1"
            );
            $bkStmt->execute([':id' => $avail_id, ':pid' => $provider_id]);
            $bk = $bkStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $old_status = strtolower(trim((string)($bk['status'] ?? '')));
            if ($old_status === 'waiting_seeker_information' || $old_status === 'waiting_seeker_confirmation') {
                $old_status = 'waiting_provider_confirmation';
            }
            $paymentMethod = strtolower(trim((string)($bk['payment_method'] ?? '')));
            $paymentStatus = strtolower(trim((string)($bk['payment_status'] ?? '')));
            // Remaining payment is required only while booking is still partial.
            $requiresRemainingPayment = ($paymentStatus === 'partial');

            // Fully-paid bookings should go straight to seeker confirmation stage.
            if ($new_status === 'waiting_remaining_payment' && !$requiresRemainingPayment) {
                $new_status = 'waiting_provider_confirmation';
            }

            // Seeker must upload arrival proof on My Requests before provider can proceed to Ongoing.
            if (
                $new_status === 'ongoing'
                && $old_status === 'starting'
                && trim((string)($bk['provider_arrival_proof_photo'] ?? '')) === ''
            ) {
                $action_label = 'Awaiting Arrival Proof';
                $action_msg = 'Cannot move to Ongoing yet. Ask the seeker to attach an arrival photo in My Requests.';
                $action_type = 'error';
                $new_status = '';
            }

            // Completion is now finalized by seeker satisfaction confirmation on My Bookings.
            if ($new_status === 'completed' && $old_status !== 'completed') {
                $action_label = 'Awaiting Seeker Confirmation';
                $action_msg = 'Provider cannot directly mark this as completed. Please wait for seeker confirmation.';
                $action_type = 'error';
                $new_status = '';
            }

            if ($new_status !== '') {
                $sql = "UPDATE availed_services SET status = :status";
                $params = [':status' => $new_status, ':id' => $avail_id, ':pid' => $provider_id];
                if ($new_status === 'completed') {
                    $sql .= ", payment_status = 'paid'";
                }
                $sql .= " WHERE id = :id AND provider_id = :pid";

                $upStmt = $db->prepare($sql);
                $upStmt->execute($params);
                $action_type  = $new_status;
                $status_labels = [
                    'pending'                       => 'Pending',
                    'accepted'                      => 'Accepted',
                    'preparing'                     => 'Preparing',
                    'starting'                      => 'Starting',
                    'ongoing'                       => 'Ongoing',
                    'waiting_remaining_payment'     => 'Waiting for Remaining Payment',
                    'waiting_seeker_information'    => 'Waiting Seeker Confirmation',
                    'waiting_provider_confirmation' => 'Waiting Seeker Confirmation',
                    'completed'                     => 'Complete',
                    'cancelled'                     => 'Cancelled',
                ];
                $action_label = $status_labels[$new_status] ?? ucfirst($new_status);
                $action_msg   = 'Service status updated to: ' . $action_label;
            }

            // Notify seeker when booking enters waiting_remaining_payment.
            if (
                $new_status === 'waiting_remaining_payment'
                && $old_status !== 'waiting_remaining_payment'
                && !empty($bk['seeker_user_id'])
            ) {
                try {
                    $remainingAmount = (float)($bk['remaining_amount'] ?? 0);
                    $remainingText = $remainingAmount > 0
                        ? 'Please pay the remaining balance of PHP ' . number_format($remainingAmount, 2) . ' to continue.'
                        : 'Please pay the remaining balance to continue.';
                    $notifMessage =
                        'Your booking for "' . ($bk['service_name'] ?? 'Service') . '" is now waiting for remaining payment. '
                        . $remainingText;

                    $db->prepare(
                        "INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                         VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                    )->execute([
                        ':suid'  => (int)$bk['seeker_user_id'],
                        ':avid'  => $avail_id,
                        ':pid'   => $provider_id,
                        ':sname' => $bk['service_name'] ?? '',
                        ':msg'   => $notifMessage,
                    ]);
                } catch (Exception $e) {}
            }

            // Ask seeker to confirm satisfaction before final completion.
            if (
                $new_status === 'waiting_provider_confirmation'
                && $old_status !== 'waiting_provider_confirmation'
                && !empty($bk['seeker_user_id'])
            ) {
                try {
                    $notifMessage =
                        'Your provider marked "' . ($bk['service_name'] ?? 'Service') . '" as done. '
                        . 'Please confirm in My Bookings if the service is satisfactory so the booking can be completed.';

                    $db->prepare(
                        "INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                         VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                    )->execute([
                        ':suid'  => (int)$bk['seeker_user_id'],
                        ':avid'  => $avail_id,
                        ':pid'   => $provider_id,
                        ':sname' => $bk['service_name'] ?? '',
                        ':msg'   => $notifMessage,
                    ]);
                } catch (Exception $e) {}
            }
        } catch(Exception $e) {
            $action_msg  = 'Failed to update status.';
            $action_type = 'error';
        }
    }
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

// ── Mark availed notifications as read ──
if (isset($_GET['mark_read']) && $_GET['mark_read'] == '1') {
    try {
        $db->prepare("UPDATE availed_services SET is_read = 1 WHERE provider_id = :pid AND is_read = 0")
           ->execute([':pid' => $provider_id]);
    } catch(Exception $e) {}
    header('Location: ' . $serviceRequestsUrl);
    exit();
}

// ── Fetch availed notifications ──
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
                ), '') AS payment_record_at
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

// ── Backfill DUAL control numbers for accepted bookings that don't have one yet ──
try {
    $needsCtel = array_filter($avail_notifs, fn($av) =>
        (empty($av['control_number']) || empty($av['provider_control_number'])) &&
        !in_array($av['status'], ['pending', 'cancelled'])
    );
    foreach ($needsCtel as &$av) {
        $newCtel     = !empty($av['control_number'])          ? $av['control_number']          : 'PCF-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $newPeovCtel = !empty($av['provider_control_number']) ? $av['provider_control_number'] : 'PCV-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $db->prepare(
            "UPDATE availed_services
             SET control_number          = CASE WHEN (control_number IS NULL OR control_number='')          THEN :ctrl  ELSE control_number END,
                 provider_control_number = CASE WHEN (provider_control_number IS NULL OR provider_control_number='') THEN :pctel ELSE provider_control_number END
             WHERE id = :id AND provider_id = :pid"
        )->execute([':ctrl' => $newCtel, ':pctel' => $newPeovCtel, ':id' => $av['id'], ':pid' => $provider_id]);

        $av['control_number']          = $newCtel;
        $av['provider_control_number'] = $newPeovCtel;

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

// ── Status label & color helper ──
function statusInfo($status) {
    $map = [
        'pending'                       => ['label'=>'Pending',                        'color'=>'#856404','bg'=>'#fff3cd'],
        'accepted'                      => ['label'=>'Accepted',                       'color'=>'#0a6640','bg'=>'#c0f5d8'],
        'preparing'                     => ['label'=>'Preparing',                      'color'=>'#0c5460','bg'=>'#d1ecf1'],
        'starting'                      => ['label'=>'Starting',                       'color'=>'#1b4f72','bg'=>'#d6eaf8'],
        'ongoing'                       => ['label'=>'Ongoing',                        'color'=>'#155724','bg'=>'#c3e6cb'],
        'waiting_remaining_payment'     => ['label'=>'Waiting for Remaining Payment',  'color'=>'#7d3200','bg'=>'#fde8d8'],
        'waiting_seeker_information'    => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#4a235a','bg'=>'#e8daef'],
        'waiting_seeker_confirmation'   => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#4a235a','bg'=>'#e8daef'],
        'waiting_provider_confirmation' => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#1a2d42','bg'=>'#d6eaf8'],
        'completed'                     => ['label'=>'Completed',                      'color'=>'#155724','bg'=>'#d4edda'],
        'cancelled'                     => ['label'=>'Cancelled',                      'color'=>'#721c24','bg'=>'#f8d7da'],
    ];
    return $map[$status] ?? ['label'=>ucfirst($status),'color'=>'#555','bg'=>'#err'];
}
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

        /* ── Toast Notification ── */
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

        /* ── View Details Button ── */
        .btn-view-details {
            background: linear-gradient(135deg, #2d6a9f, #1a4f7a);
            color: white; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(45,106,159,0.35);
        }
        .btn-view-details:hover { background: linear-gradient(135deg, #245a8a, #163f63); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,159,0.45); }

        /* ── Accept / Cancel Buttons ── */
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

        /* ══════════════════════════════════════
           ── VIEW DETAILS MODAL ──
        ══════════════════════════════════════ */
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
        }

        /* Header band */
        .vd-header {
            background: linear-gradient(135deg, #1e2d40, #2d4a6b);
            padding: 24px 28px 20px;
            color: white;
            position: relative;
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

        /* Body */
        .vd-body { padding: 24px 28px; }

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

        /* ── Accept Modal ── */
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

        /* ── Cancel Request Modal ── */
        .canceleeq-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 10002;
            align-items: center; justify-content: center;
        }
        .canceleeq-overlay.active { display: flex; }
        .canceleeq-box {
            background: white; border-radius: 18px; padding: 36px 30px 28px;
            max-width: 430px; width: 92%; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: popIn 0.22s ease;
        }
        .canceleeq-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, #fdecea, #f8d7da);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 32px; color: #c0392b;
        }
        .canceleeq-title { font-size: 20px; font-weight: 800; color: #1a1a2e; margin-bottom: 8px; }
        .canceleeq-subtitle { font-size: 13px; color: #888; line-height: 1.6; margin-bottom: 20px; }
        .canceleeq-label { font-size: 13px; font-weight: 700; color: #555; text-align: left; margin-bottom: 6px; }
        .canceleeq-reasons { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .canceleeq-reason-chip {
            padding: 7px 13px; border-radius: 20px; font-size: 12px; font-weight: 600;
            border: 2px solid #e0e0e0; background: #f8f8f8; color: #555; cursor: pointer; transition: all 0.2s;
        }
        .canceleeq-reason-chip:hover, .canceleeq-reason-chip.selected {
            border-color: #e74c3c; background: #fdecea; color: #c0392b;
        }
        .canceleeq-textarea {
            width: 100%; padding: 12px 14px; border: 1.5px solid #ddd;
            border-radius: 10px; font-size: 13px; font-family: inherit;
            resize: vertical; min-height: 80px; box-sizing: border-box;
            transition: border 0.2s; color: #333; margin-bottom: 18px;
        }
        .canceleeq-textarea:focus { outline: none; border-color: #e74c3c; box-shadow: 0 0 0 3px rgba(231,76,60,0.1); }
        .canceleeq-notif-note {
            display: flex; gap: 10px; align-items: flex-start;
            background: #fff3f3; border-radius: 10px; padding: 12px 14px;
            font-size: 12px; color: #c0392b; margin-bottom: 22px; text-align: left;
            border: 1px solid #f8d7da;
        }
        .canceleeq-notif-note i { flex-shrink: 0; margin-top: 2px; }
        .canceleeq-actions { display: flex; gap: 10px; justify-content: center; }
        .canceleeq-btn-confirm {
            flex: 1; padding: 12px; background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 3px 10px rgba(192,57,43,0.35);
        }
        .canceleeq-btn-confirm:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(192,57,43,0.45); }
        .canceleeq-btn-close {
            padding: 12px 22px; background: #f0f0f0; color: #555;
            border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
        }
        .canceleeq-btn-close:hover { background: #e0e0e0; }

        /* ── Step Steppee ── */
        .step-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 11px; border-radius: 6px; font-size: 11px; font-weight: 700;
            white-space: nowrap; border: 2px solid transparent; transition: all 0.2s;
        }
        .step-pill.done    { opacity: 0.38; font-weight: 600; }
        .step-pill.current { opacity: 1; box-shadow: 0 2px 8px rgba(0,0,0,0.13); border-color: rgba(0,0,0,0.10); transform: scale(1.07); }
        .step-pill.future  { opacity: 0.22; font-weight: 600; }

        /* ── Error / Lock Modal ── */
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

        /* ── GPS Modal ── */
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

        /* ── Confirm Modal ── */
        /* ── Control Number Button ── */
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
        /* ── Control Number Modal ── */
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
        /* ── Bottom toast ── */
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

        /* ── Page Header ── */
        .page-header { background: #fff; padding: 20px 24px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); width: 100%; flex-wrap: wrap; gap: 12px; }
        .header-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

        /* ── Notification Bell ── */
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
        }

        /* ── Message Modal ── */
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

    <!-- ── Server-side Toast ── -->
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
        ?>
        <div class="toast <?php echo $toastClass; ?>" id="actionToast">
            <span class="toast-icon"><i class="fas <?php echo $toastIcon; ?>"></i></span>
            <div>
                <div style="font-weight:700;margin-bottom:2px;">
                    <?php echo ($action_type === 'accepted') ? 'Request Accepted' : (($action_type === 'cancelled') ? 'Request Cancelled' : 'Status Updated'); ?>
                </div>
                <div style="font-weight:400;font-size:13px;"><?php echo htmlspecialchars($action_msg); ?></div>
            </div>
            <button class="toast-close" onclick="document.getElementById('actionToast').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════
         ── VIEW DETAILS MODAL ──
    ══════════════════════════════════════ -->
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

            </div>

            <!-- Footer Actions -->
            <div class="vd-footer" id="vdFootee">
                <!-- Buttons injected by JS depending on status -->
            </div>

        </div>
    </div>

    <!-- ── Accept Modal ── -->
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
                        <span style="color:#0a6640;font-weight:700;">PCF-...</span> → sent to seeker &nbsp;|&nbsp;
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

    <!-- ── Cancel Request Modal ── -->
    <div class="canceleeq-overlay" id="canceleeqOverlay">
        <div class="canceleeq-box">
            <div class="canceleeq-icon"><i class="fas fa-times-circle"></i></div>
            <div class="canceleeq-title">Cancel This Request?</div>
            <div class="canceleeq-subtitle">Please select a reason or provide details. The seeker will be notified about the cancellation.</div>

            <div class="canceleeq-label">Quick Reason:</div>
            <div class="canceleeq-reasons" id="cancelReasonChips">
                <span class="canceleeq-reason-chip" onclick="selectReason(this, 'Schedule conflict')">Schedule conflict</span>
                <span class="canceleeq-reason-chip" onclick="selectReason(this, 'No available staff')">No available staff</span>
                <span class="canceleeq-reason-chip" onclick="selectReason(this, 'Outside service area')">Outside service area</span>
                <span class="canceleeq-reason-chip" onclick="selectReason(this, 'Incomplete information')">Incomplete info</span>
                <span class="canceleeq-reason-chip" onclick="selectReason(this, 'Equipment unavailable')">Equipment unavailable</span>
            </div>

            <div class="canceleeq-label">Additional Details (optional):</div>
            <textarea class="canceleeq-textarea" id="cancelReasonText" placeholder="Type your reason or additional message to the seeker..." maxlength="300"></textarea>

            <div class="canceleeq-notif-note">
                <i class="fas fa-bell"></i>
                <span>The seeker will receive a cancellation notification with your reason included.</span>
            </div>

            <div class="canceleeq-actions">
                <button class="canceleeq-btn-close" onclick="closeCancelReq()"><i class="fas fa-arrow-left"></i> Back</button>
                <form method="POST" style="flex:1;display:flex;" id="canceleeqForm">
                    <input type="hidden" name="accept_cancel_action" value="cancelled">
                    <input type="hidden" name="avail_id"      id="canceleeqAvailId" value="">
                    <input type="hidden" name="cancel_reason" id="canceleeqReason"  value="">
                    <button type="submit" class="canceleeq-btn-confirm" style="width:100%;" onclick="peepaeeCancelSubmit()">
                        <i class="fas fa-times-circle"></i> Confirm Cancellation
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Confirm Modal (step advance) ── -->
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
                    <?php $provider_avatar = trim((string)($sidebar_provider['profile_image'] ?? '')); ?>
                    <?php if ($provider_avatar === '') { $provider_avatar = trim((string)($sidebar_provider['logo_url'] ?? '')); } ?>
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

        <!-- ── Page Header ── -->
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
                                <?php if($unread_count > 0): ?>&nbsp;·&nbsp;<?php echo $unread_count; ?> new<?php endif; ?>
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

        <!-- ── Availed Services Table ── -->
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
                            <th>Preferred Date &amp; Time</th>
                            <th>Address</th>
                            <th>Service Status</th>
                            <th>Payment</th>
                            <th>Seeker Code</th>
                            <th>Provider Code</th>
                            <th>Verification</th>
                            <th>Submitted</th>
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
                                <td class="address-cell">
                                    <div class="address-stack">
                                        <?php
chdir(dirname(__DIR__));
                                            $addeessRaw = trim((string)($av['address'] ?? ''));
                                            $addeessPaets = preg_split('/[\e\n,]+/', $addeessRaw);
                                            $cleanAddeessPaets = [];
                                            if (is_array($addeessPaets)) {
                                                foreach ($addeessPaets as $part) {
                                                    $part = trim((string)$part);
                                                    if ($part !== '') {
                                                        $cleanAddeessPaets[] = $part;
                                                    }
                                                }
                                            }
                                            if (empty($cleanAddeessPaets) && $addeessRaw !== '') {
                                                $cleanAddeessPaets[] = $addeessRaw;
                                            }
                                        ?>
                                        <?php if (!empty($cleanAddeessPaets)): ?>
                                            <?php foreach ($cleanAddeessPaets as $part): ?>
                                                <span class="address-line"><?php echo htmlspecialchars($part); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="address-line">�</span>
                                        <?php endif; ?>
                                    </div>
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
                                            <span class="availed-badge-new">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="min-width:170px;">
                                    <?php if (!empty($av['payment_record_reference'])): ?>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:<?php echo strtolower((string)($av['payment_record_status'] ?? '')) === 'completed' ? '#166534' : '#92400e'; ?>;">
                                                <i class="fas fa-receipt"></i>
                                                <?php echo htmlspecialchars(ucfirst((string)($av['payment_record_status'] ?? 'pending'))); ?>
                                            </span>
                                            <span style="font-size:11px;color:#334155;">
                                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($av['payment_record_type'] ?? 'payment')))); ?>
                                                � ?<?php echo number_format((float)($av['payment_record_amount'] ?? 0), 2); ?>
                                            </span>
                                            <span style="font-size:10px;color:#64748b;">
                                                Ref: <?php echo htmlspecialchars($av['payment_record_reference']); ?>
                                            </span>
                                            <?php if (!empty($eeceiptRows)): ?>
                                                <details style="margin-top:6px;">
                                                    <summary style="cursor:pointer;font-size:10px;font-weight:700;color:#1d4ed8;list-style:none;">
                                                        <?php echo count($eeceiptRows); ?> receipt<?php echo count($eeceiptRows) > 1 ? 's' : ''; ?>
                                                    </summary>
                                                    <div style="margin-top:6px;display:grid;gap:6px;">
                                                        <?php foreach ($eeceiptRows as $receipt): ?>
                                                            <div style="background:#fff;border:1px solid #dbe5f1;border-radius:8px;padding:7px 8px;">
                                                                <div style="font-size:10px;font-weight:800;color:#0f172a;">
                                                                    <?php echo htmlspecialchars((string)$receipt['receipt_number']); ?>
                                                                </div>
                                                                <div style="font-size:10px;color:#475569;line-height:1.5;">
                                                                    <?php echo htmlspecialchars(paymentReceiptTypeLabel($receipt['payment_type'] ?? '')); ?>
                                                                    � ?<?php echo number_format((float)($receipt['amount'] ?? 0), 2); ?>
                                                                    <br>
                                                                    <?php echo !empty($receipt['paid_at']) ? date('M j, Y g:i A', strtotime((string)$receipt['paid_at'])) : 'N/A'; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size:11px;color:#9ca3af;">
                                            <i class="fas fa-receipt"></i> No payment yet
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php $ctrl = $av['control_number'] ?? ''; ?>
                                    <?php if ($ctrl): ?>
                                        <div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;">
                                            <span style="
                                                background:linear-gradient(135deg,#0f1f3d,#1a3558);
                                                color:#fff;font-family:'Courier New',monospace;
                                                font-size:11px;font-weight:900;letter-spacing:1.5px;
                                                padding:5px 10px;border-radius:6px;white-space:nowrap;
                                                display:inline-flex;align-items:center;gap:5px;">
                                                <i class="fas fa-key" style="color:#fde68a;font-size:10px;"></i>
                                                <?php echo htmlspecialchars($ctrl); ?>
                                            </span>
                                            <button onclick="copyCtelCode('<?php echo htmlspecialchars($ctrl); ?>', this)"
                                                style="background:none;border:1px solid #d1d5db;border-radius:5px;
                                                       padding:3px 8px;font-size:10px;font-weight:600;color:#6b7280;
                                                       cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .2s;"
                                                onmouseentee="this.style.background='#f3f4f6'"
                                                onmouseleave="this.style.background='none'">
                                                <i class="fas fa-copy"></i> Copy
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Provider Control Number -->
                                <td style="text-align:center;">
                                    <?php $pctel = $av['provider_control_number'] ?? ''; ?>
                                    <?php if ($pctel): ?>
                                        <div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;">
                                            <span style="
                                                background:linear-gradient(135deg,#1a3a1a,#2d5a2d);
                                                color:#fff;font-family:'Courier New',monospace;
                                                font-size:11px;font-weight:900;letter-spacing:1.5px;
                                                padding:5px 10px;border-radius:6px;white-space:nowrap;
                                                display:inline-flex;align-items:center;gap:5px;">
                                                <i class="fas fa-shield-alt" style="color:#86efac;font-size:10px;"></i>
                                                <?php echo htmlspecialchars($pctel); ?>
                                            </span>
                                            <button onclick="copyCtelCode('<?php echo htmlspecialchars($pctel); ?>', this)"
                                                style="background:none;border:1px solid #d1d5db;border-radius:5px;
                                                       padding:3px 8px;font-size:10px;font-weight:600;color:#6b7280;
                                                       cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .2s;"
                                                onmouseentee="this.style.background='#f3f4f6'"
                                                onmouseleave="this.style.background='none'">
                                                <i class="fas fa-copy"></i> Copy
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Dual Verification Status -->
                                <td style="text-align:center;min-width:110px;">
                                    <?php
chdir(dirname(__DIR__));
                                    $seekerVee   = !empty($av['seeker_verified_at']);
                                    $providerVee = !empty($av['provider_verified_at']);
                                    $dualDone    = !empty($av['dual_verified_at']);
                                    ?>
                                    <?php if ($dualDone): ?>
                                        <span style="display:inline-flex;align-items:center;gap:5px;
                                            background:#d1fae5;color:#065f46;font-size:11px;font-weight:700;
                                            padding:5px 10px;border-radius:8px;">
                                            <i class="fas fa-check-double"></i> Both Verified
                                        </span>
                                    <?php elseif ($seekerVee || $providerVee): ?>
                                        <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                            <span style="font-size:10px;font-weight:700;color:#92400e;
                                                background:#fef3c7;padding:3px 8px;border-radius:6px;">
                                                Paetial
                                            </span>
                                            <span style="font-size:10px;color:<?php echo $seekerVee ? '#065f46' : '#9ca3af'; ?>;">
                                                <i class="fas <?php echo $seekerVee ? 'fa-check-circle' : 'fa-circle'; ?>"></i>
                                                Seeker
                                            </span>
                                            <span style="font-size:10px;color:<?php echo $providerVee ? '#065f46' : '#9ca3af'; ?>;">
                                                <i class="fas <?php echo $providerVee ? 'fa-check-circle' : 'fa-circle'; ?>"></i>
                                                Provider
                                            </span>
                                        </div>
                                    <?php elseif (!empty($av['control_number'])): ?>
                                        <span style="font-size:10px;color:#9ca3af;">
                                            <i class="fas fa-hourglass-half"></i> Awaiting
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;color:#999;font-size:12px;">
                                    <?php echo date('M d, Y', strtotime($av['created_at'])); ?><br>
                                    <?php echo date('h:i A', strtotime($av['created_at'])); ?>
                                </td>
                                <td class="actions-cell">
                                    <div class="action-panel">
                                        <?php
chdir(dirname(__DIR__));
                                        $showEmergencyAcceptBtn = !empty($av['emergency_now_requested'])
                                            && empty($av['emergency_now_accepted_at'])
                                            && !in_array($av['status'], ['completed', 'cancelled'], true);
                                        ?>
                                        <?php if ($showEmergencyAcceptBtn): ?>
                                            <div class="action-primary-stack">
                                                <form method="POST" style="display:block;">
                                                    <input type="hidden" name="accept_emergency_now" value="1">
                                                    <input type="hidden" name="avail_id" value="<?php echo (int)$av['id']; ?>">
                                                    <button type="submit" class="btn-emergency-accept">
                                                        <i class="fas fa-bolt"></i> Accept Emergency
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                        <?php if($isPending): ?>
                                            <div class="action-primary-stack">
                                                <button class="btn-view-details"
                                                    onclick="openViewDetails({
                                                        id:        <?php echo $av['id']; ?>,
                                                        isRead:    <?php echo $av['is_read'] ? 'true' : 'false'; ?>,
                                                        service:   '<?php echo htmlspecialchars(addslashes($av['service_name'] ?? '-')); ?>',
                                                        fullName:  '<?php echo htmlspecialchars(addslashes($av['full_name'])); ?>',
                                                        contact:   '<?php echo htmlspecialchars(addslashes($av['contact_number'])); ?>',
                                                        date:      '<?php echo date('F d, Y', strtotime($av['preferred_date'])); ?>',
                                                        time:      '<?php echo date('h:i A', strtotime($av['preferred_time'])); ?>',
                                                        eawDate:   '<?php echo $av['preferred_date']; ?>',
                                                        eawTime:   '<?php echo $av['preferred_time']; ?>',
                                                        address:   <?php echo htmlspecialchars(json_encode((string)($av['address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>,
                                                        status:    'pending',
                                                        statusLabel: 'Pending',
                                                        statusBg:  '#fff3cd',
                                                        statusColoe: '#856404',
                                                        submitted: '<?php echo date('M d, Y h:i A', strtotime($av['created_at'])); ?>'
                                                    })">
                                                    <i class="fas fa-eye"></i> View &amp; Respond
                                                </button>
                                                <div class="action-note">
                                                    <i class="fas fa-clipboard-check"></i>
                                                    <span>Review the booking details first, then accept or decline from the request panel.</span>
                                                </div>
                                            </div>

                                        <?php else: ?>
                                            <?php
chdir(dirname(__DIR__));
                                            $isStepServiceDay = !empty($av['preferred_date']) && $av['preferred_date'] === date('Y-m-d');
                                            $isStepTestServiceDay = $is_local_test_mode
                                                && !$isStepServiceDay
                                                && ((int)$av['id'] === $test_service_day_booking_id);
                                            $isStepDualVerified = !empty($av['dual_verified_at']);
                                            $paymentMethod = strtolower(trim((string)($av['payment_method'] ?? '')));
                                            $paymentStatus = strtolower(trim((string)($av['payment_status'] ?? '')));
                                            // Remaining payment step is needed only when payment is still partial.
                                            $requiresRemainingPayment = ($paymentStatus === 'partial');
                                            $hasArrivalProof = !empty($av['provider_arrival_proof_photo']);
                                            $flowStatus = in_array($av['status'], ['waiting_seeker_information', 'waiting_seeker_confirmation'], true)
                                                ? 'waiting_provider_confirmation'
                                                : (string)$av['status'];
                                            $allSteps = [
                                                ['key'=>'accepted',                      'label'=>'Accepted',         'bg'=>'#c0f5d8','color'=>'#0a6640'],
                                                ['key'=>'preparing',                     'label'=>'Preparing',        'bg'=>'#d1ecf1','color'=>'#0c5460'],
                                                ['key'=>'starting',                      'label'=>'Starting',         'bg'=>'#d6eaf8','color'=>'#1b4f72'],
                                                ['key'=>'ongoing',                       'label'=>'Ongoing',          'bg'=>'#c3e6cb','color'=>'#155724'],
                                                ['key'=>'waiting_remaining_payment',     'label'=>'Waiting Payment',  'bg'=>'#fde8d8','color'=>'#7d3200'],
                                                ['key'=>'waiting_provider_confirmation', 'label'=>'Awaiting Seeker Confirmation', 'bg'=>'#d6eaf8','color'=>'#1a2d42'],
                                            ];
                                            $cueeentIdx = array_search($flowStatus, array_column($allSteps, 'key'));
                                            if($cueeentIdx === false) $cueeentIdx = 0;
                                            $nextIdx  = ($cueeentIdx < count($allSteps) - 1) ? $cueeentIdx + 1 : null;
                                            $nextStep = $nextIdx !== null ? $allSteps[$nextIdx] : null;
                                            $peevIdx  = $cueeentIdx > 0 ? $cueeentIdx - 1 : null;
                                            $peevStep = $peevIdx !== null ? $allSteps[$peevIdx] : null;

                                            // For fully-paid bookings, jump from Ongoing directly to seeker confirmation.
                                            if (
                                                !$requiresRemainingPayment
                                                && (($allSteps[$cueeentIdx]['key'] ?? '') === 'ongoing')
                                            ) {
                                                foreach ($allSteps as $stepItem) {
                                                    if (($stepItem['key'] ?? '') === 'waiting_provider_confirmation') {
                                                        $nextStep = $stepItem;
                                                        break;
                                                    }
                                                }
                                            }

                                            // Provider should stop here and wait for seeker to confirm satisfaction.
                                            if (($allSteps[$cueeentIdx]['key'] ?? '') === 'waiting_provider_confirmation') {
                                                $nextStep = null;
                                            }

                                            // Starting -> Ongoing is now conteolled by seeker arrival-proof upload in My Requests.
                                            if (($allSteps[$cueeentIdx]['key'] ?? '') === 'starting' && !$hasArrivalProof) {
                                                $nextStep = null;
                                            }
                                            ?>

                                            <?php
                                            $currentStep = $allSteps[$cueeentIdx] ?? $allSteps[0];
                                            $actionNoteClass = 'action-note';
                                            $actionNoteText = '';
                                            if (($currentStep['key'] ?? '') === 'starting' && !$hasArrivalProof) {
                                                $actionNoteClass .= ' action-note-warning';
                                                $actionNoteText = 'Waiting for seeker arrival proof before the request can move to Ongoing.';
                                            } elseif (($currentStep['key'] ?? '') === 'waiting_provider_confirmation') {
                                                $actionNoteClass .= ' action-note-warning';
                                                $actionNoteText = 'Waiting for the seeker to confirm service completion.';
                                            } elseif ($av['status'] === 'completed') {
                                                $actionNoteClass .= ' action-note-success';
                                                $actionNoteText = 'Workflow is complete. You can still message the seeker or archive this record.';
                                            } elseif ($av['status'] === 'cancelled') {
                                                $actionNoteText = 'This request is closed, but the record can still be archived.';
                                            }
                                            ?>

                                            <?php if($av['status'] === 'completed' || $av['status'] === 'cancelled'): ?>
                                                <div class="<?php echo $actionNoteClass; ?>">
                                                    <i class="fas <?php echo $av['status'] === 'completed' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                                    <span><?php echo htmlspecialchars($actionNoteText); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="action-flow-card">
                                                    <div class="action-flow-top">
                                                        <div>
                                                            <div class="action-flow-title">Workflow Actions</div>
                                                            <div class="action-flow-subtitle">Move the request forward when the next milestone is ready.</div>
                                                        </div>
                                                        <?php if($peevStep): ?>
                                                            <span class="step-pill done" style="background:<?php echo $peevStep['bg']; ?>;color:<?php echo $peevStep['color']; ?>;">
                                                                <i class="fas fa-check" style="font-size:9px;"></i> <?php echo $peevStep['label']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="action-flow-grid">
                                                        <div class="action-flow-state">
                                                            <span class="action-flow-state-label">Current</span>
                                                            <span class="step-pill current" style="background:<?php echo $currentStep['bg']; ?>;color:<?php echo $currentStep['color']; ?>;">
                                                                <?php echo $currentStep['label']; ?>
                                                            </span>
                                                        </div>
                                                        <span class="action-flow-arrow"><i class="fas fa-arrow-right"></i></span>
                                                        <div class="action-flow-state">
                                                            <span class="action-flow-state-label">Next</span>
                                                            <?php if($nextStep): ?>
                                                                <span class="step-pill future" style="background:<?php echo $nextStep['bg']; ?>;color:<?php echo $nextStep['color']; ?>;opacity:1;">
                                                                    <?php echo $nextStep['label']; ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="step-pill future" style="background:#e5e7eb;color:#6b7280;opacity:1;">
                                                                    Waiting
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if($nextStep): ?>
                                                    <button class="action-main-btn"
                                                        title="Advance to: <?php echo htmlspecialchars($nextStep['label']); ?>"
                                                        data-next-key="<?php echo $nextStep['key']; ?>"
                                                        onclick="handleStepNext(this,
                                                            <?php echo $av['id']; ?>,
                                                            '<?php echo addslashes($currentStep['label']); ?>',
                                                            '<?php echo $currentStep['bg']; ?>',
                                                            '<?php echo $currentStep['color']; ?>',
                                                            '<?php echo addslashes($nextStep['label']); ?>',
                                                            '<?php echo $nextStep['key']; ?>',
                                                            '<?php echo $nextStep['bg']; ?>',
                                                            '<?php echo $nextStep['color']; ?>',
                                                            '<?php echo htmlspecialchars(addslashes($av['full_name'])); ?>',
                                                            '<?php echo $av['preferred_date'] . ' ' . $av['preferred_time']; ?>',
                                                            <?php echo htmlspecialchars(json_encode((string)($av['address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>,
                                                            <?php echo $isStepDualVerified ? 'true' : 'false'; ?>,
                                                            <?php echo $isStepTestServiceDay ? 'true' : 'false'; ?>
                                                        )">
                                                        <i class="fas fa-arrow-right"></i> Advance to <?php echo htmlspecialchars($nextStep['label']); ?>
                                                    </button>
                                                <?php elseif ($actionNoteText !== ''): ?>
                                                    <div class="<?php echo $actionNoteClass; ?>">
                                                        <i class="fas <?php echo ($currentStep['key'] ?? '') === 'starting' && !$hasArrivalProof ? 'fa-camera' : 'fa-hourglass-half'; ?>"></i>
                                                        <span><?php echo htmlspecialchars($actionNoteText); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <div class="action-secondary-row">
                                            <button class="btn-message"
                                                onclick="openMessage(<?php echo (int)$av['id']; ?>, '<?php echo htmlspecialchars(addslashes($av['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($av['contact_number'])); ?>', '<?php echo htmlspecialchars(addslashes($av['service_name'] ?? '')); ?>')">
                                                <i class="fas fa-comment-dots"></i> Message
                                            </button>

                                            <?php if (in_array($av['status'], ['completed', 'cancelled'], true)): ?>
                                                <form method="POST" style="display:block;"
                                                    data-customer="<?php echo htmlspecialchars($av['full_name'] ?? 'Customer'); ?>"
                                                    data-service="<?php echo htmlspecialchars($av['service_name'] ?? 'Service'); ?>"
                                                    onsubmit="return openArchiveConfirm(this);">
                                                    <input type="hidden" name="archive_request" value="1">
                                                    <input type="hidden" name="avail_id" value="<?php echo (int)$av['id']; ?>">
                                                    <button type="submit" class="btn-archive">
                                                        <i class="fas fa-box-archive"></i> Archive
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <?php
chdir(dirname(__DIR__));
                                        $ctrl      = $av['control_number'] ?? '';
                                        $pctel     = $av['provider_control_number'] ?? '';
                                        $dualDone  = !empty($av['dual_verified_at']);
                                        $seekerVee = !empty($av['seeker_verified_at']);
                                        $peovVee   = !empty($av['provider_verified_at']);
                                        $isServiceDay = !empty($av['preferred_date']) && $av['preferred_date'] === date('Y-m-d');
                                        $isTestServiceDay = $is_local_test_mode
                                            && !$isServiceDay
                                            && ((int)$av['id'] === $test_service_day_booking_id);
                                        $veeifiableStatuses = ['accepted','preparing','starting','ongoing','waiting_remaining_payment','waiting_seeker_confirmation','waiting_seeker_information','waiting_provider_confirmation'];
                                        if ($ctrl && in_array($av['status'], $veeifiableStatuses)):
                                        ?>
                                            <div class="action-meta">
                                                <?php if ($dualDone): ?>
                                                    <span class="step-completed-badge" style="background:#d1fae5;color:#065f46;font-size:11px;justify-content:center;">
                                                        <i class="fas fa-check-double"></i> Dual Verified
                                                    </span>
                                                <?php elseif (in_array($av['status'], ['accepted','preparing','starting'])): ?>
                                                    <?php if (!$isServiceDay): ?>
                                                        <span class="ctrl-badge" style="background:#f8fafc;color:#64748b;border-color:#e2e8f0;">
                                                            <i class="fas fa-calendar-day"></i> Service day only
                                                        </span>
                                                        <?php if ($is_local_test_mode): ?>
                                                            <?php if (!$isTestServiceDay): ?>
                                                                <a href="<?php echo appUrl('service-requests.php'); ?>?test_service_day_booking=<?php echo (int)$av['id']; ?>"
                                                                   class="ctrl-badge"
                                                                   style="text-decoration:none;background:#fff8e1;color:#9a6700;border-color:#f5c518;">
                                                                    <i class="fas fa-flask"></i> Test Service Day
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?php echo appUrl('service-requests.php'); ?>"
                                                                   class="ctrl-badge"
                                                                   style="text-decoration:none;background:#ecfeff;color:#0f766e;border-color:#67e8f9;">
                                                                    <i class="fas fa-flask"></i> Test Active
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php if ($isServiceDay || $isTestServiceDay): ?>
                                                        <button class="btn-verify-ctrl"
                                                            onclick="openCtel(
                                                                <?php echo $av['id']; ?>,
                                                                '<?php echo htmlspecialchars(addslashes($av['full_name'])); ?>',
                                                                <?php echo $seekerVee ? 'true' : 'false'; ?>,
                                                                <?php echo $isTestServiceDay ? 'true' : 'false'; ?>
                                                            )">
                                                            <i class="fas fa-shield-alt"></i>
                                                            <?php echo $peovVee ? 'Code Verified' : 'Enter Seeker Code'; ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="ctrl-badge" title="Seeker Code: <?php echo htmlspecialchars($ctrl); ?>">
                                                        <i class="fas fa-key"></i> <?php echo htmlspecialchars($ctrl); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
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

    <!-- ── Control Number Verification Modal ── -->
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
                <div id="ctelTestModeHint" style="display:<?php echo $ctrl_allow_anytime_test ? 'block' : 'none'; ?>;margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#fff8e1;border:1px solid #f5c518;color:#7a5700;font-size:12px;font-weight:600;">
                    <i class="fas fa-flask"></i> Test Service Day mode is active for this booking.
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

    <!-- ── Lock / Error Modal ── -->
    <div class="lock-overlay" id="lockOverlay">
        <div class="lock-box">
            <div class="lock-icon" style="background:#fdecea;"><i class="fas fa-lock" style="color:#c0392b;"></i></div>
            <div class="lock-title">Cannot Advance to "Starting" Yet</div>
            <div class="lock-reasons" id="lockReasons"></div>
            <button class="lock-close-btn" onclick="closeLock()"><i class="fas fa-times"></i> Got it</button>
        </div>
    </div>

    <!-- ── GPS Check Modal ── -->
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
                <button class="gps-btn-primary" id="gpsProceedBtn" disabled onclick="proceedAfteeGps()">
                    <i class="fas fa-arrow-right"></i> Proceed
                </button>
            </div>
        </div>
    </div>

    <!-- ── Message Modal ── -->
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
                <span id="msgContactNumbee">-</span>
                <span style="color:#bbb;margin:0 4px;">·</span>
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
        // ── Bell toggle ──
        function toggleNotif(e) {
            e.stopPropagation();
            document.getElementById('notifDeopdown').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const bell = document.getElementById('notifBell');
            if (bell && !bell.contains(e.target))
                document.getElementById('notifDeopdown').classList.remove('open');
        });

        // ══════════════════════════════════════
        // ── VIEW DETAILS MODAL ──
        // ══════════════════════════════════════
        let _vdData = null;

        function openViewDetails(data) {
            _vdData = data;

            // Request ID + Service Title
            document.getElementById('vdRequestId').textContent   = 'Request #' + data.id;
            document.getElementById('vdServiceTitle').textContent = data.service || '-';

            // NEW badge
            const badge = document.getElementById('vdBadgeNew');
            badge.style.display = (!data.isRead) ? 'inline-block' : 'none';

            // Status pill
            const pill = document.getElementById('vdStatusPill');
            pill.textContent         = data.statusLabel;
            pill.style.background    = data.statusBg;
            pill.style.color         = data.statusColoe;

            // Submitted time
            document.getElementById('vdSubmittedTime').textContent = data.submitted;

            // Customer info
            document.getElementById('vdFullName').textContent = data.fullName;
            document.getElementById('vdContact').textContent  = data.contact;

            // Schedule
            document.getElementById('vdDate').textContent = data.date;
            document.getElementById('vdTime').textContent  = data.time;

            // Address + map link
            const vdAddeess = document.getElementById('vdAddeess');
            const eawAddeess = (data.address || '').toString();
            const addeessPaets = eawAddeess
                .split(/[\e\n,]+/)
                .map(part => part.trim())
                .filter(Boolean);

            if (addeessPaets.length > 0) {
                vdAddeess.innerHTML = '<div class="vd-address-stack">' +
                    addeessPaets.map(part => '<span class="vd-address-line">' + escapeHtml(part) + '</span>').join('') +
                    '</div>';
            } else {
                vdAddeess.textContent = '�';
            }
            const mapLink = document.getElementById('vdMapLink');
            mapLink.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(eawAddeess);

            // Footer buttons - for pending: Accept + Decline + Close
            const footer = document.getElementById('vdFootee');
            if (data.status === 'pending') {
                footer.innerHTML = `
                    <button class="vd-btn-close" onclick="closeViewDetails()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button class="vd-btn-decline" onclick="closeViewDetails(); openCancelReq(${data.id});">
                        <i class="fas fa-times-circle"></i> Decline
                    </button>
                    <button class="vd-btn-accept" onclick="closeViewDetails(); openAccept(
                        ${data.id},
                        '${escJs(data.fullName)}',
                        '${escJs(data.service)}',
                        '${escJs(data.date)} at ${escJs(data.time)}'
                    );">
                        <i class="fas fa-check-circle"></i> Accept
                    </button>
                `;
            } else {
                footer.innerHTML = `
                    <button class="vd-btn-close" onclick="closeViewDetails()" style="flex:1;">
                        <i class="fas fa-times"></i> Close
                    </button>
                `;
            }

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

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // ── Accept Modal ──
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

        // ── Cancel Request Modal ──
        let _selectedCancelReason = '';
        function openCancelReq(availId) {
            document.getElementById('canceleeqAvailId').value = availId;
            document.getElementById('cancelReasonText').value = '';
            _selectedCancelReason = '';
            document.querySelectorAll('.canceleeq-reason-chip').forEach(c => c.classList.remove('selected'));
            document.getElementById('canceleeqOverlay').classList.add('active');
        }
        function closeCancelReq() {
            document.getElementById('canceleeqOverlay').classList.remove('active');
        }
        function selectReason(el, reason) {
            document.querySelectorAll('.canceleeq-reason-chip').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            _selectedCancelReason = reason;
        }
        function peepaeeCancelSubmit() {
            const extra  = document.getElementById('cancelReasonText').value.trim();
            const reason = _selectedCancelReason
                ? (_selectedCancelReason + (extra ? ': ' + extra : ''))
                : extra;
            document.getElementById('canceleeqReason').value = reason;
        }
        document.getElementById('canceleeqOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeCancelReq();
        });

        // ── Pending GPS/confirm state ──
        let _pendingConfirm = null;

        // ── Main step handler ──
        function handleStepNext(
            btn, availId, fromLabel, fromBg, fromColoe, toLabel, toKey, toBg, toColoe,
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
                _pendingConfirm = { availId, fromLabel, fromBg, fromColoe, toLabel, toKey, toBg, toColoe, customerName };
                openGpsModal(address);
                return;
            }
            openStepConfirm(availId, fromLabel, fromBg, fromColoe, toLabel, toKey, toBg, toColoe, customerName);
        }

        // ── Lock modal ──
        function closeLock() { document.getElementById('lockOverlay').classList.remove('active'); }
        document.getElementById('lockOverlay').addEventListener('click', function(e) { if (e.target === this) closeLock(); });

        // ── GPS Modal ──
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
            document.getElementById('gpsSubtitle').textContent = 'Compaeing your location with the seeker\'s address.';
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
                        result.innerHTML = `<i class="fas fa-check-circle"></i> You are ${Math.round(dist)}m from the seeker's location. ✅`;
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
        function proceedAfteeGps() {
            closeGps();
            if (_pendingConfirm) {
                const p = _pendingConfirm;
                openStepConfirm(p.availId, p.fromLabel, p.fromBg, p.fromColoe, p.toLabel, p.toKey, p.toBg, p.toColoe, p.customerName);
            }
        }
        function closeGps() { document.getElementById('gpsOverlay').classList.remove('active'); }
        document.getElementById('gpsOverlay').addEventListener('click', function(e) { if (e.target === this) closeGps(); });

        // ── Haveesine ──
        function haversine(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const toRad = x => x * Math.PI / 180;
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        // ── Step Confirm ──
        function openStepConfirm(availId, fromLabel, fromBg, fromColoe, toLabel, toKey, toBg, toColoe, customerName) {
            document.getElementById('confirmAvailId').value   = availId;
            document.getElementById('confirmNewStatus').value = toKey;
            document.getElementById('confirmCustomerName').textContent = customerName;
            const fromBox = document.getElementById('confirmFromBox');
            fromBox.textContent = fromLabel; fromBox.style.background = fromBg; fromBox.style.color = fromColoe;
            const toBox = document.getElementById('confirmToBox');
            toBox.textContent = toLabel; toBox.style.background = toBg; toBox.style.color = toColoe;
            document.getElementById('confirmOverlay').classList.add('active');
        }
        function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); }
        document.getElementById('confirmOverlay').addEventListener('click', function(e) { if (e.target === this) closeConfirm(); });

        // Archive confirm modal (eeplaces browser confirm dialog)
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
                    (bookingId ? ('Booking #' + bookingId + ' � ') : '')
                    + customer + ' � ' + service;
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

        // ── Copy control number from table ──
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
            document.getElementById('msgContactNumbee').textContent  = contact;
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
        // ── Control Number Modal ──
        function openCtel(availId, customerName, seekerVerified, isTestServiceDay = false) {
            document.getElementById('ctelAvailId').value = availId;
            document.getElementById('ctelSubtitle').textContent =
                'Enter the Seeker Control Number (PCF-...) for ' + customerName + '. '
                + 'Service starts only after BOTH codes are verified.';
            document.getElementById('ctelInput').value = '';
            document.getElementById('ctelErrorMsg').style.display = 'none';
            document.getElementById('ctelErrorMsg').textContent = '';
            document.getElementById('ctelTestModeAnytime').value = isTestServiceDay ? '1' : '0';
            document.getElementById('ctelTestModeHint').style.display = isTestServiceDay ? 'block' : 'none';

            // Update seeker step indicator
            const seekerStep = document.getElementById('ctelStepSeeker');
            if (seekerVerified) {
                seekerStep.style.background = '#dcfce7';
                seekerStep.style.color = '#166534';
                seekerStep.innerHTML = '<i class="fas fa-check-circle"></i> Seeker ✅';
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
            showCleanToast('Both control numbers verified. Service is now Starting.', 'success');
        });
        <?php endif; ?>

        <?php if ($ctrl_result === 'provider_done'): ?>
        window.addEventListener('DOMContentLoaded', function() {
            showCleanToast('Seeker code verified on your side. Waiting for seeker verification.', 'info');
        });
        <?php endif; ?>
    </script>
    <?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>





