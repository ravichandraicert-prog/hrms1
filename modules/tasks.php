<?php
/**
 * Tasks Module
 * Core PHP HRMS Application
 * Section 9 - Task management module
 */

require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';
$permissions = new Permissions();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('tasks', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view tasks.</div>';
    exit;
}

// Get scoped employee IDs for tasks
$scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'tasks', 'read');
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// Get tab
$tab = $_GET['tab'] ?? 'my';

// Get status filter ('all' = explicitly show every status; tabs with filter
// toolbars auto-select the Pending filter when no status is chosen)
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter === 'all') {
    $statusFilter = '';
} elseif ($statusFilter === '' && in_array($tab, ['my', 'team', 'all', 'assigned'], true)) {
    $statusFilter = 'Pending';
}

// Get additional filters
$priorityFilter = $_GET['priority'] ?? '';
$departmentFilter = $_GET['department'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$recurringFilter = $_GET['recurring'] ?? '';
$assigneeFilter = $_GET['assignee'] ?? '';

// Build query based on tab
$whereConditions = [];
$params = [];

switch ($tab) {
    case 'my':
        $whereConditions[] = "EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.assignee_id = ?)";
        $params[] = $user['id'];
        break;
    case 'team':
        // Show tasks assigned to employees in user's department hierarchy (excluding user's own tasks)
        $teamScopedIds = $permissions->getScopedEmployeeIds($user['id'], 'tasks', 'read');
        // Remove user's own ID from team scope to avoid showing their own tasks
        $teamScopedIds = array_filter($teamScopedIds, function($id) use ($user) {
            return $id != $user['id'];
        });
        $teamScopedIdsStr = implode(',', $teamScopedIds) ?: '0';
        $whereConditions[] = "EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.assignee_id IN ({$teamScopedIdsStr}))";
        break;
    case 'assigned':
        $whereConditions[] = "t.assigned_by = ?";
        $params[] = $user['id'];
        break;
    case 'all':
        // Show tasks visible within user's hierarchical scope
        $allScopedIds = $permissions->getScopedEmployeeIds($user['id'], 'tasks', 'read');
        $allScopedIdsStr = implode(',', $allScopedIds) ?: '0';
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM task_assignments ta 
            WHERE ta.task_id = t.id AND ta.assignee_id IN ({$allScopedIdsStr})
        )";
        break;
}

if ($statusFilter === 'Overdue') {
    // Overdue filter: open tasks (not Completed/Cancelled) whose due date is before today
    $whereConditions[] = "t.deadline IS NOT NULL AND t.deadline < CURDATE() AND ts.name NOT IN ('Completed','Cancelled')";
} elseif ($statusFilter) {
    $whereConditions[] = "ts.name = ?";
    $params[] = $statusFilter;
}

if ($priorityFilter) {
    $whereConditions[] = "tp.name = ?";
    $params[] = $priorityFilter;
}

if ($departmentFilter) {
    $whereConditions[] = "EXISTS (
        SELECT 1 FROM task_assignments ta 
        JOIN employees e ON ta.assignee_id = e.id
        JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
        WHERE ta.task_id = t.id AND ed.department_id = ?
    )";
    $params[] = $departmentFilter;
}

if ($searchFilter) {
    $whereConditions[] = "(t.title LIKE ? OR t.description LIKE ? OR 
        EXISTS (
            SELECT 1 FROM task_assignments ta 
            JOIN employees e ON ta.assignee_id = e.id
            WHERE ta.task_id = t.id AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)
        )
    )";
    $searchTerm = '%' . $searchFilter . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($recurringFilter) {
    if ($recurringFilter === 'recurring') {
        $whereConditions[] = "t.is_recurring = 1";
    } elseif ($recurringFilter === 'one_time') {
        $whereConditions[] = "t.is_recurring = 0";
    } elseif ($recurringFilter === 'active') {
        $whereConditions[] = "t.is_recurring = 1 AND t.is_recurring_active = 1";
    } elseif ($recurringFilter === 'disabled') {
        $whereConditions[] = "t.is_recurring = 1 AND t.is_recurring_active = 0";
    }
}

if ($assigneeFilter) {
    $whereConditions[] = "EXISTS (
        SELECT 1 FROM task_assignments ta 
        WHERE ta.task_id = t.id AND ta.assignee_id = ?
    )";
    $params[] = $assigneeFilter;
}

$whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

// Get tasks
$tasks = $db->fetchAll(
    "SELECT t.*, ts.name as status_name, tp.name as priority_name,
     (SELECT CONCAT(e.first_name, ' ', e.last_name) 
      FROM task_assignments ta 
      JOIN employees e ON ta.assignee_id = e.id 
      WHERE ta.task_id = t.id LIMIT 1) as assignee_name,
     (SELECT e.employee_id 
      FROM task_assignments ta 
      JOIN employees e ON ta.assignee_id = e.id 
      WHERE ta.task_id = t.id LIMIT 1) as assignee_id,
     (SELECT d.name 
      FROM task_assignments ta 
      JOIN employees e ON ta.assignee_id = e.id
      LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
      LEFT JOIN departments d ON ed.department_id = d.id
      WHERE ta.task_id = t.id LIMIT 1) as department_name,
     ab.first_name as assigned_by_first, ab.last_name as assigned_by_last,
     t.created_at
     FROM tasks t
     LEFT JOIN task_statuses ts ON t.task_status_id = ts.id
     LEFT JOIN task_priorities tp ON t.task_priority_id = tp.id
     LEFT JOIN employees ab ON t.assigned_by = ab.id
     WHERE {$whereClause}
     ORDER BY t.deadline IS NULL, t.deadline ASC, t.created_at DESC
     LIMIT 100",
    $params
);

// Get task counts for My Tasks stats
$myTaskStats = $db->fetchAll(
    "SELECT ts.name as status_name, COUNT(*) as count
     FROM tasks t
     JOIN task_statuses ts ON t.task_status_id = ts.id
     JOIN task_assignments ta ON t.id = ta.task_id
     WHERE ta.assignee_id = ?
     GROUP BY ts.name",
    [$user['id']]
);

$myStatsMap = array_column($myTaskStats, 'count', 'status_name');
$pendingCount = $myStatsMap['Pending'] ?? 0;
$inProgressCount = $myStatsMap['In Progress'] ?? 0;
$completedCount = $myStatsMap['Completed'] ?? 0;

// Get all active statuses and priorities for the task forms
$taskStatuses = $db->fetchAll("SELECT id, name FROM task_statuses WHERE is_active = 1 ORDER BY display_order, id");
$taskPriorities = $db->fetchAll("SELECT id, name FROM task_priorities WHERE is_active = 1 ORDER BY display_order, id");

// Get departments for filter
$departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");

// Get employee options for assignee selection (all employees in user's departments - primary AND secondary)
$userDepartments = $db->fetchAll(
    "SELECT department_id FROM employee_departments WHERE employee_id = ?",
    [$user['id']]
);

if (!empty($userDepartments)) {
    $userDeptIds = array_column($userDepartments, 'department_id');
    $deptPlaceholders = implode(',', array_fill(0, count($userDeptIds), '?'));
    
    $employeeOptions = $db->fetchAll(
        "SELECT DISTINCT e.id, e.first_name, e.last_name, e.employee_id, e.position
         FROM employees e
         JOIN employee_departments ed ON e.id = ed.employee_id
         WHERE e.is_active = 1 AND ed.department_id IN ({$deptPlaceholders})
         ORDER BY e.first_name, e.last_name",
        $userDeptIds
    );
} else {
    // Fallback to scoped employees if no department found
    $employeeOptions = $db->fetchAll(
        "SELECT id, first_name, last_name, employee_id, position
         FROM employees WHERE is_active = 1 AND id IN ({$scopedIdsStr}) ORDER BY first_name, last_name"
    );
}
?>
<script>
const currentUserId = <?php echo $user['id']; ?>;
</script>

<?php
// Filter-wise task counts for the active tab (All / Pending / In Progress / Completed / Overdue).
// These counts replace the old per-tab badges and follow the selected assignee filter, so the
// number shown on each filter chip always matches the tasks that filter will list.
$allCountScopeIds = implode(',', $permissions->getScopedEmployeeIds($user['id'], 'tasks', 'read')) ?: '0';
$teamCountScopeIds = implode(',', array_filter(
    $permissions->getScopedEmployeeIds($user['id'], 'tasks', 'read'),
    function ($id) use ($user) { return $id != $user['id']; }
)) ?: '0';

$countScopeSql = '';
$countScopeParams = [];
switch ($tab) {
    case 'my':
        $countScopeSql = "EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.assignee_id = ?)";
        $countScopeParams = [$user['id']];
        break;
    case 'team':
        // Same scope as the team tab: tasks of subordinates only (current user excluded)
        $countScopeSql = "EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.assignee_id IN ({$teamCountScopeIds}))";
        break;
    case 'all':
        $countScopeSql = "EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.assignee_id IN ({$allCountScopeIds}))";
        break;
    case 'assigned':
        $countScopeSql = "t.assigned_by = ?";
        $countScopeParams = [$user['id']];
        break;
}

$filterCounts = ['all' => 0, 'Pending' => 0, 'In Progress' => 0, 'Completed' => 0, 'Overdue' => 0];

if ($countScopeSql !== '') {
    $countWhere = [$countScopeSql];
    $countParams = $countScopeParams;
    if ($assigneeFilter) {
        // Counts follow the selected assignee (specific user) filter
        $countWhere[] = "EXISTS (SELECT 1 FROM task_assignments tac WHERE tac.task_id = t.id AND tac.assignee_id = ?)";
        $countParams[] = $assigneeFilter;
    }
    $countRow = $db->fetchOne(
        "SELECT COUNT(*) AS total,
            COALESCE(SUM(ts.name = 'Pending'), 0) AS pending,
            COALESCE(SUM(ts.name = 'In Progress'), 0) AS in_progress,
            COALESCE(SUM(ts.name = 'Completed'), 0) AS completed,
            COALESCE(SUM(t.deadline IS NOT NULL AND t.deadline < CURDATE() AND ts.name NOT IN ('Completed','Cancelled')), 0) AS overdue
         FROM tasks t
         JOIN task_statuses ts ON t.task_status_id = ts.id
         WHERE " . implode(' AND ', $countWhere),
        $countParams
    );
    if ($countRow) {
        $filterCounts = [
            'all' => (int)($countRow['total'] ?? 0),
            'Pending' => (int)($countRow['pending'] ?? 0),
            'In Progress' => (int)($countRow['in_progress'] ?? 0),
            'Completed' => (int)($countRow['completed'] ?? 0),
            'Overdue' => (int)($countRow['overdue'] ?? 0),
        ];
    }
}

