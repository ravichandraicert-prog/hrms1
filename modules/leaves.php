<?php
/**
 * Leaves Module
 * Core PHP HRMS Application
 * Section 13 - Leave management module
 */

$db = Database::getInstance();
$user = getCurrentUser();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';
$permissions = new Permissions();
$workPatternService = new WorkPatternService();

// Check permissions
if (!hasPermission('leaves', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view leaves.</div>';
    exit;
}

// Get scoped employee IDs for leaves
$scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'leaves', 'read');
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// Get tab
$tab = $_GET['tab'] ?? 'calendar';

// Get month for calendar view
$month = $_GET['month'] ?? date('Y-m');

// Get status filter for my leaves
$statusFilter = $_GET['status'] ?? '';

// Get leave types (all types for Leave Types tab)
$allLeaveTypes = $db->fetchAll("SELECT * FROM leave_types ORDER BY name");

// Get active leave types for dropdowns
$leaveTypes = $db->fetchAll("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");

// Compute leave types applicable to the current user (for the Apply Leave dropdown)
$applicableLeaveTypes = [];
foreach ($leaveTypes as $lt) {
    $assignTo = isset($lt['assign_to']) ? $lt['assign_to'] : '';
    // Backward compatible: legacy types use applicable_to='all' and newer ones use assign_to='all_employees'
    if (in_array($assignTo, ['', 'all_employees'], true)) {
        $applicableLeaveTypes[] = $lt;
        continue;
    }
    $targetId = (int) ($lt['target_id'] ?? 0);
    if ($assignTo === 'user') {
        if ((int) $lt['target_id'] === (int) $user['id']) {
            $applicableLeaveTypes[] = $lt;
        }
    } elseif ($assignTo === 'department') {
        $deptMatch = $db->fetchOne(
            "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ? LIMIT 1",
            [$user['id'], $targetId]
        );
        if ($deptMatch) {
            $applicableLeaveTypes[] = $lt;
        }
    } elseif ($assignTo === 'role') {
        $roleMatch = $db->fetchOne(
            "SELECT id FROM employees WHERE id = ? AND role_id = ? LIMIT 1",
            [$user['id'], $targetId]
        );
        if ($roleMatch) {
            $applicableLeaveTypes[] = $lt;
        }
    }
}

// Get balance filters
$balanceDepartmentId = $_GET['balance_department'] ?? '';
$balanceSearch = $_GET['balance_search'] ?? '';
$balanceLeaveTypeId = $_GET['balance_leave_type'] ?? '';
$balanceYear = $_GET['balance_year'] ?? date('Y');

// Get current user's leave balances
$myBalances = $db->fetchAll(
    "SELECT lb.*, lt.name as leave_type_name
     FROM leave_balances lb
     JOIN leave_types lt ON lb.leave_type_id = lt.id
     WHERE lb.employee_id = ? AND lb.year = ?
     ORDER BY lt.name",
    [$user['id'], $balanceYear]
);

// Get employee's assigned holiday calendar
$employee = $db->fetchOne("SELECT holiday_calendar_id FROM employees WHERE id = ?", [$user['id']]);
$festivalHolidays = [];
if ($employee && $employee['holiday_calendar_id']) {
    // Fetch festival holidays from the assigned calendar
    $festivalHolidays = $db->fetchAll(
        "SELECT h.* FROM holidays h 
         WHERE h.holiday_calendar_id = ? 
         AND h.type IN ('Fixed', 'Optional')
         ORDER BY h.date",
        [$employee['holiday_calendar_id']]
    );
}

// Only offer leave types the current user still has an available balance for
// Exception: Festival Leave doesn't require balance
// Optional Festival Leave requires balance check
$balanceByType = [];
foreach ($myBalances as $mb) {
    $balanceByType[(int) $mb['leave_type_id']] = (float) $mb['balance'];
}
$applicableLeaveTypes = array_values(array_filter($applicableLeaveTypes, function ($lt) use ($balanceByType) {
    // Festival Leave doesn't require balance
    if ($lt['name'] === 'Festival Leave') {
        return true;
    }
    // Optional Festival Leave requires balance
    if ($lt['name'] === 'Optional Festival Leave') {
        return (($balanceByType[(int) $lt['id']] ?? 0) > 0);
    }
    // Regular leave types require balance
    return (($balanceByType[(int) $lt['id']] ?? 0) > 0);
}));

// Get departments for filters
$departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");

// Build balance query
$balanceWhere = ["lb.year = ?", "lb.employee_id IN ({$scopedIdsStr})"];
$balanceParams = [$balanceYear];

