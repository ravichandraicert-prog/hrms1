-- Diagnostic script to check current state of Harbinder Singh Soni (EMP7494)

USE orbit_hrms;

-- Check if employee exists
SELECT '=== Employee Records ===' as info;
SELECT id, employee_id, first_name, last_name, official_email 
FROM employees WHERE employee_id = 'EMP7494';

-- Check all employees with similar names
SELECT '=== Similar Employee Records ===' as info;
SELECT id, employee_id, first_name, last_name, official_email 
FROM employees WHERE first_name LIKE '%Harbinder%' OR last_name LIKE '%Soni%';

-- Check current department assignments
SELECT '=== Current Department Assignments ===' as info;
SELECT ed.id, ed.employee_id, ed.department_id, ed.is_primary, d.name as department_name
FROM employee_departments ed
LEFT JOIN departments d ON ed.department_id = d.id
WHERE ed.employee_id IN (SELECT id FROM employees WHERE employee_id = 'EMP7494');

-- Check available departments
SELECT '=== Available Departments ===' as info;
SELECT id, name FROM departments WHERE name LIKE '%Marketing%' OR name LIKE '%IT%' OR name LIKE '%Information Technology%';
