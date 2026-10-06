<?php
/**
 * Employee Reporting Check API
 * Checks if an employee has active employees reporting to them
 * Used for validation before deactivating an employee
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
    
    $employeeId = $_GET['employee_id'] ?? null;
    
    if (!$employeeId) {
        sendJsonResponse(false, 'Employee ID required');
    }
    
    // Get the employee being edited
    $employee = $db->fetchOne(
        "SELECT id, first_name, last_name, employee_id, is_active FROM employees WHERE id = ?",
        [$employeeId]
    );
    
    if (!$employee) {
        sendJsonResponse(false, 'Employee not found');
    }
    
    // Get active employees reporting to this person
    $reportingEmployees = $db->fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.employee_id, e.is_active
         FROM employees e
         WHERE e.reporting_manager_id = ? AND e.is_active = 1
         ORDER BY e.first_name, e.last_name",
        [$employeeId]
    );
    
    sendJsonResponse(true, 'Reporting employees retrieved', [
        'employee' => $employee,
        'reporting_employees' => $reportingEmployees,
        'has_reporting_employees' => count($reportingEmployees) > 0
    ]);
    
} catch (Exception $e) {
    error_log("Employee Reporting Check Error: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred');
}
