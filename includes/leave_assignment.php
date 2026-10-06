<?php
/**
 * Leave Type Assignment Helpers
 * Single source of truth for how leave types are assigned to employees.
 * Shared across Settings -> Leave Types and Leaves -> Leave Types / Balances
 * so both locations stay synchronized.
 *
 * Assignment model (stored on leave_types):
 *   assign_to : all_employees | department | role | user
 *   target_id : id of the department / role / user (NULL for all_employees)
 *   Legacy: applicable_to = all / specific_roles / specific_departments (no data) -> treated as all when absent
 */

if (!function_exists('leave_type_assign_to')) {
    /**
     * Resolve the assignment mode for a leave type row.
     * Prefers the modern assign_to column, falls back to the legacy applicable_to column.
     */
    function leave_type_assign_to($type) {
        if (isset($type['assign_to']) && $type['assign_to'] !== '' && $type['assign_to'] !== null) {
            return $type['assign_to'];
        }
        $legacy = isset($type['applicable_to']) ? $type['applicable_to'] : 'all';
        if ($legacy === 'specific_roles') return 'role';
        if ($legacy === 'specific_departments') return 'department';
        return 'all_employees';
    }
}

if (!function_exists('leave_type_is_applicable')) {
    /**
     * Whether a leave type is applicable to the given employee.
     * - all_employees (or no assignment) -> every employee
     * - department -> employee belongs to the target department
     * - role       -> employee has the target role
     * - user       -> only the target user
     */
    function leave_type_is_applicable($db, $type, $employeeId) {
        $assignTo = leave_type_assign_to($type);
        $employeeId = (int) $employeeId;
        if ($assignTo === 'all_employees') {
            return true;
        }
        $targetId = (int) ($type['target_id'] ?? 0);
        if (!$targetId) {
            return false;
        }
        if ($assignTo === 'user') {
            return ($employeeId === $targetId) || (isset($type['id']) && (int) $employeeId === $targetId);
        }
        if ($assignTo === 'department') {
            $row = $db->fetchOne(
                "SELECT id FROM employee_departments WHERE employee_id = ? AND department_id = ? LIMIT 1",
                [$employeeId, $targetId]
            );
            return (bool) $row;
        }
        if ($assignTo === 'role') {
            $row = $db->fetchOne(
                "SELECT id FROM employees WHERE id = ? AND role_id = ? LIMIT 1",
                [$employeeId, $targetId]
            );
            return (bool) $row;
        }
        return false;
    }
}

if (!function_exists('leave_type_target_employee_ids')) {
    /**
     * Return the ids of active employees to whom the leave type applies.
     */
    function leave_type_target_employee_ids($db, $type) {
        $assignTo = leave_type_assign_to($type);
        if ($assignTo === 'all_employees') {
            $rows = $db->fetchAll("SELECT id FROM employees WHERE is_active = 1");
            return array_map(function ($r) { return (int) $r['id']; }, $rows);
        }
        $targetId = (int) ($type['target_id'] ?? 0);
        if (!$targetId) {
            return [];
        }
        if ($assignTo === 'user') {
            $rows = $db->fetchAll(
                "SELECT id FROM employees WHERE id = ? AND is_active = 1 LIMIT 1",
                [$targetId]
            );
        } elseif ($assignTo === 'department') {
            $rows = $db->fetchAll(
                "SELECT e.id FROM employees e
                 JOIN employee_departments ed ON ed.employee_id = e.id AND ed.department_id = ?
                 WHERE e.is_active = 1",
                [$targetId]
            );
        } elseif ($assignTo === 'role') {
            $rows = $db->fetchAll(
                "SELECT id FROM employees WHERE role_id = ? AND is_active = 1",
                [$targetId]
            );
        } else {
            return [];
        }
        return array_map(function ($r) { return (int) $r['id']; }, $rows);
    }
}

if (!function_exists('sync_leave_type_balances')) {
    /**
     * Provision leave_balances rows for the employees the type is assigned to.
     *
     * Non-destructive:
     *  - only INSERTS missing rows; never updates existing rows
     *  - never deletes rows (so unrelated employees / history are untouched)
     *  - a newly provisioned row starts with total_accrued = max_days_per_year
     *  - Special handling for Optional Festival Leave: total_accrued = count of optional festivals in assigned calendar
     */
    function sync_leave_type_balances($db, $type, $year = null) {
        if (!$year) {
            $year = (int) date('Y');
        }
        $typeId = (int) ($type['id'] ?? 0);
        if (!$typeId) {
            $typeId = (int) ($type['type_id'] ?? 0);
        }
        if (!$typeId) {
            return 0;
        }
        
        // Special handling for Optional Festival Leave
        $isOptionalFestivalLeave = isset($type['name']) && strtolower($type['name']) === 'optional festival leave';
        
        $empIds = leave_type_target_employee_ids($db, $type);
        $created = 0;
        $healed = 0;
        
        foreach ($empIds as $empId) {
            // Calculate accrued days based on leave type
            if ($isOptionalFestivalLeave) {
                // For Optional Festival Leave, count optional festivals in employee's assigned calendar
                $employeeData = $db->fetchOne("SELECT holiday_calendar_id FROM employees WHERE id = ?", [$empId]);
                if ($employeeData && $employeeData['holiday_calendar_id']) {
                    $optionalFestivalCount = $db->fetchOne(
                        "SELECT COUNT(*) as count FROM holidays 
                         WHERE holiday_calendar_id = ? AND type = 'Optional'",
                        [$employeeData['holiday_calendar_id']]
                    );
                    $accrued = (int) ($optionalFestivalCount['count'] ?? 0);
                } else {
                    $accrued = 0; // No calendar assigned, no optional festival leave
                }
            } else {
                // For regular leave types, use max_days_per_year
                $accrued = (int) ($type['max_days_per_year'] ?? 0);
            }
            
            $existing = $db->fetchOne(
                "SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ? LIMIT 1",
                [$empId, $typeId, $year]
            );
            if ($existing) {
                // Heal "default" rows only: zero accrued, zero used, zero carried forward.
                // Such rows are auto-created by ensureBalance() on apply and were never
                // granted or used, so it is safe to set the real accrual. Any row with
                // real accrual or usage is left completely untouched.
                $isDefault = ((float) $existing['total_accrued'] == 0)
                    && ((float) $existing['total_used'] == 0)
                    && ((float) $existing['carried_forward'] == 0);
                if ($isDefault) {
                    $db->update('leave_balances',
                        ['total_accrued' => $accrued],
                        'id = ?',
                        [$existing['id']]
                    );
                    $healed++;
                }
                continue;
            }
            $db->insert('leave_balances', [
                'employee_id' => $empId,
                'leave_type_id' => $typeId,
                'year' => $year,
                'total_accrued' => $accrued,
                'total_used' => 0,
                'carried_forward' => 0
            ]);
            $created++;
        }
        return $created + $healed;
    }
}
