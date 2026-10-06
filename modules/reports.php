<?php
/**
 * Reports Module
 * Core PHP HRMS Application
 * Section 15 - Reports module
 */

$db = Database::getInstance();
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';
$permissions = new Permissions();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('reports', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view reports.</div>';
    exit;
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

$directBranchEmployees = [];
$leaveScopeEmployees = [];
try {
    $directBranchEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr})
         ORDER BY e.first_name, e.last_name"
    );
    $leaveScopeEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$leaveScopeIdsStr})
         ORDER BY e.first_name, e.last_name"
    );
} catch (Exception $e) {
    $directBranchEmployees = [];
    $leaveScopeEmployees = [];
}

// Get tab
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

// =====================================================================
// LEAVE SUMMARY - compute real figures from leave_applications
// =====================================================================
$leaveStart = $_GET['leave_start'] ?? date('Y-m-01');
$leaveEnd   = $_GET['leave_end'] ?? date('Y-m-t');
$leaveDeptFilter = $_GET['leave_department'] ?? '';
$leaveEmployeeFilter = $_GET['leave_employee'] ?? '';
$leaveRoleFilter = $_GET['leave_role'] ?? '';

// Employee dropdown limited to the selected leave department and/or role (cascading filters)
$leaveFilterEmployees = $leaveScopeEmployees;
try {
    $leaveEmpParams = [];
    $leaveEmpCond = '';
    if ($leaveDeptFilter) {
        $leaveEmpCond = "AND EXISTS (SELECT 1 FROM employee_departments ed
                       WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
        $leaveEmpParams[] = $leaveDeptFilter;
    }
    if ($leaveRoleFilter) {
        $leaveEmpCond .= " AND e.role_id = ?";
        $leaveEmpParams[] = $leaveRoleFilter;
    }
    $leaveFilterEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$leaveScopeIdsStr})
           {$leaveEmpCond}
         ORDER BY e.first_name, e.last_name",
        $leaveEmpParams
    );
} catch (Exception $e) { $leaveFilterEmployees = $leaveScopeEmployees; }

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

// Aggregate leave data
$leaveSummary = [];
foreach ($leaveRows as $row) {
    $pk = $row['emp_pk'];
    if (!isset($leaveSummary[$pk])) {
        $leaveSummary[$pk] = [
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'employee_id' => $row['employee_id'],
            'department_name' => $row['department_name'] ?? '-',
            'applied' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0,
            'total_days' => 0
        ];
    }
    $s = &$leaveSummary[$pk];
    $s['applied']++;
    $s['total_days'] += (float) $row['total_days'];
    
    switch ($row['status']) {
        case 'Pending':
            $s['pending']++;
            break;
        case 'Approved':
            $s['approved']++;
            break;
        case 'Rejected':
            $s['rejected']++;
            break;
        case 'Cancelled':
            $s['cancelled']++;
            break;
    }
}
unset($s);

// =====================================================================
// ATTENDANCE SUMMARY - compute real aggregates from attendance_daily
// =====================================================================
// Get default pay cycle period dates
$defaultPeriod = getDefaultPayCyclePeriodDates();
$attStart = $_GET['start'] ?? $defaultPeriod['start'];
$attEnd   = $_GET['end']   ?? $defaultPeriod['end'];
$deptFilter = $_GET['department'] ?? '';
$employeeFilter = $_GET['employee'] ?? '';
$roleFilter = $_GET['role'] ?? '';

// Employee dropdown limited to the selected department and/or role (cascading filters)
$attFilterEmployees = $directBranchEmployees;
try {
    $attEmpParams = [];
    $attEmpCond = '';
    if ($deptFilter) {
        $attEmpCond = "AND EXISTS (SELECT 1 FROM employee_departments ed
                       WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
        $attEmpParams[] = $deptFilter;
    }
    if ($roleFilter) {
        $attEmpCond .= " AND e.role_id = ?";
        $attEmpParams[] = $roleFilter;
    }
    $attFilterEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr})
           {$attEmpCond}
         ORDER BY e.first_name, e.last_name",
        $attEmpParams
    );
} catch (Exception $e) { $attFilterEmployees = $directBranchEmployees; }

$attRows = [];
$depts = [];
try {
    $depts = $db->fetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
} catch (Exception $e) { $depts = []; }