// Renders the count badge displayed on each status filter chip
$renderFilterCount = function (string $key) use ($filterCounts): string {
    return ' <span style="background:var(--accent);color:#FFFFFF;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600;margin-left:6px;display:inline-block;min-width:16px;text-align:center;">' . (int)($filterCounts[$key] ?? 0) . '</span>';
};
?>

<div class="tabs">
    <div class="tab <?php echo $tab == 'my' ? 'active' : ''; ?>" onclick="switchTab('my')">My Tasks</div>
    <div class="tab <?php echo $tab == 'team' ? 'active' : ''; ?>" onclick="switchTab('team')">Team Tasks</div>
    <div class="tab <?php echo $tab == 'all' ? 'active' : ''; ?>" onclick="switchTab('all')">All Tasks</div>
    <div class="tab <?php echo $tab == 'assigned' ? 'active' : ''; ?>" onclick="switchTab('assigned')">Assigned By Me</div>
</div>

<?php if ($tab == 'all'): ?>
    <div class="toolbar">
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="filter-chip <?php echo $statusFilter == '' ? 'active' : ''; ?>" onclick="filterTasks('all')">All<?php echo $renderFilterCount('all'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterTasks('Pending')">Pending<?php echo $renderFilterCount('Pending'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'In Progress' ? 'active' : ''; ?>" onclick="filterTasks('In Progress')">In Progress<?php echo $renderFilterCount('In Progress'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Completed' ? 'active' : ''; ?>" onclick="filterTasks('Completed')">Completed<?php echo $renderFilterCount('Completed'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Overdue' ? 'active' : ''; ?>" onclick="filterTasks('Overdue')">Overdue<?php echo $renderFilterCount('Overdue'); ?></div>
            <div class="spacer"></div>
            <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
            <button class="btn primary sm" onclick="addTask()" title="Add Task" style="font-size:12px;padding:6px 12px;border:1px solid var(--accent);"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="search" style="width:200px;">
                <i class="bi bi-search"></i>
                <input type="text" id="taskSearch" placeholder="Search tasks..." 
                       value="<?php echo htmlspecialchars($searchFilter); ?>" 
                       oninput="filterTasksDebounced(this)">
            </div>
            <select class="filter-chip" id="priorityFilter" onchange="applyFilter('priority', this.value)">
                <option value="">All Priorities</option>
                <?php foreach ($taskPriorities as $tp): ?>
                    <option value="<?php echo htmlspecialchars($tp['name']); ?>" <?php echo $priorityFilter == $tp['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tp['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="departmentFilter" onchange="applyFilter('department', this.value)">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $departmentFilter == $dept['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="recurringFilter" onchange="applyFilter('recurring', this.value)">
                <option value="">All Tasks</option>
                <option value="one_time" <?php echo $recurringFilter == 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                <option value="recurring" <?php echo $recurringFilter == 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                <option value="active" <?php echo $recurringFilter == 'active' ? 'selected' : ''; ?>>Active Recurring</option>
                <option value="disabled" <?php echo $recurringFilter == 'disabled' ? 'selected' : ''; ?>>Disabled Recurring</option>
            </select>
            <select class="filter-chip" id="assigneeFilter" onchange="applyFilter('assignee', this.value)">
                <option value="">All Assignees</option>
                <?php foreach ($employeeOptions as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $assigneeFilter == $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Assigned To</th>
                    <th>Assigned By</th>
                    <th>Department</th>
                    <th>Assigned Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-list-task"></i>
                                <p>No tasks found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="mono">#T-<?php echo str_pad($task['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <b><?php echo htmlspecialchars($task['title']); ?></b>
                                <?php if ($task['is_recurring']): ?>
                                    <span class="badge" style="background:var(--accent);color:white;margin-left:8px;">
                                        🔁 <?php echo $task['is_recurring_active'] ? 'Active' : 'Disabled'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($task['description']): ?>
                                    <div style="font-size:11px;color:var(--faint);">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 50)) . (strlen($task['description']) > 50 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $priorityClass = 'gray';
                                if ($task['priority_name'] == 'High') $priorityClass = 'danger';
                                elseif ($task['priority_name'] == 'Medium') $priorityClass = 'warning';
                                elseif ($task['priority_name'] == 'Low') $priorityClass = 'success';
                                ?>
                                <span class="badge <?php echo $priorityClass; ?>"><?php echo htmlspecialchars($task['priority_name'] ?? '-'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($task['assignee_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($task['assigned_by_first'] . ' ' . $task['assigned_by_last']); ?></td>
                            <td><?php echo htmlspecialchars($task['department_name'] ?? '-'); ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['created_at']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['created_at'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['created_at'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['deadline']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['deadline'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['deadline'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td>
                                <?php
                                // Auto Overdue: open task whose due date is before today
                                $deadlineTs = $task['deadline'] ? strtotime($task['deadline']) : false;
                                $isOverdue = $deadlineTs && $deadlineTs < strtotime('today')
                                    && !in_array($task['status_name'], ['Completed', 'Cancelled']);
                                if ($isOverdue) {
                                    $statusClass = 'danger';
                                    $statusLabel = 'Overdue';
                                } else {
                                    $statusClass = 'pending';
                                    if ($task['status_name'] == 'Completed') $statusClass = 'success';
                                    elseif ($task['status_name'] == 'Cancelled') $statusClass = 'gray';
                                    $statusLabel = $task['status_name'] ?? '-';
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="range" min="0" max="100" value="<?php echo $task['progress'] ?? 0; ?>" 
                                           class="progress-slider" 
                                           data-task-id="<?php echo $task['id']; ?>"
                                           onchange="updateProgress(<?php echo $task['id']; ?>, this.value)"
                                           oninput="this.nextElementSibling.textContent = this.value + '%'">
                                    <span style="font-size:11px;min-width:35px;"><?php echo $task['progress'] ?? 0; ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <?php 
                                    $currentUserId = getCurrentUserId();
                                    $isAssignee = isTaskAssignee($task['id'], $currentUserId);
                                    $isAssigner = isTaskAssigner($task['id'], $currentUserId);
                                    $hasEditPerm = hasPermission('tasks', 'edit');
                                    
                                    // Show Update Status button ONLY for assignees (not assigners)
                                    $canUpdate = $isAssignee;
                                    if ($canUpdate): 
                                    ?>
                                    <div class="icon-btn" onclick="openAssigneeUpdateModal(<?php echo $task['id']; ?>)" title="Update Status">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show Edit button only for assigners OR users with edit permission
                                    $canEdit = $hasEditPerm || $isAssigner;
                                    if ($canEdit): 
                                    ?>
                                    <div class="icon-btn" onclick="editTask(<?php echo $task['id']; ?>)" title="Edit Task">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $canDelete = hasPermission('tasks', 'delete') || $isAssigner;
                                    if ($canDelete): 
                                    ?>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteTask(<?php echo $task['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'my'): ?>
    <div class="toolbar">
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="filter-chip <?php echo $statusFilter == '' ? 'active' : ''; ?>" onclick="filterTasks('all')">All<?php echo $renderFilterCount('all'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterTasks('Pending')">Pending<?php echo $renderFilterCount('Pending'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'In Progress' ? 'active' : ''; ?>" onclick="filterTasks('In Progress')">In Progress<?php echo $renderFilterCount('In Progress'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Completed' ? 'active' : ''; ?>" onclick="filterTasks('Completed')">Completed<?php echo $renderFilterCount('Completed'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Overdue' ? 'active' : ''; ?>" onclick="filterTasks('Overdue')">Overdue<?php echo $renderFilterCount('Overdue'); ?></div>
            <div class="spacer"></div>
            <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        </div>
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="search" style="width:200px;">
                <i class="bi bi-search"></i>
                <input type="text" id="taskSearch" placeholder="Search tasks..." 
                       value="<?php echo htmlspecialchars($searchFilter); ?>" 
                       oninput="filterTasksDebounced(this)">
            </div>
            <select class="filter-chip" id="priorityFilter" onchange="applyFilter('priority', this.value)">
                <option value="">All Priorities</option>
                <?php foreach ($taskPriorities as $tp): ?>
                    <option value="<?php echo htmlspecialchars($tp['name']); ?>" <?php echo $priorityFilter == $tp['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tp['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="recurringFilter" onchange="applyFilter('recurring', this.value)">
                <option value="">All Tasks</option>
                <option value="one_time" <?php echo $recurringFilter == 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                <option value="recurring" <?php echo $recurringFilter == 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                <option value="active" <?php echo $recurringFilter == 'active' ? 'selected' : ''; ?>>Active Recurring</option>
                <option value="disabled" <?php echo $recurringFilter == 'disabled' ? 'selected' : ''; ?>>Disabled Recurring</option>
            </select>
        </div>
    </div>
    <div class="row-3">
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--warning);"><?php echo $pendingCount; ?></div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">Pending tasks</div>
        </div>
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--accent);"><?php echo $inProgressCount; ?></div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">In progress</div>
        </div>
        <div class="panel" style="text-align:center;">
            <div class="balance-num" style="font-size:28px;color:var(--success);"><?php echo $completedCount; ?></div>
            <div class="balance-lbl" style="font-size:12px;color:var(--muted);margin-top:4px;">Completed</div>
        </div>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Assigned By</th>
                    <th>Assigned Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-list-task"></i>
                                <p>No tasks found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="mono">#T-<?php echo str_pad($task['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td><b><?php echo htmlspecialchars($task['title']); ?></b></td>
                            <td>
                                <?php
                                $priorityClass = 'gray';
                                if ($task['priority_name'] == 'High') $priorityClass = 'danger';
                                elseif ($task['priority_name'] == 'Medium') $priorityClass = 'warning';
                                elseif ($task['priority_name'] == 'Low') $priorityClass = 'success';
                                ?>
                                <span class="badge <?php echo $priorityClass; ?>"><?php echo htmlspecialchars($task['priority_name'] ?? '-'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($task['assigned_by_first'] . ' ' . $task['assigned_by_last']); ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['created_at']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['created_at'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['created_at'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['deadline']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['deadline'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['deadline'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td>
                                <?php
                                // Auto Overdue: open task whose due date is before today
                                $deadlineTs = $task['deadline'] ? strtotime($task['deadline']) : false;
                                $isOverdue = $deadlineTs && $deadlineTs < strtotime('today')
                                    && !in_array($task['status_name'], ['Completed', 'Cancelled']);
                                if ($isOverdue) {
                                    $statusClass = 'danger';
                                    $statusLabel = 'Overdue';
                                } else {
                                    $statusClass = 'pending';
                                    if ($task['status_name'] == 'Completed') $statusClass = 'success';
                                    elseif ($task['status_name'] == 'Cancelled') $statusClass = 'gray';
                                    $statusLabel = $task['status_name'] ?? '-';
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </td>
                            <td>
                                <span style="font-size:13px;"><?php echo $task['progress'] ?? 0; ?>%</span>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <?php
                                    $currentUserId = getCurrentUserId();
                                    $isAssignee = isTaskAssignee($task['id'], $currentUserId);

                                    // Show update icon for assignees (opens simplified status/progress modal)
                                    if ($isAssignee):
                                    ?>
                                    <div class="icon-btn" onclick="openAssigneeUpdateModal(<?php echo $task['id']; ?>)" title="Update Status">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'team'): ?>
    <div class="toolbar">
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="filter-chip <?php echo $statusFilter == '' ? 'active' : ''; ?>" onclick="filterTasks('all')">All<?php echo $renderFilterCount('all'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterTasks('Pending')">Pending<?php echo $renderFilterCount('Pending'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'In Progress' ? 'active' : ''; ?>" onclick="filterTasks('In Progress')">In Progress<?php echo $renderFilterCount('In Progress'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Completed' ? 'active' : ''; ?>" onclick="filterTasks('Completed')">Completed<?php echo $renderFilterCount('Completed'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Overdue' ? 'active' : ''; ?>" onclick="filterTasks('Overdue')">Overdue<?php echo $renderFilterCount('Overdue'); ?></div>
            <div class="spacer"></div>
            <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
            <button class="btn primary sm" onclick="addTask()" title="Add Task" style="font-size:12px;padding:6px 12px;border:1px solid var(--accent);"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="search" style="width:200px;">
                <i class="bi bi-search"></i>
                <input type="text" id="taskSearch" placeholder="Search tasks..." 
                       value="<?php echo htmlspecialchars($searchFilter); ?>" 
                       oninput="filterTasksDebounced(this)">
            </div>
            <select class="filter-chip" id="priorityFilter" onchange="applyFilter('priority', this.value)">
                <option value="">All Priorities</option>
                <?php foreach ($taskPriorities as $tp): ?>
                    <option value="<?php echo htmlspecialchars($tp['name']); ?>" <?php echo $priorityFilter == $tp['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tp['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="departmentFilter" onchange="applyFilter('department', this.value)">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $departmentFilter == $dept['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="recurringFilter" onchange="applyFilter('recurring', this.value)">
                <option value="">All Tasks</option>
                <option value="one_time" <?php echo $recurringFilter == 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                <option value="recurring" <?php echo $recurringFilter == 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                <option value="active" <?php echo $recurringFilter == 'active' ? 'selected' : ''; ?>>Active Recurring</option>
                <option value="disabled" <?php echo $recurringFilter == 'disabled' ? 'selected' : ''; ?>>Disabled Recurring</option>
            </select>
            <select class="filter-chip" id="assigneeFilter" onchange="applyFilter('assignee', this.value)">
                <option value="">All Assignees</option>
                <?php foreach ($employeeOptions as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $assigneeFilter == $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Assigned To</th>
                    <th>Assigned By</th>
                    <th>Department</th>
                    <th>Assigned Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-list-task"></i>
                                <p>No team tasks found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="mono">#T-<?php echo str_pad($task['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <b><?php echo htmlspecialchars($task['title']); ?></b>
                                <?php if ($task['is_recurring']): ?>
                                    <span class="badge" style="background:var(--accent);color:white;margin-left:8px;">
                                        🔁 <?php echo $task['is_recurring_active'] ? 'Active' : 'Disabled'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($task['description']): ?>
                                    <div style="font-size:11px;color:var(--faint);">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 50)) . (strlen($task['description']) > 50 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $priorityClass = 'gray';
                                if ($task['priority_name'] == 'High') $priorityClass = 'danger';
                                elseif ($task['priority_name'] == 'Medium') $priorityClass = 'warning';
                                elseif ($task['priority_name'] == 'Low') $priorityClass = 'success';
                                ?>
                                <span class="badge <?php echo $priorityClass; ?>"><?php echo htmlspecialchars($task['priority_name'] ?? '-'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($task['assignee_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($task['assigned_by_first'] . ' ' . $task['assigned_by_last']); ?></td>
                            <td><?php echo htmlspecialchars($task['department_name'] ?? '-'); ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['created_at']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['created_at'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['created_at'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['deadline']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['deadline'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['deadline'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td>
                                <?php
                                // Auto Overdue: open task whose due date is before today
                                $deadlineTs = $task['deadline'] ? strtotime($task['deadline']) : false;
                                $isOverdue = $deadlineTs && $deadlineTs < strtotime('today')
                                    && !in_array($task['status_name'], ['Completed', 'Cancelled']);
                                if ($isOverdue) {
                                    $statusClass = 'danger';
                                    $statusLabel = 'Overdue';
                                } else {
                                    $statusClass = 'pending';
                                    if ($task['status_name'] == 'Completed') $statusClass = 'success';
                                    elseif ($task['status_name'] == 'Cancelled') $statusClass = 'gray';
                                    $statusLabel = $task['status_name'] ?? '-';
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="range" min="0" max="100" value="<?php echo $task['progress'] ?? 0; ?>" 
                                           class="progress-slider" 
                                           data-task-id="<?php echo $task['id']; ?>"
                                           onchange="updateProgress(<?php echo $task['id']; ?>, this.value)"
                                           oninput="this.nextElementSibling.textContent = this.value + '%'">
                                    <span style="font-size:11px;min-width:35px;"><?php echo $task['progress'] ?? 0; ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <?php 
                                    $currentUserId = getCurrentUserId();
                                    $isAssignee = isTaskAssignee($task['id'], $currentUserId);
                                    $isAssigner = isTaskAssigner($task['id'], $currentUserId);
                                    $hasEditPerm = hasPermission('tasks', 'edit');
                                    
                                    // Show Update Status button ONLY for assignees (not assigners)
                                    $canUpdate = $isAssignee;
                                    if ($canUpdate): 
                                    ?>
                                    <div class="icon-btn" onclick="openAssigneeUpdateModal(<?php echo $task['id']; ?>)" title="Update Status">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show Edit button only for assigners OR users with edit permission
                                    $canEdit = $hasEditPerm || $isAssigner;
                                    if ($canEdit): 
                                    ?>
                                    <div class="icon-btn" onclick="editTask(<?php echo $task['id']; ?>)" title="Edit Task">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $canDelete = hasPermission('tasks', 'delete') || $isAssigner;
                                    if ($canDelete): 
                                    ?>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteTask(<?php echo $task['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'assigned'): ?>
    <div class="toolbar">
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="filter-chip <?php echo $statusFilter == '' ? 'active' : ''; ?>" onclick="filterTasks('all')">All<?php echo $renderFilterCount('all'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Pending' ? 'active' : ''; ?>" onclick="filterTasks('Pending')">Pending<?php echo $renderFilterCount('Pending'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'In Progress' ? 'active' : ''; ?>" onclick="filterTasks('In Progress')">In Progress<?php echo $renderFilterCount('In Progress'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Completed' ? 'active' : ''; ?>" onclick="filterTasks('Completed')">Completed<?php echo $renderFilterCount('Completed'); ?></div>
            <div class="filter-chip <?php echo $statusFilter == 'Overdue' ? 'active' : ''; ?>" onclick="filterTasks('Overdue')">Overdue<?php echo $renderFilterCount('Overdue'); ?></div>
            <div class="spacer"></div>
            <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
            <button class="btn primary sm" onclick="addTask()" title="Add Task" style="font-size:12px;padding:6px 12px;border:1px solid var(--accent);"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div style="display:flex;gap:10px;align-items:center;width:100%;overflow-x:auto;">
            <div class="search" style="width:200px;">
                <i class="bi bi-search"></i>
                <input type="text" id="taskSearch" placeholder="Search tasks..." 
                       value="<?php echo htmlspecialchars($searchFilter); ?>" 
                       oninput="filterTasksDebounced(this)">
            </div>
            <select class="filter-chip" id="priorityFilter" onchange="applyFilter('priority', this.value)">
                <option value="">All Priorities</option>
                <?php foreach ($taskPriorities as $tp): ?>
                    <option value="<?php echo htmlspecialchars($tp['name']); ?>" <?php echo $priorityFilter == $tp['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tp['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="departmentFilter" onchange="applyFilter('department', this.value)">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $departmentFilter == $dept['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="filter-chip" id="recurringFilter" onchange="applyFilter('recurring', this.value)">
                <option value="">All Tasks</option>
                <option value="one_time" <?php echo $recurringFilter == 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                <option value="recurring" <?php echo $recurringFilter == 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                <option value="active" <?php echo $recurringFilter == 'active' ? 'selected' : ''; ?>>Active Recurring</option>
                <option value="disabled" <?php echo $recurringFilter == 'disabled' ? 'selected' : ''; ?>>Disabled Recurring</option>
            </select>
            <select class="filter-chip" id="assigneeFilter" onchange="applyFilter('assignee', this.value)">
                <option value="">All Assignees</option>
                <?php foreach ($employeeOptions as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $assigneeFilter == $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Assigned To</th>
                    <th>Assigned Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-list-task"></i>
                                <p>No tasks found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="mono">#T-<?php echo str_pad($task['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td><b><?php echo htmlspecialchars($task['title']); ?></b></td>
                            <td>
                                <?php
                                $priorityClass = 'gray';
                                if ($task['priority_name'] == 'High') $priorityClass = 'danger';
                                elseif ($task['priority_name'] == 'Medium') $priorityClass = 'warning';
                                elseif ($task['priority_name'] == 'Low') $priorityClass = 'success';
                                ?>
                                <span class="badge <?php echo $priorityClass; ?>"><?php echo htmlspecialchars($task['priority_name'] ?? '-'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($task['assignee_name'] ?? '-'); ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['created_at']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['created_at'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['created_at'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td class="mono" style="white-space:nowrap;"><?php if ($task['deadline']): ?><div style="font-weight:600;font-size:13px;"><?php echo date('d M', strtotime($task['deadline'])); ?></div><div style="font-size:11px;color:var(--muted);"><?php echo date('Y', strtotime($task['deadline'])); ?></div><?php else: ?>—<?php endif; ?></td>
                            <td>
                                <?php
                                // Auto Overdue: open task whose due date is before today
                                $deadlineTs = $task['deadline'] ? strtotime($task['deadline']) : false;
                                $isOverdue = $deadlineTs && $deadlineTs < strtotime('today')
                                    && !in_array($task['status_name'], ['Completed', 'Cancelled']);
                                if ($isOverdue) {
                                    $statusClass = 'danger';
                                    $statusLabel = 'Overdue';
                                } else {
                                    $statusClass = 'pending';
                                    if ($task['status_name'] == 'Completed') $statusClass = 'success';
                                    elseif ($task['status_name'] == 'Cancelled') $statusClass = 'gray';
                                    $statusLabel = $task['status_name'] ?? '-';
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="range" min="0" max="100" value="<?php echo $task['progress'] ?? 0; ?>" 
                                           class="progress-slider" 
                                           data-task-id="<?php echo $task['id']; ?>"
                                           onchange="updateProgress(<?php echo $task['id']; ?>, this.value)"
                                           oninput="this.nextElementSibling.textContent = this.value + '%'">
                                    <span style="font-size:11px;min-width:35px;"><?php echo $task['progress'] ?? 0; ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <?php 
                                    $currentUserId = getCurrentUserId();
                                    $isAssignee = isTaskAssignee($task['id'], $currentUserId);
                                    $isAssigner = isTaskAssigner($task['id'], $currentUserId);
                                    $hasEditPerm = hasPermission('tasks', 'edit');
                                    
                                    // Show Update Status button ONLY for assignees (not assigners)
                                    $canUpdate = $isAssignee;
                                    if ($canUpdate): 
                                    ?>
                                    <div class="icon-btn" onclick="openAssigneeUpdateModal(<?php echo $task['id']; ?>)" title="Update Status">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show Edit button only for assigners OR users with edit permission
                                    $canEdit = $hasEditPerm || $isAssigner;
                                    if ($canEdit): 
                                    ?>
                                    <div class="icon-btn" onclick="editTask(<?php echo $task['id']; ?>)" title="Edit Task">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $canDelete = hasPermission('tasks', 'delete') || $isAssigner;
                                    if ($canDelete): 
                                    ?>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteTask(<?php echo $task['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Task Modal -->
<div id="taskModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:800px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="taskModalTitle">Add Task</h3>
            <button onclick="closeTaskModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="taskId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Title <span class="req">*</span></label>
                <input type="text" id="taskTitle" placeholder="Enter task title">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="taskDescription" rows="8" placeholder="Enter task details" style="resize:vertical;min-height:120px;"></textarea>
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Assignees</label>
                <div style="position:relative;">
                    <div id="assigneeDropdown" style="border:1px solid var(--border);border-radius:4px;padding:8px 12px;cursor:pointer;background:var(--surface);min-height:38px;display:flex;align-items:center;justify-content:space-between;" onclick="toggleAssigneeDropdown()">
                        <span id="selectedAssignees" style="color:var(--muted);">Select assignees...</span>
                        <i class="bi bi-chevron-down" style="color:var(--muted);"></i>
                    </div>
                    <div id="assigneeOptions" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:4px;max-height:200px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,0.1);margin-top:4px;">
                        <div style="padding:8px 12px;border-bottom:1px solid var(--border);">
                            <input type="text" id="assigneeSearch" placeholder="Search assignees..." style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:13px;" oninput="filterAssignees()">
                        </div>
                        <div id="assigneeList">
                            <?php foreach ($employeeOptions as $emp): ?>
                                <label class="assignee-option" style="display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0;font-size:13px;cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.2s;" data-assignee-id="<?php echo $emp['id']; ?>" data-assignee-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>" data-assignee-search="<?php echo strtolower(htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' ' . $emp['employee_id'])); ?>">
                                    <input type="checkbox" name="task_assignee" value="<?php echo $emp['id']; ?>" class="assignee-checkbox" style="width:16px;height:16px;cursor:pointer;" onclick="event.stopPropagation()">
                                    <span style="flex:1;"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?> &mdash; <?php echo htmlspecialchars($emp['employee_id']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                    <input type="checkbox" id="setForYourself" style="width:16px;height:16px;cursor:pointer;" onchange="toggleSetForYourself()">
                    <label for="setForYourself" style="font-size:13px;cursor:pointer;margin:0;">Set for Yourself</label>
                </div>
            </div>
            <div class="field">
                <label>Priority</label>
                <select id="taskPriority">
                    <?php foreach ($taskPriorities as $tp): ?>
                        <option value="<?php echo htmlspecialchars($tp['name']); ?>"><?php echo htmlspecialchars($tp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Due Date</label>
                <input type="datetime-local" id="taskDeadline">
            </div>
            <div class="field">
                <label>Status</label>
                <select id="taskStatus">
                    <?php foreach ($taskStatuses as $ts): ?>
                        <option value="<?php echo htmlspecialchars($ts['name']); ?>"><?php echo htmlspecialchars($ts['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Progress (%)</label>
                <div style="display:flex;align-items:center;gap:12px;">
                    <input type="range" id="taskProgress" min="0" max="100" value="0" style="flex:1;" 
                           oninput="updateMainProgressStatus(this.value)" disabled>
                    <span id="progressValue" style="font-size:13px;font-weight:600;min-width:45px;">0%</span>
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">Progress can only be updated by assignees</div>
            </div>
            <!-- Recurring Task Configuration -->
            <div class="field" style="grid-column:1/-1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                    <input type="checkbox" id="isRecurring" style="width:16px;height:16px;cursor:pointer;" onchange="toggleRecurringConfig()">
                    <label for="isRecurring" style="font-size:14px;font-weight:500;cursor:pointer;margin:0;">Recurring Task</label>
                </div>
                
                <div id="recurringConfig" style="display:none;background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-top:8px;">
                    <div class="form-grid">
                        <div class="field" style="grid-column:1/-1;">
                            <label>Repeat Pattern</label>
                            <select id="recurrencePattern" onchange="toggleDaySelection()">
                                <option value="once">Once</option>
                                <option value="daily">Daily</option>
                                <option value="custom_days">Custom Days</option>
                            </select>
                        </div>
                        
                        <div class="field" style="grid-column:1/-1;" id="daySelectionContainer" style="display:none;">
                            <label>Select Days</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="day-btn" data-day="1" onclick="toggleDay(1)">Mon</button>
                                <button type="button" class="day-btn" data-day="2" onclick="toggleDay(2)">Tue</button>
                                <button type="button" class="day-btn" data-day="3" onclick="toggleDay(3)">Wed</button>
                                <button type="button" class="day-btn" data-day="4" onclick="toggleDay(4)">Thu</button>
                                <button type="button" class="day-btn" data-day="5" onclick="toggleDay(5)">Fri</button>
                                <button type="button" class="day-btn" data-day="6" onclick="toggleDay(6)">Sat</button>
                            </div>
                        </div>
                        
                        <div class="field">
                            <label>Start Date</label>
                            <input type="date" id="recurrenceStartDate">
                        </div>
                        
                        <div class="field">
                            <label>End Date</label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="date" id="recurrenceEndDate" style="flex:1;">
                                <div style="display:flex;align-items:center;gap:4px;">
                                    <input type="checkbox" id="noEndDate" style="width:16px;height:16px;cursor:pointer;" onchange="toggleEndDate()">
                                    <label for="noEndDate" style="font-size:12px;cursor:pointer;margin:0;">No End Date</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="field">
                            <label>Task Time</label>
                            <input type="time" id="recurrenceTime">
                        </div>
                        
                        <div class="field">
                            <label>Reminder Before</label>
                            <select id="reminderMinutesBefore">
                                <option value="">No reminder</option>
                                <option value="5">5 minutes</option>
                                <option value="10">10 minutes</option>
                                <option value="15">15 minutes</option>
                                <option value="30">30 minutes</option>
                                <option value="60">1 hour</option>
                            </select>
                        </div>
                        
                        <div class="field" style="grid-column:1/-1;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" id="notificationsEnabled" style="width:16px;height:16px;cursor:pointer;">
                                <label for="notificationsEnabled" style="font-size:14px;cursor:pointer;margin:0;">Enable Notifications</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recurring Task Summary -->
                    <div id="recurringSummary" style="margin-top:16px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-size:12px;display:none;">
                        <div style="font-weight:600;margin-bottom:8px;">Recurring Task Summary</div>
                        <div id="recurringSummaryContent"></div>
                    </div>
                </div>
                
                <!-- Attachments Section -->
                <div id="attachmentsSection" style="margin-top:20px;padding:16px;background:var(--background);border:1px solid var(--border);border-radius:8px;display:none;">
                    <div style="font-weight:600;margin-bottom:12px;">Attachments</div>
                    <div id="attachmentList" style="margin-bottom:12px;"></div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="file" id="attachmentFile" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx" style="display:none;" onchange="handleFileUpload()">
                        <button class="btn sm" onclick="document.getElementById('attachmentFile').click()">
                            <i class="bi bi-upload"></i> Upload Files
                        </button>
                        <span style="font-size:12px;color:var(--muted);">Max 100MB per file</span>
                    </div>
                </div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeTaskModal()">Cancel</button>
            <button class="btn primary" onclick="saveTask()">Save Task</button>
        </div>
    </div>
</div>

<!-- Task Detail Modal -->
<div id="taskDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:800px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="taskDetailTitle">Task Details</h3>
            <button onclick="closeTaskDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="taskDetailBody" style="font-size:14px;color:var(--ink);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeTaskDetailModal()">Close</button>
            <button class="btn sm danger" id="taskDeleteBtn" onclick="deleteTask()" style="display:none;">Delete</button>
            <button class="btn sm primary" id="taskEditBtn" onclick="closeTaskDetailModal(); editTask(currentDetailTaskId)" style="display:none;">Edit</button>
        </div>
    </div>
</div>

<!-- Assignee Update Modal -->
<div id="assigneeUpdateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:500px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Update Task Status</h3>
            <button onclick="closeAssigneeUpdateModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="assigneeUpdateTaskId">
        <div style="margin-bottom:16px;">
            <label style="display:block;margin-bottom:8px;font-weight:500;">Status</label>
            <select id="assigneeUpdateStatus" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;" disabled>
                <option value="Pending">Pending</option>
                <option value="In Progress">In Progress</option>
                <option value="Completed">Completed</option>
                <option value="On Hold">On Hold</option>
            </select>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Status is automatically determined by progress</div>
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;margin-bottom:8px;font-weight:500;">Progress (%)</label>
            <div style="display:flex;align-items:center;gap:12px;">
                <input type="range" id="assigneeUpdateProgress" min="0" max="100" value="0" style="flex:1;" 
                       oninput="updateProgressStatus(this.value)">
                <span id="assigneeProgressValue" style="font-size:13px;font-weight:600;min-width:45px;">0%</span>
            </div>
        </div>
        
        <!-- Attachments Section -->
        <div style="margin-bottom:16px;padding:16px;background:var(--background);border:1px solid var(--border);border-radius:8px;">
            <div style="font-weight:600;margin-bottom:12px;">Attachments</div>
            <div id="assigneeAttachmentList" style="margin-bottom:12px;"></div>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="file" id="assigneeAttachmentFile" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx" style="display:none;" onchange="handleAssigneeFileUpload()">
                <button class="btn sm" onclick="document.getElementById('assigneeAttachmentFile').click()">
                    <i class="bi bi-upload"></i> Upload Files
                </button>
                <span style="font-size:12px;color:var(--muted);">Max 100MB per file</span>
            </div>
        </div>
        
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeAssigneeUpdateModal()">Cancel</button>
            <button class="btn primary" onclick="saveAssigneeUpdate()">Update</button>
        </div>
    </div>
</div>

<style>
/* Task list: horizontal scroll on small screens.
   The scroll container + sticky header come from the app-wide .data-table
   rules in the shell; only the wide floor width is kept here. */
.data-table table {
    min-width: 1150px;
}

.day-btn {
    padding:6px 12px;
    border:1px solid var(--border);
    background:var(--surface);
    border-radius:4px;
    cursor:pointer;
    font-size:12px;
    transition:all 0.2s;
}
.day-btn:hover {
    background:var(--background);
}
.day-btn.selected {
    background:var(--accent);
    color:white;
    border-color:var(--accent);
}
</style>

<script>
let currentDetailTaskId = null;
let selectedDays = [];
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    url.searchParams.delete('status');
    url.searchParams.delete('priority');
    url.searchParams.delete('department');
    url.searchParams.delete('search');
    // My Tasks tab has no assignee filter - drop any selected user so it
    // is not applied invisibly to the current user's own task list
    if (tab === 'my') {
        url.searchParams.delete('assignee');
    }
    window.location = url.toString();
}

function filterTasks(status) {
    const url = new URL(window.location);
    if (status && status !== 'all') {
        url.searchParams.set('status', status);
    } else {
        url.searchParams.set('status', 'all');
    }
    window.location = url.toString();
}

function applyFilter(type, value) {
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    // For recurring filter, also clear other filters when switching types
    if (type === 'recurring' && value) {
        url.searchParams.delete('status');
    }
    HrmsSearch.navigate(url.toString());
}

function clearFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('status');
    url.searchParams.delete('priority');
    url.searchParams.delete('department');
    url.searchParams.delete('search');
    url.searchParams.delete('recurring');
    url.searchParams.delete('assignee');
    window.location = url.toString();
}

let filterTimeout;
function filterTasksDebounced(input) {
    // Live-value debounce: use the input that actually fired (several tabs
    // reuse the same id="taskSearch"), never a stale keyup value.
    var el = input || document.getElementById('taskSearch');
    if (!el) return;
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(function () {
        applyFilter('search', el.value);
    }, 450);
}

function openTaskModal(task) {
    const isEdit = !!task && task.id;
    document.getElementById('taskModalTitle').textContent = isEdit ? 'Edit Task' : 'Add Task';
    document.getElementById('taskId').value = task ? task.id : '';
    document.getElementById('taskTitle').value = task ? (task.title || '') : '';
    document.getElementById('taskDescription').value = task ? (task.description || '') : '';
    document.getElementById('taskPriority').value = task && task.priority_name ? task.priority_name : 'Medium';
    const progressValue = task && task.progress != null ? task.progress : 0;
    document.getElementById('taskProgress').value = progressValue;
    document.getElementById('progressValue').textContent = progressValue + '%';
    
    // Sync status with progress when editing
    const progressBasedStatus = progressValue === 0 ? 'Pending' : (progressValue < 100 ? 'In Progress' : 'Completed');
    document.getElementById('taskStatus').value = task && task.status_name ? task.status_name : progressBasedStatus;
    const deadline = task && task.deadline ? String(task.deadline).replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('taskDeadline').value = deadline;
    
    // Handle recurring task data
    const isRecurring = task && task.is_recurring;
    document.getElementById('isRecurring').checked = isRecurring;
    
    // Handle attachments section (available in both Add and Edit modes)
    document.getElementById('attachmentsSection').style.display = 'block';
    if (isEdit) {
        pendingAttachments = [];
        loadAttachments(task.id);
    } else {
        // Add mode: reset any staged attachments from a previous session
        pendingAttachments = [];
        displayPendingAttachments();
    }
    
    if (isRecurring) {
        document.getElementById('recurrencePattern').value = task.recurrence_pattern || 'once';
        document.getElementById('recurrenceStartDate').value = task.recurrence_start_date || '';
        document.getElementById('recurrenceEndDate').value = task.recurrence_end_date || '';
        document.getElementById('noEndDate').checked = !task.recurrence_end_date;
        document.getElementById('recurrenceTime').value = task.recurrence_time || '';
        document.getElementById('reminderMinutesBefore').value = task.reminder_minutes_before || '';
        document.getElementById('notificationsEnabled').checked = task.notifications_enabled || false;
        
        // Handle selected days
        selectedDays = task.recurrence_days || [];
        document.querySelectorAll('.day-btn').forEach(btn => {
            const day = parseInt(btn.getAttribute('data-day'));
            if (selectedDays.includes(day)) {
                btn.classList.add('selected');
            } else {
                btn.classList.remove('selected');
            }
        });
        
        toggleRecurringConfig();
        toggleDaySelection();
        toggleEndDate();
        updateRecurringSummary();
    } else {
        // Reset recurring fields
        document.getElementById('recurringConfig').style.display = 'none';
        selectedDays = [];
        document.querySelectorAll('.day-btn').forEach(btn => btn.classList.remove('selected'));
    }
    
    // Reset assignee selection
    document.getElementById('setForYourself').checked = false;
    document.querySelectorAll('.assignee-checkbox').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.disabled = false;
    });
    
    // Enable the dropdown
    document.getElementById('assigneeDropdown').style.opacity = '1';
    document.getElementById('assigneeDropdown').style.pointerEvents = 'auto';
    
    // Handle assignees for editing
    if (task && task.assignees && task.assignees.length > 0) {
        // Check if task is assigned only to current user
        const isAssignedToSelf = task.assignees.length === 1 && task.assignees[0] === currentUserId;
        
        if (isAssignedToSelf) {
            // Set "Set for Yourself" checkbox
            document.getElementById('setForYourself').checked = true;
            toggleSetForYourself();
        } else {
            // Select the appropriate assignees
            const selectedIds = task.assignees.map(String);
            document.querySelectorAll('.assignee-checkbox').forEach(checkbox => {
                checkbox.checked = selectedIds.includes(String(checkbox.value));
            });
            updateSelectedAssignees();
        }
    } else {
        // No assignees, reset display
        updateSelectedAssignees();
    }
    
    // Apply permission-based UI controls
    applyPermissionControls(task);
    
    document.getElementById('taskModal').style.display = 'flex';
}

function applyPermissionControls(task) {
    const isEdit = !!task && task.id;
    const editableFields = task && task.editable_fields ? task.editable_fields : [];
    const userIsAssigner = task && task.is_assigner;
    
    // Fields that require assigner permission
    const assignerOnlyFields = ['taskTitle', 'taskDescription', 'taskPriority', 'taskDeadline', 'assigneeDropdown', 'isRecurring'];
    
    // Fields that both assigners and assignees can edit
    const commonFields = ['taskStatus'];
    
    // Progress bar can ONLY be edited by assignees
    const assigneeOnlyFields = ['taskProgress'];
    
    if (isEdit) {
        // Check if user is assignee
        const userIsAssignee = task && task.is_assignee;
        
        // Handle assigner-only fields
        assignerOnlyFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                if (userIsAssigner || editableFields.includes(fieldId)) {
                    element.disabled = false;
                    element.style.opacity = '1';
                    element.style.pointerEvents = 'auto';
                } else {
                    element.disabled = true;
                    element.style.opacity = '0.6';
                    element.style.pointerEvents = 'none';
                }
            }
        });
        
        // Handle common fields (both assigners and assignees can edit)
        commonFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                if (userIsAssigner || userIsAssignee || editableFields.includes(fieldId)) {
                    element.disabled = false;
                    element.style.opacity = '1';
                    element.style.pointerEvents = 'auto';
                } else {
                    element.disabled = true;
                    element.style.opacity = '0.6';
                    element.style.pointerEvents = 'none';
                }
            }
        });
        
        // Handle assignee-only fields (progress bar) - only assignees can edit
        assigneeOnlyFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                // Enable ONLY for assignees
                if (userIsAssignee || editableFields.includes(fieldId)) {
                    element.disabled = false;
                    element.style.opacity = '1';
                    element.style.pointerEvents = 'auto';
                } else {
                    // Always disable for assigners and others
                    element.disabled = true;
                    element.style.opacity = '0.6';
                    element.style.pointerEvents = 'none';
                }
            }
        });
        
        // Disable recurring settings for assignees (they can view but not edit)
        if (!userIsAssigner) {
            // Make recurring settings visible but disabled for assignees
            document.getElementById('isRecurring').disabled = true;
            document.getElementById('recurrencePattern').disabled = true;
            document.getElementById('recurrenceStartDate').disabled = true;
            document.getElementById('recurrenceEndDate').disabled = true;
            document.getElementById('noEndDate').disabled = true;
            document.getElementById('recurrenceTime').disabled = true;
            document.getElementById('reminderMinutesBefore').disabled = true;
            document.getElementById('notificationsEnabled').disabled = true;
            
            document.querySelectorAll('.day-btn').forEach(btn => {
                btn.style.pointerEvents = 'none';
                btn.style.opacity = '0.6';
            });
        }
    } else {
        // New task - enable all fields
        assignerOnlyFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                element.disabled = false;
                element.style.opacity = '1';
                element.style.pointerEvents = 'auto';
            }
        });
        
        document.getElementById('isRecurring').disabled = false;
        document.getElementById('recurrencePattern').disabled = false;
        document.getElementById('recurrenceStartDate').disabled = false;
        document.getElementById('recurrenceEndDate').disabled = false;
        document.getElementById('noEndDate').disabled = false;
        document.getElementById('recurrenceTime').disabled = false;
        document.getElementById('reminderMinutesBefore').disabled = false;
        document.getElementById('notificationsEnabled').disabled = false;
        
        document.querySelectorAll('.day-btn').forEach(btn => {
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
        });
    }
}

function addTask() {
    openTaskModal(null);
}

function editTask(id) {
    fetch(`api/tasks.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openTaskModal(data.task);
            } else {
                alert('Error loading task: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading task');
        });
}

function closeTaskModal() {
    document.getElementById('taskModal').style.display = 'none';
    pendingAttachments = [];
}

function saveTask() {
    const id = document.getElementById('taskId').value;
    const title = document.getElementById('taskTitle').value.trim();
    if (!title) {
        alert('Task title is required');
        return;
    }
    const deadline = document.getElementById('taskDeadline').value.replace('T', ' ') || null;
    
    // Handle assignees based on "Set for Yourself" checkbox
    let assignees = [];
    const setForYourself = document.getElementById('setForYourself').checked;
    
    if (setForYourself) {
        // Assign to current user
        assignees = [currentUserId];
    } else {
        // Get selected assignees from checkboxes
        assignees = Array.from(document.querySelectorAll('.assignee-checkbox:checked')).map(cb => parseInt(cb.value));
    }

    const payload = {
        title: title,
        description: document.getElementById('taskDescription').value,
        priority: document.getElementById('taskPriority').value,
        status: document.getElementById('taskStatus').value,
        deadline: deadline,
        assignees: assignees
    };
    
    // Only include progress if user is allowed to update it (assignees)
    const progressElement = document.getElementById('taskProgress');
    if (!progressElement.disabled) {
        payload.progress = parseInt(progressElement.value) || 0;
    }

    // Add recurring task data if enabled
    const isRecurring = document.getElementById('isRecurring').checked;
    if (isRecurring) {
        payload.is_recurring = true;
        payload.recurrence_pattern = document.getElementById('recurrencePattern').value;
        payload.recurrence_days = selectedDays;
        payload.recurrence_start_date = document.getElementById('recurrenceStartDate').value;
        payload.recurrence_end_date = document.getElementById('noEndDate').checked ? null : document.getElementById('recurrenceEndDate').value;
        payload.recurrence_time = document.getElementById('recurrenceTime').value;
        payload.reminder_minutes_before = document.getElementById('reminderMinutesBefore').value ? parseInt(document.getElementById('reminderMinutesBefore').value) : null;
        payload.notifications_enabled = document.getElementById('notificationsEnabled').checked;
    }

    const url = id ? `api/tasks.php?id=${id}` : 'api/tasks.php';
    const method = id ? 'PUT' : 'POST';

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const finishSave = () => {
                closeTaskModal();
                location.reload();
            };

            // For newly created tasks, upload any attachments staged before save
            if (!id && pendingAttachments.length > 0 && data.task_id) {
                const expected = pendingAttachments.length;
                uploadPendingAttachments(data.task_id).then((uploadedCount) => {
                    pendingAttachments = [];
                    if (uploadedCount < expected) {
                        alert(`Task created. Uploaded ${uploadedCount} of ${expected} attachment(s).`);
                    }
                    finishSave();
                });
            } else {
                pendingAttachments = [];
                finishSave();
            }
        } else {
            alert('Error saving task: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving task');
    });
}

function viewTask(id) {
    // Check if the modal elements exist
    const detailModal = document.getElementById('taskDetailModal');
    const detailTitle = document.getElementById('taskDetailTitle');
    const detailBody = document.getElementById('taskDetailBody');
    
    if (!detailModal || !detailTitle || !detailBody) {
        console.error('Modal elements not found');
        alert('Error: Modal elements not found');
        return;
    }
    
    fetch(`api/tasks.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error loading task: ' + (data.message || 'Unknown error'));
                console.error('API Error:', data);
                return;
            }
            const t = data.task;
            currentDetailTaskId = id;

            // Define user role before permission checks
            const userIsAssigner = t.is_assigner;

            // Handle missing data gracefully
            const todayStart = new Date(); todayStart.setHours(0, 0, 0, 0);
            const isOverdue = t.deadline && new Date(t.deadline) < todayStart && !['Completed', 'Cancelled'].includes(t.status_name);
            const statusClass = isOverdue ? 'danger' : (t.status_name === 'Completed' ? 'success' : (t.status_name === 'Cancelled' || t.status_name === 'On Hold' ? 'gray' : 'pending'));
            const statusLabel = isOverdue ? 'Overdue' : (t.status_name || '—');
            const priorityClass = t.priority_name === 'High' ? 'danger' : (t.priority_name === 'Medium' ? 'warning' : (t.priority_name === 'Low' ? 'success' : 'gray'));
            const assignees = (t.assignee_names && t.assignee_names.length) ? t.assignee_names.join(', ') : '—';
            const assignedBy = (t.assigned_by_first || t.assigned_by_last) ? `${t.assigned_by_first || ''} ${t.assigned_by_last || ''}` : '—';
            const dueDate = t.deadline ? new Date(t.deadline).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';

            detailTitle.textContent = '#T-' + String(id).padStart(4, '0') + ' · ' + t.title;

            let detailHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;gap:10px;flex-wrap:wrap;">
                        <span><b>Status:</b> <span class="badge ${statusClass}">${statusLabel}</span></span>
                        <span><b>Priority:</b> <span class="badge ${priorityClass}">${t.priority_name || '—'}</span></span>
                        <span><b>Progress:</b> ${t.progress || 0}%</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Assigned By:</b> ${assignedBy}</span>
                        <span><b>Due:</b> ${dueDate}</span>
                    </div>
                </div>
                ${t.description ? '<div style="color:var(--ink);margin-bottom:16px;padding:12px;background:var(--background);border-radius:8px;line-height:1.6;white-space:pre-wrap;">' + t.description + '</div>' : '<p style="color:var(--faint);">No description provided.</p>'}
                <div style="font-size:12px;color:var(--muted);"><b>Assignees:</b> ${assignees}</div>
            `;
            
            // Add recurring task information if applicable
            if (t.is_recurring) {
                const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                let patternText = t.recurrence_pattern === 'daily' ? 'Daily' : 'Custom Days';
                if (t.recurrence_pattern === 'custom_days' && t.recurrence_days) {
                    const selectedDayNames = t.recurrence_days.map(d => dayNames[d]).sort();
                    patternText = selectedDayNames.join(', ');
                }
                
                const formattedTime = t.recurrence_time ? new Date('2000-01-01T' + t.recurrence_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) : '—';
                const formattedStart = t.recurrence_start_date ? new Date(t.recurrence_start_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '—';
                const formattedEnd = t.recurrence_end_date ? new Date(t.recurrence_end_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'No end date';
                
                detailHTML += `
                    <div style="background:var(--background);border:1px solid var(--accent);border-radius:8px;padding:16px;margin-top:16px;">
                        <div style="font-weight:600;color:var(--accent);margin-bottom:8px;">🔁 Recurring Task</div>
                        <div style="font-size:13px;color:var(--muted);line-height:1.8;">
                            <div><b>Pattern:</b> ${patternText}</div>
                            <div><b>Time:</b> ${formattedTime}</div>
                            <div><b>From:</b> ${formattedStart}</div>
                            <div><b>Until:</b> ${formattedEnd}</div>
                            <div><b>Notifications:</b> ${t.notifications_enabled ? 'Enabled' : 'Disabled'}</div>
                            ${t.reminder_minutes_before ? `<div><b>Reminder:</b> ${t.reminder_minutes_before} minutes before</div>` : ''}
                            ${t.next_occurrence ? `<div><b>Next Occurrence:</b> ${new Date(t.next_occurrence.occurrence_date + ' ' + t.next_occurrence.occurrence_time).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}</div>` : ''}
                        </div>
                        ${userIsAssigner ? `
                        <div style="margin-top:12px;">
                            <button class="btn sm" onclick="toggleRecurringTask(${id}, ${t.is_recurring_active})">
                                ${t.is_recurring_active ? 'Disable Recurring' : 'Enable Recurring'}
                            </button>
                        </div>
                        ` : ''}
                    </div>
                `;
            }
            
            detailBody.innerHTML = detailHTML;

            // Load and display attachments
            loadDetailAttachments(id);

            // Show edit/delete buttons based on permissions and user role
            // userIsAssigner is already defined above before the recurring task check

            // Only assigners can edit/delete tasks, not assignees
            const canEdit = userIsAssigner;
            const canDelete = userIsAssigner;

            const editBtn = document.getElementById('taskEditBtn');
            const deleteBtn = document.getElementById('taskDeleteBtn');

            if (editBtn) {
                if (canEdit) {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
            }

            if (deleteBtn) {
                if (canDelete) {
                    deleteBtn.style.display = 'inline-block';
                } else {
                    deleteBtn.style.display = 'none';
                }
            }
            
            detailModal.style.display = 'flex';
        })
        .catch(err => {
            console.error('Error in viewTask:', err);
            alert('Error loading task: ' + err.message);
        });
}

// Attachment Functions
function loadAttachments(taskId) {
    fetch(`api/task_attachments.php?task_id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayAttachments(data.attachments);
            } else {
                console.error('Error loading attachments:', data.message);
            }
        })
        .catch(err => {
            console.error('Error loading attachments:', err);
        });
}

function loadDetailAttachments(taskId) {
    fetch(`api/task_attachments.php?task_id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayDetailAttachments(data.attachments);
            } else {
                console.error('Error loading attachments:', data.message);
            }
        })
        .catch(err => {
            console.error('Error loading attachments:', err);
        });
}

function displayDetailAttachments(attachments) {
    const detailBody = document.getElementById('taskDetailBody');
    if (!detailBody) return;
    
    if (!attachments || attachments.length === 0) {
        detailBody.innerHTML += '<div style="margin-top:16px;padding:12px;background:var(--background);border-radius:8px;"><div style="font-weight:600;margin-bottom:8px;">Attachments</div><div style="color:var(--faint);font-size:13px;">No attachments</div></div>';
        return;
    }
    
    let html = '<div style="margin-top:16px;padding:16px;background:var(--background);border-radius:8px;"><div style="font-weight:600;margin-bottom:12px;">Attachments</div>';
    html += '<div style="display:flex;flex-direction:column;gap:8px;">';
    attachments.forEach(att => {
        const sizeKB = Math.round(att.file_size / 1024);
        html += `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:4px;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;">
                    <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:500;">${att.file_name}</div>
                        <div style="font-size:11px;color:var(--muted);">${sizeKB} KB • Uploaded by ${att.uploader_name}</div>
                    </div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="btn sm" onclick="downloadAttachment(${att.id})" title="Download">
                        <i class="bi bi-download"></i>
                    </button>
                    <button class="btn sm" style="color:var(--danger);" onclick="deleteDetailAttachment(${att.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    html += '</div></div>';
    detailBody.innerHTML += html;
}

function deleteDetailAttachment(attachmentId) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    
    fetch(`api/task_attachments.php?id=${attachmentId}`, {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (currentDetailTaskId) {
                loadDetailAttachments(currentDetailTaskId);
            }
        } else {
            alert('Error deleting attachment: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Error deleting attachment:', err);
        alert('Error deleting attachment');
    });
}

// Files staged for upload when creating a new task (uploaded right after save)
let pendingAttachments = [];

function displayPendingAttachments() {
    const container = document.getElementById('attachmentList');
    if (!pendingAttachments || pendingAttachments.length === 0) {
        container.innerHTML = '<div style="color:var(--faint);font-size:13px;">No attachments</div>';
        return;
    }

    let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    pendingAttachments.forEach((file, index) => {
        const sizeKB = Math.round(file.size / 1024);
        html += `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:4px;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;">
                    <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:500;">${file.name}</div>
                        <div style="font-size:11px;color:var(--muted);">${sizeKB} KB • Will be uploaded after save</div>
                    </div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="btn sm danger" onclick="removePendingAttachment(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function removePendingAttachment(index) {
    pendingAttachments.splice(index, 1);
    displayPendingAttachments();
}

// Upload staged files to a task — same endpoint/logic as edit-mode uploads
function uploadPendingAttachments(taskId) {
    if (!pendingAttachments || pendingAttachments.length === 0) {
        return Promise.resolve(0);
    }

    const files = pendingAttachments.slice();
    let uploadedCount = 0;
    let errorCount = 0;

    return new Promise((resolve) => {
        files.forEach((file) => {
            if (file.size > 100 * 1024 * 1024) {
                errorCount++;
                if (uploadedCount + errorCount === files.length) {
                    resolve(uploadedCount);
                }
                return;
            }

            const formData = new FormData();
            formData.append('file', file);

            fetch(`api/task_attachments.php?task_id=${taskId}`, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    uploadedCount++;
                } else {
                    errorCount++;
                    console.error('Error uploading file ' + file.name + ': ' + (data.message || 'Unknown error'));
                }
                if (uploadedCount + errorCount === files.length) {
                    resolve(uploadedCount);
                }
            })
            .catch(err => {
                errorCount++;
                console.error('Error uploading file ' + file.name + ': ' + err.message);
                if (uploadedCount + errorCount === files.length) {
                    resolve(uploadedCount);
                }
            });
        });
    });
}

function displayAttachments(attachments) {
    const container = document.getElementById('attachmentList');
    if (!attachments || attachments.length === 0) {
        container.innerHTML = '<div style="color:var(--faint);font-size:13px;">No attachments</div>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    attachments.forEach(att => {
        const sizeKB = Math.round(att.file_size / 1024);
        const isImage = ['image/jpeg', 'image/png', 'image/webp'].includes(att.file_type);
        
        html += `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:4px;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;">
                    <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:500;">${att.file_name}</div>
                        <div style="font-size:11px;color:var(--muted);">${sizeKB} KB • Uploaded by ${att.uploader_name}</div>
                    </div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="btn sm" onclick="downloadAttachment(${att.id})" title="Download">
                        <i class="bi bi-download"></i>
                    </button>
                    <button class="btn sm danger" onclick="deleteAttachment(${att.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function handleFileUpload() {
    const fileInput = document.getElementById('attachmentFile');
    const taskId = document.getElementById('taskId').value;
    
    if (!taskId) {
        // Add mode: stage the files, they will be uploaded after the task is saved
        const staged = fileInput.files;
        Array.from(staged).forEach((file) => {
            if (file.size > 100 * 1024 * 1024) {
                alert(`File ${file.name} exceeds 100MB limit`);
                return;
            }
            pendingAttachments.push(file);
        });
        displayPendingAttachments();
        fileInput.value = '';
        return;
    }
    
    const files = fileInput.files;
    if (files.length === 0) return;
    
    // Upload files one at a time
    let uploadedCount = 0;
    let errorCount = 0;
    
    Array.from(files).forEach((file, index) => {
        if (file.size > 100 * 1024 * 1024) {
            alert(`File ${file.name} exceeds 100MB limit`);
            errorCount++;
            return;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        
        fetch(`api/task_attachments.php?task_id=${taskId}`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                uploadedCount++;
                if (uploadedCount + errorCount === files.length) {
                    loadAttachments(taskId);
                    // Also reload detail attachments if detail modal is open
                    const detailModal = document.getElementById('taskDetailModal');
                    if (detailModal && detailModal.style.display !== 'none') {
                        loadDetailAttachments(taskId);
                    }
                    fileInput.value = '';
                    if (errorCount > 0) {
                        alert(`Uploaded ${uploadedCount} file(s), ${errorCount} skipped`);
                    }
                }
            } else {
                errorCount++;
                alert('Error uploading file ' + file.name + ': ' + (data.message || 'Unknown error'));
                if (uploadedCount + errorCount === files.length) {
                    if (uploadedCount > 0) {
                        loadAttachments(taskId);
                        // Also reload detail attachments if detail modal is open
                        const detailModal = document.getElementById('taskDetailModal');
                        if (detailModal && detailModal.style.display !== 'none') {
                            loadDetailAttachments(taskId);
                        }
                    }
                    fileInput.value = '';
                }
            }
        })
        .catch(err => {
            errorCount++;
            alert('Error uploading file ' + file.name + ': ' + err.message);
            if (uploadedCount + errorCount === files.length) {
                if (uploadedCount > 0) {
                    loadAttachments(taskId);
                    // Also reload detail attachments if detail modal is open
                    const detailModal = document.getElementById('taskDetailModal');
                    if (detailModal && detailModal.style.display !== 'none') {
                        loadDetailAttachments(taskId);
                    }
                }
                fileInput.value = '';
            }
        });
    });
}

function downloadAttachment(attachmentId) {
    window.open(`api/task_attachments.php?id=${attachmentId}`, '_blank');
}

function deleteAttachment(attachmentId) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    
    fetch(`api/task_attachments.php?id=${attachmentId}`, {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const mainTaskId = document.getElementById('taskId').value;
            if (mainTaskId) {
                loadAttachments(mainTaskId);
            }
        } else {
            alert('Error deleting attachment: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Error deleting attachment:', err);
        alert('Error deleting attachment');
    });
}

function closeTaskDetailModal() {
    document.getElementById('taskDetailModal').style.display = 'none';
}

function deleteTask() {
    if (!currentDetailTaskId) return;
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this task?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch(`api/tasks.php?id=${currentDetailTaskId}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeTaskDetailModal();
                location.reload();
            } else {
                ModalSystem.error('Error deleting task: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error deleting task');
        });
}

// Assignee Update Modal Functions
function openAssigneeUpdateModal(taskId) {
    fetch(`api/tasks.php?id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error loading task: ' + (data.message || 'Unknown error'));
                return;
            }
            const task = data.task;
            document.getElementById('assigneeUpdateTaskId').value = taskId;
            const progressValue = task.progress || 0;
            document.getElementById('assigneeUpdateProgress').value = progressValue;
            document.getElementById('assigneeProgressValue').textContent = progressValue + '%';
            
            // Auto-set status based on progress (the select is disabled and read-only)
            const progressBasedStatus = progressValue === 0 ? 'Pending' : (progressValue < 100 ? 'In Progress' : 'Completed');
            document.getElementById('assigneeUpdateStatus').value = progressBasedStatus;
            
            // Load attachments
            loadAssigneeAttachments(taskId);
            
            document.getElementById('assigneeUpdateModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading task');
        });
}

function closeAssigneeUpdateModal() {
    document.getElementById('assigneeUpdateModal').style.display = 'none';
}

function loadAssigneeAttachments(taskId) {
    fetch(`api/task_attachments.php?task_id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayAssigneeAttachments(data.attachments);
            } else {
                console.error('Error loading attachments:', data.message);
            }
        })
        .catch(err => {
            console.error('Error loading attachments:', err);
        });
}

function displayAssigneeAttachments(attachments) {
    const container = document.getElementById('assigneeAttachmentList');
    if (!attachments || attachments.length === 0) {
        container.innerHTML = '<div style="color:var(--faint);font-size:13px;">No attachments</div>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    attachments.forEach(att => {
        const sizeKB = Math.round(att.file_size / 1024);
        
        html += `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:4px;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;">
                    <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:500;">${att.file_name}</div>
                        <div style="font-size:11px;color:var(--muted);">${sizeKB} KB • Uploaded by ${att.uploader_name}</div>
                    </div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="btn sm" onclick="downloadAttachment(${att.id})" title="Download">
                        <i class="bi bi-download"></i>
                    </button>
                    <button class="btn sm" style="color:var(--danger);" onclick="deleteAssigneeAttachment(${att.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function deleteAssigneeAttachment(attachmentId) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    
    fetch(`api/task_attachments.php?id=${attachmentId}`, {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const assigneeTaskId = document.getElementById('assigneeUpdateTaskId').value;
            if (assigneeTaskId) {
                loadAssigneeAttachments(assigneeTaskId);
            }
        } else {
            alert('Error deleting attachment: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Error deleting attachment:', err);
        alert('Error deleting attachment');
    });
}

function handleAssigneeFileUpload() {
    const fileInput = document.getElementById('assigneeAttachmentFile');
    const taskId = document.getElementById('assigneeUpdateTaskId').value;
    
    if (!taskId) {
        alert('No task ID found');
        fileInput.value = '';
        return;
    }
    
    const files = fileInput.files;
    if (files.length === 0) return;
    
    // Upload files one at a time
    let uploadedCount = 0;
    let errorCount = 0;
    
    Array.from(files).forEach((file, index) => {
        if (file.size > 100 * 1024 * 1024) {
            alert(`File ${file.name} exceeds 100MB limit`);
            errorCount++;
            return;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        
        fetch(`api/task_attachments.php?task_id=${taskId}`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                uploadedCount++;
                if (uploadedCount + errorCount === files.length) {
                    loadAssigneeAttachments(taskId);
                    // Also reload detail attachments if detail modal is open
                    const detailModal = document.getElementById('taskDetailModal');
                    if (detailModal && detailModal.style.display !== 'none') {
                        loadDetailAttachments(taskId);
                    }
                    fileInput.value = '';
                    if (errorCount > 0) {
                        alert(`Uploaded ${uploadedCount} file(s), ${errorCount} skipped`);
                    }
                }
            } else {
                errorCount++;
                alert('Error uploading file ' + file.name + ': ' + (data.message || 'Unknown error'));
                if (uploadedCount + errorCount === files.length) {
                    if (uploadedCount > 0) {
                        loadAssigneeAttachments(taskId);
                        // Also reload detail attachments if detail modal is open
                        const detailModal = document.getElementById('taskDetailModal');
                        if (detailModal && detailModal.style.display !== 'none') {
                            loadDetailAttachments(taskId);
                        }
                    }
                    fileInput.value = '';
                }
            }
        })
        .catch(err => {
            errorCount++;
            alert('Error uploading file ' + file.name + ': ' + err.message);
            if (uploadedCount + errorCount === files.length) {
                if (uploadedCount > 0) {
                    loadAssigneeAttachments(taskId);
                    // Also reload detail attachments if detail modal is open
                    const detailModal = document.getElementById('taskDetailModal');
                    if (detailModal && detailModal.style.display !== 'none') {
                        loadDetailAttachments(taskId);
                    }
                }
                fileInput.value = '';
            }
        });
    });
}

function saveAssigneeUpdate() {
    const taskId = document.getElementById('assigneeUpdateTaskId').value;
    const progress = document.getElementById('assigneeUpdateProgress').value;
    
    // Status is auto-determined by progress, don't send it explicitly
    fetch(`api/tasks.php?id=${taskId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            progress: progress
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeAssigneeUpdateModal();
            location.reload();
        } else {
            alert('Error updating task: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error updating task');
    });
}

function startTask(id) {
    if (!ModalSystem.confirm({ 
        message: 'Start this task? Status will change to In Progress.',
        confirmText: 'Start' 
    })) return;
    fetch(`api/tasks.php?id=${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'start' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not start task'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error starting task');
    });
}

function updateTask(id) {
    editTask(id);
}

function updateMainProgressStatus(progressValue) {
    // Update the display value
    document.getElementById('progressValue').textContent = progressValue + '%';
    
    // Auto-update status based on progress
    const statusSelect = document.getElementById('taskStatus');
    const progress = parseInt(progressValue);
    
    if (progress === 0) {
        statusSelect.value = 'Pending';
    } else if (progress > 0 && progress < 100) {
        statusSelect.value = 'In Progress';
    } else if (progress === 100) {
        statusSelect.value = 'Completed';
    }
}

function updateProgressStatus(progressValue) {
    // Update the display value
    document.getElementById('assigneeProgressValue').textContent = progressValue + '%';
    
    // Auto-update status based on progress
    const statusSelect = document.getElementById('assigneeUpdateStatus');
    const progress = parseInt(progressValue);
    
    if (progress === 0) {
        statusSelect.value = 'Pending';
    } else if (progress > 0 && progress < 100) {
        statusSelect.value = 'In Progress';
    } else if (progress === 100) {
        statusSelect.value = 'Completed';
    }
}

function updateProgress(taskId, progress) {
    const payload = {
        progress: parseInt(progress)
    };
    
    fetch(`api/tasks.php?id=${taskId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            ModalSystem.error('Error updating progress: ' + (data.message || 'Unknown error'));
            // Revert the slider on error
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error updating progress');
        location.reload();
    });
}

function deleteTaskInline(taskId) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this task? This action cannot be undone.',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    
    fetch(`api/tasks.php?id=${taskId}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error deleting task: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error deleting task');
        });
}

// Assignee dropdown functionality
function toggleAssigneeDropdown() {
    const dropdown = document.getElementById('assigneeOptions');
    const isDisabled = document.getElementById('assigneeDropdown').style.opacity === '0.5';
    if (!isDisabled) {
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    }
}

function filterAssignees() {
    const searchTerm = document.getElementById('assigneeSearch').value.toLowerCase();
    document.querySelectorAll('.assignee-option').forEach(option => {
        const searchValue = option.getAttribute('data-assignee-search');
        option.style.display = searchValue.includes(searchTerm) ? 'flex' : 'none';
    });
}

function updateSelectedAssignees() {
    const selectedAssignees = [];
    document.querySelectorAll('.assignee-checkbox:checked').forEach(checkbox => {
        const assigneeName = checkbox.closest('.assignee-option').getAttribute('data-assignee-name');
        selectedAssignees.push(assigneeName);
    });

    const displaySpan = document.getElementById('selectedAssignees');
    if (selectedAssignees.length === 0) {
        displaySpan.textContent = 'Select assignees...';
        displaySpan.style.color = 'var(--muted)';
    } else {
        displaySpan.textContent = selectedAssignees.join(', ');
        displaySpan.style.color = 'var(--ink)';
    }
}

function toggleSetForYourself() {
    const setForYourself = document.getElementById('setForYourself').checked;
    const assigneeDropdown = document.getElementById('assigneeDropdown');
    const assigneeOptions = document.getElementById('assigneeOptions');
    const assigneeCheckboxes = document.querySelectorAll('.assignee-checkbox');
    
    if (setForYourself) {
        // Disable and grey out the assignee dropdown
        assigneeDropdown.style.opacity = '0.5';
        assigneeDropdown.style.pointerEvents = 'none';
        assigneeOptions.style.display = 'none';
        
        // Uncheck all assignee checkboxes
        assigneeCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
            checkbox.disabled = true;
        });
        
        // Update display
        document.getElementById('selectedAssignees').textContent = 'Assigned to yourself';
        document.getElementById('selectedAssignees').style.color = 'var(--ink)';
    } else {
        // Enable the assignee dropdown
        assigneeDropdown.style.opacity = '1';
        assigneeDropdown.style.pointerEvents = 'auto';
        
        // Enable all assignee checkboxes
        assigneeCheckboxes.forEach(checkbox => {
            checkbox.disabled = false;
        });
        
        // Update display
        updateSelectedAssignees();
    }
}

// Add event listeners for assignee checkboxes
document.querySelectorAll('.assignee-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateSelectedAssignees);
});

// Close assignee dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('assigneeOptions');
    const dropdownTrigger = document.getElementById('assigneeDropdown');
    if (dropdown && dropdownTrigger && 
        !dropdown.contains(event.target) && 
        !dropdownTrigger.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// Recurring Task Functions
function toggleRecurringConfig() {
    const isRecurring = document.getElementById('isRecurring').checked;
    const recurringConfig = document.getElementById('recurringConfig');
    const deadlineField = document.getElementById('taskDeadline');
    const deadlineLabel = deadlineField.parentElement.querySelector('label');
    
    recurringConfig.style.display = isRecurring ? 'block' : 'none';
    
    if (isRecurring) {
        // Hide regular deadline field for recurring tasks
        deadlineField.style.display = 'none';
        if (deadlineLabel) deadlineLabel.style.display = 'none';
        
        // Set default start date to today
        document.getElementById('recurrenceStartDate').value = new Date().toISOString().split('T')[0];
        // Set default time to 9:00 AM
        document.getElementById('recurrenceTime').value = '09:00';
        updateRecurringSummary();
    } else {
        // Show regular deadline field for one-time tasks
        deadlineField.style.display = 'block';
        if (deadlineLabel) deadlineLabel.style.display = 'block';
        
        // Clear recurring fields
        document.getElementById('recurrencePattern').value = 'once';
        selectedDays = [];
        document.querySelectorAll('.day-btn').forEach(btn => btn.classList.remove('selected'));
        document.getElementById('recurrenceStartDate').value = '';
        document.getElementById('recurrenceEndDate').value = '';
        document.getElementById('noEndDate').checked = false;
        document.getElementById('recurrenceTime').value = '';
        document.getElementById('reminderMinutesBefore').value = '';
        document.getElementById('notificationsEnabled').checked = false;
        document.getElementById('recurringSummary').style.display = 'none';
    }
}

function toggleDaySelection() {
    const pattern = document.getElementById('recurrencePattern').value;
    const dayContainer = document.getElementById('daySelectionContainer');
    dayContainer.style.display = pattern === 'custom_days' ? 'block' : 'none';
    updateRecurringSummary();
}

function toggleDay(day) {
    const btn = document.querySelector(`.day-btn[data-day="${day}"]`);
    const index = selectedDays.indexOf(day);
    
    if (index > -1) {
        selectedDays.splice(index, 1);
        btn.classList.remove('selected');
    } else {
        selectedDays.push(day);
        btn.classList.add('selected');
    }
    
    updateRecurringSummary();
}

function toggleEndDate() {
    const noEndDate = document.getElementById('noEndDate').checked;
    const endDateInput = document.getElementById('recurrenceEndDate');
    endDateInput.disabled = noEndDate;
    if (noEndDate) {
        endDateInput.value = '';
    }
    updateRecurringSummary();
}

function updateRecurringSummary() {
    const isRecurring = document.getElementById('isRecurring').checked;
    if (!isRecurring) return;
    
    const pattern = document.getElementById('recurrencePattern').value;
    const startDate = document.getElementById('recurrenceStartDate').value;
    const endDate = document.getElementById('recurrenceEndDate').value;
    const noEndDate = document.getElementById('noEndDate').checked;
    const taskTime = document.getElementById('recurrenceTime').value;
    const reminder = document.getElementById('reminderMinutesBefore').value;
    const notificationsEnabled = document.getElementById('notificationsEnabled').checked;
    
    let summary = '';
    
    // Pattern description
    if (pattern === 'once') {
        summary += 'Repeats: Once<br>';
    } else if (pattern === 'daily') {
        summary += 'Repeats: Daily<br>';
    } else if (pattern === 'custom_days' && selectedDays.length > 0) {
        const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const selectedDayNames = selectedDays.map(d => dayNames[d]).sort();
        summary += 'Repeats: ' + selectedDayNames.join(', ') + '<br>';
    }
    
    // Time
    if (taskTime) {
        const formattedTime = new Date('2000-01-01T' + taskTime).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
        summary += 'Time: ' + formattedTime + '<br>';
    }
    
    // Date range
    if (startDate) {
        const formattedStart = new Date(startDate).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
        summary += 'From: ' + formattedStart;
        
        if (noEndDate) {
            summary += ' (No end date)<br>';
        } else if (endDate) {
            const formattedEnd = new Date(endDate).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
            summary += ' Until: ' + formattedEnd + '<br>';
        } else {
            summary += '<br>';
        }
    }
    
    // Reminder
    if (reminder) {
        summary += 'Reminder: ' + reminder + ' minutes before<br>';
    }
    
    // Notifications
    summary += 'Notifications: ' + (notificationsEnabled ? 'Enabled' : 'Disabled') + '<br>';
    
    // Next occurrence preview
    if (startDate && taskTime) {
        const nextOccurrence = calculateNextOccurrence();
        if (nextOccurrence) {
            summary += '<br><strong>Next Occurrence:</strong> ' + nextOccurrence;
        }
    }
    
    document.getElementById('recurringSummaryContent').innerHTML = summary;
    document.getElementById('recurringSummary').style.display = 'block';
}

function calculateNextOccurrence() {
    const pattern = document.getElementById('recurrencePattern').value;
    const startDate = document.getElementById('recurrenceStartDate').value;
    const taskTime = document.getElementById('recurrenceTime').value;
    
    if (!startDate || !taskTime) return '';
    
    const start = new Date(startDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    // If start date is in the future, that's the next occurrence
    if (start > today) {
        const formattedTime = new Date('2000-01-01T' + taskTime).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
        return start.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) + ' at ' + formattedTime;
    }
    
    // Calculate next occurrence based on pattern
    if (pattern === 'daily') {
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const formattedTime = new Date('2000-01-01T' + taskTime).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
        return tomorrow.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) + ' at ' + formattedTime;
    }
    
    if (pattern === 'custom_days' && selectedDays.length > 0) {
        const dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        let nextDate = new Date(today);
        let found = false;
        
        // Check next 14 days
        for (let i = 1; i <= 14; i++) {
            nextDate.setDate(today.getDate() + i);
            const dayOfWeek = nextDate.getDay(); // 0=Sun, 1=Mon, ..., 6=Sat
            if (selectedDays.includes(dayOfWeek)) {
                const formattedTime = new Date('2000-01-01T' + taskTime).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
                return nextDate.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) + ' at ' + formattedTime;
            }
        }
    }
    
    return '';
}

function toggleRecurringTask(taskId, currentStatus) {
    const action = currentStatus ? 'disable' : 'enable';
    const message = currentStatus ? 
        'Disabling this recurring task will stop future occurrences and notifications. Existing task history will remain unchanged. Continue?' :
        'Enabling this recurring task will start generating future occurrences and notifications. Continue?';
    
    if (!confirm(message)) return;
    
    fetch(`api/tasks.php?id=${taskId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'toggle_recurring',
            is_active: !currentStatus
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not toggle recurring task'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error toggling recurring task');
    });
}
</script>

