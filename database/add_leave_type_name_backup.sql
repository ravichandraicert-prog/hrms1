-- Add backup column for leave type name in leave_applications table
-- This preserves the leave type name when the leave type is deleted

ALTER TABLE `leave_applications` 
ADD COLUMN `leave_type_name_backup` VARCHAR(100) DEFAULT NULL AFTER `leave_type_id`;

-- Update existing records with current leave type names
UPDATE `leave_applications` la
LEFT JOIN `leave_types` lt ON la.leave_type_id = lt.id
SET la.leave_type_name_backup = lt.name
WHERE la.leave_type_name_backup IS NULL;
