<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/send_email.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_crm = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_crm) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

if (!$tier_is_paid) {
    echo _tierLockedPage('Customer Outreach', 'subscriptions.php');
    exit;
}

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$success = $error = '';
$sentCount = 0;
$failedCount = 0;

// -- Past customers: seekers with at least one COMPLETED booking with this provider --
$pastCustomers = safeAll($db,
    "SELECT COALESCE(a.seeker_user_id, a.user_id) AS seeker_user_id,
            u.first_name, u.last_name, u.email,
            COUNT(a.id) AS completed_count,
            MAX(a.updated_at) AS last_completed_at,
            GROUP_CONCAT(DISTINCT COALESCE(a.service_name, 'Service') ORDER BY a.service_name SEPARATOR ', ') AS services_had
     FROM availed_services a
     JOIN users u ON u.id = COALESCE(a.seeker_user_id, a.user_id)
     WHERE a.provider_id = :pid AND a.status = 'completed'
     GROUP BY COALESCE(a.seeker_user_id, a.user_id), u.first_name, u.last_name, u.email
     ORDER BY last_completed_at DESC",
    [':pid' => $pid]
);
$pastCustomersById = [];
foreach ($pastCustomers as $c) {
    $pastCustomersById[(int)$c['seeker_user_id']] = $c;
}

