-- Add duration column to leave_applications table
-- This column stores whether the leave is full day, first half, or second half
ALTER TABLE `leave_applications`
ADD COLUMN `duration` VARCHAR(20) DEFAULT 'full' COMMENT 'full, first_half, second_half' AFTER `end_date`;
