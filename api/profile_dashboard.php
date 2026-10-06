<?php
/**
 * Profile Dashboard API
 * Fetches data for the employee profile dashboard
 * Core PHP HRMS Application
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();

    $db = Database::getInstance();
    $user = getCurrentUser();
    $employeeId = $user['id'];

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get priority filter if provided
        $priorityFilter = $_GET['priority'] ?? 'all';

        // Fetch employee profile data
        $profileData = $db->fetchOne(
            "SELECT e.*, r.name as role_name, d.name as department_name, ed.department_id as primary_department_id
             FROM employees e 
             LEFT JOIN roles r ON e.role_id = r.id 
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 
             LEFT JOIN departments d ON ed.department_id = d.id 
             WHERE e.id = ?",
            [$employeeId]
        );

        // Fetch pending tasks with priority filter
        $priorityCondition = '';
        $params = [$employeeId];
        
        if ($priorityFilter !== 'all') {
            // Map filter values to database priority names
            $priorityMap = [
                'high' => 'High',
                'medium' => 'Medium', 
                'low' => 'Low',
                'critical' => 'Critical'
            ];
            $dbPriority = $priorityMap[$priorityFilter] ?? ucfirst($priorityFilter);
            $priorityCondition = "AND tp.name = ?";
            $params[] = $dbPriority;
        }

        $pendingTasks = $db->fetchAll(
            "SELECT t.*, tp.name as priority_name, ts.name as status_name,
                    ab.first_name as assigned_by_first, ab.last_name as assigned_by_last
             FROM tasks t
             JOIN task_assignments ta ON t.id = ta.task_id
             LEFT JOIN task_priorities tp ON t.task_priority_id = tp.id
             LEFT JOIN task_statuses ts ON t.task_status_id = ts.id
             LEFT JOIN employees ab ON t.assigned_by = ab.id
             WHERE ta.assignee_id = ? 
             AND (ts.name IS NULL OR ts.name NOT IN ('Completed', 'Cancelled'))
             {$priorityCondition}
             ORDER BY t.deadline ASC, t.created_at DESC
             LIMIT 10",
            $params
        );

        // Fetch attendance summary for current pay cycle
        // Get current pay cycle dates (assuming monthly cycle)
        $currentMonth = date('Y-m');
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $attendanceSummary = $db->fetchOne(
            "SELECT 
                COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_days,
                COUNT(CASE WHEN status = 'Absent' THEN 1 END) as absent_days,
                COUNT(CASE WHEN status = 'Half Day' THEN 1 END) as half_days,
                COUNT(CASE WHEN status = 'On Leave' THEN 1 END) as leave_days,
                COUNT(*) as total_days,
                SUM(total_worked_minutes) as total_worked_minutes
             FROM attendance_daily
             WHERE employee_id = ? 
             AND attendance_date BETWEEN ? AND ?",
            [$employeeId, $monthStart, $monthEnd]
        );

        // Calculate attendance percentage
        $totalWorkingDays = $attendanceSummary['total_days'] ?? 0;
        $presentDays = $attendanceSummary['present_days'] ?? 0;
        $attendancePercentage = $totalWorkingDays > 0 ? round(($presentDays / $totalWorkingDays) * 100, 1) : 0;

        $attendanceData = [
            'present_days' => $attendanceSummary['present_days'] ?? 0,
            'absent_days' => $attendanceSummary['absent_days'] ?? 0,
            'half_days' => $attendanceSummary['half_days'] ?? 0,
            'leave_days' => $attendanceSummary['leave_days'] ?? 0,
            'total_working_days' => $totalWorkingDays,
            'attendance_percentage' => $attendancePercentage,
            'total_worked_minutes' => $attendanceSummary['total_worked_minutes'] ?? 0,
            'pay_cycle_start' => $monthStart,
            'pay_cycle_end' => $monthEnd
        ];

        // Fetch pending leave approvals (where user is approver)
        $pendingLeaveApprovals = 0;
        if (hasPermission('leaves', 'approve')) {
            $scope = getDataScope('leaves');
            $permissions = new Permissions();
            $scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'leaves', 'approve');
            $employeeIds = implode(',', $scopedIds) ?: '0';

            $pendingLeaveApprovals = $db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM leave_applications 
                 WHERE status = 'Pending' 
                 AND employee_id IN ({$employeeIds})",
                []
            )['count'] ?? 0;
        }

        // Fetch pending regularization requests (where user is approver)
        $pendingRegularization = 0;
        if (hasPermission('attendance', 'approve')) {
            $scope = getDataScope('attendance');
            $permissions = new Permissions();
            $scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'attendance', 'approve');
            $employeeIds = implode(',', $scopedIds) ?: '0';

            $pendingRegularization = $db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM regularization_requests 
                 WHERE status = 'Pending' 
                 AND employee_id IN ({$employeeIds})",
                []
            )['count'] ?? 0;
        }

        $approvalsData = [
            'pending_leave_approvals' => $pendingLeaveApprovals,
            'pending_regularization' => $pendingRegularization,
            'can_approve_leaves' => hasPermission('leaves', 'approve'),
            'can_approve_attendance' => hasPermission('attendance', 'approve')
        ];

        // Combine all data
        $response = [
            'success' => true,
            'message' => 'Profile dashboard data retrieved successfully',
            'data' => [
                'profile' => $profileData,
                'pending_tasks' => $pendingTasks,
                'attendance' => $attendanceData,
                'approvals' => $approvalsData
            ]
        ];

        echo json_encode($response);

    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
