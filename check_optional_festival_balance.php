<?php
/**
 * Check and fix optional festival leave balance
 * Access this file via: http://localhost/HRMS/check_optional_festival_balance.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

try {
    $db = Database::getInstance();
    
    echo "Checking Optional Festival Leave balance...\n\n";
    
    // Check if Optional Festival Leave type exists
    $optionalFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Optional Festival Leave'");
    if (!$optionalFestivalLeaveType) {
        echo "Optional Festival Leave type does not exist. Creating it...\n";
        
        $db->insert('leave_types', [
            'name' => 'Optional Festival Leave',
            'calculation_basis' => 'working_days',
            'accrual_method' => 'fixed_annual',
            'max_days_per_year' => 0,
            'min_days_per_request' => 1.0,
            'carry_forward' => 0,
            'max_carry_forward_days' => 0,
            'applicable_to' => 'all',
            'is_paid' => 1,
            'requires_approval' => 1,
            'description' => 'Optional festival leave as per assigned holiday calendar',
            'is_active' => 1,
            'assign_to' => 'all_employees',
            'target_id' => null
        ]);
        
        $optionalFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Optional Festival Leave'");
        echo "✓ Created Optional Festival Leave type (ID: {$optionalFestivalLeaveType['id']})\n";
    } else {
        echo "✓ Optional Festival Leave type exists (ID: {$optionalFestivalLeaveType['id']})\n";
    }
    
    // Create/update balances for all active employees
    $currentYear = date('Y');
    $employees = $db->fetchAll("SELECT id, first_name, last_name, holiday_calendar_id FROM employees WHERE is_active = 1");
    
    echo "\nProcessing " . count($employees) . " active employees...\n";
    
    $processedCount = 0;
    $skippedCount = 0;
    
    foreach ($employees as $employee) {
        // Check if balance already exists
        $existingBalance = $db->fetchOne(
            "SELECT * FROM leave_balances 
             WHERE employee_id = ? AND leave_type_id = ? AND year = ?",
            [$employee['id'], $optionalFestivalLeaveType['id'], $currentYear]
        );
        
        if ($existingBalance) {
            echo "  - {$employee['first_name']} {$employee['last_name']}: Balance exists ({$existingBalance['balance']})\n";
            $skippedCount++;
        } else {
            // Count optional festivals in employee's assigned calendar
            if ($employee['holiday_calendar_id']) {
                $optionalFestivalCount = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM holidays 
                     WHERE holiday_calendar_id = ? AND type = 'Optional'",
                    [$employee['holiday_calendar_id']]
                );
                
                $count = (int) ($optionalFestivalCount['count'] ?? 0);
                
                // Create balance entry
                $db->insert('leave_balances', [
                    'employee_id' => $employee['id'],
                    'leave_type_id' => $optionalFestivalLeaveType['id'],
                    'year' => $currentYear,
                    'total_accrued' => $count,
                    'total_used' => 0,
                    'carried_forward' => 0
                ]);
                
                echo "  + {$employee['first_name']} {$employee['last_name']}: Created balance ({$count} optional festivals)\n";
                $processedCount++;
            } else {
                echo "  - {$employee['first_name']} {$employee['last_name']}: No assigned calendar, skipped\n";
                $skippedCount++;
            }
        }
    }
    
    echo "\n✓ Processed {$processedCount} employees\n";
    echo "✓ Skipped {$skippedCount} employees (existing balances or no calendar)\n";
    echo "\nOptional Festival Leave balance check completed.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
