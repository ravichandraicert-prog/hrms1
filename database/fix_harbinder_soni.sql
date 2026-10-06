-- Fix Harbinder Singh Soni (EMP7494) duplicate records
-- Set IT as PRIMARY department
-- Set Marketing as SECONDARY department

USE orbit_hrms;

-- First, let's see the current state
SELECT 'Current employee records for EMP7494:' as info;
SELECT id, employee_id, first_name, last_name, official_email FROM employees WHERE employee_id = 'EMP7494';

SELECT 'Current department assignments for EMP7494:' as info;
SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
FROM employee_departments ed
LEFT JOIN departments d ON ed.department_id = d.id
WHERE ed.employee_id IN (SELECT id FROM employees WHERE employee_id = 'EMP7494');

-- Get the department IDs (you may need to adjust these based on your actual department IDs)
SET @marketing_dept_id = (SELECT id FROM departments WHERE name LIKE '%Marketing%' LIMIT 1);
SET @it_dept_id = (SELECT id FROM departments WHERE name LIKE '%IT%' OR name LIKE '%Information Technology%' LIMIT 1);

SELECT 'Marketing Dept ID:' as info, @marketing_dept_id;
SELECT 'IT Dept ID:' as info, @it_dept_id;

-- Keep only the first employee record (lowest ID) and delete duplicates
SET @primary_emp_id = (SELECT MIN(id) FROM employees WHERE employee_id = 'EMP7494');

SELECT 'Primary employee DB ID to keep:' as info, @primary_emp_id;

-- Delete duplicate employee records (keep the one with lowest ID)
DELETE FROM employees WHERE employee_id = 'EMP7494' AND id != @primary_emp_id;

-- Delete all existing department assignments for the kept employee
DELETE FROM employee_departments WHERE employee_id = @primary_emp_id;

-- Set IT as PRIMARY department
INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
VALUES (@primary_emp_id, @it_dept_id, 1, NOW());

-- Set Marketing as SECONDARY department
INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
VALUES (@primary_emp_id, @marketing_dept_id, 0, NOW());

-- Verification
SELECT 'Verification - Final state:' as info;
SELECT e.id, e.employee_id, e.first_name, e.last_name, e.official_email
FROM employees e WHERE e.employee_id = 'EMP7494';

SELECT 'Final department assignments:' as info;
SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
FROM employee_departments ed
LEFT JOIN departments d ON ed.department_id = d.id
WHERE ed.employee_id = @primary_emp_id;
