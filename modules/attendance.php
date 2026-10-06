<?php
/**
 * Attendance Module
 * Core PHP HRMS Application
 * Section 11 - Attendance management module
 */

$db = Database::getInstance();
$user = getCurrentUser();

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';
$permissions = new Permissions();
$workPatternService = new WorkPatternService();

// Check permissions
if (!hasPermission('attendance', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view attendance.</div>';
    exit;
}

// Get view type first (needed for per-view scoping below)
$view = $_GET['view'] ?? 'calendar'; // calendar, table

// Get scoped employee IDs for attendance
// Direct hierarchy branch scope (same rule as Reports):
// privileged roles (HR Manager / Super Admin / Admin / HR Executive) -> all employees,
// everyone else -> direct reporting branch only (self + recursive reports)
$scopedIds = $permissions->getDirectBranchScopeIds($user['id']);
// HR Executive: the Calendar tab (and its date-click modal) shows all employees,
// but the Attendance Table tab keeps her previous own-branch scope (own data only).
if ($view === 'table' && strtolower((string) ($user['role_name'] ?? '')) === 'hr executive') {
    $scopedIds = $permissions->getDirectBranchScopeIds($user['id'], 'hr executive');
}
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// Get month for calendar view
// Default to the month of the most recent attendance_date with data
$mostRecentMonth = $db->fetchOne(
    "SELECT DATE_FORMAT(attendance_date, '%Y-%m') as month FROM attendance_daily 
     WHERE attendance_date <= CURDATE() 
     ORDER BY attendance_date DESC 
     LIMIT 1"
);
$month = $_GET['month'] ?? ($mostRecentMonth['month'] ?? date('Y-m'));

// Get table view mode (inout or summary)
$tableMode = $_GET['table_mode'] ?? 'summary';

// Get date filter for table view (single date)
// Default to the most recent attendance_date with data, otherwise use today
$mostRecentDate = $db->fetchOne(
    "SELECT attendance_date FROM attendance_daily 
     WHERE attendance_date <= CURDATE() 
     ORDER BY attendance_date DESC 
     LIMIT 1"
);
$selectedDate = $_GET['date'] ?? ($mostRecentDate['attendance_date'] ?? date('Y-m-d'));

// Get filter parameters
$filters = [
    'employee' => $_GET['employee'] ?? '',
    'department' => $_GET['department'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Build filter conditions
$whereConditions = ["e.id IN ({$scopedIdsStr})"];
$params = [];

if (!empty($filters['employee'])) {
    $whereConditions[] = "e.id = ?";
    $params[] = $filters['employee'];
}

if (!empty($filters['department'])) {
    $whereConditions[] = "ed.department_id = ?";
    $params[] = $filters['department'];
}

if (!empty($filters['status'])) {
    $whereConditions[] = "ad.status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['search'])) {
    $whereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

// Get attendance records based on table mode
if ($tableMode == 'inout') {
    // Get individual punch records
    $showBreaks = $_GET['show_breaks'] ?? '0';
    $baseQuery = "SELECT ap.*, e.first_name, e.last_name, e.employee_id, e.shift_id,
         s.name as shift_name,
         d.name as department_name,
         bt.name as break_type_name
         FROM attendance_punches ap
         JOIN employees e ON ap.employee_id = e.id
         LEFT JOIN shifts s ON e.shift_id = s.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
             SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
         )
         LEFT JOIN departments d ON ed.department_id = d.id
         LEFT JOIN break_types bt ON ap.break_type_id = bt.id
         WHERE ap.attendance_date = ?";
    
    if ($showBreaks == '0') {
        $baseQuery .= " AND ap.event_type IN ('CLOCK_IN', 'CLOCK_OUT')";
    }
    
    if (!empty($whereClause) && $whereClause !== '1=1') {
        $baseQuery .= " AND " . $whereClause;
    }
    
    $baseQuery .= " ORDER BY ap.timestamp_utc DESC";
    
    $attendance = $db->fetchAll($baseQuery, array_merge([$selectedDate], $params));
} else {
    // Get daily summary records
    $baseQuery = "SELECT ad.*, e.first_name, e.last_name, e.employee_id, s.name as shift_name,
         d.name as department_name,
         (SELECT ap.source FROM attendance_punches ap
          WHERE ap.employee_id = e.id AND ap.attendance_date = ad.attendance_date
            AND ap.event_type = 'CLOCK_OUT'
          ORDER BY ap.timestamp_utc DESC, ap.id DESC LIMIT 1) AS last_out_source
         FROM attendance_daily ad
         JOIN employees e ON ad.employee_id = e.id
         LEFT JOIN shifts s ON ad.shift_id = s.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
             SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
         )
         LEFT JOIN departments d ON ed.department_id = d.id
         WHERE ad.attendance_date = ?";
    
    if (!empty($whereClause) && $whereClause !== '1=1') {
        $baseQuery .= " AND " . $whereClause;
    }
    
    $baseQuery .= " ORDER BY e.first_name, e.last_name";
    
    $attendance = $db->fetchAll($baseQuery, array_merge([$selectedDate], $params));
}

// Get status counts for the selected date
$statusCounts = $db->fetchAll(
    "SELECT status, COUNT(*) as count 
     FROM attendance_daily 
     WHERE attendance_date = ? AND employee_id IN ({$scopedIdsStr})
     GROUP BY status",
    [$selectedDate]
);

$statusCountMap = array_column($statusCounts, 'count', 'status');

// Employees for bulk-mark & regularization modals
$employeeList = $db->fetchAll(
    "SELECT e.id, e.first_name, e.last_name, e.employee_id, d.name as department_name
     FROM employees e
     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
         SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
     )
     LEFT JOIN departments d ON ed.department_id = d.id
     WHERE e.is_active = 1 AND e.id IN ({$scopedIdsStr})
     ORDER BY e.first_name, e.last_name"
);

// Get departments for filters
$departments = $db->fetchAll(
    "SELECT * FROM departments WHERE is_active = 1 ORDER BY name"
);
?>

<div class="panel-head">
</div>

<div class="tabs">
    <div class="tab <?php echo $view == 'calendar' ? 'active' : ''; ?>" onclick="switchView('calendar')">Calendar</div>
    <div class="tab <?php echo $view == 'table' ? 'active' : ''; ?>" onclick="switchView('table')">Attendance Table</div>
    <div class="tab <?php echo $view == 'regularization' ? 'active' : ''; ?>" onclick="switchView('regularization')">Regularization</div>
</div>

<?php if ($view == 'calendar'): ?>
    <?php
    // Get calendar data
    $currentMonth = new DateTime($month);
    $firstDay = clone $currentMonth;
    $firstDay->modify('first day of this month');
    $lastDay = clone $currentMonth;
    $lastDay->modify('last day of this month');
    
    // Get attendance summary for each day
    $attendanceSummary = [];
    for ($d = clone $firstDay; $d <= $lastDay; $d->modify('+1 day')) {
        $dateStr = $d->format('Y-m-d');
        
        $baseQuery = "SELECT
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'Half Day' THEN 1 ELSE 0 END) as half_day,
                SUM(CASE WHEN status = 'On Leave' THEN 1 ELSE 0 END) as on_leave
             FROM attendance_daily ad
             JOIN employees e ON ad.employee_id = e.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
                 SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
             )
             WHERE ad.attendance_date = ?";
        
        $queryParams = [$dateStr];
        
        if (!empty($whereClause) && $whereClause !== '1=1') {
            $baseQuery .= " AND " . $whereClause;
            $queryParams = array_merge($queryParams, $params);
        }
        
        $summary = $db->fetchOne($baseQuery, $queryParams);
        $attendanceSummary[$dateStr] = $summary;
    }
    
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
        <select class="filter-chip" onchange="applyFilter('employee', this.value)" style="cursor:pointer;">
            <option value="">All employees</option>
            <?php foreach ($employeeList as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $filters['employee'] == $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('department', this.value)" style="cursor:pointer;">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $filters['department'] == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('status', this.value)" style="cursor:pointer;">
            <option value="">All status</option>
            <option value="Present" <?php echo $filters['status'] == 'Present' ? 'selected' : ''; ?>>Present</option>
            <option value="Absent" <?php echo $filters['status'] == 'Absent' ? 'selected' : ''; ?>>Absent</option>
            <option value="Half Day" <?php echo $filters['status'] == 'Half Day' ? 'selected' : ''; ?>>Half Day</option>
            <option value="On Leave" <?php echo $filters['status'] == 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
        </select>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search..." value="<?php echo htmlspecialchars($filters['search']); ?>" 
                   oninput="applyFilterDebounced(this, 'search')">
        </div>
        <?php if (hasPermission('attendance', 'create')): ?>
            <button class="btn primary" onclick="openBulkMarkModal()">
                <i class="bi bi-plus-lg"></i> Mark Attendance
            </button>
        <?php endif; ?>
    </div>

    <div class="cal-grid">
        <div class="cal-head">Sun</div>
        <div class="cal-head">Mon</div>
        <div class="cal-head">Tue</div>
        <div class="cal-head">Wed</div>
        <div class="cal-head">Thu</div>
        <div class="cal-head">Fri</div>
        <div class="cal-head">Sat</div>
        
        <?php
        // Previous month days
        for ($i = $startDayOfWeek - 1; $i >= 0; $i--):
            $day = $prevMonthLastDay - $i;
        ?>
            <div class="cal-day other"><div class="cal-date"><?php echo $day; ?></div></div>
        <?php endfor; ?>
        
        <?php
        // Current month days
        $today = date('Y-m-d');
        for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = $currentMonth->format('Y-m') . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
            $summary = $attendanceSummary[$dateStr] ?? ['present' => 0, 'absent' => 0, 'half_day' => 0, 'on_leave' => 0];
            $isToday = $dateStr === $today;
            
            // Simplified weekend check for calendar display - actual work pattern logic is applied
            // during attendance calculation and leave validation
            $dayOfWeek = (int)date('w', strtotime($dateStr));
            $isWeekend = $dayOfWeek == 0 || $dayOfWeek == 6;
            
            // Check if there's actual attendance on weekend dates
            $hasWeekendAttendance = false;
            if ($isWeekend) {
                $weekendAttendanceCheck = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM attendance_daily ad
                     JOIN employees e ON ad.employee_id = e.id
                     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
                         SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
                     )
                     WHERE ad.attendance_date = ? AND ad.total_worked_minutes > 0 AND ad.employee_id IN ({$scopedIdsStr})",
                    [$dateStr]
                );
                $hasWeekendAttendance = ($weekendAttendanceCheck['count'] ?? 0) > 0;
            }
        ?>
            <div class="cal-day <?php echo $isToday ? 'today' : ''; ?>" onclick="showDayAttendance('<?php echo $dateStr; ?>')">
                <div class="cal-date"><?php echo $day; ?></div>
                <?php if ($isToday): ?>
                    <div class="cal-event" style="background:var(--accent-ink);color:var(--accent-dark);">Today</div>
                <?php elseif ($isWeekend && !$hasWeekendAttendance): ?>
                    <div class="cal-event" style="background:var(--warning-bg);color:#8A5A16;">Weekend</div>
                <?php elseif ($summary['present'] > 0): ?>
                    <div class="cal-event" style="background:var(--success-bg);color:#146B47;"><?php echo $summary['present']; ?> present</div>
                <?php elseif ($summary['on_leave'] > 0): ?>
                    <div class="cal-event" style="background:var(--warning-bg);color:#8A5A16;"><?php echo $summary['on_leave']; ?> on leave</div>
                <?php elseif ($summary['absent'] > 0): ?>
                    <div class="cal-event" style="background:var(--danger-bg);color:#A33A3A;"><?php echo $summary['absent']; ?> absent</div>
                <?php elseif ($summary['half_day'] > 0): ?>
                    <div class="cal-event" style="background:var(--warning-bg);color:#8A5A16;"><?php echo $summary['half_day']; ?> half day</div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
        
        <?php
        // Next month days
        $totalCells = $startDayOfWeek + $daysInMonth;
        $remainingCells = 7 - ($totalCells % 7);
        if ($remainingCells < 7):
            for ($i = 1; $i <= $remainingCells; $i++):
        ?>
            <div class="cal-day other"><div class="cal-date"><?php echo $i; ?></div></div>
        <?php 
            endfor;
        endif;
        ?>
    </div>

    <div class="cal-legend">
        <div class="legend-item"><div class="legend-dot" style="background:var(--success);"></div>Present</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--warning);"></div>Weekend / Leave</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--danger);"></div>Absent</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--accent);"></div>Today</div>
    </div>

