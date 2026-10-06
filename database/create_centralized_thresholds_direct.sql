-- Run this SQL script directly in your database (phpMyAdmin or MySQL CLI)
-- This creates the centralized attendance threshold tables

-- Create attendance_settings table
CREATE TABLE IF NOT EXISTS `attendance_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `present_threshold_minutes` int(11) NOT NULL DEFAULT 495 COMMENT 'Present threshold in minutes (default: 8:15 = 495 minutes)',
  `half_day_threshold_minutes` int(11) NOT NULL DEFAULT 255 COMMENT 'Half Day threshold in minutes (default: 4:15 = 255 minutes)',
  `effective_date` date NOT NULL DEFAULT (CURDATE()) COMMENT 'Date when these thresholds become effective',
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created/last modified these settings',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `attendance_settings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings if table is empty
INSERT IGNORE INTO `attendance_settings` (`present_threshold_minutes`, `half_day_threshold_minutes`, `effective_date`)
VALUES (495, 255, CURDATE());

-- Create attendance_threshold_history table
CREATE TABLE IF NOT EXISTS `attendance_threshold_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_settings_id` int(11) NOT NULL COMMENT 'Reference to the attendance_settings record',
  `present_threshold_minutes` int(11) NOT NULL,
  `half_day_threshold_minutes` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'When this configuration was replaced (NULL if current)',
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `change_reason` text DEFAULT NULL COMMENT 'Optional reason for the change',
  PRIMARY KEY (`id`),
  KEY `attendance_settings_id` (`attendance_settings_id`),
  KEY `effective_date` (`effective_date`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `threshold_history_ibfk_1` FOREIGN KEY (`attendance_settings_id`) REFERENCES `attendance_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `threshold_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;