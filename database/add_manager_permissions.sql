-- Add Manager role permissions for leave approval
-- Run this SQL script to add the necessary permissions for Manager role to approve/reject leaves

-- Get Manager role ID and leaves module ID
SET @manager_role_id = (SELECT id FROM roles WHERE name = 'Manager' AND is_active = 1);
SET @leaves_module_id = (SELECT id FROM modules WHERE name = 'leaves');

-- Add or update approve permission for Manager role
INSERT INTO permissions (role_id, module_id, action, scope, allowed)
VALUES (@manager_role_id, @leaves_module_id, 'approve', 'department', 1)
ON DUPLICATE KEY UPDATE scope = 'department', allowed = 1;

-- Add or update read permission for Manager role
INSERT INTO permissions (role_id, module_id, action, scope, allowed)
VALUES (@manager_role_id, @leaves_module_id, 'read', 'department', 1)
ON DUPLICATE KEY UPDATE scope = 'department', allowed = 1;

-- Add or update create permission for Manager role (to apply for leaves)
INSERT INTO permissions (role_id, module_id, action, scope, allowed)
VALUES (@manager_role_id, @leaves_module_id, 'create', 'self', 1)
ON DUPLICATE KEY UPDATE scope = 'self', allowed = 1;

-- Add or update update permission for Manager role (to cancel their own leaves)
INSERT INTO permissions (role_id, module_id, action, scope, allowed)
VALUES (@manager_role_id, @leaves_module_id, 'update', 'self', 1)
ON DUPLICATE KEY UPDATE scope = 'self', allowed = 1;

-- Verify the permissions were added
SELECT * FROM permissions WHERE role_id = @manager_role_id AND module_id = @leaves_module_id;
