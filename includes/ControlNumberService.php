<?php
/**
 * ControlNumberService.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Pestify – Dual Control Number System
 *
 * HOW THE CROSS-SHARED SCHEME WORKS
 * ───────────────────────────────────
 *  Two codes are generated per booking after payment is confirmed:
 *
 *   control_number          (PCF-YYYY-XXXXXX)  ← the SEEKER's code
 *   provider_control_number (PCP-YYYY-XXXXXX)  ← the PROVIDER's code
 *
 *  They are distributed OPPOSITELY:
 *   • The seeker  receives the PROVIDER code (PCP-…) in their notification.
 *   • The provider receives the SEEKER  code (PCF-…) in their notification.
 *
 *  On service day, both parties enter the code they RECEIVED:
 *   • Seeker  inputs → validated against `provider_control_number`
 *   • Provider inputs → validated against `control_number`
 *
 *  Only when BOTH inputs are correct is `dual_verified_at` stamped and
 *  the booking status advanced to `in_progress`.
 *
 * USAGE
 * ───────────────────────────────────
 *  require_once 'includes/ControlNumberService.php';
 *  $cns = new ControlNumberService($db);
 *
 *  // Called from payment webhook / PayMongo callback handler:
 *  $result = $cns->generateAndDistribute($availedServiceId, $seekerUserId, $providerUserId);
 *
 *  // Called from seeker verify-service.php:
 *  $result = $cns->verifySeekerCode($availedServiceId, $seekerUserId, $enteredCode, $ip);
 *
 *  // Called from provider crm-bookings.php:
 *  $result = $cns->verifyProviderCode($availedServiceId, $providerId, $enteredCode, $ip);
 * ─────────────────────────────────────────────────────────────────────────────
 */

class ControlNumberService
{
    private PDO $db;

