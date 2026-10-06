<?php
/**
 * Add Employee Salary Overrides - Idempotent Database Migration
 * Run:  C:\xampp\php\php.exe database/add_employee_salary_overrides.php
 * Safe to run multiple times. Adds an employee-specific salary override table
 * so individual employees on a shared salary structure can have customized
 * component amounts WITHOUT changing the shared structure template.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$out = [];
$fail = 0;

function tblExists2(Database $db, $table) {
    try {
        $db->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if (!tblExists2($db, 'employee_salary_overrides')) {
    try {
        $db->query(
            "CREATE TABLE `employee_salary_overrides` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `employee_id` int(11) NOT NULL,
                `salary_structure_id` int(11) NOT NULL,
                `salary_component_id` int(11) NOT NULL,
                `monthly_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'fixed monthly amount for this employee + component',
                `is_active` tinyint(1) DEFAULT 1,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_emp_struct_component` (`employee_id`, `salary_structure_id`, `salary_component_id`),
                KEY `employee_id` (`employee_id`),
                KEY `salary_structure_id` (`salary_structure_id`),
                KEY `salary_component_id` (`salary_component_id`),
                CONSTRAINT `employee_salary_overrides_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
                CONSTRAINT `employee_salary_overrides_ibfk_2` FOREIGN KEY (`salary_structure_id`) REFERENCES `salary_structures` (`id`) ON DELETE CASCADE,
                CONSTRAINT `employee_salary_overrides_ibfk_3` FOREIGN KEY (`salary_component_id`) REFERENCES `salary_components` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $out[] = "CREATED employee_salary_overrides";
    } catch (Exception $e) {
        $fail++;
        $out[] = "FAIL employee_salary_overrides: " . $e->getMessage();
    }
} else {
    $out[] = "ok employee_salary_overrides (already exists)";
}

echo "=== Employee Salary Overrides Migration ===" . PHP_EOL;
foreach ($out as $o) echo "  $o" . PHP_EOL;
echo ($fail === 0 ? "ALL DONE - no failures" : "FAILURES: $fail") . PHP_EOL;
