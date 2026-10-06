<?php
/**
 * Add festival_type column to leave_applications table
 * Access this file via: http://localhost/HRMS/add_festival_type_column.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

try {
    $db = Database::getInstance();
    
    echo "Adding festival_type column to leave_applications table...\n\n";
    
    // Check if festival_type column already exists
    $checkColumn = $db->fetchOne("SHOW COLUMNS FROM leave_applications LIKE 'festival_type'");
    if ($checkColumn) {
        echo "festival_type column already exists in leave_applications table.\n";
    } else {
        $db->query("ALTER TABLE `leave_applications` 
            ADD COLUMN `festival_type` varchar(20) DEFAULT NULL 
            COMMENT 'Festival type: Fixed or Optional' 
            AFTER `festival_holiday_id`");
        echo "✓ Added festival_type column to leave_applications table.\n";
    }
    
    echo "\nColumn addition completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
