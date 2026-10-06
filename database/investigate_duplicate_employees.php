<?php
/**
 * Investigate and fix duplicate employee records
 * Specifically for Karan Aiyappa (EMP7476) and Harbinder Singh Soni (EMP7494)
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Investigating Duplicate Employee Records ===\n\n";

try {
    $db = Database::getInstance();
    
    // Check for the specific employees
    $targetEmployees = ['EMP7476', 'EMP7494'];
    
    foreach ($targetEmployees as $empId) {
        echo "=== Employee ID: {$empId} ===\n";
        
        // Get all employee records with this employee_id
        $employees = $db->fetchAll("SELECT * FROM employees WHERE employee_id = ?", [$empId]);
        
        if (empty($employees)) {
            echo "No employee found with ID: {$empId}\n\n";
            continue;
        }
        
        echo "Found " . count($employees) . " employee record(s):\n";
        foreach ($employees as $emp) {
            echo "  - DB ID: {$emp['id']}, Name: {$emp['first_name']} {$emp['last_name']}, Email: {$emp['official_email']}\n";
        }
        
        // Check department assignments for each employee record
        foreach ($employees as $emp) {
            $dbId = $emp['id'];
            echo "\n  Department assignments for DB ID {$dbId}:\n";
            
            $deptAssignments = $db->fetchAll(
                "SELECT ed.*, d.name as department_name 
                 FROM employee_departments ed 
                 LEFT JOIN departments d ON ed.department_id = d.id 
                 WHERE ed.employee_id = ?",
                [$dbId]
            );
            
            if (empty($deptAssignments)) {
                echo "    No department assignments\n";
            } else {
                foreach ($deptAssignments as $assignment) {
                    $type = $assignment['is_primary'] == 1 ? 'PRIMARY' : 'SECONDARY';
                    echo "    - Department: {$assignment['department_name']} (ID: {$assignment['department_id']}) [{$type}]\n";
                }
            }
        }
        
        echo "\n";
    }
    
    // Check if there are duplicate employee_ids in the system
    echo "=== Checking for duplicate employee_ids in entire system ===\n";
    $duplicates = $db->fetchAll("
        SELECT employee_id, COUNT(*) as count 
        FROM employees 
        GROUP BY employee_id 
        HAVING count > 1
    ");
    
    if (empty($duplicates)) {
        echo "No duplicate employee_ids found in the system\n";
    } else {
        echo "Found " . count($duplicates) . " duplicate employee_ids:\n";
        foreach ($duplicates as $dup) {
            echo "  - {$dup['employee_id']}: {$dup['count']} records\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
