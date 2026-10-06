<?php
// Web-accessible script to add duration column
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "<h2>Adding duration column to leave_applications table</h2>";

try {
    $db->query("ALTER TABLE `leave_applications` ADD COLUMN `duration` VARCHAR(20) DEFAULT 'full' COMMENT 'full, first_half, second_half' AFTER `end_date`");
    echo "<p style='color:green;'>SUCCESS: duration column added to leave_applications table</p>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<p style='color:blue;'>INFO: duration column already exists</p>";
    } else {
        echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<p><a href='../index.php'>Return to Home</a></p>";
