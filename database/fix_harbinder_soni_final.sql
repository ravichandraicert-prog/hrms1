-- Fix Harbinder Singh Soni (EMP9074) - Database ID: 81
-- Set IT (ID: 5) as PRIMARY department
-- Set Marketing (ID: 1) as SECONDARY department

USE orbit_hrms;

-- Check current state first
SELECT '=== Current Employee Record ===' as info;
SELECT id, employee_id, first_name, last_name, official_email 
FROM employees WHERE employee_id = 'EMP9074';

SELECT '=== Current Department Assignments ===' as info;
SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
FROM employee_departments ed
LEFT JOIN departments d ON ed.department_id = d.id
WHERE ed.employee_id = 81;

-- Step 1: Delete all existing department assignments for employee ID 81
DELETE FROM employee_departments WHERE employee_id = 81;

SELECT '=== Cleared existing department assignments ===' as info;

-- Step 2: Set IT (ID: 5) as PRIMARY department
INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
VALUES (81, 5, 1, NOW());

SELECT '=== Set IT as PRIMARY department ===' as info;

-- Step 3: Set Marketing (ID: 1) as SECONDARY department
INSERT INTO employee_departments (employee_id, department_id, is_primary, created_at)
VALUES (81, 1, 0, NOW());

SELECT '=== Set Marketing as SECONDARY department ===' as info;

-- Verification
SELECT '=== Final Verification ===' as info;
SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
FROM employee_departments ed
LEFT JOIN departments d ON ed.department_id = d.id
WHERE ed.employee_id = 81;

SELECT '=== Complete ===' as info;
