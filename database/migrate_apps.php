<?php
/**
 * Web-based migration script for Apps module
 * Access this file via browser: http://localhost/hrms1/database/migrate_apps.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "<h2>Apps Module Migration</h2>";
echo "<pre>";

try {
    // Execute user_apps table creation
    $sql1 = file_get_contents(__DIR__ . '/add_user_apps_table.sql');
    $db->query($sql1);
    echo "✓ Created user_apps table\n";
} catch (Exception $e) {
    echo "✗ Error creating user_apps table: " . $e->getMessage() . "\n";
}

try {
    // Execute apps module and permissions
    $sql2 = file_get_contents(__DIR__ . '/add_apps_module.sql');
    $db->query($sql2);
    echo "✓ Added apps module and permissions\n";
} catch (Exception $e) {
    echo "✗ Error adding apps module: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p>Migration complete. <a href='../index.php?module=apps'>Go to Apps module</a></p>";
