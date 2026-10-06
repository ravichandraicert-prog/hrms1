<?php
/**
 * Payroll API - Redesigned
 * Core PHP HRMS Application
 *
 * Centralized endpoints for Salary Components, Salary Structures,
 * Assignments, Payroll Preview/Generate/Regenerate, History,
 * Payslips and status workflow. All calculations go through
 * PayrollService (includes/payroll_service.php).
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/permissions.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/payroll_service.php';

    $db = Database::getInstance();
    $user = getCurrentUser();
    $service = new PayrollService();
    $method = $_SERVER['REQUEST_METHOD'];

    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'multipart/form-data') !== false || !empty($_POST)) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
    }
    $action = $data['action'] ?? '';

    // ================================================================
    // GET: salary components
    // ================================================================
    if ($method === 'GET' && isset($_GET['components'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $rows = $db->fetchAll("SELECT * FROM salary_components ORDER BY type, display_order, name");
        echo json_encode(['success' => true, 'components' => $rows]);
        exit;
    }

    // ================================================================
    // GET: salary structures list (with counts)
    // ================================================================
    if ($method === 'GET' && isset($_GET['structures'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $rows = $db->fetchAll(
            "SELECT ss.id, ss.name, ss.description, ss.annual_ctc, ss.is_active,
                    (SELECT COUNT(*) FROM salary_structure_components ssc WHERE ssc.salary_structure_id = ss.id) as component_count,
                    (SELECT COUNT(*) FROM employee_salary_structures ess WHERE ess.salary_structure_id = ss.id AND ess.is_active = 1) as assignment_count
             FROM salary_structures ss ORDER BY ss.name"
        );
        echo json_encode(['success' => true, 'structures' => $rows]);
        exit;
    }

    // ================================================================
    // GET: employees currently assigned to a salary structure
    // ================================================================
    if ($method === 'GET' && isset($_GET['structure_employees'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $structureId = (int) ($_GET['structure_id'] ?? 0);
        if (!$structureId) { echo json_encode(['success' => false, 'message' => 'structure_id required']); exit; }
        $struct = $db->fetchOne("SELECT id, name FROM salary_structures WHERE id = ?", [$structureId]);
        if (!$struct) { echo json_encode(['success' => false, 'message' => 'Salary structure not found']); exit; }
        $employees = $db->fetchAll(
            "SELECT ess.id AS assignment_id, ess.employee_id, ess.annual_ctc, ess.effective_date, ess.is_active,
                    e.first_name, e.last_name, e.employee_id AS emp_code, e.position,
                    d.name AS department_name, r.name AS role_name,
                    (SELECT COUNT(*) FROM employee_salary_overrides eso
                     WHERE eso.employee_id = ess.employee_id
                       AND eso.salary_structure_id = ess.salary_structure_id
                       AND eso.is_active = 1) as has_override
             FROM employee_salary_structures ess
             JOIN employees e ON ess.employee_id = e.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             LEFT JOIN departments d ON ed.department_id = d.id
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE ess.salary_structure_id = ? AND ess.is_active = 1
             ORDER BY e.first_name, e.last_name",
            [$structureId]
        );
        echo json_encode(['success' => true, 'structure' => $struct, 'employees' => $employees]);
        exit;
    }

    // ================================================================
    // GET: one employee's salary components for a structure, showing the
    // structure default vs any employee-specific override.
    // ================================================================
    if ($method === 'GET' && isset($_GET['employee_components'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $structureId = (int) ($_GET['structure_id'] ?? 0);
        $employeeId = (int) ($_GET['employee_id'] ?? 0);
        if (!$structureId || !$employeeId) { echo json_encode(['success' => false, 'message' => 'structure_id and employee_id required']); exit; }

        $assignment = $db->fetchOne(
            "SELECT ess.id AS assignment_id, ess.employee_id, ess.salary_structure_id, ess.annual_ctc, ess.effective_date,
                    e.first_name, e.last_name, e.employee_id AS emp_code, e.position,
                    d.name AS department_name, r.name AS role_name,
                    ss.name AS structure_name
             FROM employee_salary_structures ess
             JOIN employees e ON ess.employee_id = e.id
             JOIN salary_structures ss ON ess.salary_structure_id = ss.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             LEFT JOIN departments d ON ed.department_id = d.id
             LEFT JOIN roles r ON e.role_id = r.id
             WHERE ess.salary_structure_id = ? AND ess.employee_id = ? AND ess.is_active = 1",
            [$structureId, $employeeId]
        );
        if (!$assignment) {
            echo json_encode(['success' => false, 'message' => 'No active salary structure assignment found for this employee.']);
            exit;
        }

        $overrides = $service->getEmployeeOverrides($employeeId, $structureId);
        $default = $service->computeComponents($structureId, (float) $assignment['annual_ctc']);
        $withOverride = $service->computeComponents($structureId, (float) $assignment['annual_ctc'], $overrides);

        // Per-component: structure default amount (this employee's CTC) + override value
        $components = [];
        foreach (array_merge($default['earnings'], $default['deductions']) as $d) {
            $cid = (int) $d['component_id'];
            $components[] = [
                'salary_component_id' => $cid,
                'name' => $d['name'],
                'code' => $d['code'],
                'type' => $d['type'],
                'structure_monthly' => round((float) $d['amount'], 2),
                'employee_monthly' => isset($overrides[$cid]) ? round((float) $overrides[$cid], 2) : null,
            ];
        }
        // Keep component ordering: earnings first, then deductions (already the
        // order returned by computeComponents).

        echo json_encode([
            'success' => true,
            'assignment' => $assignment,
            'components' => $components,
            'computed_default' => [
                'gross' => (float) $default['gross'],
                'total_deductions' => (float) $default['total_deductions'],
                'net' => (float) $default['net'],
            ],
            'computed_override' => [
                'gross' => (float) $withOverride['gross'],
                'total_deductions' => (float) $withOverride['total_deductions'],
                'net' => (float) $withOverride['net'],
            ],
            'has_override' => count($overrides) > 0,
        ]);
        exit;
    }
    if ($method === 'GET' && isset($_GET['structure_id'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $struct = $db->fetchOne("SELECT * FROM salary_structures WHERE id = ?", [(int) $_GET['structure_id']]);
        if (!$struct) { echo json_encode(['success' => false, 'message' => 'Structure not found']); exit; }
        $components = $db->fetchAll(
            "SELECT ssc.id as pivot_id, ssc.salary_component_id, ssc.value,
                    sc.name, sc.code, sc.type, sc.calculation_method, sc.percentage_based_on, sc.frequency,
                    sc.default_value, sc.is_taxable, sc.display_order
             FROM salary_structure_components ssc
             JOIN salary_components sc ON ssc.salary_component_id = sc.id
             WHERE ssc.salary_structure_id = ?
             ORDER BY sc.display_order, sc.name", [$struct['id']]
        );
        $struct['components'] = $components;
        try {
            $struct['computed'] = $service->computeComponents($struct['id'], (float) ($struct['annual_ctc'] ?? 0));
        } catch (Exception $e) { $struct['computed'] = null; }
        echo json_encode(['success' => true, 'structure' => $struct]);
        exit;
    }

    // ================================================================
    // GET: employee salary assignment history (spec #5)
    // With employee_id => that employee's full history.
    // Without employee_id => ALL assignment records (global history) so the
    // Salary Structure tab's history panel can list every employee/structure.
    // ================================================================
    if ($method === 'GET' && isset($_GET['employee_structures'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $empId = (int) ($_GET['employee_id'] ?? 0);
        $base = "SELECT ess.id, ess.employee_id, ess.salary_structure_id, ess.annual_ctc, ess.effective_date, ess.is_active,
                        ss.name as structure_name,
                        e.first_name, e.last_name, e.employee_id as emp_code,
                        d.name as department_name, r.name as role_name
                 FROM employee_salary_structures ess
                 JOIN salary_structures ss ON ess.salary_structure_id = ss.id
                 JOIN employees e ON ess.employee_id = e.id
                 LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
                 LEFT JOIN departments d ON ed.department_id = d.id
                 LEFT JOIN roles r ON e.role_id = r.id";
        if ($empId) {
            $rows = $db->fetchAll($base . " WHERE ess.employee_id = ? ORDER BY ess.effective_date DESC", [$empId]);
        } else {
            $rows = $db->fetchAll($base . " ORDER BY e.first_name, e.last_name, ess.effective_date DESC");
        }
        echo json_encode(['success' => true, 'assignments' => $rows]);
        exit;
    }

    // ================================================================
    // GET: payroll periods derived from a pay cycle (spec #8, #9)
    // ================================================================
    if ($method === 'GET' && isset($_GET['periods'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $cycleId = (int) ($_GET['pay_cycle_id'] ?? 0);
        if (!$cycleId) { echo json_encode(['success' => false, 'message' => 'pay_cycle_id required']); exit; }
        $cycle = $db->fetchOne("SELECT * FROM pay_cycles WHERE id = ?", [$cycleId]);
        if (!$cycle) { echo json_encode(['success' => false, 'message' => 'Pay cycle not found']); exit; }
        $months = (int) ($_GET['months'] ?? 6);
        $series = $service->generatePeriodSeries($cycle, date('Y-m-d', strtotime('-1 month')), $months);
        echo json_encode(['success' => true, 'periods' => $series]);
        exit;
    }

    // ================================================================
    // GET: payroll history (spec #19)
    // ================================================================
    if ($method === 'GET' && isset($_GET['history'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $runs = $db->fetchAll(
            "SELECT pr.id, pr.name, pr.period_start, pr.period_end, pr.pay_cycle_id, pc.name as pay_cycle_name,
                    pr.status, pr.payment_status, pr.version, pr.total_employees, pr.total_payout,
                    pr.generated_at, pr.regenerate_reason,
                    e.first_name as gen_first, e.last_name as gen_last
             FROM payroll_runs pr
             LEFT JOIN pay_cycles pc ON pr.pay_cycle_id = pc.id
             LEFT JOIN employees e ON pr.generated_by = e.id
             ORDER BY pr.period_end DESC, pr.id DESC"
        );
        echo json_encode(['success' => true, 'runs' => $runs]);
        exit;
    }

    // ================================================================
    // GET: single run with line items (spec #19, #21)
    // ================================================================
    if ($method === 'GET' && isset($_GET['run_id'])) {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $run = $db->fetchOne(
            "SELECT pr.*, pc.name as pay_cycle_name,
                    e.first_name as gen_first, e.last_name as gen_last,
                    m.first_name as paid_first, m.last_name as paid_last
             FROM payroll_runs pr
             LEFT JOIN pay_cycles pc ON pr.pay_cycle_id = pc.id
             LEFT JOIN employees e ON pr.generated_by = e.id
             LEFT JOIN employees m ON pr.marked_paid_by = m.id
             WHERE pr.id = ?", [(int) $_GET['run_id']]
        );
        if (!$run) { echo json_encode(['success' => false, 'message' => 'Run not found']); exit; }
        $run['items'] = $service->getRunLineItems($run['id']);
        echo json_encode(['success' => true, 'run' => $run]);
        exit;
    }

    // ================================================================
    // GET: payslip data from a line item + version history (spec #23, #26)
    // ================================================================
    if ($method === 'GET' && isset($_GET['line_id'])) {
        $permitted = hasPermission('payroll', 'read');
        if (!$permitted) {
            // Allow employees to see ONLY their own payslip / payroll snapshot
            $own = $db->fetchOne("SELECT employee_id FROM payroll_line_items WHERE id = ?", [(int) $_GET['line_id']]);
            if (!$own || (int) $own['employee_id'] !== (int) $user['id']) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
        }
        $item = $db->fetchOne(
            "SELECT pl.*, pr.name as run_name, pr.period_start, pr.period_end, pr.status as run_status,
                    pr.payment_status, e.first_name, e.last_name, e.employee_id, e.position,
                    d.name as department_name, ss.name as structure_name
             FROM payroll_line_items pl
             JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
             JOIN employees e ON pl.employee_id = e.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             LEFT JOIN departments d ON ed.department_id = d.id
             LEFT JOIN salary_structures ss ON pl.salary_structure_id = ss.id
             WHERE pl.id = ?", [(int) $_GET['line_id']]
        );
        if (!$item) { echo json_encode(['success' => false, 'message' => 'Record not found']); exit; }
        $item['component_details'] = $db->fetchAll(
            "SELECT sc.name, sc.type, pcd.amount FROM payroll_component_details pcd
             JOIN salary_components sc ON pcd.salary_component_id = sc.id
             WHERE pcd.payroll_line_item_id = ? ORDER BY sc.type, sc.name", [$item['id']]
        );
        $item['snapshot'] = json_decode((string) $item['salary_snapshot'], true);
        $item['attendance_snapshot'] = json_decode((string) $item['attendance_snapshot'], true);
        $psRecord = $db->fetchOne("SELECT id FROM payslips WHERE payroll_line_item_id = ?", [$item['id']]);
        $item['payslip_id'] = $psRecord['id'] ?? null;
        $item['version_history'] = $service->getVersionHistory($item['id']);
        echo json_encode(['success' => true, 'item' => $item]);
        exit;
    }

    // ================================================================
    // GET: employee's own payroll records + payslips (spec #27)
    // ================================================================
    if ($method === 'GET' && isset($_GET['my_payroll'])) {
        $empId = (int) $user['id'];
        $rows = $db->fetchAll(
            "SELECT pl.id as line_item_id, pl.payroll_run_id, pl.net_salary, pl.gross_pay, pl.deductions,
                    pr.period_start, pr.period_end, pr.status, pr.payment_status,
                    (SELECT id FROM payslips ps WHERE ps.payroll_line_item_id = pl.id) as payslip_id,
                    ss.name as structure_name
             FROM payroll_line_items pl
             JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
             LEFT JOIN salary_structures ss ON pl.salary_structure_id = ss.id
             WHERE pl.employee_id = ?
             ORDER BY pr.period_end DESC", [$empId]
        );
        echo json_encode(['success' => true, 'payroll' => $rows]);
        exit;
    }

    // ================================================================
    // POST HANDLERS
    // ================================================================
    if ($method === 'POST') {

    // ---- Save salary component (spec #2) ----
    if ($action === 'save_component') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') { echo json_encode(['success' => false, 'message' => 'Component name is required']); exit; }
        $fields = [
            'name' => $name,
            'code' => strtoupper(trim((string) ($data['code'] ?? $name))),
            'type' => in_array($data['type'] ?? '', ['earning', 'deduction']) ? $data['type'] : 'earning',
            'calculation_method' => in_array($data['calculation_method'] ?? '', ['fixed', 'percentage', 'formula']) ? $data['calculation_method'] : 'fixed',
            'default_value' => (float) ($data['default_value'] ?? 0),
            'percentage' => (float) ($data['percentage'] ?? 0),
            'percentage_based_on' => trim((string) ($data['percentage_based_on'] ?? 'ctc')),
            'frequency' => ($data['frequency'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly',
            'is_taxable' => !empty($data['is_taxable']) ? 1 : 0,
            'display_order' => (int) ($data['display_order'] ?? 0),
            'is_active' => 1,
            'description' => trim((string) ($data['description'] ?? '')),
        ];
        if ($id) $db->update('salary_components', $fields, 'id = ?', [$id]);
        else $db->insert('salary_components', $fields);
        logAudit('salary_components', $id ?: $db->lastInsertId(), 'save_component', null, $fields);
        echo json_encode(['success' => true, 'message' => 'Salary component saved']);
        exit;
    }

    // ---- Save salary structure + its components (spec #1, #3, #4) ----
    if ($action === 'save_structure') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') { echo json_encode(['success' => false, 'message' => 'Structure name is required']); exit; }
        // Per-employee CTC model: the Salary Structure no longer carries a
        // required Annual CTC (each employee's CTC is stored on their
        // assignment). Kept for backward compatibility with legacy payloads.
        $hasCtc = ($data['annual_ctc'] ?? null) !== null && $data['annual_ctc'] !== '';
        $annualCtc = (float) ($data['annual_ctc'] ?? 0);
        $components = $data['components'] ?? [];

        if ($id) {
            $structFields = [
                'name' => $name, 'description' => trim((string) ($data['description'] ?? '')),
            ];
            // Only overwrite the stored structure-level CTC when the request
            // actually provides one - editing a legacy structure's components
            // must never wipe its baseline used for historical recalculation.
            if ($hasCtc) $structFields['annual_ctc'] = $annualCtc;
            $db->update('salary_structures', $structFields, 'id = ?', [$id]);
            // Replace components
            $db->delete('salary_structure_components', 'salary_structure_id = ?', [$id]);
        } else {
            $db->insert('salary_structures', [
                'name' => $name, 'description' => trim((string) ($data['description'] ?? '')), 'annual_ctc' => $annualCtc,
            ]);
            $id = (int) $db->lastInsertId();
        }

        foreach ($components as $c) {
            $compId = (int) ($c['salary_component_id'] ?? ($c['component_id'] ?? 0));
            if (!$compId) continue;
            $db->insert('salary_structure_components', [
                'salary_structure_id' => $id,
                'salary_component_id' => $compId,
                'value' => (float) ($c['value'] ?? 0),
            ]);
        }
        logAudit('salary_structures', $id, 'save_structure', null, ['name' => $name, 'components' => count($components)]);
        echo json_encode(['success' => true, 'message' => 'Salary structure saved', 'structure_id' => $id]);
        exit;
    }

    // ---- Assign salary structure to employee (spec #5, #6) ----
    if ($action === 'assign_structure') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $structureId = (int) ($data['structure_id'] ?? 0);
        $effective = $data['effective_date'] ?? date('Y-m-d');
        $ctc = (float) ($data['annual_ctc'] ?? 0);
        if (!$employeeId || !$structureId) { echo json_encode(['success' => false, 'message' => 'Employee and Structure required']); exit; }
        // Duplicate protection: never create a second ACTIVE record for the
        // same employee + structure (only one current assignment per employee).
        $dup = $db->fetchOne(
            "SELECT id FROM employee_salary_structures WHERE employee_id = ? AND salary_structure_id = ? AND is_active = 1",
            [$employeeId, $structureId]
        );
        if ($dup) {
            echo json_encode(['success' => false, 'message' => 'Employee is already assigned to this salary structure.']);
            exit;
        }
        // Deactivate any overlapping active assignment for this employee
        $db->update('employee_salary_structures',
            ['is_active' => 0],
            "employee_id = ? AND is_active = 1 AND effective_date <= ?", [$employeeId, $effective]);
        $db->insert('employee_salary_structures', [
            'employee_id' => $employeeId, 'salary_structure_id' => $structureId,
            'annual_ctc' => $ctc, 'effective_date' => $effective, 'is_active' => 1, 'assigned_by' => $user['id'],
        ]);
        $newId = (int) $db->lastInsertId();
        logAudit('employee_salary_structures', $newId, 'assign_structure', null, [
            'employee_id' => $employeeId, 'structure_id' => $structureId, 'ctc' => $ctc, 'effective' => $effective,
        ]);
        echo json_encode(['success' => true, 'message' => 'Salary structure assigned', 'assignment_id' => $newId]);
        exit;
    }

    // ---- Remove an employee from a salary structure ----
    // Deactivates ONLY the active assignment relationship (employee_salary_structures).
    // The employee record, the salary structure, and historical assignment rows
    // are all preserved so past payroll/payslips stay intact.
    if ($action === 'unassign_structure') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $structureId = (int) ($data['structure_id'] ?? 0);
        if (!$employeeId || !$structureId) { echo json_encode(['success' => false, 'message' => 'Employee and Structure required']); exit; }

        $row = $db->fetchOne(
            "SELECT ess.id, e.first_name, e.last_name, e.employee_id as emp_code, ss.name as structure_name
             FROM employee_salary_structures ess
             JOIN employees e ON ess.employee_id = e.id
             JOIN salary_structures ss ON ess.salary_structure_id = ss.id
             WHERE ess.employee_id = ? AND ess.salary_structure_id = ? AND ess.is_active = 1",
            [$employeeId, $structureId]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'No active assignment found for this employee and salary structure.']);
            exit;
        }
        $db->update('employee_salary_structures', ['is_active' => 0], 'id = ?', [$row['id']]);
        // Soft-deactivate this employee's overrides on this structure (history preserved).
        $db->update('employee_salary_overrides', ['is_active' => 0],
            "employee_id = ? AND salary_structure_id = ? AND is_active = 1", [$employeeId, $structureId]);

        $remaining = $db->fetchOne(
            "SELECT COUNT(*) as c FROM employee_salary_structures WHERE salary_structure_id = ? AND is_active = 1",
            [$structureId]
        );
        logAudit('employee_salary_structures', (int) $row['id'], 'unassign_structure', null, [
            'employee_id' => $employeeId, 'structure_id' => $structureId,
            'employee' => ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''),
        ]);
        echo json_encode([
            'success' => true,
            'message' => ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '') . ' removed from ' . ($row['structure_name'] ?? '') . '.',
            'assignment_id' => (int) $row['id'],
            'assignment_count' => (int) ($remaining['c'] ?? 0),
        ]);
        exit;
    }

    // ---- Save an employee's salary override (component customizations) ----
    // Updates ONLY this employee's assignment + override rows. The shared salary
    // structure and all other employees are never touched. Components sent with a
    // null/empty monthly amount fall back to the structure default (override
    // removed/soft-deactivated). Numeric amounts become fixed monthly overrides.
    if ($action === 'save_employee_override') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $structureId = (int) ($data['structure_id'] ?? 0);
        if (!$employeeId || !$structureId) { echo json_encode(['success' => false, 'message' => 'Employee and Structure required']); exit; }

        $assignment = $db->fetchOne(
            "SELECT ess.id, ess.employee_id, ess.salary_structure_id, ess.annual_ctc
             FROM employee_salary_structures ess
             WHERE ess.salary_structure_id = ? AND ess.employee_id = ? AND ess.is_active = 1",
            [$structureId, $employeeId]
        );
        if (!$assignment) {
            echo json_encode(['success' => false, 'message' => 'No active salary structure assignment found for this employee.']);
            exit;
        }

        // 1) Optional: update THIS employee's annual CTC only.
        if ($data['annual_ctc'] !== null && $data['annual_ctc'] !== '' && (float) $data['annual_ctc'] > 0) {
            $db->update('employee_salary_structures',
                ['annual_ctc' => (float) $data['annual_ctc']], 'id = ?', [(int) $assignment['id']]);
        }

        // 2) Component overrides: { salary_component_id: monthly_amount|null }
        $raw = $data['components'] ?? [];
        $savedIds = [];
        foreach ($raw as $comp) {
            $cid = (int) ($comp['salary_component_id'] ?? 0);
            if (!$cid) continue;
            $amtRaw = $comp['monthly_amount'] ?? null;
            if ($amtRaw === null || $amtRaw === '' || !is_numeric((float) $amtRaw) || (float) $amtRaw < 0) {
                // No override -> deactivate any existing one (fall back to structure).
                $db->update('employee_salary_overrides', ['is_active' => 0],
                    "employee_id = ? AND salary_structure_id = ? AND salary_component_id = ? AND is_active = 1",
                    [$employeeId, $structureId, $cid]);
                continue;
            }
            $amount = round((float) $amtRaw, 2);
            $existing = $db->fetchOne(
                "SELECT id FROM employee_salary_overrides
                 WHERE employee_id = ? AND salary_structure_id = ? AND salary_component_id = ?",
                [$employeeId, $structureId, $cid]
            );
            if ($existing) {
                $db->update('employee_salary_overrides',
                    ['monthly_amount' => $amount, 'is_active' => 1], 'id = ?', [(int) $existing['id']]);
            } else {
                $db->insert('employee_salary_overrides', [
                    'employee_id' => $employeeId, 'salary_structure_id' => $structureId,
                    'salary_component_id' => $cid, 'monthly_amount' => $amount, 'is_active' => 1,
                ]);
            }
            $savedIds[] = $cid;
        }

        // 3) Recompute this employee's monthly summary with the saved overrides.
        $ctc = (float) ($data['annual_ctc'] !== null && $data['annual_ctc'] !== '' ? (float) $data['annual_ctc'] : $assignment['annual_ctc']);
        $overrides = $service->getEmployeeOverrides($employeeId, $structureId);
        $computed = $service->computeComponents($structureId, $ctc, $overrides);

        logAudit('employee_salary_structures', (int) $assignment['id'], 'save_employee_override', null, [
            'employee_id' => $employeeId, 'structure_id' => $structureId,
            'annual_ctc' => $ctc, 'components' => $savedIds,
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Salary customization saved for this employee only.',
            'computed' => [
                'gross' => (float) $computed['gross'],
                'total_deductions' => (float) $computed['total_deductions'],
                'net' => (float) $computed['net'],
            ],
            'has_override' => count($overrides) > 0,
            'annual_ctc' => $ctc,
        ]);
        exit;
    }

    // ---- Delete salary structure (spec #4) ----
    if ($action === 'delete_structure') {
        // Require delete OR update permission (super admin always passes).
        if (!hasPermission('payroll', 'delete') && !hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }

        $structureId = (int) ($data['structure_id'] ?? 0);
        if (!$structureId) { echo json_encode(['success' => false, 'message' => 'Structure ID required']); exit; }

        $struct = $db->fetchOne("SELECT * FROM salary_structures WHERE id = ?", [$structureId]);
        if (!$struct) { echo json_encode(['success' => false, 'message' => 'Structure not found']); exit; }

        // Protect structures still assigned to ACTIVE employees or already used
        // by payroll history (preserve actual payroll history). Historical /
        // inactive assignment records (e.g. left behind after a reassignment)
        // no longer block deletion - the cascade FKs clean them up.
        $assigned = $db->fetchOne(
            "SELECT COUNT(*) as c FROM employee_salary_structures ess
             WHERE ess.salary_structure_id = ? AND ess.is_active = 1", [$structureId]
        );
        $payrollRef = $db->fetchOne(
            "SELECT COUNT(*) as c FROM payroll_line_items pl
             WHERE pl.salary_structure_id = ?", [$structureId]
        );
        if ((($assigned['c'] ?? 0) + ($payrollRef['c'] ?? 0)) > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete salary structure: it is assigned to active employee(s) or used by payroll history. Unassign or delete those first to protect payroll history.']);
            exit;
        }

        $db->delete('salary_structure_components', 'salary_structure_id = ?', [$structureId]);
        $db->delete('salary_structures', 'id = ?', [$structureId]);
        logAudit('salary_structures', $structureId, 'delete_structure', null, ['name' => $struct['name']]);
        echo json_encode(['success' => true, 'message' => 'Salary structure deleted']);
        exit;
    }

    // ---- Delete a salary structure assignment (unassign an employee) ----
    // Removes the assignment record and any employee-specific component
    // overrides tied to it. Assignments referenced by payroll history are
    // protected so existing payslips/reports keep their structure context.
    if ($action === 'delete_assignment') {
        if (!hasPermission('payroll', 'delete') && !hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }

        $assignmentId = (int) ($data['assignment_id'] ?? 0);
        if (!$assignmentId) { echo json_encode(['success' => false, 'message' => 'Assignment ID required']); exit; }

        $assignment = $db->fetchOne("SELECT * FROM employee_salary_structures WHERE id = ?", [$assignmentId]);
        if (!$assignment) { echo json_encode(['success' => false, 'message' => 'Assignment not found']); exit; }

        // Protect assignments already used by payroll history.
        $ref = $db->fetchOne(
            "SELECT COUNT(*) as c FROM payroll_line_items pl
             WHERE pl.employee_id = ? AND pl.salary_structure_id = ?",
            [$assignment['employee_id'], $assignment['salary_structure_id']]
        );
        if (($ref['c'] ?? 0) > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete this assignment: it is used by payroll history.']);
            exit;
        }

        // Remove employee-specific component overrides tied to this assignment.
        $db->delete('employee_salary_overrides',
            'employee_id = ? AND salary_structure_id = ?',
            [$assignment['employee_id'], $assignment['salary_structure_id']]
        );
        $db->delete('employee_salary_structures', 'id = ?', [$assignmentId]);
        logAudit('employee_salary_structures', $assignmentId, 'delete_assignment', null, [
            'employee_id' => $assignment['employee_id'],
            'salary_structure_id' => $assignment['salary_structure_id'],
        ]);
        echo json_encode(['success' => true, 'message' => 'Salary structure assignment deleted']);
        exit;
    }

    // ---- Preview payroll (spec #8, #18, #36) ----
    if ($action === 'preview') {
        if (!hasPermission('payroll', 'read')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $cycleId = (int) ($data['pay_cycle_id'] ?? 0);
        $start = $data['period_start'] ?? ''; $end = $data['period_end'] ?? '';
        $departmentId = $data['department_id'] ?? null;
        $employeeId = $data['employee_id'] ?? null;

        $cycle = $cycleId ? $db->fetchOne("SELECT * FROM pay_cycles WHERE id = ?", [$cycleId]) : null;
        if ((!$start || !$end) && $cycle) {
            $anchor = $data['anchor_date'] ?? date('Y-m-d');
            $p = $service->getPeriodForDate($cycle, $anchor);
            $start = $p['start']; $end = $p['end'];
        }
        if (!$start || !$end) { echo json_encode(['success' => false, 'message' => 'Payroll period start/end required']); exit; }

        $employees = $service->resolvePeriodEmployees($cycle, $start, $end, $departmentId, $employeeId);
        $result = $service->computePayrollBatch($employees, $start, $end);
        $result['period_start'] = $start;
        $result['period_end'] = $end;
        $result['pay_cycle_id'] = $cycleId;
        $result['pay_cycle_name'] = $cycle['name'] ?? null;
        echo json_encode(['success' => true] + $result);
        exit;
    }

    // ---- Generate payroll (spec #8, #18, #37) ----
    if ($action === 'generate') {
        if (!hasPermission('payroll', 'create')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $cycleId = (int) ($data['pay_cycle_id'] ?? 0);
        $start = $data['period_start'] ?? ''; $end = $data['period_end'] ?? '';
        $departmentId = $data['department_id'] ?? null;
        $employeeId = $data['employee_id'] ?? null;

        $cycle = $cycleId ? $db->fetchOne("SELECT * FROM pay_cycles WHERE id = ?", [$cycleId]) : null;
        if (!$start || !$end) {
            if ($cycle) {
                $anchor = $data['anchor_date'] ?? date('Y-m-d');
                $p = $service->getPeriodForDate($cycle, $anchor);
                $start = $p['start']; $end = $p['end'];
            } else { echo json_encode(['success' => false, 'message' => 'Period start/end required']); exit; }
        }
        if (!$cycleId) { echo json_encode(['success' => false, 'message' => 'A Pay Cycle is required to generate payroll']); exit; }

        // Duplicate protection (spec #37)
        $existing = $service->existingRunForPeriod($cycleId, $start, $end);
        if ($existing && ($existing['status'] === 'Paid' || $existing['payment_status'] === 'Paid')) {
            echo json_encode(['success' => false, 'message' => 'Payroll already generated and paid for this period and cannot be regenerated in place. Use Regenerate/adjust workflow.']);
            exit;
        }
        if ($existing && $existing['status'] !== 'Draft') {
            echo json_encode(['success' => false, 'message' => 'Payroll already generated for this period (Run #' . $existing['id'] . '). Use the Regenerate action in Payroll History instead.', 'existing_run_id' => $existing['id']]);
            exit;
        }

        $employees = $service->resolvePeriodEmployees($cycle, $start, $end, $departmentId, $employeeId);
        $result = $service->computePayrollBatch($employees, $start, $end);
        if (empty($result['items'])) {
            echo json_encode(['success' => false, 'message' => 'No eligible employees found for this period. Ensure employees have a salary structure and pay cycle assigned.', 'errors' => $result['errors']]);
            exit;
        }

        $runId = $service->savePayrollRun($result, [
            'period_start' => $start, 'period_end' => $end,
            'pay_cycle_id' => $cycleId, 'payroll_period_id' => $data['payroll_period_id'] ?? null,
            'name' => $data['name'] ?? (date('M Y', strtotime($end))),
            'generated_by' => $user['id'], 'existing_run' => $existing, 'reason' => $data['reason'] ?? null,
        ]);
        logAudit('payroll_runs', $runId, 'generate', null, ['period' => $start . ' - ' . $end, 'payout' => $result['total_payout'], 'employees' => count($result['items'])]);
        echo json_encode(['success' => true, 'run_id' => $runId, 'message' => 'Payroll generated (' . count($result['items']) . ' employees)', 'total_payout' => $result['total_payout'], 'warnings' => count($result['errors'])]);
        exit;
    }

    // ---- Regenerate payroll (spec #21, #22, #23) ----
    if ($action === 'regenerate') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $runId = (int) ($data['run_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));
        $run = $db->fetchOne("SELECT * FROM payroll_runs WHERE id = ?", [$runId]);
        if (!$run) { echo json_encode(['success' => false, 'message' => 'Payroll run not found']); exit; }
        if ($run['status'] === 'Paid' || $run['payment_status'] === 'Paid') {
            echo json_encode(['success' => false, 'message' => 'Paid payroll is locked and cannot be regenerated. Use an adjustment/reversal workflow.']);
            exit;
        }
        if (!$reason) { echo json_encode(['success' => false, 'message' => 'A regeneration reason is required for the audit trail']); exit; }

        $employees = $service->resolvePeriodEmployees(['id' => $run['pay_cycle_id']], $run['period_start'], $run['period_end']);
        $result = $service->computePayrollBatch($employees, $run['period_start'], $run['period_end']);
        $existing = ['id' => $runId, 'version' => $run['version'] ?? 1, 'status' => $run['status']];
        $newRunId = $service->savePayrollRun($result, [
            'period_start' => $run['period_start'], 'period_end' => $run['period_end'],
            'pay_cycle_id' => $run['pay_cycle_id'], 'payroll_period_id' => $run['payroll_period_id'],
            'name' => $run['name'], 'generated_by' => $user['id'], 'existing_run' => $existing, 'reason' => $reason,
        ]);
        logAudit('payroll_runs', $newRunId, 'regenerate', ['status' => $run['status']], ['reason' => $reason, 'payout' => $result['total_payout']]);
        echo json_encode(['success' => true, 'run_id' => $newRunId, 'version' => (int) ($run['version'] ?? 1) + 1, 'message' => 'Payroll regenerated successfully', 'total_payout' => $result['total_payout']]);
        exit;
    }

    // ---- Status workflow (spec #20, #22, #35) ----
    if (in_array($action, ['review', 'approve', 'mark_paid'])) {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $runId = (int) ($data['run_id'] ?? ($data['id'] ?? 0));
        if (!$runId) { echo json_encode(['success' => false, 'message' => 'Run ID required']); exit; }
        $toStatus = ['review' => 'Reviewed', 'approve' => 'Approved', 'mark_paid' => 'Paid'][$action];
        $res = $service->transitionRunStatus($runId, $toStatus, $user['id']);
        echo json_encode($res);
        exit;
    }

    // ---- Generate payslip (spec #25, #26, #28) ----
    if ($action === 'generate_payslip') {
        if (!hasPermission('payroll', 'update')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }
        $lineId = (int) ($data['line_item_id'] ?? 0);
        if (!$lineId) { echo json_encode(['success' => false, 'message' => 'line_item_id required']); exit; }
        $res = $service->generatePayslip($lineId, $user['id']);
        if ($res['success']) {
            logAudit('payslips', $res['payslip_id'], 'generate_payslip', null, ['line_item_id' => $lineId, 'net' => $res['net_salary']]);
        }
        echo json_encode($res);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Throwable $e) {
    // Throwable covers Error too (e.g. private property / type errors), so the
    // API always returns valid JSON instead of an HTML fatal.
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}