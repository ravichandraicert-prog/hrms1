<?php
/**
 * Attendance Punch Logging Class
 * Core PHP HRMS Application
 * Section 2 - The shift-crossing-midnight attendance problem solution
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/attendance_threshold_service.php';

class Attendance {
    private $db;
    private $thresholdService;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->thresholdService = new AttendanceThresholdService();
    }
    
    /**
     * Record a manual punch at a specific time (for bulk mark attendance)
     * Skips sequence validation and attendance_date calculation
     */
    public function recordManualPunch($employeeId, $eventType, $timestamp, $attendanceDate, $source = 'manual') {
        // Insert punch record
        $punchData = [
            'employee_id' => $employeeId,
            'event_type' => $eventType,
            'timestamp_utc' => $timestamp,
            'source' => $source,
            'attendance_date' => $attendanceDate,
            'is_review_flagged' => 0
        ];
        
        $this->db->insert('attendance_punches', $punchData);
        
        return ['success' => true];
    }
    
    /**
     * Record a punch with attendance_date calculation per Section 2.4 algorithm
     */
    public function recordPunch($employeeId, $eventType, $breakTypeId = null, $source = 'web', $geolocation = null, $reason = null, $punchTimestampUtc = null) {
        // Get the punch timestamp in UTC. Defaults to now; a caller-supplied
        // timestamp is used ONLY by the shutdown safety-net sweep to clock an
        // employee out at their last known "alive" moment. The rest of the
        // pipeline (shift-based attendance_date, recompute, audit) is unchanged.
        $timestampUtc = $punchTimestampUtc
            ?: (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $now = new DateTime($timestampUtc, new DateTimeZone('UTC'));
        
        // Get employee's current shift
        $employee = $this->db->fetchOne(
            "SELECT e.shift_id, s.* FROM employees e LEFT JOIN shifts s ON e.shift_id = s.id WHERE e.id = ?",
            [$employeeId]
        );
        
        if (!$employee || !$employee['shift_id']) {
            return ['success' => false, 'message' => 'Employee or shift not found'];
        }
        
        $shift = $employee;
        
        // ---- Validate the punch sequence (prevent duplicate clock-ins / ghost clock-outs) ----
        // This stops the attendance_punches log from being polluted with repeated CLOCK_IN rows
        // when the user clicks the clock widget multiple times.
        if ($eventType === 'CLOCK_IN' || $eventType === 'CLOCK_OUT') {
            $current = $this->getCurrentStatus($employeeId);
            if ($eventType === 'CLOCK_IN' && $current['status'] === 'in') {
                return ['success' => false, 'message' => 'You are already clocked in.'];
            }
            if ($eventType === 'CLOCK_OUT' && $current['status'] === 'out') {
                return ['success' => false, 'message' => 'You are not currently clocked in.'];
            }
        }
        
        // Validate break punches
        if ($eventType === 'BREAK_START') {
            $current = $this->getCurrentStatus($employeeId);
            if ($current['status'] === 'out') {
                return ['success' => false, 'message' => 'You must be clocked in to start a break.'];
            }
            if ($current['on_break']) {
                return ['success' => false, 'message' => 'You are already on a break.'];
            }
        }
        
        if ($eventType === 'BREAK_END') {
            $current = $this->getCurrentStatus($employeeId);
            if (!$current['on_break']) {
                return ['success' => false, 'message' => 'You are not currently on a break.'];
            }
        }
        
        // Calculate attendance_date per Section 2.4 algorithm
        // Pass the DateTime object directly to preserve timezone info
        $attendanceDate = $this->calculateAttendanceDate($now, $shift);
        
        // Check if punch is outside expected windows
        $isReviewFlagged = $this->isOutsideExpectedWindow($now, $shift, $attendanceDate);
        
        // Insert punch record (immutable per P5)
        $punchData = [
            'employee_id' => $employeeId,
            'event_type' => $eventType,
            'break_type_id' => $breakTypeId,
            'reason' => $reason,
            'timestamp_utc' => $timestampUtc,
            'source' => $source,
            'geolocation' => $geolocation,
            'attendance_date' => $attendanceDate,
            'is_review_flagged' => $isReviewFlagged ? 1 : 0
        ];
        
        $punchId = $this->db->insert('attendance_punches', $punchData);
        
        // Recompute attendance_daily aggregate
        $this->recomputeAttendanceDaily($employeeId, $attendanceDate, $shift['shift_id']);
        
        // Log audit
        logAudit('attendance_punches', $punchId, 'create', null, $punchData);
        
        return [
            'success' => true,
            'punch_id' => $punchId,
            'attendance_date' => $attendanceDate,
            'is_review_flagged' => $isReviewFlagged
        ];
    }
    
    /**
     * Calculate attendance_date per Section 2.4 algorithm
     */
    private function calculateAttendanceDate($timestamp, $shift) {
        // Convert UTC timestamp to local timezone for date calculation
        $timestamp->setTimezone(new DateTimeZone(TIMEZONE));
        
        $calendarDate = $timestamp->format('Y-m-d');
        $previousDay = date('Y-m-d', strtotime('-1 day', strtotime($calendarDate)));
        $nextDay = date('Y-m-d', strtotime('+1 day', strtotime($calendarDate)));
        
        $graceInMinutes = $shift['grace_in_minutes'] ?? 120;
        $graceOutMinutes = $shift['grace_out_minutes'] ?? 180;
        
        // For shifts that span midnight, we need to check three potential windows:
        // 1. Previous day's shift (might still be running)
        // 2. Current day's shift
        // 3. Next day's shift (for very early clock-ins)
        
        // Build Previous day window
        $prevDayStart = new DateTime($previousDay . ' ' . $shift['start_time'], new DateTimeZone(TIMEZONE));
        $prevDayStart->sub(new DateInterval('PT' . $graceInMinutes . 'M'));
        
        $prevDayEnd = new DateTime($previousDay . ' ' . $shift['end_time'], new DateTimeZone(TIMEZONE));
        if ($shift['spans_midnight']) {
            $prevDayEnd->modify('+1 day');
        }
        $prevDayEnd->add(new DateInterval('PT' . $graceOutMinutes . 'M'));
        
        // Build Current day window
        $currDayStart = new DateTime($calendarDate . ' ' . $shift['start_time'], new DateTimeZone(TIMEZONE));
        $currDayStart->sub(new DateInterval('PT' . $graceInMinutes . 'M'));
        
        $currDayEnd = new DateTime($calendarDate . ' ' . $shift['end_time'], new DateTimeZone(TIMEZONE));
        if ($shift['spans_midnight']) {
            $currDayEnd->modify('+1 day');
        }
        $currDayEnd->add(new DateInterval('PT' . $graceOutMinutes . 'M'));
        
        // Build Next day window (for early clock-ins before shift starts)
        $nextDayStart = new DateTime($nextDay . ' ' . $shift['start_time'], new DateTimeZone(TIMEZONE));
        $nextDayStart->sub(new DateInterval('PT' . $graceInMinutes . 'M'));
        
        $nextDayEnd = new DateTime($nextDay . ' ' . $shift['end_time'], new DateTimeZone(TIMEZONE));
        if ($shift['spans_midnight']) {
            $nextDayEnd->modify('+1 day');
        }
        $nextDayEnd->add(new DateInterval('PT' . $graceOutMinutes . 'M'));
        
        // Check which window the timestamp falls into
        // Priority: previous day (for late clock-outs), current day, next day
        if ($timestamp >= $prevDayStart && $timestamp <= $prevDayEnd) {
            return $previousDay;
        } elseif ($timestamp >= $currDayStart && $timestamp <= $currDayEnd) {
            return $calendarDate;
        } elseif ($timestamp >= $nextDayStart && $timestamp <= $nextDayEnd) {
            return $nextDay;
        } else {
            // Outside expected windows - use calendar date and flag for review
            return $calendarDate;
        }
    }
    
    /**
     * Check if punch is outside expected windows
     */
    private function isOutsideExpectedWindow($timestamp, $shift, $attendanceDate) {
        // Convert to local timezone if needed
        if ($timestamp->getTimezone()->getName() !== TIMEZONE) {
            $timestamp->setTimezone(new DateTimeZone(TIMEZONE));
        }
        
        $graceInMinutes = $shift['grace_in_minutes'] ?? 120;
        $graceOutMinutes = $shift['grace_out_minutes'] ?? 180;
        
        // Build window for the calculated attendance_date
        $windowStart = new DateTime($attendanceDate . ' ' . $shift['start_time'], new DateTimeZone(TIMEZONE));
        $windowStart->sub(new DateInterval('PT' . $graceInMinutes . 'M'));
        
        $windowEnd = new DateTime($attendanceDate . ' ' . $shift['end_time'], new DateTimeZone(TIMEZONE));
        if ($shift['spans_midnight']) {
            $windowEnd->modify('+1 day');
        }
        $windowEnd->add(new DateInterval('PT' . $graceOutMinutes . 'M'));
        
        return !($timestamp >= $windowStart && $timestamp <= $windowEnd);
    }
    
    /**
     * Recompute attendance_daily aggregate per Section 2.4 step 6
     */
    public function recomputeAttendanceDaily($employeeId, $attendanceDate, $shiftId) {
        // Resolve a concrete shift id (attendance_daily.shift_id is NOT NULL).
        // Fall back to the employee's default shift, then to the first active shift.
        if (!$shiftId) {
            $emp = $this->db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$employeeId]);
            $shiftId = $emp ? $emp['shift_id'] : null;
        }
        if (!$shiftId) {
            $default = $this->db->fetchOne("SELECT id FROM shifts WHERE is_active = 1 ORDER BY id LIMIT 1");
            $shiftId = $default ? (int) $default['id'] : null;
        }
        if (!$shiftId) {
            return; // no shift available to reference
        }

        // Get all punches for this employee on this attendance_date
        // Order by id ASC to keep same-second in/out events in insertion order
        // Include deduct_from_work_time from break_types to respect break type settings
        $punches = $this->db->fetchAll(
            "SELECT ap.*, bt.deduct_from_work_time 
             FROM attendance_punches ap
             LEFT JOIN break_types bt ON ap.break_type_id = bt.id
             WHERE ap.employee_id = ? AND ap.attendance_date = ? 
             ORDER BY ap.timestamp_utc ASC, ap.id ASC",
            [$employeeId, $attendanceDate]
        );
        
        $shift = $this->db->fetchOne("SELECT * FROM shifts WHERE id = ?", [$shiftId]);
        
        // Calculate aggregates
        $firstIn = null;
        $lastOut = null;
        $totalWorkedMinutes = 0;
        $totalBreakMinutes = 0;
        $sessionCount = 0;
        
        $clockInTime = null;
        $breakStartTime = null;
        $currentBreakTypeId = null;
        
        if (!empty($punches)) {
            foreach ($punches as $punch) {
                if ($punch['event_type'] == 'CLOCK_IN') {
                    if ($clockInTime === null) {
                        $clockInTime = $this->utcDateTime($punch['timestamp_utc']);
                        if ($firstIn === null) {
                            $firstIn = $punch['timestamp_utc'];
                        }
                    } else {
                        // Multiple clock-ins - treat as new session
                        if ($clockInTime) {
                            $duration = $this->calculateDuration($clockInTime, $this->utcDateTime($punch['timestamp_utc']));
                            $totalWorkedMinutes += $duration;
                            $sessionCount++;
                        }
                        $clockInTime = $this->utcDateTime($punch['timestamp_utc']);
                    }
                } elseif ($punch['event_type'] == 'CLOCK_OUT' && $clockInTime !== null) {
                    $clockOutTime = $this->utcDateTime($punch['timestamp_utc']);
                    $duration = $this->calculateDuration($clockInTime, $clockOutTime);
                    // If the employee clocked out during an open break (BREAK_START with no
                    // BREAK_END), the open break is terminated by the clock-out.
                    // Only deduct from work time if the break type has deduct_from_work_time = 1
                    if ($breakStartTime !== null) {
                        $openBreakDuration = $this->calculateDuration($breakStartTime, $clockOutTime);
                        $duration = max(0, $duration - $openBreakDuration);
                        
                        // Check if this break type should deduct from work time
                        $shouldDeduct = true;
                        if ($currentBreakTypeId) {
                            $breakType = $this->db->fetchOne(
                                "SELECT deduct_from_work_time FROM break_types WHERE id = ?",
                                [$currentBreakTypeId]
                            );
                            $shouldDeduct = ($breakType['deduct_from_work_time'] ?? 1) == 1;
                        }
                        
                        if ($shouldDeduct) {
                            $totalBreakMinutes += $openBreakDuration;
                        }
                        $breakStartTime = null;
                        $currentBreakTypeId = null;
                    }
                    $totalWorkedMinutes += $duration;
                    $lastOut = $punch['timestamp_utc'];
                    $sessionCount++;
                    $clockInTime = null;
                } elseif ($punch['event_type'] == 'BREAK_START' && $clockInTime !== null) {
                    $breakStartTime = $this->utcDateTime($punch['timestamp_utc']);
                    $currentBreakTypeId = $punch['break_type_id'];
                } elseif ($punch['event_type'] == 'BREAK_END' && isset($breakStartTime)) {
                    $breakEndTime = $this->utcDateTime($punch['timestamp_utc']);
                    $breakDuration = $this->calculateDuration($breakStartTime, $breakEndTime);
                    
                    // Only deduct break time if the break type has deduct_from_work_time = 1
                    // Default to 1 (deduct) if the setting is not available for backward compatibility
                    $shouldDeduct = ($punch['deduct_from_work_time'] ?? 1) == 1;
                    if ($shouldDeduct) {
                        $totalBreakMinutes += $breakDuration;
                    }

                    $breakStartTime = null;
                }
            }
            
            // If still clocked in, the currently-open session is intentionally NOT baked into
            // the stored totals (total_worked_minutes / net_worked_minutes). The frontend clock
            // widget adds the live elapsed session on top of this completed-only base, so storing
            // the open session here would count it twice after every page reload/punch.
            // last_out must remain NULL while a session is open.
            if ($clockInTime !== null) {
                $lastOut = null;
            }
        }
        
        // Aggregate idle minutes logged for this attendance date (idle_periods).
        // Idle time is only ever recorded while the employee is "currently in"
        // (clocked in and not on a break), so it is safe to deduct from Net Time.
        $totalIdleMinutes = 0;
        try {
            $idleAgg = $this->db->fetchOne(
                "SELECT COALESCE(SUM(idle_minutes), 0) as total
                 FROM idle_periods
                 WHERE employee_id = ? AND attendance_date = ?",
                [$employeeId, $attendanceDate]
            );
            if ($idleAgg) {
                $totalIdleMinutes = (int) $idleAgg['total'];
            }
        } catch (Exception $e) {
            // idle_periods table may not exist yet on very old installs; ignore.
            $totalIdleMinutes = 0;
        }

        // Stored Net Time = completed Work Duration - Total Break Duration - Total Idle Time.
        // (The currently-open session is intentionally excluded - the frontend adds it live.)
        $netWorkedMinutes = $totalWorkedMinutes - $totalBreakMinutes - $totalIdleMinutes;
        // Net Time can never go below zero
        if ($netWorkedMinutes < 0) {
            $netWorkedMinutes = 0;
        }

        // Determine status based on the LIVE (current standing) Net Time - completed totals plus
        // the still-open session - so an employee mid-shift is never downgraded merely because
        // the stored completed totals are still 0. While an active break is open, the open session
        // is frozen at BREAK_START so the status/timer stops increasing during the break; breaks
        // are subtracted once they are closed (matching what the clock widget displays).
        $liveNetWorkedMinutes = $netWorkedMinutes;
        if ($clockInTime !== null) {
            $now = new DateTime('now', new DateTimeZone('UTC'));
            if ($breakStartTime !== null) {
                // Active break: freeze the open session at the break start.
                $openSessionWorkMinutes = $this->calculateDuration($clockInTime, $breakStartTime);
            } else {
                $openSessionWorkMinutes = $this->calculateDuration($clockInTime, $now);
            }
            $liveNetWorkedMinutes += $openSessionWorkMinutes;
            if ($liveNetWorkedMinutes < 0) {
                $liveNetWorkedMinutes = 0;
            }
        }
        $status = $this->determineStatus($liveNetWorkedMinutes, $attendanceDate, $employeeId);
        
        // Upsert attendance_daily record
        $attendanceData = [
            'employee_id' => $employeeId,
            'attendance_date' => $attendanceDate,
            'shift_id' => $shiftId,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'total_worked_minutes' => $totalWorkedMinutes,
            'total_break_minutes' => $totalBreakMinutes,
            'idle_minutes' => $totalIdleMinutes,
            'net_worked_minutes' => $netWorkedMinutes,
            'session_count' => $sessionCount,
            'status' => $status
        ];
        
        // Check if record exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM attendance_daily WHERE employee_id = ? AND attendance_date = ?",
            [$employeeId, $attendanceDate]
        );
        
        if ($existing) {
            $this->db->update(
                'attendance_daily',
                $attendanceData,
                'id = ?',
                [$existing['id']]
            );
        } else {
            $this->db->insert('attendance_daily', $attendanceData);
        }
    }
    
    /**
     * Parse a stored UTC timestamp as a DateTime in the UTC timezone.
     * Punch timestamps are stored in UTC (P6); they must never be interpreted in the
     * local TIMEZONE, otherwise duration math against the real UTC clock is wildly off.
     */
    private function utcDateTime($datetime) {
        return new DateTime($datetime, new DateTimeZone('UTC'));
    }

    /**
     * Calculate duration in minutes between two DateTime objects
     */
    private function calculateDuration($start, $end) {
        $interval = $start->diff($end);
        // Include full days ($interval->d * 1440) so sessions spanning 24h+ (e.g. a
        // forgotten clock-out re-punched the next day) round-trip correctly instead of
        // dropping the day component. For all normal shifts (including night shifts
        // crossing midnight, where d == 0) the result is unchanged.
        return ($interval->d * 1440) + ($interval->h * 60) + $interval->i;
    }
    
    /**
     * Determine attendance status based on centralized thresholds
     * Uses the centralized AttendanceThresholdService for consistent status calculation
     * @param int $netWorkedMinutes Net worked minutes (Work Duration - Total Break Duration)
     * @param string $attendanceDate The attendance date
     * @param int $employeeId Employee ID
     * @return string Attendance status
     */
    private function determineStatus($netWorkedMinutes, $attendanceDate, $employeeId) {
        // Use the centralized threshold service for status calculation
        return $this->thresholdService->calculateStatus($netWorkedMinutes, $attendanceDate, $employeeId);
    }
    
    /**
     * Get current clock-in status for an employee
     * 
     * Determines status from the latest CLOCK_IN: the employee is "in" only if that
     * clock-in has no matching CLOCK_OUT after it (i.e. an open session exists).
     * This mirrors getClockedInEmployees() and is robust against multi-session days.
     */
    public function getCurrentStatus($employeeId) {
        // Latest CLOCK_IN for the employee
        $lastIn = $this->db->fetchOne(
            "SELECT * FROM attendance_punches 
             WHERE employee_id = ? AND event_type = 'CLOCK_IN'
             ORDER BY timestamp_utc DESC, id DESC 
             LIMIT 1",
            [$employeeId]
        );
        
        if (!$lastIn) {
            return ['status' => 'out', 'last_punch' => null, 'on_break' => false];
        }
        
        // Check if there is a matching clock-out after this clock-in
        // (">= " intentionally: a punch in the very same second as the clock-in
        // (e.g. a quick break start right after clock-in) must still be detected)
        $hasClockOut = $this->db->fetchOne(
            "SELECT id FROM attendance_punches 
             WHERE employee_id = ? AND event_type = 'CLOCK_OUT' 
             AND timestamp_utc >= ? 
             ORDER BY timestamp_utc ASC, id ASC 
             LIMIT 1",
            [$employeeId, $lastIn['timestamp_utc']]
        );
        
        if (!$hasClockOut) {
            // Check if currently on break (BREAK_START without matching BREAK_END)
            $lastBreakStart = $this->db->fetchOne(
                "SELECT * FROM attendance_punches 
                 WHERE employee_id = ? AND event_type = 'BREAK_START'
                 AND timestamp_utc >= ?
                 ORDER BY timestamp_utc DESC, id DESC 
                 LIMIT 1",
                [$employeeId, $lastIn['timestamp_utc']]
            );
            
            if ($lastBreakStart) {
                $hasBreakEnd = $this->db->fetchOne(
                    "SELECT id FROM attendance_punches 
                     WHERE employee_id = ? AND event_type = 'BREAK_END'
                     AND timestamp_utc >= ?
                     ORDER BY timestamp_utc ASC, id ASC 
                     LIMIT 1",
                    [$employeeId, $lastBreakStart['timestamp_utc']]
                );
                
                if (!$hasBreakEnd) {
                    // Get deduct_from_work_time setting for the current break type
                    $deductFromWorkTime = 1; // default to deduct
                    if ($lastBreakStart['break_type_id']) {
                        $breakType = $this->db->fetchOne(
                            "SELECT deduct_from_work_time FROM break_types WHERE id = ?",
                            [$lastBreakStart['break_type_id']]
                        );
                        $deductFromWorkTime = $breakType['deduct_from_work_time'] ?? 1;
                    }
                    
                    return [
                        'status' => 'in', 
                        'last_punch' => $lastIn, 
                        'on_break' => true, 
                        'break_start' => $lastBreakStart,
                        'break_deduct_from_work_time' => $deductFromWorkTime
                    ];
                }
            }
            
            return ['status' => 'in', 'last_punch' => $lastIn, 'on_break' => false];
        }
        
        // Session is closed; report the clock-out as the last effective punch
        $lastOut = $this->db->fetchOne(
            "SELECT * FROM attendance_punches WHERE id = ?",
            [$hasClockOut['id']]
        );
        return ['status' => 'out', 'last_punch' => $lastOut ?? $lastIn, 'on_break' => false];
    }
    
    /**
     * Get employees currently clocked in
     */
    public function getClockedInEmployees($limit = 50) {
        return $this->db->fetchAll(
            "SELECT DISTINCT e.id, e.first_name, e.last_name, e.employee_id, ap.timestamp_utc
             FROM attendance_punches ap
             JOIN employees e ON ap.employee_id = e.id
             WHERE ap.event_type = 'CLOCK_IN'
             AND ap.employee_id NOT IN (
                 SELECT employee_id FROM attendance_punches ap2
                 WHERE ap2.event_type = 'CLOCK_OUT'
                 AND ap2.timestamp_utc > (
                     SELECT MAX(timestamp_utc) FROM attendance_punches ap3
                     WHERE ap3.employee_id = ap.employee_id AND ap3.event_type = 'CLOCK_IN'
                 )
             )
             ORDER BY ap.timestamp_utc DESC
             LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Bulk mark attendance per FR-ATT-06
     */
    public function bulkMarkAttendance($employeeId, $attendanceDate, $status, $shiftId = null, $remarks = null, $markedBy = null) {
        // Get employee's default shift if not provided
        if (!$shiftId) {
            $employee = $this->db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$employeeId]);
            $shiftId = $employee['shift_id'];
        }
        
        // Upsert attendance_daily record
        $attendanceData = [
            'employee_id' => $employeeId,
            'attendance_date' => $attendanceDate,
            'shift_id' => $shiftId,
            'status' => $status,
            'remarks' => $remarks,
            'is_regularized' => 1
        ];
        
        $existing = $this->db->fetchOne(
            "SELECT id FROM attendance_daily WHERE employee_id = ? AND attendance_date = ?",
            [$employeeId, $attendanceDate]
        );
        
        if ($existing) {
            $this->db->update('attendance_daily', $attendanceData, 'id = ?', [$existing['id']]);
            $attendanceDailyId = $existing['id'];
        } else {
            $attendanceDailyId = $this->db->insert('attendance_daily', $attendanceData);
        }
        
        // Insert manual punch record for audit
        $punchData = [
            'employee_id' => $employeeId,
            'event_type' => 'MANUAL_MARK',
            'timestamp_utc' => date('Y-m-d H:i:s'),
            'source' => 'manual',
            'attendance_date' => $attendanceDate,
            'is_review_flagged' => 0
        ];
        
        $this->db->insert('attendance_punches', $punchData);
        
        // Log audit
        logAudit('attendance_daily', $attendanceDailyId, 'update', null, $attendanceData);
        
        return ['success' => true, 'attendance_daily_id' => $attendanceDailyId];
    }

    // ========================================================================
    // Idle Time Tracking
    // ========================================================================

    /**
     * Get the idle-time configuration from the settings table.
     * Requires the migration (database/run_idle_tracking_migration.php) to have run.
     *
     * @return array [
     *   'enabled'            => bool,
     *   'timeout_minutes'    => int,
     *   'min_record_minutes' => int,
     *   'assign_to'          => string (all_employees | department | role | user | shift),
     *   'target_id'          => int
     * ]
     */
    public function getIdleSettings() {
        $enabled = getSetting('idle_tracking_enabled', '1');
        $timeout = (int) getSetting('idle_timeout_minutes', '20');
        $minRecord = (int) getSetting('idle_min_record_minutes', '6');
        $assignTo = (string) getSetting('idle_assign_to', 'all_employees');
        $targetId = (string) getSetting('idle_target_id', '');
        $idleStart = (int) getSetting('idle_start_minutes', '5');

        if ($timeout < 1) $timeout = 20;
        if ($minRecord < 1) $minRecord = 6;
        if ($idleStart < 1) $idleStart = 5;
        if ($assignTo === '' || $assignTo === null) $assignTo = 'all_employees';

        return [
            'enabled' => (bool) ((int) $enabled),
            'timeout_minutes' => $timeout,
            'min_record_minutes' => $minRecord,
            'idle_start_minutes' => $idleStart,
            'assign_to' => $assignTo,
            'target_id' => $targetId
        ];
    }

    /**
     * Split the comma-separated idle target ids into an integer array.
     */
    private function idleTargetIds($targetIdStr) {
        $ids = [];
        if (empty($targetIdStr)) {
            return $ids;
        }
        foreach (explode(',', (string) $targetIdStr) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part)) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }

    /**
     * Whether idle time tracking is applicable to the given employee based on
     * the configured Assign-To scope (all_employees | department | role | user | shift).
     * Supports multiple targets via a comma-separated idle_target_id.
     *
     * @param int $employeeId
     * @return bool
     */
    public function isIdleTrackingApplicable($employeeId) {
        $s = $this->getIdleSettings();
        if (!$s['enabled']) {
            return false;
        }
        $assignTo = $s['assign_to'];
        if ($assignTo === 'all_employees') {
            return true;
        }
        $targetIds = $this->idleTargetIds((string) $s['target_id']);
        if (empty($targetIds)) {
            return false;
        }
        $employeeId = (int) $employeeId;

        try {
            if ($assignTo === 'user') {
                return in_array($employeeId, $targetIds);
            }
            if ($assignTo === 'department') {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $row = $this->db->fetchOne(
                    "SELECT id FROM employee_departments
                     WHERE employee_id = ? AND department_id IN ({$placeholders})
                     LIMIT 1",
                    array_merge([$employeeId], $targetIds)
                );
                return (bool) $row;
            }
            if ($assignTo === 'role') {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $row = $this->db->fetchOne(
                    "SELECT id FROM employees WHERE id = ? AND role_id IN ({$placeholders}) LIMIT 1",
                    array_merge([$employeeId], $targetIds)
                );
                return (bool) $row;
            }
            if ($assignTo === 'shift') {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $row = $this->db->fetchOne(
                    "SELECT id FROM employees WHERE id = ? AND shift_id IN ({$placeholders}) LIMIT 1",
                    array_merge([$employeeId], $targetIds)
                );
                return (bool) $row;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }

    /**
     * Get the total idle minutes recorded for an employee on an attendance date.
     *
     * @param int $employeeId
     * @param string $attendanceDate Y-m-d
     * @return int
     */
    public function getTotalIdleMinutes($employeeId, $attendanceDate) {
        try {
            $row = $this->db->fetchOne(
                "SELECT COALESCE(SUM(idle_minutes), 0) as total
                 FROM idle_periods
                 WHERE employee_id = ? AND attendance_date = ?",
                [$employeeId, $attendanceDate]
            );
            return $row ? (int) $row['total'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Save a single idle period to the database.
     *
     * Rounding rule (applied consistently):
     *   - idle duration <  min_record_minutes (default 6)  -> recorded as 0 minutes
     *   - idle duration >= min_record_minutes (default 6)  -> recorded as actual floor(duration) minutes
     *   - e.g. 4 min -> 0, 5 min -> 0, 6 min -> 6, 7 min -> 7
     *
     * The row is de-duplicated with INSERT IGNORE against
     * (employee_id, attendance_date, idle_start_utc) so the same idle period is
     * never inserted twice.
     *
     * @param int $employeeId
     * @param string $attendanceDate Y-m-d
     * @param string $startUtc       Y-m-d H:i:s (UTC)
     * @param string $endUtc         Y-m-d H:i:s (UTC)
     * @param string $source         web / desktop
     * @return array
     */
    public function saveIdlePeriod($employeeId, $attendanceDate, $startUtc, $endUtc, $source = 'web') {
        // Validate the period
        $startTs = strtotime($startUtc);
        $endTs = strtotime($endUtc);
        if (!$startTs || !$endTs || $endTs <= $startTs) {
            return ['success' => false, 'message' => 'Invalid idle period timestamps'];
        }

        // Floor duration to whole minutes
        $idleMinutes = (int) floor(($endTs - $startTs) / 60);

        // Rounding rule: periods shorter than the minimum are recorded as 0
        $settings = $this->getIdleSettings();
        if ($idleMinutes < $settings['min_record_minutes']) {
            $idleMinutes = 0;
        }

        // INSERT IGNORE de-duplicates identical (employee, date, start) periods
        try {
            $this->db->query(
                "INSERT IGNORE INTO idle_periods
                   (employee_id, attendance_date, idle_start_utc, idle_end_utc, idle_minutes, source)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$employeeId, $attendanceDate, $startUtc, $endUtc, $idleMinutes, $source]
            );
        } catch (Exception $e) {
            // idle_periods table missing (migration not run) -> feature stays dormant
            return ['success' => false, 'message' => 'idle_periods table unavailable'];
        }

        // Refresh the daily aggregate (this also recalculates Net Time)
        $this->recomputeAttendanceDaily($employeeId, $attendanceDate, null);

        return ['success' => true, 'idle_minutes' => $idleMinutes];
    }

    /**
     * Finalize any in-progress idle period for the current user that is stored in
     * the PHP session. Used when the employee resumes activity, starts a break,
     * clocks out, or times out.
     *
     * @param int $employeeId
     * @param string|null $endUtc End timestamp (UTC); defaults to now
     * @param string $source
     * @return bool True if an open idle period was saved
     */
    public function finalizeOpenIdlePeriod($employeeId, $endUtc = null, $source = 'web') {
        if (empty($_SESSION['hrms_idle_start_utc'])) {
            return false;
        }

        $startUtc = $_SESSION['hrms_idle_start_utc'];
        $attendanceDate = $_SESSION['hrms_idle_attendance_date'] ?? null;

        // Clear regardless of success so an orphaned session marker never blocks tracking
        unset($_SESSION['hrms_idle_start_utc'], $_SESSION['hrms_idle_attendance_date']);

        if (!$attendanceDate) {
            return false;
        }

        $result = $this->saveIdlePeriod($employeeId, $attendanceDate, $startUtc, $endUtc ?? gmdate('Y-m-d H:i:s'), $source);
        return $result['success'];
    }
}



