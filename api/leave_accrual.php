<?php
/**
 * Leave Accrual API
 * Handles manual triggering and status checking of monthly leave accrual
 * Core PHP HRMS Application
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();

    // Only admin users can trigger accrual
    if (!hasPermission('leaves', 'update') && !hasPermission('settings', 'update')) {
        echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'POST' && $action === 'process_accrual') {
        // Manually trigger the accrual process
        require_once __DIR__ . '/../cron/process_monthly_leave_accrual.php';
        
        // The cron script will output text, so we need to capture it
        ob_start();
        include __DIR__ . '/../cron/process_monthly_leave_accrual.php';
        $output = ob_get_clean();
        
        echo json_encode(['success' => true, 'message' => 'Accrual process completed', 'output' => $output]);
        exit;
    }

    if ($method === 'GET' && $action === 'get_accrual_history') {
        // Get accrual history with filters
        $employeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;
        $leaveTypeId = isset($_GET['leave_type_id']) ? (int) $_GET['leave_type_id'] : null;
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');

        $where = ["accrual_date BETWEEN ? AND ?"];
        $params = [$startDate, $endDate];

        if ($employeeId) {
            $where[] = "employee_id = ?";
            $params[] = $employeeId;
        }

        if ($leaveTypeId) {
            $where[] = "leave_type_id = ?";
            $params[] = $leaveTypeId;
        }

        $history = $db->fetchAll(
            "SELECT lah.*, 
                    e.first_name, e.last_name, e.employee_id,
                    lt.name as leave_type_name,
                    pc.name as pay_cycle_name
             FROM leave_accrual_history lah
             JOIN employees e ON lah.employee_id = e.id
             JOIN leave_types lt ON lah.leave_type_id = lt.id
             LEFT JOIN pay_cycles pc ON lah.pay_cycle_id = pc.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY lah.accrual_date DESC, lah.created_at DESC",
            $params
        );

        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }

    if ($method === 'GET' && $action === 'get_accrual_settings') {
        // Get all leave types with monthly accrual settings
        $leaveTypes = $db->fetchAll(
            "SELECT lt.*, 
                    (SELECT COUNT(*) FROM leave_accrual_history lah WHERE lah.leave_type_id = lt.id) as total_accruals
             FROM leave_types lt
             WHERE lt.monthly_accrual_enabled = 1
             ORDER BY lt.name"
        );

        echo json_encode(['success' => true, 'leave_types' => $leaveTypes]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>