<?php
/**
 * Break API
 * Handles break type retrieval and break management
 * Core PHP HRMS Application
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/break_assignment.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();

    // ---- GET: list active break types ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $allBreakTypes = $db->fetchAll(
            "SELECT * FROM break_types WHERE is_active = 1 ORDER BY name"
        );
        
        // Filter break types based on user's assignment
        $filteredBreakTypes = [];
        foreach ($allBreakTypes as $breakType) {
            if (break_type_is_applicable($db, $breakType, $user['id'])) {
                $filteredBreakTypes[] = $breakType;
            }
        }
        
        echo json_encode(['success' => true, 'break_types' => $filteredBreakTypes]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