<?php elseif ($view == 'table'): ?>
    <div class="toolbar">
        <div class="filter-chip">
            <i class="bi bi-calendar"></i>
            <input type="date" value="<?php echo $selectedDate; ?>" onchange="changeDate(this.value)" 
                   style="border:none;background:transparent;outline:none;font-size:12px;font-family:'Inter';color:var(--ink);">
        </div>
        <select class="filter-chip" onchange="applyFilter('employee', this.value)" style="cursor:pointer;">
            <option value="">All employees</option>
            <?php foreach ($employeeList as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $filters['employee'] == $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('department', this.value)" style="cursor:pointer;">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $filters['department'] == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('status', this.value)" style="cursor:pointer;">
            <option value="">All status</option>
            <option value="Present" <?php echo $filters['status'] == 'Present' ? 'selected' : ''; ?>>Present</option>
            <option value="Absent" <?php echo $filters['status'] == 'Absent' ? 'selected' : ''; ?>>Absent</option>
            <option value="Half Day" <?php echo $filters['status'] == 'Half Day' ? 'selected' : ''; ?>>Half Day</option>
            <option value="On Leave" <?php echo $filters['status'] == 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
        </select>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search..." value="<?php echo htmlspecialchars($filters['search']); ?>" 
                   oninput="applyFilterDebounced(this, 'search')">
        </div>
        <div class="spacer"></div>
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);">
            <span>IN/OUT</span>
            <div class="toggle <?php echo $tableMode == 'inout' ? 'on' : ''; ?>" onclick="toggleTableMode()" style="cursor:pointer;"></div>
            <span>SUMMARY</span>
        </div>
        <?php if ($tableMode == 'inout'): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);">
            <span>Show Breaks</span>
            <div class="toggle <?php echo isset($_GET['show_breaks']) && $_GET['show_breaks'] == '1' ? 'on' : ''; ?>" onclick="toggleBreakFilter()" style="cursor:pointer;"></div>
        </div>
        <?php endif; ?>
        <div class="filter-chip"><i class="bi bi-download"></i> Export CSV</div>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <?php if ($tableMode == 'inout'): ?>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Punch</th>
                        <th>Department</th>
                        <th>Shift</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                <?php else: ?>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>First Check-in</th>
                        <th>Last Check-out</th>
                        <th>Work Duration</th>
                        <th>Break Duration</th>
                        <th>Net Time</th>
                        <th>Idle</th>
                        <th>Status</th>
                        <th>Late</th>
                        <th>Shift</th>
                        <th>Actions</th>
                    </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if (empty($attendance)): ?>
                    <tr>
                        <td colspan="<?php echo $tableMode == 'inout' ? 7 : 12; ?>" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <p>No attendance records found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php if ($tableMode == 'inout'): ?>
                        <?php foreach ($attendance as $att): ?>
                            <tr>
                                <td class="mono"><?php echo date('d-m-Y', strtotime($att['attendance_date'])); ?></td>
                                <td>
                                    <div class="emp-cell">
                                        <div class="avatar"><?php echo getInitials($att['first_name'] . ' ' . $att['last_name']); ?></div>
                                        <div>
                                            <div class="emp-name"><?php echo htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?></div>
                                            <div class="emp-id mono"><?php echo htmlspecialchars($att['employee_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    // Handle all event types with professional terminology
                                    switch ($att['event_type']) {
                                        case 'CLOCK_IN':
                                            $punchType = 'IN';
                                            $badgeColor = '#1F9E6D';
                                            $badgeBg = '#E7F5EE';
                                            break;
                                        case 'CLOCK_OUT':
                                            $punchType = 'OUT';
                                            $badgeColor = '#D65C5C';
                                            $badgeBg = '#FBEAEA';
                                            break;
                                        case 'BREAK_START':
                                            $punchType = 'Break Start';
                                            $badgeColor = '#F59E0B';
                                            $badgeBg = '#FEF3C7';
                                            break;
                                        case 'BREAK_END':
                                            $punchType = 'Break End';
                                            $badgeColor = '#10B981';
                                            $badgeBg = '#D1FAE5';
                                            break;
                                        default:
                                            $punchType = $att['event_type'];
                                            $badgeColor = '#6B7280';
                                            $badgeBg = '#F3F4F6';
                                    }
                                    
                                    // Add break type name for break punches
                                    if (in_array($att['event_type'], ['BREAK_START', 'BREAK_END']) && !empty($att['break_type_name'])) {
                                        $punchType .= ' (' . htmlspecialchars($att['break_type_name']) . ')';
                                    }
                                    
                                    $punchTime = utcToLocal($att['timestamp_utc'], 'H:i:s');
                                    $punchDate = utcToLocal($att['timestamp_utc'], 'd M Y');
                                    ?>
                                    <span class="badge" style="background: <?php echo $badgeBg; ?>; color: <?php echo $badgeColor; ?>; flex-direction:column; align-items:flex-start; gap:0; line-height:1.35;">
                                        <span><?php echo $punchType . ' ' . $punchTime; ?></span>
                                        <span style="font-size:9px; font-weight:400; opacity:0.75;"><?php echo $punchDate; ?></span>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($att['department_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($att['shift_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($att['remarks'] ?? '-'); ?></td>
                                <td>
                                    <div class="actions">
                                        <div class="icon-btn" onclick="viewPunchDetails(<?php echo $att['id']; ?>)" title="View">
                                            <i class="bi bi-eye"></i>
                                        </div>
                                        <?php if (hasPermission('attendance', 'delete')): ?>
                                        <div class="icon-btn" style="color:var(--danger);" onclick="deletePunch(<?php echo $att['id']; ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($attendance as $att): ?>
                            <tr>
                                <td>
                                    <div class="emp-cell">
                                        <div class="avatar"><?php echo getInitials($att['first_name'] . ' ' . $att['last_name']); ?></div>
                                        <div>
                                            <div class="emp-name"><?php echo htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?></div>
                                            <div class="emp-id mono"><?php echo htmlspecialchars($att['employee_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($att['department_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($att['first_in']): ?>
                                        <div class="mono"><?php echo utcToLocal($att['first_in'], 'H:i'); ?></div>
                                        <div style="font-size:9px;color:var(--faint);margin-top:1px;"><?php echo utcToLocal($att['first_in'], 'd M Y'); ?></div>
                                    <?php else: ?>
                                        <span class="mono">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($att['last_out']): ?>
                                        <div class="mono"><?php echo utcToLocal($att['last_out'], 'H:i'); ?></div>
                                        <div style="font-size:9px;color:var(--faint);margin-top:1px;"><?php echo utcToLocal($att['last_out'], 'd M Y'); ?></div>
                                        <?php if (in_array($att['last_out_source'] ?? '', ['desktop_shutdown', 'shutdown_sweep'])): ?>
                                            <div style="font-size:9px;color:var(--warning);margin-top:1px;">Shutdown</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="mono">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?php echo formatMinutes($att['total_worked_minutes']); ?></td>
                                <td class="mono"><?php echo formatMinutes($att['total_break_minutes'] ?? 0); ?></td>
                                <td class="mono"><?php echo formatMinutes($att['net_worked_minutes'] ?? $att['total_worked_minutes']); ?></td>
                                <td class="mono"><?php echo formatMinutes($att['idle_minutes'] ?? 0); ?></td>
                                <td>
                                    <?php
                                    $statusClass = 'gray';
                                    if ($att['status'] == 'Present') $statusClass = 'success';
                                    elseif ($att['status'] == 'Absent') $statusClass = 'danger';
                                    elseif ($att['status'] == 'Half Day') $statusClass = 'pending';
                                    elseif ($att['status'] == 'On Leave') $statusClass = 'info';
                                    elseif ($att['status'] == 'Late') $statusClass = 'warning';
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($att['status']); ?></span>
                                </td>
                                <td class="mono"><?php echo isset($att['late_minutes']) && $att['late_minutes'] > 0 ? formatMinutes($att['late_minutes']) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($att['shift_name'] ?? '-'); ?></td>
                                <td>
                                    <div class="actions">
                                        <div class="icon-btn" onclick="viewAttendanceDetails(<?php echo $att['id']; ?>)" title="View">
                                            <i class="bi bi-eye"></i>
                                        </div>
                                        <?php if (hasPermission('attendance', 'delete')): ?>
                                        <div class="icon-btn" style="color:var(--danger);" onclick="deleteAttendance(<?php echo $att['id']; ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <?php
    // Get regularization requests with filters
    $regStatusFilter = $_GET['reg_status'] ?? '';
    $regEmployeeFilter = $_GET['reg_employee'] ?? '';
    $regDepartmentFilter = $_GET['reg_department'] ?? '';
    $regSearchFilter = $_GET['reg_search'] ?? '';
    
    $regWhereConditions = ['1=1'];
    $regParams = [];
    
    if ($regStatusFilter) {
        $regWhereConditions[] = 'rr.status = ?';
        $regParams[] = $regStatusFilter;
    }
    
    if ($regEmployeeFilter) {
        $regWhereConditions[] = 'rr.employee_id = ?';
        $regParams[] = $regEmployeeFilter;
    }
    
    if ($regDepartmentFilter) {
        $regWhereConditions[] = 'ed.department_id = ?';
        $regParams[] = $regDepartmentFilter;
    }
    
    if ($regSearchFilter) {
        $regWhereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
        $searchTerm = '%' . $regSearchFilter . '%';
        $regParams[] = $searchTerm;
        $regParams[] = $searchTerm;
        $regParams[] = $searchTerm;
    }
    
    $regWhereClause = implode(' AND ', $regWhereConditions);
    
    $regularizationRequests = $db->fetchAll(
        "SELECT rr.*, e.first_name, e.last_name, e.employee_id, d.name as department_name
         FROM regularization_requests rr
         JOIN employees e ON rr.employee_id = e.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 AND ed.id = (
             SELECT id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1
         )
         LEFT JOIN departments d ON ed.department_id = d.id
         WHERE {$regWhereClause}
         ORDER BY rr.created_at DESC",
        $regParams
    );
    ?>
    
    <div class="toolbar">
        <div class="filter-chip <?php echo $regStatusFilter == '' ? 'active' : ''; ?>" onclick="filterRegularization('reg_status', '')">All</div>
        <div class="filter-chip <?php echo $regStatusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterRegularization('reg_status', 'Pending')">Pending</div>
        <div class="filter-chip <?php echo $regStatusFilter == 'Approved' ? 'active' : ''; ?>" onclick="filterRegularization('reg_status', 'Approved')">Approved</div>
        <div class="filter-chip <?php echo $regStatusFilter == 'Rejected' ? 'active' : ''; ?>" onclick="filterRegularization('reg_status', 'Rejected')">Disapproved</div>
        <div class="spacer"></div>
        <select class="filter-chip" onchange="filterRegularization('reg_employee', this.value)" style="cursor:pointer;">
            <option value="">All employees</option>
            <?php foreach ($employeeList as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $regEmployeeFilter == $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="filterRegularization('reg_department', this.value)" style="cursor:pointer;">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $regDepartmentFilter == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search..." value="<?php echo htmlspecialchars($regSearchFilter); ?>" 
                   oninput="filterRegularizationDebounced(this, 'reg_search')">
        </div>
        <div class="spacer"></div>
        <button class="btn primary" onclick="openRegularizationModal()">
            <i class="bi bi-plus-lg"></i> New Request
        </button>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Reason</th>
                    <th>Current Status</th>
                    <th>Requested Status</th>
                    <th>Notes</th>
                    <th>Request Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($regularizationRequests)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-pencil-square"></i>
                                <p>No regularization requests found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($regularizationRequests as $req): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($req['first_name'] . ' ' . $req['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></div>
                                        <div class="emp-id mono"><?php echo htmlspecialchars($req['employee_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mono"><?php echo formatDate($req['attendance_date']); ?></td>
                            <td><?php echo htmlspecialchars($req['reason']); ?></td>
                            <td>
                                <?php
                                // Get current attendance status
                                $currentStatus = $db->fetchOne(
                                    "SELECT status FROM attendance_daily WHERE employee_id = ? AND attendance_date = ?",
                                    [$req['employee_id'], $req['attendance_date']]
                                );
                                $currentStatusClass = 'gray';
                                if ($currentStatus && $currentStatus['status'] == 'Present') $currentStatusClass = 'success';
                                elseif ($currentStatus && $currentStatus['status'] == 'Absent') $currentStatusClass = 'rejected';
                                elseif ($currentStatus && $currentStatus['status'] == 'Half Day') $currentStatusClass = 'pending';
                                ?>
                                <span class="badge <?php echo $currentStatusClass; ?>">
                                    <?php echo htmlspecialchars($currentStatus['status'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <td><span class="badge success">Present</span></td>
                            <td><?php echo htmlspecialchars($req['remarks'] ?? '-'); ?></td>
                            <td>
                                <?php
                                $reqStatusClass = 'gray';
                                if ($req['status'] == 'Pending') $reqStatusClass = 'pending';
                                elseif ($req['status'] == 'Approved') $reqStatusClass = 'approved';
                                elseif ($req['status'] == 'Rejected') $reqStatusClass = 'rejected';
                                ?>
                                <span class="badge <?php echo $reqStatusClass; ?>"><?php echo htmlspecialchars($req['status']); ?></span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($req['status'] == 'Pending' && hasPermission('attendance', 'approve')): ?>
                                        <button class="btn sm success" onclick="approveRegularization(<?php echo $req['id']; ?>)">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                        <button class="btn sm danger" onclick="rejectRegularization(<?php echo $req['id']; ?>)">
                                            <i class="bi bi-x-lg"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <div class="icon-btn" onclick="viewRegularization(<?php echo $req['id']; ?>)" title="View">
                                            <i class="bi bi-eye"></i>
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
<?php endif; ?>

<!-- Bulk Mark Attendance Modal -->
<div id="bulkMarkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Mark Attendance</h3>
            <button onclick="closeBulkMarkModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Employee <span class="req">*</span></label>
                <select id="bulkMarkEmployee">
                    <?php foreach ($employeeList as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Clock In Date <span class="req">*</span></label>
                <input type="date" id="bulkMarkClockInDate" value="<?php echo $selectedDate; ?>" onchange="syncBulkMarkClockOutDate()">
            </div>
            <div class="field">
                <label>Clock In Time <span class="req">*</span></label>
                <div style="display:flex;gap:8px;">
                    <input type="number" id="bulkMarkClockInHour" min="1" max="12" placeholder="HH" value="9" style="width:60px;" onchange="syncBulkMarkClockOutDate()" oninput="syncBulkMarkClockOutDate()">
                    <input type="number" id="bulkMarkClockInMinute" min="0" max="59" placeholder="MM" value="0" style="width:60px;" onchange="syncBulkMarkClockOutDate()" oninput="syncBulkMarkClockOutDate()">
                    <select id="bulkMarkClockInAmPm" style="width:80px;" onchange="syncBulkMarkClockOutDate()">
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Clock Out Date <span class="req">*</span></label>
                <input type="date" id="bulkMarkClockOutDate" value="<?php echo $selectedDate; ?>">
            </div>
            <div class="field">
                <label>Clock Out Time <span class="req">*</span></label>
                <div style="display:flex;gap:8px;">
                    <input type="number" id="bulkMarkClockOutHour" min="1" max="12" placeholder="HH" value="6" style="width:60px;" onchange="syncBulkMarkClockOutDate()" oninput="syncBulkMarkClockOutDate()">
                    <input type="number" id="bulkMarkClockOutMinute" min="0" max="59" placeholder="MM" value="0" style="width:60px;" onchange="syncBulkMarkClockOutDate()" oninput="syncBulkMarkClockOutDate()">
                    <select id="bulkMarkClockOutAmPm" style="width:80px;" onchange="syncBulkMarkClockOutDate()">
                        <option value="AM">AM</option>
                        <option value="PM" selected>PM</option>
                    </select>
                </div>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Remarks</label>
                <textarea id="bulkMarkRemarks" rows="2" placeholder="Optional remarks"></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeBulkMarkModal()">Cancel</button>
            <button class="btn primary" onclick="saveBulkMark()">Save Attendance</button>
        </div>
    </div>
</div>

<!-- Attendance Detail / Edit Modal -->
<div id="attendanceDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="attDetailTitle">Attendance Details</h3>
            <button onclick="closeAttendanceDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="attDetailRecordId">
        <div id="attDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
    </div>
</div>

<!-- Punch Detail Modal -->
<div id="punchDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:520px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Punch Details</h3>
            <button onclick="closePunchDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="punchDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closePunchDetailModal()">Close</button>
            <button class="btn sm danger" onclick="deletePunch()">Delete</button>
        </div>
    </div>
</div>

<!-- Regularization Request Modal -->
<div id="regularizationModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:580px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Request Regularization</h3>
            <button onclick="closeRegularizationModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Employee</label>
                <select id="regEmployee">
                    <option value="">Select employee</option>
                    <?php foreach ($employeeList as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Attendance Date <span class="req">*</span></label>
                <input type="date" id="regDate" value="<?php echo $selectedDate; ?>">
            </div>
            <div class="field">
                <label>Requested Clock In</label>
                <input type="datetime-local" id="regIn">
            </div>
            <div class="field">
                <label>Requested Clock Out</label>
                <input type="datetime-local" id="regOut">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Reason <span class="req">*</span></label>
                <textarea id="regReason" rows="2" placeholder="Why are you requesting this change?"></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeRegularizationModal()">Cancel</button>
            <button class="btn primary" onclick="saveRegularization()">Submit Request</button>
        </div>
    </div>
</div>

<!-- Regularization Detail Modal -->
<div id="regDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Regularization Details</h3>
            <button onclick="closeRegDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="regDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button class="btn" onclick="closeRegDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Restore scroll position on page load
window.addEventListener('load', function() {
    const scrollPos = sessionStorage.getItem('attendanceScrollPos');
    if (scrollPos) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('attendanceScrollPos');
    }
});

function switchView(view) {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    url.searchParams.set('view', view);
    window.location = url.toString();
}

function changeMonth(delta) {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    const currentMonth = url.searchParams.get('month') || new Date().toISOString().slice(0, 7);
    const date = new Date(currentMonth + '-01');
    date.setMonth(date.getMonth() + delta);
    url.searchParams.set('month', date.toISOString().slice(0, 7));
    window.location = url.toString();
}

function changeDate(date) {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    url.searchParams.set('date', date);
    window.location = url.toString();
}

function toggleTableMode() {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    const currentMode = url.searchParams.get('table_mode') || 'summary';
    url.searchParams.set('table_mode', currentMode === 'summary' ? 'inout' : 'summary');
    window.location = url.toString();
}

function toggleBreakFilter() {
    const url = new URL(window.location);
    const currentShowBreaks = url.searchParams.get('show_breaks') || '0';
    const newShowBreaks = currentShowBreaks === '1' ? '0' : '1';
    url.searchParams.set('show_breaks', newShowBreaks);
    window.location.href = url.toString();
}

function openBulkMarkModal() {
    const today = new Date().toISOString().slice(0, 10);
    document.getElementById('bulkMarkClockInDate').value = document.getElementById('bulkMarkClockInDate').value || today;
    document.getElementById('bulkMarkClockOutDate').value = document.getElementById('bulkMarkClockOutDate').value || today;
    // Set default clock in/out times
    document.getElementById('bulkMarkClockInHour').value = '9';
    document.getElementById('bulkMarkClockInMinute').value = '0';
    document.getElementById('bulkMarkClockInAmPm').value = 'AM';
    document.getElementById('bulkMarkClockOutHour').value = '6';
    document.getElementById('bulkMarkClockOutMinute').value = '0';
    document.getElementById('bulkMarkClockOutAmPm').value = 'PM';
    // Auto-advance clock-out date when clock-out time is earlier than clock-in (night shift)
    syncBulkMarkClockOutDate();
    document.getElementById('bulkMarkModal').style.display = 'flex';
}

function closeBulkMarkModal() {
    document.getElementById('bulkMarkModal').style.display = 'none';
    // Clear time fields
    document.getElementById('bulkMarkClockInHour').value = '';
    document.getElementById('bulkMarkClockInMinute').value = '';
    document.getElementById('bulkMarkClockOutHour').value = '';
    document.getElementById('bulkMarkClockOutMinute').value = '';
    document.getElementById('bulkMarkRemarks').value = '';
}

function saveBulkMark() {
    const employeeId = document.getElementById('bulkMarkEmployee').value;
    const clockInDate = document.getElementById('bulkMarkClockInDate').value;
    const clockOutDate = document.getElementById('bulkMarkClockOutDate').value;
    
    if (!employeeId || !clockInDate || !clockOutDate) {
        alert('Please select an employee, clock in date and clock out date.');
        return;
    }
    
    if (clockOutDate < clockInDate) {
        alert('Clock out date cannot be before clock in date.');
        return;
    }
    
    // Get clock in time
    const clockInHour = document.getElementById('bulkMarkClockInHour').value;
    const clockInMinute = document.getElementById('bulkMarkClockInMinute').value;
    const clockInAmPm = document.getElementById('bulkMarkClockInAmPm').value;
    
    // Get clock out time
    const clockOutHour = document.getElementById('bulkMarkClockOutHour').value;
    const clockOutMinute = document.getElementById('bulkMarkClockOutMinute').value;
    const clockOutAmPm = document.getElementById('bulkMarkClockOutAmPm').value;
    
    if (!clockInHour || !clockInMinute || !clockOutHour || !clockOutMinute) {
        alert('Please provide both clock in and clock out times.');
        return;
    }
    
    // Convert to 24-hour format
    const clockInTime24 = convertTo24Hour(clockInHour, clockInMinute, clockInAmPm);
    const clockOutTime24 = convertTo24Hour(clockOutHour, clockOutMinute, clockOutAmPm);
    
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'bulk_mark',
            employee_id: employeeId,
            clock_in_date: clockInDate,
            clock_out_date: clockOutDate,
            clock_in: clockInTime24,
            clock_out: clockOutTime24,
            remarks: document.getElementById('bulkMarkRemarks').value
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeBulkMarkModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not mark attendance'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error marking attendance');
    });
}

function convertTo24Hour(hour, minute, amPm) {
    let h = parseInt(hour);
    const m = parseInt(minute).toString().padStart(2, '0');
    
    if (amPm === 'PM' && h !== 12) {
        h += 12;
    } else if (amPm === 'AM' && h === 12) {
        h = 0;
    }
    
    return h.toString().padStart(2, '0') + ':' + m;
}

function syncBulkMarkClockOutDate() {
    const inHour = document.getElementById('bulkMarkClockInHour').value;
    const inMinute = document.getElementById('bulkMarkClockInMinute').value;
    const inAmPm = document.getElementById('bulkMarkClockInAmPm').value;
    const outHour = document.getElementById('bulkMarkClockOutHour').value;
    const outMinute = document.getElementById('bulkMarkClockOutMinute').value;
    const outAmPm = document.getElementById('bulkMarkClockOutAmPm').value;
    
    if (!inHour || !outHour) return;
    
    const inTime24 = convertTo24Hour(inHour, inMinute || 0, inAmPm);
    const outTime24 = convertTo24Hour(outHour, outMinute || 0, outAmPm);
    
    const inDateEl = document.getElementById('bulkMarkClockInDate');
    const outDateEl = document.getElementById('bulkMarkClockOutDate');
    if (!inDateEl.value) return;
    
    // If clock-out is earlier than clock-in, the shift crosses midnight →
    // auto-advance the clock-out date to the day after clock-in (night shift support)
    if (outTime24 < inTime24) {
        const d = new Date(inDateEl.value + 'T00:00:00');
        d.setDate(d.getDate() + 1);
        outDateEl.value = d.toISOString().slice(0, 10);
    } else {
        outDateEl.value = inDateEl.value;
    }
}

function viewAttendanceDetails(id) {
    fetch(`api/attendance.php?record_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Could not load record'));
                return;
            }
            const rec = data.record;
            document.getElementById('attDetailRecordId').value = id;
            document.getElementById('attDetailTitle').textContent = 'Attendance — ' + (rec.first_name || '') + ' ' + (rec.last_name || '');
            const statusClass = rec.status === 'Present' ? 'success' : (rec.status === 'Absent' ? 'danger' : (rec.status === 'Half Day' ? 'pending' : 'info'));
            const punches = (rec.punches && rec.punches.length)
                ? rec.punches.map(p => `<div style="padding:4px 0;border-bottom:1px solid var(--border);">
                        <b>${p.event_type.replace(/_/g, ' ')}</b> · <span class="mono">${formatTime(p.timestamp_utc)}</span> · ${p.source || 'web'}${p.is_review_flagged ? ' · <span style="color:var(--warning);">flagged</span>' : ''}
                        <div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(p.timestamp_utc)}</div>
                    </div>`).join('')
                : '<div style="color:var(--faint);">No punches.</div>';
            document.getElementById('attDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Employee:</b> ${rec.first_name || ''} ${rec.last_name || ''} <span class="mono" style="color:var(--faint);">(${rec.employee_id || ''})</span></span>
                        <span><b>Date:</b> ${rec.attendance_date}</span>
                        <span><b>Status:</b> <span class="badge ${statusClass}">${rec.status}</span></span>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:16px;flex-wrap:wrap;">
                        <div>
                            <b>First In:</b> ${rec.first_in ? formatTime(rec.first_in) : '—'}
                            ${rec.first_in ? `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(rec.first_in)}</div>` : ''}
                        </div>
                        <div>
                            <b>Last Out:</b> ${rec.last_out ? formatTime(rec.last_out) : '—'}
                            ${rec.last_out ? `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(rec.last_out)}</div>` : ''}
                            ${rec.last_out_source && ['desktop_shutdown','shutdown_sweep'].indexOf(rec.last_out_source) !== -1 ? `<div style="font-size:10px;color:var(--warning);margin-top:1px;">Shutdown</div>` : ''}
                        </div>
                        <div><b>Worked:</b> ${rec.total_worked_minutes ? Math.floor(rec.total_worked_minutes / 60) + 'h ' + rec.total_worked_minutes % 60 + 'm' : '—'}</div>
                        <div><b>Break:</b> ${rec.total_break_minutes ? Math.floor(rec.total_break_minutes / 60) + 'h ' + rec.total_break_minutes % 60 + 'm' : '—'}</div>
                    </div>
                    <div><b>Shift:</b> ${rec.shift_name || '—'} &nbsp;·&nbsp; <b>Department:</b> ${rec.department_name || '—'}</div>
                    ${rec.remarks ? '<div><b>Remarks:</b> ' + rec.remarks + '</div>' : ''}
                </div>
                <h4 style="font-size:13px;font-weight:600;margin:12px 0 8px;color:var(--ink);">Punches</h4>
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:12px;">${punches}</div>
            `;
            document.getElementById('attendanceDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading attendance details');
        });
}

function closeAttendanceDetailModal() {
    document.getElementById('attendanceDetailModal').style.display = 'none';
}

function deleteAttendance(id) {
    const recordId = id || document.getElementById('attDetailRecordId').value;
    if (!ModalSystem.confirm({ 
        message: 'Delete this attendance record?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_record', record_id: recordId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not delete'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error deleting attendance');
    });
}

function viewPunchDetails(id) {
    fetch(`api/attendance.php?punch_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                ModalSystem.error('Error: ' + (data.message || 'Could not load punch'));
                return;
            }
            const p = data.punch;
            document.getElementById('punchDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Employee:</b> ${p.first_name || ''} ${p.last_name || ''} <span class="mono" style="color:var(--faint);">(${p.employee_id || ''})</span></span>
                        <span><b>Date:</b> ${p.attendance_date}</span>
                    </div>
                    <div style="margin-top:8px;"><b>Event:</b> ${p.event_type.replace(/_/g, ' ')}</div>
                    <div><b>Time:</b> <span class="mono">${formatTime(p.timestamp_utc)}</span>
                        <div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(p.timestamp_utc)}</div>
                    </div>
                    <div><b>Source:</b> ${p.source || 'web'}</div>
                    <div><b>Shift:</b> ${p.shift_name || '—'}</div>
                    <div><b>Flagged for review:</b> ${p.is_review_flagged ? 'Yes' : 'No'}</div>
                </div>
            `;
            document.getElementById('punchDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading punch details');
        });
}

function closePunchDetailModal() {
    document.getElementById('punchDetailModal').style.display = 'none';
}

function deletePunch(id) {
    if (!ModalSystem.confirm({ 
        message: 'Delete this punch record? This will recompute the daily attendance.',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_punch', punch_id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not delete punch'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error deleting punch');
    });
}

function filterRegularization(filterType, value) {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(filterType, value);
    } else {
        url.searchParams.delete(filterType);
    }
    HrmsSearch.navigate(url.toString());
}

function filterRegularizationDebounced(input, filterType) {
    // Live-value debounce: reads the input's value when the timer fires,
    // so fast or slow typing both end up as one clean filter navigation.
    clearTimeout(input._hrmsSearchTimer);
    input._hrmsSearchTimer = setTimeout(() => filterRegularization(filterType, input.value), 450);
}

function applyFilter(filterType, value) {
    sessionStorage.setItem('attendanceScrollPos', window.pageYOffset || document.documentElement.scrollTop);
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(filterType, value);
    } else {
        url.searchParams.delete(filterType);
    }
    HrmsSearch.navigate(url.toString());
}

function applyFilterDebounced(input, filterType) {
    // Live-value debounce: reads the input's value when the timer fires,
    // so fast or slow typing both end up as one clean filter navigation.
    clearTimeout(input._hrmsSearchTimer);
    input._hrmsSearchTimer = setTimeout(() => applyFilter(filterType, input.value), 450);
}

function openRegularizationModal() {
    document.getElementById('regDate').value = document.getElementById('regDate').value || new Date().toISOString().slice(0, 10);
    document.getElementById('regIn').value = '';
    document.getElementById('regOut').value = '';
    document.getElementById('regReason').value = '';
    document.getElementById('regularizationModal').style.display = 'flex';
}

function closeRegularizationModal() {
    document.getElementById('regularizationModal').style.display = 'none';
}

function saveRegularization() {
    const date = document.getElementById('regDate').value;
    const reason = document.getElementById('regReason').value.trim();
    if (!date || !reason) {
        alert('Please select a date and provide a reason.');
        return;
    }
    const employeeId = document.getElementById('regEmployee').value;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'create_regularization',
            employee_id: employeeId,
            attendance_date: date,
            requested_clock_in: document.getElementById('regIn').value.replace('T', ' '),
            requested_clock_out: document.getElementById('regOut').value.replace('T', ' '),
            reason: reason
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeRegularizationModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not submit request'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error submitting regularization request');
    });
}

function showDayAttendance(dateStr) {
    // Get current filter parameters
    const url = new URL(window.location);
    const employeeFilter = url.searchParams.get('employee') || '';
    const departmentFilter = url.searchParams.get('department') || '';
    const statusFilter = url.searchParams.get('status') || '';
    const searchFilter = url.searchParams.get('search') || '';
    
    // Build query parameters
    let apiUrl = `api/attendance.php?date=${dateStr}`;
    if (employeeFilter) apiUrl += `&employee=${employeeFilter}`;
    if (departmentFilter) apiUrl += `&department=${departmentFilter}`;
    if (statusFilter) apiUrl += `&status=${statusFilter}`;
    if (searchFilter) apiUrl += `&search=${searchFilter}`;
    
    // Fetch attendance data for the selected date
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAttendanceModal(dateStr, data.employees);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error fetching attendance data');
        });
}

