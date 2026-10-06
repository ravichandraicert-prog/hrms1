<?php
/**
 * Recurring Task Processor
 * This script should be run daily (e.g., via cron job) to process recurring tasks
 * It generates task occurrences, creates the concrete daily task for each assignee,
 * and sends notifications
 *
 * Usage: php cron/process_recurring_tasks.php
 * Recommended cron schedule: every 15 minutes (script is idempotent)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/recurring_task_service.php';

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');
    $currentTime = date('H:i:s');

    echo "Starting recurring task processing for: {$today} at {$currentTime}\n";

    // Step 1: Get all active recurring tasks
    $recurringTasks = $db->fetchAll(
        "SELECT t.*,
                (SELECT GROUP_CONCAT(assignee_id) FROM task_assignments WHERE task_id = t.id) as assignee_ids
         FROM tasks t
         WHERE t.is_recurring = 1
         AND t.is_recurring_active = 1
         AND (t.recurrence_end_date IS NULL OR t.recurrence_end_date >= ?)
         ORDER BY t.created_at ASC",
        [$today]
    );

    if (empty($recurringTasks)) {
        echo "No active recurring tasks found.\n";
        exit;
    }

    echo "Found " . count($recurringTasks) . " active recurring tasks.\n";

    $recurringService = new RecurringTaskService();
    $occurrencesGenerated = 0;
    $notificationsSent = 0;
    $remindersSent = 0;
    $dailyTasksCreated = 0;

    foreach ($recurringTasks as $task) {
        echo "\nProcessing task: {$task['title']} (ID: {$task['id']})\n";

        // Get assignees
        $assigneeIds = !empty($task['assignee_ids']) ? explode(',', $task['assignee_ids']) : [];
        $assigneeIds = array_map('intval', $assigneeIds);

        if (empty($assigneeIds)) {
            echo "  No assignees found for this task. Skipping.\n";
            continue;
        }

        echo "  Found " . count($assigneeIds) . " assignee(s).\n";

        // Generate occurrences for the next 30 days (batch processing for "no end date" tasks)
        $lookAheadDays = 30;
        $startDate = $task['recurrence_start_date'] ?: $today;
        $endDate = $task['recurrence_end_date'] ?: date('Y-m-d', strtotime("+{$lookAheadDays} days"));

        // Calculate occurrences
        $occurrenceDates = $recurringService->calculateNextOccurrences($task, $startDate, $endDate, $lookAheadDays);

        if (empty($occurrenceDates)) {
            echo "  No occurrences to generate for this task.\n";
            continue;
        }

        echo "  Found " . count($occurrenceDates) . " potential occurrence dates.\n";

        $taskTime = $task['recurrence_time'] ?: '09:00:00';
        $generatedForTask = 0;

        foreach ($assigneeIds as $assigneeId) {
            foreach ($occurrenceDates as $date) {
                // Check if occurrence already exists
                $existing = $db->fetchOne(
                    "SELECT id, status, notification_sent, reminder_sent
                     FROM task_occurrences
                     WHERE parent_task_id = ? AND assignee_id = ? AND occurrence_date = ?",
                    [$task['id'], $assigneeId, $date]
                );

                if (!$existing) {
                    // Create new occurrence
                    $occurrenceId = $db->insert('task_occurrences', [
                        'parent_task_id' => $task['id'],
                        'assignee_id' => $assigneeId,
                        'occurrence_date' => $date,
                        'occurrence_time' => $taskTime,
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $generatedForTask++;
                    $occurrencesGenerated++;
                } else {
                    $occurrenceId = $existing['id'];
                    // Process existing occurrence for notifications
                    // Check if this occurrence is due today
                    if ($date === $today && $existing['status'] === 'pending') {
                        $occurrenceDateTime = $date . ' ' . $taskTime;
                        $occurrenceTimestamp = strtotime($occurrenceDateTime);
                        $currentTimestamp = time();

                        // Check if it's time to send the main notification
                        if (!$existing['notification_sent'] && $currentTimestamp >= $occurrenceTimestamp) {
                            if ($task['notifications_enabled']) {
                                sendTaskNotification($assigneeId, $task, $date, $taskTime);
                                $db->update('task_occurrences',
                                    ['notification_sent' => 1],
                                    'id = ?',
                                    [$existing['id']]
                                );
                                $notificationsSent++;
                                echo "  Sent notification for occurrence on {$date}\n";
                            }
                        }

                        // Check if it's time to send reminder
                        if (!$existing['reminder_sent'] && $task['reminder_minutes_before']) {
                            $reminderTime = $occurrenceTimestamp - ($task['reminder_minutes_before'] * 60);
                            if ($currentTimestamp >= $reminderTime) {
                                sendTaskReminder($assigneeId, $task, $date, $taskTime, $task['reminder_minutes_before']);
                                $db->update('task_occurrences',
                                    ['reminder_sent' => 1],
                                    'id = ?',
                                    [$existing['id']]
                                );
                                $remindersSent++;
                                echo "  Sent reminder for occurrence on {$date}\n";
                            }
                        }
                    }
                }

                // Ensure a concrete daily task exists for today's occurrence
                // so the assignee gets an updatable task every day
                if ($date === $today && $occurrenceId) {
                    if (ensureDailyTask($db, $task, $assigneeId, $date, $taskTime, $occurrenceId)) {
                        $dailyTasksCreated++;
                    }
                }
            }
        }

        echo "  Generated {$generatedForTask} new occurrences for this task.\n";
    }

    echo "\n=== Processing Summary ===\n";
    echo "Total occurrences generated: {$occurrencesGenerated}\n";
    echo "Total daily tasks created: {$dailyTasksCreated}\n";
    echo "Total notifications sent: {$notificationsSent}\n";
    echo "Total reminders sent: {$remindersSent}\n";
    echo "Processing completed successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Ensure a concrete daily task exists for today's occurrence.
 * The daily task is linked to the occurrence via related_module/related_record_id,
 * is assigned to the assignee, and can be updated like any normal task.
 * Returns true if a new task was created, false if it already exists.
 */
