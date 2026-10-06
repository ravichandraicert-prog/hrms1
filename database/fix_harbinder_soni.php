<?php
/**
 * Fix Harbinder Singh Soni (EMP7494) duplicate records
 * Keep one employee record
 * Set IT as PRIMARY department
 * Set Marketing as SECONDARY department
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Fixing Harbinder Singh Soni (EMP7494) ===\n\n";

try {
    $db = Database::getInstance();
    
    // Get department IDs
    $marketingDept = $db->fetchOne("SELECT id FROM departments WHERE name LIKE '%Marketing%' LIMIT 1");
    $itDept = $db->fetchOne("SELECT id FROM departments WHERE name LIKE '%IT%' OR name LIKE '%Information Technology%' LIMIT 1");
    
    if (!$marketingDept) {
        echo "ERROR: Marketing department not found\n";
        exit;
    }
    if (!$itDept) {
        echo "ERROR: IT department not found\n";
        exit;
    }
    
    echo "Marketing Department ID: {$marketingDept['id']}\n";
    echo "IT Department ID: {$itDept['id']}\n\n";
    
    $empId = 'EMP7494';
    $primaryDeptId = $itDept['id'];
    $secondaryDeptId = $marketingDept['id'];
    
    echo "=== Processing Employee: {$empId} ===\n";
    
    // Get all employee records with this employee_id
    $employees = $db->fetchAll("SELECT * FROM employees WHERE employee_id = ?", [$empId]);
    
    if (count($employees) < 2) {
        echo "No duplicates found for {$empId}\n";
        echo "Checking current department assignments for the single record...\n";
        
        $singleRecord = $employees[0];
        $dbId = $singleRecord['id'];
        
        // Clear existing department assignments
        $db->delete('employee_departments', 'employee_id = ?', [$dbId]);
        echo "Cleared existing department assignments\n";
        
        // Set IT as primary department
        $db->insert('employee_departments', [
            'employee_id' => $dbId,
            'department_id' => $primaryDeptId,
            'is_primary' => 1
        ]);
        echo "Set IT as PRIMARY department\n";
        
        // Set Marketing as secondary department
        $db->insert('employee_departments', [
            'employee_id' => $dbId,
            'department_id' => $secondaryDeptId,
            'is_primary' => 0
        ]);
        echo "Set Marketing as SECONDARY department\n";
        
    } else {
        echo "Found " . count($employees) . " duplicate records\n";
        
        // Keep the first record as the primary one
        $primaryRecord = $employees[0];
        $primaryDbId = $primaryRecord['id'];
        
        echo "Keeping primary record with DB ID: {$primaryDbId}\n";
        echo "Name: {$primaryRecord['first_name']} {$primaryRecord['last_name']}\n";
        
        // Delete all other employee records
        $recordsToDelete = array_slice($employees, 1);
        foreach ($recordsToDelete as $record) {
            $recordDbId = $record['id'];
            echo "Deleting duplicate record with DB ID: {$recordDbId}\n";
            
            // First, transfer any department assignments from the duplicate to the primary
            $duplicateDepts = $db->fetchAll(
                "SELECT * FROM employee_departments WHERE employee_id = ?",
                [$recordDbId]
            );
            
            foreach ($duplicateDepts as $dept) {
                // Check if this department assignment already exists for the primary record
                $existing = $db->fetchOne(
                    "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ?",
                    [$primaryDbId, $dept['department_id']]
                );
                
                if (!$existing) {
                    echo "  Transferring department assignment to primary record\n";
                    $db->insert('employee_departments', [
                        'employee_id' => $primaryDbId,
                        'department_id' => $dept['department_id'],
                        'is_primary' => $dept['is_primary']
                    ]);
                }
            }
            
            // Delete the duplicate employee record
            $db->delete('employees', 'id = ?', [$recordDbId]);
            echo "Deleted duplicate employee record\n";
        }
        
        // Now set the correct primary and secondary departments
        echo "Setting correct department assignments:\n";
        
        // Remove all existing department assignments
        $db->delete('employee_departments', 'employee_id = ?', [$primaryDbId]);
        echo "Cleared existing department assignments\n";
        
        // Set IT as primary department
        $db->insert('employee_departments', [
            'employee_id' => $primaryDbId,
            'department_id' => $primaryDeptId,
            'is_primary' => 1
        ]);
        echo "Set IT as PRIMARY department\n";
        
        // Set Marketing as secondary department
        $db->insert('employee_departments', [
            'employee_id' => $primaryDbId,
            'department_id' => $secondaryDeptId,
            'is_primary' => 0
        ]);
        echo "Set Marketing as SECONDARY department\n";
    }
    
    // Verify the fix
    echo "\n=== Verification ===\n";
    $employee = $db->fetchOne("SELECT * FROM employees WHERE employee_id = ?", [$empId]);
    if ($employee) {
        echo "Employee {$empId} ({$employee['first_name']} {$employee['last_name']}):\n";
        
        $deptAssignments = $db->fetchAll(
            "SELECT ed.*, d.name as department_name 
             FROM employee_departments ed 
             LEFT JOIN departments d ON ed.department_id = d.id 
             WHERE ed.employee_id = ?",
            [$employee['id']]
        );
        
        foreach ($deptAssignments as $assignment) {
            $type = $assignment['is_primary'] == 1 ? 'PRIMARY' : 'SECONDARY';
            echo "  - {$assignment['department_name']} [{$type}]\n";
        }
        
        echo "\n✅ Successfully fixed Harbinder Singh Soni (EMP7494)\n";
        echo "He will now appear only once in the employee list with IT as his primary department.\n";
    } else {
        echo "ERROR: Employee {$empId} not found after fix\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
