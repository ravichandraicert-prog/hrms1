<?php
/**
 * Common Functions
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/../config/database.php';

// Sanitize input
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Get current user data
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    if ($user === null) {
        $db = Database::getInstance();
        $user = $db->fetchOne(
            "SELECT e.*, r.name as role_name, r.hierarchy_level 
             FROM employees e 
             LEFT JOIN roles r ON e.role_id = r.id 
             WHERE e.id = ?",
            [getCurrentUserId()]
        );
    }
    return $user;
}

// Check if current user is Super Admin
function isSuperAdmin() {
    $user = getCurrentUser();
    return $user && isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1;
}

// Redirect to URL
function redirect($url) {
    header("Location: " . $url);
    exit;
}

// JSON response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Format date
function formatDate($date, $format = 'Y-m-d') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

// Format datetime
function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime)) return '';
    return date($format, strtotime($datetime));
}

// Convert UTC to local timezone
function utcToLocal($utcDateTime, $format = 'Y-m-d H:i:s') {
    if (empty($utcDateTime)) return '';
    $utc = new DateTime($utcDateTime, new DateTimeZone('UTC'));
    $utc->setTimezone(new DateTimeZone(TIMEZONE));
    return $utc->format($format);
}

// Convert local to UTC
function localToUtc($localDateTime, $format = 'Y-m-d H:i:s') {
    if (empty($localDateTime)) return '';
    $local = new DateTime($localDateTime, new DateTimeZone(TIMEZONE));
    $local->setTimezone(new DateTimeZone('UTC'));
    return $local->format($format);
}

// Generate employee ID
function generateEmployeeId($prefix = 'EMP', $startNumber = 1000, $padding = 4) {
    $db = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT employee_id FROM employees WHERE employee_id LIKE ?",
        [$prefix . '%']
    );
    $maxNum = $startNumber - 1;
    foreach ($rows as $row) {
        // Extract the numeric suffix after the prefix (handles separators like EMP-1001)
        $suffix = preg_replace('/\D+/', '', substr($row['employee_id'], strlen($prefix)));
        $num = (int) $suffix;
        if ($num > $maxNum) {
            $maxNum = $num;
        }
    }
    $nextNumber = $maxNum + 1;
    return $prefix . str_pad($nextNumber, $padding, '0', STR_PAD_LEFT);
}

// Get initials from name
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Calculate duration in minutes
function calculateMinutes($startTime, $endTime) {
    $start = strtotime($startTime);
    $end = strtotime($endTime);
    return round(($end - $start) / 60);
}

// Format minutes to hours
function formatMinutes($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
}

// Get a single application setting (key/value table)
function getSetting($key, $default = null) {
    $db = Database::getInstance();
    try {
        $row = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row !== false ? $row['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Check permission
function hasPermission($module, $action, $scope = null) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Super Admin has all permissions
    if (isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) return true;
    
    $db = Database::getInstance();
    
    // Check role-based permission
    $params = [$user['role_id'], $module, $action];
    $sql = "SELECT p.allowed, p.scope FROM permissions p 
             JOIN modules m ON p.module_id = m.id 
             WHERE p.role_id = ? AND m.name = ? AND p.action = ?";
    
    if ($scope) {
        $sql .= " AND p.scope = ?";
        $params[] = $scope;
    }
    
    $permission = $db->fetchOne($sql, $params);
    
    // Check user-level override
    $overrideParams = [$user['id'], $module, $action];
    $overrideSql = "SELECT up.allowed, up.scope FROM user_permissions up 
                    JOIN modules m ON up.module_id = m.id 
                    WHERE up.user_id = ? AND m.name = ? AND up.action = ?";
    
    if ($scope) {
        $overrideSql .= " AND up.scope = ?";
        $overrideParams[] = $scope;
    }
    
    $override = $db->fetchOne($overrideSql, $overrideParams);
    
    // Override takes precedence
    if ($override !== false) {
        return (bool) $override['allowed'];
    }
    
    return $permission !== false && (bool) $permission['allowed'];
}

// Check if user has ANY permission for a module (for sidebar visibility)
function hasModuleAccess($module) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Super Admin has access to all modules
    if ($user['hierarchy_level'] == 1) return true;
    
    $db = Database::getInstance();
    
    // Check if user has ANY permission (read, create, update, delete, etc.) for this module
    $permission = $db->fetchOne(
        "SELECT p.allowed FROM permissions p 
         JOIN modules m ON p.module_id = m.id 
         WHERE p.role_id = ? AND m.name = ? AND p.allowed = 1",
        [$user['role_id'], $module]
    );
    
    // Check user-level override
    $override = $db->fetchOne(
        "SELECT up.allowed FROM user_permissions up 
         JOIN modules m ON up.module_id = m.id 
         WHERE up.user_id = ? AND m.name = ? AND up.allowed = 1",
        [$user['id'], $module]
    );
    
    // Override takes precedence
    if ($override !== false) {
        return (bool) $override['allowed'];
    }
    
    return $permission !== false;
}

// Get data scope for current user
function getDataScope($module) {
    $user = getCurrentUser();
    if (!$user) return 'self';
    
    if ($user['hierarchy_level'] == 1) return 'all';
    
    $db = Database::getInstance();
    $permission = $db->fetchOne(
        "SELECT p.scope FROM permissions p 
         JOIN modules m ON p.module_id = m.id 
         WHERE p.role_id = ? AND m.name = ? AND p.action = 'read'",
        [$user['role_id'], $module]
    );
    
    // Check user override
    $override = $db->fetchOne(
        "SELECT up.scope FROM user_permissions up 
         JOIN modules m ON up.module_id = m.id 
         WHERE up.user_id = ? AND m.name = ? AND up.action = 'read'",
        [$user['id'], $module]
    );
    
    return $override['scope'] ?? $permission['scope'] ?? 'self';
}

// Log audit trail
function logAudit($entityType, $entityId, $action, $before = null, $after = null) {
    $db = Database::getInstance();
    $data = [
        'user_id' => getCurrentUserId(),
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => $action,
        'before_data' => $before ? json_encode($before) : null,
        'after_data' => $after ? json_encode($after) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    return $db->insert('audit_log', $data);
}

// Send notification (BACKWARD COMPATIBLE - priority parameter is optional)
function sendNotification($userId, $type, $title, $message, $link = null, $priority = null) {
    $db = Database::getInstance();

    // Check user's notification preferences (BACKWARD COMPATIBLE: if no preferences, send anyway)
    try {
        $preferences = $db->fetchOne(
            "SELECT * FROM user_notification_preferences WHERE user_id = ?",
            [$userId]
        );
    } catch (Exception $e) {
        $preferences = null;
    }

    if ($preferences) {
        // Check if notifications are globally disabled
        if (!$preferences['enabled']) {
            return false;
        }

        // Check if this type is enabled
        $typesEnabled = json_decode($preferences['types_enabled'] ?? '{}', true);
        if (is_array($typesEnabled) && !empty($typesEnabled)) {
            // Map type to preference key
            $typeKey = $type;
            if (!isset($typesEnabled[$typeKey])) {
                // Try to match by substring
                foreach ($typesEnabled as $key => $enabled) {
                    if (strpos($type, $key) !== false || strpos($key, $type) !== false) {
                        $typeKey = $key;
                        break;
                    }
                }
            }

            if (isset($typesEnabled[$typeKey]) && !$typesEnabled[$typeKey]) {
                return false; // Type is disabled
            }
        }
    }

    // Check if priority column exists
    $checkColumn = $db->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'priority'"
    );
    $hasPriority = $checkColumn && $checkColumn['count'] > 0;

    $data = [
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link' => $link,
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Only add priority if column exists
    if ($hasPriority && $priority) {
        $data['priority'] = $priority;
    }

    return $db->insert('notifications', $data);
}

// Send bulk notification to multiple users by role/department (NEW FUNCTION)
function sendBulkNotification($type, $title, $message, $link = null, $priority = null, $targetType = 'all', $targetRoles = null, $targetDepartments = null, $targetEmployees = null) {
    $db = Database::getInstance();

    // Build query to get target employees
    $sql = "SELECT DISTINCT e.id FROM employees e WHERE e.is_active = 1";
    $params = [];

    if ($targetType === 'role' && $targetRoles) {
        $roleIds = is_array($targetRoles) ? $targetRoles : explode(',', $targetRoles);
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $sql .= " AND e.role_id IN ({$placeholders})";
        $params = array_merge($params, $roleIds);
    } elseif ($targetType === 'department' && $targetDepartments) {
        // Use employee_departments many-to-many table
        $deptIds = is_array($targetDepartments) ? $targetDepartments : explode(',', $targetDepartments);
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        $sql .= " AND e.id IN (SELECT employee_id FROM employee_departments WHERE department_id IN ({$placeholders}))";
        $params = array_merge($params, $deptIds);
    } elseif ($targetType === 'custom' && $targetEmployees) {
        $empIds = is_array($targetEmployees) ? $targetEmployees : explode(',', $targetEmployees);
        $placeholders = implode(',', array_fill(0, count($empIds), '?'));
        $sql .= " AND e.id IN ({$placeholders})";
        $params = array_merge($params, $empIds);
    }

    $employees = $db->fetchAll($sql, $params);

    $sentCount = 0;
    foreach ($employees as $employee) {
        // Send notification to each employee (respects their individual preferences)
        $result = sendNotification($employee['id'], $type, $title, $message, $link, $priority);
        if ($result) {
            $sentCount++;
        }
    }

    return $sentCount;
}

// Send notifications for a notice to its targeted users (idempotent - never duplicates)
function sendNoticeNotifications($noticeId, $title, $body, $noticeData) {
    if (!$noticeId || !is_array($noticeData)) {
        return 0;
    }
    $db = Database::getInstance();

    // Idempotency guard: if this notice was already broadcast, do not resend.
    // The notice id is embedded in the link so each notice has a unique target.
    $link = '?module=notices&notice=' . $noticeId;
    $existing = $db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE type = 'notice' AND link = ?",
        [$link]
    );
    if ($existing && (int) $existing['cnt'] > 0) {
        return 0;
    }

    return sendBulkNotification(
        'notice',
        $title,
        $body,
        $link,
        $noticeData['priority'] ?? 'Low',
        $noticeData['target_type'] ?? 'all',
        $noticeData['target_roles'] ?? null,
        $noticeData['target_departments'] ?? null
    );
}

// Get unread notification count
function getUnreadNotificationCount($userId = null) {
    $userId = $userId ?? getCurrentUserId();
    if (!$userId) return 0;
    
    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId]
    );
    return $result['count'] ?? 0;
}

// Get unread notice notification count
function getUnreadNoticeNotificationCount($userId = null) {
    $userId = $userId ?? getCurrentUserId();
    if (!$userId) return 0;
    
    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'notice' AND is_read = 0",
        [$userId]
    );
    return $result['count'] ?? 0;
}

// Upload file
function uploadFile($file, $subdirectory = '') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds limit'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_FILE_TYPES)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    $uploadDir = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($subdirectory, '/\\');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $filename = uniqid() . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $filepath];
    }
    
    return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

// Pagination helper
function paginate($query, $params, $page = 1, $perPage = RECORDS_PER_PAGE) {
    $offset = ($page - 1) * $perPage;
    $db = Database::getInstance();
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM ({$query}) as subquery";
    $total = $db->fetchOne($countQuery, $params)['total'];
    
    // Get paginated data
    $dataQuery = $query . " LIMIT {$perPage} OFFSET {$offset}";
    $data = $db->fetchAll($dataQuery, $params);
    
    return [
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ];
}