// -- Services this provider offers, to feature in the outreach message (new or previously booked) --
$providerServices = safeAll($db,
    "SELECT id, service_name, price, status FROM services WHERE provider_id = :pid ORDER BY status = 'active' DESC, service_name",
    [':pid' => $pid]
);
$providerServicesById = [];
foreach ($providerServices as $s) {
    $providerServicesById[(int)$s['id']] = $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_outreach') {
    $subject   = trim($_POST['subject'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $recipients = array_map('intval', $_POST['recipients'] ?? []);
    $recipients = array_unique(array_filter($recipients));

    // Re-validate the featured service against this provider's own catalog —
    // never trust a service id from the request body directly.
    $featuredService = $providerServicesById[$serviceId] ?? null;
    $ctaLabel = $ctaUrl = null;
    if ($featuredService) {
        $ctaLabel = 'View & Book: ' . $featuredService['service_name'];
        $ctaUrl   = siteUrl('provider-details.php') . '?id=' . $pid . '&highlight=' . (int)$featuredService['id'];
    }

    if ($subject === '' || $message === '') {
        $error = 'Subject and message are both required.';
    } elseif (empty($recipients)) {
        $error = 'Select at least one customer to send to.';
    } else {
        $mailer = new EmailSender();
        foreach ($recipients as $rid) {
            // Re-validate against the actual past-customer list — never trust
            // recipient ids from the request body directly.
            if (!isset($pastCustomersById[$rid])) {
                continue;
            }
            $cust = $pastCustomersById[$rid];
            $toName = trim($cust['first_name'] . ' ' . $cust['last_name']) ?: 'Customer';
            $ok = $mailer->sendCustomEmail($cust['email'], $toName, $subject, $message, $portal_company, $ctaLabel, $ctaUrl);

            $db->prepare(
                "INSERT INTO crm_outreach_log
                    (provider_id, seeker_user_id, featured_service_id, featured_service_name,
                     sent_by_name, sent_by_role, subject, message, status, error_message)
                 VALUES (:pid, :sid, :fsid, :fsname, :bn, :br, :subj, :msg, :status, :err)"
            )->execute([
                ':pid'    => $pid,
                ':sid'    => $rid,
                ':fsid'   => $featuredService ? (int)$featuredService['id'] : null,
                ':fsname' => $featuredService ? $featuredService['service_name'] : null,
                ':bn'     => $portal_full_name,
                ':br'     => $portal_role,
                ':subj'   => $subject,
                ':msg'    => $message,
                ':status' => $ok ? 'sent' : 'failed',
                ':err'    => $ok ? null : $mailer->getLastError(),
            ]);

            if ($ok) { $sentCount++; } else { $failedCount++; }
        }

        if ($sentCount > 0 && $failedCount === 0) {
            $success = "Sent to $sentCount customer" . ($sentCount === 1 ? '' : 's') . ".";
        } elseif ($sentCount > 0) {
            $success = "Sent to $sentCount customer" . ($sentCount === 1 ? '' : 's') . ", but $failedCount failed to send.";
        } else {
            $error = 'Could not send to any selected customer. Check the SMTP settings in config/config.php.';
        }
    }
}

// -- Recent outreach history --
$history = safeAll($db,
    "SELECT l.*, u.first_name, u.last_name, u.email
     FROM crm_outreach_log l
     LEFT JOIN users u ON u.id = l.seeker_user_id
     WHERE l.provider_id = :pid
     ORDER BY l.created_at DESC
     LIMIT 25",
    [':pid' => $pid]
);

$active_menu = 'crm_outreach';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customer Outreach · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{margin-bottom:22px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(239,68,68,.2);color:#991b1b}
.layout-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px;align-items:start}
@media (max-width:900px){.layout-grid{grid-template-columns:1fr}}
.card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
.card-header{padding:16px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-body{padding:18px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--dark);margin-bottom:6px}
.form-control{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
textarea.form-control{min-height:120px;resize:vertical}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.recipient-list{max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.recipient-row{display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border);font-size:13px}
.recipient-row:last-child{border-bottom:none}
.recipient-name{font-weight:600;color:var(--dark)}
.recipient-meta{font-size:11px;color:var(--muted)}
.recipient-tags{font-size:11px;color:#0f766e;margin-top:2px}
.field-hint{font-size:11px;color:var(--muted);margin-top:6px}
.select-all-row{display:flex;align-items:center;gap:8px;padding:8px 0;font-size:12px;color:var(--muted);font-weight:600}
.empty-state{padding:30px;text-align:center;color:var(--muted);font-size:13px}
.history-row{padding:12px 18px;border-bottom:1px solid var(--border);font-size:12px}
.history-row:last-child{border-bottom:none}
.history-top{display:flex;justify-content:space-between;gap:8px;margin-bottom:3px}
.history-subj{font-weight:700;color:var(--dark)}
.history-status{font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:uppercase}
.history-status.sent{background:#d1fae5;color:#065f46}
.history-status.failed{background:#fee2e2;color:#991b1b}
.history-meta{color:var(--muted)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

    <div class="page-header">
        <h1><i class="fas fa-bullhorn"></i> Customer Outreach</h1>
        <p>Email past customers about promotions, new services, or a returning-customer offer.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="layout-grid">
        <div class="card">
            <div class="card-header"><i class="fas fa-paper-plane"></i> Compose Message</div>
            <div class="card-body">
                <?php if (empty($pastCustomers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash" style="font-size:24px;margin-bottom:8px;display:block"></i>
                        No past customers yet. This list fills up once a booking is marked completed.
                    </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="send_outreach">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="e.g. 20% off your next booking!" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" placeholder="Write your message to these customers..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Feature a Service (optional)</label>
                        <select name="service_id" class="form-control">
                            <option value="0">— No specific service —</option>
                            <?php foreach ($providerServices as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['service_name']) ?> — ₱<?= number_format((float)$s['price'], 2) ?><?= $s['status'] !== 'active' ? ' (inactive)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Picking a service (one they've had before, or a new one) adds a clickable "View &amp; Book" button in the email that takes the customer straight to it.</div>
                    </div>
                    <div class="form-group">
                        <label>Send To (<?= count($pastCustomers) ?> past customer<?= count($pastCustomers) === 1 ? '' : 's' ?>)</label>
                        <div class="select-all-row">
                            <input type="checkbox" id="selectAll" onclick="document.querySelectorAll('.recipient-check').forEach(c=>c.checked=this.checked)">
                            <label for="selectAll" style="margin:0;font-weight:600">Select all</label>
                        </div>
                        <div class="recipient-list">
                            <?php foreach ($pastCustomers as $c): ?>
                            <label class="recipient-row" style="cursor:pointer">
                                <input type="checkbox" class="recipient-check" name="recipients[]" value="<?= (int)$c['seeker_user_id'] ?>">
                                <div>
                                    <div class="recipient-name"><?= htmlspecialchars(trim($c['first_name'].' '.$c['last_name'])) ?></div>
                                    <div class="recipient-meta"><?= htmlspecialchars($c['email']) ?> &middot; <?= (int)$c['completed_count'] ?> completed booking<?= (int)$c['completed_count'] === 1 ? '' : 's' ?></div>
                                    <div class="recipient-tags">Had: <?= htmlspecialchars($c['services_had'] ?: '—') ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-clock-rotate-left"></i> Recent Outreach</div>
            <?php if (empty($history)): ?>
                <div class="empty-state">No outreach sent yet.</div>
            <?php else: foreach ($history as $h): ?>
                <div class="history-row">
                    <div class="history-top">
                        <span class="history-subj"><?= htmlspecialchars($h['subject']) ?></span>
                        <span class="history-status <?= $h['status'] === 'sent' ? 'sent' : 'failed' ?>"><?= htmlspecialchars($h['status']) ?></span>
                    </div>
                    <div class="history-meta">
                        To <?= htmlspecialchars(trim(($h['first_name'] ?? '').' '.($h['last_name'] ?? '')) ?: 'Unknown') ?>
                        <?php if (!empty($h['featured_service_name'])): ?>&middot; featuring <strong><?= htmlspecialchars($h['featured_service_name']) ?></strong><?php endif; ?>
                        &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime($h['created_at']))) ?>
                        &middot; by <?= htmlspecialchars($h['sent_by_name'] ?: 'Staff') ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

</div></div>
</div>
</body></html>
