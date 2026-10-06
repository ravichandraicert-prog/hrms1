<?php
/**
 * Reports API - CSV Export
 * Core PHP HRMS Application
 * Section 15 - Reports module
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';

$auth = new Auth();
$auth->requireLogin();
$user = getCurrentUser();
$db = Database::getInstance();
$permissions = new Permissions();

if (!hasPermission('reports', 'export') && !hasPermission('reports', 'read')) {
    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
}

// Get scoped employee IDs for reports
$scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'reports', 'read');
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// =====================================================================
// FILTER OPTIONS - roles & employees available for the filter dropdowns
// =====================================================================
$roles = [];
try {
    $roles = $db->fetchAll("SELECT id, name FROM roles WHERE is_active = 1 ORDER BY hierarchy_level, name");
} catch (Exception $e) { $roles = []; }

$scopeEmployees = [];
try {
    $scopeEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$scopedIdsStr})
         ORDER BY e.first_name, e.last_name"
    );
} catch (Exception $e) { $scopeEmployees = []; }

// =====================================================================
// REPORTS SCOPING
// Privileged roles (HR Manager / Super Admin / Admin) -> ALL employees
// Everyone else (Senior Manager, Manager, Team Lead and below levels):
//   - DIRECT BRANCH (Attendance + Payroll): reporting tree only
//     (self + recursive reports via reporting_manager_id)
//   - LEAVE SCOPE: direct branch UNION secondary-department employees
//     (active employees whose SECONDARY department is one of the
//     viewer's own primary/secondary departments)
// =====================================================================
$privilegedRoleNames = ['super admin', 'admin', 'hr manager'];
$userRoleName = strtolower((string) ($user['role_name'] ?? ''));
$isPrivilegedHierarchyView = in_array($userRoleName, $privilegedRoleNames) || (int) ($user['hierarchy_level'] ?? 999) <= 3;

// --- Direct branch scope (Attendance + Payroll) ---
$directBranchIds = $scopedIds;
$leaveScopeIds = $scopedIds;

if ($isPrivilegedHierarchyView) {
    try {
        $allEmpRows = $db->fetchAll("SELECT id FROM employees");
        $allEmployeeIds = array_column($allEmpRows, 'id');
        $directBranchIds = $allEmployeeIds;
        $leaveScopeIds = $allEmployeeIds;
    } catch (Exception $e) {
        $directBranchIds = $scopedIds;
        $leaveScopeIds = $scopedIds;
    }
} else {
    // Direct hierarchy branching (reporting tree: self + recursive reports)
    $directBranchIds = $permissions->getReportingTree($user['id']);

    // Leave additionally includes secondary-department employees
    $secondaryDeptIds = [];
    try {
        $secondaryDeptIds = array_map('intval', array_column(
            $db->fetchAll(
                "SELECT DISTINCT ed.employee_id AS id
                 FROM employee_departments ed
                 JOIN employees se ON se.id = ed.employee_id AND se.is_active = 1
                 WHERE ed.is_primary = 0
                   AND ed.department_id IN (
                       SELECT ed2.department_id FROM employee_departments ed2 WHERE ed2.employee_id = ?
                   )",
                [$user['id']]
            ),
            'id'
        ));
    } catch (Exception $e) { $secondaryDeptIds = []; }
    $leaveScopeIds = array_values(array_unique(array_merge($directBranchIds, $secondaryDeptIds)));
}

$directBranchIdsStr = implode(',', $directBranchIds) ?: '0';
$leaveScopeIdsStr = implode(',', $leaveScopeIds) ?: '0';

$tab = $_GET['tab'] ?? 'attendance';

// =====================================================================
// HELPER: Get default pay cycle period dates
// =====================================================================
function getDefaultPayCyclePeriodDates() {
    $db = Database::getInstance();
    
    // Get default pay cycle
    $defaultCycle = $db->fetchOne("SELECT * FROM pay_cycles WHERE is_default = 1 AND is_active = 1 LIMIT 1");
    
    if (!$defaultCycle) {
        // Fallback to calendar month if no default pay cycle
        return [
            'start' => date('Y-m-01'),
            'end' => date('Y-m-t')
        ];
    }
    
    // Calculate current period based on pay cycle rules
    $currentDate = new DateTime();
    $cycleStartDay = (int) $defaultCycle['cycle_start_day'];
    $cycleEndDay = (int) $defaultCycle['cycle_end_day'];
    
    // Calculate period start
    $periodStart = clone $currentDate;
    $periodStart->setDate((int)$currentDate->format('Y'), (int)$currentDate->format('m'), $cycleStartDay);
    
    // Calculate period end
    $periodEnd = clone $currentDate;
    if ($cycleEndDay == 99) {
        // Last day of month
        $periodEnd->modify('last day of this month');
    } else {
        $periodEnd->setDate((int)$currentDate->format('Y'), (int)$currentDate->format('m'), $cycleEndDay);
    }
    
    // If end day is before start day, it means the period spans to next month
    if ($periodEnd < $periodStart) {
        $periodEnd->modify('+1 month');
        $periodEnd->setDate((int)$periodEnd->format('Y'), (int)$periodEnd->format('m'), $cycleEndDay);
        if ($cycleEndDay == 99) {
            $periodEnd->modify('last day of this month');
        }
    }
    
    // If current date is before the period start, we need to go back to previous period
    if ($currentDate < $periodStart) {
        $periodStart->modify('-1 month');
        $periodEnd->modify('-1 month');
        
        // Recalculate end day for previous month
        if ($cycleEndDay == 99) {
            $periodEnd->modify('last day of this month');
        } else {
            $periodEnd->setDate((int)$periodEnd->format('Y'), (int)$periodEnd->format('m'), $cycleEndDay);
        }
    }
    
    return [
        'start' => $periodStart->format('Y-m-d'),
        'end' => $periodEnd->format('Y-m-d')
    ];
}

// ------- Attendance summary (mirrors reports.php) -------
// Get default pay cycle period dates
$defaultPeriod = getDefaultPayCyclePeriodDates();
$attStart    = $_GET['start'] ?? $defaultPeriod['start'];
$attEnd      = $_GET['end']   ?? $defaultPeriod['end'];
$deptFilter  = $_GET['department'] ?? '';
$employeeFilter = $_GET['employee'] ?? '';
$roleFilter = $_GET['role'] ?? '';

$attRows = [];
try {
    $params = [$attStart, $attEnd];
    $deptCond = '';
    if ($deptFilter) {
        $deptCond = "AND EXISTS (SELECT 1 FROM employee_departments ed
                     WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
        $params[] = $deptFilter;
    }
    if ($employeeFilter) {
        $deptCond .= " AND e.id = ?";
        $params[] = $employeeFilter;
    }
    if ($roleFilter) {
        $deptCond .= " AND e.role_id = ?";
        $params[] = $roleFilter;
    }
    $attRows = $db->fetchAll(
        "SELECT ad.*, e.id as emp_pk
         FROM attendance_daily ad
         JOIN employees e ON ad.employee_id = e.id
         WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr}) AND ad.attendance_date BETWEEN ? AND ? {$deptCond}
         ORDER BY e.first_name, e.last_name, ad.attendance_date",
        $params
    );
} catch (Exception $e) { $attRows = []; }

function apiReportIsLate($record, $startTime = null, $graceMinutes = null) {
    if (empty($record['first_in']) || empty($startTime)) return false;
    $local = utcToLocal($record['first_in'], 'H:i');
    [$h, $m] = array_map('intval', explode(':', $local));
    [$sh, $sm] = array_map('intval', explode(':', $startTime));
    $grace = (int) ($graceMinutes ?? 0);
    return ($h * 60 + $m) > ($sh * 60 + $sm + $grace);
}

// Calculate total calendar days in the date range
function getTotalDaysInRange($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = $start->diff($end);
    return $interval->days + 1; // Include both start and end dates
}

$totalDaysInRange = getTotalDaysInRange($attStart, $attEnd);

// First get all active employees in scope with their departments
$empParams = [];
$empDeptCond = '';
if ($deptFilter) {
    $empDeptCond = "AND EXISTS (SELECT 1 FROM employee_departments ed
                 WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
    $empParams[] = $deptFilter;
}
if ($employeeFilter) {
    $empDeptCond .= " AND e.id = ?";
    $empParams[] = $employeeFilter;
}
if ($roleFilter) {
    $empDeptCond .= " AND e.role_id = ?";
    $empParams[] = $roleFilter;
}

$allEmployees = $db->fetchAll(
    "SELECT e.id as emp_pk, e.first_name, e.last_name, e.employee_id,
            s.start_time, s.grace_in_minutes,
            d.name as department_name
     FROM employees e
     LEFT JOIN shifts s ON e.shift_id = s.id
     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
     LEFT JOIN departments d ON ed.department_id = d.id
     WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr})
       {$empDeptCond}
     ORDER BY e.first_name, e.last_name",
    $empParams
);

// Initialize all employees in summary with default values
$attSummary = [];
foreach ($allEmployees as $emp) {
    $pk = $emp['emp_pk'];
    $attSummary[$pk] = [
        'emp_pk' => $pk,
        'first_name' => $emp['first_name'], 'last_name' => $emp['last_name'],
        'employee_id' => $emp['employee_id'], 'department_name' => $emp['department_name'] ?? '-',
        'present' => 0, 'absent' => 0, 'late' => 0, 'half' => 0, 'leave' => 0, 'week_off' => 0, 'total' => 0, 'working_days' => $totalDaysInRange,
        'start_time' => $emp['start_time'], 'grace_in_minutes' => $emp['grace_in_minutes']
    ];
}

// Aggregate attendance data for employees who have records
foreach ($attRows as $row) {
    $pk = $row['emp_pk'];
    if (!isset($attSummary[$pk])) {
        continue; // Skip if employee not in scope
    }
    $s = &$attSummary[$pk];
    $s['total']++;
    switch ($row['status']) {
        case 'Present': $s['present']++; if (apiReportIsLate($row, $s['start_time'], $s['grace_in_minutes'])) $s['late']++; break;
        case 'Absent': 
            // Don't count explicit absent - will be calculated dynamically
            break;
        case 'Half Day': $s['half']++; $s['present'] += 0.5; break;
        case 'On Leave': case 'Leave': $s['leave']++; break;
        case 'First Half Leave':
            $s['leave'] += 0.5;
            $s['half']++;
            // Check if there are punches for the second half
            $hasPunches = $db->fetchOne(
                "SELECT COUNT(*) as count FROM attendance_punches 
                 WHERE employee_id = ? AND attendance_date = ?",
                [$row['emp_pk'], $row['attendance_date']]
            );
            if ($hasPunches && $hasPunches['count'] > 0) {
                $s['present'] += 0.5;
            }
            break;
        case 'Second Half Leave':
            $s['leave'] += 0.5;
            $s['half']++;
            // Check if there are punches for the first half
            $hasPunches = $db->fetchOne(
                "SELECT COUNT(*) as count FROM attendance_punches 
                 WHERE employee_id = ? AND attendance_date = ?",
                [$row['emp_pk'], $row['attendance_date']]
            );
            if ($hasPunches && $hasPunches['count'] > 0) {
                $s['present'] += 0.5;
            }
            break;
    }
}
unset($s);

// =====================================================================
// WEEK OFF - automatically calculated from each employee's assigned
// Work Pattern (mirrors modules/reports.php). Week Off days are folded
// into the existing combined "Leaves" column; no leave/attendance
// records are created or updated. Dates already counted as
// Present/Half Day/On Leave/Leave/Absent are skipped to avoid
// double-counting (e.g. approved leave covering a Week Off, or actual
// attendance clocked on a scheduled Week Off).
// =====================================================================
$workPatternService = new WorkPatternService();

// emp_pk => [attendance_date => lowercase status] for no-double-count checks
$attStatusByDate = [];
foreach ($attRows as $row) {
    $attStatusByDate[$row['emp_pk']][$row['attendance_date']] = strtolower((string) $row['status']);
}

// Statuses that already consume the day in the summary totals
$statusesAlreadyCounted = ['present', 'absent', 'half day', 'on leave', 'leave', 'first half leave', 'second half leave'];

foreach ($attSummary as &$s) {
    $weekOffDays = 0;
    $offDates = $workPatternService->getEmployeeWeekOffDates($s['emp_pk'], $attStart, $attEnd);
    foreach ($offDates as $offDate => $isOff) {
        $st = $attStatusByDate[$s['emp_pk']][$offDate] ?? null;
        if ($st !== null && in_array($st, $statusesAlreadyCounted, true)) {
            continue; // already counted - never count the same date twice
        }
        $weekOffDays++;
    }
    $s['week_off'] = $weekOffDays;
    $s['leave'] += $weekOffDays; // Week Off belongs in the combined Leaves column
}
unset($s);

// Calculate absent dynamically for each employee
foreach ($attSummary as &$s) {
    // Absent = Total Calendar Days - (Present + Leave + Week Off)
    $calculatedAbsent = $s['working_days'] - ($s['present'] + $s['leave']);
    $s['absent'] = max(0, round($calculatedAbsent, 1)); // Ensure no negative values and round to 1 decimal
}
unset($s);

// ------- Payroll summary (mirrors reports.php) -------
$payPeriod = $_GET['period'] ?? '';
$payEmployeeFilter = $_GET['payroll_employee'] ?? '';
$payRoleFilter = $_GET['payroll_role'] ?? '';
$payRows = []; $payRunInfo = null;
try {
    $payRun = $payPeriod
        ? $db->fetchOne("SELECT * FROM payroll_runs WHERE period_start = ? OR period_end = ? ORDER BY period_end DESC LIMIT 1", [$payPeriod, $payPeriod])
        : $db->fetchOne("SELECT * FROM payroll_runs ORDER BY period_end DESC LIMIT 1");
    if ($payRun) {
        $payRunInfo = $payRun;
        $payParams = [$payRun['id']];
        $payCond = " AND e.id IN ({$directBranchIdsStr})";
        if ($payEmployeeFilter) {
            $payCond .= " AND pl.employee_id = ?";
            $payParams[] = $payEmployeeFilter;
        }
        if ($payRoleFilter) {
            $payCond .= " AND e.role_id = ?";
            $payParams[] = $payRoleFilter;
        }
        $payRows = $db->fetchAll(
            "SELECT pl.*, e.first_name, e.last_name, e.employee_id, d.name as department_name
             FROM payroll_line_items pl
             JOIN employees e ON pl.employee_id = e.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             LEFT JOIN departments d ON ed.department_id = d.id
             WHERE pl.payroll_run_id = ?{$payCond} ORDER BY e.first_name, e.last_name",
            $payParams
        );
    }
} catch (Exception $e) { $payRows = []; }

// ------- Leave summary (mirrors reports.php) -------
$leaveStart = $_GET['leave_start'] ?? date('Y-m-01');
$leaveEnd   = $_GET['leave_end'] ?? date('Y-m-t');
$leaveDeptFilter = $_GET['leave_department'] ?? '';
$leaveEmployeeFilter = $_GET['leave_employee'] ?? '';
$leaveRoleFilter = $_GET['leave_role'] ?? '';

$leaveRows = [];
try {
    $params = [$leaveEnd, $leaveStart];
    $leaveDeptCond = '';
    if ($leaveDeptFilter) {
        $leaveDeptCond = "AND EXISTS (SELECT 1 FROM employee_departments ed
                     WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
        $params[] = $leaveDeptFilter;
    }
    if ($leaveEmployeeFilter) {
        $leaveDeptCond .= " AND e.id = ?";
        $params[] = $leaveEmployeeFilter;
    }
    if ($leaveRoleFilter) {
        $leaveDeptCond .= " AND e.role_id = ?";
        $params[] = $leaveRoleFilter;
    }
    $leaveRows = $db->fetchAll(
        "SELECT la.*, e.id as emp_pk, e.first_name, e.last_name, e.employee_id,
                lt.name as leave_type_name, d.name as department_name
         FROM leave_applications la
         JOIN employees e ON la.employee_id = e.id
         JOIN leave_types lt ON la.leave_type_id = lt.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
         LEFT JOIN departments d ON ed.department_id = d.id
         WHERE e.id IN ({$leaveScopeIdsStr})
           AND la.start_date <= ? AND la.end_date >= ?
           {$leaveDeptCond}
         ORDER BY la.created_at DESC",
        $params
    );
} catch (Exception $e) { $leaveRows = []; }

$leaveSummary = [];
foreach ($leaveRows as $row) {
    $pk = $row['emp_pk'];
    if (!isset($leaveSummary[$pk])) {
        $leaveSummary[$pk] = [
            'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
            'employee_id' => $row['employee_id'], 'department_name' => $row['department_name'] ?? '-',
            'applied' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0,
            'total_days' => 0
        ];
    }
    $s = &$leaveSummary[$pk];
    $s['applied']++;
    $s['total_days'] += (float) $row['total_days'];
    
    switch ($row['status']) {
        case 'Pending': $s['pending']++; break;
        case 'Approved': $s['approved']++; break;
        case 'Rejected': $s['rejected']++; break;
        case 'Cancelled': $s['cancelled']++; break;
    }
}
unset($s);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hrms_report.csv"');
$out = fopen('php://output', 'w');

if ($tab === 'attendance') {
    fputcsv($out, ['Employee', 'Code', 'Department', 'Present', 'Absent', 'Late', 'Half Days', 'Leave', 'Total Working', 'Attendance %']);
    foreach ($attSummary as $s) {
        // Calculate attendance % based on total calendar days
        // Attendance % = (Present / Total Calendar Days) * 100
        $pct = $s['working_days'] > 0 ? round(($s['present'] / $s['working_days']) * 100, 1) : 0;
        fputcsv($out, [$s['first_name'] . ' ' . $s['last_name'], $s['employee_id'], $s['department_name'],
            number_format($s['present'], 1), number_format($s['absent'], 1), $s['late'], $s['half'], number_format($s['leave'], 1), $s['working_days'], $pct . '%']);
    }
} elseif ($tab === 'leave') {
    fputcsv($out, ['Employee', 'Code', 'Department', 'Total Applied', 'Pending', 'Approved', 'Rejected', 'Cancelled', 'Total Days']);
    foreach ($leaveSummary as $s) {
        fputcsv($out, [$s['first_name'] . ' ' . $s['last_name'], $s['employee_id'], $s['department_name'],
            $s['applied'], $s['pending'], $s['approved'], $s['rejected'], $s['cancelled'], $s['total_days']]);
    }
} else {
    $period = $payRunInfo ? $payRunInfo['period_start'] . ' to ' . $payRunInfo['period_end'] : '-';
    fputcsv($out, ['Employee', 'Code', 'Department', 'Period', 'Basic Pay', 'Allowances', 'Deductions', 'Gross', 'Net Salary', 'Paid Days', 'Unpaid Days']);
    foreach ($payRows as $p) {
        fputcsv($out, [$p['first_name'] . ' ' . $p['last_name'], $p['employee_id'], $p['department_name'] ?? '-', $period,
            $p['basic_pay'], $p['allowances'], $p['deductions'], $p['gross_pay'], $p['net_salary'], $p['paid_days'], $p['unpaid_days']]);
    }
}
fclose($out);
exit;