if (!empty($balanceDepartmentId)) {
    $balanceWhere[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = lb.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
    $balanceParams[] = $balanceDepartmentId;
}

if (!empty($balanceSearch)) {
    $balanceWhere[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
    $searchTerm = '%' . $balanceSearch . '%';
    $balanceParams[] = $searchTerm;
    $balanceParams[] = $searchTerm;
    $balanceParams[] = $searchTerm;
}

if (!empty($balanceLeaveTypeId)) {
    $balanceWhere[] = "lb.leave_type_id = ?";
    $balanceParams[] = $balanceLeaveTypeId;
}

$balanceWhereClause = implode(' AND ', $balanceWhere);

// Get all balances with filters (not just current user's)
$allBalances = $db->fetchAll(
    "SELECT lb.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id, d.name as department_name
     FROM leave_balances lb
     JOIN leave_types lt ON lb.leave_type_id = lt.id
     JOIN employees e ON lb.employee_id = e.id
     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
     LEFT JOIN departments d ON ed.department_id = d.id
     WHERE {$balanceWhereClause} AND e.is_active = 1
     ORDER BY e.first_name, e.last_name, lt.name
     LIMIT 500",
    $balanceParams
);

// Reorganize balances by employee for the new UI
$balancesByEmployee = [];
foreach ($allBalances as $balance) {
    $empId = $balance['employee_id'];
    if (!isset($balancesByEmployee[$empId])) {
        $balancesByEmployee[$empId] = [
            'employee_id' => $balance['employee_id'],
            'first_name' => $balance['first_name'],
            'last_name' => $balance['last_name'],
            'department_name' => $balance['department_name'],
            'balances' => []
        ];
    }
    $balancesByEmployee[$empId]['balances'][$balance['leave_type_id']] = $balance;
}

// Get unique leave types for column headers
$leaveTypeColumns = [];
foreach ($allBalances as $balance) {
    $leaveTypeColumns[$balance['leave_type_id']] = $balance['leave_type_name'];
}
ksort($leaveTypeColumns);

// Leave types visually hidden from the balances summary table (display-only).
// Rows/balances and the balance-edit flow remain fully intact — cells stay in the DOM.
$hiddenBalanceColumns = ['Unpaid Leave', 'Festival Leave'];

// Get filter values for calendar
$calendarEmployeeId = $_GET['calendar_employee'] ?? '';
$calendarDepartmentId = $_GET['calendar_department'] ?? '';
$calendarLeaveTypeId = $_GET['calendar_leave_type'] ?? '';

// Build calendar filter query
$calendarWhere = ["(la.start_date LIKE ? OR la.end_date LIKE ?)", "la.employee_id IN ({$scopedIdsStr})"];
$calendarParams = [$month . '%', $month . '%'];

if (!empty($calendarEmployeeId)) {
    // Free-text employee search on the calendar matches on name or employee code
    $calendarWhere[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
    $calendarSearchTerm = '%' . $calendarEmployeeId . '%';
    $calendarParams[] = $calendarSearchTerm;
    $calendarParams[] = $calendarSearchTerm;
    $calendarParams[] = $calendarSearchTerm;
}

if (!empty($calendarDepartmentId)) {
    $calendarWhere[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
    $calendarParams[] = $calendarDepartmentId;
}

if (!empty($calendarLeaveTypeId)) {
    $calendarWhere[] = "la.leave_type_id = ?";
    $calendarParams[] = $calendarLeaveTypeId;
}

$calendarWhereClause = implode(' AND ', $calendarWhere);

// Get calendar leaves for the month (exclude cancelled leaves; include Rejected so its
// status can be displayed with an icon - Approved check, Pending hourglass, Rejected X)
$calendarLeaves = $db->fetchAll(
    "SELECT la.*, COALESCE(lt.name, la.leave_type_name_backup) as leave_type_name, e.first_name, e.last_name, e.employee_id
     FROM leave_applications la
     LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
     JOIN employees e ON la.employee_id = e.id
     WHERE {$calendarWhereClause} AND la.status IN ('Approved', 'Pending', 'Rejected') AND e.is_active = 1
     ORDER BY la.start_date",
    $calendarParams
);

// Leave Calendar status icons (Bootstrap Icons) - resolved from the actual leave application status
$leaveStatusIcons = [
    'Approved' => 'bi-check',
    'Pending'  => 'bi-hourglass-split',
    'Rejected' => 'bi-x',
];

// Chip colors per status - same palette already used by the app's calendars (see Attendance)
$leaveStatusStyles = [
    'Approved' => ['bg' => 'var(--success-bg)', 'fg' => '#146B47'],
    'Pending'  => ['bg' => 'var(--warning-bg)', 'fg' => '#8A5A16'],
    'Rejected' => ['bg' => 'var(--danger-bg)',  'fg' => '#A33A3A'],
];

// Maximum employees rendered directly in a calendar day cell (the rest collapse into "+N employees")
$maxVisibleDayLeaves = 4;

// Full per-date leave data used by the "+N employees" expanded list (statuses preserved)
$calendarDayLeavesData = [];

// Get my leave applications
$whereClause = 'la.employee_id = ?';
$params = [$user['id']];
if ($statusFilter) {
    $whereClause .= ' AND la.status = ?';
    $params[] = $statusFilter;
}

// Additional filters for My Leaves
$myLeaveDepartmentId = $_GET['my_department'] ?? '';
$myLeaveTypeId = $_GET['my_leave_type'] ?? '';
$myLeaveSearch = $_GET['my_search'] ?? '';

if (!empty($myLeaveDepartmentId)) {
    $whereClause .= ' AND EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)';
    $params[] = $myLeaveDepartmentId;
}

if (!empty($myLeaveTypeId)) {
    $whereClause .= ' AND la.leave_type_id = ?';
    $params[] = $myLeaveTypeId;
}

if (!empty($myLeaveSearch)) {
    // Search the applicant (emp) rather than the approver (e)
    $whereClause .= ' AND (emp.first_name LIKE ? OR emp.last_name LIKE ? OR emp.employee_id LIKE ?)';
    $searchTerm = '%' . $myLeaveSearch . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$myLeaves = $db->fetchAll(
    "SELECT la.*, COALESCE(lt.name, la.leave_type_name_backup) as leave_type_name, e.first_name as manager_first, e.last_name as manager_last,
     emp.first_name as emp_first, emp.last_name as emp_last, emp.employee_id as emp_id
     FROM leave_applications la
     LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
     LEFT JOIN employees e ON la.approved_by = e.id
     JOIN employees emp ON la.employee_id = emp.id
     WHERE {$whereClause}
     ORDER BY la.created_at DESC",
    $params
);

// Get pending approvals
$approvalStatus = $_GET['approval_status'] ?? 'Pending';
$approvalDepartmentId = $_GET['approval_department'] ?? '';
$approvalSearch = $_GET['approval_search'] ?? '';

// Use branching hierarchy to determine which employees' leaves can be approved
// Super Admin and whole-company-scope approvers (HR Manager/Admin) see all scoped
// requests; other approvers are limited to employees in their reporting chain.
// See Permissions::getApprovableEmployeeIds() in includes/permissions.php
$approvableEmployeeIds = [];
if (hasPermission('leaves', 'approve')) {
    require_once __DIR__ . '/../includes/permissions.php';
    $approvableEmployeeIds = getApprovableEmployeeIds($user['id']);
}

$approvableIdsStr = !empty($approvableEmployeeIds) ? implode(',', $approvableEmployeeIds) : '0';

$approvalWhere = ['la.status = ?', "la.employee_id IN ({$approvableIdsStr})", "la.employee_id != ?"];
$approvalParams = [$approvalStatus, $user['id']];

if (!empty($approvalDepartmentId)) {
    $approvalWhere[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
    $approvalParams[] = $approvalDepartmentId;
}

if (!empty($approvalSearch)) {
    $approvalWhere[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
    $searchTerm = '%' . $approvalSearch . '%';
    $approvalParams[] = $searchTerm;
    $approvalParams[] = $searchTerm;
    $approvalParams[] = $searchTerm;
}

$approvalWhereClause = implode(' AND ', $approvalWhere);

$pendingApprovals = $db->fetchAll(
    "SELECT la.*, e.first_name, e.last_name, e.employee_id, COALESCE(lt.name, la.leave_type_name_backup) as leave_type_name,
     ap.first_name as approver_first, ap.last_name as approver_last,
     lb.balance as leave_balance
     FROM leave_applications la
     JOIN employees e ON la.employee_id = e.id
     LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
     LEFT JOIN employees ap ON la.approved_by = ap.id
     LEFT JOIN leave_balances lb ON la.employee_id = lb.employee_id 
                                  AND la.leave_type_id = lb.leave_type_id 
                                  AND lb.year = YEAR(CURDATE())
     WHERE {$approvalWhereClause} AND e.is_active = 1
     ORDER BY la.created_at DESC",
    $approvalParams
);
?>

<div class="panel-head"></div>

<div class="tabs">
    <div class="tab <?php echo $tab == 'calendar' ? 'active' : ''; ?>" onclick="switchTab('calendar')">Leave Calendar</div>
    <div class="tab <?php echo $tab == 'apply' ? 'active' : ''; ?>" onclick="switchTab('apply')">Apply Leave</div>
    <div class="tab <?php echo $tab == 'my' ? 'active' : ''; ?>" onclick="switchTab('my')">My Leaves</div>
    <?php if (hasPermission('leaves', 'approve')): ?>
        <div class="tab <?php echo $tab == 'approvals' ? 'active' : ''; ?>" onclick="switchTab('approvals')">Approvals</div>
    <?php endif; ?>
    <div class="tab <?php echo $tab == 'balances' ? 'active' : ''; ?>" onclick="switchTab('balances')">Balances</div>
    <?php if (hasPermission('leaves', 'update')): ?>
        <div class="tab <?php echo $tab == 'types' ? 'active' : ''; ?>" onclick="switchTab('types')">Leave Types</div>
    <?php endif; ?>
</div>

<?php if ($tab == 'calendar'): ?>
    <?php
    $currentMonth = new DateTime($month);
    $firstDay = clone $currentMonth;
    $firstDay->modify('first day of this month');
    $lastDay = clone $currentMonth;
    $lastDay->modify('last day of this month');
    
    $startDayOfWeek = (int)$firstDay->format('w');
    $daysInMonth = (int)$lastDay->format('t');
    $prevMonth = clone $firstDay;
    $prevMonth->modify('-1 day');
    $prevMonthLastDay = (int)$prevMonth->format('t');
    ?>
    
    <div class="toolbar">
        <div class="filter-chip" onclick="changeMonth(-1)"><i class="bi bi-chevron-left"></i></div>
        <div class="filter-chip" style="font-weight:600;color:var(--ink);"><?php echo $currentMonth->format('F Y'); ?></div>
        <div class="filter-chip" onclick="changeMonth(1)"><i class="bi bi-chevron-right"></i></div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="calendarEmployeeSearch" placeholder="Search employee..." 
                   value="<?php echo htmlspecialchars($calendarEmployeeId); ?>" 
                   oninput="filterCalendarDebounced()">
        </div>
        <select class="filter-chip" id="calendarDepartmentFilter" onchange="filterCalendar()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $calendarDepartmentId == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="calendarLeaveTypeFilter" onchange="filterCalendar()">
            <option value="">All Leave Types</option>
            <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo $lt['id']; ?>" <?php echo $calendarLeaveTypeId == $lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearCalendarFilters()">Clear Filters</button>
    </div>
    
    <style>
        /* Leave Calendar - scoped to #leaveCal (Leaves module only); does not affect other calendars */
        #leaveCal .cal-event{display:flex;align-items:center;gap:4px;}
        #leaveCal .cal-event .cal-ev-ic{flex:0 0 auto;font-size:10px;line-height:1;}
        #leaveCal .cal-event .cal-ev-name{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        #leaveCal .cal-more{margin-top:2px;font-size:9.5px;font-weight:600;padding:2px 4px;border-radius:3px;background:var(--accent-ink);color:var(--accent-dark);cursor:pointer;display:block;text-align:center;}
        #leaveCal .cal-more:hover{background:var(--accent);color:#fff;}
    </style>
    <div class="cal-grid" id="leaveCal">
        <div class="cal-head">Sun</div><div class="cal-head">Mon</div><div class="cal-head">Tue</div><div class="cal-head">Wed</div><div class="cal-head">Thu</div><div class="cal-head">Fri</div><div class="cal-head">Sat</div>
        <?php for ($i = 0; $i < $startDayOfWeek; $i++): ?>
            <div class="cal-day other"><div class="cal-date"><?php echo $prevMonthLastDay - $startDayOfWeek + $i + 1; ?></div></div>
        <?php endfor; ?>
        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
            <?php
            $dateStr = $currentMonth->format('Y-m-') . sprintf('%02d', $day);
            $isToday = $dateStr === date('Y-m-d');
            
            // Simplified weekend check for calendar display - actual work pattern logic is applied
            // during leave application validation through the work pattern service
            $isWeekend = date('N', strtotime($dateStr)) >= 6;
            
            // Find leaves for this day
            $dayLeaves = [];
            foreach ($calendarLeaves as $leave) {
                $startDate = new DateTime($leave['start_date']);
                $endDate = new DateTime($leave['end_date']);
                $currentDate = new DateTime($dateStr);
                if ($currentDate >= $startDate && $currentDate <= $endDate) {
                    $dayLeaves[] = $leave;
                }
            }

            // Sort by status priority (Approved first, then Pending, then Rejected), then by employee name
            usort($dayLeaves, function ($a, $b) {
                $priority = ['Approved' => 1, 'Pending' => 2, 'Rejected' => 3];
                $pa = $priority[$a['status']] ?? 9;
                $pb = $priority[$b['status']] ?? 9;
                if ($pa !== $pb) {
                    return $pa - $pb;
                }
                return strcmp(trim($a['first_name'] . ' ' . $a['last_name']), trim($b['first_name'] . ' ' . $b['last_name']));
            });

            // Keep the full list for this date (used by the "+N employees" expanded view - statuses preserved)
            $calendarDayLeavesData[$dateStr] = array_map(function ($leave) {
                return [
                    'name'       => trim($leave['first_name'] . ' ' . $leave['last_name']),
                    'status'     => $leave['status'],
                    'leave_type' => $leave['leave_type_name'],
                    'start_date' => $leave['start_date'],
                    'end_date'   => $leave['end_date'],
                ];
            }, $dayLeaves);

            // Show a maximum of 4 employees directly in the cell; the rest collapse into "+N employees"
            $visibleDayLeaves = array_slice($dayLeaves, 0, $maxVisibleDayLeaves);
            $hiddenDayLeavesCount = count($dayLeaves) - count($visibleDayLeaves);
            ?>
            <div class="cal-day <?php echo $isToday ? 'today' : ''; ?>">
                <div class="cal-date"><?php echo $day; ?></div>
                <?php if ($isWeekend): ?>
                    <div class="cal-event" style="background:var(--warning-bg);color:#8A5A16;">Weekend</div>
                <?php endif; ?>
                <?php foreach ($visibleDayLeaves as $leave): ?>
                    <?php
                    $evStyle = $leaveStatusStyles[$leave['status']] ?? ['bg' => 'var(--info-bg)', 'fg' => 'var(--info)'];
                    $evIcon = $leaveStatusIcons[$leave['status']] ?? 'bi-calendar3';
                    ?>
                    <div class="cal-event" style="background:<?php echo $evStyle['bg']; ?>;color:<?php echo $evStyle['fg']; ?>;font-size:11px;"
                         title="<?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name'] . ' - ' . $leave['leave_type_name'] . ' (' . $leave['status'] . ')'); ?>">
                        <i class="bi <?php echo $evIcon; ?> cal-ev-ic"></i><span class="cal-ev-name"><?php echo htmlspecialchars(substr($leave['first_name'] . ' ' . $leave['last_name'], 0, 10)); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($hiddenDayLeavesCount > 0): ?>
                    <div class="cal-more"
                         onclick="openDayLeavesModal('<?php echo $dateStr; ?>')"
                         title="View all employees on leave">
                        +<?php echo $hiddenDayLeavesCount; ?> employee<?php echo $hiddenDayLeavesCount > 1 ? 's' : ''; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
        <?php for ($i = 1; $i <= (7 - ($startDayOfWeek + $daysInMonth) % 7) % 7; $i++): ?>
            <div class="cal-day other"><div class="cal-date"><?php echo $i; ?></div></div>
        <?php endfor; ?>
    </div>
    
    <div class="cal-legend">
        <div class="legend-item"><div class="legend-dot" style="background:var(--success);"></div>Annual Leave</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--info);"></div>Sick Leave</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--warning);"></div>Holiday / Weekend</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--accent);"></div>Comp Off</div>
        <div class="legend-item" style="margin-left:10px;border-left:1px solid var(--border);padding-left:14px;"><i class="bi bi-check" style="color:#146B47;"></i>Approved</div>
        <div class="legend-item"><i class="bi bi-hourglass-split" style="color:#8A5A16;"></i>Pending</div>
        <div class="legend-item"><i class="bi bi-x" style="color:#A33A3A;"></i>Rejected</div>
    </div>

    <script>
    // Leave Calendar: full per-date leave data for the "+N employees" expanded list.
    // Statuses come from the actual leave_applications.status values (Approved / Pending / Rejected).
    window.dayLeavesData = <?php echo json_encode($calendarDayLeavesData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    </script>

    <!-- Leave Calendar: expanded list of all employees on leave for a date -->
    <div id="dayLeavesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
        <div style="background:var(--surface);border-radius:var(--radius);width:440px;max-width:95vw;max-height:80vh;overflow-y:auto;padding:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Employees on Leave</h3>
                <button onclick="closeDayLeavesModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
            </div>
            <div id="dayLeavesModalDate" style="font-size:12.5px;color:var(--muted);margin:4px 0 16px;"></div>
            <div id="dayLeavesModalList"></div>
        </div>
    </div>
    <script>
    const DAY_LEAVE_META = {
        Approved: { icon: 'bi-check', color: '#146B47', badge: 'approved', order: 1 },
        Pending:  { icon: 'bi-hourglass-split', color: '#8A5A16', badge: 'pending', order: 2 },
        Rejected: { icon: 'bi-x', color: '#A33A3A', badge: 'rejected', order: 3 }
    };

    function dayLeavesEscapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function openDayLeavesModal(dateStr) {
        const leaves = (window.dayLeavesData && window.dayLeavesData[dateStr]) || [];
        const listEl = document.getElementById('dayLeavesModalList');
        const dateObj = new Date(dateStr + 'T00:00:00');
        document.getElementById('dayLeavesModalDate').textContent =
            dateObj.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) +
            ' - ' + leaves.length + ' employee' + (leaves.length === 1 ? '' : 's') + ' on leave';

        if (!leaves.length) {
            listEl.innerHTML = '<div style="padding:12px;text-align:center;color:var(--muted);font-size:13px;">No leave applications for this date.</div>';
        } else {
            const sorted = leaves.slice().sort(function (a, b) {
                const oa = (DAY_LEAVE_META[a.status] || {}).order || 9;
                const ob = (DAY_LEAVE_META[b.status] || {}).order || 9;
                if (oa !== ob) { return oa - ob; }
                return String(a.name || '').localeCompare(String(b.name || ''));
            });
            listEl.innerHTML = sorted.map(function (l) {
                const meta = DAY_LEAVE_META[l.status] || { icon: 'bi-calendar3', color: 'var(--info)', badge: 'pending' };
                return '<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">' +
                    '<i class="bi ' + meta.icon + '" style="color:' + meta.color + ';font-size:15px;flex-shrink:0;"></i>' +
                    '<div style="flex:1;min-width:0;">' +
                        '<div style="font-size:13px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + dayLeavesEscapeHtml(l.name) + '</div>' +
                        '<div style="font-size:11.5px;color:var(--muted);">' + dayLeavesEscapeHtml(l.leave_type || '') + '</div>' +
                    '</div>' +
                    '<span class="badge ' + meta.badge + '">' + dayLeavesEscapeHtml(l.status || 'Pending') + '</span>' +
                '</div>';
            }).join('');
        }
        document.getElementById('dayLeavesModal').style.display = 'flex';
    }

    function closeDayLeavesModal() {
        document.getElementById('dayLeavesModal').style.display = 'none';
    }

    (function () {
        const modal = document.getElementById('dayLeavesModal');
        if (!modal) { return; }
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeDayLeavesModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') { closeDayLeavesModal(); }
        });
    })();
    </script>

<?php elseif ($tab == 'apply'): ?>
    <div class="flow-wrap">
        <div class="flow-card">
            <div class="flow-title">Request time off</div>
            <div class="flow-desc">Fill in the details for your leave request. Your manager will be notified once submitted.</div>

            <div class="balance-strip">
                <?php 
                // Calculate optional festival leave balance
                $optionalFestivalBalance = 0;
                $hasOptionalFestivalLeaveType = false;
                
                // Debug: check what balances are available
                foreach ($myBalances as $balance) {
                    if (strtolower($balance['leave_type_name']) === 'optional festival leave') {
                        $optionalFestivalBalance = max(0, $balance['balance']);
                        $hasOptionalFestivalLeaveType = true;
                        break;
                    }
                }
                
                // Count optional holidays in the calendar
                $optionalHolidaysCount = 0;
                foreach ($festivalHolidays as $holiday) {
                    if ($holiday['type'] === 'Optional') {
                        $optionalHolidaysCount++;
                    }
                }
                
                // Display optional festival leave balance at the top if there are optional holidays
                // Also show if the balance exists (even if 0) to indicate the leave type is available
                if ($optionalHolidaysCount > 0 || $hasOptionalFestivalLeaveType): 
                ?>
                    <div class="balance-item">
                        <div class="balance-num"><?php echo number_format($optionalFestivalBalance, 1); ?></div>
                        <div class="balance-lbl">Optional Festival Leaves</div>
                    </div>
                <?php endif; ?>
                
                <?php foreach ($myBalances as $balance): ?>
                    <?php if (strtolower($balance['leave_type_name']) !== 'unpaid leave' && strtolower($balance['leave_type_name']) !== 'optional festival leave'): ?>
                    <div class="balance-item">
                        <div class="balance-num"><?php echo number_format(max(0, $balance['balance']), 1); ?></div>
                        <div class="balance-lbl"><?php echo htmlspecialchars($balance['leave_type_name']); ?> days left</div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Leave type <span class="req">*</span></label>
                    <select id="leaveType" onchange="handleLeaveTypeChange()">
                        <?php foreach ($applicableLeaveTypes as $lt): ?>
                            <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" id="festivalHolidayField" style="display:none;">
                    <label>Festival Holiday <span class="req">*</span></label>
                    <select id="festivalHoliday">
                        <option value="">Select a festival holiday</option>
                    </select>
                    <div style="margin-top: 10px;">
                        <label style="font-size: 14px; font-weight: 500;">Festival Type:</label>
                        <div style="display: flex; gap: 20px; margin-top: 5px;">
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="radio" name="festivalTypeFilter" value="Fixed" checked onchange="filterFestivalHolidays()">
                                Fixed
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="radio" name="festivalTypeFilter" value="Optional" onchange="filterFestivalHolidays()">
                                Optional
                            </label>
                        </div>
                    </div>
                    <?php if (empty($festivalHolidays)): ?>
                        <div class="alert warning" style="margin-top: 5px;">No festival holidays available in your assigned calendar.</div>
                    <?php endif; ?>
                </div>
                
                <!-- Store festival holidays data for JavaScript -->
                <script>
                const festivalHolidaysData = <?php echo json_encode($festivalHolidays); ?>;
                
                function filterFestivalHolidays() {
                    const festivalHolidaySelect = document.getElementById('festivalHoliday');
                    const selectedType = document.querySelector('input[name="festivalTypeFilter"]:checked').value;
                    
                    // Clear existing options and rebuild
                    festivalHolidaySelect.innerHTML = '<option value="">Select a festival holiday</option>';
                    
                    // Add only matching festival options
                    if (typeof festivalHolidaysData !== 'undefined' && Array.isArray(festivalHolidaysData)) {
                        festivalHolidaysData.forEach(function(holiday) {
                            if (holiday.type === selectedType) {
                                const option = document.createElement('option');
                                option.value = holiday.id;
                                option.setAttribute('data-date', holiday.date);
                                option.setAttribute('data-type', holiday.type);
                                // Format date
                                const dateObj = new Date(holiday.date);
                                const formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                                option.textContent = holiday.name + ' - ' + formattedDate + ' (' + holiday.type + ')';
                                festivalHolidaySelect.appendChild(option);
                            }
                        });
                    }
                }
                </script>
                <div class="field" id="durationField">
                    <label>Duration <span class="req">*</span></label>
                    <select id="duration">
                        <option value="full">Full day</option>
                        <option value="first_half">First half</option>
                        <option value="second_half">Second half</option>
                    </select>
                </div>
                <div class="field" id="startDateField">
                    <label>Start date <span class="req">*</span></label>
                    <input type="date" id="startDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="field" id="endDateField">
                    <label>End date <span class="req">*</span></label>
                    <input type="date" id="endDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="field full" id="reasonField">
                    <label>Reason <span class="req">*</span></label>
                    <textarea id="reason" placeholder="Add a short note for your manager"></textarea>
                </div>
                <div class="field full" id="attachmentField">
                    <label>Documentation (required for sick leave > 3 days)</label>
                    <input type="file" id="attachment">
                </div>
            </div>

            <div class="flow-actions">
                <button class="btn" onclick="switchTab('my')">Cancel</button>
                <button class="btn primary" onclick="submitLeave()">Submit Request</button>
            </div>
        </div>
    </div>

<?php elseif ($tab == 'my'): ?>
    <div class="toolbar">
        <div class="filter-chip <?php echo $statusFilter == '' ? 'active' : ''; ?>" onclick="filterMyLeaves('')">All</div>
        <div class="filter-chip <?php echo $statusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterMyLeaves('Pending')">Pending</div>
        <div class="filter-chip <?php echo $statusFilter == 'Approved' ? 'active' : ''; ?>" onclick="filterMyLeaves('Approved')">Approved</div>
        <div class="filter-chip <?php echo $statusFilter == 'Rejected' ? 'active' : ''; ?>" onclick="filterMyLeaves('Rejected')">Rejected</div>
        <div class="filter-chip <?php echo $statusFilter == 'Cancelled' ? 'active' : ''; ?>" onclick="filterMyLeaves('Cancelled')">Cancelled</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="myLeaveSearch" placeholder="Search employee..." 
                   value="<?php echo htmlspecialchars($myLeaveSearch); ?>" 
                   oninput="filterMyLeavesDebounced()">
        </div>
        <select class="filter-chip" id="myLeaveDepartmentFilter" onchange="filterMyLeavesWithFilters()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $myLeaveDepartmentId == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="myLeaveTypeFilter" onchange="filterMyLeavesWithFilters()">
            <option value="">All Leave Types</option>
            <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo $lt['id']; ?>" <?php echo $myLeaveTypeId == $lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearMyLeaveFilters()">Clear Filters</button>
        <button class="btn primary" onclick="switchTab('apply')"><i class="bi bi-plus-lg"></i> Apply Leave</button>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Applied To</th>
                    <th>Approved By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($myLeaves)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-minus"></i>
                                <p>No leave applications found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($myLeaves as $leave): ?>
                        <tr>
                            <td><span class="badge accent"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span></td>
                            <td class="mono"><?php echo formatDate($leave['start_date']); ?></td>
                            <td class="mono"><?php echo formatDate($leave['end_date']); ?></td>
                            <td><?php echo number_format($leave['total_days'], 1); ?></td>
                            <td><?php echo htmlspecialchars($leave['reason'] ?? '-'); ?></td>
                            <td>
                                <?php
                                $statusClass = 'pending';
                                if ($leave['status'] == 'Approved') $statusClass = 'approved';
                                elseif ($leave['status'] == 'Rejected') $statusClass = 'rejected';
                                elseif ($leave['status'] == 'Cancelled') $statusClass = 'gray';
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($leave['status']); ?></span>
                            </td>
                            <td>Manager</td>
                            <td><?php echo $leave['approved_by'] ? htmlspecialchars($leave['manager_first'] . ' ' . $leave['manager_last']) : '—'; ?></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <?php if (in_array($leave['status'], ['Pending', 'Approved'])): ?>
                                        <div class="icon-btn" style="color:var(--danger);" onclick="cancelLeave(<?php echo $leave['id']; ?>)" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'approvals'): ?>
    <div class="toolbar">
        <div class="filter-chip <?php echo $approvalStatus == 'Pending' ? 'active' : ''; ?>" onclick="filterApprovals('Pending')">Pending</div>
        <div class="filter-chip <?php echo $approvalStatus == 'Approved' ? 'active' : ''; ?>" onclick="filterApprovals('Approved')">Approved</div>
        <div class="filter-chip <?php echo $approvalStatus == 'Rejected' ? 'active' : ''; ?>" onclick="filterApprovals('Rejected')">Rejected</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="approvalSearch" placeholder="Search employee..." 
                   value="<?php echo htmlspecialchars($approvalSearch); ?>" 
                   oninput="filterApprovalsDebounced()">
        </div>
        <select class="filter-chip" id="approvalDepartmentFilter" onchange="filterApprovalsWithFilters()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $approvalDepartmentId == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearApprovalFilters()">Clear Filters</button>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Reason</th>
                    <th>Available Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingApprovals)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-minus"></i>
                                <p>
                                    <?php if ($approvalStatus == 'Pending'): ?>No pending approvals
                                    <?php elseif ($approvalStatus == 'Approved'): ?>No approved leave requests
                                    <?php else: ?>No rejected leave requests
                                    <?php endif; ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingApprovals as $leave): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($leave['first_name'] . ' ' . $leave['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></div>
                                        <div class="emp-id mono"><?php echo htmlspecialchars($leave['employee_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge info"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span></td>
                            <td class="mono"><?php echo formatDate($leave['start_date']); ?></td>
                            <td class="mono"><?php echo formatDate($leave['end_date']); ?></td>
                            <td><?php echo number_format($leave['total_days'], 1); ?></td>
                            <td><?php echo htmlspecialchars($leave['reason'] ?? '-'); ?></td>
                            <td class="mono"><?php echo isset($leave['leave_balance']) ? number_format($leave['leave_balance'], 1) : '0.0'; ?></td>
                            <td>
                                <?php
                                $statusClass = 'pending';
                                if ($leave['status'] == 'Approved') $statusClass = 'approved';
                                elseif ($leave['status'] == 'Rejected') $statusClass = 'rejected';
                                elseif ($leave['status'] == 'Cancelled') $statusClass = 'gray';
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($leave['status']); ?></span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($leave['status'] == 'Pending' && hasPermission('leaves', 'approve') && $leave['employee_id'] != $user['id']): ?>
                                    <button class="btn sm success" onclick="approveLeave(<?php echo $leave['id']; ?>)">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button class="btn sm danger" onclick="rejectLeave(<?php echo $leave['id']; ?>)">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                    <?php elseif ($leave['status'] == 'Approved'): ?>
                                        <span style="font-size:12px;color:var(--muted);">
                                            Approved by <?php echo htmlspecialchars($leave['approver_first'] . ' ' . $leave['approver_last']); ?>
                                            <?php if ($leave['approved_at']): ?>
                                                <br><?php echo formatDate($leave['approved_at']); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif ($leave['status'] == 'Rejected'): ?>
                                        <span style="font-size:12px;color:var(--muted);">
                                            Rejected by <?php echo htmlspecialchars($leave['approver_first'] . ' ' . $leave['approver_last']); ?>
                                            <?php if ($leave['approved_at']): ?>
                                                <br><?php echo formatDate($leave['approved_at']); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:12px;color:var(--muted);">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'balances'): ?>
    <div class="row-3" style="margin-bottom:20px;">
        <?php foreach (array_slice($myBalances, 0, 3) as $balance): ?>
            <div class="panel" style="text-align:center;">
                <div class="balance-num" style="font-size:32px;color:var(--accent-dark);"><?php echo number_format(max(0, $balance['balance']), 1); ?></div>
                <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;"><?php echo htmlspecialchars($balance['leave_type_name']); ?> remaining</div>
                <div class="progress" style="margin-top:12px;">
                    <div class="progress-bar" style="width:<?php echo min(100, (max(0, $balance['balance']) / max(1, $balance['total_accrued'])) * 100); ?>%;"></div>
                </div>
                <div style="font-size:11px;color:var(--faint);margin-top:6px;"><?php echo number_format(max(0, $balance['balance']), 1); ?> of <?php echo number_format($balance['total_accrued'], 1); ?> left</div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="toolbar">
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="balanceSearch" placeholder="Search employee..." 
                   value="<?php echo htmlspecialchars($balanceSearch); ?>" 
                   oninput="filterBalancesDebounced()">
        </div>
        <select class="filter-chip" id="balanceDepartmentFilter" onchange="filterBalances()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $balanceDepartmentId == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="balanceLeaveTypeFilter" onchange="filterBalances()">
            <option value="">All Leave Types</option>
            <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo $lt['id']; ?>" <?php echo $balanceLeaveTypeId == $lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="balanceYearFilter" onchange="filterBalances()">
            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $balanceYear == $y ? 'selected' : ''; ?>>
                    <?php echo $y; ?>
                </option>
            <?php endfor; ?>
        </select>
        <button class="btn sm" onclick="clearBalanceFilters()">Clear Filters</button>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <?php foreach ($leaveTypeColumns as $leaveTypeId => $leaveTypeName): ?>
                    <th <?php if (in_array($leaveTypeName, $hiddenBalanceColumns, true)): ?>style="display:none;"<?php endif; ?>><?php echo htmlspecialchars($leaveTypeName); ?></th>
                    <?php endforeach; ?>
                    <?php if ($user['hierarchy_level'] == 1 || $user['hierarchy_level'] == 3): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($balancesByEmployee)): ?>
                    <tr>
                        <td colspan="<?php echo count($leaveTypeColumns) + 2 + (($user['hierarchy_level'] == 1 || $user['hierarchy_level'] == 3) ? 1 : 0); ?>" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-minus"></i>
                                <p>No balance records found for selected filters</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($balancesByEmployee as $empId => $employeeData): ?>
                        <tr id="employee-row-<?php echo $empId; ?>">
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($employeeData['first_name'] . ' ' . $employeeData['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($employeeData['first_name'] . ' ' . $employeeData['last_name']); ?></div>
                                        <div class="emp-id mono"><?php echo htmlspecialchars($employeeData['employee_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($employeeData['department_name'] ?? 'Not Assigned'); ?></td>
                            <?php foreach ($leaveTypeColumns as $leaveTypeId => $leaveTypeName): ?>
                            <td id="balance-cell-<?php echo $empId; ?>-<?php echo $leaveTypeId; ?>" data-emp-id="<?php echo $empId; ?>" data-leave-type-id="<?php echo $leaveTypeId; ?>" <?php if (in_array($leaveTypeName, $hiddenBalanceColumns, true)): ?>style="display:none;"<?php endif; ?>>
                                <?php
                                $balance = $employeeData['balances'][$leaveTypeId] ?? null;
                                if ($balance):
                                    echo '<div class="balance-display">';
                                    echo '<div class="balance-value">' . (int)$balance['balance'] . '</div>';
                                    echo '<div class="balance-details" style="font-size:11px;color:var(--faint);">';
                                    echo 'Accrued: ' . (int)$balance['total_accrued'] . ' | Used: ' . (int)$balance['total_used'];
                                    echo '</div>';
                                    echo '</div>';
                                else:
                                    echo '<span style="color:var(--faint);">-</span>';
                                endif;
                                ?>
                            </td>
                            <?php endforeach; ?>
                            <?php if ($user['hierarchy_level'] == 1 || $user['hierarchy_level'] == 3): ?>
                            <td>
                                <button class="btn sm" onclick="enableEditEmployee('<?php echo $empId; ?>')">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'types'): ?>
    <div class="toolbar">
        <div class="spacer"></div>
        <button class="btn primary" onclick="addLeaveType()">
            <i class="bi bi-plus-lg"></i> Add Leave Type
        </button>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>Max Days/Year</th>
                    <th>Assign To</th>
                    <th>Carry Forward</th>
                    <th>Paid</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allLeaveTypes as $lt): ?>
                    <?php 
                    $assignToText = 'All Employees';
                    if (isset($lt['assign_to'])) {
                        if ($lt['assign_to'] == 'department') {
                            $assignToText = 'Department';
                        } elseif ($lt['assign_to'] == 'role') {
                            $assignToText = 'Role';
                        } elseif ($lt['assign_to'] == 'user') {
                            $assignToText = 'User';
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($lt['name']); ?></td>
                        <td><?php echo $lt['max_days_per_year']; ?></td>
                        <td><?php echo htmlspecialchars($assignToText); ?></td>
                        <td><?php echo $lt['carry_forward'] ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $lt['is_paid'] ? 'Yes' : 'No'; ?></td>
                        <td><span class="badge <?php echo $lt['is_active'] ? 'success' : 'gray'; ?>"><?php echo $lt['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <div class="actions">
                                <div class="icon-btn" onclick="editLeaveType(<?php echo $lt['id']; ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </div>
                                <div class="icon-btn" style="color:var(--danger);" onclick="deleteLeaveType(<?php echo $lt['id']; ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Leave Type Modal -->
<div id="leaveTypeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;max-height:92vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="leaveTypeModalTitle">Add Leave Type</h3>
            <button onclick="closeLeaveTypeModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="leaveTypeId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Name <span class="req">*</span></label>
                <input type="text" id="ltName" placeholder="e.g. Wedding Leave">
            </div>
            <div class="field">
                <label>Max Days / Year</label>
                <input type="number" id="ltMaxDays" min="0" value="0">
            </div>
            <div class="field">
                <label>Min Days / Request</label>
                <input type="number" id="ltMinDays" min="0" step="0.5" value="0.5">
            </div>
            <div class="field">
                <label>Calculation Basis</label>
                <select id="ltBasis">
                    <option value="working_days">Working days (excl. weekends)</option>
                    <option value="calendar">Calendar days</option>
                </select>
            </div>
            <div class="field">
                <label>Accrual Method</label>
                <select id="ltAccrual">
                    <option value="fixed_annual">Fixed Annual</option>
                    <option value="monthly_prorated">Monthly Prorated</option>
                </select>
            </div>
            <div class="field">
                <label>Assign To</label>
                <select id="ltAssignTo" onchange="updateTargetField()">
                    <option value="all_employees">All Employees</option>
                    <option value="department">Department</option>
                    <option value="role">Role</option>
                    <option value="user">User</option>
                </select>
            </div>
            <div class="field" id="targetFieldContainer" style="display:none;">
                <label id="targetLabel">Target</label>
                <select id="ltTarget">
                    <option value="">Select target...</option>
                </select>
            </div>
            <div class="field">
                <label>Max Carry Forward Days</label>
                <input type="number" id="ltCarryMax" min="0" value="0">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="ltDescription" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="field" style="display:flex;gap:20px;grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltCarry" value="1"> Carry forward</label>
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltPaid" checked> Paid</label>
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltApproval" checked> Requires approval</label>
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltActive" checked> Active</label>
            </div>
        </div>
        
        <!-- Monthly Accrual Settings -->
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 12px 0;">Monthly Accrual Settings</h4>
            <div style="display:flex;gap:16px;margin-bottom:12px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltMonthlyAccrualEnabled" onchange="toggleMonthlyAccrualFields()"> Enable monthly accrual</label>
            </div>
            <div id="monthlyAccrualFields" style="display:none;">
                <div class="form-grid">
                    <div class="field">
                        <label>Days to Accrue Monthly</label>
                        <input type="number" id="ltMonthlyAccrualDays" min="0" step="0.5" value="0" placeholder="e.g. 1.5">
                    </div>
                    <div class="field">
                        <label>Accrual Timing</label>
                        <select id="ltAccrualTiming" onchange="toggleAccrualDayField()">
                            <option value="pay_cycle_end">After pay cycle ends</option>
                            <option value="pay_cycle_start">After pay cycle starts</option>
                            <option value="specific_day">Specific day of month</option>
                        </select>
                    </div>
                    <div class="field" id="accrualDayField" style="display:none;">
                        <label>Day of Month</label>
                        <input type="number" id="ltAccrualDayOfMonth" min="1" max="31" value="1" placeholder="1-31">
                    </div>
                </div>
            </div>
        </div>
        
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeLeaveTypeModal()">Cancel</button>
            <button class="btn primary" onclick="saveLeaveType()">Save Leave Type</button>
        </div>
    </div>
</div>

<!-- Leave Detail Modal -->
<div id="leaveDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:600px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Leave Details</h3>
            <button onclick="closeLeaveDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="leaveDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button class="btn" onclick="closeLeaveDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Cancel Leave Confirmation Modal -->
<div id="cancelLeaveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:500px;max-width:95vw;padding:24px;">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="width:48px;height:48px;background:var(--danger-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x-lg" style="font-size:24px;color:var(--danger);"></i>
            </div>
            <div>
                <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Cancel Leave Request</h3>
                <p style="margin:4px 0 0 0;color:var(--muted);font-size:13px;">This action cannot be undone</p>
            </div>
        </div>
        <p style="color:var(--ink);line-height:1.6;margin-bottom:24px;">
            Are you sure you want to cancel this leave request?<?php if (hasPermission('leaves', 'update')): ?> If the leave was approved, the balance will be restored to the employee's account.<?php endif; ?>
        </p>
        <input type="hidden" id="cancelLeaveId">
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button class="btn" onclick="closeCancelLeaveModal()">Cancel</button>
            <button class="btn danger" onclick="confirmCancelLeave()">Yes, Cancel Leave</button>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.location = url.toString();
}

// Calendar filtering
let calendarFilterTimeout;
function filterCalendarDebounced() {
    clearTimeout(calendarFilterTimeout);
    calendarFilterTimeout = setTimeout(filterCalendar, 450);
}

function filterCalendar() {
    const url = new URL(window.location);
    const employeeSearch = document.getElementById('calendarEmployeeSearch').value;
    const departmentId = document.getElementById('calendarDepartmentFilter').value;
    const leaveTypeId = document.getElementById('calendarLeaveTypeFilter').value;
    
    if (employeeSearch) {
        url.searchParams.set('calendar_employee', employeeSearch);
    } else {
        url.searchParams.delete('calendar_employee');
    }
    
    if (departmentId) {
        url.searchParams.set('calendar_department', departmentId);
    } else {
        url.searchParams.delete('calendar_department');
    }
    
    if (leaveTypeId) {
        url.searchParams.set('calendar_leave_type', leaveTypeId);
    } else {
        url.searchParams.delete('calendar_leave_type');
    }
    
    HrmsSearch.navigate(url.toString());
}

function clearCalendarFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('calendar_employee');
    url.searchParams.delete('calendar_department');
    url.searchParams.delete('calendar_leave_type');
    window.location = url.toString();
}

function changeMonth(delta) {
    const url = new URL(window.location);
    const currentMonth = url.searchParams.get('month') || new Date().toISOString().slice(0, 7);
    const date = new Date(currentMonth + '-01');
    date.setMonth(date.getMonth() + delta);
    url.searchParams.set('month', date.toISOString().slice(0, 7));
    window.location = url.toString();
}

function filterMyLeaves(status) {
    const url = new URL(window.location);
    if (status) {
        url.searchParams.set('status', status);
    } else {
        url.searchParams.delete('status');
    }
    window.location = url.toString();
}

let myLeaveFilterTimeout;
function filterMyLeavesDebounced() {
    clearTimeout(myLeaveFilterTimeout);
    myLeaveFilterTimeout = setTimeout(filterMyLeavesWithFilters, 450);
}

function filterMyLeavesWithFilters() {
    const url = new URL(window.location);
    const search = document.getElementById('myLeaveSearch').value;
    const departmentId = document.getElementById('myLeaveDepartmentFilter').value;
    const leaveTypeId = document.getElementById('myLeaveTypeFilter').value;
    const status = url.searchParams.get('status') || '';
    
    if (search) {
        url.searchParams.set('my_search', search);
    } else {
        url.searchParams.delete('my_search');
    }
    
    if (departmentId) {
        url.searchParams.set('my_department', departmentId);
    } else {
        url.searchParams.delete('my_department');
    }
    
    if (leaveTypeId) {
        url.searchParams.set('my_leave_type', leaveTypeId);
    } else {
        url.searchParams.delete('my_leave_type');
    }
    
    HrmsSearch.navigate(url.toString());
}

function clearMyLeaveFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('my_search');
    url.searchParams.delete('my_department');
    url.searchParams.delete('my_leave_type');
    window.location = url.toString();
}

// Approvals filtering
function filterApprovals(status) {
    const url = new URL(window.location);
    if (status) {
        url.searchParams.set('approval_status', status);
    } else {
        url.searchParams.delete('approval_status');
    }
    window.location = url.toString();
}

let approvalFilterTimeout;
function filterApprovalsDebounced() {
    clearTimeout(approvalFilterTimeout);
    approvalFilterTimeout = setTimeout(filterApprovalsWithFilters, 450);
}

function filterApprovalsWithFilters() {
    const url = new URL(window.location);
    const search = document.getElementById('approvalSearch').value;
    const departmentId = document.getElementById('approvalDepartmentFilter').value;
    const status = url.searchParams.get('approval_status') || 'Pending';
    
    if (search) {
        url.searchParams.set('approval_search', search);
    } else {
        url.searchParams.delete('approval_search');
    }
    
    if (departmentId) {
        url.searchParams.set('approval_department', departmentId);
    } else {
        url.searchParams.delete('approval_department');
    }
    
    HrmsSearch.navigate(url.toString());
}

function clearApprovalFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('approval_search');
    url.searchParams.delete('approval_department');
    window.location = url.toString();
}

// Balances filtering
let balanceFilterTimeout;
function filterBalancesDebounced() {
    clearTimeout(balanceFilterTimeout);
    balanceFilterTimeout = setTimeout(filterBalances, 450);
}

function filterBalances() {
    const url = new URL(window.location);
    const search = document.getElementById('balanceSearch').value;
    const departmentId = document.getElementById('balanceDepartmentFilter').value;
    const leaveTypeId = document.getElementById('balanceLeaveTypeFilter').value;
    const year = document.getElementById('balanceYearFilter').value;
    
    if (search) {
        url.searchParams.set('balance_search', search);
    } else {
        url.searchParams.delete('balance_search');
    }
    
    if (departmentId) {
        url.searchParams.set('balance_department', departmentId);
    } else {
        url.searchParams.delete('balance_department');
    }
    
    if (leaveTypeId) {
        url.searchParams.set('balance_leave_type', leaveTypeId);
    } else {
        url.searchParams.delete('balance_leave_type');
    }
    
    if (year) {
        url.searchParams.set('balance_year', year);
    } else {
        url.searchParams.delete('balance_year');
    }
    
    HrmsSearch.navigate(url.toString());
}

function clearBalanceFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('balance_search');
    url.searchParams.delete('balance_department');
    url.searchParams.delete('balance_leave_type');
    url.searchParams.delete('balance_year');
    window.location = url.toString();
}

function handleLeaveTypeChange() {
    const leaveTypeSelect = document.getElementById('leaveType');
    const festivalHolidayField = document.getElementById('festivalHolidayField');
    const festivalHolidaySelect = document.getElementById('festivalHoliday');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const durationField = document.getElementById('durationField');
    const startDateField = document.getElementById('startDateField');
    const endDateField = document.getElementById('endDateField');
    const reasonField = document.getElementById('reasonField');
    const attachmentField = document.getElementById('attachmentField');
    
    // Get selected leave type name
    const selectedOption = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
    const leaveTypeName = selectedOption ? selectedOption.text.trim() : '';
    
    // Show festival holiday dropdown for Festival Leave and Optional Festival Leave
    // Use contains for more flexible matching
    const isFestivalLeave = leaveTypeName.includes('Festival Leave') && !leaveTypeName.includes('Optional');
    const isOptionalFestivalLeave = leaveTypeName.includes('Optional Festival Leave');
    
    if (isFestivalLeave || isOptionalFestivalLeave) {
        festivalHolidayField.style.display = 'block';
        
        // Hide other fields for Festival Leave types
        durationField.style.display = 'none';
        startDateField.style.display = 'none';
        endDateField.style.display = 'none';
        reasonField.style.display = 'none';
        attachmentField.style.display = 'none';
        
        // Set the appropriate radio button based on leave type
        const fixedRadio = document.querySelector('input[name="festivalTypeFilter"][value="Fixed"]');
        const optionalRadio = document.querySelector('input[name="festivalTypeFilter"][value="Optional"]');
        
        if (isOptionalFestivalLeave) {
            optionalRadio.checked = true;
        } else {
            fixedRadio.checked = true;
        }
        
        // Call the filter function to populate the dropdown
        filterFestivalHolidays();
        
        // Auto-fill dates when festival is selected
        festivalHolidaySelect.onchange = function() {
            const selectedHolidayOption = this.options[this.selectedIndex];
            if (selectedHolidayOption && selectedHolidayOption.value) {
                const holidayDate = selectedHolidayOption.getAttribute('data-date');
                if (holidayDate) {
                    startDateInput.value = holidayDate;
                    endDateInput.value = holidayDate;
                }
            }
        };
    } else {
        festivalHolidayField.style.display = 'none';
        festivalHolidaySelect.value = '';
        
        // Show other fields for regular leave types
        durationField.style.display = 'block';
        startDateField.style.display = 'block';
        endDateField.style.display = 'block';
        reasonField.style.display = 'block';
        attachmentField.style.display = 'block';
    }
}

function submitLeave() {
    const leaveTypeId = document.getElementById('leaveType').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const duration = document.getElementById('duration').value;
    const reason = document.getElementById('reason').value.trim();
    const attachment = document.getElementById('attachment').files[0];
    const festivalHolidayId = document.getElementById('festivalHoliday').value;

    // Check if Festival Leave is selected
    const selectedOption = document.getElementById('leaveType').options[document.getElementById('leaveType').selectedIndex];
    const leaveTypeName = selectedOption ? selectedOption.text.trim() : '';
    
    console.log('Submit - Selected leave type:', leaveTypeName);
    
    if (!leaveTypeId) {
        alert('Please select a leave type.');
        return;
    }
    
    // Use the same matching logic as handleLeaveTypeChange
    const isFestivalLeave = leaveTypeName.includes('Festival Leave') && !leaveTypeName.includes('Optional');
    const isOptionalFestivalLeave = leaveTypeName.includes('Optional Festival Leave');
    
    // For Festival Leave types, validate festival holiday selection
    if (isFestivalLeave || isOptionalFestivalLeave) {
        if (!festivalHolidayId) {
            alert('Please select a festival holiday from the dropdown.');
            return;
        }
        // Auto-fill dates from selected holiday
        const selectedHolidayOption = document.getElementById('festivalHoliday').options[document.getElementById('festivalHoliday').selectedIndex];
        if (selectedHolidayOption && selectedHolidayOption.value) {
            const holidayDate = selectedHolidayOption.getAttribute('data-date');
            if (holidayDate) {
                document.getElementById('startDate').value = holidayDate;
                document.getElementById('endDate').value = holidayDate;
            }
        }
    } else {
        // For regular leave types, validate all fields
        if (!startDate || !endDate) {
            alert('Please select both start and end dates.');
            return;
        }
        if (endDate < startDate) {
            alert('End date cannot be before start date.');
            return;
        }
        if (!reason) {
            alert('Please provide a reason.');
            return;
        }
    }

    const formData = new FormData();
    formData.append('action', 'apply');
    formData.append('leave_type_id', leaveTypeId);
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);
    formData.append('duration', duration);
    
    // For Festival Leave types, use holiday name as reason and include festival type
    if (isFestivalLeave || isOptionalFestivalLeave) {
        const selectedHolidayOption = document.getElementById('festivalHoliday').options[document.getElementById('festivalHoliday').selectedIndex];
        const holidayName = selectedHolidayOption ? selectedHolidayOption.text.split(' - ')[0] : 'Festival Holiday';
        const festivalType = selectedHolidayOption ? selectedHolidayOption.getAttribute('data-type') : 'Fixed';
        formData.append('reason', holidayName);
        formData.append('festival_type', festivalType);
    } else {
        formData.append('reason', reason);
    }
    
    if (festivalHolidayId) {
        formData.append('festival_holiday_id', festivalHolidayId);
    }
    if (attachment) {
        formData.append('attachment', attachment);
    }

    const btn = document.querySelector('.flow-actions .btn.primary');
    if (btn) btn.disabled = true;

    fetch('api/leaves.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.disabled = false;
        if (data.success) {
            alert(data.message);
            switchTab('my');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not submit request'));
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        console.error(err);
        alert('Error submitting request');
    });
}

function cancelLeave(id) {
    document.getElementById('cancelLeaveId').value = id;
    document.getElementById('cancelLeaveModal').style.display = 'flex';
}

function closeCancelLeaveModal() {
    document.getElementById('cancelLeaveModal').style.display = 'none';
    document.getElementById('cancelLeaveId').value = '';
}

function confirmCancelLeave() {
    const id = document.getElementById('cancelLeaveId').value;
    if (!id) return;
    
    closeCancelLeaveModal();
    
    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not cancel request'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error cancelling request');
    });
}

