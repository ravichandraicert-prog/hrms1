<?php
/**
 * Employee Reassignment API
 * Handles bulk reassignment of reporting employees when a manager is deactivated
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
    if (!hasPermission('employees', 'update')) {
        sendJsonResponse(false, 'Access denied');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Invalid request method');
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $reassignments = $data['reassignments'] ?? [];
    
    if (empty($reassignments) || !is_array($reassignments)) {
        sendJsonResponse(false, 'No reassignments provided');
    }
    
    $updatedCount = 0;
    $errors = [];
    
    foreach ($reassignments as $reassignment) {
        $employeeId = $reassignment['employee_id'] ?? null;
        $newManagerId = $reassignment['new_manager_id'] ?? null;
        
        if (!$employeeId) {
            $errors[] = 'Missing employee_id in reassignment';
            continue;
        }
        
        if (!$newManagerId) {
            $errors[] = "Missing new_manager_id for employee {$employeeId}";
            continue;
        }
        
        // Prevent circular reporting relationships
        // Check if the new manager reports to this employee (directly or indirectly)
        $hasCircularDependency = checkCircularDependency($db, $employeeId, $newManagerId);
        
        if ($hasCircularDependency) {
            $errors[] = "Circular dependency detected for employee {$employeeId}";
            continue;
        }
        
        // Update the reporting manager
        $result = $db->update(
            'employees',
            ['reporting_manager_id' => $newManagerId, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$employeeId]
        );
        
        if ($result) {
            $updatedCount++;
        } else {
            $errors[] = "Failed to update employee {$employeeId}";
        }
    }
    
    if ($updatedCount > 0) {
        sendJsonResponse(true, "Successfully reassigned {$updatedCount} employee(s)", [
            'updated_count' => $updatedCount,
            'errors' => $errors
        ]);
    } else {
        sendJsonResponse(false, 'No employees were reassigned', ['errors' => $errors]);
    }
    
} catch (Exception $e) {
    error_log("Employee Reassignment Error: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred');
}

/**
 * Check for circular reporting dependencies
 * Returns true if assigning employee A to report to B would create a circular dependency
 */
function checkCircularDependency($db, $employeeId, $newManagerId) {
    // Prevent self-assignment
    if ($employeeId == $newManagerId) {
        return true;
    }
    
    // Check if the new manager currently reports (directly or indirectly) to the employee being reassigned
    // This would create a circular dependency: A -> B -> ... -> A
    
    $visited = [];
    $currentManagerId = $newManagerId;
    $maxDepth = 50; // Prevent infinite loops
    
    while ($currentManagerId && $maxDepth > 0) {
        if ($currentManagerId == $employeeId) {
            return true; // Circular dependency found
        }
        
        if (in_array($currentManagerId, $visited)) {
            break; // Already checked this manager, avoid infinite loop
        }
        
        $visited[] = $currentManagerId;
        
        // Get the current manager's reporting manager
        $manager = $db->fetchOne(
            "SELECT reporting_manager_id FROM employees WHERE id = ?",
            [$currentManagerId]
        );
        
        $currentManagerId = $manager['reporting_manager_id'] ?? null;
        $maxDepth--;
    }
    
    return false;
}
