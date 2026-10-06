<?php
/**
 * Break Type Assignment Helpers
 * Single source of truth for how break types are assigned to employees.
 * Shared across Settings -> Break Types and other modules
 * so both locations stay synchronized.
 *
 * Assignment model (stored on break_types):
 *   assign_to : all_employees | department | role | user | shift
 *   target_id : id of the department / role / user / shift (NULL for all_employees)
 */

if (!function_exists('break_type_assign_to')) {
    /**
     * Resolve the assignment mode for a break type row.
     */
    function break_type_assign_to($type) {
        if (isset($type['assign_to']) && $type['assign_to'] !== '' && $type['assign_to'] !== null) {
            return $type['assign_to'];
        }
        return 'all_employees';
    }
}

if (!function_exists('break_type_target_ids')) {
    /**
     * Return the target ids for a break type row as an integer array.
     * Prefers the comma-separated target_ids column (used for multiple targets,
     * e.g. multiple shifts) and falls back to the legacy single target_id column.
     */
    function break_type_target_ids($type) {
        $ids = [];
        $raw = isset($type['target_ids']) && $type['target_ids'] !== '' && $type['target_ids'] !== null
            ? (string) $type['target_ids']
            : '';
        if ($raw !== '') {
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if ($part !== '' && is_numeric($part) && (int) $part > 0) {
                    $ids[] = (int) $part;
                }
            }
        }
        if (empty($ids)) {
            $single = (int) ($type['target_id'] ?? 0);
            if ($single > 0) {
                $ids[] = $single;
            }
        }
        return $ids;
    }
}

if (!function_exists('break_type_is_applicable')) {
    /**
     * Whether a break type is applicable to the given employee.
     * - all_employees (or no assignment) -> every employee
     * - department -> employee belongs to the target department
     * - role       -> employee has the target role
     * - user       -> only the target user
     * - shift      -> employee has one of the target shifts (multiple shifts supported)
     */
    function break_type_is_applicable($db, $type, $employeeId) {
        $assignTo = break_type_assign_to($type);
        $employeeId = (int) $employeeId;
        if ($assignTo === 'all_employees') {
            return true;
        }
        if ($assignTo === 'shift') {
            // Multiple shifts are stored comma-separated in target_ids
            $targetIds = break_type_target_ids($type);
            if (empty($targetIds)) {
                return false;
            }
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $row = $db->fetchOne(
                "SELECT id FROM employees WHERE id = ? AND shift_id IN ({$placeholders}) LIMIT 1",
                array_merge([$employeeId], $targetIds)
            );
            return (bool) $row;
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

if (!function_exists('break_type_target_employee_ids')) {
    /**
     * Return the ids of active employees to whom the break type applies.
     */
    function break_type_target_employee_ids($db, $type) {
        $assignTo = break_type_assign_to($type);
        if ($assignTo === 'all_employees') {
            $rows = $db->fetchAll("SELECT id FROM employees WHERE is_active = 1");
            return array_map(function ($r) { return (int) $r['id']; }, $rows);
        }
        if ($assignTo === 'shift') {
            // Multiple shifts are stored comma-separated in target_ids
            $targetIds = break_type_target_ids($type);
            if (empty($targetIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $rows = $db->fetchAll(
                "SELECT id FROM employees WHERE shift_id IN ({$placeholders}) AND is_active = 1",
                $targetIds
            );
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
