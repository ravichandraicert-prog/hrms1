-- Add assignment fields to break_types table
-- This enables assigning break types to specific employees, departments, roles, users, or shifts

ALTER TABLE `break_types`
ADD COLUMN `assign_to` VARCHAR(50) DEFAULT 'all_employees'
COMMENT 'all_employees, department, role, user, shift' AFTER `description`,
ADD COLUMN `target_id` INT(11) DEFAULT NULL
COMMENT 'ID of the department, role, user, or shift based on assign_to' AFTER `assign_to`;
