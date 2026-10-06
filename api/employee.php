<?php
/**
 * Employee API - Clean version for onboarding
 * Handles CRUD operations for employees
 */

// Disable all error reporting
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Output buffering
ob_start();

// Load required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/leave_assignment.php';

// Clear buffer
ob_clean();

// Helper function to send JSON response
function sendJsonResponse($success, $message, $data = []) {
    ob_clean();
    $response = array_merge(['success' => $success, 'message' => $message], $data);
    echo json_encode($response);
    ob_end_flush();
    exit;
}

header('Content-Type: application/json');

try {
    session_start();
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if (!hasPermission('employees', 'read')) {
                sendJsonResponse(false, 'Access denied');
            }
            if ($id) {
                $employee = $db->fetchOne(
                    "SELECT e.*, r.name as role_name, d.name as department_name, ed.department_id as department_id,
                     rm.first_name as reporting_manager_first_name, rm.last_name as reporting_manager_last_name,
                     rm.employee_id as reporting_manager_employee_id
                     FROM employees e 
                     LEFT JOIN roles r ON e.role_id = r.id 
                     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 
                     LEFT JOIN departments d ON ed.department_id = d.id 
                     LEFT JOIN employees rm ON e.reporting_manager_id = rm.id
                     WHERE e.id = ?",
                    [$id]
                );
                
                if ($employee) {
                    // Get secondary departments
                    $secondaryDepts = $db->fetchAll(
                        "SELECT department_id FROM employee_departments WHERE employee_id = ? AND is_primary = 0",
                        [$id]
                    );
                    $employee['secondary_departments'] = array_column($secondaryDepts, 'department_id');
                    
                    sendJsonResponse(true, 'Employee found', ['employee' => $employee]);
                } else {
                    sendJsonResponse(false, 'Employee not found');
                }
            } else {
                $employees = $db->fetchAll(
                    "SELECT e.id, e.first_name, e.last_name, e.employee_id, e.is_active,
                     r.name as role_name, d.name as department_name 
                     FROM employees e 
                     LEFT JOIN roles r ON e.role_id = r.id 
                     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 
                     LEFT JOIN departments d ON ed.department_id = d.id 
                     ORDER BY e.first_name, e.last_name"
                );
                sendJsonResponse(true, 'Employees retrieved', ['employees' => $employees]);
            }
            break;

        case 'POST':
            if (!hasPermission('employees', 'create')) {
                sendJsonResponse(false, 'Access denied');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Debug: Log incoming data
            error_log("POST Employee Data: " . print_r($data, true));
            
            $employeeId = $data['employee_id'] ?? 'EMP-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            
            if (empty($data['first_name']) || empty($data['last_name']) || empty($data['official_email'])) {
                sendJsonResponse(false, 'First name, last name and official email are required');
            }
            
            $existingEmail = $db->fetchOne("SELECT id FROM employees WHERE official_email = ?", [$data['official_email']]);
            if ($existingEmail) {
                sendJsonResponse(false, 'An employee with this official email already exists');
            }
            
            if (!empty($data['username'])) {
                $existingUsername = $db->fetchOne("SELECT id FROM employees WHERE username = ?", [$data['username']]);
                if ($existingUsername) {
                    sendJsonResponse(false, 'This username is already taken');
                }
            }
            
            $dateOfBirth = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
            $dateOfJoining = !empty($data['date_of_joining']) ? $data['date_of_joining'] : null;
            $username = !empty($data['username']) ? $data['username'] : null;

            $db->insert('employees', [
                'employee_id' => $employeeId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'personal_email' => !empty($data['personal_email']) ? $data['personal_email'] : null,
                'official_email' => $data['official_email'],
                'alternate_email' => !empty($data['alternate_email']) ? $data['alternate_email'] : null,
                'phone_number' => $data['phone'] ?? null,
                'alternate_phone' => !empty($data['alternate_phone']) ? $data['alternate_phone'] : null,
                'date_of_birth' => $dateOfBirth,
                'gender' => !empty($data['gender']) ? $data['gender'] : null,
                'marital_status' => !empty($data['marital_status']) ? $data['marital_status'] : null,
                'address' => !empty($data['address']) ? $data['address'] : null,
                'state' => !empty($data['state']) ? $data['state'] : null,
                'city' => !empty($data['city']) ? $data['city'] : null,
                'zip_code' => !empty($data['zip_code']) ? $data['zip_code'] : null,
                'position' => !empty($data['position']) ? $data['position'] : null,
                'reporting_manager_id' => !empty($data['reporting_manager_id']) ? $data['reporting_manager_id'] : null,
                'date_of_joining' => $dateOfJoining,
                'employment_type' => !empty($data['employment_type']) ? $data['employment_type'] : null,
                'role_id' => !empty($data['role_id']) ? $data['role_id'] : null,
                'username' => $username,
                'password' => !empty($data['password']) ? $data['password'] : null,
                'holiday_calendar_id' => !empty($data['holiday_calendar_id']) ? $data['holiday_calendar_id'] : null,
                'shift_id' => !empty($data['shift_id']) ? $data['shift_id'] : null,
                'work_pattern_id' => !empty($data['work_pattern_id']) ? $data['work_pattern_id'] : null,
                'bank_name' => !empty($data['bank_name']) ? $data['bank_name'] : null,
                'account_number' => !empty($data['account_number']) ? $data['account_number'] : null,
                'ifsc_code' => !empty($data['ifsc_code']) ? $data['ifsc_code'] : null,
                'pan_number' => !empty($data['pan_number']) ? $data['pan_number'] : null,
                'is_active' => $data['is_active'] ?? 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            $employeeId = $db->lastInsertId();
            
            if (!empty($data['department_id'])) {
                $db->insert('employee_departments', [
                    'employee_id' => $employeeId,
                    'department_id' => $data['department_id'],
                    'is_primary' => 1
                ]);
            }
            
            // Handle secondary departments
            if (!empty($data['secondary_departments']) && is_array($data['secondary_departments'])) {
                error_log("Processing secondary departments: " . print_r($data['secondary_departments'], true));
                foreach ($data['secondary_departments'] as $secondaryDeptId) {
                    // Skip if it's the same as primary department
                    if ($secondaryDeptId == $data['department_id']) {
                        error_log("Skipping secondary dept {$secondaryDeptId} - same as primary");
                        continue;
                    }
                    
                    // Check if already exists
                    $existing = $db->fetchOne(
                        "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ?",
                        [$employeeId, $secondaryDeptId]
                    );
                    
                    if (!$existing) {
                        error_log("Inserting secondary department: employee_id={$employeeId}, department_id={$secondaryDeptId}");
                        $db->insert('employee_departments', [
                            'employee_id' => $employeeId,
                            'department_id' => $secondaryDeptId,
                            'is_primary' => 0
                        ]);
                    } else {
                        error_log("Secondary department already exists: employee_id={$employeeId}, department_id={$secondaryDeptId}");
                    }
                }
            } else {
                error_log("No secondary departments provided or invalid format");
            }
            
            // Provision leave type balances for the new employee so they immediately
            // appear in Leaves -> Balances. Uses the same non-destructive sync that
            // runs when a leave type is saved (creates only missing rows).
            if (function_exists('sync_leave_type_balances')) {
                $activeLeaveTypes = $db->fetchAll("SELECT * FROM leave_types WHERE is_active = 1");
                foreach ($activeLeaveTypes as $leaveTypeToSync) {
                    sync_leave_type_balances($db, $leaveTypeToSync);
                }
            }
            
            sendJsonResponse(true, 'Employee created successfully', ['id' => $employeeId]);
            break;

        case 'PUT':
            if (!hasPermission('employees', 'update')) {
                sendJsonResponse(false, 'Access denied');
            }
            if (!$id) {
                sendJsonResponse(false, 'Employee ID required');
            }

            // Hierarchy check: cannot edit employees with higher or equal hierarchy level (unless Super Admin)
            if ($user['hierarchy_level'] != 1) {
                $targetEmployee = $db->fetchOne(
                    "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
                    [$id]
                );
                if ($targetEmployee && $targetEmployee['hierarchy_level'] <= $user['hierarchy_level']) {
                    sendJsonResponse(false, 'You cannot edit employees with equal or higher hierarchy level');
                }
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            // Debug: Log incoming data
            error_log("PUT Employee Data (ID: {$id}): " . print_r($data, true));
            
            // Validation: Check if changing status from Active to Inactive
            $currentEmployee = $db->fetchOne("SELECT is_active FROM employees WHERE id = ?", [$id]);
            if ($currentEmployee && isset($data['is_active'])) {
                if ($currentEmployee['is_active'] == 1 && $data['is_active'] == 0) {
                    // Check if there are active employees reporting to this person
                    $reportingEmployees = $db->fetchAll(
                        "SELECT id, first_name, last_name, employee_id
                         FROM employees
                         WHERE reporting_manager_id = ? AND is_active = 1",
                        [$id]
                    );
                    
                    if (count($reportingEmployees) > 0) {
                        // Allow update only if reassignments flag is set
                        if (!isset($data['reassignments_completed']) || $data['reassignments_completed'] !== true) {
                            sendJsonResponse(false, 'Cannot deactivate employee with active reporting employees. Please reassign them first.', [
                                'has_reporting_employees' => true,
                                'reporting_employees' => $reportingEmployees
                            ]);
                        }
                    }
                }
            }
            
            $updateData = [];
            if (isset($data['first_name'])) $updateData['first_name'] = $data['first_name'];
            if (isset($data['last_name'])) $updateData['last_name'] = $data['last_name'];
            if (isset($data['personal_email'])) $updateData['personal_email'] = !empty($data['personal_email']) ? $data['personal_email'] : null;
            if (isset($data['official_email'])) $updateData['official_email'] = $data['official_email'];
            if (isset($data['alternate_email'])) $updateData['alternate_email'] = !empty($data['alternate_email']) ? $data['alternate_email'] : null;
            if (isset($data['phone'])) $updateData['phone_number'] = $data['phone'];
            if (isset($data['alternate_phone'])) $updateData['alternate_phone'] = !empty($data['alternate_phone']) ? $data['alternate_phone'] : null;
            if (isset($data['date_of_birth'])) $updateData['date_of_birth'] = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
            if (isset($data['gender'])) $updateData['gender'] = !empty($data['gender']) ? $data['gender'] : null;
            if (isset($data['marital_status'])) $updateData['marital_status'] = !empty($data['marital_status']) ? $data['marital_status'] : null;
            if (isset($data['address'])) $updateData['address'] = !empty($data['address']) ? $data['address'] : null;
            if (isset($data['state'])) $updateData['state'] = !empty($data['state']) ? $data['state'] : null;
            if (isset($data['city'])) $updateData['city'] = !empty($data['city']) ? $data['city'] : null;
            if (isset($data['zip_code'])) $updateData['zip_code'] = !empty($data['zip_code']) ? $data['zip_code'] : null;
            if (isset($data['position'])) $updateData['position'] = !empty($data['position']) ? $data['position'] : null;
            if (isset($data['reporting_manager_id'])) $updateData['reporting_manager_id'] = !empty($data['reporting_manager_id']) ? $data['reporting_manager_id'] : null;
            if (isset($data['date_of_joining'])) $updateData['date_of_joining'] = !empty($data['date_of_joining']) ? $data['date_of_joining'] : null;
            if (isset($data['last_working_day'])) {
                if (!empty($data['last_working_day'])) {
                    $lwdOriginal = $data['last_working_day'];
                    $lwdTs = strtotime($lwdOriginal);
                    $lwdNormalized = $lwdTs ? date('Y-m-d', $lwdTs) : '';
                    if (!$lwdTs || $lwdNormalized !== $lwdOriginal) {
                        sendJsonResponse(false, 'Please provide a valid Last Working Day date (YYYY-MM-DD).');
                    }
                    $updateData['last_working_day'] = $lwdNormalized;
                } else {
                    $updateData['last_working_day'] = null;
                }
            }
            if (isset($data['employment_type'])) $updateData['employment_type'] = !empty($data['employment_type']) ? $data['employment_type'] : null;
            if (isset($data['work_pattern_id'])) $updateData['work_pattern_id'] = !empty($data['work_pattern_id']) ? $data['work_pattern_id'] : null;
            if (isset($data['role_id'])) {
                // Hierarchy check: cannot assign role with equal or higher hierarchy level (unless Super Admin)
                if ($user['hierarchy_level'] != 1 && !empty($data['role_id'])) {
                    $newRole = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$data['role_id']]);
                    if ($newRole && $newRole['hierarchy_level'] <= $user['hierarchy_level']) {
                        sendJsonResponse(false, 'You cannot assign roles with equal or higher hierarchy level');
                    }
                }
                $updateData['role_id'] = !empty($data['role_id']) ? $data['role_id'] : null;
            }
            if (isset($data['username'])) $updateData['username'] = !empty($data['username']) ? $data['username'] : null;
            if (isset($data['password'])) $updateData['password'] = !empty($data['password']) ? $data['password'] : null;
            if (isset($data['holiday_calendar_id'])) $updateData['holiday_calendar_id'] = !empty($data['holiday_calendar_id']) ? $data['holiday_calendar_id'] : null;
            if (isset($data['shift_id'])) $updateData['shift_id'] = !empty($data['shift_id']) ? $data['shift_id'] : null;
            if (isset($data['work_pattern_id'])) $updateData['work_pattern_id'] = !empty($data['work_pattern_id']) ? $data['work_pattern_id'] : null;
            if (isset($data['bank_name'])) $updateData['bank_name'] = !empty($data['bank_name']) ? $data['bank_name'] : null;
            if (isset($data['account_number'])) $updateData['account_number'] = !empty($data['account_number']) ? $data['account_number'] : null;
            if (isset($data['ifsc_code'])) $updateData['ifsc_code'] = !empty($data['ifsc_code']) ? $data['ifsc_code'] : null;
            if (isset($data['pan_number'])) $updateData['pan_number'] = !empty($data['pan_number']) ? $data['pan_number'] : null;
            if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
            
            $db->update('employees', $updateData, 'id = ?', [$id]);
            
            if (!empty($data['department_id'])) {
                $existingDept = $db->fetchOne(
                    "SELECT id, department_id FROM employee_departments WHERE employee_id = ? AND is_primary = 1 LIMIT 1",
                    [$id]
                );
                
                if ($existingDept) {
                    if ($existingDept['department_id'] != $data['department_id']) {
                        $db->update('employee_departments', 
                            ['department_id' => $data['department_id'], 'is_primary' => 1], 
                            'id = ?', 
                            [$existingDept['id']]
                        );
                    }
                } else {
                    $db->insert('employee_departments', [
                        'employee_id' => $id,
                        'department_id' => $data['department_id'],
                        'is_primary' => 1
                    ]);
                }
            }
            
            // Handle secondary departments
            if (isset($data['secondary_departments'])) {
                error_log("PUT: Processing secondary departments for employee {$id}");
                
                // First, remove all existing secondary departments
                $deleted = $db->delete('employee_departments', 
                    'employee_id = ? AND is_primary = 0', 
                    [$id]
                );
                error_log("PUT: Deleted {$deleted} existing secondary departments");
                
                // Add new secondary departments
                if (!empty($data['secondary_departments']) && is_array($data['secondary_departments'])) {
                    $primaryDeptId = !empty($data['department_id']) ? $data['department_id'] : null;
                    error_log("PUT: Adding new secondary departments: " . print_r($data['secondary_departments'], true));
                    
                    foreach ($data['secondary_departments'] as $secondaryDeptId) {
                        // Skip if it's the same as primary department
                        if ($primaryDeptId && $secondaryDeptId == $primaryDeptId) {
                            error_log("PUT: Skipping secondary dept {$secondaryDeptId} - same as primary");
                            continue;
                        }
                        
                        // Check if already exists (from primary department update)
                        $existing = $db->fetchOne(
                            "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ?",
                            [$id, $secondaryDeptId]
                        );
                        
                        if (!$existing) {
                            error_log("PUT: Inserting secondary department: employee_id={$id}, department_id={$secondaryDeptId}");
                            $db->insert('employee_departments', [
                                'employee_id' => $id,
                                'department_id' => $secondaryDeptId,
                                'is_primary' => 0
                            ]);
                        } else {
                            error_log("PUT: Secondary department already exists: employee_id={$id}, department_id={$secondaryDeptId}");
                        }
                    }
                } else {
                    error_log("PUT: No secondary departments to add");
                }
            } else {
                error_log("PUT: secondary_departments not set in request data");
            }
            
            sendJsonResponse(true, 'Employee updated successfully');
            break;

        case 'DELETE':
            if (!hasPermission('employees', 'delete')) {
                sendJsonResponse(false, 'Access denied');
            }
            if (!$id) {
                sendJsonResponse(false, 'Employee ID required');
            }

            // Hierarchy check: cannot delete employees with higher or equal hierarchy level (unless Super Admin)
            if ($user['hierarchy_level'] != 1) {
                $targetEmployee = $db->fetchOne(
                    "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
                    [$id]
                );
                if ($targetEmployee && $targetEmployee['hierarchy_level'] <= $user['hierarchy_level']) {
                    sendJsonResponse(false, 'You cannot delete employees with equal or higher hierarchy level');
                }
            }

            $currentData = $db->fetchOne("SELECT * FROM employees WHERE id = ?", [$id]);
            if (!$currentData) {
                sendJsonResponse(false, 'Employee not found');
            }
            
            try {
                $db->delete('employees', 'id = ?', [$id]);
                
                if (function_exists('logAudit')) {
                    logAudit('employees', $id, 'delete', $currentData, null);
                }
                
                sendJsonResponse(true, 'Employee permanently deleted successfully');
            } catch (Exception $e) {
                sendJsonResponse(false, 'Failed to delete employee: ' . $e->getMessage());
            }
            break;

        default:
            sendJsonResponse(false, 'Method not allowed');
    }
} catch (Exception $e) {
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

ob_end_flush();
exit;
?>
