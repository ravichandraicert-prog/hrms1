<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    // Check if assign_to column exists
    $checkAssignTo = $db->fetchOne("SHOW COLUMNS FROM leave_types LIKE 'assign_to'");
    if (!$checkAssignTo) {
        $db->query("ALTER TABLE leave_types ADD COLUMN assign_to VARCHAR(50) DEFAULT 'all_employees' COMMENT 'all_employees, department, role, user' AFTER applicable_to");
        echo "Added assign_to column successfully.\n";
    } else {
        echo "assign_to column already exists.\n";
    }
    
    // Check if target_id column exists
    $checkTargetId = $db->fetchOne("SHOW COLUMNS FROM leave_types LIKE 'target_id'");
    if (!$checkTargetId) {
        $db->query("ALTER TABLE leave_types ADD COLUMN target_id INT(11) DEFAULT NULL COMMENT 'ID of the department, role, or user based on assign_to' AFTER assign_to");
        echo "Added target_id column successfully.\n";
    } else {
        echo "target_id column already exists.\n";
    }
    
    echo "Database schema updated successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
