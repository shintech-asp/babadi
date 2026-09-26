<?php
// includes/payroll_helper.php
// Attendance-based payroll generation, extracted from provider-portal/
// payroll.php's inline 'generate' POST handler so the mobile
// api/v1/portal/hr/payroll/generate.php endpoint shares the exact same
// computation (daily/hourly rate, absent/late deductions, overtime,
// statutory deductions) instead of re-implementing it — see CLAUDE.md's
// "Recent Work Log" for the bug class this avoids.

require_once __DIR__ . '/hr_schedule_helper.php';
require_once __DIR__ . '/../provider-portal/includes/portal-settings.php';

if (!function_exists('computePayrollPeriodBounds')) {
    function computePayrollPeriodBounds(string $month, string $half, int $cutoff1, int $cutoff2): array {
        $daysInMonth = (int)date('t', strtotime($month . '-01'));
        if ($half === '1') {
            return [$month . '-01', sprintf('%s-%02d', $month, min($cutoff1, $daysInMonth))];
        }
        return [sprintf('%s-%02d', $month, min($cutoff1 + 1, $daysInMonth)), sprintf('%s-%02d', $month, min($cutoff2, $daysInMonth))];
    }
}

if (!function_exists('countPayrollExpectedWorkDays')) {
    function countPayrollExpectedWorkDays($schedule, $start, $end, $workingDaysPerMonth) {
        if (!$schedule) {
            $periodDays = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
            return (int)round($workingDaysPerMonth * ($periodDays / 30));
        }
        $count = 0;
        $cur = strtotime($start);
        $stop = strtotime($end);
        while ($cur <= $stop) {
            if (getScheduleForDate($schedule, date('Y-m-d', $cur)) !== null) $count++;
            $cur = strtotime('+1 day', $cur);
        }
        return $count;
    }
}

