<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . appUrl('login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? appUrl('messages.php'))));
    exit;
}

if (($_SESSION['user_type'] ?? '') === 'provider') {
    require_once __DIR__ . '/provider/messages-provider.php';
    exit;
}

require_once __DIR__ . '/seeker/messages-seeker.php';
