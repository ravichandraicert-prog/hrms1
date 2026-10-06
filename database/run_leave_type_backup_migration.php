<?php
// Run the leave type name backup migration via web interface
require_once __DIR__ . '/../config/database.php';

$sql = file_get_contents(__DIR__ . '/add_leave_type_name_backup.sql');

if (!$sql) {
    die('Error: Could not read migration file');
}

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($mysqli->connect_error) {
        die('Connection failed: ' . $mysqli->connect_error);
    }
    
    // Check if column already exists
    $check = $mysqli->query("SHOW COLUMNS FROM leave_applications LIKE 'leave_type_name_backup'");
    if ($check->num_rows > 0) {
        echo 'Column leave_type_name_backup already exists in leave_applications table.';
        $mysqli->close();
        exit;
    }
    
    // Add the column
    $addColumn = "ALTER TABLE `leave_applications` ADD COLUMN `leave_type_name_backup` VARCHAR(100) DEFAULT NULL AFTER `leave_type_id`";
    if (!$mysqli->query($addColumn)) {
        die('Error adding column: ' . $mysqli->error);
    }
    
    // Update existing records with current leave type names
    $updateNames = "UPDATE `leave_applications` la LEFT JOIN `leave_types` lt ON la.leave_type_id = lt.id SET la.leave_type_name_backup = lt.name WHERE la.leave_type_name_backup IS NULL";
    if (!$mysqli->query($updateNames)) {
        die('Error updating records: ' . $mysqli->error);
    }
    
    echo 'Migration completed successfully! The leave_type_name_backup column has been added to leave_applications table and existing records have been updated.';
    
    $mysqli->close();
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
