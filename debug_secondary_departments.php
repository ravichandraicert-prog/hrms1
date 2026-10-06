<?php
/**
 * Debug script to check secondary department functionality
 * This will help identify why secondary departments are not being saved
 */

require_once __DIR__ . '/config/database.php';

echo "=== Secondary Department Debug ===\n\n";

try {
    $db = Database::getInstance();
    
    // 1. Check current database structure
    echo "1. Current employee_departments table structure:\n";
    $columns = $db->fetchAll("DESCRIBE employee_departments");
    foreach ($columns as $col) {
        echo "   - {$col['Field']} ({$col['Type']}, Default: {$col['Default']}, Null: {$col['Null']})\n";
    }
    
    // 2. Check current data
    echo "\n2. Current employee_departments data:\n";
    $data = $db->fetchAll("SELECT * FROM employee_departments ORDER BY id DESC LIMIT 10");
    if (empty($data)) {
        echo "   No data found\n";
    } else {
        foreach ($data as $row) {
            echo "   ID: {$row['id']}, Employee: {$row['employee_id']}, Dept: {$row['department_id']}, Primary: {$row['is_primary']}, Managerial: {$row['is_managerial']}, In Hierarchy: {$row['is_in_hierarchy']}\n";
        }
    }
    
    // 3. Check for any secondary departments (is_primary = 0)
    echo "\n3. Checking for secondary departments (is_primary = 0):\n";
    $secondaryCount = $db->fetchOne("SELECT COUNT(*) as count FROM employee_departments WHERE is_primary = 0");
    echo "   Secondary department records: {$secondaryCount['count']}\n";
    
    // 4. Check primary department counts per employee
    echo "\n4. Primary department assignments per employee:\n";
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
        ORDER BY e.id
        LIMIT 10
    ");
    
    foreach ($primaryCounts as $emp) {
        echo "   {$emp['employee_id']} ({$emp['first_name']} {$emp['last_name']}): Primary={$emp['primary_count']}, Secondary={$emp['secondary_count']}\n";
    }
    
    // 5. Test if we can manually insert a secondary department
    echo "\n5. Testing manual secondary department insertion:\n";
    $testEmployee = $db->fetchOne("SELECT id FROM employees LIMIT 1");
    if ($testEmployee) {
        $empId = $testEmployee['id'];
        
        // Get a department that's not already assigned as primary
        $existingDepts = $db->fetchAll("SELECT department_id FROM employee_departments WHERE employee_id = ?", [$empId]);
        $existingDeptIds = array_column($existingDepts, 'department_id');
        
        $availableDept = $db->fetchOne("SELECT id FROM departments WHERE id NOT IN (" . implode(',', $existingDeptIds + [0]) . ") LIMIT 1");
        
        if ($availableDept) {
            echo "   Attempting to add secondary department for employee {$empId}, department {$availableDept['id']}...\n";
            
            try {
                $db->insert('employee_departments', [
                    'employee_id' => $empId,
                    'department_id' => $availableDept['id'],
                    'is_primary' => 0
                ]);
                echo "   ✅ Successfully inserted secondary department\n";
                
                // Clean up the test record
                $db->delete('employee_departments', 'employee_id = ? AND department_id = ? AND is_primary = 0', [$empId, $availableDept['id']]);
                echo "   ✅ Cleaned up test record\n";
            } catch (Exception $e) {
                echo "   ❌ Failed to insert: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ⚠️ No available department to test with\n";
        }
    } else {
        echo "   ⚠️ No employees found to test\n";
    }
    
    echo "\n=== Debug Complete ===\n";
    echo "If manual insertion works, the issue is in the API/frontend code.\n";
    echo "If manual insertion fails, there's a database constraint issue.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
