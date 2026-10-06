<?php
// Run the foreign key constraint migration via web interface
require_once __DIR__ . '/../config/database.php';

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($mysqli->connect_error) {
        die('Connection failed: ' . $mysqli->connect_error);
    }
    
    // Step 1: Make leave_type_id nullable
    $modifyColumn = "ALTER TABLE `leave_applications` MODIFY COLUMN `leave_type_id` int(11) DEFAULT NULL";
    if (!$mysqli->query($modifyColumn)) {
        die('Error making column nullable: ' . $mysqli->error);
    }
    
    // Step 2: Check if the foreign key exists before dropping
    $checkFk = $mysqli->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'leave_applications' 
        AND CONSTRAINT_NAME = 'leave_applications_ibfk_2'
    ");
    
    if ($checkFk && $checkFk->num_rows > 0) {
        // Foreign key exists, drop it
        $dropFk = "ALTER TABLE `leave_applications` DROP FOREIGN KEY `leave_applications_ibfk_2`";
        if (!$mysqli->query($dropFk)) {
            die('Error dropping foreign key: ' . $mysqli->error);
        }
    }
    
    // Step 3: Re-add the foreign key with ON DELETE SET NULL
    $addFk = "ALTER TABLE `leave_applications` ADD CONSTRAINT `leave_applications_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE SET NULL";
    if (!$mysqli->query($addFk)) {
        die('Error adding foreign key: ' . $mysqli->error);
    }
    
    echo 'Migration completed successfully! The leave_type_id column is now nullable and the foreign key constraint has been updated to ON DELETE SET NULL.';
    
    $mysqli->close();
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
