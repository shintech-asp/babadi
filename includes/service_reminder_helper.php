<?php
// includes/service_reminder_helper.php
//
// Lazy, best-effort "your service is today" email reminder for the assigned
// field technician. There's no cron/queue in this app, so this is called
// from pages the provider/staff/employee actually load (provider-portal's
// dashboard.php today) — the same lazy-check pattern portal-tier.php already
// uses for subscription expiry. It's cheap to call repeatedly: the query
// only matches unsent, same-day, assigned bookings, so it's a no-op once
// everything due has already been emailed.

require_once __DIR__ . '/../config/send_email.php';

function sendDueServiceReminders(PDO $db, int $providerId): void
{
    try {
        $stmt = $db->prepare(
            "SELECT a.id, a.service_name, a.full_name, a.preferred_time, a.address,
                    e.id AS employee_id, e.first_name, e.last_name, e.email
             FROM availed_services a
             JOIN employees e ON e.id = a.assigned_employee_id
             WHERE a.provider_id = :pid
               AND a.preferred_date = CURDATE()
               AND a.assigned_employee_id IS NOT NULL
               AND a.reminder_sent_at IS NULL
               AND a.status NOT IN ('completed', 'cancelled')
               AND e.status = 'active'"
        );
        $stmt->execute([':pid' => $providerId]);
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return;
    }

    if (empty($due)) {
        return;
    }

    $mailer = new EmailSender();

    foreach ($due as $row) {
        if (empty($row['email'])) {
            continue;
        }

        $techName = trim($row['first_name'] . ' ' . $row['last_name']);
        $time     = $row['preferred_time'] ? date('g:i A', strtotime($row['preferred_time'])) : '';
        $body     = "You have a service scheduled today.\n\n"
            . "Service: " . ($row['service_name'] ?: 'Service') . "\n"
            . "Client: " . $row['full_name'] . "\n"
            . ($time ? "Time: " . $time . "\n" : '')
            . "Address: " . $row['address'];

        $ok = $mailer->sendCustomEmail(
            $row['email'],
            $techName,
            "Reminder: Service today — " . ($row['service_name'] ?: 'Service'),
            $body
        );

        if ($ok) {
            try {
                $db->prepare("UPDATE availed_services SET reminder_sent_at = NOW() WHERE id = ?")
                   ->execute([$row['id']]);
            } catch (Exception $e) { /* next lazy check will retry the send instead */ }
        }
    }
}