function displayAttendanceModal(dateStr, employees) {
    // Create modal HTML
    let modalHtml = `
        <div id="attendanceModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
            <div style="background:var(--surface);border-radius:12px;padding:24px;max-width:1200px;width:98%;max-height:90vh;display:flex;flex-direction:column;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-shrink:0;">
                    <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;">Attendance - ${dateStr}</h3>
                    <button onclick="closeAttendanceModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted);">&times;</button>
                </div>
                <div style="overflow:auto;flex:1;min-height:0;">
                    <div class="data-table" style="width:100%;">
                        <table style="width:100%;">
                            <thead style="position:sticky;top:0;background:#FBFAF7;z-index:10;">
                                <tr>
                                    <th>Employee</th>
                                    <th>Status</th>
                                    <th>Department</th>
                                    <th>Shift</th>
                                    <th>First Check-in</th>
                                    <th>Last Check-out</th>
                                    <th>Work Duration</th>
                                    <th>Break Duration</th>
                                    <th>Net Time</th>
                                </tr>
                            </thead>
                            <tbody>
    `;
    
    if (employees.length === 0) {
        modalHtml += `
                                <tr>
                                    <td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">No attendance records found</td>
                                </tr>
        `;
    } else {
        employees.forEach(emp => {
            let status = emp.status || '-';
            let statusClass = 'info';

            // Debug: log the employee data
            console.log('Employee data:', emp);

            if (emp.is_on_break === true || emp.is_on_break === '1' || emp.is_on_break === 1) {
                status = 'On Break';
                statusClass = 'warning'; // Orange badge for On Break
            } else if (emp.is_currently_in === true || emp.is_currently_in === '1' || emp.is_currently_in === 1) {
                status = 'Currently In';
                statusClass = 'info'; // Blue badge for Currently In
            } else if (emp.status === 'Present') {
                statusClass = 'success';
            } else if (emp.status === 'Absent') {
                statusClass = 'danger';
            } else if (emp.status === 'Half Day') {
                statusClass = 'pending';
            }

            const workDuration = emp.total_worked_minutes ?
                `${Math.floor(emp.total_worked_minutes / 60)}h ${emp.total_worked_minutes % 60}m` : '-';

            const breakDuration = emp.total_break_minutes ?
                `${Math.floor(emp.total_break_minutes / 60)}h ${emp.total_break_minutes % 60}m` : '-';

            // Calculate Net Time = Work Duration - Break Duration
            const netWorkedMinutes = emp.net_worked_minutes ?
                emp.net_worked_minutes :
                (emp.total_worked_minutes || 0) - (emp.total_break_minutes || 0);
            const netTime = netWorkedMinutes > 0 ?
                `${Math.floor(netWorkedMinutes / 60)}h ${netWorkedMinutes % 60}m` : '-';

            modalHtml += `
                                <tr>
                                    <td>
                                        <div class="emp-cell">
                                            <div class="avatar">${getInitials(emp.first_name + ' ' + emp.last_name)}</div>
                                            <div>
                                                <div class="emp-name">${emp.first_name} ${emp.last_name}</div>
                                                <div class="emp-id mono">${emp.employee_id}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge ${statusClass}">${status}</span></td>
                                    <td>${emp.department_name || '-'}</td>
                                    <td>${emp.shift_name || '-'}</td>
                                    <td class="mono">${emp.first_in ? formatTime(emp.first_in) + `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(emp.first_in)}</div>` : '-'}</td>
                                    <td class="mono">${emp.last_out ? formatTime(emp.last_out) + `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(emp.last_out)}</div>` + (['desktop_shutdown','shutdown_sweep'].indexOf(emp.last_out_source) !== -1 ? `<div style="font-size:10px;color:var(--warning);margin-top:1px;">Shutdown</div>` : '') : '-'}</td>
                                    <td class="mono">${workDuration}</td>
                                    <td class="mono">${breakDuration}</td>
                                    <td class="mono" style="font-weight:600;color:var(--accent-dark);">${netTime}</td>
                                </tr>
            `;
        });
    }
    
    modalHtml += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeAttendanceModal() {
    const modal = document.getElementById('attendanceModal');
    if (modal) {
        modal.remove();
    }
}

