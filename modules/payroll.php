<?php
/**
 * Payroll Module - Redesigned
 * Core PHP HRMS Application
 *
 * Generate Payroll | Salary Structure | Salary Components | Pay Cycles |
 * Assignments | Payroll History | Payslip
 *
 * Payroll Periods are now derived from Pay Cycles inside Generate Payroll
 * (no separate manual period management required).
 */

$db = Database::getInstance();
$user = getCurrentUser();

if (!hasPermission('payroll', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view payroll.</div>';
    exit;
}

$tab = $_GET['tab'] ?? 'generate';

// ---- Pay cycles ----
$payCycles = [];
try {
    $payCycles = $db->fetchAll(
        "SELECT pc.*,
                (SELECT COUNT(*) FROM pay_cycle_assignments pca WHERE pca.pay_cycle_id = pc.id AND pca.is_active = 1) as assignment_count,
                (SELECT COUNT(*) FROM payroll_periods pp WHERE pp.pay_cycle_id = pc.id) as period_count
         FROM pay_cycles pc
         WHERE pc.is_active = 1
         ORDER BY pc.is_default DESC, pc.name"
    );
} catch (Exception $e) { $payCycles = []; }

// ---- Salary structures (now real reusable structure table) ----
$salaryStructures = [];
try {
    $salaryStructures = $db->fetchAll(
        "SELECT ss.id, ss.name, ss.description, ss.annual_ctc, ss.is_active,
                (SELECT COUNT(*) FROM salary_structure_components ssc WHERE ssc.salary_structure_id = ss.id) as component_count,
                (SELECT COUNT(*) FROM employee_salary_structures ess WHERE ess.salary_structure_id = ss.id AND ess.is_active = 1) as assignment_count
         FROM salary_structures ss ORDER BY ss.name"
    );
} catch (Exception $e) { $salaryStructures = []; }

// ---- Salary components ----
$salaryComponents = [];
try { $salaryComponents = $db->fetchAll("SELECT * FROM salary_components ORDER BY type, display_order, name"); } catch (Exception $e) {}

// ---- Active employees / departments / roles ----
$allEmployees = [];
try { $allEmployees = $db->fetchAll("SELECT id, first_name, last_name, employee_id, position, date_of_joining FROM employees WHERE is_active = 1 ORDER BY first_name, last_name"); } catch (Exception $e) {}
$departments = [];
try { $departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name"); } catch (Exception $e) {}
$roles = [];
try { $roles = $db->fetchAll("SELECT * FROM roles WHERE is_active = 1 ORDER BY name"); } catch (Exception $e) {}

// ---- Employee -> departments map (for the Generate Payroll department filter) ----
// Only the PRIMARY department is used so employees in multiple departments
// appear only under their primary department, never duplicated.
$employeeDeptsMap = [];
try {
    foreach ($db->fetchAll(
        "SELECT ed.employee_id, MIN(ed.department_id) as primary_dept
         FROM employee_departments ed
         WHERE ed.is_primary = 1
         GROUP BY ed.employee_id"
    ) as $ed) {
        $employeeDeptsMap[(int) $ed['employee_id']] = [(int) $ed['primary_dept']];
    }
} catch (Exception $e) { $employeeDeptsMap = []; }

// ---- Pay cycle assignments ----
$assignments = [];
try {
    $assignments = $db->fetchAll(
        "SELECT pca.*, pc.name as pay_cycle_name,
                CASE pca.assignment_type
                    WHEN 'organization' THEN 'All Employees'
                    WHEN 'department' THEN (SELECT name FROM departments WHERE id = pca.assignment_id)
                    WHEN 'role' THEN (SELECT name FROM roles WHERE id = pca.assignment_id)
                    WHEN 'employee' THEN CONCAT((SELECT first_name FROM employees WHERE id = pca.assignment_id), ' ', (SELECT last_name FROM employees WHERE id = pca.assignment_id))
                END as assignment_name
         FROM pay_cycle_assignments pca
         JOIN pay_cycles pc ON pca.pay_cycle_id = pc.id
         ORDER BY pca.priority DESC, pca.effective_date DESC
         LIMIT 50"
    );
} catch (Exception $e) {}

// ---- Payroll runs (history) ----
// Only include currently existing/active runs that actually have payroll
// line items (employees processed). Empty/orphaned/deleted runs are excluded.
$payrollHistory = [];
try {
    $payrollHistory = $db->fetchAll(
        "SELECT pr.id, pr.name, pr.period_start, pr.period_end, pr.pay_cycle_id, pc.name as pay_cycle_name,
                pr.status, pr.payment_status, pr.version, pr.total_employees, pr.total_payout, pr.generated_at, pr.regenerate_reason,
                e.first_name as gen_first, e.last_name as gen_last,
                (SELECT COUNT(*) FROM payroll_line_items pli WHERE pli.payroll_run_id = pr.id) as item_count
         FROM payroll_runs pr
         LEFT JOIN pay_cycles pc ON pr.pay_cycle_id = pc.id
         LEFT JOIN employees e ON pr.generated_by = e.id
         WHERE EXISTS (SELECT 1 FROM payroll_line_items pli WHERE pli.payroll_run_id = pr.id)
         ORDER BY pr.period_end DESC, pr.id DESC
         LIMIT 50"
    );
} catch (Exception $e) { $payrollHistory = []; }

$latestRun = null;
try { $latestRun = $db->fetchOne("SELECT * FROM payroll_runs ORDER BY period_end DESC, id DESC LIMIT 1"); } catch (Exception $e) {}

// Default pay cycle selected in Generate Payroll
$defaultCycle = null;
foreach ($payCycles as $pc) { if ($pc['is_default'] == 1) { $defaultCycle = $pc; break; } }
if (!$defaultCycle && !empty($payCycles)) $defaultCycle = $payCycles[0];
$defaultCyclePeriods = [];
if ($defaultCycle) {
    try {
        require_once __DIR__ . '/../includes/payroll_service.php';
        $svc = new PayrollService();
        $defaultCyclePeriods = $svc->generatePeriodSeries($defaultCycle, date('Y-m-d', strtotime('-2 months')), 6);
    } catch (Exception $e) { $defaultCyclePeriods = []; }
}
?>
<div class="panel-head"></div>

<style>
/* ---- Payslip Print Styles ----
   Only the #payrollPrintArea container is printed (a single payslip).
   All HRMS UI chrome (sidebar, navbar, tabs, toolbar, buttons, modals) is hidden
   during print so the payslip appears once on one page, with its styling intact. */
@media print {
    body { background:#fff !important; }
    body * { visibility: hidden; }
    #payrollPrintArea, #payrollPrintArea * { visibility: visible; }
    #payrollPrintArea {
        display: block !important;
        position: absolute;
        left: 0; top: 0;
        width: 100%;
        margin: 0; padding: 0;
    }
    #hrms-modal-container,
    .sidebar, .topbar, nav, footer, .tabs, .toolbar, .actions,
    .filter-chip, .panel-head, .empty-state, .icon-btn, .btn,
    .count-badge, .clock-widget, .break-widget, .duration-widget,
    .topbar-actions, .settings-nav, .content > .toolbar {
        display: none !important;
    }
    #payrollPrintArea .payslip {
        border: 1px solid #ccc !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        font-family: 'Inter', Arial, sans-serif !important;
    }
    #payrollPrintArea .payslip-table,
    #payrollPrintArea table {
        page-break-inside: avoid;
    }
    @page { size: A4; margin: 12mm; }
}
</style>
<!-- Dedicated print area: only the payslip is rendered here for printing. -->
<div id="payrollPrintArea" style="display:none;"></div>

<div class="tabs">
    <div class="tab <?php echo $tab == 'generate' ? 'active' : ''; ?>" onclick="switchTab('generate')">Generate Payroll</div>
    <div class="tab <?php echo $tab == 'structure' ? 'active' : ''; ?>" onclick="switchTab('structure')">Salary Structure</div>
    <div class="tab <?php echo $tab == 'components' ? 'active' : ''; ?>" onclick="switchTab('components')">Salary Components</div>
    <div class="tab <?php echo $tab == 'cycles' ? 'active' : ''; ?>" onclick="switchTab('cycles')">Pay Cycles</div>
    <div class="tab <?php echo $tab == 'assignments' ? 'active' : ''; ?>" onclick="switchTab('assignments')">Assignments</div>
    <div class="tab <?php echo $tab == 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">Payroll History</div>
    <div class="tab <?php echo $tab == 'payslip' ? 'active' : ''; ?>" onclick="switchTab('payslip')">Payslip</div>
