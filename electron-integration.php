<?php
/**
 * Electron Integration Configuration
 * This file provides configuration for running HRMS in Electron app
 * 
 * This file can be used by Electron's main process to:
 * 1. Get server configuration for PHP built-in server
 * 2. Verify database connectivity
 * 3. Get application configuration details
 */

// Load main configuration
require_once __DIR__ . '/config/config.php';

// Return configuration array for Electron
return [
    // PHP built-in server configuration
    'server' => [
        'host' => '127.0.0.1',
        'port' => 8000,
        'docroot' => __DIR__,
        'router' => __DIR__ . '/index.php'
    ],
    
    // Database configuration
    'database' => [
        'host' => DB_HOST,
        'name' => DB_NAME,
        'user' => DB_USER,
        'pass' => DB_PASS,
        'charset' => 'utf8mb4'
    ],
    
    // Application configuration
    'app' => [
        'name' => APP_NAME,
        'version' => APP_VERSION,
        'base_url' => BASE_URL,
        'timezone' => TIMEZONE,
        'upload_dir' => UPLOAD_DIR
    ],
    
    // File upload configuration
    'uploads' => [
        'directory' => UPLOAD_DIR,
        'max_size' => MAX_FILE_SIZE,
        'allowed_types' => ALLOWED_FILE_TYPES
    ],
    
    // Session configuration
    'session' => [
        'name' => SESSION_NAME,
        'lifetime' => null // Session timeout disabled
    ],
    
    // API endpoints
    'api_endpoints' => [
        'base' => BASE_URL . '/api',
        'clock' => BASE_URL . '/api/clock.php',
        'attendance' => BASE_URL . '/api/attendance.php',
        'employees' => BASE_URL . '/api/employees.php',
        'leaves' => BASE_URL . '/api/leaves.php',
        'departments' => BASE_URL . '/api/department.php',
        'settings' => BASE_URL . '/api/settings.php',
        'notification_poll' => BASE_URL . '/api/notification_poll.php'
    ],

    // Notification configuration for Electron
    'notifications' => [
        'enabled' => true,
        'poll_interval' => 30000, // 30 seconds
        'sound_enabled' => true,
        'icon_path' => __DIR__ . '/assets/icon.png' // Optional: path to app icon
    ]
];

/**
 * Test database connection
 * This function can be called from Electron to verify database connectivity
 */
function testDatabaseConnection() {
    try {
        require_once __DIR__ . '/config/database.php';
        $db = Database::getInstance();
        $result = $db->fetchOne("SELECT 1 as test");
        return [
            'success' => true,
            'message' => 'Database connection successful',
            'database' => DB_NAME,
            'host' => DB_HOST
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'database' => DB_NAME,
            'host' => DB_HOST
        ];
    }
}

/**
 * Get server startup command for Electron
 * Returns the command to start PHP built-in server
 */
function getServerStartupCommand() {
    $config = include(__DIR__ . '/electron-integration.php');
    $host = $config['server']['host'];
    $port = $config['server']['port'];
    $docroot = $config['server']['docroot'];
    
    return "php -S {$host}:{$port} -t {$docroot}";
}

// If this file is accessed directly, return JSON configuration
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(include(__DIR__ . '/electron-integration.php'));
    exit;
}
?>