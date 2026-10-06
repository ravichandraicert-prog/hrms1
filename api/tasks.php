<?php
/**
 * Tasks API
 * Handles CRUD operations for tasks
 * Core PHP HRMS Application
 * Section 9 - Task management module
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/recurring_task_service.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();

    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

    // ---- Helpers ----
    $statusIdByName = function ($name) use ($db) {
        if (!$name) return null;
        $row = $db->fetchOne("SELECT id FROM task_statuses WHERE name = ?", [$name]);
        return $row ? (int) $row['id'] : null;
    };

    $priorityIdByName = function ($name) use ($db) {
        if (!$name) return null;
        $row = $db->fetchOne("SELECT id FROM task_priorities WHERE name = ?", [$name]);
        return $row ? (int) $row['id'] : null;
    };

    $fetchTask = function ($taskId) use ($db) {
        $task = $db->fetchOne(
            "SELECT t.*, ts.name as status_name, tp.name as priority_name,
             ab.first_name as assigned_by_first, ab.last_name as assigned_by_last
             FROM tasks t
             LEFT JOIN task_statuses ts ON t.task_status_id = ts.id
             LEFT JOIN task_priorities tp ON t.task_priority_id = tp.id
             LEFT JOIN employees ab ON t.assigned_by = ab.id
             WHERE t.id = ?",
            [$taskId]
        );
        
        // Parse recurrence_days JSON if present
        if ($task && !empty($task['recurrence_days'])) {
            $task['recurrence_days'] = json_decode($task['recurrence_days'], true);
        }
        
        return $task;
    };

    $fetchAssignees = function ($taskId) use ($db) {
        return $db->fetchAll(
            "SELECT ta.assignee_id, e.first_name, e.last_name, e.employee_id
             FROM task_assignments ta
             JOIN employees e ON ta.assignee_id = e.id
             WHERE ta.task_id = ?",
            [$taskId]
        );
    };
$syncAssignees = function ($taskId, $assigneeIds, $prevAssigneeIds) use ($db, $user) {
        $assigneeIds = array_values(array_unique(array_filter(array_map('intval', (array) $assigneeIds))));
        $prevAssigneeIds = array_map('intval', (array) $prevAssigneeIds);

        // Insert newly added assignees
        foreach ($assigneeIds as $empId) {
            if (!in_array($empId, $prevAssigneeIds)) {
                $db->insert('task_assignments', ['task_id' => $taskId, 'assignee_id' => $empId, 'created_at' => date('Y-m-d H:i:s')]);
                sendNotification($empId, 'task', 'New Task Assigned',
                    'A new task has been assigned to you.', '?module=tasks&tab=my');

                // Notify hierarchy authorities about the task assignment
                $recipients = getHierarchyNotificationRecipients($user['id'], 'tasks');
                if (!empty($recipients)) {
                    $assignee = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$empId]);
                    $task = $db->fetchOne("SELECT title FROM tasks WHERE id = ?", [$taskId]);
                    foreach ($recipients as $recipientId) {
                        // Don't notify the assignee themselves or the assigner
                        if ($recipientId != $empId && $recipientId != $user['id']) {
                            sendNotification($recipientId, 'task', 'Task Assigned',
                                'Task "' . ($task['title'] ?? '') . '" has been assigned to ' . $assignee['first_name'] . ' ' . $assignee['last_name'], '?module=tasks&tab=all');
                        }
                    }
                }
            }
        }
        // Remove unassigned assignees
        foreach ($prevAssigneeIds as $empId) {
            if (!in_array($empId, $assigneeIds)) {
                $db->delete('task_assignments', 'task_id = ? AND assignee_id = ?', [$taskId, $empId]);
            }
        }
        return $assigneeIds;
    };

    $finalizeCompletion = function ($taskId, $assigneeIds) use ($db) {
        $t = $db->fetchOne("SELECT deadline FROM tasks WHERE id = ?", [$taskId]);
        $deadline = $t ? $t['deadline'] : null;
        $isOnTime = ($deadline === null || strtotime($deadline) >= time()) ? 1 : 0;
        foreach ($assigneeIds as $empId) {
            $db->update('task_assignments',
                ['completed_date' => date('Y-m-d H:i:s'), 'is_on_time' => $isOnTime],
                'task_id = ? AND assignee_id = ?',
                [$taskId, $empId]
            );
        }
    };
switch ($method) {
        case 'GET':
            if (!hasPermission('tasks', 'read')) {
                jsonResponse(['success' => false, 'message' => 'Access denied - no read permission'], 403);
            }
            if ($id) {
                try {
                    $task = $fetchTask($id);
                    if (!$task) {
                        jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
                    }
                    $assignees = $fetchAssignees($id);
                    $task['assignees'] = array_column($assignees, 'assignee_id');
                    $task['assignee_names'] = array_map(function ($a) {
                        return $a['first_name'] . ' ' . $a['last_name'];
                    }, $assignees);
                    
                    // Add next occurrence info for recurring tasks
                    if ($task['is_recurring']) {
                        $recurringService = new RecurringTaskService();
                        $nextOccurrence = $recurringService->getNextOccurrence($id);
                        $task['next_occurrence'] = $nextOccurrence;
                    }
                    
                    // Add editable fields information for current user
                    try {
                        $task['editable_fields'] = getEditableTaskFields($id, $user['id']);
                        $task['is_assigner'] = isTaskAssigner($id, $user['id']);
                        $task['is_assignee'] = isTaskAssignee($id, $user['id']);
                    } catch (Exception $permError) {
                        // If permission functions fail, set defaults
                        error_log("Permission error: " . $permError->getMessage());
                        $task['editable_fields'] = [];
                        $task['is_assigner'] = false;
                        $task['is_assignee'] = false;
                    }
                    
                    jsonResponse(['success' => true, 'task' => $task]);
                } catch (Exception $e) {
                    error_log("Task loading error: " . $e->getMessage());
                    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
                }
            }
            $tasks = $db->fetchAll(
                "SELECT t.*, ts.name as status_name, tp.name as priority_name,
                 ab.first_name as assigned_by_first, ab.last_name as assigned_by_last
                 FROM tasks t
                 LEFT JOIN task_statuses ts ON t.task_status_id = ts.id
                 LEFT JOIN task_priorities tp ON t.task_priority_id = tp.id
                 LEFT JOIN employees ab ON t.assigned_by = ab.id
                 ORDER BY t.deadline IS NULL, t.deadline ASC, t.created_at DESC"
            );
            
            $recurringService = new RecurringTaskService();
            
            foreach ($tasks as &$t) {
                $a = $fetchAssignees($t['id']);
                $t['assignees'] = array_column($a, 'assignee_id');
                $t['assignee_names'] = array_map(function ($x) {
                    return $x['first_name'] . ' ' . $x['last_name'];
                }, $a);
                
                // Parse recurrence_days JSON if present
                if (!empty($t['recurrence_days'])) {
                    $t['recurrence_days'] = json_decode($t['recurrence_days'], true);
                }
                
                // Add next occurrence info for recurring tasks
                if ($t['is_recurring']) {
                    $nextOccurrence = $recurringService->getNextOccurrence($t['id']);
                    $t['next_occurrence'] = $nextOccurrence;
                }
                
                // Add basic permission information for each task
                $t['is_assigner'] = ($t['assigned_by'] == $user['id']);
                $t['is_assignee'] = in_array($user['id'], $t['assignees']);
            }
            jsonResponse(['success' => true, 'tasks' => $tasks]);
            break;
case 'POST':
            // Create task
            if (!hasPermission('tasks', 'create')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['title'])) {
                jsonResponse(['success' => false, 'message' => 'Task title is required'], 400);
            }
            $statusName = $data['status'] ?? 'Pending';
            $progress = isset($data['progress']) ? max(0, min(100, (int) $data['progress'])) : 0;

            $priorityId = $priorityIdByName($data['priority'] ?? null);
            if ($priorityId === null) $priorityId = $priorityIdByName('Medium');

            // Check if this is a recurring task
            $isRecurring = !empty($data['is_recurring']);
            
            // Validate recurring task configuration if applicable
            if ($isRecurring) {
                $recurringService = new RecurringTaskService();
                $validation = $recurringService->validateRecurringConfig($data);
                if (!$validation['valid']) {
                    jsonResponse(['success' => false, 'message' => $validation['message']], 400);
                }
            }

            $taskData = [
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'assigned_by' => $user['id'],
                'deadline' => !empty($data['deadline']) ? date('Y-m-d H:i:s', strtotime($data['deadline'])) : null,
                'task_status_id' => $statusIdByName($statusName),
                'task_priority_id' => $priorityId,
                'progress' => $progress,
                'related_module' => $data['related_module'] ?? null,
                'related_record_id' => !empty($data['related_record_id']) ? (int) $data['related_record_id'] : null,
                // Recurring task fields (defaults for one-time tasks)
                'is_recurring' => $isRecurring ? 1 : 0,
                'recurrence_pattern' => $data['recurrence_pattern'] ?? 'once',
                'recurrence_days' => $isRecurring && !empty($data['recurrence_days']) ? json_encode($data['recurrence_days']) : null,
                'recurrence_start_date' => $isRecurring ? $data['recurrence_start_date'] : null,
                'recurrence_end_date' => $isRecurring && !empty($data['recurrence_end_date']) ? $data['recurrence_end_date'] : null,
                'recurrence_time' => $isRecurring ? $data['recurrence_time'] : null,
                'reminder_minutes_before' => $isRecurring && !empty($data['reminder_minutes_before']) ? (int)$data['reminder_minutes_before'] : null,
                'notifications_enabled' => $isRecurring && !empty($data['notifications_enabled']) ? 1 : 0,
                'is_recurring_active' => $isRecurring ? 1 : 0
            ];

            $taskId = $db->insert('tasks', $taskData);
            $assigneeIds = $syncAssignees($taskId, $data['assignees'] ?? [], []);
            
            // Generate occurrences for recurring tasks
            if ($isRecurring && !empty($assigneeIds)) {
                $recurringService = new RecurringTaskService();
                $recurringService->generateOccurrences($taskId, $assigneeIds);
            }
            
            if ($statusName === 'Completed') {
                $finalizeCompletion($taskId, $assigneeIds);
            }

            logAudit('tasks', $taskId, 'create', null, $taskData);
            jsonResponse(['success' => true, 'task_id' => $taskId, 'message' => 'Task created successfully']);
            break;
case 'PUT':
            // Update task / start / update progress / toggle recurring
            if (!hasPermission('tasks', 'update')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$id) $id = isset($data['id']) ? (int) $data['id'] : null;
            if (!$id) jsonResponse(['success' => false, 'message' => 'Task ID is required'], 400);

            $before = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$id]);
            if (!$before) jsonResponse(['success' => false, 'message' => 'Task not found'], 404);

            // Get editable fields for current user
            $editableFields = getEditableTaskFields($id, $user['id']);
            
            // Check if user has permission to edit this task
            if (empty($editableFields)) {
                jsonResponse(['success' => false, 'message' => 'You do not have permission to edit this task'], 403);
            }

            // Handle special recurring task actions
            $action = $data['action'] ?? '';
            if ($action === 'toggle_recurring') {
                // Check if user can edit recurring settings
                if (!in_array('all', $editableFields) && !in_array('is_recurring_active', $editableFields)) {
                    jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurring settings'], 403);
                }
                $isActive = !empty($data['is_active']);
                $recurringService = new RecurringTaskService();
                $result = $recurringService->toggleRecurringTask($id, $isActive);
                logAudit('tasks', $id, 'toggle_recurring', ['is_recurring_active' => $before['is_recurring_active']], ['is_recurring_active' => $isActive ? 1 : 0]);
                jsonResponse($result);
            }

            $updateData = [];
            $isStart = ($action === 'start');

            if ($isStart) {
                // Check if user can edit status
                if (!in_array('all', $editableFields) && !in_array('status', $editableFields)) {
                    jsonResponse(['success' => false, 'message' => 'You do not have permission to change task status'], 403);
                }
                $updateData['task_status_id'] = $statusIdByName('In Progress');
            } else {
                // Validate each field against user permissions
                if (array_key_exists('title', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('title', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to edit task title'], 403);
                    }
                    $updateData['title'] = trim($data['title']);
                }
                if (array_key_exists('description', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('description', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to edit task description'], 403);
                    }
                    $updateData['description'] = $data['description'];
                }
                if (array_key_exists('deadline', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('deadline', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to change due date'], 403);
                    }
                    $updateData['deadline'] = !empty($data['deadline']) ? date('Y-m-d H:i:s', strtotime($data['deadline'])) : null;
                }
                if (array_key_exists('priority', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('priority', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to change task priority'], 403);
                    }
                    $updateData['task_priority_id'] = $priorityIdByName($data['priority']);
                }
                if (array_key_exists('status', $data)) {
                    // Allow status updates for assigners and assignees
                    $isAssigner = isTaskAssigner($id, $user['id']);
                    $isAssignee = isTaskAssignee($id, $user['id']);
                    
                    if (!in_array('all', $editableFields) && !in_array('status', $editableFields) && !$isAssigner && !$isAssignee) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to change task status'], 403);
                    }
                    $updateData['task_status_id'] = $statusIdByName($data['status']);
                }
                if (array_key_exists('progress', $data)) {
                    // ONLY allow progress updates for assignees (not assigners or others)
                    $isAssignee = isTaskAssignee($id, $user['id']);
                    
                    if (!in_array('all', $editableFields) && !in_array('progress', $editableFields) && !$isAssignee) {
                        jsonResponse(['success' => false, 'message' => 'Only assignees can update task progress'], 403);
                    }
                    $updateData['progress'] = max(0, min(100, (int) $data['progress']));
                    
                    // Auto-update status based on progress if status is not being explicitly changed
                    if (!array_key_exists('status', $data)) {
                        $progressValue = (int) $data['progress'];
                        if ($progressValue === 0) {
                            $updateData['task_status_id'] = $statusIdByName('Pending');
                        } elseif ($progressValue > 0 && $progressValue < 100) {
                            $updateData['task_status_id'] = $statusIdByName('In Progress');
                        } elseif ($progressValue === 100) {
                            $updateData['task_status_id'] = $statusIdByName('Completed');
                        }
                    }
                }
                if (array_key_exists('related_module', $data)) {
                    if (!in_array('all', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify related module'], 403);
                    }
                    $updateData['related_module'] = $data['related_module'];
                }
                if (array_key_exists('related_record_id', $data)) {
                    if (!in_array('all', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify related record'], 403);
                    }
                    $updateData['related_record_id'] = !empty($data['related_record_id']) ? (int) $data['related_record_id'] : null;
                }
                
                // Handle recurring task updates with permission checks
                if (array_key_exists('is_recurring', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('is_recurring_active', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurring settings'], 403);
                    }
                    $updateData['is_recurring'] = !empty($data['is_recurring']) ? 1 : 0;
                }
                if (array_key_exists('recurrence_pattern', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('recurrence_pattern', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurrence pattern'], 403);
                    }
                    $updateData['recurrence_pattern'] = $data['recurrence_pattern'];
                }
                if (array_key_exists('recurrence_days', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('recurrence_days', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurrence days'], 403);
                    }
                    $updateData['recurrence_days'] = !empty($data['recurrence_days']) ? json_encode($data['recurrence_days']) : null;
                }
                if (array_key_exists('recurrence_start_date', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('recurrence_start_date', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurrence start date'], 403);
                    }
                    $updateData['recurrence_start_date'] = $data['recurrence_start_date'];
                }
                if (array_key_exists('recurrence_end_date', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('recurrence_end_date', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurrence end date'], 403);
                    }
                    $updateData['recurrence_end_date'] = $data['recurrence_end_date'];
                }
                if (array_key_exists('recurrence_time', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('recurrence_time', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify recurrence time'], 403);
                    }
                    $updateData['recurrence_time'] = $data['recurrence_time'];
                }
                if (array_key_exists('reminder_minutes_before', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('reminder_minutes_before', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify reminder settings'], 403);
                    }
                    $updateData['reminder_minutes_before'] = !empty($data['reminder_minutes_before']) ? (int)$data['reminder_minutes_before'] : null;
                }
                if (array_key_exists('notifications_enabled', $data)) {
                    if (!in_array('all', $editableFields) && !in_array('notifications_enabled', $editableFields)) {
                        jsonResponse(['success' => false, 'message' => 'You do not have permission to modify notification settings'], 403);
                    }
                    $updateData['notifications_enabled'] = !empty($data['notifications_enabled']) ? 1 : 0;
                }
            }

            if (!empty($updateData)) $db->update('tasks', $updateData, 'id = ?', [$id]);

            $currentAssignees = array_column($fetchAssignees($id), 'assignee_id');
            if (isset($data['assignees'])) {
                // Check if user can change assignees
                if (!in_array('all', $editableFields) && !in_array('assignees', $editableFields)) {
                    jsonResponse(['success' => false, 'message' => 'You do not have permission to change task assignees'], 403);
                }
                $currentAssignees = $syncAssignees($id, $data['assignees'], $currentAssignees);
            }

            // Regenerate occurrences if recurring task configuration changed
            if (!empty($updateData['is_recurring']) || !empty($updateData['recurrence_pattern']) || 
                !empty($updateData['recurrence_days']) || !empty($updateData['recurrence_start_date']) ||
                !empty($updateData['recurrence_end_date']) || !empty($updateData['recurrence_time'])) {
                
                $task = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$id]);
                if ($task && $task['is_recurring'] && !empty($currentAssignees)) {
                    $recurringService = new RecurringTaskService();
                    $recurringService->generateOccurrences($id, $currentAssignees);
                }
            }

            $newTask = $db->fetchOne(
                "SELECT t.*, ts.name as status_name FROM tasks t
                 LEFT JOIN task_statuses ts ON t.task_status_id = ts.id WHERE t.id = ?",
                [$id]
            );
            if ($newTask && ($newTask['status_name'] === 'Completed' || (int) $newTask['progress'] >= 100)) {
                $db->update('tasks',
                    ['task_status_id' => $statusIdByName('Completed'), 'progress' => 100],
                    'id = ?', [$id]);
                $finalizeCompletion($id, $currentAssignees);

                // Sync linked recurring-task occurrence: completing the daily
                // occurrence task also completes the corresponding task_occurrences row
                if ($newTask['related_module'] === 'task_occurrence' && !empty($newTask['related_record_id'])) {
                    $db->update('task_occurrences',
                        ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')],
                        'id = ?',
                        [$newTask['related_record_id']]
                    );
                }
            }

            logAudit('tasks', $id, 'update', $before, $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$id]));
            jsonResponse(['success' => true, 'message' => 'Task updated successfully']);
            break;
case 'DELETE':
            if (!hasPermission('tasks', 'delete')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            if (!$id) jsonResponse(['success' => false, 'message' => 'Task ID is required'], 400);
            $existing = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$id]);
            if (!$existing) jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
            $db->delete('tasks', 'id = ?', [$id]);
            logAudit('tasks', $id, 'delete', $existing, null);
            jsonResponse(['success' => true, 'message' => 'Task deleted successfully']);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