</div>
<?php if ($tab == 'generate'): ?>
    <div class="panel" style="max-width:1400px;margin:0 auto;">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-cash-stack"></i> Generate Payroll</div>
            <div class="filter-chip"><i class="bi bi-diagram-3"></i> Payroll period is derived from Pay Cycle</div>
        </div>
        <div class="alert info" style="margin-bottom:16px;">
            <i class="bi bi-info-circle"></i> Select a pay cycle and payroll period. The period dates are automatically derived from the selected cycle — no separate Payroll Periods management is required.
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Pay Cycle <span class="req">*</span></label>
                <select id="genPayCycleId" onchange="loadCyclePeriods()">
                    <option value="">Select Pay Cycle...</option>
                    <?php foreach ($payCycles as $cycle): ?>
                        <option value="<?php echo $cycle['id']; ?>" <?php echo ($defaultCycle && $defaultCycle['id'] == $cycle['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cycle['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Payroll Period <span class="req">*</span></label>
                <select id="genPeriodSelect">
                    <option value="">Select Period...</option>
                    <?php foreach ($defaultCyclePeriods as $p): ?>
                        <option value="<?php echo $p['start']; ?>" data-end="<?php echo $p['end']; ?>">
                            <?php echo date('d M Y', strtotime($p['start'])) . ' – ' . date('d M Y', strtotime($p['end'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Department</label>
                <select id="genDepartmentId" onchange="updateEmpFilterByDept()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Employee</label>
                <select id="genEmployeeId">
                    <option value="">All Employees</option>
                    <?php foreach ($allEmployees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo isset($employeeDeptsMap[(int)$emp['id']]) ? 'data-depts="' . implode(',', $employeeDeptsMap[(int)$emp['id']]) . '"' : 'data-depts=""'; ?>><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <small id="genDeptInfo" style="color:var(--muted);font-size:11px;"></small>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="previewPayroll()">
                <i class="bi bi-eye"></i> Preview Payroll
            </button>
            <button class="btn primary" onclick="generatePayroll()">
                <i class="bi bi-lightning-charge"></i> Generate Payroll
            </button>
        </div>

        <div id="previewContainer" style="display:none;margin-top:24px;">
            <div class="panel-head">
                <div class="panel-title"><i class="bi bi-clipboard-data"></i> Payroll Preview</div>
                <div id="previewMeta" style="font-size:12px;color:var(--muted);"></div>
            </div>
            <div class="toolbar">
                <div class="search" style="width:320px;max-width:100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" id="previewSearch" placeholder="Search employee by name or ID..." oninput="filterPreviewRows()">
                </div>
                <div class="spacer"></div>
                <span id="previewCount" style="font-size:12px;color:var(--muted);"></span>
            </div>
            <div class="data-table" style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Period</th>
                            <th>Structure</th>
                            <th>Working Days</th>
                            <th>Paid Days</th>
                            <th>LOP</th>
                            <th>Gross</th>
                            <th>Deductions</th>
                            <th>Net Salary</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:14px;">
                <div id="previewWarnings" style="font-size:12px;color:var(--warning);"></div>
                <div style="font-size:15px;font-weight:600;">
                    Total Payout: <span id="previewTotal" class="mono">₹0</span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if ($tab == 'structure'): ?>
    <div class="toolbar">
        <div class="filter-chip"><i class="bi bi-layers"></i> <?php echo count($salaryStructures); ?> structures</div>
        <div class="spacer"></div>
        <button class="btn primary" onclick="openStructureModal()">
            <i class="bi bi-plus-lg"></i> Add Salary Structure
        </button>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Structure</th>
                    <th>Components</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salaryStructures)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-layers"></i>
                                <p>No salary structures yet. Create your first structure to define compensation components.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salaryStructures as $structure): ?>
                        <tr data-structure-id="<?php echo (int) $structure['id']; ?>">
                            <td>
                                <div class="emp-name"><?php echo htmlspecialchars($structure['name']); ?></div>
                                <div class="emp-id mono" style="font-size:11px;"><?php echo htmlspecialchars($structure['description'] ?? ''); ?></div>
                            </td>
                            <td><span class="badge info"><?php echo (int) $structure['component_count']; ?></span></td>
                            <td>
                                <button type="button" class="btn sm" onclick="showStructureAssigned(<?php echo (int) $structure['id']; ?>)"
                                        title="View assigned employees">
                                    <i class="bi bi-people"></i> <span class="assigned-count"><?php echo (int) $structure['assignment_count']; ?></span> employees
                                </button>
                            </td>
                            <td>
                                <?php if ($structure['is_active']): ?>
                                    <span class="badge success">Active</span>
                                <?php else: ?>
                                    <span class="badge gray">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="editStructureComponents(<?php echo $structure['id']; ?>)" title="Edit Components">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <div class="icon-btn" onclick="openAssignStructureModal(<?php echo $structure['id']; ?>)" title="Assign to Employee">
                                        <i class="bi bi-person-plus"></i>
                                    </div>
                                    <div class="icon-btn" onclick="viewStructureSummary(<?php echo $structure['id']; ?>)" title="View Computation">
                                        <i class="bi bi-calculator"></i>
                                    </div>
                                    <div class="icon-btn" onclick="deleteSalaryStructure(<?php echo $structure['id']; ?>)" title="Delete" style="color:var(--danger);">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="panel" style="margin-top:20px;">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-person-lines-fill"></i> Salary Structure Assignments</div>
            <div class="filter-chip"><i class="bi bi-info-circle"></i> Historical assignments retained</div>
        </div>
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Structure</th>
                        <th>Annual CTC</th>
                        <th>Effective From</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="assignmentHistoryBody">
                    <tr><td colspan="6" style="text-align:center;padding:30px;">
                        <div class="empty-state"><i class="bi bi-clock-history"></i><p>No assignments yet. Assign a structure to an employee to begin.</p></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php if ($tab == 'components'): ?>
    <div class="toolbar">
        <div class="filter-chip"><i class="bi bi-tag"></i> <?php echo count($salaryComponents); ?> components</div>
        <div class="spacer"></div>
        <button class="btn primary" onclick="openComponentModal()">
            <i class="bi bi-plus-lg"></i> Add Component
        </button>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Component</th>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Calculation</th>
                    <th>Percentage / Value</th>
                    <th>Based On</th>
                    <th>Frequency</th>
                    <th>Taxable</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salaryComponents)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-tag"></i>
                                <p>No salary components defined. Components are the building blocks of salary structures.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salaryComponents as $component): ?>
                        <tr>
                            <td class="emp-name"><?php echo htmlspecialchars($component['name']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($component['code'] ?? '-'); ?></td>
                            <td>
                                <span class="badge <?php echo $component['type'] === 'earning' ? 'success' : 'warning'; ?>">
                                    <?php echo $component['type'] === 'earning' ? 'Earning' : 'Deduction'; ?>
                                </span>
                            </td>
                            <td><?php echo ucfirst(htmlspecialchars($component['calculation_method'] ?? 'fixed')); ?></td>
                            <td class="mono">
                                <?php
                                if (($component['calculation_method'] ?? '') === 'percentage') {
                                    echo number_format((float) ($component['percentage'] ?? 0), 2) . '%';
                                } else {
                                    echo '₹' . number_format((float) ($component['default_value'] ?? 0), 2);
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($component['percentage_based_on'] ?? '-'); ?></td>
                            <td><?php echo ucfirst(htmlspecialchars($component['frequency'] ?? 'monthly')); ?></td>
                            <td><?php echo !empty($component['is_taxable']) ? '<span class="badge">Taxable</span>' : '<span class="badge gray">Non-taxable</span>'; ?></td>
                            <td class="mono"><?php echo (int) ($component['display_order'] ?? 0); ?></td>
                            <td>
                                <?php if ($component['is_active']): ?>
                                    <span class="badge success">Active</span>
                                <?php else: ?>
                                    <span class="badge gray">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="openComponentModal(<?php echo $component['id']; ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <div class="icon-btn" onclick="deleteComponent(<?php echo $component['id']; ?>)" title="Delete" style="color:var(--danger);">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($tab == 'cycles'): ?>
    <div class="toolbar">
        <div class="spacer"></div>
        <button class="btn primary" onclick="addPayCycle()">
            <i class="bi bi-plus-lg"></i> Add Pay Cycle
        </button>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Cycle Name</th>
                    <th>Frequency</th>
                    <th>Start Day</th>
                    <th>End Day</th>
                    <th>Assignments</th>
                    <th>Periods</th>
                    <th>Default</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payCycles)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px;">
                            <div class="empty-state"><i class="bi bi-calendar-check"></i><p>No pay cycles found</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payCycles as $cycle): ?>
                        <tr>
                            <td class="emp-name"><?php echo htmlspecialchars($cycle['name']); ?></td>
                            <td><span class="badge info"><?php echo ucfirst(htmlspecialchars($cycle['frequency'] ?? 'monthly')); ?></span></td>
                            <td class="mono"><?php echo (int) $cycle['cycle_start_day']; ?>th</td>
                            <td class="mono"><?php echo ($cycle['cycle_end_day'] == 99) ? 'Last Day' : ((int) $cycle['cycle_end_day']) . 'th'; ?></td>
                            <td><?php echo number_format($cycle['assignment_count'] ?? 0); ?></td>
                            <td><?php echo number_format($cycle['period_count'] ?? 0); ?></td>
                            <td>
                                <?php if ($cycle['is_default']): ?>
                                    <span class="badge success">Default</span>
                                <?php else: ?>
                                    <button class="btn sm" onclick="setDefaultCycle(<?php echo $cycle['id']; ?>)">Set Default</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewPayCycleAssignments(<?php echo $cycle['id']; ?>)" title="View Assignments">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <div class="icon-btn" onclick="generatePayrollPeriods(<?php echo $cycle['id']; ?>)" title="Generate Period Records">
                                        <i class="bi bi-calendar-plus"></i>
                                    </div>
                                    <div class="icon-btn" onclick="editPayCycle(<?php echo $cycle['id']; ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <div class="icon-btn" onclick="deletePayCycle(<?php echo $cycle['id']; ?>)" title="Delete" style="color:var(--danger);">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($tab == 'assignments'): ?>
    <div class="toolbar">
        <div class="filter-chip"><i class="bi bi-diagram-3"></i> Priority: Employee → Role → Department → Organization</div>
        <div class="spacer"></div>
        <button class="btn primary" onclick="openAssignmentModal()">
            <i class="bi bi-plus-lg"></i> Add Assignment
        </button>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Pay Cycle</th>
                    <th>Assignment Type</th>
                    <th>Assigned To</th>
                    <th>Priority</th>
                    <th>Effective Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px;">
                            <div class="empty-state"><i class="bi bi-people"></i><p>No pay cycle assignments found</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($assignment['pay_cycle_name']); ?></td>
                            <td><span class="badge info"><?php echo ucfirst(htmlspecialchars($assignment['assignment_type'])); ?></span></td>
                            <td><?php echo htmlspecialchars($assignment['assignment_name'] ?? '-'); ?></td>
                            <td>
                                <span class="badge <?php echo $assignment['priority'] >= 40 ? 'success' : ($assignment['priority'] >= 30 ? 'warning' : 'info'); ?>">
                                    Priority <?php echo $assignment['priority']; ?>
                                </span>
                            </td>
                            <td class="mono"><?php echo formatDate($assignment['effective_date']); ?></td>
                            <td class="mono"><?php echo $assignment['end_date'] ? formatDate($assignment['end_date']) : '-'; ?></td>
                            <td>
                                <?php if ($assignment['is_active']): ?>
                                    <span class="badge success">Active</span>
                                <?php else: ?>
                                    <span class="badge gray">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="editAssignment(<?php echo $assignment['id']; ?>, '<?php echo $assignment['pay_cycle_id']; ?>', '<?php echo $assignment['assignment_type']; ?>', '<?php echo $assignment['assignment_id'] ?? ''; ?>', '<?php echo $assignment['effective_date']; ?>', '<?php echo $assignment['end_date'] ?? ''; ?>')" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <div class="icon-btn" onclick="removeAssignment(<?php echo $assignment['id']; ?>)" title="Delete" style="color:var(--danger);">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($tab == 'history'): ?>
    <div class="toolbar">
        <div class="filter-chip"><i class="bi bi-calendar-month"></i> Payroll History</div>
        <div class="filter-chip"><i class="bi bi-diagram-3"></i> Runs</div>
        <div class="spacer"></div>
    </div>

    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Pay Cycle</th>
                    <th>Version</th>
                    <th>Employees</th>
                    <th>Total Payout</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Generated At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payrollHistory)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state"><i class="bi bi-cash-stack"></i><p>No payroll generated yet. Use Generate Payroll to create your first run.</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payrollHistory as $run): ?>
                        <tr>
                            <td class="mono">
                                <b><?php echo date('M Y', strtotime($run['period_start'])); ?></b><br>
                                <span style="font-size:11px;color:var(--muted);">
                                    <?php echo date('d M', strtotime($run['period_start'])) . ' – ' . date('d M Y', strtotime($run['period_end'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($run['pay_cycle_name'] ?? '-'); ?></td>
                            <td><span class="badge info">v<?php echo (int) ($run['version'] ?? 1); ?></span></td>
                            <td><?php echo (int) $run['total_employees']; ?></td>
                            <td class="mono">₹<?php echo number_format((float) $run['total_payout'], 2); ?></td>
                            <td>
                                <span class="badge <?php
                                    echo match($run['status']) {
                                        'Generated' => 'info',
                                        'Reviewed' => 'primary',
                                        'Approved' => 'success',
                                        'Paid' => 'success',
                                        default => 'gray'
                                    };
                                ?>"><?php echo htmlspecialchars($run['status']); ?></span>
                            </td>
                            <td>
                                <span class="badge <?php echo $run['payment_status'] === 'Paid' ? 'success' : 'gray'; ?>">
                                    <?php echo htmlspecialchars($run['payment_status'] ?? 'Unpaid'); ?>
                                </span>
                            </td>
                            <td class="mono" style="font-size:11px;">
                                <?php echo $run['generated_at'] ? date('d M Y H:i', strtotime($run['generated_at'])) : '-'; ?><br>
                                <?php if (!empty($run['regenerate_reason'] ?? '')): ?>
                                    <span style="color:var(--warning);" title="<?php echo htmlspecialchars($run['regenerate_reason'] ?? ''); ?>">Regenerated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="btn sm primary" onclick="viewRunDetails(<?php echo $run['id']; ?>)">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                    <?php if ($run['status'] !== 'Paid' && $run['payment_status'] !== 'Paid'): ?>
                                        <button class="btn sm" onclick="regeneratePayroll(<?php echo $run['id']; ?>)">
                                            <i class="bi bi-arrow-repeat"></i> Regenerate
                                        </button>
                                        <button class="btn sm" onclick="transitionRun(<?php echo $run['id']; ?>, '<?php echo $run['status']; ?>')">
                                            <i class="bi bi-check-circle"></i>
                                            <?php echo $run['status'] === 'Generated' ? 'Review' : ($run['status'] === 'Reviewed' ? 'Approve' : 'Mark Paid'); ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="badge success"><i class="bi bi-lock"></i> Locked</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($tab == 'payslip'): ?>
    <div class="panel" style="max-width:900px;margin:0 auto;">
        <div class="panel-head">
            <div class="panel-title"><i class="bi bi-file-earmark-text"></i> Payslip</div>
            <div class="filter-chip"><i class="bi bi-shield-check"></i> Based on finalized payroll snapshot</div>
        </div>

        <div class="form-grid" style="margin-bottom:16px;">
            <div class="field">
                <label>Payroll Run</label>
                <select id="psRunSelect" onchange="loadPayslipByRun()">
                    <option value="">Select a payroll run...</option>
                    <?php foreach ($payrollHistory as $run): ?>
                        <option value="<?php echo $run['id']; ?>">
                            <?php echo htmlspecialchars($run['name']); ?> (<?php echo date('M Y', strtotime($run['period_start'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Employee</label>
                <select id="psEmployeeSelect" style="pointer-events:none;opacity:0.9;">
                    <option value="">Select run first...</option>
                </select>
            </div>
        </div>

        <div id="payslipView">
            <div class="panel" style="text-align:center;padding:60px;">
                <div class="empty-state">
                    <i class="bi bi-file-earmark-text"></i>
                    <p>Select a payroll run and employee to view the payslip.</p>
                    <p style="font-size:12px;color:var(--muted);">
                        Payroll records always reflect the exact finalized payroll values — never a separate recalculation.
                    </p>
                </div>
            </div>
        </div>

        <div id="payslipActions" style="display:none;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button class="btn" onclick="printPayslip(currentPayslipItem)">
                <i class="bi bi-printer"></i> Print Payslip
            </button>
            <button class="btn primary" onclick="downloadPayslip()">
                <i class="bi bi-download"></i> Download Payslip
            </button>
        </div>
    </div>
<?php endif; ?>
<!-- Salary Structure Modal -->
<div id="structModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:var(--surface);border-radius:var(--radius);width:720px;max-width:95vw;padding:24px;margin:20px auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Salary Structure Builder</h3>
            <button onclick="closeStructureModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Structure Name <span class="req">*</span></label>
                <input type="text" id="structName" placeholder="e.g. Standard Monthly Salary">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <input type="text" id="structDescription" placeholder="e.g. Standard Employee">
            </div>
            <input type="hidden" id="structId">
        </div>

        <div class="panel-head" style="margin-top:16px;">
            <div class="panel-title"><i class="bi bi-list-check"></i> Components</div>
            <button class="btn sm" onclick="addStructureComponentRow()"><i class="bi bi-plus-lg"></i> Add Component</button>
        </div>
        <div class="data-table" style="max-height:320px;overflow-y:auto;">
            <table>
                <thead>
                    <tr><th>Component</th><th>Method</th><th>Value / %</th><th></th></tr>
                </thead>
                <tbody id="structComponentsBody"></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeStructureModal()">Cancel</button>
            <button class="btn primary" onclick="saveStructure()">Save Structure</button>
        </div>
    </div>
</div>
<!-- Assign Structure to Employee Modal -->
<div id="assignStructureModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Assign Salary Structure</h3>
            <button onclick="closeAssignStructureModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Employee <span class="req">*</span></label>
                <select id="asEmployeeId">
                    <option value="">Select Employee</option>
                    <?php foreach ($allEmployees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Salary Structure <span class="req">*</span></label>
                <select id="asStructureId">
                    <option value="">Select Structure</option>
                    <?php foreach ($salaryStructures as $structure): ?>
                        <option value="<?php echo $structure['id']; ?>"><?php echo htmlspecialchars($structure['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Annual CTC (₹) <span class="req">*</span></label>
                <input type="number" id="asAnnualCtc" min="0" step="0.01" oninput="updateAsCtcPreview()">
                <small id="asCtcPreview" style="color:var(--muted);font-size:11px;"></small>
            </div>
            <div class="field">
                <label>Effective From <span class="req">*</span></label>
                <input type="date" id="asEffectiveDate" value="<?php echo date('Y-m-d'); ?>">
                <small style="color:var(--muted);font-size:11px;">Historical periods use the structure active at that time.</small>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeAssignStructureModal()">Cancel</button>
            <button class="btn primary" onclick="saveStructureAssignment()">Assign Structure</button>
        </div>
    </div>
</div>
<!-- Assigned Employees Modal (view + search + remove for one structure) -->
<div id="structureEmployeesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:760px;max-width:95vw;padding:24px;margin:20px auto;max-height:90vh;display:flex;flex-direction:column;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">
                <i class="bi bi-people" style="color:var(--accent);"></i> Assigned Employees
                <span id="seCountBadge" class="badge" style="margin-left:8px;">0</span>
            </h3>
            <button onclick="closeStructureAssignedModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="seStructureId" value="">
        <div style="margin-bottom:12px;">
            <input type="text" id="seSearch" placeholder="Search by name, employee ID, department or designation..."
                   oninput="renderAssignedEmployeesRows()"
                   style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Inter';background:var(--bg);color:var(--ink);outline:none;">
        </div>
        <div class="data-table" style="overflow-x:auto;flex:1;min-height:200px;">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Employee ID</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="structureEmployeesBody">
                    <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted);">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button class="btn" onclick="closeStructureAssignedModal()">Close</button>
            <button class="btn primary" onclick="openAssignStructureModal(document.getElementById('seStructureId').value); closeStructureAssignedModal();">
                <i class="bi bi-person-plus"></i> Assign Employee
            </button>
        </div>
    </div>
</div>
    </div>
</div>
<!-- Employee Salary Override Modal (edit one employee's components only) -->
<div id="employeeSalaryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1001;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:820px;max-width:95vw;padding:24px;margin:20px auto;max-height:90vh;display:flex;flex-direction:column;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">
                <i class="bi bi-person-gear" style="color:var(--accent);"></i> <span id="empSalTitle">Edit Salary</span>
            </h3>
            <button onclick="closeEmployeeSalaryModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="empSalEmployeeId" value="">
        <input type="hidden" id="empSalStructureId" value="">
        <div class="form-grid" style="margin-bottom:12px;">
            <div class="field">
                <label>Annual CTC (₹) <span class="req">*</span></label>
                <input type="number" id="empSalAnnualCtc" min="0" step="0.01" oninput="onEmpSalCtcChange()">
                <small style="color:var(--muted);font-size:11px;">Applies to this employee only. The shared salary structure is unchanged.</small>
            </div>
            <div class="field">
                <label>Structure</label>
                <input type="text" id="empSalStructureName" disabled style="background:var(--bg);">
            </div>
        </div>
        <div class="data-table" style="overflow-y:auto;flex:1;min-height:240px;max-height:46vh;">
            <table>
                <thead>
                    <tr>
                        <th>Salary Component</th>
                        <th style="text-align:right;">Structure Amount</th>
                        <th style="text-align:right;">Employee Amount (₹/month)</th>
                    </tr>
                </thead>
                <tbody id="empSalComponentsBody">
                    <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--muted);">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <div style="background:var(--bg);border-radius:8px;padding:10px 12px;">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Gross Salary</div>
                <div id="empSalGross" class="mono" style="font-size:16px;font-weight:600;">₹0</div>
            </div>
            <div style="background:var(--bg);border-radius:8px;padding:10px 12px;">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Total Deductions</div>
                <div id="empSalDeductions" class="mono" style="font-size:16px;font-weight:600;">₹0</div>
            </div>
            <div style="background:var(--bg);border-radius:8px;padding:10px 12px;">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Net Salary</div>
                <div id="empSalNet" class="mono" style="font-size:16px;font-weight:700;">₹0</div>
            </div>
        </div>
        <small id="empSalHint" style="color:var(--muted);font-size:11px;margin-top:8px;">Leave the Employee Amount blank to use the structure default for that component.</small>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button class="btn" onclick="closeEmployeeSalaryModal()">Cancel</button>
            <button class="btn primary" onclick="saveEmployeeOverride()">
                <i class="bi bi-check-lg"></i> Save Changes
            </button>
        </div>
    </div>
</div>
<!-- Salary Component Modal -->
<div id="componentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:var(--surface);border-radius:var(--radius);width:640px;max-width:95vw;padding:24px;margin:20px auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Salary Component</h3>
            <button onclick="closeComponentModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <input type="hidden" id="compId">
            <div class="field">
                <label>Component Name <span class="req">*</span></label>
                <input type="text" id="compName">
            </div>
            <div class="field">
                <label>Component Code</label>
                <input type="text" id="compCode" placeholder="e.g. HRA">
            </div>
            <div class="field">
                <label>Type <span class="req">*</span></label>
                <select id="compType">
                    <option value="earning">Earning</option>
                    <option value="deduction">Deduction</option>
                </select>
            </div>
            <div class="field">
                <label>Calculation Method <span class="req">*</span></label>
                <select id="compMethod" onchange="toggleCompMethodFields()">
                    <option value="fixed">Fixed Amount</option>
                    <option value="percentage">Percentage</option>
                    <option value="formula">Formula / Balancing</option>
                </select>
            </div>
            <div class="field" id="compFixedField">
                <label>Fixed Amount (₹)</label>
                <input type="number" id="compDefaultValue" min="0" step="0.01" value="0">
            </div>
            <div class="field" id="compPercentField" style="display:none;">
                <label>Percentage (%)</label>
                <input type="number" id="compPercentage" min="0" step="0.01" value="0">
            </div>
            <div class="field" id="compBasisField" style="display:none;">
                <label>Percentage Based On</label>
                <select id="compBasis">
                    <option value="ctc">CTC</option>
                    <option value="gross">Gross</option>
                    <?php foreach ($salaryComponents as $component): ?>
                        <option value="<?php echo htmlspecialchars($component['code'] ?? $component['name']); ?>"><?php echo htmlspecialchars($component['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Frequency</label>
                <select id="compFrequency">
                    <option value="monthly">Monthly</option>
                    <option value="annual">Annual</option>
                </select>
            </div>
            <div class="field">
                <label>Display Order</label>
                <input type="number" id="compDisplayOrder" min="0" value="0">
            </div>
            <div class="field" style="grid-column:1/-1;display:flex;align-items:center;gap:8px;">
                <input type="checkbox" id="compTaxable" style="width:16px;height:16px;cursor:pointer;">
                <label style="margin:0;font-size:13px;">Taxable component</label>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeComponentModal()">Cancel</button>
            <button class="btn primary" onclick="saveComponent()">Save Component</button>
        </div>
    </div>
</div>
<!-- Pay Cycle Modal -->
<div id="cycleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Pay Cycle</h3>
            <button onclick="closePayCycleModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <input type="hidden" id="cycleId">
            <div class="field" style="grid-column:1/-1;">
                <label>Cycle Name <span class="req">*</span></label>
                <input type="text" id="cycleName" placeholder="e.g. Monthly - 16th to 15th">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <input type="text" id="cycleDescription" placeholder="e.g. Mid-month to mid-month">
            </div>
            <div class="field">
                <label>Frequency</label>
                <select id="cycleFrequency">
                    <option value="monthly">Monthly</option>
                    <option value="bi-weekly">Bi-Weekly</option>
                    <option value="weekly">Weekly</option>
                </select>
            </div>
            <div class="field">
                <label>Cycle Start Day (1-31)</label>
                <input type="number" id="cycleStartDay" min="1" max="31" value="1">
            </div>
            <div class="field">
                <label>Cycle End Day (1-31 or 99=Last)</label>
                <input type="number" id="cycleEndDay" min="1" max="99" value="99">
                <small style="color:var(--muted);font-size:11px;">99 = last day of month. End day may be less than start day.</small>
            </div>
            <div class="field" style="grid-column:1/-1;display:flex;align-items:center;gap:8px;">
                <input type="checkbox" id="cycleIsDefault" style="width:16px;height:16px;cursor:pointer;">
                <label style="margin:0;font-size:13px;">Set as default pay cycle</label>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closePayCycleModal()">Cancel</button>
            <button class="btn primary" onclick="savePayCycle()">Save Pay Cycle</button>
        </div>
    </div>
</div>
<!-- Assignment Modal -->
<div id="assignmentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:640px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 id="assignmentAssignTitle" style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Assign Pay Cycle</h3>
            <button onclick="closeAssignmentModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <input type="hidden" id="assignmentRowId" value="">
            <div class="field" style="grid-column:1/-1;">
                <label>Pay Cycle <span class="req">*</span></label>
                <select id="assignmentPayCycleId">
                    <option value="">Select Pay Cycle</option>
                    <?php foreach ($payCycles as $cycle): ?>
                        <option value="<?php echo $cycle['id']; ?>"><?php echo htmlspecialchars($cycle['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Assignment Type <span class="req">*</span></label>
                <select id="assignmentType" onchange="updateAssignmentFields()">
                    <option value="organization">Organization (All Employees)</option>
                    <option value="department">Department</option>
                    <option value="role">Role</option>
                    <option value="employee">Specific Employee</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;display:none;" id="departmentField">
                <label>Department</label>
                <select id="assignmentDepartmentId">
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;display:none;" id="roleField">
                <label>Role</label>
                <select id="assignmentRoleId">
                    <option value="">Select Role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;display:none;" id="employeeField">
                <label>Employee</label>
                <select id="assignmentEmployeeId">
                    <option value="">Select Employee</option>
                    <?php foreach ($allEmployees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Effective Date <span class="req">*</span></label>
                <input type="date" id="assignmentEffectiveDate" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="field">
                <label>End Date (Optional)</label>
                <input type="date" id="assignmentEndDate">
                <small style="color:var(--muted);font-size:11px;">Leave empty for ongoing assignment</small>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeAssignmentModal()">Cancel</button>
            <button class="btn primary" onclick="saveAssignment()">Save Assignment</button>
        </div>
    </div>
</div>
<!-- Periods Generation Modal (internal; periods stored but not a separate admin workflow) -->
<div id="periodsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Generate Payroll Periods</h3>
            <button onclick="closePeriodsModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Pay Cycle <span class="req">*</span></label>
                <select id="periodsPayCycleId">
                    <option value="">Select Pay Cycle</option>
                    <?php foreach ($payCycles as $cycle): ?>
                        <option value="<?php echo $cycle['id']; ?>"><?php echo htmlspecialchars($cycle['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);font-size:11px;">Optional: periods are also derived automatically inside Generate Payroll.</small>
            </div>
            <div class="field">
                <label>Start Date</label>
                <input type="date" id="periodsStartDate" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="field">
                <label>Months to Generate</label>
                <input type="number" id="periodsMonths" min="1" max="24" value="6">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closePeriodsModal()">Cancel</button>
            <button class="btn primary" onclick="generatePeriods()">Generate Periods</button>
        </div>
    </div>
</div>
<script>
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.location = url.toString();
}
function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function getInitialsLite(name) {
    const parts = name.trim().split(/\s+/);
    return (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');
}
function formatPeriod(start, end) {
    return new Date(start).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) + ' – ' +
           new Date(end).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'});
}
function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN', {maximumFractionDigits: 2}); }

/* ============================ GENERATE PAYROLL ============================ */

function loadCyclePeriods() {
    const cycleId = document.getElementById('genPayCycleId').value;
    const select = document.getElementById('genPeriodSelect');
    if (!cycleId) { select.innerHTML = '<option value="">Select Period...</option>'; return; }
    fetch(`api/payroll.php?periods&pay_cycle_id=${cycleId}&months=6`)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Period...</option>';
            if (!data.success || !data.periods) return;
            data.periods.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.start;
                opt.dataset.end = p.end;
                opt.textContent = formatPeriod(p.start, p.end);
                select.appendChild(opt);
            });
        })
        .catch(() => { select.innerHTML = '<option value="">Error loading periods</option>'; });
}

function getSelectedPeriod() {
    const sel = document.getElementById('genPeriodSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!sel.value || !opt.dataset.end) return null;
    return { start: sel.value, end: opt.dataset.end };
}

// Filter the Employee dropdown by the selected department so only that
// department's employees are shown. "All Departments" shows everyone.
// Uses window.__EMP_DEPTS (server-provided map) + window.__ALL_EMP_OPTIONS
// (master list captured once) - robust regardless of DOM re-parsing.
function updateEmpFilterByDept() {
    const deptSelect = document.getElementById('genDepartmentId');
    const empSelect = document.getElementById('genEmployeeId');
    const infoEl = document.getElementById('genDeptInfo');
    const deptId = deptSelect.value;
    const current = empSelect.value;
    const master = window.__ALL_EMP_OPTIONS || (function() {
        // Fallback: capture options from the current DOM.
        const arr = [];
        empSelect.querySelectorAll('option[data-depts]').forEach(o => {
            arr.push({ value: o.value, depts: (o.getAttribute('data-depts') || ''), text: o.textContent });
        });
        return arr;
    })();

    let html = '<option value="">All Employees</option>';
    const shown = [];
    master.forEach(item => {
        const depts = (item.depts || '').split(',').filter(Boolean).map(Number);
        if (!deptId || depts.indexOf(Number(deptId)) !== -1) {
            shown.push(item);
            html += `<option value="${item.value}" data-depts="${item.depts}" ${String(item.value) === String(current) ? 'selected' : ''}>${item.text}</option>`;
        }
    });
    empSelect.innerHTML = html;
    if (infoEl) {
        if (deptId) {
            const deptNameEl = deptSelect.options[deptSelect.selectedIndex];
            const deptName = deptNameEl ? deptNameEl.text : 'selected department';
            infoEl.textContent = 'Showing ' + shown.length + ' employee(s) in ' + deptName;
        } else {
            infoEl.textContent = 'Showing all ' + master.length + ' employee(s)';
        }
    }
}
function previewPayroll() {
    const cycleId = document.getElementById('genPayCycleId').value;
    const period = getSelectedPeriod();
    if (!cycleId) { ModalSystem.warning('Please select a Pay Cycle'); return; }
    if (!period) { ModalSystem.warning('Please select a Payroll Period'); return; }
    const btn = event.target.closest('button');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Computing...';
    const payload = { action: 'preview', pay_cycle_id: cycleId, period_start: period.start, period_end: period.end,
        department_id: document.getElementById('genDepartmentId').value || null,
        employee_id: document.getElementById('genEmployeeId').value || null };
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-eye"></i> Preview Payroll';
        if (!data.success) { ModalSystem.error(data.message || 'Preview failed'); return; }
        renderPreview(data);
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-eye"></i> Preview Payroll'; ModalSystem.error('Error computing preview'); });
}

function renderPreview(data) {
    const container = document.getElementById('previewContainer');
    container.style.display = 'block';
    document.getElementById('previewMeta').textContent = formatPeriod(data.period_start, data.period_end) + (data.pay_cycle_name ? ' · ' + data.pay_cycle_name : '');
    const items = data.items || [];
    const errors = data.errors || [];
    // Stash the preview selection so the per-row "Details" modal can show the
    // exact period / pay cycle used for these calculations.
    window.__previewMeta = {
        period_start: data.period_start,
        period_end: data.period_end,
        period_label: formatPeriod(data.period_start, data.period_end),
        pay_cycle_name: data.pay_cycle_name || null
    };
    // Stash the rows so the preview search bar can re-render a filtered view
    // instantly without re-running the (expensive) preview computation.
    window.__previewItems = items;
    window.__previewErrors = errors;

    document.getElementById('previewTotal').textContent = money(data.total_payout || 0);
    let warn = '';
    if (errors.length) warn = `${errors.length} employee(s) skipped (need pay cycle / salary structure)`;
    if (items.length && errors.length) warn = items.length + ' eligible · ' + errors.length + ' skipped (need pay cycle / salary structure)';
    document.getElementById('previewWarnings').textContent = warn;

    renderPreviewRows();
}

/* Render the preview table rows, honouring the current search term typed into
   the preview search bar. Builds the rows fresh from the stashed preview
   results so filtering works instantly without re-fetching from the server. */
function renderPreviewRows() {
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    const items = window.__previewItems || [];
    const errors = window.__previewErrors || [];
    const periodLabel = window.__previewMeta ? window.__previewMeta.period_label : '';
    const q = (document.getElementById('previewSearch').value || '').trim().toLowerCase();

    const matches = (obj) => {
        if (!q) return true;
        const name = ((obj.first_name || '') + ' ' + (obj.last_name || '')).toLowerCase();
        const code = (obj.employee_code || obj.employee_id || '').toLowerCase();
        return name.indexOf(q) !== -1 || code.indexOf(q) !== -1;
    };

    const totalRows = items.length + errors.length;
    let visibleCount = 0;

    if (!items.length && !errors.length) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;">No employees found for this selection.</td></tr>';
        updatePreviewCount(visibleCount, totalRows);
        return;
    }

    // Eligible employees (computed payroll)
    items.forEach(item => {
        if (!matches(item)) return;
        visibleCount++;
        const tr = document.createElement('tr');
        const dataAttr = 'data-item="' + escapeHtml(JSON.stringify(item)) + '"';
        tr.innerHTML = `
            <td><div class="emp-cell"><div class="avatar">${getInitialsLite(item.first_name + ' ' + item.last_name)}</div>
                <div><div class="emp-name">${escapeHtml(item.first_name + ' ' + item.last_name)}</div>
                <div class="emp-id mono">${escapeHtml(item.employee_code)}</div></div></div></td>
            <td style="white-space:nowrap;">${escapeHtml(periodLabel)}</td>
            <td style="font-size:11px;">${escapeHtml(item.structure_name)}<br><span class="badge success">Eligible</span></td>
            <td>${item.attendance.expected_working_days}</td>
            <td>${item.attendance.paid_days}</td>
            <td>${item.attendance.lop_days}</td>
            <td class="mono">${money(item.gross_pay)}</td>
            <td class="mono">${money(item.deductions_amount)}</td>
            <td class="mono"><b>${money(item.net_salary)}</b></td>
            <td><button class="btn sm" ${dataAttr} data-period="${escapeHtml(periodLabel)}" onclick="showPreviewItem(this)">Details</button></td>`;
        tbody.appendChild(tr);
    });

    // Skipped employees (visible so the department list is obvious)
    errors.forEach(item => {
        if (!matches(item)) return;
        visibleCount++;
        const tr = document.createElement('tr');
        const badge = item.error === 'no_cycle'
            ? '<span class="badge warning">No Pay Cycle</span>'
            : '<span class="badge gray">No Structure</span>';
        tr.innerHTML = `
            <td><div class="emp-cell"><div class="avatar">${getInitialsLite(item.first_name + ' ' + item.last_name)}</div>
                <div><div class="emp-name">${escapeHtml(item.first_name + ' ' + item.last_name)}</div>
                <div class="emp-id mono">${escapeHtml(item.employee_id)}</div></div></div></td>
            <td style="white-space:nowrap;">${escapeHtml(periodLabel)}</td>
            <td style="font-size:11px;">${badge}<br><span style="font-size:10px;color:var(--muted);">${escapeHtml(item.message)}</span></td>
            <td colspan="7" style="font-size:11px;color:var(--muted);">Skipped - will not be included in payroll</td>`;
        tbody.appendChild(tr);
    });

    if (!visibleCount) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;">No employees match your search.</td></tr>';
    }
    updatePreviewCount(visibleCount, totalRows);
}

/* Show a small live count of visible vs total preview rows. */
function updatePreviewCount(visible, total) {
    const el = document.getElementById('previewCount');
    if (el) el.textContent = visible + ' of ' + total + ' employees';
}

/* Triggered by the preview search bar (oninput). */
function filterPreviewRows() {
    renderPreviewRows();
}

/* Show the complete payroll breakdown for one employee in the preview.
   Uses ONLY the already-computed preview record (data-item) so the modal
   always matches exactly what the preview table shows for the period. */
function showPreviewItem(btn) {
    let item = null;
    try { item = JSON.parse(btn.getAttribute('data-item') || 'null'); } catch (e) { item = null; }
    if (!item) { ModalSystem.error('Preview data unavailable. Please re-run Preview Payroll.'); return; }

    const meta = window.__previewMeta || {};
    const periodLabel = btn.getAttribute('data-period') || meta.period_label || '';
    const att = item.attendance || {};
    const pr = Number(item.proration_factor || 1);
    const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
    const fmtNum = (v) => Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

    let earnRows = '';
    // Prefer the engine-computed payable amounts (identical to what is saved
    // into the generated payroll) so the modal and payroll always match.
    const earns = (item.payable_earnings || []).length ? item.payable_earnings : (item.earnings || []);
    earns.forEach(e => {
        const monthly = Number(e.amount || 0);
        const payable = (e.payable_amount !== undefined && e.payable_amount !== null) ? Number(e.payable_amount) : monthly * pr;
        earnRows += `<tr><td>${escapeHtml(e.name)}</td><td class="mono" style="text-align:right;">${fmt(monthly)}</td><td class="mono" style="text-align:right;">${fmt(payable)}</td></tr>`;
    });
    let dedRows = '';
    const deds = (item.payable_deductions || []).length ? item.payable_deductions : (item.deductions || []);
    deds.forEach(d => {
        const monthly = Number(d.amount || 0);
        const payable = (d.payable_amount !== undefined && d.payable_amount !== null) ? Number(d.payable_amount) : monthly * pr;
        dedRows += `<tr><td>${escapeHtml(d.name)}</td><td class="mono" style="text-align:right;">${fmt(monthly)}</td><td class="mono" style="text-align:right;">${fmt(payable)}</td></tr>`;
    });
    if (!earnRows) earnRows = '<tr><td colspan="3" style="text-align:center;color:var(--muted);">No earning components configured in this salary structure.</td></tr>';
    if (!dedRows) dedRows = '<tr><td colspan="3" style="text-align:center;color:var(--muted);">No deduction components configured in this salary structure.</td></tr>';

    // Gross = total earnings, Net = Gross - Deductions (never Gross == Net).
    const grossMonthly = Number(item.gross_monthly || 0);
    const dedMonthly = Number(item.deductions_monthly || 0);
    const grossPay = Number(item.gross_pay || 0);
    const dedPay = Number(item.deductions_amount || 0);
    const netPay = Number(item.net_salary || 0);

    const attRows = `
        <tr><td>Working Days</td><td class="mono" style="text-align:right;">${fmtNum(att.expected_working_days)}</td><td></td></tr>
        <tr><td>Present Days</td><td class="mono" style="text-align:right;">${fmtNum(att.present_days)}</td><td></td></tr>
        <tr><td>Half Days</td><td class="mono" style="text-align:right;">${fmtNum(att.half_days)}</td><td></td></tr>
        <tr><td>Paid Days</td><td class="mono" style="text-align:right;"><b>${fmtNum(att.paid_days)}</b></td><td></td></tr>
        <tr><td>LOP Days</td><td class="mono" style="text-align:right;"><b>${fmtNum(att.lop_days)}</b></td><td></td></tr>
        ${item.has_conflict ? '<tr><td colspan="3" style="color:var(--warning);font-size:12px;"><i class="bi bi-exclamation-triangle"></i> Review flags present (leave/attendance conflict)</td></tr>' : ''}`;

    const html = `
    <div style="font-size:13px;line-height:1.6;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
            <div>
                <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Employee Details</div>
                <div><b>${escapeHtml(item.first_name + ' ' + item.last_name)}</b> <span class="mono" style="font-size:11px;color:var(--muted);">${escapeHtml(item.employee_code || '')}</span></div>
                <div><b>Department:</b> ${escapeHtml(item.department_name || '-')} &nbsp; <b>Designation:</b> ${escapeHtml(item.position || '-')}</div>
                <div><b>Pay Cycle:</b> ${escapeHtml(meta.pay_cycle_name || '-')}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Payroll Period</div>
                <div><b>${escapeHtml(periodLabel)}</b></div>
                <div><b>Structure:</b> ${escapeHtml(item.structure_name || '-')} &nbsp; <b>Annual CTC:</b> <span class="mono">${fmt(item.annual_ctc)}</span></div>
                <div style="font-size:12px;color:var(--muted);">Proration factor (paid &divide; working days): ${fmtNum(pr)}</div>
            </div>
        </div>

        <table style="width:100%;font-size:13px;border-collapse:collapse;margin-bottom:10px;">
            <tr style="border-bottom:1px solid var(--border);">
                <th style="text-align:left;padding:6px 4px;">Attendance</th>
                <th style="text-align:right;padding:6px 4px;"></th>
                <th style="text-align:right;padding:6px 4px;"></th>
            </tr>
            ${attRows}
        </table>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <table style="width:100%;font-size:13px;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px solid var(--border);">
                    <th style="text-align:left;padding:6px 4px;">Earnings</th>
                    <th style="text-align:right;padding:6px 4px;">Monthly</th>
                    <th style="text-align:right;padding:6px 4px;">Payable</th>
                </tr></thead>
                <tbody>
                    ${earnRows}
                    <tr style="border-top:1px solid var(--border);">
                        <td><b>Gross Salary (Total Earnings)</b></td>
                        <td class="mono" style="text-align:right;">${fmt(grossMonthly)}</td>
                        <td class="mono" style="text-align:right;font-weight:700;">${fmt(grossPay)}</td>
                    </tr>
                </tbody>
            </table>
            <table style="width:100%;font-size:13px;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px solid var(--border);">
                    <th style="text-align:left;padding:6px 4px;">Deductions</th>
                    <th style="text-align:right;padding:6px 4px;">Monthly</th>
                    <th style="text-align:right;padding:6px 4px;">Payable</th>
                </tr></thead>
                <tbody>
                    ${dedRows}
                    <tr style="border-top:1px solid var(--border);">
                        <td><b>Total Deductions</b></td>
                        <td class="mono" style="text-align:right;">${fmt(dedMonthly)}</td>
                        <td class="mono" style="text-align:right;font-weight:700;">${fmt(dedPay)}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding:14px;background:var(--accent-ink);border-radius:var(--radius);color:#fff;">
            <div>
                <div style="font-size:14px;">Net Salary</div>
                <div style="font-size:11px;opacity:0.85;">Gross Pay &minus; Total Deductions = ${fmt(grossPay)} &minus; ${fmt(dedPay)}</div>
            </div>
            <div style="font-size:22px;font-weight:700;">${fmt(netPay)}</div>
        </div>
    </div>`;

    ModalSystem.html(html, { title: 'Payroll Details · ' + (item.first_name + ' ' + item.last_name), width: '900px' });
}

function generatePayroll() {
    const cycleId = document.getElementById('genPayCycleId').value;
    const period = getSelectedPeriod();
    if (!cycleId) { ModalSystem.warning('Please select a Pay Cycle'); return; }
    if (!period) { ModalSystem.warning('Please select a Payroll Period'); return; }
    ModalSystem.confirm({
        message: 'Generate payroll for <b>' + formatPeriod(period.start, period.end) + '</b>?<br><br>This will create or finalize the payroll run for the selected employees.',
        confirmText: 'Generate Payroll'
    }).then(ok => {
        if (!ok) return;
        const payload = { action: 'generate', pay_cycle_id: cycleId, period_start: period.start, period_end: period.end,
            department_id: document.getElementById('genDepartmentId').value || null,
            employee_id: document.getElementById('genEmployeeId').value || null };
        fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json())
        .then(data => {
            if (data.success) { ModalSystem.success(data.message + (data.warnings ? ` (${data.warnings} warnings)` : ''), 'Payroll Generated'); setTimeout(() => switchTab('history'), 1200); }
            else ModalSystem.error(data.message || 'Could not generate payroll');
        })
        .catch(() => ModalSystem.error('Error generating payroll'));
    });
}

/* ============================ SALARY COMPONENTS ============================ */

function openComponentModal(id) {
    if (id) {
        fetch('api/payroll.php?components').then(r => r.json()).then(data => {
            const comp = (data.components || []).find(c => c.id == id);
            if (!comp) return;
            document.getElementById('compId').value = comp.id;
            document.getElementById('compName').value = comp.name;
            document.getElementById('compCode').value = comp.code || '';
            document.getElementById('compType').value = comp.type;
            document.getElementById('compMethod').value = comp.calculation_method || 'fixed';
            document.getElementById('compDefaultValue').value = comp.default_value || 0;
            document.getElementById('compPercentage').value = comp.percentage || 0;
            document.getElementById('compBasis').value = comp.percentage_based_on || 'ctc';
            document.getElementById('compFrequency').value = comp.frequency || 'monthly';
            document.getElementById('compDisplayOrder').value = comp.display_order || 0;
            document.getElementById('compTaxable').checked = !!comp.is_taxable;
            toggleCompMethodFields();
            document.getElementById('componentModal').style.display = 'flex';
        });
        return;
    }
    document.getElementById('compId').value = '';
    document.getElementById('compName').value = '';
    document.getElementById('compCode').value = '';
    document.getElementById('compType').value = 'earning';
    document.getElementById('compMethod').value = 'fixed';
    document.getElementById('compDefaultValue').value = 0;
    document.getElementById('compPercentage').value = 0;
    document.getElementById('compFrequency').value = 'monthly';
    document.getElementById('compDisplayOrder').value = 0;
    document.getElementById('compTaxable').checked = false;
    toggleCompMethodFields();
    document.getElementById('componentModal').style.display = 'flex';
}
function closeComponentModal() { document.getElementById('componentModal').style.display = 'none'; }
function toggleCompMethodFields() {
    const m = document.getElementById('compMethod').value;
    document.getElementById('compFixedField').style.display = (m === 'fixed') ? 'block' : 'none';
    const showPct = (m === 'percentage');
    document.getElementById('compPercentField').style.display = showPct ? 'block' : 'none';
    document.getElementById('compBasisField').style.display = showPct ? 'block' : 'none';
}
function saveComponent() {
    const name = document.getElementById('compName').value.trim();
    if (!name) { ModalSystem.warning('Component name is required'); return; }
    const payload = {
        action: 'save_component',
        id: document.getElementById('compId').value || null,
        name: name,
        code: document.getElementById('compCode').value.trim(),
        type: document.getElementById('compType').value,
        calculation_method: document.getElementById('compMethod').value,
        default_value: document.getElementById('compDefaultValue').value,
        percentage: document.getElementById('compPercentage').value,
        percentage_based_on: document.getElementById('compBasis').value,
        frequency: document.getElementById('compFrequency').value,
        display_order: document.getElementById('compDisplayOrder').value,
        is_taxable: document.getElementById('compTaxable').checked ? 1 : 0
    };
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(data => {
        if (data.success) { closeComponentModal(); location.reload(); }
        else ModalSystem.error(data.message || 'Could not save component');
    })
    .catch(() => ModalSystem.error('Error saving component'));
}
function deleteComponent(id) {
    ModalSystem.confirm({ message: 'Delete this salary component? Components in use by structures will become unavailable.', destructive: true, confirmText: 'Delete' })
    .then(ok => {
        if (!ok) return;
        ModalSystem.warning('Deletion of components is disabled to preserve structure integrity. Set it to inactive instead, or manage via database.');
    });
}
/* ============================ SALARY STRUCTURES ============================ */

function openStructureModal() {
    document.getElementById('structId').value = '';
    document.getElementById('structName').value = '';
    document.getElementById('structDescription').value = '';
    document.getElementById('structComponentsBody').innerHTML = '';
    addStructureComponentRow();
    document.getElementById('structModal').style.display = 'flex';
}
function closeStructureModal() { document.getElementById('structModal').style.display = 'none'; }

function editStructureComponents(id) {
    fetch(`api/payroll.php?structure_id=${id}`).then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error(data.message || 'Could not load structure'); return; }
        const s = data.structure;
        document.getElementById('structId').value = s.id;
        document.getElementById('structName').value = s.name;
        document.getElementById('structDescription').value = s.description || '';
        const body = document.getElementById('structComponentsBody');
        body.innerHTML = '';
        (s.components || []).forEach(c => addStructureComponentRow(c));
        if (!(s.components || []).length) addStructureComponentRow();
        document.getElementById('structModal').style.display = 'flex';
    }).catch(() => ModalSystem.error('Error loading structure'));
}

function addStructureComponentRow(comp) {
    const body = document.getElementById('structComponentsBody');
    const comps = window.__ALL_COMPONENTS || [];
    const tr = document.createElement('tr');
    const selected = comp ? comp.salary_component_id : '';
    let options = '<option value="">Select component</option>';
    comps.forEach(c => {
        options += `<option value="${c.id}" ${String(selected) === String(c.id) ? 'selected' : ''}>${escapeHtml(c.name)} (${c.type === 'earning' ? 'Earning' : 'Deduction'})</option>`;
    });
    const method = comp ? (comp.calculation_method || 'fixed') : 'fixed';
    const value = comp ? (comp.value != null ? comp.value : (comp.default_value || 0)) : 0;
    const unit = (comp && method === 'percentage') ? '% (of ' + escapeHtml(comp.percentage_based_on || 'CTC') + ')' : '₹';
    tr.innerHTML = `
        <td><select class="sc-comp" onchange="updateStructureRowMethod(this)">${options}</select></td>
        <td class="sc-method mono" style="font-size:11px;">${escapeHtml(method)}</td>
        <td>
            <div style="display:flex;align-items:center;gap:6px;">
                <span class="sc-unit" style="font-size:11px;color:var(--muted);">${unit}</span>
                <input type="number" class="sc-value" step="0.01" value="${value}" style="width:110px;">
            </div>
        </td>
        <td><div class="icon-btn" onclick="this.closest('tr').remove()" style="color:var(--danger);" title="Remove"><i class="bi bi-x"></i></div></td>`;
    body.appendChild(tr);
}

function updateStructureRowMethod(select) {
    const comps = window.__ALL_COMPONENTS || [];
    const c = comps.find(x => x.id == select.value);
    const tr = select.closest('tr');
    if (!c) { tr.querySelector('.sc-method').textContent = '-'; tr.querySelector('.sc-unit').textContent = ''; return; }
    tr.querySelector('.sc-method').textContent = c.calculation_method || 'fixed';
    tr.querySelector('.sc-unit').textContent = (c.calculation_method === 'percentage') ? '% (of ' + (c.percentage_based_on || 'CTC') + ')' : '₹';
}

function saveStructure() {
    const name = document.getElementById('structName').value.trim();
    if (!name) { ModalSystem.warning('Structure name is required'); return; }
    const comps = [];
    document.querySelectorAll('#structComponentsBody tr').forEach(tr => {
        const compId = tr.querySelector('.sc-comp').value;
        const val = tr.querySelector('.sc-value').value;
        if (compId) comps.push({ salary_component_id: compId, value: val });
    });
    if (!comps.length) { ModalSystem.warning('Add at least one component to the structure'); return; }
    const payload = {
        action: 'save_structure', id: document.getElementById('structId').value || null,
        name: name, description: document.getElementById('structDescription').value,
        components: comps
    };
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json()).then(data => {
        if (data.success) { closeStructureModal(); location.reload(); }
        else ModalSystem.error(data.message || 'Could not save structure');
    }).catch(() => ModalSystem.error('Error saving structure'));
}
function openAssignStructureModal(structureId) {
    document.getElementById('asEmployeeId').value = '';
    document.getElementById('asStructureId').value = structureId || '';
    document.getElementById('asAnnualCtc').value = '';
    document.getElementById('asEffectiveDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('asCtcPreview').textContent = '';
    document.getElementById('assignStructureModal').style.display = 'flex';
}
function closeAssignStructureModal() { document.getElementById('assignStructureModal').style.display = 'none'; }
function updateAsCtcPreview() {
    const ctc = parseFloat(document.getElementById('asAnnualCtc').value) || 0;
    document.getElementById('asCtcPreview').textContent = ctc ? 'Monthly CTC: ₹' + (ctc / 12).toLocaleString('en-IN', {maximumFractionDigits: 2}) : '';
}
function saveStructureAssignment() {
    const employeeId = document.getElementById('asEmployeeId').value;
    const structureId = document.getElementById('asStructureId').value;
    const ctc = document.getElementById('asAnnualCtc').value;
    const effective = document.getElementById('asEffectiveDate').value;
    if (!employeeId) { ModalSystem.warning('Please select an employee'); return; }
    if (!structureId) { ModalSystem.warning('Please select a structure'); return; }
    if (!ctc || parseFloat(ctc) <= 0) { ModalSystem.warning('Annual CTC is required'); return; }
    if (!effective) { ModalSystem.warning('Effective date is required'); return; }
    const payload = { action: 'assign_structure', employee_id: employeeId, structure_id: structureId, annual_ctc: ctc, effective_date: effective };
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json()).then(data => {
        if (data.success) { closeAssignStructureModal(); ModalSystem.success('Salary structure assigned. Historical periods will use the applicable structure.'); loadAssignmentHistory(); refreshStructureAssignmentCounts(); }
        else ModalSystem.error(data.message || 'Could not assign structure');
    }).catch(() => ModalSystem.error('Error assigning structure'));
}

/* ================= ASSIGNED EMPLOYEES (view/search/remove) ================= */
let __seEmployees = [];
function showStructureAssigned(structureId) {
    if (!structureId) return;
    document.getElementById('seStructureId').value = structureId;
    document.getElementById('seSearch').value = '';
    document.getElementById('structureEmployeesModal').style.display = 'flex';
    document.getElementById('structureEmployeesBody').innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted);">Loading...</td></tr>';
    fetch(`api/payroll.php?structure_employees&structure_id=${structureId}`)
        .then(r => r.json()).then(data => {
            if (!data.success) { ModalSystem.error(data.message || 'Could not load assigned employees'); closeStructureAssignedModal(); return; }
            __seEmployees = data.employees || [];
            renderAssignedEmployeesRows();
        }).catch(() => { ModalSystem.error('Error loading assigned employees'); closeStructureAssignedModal(); });
}
function closeStructureAssignedModal() { document.getElementById('structureEmployeesModal').style.display = 'none'; }
function renderAssignedEmployeesRows() {
    const body = document.getElementById('structureEmployeesBody');
    const q = (document.getElementById('seSearch').value || '').trim().toLowerCase();
    const filtered = __seEmployees.filter(e => {
        if (!q) return true;
        return ((e.first_name || '') + ' ' + (e.last_name || '')).toLowerCase().indexOf(q) !== -1 ||
            (e.emp_code || '').toLowerCase().indexOf(q) !== -1 ||
            (e.department_name || '').toLowerCase().indexOf(q) !== -1 ||
            (e.position || '').toLowerCase().indexOf(q) !== -1;
    });
    document.getElementById('seCountBadge').textContent = __seEmployees.length + ' assigned';
    if (!__seEmployees.length) {
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted);">No employees are currently assigned to this structure.</td></tr>';
        return;
    }
    if (!filtered.length) {
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted);">No employees match your search.</td></tr>';
        return;
    }
    let html = '';
    filtered.forEach(e => {
        const name = (e.first_name || '') + ' ' + (e.last_name || '');
        const dept = e.department_name || '-';
        const desig = e.position || '-';
        const overrideTag = Number(e.has_override) > 0 ? ' <span class="badge warning" title="This employee has individual salary customizations">Custom</span>' : '';
        html += `<tr>
            <td><div class="emp-cell"><div class="avatar">${getInitialsLite(name)}</div>
                <div><div class="emp-name">${escapeHtml(name)}</div></div></div></td>
            <td class="mono">${escapeHtml(e.emp_code || '')}</td>
            <td style="font-size:12px;">${escapeHtml(desig)}${overrideTag}</td>
            <td>${escapeHtml(dept)}</td>
            <td>
                <div class="actions">
                    <div class="icon-btn" onclick="openEmployeeSalaryModal(${e.employee_id})" title="Edit employee salary">
                        <i class="bi bi-pencil"></i>
                    </div>
                    <div class="icon-btn" onclick="removeStructureAssignment(${e.assignment_id}, ${e.employee_id})" title="Remove employee" style="color:var(--danger);">
                        <i class="bi bi-trash"></i>
                    </div>
                </div>
            </td>
        </tr>`;
    });
    body.innerHTML = html;
}
function removeStructureAssignment(assignmentId, employeeId) {
    const structureId = document.getElementById('seStructureId').value;
    if (!employeeId || !structureId) return;
    const emp = __seEmployees.find(x => x.assignment_id === assignmentId);
    const name = emp ? ((emp.first_name || '') + ' ' + (emp.last_name || '')).trim() : 'this employee';
    ModalSystem.confirm({
        message: 'Remove <b>' + escapeHtml(name) + '</b> from this salary structure?<br><br><span style="font-size:12px;color:var(--muted);">Only the salary-structure assignment is removed. The employee record and historical payroll records are kept.</span>',
        destructive: true,
        confirmText: 'Remove'
    }).then(ok => {
        if (!ok) return;
        fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'unassign_structure', employee_id: employeeId, structure_id: structureId }) })
        .then(r => r.json()).then(data => {
            if (!data.success) { ModalSystem.error(data.message || 'Could not remove assignment'); return; }
            ModalSystem.success(data.message || 'Assignment removed');
            __seEmployees = __seEmployees.filter(e => e.assignment_id !== assignmentId);
            renderAssignedEmployeesRows();
            updateStructureBadge(structureId, data.assignment_count);
            loadAssignmentHistory();
        }).catch(() => ModalSystem.error('Error removing assignment'));
    });
}
/* Refresh the "Assigned To" counts in the structure table from the DB. */
function refreshStructureAssignmentCounts() {
    fetch('api/payroll.php?structures')
        .then(r => r.json()).then(data => {
            if (!data.success || !data.structures) return;
            data.structures.forEach(s => {
                const tr = document.querySelector('tr[data-structure-id="' + s.id + '"]');
                if (!tr) return;
                const badge = tr.querySelector('.assigned-count');
                if (badge) badge.textContent = s.assignment_count;
            });
        }).catch(() => {});
}
function updateStructureBadge(structureId, count) {
    const tr = document.querySelector('tr[data-structure-id="' + structureId + '"]');
    if (!tr) return;
    const badge = tr.querySelector('.assigned-count');
    if (badge) badge.textContent = count;
}
/* =============== EMPLOYEE SALARY OVERRIDE (edit one employee only) =============== */
let __empSalComponents = [];
let __empSalDefaultSum = null;
let __empSalOverrideSum = null;
function openEmployeeSalaryModal(employeeId) {
    const structureId = document.getElementById('seStructureId').value;
    if (!employeeId || !structureId) return;
    document.getElementById('empSalEmployeeId').value = employeeId;
    document.getElementById('empSalStructureId').value = structureId;
    document.getElementById('empSalComponentsBody').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:30px;color:var(--muted);">Loading...</td></tr>';
    document.getElementById('employeeSalaryModal').style.display = 'flex';
    fetch(`api/payroll.php?employee_components&structure_id=${structureId}&employee_id=${employeeId}`)
        .then(r => r.json()).then(data => {
            if (!data.success) { ModalSystem.error(data.message || 'Could not load employee salary'); closeEmployeeSalaryModal(); return; }
            const a = data.assignment || {};
            const name = (a.first_name || '') + ' ' + (a.last_name || '');
            document.getElementById('empSalTitle').textContent = 'Edit Salary — ' + name + ' (' + (a.emp_code || '') + ')';
            document.getElementById('empSalAnnualCtc').value = a.annual_ctc || '';
            document.getElementById('empSalStructureName').value = a.structure_name || '';
            __empSalComponents = data.components || [];
            __empSalDefaultSum = data.computed_default || null;
            __empSalOverrideSum = data.computed_override || null;
            renderEmpSalComponentsBody();
        }).catch(() => { ModalSystem.error('Error loading employee salary'); closeEmployeeSalaryModal(); });
}
function closeEmployeeSalaryModal() { document.getElementById('employeeSalaryModal').style.display = 'none'; }
function renderEmpSalComponentsBody() {
    const body = document.getElementById('empSalComponentsBody');
    if (!__empSalComponents || !__empSalComponents.length) {
        body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:30px;color:var(--muted);">No components found for this structure.</td></tr>';
        return;
    }
    let html = '';
    __empSalComponents.forEach(c => {
        const isEarning = c.type === 'earning';
        html += `<tr>
            <td>${escapeHtml(c.name)} <span class="badge ${isEarning ? 'success' : 'warning'}" style="margin-left:6px;">${isEarning ? 'Earning' : 'Deduction'}</span></td>
            <td class="mono" style="text-align:right;">${money(c.structure_monthly)}</td>
            <td style="text-align:right;">
                <input type="number" min="0" step="0.01" style="width:130px;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter';background:var(--bg);color:var(--ink);text-align:right;"
                       value="${c.employee_monthly !== null && c.employee_monthly !== undefined ? c.employee_monthly : ''}"
                       placeholder="${money(c.structure_monthly)}"
                       data-cid="${c.salary_component_id}"
                       oninput="renderEmpSalSummary()">
            </td>
        </tr>`;
    });
    body.innerHTML = html;
    renderEmpSalSummary();
}
function onEmpSalCtcChange() { renderEmpSalSummary(); }
function renderEmpSalSummary() {
    let gross = 0, ded = 0;
    document.querySelectorAll('#empSalComponentsBody input[data-cid]').forEach(inp => {
        const cid = parseInt(inp.getAttribute('data-cid'), 10);
        const comp = (__empSalComponents || []).find(x => x.salary_component_id === cid);
        if (!comp) return;
        const val = inp.value.trim();
        const amt = val === '' ? (comp.structure_monthly || 0) : (parseFloat(val) || 0);
        if (comp.type === 'deduction') ded += amt; else gross += amt;
    });
    document.getElementById('empSalGross').textContent = money(gross);
    document.getElementById('empSalDeductions').textContent = money(ded);
    document.getElementById('empSalNet').textContent = money(gross - ded);
}
function buildEmpSalPayload() {
    const components = [];
    document.querySelectorAll('#empSalComponentsBody input[data-cid]').forEach(inp => {
        const cid = parseInt(inp.getAttribute('data-cid'), 10);
        const val = inp.value.trim();
        components.push({ salary_component_id: cid, monthly_amount: val === '' ? null : parseFloat(val) });
    });
    return {
        employee_id: parseInt(document.getElementById('empSalEmployeeId').value, 10),
        structure_id: parseInt(document.getElementById('empSalStructureId').value, 10),
        annual_ctc: parseFloat(document.getElementById('empSalAnnualCtc').value) || 0,
        components
    };
}
function saveEmployeeOverride() {
    const payload = buildEmpSalPayload();
    if (!payload.employee_id || !payload.structure_id) return;
    if (!payload.annual_ctc || payload.annual_ctc <= 0) { ModalSystem.warning('Annual CTC is required'); return; }
    const body = { action: 'save_employee_override' };
    Object.keys(payload).forEach(k => body[k] = payload[k]);
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error(data.message || 'Could not save salary customization'); return; }
        ModalSystem.success(data.message);
        document.getElementById('empSalGross').textContent = money(data.computed.gross);
        document.getElementById('empSalDeductions').textContent = money(data.computed.total_deductions);
        document.getElementById('empSalNet').textContent = money(data.computed.net);
        __empSalOverrideSum = data.computed;
        // Refresh the assigned list so the "Custom" tag stays in sync, and reload history.
        showStructureAssigned(parseInt(document.getElementById('empSalStructureId').value, 10));
        loadAssignmentHistory();
    }).catch(() => ModalSystem.error('Error saving salary customization'));
}
function viewStructureSummary(id) {
    fetch(`api/payroll.php?structure_id=${id}`).then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error('Error loading structure'); return; }
        const s = data.structure;
        let html = `<strong>${escapeHtml(s.name)}</strong><br><br>`;
        if (parseFloat(s.annual_ctc || 0) > 0 && s.computed) {
            const c = s.computed;
            html += '<div style="font-size:12px;color:var(--muted);">Structure-level CTC: ' + money(s.annual_ctc) + '</div>';
            html += '<b>Monthly Earnings</b><br>';
            (c.earnings || []).forEach(e => { html += `&nbsp;&nbsp;${escapeHtml(e.name)}: ${money(e.amount)}<br>`; });
            html += `<b>Gross:</b> ${money(c.gross)}<br><br>`;
            html += '<b>Monthly Deductions</b><br>';
            (c.deductions || []).forEach(d => { html += `&nbsp;&nbsp;${escapeHtml(d.name)}: ${money(d.amount)}<br>`; });
            html += `<b>Total Deductions:</b> ${money(c.total_deductions)}<br><br>`;
            html += `<b style="font-size:15px;">Net Salary: ${money(c.net)}</b>`;
        } else {
            html += '<div style="font-size:12px;color:var(--muted);">Monthly amounts are calculated per employee from their assigned Annual CTC.<br>Salary component percentages are the same for everyone assigned to this structure.</div><br>';
            html += '<b>Components</b><br>';
            (s.components || []).forEach(x => {
                const method = x.calculation_method || 'fixed';
                const value = x.value != null ? x.value : (x.default_value || 0);
                if (method === 'percentage') {
                    html += `&nbsp;&nbsp;${escapeHtml(x.name)}: ${parseFloat(value)}% of ${escapeHtml(x.percentage_based_on || 'CTC')}<br>`;
                } else {
                    html += `&nbsp;&nbsp;${escapeHtml(x.name)}: Fixed ${money(value)}<br>`;
                }
            });
        }
        ModalSystem.info(html, 'Structure Computation');
    }).catch(() => ModalSystem.error('Error loading structure'));
}

