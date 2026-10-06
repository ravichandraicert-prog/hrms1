<?php
/**
 * Test script for weekend attendance logic
 * Tests that weekend attendance overrides Week Off status
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/attendance_threshold_service.php';

$db = Database::getInstance();
$thresholdService = new AttendanceThresholdService();

echo "<h2>Weekend Attendance Logic Test</h2>";

// Test 1: Employee with attendance on Saturday (Present threshold)
echo "<h3>Test 1: Employee with 8+ hours on Saturday</h3>";
$testDate = '2026-08-23'; // Saturday
$testEmployeeId = 1; // Assuming employee 1 exists
$workedMinutes = 510; // 8.5 hours (above present threshold of 495)
$breakMinutes = 15;
$netWorkedMinutes = $workedMinutes - $breakMinutes; // Net Time = Work Duration - Total Break Duration

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Saturday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Present<br><br>";

// Test 2: Employee with attendance on Saturday (Half Day threshold)
echo "<h3>Test 2: Employee with 5 hours on Saturday</h3>";
$workedMinutes = 300; // 5 hours (above half day threshold of 255, below present threshold of 495)
$breakMinutes = 0;
$netWorkedMinutes = $workedMinutes - $breakMinutes;

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Saturday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Half Day<br><br>";

// Test 3: Employee with attendance on Saturday (Absent threshold)
echo "<h3>Test 3: Employee with 2 hours on Saturday</h3>";
$workedMinutes = 120; // 2 hours (below half day threshold of 255)
$breakMinutes = 0;
$netWorkedMinutes = $workedMinutes - $breakMinutes;

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Saturday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Absent<br><br>";

// Test 4: Employee with NO attendance on Saturday (should be Week Off)
echo "<h3>Test 4: Employee with NO attendance on Saturday</h3>";
$workedMinutes = 0; // No attendance
$breakMinutes = 0;
$netWorkedMinutes = $workedMinutes - $breakMinutes;

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Saturday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Week Off<br><br>";

// Test 5: Employee with attendance on Sunday (Present threshold)
echo "<h3>Test 5: Employee with 8+ hours on Sunday</h3>";
$testDate = '2026-08-24'; // Sunday
$workedMinutes = 510; // 8.5 hours (above present threshold of 495)
$breakMinutes = 15;
$netWorkedMinutes = $workedMinutes - $breakMinutes;

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Sunday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Present<br><br>";

// Test 6: Employee with NO attendance on Sunday (should be Week Off)
echo "<h3>Test 6: Employee with NO attendance on Sunday</h3>";
$workedMinutes = 0; // No attendance
$breakMinutes = 0;
$netWorkedMinutes = $workedMinutes - $breakMinutes;

$status = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
echo "Date: $testDate (Sunday)<br>";
echo "Worked: " . formatMinutes($workedMinutes) . "<br>";
echo "Break: " . formatMinutes($breakMinutes) . "<br>";
echo "Net: " . formatMinutes($netWorkedMinutes) . "<br>";
echo "Status: <strong>$status</strong><br>";
echo "Expected: Week Off<br><br>";

// Test 7: Check actual attendance_daily records for weekend
echo "<h3>Test 7: Check actual weekend attendance records</h3>";
$weekendRecords = $db->fetchAll(
    "SELECT ad.*, e.first_name, e.last_name 
     FROM attendance_daily ad
     JOIN employees e ON ad.employee_id = e.id
     WHERE DAYOFWEEK(ad.attendance_date) IN (1, 7) 
     AND ad.total_worked_minutes > 0
     ORDER BY ad.attendance_date DESC
     LIMIT 5"
);

if (empty($weekendRecords)) {
    echo "No weekend attendance records found with work time > 0<br>";
} else {
    echo "Found " . count($weekendRecords) . " weekend attendance records:<br>";
    foreach ($weekendRecords as $record) {
        $dayName = date('l', strtotime($record['attendance_date']));
        echo "- {$record['first_name']} {$record['last_name']}: {$record['attendance_date']} ($dayName) - {$record['status']} (" . formatMinutes($record['total_worked_minutes']) . ")<br>";
    }
}

echo "<h3>Test Summary</h3>";
echo "✅ Weekend attendance should override Week Off status when actual work exists<br>";
echo "✅ Threshold logic should apply to weekend work same as weekdays<br>";
echo "✅ Week Off should only apply when no attendance records exist<br>";

function formatMinutes($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'h ' . $mins . 'm';
}
?>
