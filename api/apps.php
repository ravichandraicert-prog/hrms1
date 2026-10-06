<?php
/**
 * Apps API
 * Handles CRUD operations for user application shortcuts
 * Core PHP HRMS Application
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

    // ---- GET: list user's apps ----
    if ($method === 'GET') {
        $apps = $db->fetchAll(
            "SELECT * FROM user_apps WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id",
            [$user['id']]
        );
        echo json_encode(['success' => true, 'apps' => $apps]);
        exit;
    }

    // ---- POST handlers ----
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $action = $data['action'] ?? '';

    // Create new app
    if ($method === 'POST' && $action === 'create') {
        $appName = trim($data['app_name'] ?? '');
        $appType = $data['app_type'] ?? '';
        $target = trim($data['target'] ?? '');
        $icon = isset($data['icon']) ? trim($data['icon']) : null;

        // Validation
        if (empty($appName)) {
            echo json_encode(['success' => false, 'message' => 'App name is required']);
            exit;
        }

        if (empty($appType)) {
            echo json_encode(['success' => false, 'message' => 'App type is required']);
            exit;
        }

        $validTypes = ['website', 'desktop_app', 'file', 'folder', 'hrms_module'];
        if (!in_array($appType, $validTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid app type']);
            exit;
        }

        if (empty($target)) {
            echo json_encode(['success' => false, 'message' => 'Target URL/path/module is required']);
            exit;
        }

        // For websites, validate URL format
        if ($appType === 'website') {
            if (!filter_var($target, FILTER_VALIDATE_URL)) {
                // Try adding https://
                if (!filter_var('https://' . $target, FILTER_VALIDATE_URL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid website URL']);
                    exit;
                }
                $target = 'https://' . $target;
            }
        }

        // For HRMS module shortcuts, verify user has permission
        if ($appType === 'hrms_module') {
            if (!hasModuleAccess($target)) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to access this module']);
                exit;
            }
        }

        // Get next sort order
        $maxSortOrder = $db->fetchOne(
            "SELECT MAX(sort_order) as max_order FROM user_apps WHERE user_id = ?",
            [$user['id']]
        );
        $sortOrder = ($maxSortOrder['max_order'] ?? 0) + 1;

        // Insert app
        $appId = $db->insert('user_apps', [
            'user_id' => $user['id'],
            'app_name' => $appName,
            'app_type' => $appType,
            'target' => $target,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_active' => 1
        ]);

        echo json_encode(['success' => true, 'message' => 'App added successfully', 'id' => $appId]);
        exit;
    }

    // Update existing app
    if ($method === 'POST' && $action === 'update') {
        $appId = (int) ($data['id'] ?? 0);
        $appName = trim($data['app_name'] ?? '');
        $appType = $data['app_type'] ?? '';
        $target = trim($data['target'] ?? '');
        $icon = isset($data['icon']) ? trim($data['icon']) : null;

        if (!$appId) {
            echo json_encode(['success' => false, 'message' => 'App ID is required']);
            exit;
        }

        // Verify ownership
        $existingApp = $db->fetchOne(
            "SELECT * FROM user_apps WHERE id = ? AND user_id = ?",
            [$appId, $user['id']]
        );

        if (!$existingApp) {
            echo json_encode(['success' => false, 'message' => 'App not found or access denied']);
            exit;
        }

        // Validation
        if (empty($appName)) {
            echo json_encode(['success' => false, 'message' => 'App name is required']);
            exit;
        }

        if (empty($appType)) {
            echo json_encode(['success' => false, 'message' => 'App type is required']);
            exit;
        }

        $validTypes = ['website', 'desktop_app', 'file', 'folder', 'hrms_module'];
        if (!in_array($appType, $validTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid app type']);
            exit;
        }

        if (empty($target)) {
            echo json_encode(['success' => false, 'message' => 'Target URL/path/module is required']);
            exit;
        }

        // For websites, validate URL format
        if ($appType === 'website') {
            if (!filter_var($target, FILTER_VALIDATE_URL)) {
                // Try adding https://
                if (!filter_var('https://' . $target, FILTER_VALIDATE_URL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid website URL']);
                    exit;
                }
                $target = 'https://' . $target;
            }
        }

        // For HRMS module shortcuts, verify user has permission
        if ($appType === 'hrms_module') {
            if (!hasModuleAccess($target)) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to access this module']);
                exit;
            }
        }

        // Update app
        $db->update('user_apps', [
            'app_name' => $appName,
            'app_type' => $appType,
            'target' => $target,
            'icon' => $icon
        ], 'id = ? AND user_id = ?', [$appId, $user['id']]);

        echo json_encode(['success' => true, 'message' => 'App updated successfully']);
        exit;
    }

    // Delete app
    if ($method === 'POST' && $action === 'delete') {
        $appId = (int) ($data['id'] ?? 0);

        if (!$appId) {
            echo json_encode(['success' => false, 'message' => 'App ID is required']);
            exit;
        }

        // Verify ownership
        $existingApp = $db->fetchOne(
            "SELECT * FROM user_apps WHERE id = ? AND user_id = ?",
            [$appId, $user['id']]
        );

        if (!$existingApp) {
            echo json_encode(['success' => false, 'message' => 'App not found or access denied']);
            exit;
        }

        // Soft delete (set is_active = 0)
        $db->update('user_apps', ['is_active' => 0], 'id = ? AND user_id = ?', [$appId, $user['id']]);

        echo json_encode(['success' => true, 'message' => 'App deleted successfully']);
        exit;
    }

    // Reorder apps
    if ($method === 'POST' && $action === 'reorder') {
        $orderData = $data['order'] ?? [];

        if (!is_array($orderData) || empty($orderData)) {
            echo json_encode(['success' => false, 'message' => 'Invalid order data']);
            exit;
        }

        // Update sort order for each app
        foreach ($orderData as $index => $appId) {
            $appId = (int) $appId;
            $sortOrder = $index + 1;

            // Verify ownership before updating
            $exists = $db->fetchOne(
                "SELECT id FROM user_apps WHERE id = ? AND user_id = ?",
                [$appId, $user['id']]
            );

            if ($exists) {
                $db->update('user_apps', ['sort_order' => $sortOrder], 'id = ? AND user_id = ?', [$appId, $user['id']]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Apps reordered successfully']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
