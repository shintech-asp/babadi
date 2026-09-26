<?php
// admin/verify-providers.php
$allowed_roles = ['super_admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/provider_verification_helper.php';
$database = new Database();
$db = $database->getConnection();
ensureProviderVerificationWorkflow($db);
syncProviderVerificationStatus($db);

$success_message = '';
$error_message = '';
$superAdminId = (int)($_SESSION['admin_id'] ?? 0);
$superAdminName = trim((string)($_SESSION['admin_full_name'] ?? ($_SESSION['admin_username'] ?? 'Super Admin')));

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $provider_id = (int)($_POST['provider_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($provider_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $error_message = "Invalid review request.";
    } elseif ($action === 'reject' && $notes === '') {
        $error_message = "Rejection notes are required so the provider knows what to correct.";
    } else {
        try {
            $providerStmt = $db->prepare(
                "SELECT p.id, p.user_id, p.company_name, p.status, p.verification_status
                 FROM providers p
                 WHERE p.id = :id
                 LIMIT 1"
            );
            $providerStmt->execute([':id' => $provider_id]);
            $providerRow = $providerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$providerRow) {
                $error_message = "Provider not found.";
            } else {
                // providers.status is ENUM('pending','active','inactive','suspended') — it has no
                // 'rejected' value, so writing one here silently failed the whole UPDATE under
                // strict SQL mode (same bug already fixed in api/v1/admin/providers/reject.php;
                // this web page's own copy of the same logic was missed by that fix). A rejection
                // is fully represented by verification_status alone — status is left untouched.
                $status = $action === 'approve' ? 'active' : $providerRow['status'];
                $verificationStatus = $action === 'approve' ? 'approved' : 'rejected';
                $message = $action === 'approve'
                    ? "Provider verified and approved successfully."
                    : "Provider verification rejected.";

                $updateStmt = $db->prepare(
                    "UPDATE providers SET
                        status = :status,
                        verification_status = :verification_status,
                        verification_notes = :verification_notes,
                        verification_date = NOW(),
                        verification_reviewed_by = :reviewed_by,
                        verification_reviewed_by_name = :reviewed_by_name,
                        updated_at = NOW()
                     WHERE id = :id"
                );

                $updateStmt->execute([
                    ':status' => $status,
                    ':verification_status' => $verificationStatus,
                    ':verification_notes' => $notes !== '' ? $notes : null,
                    ':reviewed_by' => $superAdminId ?: null,
                    ':reviewed_by_name' => $superAdminName !== '' ? $superAdminName : null,
                    ':id' => $provider_id,
                ]);

                if ($updateStmt->rowCount() > 0) {
                    $success_message = $message;

                    logProviderVerificationDecision(
                        $db,
                        $provider_id,
                        $superAdminId,
                        $superAdminName,
                        $verificationStatus,
                        $notes
                    );

                    try {
                        $logStmt = $db->prepare(
                            "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at)
                             VALUES (:admin_id, :action, :details, :ip, NOW())"
                        );
                        $logStmt->execute([
                            ':admin_id' => $superAdminId,
                            ':action' => strtoupper($action) . '_PROVIDER',
                            ':details' => 'Provider ID ' . $provider_id . ' verification changed to ' . $verificationStatus . ($notes !== '' ? '. Notes: ' . $notes : ''),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                        ]);
                    } catch (Exception $e) {}

                    if (!empty($providerRow['user_id'])) {
                        try {
                            $notifTitle = $action === 'approve' ? 'Business Verification Approved' : 'Business Verification Needs Attention';
                            $notifMessage = $action === 'approve'
                                ? 'Your business "' . ($providerRow['company_name'] ?? 'Provider') . '" has been approved by the Super Admin. You can now accept live bookings.'
                                : 'Your business "' . ($providerRow['company_name'] ?? 'Provider') . '" was not approved yet. Review the Super Admin notes in Provider Setup and submit updated documents.';
                            if ($notes !== '') {
                                $notifMessage .= ' Notes: ' . $notes;
                            }

                            $notifStmt = $db->prepare(
                                "INSERT INTO notifications
                                    (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
                                 VALUES (:user_id, 'system', :title, :message, :related_id, 'provider_verification', :action_url, 0, NOW())"
                            );
                            $notifStmt->execute([
                                ':user_id' => (int)$providerRow['user_id'],
                                ':title' => $notifTitle,
                                ':message' => $notifMessage,
                                ':related_id' => $provider_id,
                                ':action_url' => '/pestify/provider/provider-setup.php',
                            ]);
                        } catch (Exception $e) {}
                    }
                } else {
                    $error_message = "No verification changes were saved.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}
$search = trim((string)($_GET['search'] ?? ''));

$verificationExpr = "COALESCE(NULLIF(p.verification_status, ''), CASE
    WHEN p.status = 'active' THEN 'approved'
    WHEN p.status = 'rejected' THEN 'rejected'
    ELSE 'pending'
END)";

$query = "SELECT p.*, u.first_name, u.last_name, u.email, u.phone, u.created_at as user_created_at,
                 TIMESTAMPDIFF(HOUR, COALESCE(p.verification_submitted_at, p.created_at), NOW()) AS queue_age_hours
          FROM providers p
          JOIN users u ON p.user_id = u.id
          WHERE 1 = 1";
$params = [];

if ($filter !== 'all') {
    $query .= " AND {$verificationExpr} = :filter";
    $params[':filter'] = $filter;
}
if ($search !== '') {
    $query .= " AND (
        p.company_name LIKE :search
        OR u.email LIKE :search
        OR u.first_name LIKE :search
        OR u.last_name LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

$query .= " ORDER BY
    CASE WHEN {$verificationExpr} = 'pending' THEN 0 ELSE 1 END,
    COALESCE(p.verification_submitted_at, p.created_at) DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_count = (int)$db->query("SELECT COUNT(*) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'pending'")->fetchColumn();
$approved_count = (int)$db->query("SELECT COUNT(*) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'approved'")->fetchColumn();
$rejected_count = (int)$db->query("SELECT COUNT(*) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'rejected'")->fetchColumn();
$reviewed_today = (int)$db->query("SELECT COUNT(*) FROM providers WHERE verification_date IS NOT NULL AND DATE(verification_date) = CURDATE()")->fetchColumn();
$avg_pending_hours = (float)$db->query("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, COALESCE(verification_submitted_at, created_at), NOW())), 0) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'pending'")->fetchColumn();

$verificationAudit = [];
if (!empty($providers)) {
    $providerIds = array_map(static fn($row) => (int)$row['id'], $providers);
    $auditSql = "SELECT a.*
                 FROM provider_verification_audit a
                 WHERE a.provider_id IN (" . implode(',', array_fill(0, count($providerIds), '?')) . ")
                 ORDER BY a.created_at DESC, a.id DESC";
    $auditStmt = $db->prepare($auditSql);
    $auditStmt->execute($providerIds);
    while ($auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($auditRow['provider_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $verificationAudit[$pid] ??= [];
        if (count($verificationAudit[$pid]) < 3) {
            $verificationAudit[$pid][] = $auditRow;
        }
    }
}

function buildVerificationDocumentMeta(string $path): array
{
    $cleanPath = (string)(parse_url($path, PHP_URL_PATH) ?? $path);
    $extension = strtolower((string)pathinfo($cleanPath, PATHINFO_EXTENSION));
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $isImage = in_array($extension, $imageExtensions, true);

    $icon = match ($extension) {
        'pdf' => 'fa-file-pdf',
        'doc', 'docx' => 'fa-file-word',
        'xls', 'xlsx', 'csv' => 'fa-file-excel',
        default => $isImage ? 'fa-image' : 'fa-file-lines',
    };

    $label = match ($extension) {
        'pdf' => 'PDF Document',
        'doc', 'docx' => 'Word Document',
        'xls', 'xlsx', 'csv' => 'Spreadsheet',
        default => $isImage ? 'Image Proof' : 'Supporting File',
    };

    return [
        'extension' => $extension !== '' ? strtoupper($extension) : 'FILE',
        'is_image' => $isImage,
        'icon' => $icon,
        'type_label' => $label,
    ];
}

function buildQueuePriorityMeta(int $queueHours): array
{
    if ($queueHours >= 72) {
        return [
            'label' => 'Critical queue',
            'class' => 'priority-critical',
            'icon' => 'fa-triangle-exclamation',
        ];
    }

    if ($queueHours >= 24) {
        return [
            'label' => 'Needs review',
            'class' => 'priority-warning',
            'icon' => 'fa-bolt',
        ];
    }

    return [
        'label' => 'On track',
        'class' => 'priority-normal',
        'icon' => 'fa-wave-square',
    ];
}

$urgent_pending_count = (int)$db->query("SELECT COUNT(*) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'pending' AND TIMESTAMPDIFF(HOUR, COALESCE(verification_submitted_at, created_at), NOW()) >= 72")->fetchColumn();
$oldest_pending_hours = (int)$db->query("SELECT COALESCE(MAX(TIMESTAMPDIFF(HOUR, COALESCE(verification_submitted_at, created_at), NOW())), 0) FROM providers WHERE COALESCE(NULLIF(verification_status, ''), CASE WHEN status = 'active' THEN 'approved' WHEN status = 'rejected' THEN 'rejected' ELSE 'pending' END) = 'pending'")->fetchColumn();
$currentFilterLabel = match ($filter) {
    'approved' => 'Approved businesses',
    'rejected' => 'Rejected businesses',
    'all' => 'Full audit view',
    default => 'Pending review queue',
};

$active_menu = 'verify_providers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Verification - Pestify Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --secondary: #38A169;
            --neutral-dark: #10213f;
            --neutral-gray: #64748b;
            --neutral-light: #dbe5f0;
            --neutral-soft: #f4f8fb;
            --neutral-panel: #FCFDFD;
            --danger: #E53E3E;
            --success: #38A169;
            --warning: #D69E2E;
            --info: #3182CE;
            --radius: 10px;
            --radius-md: 14px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.08);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
            --transition: 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background:
                radial-gradient(circle at 0% 0%, rgba(46,139,87,.10), transparent 28%),
                radial-gradient(circle at 100% 0%, rgba(37,99,235,.10), transparent 24%),
                linear-gradient(180deg, #f8fbff 0%, #f2f7fb 100%);
            color: var(--neutral-dark);
        }

        .super-admin-shell {
            display: flex;
            min-height: 100vh;
        }

        .super-admin-sidebar {
            width: 268px;
            background: linear-gradient(180deg, #162443 0%, #1c2e58 100%);
            color: #fff;
            position: fixed;
            inset: 0 auto 0 0;
            overflow-y: auto;
            z-index: 100;
            display: flex;
            flex-direction: column;
            box-shadow: 6px 0 28px rgba(15, 23, 42, 0.18);
        }

        .super-admin-brand {
            padding: 26px 22px 18px;
            border-bottom: 1px solid rgba(255,255,255,.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .super-admin-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #30a46c, #2E8B57);
            display: grid;
            place-items: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .super-admin-brand h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
        }

        .super-admin-brand p {
            margin: 4px 0 0;
            font-size: 12px;
            color: rgba(255,255,255,.66);
        }

        .super-admin-nav {
            flex: 1;
            padding: 18px 0;
            overflow-y: auto;
        }

        .super-admin-section {
            padding: 8px 18px 4px;
            font-size: 11px;
            font-weight: 700;
            color: rgba(255,255,255,.45);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-top: 4px;
        }

        .super-admin-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 20px;
            color: rgba(255,255,255,.84);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all .2s;
            border-left: 3px solid transparent;
        }

        .super-admin-link:hover {
            background: rgba(255,255,255,.1);
            color: #fff;
        }

        .super-admin-link.active {
            background: rgba(255,255,255,.15);
            color: #fff;
            border-left-color: #7dd3fc;
        }

        .super-admin-link i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .super-admin-pill {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 700;
            background: rgba(59,130,246,.25);
            color: #dbeafe;
        }

        .super-admin-badge {
            margin-left: auto;
            background: rgba(220,38,38,.24);
            color: #fecaca;
            font-size: 11px;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 700;
        }

        .super-admin-footer {
            padding: 18px;
            border-top: 1px solid rgba(255,255,255,.1);
        }

        .super-admin-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: rgba(255,255,255,.08);
            border-radius: 14px;
        }

        .super-admin-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #34d399, #0ea5e9);
            display: grid;
            place-items: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .super-admin-user h4 {
            margin: 0;
            font-size: 13px;
            color: #fff;
            font-weight: 600;
        }

        .super-admin-user p {
            margin: 2px 0 0;
            font-size: 11px;
            color: rgba(255,255,255,.68);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .super-admin-main {
            flex: 1;
            margin-left: 268px;
            padding: 28px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .page-header h1 {
            color: var(--neutral-dark);
            margin-bottom: 8px;
            font-size: 38px;
        }
        
        .page-header p {
            color: var(--neutral-gray);
            font-size: 16px;
            line-height: 1.6;
            max-width: 760px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .page-hero {
            background: linear-gradient(135deg, rgba(46, 139, 87, 0.12), rgba(49, 130, 206, 0.08));
            border: 1px solid rgba(46, 139, 87, 0.18);
            border-radius: 20px;
            padding: 1.5rem 1.6rem;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .page-hero-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(160px, 1fr));
            gap: 0.9rem;
            min-width: min(100%, 360px);
        }

        .hero-stat {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(46, 139, 87, 0.16);
            border-radius: 16px;
            padding: 0.95rem 1rem;
            box-shadow: var(--shadow);
        }

        .hero-stat .label {
            display: block;
            margin-bottom: 0.2rem;
            font-size: 0.76rem;
            color: var(--neutral-gray);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .hero-stat .value {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--neutral-dark);
        }

        .hero-stat .meta {
            margin-top: 0.2rem;
            font-size: 0.82rem;
            color: var(--neutral-gray);
        }

        .page-hero-copy {
            display: grid;
            gap: 0.45rem;
        }

        .page-hero-copy .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.38rem 0.75rem;
            width: fit-content;
            border-radius: var(--radius-full);
            background: rgba(46, 139, 87, 0.12);
            color: var(--primary-dark);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .page-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            color: var(--neutral-gray);
            font-size: 0.9rem;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 0.8rem;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--neutral-light);
            color: var(--neutral-dark);
            font-weight: 600;
        }

        .review-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .summary-card {
            background: white;
            border: 1px solid var(--neutral-light);
            border-radius: 16px;
            padding: 1.1rem 1.2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .summary-card::after {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--primary);
        }

        .summary-card.summary-urgent::after {
            background: var(--danger);
        }

        .summary-card.summary-approved::after {
            background: var(--success);
        }

        .summary-card.summary-review::after {
            background: var(--info);
        }

        .summary-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--neutral-gray);
            margin-bottom: 0.4rem;
            display: block;
        }

        .summary-card .value {
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--neutral-dark);
        }

        .summary-card .meta {
            margin-top: 0.35rem;
            font-size: 0.85rem;
            color: var(--neutral-gray);
        }

        .review-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            background: rgba(255,255,255,.82);
            border: 1px solid var(--neutral-light);
            border-radius: 18px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
        }

        .review-search {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .review-toolbar-label {
            display: grid;
            gap: 0.2rem;
        }

        .review-toolbar-label strong {
            color: var(--neutral-dark);
            font-size: 1rem;
        }

        .review-toolbar-label span {
            color: var(--neutral-gray);
            font-size: 0.88rem;
        }

        .review-search input {
            min-width: 280px;
            padding: 0.8rem 0.95rem;
            border: 2px solid var(--neutral-light);
            border-radius: 12px;
            font-size: 0.95rem;
            background: white;
        }

        .review-search input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn-secondary {
            background: white;
            border: 1px solid var(--neutral-light);
            color: var(--neutral-dark);
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--neutral-light);
            padding-bottom: 0.35rem;
            flex-wrap: wrap;
            background: rgba(255,255,255,.82);
            border: 1px solid var(--neutral-light);
            border-radius: 18px;
            padding: 0.7rem;
            box-shadow: var(--shadow);
        }
        
        .filter-tab {
            padding: 0.8rem 1rem;
            background: transparent;
            border: 1px solid transparent;
            border-radius: 14px;
            color: var(--neutral-gray);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            position: relative;
            transition: all var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 150px;
            justify-content: center;
            white-space: nowrap;
        }
        
        .filter-tab:hover {
            color: var(--primary);
            background: rgba(46, 139, 87, 0.06);
        }
        
        .filter-tab.active {
            color: #fff;
            background: linear-gradient(135deg, #2E8B57, #1f9d55);
            border-color: rgba(46, 139, 87, 0.3);
            box-shadow: 0 10px 24px rgba(46,139,87,.18);
        }
        
        .filter-count {
            display: inline-block;
            background: var(--neutral-light);
            color: var(--neutral-dark);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .filter-tab.active .filter-count {
            background: rgba(255,255,255,.22);
            color: white;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: #F0FFF4;
            border-left: 4px solid var(--success);
            color: #22543D;
        }
        
        .alert-error {
            background: #FFF5F5;
            border-left: 4px solid var(--danger);
            color: #742A2A;
        }
        
        /* Provider Cards */
        .providers-grid {
            display: grid;
            gap: 1.5rem;
        }

        .provider-card {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            transition: all var(--transition);
            border: 1px solid rgba(226, 232, 240, 0.95);
            overflow: hidden;
        }

        .provider-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .provider-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
            gap: 1rem;
        }

        .provider-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.32rem 0.72rem;
            border-radius: 999px;
            background: rgba(46, 139, 87, 0.08);
            color: var(--primary-dark);
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.65rem;
        }

        .provider-info h3 {
            color: var(--neutral-dark);
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }
        
        .provider-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--neutral-gray);
        }
        
        .provider-meta i {
            color: var(--primary);
        }

        .provider-inline-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1rem;
            margin-top: 0.9rem;
            font-size: 0.86rem;
            color: var(--neutral-gray);
        }

        .provider-inline-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .status-stack {
            display: grid;
            gap: 0.6rem;
            justify-items: end;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }
        
        .status-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .status-approved {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-rejected {
            background: #FEE2E2;
            color: #991B1B;
        }

        .priority-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.8rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .priority-normal {
            background: #E0F2FE;
            color: #075985;
        }

        .priority-warning {
            background: #FEF3C7;
            color: #92400E;
        }

        .priority-critical {
            background: #FEE2E2;
            color: #991B1B;
        }

        .provider-details {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            background: linear-gradient(180deg, rgba(247, 250, 252, 0.55), rgba(255, 255, 255, 0));
        }
        
        .detail-group h4 {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--neutral-gray);
            margin-bottom: 0.5rem;
            letter-spacing: 0.05em;
        }
        
        .detail-group p {
            color: var(--neutral-dark);
            font-size: 0.9375rem;
        }

        .review-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1.55fr) minmax(300px, 0.95fr);
            gap: 1.25rem;
            padding: 0 1.5rem 1.5rem;
        }

        .workspace-panel {
            border: 1px solid var(--neutral-light);
            border-radius: 18px;
            background: var(--neutral-panel);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .workspace-panel-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.15rem 1.2rem 0;
        }

        .workspace-panel-head h4 {
            margin: 0;
            color: var(--neutral-dark);
            font-size: 1rem;
        }

        .workspace-panel-head p {
            margin: 0.28rem 0 0;
            color: var(--neutral-gray);
            font-size: 0.88rem;
        }

        .workspace-panel-body {
            padding: 1.15rem 1.2rem 1.2rem;
        }

        .workspace-section + .workspace-section {
            margin-top: 1.15rem;
            padding-top: 1.15rem;
            border-top: 1px solid var(--neutral-light);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-bottom: 0.8rem;
            color: var(--neutral-dark);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .compact-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.42rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .compact-pill.success {
            background: #DCFCE7;
            color: #166534;
            border-color: #BBF7D0;
        }

        .compact-pill.warning {
            background: #FEF3C7;
            color: #92400E;
            border-color: #FDE68A;
        }

        .review-checklist {
            display: grid;
            gap: 0.65rem;
        }

        .check-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.8rem 0.9rem;
            border-radius: 12px;
            border: 1px solid var(--neutral-light);
            background: white;
        }

        .check-item strong {
            display: block;
            color: var(--neutral-dark);
            font-size: 0.9rem;
        }

        .check-item span {
            display: block;
            color: var(--neutral-gray);
            font-size: 0.8rem;
            margin-top: 0.15rem;
        }

        .check-item.ok {
            border-color: #BBF7D0;
            background: #F0FDF4;
        }

        .check-item.missing {
            border-color: #FECACA;
            background: #FEF2F2;
        }

        .check-item i {
            font-size: 1rem;
        }

        .check-item.ok i {
            color: var(--success);
        }

        .check-item.missing i {
            color: var(--danger);
        }

        .doc-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 1rem;
        }

        .doc-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.8rem;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
        }

        .doc-chip:hover {
            background: #dbeafe;
        }

        .doc-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.9rem;
        }

        .doc-preview {
            display: flex;
            flex-direction: column;
            min-height: 190px;
            border: 1px solid var(--neutral-light);
            border-radius: 16px;
            overflow: hidden;
            background: white;
            text-decoration: none;
            color: inherit;
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
        }

        .doc-preview:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: rgba(46, 139, 87, 0.24);
        }

        .doc-preview-media {
            min-height: 120px;
            background: linear-gradient(135deg, rgba(46, 139, 87, 0.08), rgba(49, 130, 206, 0.12));
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .doc-preview-media img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            display: block;
        }

        .doc-preview-file {
            display: grid;
            gap: 0.35rem;
            justify-items: center;
            text-align: center;
            padding: 1rem;
            color: var(--neutral-dark);
        }

        .doc-preview-file i {
            font-size: 2rem;
            color: var(--info);
        }

        .doc-extension {
            position: absolute;
            top: 0.7rem;
            right: 0.7rem;
            padding: 0.28rem 0.55rem;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.84);
            color: white;
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.06em;
        }

        .doc-preview-copy {
            padding: 0.95rem 1rem 1rem;
        }

        .doc-preview-copy strong {
            display: block;
            color: var(--neutral-dark);
            font-size: 0.9rem;
            margin-bottom: 0.18rem;
        }

        .doc-preview-copy span {
            color: var(--neutral-gray);
            font-size: 0.8rem;
        }

        .audit-trail {
            margin-top: 1rem;
            display: grid;
            gap: 0.75rem;
        }

        .audit-item {
            border: 1px solid var(--neutral-light);
            border-radius: 12px;
            padding: 0.85rem 0.95rem;
            background: #fff;
        }

        .audit-item-head {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 0.35rem;
            font-size: 0.82rem;
            color: var(--neutral-gray);
        }

        .audit-item strong {
            color: var(--neutral-dark);
        }

        .audit-item p {
            margin: 0;
            font-size: 0.9rem;
            color: var(--neutral-dark);
            line-height: 1.5;
        }
        
        .verification-actions {
            background: white;
            padding: 1.1rem;
            border-radius: 16px;
            border: 1px solid var(--neutral-light);
        }

        .verification-actions h4 {
            color: var(--neutral-dark);
            margin-bottom: 1rem;
        }

        .status-note {
            padding: 1rem;
            border-radius: 14px;
            border: 1px solid var(--neutral-light);
            background: white;
        }

        .status-note h4 {
            margin-bottom: 0.5rem;
            color: var(--neutral-dark);
            font-size: 0.96rem;
        }

        .status-note p {
            margin: 0;
            color: var(--neutral-gray);
            line-height: 1.55;
            font-size: 0.9rem;
        }

        .status-note p + p {
            margin-top: 0.6rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--neutral-dark);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            font-family: inherit;
            resize: vertical;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9375rem;
        }
        
        .btn-approve {
            background: var(--success);
            color: white;
            flex: 1;
        }
        
        .btn-approve:hover {
            background: #2F855A;
            transform: translateY(-2px);
        }
        
        .btn-reject {
            background: var(--danger);
            color: white;
            flex: 1;
        }
        
        .btn-reject:hover {
            background: #C53030;
            transform: translateY(-2px);
        }
        
        .no-providers {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--neutral-gray);
        }
        
        .no-providers i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--neutral-light);
        }
        
        /* Lightbox */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .lightbox.active {
            display: flex;
        }
        
        .lightbox img {
            max-width: 90%;
            max-height: 90%;
            border-radius: var(--radius);
        }
        
        .lightbox-close {
            position: absolute;
            top: 2rem;
            right: 2rem;
            font-size: 2.5rem;
            color: white;
            cursor: pointer;
            transition: transform var(--transition);
        }
        
        .lightbox-close:hover {
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .super-admin-sidebar {
                display: none;
            }

            .super-admin-main {
                margin-left: 0;
            }

            .page-hero-stats {
                width: 100%;
                grid-template-columns: 1fr;
            }

            .filter-tabs {
                overflow-x: auto;
            }
            
            .provider-details {
                grid-template-columns: 1fr;
            }

            .page-hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .review-workspace {
                grid-template-columns: 1fr;
                padding-inline: 1rem;
                padding-bottom: 1rem;
            }

            .provider-header {
                flex-direction: column;
            }

            .status-stack {
                justify-items: start;
            }
        }
    </style>
