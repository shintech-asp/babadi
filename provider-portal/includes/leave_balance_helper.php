<?php
// provider-portal/includes/leave_balance_helper.php
//
// Wires up hr_leave_balances (previously an unused table) to the
// "Leave Credits Per Year" settings. Each employee gets the company-wide
// default from Settings > HR unless HR has set a per-employee override for
// that specific employee — set via the "Per-Employee Leave Override" card
// on settings.php, which writes directly into hr_leave_balances.

require_once __DIR__ . '/portal-settings.php';

function getLeaveDefaultAllowance($db, $pid, string $leaveType): float {
    $defaults = ['annual' => 15, 'sick' => 15, 'personal' => 5, 'maternity' => 105, 'paternity' => 7];
    return (float)getSetting($db, $pid, $leaveType . '_leave_days', (string)($defaults[$leaveType] ?? 0));
}

// Returns ['total'=>, 'used'=>, 'remaining'=>, 'has_override'=>bool] for one
// employee/leave-type/year. Falls back to the company-wide default when no
// hr_leave_balances row exists yet for this employee (i.e. no override and
// no usage recorded so far).
function getLeaveBalance($db, $pid, int $empId, string $leaveType, $year): array {
    $default = getLeaveDefaultAllowance($db, $pid, $leaveType);
    $row = null;
    try {
        $stmt = $db->prepare(
            "SELECT total_days, used_days FROM hr_leave_balances WHERE employee_id=:e AND leave_type=:t AND year=:y LIMIT 1"
        );
        $stmt->execute([':e' => $empId, ':t' => $leaveType, ':y' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $total = $row ? (float)$row['total_days'] : $default;
    $used  = $row ? (float)$row['used_days']  : 0.0;
    return [
        'total'        => $total,
        'used'         => $used,
        'remaining'    => max(0, $total - $used),
        'has_override' => ($row !== null),
    ];
}

// Simple inclusive calendar-day count — matches how leave-requests.php
// already displays "Days" for a leave request.
function countLeaveCalendarDays(string $start, string $end): int {
    return (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
}

// Records that $days of leave were consumed, creating the balance row
// (seeded with the current default allowance) if none exists yet.
function deductLeaveBalance($db, $pid, int $empId, string $leaveType, $year, float $days): void {
    $default = getLeaveDefaultAllowance($db, $pid, $leaveType);
    try {
        $db->prepare(
            "INSERT INTO hr_leave_balances (employee_id, leave_type, year, total_days, used_days)
             VALUES (:e, :t, :y, :tot, :d1)
             ON DUPLICATE KEY UPDATE used_days = used_days + :d2"
        )->execute([':e' => $empId, ':t' => $leaveType, ':y' => $year, ':tot' => $default, ':d1' => $days, ':d2' => $days]);
    } catch (Exception $e) {}
}

// Sets (overrides) the total allowance for one employee/leave-type/year.
function setLeaveBalanceOverride($db, int $empId, string $leaveType, $year, float $totalDays): void {
    try {
        $db->prepare(
            "INSERT INTO hr_leave_balances (employee_id, leave_type, year, total_days, used_days)
             VALUES (:e, :t, :y, :tot1, 0)
             ON DUPLICATE KEY UPDATE total_days = :tot2"
        )->execute([':e' => $empId, ':t' => $leaveType, ':y' => $year, ':tot1' => $totalDays, ':tot2' => $totalDays]);
    } catch (Exception $e) {}
}
