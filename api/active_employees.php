<?php
/**
 * Active Employees API
 * Returns list of active employees for dropdowns (e.g., reassignment)
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    
    // Check permissions
    if (!hasPermission('employees', 'read')) {
        sendJsonResponse(false, 'Access denied');
    }
    
    $excludeEmployeeId = $_GET['exclude_employee_id'] ?? null;
    
    // Get scoped employee IDs based on permissions
    $permissions = new Permissions();
    $scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'employees', 'read');
    $scopedIdsStr = implode(',', $scopedIds) ?: '0';
    
    // Build query
    $query = "SELECT e.id, e.first_name, e.last_name, e.employee_id
              FROM employees e
              WHERE e.id IN ({$scopedIdsStr}) AND e.is_active = 1";
    $params = [];
    
    // Exclude specific employee (e.g., the one being deactivated)
    if ($excludeEmployeeId) {
        $query .= " AND e.id != ?";
        $params[] = $excludeEmployeeId;
    }
    
    $query .= " ORDER BY e.first_name, e.last_name";
    
    $activeEmployees = $db->fetchAll($query, $params);
    
    sendJsonResponse(true, 'Active employees retrieved', [
        'active_employees' => $activeEmployees
    ]);
    
} catch (Exception $e) {
    error_log("Active Employees Error: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred');
}
