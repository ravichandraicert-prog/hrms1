-- Migration: Add Task Attachments Support
-- This migration adds a task_attachments table to support file uploads for tasks
-- Supports multiple attachments per task with proper security and metadata tracking

-- Create task_attachments table
CREATE TABLE `task_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `task_id` INT(11) NOT NULL COMMENT 'Reference to the task',
  `uploaded_by` INT(11) NOT NULL COMMENT 'Employee who uploaded the attachment',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'Original filename',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Server file path',
  `file_type` VARCHAR(100) NOT NULL COMMENT 'MIME type of the file',
  `file_size` INT(11) NOT NULL COMMENT 'File size in bytes',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `task_attachments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Task attachments with file metadata';

-- Create uploads directory for task attachments
-- This will be created programmatically, but documented here for reference
-- Directory structure: /uploads/tasks/
-- Subdirectories can be organized by year/month for better management