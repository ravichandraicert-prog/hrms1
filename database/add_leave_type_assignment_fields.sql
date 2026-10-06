-- Add assignment fields to leave_types table
-- This allows leave types to be assigned to specific targets (all employees, departments, roles, or specific users)

ALTER TABLE `leave_types` 
ADD COLUMN `assign_to` VARCHAR(50) DEFAULT 'all_employees' 
COMMENT 'all_employees, department, role, user' AFTER `applicable_to`,
ADD COLUMN `target_id` INT(11) DEFAULT NULL 
COMMENT 'ID of the department, role, or user based on assign_to' AFTER `assign_to`;