</head>
<body>
    <div class="super-admin-shell">
    <aside class="super-admin-sidebar">
        <div class="super-admin-brand">
            <div class="super-admin-brand-icon"><i class="fas fa-bug"></i></div>
            <div>
                <h2>Pestify</h2>
                <p>Super Admin Portal</p>
            </div>
        </div>

        <nav class="super-admin-nav">
            <div class="super-admin-section">Workspace</div>
            <a href="super-admin-dashboard.php" class="super-admin-link">
                <i class="fas fa-house"></i> Dashboard
            </a>
            <div class="super-admin-section">Verification</div>
            <a href="verify-providers.php?filter=pending" class="super-admin-link <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                <i class="fas fa-hourglass-half"></i> Review Queue
                <?php if ($pending_count > 0): ?>
                    <span class="super-admin-badge"><?php echo (int)$pending_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="verify-providers.php?filter=approved" class="super-admin-link <?php echo $filter === 'approved' ? 'active' : ''; ?>">
                <i class="fas fa-circle-check"></i> Approved Businesses
                <span class="super-admin-pill"><?php echo (int)$approved_count; ?></span>
            </a>
            <a href="verify-providers.php?filter=rejected" class="super-admin-link <?php echo $filter === 'rejected' ? 'active' : ''; ?>">
                <i class="fas fa-circle-xmark"></i> Rejected Businesses
                <span class="super-admin-pill"><?php echo (int)$rejected_count; ?></span>
            </a>
            <a href="verify-providers.php?filter=all" class="super-admin-link <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Full Audit View
                <span class="super-admin-pill"><?php echo (int)($pending_count + $approved_count + $rejected_count); ?></span>
            </a>

            <div class="super-admin-section">Account</div>
            <a href="<?php echo appUrl('logout.php'); ?>" class="super-admin-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>

        <div class="super-admin-footer">
            <div class="super-admin-user">
                <div class="super-admin-avatar"><?php echo strtoupper(substr($superAdminName !== '' ? $superAdminName : 'S', 0, 1)); ?></div>
                <div>
                    <h4><?php echo htmlspecialchars($superAdminName); ?></h4>
                    <p>Super Admin</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="super-admin-main">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-user-shield"></i> Super Admin Verification</h1>
                <p>Review provider legitimacy, approve live-booking access, and keep a decision trail without dropping back into the generic admin screens.</p>
            </div>
            <div class="header-actions">
                <a href="super-admin-dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <a href="<?php echo appUrl('admin/change-password.php'); ?>" class="btn btn-secondary"><i class="fas fa-key"></i> Security</a>
            </div>
        </div>

        <section class="page-hero">
            <div class="page-hero-copy">
                <span class="eyebrow"><i class="fas fa-shield-halved"></i> Super Admin Control</span>
                <h2 style="font-size:1.5rem;color:var(--neutral-dark);margin:0;">Business legitimacy review workspace</h2>
                <p style="color:var(--neutral-gray);max-width:680px;line-height:1.6;">
                    Review business identity, supporting documents, and queue health from one place. This view stays role-specific, but now follows the same card and decision patterns used across the rest of Pestify.
                </p>
                <div class="page-hero-meta">
                    <span class="hero-chip"><i class="fas fa-user"></i> <?php echo htmlspecialchars($superAdminName); ?></span>
                    <span class="hero-chip"><i class="fas fa-clipboard-check"></i> <?php echo $reviewed_today; ?> reviewed today</span>
                    <span class="hero-chip"><i class="fas fa-hourglass-half"></i> Avg pending: <?php echo number_format($avg_pending_hours, 1); ?>h</span>
                    <span class="hero-chip"><i class="fas fa-filter"></i> <?php echo htmlspecialchars($currentFilterLabel); ?></span>
                </div>
            </div>
            <div class="page-hero-stats">
                <div class="hero-stat">
                    <span class="label">Urgent queue</span>
                    <div class="value"><?php echo (int)$urgent_pending_count; ?></div>
                    <div class="meta">Pending reviews older than 72 hours</div>
                </div>
                <div class="hero-stat">
                    <span class="label">Oldest pending</span>
                    <div class="value"><?php echo (int)$oldest_pending_hours; ?>h</div>
                    <div class="meta">Longest waiting provider in the queue</div>
                </div>
            </div>
        </section>
        
        <?php if($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success_message; ?></div>
            </div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error_message; ?></div>
            </div>
        <?php endif; ?>
        
        <div class="review-summary">
            <div class="summary-card">
                <span class="label">Pending Queue</span>
                <div class="value"><?php echo (int)$pending_count; ?></div>
                <div class="meta">Providers waiting for Super Admin review</div>
            </div>
            <div class="summary-card summary-approved">
                <span class="label">Approved</span>
                <div class="value"><?php echo (int)$approved_count; ?></div>
                <div class="meta">Businesses cleared for live bookings</div>
            </div>
            <div class="summary-card summary-urgent">
                <span class="label">Rejected</span>
                <div class="value"><?php echo (int)$rejected_count; ?></div>
                <div class="meta">Profiles that still need corrections</div>
            </div>
            <div class="summary-card summary-review">
                <span class="label">Reviewed Today</span>
                <div class="value"><?php echo (int)$reviewed_today; ?></div>
                <div class="meta">Average pending age: <?php echo number_format($avg_pending_hours / 24, 1); ?> day(s)</div>
            </div>
        </div>

        <div class="review-toolbar">
            <div class="review-toolbar-label">
                <strong><?php echo htmlspecialchars($superAdminName); ?></strong>
                <span>Verification controls and live queue filters</span>
            </div>
            <form method="GET" class="review-search">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search company, owner, or email">
                <?php if ($search !== ''): ?>
                    <a href="?filter=<?php echo urlencode($filter); ?>" class="btn btn-secondary"><i class="fas fa-xmark"></i> Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-approve"><i class="fas fa-search"></i> Search</button>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=pending" class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Pending
                <span class="filter-count"><?php echo $pending_count; ?></span>
            </a>
            <a href="?filter=approved" class="filter-tab <?php echo $filter === 'approved' ? 'active' : ''; ?>">
                <i class="fas fa-check"></i> Approved
                <span class="filter-count"><?php echo $approved_count; ?></span>
            </a>
            <a href="?filter=rejected" class="filter-tab <?php echo $filter === 'rejected' ? 'active' : ''; ?>">
                <i class="fas fa-times"></i> Rejected
                <span class="filter-count"><?php echo $rejected_count; ?></span>
            </a>
            <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> All
                <span class="filter-count"><?php echo $pending_count + $approved_count + $rejected_count; ?></span>
            </a>
        </div>
        
        <!-- Provider Cards -->
        <div class="providers-grid">
            <?php if(count($providers) === 0): ?>
                <div class="no-providers">
                    <i class="fas fa-inbox"></i>
                    <h3>No providers found</h3>
                    <p>There are no matching providers for this Super Admin review view.</p>
                </div>
            <?php else: ?>
                <?php foreach($providers as $provider): ?>
                    <?php
                    $providerVerificationState = trim((string)($provider['verification_status'] ?? ''));
                    if ($providerVerificationState === '') {
                        $providerVerificationState = normalizeProviderVerificationState((string)($provider['status'] ?? 'pending'));
                    }
                    $providerStatusBadgeClass = match ($providerVerificationState) {
                        'approved' => 'status-approved',
                        'rejected' => 'status-rejected',
                        default => 'status-pending',
                    };
                    $providerStatusLabel = match ($providerVerificationState) {
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => 'Pending Review',
                    };
                    $queueHours = max(0, (int)($provider['queue_age_hours'] ?? 0));
                    $queuePriority = buildQueuePriorityMeta($queueHours);
                    $docLinks = [];
                    if (!empty($provider['business_registration_file'])) {
                        $docLinks[] = ['label' => 'Business Registration', 'path' => $provider['business_registration_file'], 'required' => true];
                    }
                    if (!empty($provider['license_file'])) {
                        $docLinks[] = ['label' => 'License Document', 'path' => $provider['license_file'], 'required' => true];
                    }
                    if (!empty($provider['verification_images'])) {
                        foreach (explode(',', (string)$provider['verification_images']) as $legacyImage) {
                            $legacyImage = trim($legacyImage);
                            if ($legacyImage !== '') {
                                $docLinks[] = ['label' => 'Legacy Document', 'path' => $legacyImage, 'required' => false];
                            }
                        }
                    }
                    foreach ($docLinks as $docIndex => $docLink) {
                        $docLinks[$docIndex] += buildVerificationDocumentMeta((string)$docLink['path']);
                    }
                    $missingDocs = [];
                    if (empty($provider['business_registration_file'])) {
                        $missingDocs[] = 'Business Registration';
                    }
                    if (empty($provider['license_file'])) {
                        $missingDocs[] = 'License Document';
                    }
                    $requiredDocsComplete = count($missingDocs) === 0;
                    $submittedAt = !empty($provider['verification_submitted_at']) ? strtotime((string)$provider['verification_submitted_at']) : null;
                    $providerAuditRows = $verificationAudit[(int)$provider['id']] ?? [];
                    ?>
                    <div class="provider-card">
                        <div class="provider-header">
                            <div class="provider-info">
                                <div class="provider-kicker"><i class="fas fa-fingerprint"></i> Verification Review</div>
                                <h3><?php echo htmlspecialchars($provider['company_name']); ?></h3>
                                <div class="provider-meta">
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($provider['first_name'] . ' ' . $provider['last_name']); ?></span>
                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($provider['email']); ?></span>
                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($provider['phone']); ?></span>
                                </div>
                                <div class="provider-inline-meta">
                                    <span><i class="fas fa-calendar-plus"></i> Registered <?php echo date('M d, Y', strtotime($provider['created_at'])); ?></span>
                                    <?php if ($submittedAt): ?>
                                        <span><i class="fas fa-paper-plane"></i> Submitted <?php echo date('M d, Y g:i A', $submittedAt); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-hourglass-half"></i> <?php echo $queueHours; ?> hour(s) in queue</span>
                                </div>
                            </div>
                            <div class="status-stack">
                                <span class="status-badge <?php echo $providerStatusBadgeClass; ?>">
                                    <i class="fas fa-<?php echo $providerVerificationState === 'approved' ? 'check' : ($providerVerificationState === 'rejected' ? 'times' : 'clock'); ?>"></i>
                                    <?php echo $providerStatusLabel; ?>
                                </span>
                                <span class="priority-pill <?php echo htmlspecialchars($queuePriority['class']); ?>">
                                    <i class="fas <?php echo htmlspecialchars($queuePriority['icon']); ?>"></i>
                                    <?php echo htmlspecialchars($queuePriority['label']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="provider-details">
                            <div class="detail-group">
                                <h4><i class="fas fa-map-marker-alt"></i> Address</h4>
                                <p><?php echo htmlspecialchars((string)($provider['address'] ?: 'No address provided')); ?></p>
                            </div>
                            
                            <div class="detail-group">
                                <h4><i class="fas fa-city"></i> City</h4>
                                <p><?php echo htmlspecialchars((string)($provider['city'] ?: 'Not set')); ?></p>
                            </div>
                            
                            <div class="detail-group">
                                <h4><i class="fas fa-list-check"></i> Required Documents</h4>
                                <p><?php echo $requiredDocsComplete ? 'All required files are present.' : 'Missing: ' . htmlspecialchars(implode(', ', $missingDocs)); ?></p>
                            </div>

                            <div class="detail-group">
                                <h4><i class="fas fa-file-shield"></i> Review State</h4>
                                <p><?php echo htmlspecialchars($providerStatusLabel); ?><?php echo $requiredDocsComplete ? ' with a complete document set.' : ' with missing required proofs.'; ?></p>
                            </div>
                            
                            <?php if($provider['business_registration_number']): ?>
                            <div class="detail-group">
                                <h4><i class="fas fa-file-alt"></i> Registration Number</h4>
                                <p><?php echo htmlspecialchars($provider['business_registration_number']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($provider['license_number']): ?>
                            <div class="detail-group">
                                <h4><i class="fas fa-certificate"></i> License Number</h4>
                                <p><?php echo htmlspecialchars($provider['license_number']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($provider['description']): ?>
                            <div class="detail-group" style="grid-column: 1 / -1;">
                                <h4><i class="fas fa-info-circle"></i> Company Description</h4>
                                <p><?php echo nl2br(htmlspecialchars($provider['description'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="review-workspace">
                            <section class="workspace-panel">
                                <div class="workspace-panel-head">
                                    <div>
                                        <h4>Compliance Snapshot</h4>
                                        <p>Validate the required files first, then review supporting uploads and the decision trail.</p>
                                    </div>
                                    <span class="compact-pill <?php echo $requiredDocsComplete ? 'success' : 'warning'; ?>">
                                        <i class="fas fa-<?php echo $requiredDocsComplete ? 'check' : 'triangle-exclamation'; ?>"></i>
                                        <?php echo $requiredDocsComplete ? 'Document set complete' : 'Action needed'; ?>
                                    </span>
                                </div>
                                <div class="workspace-panel-body">
                                    <div class="workspace-section" style="padding-top:0;border-top:none;">
                                        <div class="section-title"><i class="fas fa-list-check"></i> Review Checklist</div>
                                        <div class="review-checklist">
                                            <div class="check-item <?php echo !empty($provider['business_registration_file']) ? 'ok' : 'missing'; ?>">
                                                <div>
                                                    <strong>Business Registration</strong>
                                                    <span>Required proof of business legitimacy</span>
                                                </div>
                                                <i class="fas fa-<?php echo !empty($provider['business_registration_file']) ? 'circle-check' : 'circle-xmark'; ?>"></i>
                                            </div>
                                            <div class="check-item <?php echo !empty($provider['license_file']) ? 'ok' : 'missing'; ?>">
                                                <div>
                                                    <strong>License Document</strong>
                                                    <span>Required before live-booking approval</span>
                                                </div>
                                                <i class="fas fa-<?php echo !empty($provider['license_file']) ? 'circle-check' : 'circle-xmark'; ?>"></i>
                                            </div>
                                            <div class="check-item <?php echo !empty($provider['description']) ? 'ok' : 'missing'; ?>">
                                                <div>
                                                    <strong>Company Description</strong>
                                                    <span>Needed so clients can understand the provider profile</span>
                                                </div>
                                                <i class="fas fa-<?php echo !empty($provider['description']) ? 'circle-check' : 'circle-xmark'; ?>"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="workspace-section">
                                        <div class="section-title"><i class="fas fa-folder-open"></i> Verification Documents</div>
                                        <?php if(!empty($docLinks)): ?>
                                            <div class="doc-chip-row">
                                                <?php foreach ($docLinks as $docLink): ?>
                                                    <a class="doc-chip" href="<?php echo htmlspecialchars('../' . ltrim((string)$docLink['path'], '/')); ?>" target="_blank" rel="noopener noreferrer">
                                                        <i class="fas <?php echo htmlspecialchars((string)$docLink['icon']); ?>"></i> <?php echo htmlspecialchars((string)$docLink['label']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="doc-preview-grid">
                                                <?php foreach($docLinks as $docLink): ?>
                                                    <?php $docPath = '../' . ltrim((string)$docLink['path'], '/'); ?>
                                                    <a class="doc-preview" href="<?php echo htmlspecialchars($docPath); ?>" target="_blank" rel="noopener noreferrer">
                                                        <div class="doc-preview-media">
                                                            <span class="doc-extension"><?php echo htmlspecialchars((string)$docLink['extension']); ?></span>
                                                            <?php if (!empty($docLink['is_image'])): ?>
                                                                <img src="<?php echo htmlspecialchars($docPath); ?>" alt="<?php echo htmlspecialchars((string)$docLink['label']); ?>">
                                                            <?php else: ?>
                                                                <div class="doc-preview-file">
                                                                    <i class="fas <?php echo htmlspecialchars((string)$docLink['icon']); ?>"></i>
                                                                    <strong><?php echo htmlspecialchars((string)$docLink['type_label']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="doc-preview-copy">
                                                            <strong><?php echo htmlspecialchars((string)$docLink['label']); ?></strong>
                                                            <span><?php echo htmlspecialchars((string)$docLink['type_label']); ?><?php echo !empty($docLink['required']) ? ' | required' : ' | supporting'; ?></span>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-error" style="margin-bottom: 0;">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <div>No verification documents are attached yet.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($providerAuditRows)): ?>
                                        <div class="workspace-section">
                                            <div class="section-title"><i class="fas fa-timeline"></i> Review Audit Trail</div>
                                            <div class="audit-trail">
                                                <?php foreach ($providerAuditRows as $auditRow): ?>
                                                    <div class="audit-item">
                                                        <div class="audit-item-head">
                                                            <strong><?php echo htmlspecialchars(ucfirst((string)$auditRow['decision_status'])); ?></strong>
                                                            <span><?php echo !empty($auditRow['admin_name']) ? htmlspecialchars((string)$auditRow['admin_name']) . ' | ' : ''; ?><?php echo date('M d, Y g:i A', strtotime((string)$auditRow['created_at'])); ?></span>
                                                        </div>
                                                        <p><?php echo nl2br(htmlspecialchars((string)($auditRow['notes'] ?? 'No additional notes.'))); ?></p>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <aside class="workspace-panel">
                                <div class="workspace-panel-head">
                                    <div>
                                        <h4>Decision Workspace</h4>
                                        <p>Status is shown separately from the next action so the review flow matches the rest of Pestify.</p>
                                    </div>
                                    <span class="compact-pill <?php echo $providerVerificationState === 'approved' ? 'success' : 'warning'; ?>">
                                        <i class="fas fa-<?php echo $providerVerificationState === 'approved' ? 'circle-check' : ($providerVerificationState === 'rejected' ? 'circle-xmark' : 'clock'); ?>"></i>
                                        <?php echo htmlspecialchars($providerStatusLabel); ?>
                                    </span>
                                </div>
                                <div class="workspace-panel-body">
                                    <div class="workspace-section" style="padding-top:0;border-top:none;">
                                        <div class="status-note">
                                            <h4><i class="fas fa-clipboard-list"></i> Queue Summary</h4>
                                            <p>This provider has been in the review queue for <strong><?php echo $queueHours; ?> hour(s)</strong>.</p>
                                            <?php if ($submittedAt): ?>
                                                <p>Submitted on <?php echo date('F d, Y \a\t h:i A', $submittedAt); ?>.</p>
                                            <?php endif; ?>
                                            <p><?php echo $requiredDocsComplete ? 'The required business registration and license documents are present.' : 'Required verification documents are still incomplete and should be checked before approval.'; ?></p>
                                        </div>
                                    </div>

                                    <?php if($providerVerificationState === 'pending'): ?>
                                        <div class="workspace-section">
                                            <div class="verification-actions">
                                                <h4><i class="fas fa-gavel"></i> Verification Decision</h4>
                                                <form method="POST" action="">
                                                    <input type="hidden" name="provider_id" value="<?php echo $provider['id']; ?>">
                                                    
                                                    <div class="form-group">
                                                        <label for="notes_<?php echo $provider['id']; ?>">
                                                            <i class="fas fa-sticky-note"></i> Decision Notes
                                                        </label>
                                                        <textarea name="notes"
                                                                  id="notes_<?php echo $provider['id']; ?>"
                                                                  rows="4"
                                                                  class="form-control"
                                                                  placeholder="Explain the approval basis or list exactly what the provider must fix."></textarea>
                                                    </div>
                                                    
                                                    <div class="button-group">
                                                        <button type="submit" name="action" value="approve" class="btn btn-approve">
                                                            <i class="fas fa-check"></i> Approve Provider
                                                        </button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-reject">
                                                            <i class="fas fa-times"></i> Reject Provider
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php elseif(!empty($provider['verification_notes'])): ?>
                                        <div class="workspace-section">
                                            <div class="status-note">
                                                <h4><i class="fas fa-comment-alt"></i> Latest Review Notes</h4>
                                                <p><?php echo nl2br(htmlspecialchars($provider['verification_notes'])); ?></p>
                                                <p>
                                                    Decision made on <?php echo date('F d, Y \a\t h:i A', strtotime($provider['verification_date'])); ?>
                                                    <?php if (!empty($provider['verification_reviewed_by_name'])): ?>
                                                        by <?php echo htmlspecialchars($provider['verification_reviewed_by_name']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </aside>
                            <?php if (false): ?>
                            <?php if(!empty($docLinks)): ?>
                                <div class="verification-images">
                                    <h4><i class="fas fa-folder-open"></i> Verification Documents</h4>
                                    <div class="doc-chip-row">
                                        <?php foreach ($docLinks as $docLink): ?>
                                            <a class="doc-chip" href="<?php echo htmlspecialchars('../' . ltrim((string)$docLink['path'], '/')); ?>" target="_blank" rel="noopener noreferrer">
                                                <i class="fas fa-file-arrow-up"></i> <?php echo htmlspecialchars((string)$docLink['label']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="images-grid">
                                        <?php foreach($docLinks as $docLink): ?>
                                            <div class="image-container" onclick="openLightbox('<?php echo htmlspecialchars((string)$docLink['path']); ?>')">
                                                <img src="<?php echo htmlspecialchars('../' . ltrim((string)$docLink['path'], '/')); ?>" 
                                                     alt="Verification Document"
                                                     onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;background:#f0f0f0;color:#999;\'>Image not found</div>'">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-error" style="margin-bottom: 1rem;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>No verification documents are attached yet.</div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if($providerVerificationState === 'pending'): ?>
                                <div class="verification-actions">
                                    <h4><i class="fas fa-gavel"></i> Verification Decision</h4>
                                    <form method="POST" action="">
                                        <input type="hidden" name="provider_id" value="<?php echo $provider['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="notes_<?php echo $provider['id']; ?>">
                                                <i class="fas fa-sticky-note"></i> Decision Notes
                                            </label>
                                            <textarea name="notes" 
                                                      id="notes_<?php echo $provider['id']; ?>" 
                                                      rows="3" 
                                                      class="form-control" 
                                                      placeholder="Explain why you approved or what the provider needs to fix."></textarea>
                                        </div>
                                        
                                        <div class="button-group">
                                            <button type="submit" name="action" value="approve" class="btn btn-approve">
                                                <i class="fas fa-check"></i> Approve Provider
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn btn-reject">
                                                <i class="fas fa-times"></i> Reject Provider
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php if(!empty($provider['verification_notes'])): ?>
                                    <div class="detail-group">
                                        <h4><i class="fas fa-comment-alt"></i> Verification Notes</h4>
                                        <p><?php echo nl2br(htmlspecialchars($provider['verification_notes'])); ?></p>
                                        <p style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--neutral-gray);">
                                            <i class="fas fa-clock"></i> Decision made on 
                                            <?php echo date('F d, Y \a\t h:i A', strtotime($provider['verification_date'])); ?>
                                            <?php if (!empty($provider['verification_reviewed_by_name'])): ?>
                                                by <?php echo htmlspecialchars($provider['verification_reviewed_by_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($providerAuditRows)): ?>
                                <div class="audit-trail">
                                    <?php foreach ($providerAuditRows as $auditRow): ?>
                                        <div class="audit-item">
                                            <div class="audit-item-head">
                                                <strong><?php echo htmlspecialchars(ucfirst((string)$auditRow['decision_status'])); ?></strong>
                                                <span><?php echo !empty($auditRow['admin_name']) ? htmlspecialchars((string)$auditRow['admin_name']) . ' · ' : ''; ?><?php echo date('M d, Y g:i A', strtotime((string)$auditRow['created_at'])); ?></span>
                                            </div>
                                            <p><?php echo nl2br(htmlspecialchars((string)($auditRow['notes'] ?? 'No additional notes.'))); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    </div>
    
    <!-- Lightbox -->
    <div class="lightbox" id="lightbox" onclick="closeLightbox()">
        <span class="lightbox-close">&times;</span>
        <img id="lightbox-img" src="" alt="Full size image">
    </div>
    
    <script>
        function openLightbox(imageSrc) {
            const lightbox = document.getElementById('lightbox');
            const lightboxImg = document.getElementById('lightbox-img');
            lightboxImg.src = '../' + imageSrc;
            lightbox.classList.add('active');
        }
        
        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
        }
        
        // Prevent lightbox from closing when clicking on image
        document.getElementById('lightbox-img').addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