function deleteSalaryStructure(id) {
    if (!id) return;
    // Guard against a stale cache of the modal-system script being served.
    if (typeof ModalSystem === 'undefined' || !ModalSystem.confirm) { if (window.confirm) { if (!window.confirm('Delete this salary structure?')) return; } else { return; } }
    ModalSystem.confirm({
        message: 'Delete this salary structure?<br><br><span style="font-size:12px;color:var(--muted);">Structures assigned to active employees or used by payroll history cannot be deleted. Historical (inactive) assignment records are cleaned up automatically.</span>',
        destructive: true,
        confirmText: 'Delete'
    }).then(ok => {
        if (!ok) return;
        fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_structure', structure_id: id }) })
        .then(r => r.json()).then(data => {
            if (data.success) { ModalSystem.success(data.message); setTimeout(() => location.reload(), 600); }
            else ModalSystem.error(data.message || 'Could not delete structure');
        }).catch(() => ModalSystem.error('Error deleting structure'));
    });
}

function loadAssignmentHistory() {
    const body = document.getElementById('assignmentHistoryBody');
    if (!body) return;
    fetch('api/payroll.php?employee_structures')
    .then(r => r.json()).then(data => {
        const rows = data.assignments || [];
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;">' +
                '<div class="empty-state"><i class="bi bi-clock-history"></i><p>No assignments yet. Assign a structure to an employee to begin.</p></div></td></tr>';
            return;
        }
        let html = '';
        rows.forEach(a => {
            const name = (a.first_name || '') + ' ' + (a.last_name || '');
            const dept = a.department_name || '-';
            html += `<tr>
                <td><div class="emp-cell"><div class="avatar">${getInitialsLite(name)}</div>
                    <div><div class="emp-name">${escapeHtml(name)}</div>
                    <div class="emp-id mono">${escapeHtml(a.emp_code || '')}</div></div></div></td>
                <td>${escapeHtml(a.structure_name)}</td>
                <td class="mono">${money(a.annual_ctc)}</td>
                <td class="mono">${a.effective_date}</td>
                <td><span class="badge ${a.is_active ? 'success' : 'gray'}">${a.is_active ? 'Active' : 'Inactive'}</span></td>
                <td><div class="icon-btn" onclick="deleteSalaryAssignment(${a.id})" title="Delete assignment" style="color:var(--danger);"><i class="bi bi-trash"></i></div></td>
            </tr>`;
        });
        body.innerHTML = html;
    }).catch(() => {});
}

