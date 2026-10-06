-- Monthly Leave Accrual Feature
-- This migration adds support for automatic monthly leave accrual based on pay cycles

-- Add monthly accrual fields to leave_types table
ALTER TABLE `leave_types`
ADD COLUMN `monthly_accrual_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable automatic monthly leave accrual',
ADD COLUMN `monthly_accrual_days` decimal(5,2) DEFAULT 0.00 COMMENT 'Number of days to accrue monthly when enabled',
ADD COLUMN `accrual_timing` varchar(50) DEFAULT 'pay_cycle_end' COMMENT 'When to accrue: pay_cycle_end, pay_cycle_start, specific_day',
ADD COLUMN `accrual_day_of_month` int(11) DEFAULT NULL COMMENT 'Specific day of month for accrual if timing is specific_day';

-- Create table to track monthly accrual history
CREATE TABLE IF NOT EXISTS `leave_accrual_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `accrual_date` date NOT NULL,
  `accrual_period_start` date NOT NULL,
  `accrual_period_end` date NOT NULL,
  `days_accrued` decimal(5,2) NOT NULL,
  `pay_cycle_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `leave_type_id` (`leave_type_id`),
  KEY `accrual_date` (`accrual_date`),
  KEY `accrual_period_end` (`accrual_period_end`),
  KEY `pay_cycle_id` (`pay_cycle_id`),
  CONSTRAINT `leave_accrual_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_accrual_history_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_accrual_history_ibfk_3` FOREIGN KEY (`pay_cycle_id`) REFERENCES `pay_cycles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add unique constraint to prevent duplicate accruals for same period
ALTER TABLE `leave_accrual_history`
ADD UNIQUE KEY `unique_emp_type_period` (`employee_id`, `leave_type_id`, `accrual_period_start`, `accrual_period_end`);