try {
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
        "SELECT e.id as emp_pk, e.first_name, e.last_name, e.employee_id, e.date_of_joining,
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
    
    // Then pull daily attendance in range for these employees
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
         WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr})
           AND ad.attendance_date BETWEEN ? AND ?
           {$deptCond}
         ORDER BY e.first_name, e.last_name, ad.attendance_date",
        $params
    );
} catch (Exception $e) { 
    $allEmployees = [];
    $attRows = []; 
}

// Helper: is a daily record "late" given its first_in (UTC) and the shift?
function reportIsLate($record, $startTime = null, $graceMinutes = null) {
    if (empty($record['first_in']) || empty($startTime)) return false;
    $local = utcToLocal($record['first_in'], 'H:i');
    [$h, $m] = array_map('intval', explode(':', $local));
    $minutes = $h * 60 + $m;
    [$sh, $sm] = array_map('intval', explode(':', $startTime));
    $shiftStart = $sh * 60 + $sm;
    $grace = (int) ($graceMinutes ?? 0);
    return $minutes > ($shiftStart + $grace);
}

// Calculate total calendar days in the date range
function getTotalDaysInRange($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = $start->diff($end);
    return $interval->days + 1; // Include both start and end dates
}

$totalDaysInRange = getTotalDaysInRange($attStart, $attEnd);

// Initialize all employees in summary with default values
$attSummary = [];   // emp_pk => stats
foreach ($allEmployees as $emp) {
    $pk = $emp['emp_pk'];
    $attSummary[$pk] = [
        'emp_pk' => $pk,
        'first_name' => $emp['first_name'],
        'last_name' => $emp['last_name'],
        'employee_id' => $emp['employee_id'],
        'department_name' => $emp['department_name'] ?? '-',
        'present' => 0, 'absent' => 0, 'late' => 0, 'half' => 0,
        'leave' => 0, 'week_off' => 0, 'total' => 0, 'working_days' => $totalDaysInRange,
        'start_time' => $emp['start_time'],
        'grace_in_minutes' => $emp['grace_in_minutes']
    ];
}

// Aggregate attendance data for employees who have records
$totalRecords = 0;
$totalLate = 0;
$totalAbsentDays = 0;
$attPresentSum = 0; // for average %
$attAttendanceRecords = 0;

foreach ($attRows as $row) {
    $pk = $row['emp_pk'];
    if (!isset($attSummary[$pk])) {
        // This shouldn't happen since we initialized all employees, but just in case
        continue;
    }
    $s = &$attSummary[$pk];
    $s['total']++;
    $totalRecords++;
    switch ($row['status']) {
        case 'Present':
            $s['present']++;
            $attPresentSum++;
            if (reportIsLate($row, $s['start_time'], $s['grace_in_minutes'])) { $s['late']++; $totalLate++; }
            break;
        case 'Absent':
            // Don't count explicit absent - will be calculated dynamically
            break;
        case 'Half Day':
            $s['half']++;
            $s['present'] += 0.5;
            $attPresentSum += 0.5;
            break;
        case 'On Leave':
        case 'Leave':
            $s['leave']++;
            break;
        case 'First Half Leave':
            // Count 0.5 for leave
            $s['leave'] += 0.5;
            $s['half']++; // Count 1 half-day leave instance
            // Check if there are punches for the second half
            $hasPunches = $db->fetchOne(
                "SELECT COUNT(*) as count FROM attendance_punches 
                 WHERE employee_id = ? AND attendance_date = ?",
                [$row['emp_pk'], $row['attendance_date']]
            );
            if ($hasPunches && $hasPunches['count'] > 0) {
                // Has attendance for second half - count as 0.5 present
                $s['present'] += 0.5;
                $attPresentSum += 0.5;
            }
            // If no attendance for second half, the absent calculation will handle it
            break;
        case 'Second Half Leave':
            // Count 0.5 for leave
            $s['leave'] += 0.5;
            $s['half']++; // Count 1 half-day leave instance
            // Check if there are punches for the first half
            $hasPunches = $db->fetchOne(
                "SELECT COUNT(*) as count FROM attendance_punches 
                 WHERE employee_id = ? AND attendance_date = ?",
                [$row['emp_pk'], $row['attendance_date']]
            );
            if ($hasPunches && $hasPunches['count'] > 0) {
                // Has attendance for first half - count as 0.5 present
                $s['present'] += 0.5;
                $attPresentSum += 0.5;
            }
            // If no attendance for first half, the absent calculation will handle it
            break;
        default:
            // Holiday / Week Off / other: count as a recorded day but not attended
            break;
    }
    $attAttendanceRecords++;
}
unset($s);