function getInitials(name) {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
}

function formatTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
}

function formatDateShort(datetime) {
    const date = new Date(datetime);
    return date.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
}

function approveRegularization(id) {
    if (!ModalSystem.confirm({ 
        message: 'Approve this regularization request and update the attendance record?',
        confirmText: 'Approve' 
    })) return;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'approve_regularization', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not approve'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error approving regularization');
    });
}

function rejectRegularization(id) {
    ModalSystem.prompt({
        title: 'Reject Regularization',
        message: 'Enter rejection reason:',
        placeholder: 'Reason for rejection',
        required: true
    }).then(reason => {
        if (reason === null) return;
        
        fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject_regularization', id: id, reason: reason.trim() })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not reject'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error rejecting regularization');
        });
    });
}

function viewRegularization(id) {
    fetch(`api/attendance.php?reg_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Could not load request'));
                return;
            }
            const req = data.request;
            const statusClass = req.status === 'Approved' ? 'success' : (req.status === 'Rejected' ? 'danger' : 'pending');
            document.getElementById('regDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Employee:</b> ${req.first_name || ''} ${req.last_name || ''} <span class="mono" style="color:var(--faint);">(${req.employee_id || ''})</span></span>
                        <span><b>Status:</b> <span class="badge ${statusClass}">${req.status}</span></span>
                    </div>
                    <div style="margin-top:8px;"><b>Attendance Date:</b> ${req.attendance_date}</div>
                    <div>
                        <b>Requested In:</b> ${req.requested_clock_in ? formatTime(req.requested_clock_in) : '—'}
                        ${req.requested_clock_in ? `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(req.requested_clock_in)}</div>` : ''}
                    </div>
                    <div>
                        <b>Requested Out:</b> ${req.requested_clock_out ? formatTime(req.requested_clock_out) : '—'}
                        ${req.requested_clock_out ? `<div style="font-size:10px;color:var(--faint);margin-top:1px;">${formatDateShort(req.requested_clock_out)}</div>` : ''}
                    </div>
                    <div style="margin-top:8px;"><b>Reason:</b> ${req.reason}</div>
                    ${req.rejection_reason ? '<div style="margin-top:8px;color:var(--danger);"><b>Rejection Reason:</b> ' + req.rejection_reason + '</div>' : ''}
                    ${req.approver_first ? '<div style="margin-top:8px;"><b>Decision By:</b> ' + req.approver_first + ' ' + req.approver_last + (req.approved_at ? ' on ' + new Date(req.approved_at).toLocaleString() : '') + '</div>' : ''}
                </div>
                <p style="font-size:12px;color:var(--faint);">Requested on ${new Date(req.created_at).toLocaleString()}</p>
            `;
            document.getElementById('regDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading regularization details');
        });
}

function closeRegDetailModal() {
    document.getElementById('regDetailModal').style.display = 'none';
}
</script>


