<?php
/**
 * Payroll Redesign - Idempotent Database Migration Runner
 * Run:  C:\xampp\php\php.exe database/run_payroll_redesign.php
 * Safe to run multiple times. Adds supporting columns/tables for the
 * redesigned payroll module without destroying existing data.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$out = [];
$fail = 0;

function colExists(Database $db, $table, $col) {
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM `$table` LIKE '{$col}'");
        return count($rows) > 0;
    } catch (Exception $e) { return false; }
}
function tblExists(Database $db, $table) {
    try { $db->fetchOne("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}
function addCol(Database $db, $table, $col, $def) {
    if (!colExists($db, $table, $col)) {
        try { $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $def"); return "ADDED $table.$col"; }
        catch (Exception $e) { global $fail; $fail++; return "FAIL $table.$col: " . $e->getMessage(); }
    }
    return "ok $table.$col";
}

// ---- salary_components: add flexible component fields ----
foreach ([
    'code' => "varchar(50) DEFAULT NULL AFTER `name`",
    'percentage' => "decimal(8,2) DEFAULT 0.00 AFTER `default_value`",
    'percentage_based_on' => "varchar(100) DEFAULT NULL COMMENT 'ctc, gross, or salary_component code' AFTER `percentage`",
    'frequency' => "varchar(20) DEFAULT 'monthly' COMMENT 'monthly, annual' AFTER `percentage_based_on`",
    'display_order' => "int(11) DEFAULT 0 AFTER `frequency`",
] as $col => $def) {
    $out[] = addCol($db, 'salary_components', $col, $def);
}

// ---- salary_structures: add annual CTC ----
$out[] = addCol($db, 'salary_structures', 'annual_ctc', "decimal(15,2) DEFAULT 0.00 AFTER `description`");
// ---- payroll_runs: versioning / audit reference ----
$out[] = addCol($db, 'payroll_runs', 'version', "int(11) DEFAULT 1 AFTER `payroll_period_id`");
$out[] = addCol($db, 'payroll_runs', 'parent_run_id', "int(11) DEFAULT NULL AFTER `version`");
$out[] = addCol($db, 'payroll_runs', 'regenerate_reason', "text AFTER `parent_run_id`");
$out[] = addCol($db, 'payroll_runs', 'pay_cycle_id', "int(11) DEFAULT NULL AFTER `period_end`");
$out[] = addCol($db, 'payroll_runs', 'payment_status', "varchar(20) DEFAULT 'Unpaid' AFTER `status`");

// ---- payroll_line_items: historical snapshots + attendance detail ----
foreach ([
    'salary_structure_id' => "int(11) DEFAULT NULL AFTER `employee_id`",
    'pay_cycle_id' => "int(11) DEFAULT NULL AFTER `salary_structure_id`",
    'salary_snapshot' => "text AFTER `pay_cycle_id`",
    'attendance_snapshot' => "text AFTER `salary_snapshot`",
    'expected_working_days' => "decimal(5,1) DEFAULT 0 AFTER `unpaid_days`",
    'present_days' => "decimal(5,1) DEFAULT 0 AFTER `expected_working_days`",
    'half_days' => "decimal(5,1) DEFAULT 0 AFTER `present_days`",
    'leave_days' => "decimal(5,1) DEFAULT 0 AFTER `half_days`",
    'lop_days' => "decimal(5,1) DEFAULT 0 AFTER `leave_days`",
    'has_conflict' => "tinyint(1) DEFAULT 0 AFTER `lop_days`",
    'conflicts' => "text AFTER `has_conflict`",
] as $col => $def) {
    $out[] = addCol($db, 'payroll_line_items', $col, $def);
}

// ---- employee_salary_structures: add CTC + active + assigned by ----
$out[] = addCol($db, 'employee_salary_structures', 'annual_ctc', "decimal(15,2) DEFAULT 0.00 AFTER `effective_date`");
$out[] = addCol($db, 'employee_salary_structures', 'is_active', "tinyint(1) DEFAULT 1 AFTER `annual_ctc`");
$out[] = addCol($db, 'employee_salary_structures', 'assigned_by', "int(11) DEFAULT NULL AFTER `is_active`");
$out[] = addCol($db, 'employee_salary_structures', 'created_at', "datetime DEFAULT CURRENT_TIMESTAMP");
// ---- payslips table ----
if (!tblExists($db, 'payslips')) {
    try {
        $db->query("CREATE TABLE `payslips` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_id` int(11) DEFAULT NULL,
            `payroll_line_item_id` int(11) NOT NULL,
            `employee_id` int(11) NOT NULL,
            `period_start` date DEFAULT NULL,
            `period_end` date DEFAULT NULL,
            `net_salary` decimal(15,2) DEFAULT 0.00,
            `gross_salary` decimal(15,2) DEFAULT 0.00,
            `total_deductions` decimal(15,2) DEFAULT 0.00,
            `structure_name` varchar(100) DEFAULT NULL,
            `snapshot` text,
            `generated_by` int(11) DEFAULT NULL,
            `generated_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `payment_status` varchar(20) DEFAULT 'Unpaid',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_payslip_line` (`payroll_line_item_id`),
            KEY `employee_id` (`employee_id`),
            KEY `payroll_run_id` (`payroll_run_id`),
            CONSTRAINT `payslips_ibfk_1` FOREIGN KEY (`payroll_line_item_id`) REFERENCES `payroll_line_items` (`id`) ON DELETE CASCADE,
            CONSTRAINT `payslips_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $out[] = "CREATED payslips";
    } catch (Exception $e) { $fail++; $out[] = "FAIL payslips: " . $e->getMessage(); }
} else {
    $out[] = "ok payslips (exists)";
}

// ---- payroll_versions table (audit trail per line item/run) ----
if (!tblExists($db, 'payroll_versions')) {
    try {
        $db->query("CREATE TABLE `payroll_versions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_id` int(11) NOT NULL,
            `line_item_id` int(11) DEFAULT NULL,
            `employee_id` int(11) DEFAULT NULL,
            `version` int(11) NOT NULL DEFAULT 1,
            `action` varchar(50) DEFAULT 'generated' COMMENT 'generated, regenerated, marked_paid, payslip_generated',
            `reason` text,
            `net_salary` decimal(15,2) DEFAULT 0.00,
            `snapshot` text,
            `changed_by` int(11) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `payroll_run_id` (`payroll_run_id`),
            KEY `employee_id` (`employee_id`),
            CONSTRAINT `payroll_versions_ibfk_1` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
            CONSTRAINT `payroll_versions_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $out[] = "CREATED payroll_versions";
    } catch (Exception $e) { $fail++; $out[] = "FAIL payroll_versions: " . $e->getMessage(); }
} else {
    // Ensure employee_id is nullable (for run-level status audit entries)
    try {
        $db->query("ALTER TABLE `payroll_versions` MODIFY `employee_id` int(11) DEFAULT NULL");
        $out[] = "ok payroll_versions.employee_id nullable";
    } catch (Exception $e) { $out[] = "ok payroll_versions.employee_id (unchanged)"; }
}

// ---- Seed standard salary components (idempotent) ----
$seedComponents = [
    ['Basic Salary', 'BASIC', 'earning', 'percentage', 'ctc',  40.00, 0, 'monthly', 1, 10],
    ['HRA', 'HRA', 'earning', 'percentage', 'BASIC', 40.00, 0, 'monthly', 0, 20],
    ['Conveyance Allowance', 'CONV', 'earning', 'fixed', null, 1600.00, 0, 'monthly', 0, 30],
    ['Medical Allowance', 'MED', 'earning', 'fixed', null, 1250.00, 0, 'monthly', 0, 40],
    ['Special Allowance', 'SPL', 'earning', 'formula', 'balancing', 0.00, 0, 'monthly', 0, 50],
    ['Other Allowances', 'OTH_ALLOW', 'earning', 'fixed', null, 0.00, 0, 'monthly', 0, 60],
    ['Employee PF', 'PF', 'deduction', 'percentage', 'BASIC', 12.00, 0, 'monthly', 0, 70],
    ['Employee ESI', 'ESI', 'deduction', 'percentage', 'GROSS', 0.75, 0, 'monthly', 0, 80],
    ['Professional Tax', 'PT', 'deduction', 'fixed', null, 200.00, 0, 'monthly', 0, 90],
    ['Other Deductions', 'OTH_DED', 'deduction', 'fixed', null, 0.00, 0, 'monthly', 0, 100],
];
try {
    $existingCount = $db->fetchOne("SELECT COUNT(*) as c FROM salary_components");
    if (($existingCount['c'] ?? 0) === 0) {
        foreach ($seedComponents as $sc) {
            $db->insert('salary_components', [
                'name' => $sc[0], 'code' => $sc[1], 'type' => $sc[2],
                'calculation_method' => $sc[3], 'percentage_based_on' => $sc[4],
                'percentage' => $sc[5], 'default_value' => $sc[6],
                'frequency' => $sc[7], 'is_taxable' => $sc[8], 'display_order' => $sc[9], 'is_active' => 1,
            ]);
        }
        $out[] = "SEEDED salary_components (" . count($seedComponents) . " default components)";
    } else {
        $out[] = "ok salary_components (already populated)";
    }
} catch (Exception $e) { $fail++; $out[] = "FAIL seed salary_components: " . $e->getMessage(); }

// ---- Indexes where useful (guard against duplicate) ----
try {
    $idxExists = $db->fetchOne("SELECT COUNT(*) as c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payroll_line_items' AND index_name = 'idx_pline_run'");
    if (($idxExists['c'] ?? 0) === 0) {
        $db->query("CREATE INDEX idx_pline_run ON payroll_line_items(payroll_run_id)");
        $out[] = "CREATED index idx_pline_run";
    } else {
        $out[] = "ok index idx_pline_run";
    }
} catch (Exception $e) { $out[] = "ok index idx_pline_run (skipped)"; }

echo "=== Payroll Redesign Migration ===" . PHP_EOL;
foreach ($out as $o) echo "  $o" . PHP_EOL;
echo ($fail === 0 ? "ALL DONE - no failures" : "FAILURES: $fail") . PHP_EOL;