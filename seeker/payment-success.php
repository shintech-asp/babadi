<?php
// seeker/payment-success.php — PayMongo's success_url points directly at this
// root-level path (built via SITE_URL string concatenation in store.php,
// retry-payment.php, remaining-payment.php, payment-redirect.php, and
// provider-portal's crm-bookings.php/portal-request-action.php — none of
// those go through appUrl(), so they bypass the canonicalAppRoute() alias
// that maps 'payment-success.php' to seeker/payment-success-result.php).
// This file's only job is to forward to the real handler, preserving the
// query string (booking_id etc.).
chdir(dirname(__DIR__));
require_once 'config/config.php';
$_qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . siteUrl('seeker/payment-success-result.php') . $_qs);
exit;
