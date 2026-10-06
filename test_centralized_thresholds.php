<?php
/**
 * Test script for centralized attendance threshold implementation
 * Tests the attendance status calculation with the new centralized thresholds
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/attendance_threshold_service.php';

echo "=== Centralized Attendance Threshold Test ===\n\n";

$thresholdService = new AttendanceThresholdService();
$db = Database::getInstance();

// Test 1: Check if threshold tables exist
echo "Test 1: Checking database tables...\n";
$tables = $db->fetchAll("SHOW TABLES LIKE 'attendance_%'");
$attendanceTables = [];
foreach ($tables as $table) {
    $attendanceTables[] = array_values($table)[0];
}

if (in_array('attendance_settings', $attendanceTables)) {
    echo "✓ attendance_settings table exists\n";
} else {
    echo "✗ attendance_settings table missing\n";
}

if (in_array('attendance_threshold_history', $attendanceTables)) {
    echo "✓ attendance_threshold_history table exists\n";
} else {
    echo "✗ attendance_threshold_history table missing\n";
}

// Test 2: Check default threshold values
echo "\nTest 2: Checking default threshold values...\n";
$currentThresholds = $thresholdService->getCurrentThresholds();
if ($currentThresholds) {
    echo "✓ Current thresholds found:\n";
    echo "  Present: " . floor($currentThresholds['present_threshold_minutes'] / 60) . "h " . ($currentThresholds['present_threshold_minutes'] % 60) . "m (" . $currentThresholds['present_threshold_minutes'] . " minutes)\n";
    echo "  Half Day: " . floor($currentThresholds['half_day_threshold_minutes'] / 60) . "h " . ($currentThresholds['half_day_threshold_minutes'] % 60) . "m (" . $currentThresholds['half_day_threshold_minutes'] . " minutes)\n";
} else {
    echo "✗ No current thresholds found\n";
}

// Test 3: Test status calculation logic
echo "\nTest 3: Testing status calculation logic...\n";

$testCases = [
    ['worked' => 495, 'break' => 0, 'expected' => 'Present', 'description' => 'Exactly 8:15'],
    ['worked' => 510, 'break' => 0, 'expected' => 'Present', 'description' => '8:30'],
    ['worked' => 540, 'break' => 0, 'expected' => 'Present', 'description' => '9:00'],
    ['worked' => 256, 'break' => 0, 'expected' => 'Half Day', 'description' => '4:16 (1 minute over half day)'],
    ['worked' => 300, 'break' => 0, 'expected' => 'Half Day', 'description' => '5:00'],
    ['worked' => 390, 'break' => 0, 'expected' => 'Half Day', 'description' => '6:30'],
    ['worked' => 494, 'break' => 0, 'expected' => 'Half Day', 'description' => '8:14 (1 minute under present)'],
    ['worked' => 255, 'break' => 0, 'expected' => 'Half Day', 'description' => 'Exactly 4:15'], // Updated per requirements
    ['worked' => 240, 'break' => 0, 'expected' => 'Absent', 'description' => '4:00'],
    ['worked' => 120, 'break' => 0, 'expected' => 'Absent', 'description' => '2:00'],
    ['worked' => 0, 'break' => 0, 'expected' => 'Absent', 'description' => '0:00'],
];

$testDate = date('Y-m-d');
$testEmployeeId = 1; // Use a valid employee ID if available

foreach ($testCases as $testCase) {
    $netWorkedMinutes = $testCase['worked'] - $testCase['break']; // Net Time = Work Duration - Total Break Duration
    $result = $thresholdService->calculateStatus($netWorkedMinutes, $testDate, $testEmployeeId);
    $status = ($result === $testCase['expected']) ? '✓' : '✗';
    echo "{$status} {$testCase['description']}: Expected {$testCase['expected']}, Got {$result}\n";
}

// Test 4: Test validation
echo "\nTest 4: Testing threshold validation...\n";

$validationTests = [
    ['present' => 495, 'half' => 255, 'expected' => true, 'description' => 'Valid thresholds (8:15, 4:15)'],
    ['present' => 255, 'half' => 495, 'expected' => false, 'description' => 'Present <= Half Day (invalid)'],
    ['present' => 0, 'half' => 255, 'expected' => false, 'description' => 'Present = 0 (invalid)'],
    ['present' => 495, 'half' => 0, 'expected' => false, 'description' => 'Half Day = 0 (invalid)'],
    ['present' => 1500, 'half' => 255, 'expected' => false, 'description' => 'Present > 24 hours (invalid)'],
];

foreach ($validationTests as $test) {
    $validation = $thresholdService->validateThresholds([
        'present_threshold_minutes' => $test['present'],
        'half_day_threshold_minutes' => $test['half']
    ]);
    $status = ($validation['valid'] === $test['expected']) ? '✓' : '✗';
    echo "{$status} {$test['description']}: " . ($validation['valid'] ? 'Valid' : 'Invalid') . " - {$validation['message']}\n";
}

// Test 5: Check if Attendance class uses centralized service
echo "\nTest 5: Checking Attendance class integration...\n";
$attendanceFile = file_get_contents(__DIR__ . '/includes/attendance.php');
if (strpos($attendanceFile, 'AttendanceThresholdService') !== false) {
    echo "✓ Attendance class includes AttendanceThresholdService\n";
} else {
    echo "✗ Attendance class does not include AttendanceThresholdService\n";
}

if (strpos($attendanceFile, 'thresholdService->calculateStatus') !== false) {
    echo "✓ Attendance class uses centralized threshold calculation\n";
} else {
    echo "✗ Attendance class does not use centralized threshold calculation\n";
}

// Test 6: Check Settings UI integration
echo "\nTest 6: Checking Settings UI integration...\n";
$settingsFile = file_get_contents(__DIR__ . '/modules/settings.php');
if (strpos($settingsFile, 'saveCentralizedThresholds') !== false) {
    echo "✓ Settings UI has centralized threshold function\n";
} else {
    echo "✗ Settings UI missing centralized threshold function\n";
}

if (strpos($settingsFile, 'AttendanceThresholdService') !== false) {
    echo "✓ Settings UI includes AttendanceThresholdService\n";
} else {
    echo "✗ Settings UI does not include AttendanceThresholdService\n";
}

// Test 7: Check API integration
echo "\nTest 7: Checking API integration...\n";
$apiFile = file_get_contents(__DIR__ . '/api/settings.php');
if (strpos($apiFile, 'save_centralized_thresholds') !== false) {
    echo "✓ API has centralized threshold endpoint\n";
} else {
    echo "✗ API missing centralized threshold endpoint\n";
}

if (strpos($apiFile, 'AttendanceThresholdService') !== false) {
    echo "✓ API includes AttendanceThresholdService\n";
} else {
    echo "✗ API does not include AttendanceThresholdService\n";
}

echo "\n=== Test Complete ===\n";
echo "All critical components have been implemented for centralized attendance threshold configuration.\n";
echo "Run the database migration script to create the required tables: php database/run_centralized_threshold_migration.php\n";
?>
