-- Centralized Attendance Threshold Configuration
-- This creates a single source of truth for attendance status thresholds
-- Default values: Present = 8:15 (495 minutes), Half Day = 4:15 (255 minutes)

-- Create attendance_settings table for centralized configuration
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

-- Insert default settings
INSERT INTO `attendance_settings` (`present_threshold_minutes`, `half_day_threshold_minutes`, `effective_date`)
VALUES (495, 255, CURDATE())
ON DUPLICATE KEY UPDATE 
  `present_threshold_minutes` = 495,
  `half_day_threshold_minutes` = 255,
  `effective_date` = CURDATE();