<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'Help & FAQ';
$current_page = 'faq';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Pestify</title>
</head>
<body>
<?php include appPath('includes/header.php'); ?>
<main class="container" style="padding: 3rem 1rem 4rem;">
    <section style="max-width: 900px; margin: 0 auto; background: #fff; border-radius: 20px; padding: 2.5rem; box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);">
        <h1 style="margin: 0 0 1rem;">Help & FAQ</h1>
        <div style="display: grid; gap: 1.25rem; color: #475569; line-height: 1.7;">
            <div><strong>How do I book a service?</strong><br>Browse providers or listings, open a provider profile, and submit a service request with your preferred date and time.</div>
            <div><strong>How do payments work?</strong><br>Pestify supports full payment or downpayment depending on the provider’s configuration. Receipts are shown to both client and provider.</div>
            <div><strong>Can a provider reschedule?</strong><br>Yes. Providers can propose a new date when they cannot attend on the original booking date, and seekers can accept or reject the change.</div>
            <div><strong>How is provider legitimacy checked?</strong><br>Businesses submit supporting documents for review, and the Super Admin approval workflow records reviewer identity, notes, and audit history.</div>
        </div>
    </section>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
