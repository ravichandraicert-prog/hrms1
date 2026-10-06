<?php
/**
 * Idle Time Tracking API Endpoint
 * Core PHP HRMS Application
 *
 * Heartbeat endpoint invoked by the client ONCE PER MINUTE (plus on resume /
 * visibility change / unload) while the employee is logged in.
 *
 * Idle tracking ONLY runs while the employee is "currently in" (clocked in and
 * NOT on an active break). Break time never counts as idle time.
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attendance.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];
    $auth = new Auth();
    $attendance = new Attendance();
    $db = Database::getInstance();
    $settings = $attendance->getIdleSettings();
    // Whether idle tracking applies to this specific employee (scope check)
    $applicable = $attendance->isIdleTrackingApplicable($userId);

    // ---- GET: idle configuration + today's recorded idle total ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $today = date('Y-m-d');
        $todayIdle = 0;

        // ---- Shutdown / crash recovery (re-uses the existing safety-net logic) ----
        // If the employee is still marked "clocked in" but their machine-alive
        // heartbeat is stale, the previous HRMS session - Windows shutdown,
        // restart, logoff, or crash without a clean clock-out - left an orphaned
        // attendance record. Close it now with the SAME punch pipeline used by
        // File -> Quit / shutdown (see the POST action=shutdown branch and
        // cron/auto_clockout_stale_sessions.php), so checkout time, break/net
        // duration and shift aggregates are computed identically. Guarded by the
        // same "no fresh heartbeat anywhere" rule as the cron sweep so a genuinely
        // active session (another logged-in machine/tab) is never touched. This
        // runs on page load BEFORE the new session's first heartbeat, so a fresh
        // cold start is recovered immediately.
        $recoveredShutdown = false;
        try {
            if (getSetting('shutdown_clockout_enabled', '1') === '1') {
                $check = $attendance->getCurrentStatus($userId);
                if ($check['status'] === 'in') {
                    $staleMinutes = max(1, (int) getSetting('shutdown_recovery_stale_minutes', '2'));
                    $staleThresholdUtc = gmdate('Y-m-d H:i:s', time() - $staleMinutes * 60);
                    $live = $db->fetchOne(
                        "SELECT MAX(last_seen_utc) AS last_seen_utc
                         FROM employee_live_sessions
                         WHERE employee_id = ?",
                        [$userId]
                    );
                    $lastSeen = ($live && !empty($live['last_seen_utc'])) ? $live['last_seen_utc'] : null;
                    // Only close when a marker exists AND it is entirely stale.
                    if ($lastSeen !== null && $lastSeen < $staleThresholdUtc) {
                        // Clock out at the moment the machine was last known alive.
                        $attendance->finalizeOpenIdlePeriod($userId, $lastSeen, 'web');
                        $result = $attendance->recordPunch(
                            $userId, 'CLOCK_OUT', null, 'shutdown_sweep', null,
                            'Session recovered after shutdown', $lastSeen
                        );
                        if (!empty($result['success'])) {
                            try {
                                $db->query('DELETE FROM employee_live_sessions WHERE employee_id = ?', [$userId]);
                            } catch (Exception $e) {}
                            try {
                                $auth->logout();
                            } catch (Exception $e) {}
                            $recoveredShutdown = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Best-effort only: never fail the normal status response.
            $recoveredShutdown = false;
        }

        $currentStatus = $attendance->getCurrentStatus($userId);
        if ($currentStatus['status'] === 'in' && isset($currentStatus['last_punch']['attendance_date'])) {
            $todayIdle = $attendance->getTotalIdleMinutes($userId, $currentStatus['last_punch']['attendance_date']);
        } else {
            $todayIdle = $attendance->getTotalIdleMinutes($userId, $today);
        }

        echo json_encode([
            'success' => true,
            'settings' => $settings,
            'applicable' => $applicable,
            'today_total_idle_minutes' => $todayIdle,
            'currently_in' => ($currentStatus['status'] === 'in'),
            'on_break' => ($currentStatus['on_break'] === true),
            'recovered_shutdown' => $recoveredShutdown
        ]);
        exit;
    }

    // ---- POST: heartbeat ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $idleSeconds = max(0, (int) ($data['idle_seconds'] ?? 0));
        $source = ($data['source'] ?? 'web') === 'desktop' ? 'desktop' : 'web';
        $nowUtc = gmdate('Y-m-d H:i:s');

        // ---- Machine/app "alive" marker for the shutdown safety-net sweep ----
        // Refreshed on every heartbeat. If the machine goes down without sending
        // the clean shutdown signal (power cut / crash / forced kill), the cron
        // sweep (cron/auto_clockout_stale_sessions.php) uses this timestamp to
        // clock the employee out at the moment they were last alive. This is a
        // separate additive write and does NOT change any idle-tracking logic.
        try {
            $db->query(
                "INSERT INTO employee_live_sessions (session_id, employee_id, last_seen_utc)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_seen_utc = VALUES(last_seen_utc)",
                [session_id(), $userId, $nowUtc]
            );
        } catch (Exception $e) {
            // Table not available (migration not run) -> feature stays dormant.
        }

        // Current attendance status (server-authoritative)
        $status = $attendance->getCurrentStatus($userId);
        $isClockIn = ($status['status'] === 'in');
        $isOnBreak = ($status['on_break'] === true);
        $attendanceDate = ($status['last_punch']['attendance_date'] ?? null);

        // ============================================================
        // SHUTDOWN / APP EXIT -> clean clock-out + logout
        // Invoked by the Electron desktop wrapper when the OS shuts down,
        // restarts or logs off, or when the user quits the app. A single
        // session-authenticated request, so it only ever closes the
        // requesting user's own open attendance record. Runs regardless of
        // idle-tracking settings so a shutdown always records attendance.
        // ============================================================
        if (($data['action'] ?? '') === 'shutdown') {
            $punchSource = ($source === 'desktop') ? 'desktop_shutdown' : 'shutdown_sweep';

            // 1) Close any open idle period (session is still alive in this request).
            $attendance->finalizeOpenIdlePeriod($userId, $nowUtc, $source);

            // 2) Record CLOCK_OUT at this moment through the normal punch
            //    pipeline (shift-based attendance_date + aggregates preserved).
            $clockOutResult = $attendance->recordPunch(
                $userId, 'CLOCK_OUT', null, $punchSource, null, 'System shutdown'
            );

            // 3) Drop the "alive" marker so the sweep ignores this session.
            try {
                $db->query('DELETE FROM employee_live_sessions WHERE session_id = ?', [session_id()]);
            } catch (Exception $e) {}

            // 4) Log out (destroys the PHP session).
            try {
                $auth->logout();
            } catch (Exception $e) {}

            echo json_encode([
                'success' => true,
                'action' => 'shutdown_clockout',
                'clocked_out' => !empty($clockOutResult['success']),
                'message' => 'Attendance recorded and you have been logged out (system shutdown).'
            ]);
            exit;
        }

        // ---- Feature disabled OR employee not in scope: track nothing ----
        if (!$settings['enabled'] || !$applicable) {
            unset($_SESSION['hrms_idle_start_utc'], $_SESSION['hrms_idle_attendance_date']);
            unset($_SESSION['hrms_idle_warned'], $_SESSION['hrms_idle_started_notified'], $_SESSION['hrms_idle_final_warned']);
            echo json_encode([
                'success' => true,
                'tracking' => false,
                'reason' => $applicable ? 'disabled' : 'not_applicable',
                'today_total_idle_minutes' => $attendance->getTotalIdleMinutes($userId, $attendanceDate ?? date('Y-m-d'))
            ]);
            exit;
        }

        // ---- Track ONLY when "currently in" and NOT on a break ----
        if (!$isClockIn || $isOnBreak) {
            // Reset pending idle-reminder flags so a later idle cycle restarts
            // the warning sequence from the beginning.
            unset($_SESSION['hrms_idle_warned'], $_SESSION['hrms_idle_started_notified'], $_SESSION['hrms_idle_final_warned']);
            // A break (or clock-out) ends any open idle period. Save the pre-break
            // idle so it is not lost, but do NOT open or extend idle during the break.
            $attendance->finalizeOpenIdlePeriod($userId, $nowUtc, $source);
            echo json_encode([
                'success' => true,
                'tracking' => false,
                'reason' => $isOnBreak ? 'on_break' : 'not_in',
                'today_total_idle_minutes' => $attendance->getTotalIdleMinutes($userId, $attendanceDate ?? date('Y-m-d'))
            ]);
            exit;
        }

        // At this point the employee IS currently clocked in and NOT on a break.
        $thresholdSeconds = $settings['timeout_minutes'] * 60;

        // ============================================================
        // IDLE NOTIFICATION REMINDERS (in-app toast + Windows desktop)
        // Notifications ONLY - they never change idle tracking, attendance,
        // auto-logout or session logic. One DB record is inserted per reminder
        // (guarded by session flags so it fires once per idle cycle); the
        // existing poller (includes/notification-toast.js) renders it BOTH as
        // an in-app toast and (in the Electron desktop app) as a native
        // Windows notification, keeping the two channels synchronised.
        // Thresholds are read live from HRMS settings so the reminders follow
        // administrator changes automatically.
        // ============================================================
        $idleStartMin = max(1, (int) $settings['idle_start_minutes']);
        $timeoutMin   = (int) $settings['timeout_minutes'];

        // Employee is active again -> reset the reminder state so the next
        // idle cycle restarts the whole sequence from the beginning.
        if ($idleSeconds < 60) {
            unset(
                $_SESSION['hrms_idle_warned'],
                $_SESSION['hrms_idle_started_notified'],
                $_SESSION['hrms_idle_final_warned']
            );
        }

        // 1) 2-minute warning BEFORE idle starts (idle_start_minutes - 2).
        //    e.g. idle start 5 -> warning at 3 min; 10 -> at 8 min; 3 -> at 1 min.
        if ($idleStartMin > 2) {
            $warnSec = ($idleStartMin - 2) * 60;
            if ($idleSeconds >= $warnSec && $idleSeconds < $idleStartMin * 60 && empty($_SESSION['hrms_idle_warned'])) {
                $_SESSION['hrms_idle_warned'] = true;
                sendNotification(
                    $userId,
                    'idle',
                    'Idle Time Warning',
                    'Your idle time will start in 2 minutes. Please move your mouse or use your keyboard to remain active.',
                    null,
                    'Medium'
                );
            }
        }

        // 2) Idle actually started (idle_start_minutes reached).
        if ($idleStartMin <= $timeoutMin && $idleSeconds >= $idleStartMin * 60 && empty($_SESSION['hrms_idle_started_notified'])) {
            $_SESSION['hrms_idle_started_notified'] = true;
            sendNotification(
                $userId,
                'idle',
                'Idle Time Started',
                'Your idle time has started.',
                null,
                'Medium'
            );
        }

        // 3) Final warning BEFORE the auto clock-out / logout (timeout_minutes - 2).
        //    e.g. timeout 20 -> warning at 18 min, auto-logout still at 20 min.
        if ($timeoutMin > 2 && $timeoutMin - $idleStartMin >= 2) {
            $finalWarnSec = ($timeoutMin - 2) * 60;
            if ($idleSeconds >= $finalWarnSec && $idleSeconds < $timeoutMin * 60 && empty($_SESSION['hrms_idle_final_warned'])) {
                $_SESSION['hrms_idle_final_warned'] = true;
                sendNotification(
                    $userId,
                    'idle',
                    'Idle Time Warning',
                    'You have been idle for ' . ($timeoutMin - 2) . ' minutes. HRMS will automatically log you out in 2 minutes if no activity is detected.',
                    null,
                    'High'
                );
            }
        }

        // ============================================================
        // 1) IDLE TIMEOUT REACHED -> auto clock out + auto logout
        // ============================================================
        if ($idleSeconds >= $thresholdSeconds) {
            // Make sure an idle period exists spanning the whole idle window
            if (empty($_SESSION['hrms_idle_start_utc'])) {
                $_SESSION['hrms_idle_start_utc'] = gmdate('Y-m-d H:i:s', time() - $idleSeconds);
                $_SESSION['hrms_idle_attendance_date'] = $attendanceDate ?? date('Y-m-d');
            }

            // Save the idle period to the database (exactly once)
            $attendance->finalizeOpenIdlePeriod($userId, $nowUtc, $source);

            // Automatically clock out using the existing punch pipeline so that the
            // night-shift attendance_date calculation and aggregates are preserved.
            $clockOutResult = $attendance->recordPunch($userId, 'CLOCK_OUT', null, 'auto_idle');

            // Log out the employee (destroys the PHP session)
            $auth->logout();

            echo json_encode([
                'success' => true,
                'action' => 'auto_logout',
                'message' => 'You were logged out after being idle for more than ' . $settings['timeout_minutes'] . ' minutes.',
                'clocked_out' => !empty($clockOutResult['success'])
            ]);
            exit;
        }

        // ============================================================
        // 2) IDLE (but under the timeout) -> open / keep an idle period.
        //    The client checks once per minute, so this only runs at most
        //    once per minute per user.
        // ============================================================
        if ($idleSeconds >= 60) {
            if (empty($_SESSION['hrms_idle_start_utc'])) {
                $_SESSION['hrms_idle_start_utc'] = gmdate('Y-m-d H:i:s', time() - $idleSeconds);
                $_SESSION['hrms_idle_attendance_date'] = $attendanceDate ?? date('Y-m-d');
            }

            echo json_encode([
                'success' => true,
                'tracking' => true,
                'idle' => true,
                'idle_seconds' => $idleSeconds,
                'today_total_idle_minutes' => $attendance->getTotalIdleMinutes($userId, $attendanceDate ?? date('Y-m-d'))
            ]);
            exit;
        }

        // ============================================================
        // 3) ACTIVE -> close & save any open idle period (resume)
        // ============================================================
        $attendance->finalizeOpenIdlePeriod($userId, $nowUtc, $source);

        echo json_encode([
            'success' => true,
            'tracking' => true,
            'active' => true,
            'idle_seconds' => 0,
            'today_total_idle_minutes' => $attendance->getTotalIdleMinutes($userId, $attendanceDate ?? date('Y-m-d'))
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
