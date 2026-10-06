<?php
/**
 * Settings API
 * Handles roles, shifts, break types, holiday calendars, permissions, settings and password
 * Core PHP HRMS Application
 * Section 17 - Settings module
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/leave_assignment.php';
require_once __DIR__ . '/../includes/break_assignment.php';
require_once __DIR__ . '/../includes/attendance_threshold_service.php';
require_once __DIR__ . '/../includes/work_pattern_service.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();

    // ---- Helpers ----
    $saveSetting = function ($key, $value) use ($db) {
        $existing = $db->fetchOne("SELECT id FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $db->query("UPDATE settings SET `value` = ? WHERE `key` = ?", [$value, $key]);
        } else {
            $db->query("INSERT INTO settings (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        }
    };
$action = $_GET['action'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    // ============ GET: permission matrix ============
    if (isset($_GET['get_matrix'])) {
        $permissions = new Permissions();
        $matrix = $permissions->getPermissionMatrix();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'matrix' => $matrix]);
        exit;
    }

    // ============ GET single records ============
    if (isset($_GET['role_id'])) {
        $row = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [(int) $_GET['role_id']]);
        echo json_encode($row ? ['success' => true, 'role' => $row] : ['success' => false, 'message' => 'Role not found']);
        exit;
    }
    if (isset($_GET['shift_id'])) {
        $row = $db->fetchOne("SELECT * FROM shifts WHERE id = ?", [(int) $_GET['shift_id']]);
        echo json_encode($row ? ['success' => true, 'shift' => $row] : ['success' => false, 'message' => 'Shift not found']);
        exit;
    }
    if (isset($_GET['break_id'])) {
        $row = $db->fetchOne("SELECT * FROM break_types WHERE id = ?", [(int) $_GET['break_id']]);
        echo json_encode($row ? ['success' => true, 'break' => $row] : ['success' => false, 'message' => 'Break not found']);
        exit;
    }
    if (isset($_GET['cal_id'])) {
        $cal = $db->fetchOne("SELECT * FROM holiday_calendars WHERE id = ?", [(int) $_GET['cal_id']]);
        if (!$cal) { echo json_encode(['success' => false, 'message' => 'Calendar not found']); exit; }
        $holidays = $db->fetchAll("SELECT * FROM holidays WHERE holiday_calendar_id = ? ORDER BY date", [(int) $_GET['cal_id']]);
        $cal['holidays'] = $holidays;
        echo json_encode(['success' => true, 'calendar' => $cal]);
        exit;
    }
    if (isset($_GET['type_id'])) {
        $row = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [(int) $_GET['type_id']]);
        echo json_encode($row ? ['success' => true, 'type' => $row] : ['success' => false, 'message' => 'Leave type not found']);
        exit;
    }
    if (isset($_GET['work_pattern_id'])) {
        $row = $db->fetchOne("SELECT * FROM work_patterns WHERE id = ?", [(int) $_GET['work_pattern_id']]);
        echo json_encode($row ? ['success' => true, 'work_pattern' => $row] : ['success' => false, 'message' => 'Work pattern not found']);
        exit;
    }
    if (isset($_GET['get_work_patterns'])) {
        $workPatternService = new WorkPatternService();
        $patterns = $workPatternService->getAllWorkPatterns();
        echo json_encode(['success' => true, 'work_patterns' => $patterns]);
        exit;
    }
    if (isset($_GET['get_employee_work_pattern'])) {
        $employeeId = (int) $_GET['employee_id'];
        $date = $_GET['date'] ?? date('Y-m-d');
        $workPatternService = new WorkPatternService();
        $pattern = $workPatternService->getEmployeeWorkPattern($employeeId, $date);
        echo json_encode($pattern ? ['success' => true, 'work_pattern' => $pattern] : ['success' => false, 'message' => 'Work pattern not found']);
        exit;
    }
    if (isset($_GET['get_work_pattern_history'])) {
        $employeeId = (int) $_GET['employee_id'];
        $workPatternService = new WorkPatternService();
        $history = $workPatternService->getAssignmentHistory($employeeId);
        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }
    if (isset($_GET['get_settings'])) {
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings");
        echo json_encode(['success' => true, 'settings' => array_column($rows, 'value', 'key')]);
        exit;
    }
// ============ POST handlers ============
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = $data['action'] ?? '';
        $thresholdService = new AttendanceThresholdService();

        // ---- Save role ----
        if ($postAction === 'save_role') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $roleData = [
                'name' => trim($data['name'] ?? ''),
                'hierarchy_level' => (int) ($data['hierarchy_level'] ?? 10),
                'description' => $data['description'] ?? null,
                'is_active' => !empty($data['is_active']) ? 1 : 0
            ];
            if (empty($roleData['name'])) { echo json_encode(['success' => false, 'message' => 'Role name is required']); exit; }
            if ($id) {
                $db->update('roles', $roleData, 'id = ?', [$id]);
                $newId = $id;
            } else {
                $newId = $db->insert('roles', $roleData);
            }
            logAudit('roles', $newId, $id ? 'update' : 'create', null, $roleData);
            echo json_encode(['success' => true, 'role_id' => $newId, 'message' => 'Role saved successfully']);
            exit;
        }

        // ---- Save shift ----
        if ($postAction === 'save_shift') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $shiftData = [
                'name' => trim($data['name'] ?? ''),
                'start_time' => $data['start_time'] ?? '09:00:00',
                'end_time' => $data['end_time'] ?? '18:00:00',
                'grace_in_minutes' => (int) ($data['grace_in_minutes'] ?? 120),
                'grace_out_minutes' => (int) ($data['grace_out_minutes'] ?? 180),
                'half_day_threshold_minutes' => (int) ($data['half_day_threshold_minutes'] ?? 240),
                'full_day_minutes' => (int) ($data['full_day_minutes'] ?? 480),
                'description' => $data['description'] ?? null,
                'is_active' => !empty($data['is_active']) ? 1 : 0
            ];
            if (empty($shiftData['name'])) { echo json_encode(['success' => false, 'message' => 'Shift name is required']); exit; }
            if ($id) {
                $db->update('shifts', $shiftData, 'id = ?', [$id]);
                $newId = $id;
            } else {
                $newId = $db->insert('shifts', $shiftData);
            }
            logAudit('shifts', $newId, $id ? 'update' : 'create', null, $shiftData);
            echo json_encode(['success' => true, 'shift_id' => $newId, 'message' => 'Shift saved successfully']);
            exit;
        }
// ---- Save centralized attendance thresholds (Present / Half Day) ----
        if ($postAction === 'save_centralized_thresholds') {
            if (!hasPermission('settings', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            
            $thresholdData = [
                'present_threshold_minutes' => (int) ($data['present_threshold_minutes'] ?? 0),
                'half_day_threshold_minutes' => (int) ($data['half_day_threshold_minutes'] ?? 0)
            ];
            
            // Validate thresholds
            $validation = $thresholdService->validateThresholds($thresholdData);
            if (!$validation['valid']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                exit;
            }
            
            $reason = $data['reason'] ?? null;
            $userId = $user['id'];
            
            try {
                if ($thresholdService->updateThresholds($thresholdData, $userId, $reason)) {
                    logAudit('attendance_settings', 1, 'update', null, $thresholdData);
                    echo json_encode(['success' => true, 'message' => 'Centralized attendance thresholds saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save threshold settings - update returned false']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
        }

// ---- Save per-shift attendance thresholds (Present / Half Day) ----
        if ($postAction === 'save_shift_thresholds') {
            if (!hasPermission('settings', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied'], 403); exit; }
            $shiftId = (int) ($data['shift_id'] ?? 0);
            if (!$shiftId) { echo json_encode(['success' => false, 'message' => 'Shift ID is required']); exit; }

            $thresholdData = [
                'present_threshold_minutes' => (int) ($data['present_threshold_minutes'] ?? 0),
                'half_day_threshold_minutes' => (int) ($data['half_day_threshold_minutes'] ?? 0)
            ];

            // Validate with the same rules as the organization-wide thresholds
            $validation = $thresholdService->validateThresholds($thresholdData);
            if (!$validation['valid']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                exit;
            }

            $db->update('shifts', [
                'present_threshold_minutes' => $thresholdData['present_threshold_minutes'],
                'half_day_threshold_minutes' => $thresholdData['half_day_threshold_minutes']
            ], 'id = ?', [$shiftId]);

            logAudit('shifts', $shiftId, 'update', null, [
                'shift_id' => $shiftId,
                'present_threshold_minutes' => $thresholdData['present_threshold_minutes'],
                'half_day_threshold_minutes' => $thresholdData['half_day_threshold_minutes']
            ]);

            echo json_encode(['success' => true, 'message' => 'Thresholds saved successfully']);
            exit;
        }
        // ---- Save break type ----
        if ($postAction === 'save_break') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $assignTo = !empty($data['assign_to']) ? $data['assign_to'] : 'all_employees';
            $isUnlimited = !empty($data['is_unlimited']) ? 1 : 0;

            // Multiple targets (e.g. comma-separated shift ids) can be provided for
            // 'shift' assignments. target_ids stores the resolved list; target_id keeps
            // the first id for backward compatibility with existing readers.
            $targetIdsString = '';
            if (!empty($data['target_ids'])) {
                $rawTargets = is_array($data['target_ids']) ? $data['target_ids'] : explode(',', (string) $data['target_ids']);
                $cleanTargets = [];
                foreach ($rawTargets as $tid) {
                    $tid = trim((string) $tid);
                    if ($tid !== '' && is_numeric($tid) && (int) $tid > 0) {
                        $cleanTargets[] = (string) (int) $tid;
                    }
                }
                $targetIdsString = implode(',', $cleanTargets);
            }

            $singleTargetId = null;
            if ($assignTo === 'shift') {
                // The first selected shift becomes the legacy single target id
                if ($targetIdsString !== '') {
                    $firstTarget = explode(',', $targetIdsString);
                    $singleTargetId = (int) $firstTarget[0];
                }
            } elseif (!empty($data['target_id'])) {
                $singleTargetId = (int) $data['target_id'];
            }

            $breakData = [
                'name' => trim($data['name'] ?? ''),
                // Unlimited breaks (Meeting, Official, Training, ...) have no fixed duration:
                // durations are cleared and the is_unlimited flag marks them as time-unlimited.
                'default_duration_minutes' => $isUnlimited ? 0 : (int) ($data['default_duration_minutes'] ?? 30),
                'min_duration_minutes' => $isUnlimited ? null : (!empty($data['min_duration_minutes']) ? (int) $data['min_duration_minutes'] : null),
                'max_duration_minutes' => $isUnlimited ? null : (!empty($data['max_duration_minutes']) ? (int) $data['max_duration_minutes'] : null),
                'is_unlimited' => $isUnlimited,
                'is_paid' => !empty($data['is_paid']) ? 1 : 0,
                'deduct_from_work_time' => !empty($data['deduct_from_work_time']) ? 1 : 0,
                'description' => $data['description'] ?? null,
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'assign_to' => $assignTo,
                'target_id' => $singleTargetId,
                'target_ids' => ($assignTo === 'shift' && $targetIdsString !== '') ? $targetIdsString : null
            ];
            if (empty($breakData['name'])) { echo json_encode(['success' => false, 'message' => 'Break name is required']); exit; }
            if ($id) {
                $db->update('break_types', $breakData, 'id = ?', [$id]);
                $newId = $id;
            } else {
                $newId = $db->insert('break_types', $breakData);
            }
            logAudit('break_types', $newId, $id ? 'update' : 'create', null, $breakData);
            echo json_encode(['success' => true, 'break_id' => $newId, 'message' => 'Break type saved successfully']);
            exit;
        }

        // ---- Delete break type ----
        if ($postAction === 'delete_break') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Break type ID required']); exit; }
            
            // Check if break type is used in any attendance records
            $usageCount = $db->fetchOne("SELECT COUNT(*) as count FROM attendance_punches WHERE break_type_id = ?", [$id]);
            if ($usageCount && $usageCount['count'] > 0) {
                // Mark as inactive instead of deleting
                $db->update('break_types', ['is_active' => 0], 'id = ?', [$id]);
                logAudit('break_types', $id, 'deactivate', null, null);
                echo json_encode(['success' => true, 'message' => 'Break type deactivated (it is used in attendance records)']);
                exit;
            }
            
            // Safe to delete
            $db->delete('break_types', 'id = ?', [$id]);
            logAudit('break_types', $id, 'delete', null, null);
            echo json_encode(['success' => true, 'message' => 'Break type deleted successfully']);
            exit;
        }

        // ---- Save holiday calendar (with holidays) ----
        if ($postAction === 'save_holiday_calendar') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $calData = [
                'name' => trim($data['name'] ?? ''),
                'description' => $data['description'] ?? null,
                'year' => (int) ($data['year'] ?? date('Y')),
                'is_active' => !empty($data['is_active']) ? 1 : 0
            ];
            if (empty($calData['name'])) { echo json_encode(['success' => false, 'message' => 'Calendar name is required']); exit; }
            if ($id) {
                $db->update('holiday_calendars', $calData, 'id = ?', [$id]);
                $calId = $id;
            } else {
                $calId = $db->insert('holiday_calendars', $calData);
            }
            if (isset($data['holidays']) && is_array($data['holidays'])) {
                $db->delete('holidays', 'holiday_calendar_id = ?', [$calId]);
                foreach ($data['holidays'] as $h) {
                    if (empty($h['date']) || empty($h['name'])) continue;
                    $db->insert('holidays', [
                        'holiday_calendar_id' => $calId,
                        'date' => $h['date'],
                        'name' => $h['name'],
                        'type' => $h['type'] ?? 'Fixed',
                        'description' => $h['description'] ?? null
                    ]);
                }
            }
            logAudit('holiday_calendars', $calId, $id ? 'update' : 'create', null, $calData);
            echo json_encode(['success' => true, 'calendar_id' => $calId, 'message' => 'Holiday calendar saved successfully']);
            exit;
        }
// ---- Save leave type ----
        if ($postAction === 'save_type') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            // Guard with permission (settings or leaves update)
            if (!hasPermission('leaves', 'update') && !hasPermission('settings', 'update')) {
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
                exit;
            }
            $assignTo = !empty($data['assign_to']) ? $data['assign_to'] : 'all_employees';
            // For new types default to active unless explicitly set, so they appear in the Leaves module
            $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : ($id ? 1 : 1);

            // Map modern assign_to to the legacy applicable_to column for consistency
            if ($assignTo === 'department') {
                $applicableTo = 'specific_departments';
            } elseif ($assignTo === 'role') {
                $applicableTo = 'specific_roles';
            } elseif ($assignTo === 'user') {
                $applicableTo = 'specific_users';
            } else {
                $applicableTo = 'all';
            }

            $typeData = [
                'name' => trim($data['name'] ?? ''),
                'calculation_basis' => $data['calculation_basis'] ?? 'working_days',
                'accrual_method' => $data['accrual_method'] ?? 'fixed_annual',
                'max_days_per_year' => (int) ($data['max_days_per_year'] ?? 0),
                'min_days_per_request' => (float) ($data['min_days_per_request'] ?? 0.5),
                'carry_forward' => !empty($data['carry_forward']) ? 1 : 0,
                'max_carry_forward_days' => (int) ($data['max_carry_forward_days'] ?? 0),
                'applicable_to' => $applicableTo,
                'assign_to' => $assignTo,
                'target_id' => !empty($data['target_id']) ? (int) $data['target_id'] : null,
                'is_paid' => !empty($data['is_paid']) ? 1 : 0,
                'requires_approval' => !empty($data['requires_approval']) ? 1 : 0,
                'description' => $data['description'] ?? null,
                'is_active' => $isActive,
                // Monthly accrual fields
                'monthly_accrual_enabled' => !empty($data['monthly_accrual_enabled']) ? 1 : 0,
                'monthly_accrual_days' => (float) ($data['monthly_accrual_days'] ?? 0),
                'accrual_timing' => $data['accrual_timing'] ?? 'pay_cycle_end',
                'accrual_day_of_month' => !empty($data['accrual_day_of_month']) ? (int) $data['accrual_day_of_month'] : null
            ];
            if (empty($typeData['name'])) { echo json_encode(['success' => false, 'message' => 'Leave type name is required']); exit; }
            if ($id) {
                $db->update('leave_types', $typeData, 'id = ?', [$id]);
                $newId = $id;
            } else {
                $newId = $db->insert('leave_types', $typeData);
            }
            // Provision balances for all employees this type is assigned to (non-destructive)
            $typeData['id'] = $newId;
            sync_leave_type_balances($db, $typeData);
            logAudit('leave_types', $newId, $id ? 'update' : 'create', null, $typeData);
            echo json_encode(['success' => true, 'type_id' => $newId, 'message' => 'Leave type saved successfully']);
            exit;
        }

        // ---- Save work pattern ----
        if ($postAction === 'save_work_pattern') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $patternData = [
                'name' => trim($data['name'] ?? ''),
                'description' => $data['description'] ?? null,
                'monday' => $data['monday'] ?? 'working',
                'tuesday' => $data['tuesday'] ?? 'working',
                'wednesday' => $data['wednesday'] ?? 'working',
                'thursday' => $data['thursday'] ?? 'working',
                'friday' => $data['friday'] ?? 'working',
                'saturday' => $data['saturday'] ?? 'working',
                'sunday' => $data['sunday'] ?? 'weekly_off',
                'saturday_rule' => $data['saturday_rule'] ?? null,
                'is_active' => !empty($data['is_active']) ? 1 : 0
            ];
            if (empty($patternData['name'])) { echo json_encode(['success' => false, 'message' => 'Work pattern name is required']); exit; }
            if ($id) {
                $db->update('work_patterns', $patternData, 'id = ?', [$id]);
                $newId = $id;
            } else {
                $newId = $db->insert('work_patterns', $patternData);
            }
            logAudit('work_patterns', $newId, $id ? 'update' : 'create', null, $patternData);
            echo json_encode(['success' => true, 'work_pattern_id' => $newId, 'message' => 'Work pattern saved successfully']);
            exit;
        }

        // ---- Delete work pattern ----
        if ($postAction === 'delete_work_pattern') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Work pattern ID is required']); exit; }
            $db->delete('work_patterns', 'id = ?', [$id]);
            logAudit('work_patterns', $id, 'delete', null, ['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Work pattern deleted successfully']);
            exit;
        }

        // ---- Assign work pattern to employee ----
        if ($postAction === 'assign_work_pattern') {
            $employeeId = (int) ($data['employee_id'] ?? 0);
            $patternId = (int) ($data['work_pattern_id'] ?? 0);
            $effectiveFrom = $data['effective_from'] ?? date('Y-m-d');
            $reason = $data['reason'] ?? null;
            
            if (!$employeeId || !$patternId) {
                echo json_encode(['success' => false, 'message' => 'Employee ID and Work Pattern ID are required']);
                exit;
            }
            
            $workPatternService = new WorkPatternService();
            $result = $workPatternService->assignWorkPattern($employeeId, $patternId, $effectiveFrom, $user['id'], $reason);
            
            if ($result) {
                logAudit('employees', $employeeId, 'update', null, ['work_pattern_id' => $patternId]);
                echo json_encode(['success' => true, 'message' => 'Work pattern assigned successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to assign work pattern']);
            }
            exit;
        }

        // ---- Bulk assign work pattern ----
        if ($postAction === 'bulk_assign_work_pattern') {
            $patternId = (int) ($data['work_pattern_id'] ?? 0);
            $effectiveFrom = $data['effective_from'] ?? date('Y-m-d');
            $employeeIds = $data['employee_ids'] ?? [];
            $reason = $data['reason'] ?? null;
            
            if (!$patternId || empty($employeeIds)) {
                echo json_encode(['success' => false, 'message' => 'Work Pattern ID and Employee IDs are required']);
                exit;
            }
            
            $workPatternService = new WorkPatternService();
            $successCount = 0;
            $failCount = 0;
            
            foreach ($employeeIds as $employeeId) {
                $result = $workPatternService->assignWorkPattern((int)$employeeId, $patternId, $effectiveFrom, $user['id'], $reason);
                if ($result) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "Assigned to $successCount employees, failed for $failCount employees"
            ]);
            exit;
        }

        // ---- Save settings (general / attendance / leave / employee id) ----
        if ($postAction === 'save_settings') {
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            foreach ($settings as $key => $value) {
                $saveSetting($key, (string) $value);
            }
            logAudit('settings', 0, 'update', null, $settings);
            echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
            exit;
        }

        // ---- Save single permission cell (matrix) ----
        if ($postAction === 'save_permission') {
            $roleId = (int) ($data['role_id'] ?? 0);
            $moduleId = (int) ($data['module_id'] ?? 0);
            $permAction = $data['perm_action'] ?? '';
            $allowed = !empty($data['allowed']) ? 1 : 0;
            $scope = $data['scope'] ?? 'self';
            if (!$roleId || !$moduleId || !$permAction) {
                echo json_encode(['success' => false, 'message' => 'Role, module and action are required']); exit;
            }
            $permissions = new Permissions();
            if ($allowed) {
                $permissions->grantToRole($roleId, $moduleId, $permAction, $scope);
            } else {
                $permissions->revokeFromRole($roleId, $moduleId, $permAction, $scope);
            }
            logAudit('permissions', 0, $allowed ? 'grant' : 'revoke', null, ['role_id' => $roleId, 'module_id' => $moduleId, 'action' => $permAction, 'scope' => $scope]);
            echo json_encode(['success' => true, 'message' => 'Permission updated']);
            exit;
        }

        // ---- Change password ----
        if ($postAction === 'change_password') {
            $result = $auth->changePassword(
                $user['id'],
                $data['current_password'] ?? '',
                $data['new_password'] ?? ''
            );
            echo json_encode($result);
            exit;
        }

        // ---- Save notification preferences ----
        if ($postAction === 'save_notification_preferences') {
            $enabled = isset($data['enabled']) ? (int) $data['enabled'] : 1;
            $typesEnabled = $data['types_enabled'] ?? '{}';
            $browserNotifications = isset($data['browser_notifications']) ? (int) $data['browser_notifications'] : 0;
            $soundEnabled = isset($data['sound_enabled']) ? (int) $data['sound_enabled'] : 1;

            try {
                // Check if user already has preferences
                $existing = $db->fetchOne(
                    "SELECT id FROM user_notification_preferences WHERE user_id = ?",
                    [$user['id']]
                );

                if ($existing) {
                    $db->update(
                        'user_notification_preferences',
                        [
                            'enabled' => $enabled,
                            'types_enabled' => $typesEnabled,
                            'browser_notifications' => $browserNotifications,
                            'sound_enabled' => $soundEnabled
                        ],
                        'user_id = ?',
                        [$user['id']]
                    );
                } else {
                    $db->insert('user_notification_preferences', [
                        'user_id' => $user['id'],
                        'enabled' => $enabled,
                        'types_enabled' => $typesEnabled,
                        'browser_notifications' => $browserNotifications,
                        'sound_enabled' => $soundEnabled
                    ]);
                }

                echo json_encode(['success' => true, 'message' => 'Notification preferences saved successfully']);
            } catch (Exception $e) {
                // Table might not exist yet
                echo json_encode(['success' => false, 'message' => 'Notification preferences table not available. Please run the database migration.']);
            }
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}