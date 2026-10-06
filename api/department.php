<?php
/**
 * Department API
 * Handles CRUD operations for departments
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Load database config directly
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user = getCurrentUser();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'];

    // Get request parameters
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if (!hasPermission('departments', 'read')) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            if ($id) {
                $action = $_GET['action'] ?? '';
                
                if ($action === 'hierarchy') {
                    // Get department employees with reporting relationships (both primary and secondary)
                    $employees = $db->fetchAll(
                        "SELECT e.*, ed.is_primary, ed.is_managerial, ed.is_in_hierarchy,
                         (SELECT first_name FROM employees WHERE id = e.reporting_manager_id) as manager_first_name,
                         (SELECT last_name FROM employees WHERE id = e.reporting_manager_id) as manager_last_name
                         FROM employees e
                         JOIN employee_departments ed ON e.id = ed.employee_id
                         WHERE ed.department_id = ? AND e.is_active = 1
                         ORDER BY e.first_name, e.last_name",
                        [$id]
                    );
                    echo json_encode(['success' => true, 'employees' => $employees]);
                } elseif ($action === 'employees') {
                    // Get department employees with full details
                    $employees = $db->fetchAll(
                        "SELECT e.*, ed.is_primary, ed.is_managerial
                         FROM employee_departments ed
                         JOIN employees e ON ed.employee_id = e.id
                         WHERE ed.department_id = ?
                         ORDER BY e.first_name, e.last_name",
                        [$id]
                    );
                    echo json_encode(['success' => true, 'employees' => $employees]);
                } else {
                    // Get single department
                    $department = $db->fetchOne(
                        "SELECT d.*, e.first_name, e.last_name 
                         FROM departments d 
                         LEFT JOIN employees e ON d.head_of_department_id = e.id 
                         WHERE d.id = ?",
                        [$id]
                    );
                    
                    if ($department) {
                        echo json_encode(['success' => true, 'department' => $department]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Department not found']);
                    }
                }
            } else {
                // Get all departments
                $departments = $db->fetchAll(
                    "SELECT d.*, e.first_name, e.last_name 
                     FROM departments d 
                     LEFT JOIN employees e ON d.head_of_department_id = e.id 
                     ORDER BY d.name"
                );
                echo json_encode(['success' => true, 'departments' => $departments]);
            }
            break;

        case 'POST':
            if (!hasPermission('departments', 'create')) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            // Create new department
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
                break;
            }
            
            // Generate department code if not provided
            if (empty($data['code'])) {
                $data['code'] = strtoupper(substr($data['name'], 0, 3)) . '-' . rand(100, 999);
            }
            
            // Insert department using Database class
            try {
                $departmentId = $db->insert('departments', [
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                    'parent_department_id' => $data['parent_department_id'] ?? null,
                    'head_of_department_id' => $data['head_of_department_id'] ?? null,
                    'is_active' => $data['is_active'] ?? 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                // Handle employee department assignments
                if (!empty($data['department_employees']) && is_array($data['department_employees'])) {
                    foreach ($data['department_employees'] as $employeeId) {
                        // Check if already assigned to this department
                        $existing = $db->fetchOne(
                            "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ?",
                            [$employeeId, $departmentId]
                        );
                        
                        if (!$existing) {
                            // Check if employee already has a primary department elsewhere
                            $existingPrimary = $db->fetchOne(
                                "SELECT id FROM employee_departments WHERE employee_id = ? AND is_primary = 1 LIMIT 1",
                                [$employeeId]
                            );
                            
                            // Set is_primary based on whether they already have a primary department
                            $isPrimary = $existingPrimary ? 0 : 1;
                            
                            // Set primary_guard based on is_primary (employee_id if primary, null if secondary)
                            $primaryGuard = $isPrimary ? $employeeId : null;
                            
                            $db->insert('employee_departments', [
                                'employee_id' => $employeeId,
                                'department_id' => $departmentId,
                                'is_primary' => $isPrimary,
                                'is_managerial' => 0,
                                'primary_guard' => $primaryGuard,
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
                
                // Handle hierarchy (reporting relationships)
                $hierarchyEmployeeIds = [];
                $managerIds = [];
                if (!empty($data['hierarchy']) && is_array($data['hierarchy'])) {
                    foreach ($data['hierarchy'] as $relationship) {
                        $hierarchyEmployeeIds[] = $relationship['employee_id'];
                        $del = NULL;
                        if (isset($relationship['reporting_manager_id']) && $relationship['reporting_manager_id']) {
                            $del = $relationship['reporting_manager_id'];
                            $managerIds[] = $relationship['reporting_manager_id'];
                        }
                        $db->update('employees', 
                            ['reporting_manager_id' => $del, 'updated_at' => date('Y-m-d H:i:s')],
                            'id = ?', 
                            [$relationship['employee_id']]
                        );
                    }
                }
                
                // Set is_in_hierarchy and is_managerial flags for all department employees
                if (!empty($data['department_employees']) && is_array($data['department_employees'])) {
                    foreach ($data['department_employees'] as $employeeId) {
                        $inHierarchy = in_array($employeeId, $hierarchyEmployeeIds) ? 1 : 0;
                        $isManagerial = in_array($employeeId, $managerIds) ? 1 : 0;
                        $db->update('employee_departments',
                            ['is_in_hierarchy' => $inHierarchy, 'is_managerial' => $isManagerial],
                            'employee_id = ? AND department_id = ?',
                            [$employeeId, $departmentId]
                        );
                        if (!$inHierarchy) {
                            $db->update('employees',
                                ['reporting_manager_id' => null, 'updated_at' => date('Y-m-d H:i:s')],
                                'id = ?',
                                [$employeeId]
                            );
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Department created successfully', 'id' => $departmentId]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to insert department: ' . $e->getMessage()]);
            }
            break;

        case 'PUT':
            if (!hasPermission('departments', 'update')) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            // Update existing department
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Department ID required']);
                break;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Update department
            $updateData = [
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Only update fields that are provided
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (isset($data['code'])) {
                $updateData['code'] = $data['code'];
            }
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['parent_department_id'])) {
                $updateData['parent_department_id'] = $data['parent_department_id'];
            }
            if (isset($data['head_of_department_id'])) {
                $updateData['head_of_department_id'] = $data['head_of_department_id'];
            }
            if (isset($data['is_active'])) {
                $updateData['is_active'] = $data['is_active'];
            }
            
            $db->update('departments', $updateData, 'id = ?', [$id]);
            
            // Pre-compute hierarchy employee IDs
            $hierarchyEmployeeIds = [];
            if (!empty($data['hierarchy']) && is_array($data['hierarchy'])) {
                foreach ($data['hierarchy'] as $hierarchyEntry) {
                    $hierarchyEmployeeIds[] = $hierarchyEntry['employee_id'];
                }
            }

            // Handle employee department assignments
            if (isset($data['department_employees']) && is_array($data['department_employees'])) {
                // Delete existing employee assignments for this department
                $db->delete('employee_departments', 'department_id = ?', [$id]);
                
                // Insert new employee assignments
                foreach ($data['department_employees'] as $employeeId) {
                    // Set the is_in_hierarchy flag based on whether employee is in the hierarchy
                    $isInHierarchy = in_array($employeeId, $hierarchyEmployeeIds) ? 1 : 0;
                    
                    // Check if employee already has a primary department elsewhere
                    $existingPrimary = $db->fetchOne(
                        "SELECT id FROM employee_departments WHERE employee_id = ? AND is_primary = 1 AND department_id != ? LIMIT 1",
                        [$employeeId, $id]
                    );
                    
                    // Set is_primary based on whether they already have a primary department
                    $isPrimary = $existingPrimary ? 0 : 1;
                    
                    $db->insert('employee_departments', [
                        'employee_id' => $employeeId,
                        'department_id' => $id,
                        'is_primary' => $isPrimary,
                        'is_managerial' => 0,
                        'is_in_hierarchy' => $isInHierarchy,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            // Handle hierarchy (reporting relationships)
            if (isset($data['hierarchy']) && is_array($data['hierarchy'])) {
                // First, collect all manager IDs (employees who have reports)
                $managerIds = [];
                foreach ($data['hierarchy'] as $relationship) {
                    if (isset($relationship['reporting_manager_id']) && $relationship['reporting_manager_id']) {
                        $managerIds[] = $relationship['reporting_manager_id'];
                    }
                }
                
                foreach ($data['hierarchy'] as $relationship) {
                    $managerId = null;
                    if (isset($relationship['reporting_manager_id']) && $relationship['reporting_manager_id']) {
                        $managerId = $relationship['reporting_manager_id'];
                    }
                    // Update reporting_manager_id (null for top-level, manager ID for reports)
                    $db->update('employees', 
                        ['reporting_manager_id' => $managerId, 'updated_at' => date('Y-m-d H:i:s')],
                        'id = ?', 
                        [$relationship['employee_id']]
                    );
                }
                
                // Set is_managerial flag for employees who are managers in this department
                foreach ($data['department_employees'] as $employeeId) {
                    $isManagerial = in_array($employeeId, $managerIds) ? 1 : 0;
                    $db->update('employee_departments',
                        ['is_managerial' => $isManagerial],
                        'employee_id = ? AND department_id = ?',
                        [$employeeId, $id]
                    );
                }
            }
            
            // Clear reporting relationships for department employees not in the new hierarchy
            if (isset($data['department_employees']) && is_array($data['department_employees'])) {
                foreach ($data['department_employees'] as $employeeId) {
                    if (!in_array($employeeId, $hierarchyEmployeeIds)) {
                        $db->update('employees',
                            ['reporting_manager_id' => null, 'updated_at' => date('Y-m-d H:i:s')],
                            'id = ?',
                            [$employeeId]
                        );
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Department updated successfully']);
            break;

        case 'DELETE':
            if (!hasPermission('departments', 'delete')) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            // Delete department
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Department ID required']);
                break;
            }
            
            // Check if department has employees
            $employeeCount = $db->fetchOne(
                "SELECT COUNT(*) as count FROM employee_departments WHERE department_id = ?",
                [$id]
            );
            
            if ($employeeCount['count'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete department with assigned employees']);
                break;
            }
            
            // Delete department
            $db->delete('departments', 'id = ?', [$id]);
            
            echo json_encode(['success' => true, 'message' => 'Department deleted successfully']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

