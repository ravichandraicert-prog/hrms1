-- Add min_duration_minutes and max_duration_minutes columns to break_types table
ALTER TABLE `break_types` 
ADD COLUMN `min_duration_minutes` int(11) DEFAULT NULL AFTER `default_duration_minutes`,
ADD COLUMN `max_duration_minutes` int(11) DEFAULT NULL AFTER `min_duration_minutes`;