function ensureDailyTask($db, $task, $assigneeId, $date, $taskTime, $occurrenceId) {
    // Idempotency: skip if the daily task for this occurrence already exists
    $existingTask = $db->fetchOne(
        "SELECT id FROM tasks WHERE related_module = 'task_occurrence' AND related_record_id = ? LIMIT 1",
        [$occurrenceId]
    );
    if ($existingTask) {
        return false;
    }

    $statusRow = $db->fetchOne("SELECT id FROM task_statuses WHERE name = 'Pending' LIMIT 1");
    $statusId = $statusRow ? (int)$statusRow['id'] : 1;

    $dailyTaskId = $db->insert('tasks', [
        'title' => $task['title'] . ' - ' . date('d M Y', strtotime($date)),
        'description' => $task['description'],
        'assigned_by' => $task['assigned_by'],
        'deadline' => $date . ' ' . $taskTime,
        'task_status_id' => $statusId,
        'task_priority_id' => $task['task_priority_id'],
        'related_module' => 'task_occurrence',
        'related_record_id' => $occurrenceId,
        'is_recurring' => 0,
        'progress' => 0
    ]);

    if ($dailyTaskId) {
        $db->insert('task_assignments', [
            'task_id' => $dailyTaskId,
            'assignee_id' => $assigneeId,
            'occurrence_id' => $occurrenceId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo "  Created daily task #{$dailyTaskId} ('{$task['title']} - " . date('d M Y', strtotime($date)) . "') for occurrence on {$date}\n";
        return true;
    }

    return false;
}

/**
 * Send task notification to assignee
 */
function sendTaskNotification($assigneeId, $task, $date, $time) {
    $db = Database::getInstance();

    // Get assignee details
    $assignee = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$assigneeId]);
    if (!$assignee) return;

    // Get assigner details
    $assigner = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$task['assigned_by']]);
    $assignerName = $assigner ? $assigner['first_name'] . ' ' . $assigner['last_name'] : 'HR/Admin';

    // Get priority name
    $priority = $db->fetchOne("SELECT name FROM task_priorities WHERE id = ?", [$task['task_priority_id']]);
    $priorityName = $priority ? $priority['name'] : 'Medium';

    // Format the time
    $formattedTime = date('h:i A', strtotime($time));
    $formattedDate = date('d M Y', strtotime($date));

    // Create notification message
    $message = "You have a scheduled task:\n\n";
    $message .= "Task: {$task['title']}\n";
    $message .= "Due: {$formattedDate} at {$formattedTime}\n";
    $message .= "Priority: {$priorityName}\n";
    $message .= "Assigned By: {$assignerName}";

    // Send notification using direct database insert
    $data = [
        'user_id' => $assigneeId,
        'type' => 'task',
        'title' => 'Task Reminder',
        'message' => $message,
        'link' => '?module=tasks&tab=my',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];
    return $db->insert('notifications', $data);
}

/**
 * Send task reminder to assignee
 */
function sendTaskReminder($assigneeId, $task, $date, $time, $minutesBefore) {
    $db = Database::getInstance();

    // Get assignee details
    $assignee = $db->fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$assigneeId]);
    if (!$assignee) return;

    // Format the time
    $formattedTime = date('h:i A', strtotime($time));
    $formattedDate = date('d M Y', strtotime($date));

    // Create reminder message
    $message = "Reminder: Your task is due in {$minutesBefore} minutes\n\n";
    $message .= "Task: {$task['title']}\n";
    $message .= "Due: {$formattedDate} at {$formattedTime}";

    // Send notification using direct database insert
    $data = [
        'user_id' => $assigneeId,
        'type' => 'task',
        'title' => 'Task Reminder',
        'message' => $message,
        'link' => '?module=tasks&tab=my',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];
    return $db->insert('notifications', $data);
}

