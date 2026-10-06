-- Add festival_holiday_id column to leave_applications table
ALTER TABLE `leave_applications` 
ADD COLUMN `festival_holiday_id` int(11) DEFAULT NULL COMMENT 'Reference to holidays table for Festival Leave type' AFTER `leave_type_id`,
ADD KEY `festival_holiday_id` (`festival_holiday_id`),
ADD CONSTRAINT `leave_applications_ibfk_4` FOREIGN KEY (`festival_holiday_id`) REFERENCES `holidays` (`id`) ON DELETE SET NULL;