// =====================================================================
// WEEK OFF - automatically calculated from each employee's assigned
// Work Pattern (employee-specific; no global weekend rule).
//
// Week Off days are dynamically evaluated against the employee's Work
// Pattern for the whole selected range and folded into the existing
// combined "Leaves" column. No leave/attendance records are created or
// updated - the actual Leave balance/history is never modified here.
//
// To avoid double-counting, a Week Off date is skipped when the day is
// already represented by an attendance record such as Present, Half Day,
// On Leave/Leave, or Absent (e.g. an approved paid leave covering a Week
// Off, or an employee who actually worked/clocked in on a scheduled
// Week Off - the existing weekend attendance rule already handled that).
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
    // Week Off days are included in the "leave" total above automatically.
    $calculatedAbsent = $s['working_days'] - ($s['present'] + $s['leave']);
    $s['absent'] = max(0, round($calculatedAbsent, 1)); // Ensure no negative values and round to 1 decimal
    $totalAbsentDays += $s['absent'];
}
unset($s);

// Average attendance % across total calendar days
$totalCalendarDaysSum = count($attSummary) * $totalDaysInRange;
$avgAttendance = $totalCalendarDaysSum > 0 ? round(($attPresentSum / $totalCalendarDaysSum) * 100, 1) : 0;

// =====================================================================
// PAYROLL SUMMARY - compute real figures from payroll_line_items
// =====================================================================
$payPeriod = $_GET['period'] ?? '';
$payEmployeeFilter = $_GET['payroll_employee'] ?? '';
$payRoleFilter = $_GET['payroll_role'] ?? '';

// Employee dropdown limited to the selected role (cascading filter); payroll has no department filter
$payFilterEmployees = $directBranchEmployees;
try {
    $payEmpParams = [];
    $payEmpCond = '';
    if ($payRoleFilter) {
        $payEmpCond .= " AND e.role_id = ?";
        $payEmpParams[] = $payRoleFilter;
    }
    $payFilterEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id
         FROM employees e
         WHERE e.is_active = 1 AND e.id IN ({$directBranchIdsStr})
           {$payEmpCond}
         ORDER BY e.first_name, e.last_name",
        $payEmpParams
    );
} catch (Exception $e) { $payFilterEmployees = $directBranchEmployees; }

$payRows = [];
$payRunInfo = null;
try {
    if ($payPeriod) {
        $payRun = $db->fetchOne("SELECT * FROM payroll_runs WHERE period_start = ? OR period_end = ? ORDER BY period_end DESC LIMIT 1", [$payPeriod, $payPeriod]);
    } else {
        $payRun = $db->fetchOne("SELECT * FROM payroll_runs ORDER BY period_end DESC LIMIT 1");
    }
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
             WHERE pl.payroll_run_id = ?{$payCond}
             ORDER BY e.first_name, e.last_name",
            $payParams
        );
    }
} catch (Exception $e) { $payRows = []; }
?>

<div class="panel-head"></div>

<div class="tabs">
    <div class="tab <?php echo $tab == 'attendance' ? 'active' : ''; ?>" onclick="switchTab('attendance')">Attendance Summary</div>
    <div class="tab <?php echo $tab == 'leave' ? 'active' : ''; ?>" onclick="switchTab('leave')">Leave Summary</div>
    <div class="tab <?php echo $tab == 'payroll' ? 'active' : ''; ?>" onclick="switchTab('payroll')">Payroll Summary</div>
</div>

