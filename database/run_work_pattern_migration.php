<?php
/**
 * Work Pattern Migration Script
 * Executes the work patterns schema update
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "Starting Work Pattern Schema Migration...\n";

try {
    $db->beginTransaction();
    
    // Read and execute the SQL file
    $sqlFile = __DIR__ . '/work_patterns_schema_update.sql';
    $sql = file_get_contents($sqlFile);
    
    // Split by individual statements
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && strpos($statement, '--') !== 0) {
            try {
                $db->query($statement);
                echo "Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (Exception $e) {
                // Some statements might fail if columns already exist
                echo "Skipped (may already exist): " . substr($statement, 0, 50) . "...\n";
            }
        }
    }
    
    $db->commit();
    echo "Work Pattern Schema Migration completed successfully!\n";
    
} catch (Exception $e) {
    $db->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
