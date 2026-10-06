<?php
/**
 * Check and fix employee_departments table structure
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    echo "Checking employee_departments table structure...\n";
    
    // Get current table structure
    $columns = $db->fetchAll("DESCRIBE employee_departments");
    echo "Current columns:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}, Default: {$col['Default']})\n";
    }
    
    // Check if is_primary column exists and its default
    $isPrimaryCol = null;
    foreach ($columns as $col) {
        if ($col['Field'] === 'is_primary') {
            $isPrimaryCol = $col;
            break;
        }
    }
    
    if ($isPrimaryCol) {
        echo "\nis_primary column exists with default: {$isPrimaryCol['Default']}\n";
        
        // If default is 1, we should update it to 0 for new records
        if ($isPrimaryCol['Default'] === '1') {
            echo "WARNING: is_primary default is 1, should be 0 for new records\n";
            echo "Consider running: ALTER TABLE employee_departments MODIFY COLUMN is_primary TINYINT(1) DEFAULT 0;\n";
        }
    } else {
        echo "\nERROR: is_primary column not found!\n";
    }
    
    // Check for duplicate employee-department combinations
    echo "\nChecking for duplicate employee-department combinations...\n";
    $duplicates = $db->fetchAll("
        SELECT employee_id, department_id, COUNT(*) as count
        FROM employee_departments
        GROUP BY employee_id, department_id
        HAVING count > 1
    ");
    
    if (empty($duplicates)) {
        echo "No duplicate employee-department combinations found.\n";
    } else {
        echo "Found " . count($duplicates) . " duplicate combinations:\n";
        foreach ($duplicates as $dup) {
            echo "  - Employee ID: {$dup['employee_id']}, Department ID: {$dup['department_id']} (Count: {$dup['count']})\n";
        }
    }
    
    // Check primary department counts
    echo "\nChecking primary department assignments...\n";
    $primaryCounts = $db->fetchAll("
        SELECT 
            e.id,
            e.employee_id,
            e.first_name,
            e.last_name,
            COUNT(CASE WHEN ed.is_primary = 1 THEN 1 END) as primary_count,
            COUNT(CASE WHEN ed.is_primary = 0 THEN 1 END) as secondary_count
        FROM employees e
        LEFT JOIN employee_departments ed ON e.id = ed.employee_id
        GROUP BY e.id
        HAVING primary_count != 1 OR secondary_count > 0
    ");
    
    if (empty($primaryCounts)) {
        echo "All employees have exactly one primary department.\n";
    } else {
        echo "Found " . count($primaryCounts) . " employees with incorrect primary department assignments:\n";
        foreach ($primaryCounts as $emp) {
            echo "  - {$emp['employee_id']} ({$emp['first_name']} {$emp['last_name']}): Primary={$emp['primary_count']}, Secondary={$emp['secondary_count']}\n";
        }
    }
    
    echo "\nCheck complete.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
