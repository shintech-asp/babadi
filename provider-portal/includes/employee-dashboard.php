<?php
// provider-portal/includes/employee-dashboard.php
// Dedicated dashboard for non-promoted employee accounts — only their own
// data (attendance, leave, assigned services). Included (and exited after)
// from provider-portal/dashboard.php when portal_account_type === 'employee'.
// Expects $db, $pid, $portal_company, $portal_full_name, $portal_role to
// already be set by the including file.

$self_emp_id = (int)($_SESSION['portal_employee_id'] ?? 0);
$staff_type  = $_SESSION['portal_staff_type'] ?? 'office';
$is_field    = ($staff_type === 'field');

$employee = safeAll($db, "SELECT * FROM employees WHERE id=:e AND provider_id=:p", [':e'=>$self_emp_id, ':p'=>$pid]);
$employee = $employee[0] ?? null;

$today = date('Y-m-d');
$todayAttendance = safeAll($db, "SELECT time_in, time_out FROM timekeeping WHERE employee_id=:e AND provider_id=:p AND work_date=:d", [':e'=>$self_emp_id, ':p'=>$pid, ':d'=>$today]);
$todayAttendance = $todayAttendance[0] ?? null;

$pendingLeaveCount = safeCount($db, "SELECT COUNT(*) FROM leave_requests WHERE employee_id=:e AND provider_id=:p AND status='pending'", [':e'=>$self_emp_id, ':p'=>$pid]);
$upcomingCount = 0;
if ($is_field) {
    // Counts both confirmed (assigned_employee_id set via Prepare Booking) and
    // tentative (this employee is the service's default handler via
    // services.assigned_staff_id, before Prepare Booking runs) assignments —
    // see provider-portal/my-services.php's top-of-file comment for the full
    // rationale; kept in sync with that page and its mobile API mirror.
    $upcomingCount = safeCount($db,
        "SELECT COUNT(*) FROM availed_services av
         LEFT JOIN services s ON s.id = av.service_id
         WHERE av.provider_id=:p AND (av.assigned_employee_id=:e OR s.assigned_staff_id=:e)
           AND av.status NOT IN ('completed','cancelled','pending') AND av.preferred_date >= CURDATE()",
        [':e'=>$self_emp_id, ':p'=>$pid]
    );
}

// ── Calendar month math ──
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) { $monthParam = date('Y-m'); }
$monthStart = $monthParam . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$prevMonth  = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth  = date('Y-m', strtotime($monthStart . ' +1 month'));
$firstWeekday = (int)date('w', strtotime($monthStart)); // 0=Sun
$daysInMonth  = (int)date('t', strtotime($monthStart));

$servicesByDay = [];
if ($is_field) {
    $rows = safeAll($db,
        "SELECT av.id, av.service_name, av.full_name, av.contact_number, av.preferred_date, av.preferred_time,
                av.status, av.address, av.operations_notes, av.assigned_employee_id
         FROM availed_services av
         LEFT JOIN services s ON s.id = av.service_id
         WHERE av.provider_id=:p AND (av.assigned_employee_id=:e OR s.assigned_staff_id=:e)
           AND av.status != 'pending'
           AND av.preferred_date BETWEEN :start AND :end
         ORDER BY av.preferred_time",
        [':e'=>$self_emp_id, ':p'=>$pid, ':start'=>$monthStart, ':end'=>$monthEnd]
    );
    foreach ($rows as $r) {
        $servicesByDay[$r['preferred_date']][] = $r;
    }
}

$leaveByDay = [];
$leaveRows = safeAll($db,
    "SELECT start_date, end_date, leave_type, status
     FROM leave_requests
     WHERE employee_id=:e AND provider_id=:p AND status IN ('pending','approved')
       AND start_date <= :end AND end_date >= :start",
    [':e'=>$self_emp_id, ':p'=>$pid, ':start'=>$monthStart, ':end'=>$monthEnd]
);
foreach ($leaveRows as $lr) {
    $cursor = max(strtotime($lr['start_date']), strtotime($monthStart));
    $stop   = min(strtotime($lr['end_date']), strtotime($monthEnd));
    while ($cursor <= $stop) {
        $leaveByDay[date('Y-m-d', $cursor)][] = $lr;
        $cursor = strtotime('+1 day', $cursor);
    }
}

$statusColors = [
    'preparing'  => '#0891b2',
    'starting'   => '#d97706',
    'on_going'   => '#2563eb',
    'completed'  => '#16a34a',
    'cancelled'  => '#dc2626',
];

