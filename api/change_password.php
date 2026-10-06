<?php
/**
 * Change Password API
 * Handles password change requests for authenticated users
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
    $userId = $user['id'];

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            echo json_encode(['success' => false, 'message' => 'Invalid request data']);
            exit;
        }

        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        // Validate input
        if (empty($currentPassword) || empty($newPassword)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            exit;
        }

        // Use Auth class to change password
        $result = $auth->changePassword($userId, $currentPassword, $newPassword);

        echo json_encode($result);

    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