function deleteSalaryAssignment(id) {
    if (!id) return;
    if (typeof ModalSystem === 'undefined' || !ModalSystem.confirm) { if (window.confirm) { if (!window.confirm('Delete this salary structure assignment?')) return; } else { return; } }
    ModalSystem.confirm({
        message: 'Delete this salary structure assignment?<br><br><span style="font-size:12px;color:var(--muted);">This removes the employee-structure link (and any custom component overrides). Assignments used by payroll history cannot be deleted.</span>',
        destructive: true,
        confirmText: 'Delete'
    }).then(ok => {
        if (!ok) return;
        fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_assignment', assignment_id: id }) })
        .then(r => r.json()).then(data => {
            if (data.success) { ModalSystem.success(data.message); loadAssignmentHistory(); refreshStructureAssignmentCounts(); }
            else ModalSystem.error(data.message || 'Could not delete assignment');
        }).catch(() => ModalSystem.error('Error deleting assignment'));
    });
}
/* ============================ PAYROLL HISTORY ============================ */

function viewRunDetails(runId) {
    fetch(`api/payroll.php?run_id=${runId}`).then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error(data.message || 'Could not load run'); return; }
        const run = data.run;
        let html = `<strong>${escapeHtml(run.name || (run.period_start + ' – ' + run.period_end))}</strong><br>`;
        html += `Period: ${escapeHtml(run.period_start)} to ${escapeHtml(run.period_end)} · ${escapeHtml(run.pay_cycle_name || '-')}<br>`;
        html += `Status: ${escapeHtml(run.status)} · Payment: ${escapeHtml(run.payment_status || 'Unpaid')} · Version: v${run.version || 1}<br><br>`;
        const items = run.items || [];
        if (!items.length) { html += 'No employee records in this run.'; }
        else {
            html += `<b>${items.length} employee(s)</b><table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;">`;
            html += '<tr style="border-bottom:1px solid var(--border);"><th style="text-align:left;padding:4px;">Employee</th><th style="text-align:right;padding:4px;">Gross</th><th style="text-align:right;padding:4px;">Net</th><th style="padding:4px;"></th><th style="padding:4px;"></th></tr>';
            items.forEach(it => {
                html += `<tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:4px;">${escapeHtml(it.first_name + ' ' + it.last_name)} <span class="mono" style="font-size:10px;">${escapeHtml(it.employee_id)}</span></td>
                    <td style="text-align:right;padding:4px;">${money(it.gross_pay)}</td>
                    <td style="text-align:right;padding:4px;"><b>${money(it.net_salary)}</b></td>
                    <td style="padding:4px;">${it.has_conflict ? '<span style="color:var(--warning);font-size:10px;" title="Leave+Attendance conflict">⚠</span>' : ''}</td>
                    <td style="padding:4px;">
                        <button class="btn sm" onclick="generatePayslipForLine(${it.id})">Payslip</button>
                    </td>
                </tr>`;
            });
            html += '</table>';
        }
        ModalSystem.info(html, 'Payroll Run #' + run.id);
    }).catch(() => ModalSystem.error('Error loading run'));
}

