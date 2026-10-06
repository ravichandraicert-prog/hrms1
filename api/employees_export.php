<?php
/**
 * Employees CSV Export API
 * Streams employee data as a CSV download
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

$auth = new Auth();
$auth->requireLogin();
$user = getCurrentUser();
$db = Database::getInstance();

if (!hasPermission('employees', 'export') && !hasPermission('employees', 'read')) {
    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$scopedIds = (new Permissions())->getScopedEmployeeIds($user['id'], 'employees', 'read');
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// Apply the same filters as the employees module
$filters = [
    'department' => $_GET['department'] ?? '',
    'role' => $_GET['role'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? ''
];
$where = ["e.id IN ({$scopedIdsStr})"];
$params = [];
if (!empty($filters['department'])) {
    $where[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
    $params[] = $filters['department'];
}
if (!empty($filters['role'])) { $where[] = "e.role_id = ?"; $params[] = $filters['role']; }
if ($filters['status'] === 'active') $where[] = "e.is_active = 1";
elseif ($filters['status'] === 'inactive') $where[] = "e.is_active = 0";
elseif ($filters['status'] === 'blacklisted') $where[] = "e.is_blacklisted = 1";
if (!empty($filters['search'])) {
    $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.official_email LIKE ?)";
    $t = '%' . $filters['search'] . '%';
    array_push($params, $t, $t, $t, $t);
}

$employees = $db->fetchAll(
    "SELECT e.employee_id, e.first_name, e.last_name, e.official_email, e.phone_number, e.position,
            e.date_of_joining, e.employment_type, e.is_active, r.name as role_name,
            (SELECT name FROM departments WHERE id = (SELECT department_id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1)) as department_name,
            (SELECT CONCAT(m.first_name, ' ', m.last_name) FROM employees m WHERE m.id = e.reporting_manager_id) as reporting_manager
     FROM employees e
     LEFT JOIN roles r ON e.role_id = r.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY e.first_name, e.last_name",
    $params
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="employees_' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Employee ID', 'First Name', 'Last Name', 'Official Email', 'Phone', 'Position', 'Department', 'Role', 'Reporting Manager', 'Date of Joining', 'Employment Type', 'Status']);
foreach ($employees as $e) {
    fputcsv($out, [
        $e['employee_id'], $e['first_name'], $e['last_name'], $e['official_email'], $e['phone_number'],
        $e['position'], $e['department_name'], $e['role_name'], $e['reporting_manager'],
        $e['date_of_joining'], $e['employment_type'],
        $e['is_active'] ? 'Active' : 'Inactive'
    ]);
}
fclose($out);
exit;