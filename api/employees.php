<?php
/**
 * Employees API Endpoint
 * Core PHP HRMS Application
 * Section 6 - Employee management module
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

$auth = new Auth();
$auth->requireLogin();

$user = getCurrentUser();
$db = Database::getInstance();
$permissions = new Permissions();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get employees list
    if (!hasPermission('employees', 'read')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'employees', 'read');
    $scopedIdsStr = implode(',', $scopedIds) ?: '0';
    
    $employees = $db->fetchAll(
        "SELECT e.*, r.name as role_name, d.name as department_name 
         FROM employees e
         LEFT JOIN roles r ON e.role_id = r.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
         LEFT JOIN departments d ON ed.department_id = d.id
         WHERE e.id IN ({$scopedIdsStr})
         ORDER BY e.first_name, e.last_name",
        []
    );
    
    jsonResponse(['success' => true, 'data' => $employees]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new employee
    if (!hasPermission('employees', 'create')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Generate employee ID
    $employeeId = generateEmployeeId();
    
    // Plain text password
    $plainPassword = $data['password'] ?? 'password123';
    
    $employeeData = [
        'employee_id' => $employeeId,
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'official_email' => $data['official_email'],
        'phone_number' => $data['phone_number'],
        'personal_email' => $data['personal_email'] ?? null,
        'alternate_email' => $data['alternate_email'] ?? null,
        'alternate_phone' => $data['alternate_phone'] ?? null,
        'date_of_birth' => $data['date_of_birth'] ?? null,
        'marital_status' => $data['marital_status'] ?? null,
        'address' => $data['address'] ?? null,
        'state' => $data['state'] ?? null,
        'city' => $data['city'] ?? null,
        'zip_code' => $data['zip_code'] ?? null,
        'position' => $data['position'] ?? null,
        'reporting_manager_id' => $data['reporting_manager_id'] ?? null,
        'date_of_joining' => $data['date_of_joining'] ?? null,
        'employment_type' => $data['employment_type'] ?? null,
        'working_days_config' => $data['working_days_config'] ?? 'Mon-Fri',
        'role_id' => $data['role_id'] ?? null,
        'username' => $data['username'] ?? null,
        'password' => $plainPassword,
        'holiday_calendar_id' => $data['holiday_calendar_id'] ?? null,
        'shift_id' => $data['shift_id'] ?? null,
        'bank_name' => $data['bank_name'] ?? null,
        'account_number' => $data['account_number'] ?? null,
        'ifsc_code' => $data['ifsc_code'] ?? null,
        'pan_number' => $data['pan_number'] ?? null
    ];
    
    try {
        $employeeId = $db->insert('employees', $employeeData);
        
        // Assign to department if provided
        if (!empty($data['department_id'])) {
            $db->insert('employee_departments', [
                'employee_id' => $employeeId,
                'department_id' => $data['department_id'],
                'is_primary' => 1
            ]);
        }
        
        logAudit('employees', $employeeId, 'create', null, $employeeData);
        
        jsonResponse(['success' => true, 'employee_id' => $employeeId, 'message' => 'Employee created successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to create employee: ' . $e->getMessage()], 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update employee
    if (!hasPermission('employees', 'update')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $id = $_GET['id'] ?? null;
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Employee ID is required'], 400);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Get current employee data for audit
    $currentData = $db->fetchOne("SELECT * FROM employees WHERE id = ?", [$id]);
    if (!$currentData) {
        jsonResponse(['success' => false, 'message' => 'Employee not found'], 404);
    }
    
    // Build update data (exclude password if not provided)
    $updateData = [];
    $allowedFields = ['first_name', 'last_name', 'official_email', 'phone_number', 'personal_email', 
                      'alternate_email', 'alternate_phone', 'date_of_birth', 'marital_status', 'address',
                      'state', 'city', 'zip_code', 'position', 'reporting_manager_id', 'date_of_joining',
                      'employment_type', 'working_days_config', 'role_id', 'username', 'holiday_calendar_id',
                      'shift_id', 'bank_name', 'account_number', 'ifsc_code', 'pan_number', 'is_active', 'is_blacklisted'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }
    
    // Handle password update separately
    if (!empty($data['password'])) {
        $updateData['password'] = $data['password'];
    }
    
    try {
        $db->update('employees', $updateData, 'id = ?', [$id]);
        logAudit('employees', $id, 'update', $currentData, $updateData);
        jsonResponse(['success' => true, 'message' => 'Employee updated successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to update employee: ' . $e->getMessage()], 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete employee
    if (!hasPermission('employees', 'delete')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $id = $_GET['id'] ?? null;
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Employee ID is required'], 400);
    }
    
    $currentData = $db->fetchOne("SELECT * FROM employees WHERE id = ?", [$id]);
    if (!$currentData) {
        jsonResponse(['success' => false, 'message' => 'Employee not found'], 404);
    }
    
    try {
        $db->delete('employees', 'id = ?', [$id]);
        logAudit('employees', $id, 'delete', $currentData, null);
        jsonResponse(['success' => true, 'message' => 'Employee deleted successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to delete employee: ' . $e->getMessage()], 500);
    }
    
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