function generatePayslipForLine(lineId) {
    // Generate first (this may already be generated; the API reports it).
    fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'generate_payslip', line_item_id: lineId }) })
    .then(() => {
        // Load the payslip data and display it, whether newly created or existing.
        return fetch(`api/payroll.php?line_id=${lineId}`).then(r => r.json());
    })
    .then(data => {
        if (!data.success) { ModalSystem.error(data.message || 'Could not load payslip'); return; }
        const html = buildPayslipHtml(data.item);
        const actions = `
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                <button class="btn primary" onclick="printPayslip(${lineId});"><i class="bi bi-printer"></i> Print / Download</button>
            </div>`;
        ModalSystem.html(html + actions, { title: 'Payslip · ' + (data.item.first_name + ' ' + data.item.last_name), width: '840px', padding: '0px' });
    })
    .catch(() => ModalSystem.error('Error generating / loading payslip'));
}

function regeneratePayroll(runId) {
    ModalSystem.prompt({
        title: 'Regenerate Payroll',
        message: 'Enter a reason for regeneration (required for the audit trail).<br><br><span style="font-size:12px;color:var(--muted);">Payroll will be recomputed from the current salary structure, attendance and leave data.</span>',
        placeholder: 'e.g. Attendance correction for 28 Aug',
        required: true
    }).then(reason => {
        if (!reason) return;
        ModalSystem.confirm({ message: 'Regenerate payroll run with this reason? The previous values are preserved in the version history.', confirmText: 'Regenerate', destructive: true })
        .then(ok => {
            if (!ok) return;
            fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'regenerate', run_id: runId, reason: reason }) })
            .then(r => r.json()).then(data => {
                if (data.success) { ModalSystem.success('Payroll regenerated (v' + data.version + ').'); setTimeout(() => location.reload(), 800); }
                else ModalSystem.error(data.message || 'Could not regenerate');
            }).catch(() => ModalSystem.error('Error regenerating payroll'));
        });
    });
}

