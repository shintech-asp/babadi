<?php
// POST api/v1/portal/crm/outreach/send
// Emails one or more past customers about a service, with a clickable
// booking link (provider-details.php?id=...&highlight=...) — mirrors
// provider-portal/crm-outreach.php's 'send_outreach' action.
// Access: owner, crm | Tier: Pro required.
//
// Body: subject, message, recipients[] (seeker_user_id list), service_id (optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'config/send_email.php';

allow('POST');
$staff = require_portal_role('owner', 'crm');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$subject = trim((string) req_inp('subject', 'subject'));
$message = trim((string) req_inp('message', 'message'));
$serviceId = (int) inp('service_id', 0);

$recipientsRaw = input()['recipients'] ?? [];
if (!is_array($recipientsRaw)) $recipientsRaw = [$recipientsRaw];
$recipients = array_unique(array_filter(array_map('intval', $recipientsRaw)));

if ($subject === '' || $message === '') fail('subject and message are both required.');
if (empty($recipients)) fail('Select at least one customer to send to.');

$pdo = db();

$companyStmt = $pdo->prepare("SELECT company_name FROM providers WHERE id = :pid LIMIT 1");
$companyStmt->execute([':pid' => $pid]);
$companyName = $companyStmt->fetchColumn() ?: null;

// Re-validate the featured service against this provider's own catalog —
// never trust a service id from the request body directly.
$featuredService = null;
if ($serviceId > 0) {
    $svcStmt = $pdo->prepare("SELECT id, service_name FROM services WHERE id = :id AND provider_id = :pid LIMIT 1");
    $svcStmt->execute([':id' => $serviceId, ':pid' => $pid]);
    $featuredService = $svcStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$ctaLabel = $ctaUrl = null;
if ($featuredService) {
    $ctaLabel = 'View & Book: ' . $featuredService['service_name'];
    $ctaUrl   = siteUrl('provider-details.php') . '?id=' . $pid . '&highlight=' . (int)$featuredService['id'];
}

// Re-validate recipients against the actual past-customer list — never
// trust recipient ids from the request body directly.
$placeholders = implode(',', array_fill(0, count($recipients), '?'));
$custStmt = $pdo->prepare(
    "SELECT DISTINCT COALESCE(a.seeker_user_id, a.user_id) AS seeker_user_id, u.first_name, u.last_name, u.email
     FROM availed_services a
     JOIN users u ON u.id = COALESCE(a.seeker_user_id, a.user_id)
     WHERE a.provider_id = ? AND a.status = 'completed' AND COALESCE(a.seeker_user_id, a.user_id) IN ($placeholders)"
);
$custStmt->execute(array_merge([$pid], $recipients));
$validCustomers = $custStmt->fetchAll(PDO::FETCH_ASSOC);

$mailer = new EmailSender();
$sentCount = 0;
$failedCount = 0;

foreach ($validCustomers as $cust) {
    $rid = (int)$cust['seeker_user_id'];
    $toName = trim($cust['first_name'] . ' ' . $cust['last_name']) ?: 'Customer';
    $success = $mailer->sendCustomEmail($cust['email'], $toName, $subject, $message, $companyName, $ctaLabel, $ctaUrl);

    try {
        $pdo->prepare(
            "INSERT INTO crm_outreach_log
                (provider_id, seeker_user_id, featured_service_id, featured_service_name,
                 sent_by_name, sent_by_role, subject, message, status, error_message)
             VALUES (:pid, :sid, :fsid, :fsname, :bn, :br, :subj, :msg, :status, :err)"
        )->execute([
            ':pid'    => $pid,
            ':sid'    => $rid,
            ':fsid'   => $featuredService ? (int)$featuredService['id'] : null,
            ':fsname' => $featuredService ? $featuredService['service_name'] : null,
            ':bn'     => $staff['full_name'] ?? '',
            ':br'     => $staff['role'] ?? '',
            ':subj'   => $subject,
            ':msg'    => $message,
            ':status' => $success ? 'sent' : 'failed',
            ':err'    => $success ? null : $mailer->getLastError(),
        ]);
    } catch (Exception $e) {}

    if ($success) { $sentCount++; } else { $failedCount++; }
}

if ($sentCount === 0) {
    fail('Could not send to any selected customer. Check the SMTP settings in config/config.php.', 502);
}

ok(['data' => [
    'sent' => $sentCount,
    'failed' => $failedCount,
    'message' => $failedCount > 0
        ? "Sent to $sentCount customer" . ($sentCount === 1 ? '' : 's') . ", but $failedCount failed to send."
        : "Sent to $sentCount customer" . ($sentCount === 1 ? '' : 's') . ".",
]]);
