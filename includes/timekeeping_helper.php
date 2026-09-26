<?php
// includes/timekeeping_helper.php
// Shared self time-in/time-out logic for employee self-service, extracted
// from provider-portal/timekeeping.php's inline POST handlers so the
// mobile api/v1/portal/me/attendance endpoints share the exact same
// business logic (late/overtime computation, the writes to both the
// `timekeeping` and `attendance` tables, the +/-2h working-window gate)
// instead of re-implementing it and risking drift — see CLAUDE.md's
// "Recent Work Log" for the bug class this avoids.

require_once __DIR__ . '/hr_schedule_helper.php';

if (!function_exists('calcTimekeepingTimings')) {
    function calcTimekeepingTimings($time_in, $time_out, $sched) {
        $r = ['late_min'=>0,'undertime_min'=>0,'overtime_min'=>0,'hours_worked'=>0,'regular_hours'=>0,'overtime_hours'=>0,'status'=>'present'];
        if (!$time_in) return $r;
        $ti_ts = strtotime($time_in);
        $to_ts = $time_out ? strtotime($time_out) : null;
        if ($sched) {
            $ss   = strtotime($sched['start']);
            $se   = strtotime($sched['end']);
            $grace= $sched['grace'] * 60;
            $sh   = ($se - $ss) / 3600;
            if ($ti_ts > $ss + $grace) { $r['late_min'] = (int)(($ti_ts - $ss)/60); $r['status'] = 'late'; }
            if ($to_ts) {
                $h = max(0, ($to_ts - $ti_ts) / 3600);
                $r['hours_worked'] = round($h, 2);
                if ($to_ts < $se) $r['undertime_min'] = (int)(($se - $to_ts)/60);
                if ($to_ts > $se) { $r['overtime_min'] = (int)(($to_ts-$se)/60); $r['overtime_hours'] = round(($to_ts-$se)/3600,2); }
                $r['regular_hours'] = round(min($h, $sh), 2);
            }
        } else {
            if ($to_ts) {
                $h = max(0, ($to_ts - $ti_ts)/3600);
                $r['hours_worked']   = round($h,2);
                $r['overtime_hours'] = round(max(0,$h-8),2);
                $r['regular_hours']  = round(min($h,8),2);
                if ($r['overtime_hours']>0) $r['overtime_min']=(int)($r['overtime_hours']*60);
            }
        }
        if ($r['late_min']>0 && $r['undertime_min']>0) $r['status']='half_day';
        return $r;
    }
}

