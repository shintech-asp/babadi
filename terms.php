<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'Terms of Service';
$current_page = 'terms';
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
        <h1 style="margin: 0 0 1rem;">Terms of Service</h1>
        <p style="color: #475569; line-height: 1.7; margin: 0 0 1rem;">
            Pestify connects clients with independent pest control businesses. Providers remain responsible for their own operations, licensing, pricing, and service delivery.
        </p>
        <p style="color: #475569; line-height: 1.7; margin: 0 0 1rem;">
            By using the platform, users agree to provide accurate information, follow booking and payment requirements, and avoid fraudulent or abusive activity.
        </p>
        <p style="color: #475569; line-height: 1.7; margin: 0;">
            Pestify may suspend or remove accounts that violate platform rules or fail verification checks.
        </p>
    </section>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
