<?php
session_start();

require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . appUrl('login.php') . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? appUrl('messages-seeker.php')));
    exit;
}

if (($_SESSION['user_type'] ?? '') === 'provider') {
    header('Location: ' . appUrl('messages.php'));
    exit;
}

require_once __DIR__ . '/seeker/messages-seeker.php';
