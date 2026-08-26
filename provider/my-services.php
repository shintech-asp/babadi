<?php
chdir(dirname(__DIR__));
// my-services.php - Redirect to services.php
require_once 'config/config.php';
header('Location: ' . appUrl('services.php'));
exit();
?>
