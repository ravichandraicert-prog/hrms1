-- Add gender column to employees table
ALTER TABLE employees ADD COLUMN gender varchar(20) DEFAULT NULL AFTER date_of_birth;
