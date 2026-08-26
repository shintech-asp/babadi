<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$provider_id = (int)inp('provider_id');
if ($provider_id <= 0) {
    fail('provider_id required');
}

$date = inp('date', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fail('date must be in Y-m-d format');
}
$ts = strtotime($date);
if ($ts === false) {
    fail('Invalid date');
}

$pdo = db();

// Fetch working hours from admin_settings
$keys = [
    'start'        => "provider_{$provider_id}_working_hours_start",
    'end'          => "provider_{$provider_id}_working_hours_end",
    'slot_minutes' => "provider_{$provider_id}_slot_minutes",
];

$stmt = $pdo->prepare(
    "SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN (:k1, :k2, :k3)"
);
$stmt->execute([
    ':k1' => $keys['start'],
    ':k2' => $keys['end'],
    ':k3' => $keys['slot_minutes'],
]);
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$work_start    = $rows[$keys['start']]        ?? '09:00';
$work_end      = $rows[$keys['end']]          ?? '17:00';
$slot_minutes  = (int)($rows[$keys['slot_minutes']] ?? 60);
if ($slot_minutes <= 0) {
    $slot_minutes = 60;
}

// Fetch occupied time slots
$stmt2 = $pdo->prepare(
    "SELECT preferred_time FROM availed_services
     WHERE provider_id = :pid
       AND preferred_date = :date
       AND status NOT IN ('cancelled', 'rejected')
       AND preferred_time IS NOT NULL"
);
$stmt2->execute([':pid' => $provider_id, ':date' => $date]);
$occupied_raw = $stmt2->fetchAll(PDO::FETCH_COLUMN);

// Normalize occupied times to H:i:s
$occupied = [];
foreach ($occupied_raw as $t) {
    $normalized = date('H:i:s', strtotime($t));
    $occupied[$normalized] = true;
}

// Generate slots
$today     = date('Y-m-d');
$is_today  = ($date === $today);
$now_time  = time();

$start_ts  = strtotime($date . ' ' . $work_start);
$end_ts    = strtotime($date . ' ' . $work_end);

$slots = [];
$current = $start_ts;
while ($current < $end_ts) {
    $slot_value = date('H:i:s', $current);

    // Skip past times if booking is today
    if ($is_today && $current <= $now_time) {
        $current += $slot_minutes * 60;
        continue;
    }

    // Skip occupied slots
    if (isset($occupied[$slot_value])) {
        $current += $slot_minutes * 60;
        continue;
    }

    // Build human-readable label
    $label = date('g:i A', $current);

    $slots[] = [
        'value' => $slot_value,
        'label' => $label,
    ];

    $current += $slot_minutes * 60;
}

ok([
    'slots' => $slots,
    'working_hours' => [
        'start' => $work_start,
        'end'   => $work_end,
    ],
    'slot_minutes' => $slot_minutes,
]);
