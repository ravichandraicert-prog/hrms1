-- Add is_in_hierarchy column to employee_departments table
-- This tracks whether an employee is part of the department's reporting hierarchy,
-- including top-level employees who have no reporting manager.
ALTER TABLE `employee_departments` 
ADD COLUMN `is_in_hierarchy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_managerial`;