-- Add present_threshold_minutes column to shifts table
-- This enables a standalone "Present Threshold" per shift (in minutes, converted from HH:MM in the UI).
-- Null means "not set" -> the attendance status logic falls back to half_day_threshold_minutes
-- (i.e. the previous Present cutoff) so existing behavior is preserved until set.
ALTER TABLE `shifts` ADD COLUMN `present_threshold_minutes` int(11) DEFAULT NULL AFTER `half_day_threshold_minutes`;