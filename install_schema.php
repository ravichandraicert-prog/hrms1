<?php
/**
 * Schema Installation Script for Pay Cycle System
 * Run this file to install the new pay cycle database schema
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

echo "Starting Pay Cycle Schema Installation...\n";

try {
    $db = Database::getInstance();
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/database/payroll_schema_update.sql';
    if (!file_exists($sqlFile)) {
        die("SQL file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split SQL into individual statements
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $db->execute($statement);
            echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
        } catch (Exception $e) {
            // Some statements might fail if they already exist
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "⊘ Skipped (already exists): " . substr($statement, 0, 50) . "...\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\nPay Cycle Schema Installation Completed Successfully!\n";
    
} catch (Exception $e) {
    die("Installation failed: " . $e->getMessage() . "\n");
}
