<?php
/**
 * Migration script for Apps module
 * Runs the SQL migrations to add user_apps table and apps module
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "Starting Apps module migration...\n";

// Execute user_apps table creation
$sql1 = file_get_contents(__DIR__ . '/add_user_apps_table.sql');
try {
    $db->query($sql1);
    echo "✓ Created user_apps table\n";
} catch (Exception $e) {
    echo "✗ Error creating user_apps table: " . $e->getMessage() . "\n";
}

// Execute apps module and permissions
$sql2 = file_get_contents(__DIR__ . '/add_apps_module.sql');
try {
    $db->query($sql2);
    echo "✓ Added apps module and permissions\n";
} catch (Exception $e) {
    echo "✗ Error adding apps module: " . $e->getMessage() . "\n";
}

echo "Migration complete.\n";
