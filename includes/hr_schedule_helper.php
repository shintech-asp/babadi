<?php
// includes/hr_schedule_helper.php
// Shared by provider-portal/timekeeping.php, provider-portal/payroll.php,
// and the mobile api/v1/portal/me + hr/payroll endpoints — previously each
// page declared its own identical copy of getScheduleForDate(), which is
// exactly the class of drift bug this codebase has been bitten by before
// (see CLAUDE.md's "Recent Work Log"). Single source of truth now.

if (!function_exists('getScheduleForDate')) {
    function getScheduleForDate($schedule, $date) {
        if (!$schedule) return null;
        $map = ['Sun'=>'sun','Mon'=>'mon','Tue'=>'tue','Wed'=>'wed','Thu'=>'thu','Fri'=>'fri','Sat'=>'sat'];
        $key = $map[date('D', strtotime($date))] ?? null;
        if (!$key) return null;
        $start = $schedule[$key.'_start'] ?? null;
        $end   = $schedule[$key.'_end']   ?? null;
        if (!$start || !$end) return null;
        return ['start' => $start, 'end' => $end, 'grace' => (int)($schedule['grace_period'] ?? 15)];
    }
}

if (!function_exists('getDefaultWorkSchedule')) {
    function getDefaultWorkSchedule(PDO $db, int $providerId) {
        try {
            $stmt = $db->prepare(
                "SELECT * FROM hr_work_schedules WHERE (provider_id=:p OR provider_id IS NULL) AND is_default=1 ORDER BY provider_id DESC LIMIT 1"
            );
            $stmt->execute([':p' => $providerId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}
