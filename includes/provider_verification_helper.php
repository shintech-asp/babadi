<?php

if (!function_exists('ensureProviderVerificationWorkflow')) {
    function ensureProviderVerificationWorkflow(PDO $db): void
    {
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_status VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_notes TEXT DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_date DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_reviewed_by INT DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_reviewed_by_name VARCHAR(150) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS verification_submitted_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS provider_verification_audit (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    provider_id INT NOT NULL,
                    admin_id INT DEFAULT NULL,
                    admin_name VARCHAR(150) DEFAULT NULL,
                    decision_status VARCHAR(20) NOT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_provider_audit (provider_id, created_at),
                    INDEX idx_admin_audit (admin_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {}
    }
}

if (!function_exists('normalizeProviderVerificationState')) {
    function normalizeProviderVerificationState(string $providerStatus): string
    {
        $status = strtolower(trim($providerStatus));
        return match ($status) {
            'active' => 'approved',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }
}

if (!function_exists('syncProviderVerificationStatus')) {
    function syncProviderVerificationStatus(PDO $db): void
    {
        try {
            $db->exec(
                "UPDATE providers
                 SET verification_status = CASE
                     WHEN status = 'active' THEN 'approved'
                     WHEN status = 'rejected' THEN 'rejected'
                     ELSE 'pending'
                 END
                 WHERE verification_status IS NULL OR verification_status = ''"
            );
        } catch (Exception $e) {}
    }
}

if (!function_exists('logProviderVerificationDecision')) {
    function logProviderVerificationDecision(
        PDO $db,
        int $providerId,
        ?int $adminId,
        string $adminName,
        string $decisionStatus,
        string $notes = ''
    ): void {
        try {
            $stmt = $db->prepare(
                "INSERT INTO provider_verification_audit
                    (provider_id, admin_id, admin_name, decision_status, notes, created_at)
                 VALUES (:provider_id, :admin_id, :admin_name, :decision_status, :notes, NOW())"
            );
            $stmt->execute([
                ':provider_id' => $providerId,
                ':admin_id' => $adminId ?: null,
                ':admin_name' => $adminName !== '' ? $adminName : null,
                ':decision_status' => $decisionStatus,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
        } catch (Exception $e) {}
    }
}
