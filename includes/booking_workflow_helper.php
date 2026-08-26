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

    return $ok
        ? ['success' => true,  'message' => 'Service started!', 'booking' => $booking]
        : ['success' => false, 'message' => 'Could not update booking status.'];
}

// ------------------------------------------------------------
// Inventory helpers
// ------------------------------------------------------------
function checkInventorySufficiency(PDO $pdo, int $providerId, array $items): array {
    $insufficient = [];
    foreach ($items as $item) {
        $stmt = $pdo->prepare("
            SELECT item_name, quantity_available
            FROM inventory_items WHERE id = ? AND provider_id = ?
        ");
        $stmt->execute([$item['inventory_item_id'], $providerId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv || $inv['quantity_available'] < $item['quantity_needed']) {
            $insufficient[] = [
                'id'        => $item['inventory_item_id'],
                'name'      => $inv['item_name'] ?? 'Unknown',
                'available' => $inv['quantity_available'] ?? 0,
                'needed'    => $item['quantity_needed'],
            ];
        }
    }
    return $insufficient;
}

function deductInventoryForBooking(PDO $pdo, int $availedId, array $items): bool {
    try {
        $pdo->beginTransaction();
        foreach ($items as $item) {
            $pdo->prepare("
                INSERT INTO booking_inventory_usage (availed_id, inventory_item_id, quantity_used)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity_used = VALUES(quantity_used)
            ")->execute([$availedId, $item['inventory_item_id'], $item['quantity_needed']]);

            $pdo->prepare("
                UPDATE inventory_items SET quantity_available = quantity_available - ?
                WHERE id = ?
            ")->execute([$item['quantity_needed'], $item['inventory_item_id']]);
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