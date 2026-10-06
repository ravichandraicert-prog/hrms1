<?php
/**
 * Migration runner for adding is_in_hierarchy column to employee_departments
 * Run via web browser: http://192.168.1.131/HRMS/database/run_hierarchy_migration.php
 */

require_once __DIR__ . '/../config/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<pre>Connected to database successfully.\n";

// Check if is_in_hierarchy column already exists
$checkColumn = $conn->query("SHOW COLUMNS FROM employee_departments LIKE 'is_in_hierarchy'");
if ($checkColumn && $checkColumn->num_rows == 0) {
    $result = $conn->query("ALTER TABLE `employee_departments` ADD COLUMN `is_in_hierarchy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_managerial`");
    if ($result) {
        echo "✓ Added is_in_hierarchy column to employee_departments table.\n";
    } else {
        echo "✗ Failed to add column: " . $conn->error . "\n";
    }
} else {
    echo "✓ is_in_hierarchy column already exists in employee_departments table.\n";
}

// Backfill: Set is_in_hierarchy = 1 for employees who have a reporting_manager_id set
$result = $conn->query("UPDATE employee_departments ed JOIN employees e ON ed.employee_id = e.id SET ed.is_in_hierarchy = 1 WHERE e.reporting_manager_id IS NOT NULL");
if ($result) {
    echo "✓ Backfilled is_in_hierarchy for employees with reporting managers.\n";
} else {
    echo "✗ Failed to backfill: " . $conn->error . "\n";
}

echo "\n✓ Migration completed successfully!\n";
echo "</pre>";

$conn->close();
?>