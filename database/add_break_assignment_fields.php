<?php
// Add assignment fields to break_types table
// This script should be run via web browser: http://localhost/HRMS/database/add_break_assignment_fields.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = Database::getInstance();
    
    echo "<h2>Break Types Database Migration</h2>";
    
    // Check if assign_to column exists in break_types
    $checkAssignTo = $db->fetchOne("SHOW COLUMNS FROM break_types LIKE 'assign_to'");
    if (!$checkAssignTo) {
        // Add assign_to column
        $db->query("ALTER TABLE `break_types` ADD COLUMN `assign_to` VARCHAR(50) DEFAULT 'all_employees' COMMENT 'all_employees, department, role, user, shift' AFTER `description`");
        echo "<p style='color:green'>✓ Added assign_to column to break_types successfully.</p>";
    } else {
        echo "<p style='color:blue'>ℹ assign_to column already exists in break_types.</p>";
    }
    
    // Check if target_id column exists in break_types
    $checkTargetId = $db->fetchOne("SHOW COLUMNS FROM break_types LIKE 'target_id'");
    if (!$checkTargetId) {
        // Add target_id column
        $db->query("ALTER TABLE `break_types` ADD COLUMN `target_id` INT(11) DEFAULT NULL COMMENT 'ID of the department, role, user, or shift based on assign_to' AFTER `assign_to`");
        echo "<p style='color:green'>✓ Added target_id column to break_types successfully.</p>";
    } else {
        echo "<p style='color:blue'>ℹ target_id column already exists in break_types.</p>";
    }
    
    echo "<p style='color:green; font-weight:bold'>Break types table schema updated successfully!</p>";
    echo "<p><a href='../modules/settings.php?section=breaks'>Return to Settings</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
