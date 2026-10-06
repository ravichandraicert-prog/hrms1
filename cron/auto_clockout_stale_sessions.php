<?php
/**
 * Auto Clock-Out Stale Sessions (Shutdown Safety Net)
 * Core PHP HRMS Application
 *
 * When the HRMS desktop application (or a browser tab) was clocked in but then
 * completely disappeared - OS shutdown without a clean signal, power cut, app
 * crash, whole machine off - the server-side idle timeout can never fire
 * because it requires an incoming heartbeat. This script closes those orphaned
 * attendance sessions:
 *
 *   1. finds every employee still "clocked in" whose last machine-alive marker
 *      (heartbeat) is older than shutdown_clockout_stale_minutes (default 60),
 *   2. records a CLOCK_OUT punch AT the last heartbeat time (so Net Time stops
 *      at the moment the machine actually went down, not at detection), using
 *      the exact same punch pipeline as a manual clock-out,
 *   3. destroys the employee's PHP session(s) -> real logout.
 *
 * Employees with ANY still-fresh heartbeat (logged in on another machine/tab)
 * are never touched.
 *
 * Run every 5 minutes, e.g. this crontab line:
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * *   php /var/www/html/hrms1/cron/auto_clockout_stale_sessions.php
 *
 * Dry-run (report only, no writes):
 *   php /var/www/html/hrms1/cron/auto_clockout_stale_sessions.php --dry-run
 *
 * Disable any time by setting shutdown_clockout_enabled to 0 in the settings
 * table (or via Attendance > Settings > Idle Time Tracking panel).
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attendance.php';

$db = Database::getInstance();
$attendance = new Attendance();

// ---- Master switch ----
if (getSetting('shutdown_clockout_enabled', '1') !== '1') {
    echo date('Y-m-d H:i:s') . " shutdown_clockout_enabled=0 -> sweep disabled\n";
    exit;
}

$staleMinutes = max(1, (int) getSetting('shutdown_clockout_stale_minutes', '60'));
$thresholdUtc = gmdate('Y-m-d H:i:s', time() - $staleMinutes * 60);
echo date('Y-m-d H:i:s') . " Stale threshold: {$thresholdUtc} UTC ({$staleMinutes} min) | dry-run=" . ($dryRun ? 'YES' : 'no') . "\n";

// ---- Employees whose machine-alive markers are ALL older than the threshold ----
// Using HAVING MAX(...) means an employee with even one still-fresh session
// (another open tab / another machine) is excluded from the sweep entirely.
$staleEmployees = $db->fetchAll(
    "SELECT employee_id, MAX(last_seen_utc) AS last_seen_utc
     FROM employee_live_sessions
     GROUP BY employee_id
     HAVING MAX(last_seen_utc) < ?",
    [$thresholdUtc]
);

if (empty($staleEmployees)) {
    echo date('Y-m-d H:i:s') . " No stale sessions.\n";
    exit;
}

foreach ($staleEmployees as $stale) {
    $empId = (int) $stale['employee_id'];
    $lastSeen = $stale['last_seen_utc'];

    try {
        $status = $attendance->getCurrentStatus($empId);
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " ERROR status employee {$empId}: " . $e->getMessage() . "\n";
        continue;
    }

    // Only close a genuinely open attendance session.
    if ($status['status'] !== 'in') {
        // Not actually clocked in - just clean up the dead session markers.
        cleanupEmployeeLiveRows($db, $empId);
        echo date('Y-m-d H:i:s') . " emp {$empId}: not clocked in -> cleaned live markers\n";
        continue;
    }

    if ($dryRun) {
        echo date('Y-m-d H:i:s') . " [dry-run] emp {$empId}: would CLOCK_OUT at {$lastSeen} UTC, source=shutdown_sweep, logout=YES\n";
        continue;
    }

    // CLOCK_OUT at the last alive moment. recordPunch() derives the correct
    // shift-based attendance_date and recomputes attendance_daily exactly like
    // a manual clock-out (night-shift safe).
    $clockOut = $attendance->recordPunch(
        $empId,
        'CLOCK_OUT',
        null,
        'shutdown_sweep',
        null,
        'System shutdown (no heartbeat)',
        $lastSeen
    );

    if (!empty($clockOut['success'])) {
        echo date('Y-m-d H:i:s') . " emp {$empId}: clocked out at {$lastSeen} UTC (" . ($clockOut['attendance_date'] ?? '?') . ")\n";
    } else {
        echo date('Y-m-d H:i:s') . " emp {$empId}: CLOCK_OUT failed: " . ($clockOut['message'] ?? 'unknown') . "\n";
    }

    // Logout: destroy every PHP session belonging to this employee, then remove
    // all their marker rows so the sweep does not reprocess them.
    $empSessions = $db->fetchAll(
        'SELECT session_id FROM employee_live_sessions WHERE employee_id = ?',
        [$empId]
    );
    foreach ($empSessions as $sess) {
        destroyPhpSession($sess['session_id']);
    }
    cleanupEmployeeLiveRows($db, $empId);
}

/**
 * Remove all machine-alive marker rows for an employee.
 */
function cleanupEmployeeLiveRows($db, $empId) {
    try {
        $db->query('DELETE FROM employee_live_sessions WHERE employee_id = ?', [$empId]);
    } catch (Exception $e) {}
}

/**
 * Destroy a single PHP session by id. This must run as the same user that
 * created the session files (www-data when Apache serves the app), otherwise
 * the sticky-bit session directory prevents file removal. The session file is
 * removed explicitly. NO session_write_close() is called after destroy() so
 * PHP can never silently re-create the file.
 */
function destroyPhpSession($sessionId) {
    if (!is_string($sessionId) || !preg_match('/^[a-zA-Z0-9,-]{1,64}$/', $sessionId)) {
        return;
    }

    $savePath = session_save_path();
    $file = ($savePath && is_dir($savePath))
        ? rtrim($savePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId
        : null;

    // 1) Ask PHP to tear the session down natively (works for any save handler).
    try {
        @session_write_close();
        @session_id($sessionId);
        @session_start();
        $_SESSION = [];          // cannot be reused as a valid login
        @session_destroy();
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . "  - session destroy error (sess_{$sessionId}): " . $e->getMessage() . "\n";
    }

    // 2) Remove the session file explicitly - the reliable step regardless of
    //    save-handler nuances and PHP's stat-cache.
    if ($file && is_file($file)) {
        clearstatcache(true, $file);
        if (!@unlink($file)) {
            echo date('Y-m-d H:i:s') . "  - WARNING: could not remove sess_{$sessionId} - run this cron as www-data so it owns the session files\n";
        }
    }
}