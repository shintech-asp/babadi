<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'Privacy Policy';
$current_page = 'privacy';
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
        <h1 style="margin: 0 0 1rem;">Privacy Policy</h1>
        <p style="color: #475569; line-height: 1.7; margin: 0 0 1rem;">
            Pestify stores account, booking, payment, and verification data to operate the platform and support service coordination.
        </p>
        <p style="color: #475569; line-height: 1.7; margin: 0 0 1rem;">
            Uploaded business documents are used for verification and audit purposes. Access is limited to authorized reviewers and system administrators.
        </p>
        <p style="color: #475569; line-height: 1.7; margin: 0;">
            Users should avoid sharing unnecessary sensitive information in public reviews or messages.
        </p>
    </section>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
