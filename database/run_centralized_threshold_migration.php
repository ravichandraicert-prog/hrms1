<?php
/**
 * Migration script to create centralized attendance threshold tables
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "Starting centralized attendance threshold migration...\n";

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
    echo "✓ Created attendance_settings table\n";
    
    // Insert default settings if they don't exist
    $checkExisting = $db->fetchOne("SELECT COUNT(*) as count FROM attendance_settings");
    if ($checkExisting['count'] == 0) {
        $insertDefault = "INSERT INTO `attendance_settings` (`present_threshold_minutes`, `half_day_threshold_minutes`, `effective_date`)
                         VALUES (495, 255, CURDATE())";
        $db->query($insertDefault);
        echo "✓ Inserted default threshold settings (Present: 8:15, Half Day: 4:15)\n";
    } else {
        echo "✓ Attendance settings already exist, skipping default insert\n";
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
    echo "✓ Created attendance_threshold_history table\n";
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>