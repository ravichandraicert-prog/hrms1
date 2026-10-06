<?php
/**
 * Monthly Leave Accrual Processor
 * This script should be run daily (e.g., via cron job) to process monthly leave accruals
 * based on pay cycles and leave type settings
 * 
 * Usage: php cron/process_monthly_leave_accrual.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');
    
    echo "Starting monthly leave accrual processing for: {$today}\n";
    
    // Step 1: Get all leave types with monthly accrual enabled
    $monthlyAccrualTypes = $db->fetchAll(
        "SELECT * FROM leave_types 
         WHERE monthly_accrual_enabled = 1 
         AND is_active = 1 
         AND monthly_accrual_days > 0"
    );
    
    if (empty($monthlyAccrualTypes)) {
        echo "No leave types with monthly accrual enabled found.\n";
        exit;
    }
    
    echo "Found " . count($monthlyAccrualTypes) . " leave types with monthly accrual enabled.\n";
    
    foreach ($monthlyAccrualTypes as $leaveType) {
        echo "\nProcessing leave type: {$leaveType['name']} (ID: {$leaveType['id']})\n";
        
        // Step 2: Determine accrual timing based on leave type settings
        $accrualTiming = $leaveType['accrual_timing'] ?? 'pay_cycle_end';
        $accrualDayOfMonth = $leaveType['accrual_day_of_month'] ?? null;
        
        // Step 3: Get all active employees who have this leave type assigned
        $employees = getEmployeesForLeaveType($db, $leaveType['id']);
        
        if (empty($employees)) {
            echo "  No employees found for this leave type.\n";
            continue;
        }
        
        echo "  Found " . count($employees) . " employees for this leave type.\n";
        
        foreach ($employees as $employee) {
            echo "  Processing employee: {$employee['first_name']} {$employee['last_name']} (ID: {$employee['id']})\n";
            
            // Step 4: Get employee's pay cycle
            $payCycle = getEmployeePayCycle($db, $employee['id']);
            
            if (!$payCycle) {
                echo "    No pay cycle assigned to employee, skipping.\n";
                continue;
            }
            
            // Step 5: Determine if accrual should happen today based on timing
            $shouldAccrue = false;
            $accrualPeriodStart = null;
            $accrualPeriodEnd = null;
            
            if ($accrualTiming === 'pay_cycle_end') {
                // Accrue on the day after pay cycle ends
                $cycleEndDay = $payCycle['cycle_end_day'];
                if ($cycleEndDay == 99) {
                    // Last day of month
                    $lastDayOfMonth = date('t', strtotime($today));
                    $cycleEndDate = date('Y-m-' . $lastDayOfMonth, strtotime($today));
                } else {
                    $cycleEndDate = date('Y-m-' . $cycleEndDay, strtotime($today));
                }
                
                // Check if today is the day after cycle end
                $expectedAccrualDate = date('Y-m-d', strtotime($cycleEndDate . ' +1 day'));
                
                if ($today === $expectedAccrualDate) {
                    $shouldAccrue = true;
                    // Calculate the period that just ended
                    $periodStart = calculatePeriodStart($cycleEndDate, $payCycle['cycle_start_day']);
                    $accrualPeriodStart = $periodStart;
                    $accrualPeriodEnd = $cycleEndDate;
                }
                
            } elseif ($accrualTiming === 'pay_cycle_start') {
                // Accrue on the day after pay cycle starts
                $cycleStartDay = $payCycle['cycle_start_day'];
                $cycleStartDate = date('Y-m-' . $cycleStartDay, strtotime($today));
                
                // Check if today is the day after cycle start
                $expectedAccrualDate = date('Y-m-d', strtotime($cycleStartDate . ' +1 day'));
                
                if ($today === $expectedAccrualDate) {
                    $shouldAccrue = true;
                    // Calculate the period that just started
                    $accrualPeriodStart = $cycleStartDate;
                    $accrualPeriodEnd = calculatePeriodEnd($cycleStartDate, $payCycle['cycle_end_day']);
                }
                
            } elseif ($accrualTiming === 'specific_day' && $accrualDayOfMonth) {
                // Accrue on specific day of month
                $currentDay = date('j', strtotime($today));
                
                if ($currentDay == $accrualDayOfMonth) {
                    $shouldAccrue = true;
                    // Use current month as the period
                    $accrualPeriodStart = date('Y-m-01', strtotime($today));
                    $accrualPeriodEnd = date('Y-m-t', strtotime($today));
                }
            }
            
            if (!$shouldAccrue) {
                echo "    Not time to accrue for this employee today.\n";
                continue;
            }
            
            echo "    Time to accrue! Period: {$accrualPeriodStart} to {$accrualPeriodEnd}\n";
            
            // Step 6: Check if accrual already happened for this period
            $existingAccrual = $db->fetchOne(
                "SELECT * FROM leave_accrual_history 
                 WHERE employee_id = ? 
                 AND leave_type_id = ? 
                 AND accrual_period_start = ? 
                 AND accrual_period_end = ?",
                [$employee['id'], $leaveType['id'], $accrualPeriodStart, $accrualPeriodEnd]
            );
            
            if ($existingAccrual) {
                echo "    Accrual already processed for this period, skipping.\n";
                continue;
            }
            
            // Step 7: Add leaves to employee balance
            $daysToAccrue = $leaveType['monthly_accrual_days'];
            $currentYear = date('Y');
            
            // Ensure balance record exists
            $balance = $db->fetchOne(
                "SELECT * FROM leave_balances 
                 WHERE employee_id = ? AND leave_type_id = ? AND year = ?",
                [$employee['id'], $leaveType['id'], $currentYear]
            );
            
            if (!$balance) {
                $balanceId = $db->insert('leave_balances', [
                    'employee_id' => $employee['id'],
                    'leave_type_id' => $leaveType['id'],
                    'year' => $currentYear,
                    'total_accrued' => 0,
                    'total_used' => 0,
                    'carried_forward' => 0
                ]);
                $balance = $db->fetchOne("SELECT * FROM leave_balances WHERE id = ?", [$balanceId]);
            }
            
            // Update total_accrued
            $newTotalAccrued = (float) $balance['total_accrued'] + $daysToAccrue;
            $db->update('leave_balances',
                ['total_accrued' => $newTotalAccrued],
                'id = ?',
                [$balance['id']]
            );
            
            // Step 8: Record accrual in history
            $db->insert('leave_accrual_history', [
                'employee_id' => $employee['id'],
                'leave_type_id' => $leaveType['id'],
                'accrual_date' => $today,
                'accrual_period_start' => $accrualPeriodStart,
                'accrual_period_end' => $accrualPeriodEnd,
                'days_accrued' => $daysToAccrue,
                'pay_cycle_id' => $payCycle['id'],
                'notes' => "Monthly accrual for period {$accrualPeriodStart} to {$accrualPeriodEnd}"
            ]);
            
            echo "    Successfully accrued {$daysToAccrual} days. New balance: " . ($newTotalAccrued - (float) $balance['total_used']) . "\n";
        }
    }
    
    echo "\nMonthly leave accrual processing completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// Helper functions

function getEmployeesForLeaveType($db, $leaveTypeId) {
    // Get employees based on leave type assignment
    $leaveType = $db->fetchOne("SELECT * FROM leave_types WHERE id = ?", [$leaveTypeId]);
    if (!$leaveType) return [];
    
    $assignTo = $leaveType['assign_to'] ?? 'all_employees';
    $targetId = $leaveType['target_id'] ?? null;
    
    $employees = [];
    
    if ($assignTo === 'all_employees') {
        $employees = $db->fetchAll("SELECT * FROM employees WHERE is_active = 1");
    } elseif ($assignTo === 'department' && $targetId) {
        $employees = $db->fetchAll(
            "SELECT e.* FROM employees e
             JOIN employee_departments ed ON e.id = ed.employee_id
             WHERE ed.department_id = ? AND e.is_active = 1",
            [$targetId]
        );
    } elseif ($assignTo === 'role' && $targetId) {
        $employees = $db->fetchAll(
            "SELECT * FROM employees WHERE role_id = ? AND is_active = 1",
            [$targetId]
        );
    } elseif ($assignTo === 'user' && $targetId) {
        $employees = $db->fetchAll(
            "SELECT * FROM employees WHERE id = ? AND is_active = 1",
            [$targetId]
        );
    }
    
    return $employees;
}

function getEmployeePayCycle($db, $employeeId) {
    // Get employee's current pay cycle based on priority assignments
    $employee = $db->fetchOne(
        "SELECT e.*, 
                (SELECT department_id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1) as primary_department_id
         FROM employees e 
         WHERE e.id = ?",
        [$employeeId]
    );
    
    if (!$employee) return null;
    
    // Get applicable assignments in priority order (employee > role > department > organization)
    $assignments = $db->fetchAll(
        "SELECT pca.*, pc.name as pay_cycle_name, pc.frequency, pc.cycle_start_day, pc.cycle_end_day
         FROM pay_cycle_assignments pca
         JOIN pay_cycles pc ON pca.pay_cycle_id = pc.id
         WHERE pca.is_active = 1
           AND (
               pca.assignment_type = 'organization' OR
               (pca.assignment_type = 'department' AND pca.assignment_id = ?) OR
               (pca.assignment_type = 'role' AND pca.assignment_id = ?) OR
               (pca.assignment_type = 'employee' AND pca.assignment_id = ?)
           )
           AND (pca.effective_date <= CURDATE() OR pca.effective_date IS NULL)
           AND (pca.end_date IS NULL OR pca.end_date >= CURDATE())
         ORDER BY pca.priority DESC, pca.effective_date DESC",
        [
            $employee['primary_department_id'] ?? 0,
            $employee['role_id'] ?? 0,
            $employeeId
        ]
    );
    
    if (empty($assignments)) {
        // Get default pay cycle
        return $db->fetchOne("SELECT * FROM pay_cycles WHERE is_default = 1 LIMIT 1");
    }
    
    // Return the highest priority assignment
    return $assignments[0];
}

function calculatePeriodStart($cycleEndDate, $cycleStartDay) {
    // Calculate the start date of the period that ended on cycleEndDate
    $periodStart = new DateTime($cycleEndDate);
    $periodStart->setDate((int)$periodStart->format('Y'), (int)$periodStart->format('m'), $cycleStartDay);
    
    // If the calculated start is after the end, it means the period started in previous month
    if ($periodStart > new DateTime($cycleEndDate)) {
        $periodStart->modify('-1 month');
    }
    
    return $periodStart->format('Y-m-d');
}

function calculatePeriodEnd($cycleStartDate, $cycleEndDay) {
    // Calculate the end date of the period that started on cycleStartDate
    $periodEnd = new DateTime($cycleStartDate);
    
    if ($cycleEndDay == 99) {
        // Last day of month
        $periodEnd->modify('last day of this month');
    } else {
        $periodEnd->setDate((int)$periodEnd->format('Y'), (int)$periodEnd->format('m'), $cycleEndDay);
    }
    
    // If the calculated end is before the start, it means the period ends in next month
    if ($periodEnd < new DateTime($cycleStartDate)) {
        $periodEnd->modify('+1 month');
        if ($cycleEndDay == 99) {
            $periodEnd->modify('last day of this month');
        } else {
            $periodEnd->setDate((int)$periodEnd->format('Y'), (int)$periodEnd->format('m'), $cycleEndDay);
        }
    }
    
    return $periodEnd->format('Y-m-d');
}
?>