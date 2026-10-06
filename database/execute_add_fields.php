<?php
// Direct database connection for schema update
require_once __DIR__ . '/../config/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if assign_to column exists
    $checkAssignTo = $pdo->query("SHOW COLUMNS FROM leave_types LIKE 'assign_to'");
    if ($checkAssignTo->rowCount() == 0) {
        // Add assign_to column
        $pdo->exec("ALTER TABLE `leave_types` ADD COLUMN `assign_to` VARCHAR(50) DEFAULT 'all_employees' COMMENT 'all_employees, department, role, user' AFTER `applicable_to`");
        echo "Added assign_to column successfully.<br>";
    } else {
        echo "assign_to column already exists.<br>";
    }
    
    // Check if target_id column exists
    $checkTargetId = $pdo->query("SHOW COLUMNS FROM leave_types LIKE 'target_id'");
    if ($checkTargetId->rowCount() == 0) {
        // Add target_id column
        $pdo->exec("ALTER TABLE `leave_types` ADD COLUMN `target_id` INT(11) DEFAULT NULL COMMENT 'ID of the department, role, or user based on assign_to' AFTER `assign_to`");
        echo "Added target_id column successfully.<br>";
    } else {
        echo "target_id column already exists.<br>";
    }
    
    echo "Database schema updated successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
