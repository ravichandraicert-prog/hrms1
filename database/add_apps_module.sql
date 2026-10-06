-- Add apps module to modules table
INSERT INTO modules (name, display_name, description, is_active)
VALUES ('apps', 'Apps', 'User application shortcuts', 1)
ON DUPLICATE KEY UPDATE display_name = 'Apps', description = 'User application shortcuts', is_active = 1;

-- Grant apps permissions to all existing roles
-- All users can manage their own apps (create, read, update, delete)
INSERT INTO permissions (role_id, module_id, action, allowed, scope)
SELECT r.id, m.id, 'read', 1, 'self'
FROM roles r, modules m
WHERE m.name = 'apps'
ON DUPLICATE KEY UPDATE allowed = 1, scope = 'self';

INSERT INTO permissions (role_id, module_id, action, allowed, scope)
SELECT r.id, m.id, 'create', 1, 'self'
FROM roles r, modules m
WHERE m.name = 'apps'
ON DUPLICATE KEY UPDATE allowed = 1, scope = 'self';

INSERT INTO permissions (role_id, module_id, action, allowed, scope)
SELECT r.id, m.id, 'update', 1, 'self'
FROM roles r, modules m
WHERE m.name = 'apps'
ON DUPLICATE KEY UPDATE allowed = 1, scope = 'self';

INSERT INTO permissions (role_id, module_id, action, allowed, scope)
SELECT r.id, m.id, 'delete', 1, 'self'
FROM roles r, modules m
WHERE m.name = 'apps'
ON DUPLICATE KEY UPDATE allowed = 1, scope = 'self';
