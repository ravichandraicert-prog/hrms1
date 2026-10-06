<?php
/**
 * Recompute Attendance Script
 * This script recalculates attendance_daily records to update break durations
 * with the corrected logic that counts all breaks regardless of deduct settings
 */

require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/attendance.php';

$db = Database::getInstance();
$attendance = new Attendance();

echo "Starting attendance recomputation...\n\n";

// Get all attendance_daily records
$attendanceRecords = $db->fetchAll("SELECT DISTINCT employee_id, attendance_date, shift_id FROM attendance_daily ORDER BY attendance_date DESC");

$totalRecords = count($attendanceRecords);
$processed = 0;
$errors = 0;

echo "Found {$totalRecords} attendance records to recompute.\n\n";

foreach ($attendanceRecords as $record) {
    try {
        $employeeId = $record['employee_id'];
        $attendanceDate = $record['attendance_date'];
        $shiftId = $record['shift_id'];
        
        echo "Recomputing: Employee ID {$employeeId}, Date {$attendanceDate}...";
        
        // Recompute attendance for this record
        $attendance->recomputeAttendanceDaily($employeeId, $attendanceDate, $shiftId);
        
        echo " ✓\n";
        $processed++;
        
    } catch (Exception $e) {
        echo " ✗ Error: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nRecomputation complete.\n";
echo "Processed: {$processed}/{$totalRecords}\n";
echo "Errors: {$errors}\n";

if ($errors > 0) {
    echo "\nSome records failed to recompute. Please check the errors above.\n";
} else {
    echo "\nAll records successfully recomputed!\n";
}