// Returns ['ok'=>bool, 'error'=>?string, 'time_in'=>?string, 'late_min'=>?int, 'status'=>?string]
if (!function_exists('selfClockIn')) {
    function selfClockIn(PDO $db, int $pid, int $empId): array {
        $today = date('Y-m-d');
        $now   = date('H:i:s');

        $stmt = $db->prepare("SELECT id, time_in FROM timekeeping WHERE provider_id=:p AND employee_id=:e AND work_date=:d");
        $stmt->execute([':p'=>$pid, ':e'=>$empId, ':d'=>$today]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['time_in']) {
            return ['ok' => false, 'error' => 'You already timed in today at ' . date('h:i A', strtotime($existing['time_in'])) . '.'];
        }

        $schedule = getDefaultWorkSchedule($db, $pid);
        $sched = getScheduleForDate($schedule, $today);

        if ($sched) {
            $now_ts       = strtotime($now);
            $sched_start  = strtotime($sched['start']);
            $sched_end    = strtotime($sched['end']);
            $two_hrs_before = $sched_start - (2 * 3600);
            $two_hrs_after  = $sched_end   + (2 * 3600);
            if ($now_ts < $two_hrs_before || $now_ts > $two_hrs_after) {
                return ['ok' => false, 'error' => 'Time-in is only allowed between ' . date('h:i A', $two_hrs_before) . ' and ' . date('h:i A', $two_hrs_after) . ' (2 hours before/after your shift).'];
            }
        }

        $late = 0; $status = 'present';
        if ($sched) {
            $ss = strtotime($sched['start']);
            $grace = $sched['grace'] * 60;
            if (strtotime($now) > $ss + $grace) { $late = (int)((strtotime($now)-$ss)/60); $status = 'late'; }
        }

        try {
            $db->prepare("INSERT INTO timekeeping (provider_id,employee_id,work_date,time_in,late_minutes,notes,created_at)
                          VALUES (:p,:e,:d,:ti,:late,'Self time-in',NOW())
                          ON DUPLICATE KEY UPDATE time_in=:ti, late_minutes=:late")
               ->execute([':p'=>$pid,':e'=>$empId,':d'=>$today,':ti'=>$now,':late'=>$late]);
            $db->prepare("INSERT INTO attendance (provider_id,employee_id,date,time_in,late_minutes,status,time_in_mode,created_at)
                          VALUES (:p,:e,:d,:ti,:late,:st,'system',NOW())
                          ON DUPLICATE KEY UPDATE time_in=:ti, late_minutes=:late, status=:st")
               ->execute([':p'=>$pid,':e'=>$empId,':d'=>$today,':ti'=>$now,':late'=>$late,':st'=>$status]);
        } catch (Exception $ex) {
            return ['ok' => false, 'error' => $ex->getMessage()];
        }

        return ['ok' => true, 'time_in' => $now, 'late_min' => $late, 'status' => $status];
    }
}

// Returns ['ok'=>bool, 'error'=>?string, 'time_out'=>?string, ...timings]
if (!function_exists('selfClockOut')) {
    function selfClockOut(PDO $db, int $pid, int $empId): array {
        $today = date('Y-m-d');
        $now   = date('H:i:s');

        $stmt = $db->prepare("SELECT id, time_in, time_out FROM timekeeping WHERE provider_id=:p AND employee_id=:e AND work_date=:d");
        $stmt->execute([':p'=>$pid, ':e'=>$empId, ':d'=>$today]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing || !$existing['time_in']) {
            return ['ok' => false, 'error' => "You haven't timed in yet today."];
        }
        if ($existing['time_out']) {
            return ['ok' => false, 'error' => 'You already timed out today at ' . date('h:i A', strtotime($existing['time_out'])) . '.'];
        }

        $schedule = getDefaultWorkSchedule($db, $pid);
        $sched = getScheduleForDate($schedule, $today);

        if ($sched) {
            $now_ts      = strtotime($now);
            $sched_end   = strtotime($sched['end']);
            $two_hrs_after = $sched_end + (2 * 3600);
            if ($now_ts > $two_hrs_after) {
                return ['ok' => false, 'error' => 'Time-out window has passed. Please ask your HR manager to log your time manually.'];
            }
        }

        $timings = calcTimekeepingTimings($existing['time_in'], $now, $sched);
        try {
            $db->prepare("UPDATE timekeeping SET time_out=:to, hours_worked=:h, overtime_hours=:ot, regular_hours=:reg
                          WHERE provider_id=:p AND employee_id=:e AND work_date=:d")
               ->execute([':to'=>$now,':h'=>$timings['hours_worked'],':ot'=>$timings['overtime_hours'],':reg'=>$timings['regular_hours'],':p'=>$pid,':e'=>$empId,':d'=>$today]);
            $db->prepare("UPDATE attendance SET time_out=:to, undertime_min=:ut, overtime_min=:otm, total_hours=:h, status=:st
                          WHERE provider_id=:p AND employee_id=:e AND date=:d")
               ->execute([':to'=>$now,':ut'=>$timings['undertime_min'],':otm'=>$timings['overtime_min'],':h'=>$timings['hours_worked'],':st'=>$timings['status'],':p'=>$pid,':e'=>$empId,':d'=>$today]);
        } catch (Exception $ex) {
            return ['ok' => false, 'error' => $ex->getMessage()];
        }

        return array_merge(['ok' => true, 'time_out' => $now, 'time_in' => $existing['time_in']], $timings);
    }
}
