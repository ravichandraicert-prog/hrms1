<?php
require_once 'config/database.php';
require_once 'config/config.php';

$db = Database::getInstance();

// Get employee ID for EMP7467
$employee = $db->fetchOne("SELECT id FROM employees WHERE employee_id = 'EMP7467'");
if (!$employee) {
    echo "Employee not found\n";
    exit;
}

$empId = $employee['id'];
echo "Employee ID: $empId\n\n";

// Check attendance_punches for this employee
echo "=== Attendance Punches ===\n";
$punches = $db->fetchAll("SELECT * FROM attendance_punches WHERE employee_id = ? ORDER BY timestamp_utc DESC LIMIT 20", [$empId]);
foreach ($punches as $punch) {
    echo "ID: {$punch['id']}, Event: {$punch['event_type']}, Time: {$punch['timestamp_utc']}, Break Type: {$punch['break_type_id']}, Date: {$punch['attendance_date']}\n";
}

// Check attendance_daily for this employee
echo "\n=== Attendance Daily ===\n";
$attendanceDaily = $db->fetchAll("SELECT * FROM attendance_daily WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 5", [$empId]);
foreach ($attendanceDaily as $daily) {
    echo "Date: {$daily['attendance_date']}, Worked: {$daily['total_worked_minutes']}, Break: {$daily['total_break_minutes']}, Net: " . ($daily['net_worked_minutes'] ?? 'N/A') . "\n";
}

// Check break_types table
echo "\n=== Break Types ===\n";
$breakTypes = $db->fetchAll("SELECT * FROM break_types");
foreach ($breakTypes as $type) {
    echo "ID: {$type['id']}, Name: {$type['name']}, Deduct: {$type['deduct_from_work_time']}\n";
}
