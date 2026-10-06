-- Idle Time Tracking implementation
-- 1) Add idle_minutes column to attendance_daily (aggregated per day)
-- 2) Create idle_periods table to store individual idle periods
-- 3) Seed settings defaults for idle-time configuration

-- --------------------------------------------------------

-- Add idle_minutes column to attendance_daily
ALTER TABLE attendance_daily
  ADD COLUMN idle_minutes int(11) NOT NULL DEFAULT 0 AFTER net_worked_minutes;

-- --------------------------------------------------------

-- Table: idle_periods (raw idle time log)
CREATE TABLE IF NOT EXISTS `idle_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `idle_start_utc` datetime NOT NULL,
  `idle_end_utc` datetime NOT NULL,
  `idle_minutes` int(11) NOT NULL DEFAULT 0,
  `source` varchar(50) DEFAULT 'web' COMMENT 'web, desktop',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_emp_date_start` (`employee_id`, `attendance_date`, `idle_start_utc`),
  KEY `employee_id` (`employee_id`),
  KEY `attendance_date` (`attendance_date`),
  CONSTRAINT `idle_periods_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Seed default idle-time configuration in the settings table.
-- These use INSERT ... ON DUPLICATE KEY UPDATE so re-running the migration is safe
-- and never overwrites an administrator-configured value.
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('idle_tracking_enabled', '1', 'Master switch for idle time tracking (1 = enabled)'),
  ('idle_timeout_minutes', '20', 'Automatic clock-out after this many consecutive idle minutes while clocked in'),
  ('idle_min_record_minutes', '6', 'Idle periods shorter than this many minutes are recorded as 0 minutes'),
  ('idle_start_minutes', '5', 'Idle time considered started after this many consecutive idle minutes while clocked in (used for idle notifications)'),
  ('idle_assign_to', 'all_employees', 'Audience for idle tracking: all_employees, department, role, user, shift'),
  ('idle_target_id', '0', 'Target department/role/user/shift id when idle_assign_to is not all_employees')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