    // Code format constants
    private const SEEKER_PREFIX   = 'PCF';   // Pest Control – Finder (seeker)
    private const PROVIDER_PREFIX = 'PCP';   // Pest Control – Provider

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * generateAndDistribute()
     *
     * Called immediately after a successful payment is confirmed.
     * - Generates PCF (seeker code) + PCP (provider code)
     * - Stores them in availed_services
     * - Sends cross-shared notifications:
     *     Seeker  gets → PCP code  (they must enter this on service day)
     *     Provider gets → PCF code  (they must enter this on service day)
     *
     * @param int $availedId        availed_services.id
     * @param int $seekerUserId     users.id of the seeker
     * @param int $providerUserId   users.id linked to the provider account
     *                              (providers.user_id – used for `notifications` table)
     * @return array  ['success'=>bool, 'seeker_cn'=>string, 'provider_cn'=>string, 'error'=>string]
     */
    public function generateAndDistribute(
        int $availedId,
        int $seekerUserId,
        int $providerUserId
    ): array {
        try {
            // 1. Load the booking – confirm it exists and belongs to both parties
            $booking = $this->fetchBooking($availedId);
            if (!$booking) {
                return $this->fail("Booking #$availedId not found.");
            }

            // 2. Idempotency – do not regenerate if codes already exist
            if (!empty($booking['control_number']) && !empty($booking['provider_control_number'])) {
                return [
                    'success'     => true,
                    'seeker_cn'   => $booking['control_number'],
                    'provider_cn' => $booking['provider_control_number'],
                    'already_had' => true,
                ];
            }

            // 3. Generate two cryptographically unique codes
            $seekerCN   = $this->generateCode(self::SEEKER_PREFIX);   // PCF-YYYY-XXXXXX
            $providerCN = $this->generateCode(self::PROVIDER_PREFIX);  // PCP-YYYY-XXXXXX

            $this->db->beginTransaction();

            // 4. Persist both codes
            $this->db->prepare(
                "UPDATE availed_services
                 SET control_number          = :scn,
                     provider_control_number = :pcn,
                     cn_generated_at         = NOW(),
                     updated_at              = NOW()
                 WHERE id = :id"
            )->execute([':scn' => $seekerCN, ':pcn' => $providerCN, ':id' => $availedId]);

            $svcName = htmlspecialchars($booking['service_name'] ?? 'your service');

            // 5a. Notify SEEKER: they receive the PROVIDER code (PCP)
            //     Message makes clear: "Enter this code on service day"
            $seekerMsg =
                "✅ Payment confirmed for Booking #{$availedId} – {$svcName}. " .
                "🔑 Your verification code is: {$providerCN}. " .
                "On service day, enter this code on the Verify Service page to start the service. " .
                "Keep it safe – do not share with the technician.";

            $this->insertSeekerNotification(
                seekerUserId : $seekerUserId,
                availedId    : $availedId,
                providerId   : (int)$booking['provider_id'],
                serviceName  : $booking['service_name'] ?? '',
                type         : 'payment',
                message      : $seekerMsg
            );

            // 5b. Notify PROVIDER: they receive the SEEKER code (PCF)
            //     Delivered via the generic `notifications` table (provider portal reads this)
            $providerMsg =
                "💳 Booking #{$availedId} confirmed – payment received. " .
                "🔑 Seeker Control Number: {$seekerCN}. " .
                "On service day, the technician must enter this code in CRM Bookings to start the service. " .
                "Do NOT reveal this to the client.";

            $this->insertProviderNotification(
                providerUserId : $providerUserId,
                availedId      : $availedId,
                message        : $providerMsg
            );

            $this->db->commit();

            return [
                'success'     => true,
                'seeker_cn'   => $seekerCN,
                'provider_cn' => $providerCN,
                'already_had' => false,
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->fail("Control number generation failed: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * verifySeekerCode()
     *
     * The SEEKER enters the code they RECEIVED (which is the PROVIDER code, PCP-…).
     * We validate it against `provider_control_number` in the DB.
     *
     * @param int    $availedId
     * @param int    $seekerUserId
     * @param string $entered        Raw input from the seeker's form
     * @param string $ip             For audit log
     * @return array ['success'=>bool, 'state'=>string, 'error'=>string]
     *               state: 'waiting_provider' | 'dual_verified'
     */
    public function verifySeekerCode(
        int    $availedId,
        int    $seekerUserId,
        string $entered,
        string $ip = ''
    ): array {
        $entered = strtoupper(trim($entered));

        $booking = $this->db->prepare(
            "SELECT id, status, service_name, provider_id,
                    control_number, provider_control_number,
                    seeker_verified_at, provider_verified_at, dual_verified_at
             FROM availed_services
             WHERE id = :id AND seeker_user_id = :uid"
        );
        $booking->execute([':id' => $availedId, ':uid' => $seekerUserId]);
        $row = $booking->fetch(PDO::FETCH_ASSOC);

        // Guard checks
        if (!$row) {
            return $this->fail("Booking not found or does not belong to your account.");
        }
        if ($row['dual_verified_at']) {
            $this->audit($availedId, $seekerUserId, 'seeker', $entered, 'fail_already_verified', $ip);
            return $this->fail("This booking is already verified and in progress.");
        }
        if ($row['seeker_verified_at']) {
            $this->audit($availedId, $seekerUserId, 'seeker', $entered, 'fail_already_verified', $ip);
            return $this->fail("You have already submitted your code. Waiting for the technician to verify their side.");
        }
        if (empty($row['provider_control_number'])) {
            return $this->fail("No verification code has been assigned to this booking yet. Please ensure payment is complete.");
        }

        // Cross-validation: seeker input must match PROVIDER_control_number
        if (!hash_equals($row['provider_control_number'], $entered)) {
            $this->audit($availedId, $seekerUserId, 'seeker', $entered, 'fail_wrong', $ip);
            return $this->fail("Incorrect code. Please check the code from your booking notification and try again.");
        }

        // Code correct – stamp seeker_verified_at
        $this->audit($availedId, $seekerUserId, 'seeker', $entered, 'success', $ip);

        $this->db->prepare(
            "UPDATE availed_services SET seeker_verified_at = NOW(), updated_at = NOW()
             WHERE id = :id AND seeker_user_id = :uid"
        )->execute([':id' => $availedId, ':uid' => $seekerUserId]);

        // Check if provider already verified → if so, trigger dual unlock
        if (!empty($row['provider_verified_at'])) {
            return $this->unlockService($availedId, $seekerUserId, (int)$row['provider_id'], $row['service_name']);
        }

        return ['success' => true, 'state' => 'waiting_provider', 'error' => ''];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * verifyProviderCode()
     *
     * The PROVIDER (technician) enters the code they RECEIVED (which is the SEEKER code, PCF-…).
     * We validate it against `control_number` in the DB.
     *
     * @param int    $availedId
     * @param int    $providerId     providers.id (not users.id)
     * @param string $entered        Raw input from the provider's form
     * @param string $ip             For audit log
     * @return array ['success'=>bool, 'state'=>string, 'error'=>string]
     *               state: 'waiting_seeker' | 'dual_verified'
     */
    public function verifyProviderCode(
        int    $availedId,
        int    $providerId,
        string $entered,
        string $ip = ''
    ): array {
        $entered = strtoupper(trim($entered));

        $booking = $this->db->prepare(
            "SELECT id, status, service_name,
                    seeker_user_id, control_number, provider_control_number,
                    seeker_verified_at, provider_verified_at, dual_verified_at
             FROM availed_services
             WHERE id = :id AND provider_id = :pid"
        );
        $booking->execute([':id' => $availedId, ':pid' => $providerId]);
        $row = $booking->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->fail("Booking #$availedId not found.");
        }
        if ($row['dual_verified_at']) {
            $this->audit($availedId, $providerId, 'provider', $entered, 'fail_already_verified', $ip);
            return $this->fail("Booking #$availedId is already verified and in progress.");
        }
        if ($row['provider_verified_at']) {
            $this->audit($availedId, $providerId, 'provider', $entered, 'fail_already_verified', $ip);
            return $this->fail("You have already submitted your code. Waiting for the client to verify their side.");
        }
        if (empty($row['control_number'])) {
            return $this->fail("No seeker control number found for Booking #$availedId. Ensure payment was completed.");
        }

        // Cross-validation: provider input must match SEEKER control_number
        if (!hash_equals($row['control_number'], $entered)) {
            $this->audit($availedId, $providerId, 'provider', $entered, 'fail_wrong', $ip);
            return $this->fail("Incorrect code. Please ask the client to share the code from their booking notification.");
        }

        // Code correct – stamp provider_verified_at
        $this->audit($availedId, $providerId, 'provider', $entered, 'success', $ip);

        $this->db->prepare(
            "UPDATE availed_services SET provider_verified_at = NOW(), updated_at = NOW()
             WHERE id = :id AND provider_id = :pid"
        )->execute([':id' => $availedId, ':pid' => $providerId]);

        // Check if seeker already verified → trigger dual unlock
        if (!empty($row['seeker_verified_at'])) {
            return $this->unlockService($availedId, (int)$row['seeker_user_id'], $providerId, $row['service_name']);
        }

        return ['success' => true, 'state' => 'waiting_seeker', 'error' => ''];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generates a unique control number.
     * Format: PREFIX-YYYY-XXXXXX  (6 uppercase hex chars)
     * e.g. PCF-2026-4A9F2C   |   PCP-2026-B71E00
     *
     * Uses random_bytes() for cryptographic randomness.
     */
    private function generateCode(string $prefix): string
    {
        return $prefix . '-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called when BOTH sides have verified – advances booking to in_progress.
     */
    private function unlockService(
        int    $availedId,
        int    $seekerUserId,
        int    $providerId,
        string $serviceName
    ): array {
        try {
            $this->db->beginTransaction();

            $this->db->prepare(
                "UPDATE availed_services
                 SET status            = 'in_progress',
                     dual_verified_at  = NOW(),
                     service_started_at = NOW(),
                     updated_at        = NOW()
                 WHERE id = :id"
            )->execute([':id' => $availedId]);

            // Status history
            $this->db->prepare(
                "INSERT INTO availed_service_status_history
                    (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
                 VALUES (:aid, 'starting', 'in_progress', :by, 'system',
                         'Dual control number verification complete – service unlocked', NOW())"
            )->execute([':aid' => $availedId, ':by' => $seekerUserId]);

            $svcName = htmlspecialchars($serviceName ?? 'your service');

            // Notify seeker
            $this->insertSeekerNotification(
                seekerUserId : $seekerUserId,
                availedId    : $availedId,
                providerId   : $providerId,
                serviceName  : $serviceName ?? '',
                type         : 'in_progress',
                message      : "🚀 Booking #{$availedId} – {$svcName} is now IN PROGRESS! Both verification codes matched. Your service has officially started."
            );

            $this->db->commit();

            return ['success' => true, 'state' => 'dual_verified', 'error' => ''];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->fail("Service unlock failed: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function fetchBooking(int $availedId): array|false
    {
        $s = $this->db->prepare(
            "SELECT id, provider_id, seeker_user_id, service_name,
                    control_number, provider_control_number
             FROM availed_services WHERE id = :id LIMIT 1"
        );
        $s->execute([':id' => $availedId]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function insertSeekerNotification(
        int    $seekerUserId,
        int    $availedId,
        int    $providerId,
        string $serviceName,
        string $type,
        string $message
    ): void {
        $this->db->prepare(
            "INSERT INTO seeker_notifications
                (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read, created_at)
             VALUES (:uid, :aid, :prov, :svc, :type, :msg, 0, NOW())"
        )->execute([
            ':uid'  => $seekerUserId,
            ':aid'  => $availedId,
            ':prov' => $providerId,
            ':svc'  => $serviceName,
            ':type' => $type,
            ':msg'  => $message,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function insertProviderNotification(
        int    $providerUserId,
        int    $availedId,
        string $message
    ): void {
        // `notifications` table is used by the provider portal
        try {
            $this->db->prepare(
                "INSERT INTO notifications
                    (user_id, type, title, message, related_id, related_type, is_read, action_url, created_at)
                 VALUES (:uid, 'payment', 'Booking Confirmed – Control Number Issued',
                         :msg, :rid, 'availed_service', 0,
                         '/pestify/provider-portal/crm-bookings.php', NOW())"
            )->execute([
                ':uid' => $providerUserId,
                ':msg' => $message,
                ':rid' => $availedId,
            ]);
        } catch (Exception $e) {
            // `notifications` table is optional in some installs – log and continue
            error_log("[ControlNumberService] Could not insert provider notification: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Writes one row to control_number_audit.
     * Never throws – audit failure must not break the main flow.
     */
    private function audit(
        int    $availedId,
        int    $userId,
        string $role,
        string $entered,
        string $result,
        string $ip
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO control_number_audit
                    (availed_id, attempted_by, role, entered_code, result, ip_address, created_at)
                 VALUES (:aid, :uid, :role, :code, :res, :ip, NOW())"
            )->execute([
                ':aid'  => $availedId,
                ':uid'  => $userId,
                ':role' => $role,
                ':code' => $entered,
                ':res'  => $result,
                ':ip'   => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
        } catch (Exception $e) {
            error_log("[ControlNumberService] Audit insert failed: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function fail(string $msg): array
    {
        return ['success' => false, 'state' => '', 'error' => $msg];
    }
}