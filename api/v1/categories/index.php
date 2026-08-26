<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$stmt = db()->query('SELECT * FROM service_categories ORDER BY name');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok(['data' => $rows]);
