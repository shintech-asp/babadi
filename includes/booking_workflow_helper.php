<?php
// ============================================================
// includes/booking_workflow_helper.php  (FIXED)
// Targets availed_services + provider_staff as per your schema
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ------------------------------------------------------------
// Status constants — match your availed_services ENUM exactly
// ------------------------------------------------------------
define('BK_PENDING',                    'pending');
define('BK_ACCEPTED',                   'accepted');
define('BK_PREPARING',                  'preparing');
define('BK_STARTING',                   'starting');
define('BK_ONGOING',                    'on_going');
define('BK_WAITING_REMAINING',          'waiting_for_remaining_payment');
define('BK_WAITING_SEEKER_CONFIRM',     'waiting_for_seeker_confirmation');
define('BK_WAITING_PROVIDER_CONFIRM',   'waiting_for_provider_confirmation');
define('BK_COMPLETED',                  'completed');
define('BK_CANCELLED',                  'cancelled');

function bookingStatusLabel(string $status): string {
    $labels = [
        BK_PENDING                  => 'Pending',
        BK_ACCEPTED                 => 'Accepted',
        BK_PREPARING                => 'Preparing',
        BK_STARTING                 => 'Starting',
        BK_ONGOING                  => 'On-going',
        BK_WAITING_REMAINING        => 'Waiting for Remaining Payment',
        BK_WAITING_SEEKER_CONFIRM   => 'Waiting for Seeker Confirmation',
        BK_WAITING_PROVIDER_CONFIRM => 'Waiting for Provider Confirmation',
        BK_COMPLETED                => 'Completed',
        BK_CANCELLED                => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function bookingStatusBadgeClass(string $status): string {
    $map = [
        BK_PENDING                  => 'badge bg-secondary',
        BK_ACCEPTED                 => 'badge bg-info text-dark',
        BK_PREPARING                => 'badge bg-warning text-dark',
        BK_STARTING                 => 'badge bg-primary',
        BK_ONGOING                  => 'badge bg-success',
        BK_WAITING_REMAINING        => 'badge bg-danger',
        BK_WAITING_SEEKER_CONFIRM   => 'badge bg-warning text-dark',
        BK_WAITING_PROVIDER_CONFIRM => 'badge bg-info text-dark',
        BK_COMPLETED                => 'badge bg-success',
        BK_CANCELLED                => 'badge bg-dark',
    ];
    return $map[$status] ?? 'badge bg-secondary';
}

// Moved verbatim from provider/service-requests.php (was a page-local
// function) so provider-portal/my-services.php's read-only "View Details"
// modal for field techs can render the exact same status label/colors
// instead of a second, drifting copy.
if (!function_exists('statusInfo')) {
    function statusInfo($status) {
        $map = [
            'pending'                       => ['label'=>'Pending',                        'color'=>'#856404','bg'=>'#fff3cd'],
            'accepted'                      => ['label'=>'Accepted',                       'color'=>'#0a6640','bg'=>'#c0f5d8'],
            'preparing'                     => ['label'=>'Preparing',                      'color'=>'#0c5460','bg'=>'#d1ecf1'],
            'starting'                      => ['label'=>'Starting',                       'color'=>'#1b4f72','bg'=>'#d6eaf8'],
            'ongoing'                       => ['label'=>'Ongoing',                        'color'=>'#155724','bg'=>'#c3e6cb'],
            'on_going'                      => ['label'=>'Ongoing',                        'color'=>'#155724','bg'=>'#c3e6cb'],
            // Written by includes/ControlNumberService.php when both control
            // numbers are verified — same real state as 'ongoing'/'on_going'.
            'in_progress'                   => ['label'=>'Ongoing',                        'color'=>'#155724','bg'=>'#c3e6cb'],
            'waiting_remaining_payment'     => ['label'=>'Waiting for Remaining Payment',  'color'=>'#7d3200','bg'=>'#fde8d8'],
            'waiting_seeker_information'    => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#4a235a','bg'=>'#e8daef'],
            'waiting_seeker_confirmation'   => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#4a235a','bg'=>'#e8daef'],
            'waiting_provider_confirmation' => ['label'=>'Waiting Seeker Confirmation',    'color'=>'#1a2d42','bg'=>'#d6eaf8'],
            'awaiting_agreement'            => ['label'=>'Awaiting Seeker Decision',       'color'=>'#4a235a','bg'=>'#e8daef'],
            'revising'                      => ['label'=>'Revising After Feedback',        'color'=>'#7d3200','bg'=>'#fde8d8'],
            'completed'                     => ['label'=>'Completed',                      'color'=>'#155724','bg'=>'#d4edda'],
            'cancelled'                     => ['label'=>'Cancelled',                      'color'=>'#721c24','bg'=>'#f8d7da'],
        ];
        return $map[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'color' => '#555',
            'bg'    => '#e9ecef',
        ];
    }
}

// ------------------------------------------------------------
// Allowed transitions
// ------------------------------------------------------------
function isValidTransition(string $from, string $to): bool {
    $allowed = [
        BK_PENDING                  => [BK_ACCEPTED, BK_CANCELLED],
        BK_ACCEPTED                 => [BK_PREPARING, BK_CANCELLED],
        BK_PREPARING                => [BK_STARTING, BK_CANCELLED],
        BK_STARTING                 => [BK_ONGOING],
        BK_ONGOING                  => [BK_WAITING_REMAINING, BK_WAITING_SEEKER_CONFIRM],
        BK_WAITING_REMAINING        => [BK_WAITING_PROVIDER_CONFIRM, BK_CANCELLED],
        BK_WAITING_PROVIDER_CONFIRM => [BK_COMPLETED],
        BK_WAITING_SEEKER_CONFIRM   => [BK_COMPLETED, BK_CANCELLED],
        BK_COMPLETED                => [],
        BK_CANCELLED                => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

// ------------------------------------------------------------
// Core transition — call this for EVERY status change
// ------------------------------------------------------------
function transitionBookingStatus(
    PDO    $pdo,
    int    $availedId,
    string $newStatus,
    int    $changedBy     = 0,
    string $changedByRole = 'system',
    string $notes         = ''
): bool {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT status, payment_method FROM availed_services WHERE id = ? FOR UPDATE");
        $stmt->execute([$availedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) { $pdo->rollBack(); return false; }

        $oldStatus = $row['status'];

        if (!isValidTransition($oldStatus, $newStatus)) {
            error_log("Invalid transition: $oldStatus → $newStatus (availed_service #$availedId)");
            $pdo->rollBack();
            return false;
        }

        // Extra fields for specific statuses
        $extra  = [];
        $params = [];

        switch ($newStatus) {
            case BK_STARTING:
                $extra[]  = 'qr_token = ?';
                $params[] = generateQrToken($availedId);
                break;
            case BK_ONGOING:
                $extra[] = 'service_started_at = NOW()';
                break;
            case BK_WAITING_REMAINING:
            case BK_WAITING_SEEKER_CONFIRM:
                $extra[] = 'service_ended_at = NOW()';
                break;
            case BK_WAITING_PROVIDER_CONFIRM:
                $extra[] = 'remaining_payment_received_at = NOW()';
                break;
            case BK_COMPLETED:
                if ($row['payment_method'] === 'downpayment') {
                    $extra[] = 'provider_confirmed_at = NOW()';
                } else {
                    $extra[] = 'seeker_confirmed_at = NOW()';
                }
                $extra[]  = 'payment_status = ?';
                $params[] = 'paid';
                break;
        }

        $setParts  = array_merge(['status = ?', 'updated_at = NOW()'], $extra);
        $allParams = array_merge([$newStatus], $params, [$availedId]);

        $pdo->prepare("UPDATE availed_services SET " . implode(', ', $setParts) . " WHERE id = ?")
            ->execute($allParams);

        // Log history
        $pdo->prepare("
            INSERT INTO availed_service_status_history
                (availed_id, old_status, new_status, changed_by, changed_by_role, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$availedId, $oldStatus, $newStatus, $changedBy ?: null, $changedByRole, $notes]);

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("transitionBookingStatus error: " . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// QR helpers
// ------------------------------------------------------------
function generateQrToken(int $availedId): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous 0/O/1/I/L
    $bytes = random_bytes(6);
    $token = '';
    for ($i = 0; $i < 6; $i++) {
        $token .= $chars[ord($bytes[$i]) % 32];
    }
    return $token;
}

function validateQrToken(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, u.first_name, u.last_name, u.email AS seeker_email
        FROM   availed_services a
        JOIN   users u ON u.id = a.user_id
        WHERE  a.qr_token = ? AND a.status = ?
        LIMIT  1
    ");
    $stmt->execute([$token, BK_STARTING]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function scanQrAndStartService(PDO $pdo, string $token, int $staffId): array {
    $booking = validateQrToken($pdo, $token);
    if (!$booking) {
        return ['success' => false, 'message' => 'Invalid or expired QR code.'];
    }

    $pdo->prepare("UPDATE availed_services SET qr_scanned_at = NOW() WHERE id = ?")
        ->execute([$booking['id']]);

    $ok = transitionBookingStatus($pdo, $booking['id'], BK_ONGOING, $staffId, 'provider', 'QR scanned on-site');

    if (!$ok) {
        return ['success' => false, 'message' => 'Could not update booking status.'];
    }

    // Re-fetch after the transition — $booking above is a pre-transition
    // snapshot (still status='starting'), and returning it as-is would hand
    // callers stale data even though the DB was updated correctly.
    $freshStmt = $pdo->prepare("SELECT * FROM availed_services WHERE id = ?");
    $freshStmt->execute([$booking['id']]);
    $freshBooking = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;

    return ['success' => true, 'message' => 'Service started!', 'booking' => $freshBooking];
}

// ------------------------------------------------------------
// Inventory helpers
//
// Equipment (durable tools) and consumables (used-up supplies) behave
// differently:
//  - Consumables are permanently decremented from inventory_items.quantity_available
//    the moment they're used on a booking — they're gone.
//  - Equipment is "checked out", not consumed: quantity_available stays the
//    total owned, and how many are currently free is computed live as
//    total owned minus whatever's checked out on bookings that haven't
//    reached completed/cancelled yet (getCheckedOutQuantity()). The moment a
//    booking's status becomes completed or cancelled, its equipment is
//    implicitly "returned" — there is no separate return step to remember,
//    since availability is always computed from current booking statuses.
// ------------------------------------------------------------

function getCheckedOutQuantity(PDO $pdo, int $inventoryItemId, ?int $excludeAvailedId = null): int {
    $sql = "
        SELECT COALESCE(SUM(biu.quantity_used), 0)
        FROM booking_inventory_usage biu
        JOIN availed_services a ON a.id = biu.availed_id
        WHERE biu.inventory_item_id = ?
          AND a.status NOT IN ('completed', 'cancelled')
    ";
    $params = [$inventoryItemId];
    // Excludes a specific booking's own already-checked-out usage from the
    // sum — needed when re-opening Prepare Booking on a booking that is
    // already 'preparing' (editing its equipment), so the availability
    // ceiling shown/enforced is "free to everyone else", not artificially
    // lowered by what this same booking already holds.
    if ($excludeAvailedId !== null) {
        $sql .= " AND a.id != ?";
        $params[] = $excludeAvailedId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function checkInventorySufficiency(PDO $pdo, int $providerId, array $items, ?int $excludeAvailedId = null): array {
    $insufficient = [];
    foreach ($items as $item) {
        $stmt = $pdo->prepare("
            SELECT item_name, item_type, quantity_available
            FROM inventory_items WHERE id = ? AND provider_id = ?
        ");
        $stmt->execute([$item['inventory_item_id'], $providerId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            $insufficient[] = [
                'id' => $item['inventory_item_id'], 'name' => 'Unknown',
                'available' => 0, 'needed' => $item['quantity_needed'],
            ];
            continue;
        }

        if ($inv['item_type'] === 'equipment') {
            $checkedOut = getCheckedOutQuantity($pdo, (int)$item['inventory_item_id'], $excludeAvailedId);
            $available  = max(0, (int)$inv['quantity_available'] - $checkedOut);
        } else {
            $available = (int)$inv['quantity_available'];
        }

        if ($available < $item['quantity_needed']) {
            $insufficient[] = [
                'id'        => $item['inventory_item_id'],
                'name'      => $inv['item_name'],
                'available' => $available,
                'needed'    => $item['quantity_needed'],
            ];
        }
    }
    return $insufficient;
}

// Delta-aware: safe to call more than once for the same $availedId (e.g.
// editing equipment/consumables on a booking that's already 'preparing').
// A second call diffs against what's already recorded in
// booking_inventory_usage and only applies the NET change to consumables'
// quantity_available — a naive re-subtract-the-full-amount would
// double-deduct every time equipment is edited after the first Prepare.
function deductInventoryForBooking(PDO $pdo, int $availedId, array $items): bool {
    try {
        $pdo->beginTransaction();

        $existingStmt = $pdo->prepare("SELECT inventory_item_id, quantity_used FROM booking_inventory_usage WHERE availed_id = ?");
        $existingStmt->execute([$availedId]);
        $existing = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(int)$row['inventory_item_id']] = (int)$row['quantity_used'];
        }

        $newIds = [];
        foreach ($items as $item) {
            $itemId = (int)$item['inventory_item_id'];
            $newQty = (int)$item['quantity_needed'];
            $newIds[] = $itemId;
            $delta = $newQty - ($existing[$itemId] ?? 0);

            $pdo->prepare("
                INSERT INTO booking_inventory_usage (availed_id, inventory_item_id, quantity_used)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity_used = VALUES(quantity_used)
            ")->execute([$availedId, $itemId, $newQty]);

            $typeStmt = $pdo->prepare("SELECT item_type FROM inventory_items WHERE id = ?");
            $typeStmt->execute([$itemId]);
            $itemType = $typeStmt->fetchColumn();

            // Only consumables get permanently subtracted — equipment is
            // just checked out (tracked via the booking_inventory_usage row
            // above) and stays in the owned total. Only the delta moves, so
            // re-editing the same booking's quantities doesn't re-deduct the
            // portion that was already taken on a previous call.
            if ($itemType === 'consumable' && $delta !== 0) {
                $pdo->prepare("
                    UPDATE inventory_items SET quantity_available = quantity_available - ?
                    WHERE id = ?
                ")->execute([$delta, $itemId]);
            }
        }

        // Items that were checked out before but are absent from the new
        // list (removed on an edit) — refund any consumable deduction and
        // drop the usage row so equipment frees back up immediately.
        foreach ($existing as $itemId => $oldQty) {
            if (in_array($itemId, $newIds, true)) continue;

            $typeStmt = $pdo->prepare("SELECT item_type FROM inventory_items WHERE id = ?");
            $typeStmt->execute([$itemId]);
            $itemType = $typeStmt->fetchColumn();

            if ($itemType === 'consumable' && $oldQty > 0) {
                $pdo->prepare("
                    UPDATE inventory_items SET quantity_available = quantity_available + ?
                    WHERE id = ?
                ")->execute([$oldQty, $itemId]);
            }

            $pdo->prepare("DELETE FROM booking_inventory_usage WHERE availed_id = ? AND inventory_item_id = ?")
                ->execute([$availedId, $itemId]);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("deductInventory error: " . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Staff assignment (uses provider_staff table)
// ------------------------------------------------------------
function assignStaffToBooking(PDO $pdo, int $availedId, array $staffIds, int $assignedBy): bool {
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM booking_staff_assignments WHERE availed_id = ?")
            ->execute([$availedId]);
        foreach ($staffIds as $staffId) {
            $pdo->prepare("
                INSERT INTO booking_staff_assignments (availed_id, staff_id, assigned_by)
                VALUES (?, ?, ?)
            ")->execute([$availedId, $staffId, $assignedBy]);
        }
        // Also update the JSON column on availed_services for quick reads
        $pdo->prepare("UPDATE availed_services SET assigned_staff = ? WHERE id = ?")
            ->execute([json_encode($staffIds), $availedId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("assignStaff error: " . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Inspection flow (opt-in per service via services.requires_inspection)
// ------------------------------------------------------------

/**
 * Saves an inspection-report photo for a booking. Returns the stored
 * relative path (for the DB column) on success, false on a real error,
 * or null if no file was actually uploaded.
 */
function uploadInspectionReportImage(string $fieldName, int $providerId, int $availedId)
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }
    $file = $_FILES[$fieldName];
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return false;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxFileSize = 8 * 1024 * 1024;

    if ($imageInfo === false || !isset($allowedMimeTypes[$imageInfo['mime'] ?? ''])) {
        return false;
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxFileSize) {
        return false;
    }

    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'inspections' . DIRECTORY_SEPARATOR . 'provider_' . $providerId;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        return false;
    }

    $extension = $allowedMimeTypes[$imageInfo['mime']];
    $filename = 'inspection_' . $availedId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $targetPath = $baseDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return false;
    }

    return 'uploads/inspections/provider_' . $providerId . '/' . $filename;
}

// ------------------------------------------------------------
// Provider-portal action helpers — shared by provider/service-requests.php
// (the owner's own web session) AND provider-portal/my-services.php (a
// field technician's separate portal_employee_id session, which can't use
// provider/service-requests.php directly since that page requires
// $_SESSION['user_id']/user_type='provider'). Extracted from three inline
// POST handlers that used to live only in service-requests.php, so giving
// techs their own equivalent actions doesn't mean a second, independently-
// drifting copy of this logic — see CLAUDE.md's dual-verification write-up
// for the exact bug class this is avoiding.
// ------------------------------------------------------------

/**
 * Provider (or an assigned field technician) submits the seeker's control
 * number. Extracted from provider/service-requests.php's inline
 * verify_control_number handler.
 *
 * Returns: ['result' => 'ok'|'fail'|'already_done'|'provider_done',
 *           'error' => string, 'message' => string,
 *           'action_type' => string, 'action_label' => string]
 */
function verifyProviderSeekerCode(PDO $db, int $availId, int $providerId, string $inputCode, bool $allowAnytimeTest = false, bool $earlyStart = false): array
{
    $isRealTimestamp = static function ($value): bool {
        $v = trim((string)$value);
        return $v !== '' && $v !== '0000-00-00 00:00:00';
    };

    $out = ['result' => 'fail', 'error' => '', 'message' => '', 'action_type' => '', 'action_label' => ''];

    try {
        $stmt = $db->prepare(
            "SELECT preferred_date, control_number, provider_control_number, seeker_verified_at,
                    provider_verified_at, dual_verified_at, full_name, status,
                    seeker_user_id, service_name, payment_status
             FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1"
        );
        $stmt->execute([':id' => $availId, ':pid' => $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $out['error'] = 'Booking not found. Please refresh and try again.';
            return $out;
        }

        if (in_array((string)($row['status'] ?? ''), ['completed', 'cancelled'], true)) {
            $out['error'] = 'This booking is already ' . $row['status'] . ' and cannot be re-verified.';
            return $out;
        }

        // Same "paid or at least a downpayment" bar $canEmergencyNow already
        // uses on seeker/my-requests.php — 'partial' still counts since a
        // downpayment plan is expected to still show 'partial' throughout
        // the service; only a fully 'unpaid' booking is blocked.
        if (!in_array((string)($row['payment_status'] ?? ''), ['paid', 'partial'], true)) {
            $out['error'] = 'The seeker needs to complete payment before service can start.';
            return $out;
        }

        if (!$allowAnytimeTest && !$earlyStart && (empty($row['preferred_date']) || $row['preferred_date'] > date('Y-m-d'))) {
            $out['error'] = 'Verification is only available on or after the service day.';
            return $out;
        }

        if (empty($row['control_number'])) {
            $out['error'] = 'Control numbers have not been generated yet. Please accept the booking first.';
            return $out;
        }

        if ($isRealTimestamp($row['dual_verified_at'] ?? null)) {
            $out['result'] = 'already_done';
            $out['message'] = 'Both control numbers were already verified. Service is already in Starting status.';
            $out['action_type'] = 'starting';
            return $out;
        }

        $expected = strtoupper(preg_replace('/\s+/', '', trim((string)$row['control_number'])));
        $inputNormalized = strtoupper(preg_replace('/\s+/', '', trim($inputCode)));
        $providerCodeOk = $expected !== '' && hash_equals($expected, $inputNormalized);

        if (!$providerCodeOk) {
            $out['error'] = 'Invalid seeker control number. Please double-check the code on your dashboard.';
            return $out;
        }

        $db->prepare(
            "UPDATE availed_services SET provider_verified_at = NOW(), updated_at = NOW()
             WHERE id = :id AND provider_id = :pid
               AND (provider_verified_at IS NULL OR provider_verified_at = '0000-00-00 00:00:00')"
        )->execute([':id' => $availId, ':pid' => $providerId]);

        $stmt->execute([':id' => $availId, ':pid' => $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $seekerDone = $isRealTimestamp($row['seeker_verified_at'] ?? null);
        $providerDone = $isRealTimestamp($row['provider_verified_at'] ?? null);

        if ($seekerDone && $providerDone) {
            // Both sides agreed to start (often before the technician is
            // physically on-site) — not itself proof of arrival, so
            // generate a QR token and require an on-site scan
            // (scanQrAndStartService()) before marking ongoing.
            $qrToken = generateQrToken($availId);
            $db->prepare(
                "UPDATE availed_services
                 SET status = 'starting', dual_verified_at = NOW(), qr_token = :qr, updated_at = NOW()
                 WHERE id = :id AND provider_id = :pid"
            )->execute([':id' => $availId, ':pid' => $providerId, ':qr' => $qrToken]);

            $out['result'] = 'ok';
            $out['message'] = "Both control numbers verified. Scan the seeker's QR code on arrival to start service.";
            $out['action_type'] = 'starting';
            $out['action_label'] = 'Starting';

            if (!empty($row['seeker_user_id'])) {
                try {
                    $db->prepare(
                        "INSERT INTO seeker_notifications
                            (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                         VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                    )->execute([
                        ':suid' => $row['seeker_user_id'],
                        ':avid' => $availId,
                        ':pid' => $providerId,
                        ':sname' => $row['service_name'] ?? '',
                        ':msg' => "Both control numbers were successfully verified. Show your QR code to the technician on arrival to start service.",
                    ]);
                } catch (Exception $e) {}
            }
        } else {
            $out['result'] = 'provider_done';
            $out['message'] = 'Seeker control number verified on provider side. Waiting for seeker verification.';
            $out['action_type'] = 'info';
        }

        return $out;
    } catch (Exception $e) {
        $out['error'] = 'Verification failed: ' . $e->getMessage();
        return $out;
    }
}

/**
 * Field technician (or provider) submits an inspection report. Extracted
 * from provider/service-requests.php's inline submit_inspection_report
 * handler. Caller must have already required config/send_email.php (for
 * EmailSender, only used if it's loaded — degrades gracefully otherwise).
 *
 * $validStaffIds — the set of field-technician ids $staffId is allowed to
 * be (the provider dashboard passes every field tech it has; the
 * tech-facing self-service page passes just [own id]).
 *
 * Returns: ['type' => 'accepted'|'error', 'message' => string]
 */
function submitProviderInspectionReport(PDO $db, int $providerId, int $availId, int $staffId, array $validStaffIds, string $notes, float $price, string $workingDate, int $changedByUserId, string $changedByRole = 'provider'): array
{
    if (!in_array($staffId, $validStaffIds, true)) {
        return ['type' => 'error', 'message' => 'Please assign a valid field technician before submitting the inspection report.'];
    }
    if ($notes === '') {
        return ['type' => 'error', 'message' => 'Please describe what the inspection found and what will happen.'];
    }
    if ($price <= 0) {
        return ['type' => 'error', 'message' => 'Please enter a valid proposed price.'];
    }
    if ($workingDate === '' || strtotime($workingDate) === false || $workingDate < date('Y-m-d')) {
        return ['type' => 'error', 'message' => 'Please choose a valid proposed working date (today or later).'];
    }

    $bkStmt = $db->prepare(
        "SELECT status, service_id, seeker_user_id, user_id, full_name
         FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1"
    );
    $bkStmt->execute([':id' => $availId, ':pid' => $providerId]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || !in_array($booking['status'], ['accepted', 'revising'], true)) {
        return ['type' => 'error', 'message' => 'This booking is not awaiting an inspection report.'];
    }

    $imagePath = uploadInspectionReportImage('inspection_image', $providerId, $availId);
    if ($imagePath === false) {
        return ['type' => 'error', 'message' => 'The inspection photo could not be uploaded. Please use a JPG, PNG, or WEBP image under 8MB.'];
    }
    if ($imagePath === null) {
        return ['type' => 'error', 'message' => 'Please attach a photo from the inspection.'];
    }

    try {
        $sql = "UPDATE availed_services
                   SET inspection_report_notes = :notes,
                       inspection_proposed_price = :price,
                       inspection_proposed_working_date = :wdate,
                       inspection_submitted_by = :staff,
                       assigned_employee_id = :staff2,
                       inspection_submitted_at = NOW(),
                       inspection_round = inspection_round + 1,
                       inspection_change_notes = NULL,
                       status = 'awaiting_agreement',
                       is_read = 0";
        $params = [
            ':notes' => $notes, ':price' => $price, ':wdate' => $workingDate,
            ':staff' => $staffId, ':staff2' => $staffId,
            ':id' => $availId, ':pid' => $providerId,
        ];
        if ($imagePath) {
            $sql .= ", inspection_report_image = :img";
            $params[':img'] = $imagePath;
        }
        $sql .= " WHERE id = :id AND provider_id = :pid";
        $db->prepare($sql)->execute($params);

        try {
            $db->prepare(
                "INSERT INTO availed_service_status_history
                    (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                 VALUES (:aid, :old, 'awaiting_agreement', :uid, :role, 'Inspection report submitted', NOW())"
            )->execute([':aid' => $availId, ':old' => $booking['status'], ':uid' => $changedByUserId ?: null, ':role' => $changedByRole]);
        } catch (Exception $e) {}

        $seekerUserId = (int)($booking['seeker_user_id'] ?: $booking['user_id']);
        try {
            // notifications.type is a strict ENUM('request','message','review',
            // 'payment','system','promotion') — the original inline handler
            // (before this extraction) used the literal 'inspection', which
            // isn't a valid value and silently failed to insert under this
            // DB's STRICT_TRANS_TABLES mode (caught by the try/catch below,
            // so no error ever surfaced). Found while testing this
            // extraction; fixed to the closest valid value.
            $db->prepare(
                "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
                 VALUES (:uid, 'request', :title, :msg, :rid, 'availed_service', :url, 0, NOW())"
            )->execute([
                ':uid' => $seekerUserId,
                ':title' => 'Inspection Report Ready — #' . $availId,
                ':msg' => 'Your provider submitted an inspection report with a proposed working date and price. Please review and respond.',
                ':rid' => $availId,
                ':url' => function_exists('appUrl') ? appUrl('my-requests.php') : 'my-requests.php',
            ]);
        } catch (Exception $e) {}
        try {
            $emailStmt = $db->prepare("SELECT email, first_name FROM users WHERE id = :id LIMIT 1");
            $emailStmt->execute([':id' => $seekerUserId]);
            $emailRow = $emailStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($emailRow['email']) && class_exists('EmailSender')) {
                $mailer = new EmailSender();
                $mailer->sendCustomEmail(
                    $emailRow['email'],
                    $emailRow['first_name'] ?? '',
                    'Inspection Report Ready — Booking #' . $availId,
                    "Your inspection is complete.\n\nProposed working date: " . date('F j, Y', strtotime($workingDate)) .
                    "\nProposed price: PHP " . number_format($price, 2) .
                    "\n\nNotes: " . $notes .
                    "\n\nPlease log in to review the photo and either agree or request changes."
                );
            }
        } catch (Exception $e) {}

        return ['type' => 'accepted', 'message' => 'Inspection report submitted. The seeker has been notified to review it.'];
    } catch (Exception $e) {
        return ['type' => 'error', 'message' => 'Failed to save the inspection report. Please try again.'];
    }
}

/**
 * Generic "advance status" action for a subset of linear transitions —
 * extracted from provider/service-requests.php's inline update_status
 * handler as-is, including its own ad-hoc old/new-status gating (it
 * predates transitionBookingStatus() above and was NOT unified with it
 * during this extraction — that would be a separate, riskier refactor).
 *
 * Returns: ['type' => string, 'label' => string, 'message' => string]
 */
function advanceAvailedServiceStatus(PDO $db, int $providerId, int $availId, string $requestedStatus, int $changedBy, string $changedByRole = 'provider', string $changedByLabel = 'Provider'): array
{
    $valid_statuses = [
        'pending', 'accepted', 'preparing', 'starting', 'ongoing',
        'waiting_remaining_payment', 'waiting_seeker_confirmation',
        'waiting_seeker_information', 'waiting_provider_confirmation',
        'completed', 'cancelled',
    ];
    $new_status = in_array($requestedStatus, $valid_statuses, true) ? $requestedStatus : '';
    if ($new_status === 'waiting_seeker_information' || $new_status === 'waiting_seeker_confirmation') {
        $new_status = 'waiting_provider_confirmation';
    }
    if (!$availId || !$new_status) {
        return ['type' => 'error', 'label' => '', 'message' => 'Invalid status update request.'];
    }

    try {
        $bkStmt = $db->prepare(
            "SELECT status, payment_method, payment_status,
                    seeker_user_id, service_name, remaining_amount
             FROM availed_services
             WHERE id = :id AND provider_id = :pid
             LIMIT 1"
        );
        $bkStmt->execute([':id' => $availId, ':pid' => $providerId]);
        $bk = $bkStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $old_status = strtolower(trim((string)($bk['status'] ?? '')));
        if ($old_status === 'waiting_seeker_information' || $old_status === 'waiting_seeker_confirmation') {
            $old_status = 'waiting_provider_confirmation';
        }
        $paymentStatus = strtolower(trim((string)($bk['payment_status'] ?? '')));
        $requiresRemainingPayment = ($paymentStatus === 'partial');

        // Fully-paid bookings should go straight to seeker confirmation stage.
        if ($new_status === 'waiting_remaining_payment' && !$requiresRemainingPayment) {
            $new_status = 'waiting_provider_confirmation';
        }

        // starting → ongoing is handled exclusively via QR scan, not this.

        if ($new_status === 'completed' && $old_status !== 'completed') {
            return ['type' => 'error', 'label' => 'Awaiting Seeker Confirmation', 'message' => 'Provider cannot directly mark this as completed. Please wait for seeker confirmation.'];
        }

        if (in_array($old_status, ['completed', 'cancelled'], true) && $new_status !== $old_status) {
            return ['type' => 'error', 'label' => 'Booking Already Closed', 'message' => 'This booking is already ' . $old_status . ' and its status can no longer be changed.'];
        }

        if ($new_status === 'preparing' && $old_status !== 'preparing') {
            return ['type' => 'error', 'label' => 'Assign Staff First', 'message' => 'Use "Prepare Booking" to assign a field technician (and equipment, if needed) before moving to Preparing.'];
        }

        $sql = "UPDATE availed_services SET status = :status";
        $params = [':status' => $new_status, ':id' => $availId, ':pid' => $providerId];
        if ($new_status === 'completed') {
            $sql .= ", payment_status = 'paid'";
        }
        $sql .= " WHERE id = :id AND provider_id = :pid";
        $db->prepare($sql)->execute($params);

        $status_labels = [
            'pending' => 'Pending', 'accepted' => 'Accepted', 'preparing' => 'Preparing',
            'starting' => 'Starting', 'ongoing' => 'Ongoing',
            'waiting_remaining_payment' => 'Waiting for Remaining Payment',
            'waiting_seeker_information' => 'Waiting Seeker Confirmation',
            'waiting_provider_confirmation' => 'Waiting Seeker Confirmation',
            'completed' => 'Complete', 'cancelled' => 'Cancelled',
        ];
        $action_label = $status_labels[$new_status] ?? ucfirst($new_status);
        $action_msg = 'Service status updated to: ' . $action_label;

        if (function_exists('appendAvailedStatusHistory')) {
            appendAvailedStatusHistory(
                $db, $availId, $old_status, $new_status,
                $changedBy, $changedByRole, $changedByLabel . ' updated booking status to ' . $action_label . '.'
            );
        }

        if ($new_status === 'waiting_remaining_payment' && $old_status !== 'waiting_remaining_payment' && !empty($bk['seeker_user_id'])) {
            try {
                $remainingAmount = (float)($bk['remaining_amount'] ?? 0);
                $remainingText = $remainingAmount > 0
                    ? 'Please pay the remaining balance of PHP ' . number_format($remainingAmount, 2) . ' to continue.'
                    : 'Please pay the remaining balance to continue.';
                $db->prepare(
                    "INSERT INTO seeker_notifications
                        (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                     VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                )->execute([
                    ':suid' => (int)$bk['seeker_user_id'], ':avid' => $availId, ':pid' => $providerId,
                    ':sname' => $bk['service_name'] ?? '',
                    ':msg' => 'Your booking for "' . ($bk['service_name'] ?? 'Service') . '" is now waiting for remaining payment. ' . $remainingText,
                ]);
            } catch (Exception $e) {}
        }

        if ($new_status === 'waiting_provider_confirmation' && $old_status !== 'waiting_provider_confirmation' && !empty($bk['seeker_user_id'])) {
            try {
                $db->prepare(
                    "INSERT INTO seeker_notifications
                        (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                     VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                )->execute([
                    ':suid' => (int)$bk['seeker_user_id'], ':avid' => $availId, ':pid' => $providerId,
                    ':sname' => $bk['service_name'] ?? '',
                    ':msg' => 'Your provider marked "' . ($bk['service_name'] ?? 'Service') . '" as done. Please confirm in My Bookings if the service is satisfactory so the booking can be completed.',
                ]);
            } catch (Exception $e) {}
        }

        return ['type' => $new_status, 'label' => $action_label, 'message' => $action_msg];
    } catch (Exception $e) {
        return ['type' => 'error', 'label' => '', 'message' => 'Failed to update status.'];
    }
}

// "Prepare Booking" (Accepted -> Preparing): staff + equipment/consumables
// assignment. Extracted verbatim from provider/service-requests.php's own
// $_POST['prepare_booking'] handler so provider-portal/my-services.php's
// field-tech self-service flow (a tech preparing their own assigned
// booking, $staffId always their own employee id) can call the identical
// logic instead of a second, independently-drifting copy — same pattern as
// verifyProviderSeekerCode()/submitProviderInspectionReport() above.
// Also the entry point for EDITING staff/equipment/consumables on a booking
// that is already 'preparing' (not just the one-shot accepted->preparing
// trigger) — status is allowed to already be BK_PREPARING, in which case no
// status transition happens (there's no preparing->preparing entry in
// isValidTransition(), and none is needed); a plain history row is written
// instead so the change is still auditable.
// $validCompanionIds defaults to $validStaffIds (the owner's web flow picks
// both from the same field-staff dropdown list); provider-portal/my-services.php's
// self-service flow passes them separately, since a tech may only ever set
// $staffId to themselves ($validStaffIds = [self]) but can pick a companion
// from OTHER field techs ($validCompanionIds = everyone else).
function prepareAvailedBooking(PDO $db, int $providerId, int $availId, int $staffId, array $validStaffIds, array $equipment, array $consumables, string $notes, int $changedBy, string $changedByRole = 'provider', ?int $companionId = null, ?array $validCompanionIds = null): array
{
    if (!in_array($staffId, $validStaffIds, true)) {
        return ['type' => 'error', 'message' => 'Please assign a valid field technician before preparing this booking.'];
    }
    if ($companionId !== null && $companionId === $staffId) {
        return ['type' => 'error', 'message' => 'Companion must be a different technician than the primary assignee.'];
    }
    if ($companionId !== null && !in_array($companionId, $validCompanionIds ?? $validStaffIds, true)) {
        return ['type' => 'error', 'message' => 'Please choose a valid companion technician.'];
    }

    $bkStmt = $db->prepare("SELECT status FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1");
    $bkStmt->execute([':id' => $availId, ':pid' => $providerId]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || !in_array($booking['status'], [BK_ACCEPTED, BK_PREPARING], true)) {
        return ['type' => 'error', 'message' => 'Booking must be "Accepted" or "Preparing" to assign staff/equipment.'];
    }
    $alreadyPreparing = $booking['status'] === BK_PREPARING;

    $allItems = array_merge($equipment, $consumables);
    // Exclude this booking's own current holdings from the availability
    // check — otherwise re-editing an already-'preparing' booking's
    // quantities would count what it already holds against itself.
    $insufficient = checkInventorySufficiency($db, $providerId, $allItems, $alreadyPreparing ? $availId : null);
    if (!empty($insufficient)) {
        $parts = array_map(fn($i) => "{$i['name']} (need {$i['needed']}, have {$i['available']})", $insufficient);
        return ['type' => 'error', 'message' => 'Insufficient inventory: ' . implode('; ', $parts)];
    }

    // Field technicians are identified by employees.id, not provider_staff.id
    // (they don't need portal-staff promotion), so this is stored on its own
    // column rather than reusing assignStaffToBooking()/booking_staff_assignments.
    $db->prepare(
        "UPDATE availed_services
         SET assigned_employee_id  = ?,
             companion_employee_id = ?,
             assigned_equipment    = ?,
             assigned_consumables  = ?,
             operations_notes      = ?,
             preparing_set_by      = ?,
             preparing_set_at      = NOW()
         WHERE id = ? AND provider_id = ?"
    )->execute([
        $staffId,
        $companionId,
        json_encode($equipment),
        json_encode($consumables),
        $notes,
        $changedBy ?: null,
        $availId,
        $providerId,
    ]);

    // Always call this, even with an empty $allItems — it's what cleans up
    // (and refunds consumables for) any items that were removed entirely on
    // an edit, not just ones whose quantity changed.
    deductInventoryForBooking($db, $availId, $allItems);

    if ($alreadyPreparing) {
        $db->prepare("
            INSERT INTO availed_service_status_history
                (availed_id, old_status, new_status, changed_by, changed_by_role, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$availId, BK_PREPARING, BK_PREPARING, $changedBy ?: null, $changedByRole, 'Equipment/staff updated while preparing']);
        return ['type' => 'preparing', 'message' => 'Booking assignment updated.'];
    }

    if (transitionBookingStatus($db, $availId, BK_PREPARING, $changedBy, $changedByRole, 'Staff and equipment assigned; preparing service')) {
        return ['type' => 'preparing', 'message' => 'Booking is now Preparing.'];
    }
    return ['type' => 'error', 'message' => 'Status transition failed.'];
}

// ------------------------------------------------------------
// Status value bridge: the DB always stores 'on_going' (with underscore)
// in availed_services.status, but UI code across this app compares
// against 'ongoing' (no underscore). This used to be a private function
// only declared inside provider/service-requests.php — every OTHER page
// that needed the same bridge (seeker/my-requests.php, seeker/
// booking-details.php) either didn't have it at all or declared its own
// conflicting local copy, which is exactly the kind of drift this
// codebase has been bitten by before. Now the one shared definition.
// ------------------------------------------------------------
if (!function_exists('normalizeWorkflowStatus')) {
    function normalizeWorkflowStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'waiting_seeker_information' || $normalized === 'waiting_seeker_confirmation') {
            return 'waiting_provider_confirmation';
        }
        if ($normalized === 'on_going' || $normalized === 'in_progress') {
            return 'ongoing';
        }
        return $normalized;
    }
}