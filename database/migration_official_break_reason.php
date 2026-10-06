<?php
/**
 * Official Break – add reason column to attendance_punches (idempotent migration)
 * ------------------------------------------------------------------
 * The break system stores each break as BREAK_START + BREAK_END punches in
 * `attendance_punches`. The "Official Break" feature records a mandatory reason
 * on the BREAK_START punch row so it stays associated with the break for the
 * whole break lifecycle (start -> end -> duration calculation).
 *
 * Run:  C:\xampp\php\php.exe database/migration_official_break_reason.php
 * Safe to run multiple times.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$fail = 0;
$steps = [];

try {
    $existing = $db->fetchOne("SHOW COLUMNS FROM attendance_punches LIKE 'reason'");
    if (empty($existing)) {
        $db->query("ALTER TABLE `attendance_punches` ADD COLUMN `reason` VARCHAR(500) NULL DEFAULT NULL AFTER `break_type_id`");
        $steps[] = "ADDED attendance_punches.reason (VARCHAR(500))";
    } else {
        $steps[] = "ok attendance_punches.reason (already exists)";
    }
} catch (Exception $e) {
    $fail++;
    $steps[] = "FAIL attendance_punches.reason: " . $e->getMessage();
}

echo "=== Official Break Reason Migration ===\n";
foreach ($steps as $s) {
    echo "  " . $s . "\n";
}
if ($fail === 0) {
    echo "ALL DONE - no failures\n";
} else {
    echo "FAILURES: {$fail}\n";
}
exit($fail === 0 ? 0 : 1);