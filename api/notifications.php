<?php
/**
 * Notifications API
 * Handles reading/marking notifications for the current user
 * Core PHP HRMS Application
 * Section 10 - Notifications module
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];

    // ---- GET: list notifications (optional type filter) ----
    if ($method === 'GET') {
        $type = $_GET['type'] ?? '';
        $params = [$user['id']];
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        if ($type) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY created_at DESC LIMIT 100";
        $notifications = $db->fetchAll($sql, $params);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        exit;
    }

    // ---- POST handlers ----
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $action = $data['action'] ?? '';

    if ($method === 'POST' && $action === 'mark_all_read') {
        $db->query(
            "UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0",
            [date('Y-m-d H:i:s'), $user['id']]
        );
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        exit;
    }

    if ($method === 'POST' && $action === 'mark_read') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Notification ID required']); exit; }
        $db->update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [$id, $user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Notification ID required']); exit; }
        $db->delete('notifications', 'id = ? AND user_id = ?', [$id, $user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}