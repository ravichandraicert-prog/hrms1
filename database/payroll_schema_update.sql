-- Pay Cycle-Driven Payroll System Schema Update
-- This migration adds support for recurring pay cycles, assignments, and period-driven payroll

-- ========================================================================
-- Table: pay_cycles (recurring pay cycle configuration)
-- ========================================================================
CREATE TABLE IF NOT EXISTS `pay_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'e.g., Monthly - 16th to 15th',
  `description` text DEFAULT NULL,
  `frequency` varchar(50) NOT NULL DEFAULT 'monthly' COMMENT 'monthly, bi-weekly, weekly',
  `cycle_start_day` int(11) NOT NULL COMMENT 'Day of month (1-31) for start',
  `cycle_end_day` int(11) NOT NULL COMMENT 'Day of month (1-31) or 99 for last day',
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'Default pay cycle for organization',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `pay_cycles_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================================
-- Table: pay_cycle_assignments (assign pay cycles at different levels)
-- ========================================================================
CREATE TABLE IF NOT EXISTS `pay_cycle_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pay_cycle_id` int(11) NOT NULL,
  `assignment_type` varchar(50) NOT NULL COMMENT 'organization, department, role, employee',
  `assignment_id` int(11) DEFAULT NULL COMMENT 'ID of department/role/employee, NULL for organization',
  `priority` int(11) NOT NULL DEFAULT 10 COMMENT 'Priority: 10=org, 20=dept, 30=role, 40=employee',
  `effective_date` date NOT NULL COMMENT 'When this assignment becomes effective',
  `end_date` date DEFAULT NULL COMMENT 'When this assignment ends (optional)',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pay_cycle_id` (`pay_cycle_id`),
  KEY `assignment_type` (`assignment_type`),
  KEY `assignment_id` (`assignment_id`),
  KEY `created_by` (`created_by`),
  KEY `effective_date` (`effective_date`),
  CONSTRAINT `pay_cycle_assignments_ibfk_1` FOREIGN KEY (`pay_cycle_id`) REFERENCES `pay_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pay_cycle_assignments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================================
-- Table: payroll_periods (generated payroll periods based on pay cycles)
-- ========================================================================
CREATE TABLE IF NOT EXISTS `payroll_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pay_cycle_id` int(11) NOT NULL,
  `period_name` varchar(100) NOT NULL COMMENT 'e.g., August 2026 (16th-15th)',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `pay_date` date DEFAULT NULL COMMENT 'Expected payment date',
  `status` varchar(50) DEFAULT 'Draft' COMMENT 'Draft, Processing, Generated, Reviewed, Approved, Paid',
  `total_employees` int(11) DEFAULT 0,
  `total_payout` decimal(15,2) DEFAULT 0.00,
  `processed_employees` int(11) DEFAULT 0,
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `marked_paid_by` int(11) DEFAULT NULL,
  `marked_paid_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_period_dates` (`pay_cycle_id`, `period_start`, `period_end`),
  KEY `pay_cycle_id` (`pay_cycle_id`),
  KEY `period_start` (`period_start`),
  KEY `period_end` (`period_end`),
  KEY `status` (`status`),
  KEY `generated_by` (`generated_by`),
  KEY `approved_by` (`approved_by`),
  KEY `marked_paid_by` (`marked_paid_by`),
  CONSTRAINT `payroll_periods_ibfk_1` FOREIGN KEY (`pay_cycle_id`) REFERENCES `pay_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_periods_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_ibfk_4` FOREIGN KEY (`marked_paid_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================================
-- Update payroll_runs table to reference payroll_periods
-- ========================================================================
-- Add foreign key column to link payroll runs to payroll periods
ALTER TABLE `payroll_runs` 
ADD COLUMN `payroll_period_id` int(11) DEFAULT NULL AFTER `id`,
ADD KEY `payroll_period_id` (`payroll_period_id`),
ADD CONSTRAINT `payroll_runs_ibfk_3` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL;

-- Update status to include new workflow states
ALTER TABLE `payroll_runs` 
MODIFY COLUMN `status` varchar(50) DEFAULT 'Draft' COMMENT 'Draft, Processing, Generated, Reviewed, Approved, Paid';

-- Add additional workflow tracking columns
ALTER TABLE `payroll_runs`
ADD COLUMN `reviewed_by` int(11) DEFAULT NULL AFTER `marked_paid_by`,
ADD COLUMN `reviewed_at` datetime DEFAULT NULL AFTER `marked_paid_at`,
ADD KEY `reviewed_by` (`reviewed_by`),
ADD CONSTRAINT `payroll_runs_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

-- ========================================================================
-- Add pay cycle reference to employees table for quick lookup
-- ========================================================================
ALTER TABLE `employees`
ADD COLUMN `current_pay_cycle_id` int(11) DEFAULT NULL AFTER `shift_id`,
ADD COLUMN `pay_cycle_assignment_source` varchar(50) DEFAULT NULL COMMENT 'organization, department, role, employee',
ADD COLUMN `pay_cycle_effective_date` date DEFAULT NULL,
ADD KEY `current_pay_cycle_id` (`current_pay_cycle_id`),
ADD CONSTRAINT `employees_ibfk_5` FOREIGN KEY (`current_pay_cycle_id`) REFERENCES `pay_cycles` (`id`) ON DELETE SET NULL;

-- ========================================================================
-- Insert sample pay cycles for testing
-- ========================================================================
INSERT INTO `pay_cycles` (`name`, `description`, `frequency`, `cycle_start_day`, `cycle_end_day`, `is_default`, `is_active`) VALUES
('Monthly - 1st to Last Day', 'Standard calendar month pay cycle', 'monthly', 1, 99, 1, 1),
('Monthly - 16th to 15th', 'Mid-month to mid-month pay cycle', 'monthly', 16, 15, 0, 1),
('Monthly - 26th to 25th', 'Late month to late month pay cycle', 'monthly', 26, 25, 0, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ========================================================================
-- Create default organization-wide pay cycle assignment
-- ========================================================================
INSERT INTO `pay_cycle_assignments` (`pay_cycle_id`, `assignment_type`, `assignment_id`, `priority`, `effective_date`, `is_active`)
SELECT id, 'organization', NULL, 10, CURDATE(), 1 
FROM pay_cycles 
WHERE is_default = 1 
LIMIT 1
ON DUPLICATE KEY UPDATE `pay_cycle_id` = VALUES(`pay_cycle_id`);
