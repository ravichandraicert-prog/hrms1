-- Fix secondary department functionality
-- This script ensures the employee_departments table is properly configured

USE orbit_hrms;

-- Ensure the table has the correct structure
-- Check if the table exists and has the correct columns
DESCRIBE employee_departments;

-- Update any existing records to ensure proper is_primary values
-- Make sure each employee has exactly one primary department
UPDATE employee_departments ed1
LEFT JOIN (
    SELECT employee_id, MIN(id) as min_id
    FROM employee_departments
    WHERE is_primary = 1
    GROUP BY employee_id
) ed2 ON ed1.employee_id = ed2.employee_id AND ed1.id = ed2.min_id
SET ed1.is_primary = 1
WHERE ed2.min_id IS NOT NULL;

-- For employees with no primary department, set the first department as primary
UPDATE employee_departments ed1
SET ed1.is_primary = 1
WHERE ed1.id = (
    SELECT MIN(id)
    FROM employee_departments ed2
    WHERE ed2.employee_id = ed1.employee_id
)
AND NOT EXISTS (
    SELECT 1
    FROM employee_departments ed3
    WHERE ed3.employee_id = ed1.employee_id
    AND ed3.is_primary = 1
);

-- Set all other departments to secondary (is_primary = 0)
UPDATE employee_departments ed1
SET ed1.is_primary = 0
WHERE ed1.id NOT IN (
    SELECT ed2.id
    FROM employee_departments ed2
    WHERE ed2.is_primary = 1
);

-- Verify the fix
SELECT 
    e.employee_id,
    e.first_name,
    e.last_name,
    COUNT(CASE WHEN ed.is_primary = 1 THEN 1 END) as primary_count,
    COUNT(CASE WHEN ed.is_primary = 0 THEN 1 END) as secondary_count,
    GROUP_CONCAT(CASE WHEN ed.is_primary = 1 THEN d.name END) as primary_dept,
    GROUP_CONCAT(CASE WHEN ed.is_primary = 0 THEN d.name END) as secondary_depts
FROM employees e
LEFT JOIN employee_departments ed ON e.id = ed.employee_id
LEFT JOIN departments d ON ed.department_id = d.id
GROUP BY e.id
HAVING primary_count != 1 OR secondary_count > 0;
