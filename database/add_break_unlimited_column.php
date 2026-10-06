<?php
/**
 * Migration script to add is_unlimited column to break_types table
 * Unlimited break types (e.g. Meeting, Official, Training) have no fixed duration.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();

    $columns = $db->fetchAll("SHOW COLUMNS FROM break_types LIKE 'is_unlimited'");

    if (count($columns) > 0) {
        echo "Column is_unlimited already exists.\n";
        exit;
    }

    $db->query("ALTER TABLE `break_types` ADD COLUMN `is_unlimited` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_duration_minutes`");
    echo "Added is_unlimited column.\n";

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
