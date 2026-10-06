<?php
/**
 * Attendance API Endpoint
 * Core PHP HRMS Application
 * Handles attendance CRUD, punches, and regularization actions
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/attendance.php';
    require_once __DIR__ . '/../includes/permissions.php';

    $db = Database::getInstance();
    $attendance = new Attendance();
    $permissions = new Permissions();
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];

    // ---- GET: calendar/day view (existing behavior) ----
    if ($method === 'GET' && !isset($_GET['record_id']) && !isset($_GET['punch_id']) && !isset($_GET['reg_id'])) {
        if (!hasPermission('attendance', 'read')) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        $date = $_GET['date'] ?? date('Y-m-d');
        
        // Get filter parameters
        $employeeFilter = $_GET['employee'] ?? '';
        $departmentFilter = $_GET['department'] ?? '';
        $statusFilter = $_GET['status'] ?? '';
        $searchFilter = $_GET['search'] ?? '';
        
        // Restrict results to the employees inside the current user's hierarchy
        // branch/scope so the day list matches the calendar cell counts.
        // Direct hierarchy branch scope (same rule as Reports):
        // privileged roles -> all employees; others -> direct reporting branch only.
        $scopedIds = $permissions->getDirectBranchScopeIds($user['id']);
        $scopedIdsStr = implode(',', $scopedIds) ?: '0';

        // Build filter conditions
        $whereConditions = [
            "ad.attendance_date = ?",
            "ad.employee_id IN ({$scopedIdsStr})"
        ];
        $params = [$date];
        
        if (!empty($employeeFilter)) {
            $whereConditions[] = "e.id = ?";
            $params[] = $employeeFilter;
        }
        
        if (!empty($departmentFilter)) {
            $whereConditions[] = "ed.department_id = ?";
            $params[] = $departmentFilter;
        }
        
        if (!empty($statusFilter)) {
            $whereConditions[] = "ad.status = ?";
            $params[] = $statusFilter;
        }
        
        if (!empty($searchFilter)) {
            $whereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
            $searchTerm = '%' . $searchFilter . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $employees = $db->fetchAll(
            "SELECT ad.*, e.id AS emp_id, e.first_name, e.last_name, e.employee_id, s.name as shift_name,
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
             WHERE {$whereClause}
             ORDER BY e.first_name, e.last_name",
            $params
        );
        // "Currently In" only applies when viewing today's date. For any other date we
        // keep the configured attendance rule status (Present/Absent/Half Day/etc.)
        // unchanged. "Today" is evaluated in the app's local timezone.
        $viewingToday = ($date === date('Y-m-d'));

        foreach ($employees as &$emp) {
            // An employee is "Currently In" only on today's view AND when they have an
            // active (open) clock-in session. Use getCurrentStatus() rather than the raw
            // last punch, because a still-clocked-in employee may have later punches
            // (e.g. BREAK_START/BREAK_END) that would otherwise hide the open session and
            // wrongly fall back to the rule-based status (Absent/Present/...).
            // NOTE: $emp['id'] is the attendance_daily row id (from ad.*); the employee's
            // numeric id is exposed as 'emp_id' (e.id), which is what getCurrentStatus()/punches use.
            $emp['is_currently_in'] = false;
            $emp['is_on_break'] = false;
            if ($viewingToday) {
                $currentStatus = $attendance->getCurrentStatus($emp['emp_id']);
                $emp['is_currently_in'] = ($currentStatus['status'] === 'in');
                // An employee on an active break is still "in" (open CLOCK_IN), but we
                // surface a separate flag so the popup can show "On Break" distinctly.
                $emp['is_on_break'] = ($currentStatus['on_break'] === true);
            }

            // Convert UTC times to local time for display
            if ($emp['first_in']) {
                $emp['first_in'] = utcToLocal($emp['first_in'], 'Y-m-d H:i:s');
            }
            if ($emp['last_out']) {
                $emp['last_out'] = utcToLocal($emp['last_out'], 'Y-m-d H:i:s');
            }
        }
        echo json_encode(['success' => true, 'employees' => $employees]);
        exit;
    }
// ---- GET: single attendance_daily record with punches ----
    if ($method === 'GET' && isset($_GET['record_id'])) {
        $id = (int) $_GET['record_id'];
        $record = $db->fetchOne(
            "SELECT ad.*, e.id AS emp_id, e.first_name, e.last_name, e.employee_id, s.name as shift_name, d.name as department_name,
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
             WHERE ad.id = ?",
            [$id]
        );
        if (!$record) { echo json_encode(['success' => false, 'message' => 'Record not found']); exit; }

        // Hierarchy scope check: only users whose branch includes this employee may view it.
        // This also guarantees Super Admin's own attendance is never visible to anyone else.
        // Direct hierarchy branch scope (same rule as Reports/calendar day view).
        $scopedIds = $permissions->getDirectBranchScopeIds($user['id']);
        if (!in_array((int) $record['emp_id'], array_map('intval', $scopedIds))) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        
        // Convert UTC times to local time for display
        if ($record['first_in']) {
            $record['first_in'] = utcToLocal($record['first_in'], 'Y-m-d H:i:s');
        }
        if ($record['last_out']) {
            $record['last_out'] = utcToLocal($record['last_out'], 'Y-m-d H:i:s');
        }
        
        $punches = $db->fetchAll(
            "SELECT * FROM attendance_punches WHERE employee_id = ? AND attendance_date = ? ORDER BY timestamp_utc",
            [$record['emp_id'], $record['attendance_date']]
        );
        
        // Convert punch times to local time
        foreach ($punches as &$punch) {
            if ($punch['timestamp_utc']) {
                $punch['timestamp_utc'] = utcToLocal($punch['timestamp_utc'], 'Y-m-d H:i:s');
            }
        }
        
        $record['punches'] = $punches;
        echo json_encode(['success' => true, 'record' => $record]);
        exit;
    }

    // ---- GET: single punch ----
    if ($method === 'GET' && isset($_GET['punch_id'])) {
        $id = (int) $_GET['punch_id'];
        $punch = $db->fetchOne(
            "SELECT ap.*, e.id AS emp_id, e.first_name, e.last_name, e.employee_id, s.name as shift_name
             FROM attendance_punches ap
             JOIN employees e ON ap.employee_id = e.id
             LEFT JOIN shifts s ON e.shift_id = s.id
             WHERE ap.id = ?",
            [$id]
        );
        
        // Convert UTC time to local time for display
        if ($punch && $punch['timestamp_utc']) {
            $punch['timestamp_utc'] = utcToLocal($punch['timestamp_utc'], 'Y-m-d H:i:s');
        }

        // Hierarchy scope check (Super Admin's punches are never visible to others)
        // Direct hierarchy branch scope (same rule as Reports/calendar day view).
        if ($punch) {
            $scopedIds = $permissions->getDirectBranchScopeIds($user['id']);
            if (!in_array((int) $punch['emp_id'], array_map('intval', $scopedIds))) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
        }
        
        echo json_encode($punch ? ['success' => true, 'punch' => $punch] : ['success' => false, 'message' => 'Punch not found']);
        exit;
    }

    // ---- GET: single regularization ----
    if ($method === 'GET' && isset($_GET['reg_id'])) {
        $id = (int) $_GET['reg_id'];
        $req = $db->fetchOne(
            "SELECT rr.*, e.id AS emp_id, e.first_name, e.last_name, e.employee_id, ap.first_name as approver_first, ap.last_name as approver_last
             FROM regularization_requests rr
             JOIN employees e ON rr.employee_id = e.id
             LEFT JOIN employees ap ON rr.approved_by = ap.id
             WHERE rr.id = ?",
            [$id]
        );
        // Hierarchy scope check (Super Admin's regularization is never visible to others)
        // Direct hierarchy branch scope (same rule as Reports/calendar day view).
        if ($req) {
            $scopedIds = $permissions->getDirectBranchScopeIds($user['id']);
            if (!in_array((int) $req['emp_id'], array_map('intval', $scopedIds))) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
        }
        echo json_encode($req ? ['success' => true, 'request' => $req] : ['success' => false, 'message' => 'Request not found']);
        exit;
    }
// ---- Parse body (JSON or form) ----
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'multipart/form-data') !== false || !empty($_POST)) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
    }
    $action = $data['action'] ?? '';

    if ($method === 'POST') {

        // ---- Bulk mark attendance (create punch records and compute status) ----
        if ($action === 'bulk_mark') {
            if (!hasPermission('attendance', 'create')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $employeeId = (int) ($data['employee_id'] ?? 0);
            // Support both legacy single-date and new separate in/out dates (night shift)
            $date = $data['date'] ?? '';
            $clockInDate = $data['clock_in_date'] ?? $date;
            $clockOutDate = $data['clock_out_date'] ?? $date;
            $clockIn = $data['clock_in'] ?? null;
            $clockOut = $data['clock_out'] ?? null;
            $remarks = $data['remarks'] ?? null;
            
            if (!$employeeId || !$clockInDate || !$clockOutDate) { echo json_encode(['success' => false, 'message' => 'Employee and dates required']); exit; }
            if (!$clockIn || !$clockOut) { echo json_encode(['success' => false, 'message' => 'Clock in and clock out times required']); exit; }
            if ($clockOutDate < $clockInDate) { echo json_encode(['success' => false, 'message' => 'Clock out date cannot be before clock in date']); exit; }
            
            // Get employee's shift for threshold settings
            $employee = $db->fetchOne(
                "SELECT e.shift_id, s.* FROM employees e LEFT JOIN shifts s ON e.shift_id = s.id WHERE e.id = ?",
                [$employeeId]
            );
            
            if (!$employee || !$employee['shift_id']) {
                echo json_encode(['success' => false, 'message' => 'Employee or shift not found']);
                exit;
            }
            
            $shift = $employee;
            
            // Create clock in punch record (convert local time to UTC)
            // attendance_date is the clock-in date (the shift's primary date)
            $clockInTimestamp = localToUtc($clockInDate . ' ' . $clockIn . ':00');
            $attendance->recordManualPunch($employeeId, 'CLOCK_IN', $clockInTimestamp, $clockInDate, 'manual');
            
            // Create clock out punch record (convert local time to UTC)
            // The punch's attendance_date is the shift's primary date (the in-date) so that
            // recomputeAttendanceDaily groups both punches together and computes correct duration
            // for night shifts that cross midnight (e.g. in 21:00 → out 06:00 next day).
            $clockOutTimestamp = localToUtc($clockOutDate . ' ' . $clockOut . ':00');
            $attendance->recordManualPunch($employeeId, 'CLOCK_OUT', $clockOutTimestamp, $clockInDate, 'manual');
            
            // Recompute daily attendance for the shift's date (this will apply threshold logic)
            $attendance->recomputeAttendanceDaily($employeeId, $clockInDate, $shift['shift_id']);
            
            // If clock-out is on a different day (night shift), also recompute that date to
            // clean up any stale attendance_daily / punches left from previous marks.
            if ($clockOutDate !== $clockInDate) {
                $attendance->recomputeAttendanceDaily($employeeId, $clockOutDate, $shift['shift_id']);
            }
            
            // Update remarks if provided
            $existing = $db->fetchOne(
                "SELECT id FROM attendance_daily WHERE employee_id = ? AND attendance_date = ?",
                [$employeeId, $clockInDate]
            );
            
            if ($existing && $remarks) {
                $db->update('attendance_daily', ['remarks' => $remarks], 'id = ?', [$existing['id']]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Attendance marked successfully']);
            exit;
        }

        // ---- Update attendance_daily (edit status/remarks) ----
        if ($action === 'update_record') {
            if (!hasPermission('attendance', 'update') && !hasPermission('attendance', 'create')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $id = (int) ($data['record_id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Record ID required']); exit; }
            $updates = [];
            if (isset($data['status'])) $updates['status'] = $data['status'];
            if (array_key_exists('remarks', $data)) $updates['remarks'] = $data['remarks'];
            if (empty($updates)) { echo json_encode(['success' => false, 'message' => 'Nothing to update']); exit; }
            $db->update('attendance_daily', $updates, 'id = ?', [$id]);
            logAudit('attendance_daily', $id, 'update', null, $updates);
            echo json_encode(['success' => true, 'message' => 'Attendance updated']);
            exit;
        }

        // ---- Delete attendance_daily record ----
        if ($action === 'delete_record') {
            if (!hasPermission('attendance', 'delete')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $id = (int) ($data['record_id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Record ID required']); exit; }
            $existing = $db->fetchOne("SELECT * FROM attendance_daily WHERE id = ?", [$id]);
            if (!$existing) { echo json_encode(['success' => false, 'message' => 'Record not found']); exit; }
            $db->delete('attendance_daily', 'id = ?', [$id]);
            logAudit('attendance_daily', $id, 'delete', $existing, null);
            echo json_encode(['success' => true, 'message' => 'Attendance record deleted']);
            exit;
        }

        // ---- Delete punch ----
        if ($action === 'delete_punch') {
            if (!hasPermission('attendance', 'delete')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $id = (int) ($data['punch_id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Punch ID required']); exit; }
            $punch = $db->fetchOne("SELECT * FROM attendance_punches WHERE id = ?", [$id]);
            if (!$punch) { echo json_encode(['success' => false, 'message' => 'Punch not found']); exit; }
            $db->delete('attendance_punches', 'id = ?', [$id]);
            logAudit('attendance_punches', $id, 'delete', $punch, null);
            $attendance->recomputeAttendanceDaily($punch['employee_id'], $punch['attendance_date'], null);
            echo json_encode(['success' => true, 'message' => 'Punch deleted']);
            exit;
        }
// ---- Create regularization request ----
        if ($action === 'create_regularization') {
            $employeeId = (int) ($data['employee_id'] ?? $user['id']);
            $date = $data['attendance_date'] ?? '';
            $reqIn = $data['requested_clock_in'] ?? null;
            $reqOut = $data['requested_clock_out'] ?? null;
            $reason = $data['reason'] ?? '';
            if (!$date || !$reason) { echo json_encode(['success' => false, 'message' => 'Date and reason required']); exit; }
            if ($employeeId !== (int) $user['id'] && !hasPermission('attendance', 'create')) {
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit;
            }
            $reqId = $db->insert('regularization_requests', [
                'employee_id' => $employeeId,
                'attendance_date' => $date,
                'requested_clock_in' => $reqIn ? date('Y-m-d H:i:s', strtotime($reqIn)) : null,
                'requested_clock_out' => $reqOut ? date('Y-m-d H:i:s', strtotime($reqOut)) : null,
                'reason' => $reason,
                'status' => 'Pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            logAudit('regularization_requests', $reqId, 'create', null, ['attendance_date' => $date]);
            echo json_encode(['success' => true, 'request_id' => $reqId, 'message' => 'Regularization request submitted']);
            exit;
        }

        // ---- Approve regularization ----
        if ($action === 'approve_regularization') {
            if (!hasPermission('attendance', 'approve')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Request ID required']); exit; }
            $req = $db->fetchOne("SELECT * FROM regularization_requests WHERE id = ?", [$id]);
            if (!$req || $req['status'] !== 'Pending') { echo json_encode(['success' => false, 'message' => 'Request not pending']); exit; }

            // Hierarchy check: approver must have equal or higher hierarchy level than requester
            // Super Admin bypass - can approve any regularization request
            if (!isSuperAdmin()) {
                $requester = $db->fetchOne(
                    "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
                    [$req['employee_id']]
                );
                if ($requester && $user['hierarchy_level'] > $requester['hierarchy_level']) {
                    echo json_encode(['success' => false, 'message' => 'You cannot approve regularization requests from higher hierarchy levels'], 403);
                    exit;
                }
            }
            // Insert corrected punches (flagged) - original punches never edited per P5
            if ($req['requested_clock_in']) {
                $db->insert('attendance_punches', [
                    'employee_id' => $req['employee_id'],
                    'event_type' => 'CLOCK_IN',
                    'timestamp_utc' => localToUtc($req['requested_clock_in']),
                    'source' => 'regularization',
                    'attendance_date' => $req['attendance_date'],
                    'is_review_flagged' => 1
                ]);
            }
            if ($req['requested_clock_out']) {
                $db->insert('attendance_punches', [
                    'employee_id' => $req['employee_id'],
                    'event_type' => 'CLOCK_OUT',
                    'timestamp_utc' => localToUtc($req['requested_clock_out']),
                    'source' => 'regularization',
                    'attendance_date' => $req['attendance_date'],
                    'is_review_flagged' => 1
                ]);
            }
            $shift = $db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$req['employee_id']]);
            $attendance->recomputeAttendanceDaily($req['employee_id'], $req['attendance_date'], $shift['shift_id'] ?? null);
            $db->update('regularization_requests', ['status' => 'Approved', 'approved_by' => $user['id'], 'approved_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            logAudit('regularization_requests', $id, 'approve', $req, ['status' => 'Approved']);
            echo json_encode(['success' => true, 'message' => 'Regularization approved and attendance updated']);
            exit;
        }

        // ---- Reject regularization ----
        if ($action === 'reject_regularization') {
            if (!hasPermission('attendance', 'approve')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Request ID required']); exit; }
            $req = $db->fetchOne("SELECT * FROM regularization_requests WHERE id = ?", [$id]);
            if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit; }

            // Hierarchy check: approver must have equal or higher hierarchy level than requester
            $requester = $db->fetchOne(
                "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
                [$req['employee_id']]
            );
            if ($requester && $user['hierarchy_level'] > $requester['hierarchy_level']) {
                echo json_encode(['success' => false, 'message' => 'You cannot reject regularization requests from higher hierarchy levels'], 403);
                exit;
            }
            $reason = $data['reason'] ?? '';
            $db->update('regularization_requests', ['status' => 'Rejected', 'rejection_reason' => $reason ?: null, 'approved_by' => $user['id'], 'approved_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            logAudit('regularization_requests', $id, 'reject', $req, ['status' => 'Rejected']);
            echo json_encode(['success' => true, 'message' => 'Regularization rejected']);
            exit;
        }

        // ---- Cancel regularization (by requesting employee) ----
        if ($action === 'cancel_regularization') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Request ID required']); exit; }
            $req = $db->fetchOne("SELECT * FROM regularization_requests WHERE id = ?", [$id]);
            if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit; }
            if ($req['status'] !== 'Pending') { echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled']); exit; }
            if ((int) $req['employee_id'] !== (int) $user['id'] && !hasPermission('attendance', 'approve')) {
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit;
            }
            $db->update('regularization_requests', ['status' => 'Cancelled'], 'id = ?', [$id]);
            logAudit('regularization_requests', $id, 'cancel', $req, ['status' => 'Cancelled']);
            echo json_encode(['success' => true, 'message' => 'Regularization request cancelled']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

