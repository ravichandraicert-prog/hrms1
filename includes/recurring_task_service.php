<?php
/**
 * Recurring Task Service
 * Handles business logic for recurring task operations
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/../config/database.php';

class RecurringTaskService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Check if a date matches the recurrence pattern
     */
    public function dateMatchesPattern($date, $pattern, $days) {
        $dateObj = new DateTime($date);
        $dayOfWeek = (int)$dateObj->format('N'); // 1=Mon, 7=Sun
        
        switch ($pattern) {
            case 'once':
                return false; // Once pattern doesn't generate multiple occurrences
            case 'daily':
                return true; // Every day matches
            case 'custom_days':
                if (empty($days)) return false;
                // Handle both string (JSON) and array input
                $selectedDays = is_array($days) ? $days : json_decode($days, true);
                if (!is_array($selectedDays)) return false;
                return in_array($dayOfWeek, $selectedDays);
            default:
                return false;
        }
    }
    
    /**
     * Calculate next occurrence dates for a recurring task
     */
    public function calculateNextOccurrences($task, $startDate, $endDate, $maxDays = 365) {
        $occurrences = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = $endDate ? new DateTime($endDate) : null;
        $daysGenerated = 0;
        
        // Handle missing recurrence_days
        $recurrenceDays = $task['recurrence_days'] ?? null;
        
        while ($daysGenerated < $maxDays) {
            if ($endDateTime && $currentDate > $endDateTime) {
                break;
            }
            
            if ($this->dateMatchesPattern($currentDate->format('Y-m-d'), $task['recurrence_pattern'], $recurrenceDays)) {
                $occurrences[] = $currentDate->format('Y-m-d');
                $daysGenerated++;
            }
            
            $currentDate->modify('+1 day');
        }
        
        return $occurrences;
    }
    
    /**
     * Generate task occurrences for a recurring task
     */
    public function generateOccurrences($taskId, $assigneeIds) {
        $task = $this->db->fetchOne(
            "SELECT * FROM tasks WHERE id = ? AND is_recurring = 1",
            [$taskId]
        );
        
        if (!$task) {
            return ['success' => false, 'message' => 'Recurring task not found'];
        }
        
        if (!$task['is_recurring_active']) {
            return ['success' => false, 'message' => 'Recurring task is not active'];
        }
        
        // Calculate occurrence dates
        $startDate = $task['recurrence_start_date'] ?: date('Y-m-d');
        $endDate = $task['recurrence_end_date'];
        $occurrenceDates = $this->calculateNextOccurrences($task, $startDate, $endDate);
        
        if (empty($occurrenceDates)) {
            return ['success' => true, 'message' => 'No occurrences to generate', 'generated' => 0];
        }
        
        $generated = 0;
        $taskTime = $task['recurrence_time'] ?: '09:00:00';
        
        foreach ($assigneeIds as $assigneeId) {
            foreach ($occurrenceDates as $date) {
                // Check if occurrence already exists
                $existing = $this->db->fetchOne(
                    "SELECT id FROM task_occurrences 
                     WHERE parent_task_id = ? AND assignee_id = ? AND occurrence_date = ?",
                    [$taskId, $assigneeId, $date]
                );
                
                if (!$existing) {
                    $this->db->insert('task_occurrences', [
                        'parent_task_id' => $taskId,
                        'assignee_id' => $assigneeId,
                        'occurrence_date' => $date,
                        'occurrence_time' => $taskTime,
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $generated++;
                }
            }
        }
        
        return ['success' => true, 'message' => "Generated {$generated} occurrences", 'generated' => $generated];
    }
    
    /**
     * Get next occurrence for a recurring task
     */
    public function getNextOccurrence($taskId) {
        return $this->db->fetchOne(
            "SELECT * FROM task_occurrences 
             WHERE parent_task_id = ? AND status = 'pending' 
             AND occurrence_date >= CURDATE()
             ORDER BY occurrence_date ASC, occurrence_time ASC
             LIMIT 1",
            [$taskId]
        );
    }
    
    /**
     * Get occurrences for a recurring task
     */
    public function getOccurrences($taskId, $limit = 50, $offset = 0) {
        return $this->db->fetchAll(
            "SELECT to.*, 
                    e.first_name, e.last_name, e.employee_id,
                    ts.name as status_name
             FROM task_occurrences to
             JOIN employees e ON to.assignee_id = e.id
             LEFT JOIN task_statuses ts ON to.status = ts.name
             WHERE to.parent_task_id = ?
             ORDER BY to.occurrence_date DESC, to.occurrence_time DESC
             LIMIT ? OFFSET ?",
            [$taskId, $limit, $offset]
        );
    }
    
    /**
     * Update occurrence status
     */
    public function updateOccurrenceStatus($occurrenceId, $status, $completedAt = null) {
        $data = ['status' => $status];
        if ($status === 'completed' && $completedAt) {
            $data['completed_at'] = $completedAt;
        }
        
        $this->db->update('task_occurrences', $data, 'id = ?', [$occurrenceId]);
        return ['success' => true, 'message' => 'Occurrence updated'];
    }
    
    /**
     * Skip an occurrence
     */
    public function skipOccurrence($occurrenceId) {
        return $this->updateOccurrenceStatus($occurrenceId, 'skipped');
    }
    
    /**
     * Cancel an occurrence
     */
    public function cancelOccurrence($occurrenceId) {
        return $this->updateOccurrenceStatus($occurrenceId, 'cancelled');
    }
    
    /**
     * Reschedule an occurrence
     */
    public function rescheduleOccurrence($occurrenceId, $newDate, $newTime = null) {
        $data = ['occurrence_date' => $newDate];
        if ($newTime) {
            $data['occurrence_time'] = $newTime;
        }
        
        $this->db->update('task_occurrences', $data, 'id = ?', [$occurrenceId]);
        return ['success' => true, 'message' => 'Occurrence rescheduled'];
    }
    
    /**
     * Toggle recurring task active status
     */
    public function toggleRecurringTask($taskId, $isActive) {
        $this->db->update('tasks', 
            ['is_recurring_active' => $isActive ? 1 : 0], 
            'id = ?', 
            [$taskId]
        );
        return ['success' => true, 'message' => $isActive ? 'Recurring task enabled' : 'Recurring task disabled'];
    }
    
    /**
     * Validate recurring task configuration
     */
    public function validateRecurringConfig($data) {
        if (!($data['is_recurring'] ?? false)) {
            return ['valid' => true]; // Not a recurring task, no validation needed
        }
        
        $pattern = $data['recurrence_pattern'] ?? 'once';
        if (!in_array($pattern, ['once', 'daily', 'custom_days'])) {
            return ['valid' => false, 'message' => 'Invalid recurrence pattern'];
        }
        
        if ($pattern === 'custom_days') {
            $days = $data['recurrence_days'] ?? [];
            if (empty($days) || !is_array($days)) {
                return ['valid' => false, 'message' => 'Please select at least one day for custom recurrence'];
            }
            // Validate day numbers (1-6 for Mon-Sat)
            foreach ($days as $day) {
                if ($day < 1 || $day > 6) {
                    return ['valid' => false, 'message' => 'Invalid day selected'];
                }
            }
        }
        
        $startDate = $data['recurrence_start_date'] ?? null;
        if (empty($startDate)) {
            return ['valid' => false, 'message' => 'Start date is required for recurring tasks'];
        }
        
        $endDate = $data['recurrence_end_date'] ?? null;
        if ($endDate && strtotime($endDate) < strtotime($startDate)) {
            return ['valid' => false, 'message' => 'End date cannot be earlier than start date'];
        }
        
        $taskTime = $data['recurrence_time'] ?? null;
        if (empty($taskTime)) {
            return ['valid' => false, 'message' => 'Task time is required for recurring tasks'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Get occurrence statistics for a recurring task
     */
    public function getOccurrenceStats($taskId) {
        $stats = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count 
             FROM task_occurrences 
             WHERE parent_task_id = ? 
             GROUP BY status",
            [$taskId]
        );
        
        $result = [
            'pending' => 0,
            'completed' => 0,
            'skipped' => 0,
            'cancelled' => 0,
            'total' => 0
        ];
        
        foreach ($stats as $stat) {
            $result[$stat['status']] = (int)$stat['count'];
            $result['total'] += (int)$stat['count'];
        }
        
        return $result;
    }
}