if (!function_exists('countPayrollApprovedLeaveDays')) {
    function countPayrollApprovedLeaveDays(PDO $db, int $pid, int $empId, string $start, string $end, $schedule): int {
        try {
            $stmt = $db->prepare(
                "SELECT start_date, end_date FROM leave_requests
                 WHERE employee_id=:e AND provider_id=:p AND status='approved'
                   AND start_date <= :end AND end_date >= :start"
            );
            $stmt->execute([':e'=>$empId, ':p'=>$pid, ':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = [];
        }
        $days = [];
        foreach ($rows as $r) {
            $cur  = max(strtotime($r['start_date']), strtotime($start));
            $stop = min(strtotime($r['end_date']), strtotime($end));
            while ($cur <= $stop) {
                $d = date('Y-m-d', $cur);
                if (!$schedule || getScheduleForDate($schedule, $d) !== null) {
                    $days[$d] = true;
                }
                $cur = strtotime('+1 day', $cur);
            }
        }
        return count($days);
    }
}

// SSS / PhilHealth / Pag-IBIG (HDMF) are Philippine government-mandated
// contributions — one legally fixed schedule that applies identically to
// every employer in the country. This app is Philippines-only (see
// CLAUDE.md), so there is no legitimate reason for these to be a
// per-provider setting the way overtime_rate or payroll cutoff days are —
// they were previously stored as provider-configurable flat percentages
// (sss_rate/philhealth_rate/pagibig_rate/pagibig_cap in provider_settings),
// which let two providers legally required to deduct the same amount end
// up computing different numbers. This function is now the ONE shared,
// platform-wide place these are computed, hardcoded rather than
// per-provider, using the most recent official schedules known as of this
// writing. These schedules ARE revised periodically by circular — verify
// against the current official SSS/PhilHealth/Pag-IBIG issuances before
// relying on this for real payroll, and update this one function (not
// individual providers' settings) when they change.
//
// SSS: 2023 Contribution Schedule (SSS Circular No. 2022-033, eff. Jan 2023).
//   Total rate 14% of Monthly Salary Credit (MSC), split 9.5% employer /
//   4.5% employee. MSC floor ₱4,000, ceiling ₱30,000. The official table
//   rounds compensation to MSC in ₱500 steps — approximated here by
//   clamping compensation directly to the floor/ceiling (the ₱500 stepping
//   changes the result by at most a few pesos).
// PhilHealth: 2024 premium rate (Universal Health Care Act phased schedule).
//   Total premium 5% of monthly basic salary, split 50/50 (2.5% each).
//   Salary floor ₱10,000, ceiling ₱100,000.
// Pag-IBIG/HDMF: HDMF Circular No. 460, eff. Feb 2024. Employee rate 1% if
//   monthly compensation ≤ ₱1,500, else 2%; employer always 2%. Maximum
//   monthly compensation used for computation is ₱10,000.
if (!function_exists('computeStatutoryDeductions')) {
    function computeStatutoryDeductions(float $grossSalary): array {
        $sssMsc = min(max($grossSalary, 4000), 30000);
        $sssEmployee = round($sssMsc * 0.045, 2);

        $philBase = min(max($grossSalary, 10000), 100000);
        $philEmployee = round($philBase * 0.025, 2);

        $pagibigBase = min($grossSalary, 10000);
        $pagibigRate = $grossSalary <= 1500 ? 0.01 : 0.02;
        $pagibigEmployee = round($pagibigBase * $pagibigRate, 2);

        return [
            'sss'        => $sssEmployee,
            'philhealth' => $philEmployee,
            'pagibig'    => $pagibigEmployee,
        ];
    }
}

// Bulk-generates payroll rows for every active employee for one pay period,
// computed from real attendance/timekeeping/leave data. Returns
// ['generated'=>int, 'skipped'=>int, 'period_name'=>string, 'period_start'=>string, 'period_end'=>string].
if (!function_exists('generatePayrollForPeriod')) {
    function generatePayrollForPeriod(PDO $db, int $pid, string $month, string $half): array {
        // SSS/PhilHealth/Pag-IBIG are computed by computeStatutoryDeductions()
        // below — one shared, platform-wide table, not a per-provider setting.
        $ot_multiplier  = (float)getSetting($db, $pid, 'overtime_rate', '1.25');
        $working_days_per_month = max(1, (int)getSetting($db, $pid, 'working_days_per_month', '22'));
        $cutoff1        = max(1, min(28, (int)getSetting($db, $pid, 'payroll_cutoff_1', '15')));
        $cutoff2        = max($cutoff1 + 1, (int)getSetting($db, $pid, 'payroll_cutoff_2', '30'));

        $schedule = getDefaultWorkSchedule($db, $pid);

        [$periodStart, $periodEnd] = computePayrollPeriodBounds($month, $half, $cutoff1, $cutoff2);
        $periodName = date('M j', strtotime($periodStart)) . ' – ' . date('M j, Y', strtotime($periodEnd));

        $empStmt = $db->prepare("SELECT * FROM employees WHERE provider_id=:p AND status='active'");
        $empStmt->execute([':p' => $pid]);
        $activeEmployees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

        $generated = 0; $skipped = 0;

        foreach ($activeEmployees as $emp) {
            $chkStmt = $db->prepare(
                "SELECT COUNT(*) FROM payroll WHERE employee_id=:e AND provider_id=:p AND pay_period_start=:s AND pay_period_end=:en"
            );
            $chkStmt->execute([':e'=>$emp['id'], ':p'=>$pid, ':s'=>$periodStart, ':en'=>$periodEnd]);
            if ((int)$chkStmt->fetchColumn() > 0) { $skipped++; continue; }

            $basic = (float)$emp['basic_salary'];
            $dailyRate  = $working_days_per_month > 0 ? $basic / $working_days_per_month : 0;
            $hourlyRate = $dailyRate / 8;

            $expectedDays = countPayrollExpectedWorkDays($schedule, $periodStart, $periodEnd, $working_days_per_month);

            $dwStmt = $db->prepare(
                "SELECT COUNT(DISTINCT work_date) FROM timekeeping WHERE employee_id=:e AND provider_id=:p AND work_date BETWEEN :s AND :en AND time_in IS NOT NULL"
            );
            $dwStmt->execute([':e'=>$emp['id'], ':p'=>$pid, ':s'=>$periodStart, ':en'=>$periodEnd]);
            $daysWorked = (int)$dwStmt->fetchColumn();

            $daysLeave  = countPayrollApprovedLeaveDays($db, $pid, (int)$emp['id'], $periodStart, $periodEnd, $schedule);
            $daysAbsent = max(0, $expectedDays - $daysWorked - $daysLeave);

            $lateStmt = $db->prepare(
                "SELECT COALESCE(SUM(late_minutes),0) AS t FROM timekeeping WHERE employee_id=:e AND provider_id=:p AND work_date BETWEEN :s AND :en"
            );
            $lateStmt->execute([':e'=>$emp['id'], ':p'=>$pid, ':s'=>$periodStart, ':en'=>$periodEnd]);
            $lateMinutes = (float)$lateStmt->fetchColumn();

            $otStmt = $db->prepare(
                "SELECT COALESCE(SUM(overtime_hours),0) AS t FROM timekeeping WHERE employee_id=:e AND provider_id=:p AND work_date BETWEEN :s AND :en"
            );
            $otStmt->execute([':e'=>$emp['id'], ':p'=>$pid, ':s'=>$periodStart, ':en'=>$periodEnd]);
            $overtimeHours = (float)$otStmt->fetchColumn();

            $grossSalary    = round($dailyRate * $expectedDays, 2);
            $absentDeduct   = round($dailyRate * $daysAbsent, 2);
            $lateDeduct     = round(($hourlyRate / 60) * $lateMinutes, 2);
            $overtimePay    = round($hourlyRate * $ot_multiplier * $overtimeHours, 2);

            $statutory = computeStatutoryDeductions($grossSalary);
            $sssE  = $statutory['sss'];
            $philE = $statutory['philhealth'];
            $pagE  = $statutory['pagibig'];
            $tax = 0;
            if ($grossSalary > 33332) $tax = ($grossSalary - 33332) * 0.20;
            elseif ($grossSalary > 8333) $tax = ($grossSalary - 8333) * 0.15;
            $tax = round($tax, 2);
            $statutoryDeductions = $sssE + $philE + $pagE + $tax;

            $netSalary = round($grossSalary - $absentDeduct - $lateDeduct - $statutoryDeductions + $overtimePay, 2);

            try {
                $db->prepare(
                    "INSERT INTO payroll
                        (provider_id, employee_id, pay_period_name, pay_period_start, pay_period_end,
                         basic_salary, gross_salary, sss_employee, philhealth_employee, pagibig_employee,
                         withholding_tax, deductions, absent_deduction, late_deduction, days_worked,
                         days_absent, overtime_pay, net_salary, status)
                     VALUES
                        (:pid, :eid, :pn, :ps, :pe,
                         :bs, :gs, :sse, :phe, :pae,
                         :wt, :ded, :ad, :ld, :dw,
                         :da, :ot, :net, 'pending')"
                )->execute([
                    ':pid'=>$pid, ':eid'=>$emp['id'], ':pn'=>$periodName, ':ps'=>$periodStart, ':pe'=>$periodEnd,
                    ':bs'=>$basic, ':gs'=>$grossSalary, ':sse'=>$sssE, ':phe'=>$philE, ':pae'=>$pagE,
                    ':wt'=>$tax, ':ded'=>$statutoryDeductions, ':ad'=>$absentDeduct, ':ld'=>$lateDeduct, ':dw'=>$daysWorked,
                    ':da'=>$daysAbsent, ':ot'=>$overtimePay, ':net'=>$netSalary,
                ]);
                $generated++;
            } catch (Exception $ex) { /* skip this employee, continue the batch */ }
        }

        return [
            'generated'    => $generated,
            'skipped'      => $skipped,
            'period_name'  => $periodName,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ];
    }
}
