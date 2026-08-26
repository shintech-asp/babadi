<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'Contact Us';
$current_page = 'contact';
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
    <section style="max-width: 860px; margin: 0 auto; background: #fff; border-radius: 20px; padding: 2.5rem; box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);">
        <h1 style="margin: 0 0 1rem;">Contact Pestify</h1>
        <p style="margin: 0 0 1.5rem; color: #475569; line-height: 1.7;">
            For account issues, booking concerns, or provider verification questions, contact the Pestify support team using the details below.
        </p>
        <div style="display: grid; gap: 1rem;">
            <div><strong>Email:</strong> support@pestify.local</div>
            <div><strong>Phone:</strong> +63 917 000 0000</div>
            <div><strong>Support Hours:</strong> Monday to Saturday, 8:00 AM to 6:00 PM</div>
            <div><strong>Office:</strong> Pestify Operations, Metro Manila, Philippines</div>
        </div>
    </section>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