function approveLeave(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to approve this leave request?',
        confirmText: 'Approve' 
    })) return;
    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'approve', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not approve request'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error approving request');
    });
}

function rejectLeave(id) {
    ModalSystem.prompt({
        title: 'Reject Leave Request',
        message: 'Enter rejection reason:',
        placeholder: 'Reason for rejection',
        required: true
    }).then(reason => {
        if (reason === null) return;
        
        fetch('api/leaves.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject', id: id, reason: reason.trim() })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not reject request'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error rejecting request');
        });
    });
}

function viewLeaveDetails(id) {
    fetch(`api/leaves.php?leave_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Could not load leave details'));
                return;
            }
            const l = data.leave;
            const statusClass = l.status === 'Approved' ? 'success' : (l.status === 'Rejected' || l.status === 'Cancelled' ? 'danger' : 'pending');
            const appliedOn = l.created_at ? new Date(l.created_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
            document.getElementById('leaveDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                        <span><b>Employee:</b> ${l.first_name || ''} ${l.last_name || ''} <span class="mono" style="color:var(--faint);">(${l.employee_id || ''})</span></span>
                        <span><b>Status:</b> <span class="badge ${statusClass}">${l.status}</span></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Leave Type:</b> ${l.leave_type_name}</span>
                        <span><b>Days:</b> ${l.total_days}</span>
                    </div>
                    <div style="margin-top:8px;"><b>From:</b> ${l.start_date} &nbsp;&nbsp; <b>To:</b> ${l.end_date}</div>
                </div>
                <p style="color:var(--ink);"><b>Reason:</b><br>${(l.reason || '—').replace(/\\n/g, '<br>')}</p>
                ${l.rejection_reason ? '<p style="color:var(--danger);"><b>Rejection Reason:</b> ' + l.rejection_reason.replace(/\\n/g, '<br>') + '</p>' : ''}
                <div style="font-size:12px;color:var(--muted);"><b>Applied:</b> ${appliedOn}</div>
                ${l.approver_first ? '<div style="font-size:12px;color:var(--muted);"><b>Decided By:</b> ' + l.approver_first + ' ' + l.approver_last + (l.approved_at ? ' on ' + new Date(l.approved_at).toLocaleString() : '') + '</div>' : ''}
            `;
            document.getElementById('leaveDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading leave details');
        });
}

