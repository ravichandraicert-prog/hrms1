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
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

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
    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if ($id) {
                $employee = $db->fetchOne(
                    "SELECT e.*, r.name as role_name, d.name as department_name, ed.department_id
                     FROM employees e 
                     LEFT JOIN roles r ON e.role_id = r.id 
                     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 
                     LEFT JOIN departments d ON ed.department_id = d.id 
                     WHERE e.id = ?",
                    [$id]
                );
                
                if ($employee) {
                    sendJsonResponse(true, 'Employee found', ['employee' => $employee]);
                } else {
                    sendJsonResponse(false, 'Employee not found');
                }
            } else {
                $employees = $db->fetchAll(
                    "SELECT e.*, r.name as role_name, d.name as department_name 
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
            $data = json_decode(file_get_contents('php://input'), true);
            
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
                'working_days_config' => !empty($data['working_days_config']) ? $data['working_days_config'] : null,
                'role_id' => !empty($data['role_id']) ? $data['role_id'] : null,
                'username' => $username,
                'holiday_calendar_id' => !empty($data['holiday_calendar_id']) ? $data['holiday_calendar_id'] : null,
                'shift_id' => !empty($data['shift_id']) ? $data['shift_id'] : null,
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
            
            sendJsonResponse(true, 'Employee created successfully', ['id' => $employeeId]);
            break;

        case 'PUT':
            if (!$id) {
                sendJsonResponse(false, 'Employee ID required');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
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
            if (isset($data['employment_type'])) $updateData['employment_type'] = !empty($data['employment_type']) ? $data['employment_type'] : null;
            if (isset($data['working_days_config'])) $updateData['working_days_config'] = !empty($data['working_days_config']) ? $data['working_days_config'] : null;
            if (isset($data['role_id'])) $updateData['role_id'] = !empty($data['role_id']) ? $data['role_id'] : null;
            if (isset($data['username'])) $updateData['username'] = !empty($data['username']) ? $data['username'] : null;
            if (isset($data['holiday_calendar_id'])) $updateData['holiday_calendar_id'] = !empty($data['holiday_calendar_id']) ? $data['holiday_calendar_id'] : null;
            if (isset($data['shift_id'])) $updateData['shift_id'] = !empty($data['shift_id']) ? $data['shift_id'] : null;
            if (isset($data['bank_name'])) $updateData['bank_name'] = !empty($data['bank_name']) ? $data['bank_name'] : null;
            if (isset($data['account_number'])) $updateData['account_number'] = !empty($data['account_number']) ? $data['account_number'] : null;
            if (isset($data['ifsc_code'])) $updateData['ifsc_code'] = !empty($data['ifsc_code']) ? $data['ifsc_code'] : null;
            if (isset($data['pan_number'])) $updateData['pan_number'] = !empty($data['pan_number']) ? $data['pan_number'] : null;
            if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
            
            $db->update('employees', $updateData, 'id = ?', [$id]);
            
            if (!empty($data['department_id'])) {
                $existingDept = $db->fetchOne(
                    "SELECT id, department_id FROM employee_departments WHERE employee_id = ? LIMIT 1",
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
            
            sendJsonResponse(true, 'Employee updated successfully');
            break;

        case 'DELETE':
            if (!$id) {
                sendJsonResponse(false, 'Employee ID required');
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