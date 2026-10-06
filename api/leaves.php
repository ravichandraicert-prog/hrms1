<?php
/**
 * Leaves API
 * Handles leave applications, approvals, and leave type management
 * Core PHP HRMS Application
 * Section 13 - Leave management module
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/leave_assignment.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    $workPatternService = new WorkPatternService();

    $method = $_SERVER['REQUEST_METHOD'];

    // ---- Helper: compute total days between two dates using work pattern ----
    $computeTotalDays = function ($startDate, $endDate, $duration, $leaveType, $employeeId) use ($workPatternService) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        $days = 0;
        $basis = isset($leaveType['calculation_basis']) ? $leaveType['calculation_basis'] : 'working_days';
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            
            if ($basis === 'working_days') {
                // Use work pattern service to determine if it's a working day
                if ($workPatternService->isWorkingDay($employeeId, $dateStr)) {
                    $days++;
                }
            } else {
                // Calendar days - count all days
                $days++;
            }
        }
        
        if ($duration === 'first_half' || $duration === 'second_half') {
            $days = max(0, $days - 0.5);
        }
        return round($days, 2);
    };

    // ---- Helper ensuring a balance row exists ----
    $ensureBalance = function ($employeeId, $leaveTypeId) use ($db) {
        $year = (int) date('Y');
        $existing = $db->fetchOne(
            "SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?",
            [$employeeId, $leaveTypeId, $year]
        );
        if (!$existing) {
            $id = $db->insert('leave_balances', [
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
                'total_accrued' => 0,
                'total_used' => 0,
                'carried_forward' => 0
            ]);
            $existing = $db->fetchOne("SELECT * FROM leave_balances WHERE id = ?", [$id]);
        }
        return $existing;
    };

    // ---- Handle JSON or FormData payload ----
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($method === 'POST' && strpos($contentType, 'multipart/form-data') !== false) {
        $data = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];
    }
$action = $data['action'] ?? '';

    if ($method === 'POST' && $action === 'apply') {
        // Create a leave application
        $leaveTypeId = (int) ($data['leave_type_id'] ?? 0);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $duration = $data['duration'] ?? 'full';
        $reason = $data['reason'] ?? '';
        $festivalHolidayId = !empty($data['festival_holiday_id']) ? (int) $data['festival_holiday_id'] : null;
        $festivalType = $data['festival_type'] ?? null;

        if (!$leaveTypeId) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please select a leave type']);
            exit;
        }

        $leaveType = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$leaveTypeId]);
        if (!$leaveType) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid leave type']);
            exit;
        }

        // Enforce assignment: a user can only apply for leave types assigned to them
        if (!leave_type_is_applicable($db, $leaveType, $user['id'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This leave type is not assigned to you']);
            exit;
        }

        // For Festival Leave types, validate that a festival holiday is selected
        if ($leaveType['name'] === 'Festival Leave' || $leaveType['name'] === 'Optional Festival Leave') {
            if (!$festivalHolidayId) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Please select a festival holiday']);
                exit;
            }

            // For Festival Leave types, validate that the selected holiday exists and belongs to employee's calendar
            $holiday = $db->fetchOne(
                "SELECT h.*, hc.id as calendar_id FROM holidays h 
                 JOIN holiday_calendars hc ON h.holiday_calendar_id = hc.id 
                 WHERE h.id = ?",
                [$festivalHolidayId]
            );
            if (!$holiday) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid festival holiday selected']);
                exit;
            }
            
            // Verify the holiday belongs to employee's assigned calendar
            $employee = $db->fetchOne("SELECT holiday_calendar_id FROM employees WHERE id = ?", [$user['id']]);
            if (!$employee || $employee['holiday_calendar_id'] != $holiday['calendar_id']) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Selected festival holiday is not in your assigned calendar']);
                exit;
            }

            // Get the actual festival type from the holiday
            $actualFestivalType = $holiday['type']; // 'Fixed' or 'Optional'
            
            // Automatically switch to the correct leave type based on festival type
            if ($actualFestivalType === 'Optional' && $leaveType['name'] === 'Festival Leave') {
                // User selected Festival Leave but chose an Optional festival
                // Switch to Optional Festival Leave type
                $optionalFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Optional Festival Leave'");
                if ($optionalFestivalLeaveType) {
                    $leaveTypeId = $optionalFestivalLeaveType['id'];
                    $leaveType = $optionalFestivalLeaveType;
                }
            } elseif ($actualFestivalType === 'Fixed' && $leaveType['name'] === 'Optional Festival Leave') {
                // User selected Optional Festival Leave but chose a Fixed festival
                // Switch to Festival Leave type
                $fixedFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Festival Leave'");
                if ($fixedFestivalLeaveType) {
                    $leaveTypeId = $fixedFestivalLeaveType['id'];
                    $leaveType = $fixedFestivalLeaveType;
                }
            }

            // Use the actual festival type from the holiday
            $festivalType = $actualFestivalType;

            // Auto-fill dates from holiday if not provided
            if (!$startDate || !$endDate) {
                $startDate = $holiday['date'];
                $endDate = $holiday['date'];
            }

            // Auto-generate reason if not provided
            if (!$reason) {
                $reason = $holiday['name'];
            }
        } else {
            // For regular leave types, validate all required fields
            if (!$startDate || !$endDate || !$reason) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
                exit;
            }
            if (strtotime($endDate) < strtotime($startDate)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'End date cannot be before start date']);
                exit;
            }
        }

        $totalDays = $computeTotalDays($startDate, $endDate, $duration, $leaveType, $user['id']);
        if ($totalDays <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No working days in selected range based on your work pattern']);
            exit;
        }

        // Validate that selected dates are working days according to work pattern
        // Skip this validation for Festival Leave types as they're meant for holidays
        if ($leaveType['name'] !== 'Festival Leave' && $leaveType['name'] !== 'Optional Festival Leave') {
            $validationErrors = [];
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            
            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');
                if (!$workPatternService->isWorkingDay($user['id'], $dateStr)) {
                    $dayName = $date->format('l');
                    $validationErrors[] = "$dateStr ($dayName) is a weekly off based on your work pattern";
                }
            }
            
            if (!empty($validationErrors)) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'Some selected dates are weekly offs: ' . implode(', ', $validationErrors)
                ]);
                exit;
            }
        }

        // Balance check: 
        // - Fixed Festival Leave: no balance check (doesn't consume balance)
        // - Optional Festival Leave: balance check required (consumes from optional festival leave balance)
        // - Regular leave types: balance check required
        if ($leaveType['name'] !== 'Festival Leave') {
            $balance = $ensureBalance($user['id'], $leaveTypeId);
            $available = (float) ($balance['balance'] ?? 0);
            if ($available <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'You do not have any remaining balance for this leave type']);
                exit;
            }
            if ($totalDays > $available) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Insufficient leave balance. Available: ' . number_format($available, 1)]);
                exit;
            }
        }

        // Optional attachment upload
        $attachment = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['attachment'], 'leaves');
            if ($upload['success']) {
                $attachment = $upload['filename'];
            }
        }

        $leaveId = $db->insert('leave_applications', [
            'employee_id' => $user['id'],
            'leave_type_id' => $leaveTypeId,
            'festival_holiday_id' => $festivalHolidayId,
            'festival_type' => $festivalType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration' => $duration,
            'total_days' => $totalDays,
            'reason' => $reason,
            'status' => 'Pending',
            'attachment' => $attachment,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Notify all managers in the reporting chain (branching hierarchy)
        require_once __DIR__ . '/../includes/permissions.php';
        $recipients = getReportingChainRecipients($user['id'], 'leaves');

        if (!empty($recipients)) {
            $emp = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$user['id']]);
            foreach ($recipients as $recipientId) {
                // Don't notify the employee themselves
                if ($recipientId != $user['id']) {
                    sendNotification($recipientId, 'leave', 'New Leave Request',
                        $emp['first_name'] . ' ' . $emp['last_name'] . ' requested ' . number_format($totalDays, 1) . ' day(s) of ' . $leaveType['name'], '?module=leaves&tab=approvals');
                }
            }
        }

        logAudit('leave_applications', $leaveId, 'create', null, [
            'leave_type_id' => $leaveTypeId, 'start_date' => $startDate, 'end_date' => $endDate, 'total_days' => $totalDays
        ]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'leave_id' => $leaveId, 'message' => 'Leave request submitted successfully']);
        exit;
    }
if ($method === 'POST' && $action === 'cancel') {
    $id = (int) ($data['id'] ?? 0);
    if (!$id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Leave ID is required']);
        exit;
    }

    $leave = $db->fetchOne("SELECT * FROM leave_applications WHERE id = ?", [$id]);
    if (!$leave) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Leave application not found']);
        exit;
    }
    
    // Only allow cancelling Pending or Approved leaves
    if (!in_array($leave['status'], ['Pending', 'Approved'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'This request has already been ' . strtolower($leave['status']) . '.']);
        exit;
    }

    // Only the requesting employee (or an admin) may cancel a request
    if ((int) $leave['employee_id'] !== (int) $user['id'] && !hasPermission('leaves', 'update')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
        exit;
    }
    
    $before = $leave;
    
    // If the leave was approved, restore the balance and recompute attendance
    // Skip balance restoration for Fixed Festival Leave since it never consumed balance
    if ($leave['status'] === 'Approved') {
        $leaveType = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$leave['leave_type_id']]);
        if ($leaveType && $leaveType['name'] !== 'Festival Leave') {
            $balance = $ensureBalance($leave['employee_id'], $leave['leave_type_id']);
            $db->update('leave_balances',
                ['total_used' => (float) $balance['total_used'] - (float) $leave['total_days']],
                'id = ?',
                [$balance['id']]
            );
        }
        
        // Recompute attendance for all dates in the cancelled leave period
        require_once __DIR__ . '/../includes/attendance.php';
        $attendance = new Attendance();
        
        $startDate = new DateTime($leave['start_date']);
        $endDate = new DateTime($leave['end_date']);
        $dateRange = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
        
        foreach ($dateRange as $date) {
            $attendanceDate = $date->format('Y-m-d');
            // Get the shift for this employee on this date
            $emp = $db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$leave['employee_id']]);
            $shiftId = $emp ? $emp['shift_id'] : null;
            
            // Remove leave status from attendance_daily
            $existingAttendance = $db->fetchOne(
                "SELECT id FROM attendance_daily 
                 WHERE employee_id = ? AND attendance_date = ? AND status IN ('On Leave', 'First Half Leave', 'Second Half Leave')",
                [$leave['employee_id'], $attendanceDate]
            );
            
            if ($existingAttendance) {
                // Delete the leave record and recompute based on punches
                $db->delete('attendance_daily', 'id = ?', [$existingAttendance['id']]);
            }
            
            // Recompute attendance for this date based on punches
            $attendance->recomputeAttendanceDaily($leave['employee_id'], $attendanceDate, $shiftId);
        }
    }
    
    $db->update('leave_applications', ['status' => 'Cancelled'], 'id = ?', [$id]);
    logAudit('leave_applications', $id, 'update', $before, ['status' => 'Cancelled']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Leave request cancelled']);
    exit;
}

if ($method === 'POST' && in_array($action, ['approve', 'reject'], true)) {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave ID is required']);
            exit;
        }
        if (!hasPermission('leaves', 'approve')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }

        $leave = $db->fetchOne("SELECT * FROM leave_applications WHERE id = ?", [$id]);
        if (!$leave) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave application not found']);
            exit;
        }

        // Prevent self-approval - users cannot approve/reject their own leave requests
        if (in_array($action, ['approve', 'reject']) && (int) $leave['employee_id'] === (int) $user['id']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You cannot ' . $action . ' your own leave request'], 403);
            exit;
        }

        // Branching hierarchy check: only users in the reporting chain can approve/reject
        if (in_array($action, ['approve', 'reject'])) {
            require_once __DIR__ . '/../includes/permissions.php';
            
            // Super Admin bypass - can approve any leave request
            if (!isSuperAdmin()) {
                $authorizedApprovers = getReportingChainRecipients($leave['employee_id'], 'leaves');
                
                // Check if current user is in the authorized approvers list
                if (!in_array($user['id'], $authorizedApprovers)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'You are not authorized to approve this leave request. Only managers in the reporting chain can approve.'], 403);
                    exit;
                }
            }
        }

        // Hierarchy check removed - users with approve permission can approve any leave request
        // Permission is controlled through the settings/permissions system
        // For cancel action, allow cancelling both Pending and Approved leaves
        if ($action === 'cancel' && !in_array($leave['status'], ['Pending', 'Approved'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This request has already been ' . strtolower($leave['status']) . '.']);
            exit;
        }
        // For approve/reject actions, only allow Pending leaves
        if (in_array($action, ['approve', 'reject']) && $leave['status'] !== 'Pending') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This request has already been ' . strtolower($leave['status']) . '.']);
            exit;
        }

        $before = $leave;

        if ($action === 'approve') {
            $db->update('leave_applications', [
                'status' => 'Approved',
                'approved_by' => $user['id'],
                'approved_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);

            // Consume leave balance only for non-Fixed Festival Leave types
            // Fixed Festival Leave doesn't consume balance
            $leaveType = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$leave['leave_type_id']]);
            if ($leaveType && $leaveType['name'] !== 'Festival Leave') {
                // Consume leave balance (stored generated column recomputes balance automatically)
                $balance = $ensureBalance($leave['employee_id'], $leave['leave_type_id']);
                $db->update('leave_balances',
                    ['total_used' => (float) $balance['total_used'] + (float) $leave['total_days']],
                    'id = ?',
                    [$balance['id']]
                );
            }

            // Update attendance_daily table to mark employee based on leave duration
            require_once __DIR__ . '/../includes/attendance.php';
            $attendance = new Attendance();
            
            $startDate = new DateTime($leave['start_date']);
            $endDate = new DateTime($leave['end_date']);
            $dateRange = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
            
            $leaveDuration = $leave['duration'] ?? 'full';
            
            foreach ($dateRange as $date) {
                $attendanceDate = $date->format('Y-m-d');
                // Get the shift for this employee on this date
                $emp = $db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$leave['employee_id']]);
                $shiftId = $emp ? $emp['shift_id'] : null;
                
                // Determine the attendance status based on leave duration
                if ($leaveDuration === 'first_half') {
                    $status = 'First Half Leave';
                } elseif ($leaveDuration === 'second_half') {
                    $status = 'Second Half Leave';
                } else {
                    $status = 'On Leave';
                }
                
                // Check if attendance record exists for this date
                $existingAttendance = $db->fetchOne(
                    "SELECT id, status FROM attendance_daily 
                     WHERE employee_id = ? AND attendance_date = ?",
                    [$leave['employee_id'], $attendanceDate]
                );
                
                if ($existingAttendance) {
                    // Update existing record with leave status
                    $db->update('attendance_daily',
                        ['status' => $status],
                        'id = ?',
                        [$existingAttendance['id']]
                    );
                } else {
                    // Create new attendance record with leave status
                    $db->insert('attendance_daily', [
                        'employee_id' => $leave['employee_id'],
                        'attendance_date' => $attendanceDate,
                        'shift_id' => $shiftId,
                        'status' => $status,
                        'total_worked_minutes' => 0,
                        'total_break_minutes' => 0,
                        'session_count' => 0
                    ]);
                }
            }

            $emp = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$leave['employee_id']]);

            // Notify ONLY the employee who submitted the request
            sendNotification($leave['employee_id'], 'leave', 'Leave Approved',
                'Your leave request (' . number_format($leave['total_days'], 1) . ' day(s)) has been approved.', '?module=leaves&tab=my');

            logAudit('leave_applications', $id, 'approve', $before, ['status' => 'Approved']);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Leave application approved']);
            exit;

        } elseif ($action === 'reject') {
            $reason = $data['reason'] ?? '';
            $db->update('leave_applications', [
                'status' => 'Rejected',
                'rejection_reason' => $reason ?: null,
                'approved_by' => $user['id'],
                'approved_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);

            // Recompute attendance for all dates in the rejected leave period
            // (in case attendance was manually marked as 'On Leave' before rejection)
            require_once __DIR__ . '/../includes/attendance.php';
            $attendance = new Attendance();
            
            $startDate = new DateTime($leave['start_date']);
            $endDate = new DateTime($leave['end_date']);
            $dateRange = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
            
            foreach ($dateRange as $date) {
                $attendanceDate = $date->format('Y-m-d');
                // Get the shift for this employee on this date
                $emp = $db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$leave['employee_id']]);
                $shiftId = $emp ? $emp['shift_id'] : null;
                
                // Remove leave status from attendance_daily
                $existingAttendance = $db->fetchOne(
                    "SELECT id FROM attendance_daily 
                     WHERE employee_id = ? AND attendance_date = ? AND status IN ('On Leave', 'First Half Leave', 'Second Half Leave')",
                    [$leave['employee_id'], $attendanceDate]
                );
                
                if ($existingAttendance) {
                    // Delete the leave record and recompute based on punches
                    $db->delete('attendance_daily', 'id = ?', [$existingAttendance['id']]);
                }
                
                // Recompute attendance for this date based on punches
                $attendance->recomputeAttendanceDaily($leave['employee_id'], $attendanceDate, $shiftId);
            }

            $emp = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$leave['employee_id']]);

            // Notify ONLY the employee who submitted the request
            sendNotification($leave['employee_id'], 'leave', 'Leave Rejected',
                'Your leave request (' . number_format($leave['total_days'], 1) . ' day(s)) was rejected.' . ($reason ? ' Reason: ' . $reason : ''), '?module=leaves&tab=my');

            logAudit('leave_applications', $id, 'reject', $before, ['status' => 'Rejected']);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Leave application rejected']);
            exit;
        }
    }
// ---- Leave type management (GET single, save, delete) ----
    if ($method === 'GET' && isset($_GET['get_assignment_data'])) {
        if (!hasPermission('leaves', 'read') && !hasPermission('settings', 'read')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        
        $departments = $db->fetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
        $roles = $db->fetchAll("SELECT id, name FROM roles WHERE is_active = 1 ORDER BY name");
        $users = $db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees WHERE is_active = 1 ORDER BY first_name, last_name");
        $shifts = $db->fetchAll("SELECT id, name FROM shifts WHERE is_active = 1 ORDER BY name");

        // employee_id => [department_id,...] map for the idle "select department -> employees" UI
        $userDepartments = [];
        $deptRows = $db->fetchAll(
            "SELECT ed.employee_id, ed.department_id
             FROM employee_departments ed
             JOIN employees e ON e.id = ed.employee_id
             WHERE e.is_active = 1"
        );
        foreach ($deptRows as $dr) {
            $userDepartments[(int) $dr['employee_id']][] = (int) $dr['department_id'];
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'departments' => $departments,
            'roles' => $roles,
            'users' => $users,
            'shifts' => $shifts,
            'user_departments' => $userDepartments
        ]);
        exit;
    }

    if ($method === 'GET' && isset($_GET['type_id'])) {
        if (!hasPermission('leaves', 'read')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        $typeId = (int) $_GET['type_id'];
        $type = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$typeId]);
        header('Content-Type: application/json');
        echo json_encode($type ? ['success' => true, 'type' => $type] : ['success' => false, 'message' => 'Leave type not found']);
        exit;
    }

    if ($method === 'GET' && isset($_GET['leave_id'])) {
        if (!hasPermission('leaves', 'read')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        $leaveId = (int) $_GET['leave_id'];
        $leave = $db->fetchOne(
            "SELECT la.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id,
             ap.first_name as approver_first, ap.last_name as approver_last
             FROM leave_applications la
             JOIN leave_types lt ON la.leave_type_id = lt.id
             JOIN employees e ON la.employee_id = e.id
             LEFT JOIN employees ap ON la.approved_by = ap.id
             WHERE la.id = ?",
            [$leaveId]
        );
        header('Content-Type: application/json');
        echo json_encode($leave ? ['success' => true, 'leave' => $leave] : ['success' => false, 'message' => 'Leave application not found']);
        exit;
    }

    if ($method === 'POST' && $action === 'save_type') {
        if (!hasPermission('leaves', 'update')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $typeData = [
            'name' => trim($data['name'] ?? ''),
            'calculation_basis' => $data['calculation_basis'] ?? 'working_days',
            'accrual_method' => $data['accrual_method'] ?? 'fixed_annual',
            'max_days_per_year' => (int) ($data['max_days_per_year'] ?? 0),
            'min_days_per_request' => (float) ($data['min_days_per_request'] ?? 0.5),
            'carry_forward' => !empty($data['carry_forward']) ? 1 : 0,
            'max_carry_forward_days' => (int) ($data['max_carry_forward_days'] ?? 0),
            'assign_to' => $data['assign_to'] ?? 'all_employees',
            'target_id' => !empty($data['target_id']) ? (int) $data['target_id'] : null,
            'is_paid' => !empty($data['is_paid']) ? 1 : 0,
            'requires_approval' => !empty($data['requires_approval']) ? 1 : 1,
            'description' => $data['description'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            // Monthly accrual fields
            'monthly_accrual_enabled' => !empty($data['monthly_accrual_enabled']) ? 1 : 0,
            'monthly_accrual_days' => (float) ($data['monthly_accrual_days'] ?? 0),
            'accrual_timing' => $data['accrual_timing'] ?? 'pay_cycle_end',
            'accrual_day_of_month' => !empty($data['accrual_day_of_month']) ? (int) $data['accrual_day_of_month'] : null
        ];
        if (empty($typeData['name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave type name is required']);
            exit;
        }
        if ($id) {
            $db->update('leave_types', $typeData, 'id = ?', [$id]);
            logAudit('leave_types', $id, 'update', null, $typeData);
            $newId = $id;
        } else {
            $newId = $db->insert('leave_types', $typeData);
            logAudit('leave_types', $newId, 'create', null, $typeData);
        }
        // Provision balances for all employees this type is assigned to (non-destructive)
        $typeData['id'] = $newId;
        sync_leave_type_balances($db, $typeData);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'type_id' => $newId, 'message' => 'Leave type saved successfully']);
        exit;
    }

    // ---- Get single leave type for editing ----
    if ($method === 'GET' && isset($_GET['type_id'])) {
        if (!hasPermission('leaves', 'read')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        $typeId = (int) $_GET['type_id'];
        $type = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$typeId]);
        if (!$type) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave type not found']);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'type' => $type]);
        exit;
    }

    // ---- Filter API endpoints ----
    if ($method === 'GET' && isset($_GET['action'])) {
        if (!hasPermission('leaves', 'read')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        $action = $_GET['action'];
        
        if ($action === 'get_departments') {
            $departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'departments' => $departments]);
            exit;
        }
        
        if ($action === 'get_types') {
            $types = $db->fetchAll("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'types' => $types]);
            exit;
        }
        
        if ($action === 'get_employees') {
            $search = $_GET['search'] ?? '';
            $departmentId = $_GET['department_id'] ?? '';
            
            $where = ["e.is_active = 1"];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($departmentId)) {
                $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
                $params[] = $departmentId;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $employees = $db->fetchAll(
                "SELECT e.id, e.first_name, e.last_name, e.employee_id, 
                 (SELECT d.name FROM departments d JOIN employee_departments ed ON d.id = ed.department_id WHERE ed.employee_id = e.id AND ed.is_primary = 1 LIMIT 1) as department_name
                 FROM employees e
                 WHERE {$whereClause}
                 ORDER BY e.first_name, e.last_name
                 LIMIT 50",
                $params
            );
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'employees' => $employees]);
            exit;
        }
        
        if ($action === 'filter_calendar') {
            $month = $_GET['month'] ?? date('Y-m');
            $employeeId = $_GET['employee_id'] ?? '';
            $departmentId = $_GET['department_id'] ?? '';
            $leaveTypeId = $_GET['leave_type_id'] ?? '';
            
            $where = ["la.start_date LIKE ? OR la.end_date LIKE ?"];
            $params = [$month . '%', $month . '%'];
            
            if (!empty($employeeId)) {
                $where[] = "la.employee_id = ?";
                $params[] = $employeeId;
            }
            
            if (!empty($departmentId)) {
                $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
                $params[] = $departmentId;
            }
            
            if (!empty($leaveTypeId)) {
                $where[] = "la.leave_type_id = ?";
                $params[] = $leaveTypeId;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $leaves = $db->fetchAll(
                "SELECT la.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id
                 FROM leave_applications la
                 JOIN leave_types lt ON la.leave_type_id = lt.id
                 JOIN employees e ON la.employee_id = e.id
                 WHERE {$whereClause} AND la.status IN ('Approved', 'Pending')
                 ORDER BY la.start_date",
                $params
            );
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'leaves' => $leaves]);
            exit;
        }
        
        if ($action === 'filter_my_leaves') {
            $status = $_GET['status'] ?? '';
            $departmentId = $_GET['department_id'] ?? '';
            $leaveTypeId = $_GET['leave_type_id'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $where = ["la.employee_id = ?"];
            $params = [$user['id']];
            
            if (!empty($status)) {
                $where[] = "la.status = ?";
                $params[] = $status;
            }
            
            if (!empty($departmentId)) {
                $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
                $params[] = $departmentId;
            }
            
            if (!empty($leaveTypeId)) {
                $where[] = "la.leave_type_id = ?";
                $params[] = $leaveTypeId;
            }
            
            if (!empty($search)) {
                $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $leaves = $db->fetchAll(
                "SELECT la.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id,
                 ap.first_name as manager_first, ap.last_name as manager_last
                 FROM leave_applications la
                 JOIN leave_types lt ON la.leave_type_id = lt.id
                 JOIN employees e ON la.employee_id = e.id
                 LEFT JOIN employees ap ON la.approved_by = ap.id
                 WHERE {$whereClause}
                 ORDER BY la.created_at DESC",
                $params
            );
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'leaves' => $leaves]);
            exit;
        }
        
        if ($action === 'filter_approvals') {
            $status = $_GET['status'] ?? 'Pending';
            $departmentId = $_GET['department_id'] ?? '';
            $search = $_GET['search'] ?? '';
            
            // Scope approvals to employees this user is authorized to approve
            // (mirrors modules/leaves.php approvals-tab logic)
            $approvableEmployeeIds = [];
            if (hasPermission('leaves', 'approve')) {
                $approvableEmployeeIds = getApprovableEmployeeIds($user['id']);
            }
            $approvableIdsStr = !empty($approvableEmployeeIds) ? implode(',', $approvableEmployeeIds) : '0';
            
            $where = ["la.status = ?", "la.employee_id IN ({$approvableIdsStr})", "la.employee_id != ?"];
            $params = [$status, $user['id']];
            
            if (!empty($departmentId)) {
                $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = la.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
                $params[] = $departmentId;
            }
            
            if (!empty($search)) {
                $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $leaves = $db->fetchAll(
                "SELECT la.*, e.first_name, e.last_name, e.employee_id, lt.name as leave_type_name
                 FROM leave_applications la
                 JOIN employees e ON la.employee_id = e.id
                 JOIN leave_types lt ON la.leave_type_id = lt.id
                 WHERE {$whereClause}
                 ORDER BY la.created_at DESC",
                $params
            );
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'leaves' => $leaves]);
            exit;
        }
        
        if ($action === 'filter_balances') {
            $departmentId = $_GET['department_id'] ?? '';
            $employeeSearch = $_GET['search'] ?? '';
            $leaveTypeId = $_GET['leave_type_id'] ?? '';
            $year = $_GET['year'] ?? date('Y');
            
            $where = ["lb.year = ?"];
            $params = [$year];
            
            if (!empty($departmentId)) {
                $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = lb.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
                $params[] = $departmentId;
            }
            
            if (!empty($employeeSearch)) {
                $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
                $searchTerm = '%' . $employeeSearch . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($leaveTypeId)) {
                $where[] = "lb.leave_type_id = ?";
                $params[] = $leaveTypeId;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $balances = $db->fetchAll(
                "SELECT lb.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id,
                 (SELECT d.name FROM departments d JOIN employee_departments ed ON d.id = ed.department_id WHERE ed.employee_id = lb.employee_id AND ed.is_primary = 1 LIMIT 1) as department_name
                 FROM leave_balances lb
                 JOIN leave_types lt ON lb.leave_type_id = lt.id
                 JOIN employees e ON lb.employee_id = e.id
                 WHERE {$whereClause}
                 ORDER BY e.first_name, e.last_name, lt.name",
                $params
            );
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'balances' => $balances]);
            exit;
        }
    }

    // ---- Delete leave type ----
    if ($method === 'POST' && $action === 'delete_type') {
        if (!hasPermission('leave_types', 'delete')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        
        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave type ID is required']);
            exit;
        }
        
        // Get leave type info for audit
        $leaveType = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$id]);
        if (!$leaveType) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Leave type not found']);
            exit;
        }
        
        // Check if leave type is in use (for warning message only)
        $inUse = $db->fetchOne("SELECT COUNT(*) as count FROM leave_applications WHERE leave_type_id = ?", [$id]);
        $usageCount = $inUse['count'] ?? 0;
        
        $db->beginTransaction();
        
        try {
            // First, update leave applications to preserve the leave type name in a separate field
            if ($usageCount > 0) {
                // Check if backup column exists
                $columnExists = $db->fetchOne(
                    "SHOW COLUMNS FROM leave_applications LIKE 'leave_type_name_backup'"
                );
                
                if ($columnExists) {
                    $db->query(
                        "UPDATE leave_applications 
                         SET leave_type_name_backup = (SELECT name FROM leave_types WHERE id = ?)
                         WHERE leave_type_id = ? AND leave_type_name_backup IS NULL",
                        [$id, $id]
                    );
                }
            }
            
            // Delete associated balance records (do this manually to ensure they're deleted)
            try {
                $db->delete('leave_balances', 'leave_type_id = ?', [$id]);
            } catch (Exception $e) {
                // Ignore if already deleted or table issue
            }
            
            // Delete leave type assignments if table exists
            try {
                $db->delete('leave_type_assignments', 'leave_type_id = ?', [$id]);
            } catch (Exception $e) {
                // Table might not exist, ignore
            }
            
            // Actually delete the leave type record
            // This will cascade delete leave_balances and set leave_type_id to NULL in leave_applications
            $db->delete('leave_types', 'id = ?', [$id]);
            
            $db->commit();
            
            $message = $usageCount > 0 
                ? "Leave type deleted successfully. Historical data preserved for {$usageCount} records." 
                : "Leave type deleted successfully.";
            
            logAudit('leave_types', $id, 'delete', null, [
                'name' => $leaveType['name'],
                'usage_count' => $usageCount
            ]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error deleting leave type: ' . $e->getMessage()]);
            exit;
        }
    }

    // ---- Edit leave balance (Super Admin and HR Manager only) ----
    if ($method === 'POST' && $action === 'edit_balance') {
        // Check if user is Super Admin (hierarchy_level 1) or HR Manager (hierarchy_level 3)
        if ($user['hierarchy_level'] != 1 && $user['hierarchy_level'] != 3) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied. Only Super Admin and HR Manager can edit balances.'], 403);
            exit;
        }
        
        $balanceId = (int) ($data['balance_id'] ?? 0);
        $newTotalAccrued = (float) ($data['total_accrued'] ?? 0);
        $newCarriedForward = (float) ($data['carried_forward'] ?? 0);
        $reason = $data['reason'] ?? 'Manual balance adjustment';
        
        if (!$balanceId) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Balance ID is required']);
            exit;
        }
        
        // Get current balance for audit
        $currentBalance = $db->fetchOne("SELECT * FROM leave_balances WHERE id = ?", [$balanceId]);
        if (!$currentBalance) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Balance record not found']);
            exit;
        }
        
        $before = [
            'total_accrued' => $currentBalance['total_accrued'],
            'carried_forward' => $currentBalance['carried_forward'],
            'balance' => $currentBalance['balance']
        ];
        
        // Update balance
        $db->update('leave_balances',
            [
                'total_accrued' => $newTotalAccrued,
                'carried_forward' => $newCarriedForward
            ],
            'id = ?',
            [$balanceId]
        );
        
        $after = [
            'total_accrued' => $newTotalAccrued,
            'carried_forward' => $newCarriedForward,
            'balance' => $newTotalAccrued + $newCarriedForward - $currentBalance['total_used']
        ];
        
        // Log audit
        logAudit('leave_balances', $balanceId, 'update', $before, array_merge($after, ['reason' => $reason]));
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Balance updated successfully', 'new_balance' => $after['balance']]);
        exit;
    }

    // ---- Edit employee balances (Super Admin and HR Manager only) ----
    if ($method === 'POST' && $action === 'edit_employee_balances') {
        // Check if user is Super Admin (hierarchy_level 1) or HR Manager (hierarchy_level 3)
        if ($user['hierarchy_level'] != 1 && $user['hierarchy_level'] != 3) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied. Only Super Admin and HR Manager can edit balances.'], 403);
            exit;
        }
        
        $employeeIdString = $data['employee_id'] ?? '';
        $balanceUpdates = $data['balance_updates'] ?? [];
        $reason = $data['reason'] ?? 'Manual balance adjustment';
        
        if (empty($employeeIdString)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
            exit;
        }
        
        // Get the actual numeric employee ID from the employee_id string
        $employee = $db->fetchOne("SELECT id FROM employees WHERE employee_id = ?", [$employeeIdString]);
        if (!$employee) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
            exit;
        }
        
        $employeeId = $employee['id'];
        
        if (empty($balanceUpdates) || !is_array($balanceUpdates)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Balance updates are required']);
            exit;
        }
        
        if (empty($reason)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Reason is required']);
            exit;
        }
        
        $currentYear = date('Y');
        $updatedCount = 0;
        
        $db->beginTransaction();
        
        try {
            foreach ($balanceUpdates as $update) {
                $leaveTypeId = (int) ($update['leave_type_id'] ?? 0);
                $newBalance = (int) ($update['new_balance'] ?? 0);
                $empIdString = $update['employee_id'] ?? '';
                
                if (!$leaveTypeId || empty($empIdString)) {
                    continue;
                }
                
                // Get the numeric employee ID for this balance update
                $empRecord = $db->fetchOne("SELECT id FROM employees WHERE employee_id = ?", [$empIdString]);
                if (!$empRecord) {
                    continue; // Skip if employee not found
                }
                $empId = $empRecord['id'];
                
                // Get current balance for audit
                $currentBalance = $db->fetchOne(
                    "SELECT * FROM leave_balances 
                     WHERE employee_id = ? AND leave_type_id = ? AND year = ?",
                    [$empId, $leaveTypeId, $currentYear]
                );
                
                if (!$currentBalance) {
                    // Create new balance record if it doesn't exist
                    $balanceId = $db->insert('leave_balances', [
                        'employee_id' => $employeeId,
                        'leave_type_id' => $leaveTypeId,
                        'year' => $currentYear,
                        'total_accrued' => $newBalance,
                        'total_used' => 0,
                        'carried_forward' => 0
                    ]);
                    
                    $before = [
                        'total_accrued' => 0,
                        'carried_forward' => 0,
                        'balance' => 0
                    ];
                    
                    $after = [
                        'total_accrued' => $newBalance,
                        'carried_forward' => 0,
                        'balance' => $newBalance
                    ];
                    
                    logAudit('leave_balances', $balanceId, 'create', $before, array_merge($after, ['reason' => $reason]));
                } else {
                    $before = [
                        'total_accrued' => $currentBalance['total_accrued'],
                        'carried_forward' => $currentBalance['carried_forward'],
                        'balance' => $currentBalance['balance']
                    ];
                    
                    // Calculate new total_accrued based on the desired balance
                    // new_balance = total_accrued + carried_forward - total_used
                    // We're keeping total_used and carried_forward the same, adjusting total_accrued
                    $newTotalAccrued = $newBalance + $currentBalance['total_used'] - $currentBalance['carried_forward'];
                    
                    // Update balance
                    $db->update('leave_balances',
                        ['total_accrued' => $newTotalAccrued],
                        'id = ?',
                        [$currentBalance['id']]
                    );
                    
                    $after = [
                        'total_accrued' => $newTotalAccrued,
                        'carried_forward' => $currentBalance['carried_forward'],
                        'balance' => $newBalance
                    ];
                    
                    logAudit('leave_balances', $currentBalance['id'], 'update', $before, array_merge($after, ['reason' => $reason]));
                }
                
                $updatedCount++;
            }
            
            $db->commit();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Updated {$updatedCount} balance(s) successfully"]);
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error updating balances: ' . $e->getMessage()]);
            exit;
        }
    }

    // ---- Get single balance for editing ----
    if ($method === 'GET' && isset($_GET['balance_id'])) {
        // Check if user is Super Admin (hierarchy_level 1) or HR Manager (hierarchy_level 3)
        if ($user['hierarchy_level'] != 1 && $user['hierarchy_level'] != 3) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied. Only Super Admin and HR Manager can edit balances.'], 403);
            exit;
        }
        
        $balanceId = (int) $_GET['balance_id'];
        $balance = $db->fetchOne(
            "SELECT lb.*, lt.name as leave_type_name, e.first_name, e.last_name, e.employee_id
             FROM leave_balances lb
             JOIN leave_types lt ON lb.leave_type_id = lt.id
             JOIN employees e ON lb.employee_id = e.id
             WHERE lb.id = ?",
            [$balanceId]
        );
        
        header('Content-Type: application/json');
        echo json_encode($balance ? ['success' => true, 'balance' => $balance] : ['success' => false, 'message' => 'Balance not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}