function transitionRun(runId, currentStatus) {
    const targets = { 'Generated': ['review', 'Review payroll and mark as Reviewed?'], 'Reviewed': ['approve', 'Approve this payroll?'], 'Approved': ['mark_paid', 'Mark this payroll as Paid? This locks it from further changes.'] };
    const t = targets[currentStatus];
    if (!t) { ModalSystem.warning('No further action available for this status.'); return; }
    ModalSystem.confirm({ message: t[1], confirmText: 'Confirm' }).then(ok => {
        if (!ok) return;
        fetch('api/payroll.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: t[0], run_id: runId }) })
        .then(r => r.json()).then(data => {
            if (data.success) { ModalSystem.success(data.message); setTimeout(() => location.reload(), 800); }
            else ModalSystem.error(data.message || 'Action failed');
        }).catch(() => ModalSystem.error('Error updating payroll status'));
    });
}
/* ============================ PAYSLIP TAB ============================ */

function loadPayslipByRun() {
    const runId = document.getElementById('psRunSelect').value;
    const empSelect = document.getElementById('psEmployeeSelect');
    if (!runId) { empSelect.innerHTML = '<option value="">Select run first...</option>'; document.getElementById('payslipView').innerHTML = '<div class="panel" style="text-align:center;padding:40px;"><div class="empty-state"><i class="bi bi-file-earmark-text"></i><p>Select a payroll run to continue.</p></div></div>'; return; }
    fetch(`api/payroll.php?run_id=${runId}`).then(r => r.json()).then(data => {
        const items = data.success ? (data.run.items || []) : [];
        empSelect.style.pointerEvents = 'auto';
        empSelect.innerHTML = '<option value="">Select employee...</option>';
        items.forEach(it => {
            const opt = document.createElement('option');
            opt.value = it.id;
            opt.textContent = it.first_name + ' ' + it.last_name + ' (' + it.employee_id + ')';
            empSelect.appendChild(opt);
        });
        empSelect.onchange = function() { if (this.value) loadPayslip(this.value); };
        document.getElementById('payslipView').innerHTML = '<div class="panel" style="text-align:center;padding:40px;"><div class="empty-state"><i class="bi bi-people"></i><p>Select an employee to view their payslip.</p></div></div>';
    }).catch(() => ModalSystem.error('Error loading run'));
}

function loadPayslip(lineId) {
    fetch(`api/payroll.php?line_id=${lineId}`).then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error(data.message || 'Could not load payslip'); return; }
        window.currentPayslipItem = data.item;
        renderPayslip(data.item);
        const actions = document.getElementById('payslipActions');
        if (actions) actions.style.display = 'flex';
    }).catch(() => ModalSystem.error('Error loading payslip'));
}
function buildPayslipHtml(item) {
    const snap = item.snapshot || {};
    let earnRows = '';
    ((snap.earnings || []).length ? snap.earnings : []).forEach(e => { earnRows += `<tr><td>${escapeHtml(e.name)}</td><td class="mono" style="text-align:right;">${money(e.amount)}</td></tr>`; });
    let dedRows = '';
    ((snap.deductions || []).length ? snap.deductions : []).forEach(d => { dedRows += `<tr><td>${escapeHtml(d.name)}</td><td class="mono" style="text-align:right;">${money(d.amount)}</td></tr>`; });
    const att = item.attendance_snapshot || {};
    return `
    <div class="payslip" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
        <div class="payslip-head" style="padding:20px;background:var(--accent-ink);color:#fff;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div class="payslip-company" style="font-size:18px;font-weight:700;">Orbit Technologies Pvt. Ltd.</div>
                <div class="payslip-period" style="font-size:13px;opacity:0.85;">Payslip · ${escapeHtml(formatPeriod(item.period_start, item.period_end))}</div>
            </div>
            <span class="badge" style="background:${item.payment_status === 'Paid' ? 'var(--success)' : 'var(--warning)'};color:#fff;">${escapeHtml(item.payment_status || 'Unpaid')}</span>
        </div>
        <div style="padding:20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Employee Details</div>
                    <div style="font-size:13px;line-height:1.8;">
                        <div><b>Name:</b> ${escapeHtml(item.first_name + ' ' + item.last_name)}</div>
                        <div><b>Employee ID:</b> ${escapeHtml(item.employee_id)}</div>
                        <div><b>Department:</b> ${escapeHtml(item.department_name || '-')}</div>
                        <div><b>Designation:</b> ${escapeHtml(item.position || '-')}</div>
                        <div><b>Structure:</b> ${escapeHtml(item.structure_name || (snap.structure_name || '-'))}</div>
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Attendance</div>
                    <div style="font-size:13px;line-height:1.8;">
                        <div><b>Work Pattern:</b> ${escapeHtml(att.work_pattern || '-')}</div>
                        <div><b>Expected Working Days:</b> ${att.expected_working_days || 0}</div>
                        <div><b>Paid Days:</b> ${att.paid_days || 0} &nbsp; <b>LOP:</b> ${att.lop_days || 0}</div>
                        <div><b>Present:</b> ${att.present_days || 0} &nbsp; <b>Half:</b> ${att.half_days || 0} &nbsp; <b>Leave:</b> ${att.leave_days || 0}</div>
                        ${item.has_conflict ? '<div style="color:var(--warning);font-size:12px;"><i class="bi bi-exclamation-triangle"></i> Review flags present</div>' : ''}
                    </div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead><tr><th style="text-align:left;padding:6px 4px;border-bottom:1px solid var(--border);">Earnings</th><th style="text-align:right;padding:6px 4px;border-bottom:1px solid var(--border);">Amount</th></tr></thead>
                    <tbody>
                        ${earnRows || `<tr><td>Basic Pay</td><td class="mono" style="text-align:right;">${money(item.basic_pay)}</td></tr>`}
                        <tr style="border-top:1px solid var(--border);"><td><b>Gross Salary</b></td><td class="mono" style="text-align:right;font-weight:700;">${money(item.gross_pay)}</td></tr>
                    </tbody>
                </table>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead><tr><th style="text-align:left;padding:6px 4px;border-bottom:1px solid var(--border);">Deductions</th><th style="text-align:right;padding:6px 4px;border-bottom:1px solid var(--border);">Amount</th></tr></thead>
                    <tbody>
                        ${dedRows || `<tr><td>Deductions</td><td class="mono" style="text-align:right;">${money(item.deductions_amount)}</td></tr>`}
                        <tr style="border-top:1px solid var(--border);"><td><b>Total Deductions</b></td><td class="mono" style="text-align:right;font-weight:700;">${money(item.deductions_amount)}</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding:14px;background:var(--accent-ink);border-radius:var(--radius);color:#fff;">
                <div style="font-size:14px;">Net Salary</div>
                <div style="font-size:22px;font-weight:700;">${money(item.net_salary)}</div>
            </div>
            <div class="payslip-notice" style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);text-align:center;font-size:11px;color:var(--muted);line-height:1.6;">
                This is a computer-generated payslip and does not require a seal or signature.
            </div>
        </div>
    </div>`;
}

