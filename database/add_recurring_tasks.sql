-- Migration: Add Recurring Task Support
-- This migration adds fields to support recurring tasks and creates a task_occurrences table
-- Existing one-time tasks will remain unchanged (is_recurring = 0 by default)
-- No migration of existing tasks needed - they will continue to work as before

-- Add recurring task fields to existing tasks table
ALTER TABLE `tasks`
ADD COLUMN `is_recurring` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = one-time task, 1 = recurring task' AFTER `related_record_id`,
ADD COLUMN `recurrence_pattern` ENUM('once', 'daily', 'custom_days') DEFAULT 'once' COMMENT 'Recurrence pattern' AFTER `is_recurring`,
ADD COLUMN `recurrence_days` VARCHAR(50) DEFAULT NULL COMMENT 'JSON array of day numbers (1=Mon, 2=Tue, ..., 6=Sat)' AFTER `recurrence_pattern`,
ADD COLUMN `recurrence_start_date` DATE DEFAULT NULL COMMENT 'Start date for recurrence' AFTER `recurrence_days`,
ADD COLUMN `recurrence_end_date` DATE DEFAULT NULL COMMENT 'End date for recurrence (null = no end date)' AFTER `recurrence_start_date`,
ADD COLUMN `recurrence_time` TIME DEFAULT NULL COMMENT 'Time for task occurrence (e.g., 10:00:00)' AFTER `recurrence_end_date`,
ADD COLUMN `reminder_minutes_before` INT DEFAULT NULL COMMENT 'Reminder minutes before task time' AFTER `recurrence_time`,
ADD COLUMN `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable notifications for recurring task' AFTER `reminder_minutes_before`,
ADD COLUMN `is_recurring_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = disabled (stops future occurrences)' AFTER `notifications_enabled`;

-- Add indexes for recurring task queries
ALTER TABLE `tasks`
ADD INDEX `idx_is_recurring` (`is_recurring`),
ADD INDEX `idx_is_recurring_active` (`is_recurring_active`),
ADD INDEX `idx_recurrence_start_date` (`recurrence_start_date`),
ADD INDEX `idx_recurrence_end_date` (`recurrence_end_date`);

-- Create task_occurrences table to store individual occurrences of recurring tasks
CREATE TABLE `task_occurrences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `parent_task_id` INT(11) NOT NULL COMMENT 'Reference to the parent recurring task',
  `assignee_id` INT(11) NOT NULL COMMENT 'Employee assigned to this occurrence',
  `occurrence_date` DATE NOT NULL COMMENT 'Date of this occurrence',
  `occurrence_time` TIME NOT NULL COMMENT 'Time of this occurrence',
  `status` ENUM('pending', 'completed', 'skipped', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Status of this occurrence',
  `completed_at` DATETIME DEFAULT NULL COMMENT 'When this occurrence was completed',
  `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether reminder notification was sent',
  `notification_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether task notification was sent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_parent_assignee_date` (`parent_task_id`, `assignee_id`, `occurrence_date`),
  KEY `idx_parent_task_id` (`parent_task_id`),
  KEY `idx_assignee_id` (`assignee_id`),
  KEY `idx_occurrence_date` (`occurrence_date`),
  KEY `idx_status` (`status`),
  KEY `idx_parent_status_date` (`parent_task_id`, `status`, `occurrence_date`),
  CONSTRAINT `task_occurrences_ibfk_1` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_occurrences_ibfk_2` FOREIGN KEY (`assignee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual occurrences of recurring tasks';

-- Note: task_assignments table does not need occurrence_id field
-- Occurrences are tracked separately in task_occurrences table
-- The unique constraint on (task_id, assignee_id) prevents direct occurrence linking