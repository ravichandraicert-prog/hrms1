<?php
/**
 * Add Manager role permissions for leave approval
 * This script adds the necessary permissions for Manager role to approve/reject leaves
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "Adding Manager role permissions for leave approval...\n";

// Get the Manager role ID
$managerRole = $db->fetchOne("SELECT id FROM roles WHERE name = 'Manager' AND is_active = 1");
if (!$managerRole) {
    echo "Error: Manager role not found!\n";
    exit(1);
}

echo "Manager role ID: " . $managerRole['id'] . "\n";

// Get the leaves module ID
$leavesModule = $db->fetchOne("SELECT id FROM modules WHERE name = 'leaves'");
if (!$leavesModule) {
    echo "Error: Leaves module not found!\n";
    exit(1);
}

echo "Leaves module ID: " . $leavesModule['id'] . "\n";

// Check if Manager already has approve permission
$existing = $db->fetchOne(
    "SELECT * FROM permissions WHERE role_id = ? AND module_id = ? AND action = 'approve'",
    [$managerRole['id'], $leavesModule['id']]
);

if ($existing) {
    echo "Manager already has approve permission. Updating scope to 'department'...\n";
    $db->update('permissions', 
        ['scope' => 'department', 'allowed' => 1], 
        'id = ?', 
        [$existing['id']]
    );
} else {
    echo "Adding approve permission for Manager role...\n";
    $db->insert('permissions', [
        'role_id' => $managerRole['id'],
        'module_id' => $leavesModule['id'],
        'action' => 'approve',
        'scope' => 'department',
        'allowed' => 1
    ]);
}

// Check if Manager has read permission for leaves
$readPermission = $db->fetchOne(
    "SELECT * FROM permissions WHERE role_id = ? AND module_id = ? AND action = 'read'",
    [$managerRole['id'], $leavesModule['id']]
);

if (!$readPermission) {
    echo "Adding read permission for Manager role...\n";
    $db->insert('permissions', [
        'role_id' => $managerRole['id'],
        'module_id' => $leavesModule['id'],
        'action' => 'read',
        'scope' => 'department',
        'allowed' => 1
    ]);
} else {
    echo "Manager already has read permission. Updating scope to 'department'...\n";
    $db->update('permissions', 
        ['scope' => 'department', 'allowed' => 1], 
        'id = ?', 
        [$readPermission['id']]
    );
}

echo "\nPermissions added successfully!\n";
echo "Manager role can now:\n";
echo "- View leaves module (read permission)\n";
echo "- Approve/reject leave requests (approve permission)\n";
echo "- Scope is set to 'department' (can see leaves from their department)\n";
