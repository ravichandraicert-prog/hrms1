<?php
/**
 * Merge duplicate employee records
 * Merge Karan Aiyappa (EMP7476) and Harbinder Singh Soni (EMP7494) into single records
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Merging Duplicate Employee Records ===\n\n";

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
    
    // Process each employee
    $mergeConfig = [
        'EMP7476' => [
            'primary_dept' => $marketingDept['id'],
            'secondary_dept' => $itDept['id']
        ],
        'EMP7494' => [
            'primary_dept' => $itDept['id'],
            'secondary_dept' => $marketingDept['id']
        ]
    ];
    
    foreach ($mergeConfig as $empId => $config) {
        echo "=== Processing Employee: {$empId} ===\n";
        
        // Get all employee records with this employee_id
        $employees = $db->fetchAll("SELECT * FROM employees WHERE employee_id = ?", [$empId]);
        
        if (count($employees) < 2) {
            echo "  No duplicates found for {$empId}, skipping...\n\n";
            continue;
        }
        
        echo "  Found " . count($employees) . " duplicate records\n";
        
        // Keep the first record as the primary one
        $primaryRecord = $employees[0];
        $primaryDbId = $primaryRecord['id'];
        
        echo "  Keeping primary record with DB ID: {$primaryDbId}\n";
        echo "  Name: {$primaryRecord['first_name']} {$primaryRecord['last_name']}\n";
        
        // Delete all other employee records
        $recordsToDelete = array_slice($employees, 1);
        foreach ($recordsToDelete as $record) {
            $recordDbId = $record['id'];
            echo "  Deleting duplicate record with DB ID: {$recordDbId}\n";
            
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
                    echo "    Transferring department assignment to primary record\n";
                    $db->insert('employee_departments', [
                        'employee_id' => $primaryDbId,
                        'department_id' => $dept['department_id'],
                        'is_primary' => $dept['is_primary']
                    ]);
                }
            }
            
            // Delete the duplicate employee record
            $db->delete('employees', 'id = ?', [$recordDbId]);
            echo "  Deleted duplicate employee record\n";
        }
        
        // Now set the correct primary and secondary departments
        echo "  Setting correct department assignments:\n";
        
        // Remove all existing department assignments
        $db->delete('employee_departments', 'employee_id = ?', [$primaryDbId]);
        echo "    Cleared existing department assignments\n";
        
        // Set primary department
        $db->insert('employee_departments', [
            'employee_id' => $primaryDbId,
            'department_id' => $config['primary_dept'],
            'is_primary' => 1
        ]);
        echo "    Set primary department ID: {$config['primary_dept']}\n";
        
        // Set secondary department
        $db->insert('employee_departments', [
            'employee_id' => $primaryDbId,
            'department_id' => $config['secondary_dept'],
            'is_primary' => 0
        ]);
        echo "    Set secondary department ID: {$config['secondary_dept']}\n";
        
        echo "  ✅ Successfully merged {$empId}\n\n";
    }
    
    // Verify the fixes
    echo "=== Verification ===\n";
    foreach ($mergeConfig as $empId => $config) {
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
            echo "\n";
        } else {
            echo "ERROR: Employee {$empId} not found after merge\n\n";
        }
    }
    
    echo "=== Merge Complete ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
