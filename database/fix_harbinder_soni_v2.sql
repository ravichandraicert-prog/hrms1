-- Fix Harbinder Singh Soni (EMP7494) duplicate records
-- Set IT as PRIMARY department
-- Set Marketing as SECONDARY department

USE orbit_hrms;

-- First, check if the employee exists
SELECT 'Checking if employee EMP7494 exists:' as info;
SELECT id, employee_id, first_name, last_name, official_email 
FROM employees WHERE employee_id = 'EMP7494';

-- Get the department IDs manually (you may need to check your actual department IDs)
SELECT 'Department IDs - please note these for the next steps:' as info;
SELECT id, name FROM departments WHERE name LIKE '%Marketing%' OR name LIKE '%IT%' OR name LIKE '%Information Technology%';

-- Find the employee record to keep (lowest ID)
SELECT 'Employee records for EMP7494 - we will keep the one with lowest ID:' as info;
SELECT id, employee_id, first_name, last_name 
FROM employees WHERE employee_id = 'EMP7494' 
ORDER BY id ASC;

-- IMPORTANT: Replace X below with the actual employee database ID you want to keep
-- Run this section one at a time and replace X with the actual ID from the query above

-- Step 1: Delete duplicate employee records (replace X with the ID you want to KEEP)
-- DELETE FROM employees WHERE employee_id = 'EMP7494' AND id != X;

-- Step 2: Delete all existing department assignments for the kept employee (replace X)
-- DELETE FROM employee_departments WHERE employee_id = X;

-- Step 3: Set IT as PRIMARY department (replace X with employee ID, Y with IT department ID)
-- INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
-- VALUES (X, Y, 1, NOW());

-- Step 4: Set Marketing as SECONDARY department (replace X with employee ID, Z with Marketing department ID)
-- INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
-- VALUES (X, Z, 0, NOW());

-- Step 5: Verification (replace X with employee ID)
-- SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
-- FROM employee_departments ed
-- LEFT JOIN departments d ON ed.department_id = d.id
-- WHERE ed.employee_id = X;
