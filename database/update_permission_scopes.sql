-- Update permission scopes for hierarchy-based visibility
-- Run these SQL queries to update the permission scopes for each role

-- Senior Manager (Level 5) - Should see employees at same level and below in their department hierarchy
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'department_sub'
WHERE r.name = 'Senior Manager' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- Manager (Level 6) - Should see all employees in their department
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'department'
WHERE r.name = 'Manager' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- Team Leader (Level 7) - Should see employees at their own/equal hierarchy level and all levels below
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'team'
WHERE r.name = 'Team Leader' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- HR Manager (Level 3) - Should see all employees (HR needs full visibility)
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'all'
WHERE r.name = 'HR Manager' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- HR Executive (Level 4) - Should see all employees (HR needs full visibility)
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'all'
WHERE r.name = 'HR Executive' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- Senior Executive (Level 8) - Should see direct reports and team
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'team'
WHERE r.name = 'Senior Executive' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- Executive (Level 9) - Should see themselves only
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'self'
WHERE r.name = 'Executive' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');

-- Intern / Trainee (Level 10) - Should see themselves only
UPDATE permissions p
JOIN modules m ON p.module_id = m.id
JOIN roles r ON p.role_id = r.id
SET p.scope = 'self'
WHERE r.name = 'Intern / Trainee' 
AND p.action = 'read'
AND m.name IN ('employees', 'attendance', 'leaves', 'reports', 'tasks');