function renderPayslip(item) {
    const view = document.getElementById('payslipView');
    if (view) view.innerHTML = buildPayslipHtml(item);
}

// Render ONLY the payslip into the dedicated print area and print it once.
// Prevents the duplicate/two-page issue caused by printing the whole page
// (which previously included the stacked run-details modal + all UI).
// Accepts either a payslip item object or a payroll line-item ID.
function printPayslip(source) {
    if (typeof source === 'number' || (source && !source.employee_id)) {
        // Treat as a line-item id: load the payslip data first.
        const lineId = source;
        fetch(`api/payroll.php?line_id=${lineId}`).then(r => r.json()).then(data => {
            if (!data.success) { ModalSystem.error(data.message || 'Could not load payslip'); return; }
            doPrintPayslip(data.item);
        }).catch(() => ModalSystem.error('Error loading payslip'));
        return;
    }
    if (source) doPrintPayslip(source);
    else ModalSystem.warning('No payslip is currently selected to print.');
}

function doPrintPayslip(item) {
    const area = document.getElementById('payrollPrintArea');
    if (!area) return;
    area.setAttribute('style', 'display:block;');
    area.innerHTML = buildPayslipHtml(item);
    // Defer to let the browser layout the payslip before showing the dialog.
    setTimeout(function() {
        window.print();
    }, 50);
}