function closeLeaveDetailModal() {
    document.getElementById('leaveDetailModal').style.display = 'none';
}

// Assignment data and target field management
let assignmentData = {
    departments: [],
    roles: [],
    users: []
};

function loadAssignmentData() {
    return fetch('api/leaves.php?get_assignment_data=1')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                assignmentData.departments = data.departments || [];
                assignmentData.roles = data.roles || [];
                assignmentData.users = data.users || [];
            }
        })
        .catch(err => {
            console.error('Error loading assignment data:', err);
        });
}

function updateTargetField() {
    const assignToField = document.getElementById('ltAssignTo');
    const targetContainer = document.getElementById('targetFieldContainer');
    const targetLabel = document.getElementById('targetLabel');
    const targetSelect = document.getElementById('ltTarget');
    
    if (!assignToField || !targetContainer || !targetLabel || !targetSelect) {
        return; // Fields don't exist yet
    }
    
    const assignTo = assignToField.value;
    
    // Clear existing options
    targetSelect.innerHTML = '<option value="">Select target...</option>';
    
    if (assignTo === 'all_employees') {
        targetContainer.style.display = 'none';
        targetSelect.value = '';
    } else {
        targetContainer.style.display = 'block';
        
        if (assignTo === 'department') {
            targetLabel.textContent = 'Select Department';
            assignmentData.departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'role') {
            targetLabel.textContent = 'Select Role';
            assignmentData.roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'user') {
            targetLabel.textContent = 'Select User';
            assignmentData.users.forEach(user => {
                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = `${user.first_name} ${user.last_name} (${user.employee_id})`;
                targetSelect.appendChild(option);
            });
        }
    }
}

