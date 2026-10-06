<?php
/**
 * Migration runner for Festival Leave functionality
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    // Add Festival Leave type if it doesn't exist
    $festivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Festival Leave'");
    if (!$festivalLeaveType) {
        $db->insert('leave_types', [
            'name' => 'Festival Leave',
            'calculation_basis' => 'working_days',
            'accrual_method' => 'fixed_annual',
            'max_days_per_year' => 0,
            'min_days_per_request' => 1.0,
            'carry_forward' => 0,
            'max_carry_forward_days' => 0,
            'applicable_to' => 'all',
            'is_paid' => 1,
            'requires_approval' => 1,
            'description' => 'Leave for festival holidays as per assigned holiday calendar',
            'is_active' => 1,
            'assign_to' => 'all_employees',
            'target_id' => null
        ]);
        echo "Added Festival Leave type to leave_types table.\n";
    } else {
        echo "Festival Leave type already exists in leave_types table.\n";
    }
    
    // Add Optional Festival Leave type if it doesn't exist
    $optionalFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Optional Festival Leave'");
    if (!$optionalFestivalLeaveType) {
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
        echo "Added Optional Festival Leave type to leave_types table.\n";
    } else {
        echo "Optional Festival Leave type already exists in leave_types table.\n";
    }
    
    // Add festival_holiday_id column to leave_applications table
    $checkColumn = $db->fetchOne("SHOW COLUMNS FROM leave_applications LIKE 'festival_holiday_id'");
    if (!$checkColumn) {
        $db->query("ALTER TABLE `leave_applications` 
            ADD COLUMN `festival_holiday_id` int(11) DEFAULT NULL COMMENT 'Reference to holidays table for Festival Leave type' AFTER `leave_type_id`,
            ADD KEY `festival_holiday_id` (`festival_holiday_id`)");
        echo "Added festival_holiday_id column to leave_applications table.\n";
        
        // Add foreign key constraint
        $db->query("ALTER TABLE `leave_applications` 
            ADD CONSTRAINT `leave_applications_ibfk_4` FOREIGN KEY (`festival_holiday_id`) REFERENCES `holidays` (`id`) ON DELETE SET NULL");
        echo "Added foreign key constraint for festival_holiday_id.\n";
    } else {
        echo "festival_holiday_id column already exists in leave_applications table.\n";
    }
    
    // Add festival_type column to leave_applications table to track Fixed vs Optional
    $checkFestivalTypeColumn = $db->fetchOne("SHOW COLUMNS FROM leave_applications LIKE 'festival_type'");
    if (!$checkFestivalTypeColumn) {
        $db->query("ALTER TABLE `leave_applications` 
            ADD COLUMN `festival_type` varchar(20) DEFAULT NULL COMMENT 'Festival type: Fixed or Optional' AFTER `festival_holiday_id`");
        echo "Added festival_type column to leave_applications table.\n";
    } else {
        echo "festival_type column already exists in leave_applications table.\n";
    }
    
    // Create optional festival leave balances for all employees
    $optionalFestivalLeaveType = $db->fetchOne("SELECT * FROM leave_types WHERE name = 'Optional Festival Leave'");
    if ($optionalFestivalLeaveType) {
        $currentYear = date('Y');
        $employees = $db->fetchAll("SELECT id FROM employees WHERE is_active = 1");
        
        foreach ($employees as $employee) {
            // Check if optional festival leave balance already exists for this year
            $existingBalance = $db->fetchOne(
                "SELECT id FROM leave_balances 
                 WHERE employee_id = ? AND leave_type_id = ? AND year = ?",
                [$employee['id'], $optionalFestivalLeaveType['id'], $currentYear]
            );
            
            if (!$existingBalance) {
                // Count optional festivals in employee's assigned calendar
                $employeeData = $db->fetchOne("SELECT holiday_calendar_id FROM employees WHERE id = ?", [$employee['id']]);
                if ($employeeData && $employeeData['holiday_calendar_id']) {
                    $optionalFestivalCount = $db->fetchOne(
                        "SELECT COUNT(*) as count FROM holidays 
                         WHERE holiday_calendar_id = ? AND type = 'Optional'",
                        [$employeeData['holiday_calendar_id']]
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
                }
            }
        }
        echo "Created optional festival leave balances for employees.\n";
    }

    echo "Festival Leave migration completed successfully.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
