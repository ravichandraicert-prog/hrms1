<?php
/**
 * Notification Polling API
 * Returns new notifications since last check for real-time updates
 * Core PHP HRMS Application
 *
 * BACKWARD COMPATIBLE:
 * - New API endpoint only - no changes to existing APIs
 * - Existing notification endpoints remain unchanged
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

    // Only GET method is supported for polling
    if ($method !== 'GET') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get last_check timestamp from query parameter
    $lastCheck = $_GET['last_check'] ?? null;

    // Get user's notification preferences
    try {
        $preferences = $db->fetchOne(
            "SELECT * FROM user_notification_preferences WHERE user_id = ?",
            [$user['id']]
        );
    } catch (Exception $e) {
        // Table might not exist yet, use defaults
        $preferences = null;
    }

    // If no preferences exist, default to all enabled
    $enabled = $preferences ? (bool)$preferences['enabled'] : true;
    $typesEnabled = $preferences ? json_decode($preferences['types_enabled'] ?? '{}', true) : null;

    // If notifications are globally disabled, return empty
    if (!$enabled) {
        echo json_encode([
            'success' => true,
            'notifications' => [],
            'unread_count' => 0,
            'last_check' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // Build query with type filtering based on preferences
    $params = [$user['id']];

    // Check if priority column exists
    $checkColumn = $db->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'priority'"
    );
    $hasPriority = $checkColumn && $checkColumn['count'] > 0;

    // Only return unread notifications - read items must not re-alert the user
    $sql = "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0";

    // Skip notification ids the client has already alerted for in this session,
    // so switching modules never re-triggers the same popups.
    $excludeIdsRaw = isset($_GET['exclude_ids']) ? trim((string)$_GET['exclude_ids']) : '';
    $excludeIds = [];
    if ($excludeIdsRaw !== '') {
        foreach (explode(',', $excludeIdsRaw) as $exId) {
            $exId = (int)$exId;
            if ($exId > 0) $excludeIds[$exId] = $exId;
        }
    }
    if (!empty($excludeIds)) {
        $excludeIds = array_slice($excludeIds, 0, 200);
        $idPlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= " AND id NOT IN ({$idPlaceholders})";
        $params = array_merge($params, array_values($excludeIds));
    }

    // Filter by last_check if provided
    if ($lastCheck) {
        $sql .= " AND created_at > ?";
        $params[] = $lastCheck;
    }

    // Filter by enabled types if preferences exist
    if ($typesEnabled && is_array($typesEnabled)) {
        // Idle-time reminders (created by api/idle.php) must always reach the
        // in-app toast AND the Electron desktop notification, so this type is
        // treated as enabled even if a stale preference snapshot omits it.
        $typesEnabled['idle'] = true;
        $enabledTypes = array_keys(array_filter($typesEnabled));
        if (!empty($enabledTypes)) {
            $typePlaceholders = implode(',', array_fill(0, count($enabledTypes), '?'));
            $sql .= " AND type IN ({$typePlaceholders})";
            $params = array_merge($params, $enabledTypes);
        }
    }

    $sql .= " ORDER BY created_at DESC LIMIT 50";

    $notifications = $db->fetchAll($sql, $params);

    // Get current unread count
    $unreadCount = $db->fetchOne(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$user['id']]
    );
    $unreadCount = $unreadCount['count'] ?? 0;

    // Update last_check timestamp in preferences
    if ($preferences) {
        try {
            $db->update(
                'user_notification_preferences',
                ['last_check_at' => date('Y-m-d H:i:s')],
                'user_id = ?',
                [$user['id']]
            );
        } catch (Exception $e) {
            // Ignore if update fails (table might not exist)
        }
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'last_check' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