function openLeaveTypeModal(type) {
    const isEdit = !!type && type.id;
    document.getElementById('leaveTypeModalTitle').textContent = isEdit ? 'Edit Leave Type' : 'Add Leave Type';
    document.getElementById('leaveTypeId').value = type ? type.id : '';
    document.getElementById('ltName').value = type ? (type.name || '') : '';
    document.getElementById('ltMaxDays').value = type ? (type.max_days_per_year || 0) : 0;
    document.getElementById('ltMinDays').value = type ? (type.min_days_per_request || 0.5) : 0.5;
    document.getElementById('ltBasis').value = type ? (type.calculation_basis || 'working_days') : 'working_days';
    document.getElementById('ltAccrual').value = type ? (type.accrual_method || 'fixed_annual') : 'fixed_annual';
    document.getElementById('ltCarryMax').value = type ? (type.max_carry_forward_days || 0) : 0;
    document.getElementById('ltDescription').value = type ? (type.description || '') : '';
    document.getElementById('ltCarry').checked = type ? !!type.carry_forward : false;
    document.getElementById('ltPaid').checked = type ? !!type.is_paid : true;
    document.getElementById('ltApproval').checked = type ? !!type.requires_approval : true;
    document.getElementById('ltActive').checked = type ? !!type.is_active : true;

    // Monthly accrual fields
    document.getElementById('ltMonthlyAccrualEnabled').checked = type ? !!type.monthly_accrual_enabled : false;
    document.getElementById('ltMonthlyAccrualDays').value = type ? (type.monthly_accrual_days || 0) : 0;
    document.getElementById('ltAccrualTiming').value = type ? (type.accrual_timing || 'pay_cycle_end') : 'pay_cycle_end';
    document.getElementById('ltAccrualDayOfMonth').value = type ? (type.accrual_day_of_month || 1) : 1;

    // Toggle monthly accrual fields visibility
    toggleMonthlyAccrualFields();
    toggleAccrualDayField();
    
    // Show modal first
    document.getElementById('leaveTypeModal').style.display = 'flex';
    
    // Load assignment data and handle new fields
    loadAssignmentData().then(() => {
        const assignToField = document.getElementById('ltAssignTo');
        const targetField = document.getElementById('ltTarget');
        
        if (assignToField && targetField) {
            assignToField.value = type && type.assign_to ? type.assign_to : 'all_employees';
            // Populate the target options first, then restore the previously saved selection
            updateTargetField();
            if (type && type.assign_to && type.assign_to !== 'all_employees' && type.target_id) {
                targetField.value = type.target_id;
            }
        }
    });
}

