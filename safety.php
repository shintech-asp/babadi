<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'Safety Guidelines';
$current_page = 'safety';
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
        <h1 style="margin: 0 0 1rem;">Safety Guidelines</h1>
        <div style="display: grid; gap: 1rem; color: #475569; line-height: 1.7;">
            <div>Verify provider identity before service starts and use the mutual confirmation flow in the system.</div>
            <div>Keep proof of payment and booking details accessible on service day.</div>
            <div>Report suspicious behavior, unsafe work practices, or fraudulent documents through Pestify support immediately.</div>
            <div>Follow provider instructions for children, pets, and restricted areas during treatment.</div>
        </div>
    </section>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
