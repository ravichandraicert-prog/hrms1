-- Add net_worked_minutes column to attendance_daily table
ALTER TABLE attendance_daily ADD COLUMN net_worked_minutes int(11) DEFAULT 0 AFTER total_break_minutes;
