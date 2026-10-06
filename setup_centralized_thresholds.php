<?php
/**
 * One-click setup script for centralized attendance thresholds
 * Run this file in your browser: http://localhost/HRMS/setup_centralized_thresholds.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();

echo "<h2>Centralized Attendance Threshold Setup</h2>";
echo "<p>This script will create the required database tables for the centralized attendance threshold system.</p>";

try {
    // Create attendance_settings table
    $createSettingsTable = "
    CREATE TABLE IF NOT EXISTS `attendance_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `present_threshold_minutes` int(11) NOT NULL DEFAULT 495 COMMENT 'Present threshold in minutes (default: 8:15 = 495 minutes)',
      `half_day_threshold_minutes` int(11) NOT NULL DEFAULT 255 COMMENT 'Half Day threshold in minutes (default: 4:15 = 255 minutes)',
      `effective_date` date NOT NULL DEFAULT (CURDATE()) COMMENT 'Date when these thresholds become effective',
      `created_by` int(11) DEFAULT NULL COMMENT 'User who created/last modified these settings',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `created_by` (`created_by`),
      CONSTRAINT `attendance_settings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->query($createSettingsTable);
    echo "<p style='color:green;'>✓ Created attendance_settings table</p>";
    
    // Insert default settings if they don't exist
    $checkExisting = $db->fetchOne("SELECT COUNT(*) as count FROM attendance_settings");
    if ($checkExisting['count'] == 0) {
        $insertDefault = "INSERT INTO `attendance_settings` (`present_threshold_minutes`, `half_day_threshold_minutes`, `effective_date`)
                         VALUES (495, 255, CURDATE())";
        $db->query($insertDefault);
        echo "<p style='color:green;'>✓ Inserted default threshold settings (Present: 8:15, Half Day: 4:15)</p>";
    } else {
        echo "<p style='color:blue;'>✓ Attendance settings already exist, skipping default insert</p>";
    }
    
    // Create attendance_threshold_history table
    $createHistoryTable = "
    CREATE TABLE IF NOT EXISTS `attendance_threshold_history` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `attendance_settings_id` int(11) NOT NULL COMMENT 'Reference to the attendance_settings record',
      `present_threshold_minutes` int(11) NOT NULL,
      `half_day_threshold_minutes` int(11) NOT NULL,
      `effective_date` date NOT NULL,
      `end_date` date DEFAULT NULL COMMENT 'When this configuration was replaced (NULL if current)',
      `changed_by` int(11) DEFAULT NULL,
      `changed_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `change_reason` text DEFAULT NULL COMMENT 'Optional reason for the change',
      PRIMARY KEY (`id`),
      KEY `attendance_settings_id` (`attendance_settings_id`),
      KEY `effective_date` (`effective_date`),
      KEY `changed_by` (`changed_by`),
      CONSTRAINT `threshold_history_ibfk_1` FOREIGN KEY (`attendance_settings_id`) REFERENCES `attendance_settings` (`id`) ON DELETE CASCADE,
      CONSTRAINT `threshold_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->query($createHistoryTable);
    echo "<p style='color:green;'>✓ Created attendance_threshold_history table</p>";
    
    echo "<h3 style='color:green;'>Setup Complete!</h3>";
    echo "<p>The centralized attendance threshold system is now ready to use.</p>";
    echo "<p><a href='index.php?module=settings&section=attendance'>Go to Settings → Attendance to configure thresholds</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Setup failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>