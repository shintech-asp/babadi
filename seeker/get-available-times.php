<?php
chdir(dirname(__DIR__));
// get-available-times.php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

header('Content-Type: application/json; charset=utf-8');

$provider_id = (int)($_GET['provider_id'] ?? 0);
$date = trim((string)($_GET['date'] ?? ''));

if ($provider_id <= 0) {
    echo json_encode(['slots' => [], 'error' => 'Invalid provider id']);
    exit();
}

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$dateTs = strtotime($date . ' 00:00:00');
if ($dateTs === false) {
    $date = date('Y-m-d');
}

$database = new Database();
$db = $database->getConnection();

function providerSetting(PDO $db, int $providerId, string $key, string $default): string
{
    try {
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => "provider_{$providerId}_{$key}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['setting_value'])) {
            return trim((string)$row['setting_value']);
        }
    } catch (Throwable $e) {}
    return $default;
}

$startTime = providerSetting($db, $provider_id, 'working_hours_start', '09:00');
$endTime = providerSetting($db, $provider_id, 'working_hours_end', '17:00');
$slotMinutesRaw = providerSetting($db, $provider_id, 'working_slot_minutes', '60');
$slotMinutes = (int)$slotMinutesRaw;
if ($slotMinutes < 5 || $slotMinutes > 180) {
    $slotMinutes = 60;
}

$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
if (!preg_match($timePattern, $startTime)) { $startTime = '09:00'; }
if (!preg_match($timePattern, $endTime)) { $endTime = '17:00'; }

$startTs = strtotime($date . ' ' . $startTime . ':00');
$endTs = strtotime($date . ' ' . $endTime . ':00');
if ($startTs === false || $endTs === false || $endTs <= $startTs) {
    $startTs = strtotime($date . ' 09:00:00');
    $endTs = strtotime($date . ' 17:00:00');
}

$occupied = [];
try {
    $stmt = $db->prepare(
        "SELECT preferred_time
         FROM availed_services
         WHERE provider_id = :pid
           AND preferred_date = :pd
           AND status NOT IN ('cancelled', 'rejected')
           AND preferred_time IS NOT NULL"
    );
    $stmt->execute([':pid' => $provider_id, ':pd' => $date]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $t = trim((string)($row['preferred_time'] ?? ''));
        if ($t === '') continue;
        $timeTs = strtotime($date . ' ' . $t);
        if ($timeTs === false) continue;
        $occupied[date('H:i:00', $timeTs)] = true;
    }
} catch (Throwable $e) {}

$slots = [];
$now = time();
for ($cursor = $startTs; $cursor <= $endTs; $cursor += ($slotMinutes * 60)) {
    $value = date('H:i:00', $cursor);
    if (isset($occupied[$value])) {
        continue;
    }

    // If seeker is booking today, hide times that already passed.
    if (date('Y-m-d', $cursor) === date('Y-m-d') && $cursor <= $now) {
        continue;
    }

    $slots[] = [
        'value' => $value,
        'label' => date('g:i A', $cursor),
    ];
}

echo json_encode([
    'slots' => $slots,
    'working_hours' => [
        'start' => date('g:i A', $startTs),
        'end' => date('g:i A', $endTs),
    ],
    'slot_minutes' => $slotMinutes,
]);
exit();