// Download Payslip: opens the print dialog with the payslip only, where the
// user can choose "Save as PDF". Uses the same single-payslip print area.
function downloadPayslip() {
    if (!window.currentPayslipItem) { ModalSystem.warning('No payslip is currently selected to download.'); return; }
    doPrintPayslip(window.currentPayslipItem);
}
/* ============================ PAY CYCLES ============================ */
function addPayCycle() {
    document.getElementById('cycleId').value = '';
    document.getElementById('cycleName').value = '';
    document.getElementById('cycleDescription').value = '';
    document.getElementById('cycleFrequency').value = 'monthly';
    document.getElementById('cycleStartDay').value = '1';
    document.getElementById('cycleEndDay').value = '99';
    document.getElementById('cycleIsDefault').checked = false;
    document.getElementById('cycleModal').style.display = 'flex';
}
function editPayCycle(id) {
    document.getElementById('cycleId').value = id;
    fetch('api/pay_cycles.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.success || !data.pay_cycle) { ModalSystem.error('Could not load pay cycle'); return; }
        const c = data.pay_cycle;
        document.getElementById('cycleName').value = c.name || '';
        document.getElementById('cycleDescription').value = c.description || '';
        document.getElementById('cycleFrequency').value = c.frequency || 'monthly';
        document.getElementById('cycleStartDay').value = c.cycle_start_day || 1;
        document.getElementById('cycleEndDay').value = c.cycle_end_day || 31;
        document.getElementById('cycleIsDefault').checked = !!c.is_default;
        document.getElementById('cycleModal').style.display = 'flex';
    }).catch(() => ModalSystem.error('Error loading pay cycle'));
}
function closePayCycleModal() { document.getElementById('cycleModal').style.display = 'none'; }
function savePayCycle() {
    const name = document.getElementById('cycleName').value.trim();
    if (!name) { ModalSystem.warning('Cycle name is required'); return; }
    const payload = {
        action: 'save_pay_cycle', id: document.getElementById('cycleId').value || null,
        name: name, description: document.getElementById('cycleDescription').value,
        frequency: document.getElementById('cycleFrequency').value,
        cycle_start_day: document.getElementById('cycleStartDay').value,
        cycle_end_day: document.getElementById('cycleEndDay').value,
        is_default: document.getElementById('cycleIsDefault').checked ? 1 : 0
    };
    fetch('api/pay_cycles.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json()).then(data => {
        if (data.success) { closePayCycleModal(); location.reload(); }
        else ModalSystem.error(data.message || 'Could not save pay cycle');
    }).catch(() => ModalSystem.error('Error saving pay cycle'));
}
function deletePayCycle(id) {
    ModalSystem.confirm({ message: 'Delete this pay cycle? This cannot be undone.', destructive: true, confirmText: 'Delete' }).then(ok => {
        if (!ok) return;
        fetch('api/pay_cycles.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_pay_cycle', id: id }) })
        .then(r => r.json()).then(data => { if (data.success) location.reload(); else ModalSystem.error(data.message || 'Could not delete'); })
        .catch(() => ModalSystem.error('Error deleting pay cycle'));
    });
}
function setDefaultCycle(id) { editPayCycle(id); document.getElementById('cycleIsDefault').checked = true; }
function viewPayCycleAssignments(payCycleId) {
    fetch(`api/pay_cycles.php?id=${payCycleId}`).then(r => r.json()).then(data => {
        if (!data.success) { ModalSystem.error('Could not load assignments'); return; }
        const cycle = data.pay_cycle; const assignments = cycle.assignments || [];
        let msg = `<strong>Assignments for: ${escapeHtml(cycle.name)}</strong><br><br>`;
        if (!assignments.length) msg += 'No assignments found.';
        else assignments.forEach((a, i) => { msg += `${i + 1}. ${escapeHtml(a.assignment_name)} (${escapeHtml(a.assignment_type)}) · Priority ${a.priority} · Effective ${a.effective_date}<br>`; });
        ModalSystem.info(msg, 'Pay Cycle Assignments');
    }).catch(() => ModalSystem.error('Error loading assignments'));
}
function generatePayrollPeriods(payCycleId) {
    document.getElementById('periodsPayCycleId').value = payCycleId;
    document.getElementById('periodsStartDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('periodsMonths').value = '6';
    document.getElementById('periodsModal').style.display = 'flex';
}
/* ============================ ASSIGNMENTS ============================ */
function openAssignmentModal() {
    document.getElementById('assignmentRowId').value = '';
    document.getElementById('assignmentAssignTitle').textContent = 'Assign Pay Cycle';
    document.getElementById('assignmentPayCycleId').value = '';
    document.getElementById('assignmentType').value = 'organization';
    document.getElementById('assignmentEffectiveDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('assignmentEndDate').value = '';
    updateAssignmentFields();
    document.getElementById('assignmentModal').style.display = 'flex';
}
function editAssignment(rowId, payCycleId, type, targetId, effectiveDate, endDate) {
    document.getElementById('assignmentRowId').value = rowId || '';
    document.getElementById('assignmentAssignTitle').textContent = 'Edit Pay Cycle Assignment';
    document.getElementById('assignmentPayCycleId').value = payCycleId || '';
    document.getElementById('assignmentType').value = type || 'organization';
    // Set the target based on type
    if (type === 'department') document.getElementById('assignmentDepartmentId').value = targetId || '';
    else if (type === 'role') document.getElementById('assignmentRoleId').value = targetId || '';
    else if (type === 'employee') document.getElementById('assignmentEmployeeId').value = targetId || '';
    document.getElementById('assignmentEffectiveDate').value = effectiveDate || '';
    document.getElementById('assignmentEndDate').value = endDate || '';
    updateAssignmentFields();
    document.getElementById('assignmentModal').style.display = 'flex';
}
function closeAssignmentModal() { document.getElementById('assignmentModal').style.display = 'none'; }
function updateAssignmentFields() {
    const t = document.getElementById('assignmentType').value;
    document.getElementById('departmentField').style.display = t === 'department' ? 'block' : 'none';
    document.getElementById('roleField').style.display = t === 'role' ? 'block' : 'none';
    document.getElementById('employeeField').style.display = t === 'employee' ? 'block' : 'none';
}
function saveAssignment() {
    const type = document.getElementById('assignmentType').value;
    let targetId = null;
    if (type === 'department') targetId = document.getElementById('assignmentDepartmentId').value;
    else if (type === 'role') targetId = document.getElementById('assignmentRoleId').value;
    else if (type === 'employee') targetId = document.getElementById('assignmentEmployeeId').value;
    const payload = {
        action: 'assign_pay_cycle',
        id: document.getElementById('assignmentRowId').value || null,
        pay_cycle_id: document.getElementById('assignmentPayCycleId').value,
        assignment_type: type,
        assignment_id: targetId,
        effective_date: document.getElementById('assignmentEffectiveDate').value,
        end_date: document.getElementById('assignmentEndDate').value || null
    };
    if (!payload.pay_cycle_id) { ModalSystem.warning('Please select a pay cycle'); return; }
    if ((type !== 'organization') && !targetId) { ModalSystem.warning('Please select the assignment target'); return; }
    fetch('api/pay_cycles.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json()).then(data => { if (data.success) { closeAssignmentModal(); ModalSystem.success(data.message || 'Assignment saved'); setTimeout(() => location.reload(), 600); } else ModalSystem.error(data.message || 'Could not save assignment'); })
    .catch(() => ModalSystem.error('Error saving assignment'));
}
function removeAssignment(assignmentId) {
    ModalSystem.confirm({ message: 'Remove this assignment?', destructive: true, confirmText: 'Remove' }).then(ok => {
        if (!ok) return;
        fetch('api/pay_cycles.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'remove_assignment', assignment_id: assignmentId }) })
        .then(r => r.json()).then(data => { if (data.success) location.reload(); else ModalSystem.error(data.message || 'Could not remove'); })
        .catch(() => ModalSystem.error('Error removing assignment'));
    });
}
function closePeriodsModal() { document.getElementById('periodsModal').style.display = 'none'; }
function generatePeriods() {
    const payCycleId = parseInt(document.getElementById('periodsPayCycleId').value);
    const startDate = document.getElementById('periodsStartDate').value;
    const months = parseInt(document.getElementById('periodsMonths').value);
    if (!payCycleId) { ModalSystem.warning('Please select a pay cycle'); return; }
    if (!startDate) { ModalSystem.warning('Start date is required'); return; }
    if (!months || months < 1) { ModalSystem.warning('Enter a valid number of months'); return; }
    fetch('api/pay_cycles.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'generate_periods', pay_cycle_id: payCycleId, start_date: startDate, months_to_generate: months }) })
    .then(r => r.json()).then(data => { if (data.success) { closePeriodsModal(); ModalSystem.success(data.message || 'Periods generated'); setTimeout(() => location.reload(), 700); } else ModalSystem.error(data.message || 'Could not generate periods'); })
    .catch(() => ModalSystem.error('Error generating periods'));
}

/* ============================ INIT ============================ */
window.__ALL_COMPONENTS = <?php echo json_encode($salaryComponents); ?>;
// Server-provided employee -> departments map (for the Generate dept filter).
window.__EMP_DEPTS = <?php echo json_encode($employeeDeptsMap); ?>;
document.addEventListener('DOMContentLoaded', function() {
    loadAssignmentHistory();
    // Capture the full employee option list once (before any department filter
    // mutates the dropdown), so filtering always has the complete master set.
    var empSel = document.getElementById('genEmployeeId');
    if (empSel && !window.__ALL_EMP_OPTIONS) {
        var master = [];
        empSel.querySelectorAll('option[data-depts]').forEach(function(o) {
            master.push({ value: o.value, depts: (o.getAttribute('data-depts') || ''), text: o.textContent });
        });
        window.__ALL_EMP_OPTIONS = master;
    }
});
</script>
