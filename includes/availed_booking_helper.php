<?php

if (!function_exists('ensureAvailedRescheduleColumns')) {
    function ensureAvailedRescheduleColumns(PDO $db): void
    {
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_request_status VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_requested_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_proposed_date DATE DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_proposed_time TIME DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_reason TEXT DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS reschedule_responded_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    }
}

if (!function_exists('appendAvailedStatusHistory')) {
    function appendAvailedStatusHistory(
        PDO $db,
        int $availId,
        ?string $oldStatus,
        string $newStatus,
        ?int $changedBy,
        string $changedByRole,
        string $notes = ''
    ): void {
        try {
            $stmt = $db->prepare(
                "INSERT INTO availed_service_status_history
                    (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                 VALUES (:aid, :old_status, :new_status, :changed_by, :changed_by_role, :notes, NOW())"
            );
            $stmt->execute([
                ':aid' => $availId,
                ':old_status' => $oldStatus,
                ':new_status' => $newStatus,
                ':changed_by' => $changedBy ?: null,
                ':changed_by_role' => $changedByRole,
                ':notes' => $notes,
            ]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('notifySeekerForAvailedBooking')) {
    function notifySeekerForAvailedBooking(
        PDO $db,
        int $seekerUserId,
        int $availId,
        int $providerId,
        string $serviceName,
        string $type,
        string $message
    ): void {
        try {
            $stmt = $db->prepare(
                "INSERT INTO seeker_notifications
                    (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                 VALUES (:suid, :avid, :pid, :sname, :type, :msg, 0)"
            );
            $stmt->execute([
                ':suid' => $seekerUserId,
                ':avid' => $availId,
                ':pid' => $providerId,
                ':sname' => $serviceName,
                ':type' => $type,
                ':msg' => $message,
            ]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('generateAvailedControlNumber')) {
    function generateAvailedControlNumber(string $prefix): string
    {
        return $prefix . '-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}

if (!function_exists('acceptAvailedBooking')) {
    function acceptAvailedBooking(
        PDO $db,
        int $availId,
        int $providerId,
        ?int $changedBy = null,
        string $changedByRole = 'provider',
        string $notes = ''
    ): array {
        $txStarted = false;

        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $txStarted = true;
            }

            $stmt = $db->prepare(
                "SELECT *
                 FROM availed_services
                 WHERE id = :id AND provider_id = :pid
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $availId, ':pid' => $providerId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                if ($txStarted && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'Booking not found.'];
            }

            $oldStatus = strtolower(trim((string)($booking['status'] ?? '')));
            if ($oldStatus !== 'pending') {
                if ($txStarted && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'This request is no longer pending and cannot be updated.'];
            }

            $seekerControlNumber = trim((string)($booking['control_number'] ?? ''));
            $providerControlNumber = trim((string)($booking['provider_control_number'] ?? ''));
            if ($seekerControlNumber === '') {
                $seekerControlNumber = generateAvailedControlNumber('PCF');
            }
            if ($providerControlNumber === '') {
                $providerControlNumber = generateAvailedControlNumber('PCV');
            }

            $update = $db->prepare(
                "UPDATE availed_services
                 SET status = 'accepted',
                     is_read = 1,
                     control_number = :ctrl,
                     provider_control_number = :provider_ctrl,
                     seeker_verified_at = NULL,
                     provider_verified_at = NULL,
                     dual_verified_at = NULL,
                     updated_at = NOW()
                 WHERE id = :id
                   AND provider_id = :pid
                   AND status = 'pending'"
            );
            $update->execute([
                ':ctrl' => $seekerControlNumber,
                ':provider_ctrl' => $providerControlNumber,
                ':id' => $availId,
                ':pid' => $providerId,
            ]);

            if ($update->rowCount() <= 0) {
                if ($txStarted && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'This request is no longer pending and cannot be updated.'];
            }

            appendAvailedStatusHistory(
                $db,
                $availId,
                'pending',
                'accepted',
                $changedBy,
                $changedByRole,
                $notes !== '' ? $notes : 'Service request accepted.'
            );

            if ($txStarted && $db->inTransaction()) {
                $db->commit();
            }

            if (!empty($booking['seeker_user_id'])) {
                $message =
                    'Your service request for "' . ($booking['service_name'] ?? 'Service') . '" has been ACCEPTED by the provider. '
                    . 'Your Seeker Control Number is: ' . $seekerControlNumber . '. '
                    . 'On service day, keep this code ready because the provider will verify this seeker code before service starts.';

                notifySeekerForAvailedBooking(
                    $db,
                    (int)$booking['seeker_user_id'],
                    $availId,
                    $providerId,
                    (string)($booking['service_name'] ?? ''),
                    'accepted',
                    $message
                );
            }

            return [
                'ok' => true,
                'booking' => $booking,
                'control_number' => $seekerControlNumber,
                'provider_control_number' => $providerControlNumber,
            ];
        } catch (Exception $e) {
            if ($txStarted && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

// Extracted from provider/service-requests.php's own $_POST['accept_cancel_action']
// === 'cancelled' branch, so a second caller (provider-portal/my-services.php's
// field-tech self-service "let them decide" Accept/Decline) doesn't duplicate
// this logic — same reasoning as acceptAvailedBooking() above.
if (!function_exists('declineAvailedBooking')) {
    function declineAvailedBooking(
        PDO $db,
        int $availId,
        int $providerId,
        ?int $changedBy = null,
        string $changedByRole = 'provider',
        string $reason = ''
    ): array {
        try {
            $fetchStmt = $db->prepare(
                "SELECT * FROM availed_services WHERE id = :id AND provider_id = :pid AND status = 'pending' LIMIT 1"
            );
            $fetchStmt->execute([':id' => $availId, ':pid' => $providerId]);
            $booking = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                return ['ok' => false, 'error' => 'This request is no longer pending and cannot be updated.'];
            }

            $updateStmt = $db->prepare(
                "UPDATE availed_services
                 SET status = 'cancelled', is_read = 1, updated_at = NOW()
                 WHERE id = :id AND provider_id = :pid AND status = 'pending'"
            );
            $updateStmt->execute([':id' => $availId, ':pid' => $providerId]);

            if ($updateStmt->rowCount() <= 0) {
                return ['ok' => false, 'error' => 'This request is no longer pending and cannot be updated.'];
            }

            $reasonText = $reason !== '' ? ' Reason: ' . $reason : '';
            $notifMessage = 'Your service request for "' . ($booking['service_name'] ?? 'Service') . '" has been CANCELLED by the provider.' . $reasonText;

            if (!empty($booking['seeker_user_id'])) {
                notifySeekerForAvailedBooking(
                    $db,
                    (int)$booking['seeker_user_id'],
                    $availId,
                    $providerId,
                    (string)($booking['service_name'] ?? ''),
                    'cancelled',
                    $notifMessage
                );
            }

            appendAvailedStatusHistory(
                $db,
                $availId,
                'pending',
                'cancelled',
                $changedBy,
                $changedByRole,
                $reason !== '' ? 'Service request cancelled. Reason: ' . $reason : 'Service request cancelled.'
            );

            return ['ok' => true, 'booking' => $booking];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
