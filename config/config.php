<?php
/**
 * Orbit HRMS - Configuration File
 * Core PHP HRMS Application
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'hrms1');
define('DB_USER', 'root');
define('DB_PASS', 'GlobalIndia@123$');

// Application Configuration
define('APP_NAME', 'Orbit HRMS');
define('APP_VERSION', '2.0.0');
define('BASE_URL', 'http://192.168.1.131/hrms1');
define('SITE_URL', BASE_URL);

// Timezone Configuration
define('TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set(TIMEZONE);

// Session Configuration
define('SESSION_NAME', 'ORBIT_HRMS_SESSION');
//define('SESSION_LIFETIME', 604800); // 7 days

// File Upload Configuration
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'csv', 'txt']);

// Pagination
define('RECORDS_PER_PAGE', 25);

// Error Reporting (Production)
error_reporting(0);
ini_set('display_errors', 0);

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}
