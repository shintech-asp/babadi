<?php
// provider-portal/schedules.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_schedule = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_schedule) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Schedules'); exit; }

function safeAll($db, $sql, $params = []) {
    try {
        $s = $db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}
function safeCount($db, $sql, $params = []) {
    try {
        $s = $db->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}

try {
    $monthDate = new DateTimeImmutable($ym . '-01');
} catch (Exception $e) {
    $monthDate = new DateTimeImmutable(date('Y-m-01'));
}

$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->format('Y-m-t');
$displayMonth = $monthDate->format('F Y');
$prevMonth = $monthDate->modify('-1 month')->format('Y-m');
$nextMonth = $monthDate->modify('+1 month')->format('Y-m');

$bookings = safeAll(
    $db,
    "SELECT id, full_name, service_name, preferred_date, preferred_time, status, total_amount, created_at
     FROM availed_services
     WHERE provider_id = :pid
       AND preferred_date BETWEEN :start AND :end
     ORDER BY preferred_date ASC, preferred_time ASC, id ASC",
    [':pid' => $pid, ':start' => $monthStart, ':end' => $monthEnd]
);

$monthTotal = count($bookings);
$monthPending = 0;
$monthPreparing = 0;
$monthInProgress = 0;
$monthCompleted = 0;

$eventsByDate = [];
foreach ($bookings as $b) {
    $d = $b['preferred_date'] ?? '';
    if (!$d) {
        continue;
    }
    $eventsByDate[$d][] = $b;

    $st = (string)($b['status'] ?? '');
    if ($st === 'pending') {
        $monthPending++;
    } elseif ($st === 'preparing') {
        $monthPreparing++;
    } elseif (in_array($st, ['waiting_provider_confirmation', 'on_the_way', 'in_progress'], true)) {
        $monthInProgress++;
    } elseif ($st === 'completed') {
        $monthCompleted++;
    }
}

$active_menu = 'schedules';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedules - Provider Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f7fa;
            --white: #ffffff;
            --dark: #1a2744;
            --muted: #718096;
            --border: #e2e8f0;
            --primary: #2E8B57;
            --pending-bg: #fef3c7; --pending-tx: #92400e;
            --prep-bg: #e0f2fe; --prep-tx: #0369a1;
            --active-bg: #dbeafe; --active-tx: #1e40af;
            --done-bg: #dcfce7; --done-tx: #166534;
            --x-bg: #fee2e2; --x-tx: #991b1b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: #2d3748; }
        .dashboard-layout { display: flex; min-height: 100vh; }
        .main-content { padding: 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        .page-header h1 { font-size: 24px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .page-header p { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .month-nav { display: flex; align-items: center; gap: 8px; }
        .btn {
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--dark);
            border-radius: 10px;
            padding: 8px 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
        .stat {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 14px;
        }
        .stat h3 { font-size: 22px; color: var(--dark); font-weight: 800; line-height: 1; }
        .stat p { margin-top: 5px; font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .6px; }
        .layout-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; }
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .card-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-head h2 { font-size: 14px; color: var(--dark); font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .card-body { padding: 14px 16px; }
        .week-head {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 8px;
        }
        .week-head div { text-align: center; font-size: 11px; color: var(--muted); font-weight: 800; letter-spacing: .6px; text-transform: uppercase; }
        .calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }
        .day {
            min-height: 108px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px;
            background: #fff;
            cursor: pointer;
            transition: all .15s ease;
        }
        .day:hover { border-color: #9fcdb2; box-shadow: 0 4px 12px rgba(46,139,87,.12); }
        .day.empty { background: #f8fafc; cursor: default; border-style: dashed; }
        .day.empty:hover { box-shadow: none; border-color: var(--border); }
        .day-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 7px;
        }
        .day-num { font-size: 12px; font-weight: 800; color: var(--dark); }
        .day-count { font-size: 10px; color: var(--muted); font-weight: 700; }
        .today .day-num { color: var(--primary); }
        .selected { border-color: var(--primary) !important; box-shadow: 0 0 0 2px rgba(46,139,87,.16); }
        .chip {
            display: block;
            border-radius: 8px;
            font-size: 10px;
            padding: 3px 6px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 700;
        }
        .status-pending{background:var(--pending-bg);color:var(--pending-tx);}
        .status-preparing{background:var(--prep-bg);color:var(--prep-tx);}
        .status-active{background:var(--active-bg);color:var(--active-tx);}
        .status-completed{background:var(--done-bg);color:var(--done-tx);}
        .status-cancelled{background:var(--x-bg);color:var(--x-tx);}
        .day-list { max-height: 560px; overflow: auto; padding-right: 2px; }
        .entry {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 9px;
            background: #fff;
        }
        .entry .title { font-size: 13px; font-weight: 800; color: var(--dark); margin-bottom: 4px; }
        .meta { font-size: 12px; color: var(--muted); line-height: 1.6; }
        .pill {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 3px 9px;
            margin-top: 6px;
        }
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .layout-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 16px; }
            .week-head, .calendar { gap: 5px; }
            .day { min-height: 92px; padding: 6px; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'includes/portal-sidebar.php'; ?>
    <main class="portal-main">
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-calendar-alt" style="color:#2E8B57;"></i> Booking Schedules</h1>
                    <p>Calendar view of all booking schedules for <?= htmlspecialchars($displayMonth) ?>.</p>
                </div>
                <div class="month-nav">
                    <a class="btn" href="schedules.php?ym=<?= urlencode($prevMonth) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                    <a class="btn btn-primary" href="schedules.php?ym=<?= date('Y-m') ?>">Today</a>
                    <a class="btn" href="schedules.php?ym=<?= urlencode($nextMonth) ?>">Next <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat"><h3><?= $monthTotal ?></h3><p>Total This Month</p></div>
                <div class="stat"><h3><?= $monthPending ?></h3><p>Pending</p></div>
                <div class="stat"><h3><?= $monthPreparing ?></h3><p>Preparing</p></div>
                <div class="stat"><h3><?= $monthInProgress ?></h3><p>Active</p></div>
                <div class="stat"><h3><?= $monthCompleted ?></h3><p>Completed</p></div>
            </div>

            <div class="layout-grid">
                <section class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-calendar-day" style="color:#2E8B57;"></i> <?= htmlspecialchars($displayMonth) ?></h2>
                        <span style="font-size:12px;color:var(--muted);font-weight:700;">Click a day to see details</span>
                    </div>
                    <div class="card-body">
                        <div class="week-head">
                            <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                        </div>
                        <div class="calendar">
                            <?php
                            $firstDay = (int)$monthDate->format('w');
                            $daysInMonth = (int)$monthDate->format('t');
                            $today = date('Y-m-d');

                            for ($i = 0; $i < $firstDay; $i++) {
                                echo '<div class="day empty"></div>';
                            }

                            for ($day = 1; $day <= $daysInMonth; $day++):
                                $dateKey = $monthDate->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                                $dayEvents = $eventsByDate[$dateKey] ?? [];
                                $isToday = ($dateKey === $today);
                                $encoded = htmlspecialchars(json_encode($dayEvents, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="day <?= $isToday ? 'today' : '' ?>" data-date="<?= htmlspecialchars($dateKey) ?>" data-events="<?= $encoded ?>">
                                <div class="day-top">
                                    <span class="day-num"><?= $day ?></span>
                                    <span class="day-count"><?= count($dayEvents) ? count($dayEvents) . ' booking' . (count($dayEvents) > 1 ? 's' : '') : '' ?></span>
                                </div>
                                <?php
                                $shown = 0;
                                foreach ($dayEvents as $ev):
                                    if ($shown >= 2) break;
                                    $st = (string)($ev['status'] ?? '');
                                    $cls = in_array($st, ['on_the_way', 'in_progress', 'waiting_provider_confirmation'], true) ? 'status-active' : (
                                        $st === 'pending' ? 'status-pending' : (
                                            $st === 'preparing' ? 'status-preparing' : (
                                                $st === 'completed' ? 'status-completed' : 'status-cancelled'
                                            )
                                        )
                                    );
                                    $timeLabel = !empty($ev['preferred_time']) ? date('g:i A', strtotime((string)$ev['preferred_time'])) : 'Time TBD';
                                ?>
                                <span class="chip <?= $cls ?>"><?= htmlspecialchars($timeLabel) ?> · #<?= (int)$ev['id'] ?></span>
                                <?php
                                    $shown++;
                                endforeach;
                                if (count($dayEvents) > 2):
                                ?>
                                <span class="chip" style="background:#f1f5f9;color:#475569;">+<?= count($dayEvents) - 2 ?> more</span>
                                <?php endif; ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </section>

                <aside class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-list-check" style="color:#2E8B57;"></i> Day Schedule</h2>
                        <span id="selectedDateLabel" style="font-size:12px;color:var(--muted);font-weight:700;">Select a date</span>
                    </div>
                    <div class="card-body day-list" id="dayDetails">
                        <div style="font-size:13px;color:var(--muted);">Click any day in the calendar to view booking details.</div>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>

<script>
    const dayCells = document.querySelectorAll('.day[data-date]');
    const details = document.getElementById('dayDetails');
    const label = document.getElementById('selectedDateLabel');

    function escapeHtml(v) {
        return String(v ?? '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
        });
    }

    function statusMeta(status) {
        switch (status) {
            case 'pending': return {text: 'Pending', cls: 'status-pending'};
            case 'preparing': return {text: 'Preparing', cls: 'status-preparing'};
            case 'waiting_provider_confirmation': return {text: 'Waiting Provider Confirmation', cls: 'status-active'};
            case 'on_the_way': return {text: 'On The Way', cls: 'status-active'};
            case 'in_progress': return {text: 'In Progress', cls: 'status-active'};
            case 'completed': return {text: 'Completed', cls: 'status-completed'};
            case 'cancelled': return {text: 'Cancelled', cls: 'status-cancelled'};
            case 'rejected': return {text: 'Rejected', cls: 'status-cancelled'};
            default: return {text: status ? status.replaceAll('_', ' ') : 'Unknown', cls: 'status-cancelled'};
        }
    }

    function fmtDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'short' });
    }

    function fmtTime(timeStr) {
        if (!timeStr) return 'Time TBD';
        const d = new Date('1970-01-01T' + timeStr);
        if (Number.isNaN(d.getTime())) return timeStr;
        return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    function selectDay(cell) {
        dayCells.forEach(c => c.classList.remove('selected'));
        cell.classList.add('selected');

        const date = cell.dataset.date || '';
        const events = JSON.parse(cell.dataset.events || '[]');
        label.textContent = fmtDate(date);

        if (!events.length) {
            details.innerHTML = '<div style="font-size:13px;color:#718096;">No bookings scheduled for this day.</div>';
            return;
        }

        details.innerHTML = events.map(ev => {
            const meta = statusMeta((ev.status || '').toLowerCase());
            const amount = Number(ev.total_amount || 0);
            return `
                <div class="entry">
                    <div class="title">#${escapeHtml(ev.id)} · ${escapeHtml(ev.service_name || 'Service')}</div>
                    <div class="meta">
                        <div><strong>Client:</strong> ${escapeHtml(ev.full_name || 'N/A')}</div>
                        <div><strong>Time:</strong> ${escapeHtml(fmtTime(ev.preferred_time || ''))}</div>
                        <div><strong>Amount:</strong> ₱${escapeHtml(amount.toFixed(2))}</div>
                    </div>
                    <span class="pill ${meta.cls}">${escapeHtml(meta.text)}</span>
                </div>
            `;
        }).join('');
    }

    // Auto-select first day with bookings; fallback to today's day cell if present; else first available day.
    let initCell = Array.from(dayCells).find(c => {
        try {
            const arr = JSON.parse(c.dataset.events || '[]');
            return Array.isArray(arr) && arr.length > 0;
        } catch (e) {
            return false;
        }
    });
    if (!initCell) initCell = document.querySelector('.day.today[data-date]');
    if (!initCell) initCell = dayCells[0] || null;
    if (initCell) selectDay(initCell);

    dayCells.forEach(cell => {
        cell.addEventListener('click', () => selectDay(cell));
    });
</script>
</body>
</html>
