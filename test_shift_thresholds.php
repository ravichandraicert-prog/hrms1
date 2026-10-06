<?php
/**
 * Test script for shift-wise attendance status thresholds
 *
 * Verifies that:
 *   1. Multiple shifts can have different threshold values and each shift uses ONLY its own.
 *   2. Changing one shift's thresholds does not affect employees of another shift.
 *   3. An employee's status is always computed from their own assigned shift.
 *   4. Shifts without a per-shift configuration (and employees without a shift) keep the
 *      existing organization-wide (centralized) behavior - backward compatibility.
 *
 * All temporary shifts / employees created here are removed before the script exits.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/attendance_threshold_service.php';

echo "=== Shift-Wise Attendance Threshold Test ===\n\n";

$thresholdService = new AttendanceThresholdService();
$db = Database::getInstance();

$pass = 0;
$fail = 0;
function check($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        echo "✓ {$label}\n";
        $pass++;
    } else {
        echo "✗ {$label}  {$extra}\n";
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// Test 1: Service / storage availability
// ---------------------------------------------------------------------------
echo "Test 1: Service availability...\n";
check('AttendanceThresholdService instantiated', is_object($thresholdService));
check('getShiftThresholds is callable', method_exists($thresholdService, 'getShiftThresholds'));
check('shifts table has present_threshold_minutes column',
    (bool) $db->fetchOne("SELECT present_threshold_minutes FROM shifts LIMIT 1"));

// ---------------------------------------------------------------------------
// Create temporary test data (cleaned up at the end)
// ---------------------------------------------------------------------------
$suffix = rand(10000, 99999);
$cleanupShiftIds = [];
$cleanupEmployeeIds = [];

function createTempShift($db, $name, $present, $half, $absent) {
    $id = $db->insert('shifts', [
        'name' => $name,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'present_threshold_minutes' => $present,
        'half_day_threshold_minutes' => $half,
        'absent_threshold_minutes' => $absent,
        'is_active' => 1
    ]);
    return $id;
}

function createTempEmployee($db, $email, $shiftId) {
    $empId = "TEST" . rand(100000, 999999);
    $id = $db->insert('employees', [
        'employee_id' => $empId,
        'first_name' => 'Test',
        'last_name' => 'Shift',
        'official_email' => $email,
        'phone_number' => '0000000000',
        'shift_id' => $shiftId,
        'is_active' => 1
    ]);
    return $id;
}

echo "\nCreating temporary test shifts/employees...\n";
// Mirror the task example: Shift A=15min, Shift B=30min, Shift C=45min (Present thresholds)
$shiftA = createTempShift($db, "__TEST_SHIFT_A_{$suffix}", 15, 8, 5);
$shiftB = createTempShift($db, "__TEST_SHIFT_B_{$suffix}", 30, 15, 10);
$shiftC = createTempShift($db, "__TEST_SHIFT_C_{$suffix}", 45, 30, 20);
// Unconfigured shift (present NULL) mirroring existing production shifts
$shiftD = createTempShift($db, "__TEST_SHIFT_D_{$suffix}", null, 240, 120);
$cleanupShiftIds = [$shiftA, $shiftB, $shiftC, $shiftD];

echo "  Created shifts: A={$shiftA} B={$shiftB} C={$shiftC} D(no-config)={$shiftD}\n";

$empA = createTempEmployee($db, "testa_{$suffix}@example.com", $shiftA);
$empB = createTempEmployee($db, "testb_{$suffix}@example.com", $shiftB);
$empC = createTempEmployee($db, "testc_{$suffix}@example.com", $shiftC);
$empD = createTempEmployee($db, "testd_{$suffix}@example.com", $shiftD);
$empNone = createTempEmployee($db, "testnone_{$suffix}@example.com", null);
$cleanupEmployeeIds = [$empA, $empB, $empC, $empD, $empNone];

echo "  Created employees: A={$empA} B={$empB} C={$empC} D={$empD} none={$empNone}\n";

// ---------------------------------------------------------------------------
// Find a clean test date (no leave / holiday / week-off interference)
// ---------------------------------------------------------------------------
$testDate = null;
for ($i = 0; $i <= 40; $i++) {
    $ts = time() - $i * 86400;
    if ((int) date('N', $ts) === 7) {
        continue; // default pattern is Monday-Saturday working; skip Sundays
    }
    $candidate = date('Y-m-d', $ts);
    // Probe with the no-shift employee: at 300 min the centralized path must be reachable
    $status = $thresholdService->calculateStatus(300, $candidate, $empNone);
    if ($status === 'Present' || $status === 'Half Day' || $status === 'Absent') {
        $testDate = $candidate;
        break;
    }
}
check('Found a clean test date: ' . ($testDate !== null ? $testDate : '(none)'), $testDate !== null);

// ---------------------------------------------------------------------------
// Test 2: Multiple shifts with different thresholds -> different statuses
// ---------------------------------------------------------------------------
echo "\nTest 2: Same worked minutes, different shifts -> different statuses...\n";
// 18 min: above A's Present cutoff (15), inside B's Half Day band (15..29), below C's Half Day cutoff (30)
$sA = $thresholdService->calculateStatus(18, $testDate, $empA);
$sB = $thresholdService->calculateStatus(18, $testDate, $empB);
$sC = $thresholdService->calculateStatus(18, $testDate, $empC);
check('Shift A (Present>=15): 18 min -> Present', $sA === 'Present', "got '{$sA}'");
check('Shift B (Present>=30, Half>=15): 18 min -> Half Day', $sB === 'Half Day', "got '{$sB}'");
check('Shift C (Half>=30): 18 min -> Absent', $sC === 'Absent', "got '{$sC}'");

// Per-shift boundary verification
check('Shift A: 15 min -> Present (boundary)', $thresholdService->calculateStatus(15, $testDate, $empA) === 'Present', "got '{$thresholdService->calculateStatus(15, $testDate, $empA)}'");
check('Shift A: 14 min -> Half Day', $thresholdService->calculateStatus(14, $testDate, $empA) === 'Half Day', "got '{$thresholdService->calculateStatus(14, $testDate, $empA)}'");
check('Shift A: 8 min -> Half Day (boundary)', $thresholdService->calculateStatus(8, $testDate, $empA) === 'Half Day', "got '{$thresholdService->calculateStatus(8, $testDate, $empA)}'");
check('Shift A: 7 min -> Absent', $thresholdService->calculateStatus(7, $testDate, $empA) === 'Absent', "got '{$thresholdService->calculateStatus(7, $testDate, $empA)}'");

check('Shift B: 30 min -> Present (boundary)', $thresholdService->calculateStatus(30, $testDate, $empB) === 'Present', "got '{$thresholdService->calculateStatus(30, $testDate, $empB)}'");
check('Shift B: 29 min -> Half Day', $thresholdService->calculateStatus(29, $testDate, $empB) === 'Half Day', "got '{$thresholdService->calculateStatus(29, $testDate, $empB)}'");
check('Shift B: 15 min -> Half Day (boundary)', $thresholdService->calculateStatus(15, $testDate, $empB) === 'Half Day', "got '{$thresholdService->calculateStatus(15, $testDate, $empB)}'");
check('Shift B: 14 min -> Absent', $thresholdService->calculateStatus(14, $testDate, $empB) === 'Absent', "got '{$thresholdService->calculateStatus(14, $testDate, $empB)}'");

check('Shift C: 45 min -> Present (boundary)', $thresholdService->calculateStatus(45, $testDate, $empC) === 'Present', "got '{$thresholdService->calculateStatus(45, $testDate, $empC)}'");
check('Shift C: 44 min -> Half Day', $thresholdService->calculateStatus(44, $testDate, $empC) === 'Half Day', "got '{$thresholdService->calculateStatus(44, $testDate, $empC)}'");
check('Shift C: 30 min -> Half Day (boundary)', $thresholdService->calculateStatus(30, $testDate, $empC) === 'Half Day', "got '{$thresholdService->calculateStatus(30, $testDate, $empC)}'");
check('Shift C: 29 min -> Absent', $thresholdService->calculateStatus(29, $testDate, $empC) === 'Absent', "got '{$thresholdService->calculateStatus(29, $testDate, $empC)}'");

// ---------------------------------------------------------------------------
// Test 3: Shifts are isolated - changing Shift B must not affect A or C
// ---------------------------------------------------------------------------
echo "\nTest 3: Isolation - updating Shift B thresholds leaves A and C unchanged...\n";
$db->update('shifts', ['present_threshold_minutes' => 60, 'half_day_threshold_minutes' => 30], 'id = ?', [$shiftB]);

$sA = $thresholdService->calculateStatus(18, $testDate, $empA);
$sB = $thresholdService->calculateStatus(18, $testDate, $empB);
$sC = $thresholdService->calculateStatus(18, $testDate, $empC);
check('Shift A (still 15): 18 min -> Present (unaffected)', $sA === 'Present', "got '{$sA}'");
check('Shift B (now 60/30): 18 min -> Absent (uses new B thresholds)', $sB === 'Absent', "got '{$sB}'");
check('Shift C (still 45/30): 18 min -> Absent (unaffected)', $sC === 'Absent', "got '{$sC}'");

$db->update('shifts', ['present_threshold_minutes' => 30, 'half_day_threshold_minutes' => 15], 'id = ?', [$shiftB]);
$sB = $thresholdService->calculateStatus(18, $testDate, $empB);
check('Shift B restored: 18 min -> Half Day again', $sB === 'Half Day', "got '{$sB}'");

// ---------------------------------------------------------------------------
// Test 4: Employee -> assigned shift mapping
// ---------------------------------------------------------------------------
echo "\nTest 4: Employee uses ONLY the thresholds of their own assigned shift...\n";
$db->update('employees', ['shift_id' => $shiftB], 'id = ?', [$empA]);
$sA = $thresholdService->calculateStatus(18, $testDate, $empA);
check('Employee moved A -> B: 18 min now Half Day (B threshold 30/15)', $sA === 'Half Day', "got '{$sA}'");

$db->update('employees', ['shift_id' => $shiftC], 'id = ?', [$empA]);
$sA = $thresholdService->calculateStatus(18, $testDate, $empA);
check('Employee moved B -> C: 18 min now Absent (C threshold 45/30)', $sA === 'Absent', "got '{$sA}'");

$db->update('employees', ['shift_id' => $shiftA], 'id = ?', [$empA]);
$sA = $thresholdService->calculateStatus(18, $testDate, $empA);
check('Employee moved back A: 18 min -> Present (A threshold 15)', $sA === 'Present', "got '{$sA}'");

// ---------------------------------------------------------------------------
// Test 5: Backward compatibility - unconfigured shift -> org-wide defaults
// ---------------------------------------------------------------------------
echo "\nTest 5: Unconfigured shift / no shift -> organization-wide defaults...\n";
// Shift D has present = NULL -> centralized thresholds (present 495 / half day 255) apply
check('Shift D: 300 min -> Half Day (org 255..494)',
    $thresholdService->calculateStatus(300, $testDate, $empD) === 'Half Day',
    "got '{$thresholdService->calculateStatus(300, $testDate, $empD)}'");
check('Shift D: 494 min -> Half Day',
    $thresholdService->calculateStatus(494, $testDate, $empD) === 'Half Day');
check('Shift D: 495 min -> Present',
    $thresholdService->calculateStatus(495, $testDate, $empD) === 'Present');
check('Shift D: 254 min -> Absent',
    $thresholdService->calculateStatus(254, $testDate, $empD) === 'Absent');

// Employee with NO shift -> centralized defaults too
check('No shift: 300 min -> Half Day',
    $thresholdService->calculateStatus(300, $testDate, $empNone) === 'Half Day',
    "got '{$thresholdService->calculateStatus(300, $testDate, $empNone)}'");
check('No shift: 495 min -> Present',
    $thresholdService->calculateStatus(495, $testDate, $empNone) === 'Present');
check('No shift: 254 min -> Absent',
    $thresholdService->calculateStatus(254, $testDate, $empNone) === 'Absent');

// ---------------------------------------------------------------------------
// Test 6: getShiftThresholds reads the shift's own configuration
// ---------------------------------------------------------------------------
echo "\nTest 6: getShiftThresholds returns each shift's own values...\n";
$tsA = $thresholdService->getShiftThresholds($shiftA);
check('Shift A: present = 15', $tsA && (int) $tsA['present_threshold_minutes'] === 15, json_encode($tsA));
check('Shift A: half day = 8', $tsA && (int) $tsA['half_day_threshold_minutes'] === 8, json_encode($tsA));
$tsD = $thresholdService->getShiftThresholds($shiftD);
check('Shift D: present is NULL (unconfigured)', $tsD && $tsD['present_threshold_minutes'] === null, json_encode($tsD));
$tsMissing = $thresholdService->getShiftThresholds(99999999);
check('Unknown shift id -> false', $tsMissing === false, json_encode($tsMissing));

// ---------------------------------------------------------------------------
// Test 7: Existing centralized boundary behavior still intact (regression)
// ---------------------------------------------------------------------------
echo "\nTest 7: Centralized boundary regression (employee without shift)...\n";
$boundary = [
    [495, 'Present'],
    [510, 'Present'],
    [256, 'Half Day'],
    [255, 'Half Day'],
    [254, 'Absent'],
    [240, 'Absent'],
    [0, 'Absent']
];
foreach ($boundary as $case) {
    $status = $thresholdService->calculateStatus($case[0], $testDate, $empNone);
    check("{$case[0]} min -> {$case[1]}", $status === $case[1], "got '{$status}'");
}

// ---------------------------------------------------------------------------
// Cleanup temporary data (always runs)
// ---------------------------------------------------------------------------
echo "\nCleanup: removing temporary test data...\n";
foreach ($cleanupEmployeeIds as $eid) {
    try { $db->delete('employees', 'id = ?', [$eid]); } catch (Exception $e) {}
}
foreach ($cleanupShiftIds as $sid) {
    try { $db->delete('shifts', 'id = ?', [$sid]); } catch (Exception $e) {}
}
$leftoverShifts = $db->fetchOne("SELECT COUNT(*) as c FROM shifts WHERE name LIKE '%TEST_SHIFT_A%' OR name LIKE '%TEST_SHIFT_B%' OR name LIKE '%TEST_SHIFT_C%' OR name LIKE '%TEST_SHIFT_D%'");
$leftoverEmps = $db->fetchOne("SELECT COUNT(*) as c FROM employees WHERE first_name = 'Test' AND last_name = 'Shift' AND official_email LIKE 'test%@example.com'");
check('Temporary shifts cleaned up', (int) ($leftoverShifts ? $leftoverShifts['c'] : 1) === 0);
check('Temporary employees cleaned up', (int) ($leftoverEmps ? $leftoverEmps['c'] : 1) === 0);

// ---------------------------------------------------------------------------
echo "\n=== Test Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
if ($fail > 0) {
    echo "RESULT: FAILURES PRESENT\n";
    exit(1);
}
echo "RESULT: ALL SHIFT-WISE THRESHOLD TESTS PASSED\n";
