<?php
/**
 * Permissions Engine
 * Core PHP HRMS Application
 * Central authorization service per Section 3
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Check if user is the task assigner/creator
 */
function isTaskAssigner($taskId, $userId) {
    try {
        $db = Database::getInstance();
        $task = $db->fetchOne("SELECT assigned_by FROM tasks WHERE id = ?", [$taskId]);
        return $task && isset($task['assigned_by']) && $task['assigned_by'] == $userId;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if user is a task assignee
 */
function isTaskAssignee($taskId, $userId) {
    try {
        $db = Database::getInstance();
        $assignment = $db->fetchOne(
            "SELECT id FROM task_assignments WHERE task_id = ? AND assignee_id = ?",
            [$taskId, $userId]
        );
        return $assignment !== false && $assignment !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get editable task fields based on user role
 * Returns array of field names that the user can edit
 */
function getEditableTaskFields($taskId, $userId) {
    $db = Database::getInstance();
    
    // Get user hierarchy level for super admin check
    $user = $db->fetchOne(
        "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
        [$userId]
    );
    
    // Super Admin can edit everything
    if ($user && isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) {
        return ['all'];
    }
    
    // Task assigner can edit most fields
    if (isTaskAssigner($taskId, $userId)) {
        return [
            'title', 'description', 'priority', 'status', 'deadline', 
            'assignees', 'progress', 'recurrence_pattern', 'recurrence_days',
            'recurrence_start_date', 'recurrence_end_date', 'recurrence_time',
            'reminder_minutes_before', 'notifications_enabled', 'is_recurring_active'
        ];
    }
    
    // Task assignee can only edit status and progress
    if (isTaskAssignee($taskId, $userId)) {
        return ['status', 'progress'];
    }
    
    // Other users with general task update permission can edit basic fields
    if (hasPermission('tasks', 'update')) {
        return ['title', 'description', 'priority', 'status', 'deadline', 'assignees', 'progress'];
    }
    
    // No edit permissions
    return [];
}

class Permissions {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Check if user has permission for a specific action on a module
     */
    public function check($userId, $module, $action, $scope = null) {
        $user = $this->db->fetchOne(
            "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
            [$userId]
        );
        
        if (!$user) return false;
        
        // Super Admin has all permissions
        if (isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) return true;
        
        // Check user-level override first (takes precedence)
        $overrideParams = [$userId, $module, $action];
        $overrideSql = "SELECT up.allowed, up.scope FROM user_permissions up 
                        JOIN modules m ON up.module_id = m.id 
                        WHERE up.user_id = ? AND m.name = ? AND up.action = ?";
        
        if ($scope) {
            $overrideSql .= " AND up.scope = ?";
            $overrideParams[] = $scope;
        }
        
        $override = $this->db->fetchOne($overrideSql, $overrideParams);
        
        if ($override !== false) {
            return (bool) $override['allowed'];
        }
        
        // Check role-based permission
        $params = [$user['role_id'], $module, $action];
        $sql = "SELECT p.allowed, p.scope FROM permissions p 
               JOIN modules m ON p.module_id = m.id 
               WHERE p.role_id = ? AND m.name = ? AND p.action = ?";
        
        if ($scope) {
            $sql .= " AND p.scope = ?";
            $params[] = $scope;
        }
        
        $permission = $this->db->fetchOne($sql, $params);
        
        return $permission !== false && (bool) $permission['allowed'];
    }
    
    /**
     * Get data scope for a user on a module
     */
    public function getScope($userId, $module, $action = 'read') {
        $user = $this->db->fetchOne(
            "SELECT e.*, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
            [$userId]
        );
        
        if (!$user) return 'self';
        
        if (isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) return 'all';
        
        // Check user override
        $override = $this->db->fetchOne(
            "SELECT up.scope FROM user_permissions up 
             JOIN modules m ON up.module_id = m.id 
             WHERE up.user_id = ? AND m.name = ? AND up.action = ?",
            [$userId, $module, $action]
        );
        
        if ($override) return $override['scope'];
        
        // Check role permission
        $permission = $this->db->fetchOne(
            "SELECT p.scope FROM permissions p 
             JOIN modules m ON p.module_id = m.id 
             WHERE p.role_id = ? AND m.name = ? AND p.action = ?",
            [$user['role_id'], $module, $action]
        );
        
        return $permission['scope'] ?? 'self';
    }
    
    /**
     * Get filtered employee IDs based on scope and hierarchy level
     */
    public function getScopedEmployeeIds($userId, $module, $action = 'read') {
        $scope = $this->getScope($userId, $module, $action);
        $user = getCurrentUser();
        
        if (!$user) return [];
        
        // Super Admin sees all
        if (isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) {
            $result = $this->db->fetchAll("SELECT id FROM employees");
            return array_column($result, 'id');
        }
        
        $userHierarchy = isset($user['hierarchy_level']) ? $user['hierarchy_level'] : 999;
        
        switch ($scope) {
            case 'all':
                // Whole company access (HR/Admin roles) with hierarchy level >= user's level.
                // Super Admin (level 1) is never visible to other users.
                $result = $this->db->fetchAll(
                    "SELECT e.id FROM employees e
                     LEFT JOIN roles r ON e.role_id = r.id
                     WHERE (r.hierarchy_level >= ? OR r.hierarchy_level IS NULL)
                       AND COALESCE(r.hierarchy_level, 0) != 1",
                    [$userHierarchy]
                );
                return array_column($result, 'id');

            case 'self':
                return [$userId];

            case 'direct_reports':
                // Direct reports with hierarchy level >= user's level
                $result = $this->db->fetchAll(
                    "SELECT e.id FROM employees e
                     LEFT JOIN roles r ON e.role_id = r.id
                     WHERE e.reporting_manager_id = ? AND (r.hierarchy_level >= ? OR r.hierarchy_level IS NULL)
                       AND COALESCE(r.hierarchy_level, 0) != 1",
                    [$userId, $userHierarchy]
                );
                return array_column($result, 'id');

            case 'team':
            case 'department':
            case 'department_sub':
                // Department-hierarchy branch scoping (same rule for team/department scope):
                // The user can see themselves plus everyone at-or-below their hierarchy level
                // whose PRIMARY department belongs to the user's primary OR secondary departments,
                // including the directly-connected sub-department branches (parent/child tree set
                // in the Departments module). Super Admin is never visible to other users.
                $branchDeptIds = $this->getDepartmentTree($userId);
                if (empty($branchDeptIds)) return [$userId];
                $result = $this->db->fetchAll(
                    "SELECT DISTINCT e.id FROM employees e
                     LEFT JOIN roles r ON e.role_id = r.id
                     LEFT JOIN employee_departments ed ON e.id = ed.employee_id
                     WHERE ed.department_id IN (" . implode(',', $branchDeptIds) . ")
                       AND ed.is_primary = 1
                       AND (r.hierarchy_level >= ? OR r.hierarchy_level IS NULL)
                       AND COALESCE(r.hierarchy_level, 0) != 1",
                    [$userHierarchy]
                );
                $ids = array_column($result, 'id');
                $ids = array_map('intval', $ids);
                if (!in_array((int) $userId, $ids)) {
                    $ids[] = (int) $userId; // always include the user themselves
                }
                return array_values(array_unique($ids));

            default:
                return [$userId];
        }
    }
    
    /**
     * Get reporting tree (recursive)
     * Returns all employees in the reporting hierarchy (including the manager)
     * Handles edge cases: circular references, inactive employees, no reports
     */
    public function getReportingTree($managerId, &$visited = []) {
        // Prevent circular references
        if (in_array($managerId, $visited)) return [];
        $visited[] = $managerId;
        
        // Start with the manager themselves
        $ids = [$managerId];
        
        // Get direct reports
        $directReports = $this->db->fetchAll(
            "SELECT id FROM employees WHERE reporting_manager_id = ?",
            [$managerId]
        );
        
        // Recursively get all indirect reports
        foreach ($directReports as $report) {
            $ids = array_merge($ids, $this->getReportingTree($report['id'], $visited));
        }
        
        return array_values(array_unique($ids));
    }

    /**
     * Direct hierarchy branch scope (same rule as the Reports module).
     * Privileged roles (HR Manager / Super Admin / Admin, hierarchy_level <= 3)
     * see ALL employees.
     *
     * Everyone else sees their direct reporting branch (self + recursive reports
     * via reporting_manager_id).
     *
     * ROOT managers (top of the reporting hierarchy - no reporting manager of
     * their own) additionally see ALL employees of every department they are
     * mapped to (primary or secondary), including sub-department trees.
     * Department members who do NOT report into the viewer's branch stay
     * invisible to non-root managers. Employees at Super-Admin level (1) are
     * never exposed, and employees above the viewer's own hierarchy level stay
     * hidden.
     */
    public function getDirectBranchScopeIds($userId, $excludeRole = null) {
        $user = $this->db->fetchOne(
            "SELECT e.*, r.name as role_name, r.hierarchy_level
             FROM employees e LEFT JOIN roles r ON e.role_id = r.id
             WHERE e.id = ?",
            [$userId]
        );

        if (!$user) return [];

        $privilegedRoleNames = ['super admin', 'admin', 'hr manager', 'hr executive'];
        // Optional per-call exclusion: lets a caller drop one role from the
        // privileged set (e.g. the Attendance Table tab keeps HR Executive on
        // her own reporting branch) without affecting other callers.
        if ($excludeRole !== null) {
            $privilegedRoleNames = array_values(array_diff($privilegedRoleNames, [strtolower((string) $excludeRole)]));
        }
        $roleName = strtolower((string) ($user['role_name'] ?? ''));
        $isPrivileged = in_array($roleName, $privilegedRoleNames)
            || (int) ($user['hierarchy_level'] ?? 999) <= 3;

        if ($isPrivileged) {
            $result = $this->db->fetchAll("SELECT id FROM employees");
            return array_map('intval', array_column($result, 'id'));
        }

        // Direct reporting branch: self + recursive reports
        $ids = array_map('intval', $this->getReportingTree($userId));

        $viewerLevel = (int) ($user['hierarchy_level'] ?? 999);
        $isRoot = empty($user['reporting_manager_id']);

        // Only ROOT managers get department-wide visibility (their primary and
        // secondary departments). Non-root managers are limited to their direct
        // reporting branch - department members outside that branch (not
        // reporting into it) stay hidden.
        if ($isRoot) {
            $mappedDepts = $this->db->fetchAll(
                "SELECT DISTINCT department_id FROM employee_departments WHERE employee_id = ?",
                [$userId]
            );

            if (!empty($mappedDepts)) {
                $includedDeptIds = [];
                foreach ($mappedDepts as $dept) {
                    foreach ($this->getSubDepartmentIds((int) $dept['department_id']) as $subDeptId) {
                        $includedDeptIds[] = (int) $subDeptId;
                    }
                }

                $includedDeptIds = array_values(array_unique($includedDeptIds));
                $deptEmployees = $this->db->fetchAll(
                    "SELECT DISTINCT e.id
                     FROM employees e
                     JOIN employee_departments ed ON ed.employee_id = e.id
                     LEFT JOIN roles r ON e.role_id = r.id
                     WHERE ed.is_primary = 1
                       AND ed.department_id IN (" . implode(',', $includedDeptIds) . ")
                       AND e.is_active = 1
                       AND (r.hierarchy_level >= ? OR r.hierarchy_level IS NULL)
                       AND COALESCE(r.hierarchy_level, 0) != 1",
                    [$viewerLevel]
                );
                $ids = array_values(array_unique(array_merge(
                    $ids,
                    array_map('intval', array_column($deptEmployees, 'id'))
                )));
            }
        }

        return $ids;
    }

    /**
     * Get department tree (recursive) - includes ALL departments the user belongs to (primary AND secondary)
     */
    private function getDepartmentTree($employeeId) {
        $userDepts = $this->db->fetchAll(
            "SELECT department_id FROM employee_departments WHERE employee_id = ?",
            [$employeeId]
        );
        
        if (empty($userDepts)) return [];
        
        $allDeptIds = [];
        foreach ($userDepts as $dept) {
            $allDeptIds = array_merge($allDeptIds, $this->getSubDepartmentIds($dept['department_id']));
        }
        
        return array_values(array_unique($allDeptIds));
    }
    
    /**
     * Get sub-department IDs recursively
     */
    private function getSubDepartmentIds($deptId, &$visited = []) {
        if (in_array($deptId, $visited)) return [];
        $visited[] = $deptId;

        $subDepts = $this->db->fetchAll(
            "SELECT id FROM departments WHERE parent_department_id = ? AND is_active = 1",
            [$deptId]
        );

        $ids = [$deptId];
        foreach ($subDepts as $dept) {
            $ids = array_merge($ids, $this->getSubDepartmentIds($dept['id'], $visited));
        }

        return $ids;
    }

    /**
     * Get permission matrix for role-based permission management
     * Returns roles, modules, actions, and current permission matrix
     */
    public function getPermissionMatrix() {
        $roles = $this->db->fetchAll("SELECT * FROM roles WHERE is_active = 1 ORDER BY hierarchy_level");
        $modules = $this->db->fetchAll("SELECT * FROM modules ORDER BY name");
        $actions = ['read', 'create', 'update', 'delete', 'approve'];
        
        $matrix = [];
        foreach ($roles as $role) {
            $matrix[$role['id']] = [];
            foreach ($modules as $module) {
                $matrix[$role['id']][$module['id']] = [];
                foreach ($actions as $action) {
                    $permission = $this->db->fetchOne(
                        "SELECT p.*, p.scope FROM permissions p 
                         WHERE p.role_id = ? AND p.module_id = ? AND p.action = ?",
                        [$role['id'], $module['id'], $action]
                    );
                    $matrix[$role['id']][$module['id']][$action] = $permission ? [
                        'allowed' => (bool) $permission['allowed'],
                        'scope' => $permission['scope'] ?? 'self'
                    ] : ['allowed' => false, 'scope' => 'self'];
                }
            }
        }
        
        return [
            'roles' => $roles,
            'modules' => $modules,
            'actions' => $actions,
            'matrix' => $matrix
        ];
    }

    /**
     * Grant permission to a role
     */
    public function grantToRole($roleId, $moduleId, $action, $scope = 'self') {
        $existing = $this->db->fetchOne(
            "SELECT id FROM permissions WHERE role_id = ? AND module_id = ? AND action = ?",
            [$roleId, $moduleId, $action]
        );
        
        if ($existing) {
            $this->db->update('permissions', 
                ['allowed' => 1, 'scope' => $scope], 
                'id = ?', 
                [$existing['id']]
            );
        } else {
            $this->db->insert('permissions', [
                'role_id' => $roleId,
                'module_id' => $moduleId,
                'action' => $action,
                'allowed' => 1,
                'scope' => $scope
            ]);
        }
    }

    /**
     * Revoke permission from a role
     */
    public function revokeFromRole($roleId, $moduleId, $action, $scope = 'self') {
        $this->db->update('permissions', 
            ['allowed' => 0], 
            'role_id = ? AND module_id = ? AND action = ?', 
            [$roleId, $moduleId, $action]
        );
    }

    /**
     * Get hierarchy-based notification recipients for leave requests
     * Returns all employees at higher hierarchy levels in the same department
     * HR Manager (level 3) always receives notifications for all leave requests
     */
    public function getHierarchyNotificationRecipients($employeeId, $module = 'leaves') {
        $employee = $this->db->fetchOne(
            "SELECT e.*, r.hierarchy_level, r.name as role_name
             FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE e.id = ?",
            [$employeeId]
        );

        if (!$employee) return [];

        $recipients = [];

        // Include Super Admin in all notification chains
        $superAdmins = $this->db->fetchAll(
            "SELECT e.id FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE r.hierarchy_level = 1",
            []
        );
        foreach ($superAdmins as $sa) {
            if (!in_array($sa['id'], $recipients)) {
                $recipients[] = $sa['id'];
            }
        }

        // Get employee's primary department
        $primaryDept = $this->db->fetchOne(
            "SELECT department_id FROM employee_departments WHERE employee_id = ? AND is_primary = 1",
            [$employeeId]
        );

        if (!$primaryDept) return [];

        // Always include HR Manager (hierarchy level 3) for leave requests
        if ($module === 'leaves') {
            $hrManagers = $this->db->fetchAll(
                "SELECT e.id FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
                 WHERE r.hierarchy_level = 3 AND r.name LIKE '%HR Manager%'",
                []
            );
            foreach ($hrManagers as $hr) {
                if (!in_array($hr['id'], $recipients)) {
                    $recipients[] = $hr['id'];
                }
            }
        }

        // Get all employees in the same department with lower hierarchy level (higher authority)
        $employeeHierarchy = isset($employee['hierarchy_level']) ? $employee['hierarchy_level'] : 999;
        $higherAuthorities = $this->db->fetchAll(
            "SELECT e.id, r.hierarchy_level
             FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             WHERE ed.department_id = ? AND r.hierarchy_level < ?
             ORDER BY r.hierarchy_level ASC",
            [$primaryDept['department_id'], $employeeHierarchy]
        );

        foreach ($higherAuthorities as $authority) {
            if (!in_array($authority['id'], $recipients)) {
                $recipients[] = $authority['id'];
            }
        }

        return $recipients;
    }

    /**
     * Get reporting chain recipients for notifications
     * Follows the branching hierarchy through direct reporting managers
     * Employee -> Direct Manager -> Manager's Manager -> ... -> HR Manager
     */
    public function getReportingChainRecipients($employeeId, $module = 'leaves') {
        $employee = $this->db->fetchOne(
            "SELECT e.*, r.hierarchy_level, r.name as role_name
             FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE e.id = ?",
            [$employeeId]
        );

        if (!$employee) return [];

        $recipients = [];

        // Include Super Admin in all notification chains
        $superAdmins = $this->db->fetchAll(
            "SELECT e.id FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE r.hierarchy_level = 1",
            []
        );
        foreach ($superAdmins as $sa) {
            if (!in_array($sa['id'], $recipients)) {
                $recipients[] = $sa['id'];
            }
        }

        $currentEmployee = $employee;
        $visited = [$employeeId]; // Prevent infinite loops

        // Follow the reporting chain up through direct managers
        while ($currentEmployee && $currentEmployee['reporting_manager_id']) {
            $manager = $this->db->fetchOne(
                "SELECT e.*, r.hierarchy_level, r.name as role_name
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE e.id = ?",
                [$currentEmployee['reporting_manager_id']]
            );

            if (!$manager || in_array($manager['id'], $visited)) {
                break; // Manager not found or loop detected
            }

            $recipients[] = $manager['id'];
            $visited[] = $manager['id'];
            $currentEmployee = $manager;
        }

        // Always include HR Manager for leave requests
        if ($module === 'leaves') {
            $hrManagers = $this->db->fetchAll(
                "SELECT e.id FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE r.hierarchy_level = 3 AND r.name LIKE '%HR Manager%'",
                []
            );
            foreach ($hrManagers as $hr) {
                if (!in_array($hr['id'], $recipients)) {
                    $recipients[] = $hr['id'];
                }
            }
        }

        return $recipients;
    }

    /**
     * Get leave approval recipients based on reporting hierarchy
     * Returns only the appropriate approver(s) in the reporting chain
     * Priority: Direct reporting manager -> Department head -> HR Manager
     */
    public function getLeaveApprovalRecipients($employeeId) {
        $employee = $this->db->fetchOne(
            "SELECT e.*, r.hierarchy_level, r.name as role_name
             FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE e.id = ?",
            [$employeeId]
        );

        if (!$employee) return [];

        $recipients = [];

        // Include Super Admin in all approval chains
        $superAdmins = $this->db->fetchAll(
            "SELECT e.id FROM employees e
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE r.hierarchy_level = 1",
            []
        );
        foreach ($superAdmins as $sa) {
            if (!in_array($sa['id'], $recipients)) {
                $recipients[] = $sa['id'];
            }
        }

        // First priority: Direct reporting manager
        if ($employee['reporting_manager_id']) {
            $manager = $this->db->fetchOne(
                "SELECT e.*, r.hierarchy_level, r.name as role_name
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE e.id = ?",
                [$employee['reporting_manager_id']]
            );
            if ($manager) {
                $recipients[] = $manager['id'];
            }
        }

        // Second priority: HR Manager if no direct manager or as backup
        if (empty($recipients)) {
            $hrManagers = $this->db->fetchAll(
                "SELECT e.id FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE r.hierarchy_level = 3 AND r.name LIKE '%HR Manager%'",
                []
            );
            foreach ($hrManagers as $hr) {
                if (!in_array($hr['id'], $recipients)) {
                    $recipients[] = $hr['id'];
                }
            }
        }

        return $recipients;
    }

    /**
     * Get employee IDs whose leave requests the given user can approve.
     * Super Admin and whole-company-scope users (HR Manager/Admin) see all scoped employees.
     * Everyone else is limited to employees whose reporting chain includes the user.
     */
    public function getApprovableEmployeeIds($userId) {
        $scopedIds = $this->getScopedEmployeeIds($userId, 'leaves', 'read');

        // Nothing to approve if the user has no read scope
        if (empty($scopedIds)) return [];

        $user = $this->db->fetchOne(
            "SELECT e.id, r.hierarchy_level FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.id = ?",
            [$userId]
        );

        // Super Admin can approve all employees' leave requests
        if ($user && isset($user['hierarchy_level']) && $user['hierarchy_level'] == 1) {
            return $scopedIds;
        }

        // Whole-company data scope (HR Manager/Admin roles) can approve all scoped
        // leave requests. Mirrors getReportingChainRecipients(), which always includes
        // HR Manager in the approval chain for leave notifications.
        if ($this->getScope($userId, 'leaves', 'read') === 'all'
            || $this->getScope($userId, 'leaves', 'approve') === 'all') {
            return $scopedIds;
        }

        // Branching hierarchy: only employees whose reporting chain includes the user
        $scopedIdsStr = implode(',', $scopedIds) ?: '0';
        $visibleEmployees = $this->db->fetchAll(
            "SELECT id, reporting_manager_id FROM employees WHERE id IN ({$scopedIdsStr}) AND is_active = 1"
        );

        $employeeMap = [];
        foreach ($visibleEmployees as $emp) {
            $employeeMap[$emp['id']] = $emp['reporting_manager_id'];
        }

        $approvableEmployeeIds = [];
        foreach ($visibleEmployees as $emp) {
            if ($emp['id'] == $userId) continue; // Skip self

            $currentId = $emp['id'];
            $visited = [];
            $userInChain = false;

            // Trace up the reporting chain
            while ($currentId && !in_array($currentId, $visited)) {
                $visited[] = $currentId;

                if ($currentId == $userId) {
                    $userInChain = true;
                    break;
                }

                $currentId = $employeeMap[$currentId] ?? null;
            }

            if ($userInChain) {
                $approvableEmployeeIds[] = $emp['id'];
            }
        }

        return $approvableEmployeeIds;
    }
}

/**
 * Get hierarchy-based notification recipients (global function)
 */
function getHierarchyNotificationRecipients($employeeId, $module = 'leaves') {
    $permissions = new Permissions();
    return $permissions->getHierarchyNotificationRecipients($employeeId, $module);
}

/**
 * Get reporting chain recipients (global function)
 */
function getReportingChainRecipients($employeeId, $module = 'leaves') {
    $permissions = new Permissions();
    return $permissions->getReportingChainRecipients($employeeId, $module);
}

/**
 * Get leave approval recipients (global function)
 */
function getLeaveApprovalRecipients($employeeId) {
    $permissions = new Permissions();
    return $permissions->getLeaveApprovalRecipients($employeeId);
}

/**
 * Get employee IDs whose leave requests the current user can approve (global function)
 */
function getApprovableEmployeeIds($userId) {
    $permissions = new Permissions();
    return $permissions->getApprovableEmployeeIds($userId);
}


