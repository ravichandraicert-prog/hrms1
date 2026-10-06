-- Attendance Threshold History
-- Tracks changes to threshold configuration for historical record preservation
-- This ensures that historical attendance records maintain their original calculated status

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