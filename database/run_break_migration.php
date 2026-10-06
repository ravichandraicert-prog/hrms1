<?php
/**
 * Migration script to add min_duration_minutes and max_duration_minutes columns to break_types table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load database config
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    // Check if columns already exist
    $columns = $db->fetchAll("SHOW COLUMNS FROM break_types LIKE 'min_duration_minutes'");
    
    if (count($columns) > 0) {
        echo "Columns min_duration_minutes and max_duration_minutes already exist.\n";
        exit;
    }
    
    // Add the columns
    $db->query("ALTER TABLE `break_types` ADD COLUMN `min_duration_minutes` int(11) DEFAULT NULL AFTER `default_duration_minutes`");
    echo "Added min_duration_minutes column.\n";
    
    $db->query("ALTER TABLE `break_types` ADD COLUMN `max_duration_minutes` int(11) DEFAULT NULL AFTER `min_duration_minutes`");
    echo "Added max_duration_minutes column.\n";
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