function addLeaveType() {
    openLeaveTypeModal(null);
}

function editLeaveType(id) {
    fetch(`api/leaves.php?type_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openLeaveTypeModal(data.type);
            } else {
                alert('Error loading leave type: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading leave type');
        });
}

function closeLeaveTypeModal() {
    document.getElementById('leaveTypeModal').style.display = 'none';
}

function toggleMonthlyAccrualFields() {
    const enabled = document.getElementById('ltMonthlyAccrualEnabled').checked;
    const fields = document.getElementById('monthlyAccrualFields');
    fields.style.display = enabled ? 'block' : 'none';

    // Reset values when disabled
    if (!enabled) {
        document.getElementById('ltMonthlyAccrualDays').value = 0;
        document.getElementById('ltAccrualTiming').value = 'pay_cycle_end';
        document.getElementById('ltAccrualDayOfMonth').value = 1;
    }
}

function toggleAccrualDayField() {
    const timing = document.getElementById('ltAccrualTiming').value;
    const dayField = document.getElementById('accrualDayField');
    dayField.style.display = timing === 'specific_day' ? 'block' : 'none';
}

// Balance editing functions (Super Admin and HR Manager only)
let currentEditingEmployee = null;

function enableEditEmployee(employeeId) {
    console.log('Enable edit called for employee:', employeeId);
    
    // Disable any existing edit mode
    if (currentEditingEmployee && currentEditingEmployee !== employeeId) {
        cancelEditEmployee(currentEditingEmployee);
    }
    
    currentEditingEmployee = employeeId;
    const row = document.getElementById(`employee-row-${employeeId}`);
    
    if (!row) {
        console.error('Row not found for employee:', employeeId);
        alert('Error: Row not found for employee ID: ' + employeeId);
        return;
    }
    
    console.log('Row found:', row);
    
    // Replace balance cells with input fields
    const balanceCells = row.querySelectorAll('[id^="balance-cell-"]');
    console.log('Balance cells found:', balanceCells.length);
    
    if (balanceCells.length === 0) {
        console.error('No balance cells found');
        alert('Error: No balance cells found for editing');
        return;
    }
    
    balanceCells.forEach(cell => {
        const empId = cell.getAttribute('data-emp-id');
        const leaveTypeId = cell.getAttribute('data-leave-type-id');
        
        console.log('Processing cell for empId:', empId, 'leaveTypeId:', leaveTypeId);
        
        // Get current balance value
        const balanceDisplay = cell.querySelector('.balance-value');
        const currentValue = balanceDisplay ? balanceDisplay.textContent.trim() : '0';
        
        console.log('Current value:', currentValue);
        
        // Replace with input field (integer only)
        cell.innerHTML = `
            <input type="number" 
                   id="balance-input-${empId}-${leaveTypeId}" 
                   class="balance-input" 
                   data-emp-id="${empId}"
                   data-leave-type-id="${leaveTypeId}"
                   value="${currentValue}" 
                   min="0" 
                   step="1"
                   style="width:80px;padding:4px 8px;border:1px solid var(--accent);border-radius:4px;">
        `;
    });
    
    // Change Edit button to Save/Cancel buttons
    const actionCell = row.querySelector('td:last-child');
    if (actionCell) {
        actionCell.innerHTML = `
            <button class="btn sm primary" onclick="saveEmployeeBalances('${employeeId}')">
                <i class="bi bi-check"></i> Save
            </button>
            <button class="btn sm" onclick="cancelEditEmployee('${employeeId}')">
                <i class="bi bi-x"></i> Cancel
            </button>
        `;
    } else {
        console.error('Action cell not found');
    }
}

function cancelEditEmployee(employeeId) {
    console.log('Cancel edit called for employee:', employeeId);
    // Reload the page to restore original values
    location.reload();
}

function saveEmployeeBalances(employeeId) {
    console.log('Save balances called for employee:', employeeId);
    const row = document.getElementById(`employee-row-${employeeId}`);
    const balanceInputs = row.querySelectorAll('.balance-input');
    const balanceUpdates = [];
    
    balanceInputs.forEach(input => {
        const empId = input.getAttribute('data-emp-id');
        const leaveTypeId = input.getAttribute('data-leave-type-id');
        const newValue = parseInt(input.value);
        
        console.log('Processing input - empId:', empId, 'leaveTypeId:', leaveTypeId, 'newValue:', newValue);
        
        if (!isNaN(newValue) && newValue >= 0 && empId && leaveTypeId) {
            balanceUpdates.push({
                employee_id: empId,  // Keep as string like "EMP7482"
                leave_type_id: leaveTypeId,
                new_balance: newValue
            });
        }
    });
    
    if (balanceUpdates.length === 0) {
        alert('No valid balance values to update.');
        return;
    }
    
    const reason = prompt('Please provide a reason for these balance changes:');
    if (!reason || reason.trim() === '') {
        alert('Reason is required for balance changes.');
        return;
    }
    
    const payload = {
        action: 'edit_employee_balances',
        employee_id: employeeId,  // Keep as string like "EMP7482"
        balance_updates: balanceUpdates,
        reason: reason.trim()
    };
    
    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Balances updated successfully.');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not update balances'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error updating balances');
    });
}

function saveLeaveType() {
    const id = document.getElementById('leaveTypeId').value;
    const name = document.getElementById('ltName').value.trim();
    if (!name) {
        alert('Leave type name is required');
        return;
    }
    const payload = {
        action: 'save_type',
        id: id || null,
        name: name,
        max_days_per_year: document.getElementById('ltMaxDays').value,
        min_days_per_request: document.getElementById('ltMinDays').value,
        calculation_basis: document.getElementById('ltBasis').value,
        accrual_method: document.getElementById('ltAccrual').value,
        max_carry_forward_days: document.getElementById('ltCarryMax').value,
        description: document.getElementById('ltDescription').value,
        carry_forward: document.getElementById('ltCarry').checked ? 1 : 0,
        is_paid: document.getElementById('ltPaid').checked ? 1 : 0,
        requires_approval: document.getElementById('ltApproval').checked ? 1 : 0,
        is_active: document.getElementById('ltActive').checked ? 1 : 0,
        // Monthly accrual fields
        monthly_accrual_enabled: document.getElementById('ltMonthlyAccrualEnabled').checked ? 1 : 0,
        monthly_accrual_days: document.getElementById('ltMonthlyAccrualDays').value,
        accrual_timing: document.getElementById('ltAccrualTiming').value,
        accrual_day_of_month: document.getElementById('ltAccrualDayOfMonth').value || null
    };
    
    // Add new assignment fields if they exist in the DOM
    const assignToField = document.getElementById('ltAssignTo');
    const targetField = document.getElementById('ltTarget');
    if (assignToField) {
        payload.assign_to = assignToField.value;
    }
    if (targetField) {
        payload.target_id = targetField.value || null;
    }

    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeLeaveTypeModal();
            location.reload();
        } else {
            alert('Error saving leave type: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving leave type');
    });
}

function deleteLeaveType(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this leave type? This action cannot be undone.',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    
    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_type', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not delete leave type'));
        }
    })
    .catch(err => {
        console.error('Delete error:', err);
        alert('Error deleting leave type: ' + err.message);
    });
}
</script>

