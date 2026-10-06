-- Remove ON DELETE CASCADE from leave_type_id foreign key in leave_applications
-- This allows leave types to be deleted while preserving leave application records

-- Drop the existing foreign key constraint
ALTER TABLE `leave_applications` DROP FOREIGN KEY `leave_applications_ibfk_2`;

-- Re-add the foreign key with ON DELETE SET NULL instead of CASCADE
ALTER TABLE `leave_applications` 
ADD CONSTRAINT `leave_applications_ibfk_2` 
FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE SET NULL;
