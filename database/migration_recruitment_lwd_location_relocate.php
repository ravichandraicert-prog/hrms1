<?php
/**
 * Recruitment Offboarding + Candidate Fields Migration (idempotent)
 * ------------------------------------------------------------------
 * Adds:
 *   1. employees.last_working_day  DATE  – used by Recruitment → Onboarding → Offboard flow
 *   2. candidates.location         VARCHAR(255) – candidate location
 *   3. candidates.relocate         ENUM('Yes','No') – candidate relocation willingness
 *
 * Run:  C:\xampp\php\php.exe database/migration_recruitment_lwd_location_relocate.php
 * Safe to run multiple times – each column is added only if missing.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$fail = 0;

/**
 * Check whether a column exists on a table.
 * $table and $column are always static literals from this script (no user input).
 */
function columnExists(Database $db, $table, $column) {
    try {
        $row = $db->fetchOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return !empty($row);
    } catch (Exception $e) {
        return false;
    }
}

$steps = [];

// ---- 1. employees.last_working_day ----
if (!columnExists($db, 'employees', 'last_working_day')) {
    try {
        $db->query("ALTER TABLE `employees` ADD COLUMN `last_working_day` DATE NULL DEFAULT NULL AFTER `date_of_joining`");
        $steps[] = "ADDED employees.last_working_day (DATE)";
    } catch (Exception $e) {
        $fail++;
        $steps[] = "FAIL employees.last_working_day: " . $e->getMessage();
    }
} else {
    $steps[] = "ok employees.last_working_day (already exists)";
}

// ---- 2. candidates.location ----
if (!columnExists($db, 'candidates', 'location')) {
    try {
        $db->query("ALTER TABLE `candidates` ADD COLUMN `location` VARCHAR(255) NULL DEFAULT NULL AFTER `source`");
        $steps[] = "ADDED candidates.location (VARCHAR(255))";
    } catch (Exception $e) {
        $fail++;
        $steps[] = "FAIL candidates.location: " . $e->getMessage();
    }
} else {
    $steps[] = "ok candidates.location (already exists)";
}

// ---- 3. candidates.relocate ----
if (!columnExists($db, 'candidates', 'relocate')) {
    try {
        $db->query("ALTER TABLE `candidates` ADD COLUMN `relocate` ENUM('Yes','No') NULL DEFAULT NULL AFTER `location`");
        $steps[] = "ADDED candidates.relocate ENUM('Yes','No')";
    } catch (Exception $e) {
        $fail++;
        $steps[] = "FAIL candidates.relocate: " . $e->getMessage();
    }
} else {
    $steps[] = "ok candidates.relocate (already exists)";
}

echo "=== Recruitment Offboarding / Candidate Fields Migration ===\n";
foreach ($steps as $s) {
    echo "  " . $s . "\n";
}
if ($fail === 0) {
    echo "ALL DONE - no failures\n";
} else {
    echo "FAILURES: {$fail}\n";
}
exit($fail === 0 ? 0 : 1);