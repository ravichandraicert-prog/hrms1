<?php
/**
 * Test Secondary Department Functionality
 * This script tests the secondary department feature to ensure:
 * 1. Secondary departments are saved and loaded correctly
 * 2. No duplicate employee records are created
 * 3. Employee list shows each employee only once based on primary department
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Secondary Department Functionality Test ===\n\n";

try {
    $db = Database::getInstance();
    
    // Test 1: Check database structure
    echo "Test 1: Checking database structure...\n";
    $columns = $db->fetchAll("DESCRIBE employee_departments");
    $hasIsPrimary = false;
    $hasUniqueConstraint = false;
    
    foreach ($columns as $col) {
        if ($col['Field'] === 'is_primary') {
            $hasIsPrimary = true;
            echo "  ✓ is_primary column exists (default: {$col['Default']})\n";
        }
    }
    
    // Check for unique constraint
    $constraints = $db->fetchAll("SHOW INDEX FROM employee_departments WHERE Key_name = 'unique_emp_dept'");
    if (!empty($constraints)) {
        $hasUniqueConstraint = true;
        echo "  ✓ UNIQUE constraint on (employee_id, department_id) exists\n";
    }
    
    if ($hasIsPrimary && $hasUniqueConstraint) {
        echo "  ✅ Database structure is correct\n\n";
    } else {
        echo "  ❌ Database structure needs fixing\n\n";
    }
    
    // Test 2: Check for any duplicate employee-department combinations
    echo "Test 2: Checking for duplicate employee-department combinations...\n";
    $duplicates = $db->fetchAll("
        SELECT employee_id, department_id, COUNT(*) as count
        FROM employee_departments
        GROUP BY employee_id, department_id
        HAVING count > 1
    ");
    
    if (empty($duplicates)) {
        echo "  ✅ No duplicate employee-department combinations found\n\n";
    } else {
        echo "  ❌ Found " . count($duplicates) . " duplicate combinations:\n";
        foreach ($duplicates as $dup) {
            echo "    - Employee ID: {$dup['employee_id']}, Department ID: {$dup['department_id']} (Count: {$dup['count']})\n";
        }
        echo "\n";
    }
    
    // Test 3: Check primary department assignments
    echo "Test 3: Checking primary department assignments...\n";
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
        HAVING primary_count != 1
    ");
    
    if (empty($primaryCounts)) {
        echo "  ✅ All employees have exactly one primary department\n\n";
    } else {
        echo "  ❌ Found " . count($primaryCounts) . " employees with incorrect primary department assignments:\n";
        foreach ($primaryCounts as $emp) {
            echo "    - {$emp['employee_id']} ({$emp['first_name']} {$emp['last_name']}): Primary={$emp['primary_count']}, Secondary={$emp['secondary_count']}\n";
        }
        echo "\n";
    }
    
    // Test 4: Test API endpoint for loading employee with secondary departments
    echo "Test 4: Testing API endpoint for loading employee with secondary departments...\n";
    $testEmployee = $db->fetchOne("SELECT id FROM employees LIMIT 1");
    
    if ($testEmployee) {
        $employeeId = $testEmployee['id'];
        echo "  Testing with employee ID: {$employeeId}\n";
        
        // Simulate API call
        $employee = $db->fetchOne(
            "SELECT e.*, r.name as role_name, d.name as department_name, ed.department_id as department_id
             FROM employees e 
             LEFT JOIN roles r ON e.role_id = r.id 
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1 
             LEFT JOIN departments d ON ed.department_id = d.id 
             WHERE e.id = ?",
            [$employeeId]
        );
        
        if ($employee) {
            $secondaryDepts = $db->fetchAll(
                "SELECT department_id FROM employee_departments WHERE employee_id = ? AND is_primary = 0",
                [$employeeId]
            );
            $employee['secondary_departments'] = array_column($secondaryDepts, 'department_id');
            
            echo "  ✓ Employee loaded successfully\n";
            echo "  ✓ Primary department: " . ($employee['department_name'] ?? 'None') . "\n";
            echo "  ✓ Secondary departments: " . (empty($employee['secondary_departments']) ? 'None' : implode(', ', $employee['secondary_departments'])) . "\n";
            echo "  ✅ API endpoint test passed\n\n";
        } else {
            echo "  ❌ Failed to load employee\n\n";
        }
    } else {
        echo "  ⚠️ No employees found to test\n\n";
    }
    
    // Test 5: Check employee list query (should show each employee only once)
    echo "Test 5: Checking employee list query (should show each employee only once)...\n";
    $employeeList = $db->fetchAll(
        "SELECT e.*, r.name as role_name, d.name as department_name 
         FROM employees e
         LEFT JOIN roles r ON e.role_id = r.id
         LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
         LEFT JOIN departments d ON ed.department_id = d.id
         ORDER BY e.first_name, e.last_name
         LIMIT 10"
    );
    
    $employeeIds = [];
    $duplicatesInList = [];
    
    foreach ($employeeList as $emp) {
        if (in_array($emp['id'], $employeeIds)) {
            $duplicatesInList[] = $emp['id'];
        }
        $employeeIds[] = $emp['id'];
    }
    
    if (empty($duplicatesInList)) {
        echo "  ✅ Employee list shows each employee only once (based on primary department)\n";
        echo "  ✅ Total unique employees in list: " . count($employeeList) . "\n\n";
    } else {
        echo "  ❌ Found duplicate employees in list: " . implode(', ', $duplicatesInList) . "\n\n";
    }
    
    echo "=== Test Summary ===\n";
    echo "All critical tests completed. Review the results above.\n";
    echo "If any tests failed, use the fix_secondary_departments.sql script to fix the database.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