$active_menu = 'dashboard';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Dashboard - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{margin-bottom:22px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:14px}
.stat-icon{width:50px;height:50px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.stat-info h3{font-size:18px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}
.quick-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
.ql-card{background:#fff;border-radius:12px;padding:16px;text-decoration:none;color:var(--dark);border:1px solid var(--border);display:flex;align-items:center;gap:12px;transition:all .2s}
.ql-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.1)}
.ql-card i{font-size:18px;color:var(--primary);width:24px;text-align:center}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.month-nav{display:flex;align-items:center;gap:10px;font-size:13px}
.month-nav a{color:var(--dark);text-decoration:none;padding:5px 10px;border-radius:7px;border:1px solid var(--border)}
.month-nav a:hover{background:#f8fafc}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);border-top:1px solid var(--border);border-left:1px solid var(--border)}
.cal-dow{padding:8px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);text-align:center;background:#f8fafc;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.cal-cell{min-height:88px;padding:6px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);font-size:11px;vertical-align:top}
.cal-cell.empty{background:#fafbfc}
.cal-daynum{font-size:12px;font-weight:700;color:var(--dark);margin-bottom:4px}
.cal-cell.today .cal-daynum{color:#fff;background:var(--primary);width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center}
.cal-item{background:#eff6ff;color:#1e40af;padding:2px 5px;border-radius:4px;font-size:9.5px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-leave{background:#fef3c7;color:#92400e;padding:2px 5px;border-radius:4px;font-size:9.5px;margin-bottom:2px}
@media(max-width:900px){.stats-grid{grid-template-columns:1fr 1fr}.cal-cell{min-height:60px;font-size:10px}}
@media(max-width:600px){.stats-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-home"></i> My Dashboard</h1>
    <p><?= htmlspecialchars($portal_company) ?> &nbsp;·&nbsp; <?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= htmlspecialchars($portal_full_name) ?> &middot; <?= htmlspecialchars($employee['position'] ?? ucfirst($staff_type)) ?></p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon <?= $todayAttendance && $todayAttendance['time_in'] ? 'si-green' : 'si-orange' ?>"><i class="fas fa-qrcode"></i></div>
        <div class="stat-info">
            <h3><?= $todayAttendance && $todayAttendance['time_in'] ? ($todayAttendance['time_out'] ? 'Timed Out' : 'Timed In') : 'Not Yet' ?></h3>
            <p>Today's Attendance</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-purple"><i class="fas fa-file-alt"></i></div>
        <div class="stat-info"><h3><?= $pendingLeaveCount ?></h3><p>Pending Leave Requests</p></div>
    </div>
    <?php if ($is_field): ?>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-people-carry-box"></i></div>
        <div class="stat-info"><h3><?= $upcomingCount ?></h3><p>Upcoming Assigned Services</p></div>
    </div>
    <?php endif; ?>
</div>

<div class="quick-links">
    <a href="timekeeping.php" class="ql-card"><i class="fas fa-qrcode"></i> Time In / Out</a>
    <a href="my-payslips.php" class="ql-card"><i class="fas fa-money-check-alt"></i> My Salary</a>
    <a href="my-leave-requests.php" class="ql-card"><i class="fas fa-file-alt"></i> Leave Requests</a>
    <?php if ($is_field): ?>
    <a href="my-services.php" class="ql-card"><i class="fas fa-people-carry-box"></i> My Assigned Services</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar-alt"></i> <?= $is_field ? 'My Services Calendar' : 'My Calendar' ?></h2>
        <div class="month-nav">
            <a href="?month=<?= $prevMonth ?>"><i class="fas fa-chevron-left"></i></a>
            <strong><?= date('F Y', strtotime($monthStart)) ?></strong>
            <a href="?month=<?= $nextMonth ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php if ($is_field): ?>
    <div style="padding:8px 20px 0;font-size:11px;color:var(--muted);">
        <span style="border:1px dashed var(--muted);border-radius:4px;padding:1px 5px;">dashed, *</span>
        = tentative (default assignment, not yet confirmed via Prepare Booking)
    </div>
    <?php endif; ?>
    <div class="cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
        <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
        <div class="cal-cell empty"></div>
        <?php endfor; ?>

        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr = sprintf('%s-%02d', substr($monthStart, 0, 7), $d);
            $isToday = ($dateStr === $today);
        ?>
        <div class="cal-cell <?= $isToday ? 'today' : '' ?>">
            <div class="cal-daynum"><?= $d ?></div>
            <?php foreach ($servicesByDay[$dateStr] ?? [] as $svc):
                $color = $statusColors[$svc['status']] ?? '#475569';
                $isTentative = empty($svc['assigned_employee_id']);
                $tooltip = $svc['service_name'] . ' — ' . $svc['full_name'] . ' @ ' . date('g:i A', strtotime($svc['preferred_time']))
                    . ($isTentative ? ' (tentative — not yet confirmed via Prepare Booking)' : '');
            ?>
            <div class="cal-item cal-item-clickable" style="background:<?= $color ?>1a;color:<?= $color ?><?= $isTentative ? ';border:1px dashed ' . $color : '' ?>" title="<?= htmlspecialchars($tooltip) ?>"
                data-id="<?= (int)$svc['id'] ?>"
                data-service="<?= htmlspecialchars($svc['service_name'] ?: 'Service') ?>"
                data-client="<?= htmlspecialchars($svc['full_name']) ?>"
                data-contact="<?= htmlspecialchars($svc['contact_number']) ?>"
                data-date="<?= htmlspecialchars(date('M j, Y', strtotime($svc['preferred_date']))) ?>"
                data-time="<?= htmlspecialchars(date('g:i A', strtotime($svc['preferred_time']))) ?>"
                data-status="<?= htmlspecialchars(ucfirst(str_replace('_',' ',$svc['status']))) ?>"
                data-address="<?= htmlspecialchars($svc['address']) ?>"
                data-notes="<?= htmlspecialchars($svc['operations_notes'] ?? '') ?>"
                data-tentative="<?= $isTentative ? '1' : '0' ?>"
                data-color="<?= htmlspecialchars($color) ?>">
                <?= htmlspecialchars(date('g:iA', strtotime($svc['preferred_time']))) ?> <?= htmlspecialchars($svc['service_name'] ?: 'Service') ?><?= $isTentative ? ' *' : '' ?>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($leaveByDay[$dateStr])): ?>
            <div class="cal-leave"><i class="fas fa-plane"></i> Leave</div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

</div></div></div>

<?php if ($is_field): ?>
<div class="svc-modal-backdrop" id="svcModalBackdrop">
    <div class="svc-modal">
        <div class="svc-modal-head">
            <h3 id="svcModalTitle">Service</h3>
            <button type="button" class="svc-modal-close" id="svcModalClose" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="svc-modal-body">
            <div class="svc-row"><span id="svcModalStatus" class="svc-status-pill"></span> <span id="svcModalTentative" class="svc-tentative-pill" hidden>Tentative</span></div>
            <div class="svc-row"><i class="fas fa-user"></i> <span id="svcModalClient"></span></div>
            <div class="svc-row"><i class="fas fa-phone"></i> <span id="svcModalContact"></span></div>
            <div class="svc-row"><i class="fas fa-calendar"></i> <span id="svcModalDate"></span> at <span id="svcModalTime"></span></div>
            <div class="svc-row"><i class="fas fa-map-marker-alt"></i> <span id="svcModalAddress"></span></div>
            <div class="svc-row svc-notes" id="svcModalNotesRow" hidden><i class="fas fa-note-sticky"></i> <span id="svcModalNotes"></span></div>
            <div class="svc-row svc-tentative-note" id="svcModalTentativeNote" hidden>
                <i class="fas fa-info-circle"></i> This is a tentative assignment from the service's default technician setting — it's not confirmed until the provider runs "Prepare Booking".
            </div>
        </div>
        <div class="svc-modal-foot">
            <a href="my-services.php" class="svc-modal-link">View in My Assigned Services <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
</div>
<style>
.cal-item-clickable{cursor:pointer;transition:transform .1s ease}
.cal-item-clickable:hover{transform:scale(1.03)}
.svc-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;align-items:center;justify-content:center;padding:20px}
.svc-modal-backdrop.open{display:flex}
.svc-modal{background:#fff;border-radius:14px;max-width:420px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden}
.svc-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid var(--border)}
.svc-modal-head h3{font-size:15px;font-weight:800;color:var(--dark)}
.svc-modal-close{background:none;border:none;color:var(--muted);font-size:16px;cursor:pointer;padding:4px}
.svc-modal-body{padding:16px 18px;display:flex;flex-direction:column;gap:10px}
.svc-row{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#475569}
.svc-row i{width:16px;color:var(--muted);margin-top:2px}
.svc-status-pill{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:#f1f5f9;color:#475569}
.svc-tentative-pill{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e}
.svc-tentative-note{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:12px;color:#92400e}
.svc-modal-foot{padding:12px 18px;border-top:1px solid var(--border);text-align:right}
.svc-modal-link{font-size:13px;font-weight:700;color:var(--primary);text-decoration:none}
</style>
<script>
(function() {
    var backdrop = document.getElementById('svcModalBackdrop');
    if (!backdrop) return;
    var closeBtn = document.getElementById('svcModalClose');

    function openModal(el) {
        document.getElementById('svcModalTitle').textContent = '#' + el.dataset.id + ' · ' + el.dataset.service;
        document.getElementById('svcModalStatus').textContent = el.dataset.status;
        document.getElementById('svcModalStatus').style.background = el.dataset.color + '1a';
        document.getElementById('svcModalStatus').style.color = el.dataset.color;
        document.getElementById('svcModalClient').textContent = el.dataset.client;
        document.getElementById('svcModalContact').textContent = el.dataset.contact || 'N/A';
        document.getElementById('svcModalDate').textContent = el.dataset.date;
        document.getElementById('svcModalTime').textContent = el.dataset.time;
        document.getElementById('svcModalAddress').textContent = el.dataset.address;

        var isTentative = el.dataset.tentative === '1';
        document.getElementById('svcModalTentative').hidden = !isTentative;
        document.getElementById('svcModalTentativeNote').hidden = !isTentative;

        var notes = el.dataset.notes || '';
        var notesRow = document.getElementById('svcModalNotesRow');
        if (notes) {
            document.getElementById('svcModalNotes').textContent = notes;
            notesRow.hidden = false;
        } else {
            notesRow.hidden = true;
        }

        backdrop.classList.add('open');
    }

    function closeModal() { backdrop.classList.remove('open'); }

    document.querySelectorAll('.cal-item-clickable').forEach(function(el) {
        el.addEventListener('click', function() { openModal(el); });
    });
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function(e) { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
})();
</script>
<?php endif; ?>
</body></html>
