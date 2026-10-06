<?php
/**
 * Database Migration: Add Notification Preferences System
 *
 * This migration adds:
 * 1. user_notification_preferences table for user-level notification settings
 * 2. priority field to notifications table for task priority tracking
 *
 * BACKWARD COMPATIBLE:
 * - New table only (no modifications to existing tables except adding optional field)
 * - priority field added with DEFAULT NULL (existing rows unaffected)
 * - All existing functionality continues to work unchanged
 *
 * Run this script once to apply the migration.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "Starting notification preferences migration...\n";

try {
    $db = Database::getInstance();

    // Check if user_notification_preferences table already exists
    $checkTable = $db->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_notification_preferences'"
    );

    if ($checkTable['count'] > 0) {
        echo "✓ user_notification_preferences table already exists. Skipping creation.\n";
    } else {
        // Create user_notification_preferences table
        $sql = "CREATE TABLE `user_notification_preferences` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `enabled` tinyint(1) DEFAULT 1,
          `types_enabled` text DEFAULT NULL COMMENT 'JSON: {\"leave\":1,\"task\":1,\"attendance\":1,\"notice\":1,\"payroll\":1,\"approval\":1}',
          `browser_notifications` tinyint(1) DEFAULT 0,
          `sound_enabled` tinyint(1) DEFAULT 1,
          `last_check_at` datetime DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_user` (`user_id`),
          CONSTRAINT `user_notif_prefs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $db->query($sql);
        echo "✓ Created user_notification_preferences table\n";
    }

    // Check if priority field already exists in notifications table
    $checkColumn = $db->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'priority'"
    );

    if ($checkColumn['count'] > 0) {
        echo "✓ priority field already exists in notifications table. Skipping addition.\n";
    } else {
        // Add priority field to notifications table
        $sql = "ALTER TABLE `notifications` ADD COLUMN `priority` varchar(20) DEFAULT NULL COMMENT 'Low, Medium, High' AFTER `type`";
        $db->query($sql);
        echo "✓ Added priority field to notifications table\n";
    }

    // Insert default preferences for all existing users
    $users = $db->fetchAll("SELECT id FROM employees WHERE is_active = 1");
    $defaultTypes = json_encode([
        'leave' => 1,
        'task' => 1,
        'attendance' => 1,
        'notice' => 1,
        'payroll' => 1,
        'approval' => 1,
        'idle' => 1
    ]);

    $insertedCount = 0;
    foreach ($users as $user) {
        // Check if user already has preferences
        $existing = $db->fetchOne(
            "SELECT id FROM user_notification_preferences WHERE user_id = ?",
            [$user['id']]
        );

        if (!$existing) {
            $db->insert('user_notification_preferences', [
                'user_id' => $user['id'],
                'enabled' => 1,
                'types_enabled' => $defaultTypes,
                'browser_notifications' => 0,
                'sound_enabled' => 1
            ]);
            $insertedCount++;
        }
    }

    echo "✓ Inserted default preferences for {$insertedCount} existing users\n";

    echo "\n✅ Migration completed successfully!\n";
    echo "\nSummary:\n";
    echo "- user_notification_preferences table: created\n";
    echo "- notifications.priority field: added\n";
    echo "- Default preferences: created for {$insertedCount} users\n";
    echo "\nAll existing functionality remains unchanged and fully backward compatible.\n";

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Please check the error and try again.\n";
    exit(1);
}