<?php if ($tab == 'attendance'): ?>
    <div class="toolbar">
        <div class="field" style="flex-direction:row;align-items:center;gap:6px;margin:0;">
            <label style="margin:0;font-size:12px;">From</label>
            <input type="date" style="width:140px;" value="<?php echo $attStart; ?>" onchange="applyAttendanceFilter('start', this.value)">
        </div>
        <div class="field" style="flex-direction:row;align-items:center;gap:6px;margin:0;">
            <label style="margin:0;font-size:12px;">To</label>
            <input type="date" style="width:140px;" value="<?php echo $attEnd; ?>" onchange="applyAttendanceFilter('end', this.value)">
        </div>
        <select class="filter-chip" onchange="applyAttendanceFilter('department', this.value)">
            <option value="">All departments</option>
            <?php foreach ($depts as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $deptFilter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyAttendanceFilter('employee', this.value)">
            <option value="">All employees</option>
            <?php foreach ($attFilterEmployees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $employeeFilter == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyAttendanceFilter('role', this.value)">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['id']; ?>" <?php echo $roleFilter == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="spacer"></div>
        <?php if (hasPermission('reports', 'export')): ?>
        <a class="filter-chip" style="text-decoration:none;cursor:pointer;" href="api/reports.php?export=csv&tab=attendance&start=<?php echo $attStart; ?>&end=<?php echo $attEnd; ?>&department=<?php echo $deptFilter; ?>&employee=<?php echo $employeeFilter; ?>&role=<?php echo $roleFilter; ?>"><i class="bi bi-download"></i> Export CSV</a>
        <?php endif; ?>
    </div>

    <div class="row-3" style="margin-bottom:20px;">
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--success);"><?php echo $avgAttendance; ?>%</div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">Average attendance</div>
        </div>
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--warning);"><?php echo $totalLate; ?></div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">Late arrivals</div>
        </div>
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--danger);"><?php echo number_format($totalAbsentDays, 1); ?></div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">Absent days</div>
        </div>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Half Days</th>
                    <th>Leave</th>
                    <th>Total Working</th>
                    <th>Attendance %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attSummary)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-check"></i>
                                <p>No attendance data found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attSummary as $s): ?>
                        <?php
                        // Calculate attendance % based on total calendar days
                        // Attendance % = (Present / Total Calendar Days) * 100
                        $pct = $s['working_days'] > 0 ? round(($s['present'] / $s['working_days']) * 100, 1) : 0;
                        $pctClass = $pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : 'danger');
                        ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($s['first_name'] . ' ' . $s['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mono"><?php echo htmlspecialchars($s['employee_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($s['department_name'] ?? '-'); ?></td>
                            <td><?php echo number_format($s['present'], 1); ?></td>
                            <td><?php echo number_format($s['absent'], 1); ?></td>
                            <td><?php echo $s['late']; ?></td>
                            <td><?php echo $s['half']; ?></td>
                            <td><?php echo number_format($s['leave'], 1); ?></td>
                            <td><?php echo $s['working_days']; ?></td>
                            <td><span class="badge <?php echo $pctClass; ?>"><?php echo $pct; ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'leave'): ?>
    <div class="toolbar">
        <div class="field" style="flex-direction:row;align-items:center;gap:6px;margin:0;">
            <label style="margin:0;font-size:12px;">From</label>
            <input type="date" style="width:140px;" value="<?php echo $leaveStart; ?>" onchange="applyLeaveFilter('leave_start', this.value)">
        </div>
        <div class="field" style="flex-direction:row;align-items:center;gap:6px;margin:0;">
            <label style="margin:0;font-size:12px;">To</label>
            <input type="date" style="width:140px;" value="<?php echo $leaveEnd; ?>" onchange="applyLeaveFilter('leave_end', this.value)">
        </div>
        <select class="filter-chip" onchange="applyLeaveFilter('leave_department', this.value)">
            <option value="">All departments</option>
            <?php foreach ($depts as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $leaveDeptFilter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyLeaveFilter('leave_employee', this.value)">
            <option value="">All employees</option>
            <?php foreach ($leaveFilterEmployees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $leaveEmployeeFilter == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyLeaveFilter('leave_role', this.value)">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['id']; ?>" <?php echo $leaveRoleFilter == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="spacer"></div>
        <?php if (hasPermission('reports', 'export')): ?>
        <a class="filter-chip" style="text-decoration:none;cursor:pointer;" href="api/reports.php?export=csv&tab=leave&leave_start=<?php echo $leaveStart; ?>&leave_end=<?php echo $leaveEnd; ?>&leave_department=<?php echo $leaveDeptFilter; ?>&leave_employee=<?php echo $leaveEmployeeFilter; ?>&leave_role=<?php echo $leaveRoleFilter; ?>"><i class="bi bi-download"></i> Export CSV</a>
        <?php endif; ?>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th>Total Applied</th>
                    <th>Pending</th>
                    <th>Approved</th>
                    <th>Rejected</th>
                    <th>Cancelled</th>
                    <th>Total Days</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaveSummary)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-minus"></i>
                                <p>No leave data found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leaveSummary as $s): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($s['first_name'] . ' ' . $s['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mono"><?php echo htmlspecialchars($s['employee_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($s['department_name'] ?? '-'); ?></td>
                            <td><?php echo $s['applied']; ?></td>
                            <td><span class="badge warning"><?php echo $s['pending']; ?></span></td>
                            <td><span class="badge success"><?php echo $s['approved']; ?></span></td>
                            <td><span class="badge danger"><?php echo $s['rejected']; ?></span></td>
                            <td><span class="badge gray"><?php echo $s['cancelled']; ?></span></td>
                            <td><b><?php echo number_format($s['total_days'], 1); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'payroll'): ?>
    <div class="toolbar">
        <div class="filter-chip"><i class="bi bi-calendar-month"></i> <?php echo $payRunInfo ? htmlspecialchars($payRunInfo['period_start'] . ' to ' . $payRunInfo['period_end']) : date('F Y'); ?></div>
        <select class="filter-chip" onchange="applyPayrollFilter('payroll_employee', this.value)">
            <option value="">All employees</option>
            <?php foreach ($payFilterEmployees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $payEmployeeFilter == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyPayrollFilter('payroll_role', this.value)">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['id']; ?>" <?php echo $payRoleFilter == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="spacer"></div>
        <a class="filter-chip" style="text-decoration:none;cursor:pointer;" href="api/reports.php?export=csv&tab=payroll&period=<?php echo $payPeriod; ?>&payroll_employee=<?php echo $payEmployeeFilter; ?>&payroll_role=<?php echo $payRoleFilter; ?>"><i class="bi bi-download"></i> Export CSV</a>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th>Period</th>
                    <th>Basic Pay</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net Salary</th>
                    <th>Paid Days</th>
                    <th>LOP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payRows)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-cash-stack"></i>
                                <p>No payroll data found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payRows as $p): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mono"><?php echo htmlspecialchars($p['employee_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($p['department_name'] ?? '-'); ?></td>
                            <td class="mono"><?php echo $payRunInfo ? htmlspecialchars(date('M Y', strtotime($payRunInfo['period_end']))) : '-'; ?></td>
                            <td class="mono">₹<?php echo number_format((float) $p['basic_pay'], 2); ?></td>
                            <td class="mono">₹<?php echo number_format((float) $p['gross_pay'], 2); ?></td>
                            <td class="mono">₹<?php echo number_format((float) $p['deductions'], 2); ?></td>
                            <td class="mono"><b>₹<?php echo number_format((float) $p['net_salary'], 2); ?></b></td>
                            <td><?php echo (int) $p['paid_days']; ?></td>
                            <td class="mono"><?php echo (int) $p['unpaid_days']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.location = url.toString();
}

function applyAttendanceFilter(key, value) {
    const url = new URL(window.location);
    // Cascade: department/role change resets the employee selection
    if (key === 'department' || key === 'role') {
        url.searchParams.delete('employee');
    }
    if (value) {
        url.searchParams.set(key, value);
    } else {
        url.searchParams.delete(key);
    }
    window.location = url.toString();
}

function applyLeaveFilter(key, value) {
    const url = new URL(window.location);
    // Cascade: department/role change resets the employee selection
    if (key === 'leave_department' || key === 'leave_role') {
        url.searchParams.delete('leave_employee');
    }
    if (value) {
        url.searchParams.set(key, value);
    } else {
        url.searchParams.delete(key);
    }
    window.location = url.toString();
}

function applyPayrollFilter(key, value) {
    const url = new URL(window.location);
    // Cascade: role change resets the employee selection
    if (key === 'payroll_role') {
        url.searchParams.delete('payroll_employee');
    }
    if (value) {
        url.searchParams.set(key, value);
    } else {
        url.searchParams.delete(key);
    }
    window.location = url.toString();
}
</script>

