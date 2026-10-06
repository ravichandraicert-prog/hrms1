<?php
/**
 * Dashboard Module
 * Core PHP HRMS Application
 * Section 5 - Dashboard module
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

$db = Database::getInstance();
$user = getCurrentUser();

// Get dashboard stats based on permissions
$headcount = 0;
$presentToday = 0;
$onLeave = 0;
$openRequests = 0;
$loggedUsers = [];
$pendingRequests = [];

if (hasPermission('employees', 'read')) {
    $scope = getDataScope('employees');
    $permissions = new Permissions();
    $scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'employees', 'read');
    $employeeIds = implode(',', $scopedIds) ?: '0';
    
    // Headcount
    $headcountResult = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE is_active = 1 AND id IN ({$employeeIds})");
    $headcount = $headcountResult['count'] ?? 0;
    
    // Present today = employees CURRENTLY clocked in (their latest CLOCK_IN has
    // no matching CLOCK_OUT after it). attendance_daily.status is threshold-based
    // ('Present' only after the full-day work threshold, 'Half Day'/'Absent'
    // before that), so it always reads 0 early in a shift. The live punch log is
    // the source of truth for "currently in"; same logic as Attendance::getClockedInEmployees(),
    // scoped to the viewer's visibility. Open sessions from a previous attendance
    // date (e.g. a night shift) still count as currently in. Employees on an open
    // break are still clocked in, so they are counted too.
    $today = date('Y-m-d');
    $presentResult = $db->fetchOne(
        "SELECT COUNT(DISTINCT ap.employee_id) as count
         FROM attendance_punches ap
         JOIN employees e ON e.id = ap.employee_id AND e.is_active = 1
         WHERE ap.event_type = 'CLOCK_IN'
         AND ap.employee_id IN ({$employeeIds})
         AND ap.timestamp_utc = (
             SELECT MAX(ap3.timestamp_utc) FROM attendance_punches ap3
             WHERE ap3.employee_id = ap.employee_id AND ap3.event_type = 'CLOCK_IN'
         )
         AND NOT EXISTS (
             SELECT 1 FROM attendance_punches ap_out
             WHERE ap_out.employee_id = ap.employee_id
             AND ap_out.event_type = 'CLOCK_OUT'
             AND ap_out.timestamp_utc >= ap.timestamp_utc
         )",
        []
    );
    $presentToday = $presentResult['count'] ?? 0;
    
    // On leave (exclude cancelled leaves)
    $leaveResult = $db->fetchOne(
        "SELECT COUNT(*) as count FROM leave_applications WHERE status = 'Approved' 
         AND ? BETWEEN start_date AND end_date AND employee_id IN ({$employeeIds})",
        [$today]
    );
    $onLeave = $leaveResult['count'] ?? 0;
    
    // Open requests
    $openResult = $db->fetchOne(
        "SELECT COUNT(*) as count FROM leave_applications WHERE status = 'Pending' AND employee_id IN ({$employeeIds})",
        []
    );
    $openRequests = $openResult['count'] ?? 0;

    // Get all employees with attendance activity today
    $allAttendanceData = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id,
         ap_in.timestamp_utc as check_in,
         ap_out.timestamp_utc as check_out
         FROM employees e
         LEFT JOIN attendance_punches ap_in ON e.id = ap_in.employee_id 
           AND ap_in.event_type = 'CLOCK_IN' 
           AND ap_in.attendance_date = ?
         LEFT JOIN attendance_punches ap_out ON e.id = ap_out.employee_id 
           AND ap_out.event_type = 'CLOCK_OUT'
           AND ap_out.timestamp_utc > ap_in.timestamp_utc
           AND ap_out.timestamp_utc = (
             SELECT MIN(timestamp_utc) FROM attendance_punches ap_out2 
             WHERE ap_out2.employee_id = e.id 
             AND ap_out2.event_type = 'CLOCK_OUT' 
             AND ap_out2.timestamp_utc > ap_in.timestamp_utc
           )
         WHERE e.id IN ({$employeeIds})
         AND e.is_active = 1
         AND ap_in.id IS NOT NULL
         ORDER BY e.first_name, e.last_name, ap_in.timestamp_utc",
        [$today]
    );
    
    // Group attendance data by employee
    $loggedUsers = [];
    foreach ($allAttendanceData as $attendance) {
        $empId = $attendance['id'];
        if (!isset($loggedUsers[$empId])) {
            $loggedUsers[$empId] = [
                'id' => $attendance['id'],
                'first_name' => $attendance['first_name'],
                'last_name' => $attendance['last_name'],
                'employee_id' => $attendance['employee_id'],
                'sessions' => []
            ];
        }
        $loggedUsers[$empId]['sessions'][] = [
            'check_in' => $attendance['check_in'],
            'check_out' => $attendance['check_out']
        ];
    }
    
    // Convert to indexed array
    $loggedUsers = array_values($loggedUsers);

    // Pending requests for approval
    $pendingRequests = $db->fetchAll(
        "SELECT la.*, e.first_name, e.last_name, lt.name as leave_type 
         FROM leave_applications la
         JOIN employees e ON la.employee_id = e.id
         JOIN leave_types lt ON la.leave_type_id = lt.id
         WHERE la.status = 'Pending' AND la.employee_id IN ({$employeeIds})
         ORDER BY la.created_at DESC
         LIMIT 5"
    );
}

// Upcoming holidays
$upcomingHolidays = [];
if (!empty($user['holiday_calendar_id'])) {
    $upcomingHolidays = $db->fetchAll(
        "SELECT h.*, hc.name as calendar_name 
         FROM holidays h
         JOIN holiday_calendars hc ON h.holiday_calendar_id = hc.id
         WHERE h.date >= CURDATE()
         AND h.holiday_calendar_id = ?
         ORDER BY h.date ASC
         LIMIT 5",
        [$user['holiday_calendar_id']]
    );
}

// Upcoming birthdays (next 30 days)
$upcomingBirthdays = $db->fetchAll(
    "SELECT id, first_name, last_name, employee_id, date_of_birth 
     FROM employees 
     WHERE is_active = 1 
     AND date_of_birth IS NOT NULL
     AND DATE_FORMAT(date_of_birth, '%m-%d') >= DATE_FORMAT(CURDATE(), '%m-%d')
     AND DATE_FORMAT(date_of_birth, '%m-%d') <= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '%m-%d')
     ORDER BY DATE_FORMAT(date_of_birth, '%m-%d') ASC
     LIMIT 5"
);

// Work anniversaries (next 30 days)
$workAnniversaries = $db->fetchAll(
    "SELECT id, first_name, last_name, employee_id, date_of_joining,
            TIMESTAMPDIFF(YEAR, date_of_joining, CURDATE()) as years
     FROM employees 
     WHERE is_active = 1 
     AND date_of_joining IS NOT NULL
     AND DATE_FORMAT(date_of_joining, '%m-%d') >= DATE_FORMAT(CURDATE(), '%m-%d')
     AND DATE_FORMAT(date_of_joining, '%m-%d') <= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '%m-%d')
     AND TIMESTAMPDIFF(YEAR, date_of_joining, CURDATE()) > 0
     ORDER BY DATE_FORMAT(date_of_joining, '%m-%d') ASC
     LIMIT 5"
);

// Recent activity from audit logs with hierarchy-based filtering
$visibleUserIds = [];

// Handle edge case: user hierarchy level might not be set
$userHierarchyLevel = isset($user['hierarchy_level']) ? $user['hierarchy_level'] : 999;

// Super Admin sees all activities
if ($userHierarchyLevel == 1) {
    $visibleUserIds = ['all']; // Special flag for no filtering
} else {
    // Get reporting tree for the current user (includes user + all their reports)
    $permissions = new Permissions();
    $reportingTree = $permissions->getReportingTree($user['id']);
    
    // The reporting tree includes the user themselves
    $visibleUserIds = $reportingTree;
    
    // Edge case: If something went wrong and tree is empty, default to self-only
    if (empty($visibleUserIds)) {
        $visibleUserIds = [$user['id']];
    }
}

// Build the query based on visibility
if (in_array('all', $visibleUserIds)) {
    // Super Admin - no filtering, but exclude system actions (NULL user_id) for cleaner display
    $recentActivity = $db->fetchAll(
        "SELECT al.*, e.first_name, e.last_name 
         FROM audit_log al
         LEFT JOIN employees e ON al.user_id = e.id
         WHERE al.user_id IS NOT NULL
         ORDER BY al.created_at DESC
         LIMIT 10"
    );
} else {
    // Regular user - filter by hierarchy
    if (empty($visibleUserIds)) {
        $visibleUserIds = [0]; // No results if no hierarchy
    }
    $userIdList = implode(',', $visibleUserIds);
    
    // Filter by visible user IDs and exclude system actions
    // Also ensure we only show activities from active employees
    $recentActivity = $db->fetchAll(
        "SELECT al.*, e.first_name, e.last_name 
         FROM audit_log al
         LEFT JOIN employees e ON al.user_id = e.id
         WHERE al.user_id IN ({$userIdList}) 
         AND al.user_id IS NOT NULL
         AND (e.is_active = 1 OR e.is_active IS NULL)
         ORDER BY al.created_at DESC
         LIMIT 10"
    );
}
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label"><i class="bi bi-people"></i> Total headcount</div>
        <div class="stat-value"><?php echo number_format($headcount); ?></div>
        <div class="stat-delta">Active employees</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><i class="bi bi-person-check"></i> Present today</div>
        <div class="stat-value"><?php echo number_format($presentToday); ?></div>
        <div class="stat-delta"><?php echo $headcount > 0 ? round(($presentToday / $headcount) * 100) : 0; ?>% attendance</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><i class="bi bi-calendar-x"></i> On leave</div>
        <div class="stat-value"><?php echo number_format($onLeave); ?></div>
        <div class="stat-delta">Approved leaves</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><i class="bi bi-inbox"></i> Open requests</div>
        <div class="stat-value"><?php echo number_format($openRequests); ?></div>
        <div class="stat-delta">Needs your review</div>
    </div>
</div>

<div class="row">
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-activity"></i> Team pulse — today's attendance</div>
            <div class="panel-link" onclick="location.href='?module=attendance'">View all →</div>
        </div>
        <?php if (empty($loggedUsers)): ?>
            <div class="empty-state">
                <i class="bi bi-person-x"></i>
                <p>No attendance activity today</p>
            </div>
        <?php else: ?>
            <div class="pulse-rail">
                <?php foreach ($loggedUsers as $loggedUser): ?>
                    <?php 
                    $sessions = $loggedUser['sessions'];
                    
                    // Calculate total duration and determine status.
                    // Stored punches (timestamp_utc) are UTC; parse them as UTC explicitly so a
                    // night-shift / open session is never shifted by the local UTC offset.
                    // Both open and closed sessions count gross wall-clock time (breaks included):
                    //  - closed: check_out - check_in (both UTC)
                    //  - open:   now (UTC) - check_in (UTC)  -> keeps growing live
                    $totalDuration = 0;
                    $isCurrentlyWorking = false;
                    foreach ($sessions as $session) {
                        $checkInTs = (new DateTime($session['check_in'], new DateTimeZone('UTC')))->getTimestamp();
                        if ($session['check_out']) {
                            $checkOutTs = (new DateTime($session['check_out'], new DateTimeZone('UTC')))->getTimestamp();
                            $duration = max(0, $checkOutTs - $checkInTs);
                            $totalDuration += $duration;
                        } else {
                            $isCurrentlyWorking = true;
                            // Gross live clocked-in time including breaks.
                            $duration = max(0, time() - $checkInTs);
                            $totalDuration += $duration;
                        }
                    }
                    ?>
                    <div class="pulse-row">
                        <div class="pulse-name"><?php echo $loggedUser['first_name'] . ' ' . $loggedUser['last_name']; ?></div>
                        <div class="pulse-timeline">
                            <?php foreach ($sessions as $index => $session): ?>
                                <?php 
                                $checkInTime = utcToLocal($session['check_in'], 'H:i');
                                $checkOutTime = $session['check_out'] ? utcToLocal($session['check_out'], 'H:i') : 'now';
                                $sessionBarColor = ($index === count($sessions) - 1 && !$session['check_out']) ? '#1F9E6D' : '#5B4EE5';
                                ?>
                                <div class="pulse-session">
                                    <span class="session-time"><?php echo $checkInTime; ?></span>
                                    <span class="session-bar" style="color:<?php echo $sessionBarColor; ?>">━━</span>
                                    <span class="session-time"><?php echo $checkOutTime; ?></span>
                                </div>
                                <?php if ($index < count($sessions) - 1): ?>
                                    <span class="session-gap">   </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="pulse-status mono">
                            <?php if ($totalDuration > 0): ?>
                                <?php 
                                $hours = floor($totalDuration / 3600);
                                $minutes = floor(($totalDuration % 3600) / 60);
                                echo $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="pulse-legend">
                <div class="legend-item"><div class="legend-dot" style="background:#1F9E6D;"></div>Currently Working</div>
                <div class="legend-item"><div class="legend-dot" style="background:#5B4EE5;"></div>Completed Session</div>
                <div class="legend-item"><span style="font-family:'IBM Plex Mono',monospace;font-size:11px;">09:00 ━━ 17:00</span>Timeline Format</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-inbox"></i> Pending requests</div>
            <div class="panel-link"><?php echo count($pendingRequests); ?></div>
        </div>
        <?php if (empty($pendingRequests)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle"></i>
                <p>No pending requests</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingRequests as $request): ?>
                <div class="req-item">
                    <div class="avatar" style="width:34px;height:34px;font-size:11px;">
                        <?php echo getInitials($request['first_name'] . ' ' . $request['last_name']); ?>
                    </div>
                    <div class="req-info">
                        <div class="req-name"><?php echo $request['first_name'] . ' ' . $request['last_name']; ?></div>
                        <div class="req-meta"><?php echo $request['leave_type']; ?> · <?php echo $request['total_days']; ?> days</div>
                    </div>
                    <div class="badge pending">Pending</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="row-3">
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-calendar-event"></i> Upcoming holidays</div>
        </div>
        <?php if (empty($upcomingHolidays)): ?>
            <div class="empty-state">
                <i class="bi bi-calendar-x"></i>
                <p>No upcoming holidays</p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
                <?php foreach ($upcomingHolidays as $holiday): ?>
                    <div style="background:var(--accent-ink);padding:12px;border-radius:8px;">
                        <div style="font-size:11px;color:var(--accent-dark);opacity:0.75;">
                            <?php echo date('M j, Y', strtotime($holiday['date'])); ?>
                        </div>
                        <div style="font-weight:600;font-size:13px;margin-top:4px;">
                            <?php echo $holiday['name']; ?>
                        </div>
                        <div style="font-size:11px;color:var(--accent-dark);opacity:0.75;margin-top:2px;">
                            <?php echo $holiday['type']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head">
           <div class="panel-title"><i class="bi bi-gift"></i> Upcoming birthdays</div>
        </div>
        <?php if (empty($upcomingBirthdays)): ?>
            <div class="empty-state">
                <i class="bi bi-cake"></i>
                <p>No upcoming birthdays</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($upcomingBirthdays as $emp): ?>
                    <div class="req-item">
                        <div class="avatar" style="width:34px;height:34px;font-size:11px;">
                            <?php echo getInitials($emp['first_name'] . ' ' . $emp['last_name']); ?>
                        </div>
                        <div class="req-info">
                            <div class="req-name"><?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?></div>
                            <div class="req-meta">
                                <?php 
                                $dobYear = date('Y');
                                $dobMonth = date('m', strtotime($emp['date_of_birth']));
                                $dobDay = date('d', strtotime($emp['date_of_birth']));
                                $birthday = date('M j', strtotime("$dobYear-$dobMonth-$dobDay"));
                                if (strtotime("$dobYear-$dobMonth-$dobDay") < strtotime('today')) {
                                    $birthday = date('M j', strtotime("$dobYear-$dobMonth-$dobDay +1 year"));
                                }
                                echo $birthday;
                                ?>
                            </div>
                        </div>
                        <div class="badge accent">🎂</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-trophy"></i> Work anniversaries</div>
        </div>
        <?php if (empty($workAnniversaries)): ?>
            <div class="empty-state">
                <i class="bi bi-trophy"></i>
                <p>No upcoming work anniversaries</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($workAnniversaries as $emp): ?>
                    <div class="req-item">
                        <div class="avatar" style="width:34px;height:34px;font-size:11px;">
                            <?php echo getInitials($emp['first_name'] . ' ' . $emp['last_name']); ?>
                        </div>
                        <div class="req-info">
                            <div class="req-name"><?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?></div>
                            <div class="req-meta">
                                <?php 
                                $dojYear = date('Y');
                                $dojMonth = date('m', strtotime($emp['date_of_joining']));
                                $dojDay = date('d', strtotime($emp['date_of_joining']));
                                $anniversary = date('M j', strtotime("$dojYear-$dojMonth-$dojDay"));
                                if (strtotime("$dojYear-$dojMonth-$dojDay") < strtotime('today')) {
                                    $anniversary = date('M j', strtotime("$dojYear-$dojMonth-$dojDay +1 year"));
                                }
                                echo $anniversary . ' · ' . $emp['years'] . ' year' . ($emp['years'] > 1 ? 's' : '');
                                ?>
                            </div>
                        </div>
                        <div class="badge success"><?php echo $emp['years']; ?>y</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($recentActivity)): ?>
<div class="panel">
    <div class="panel-head">
        <div class="panel-title"><i class="bi bi-clock-history"></i> Recent activity</div>
        <div class="panel-link">View all →</div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($recentActivity as $activity): ?>
            <div class="activity-item">
                <div class="activity-dot"></div>
                <div style="flex:1;">
                    <div class="activity-text">
                        <?php 
                        $userName = $activity['first_name'] ? $activity['first_name'] . ' ' . $activity['last_name'] : 'System';
                        $action = ucfirst($activity['action']);
                        $entity = ucfirst(str_replace('_', ' ', $activity['entity_type']));
                        echo "<b>{$userName}</b> {$action} {$entity}";
                        ?>
                    </div>
                    <div class="activity-time">
                        <?php 
                        $time = strtotime($activity['created_at']);
                        if (date('Y-m-d', $time) == date('Y-m-d')) {
                            echo 'Today at ' . date('H:i', $time);
                        } elseif (date('Y-m-d', $time) == date('Y-m-d', strtotime('-1 day'))) {
                            echo 'Yesterday at ' . date('H:i', $time);
                        } else {
                            echo date('M j, H:i', $time);
                        }
                        ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
