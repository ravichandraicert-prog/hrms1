<?php
/**
 * Pay Cycles API
 * Core PHP HRMS Application
 * Handles recurring pay cycle configuration, assignments, and period generation
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
    require_once __DIR__ . '/../includes/permissions.php';

    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];

    // Parse JSON / form body
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'multipart/form-data') !== false || !empty($_POST)) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
    }
    $action = $data['action'] ?? '';

    // =================================================================
    // GET: List all pay cycles
    // =================================================================
    if ($method === 'GET' && empty($_GET)) {
        if (!hasPermission('payroll', 'read')) { 
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
            exit; 
        }
        
        $payCycles = $db->fetchAll(
            "SELECT pc.*, 
                    (SELECT COUNT(*) FROM pay_cycle_assignments pca WHERE pca.pay_cycle_id = pc.id AND pca.is_active = 1) as assignment_count,
                    (SELECT COUNT(*) FROM payroll_periods pp WHERE pp.pay_cycle_id = pc.id) as period_count,
                    e.first_name as created_by_name, e.last_name as created_by_last_name
             FROM pay_cycles pc
             LEFT JOIN employees e ON pc.created_by = e.id
             ORDER BY pc.is_default DESC, pc.name"
        );
        
        echo json_encode(['success' => true, 'pay_cycles' => $payCycles]);
        exit;
    }

    // =================================================================
    // GET: Single pay cycle with assignments
    // =================================================================
    if ($method === 'GET' && isset($_GET['id'])) {
        if (!hasPermission('payroll', 'read')) { 
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
            exit; 
        }
        
        $payCycle = $db->fetchOne(
            "SELECT pc.*, 
                    e.first_name as created_by_name, e.last_name as created_by_last_name
             FROM pay_cycles pc
             LEFT JOIN employees e ON pc.created_by = e.id
             WHERE pc.id = ?", 
            [(int) $_GET['id']]
        );
        
        if (!$payCycle) {
            echo json_encode(['success' => false, 'message' => 'Pay cycle not found']);
            exit;
        }
        
        // Get assignments
        $assignments = $db->fetchAll(
            "SELECT pca.*, 
                    CASE pca.assignment_type
                        WHEN 'organization' THEN 'All Employees'
                        WHEN 'department' THEN (SELECT name FROM departments WHERE id = pca.assignment_id)
                        WHEN 'role' THEN (SELECT name FROM roles WHERE id = pca.assignment_id)
                        WHEN 'employee' THEN CONCAT((SELECT first_name FROM employees WHERE id = pca.assignment_id), ' ', (SELECT last_name FROM employees WHERE id = pca.assignment_id))
                    END as assignment_name
             FROM pay_cycle_assignments pca
             WHERE pca.pay_cycle_id = ? AND pca.is_active = 1
             ORDER BY pca.priority DESC, pca.effective_date DESC",
            [(int) $_GET['id']]
        );
        
        $payCycle['assignments'] = $assignments;
        
        echo json_encode(['success' => true, 'pay_cycle' => $payCycle]);
        exit;
    }

    // =================================================================
    // GET: Get employee's current pay cycle (with priority resolution)
    // =================================================================
    if ($method === 'GET' && isset($_GET['employee_id'])) {
        $employeeId = (int) $_GET['employee_id'];
        
        // Get employee's department and role
        $employee = $db->fetchOne(
            "SELECT e.*, 
                    (SELECT department_id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1) as primary_department_id
             FROM employees e 
             WHERE e.id = ?",
            [$employeeId]
        );
        
        if (!$employee) {
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
            exit;
        }
        
        // Get applicable assignments in priority order (employee > role > department > organization)
        $assignments = $db->fetchAll(
            "SELECT pca.*, pc.name as pay_cycle_name, pc.frequency, pc.cycle_start_day, pc.cycle_end_day,
                    CASE pca.assignment_type
                        WHEN 'organization' THEN 'Organization Default'
                        WHEN 'department' THEN (SELECT name FROM departments WHERE id = pca.assignment_id)
                        WHEN 'role' THEN (SELECT name FROM roles WHERE id = pca.assignment_id)
                        WHEN 'employee' THEN CONCAT((SELECT first_name FROM employees WHERE id = pca.assignment_id), ' ', (SELECT last_name FROM employees WHERE id = pca.assignment_id))
                    END as assignment_name
             FROM pay_cycle_assignments pca
             JOIN pay_cycles pc ON pca.pay_cycle_id = pc.id
             WHERE pca.is_active = 1
               AND (
                   pca.assignment_type = 'organization' OR
                   (pca.assignment_type = 'department' AND pca.assignment_id = ?) OR
                   (pca.assignment_type = 'role' AND pca.assignment_id = ?) OR
                   (pca.assignment_type = 'employee' AND pca.assignment_id = ?)
               )
               AND (pca.effective_date <= CURDATE() OR pca.effective_date IS NULL)
               AND (pca.end_date IS NULL OR pca.end_date >= CURDATE())
             ORDER BY pca.priority DESC, pca.effective_date DESC",
            [
                $employee['primary_department_id'] ?? 0,
                $employee['role_id'] ?? 0,
                $employeeId
            ]
        );
        
        if (empty($assignments)) {
            // Get default pay cycle
            $defaultCycle = $db->fetchOne("SELECT * FROM pay_cycles WHERE is_default = 1 LIMIT 1");
            if ($defaultCycle) {
                echo json_encode([
                    'success' => true, 
                    'pay_cycle' => $defaultCycle,
                    'assignment_source' => 'organization',
                    'assignment_name' => 'Organization Default'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No pay cycle assigned']);
            }
            exit;
        }
        
        // Return the highest priority assignment
        $activeAssignment = $assignments[0];
        
        echo json_encode([
            'success' => true,
            'pay_cycle' => [
                'id' => $activeAssignment['pay_cycle_id'],
                'name' => $activeAssignment['pay_cycle_name'],
                'frequency' => $activeAssignment['frequency'],
                'cycle_start_day' => $activeAssignment['cycle_start_day'],
                'cycle_end_day' => $activeAssignment['cycle_end_day']
            ],
            'assignment_source' => $activeAssignment['assignment_type'],
            'assignment_name' => $activeAssignment['assignment_name'],
            'effective_date' => $activeAssignment['effective_date']
        ]);
        exit;
    }

    // =================================================================
    // POST handlers
    // =================================================================
    if ($method === 'POST') {

        // ---- Save pay cycle (create/update) ----
        if ($action === 'save_pay_cycle') {
            if (!hasPermission('payroll', 'create') && !hasPermission('payroll', 'update')) { 
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
                exit; 
            }
            
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $name = trim($data['name'] ?? '');
            $description = $data['description'] ?? null;
            $frequency = $data['frequency'] ?? 'monthly';
            $cycleStartDay = (int) ($data['cycle_start_day'] ?? 1);
            $cycleEndDay = (int) ($data['cycle_end_day'] ?? 99);
            $isDefault = isset($data['is_default']) ? (int) $data['is_default'] : 0;
            $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;
            
            if (!$name || !$cycleStartDay || !$cycleEndDay) {
                echo json_encode(['success' => false, 'message' => 'Name, start day, and end day are required']);
                exit;
            }
            
            // Validate day values
            if ($cycleStartDay < 1 || $cycleStartDay > 31) {
                echo json_encode(['success' => false, 'message' => 'Start day must be between 1 and 31']);
                exit;
            }
            
            if ($cycleEndDay != 99 && ($cycleEndDay < 1 || $cycleEndDay > 31)) {
                echo json_encode(['success' => false, 'message' => 'End day must be between 1 and 31, or 99 for last day of month']);
                exit;
            }
            
            $db->beginTransaction();
            
            try {
                // If setting as default, remove default from others
                if ($isDefault) {
                    $db->update('pay_cycles', ['is_default' => 0], '1=1', []);
                }
                
                if ($id) {
                    $db->update('pay_cycles', [
                        'name' => $name,
                        'description' => $description,
                        'frequency' => $frequency,
                        'cycle_start_day' => $cycleStartDay,
                        'cycle_end_day' => $cycleEndDay,
                        'is_default' => $isDefault,
                        'is_active' => $isActive
                    ], 'id = ?', [$id]);
                    $payCycleId = $id;
                } else {
                    $payCycleId = $db->insert('pay_cycles', [
                        'name' => $name,
                        'description' => $description,
                        'frequency' => $frequency,
                        'cycle_start_day' => $cycleStartDay,
                        'cycle_end_day' => $cycleEndDay,
                        'is_default' => $isDefault,
                        'is_active' => $isActive,
                        'created_by' => $user['id']
                    ]);
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'pay_cycle_id' => $payCycleId, 'message' => 'Pay cycle saved successfully']);
            } catch (Exception $e) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'Error saving pay cycle: ' . $e->getMessage()]);
            }
            exit;
        }

        // ---- Delete pay cycle ----
        if ($action === 'delete_pay_cycle') {
            if (!hasPermission('payroll', 'delete')) { 
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
                exit; 
            }
            
            $id = (int) ($data['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Pay cycle ID required']);
                exit;
            }
            
            // Check if pay cycle is used in any payroll periods
            $periodCount = $db->fetchOne("SELECT COUNT(*) as count FROM payroll_periods WHERE pay_cycle_id = ?", [$id]);
            if ($periodCount['count'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete pay cycle with existing payroll periods']);
                exit;
            }
            
            $db->delete('pay_cycle_assignments', 'pay_cycle_id = ?', [$id]);
            $db->delete('pay_cycles', 'id = ?', [$id]);
            
            echo json_encode(['success' => true, 'message' => 'Pay cycle deleted successfully']);
            exit;
        }

        // ---- Assign pay cycle ----
        if ($action === 'assign_pay_cycle') {
            if (!hasPermission('payroll', 'update')) { 
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
                exit; 
            }
            
            $payCycleId = (int) ($data['pay_cycle_id'] ?? 0);
            $assignmentType = $data['assignment_type'] ?? ''; // organization, department, role, employee
            $targetId = isset($data['assignment_id']) ? (int) $data['assignment_id'] : null;
            $effectiveDate = $data['effective_date'] ?? date('Y-m-d');
            $endDate = $data['end_date'] ?? null;
            $rowId = (int) ($data['id'] ?? 0);
            
            if (!$payCycleId || !$assignmentType) {
                echo json_encode(['success' => false, 'message' => 'Pay cycle ID and assignment type are required']);
                exit;
            }
            
            // Set priority based on assignment type
            $priorities = [
                'organization' => 10,
                'department' => 20,
                'role' => 30,
                'employee' => 40
            ];
            $priority = $priorities[$assignmentType] ?? 10;
            
            if ($rowId) {
                // ---- EDIT existing assignment ----
                $existing = $db->fetchOne("SELECT * FROM pay_cycle_assignments WHERE id = ?", [$rowId]);
                if (!$existing) { echo json_encode(['success' => false, 'message' => 'Assignment not found']); exit; }
                $db->update('pay_cycle_assignments', [
                    'pay_cycle_id' => $payCycleId,
                    'assignment_type' => $assignmentType,
                    'assignment_id' => $targetId,
                    'priority' => $priority,
                    'effective_date' => $effectiveDate,
                    'end_date' => $endDate,
                    'is_active' => 1
                ], 'id = ?', [$rowId]);
                // Refresh employee's current cycle if this is an employee assignment
                if ($assignmentType === 'employee' && $targetId) {
                    $db->update('employees', [
                        'current_pay_cycle_id' => $payCycleId,
                        'pay_cycle_assignment_source' => 'employee',
                        'pay_cycle_effective_date' => $effectiveDate
                    ], 'id = ?', [$targetId]);
                } elseif ($existing['assignment_type'] === 'employee' && $existing['assignment_id']) {
                    // Previously an employee assignment now changed type/employee - clear old employee link
                    $db->update('employees', [
                        'current_pay_cycle_id' => null,
                        'pay_cycle_assignment_source' => null,
                        'pay_cycle_effective_date' => null
                    ], 'id = ?', [$existing['assignment_id']]);
                }
                echo json_encode(['success' => true, 'assignment_id' => $rowId, 'message' => 'Assignment updated successfully']);
                exit;
            }
            
            // If organization assignment, deactivate other organization assignments
            if ($assignmentType === 'organization') {
                $db->update('pay_cycle_assignments', 
                    ['is_active' => 0], 
                    'assignment_type = ? AND is_active = 1', 
                    ['organization']
                );
            }
            
            // If employee assignment, deactivate previous employee assignments
            if ($assignmentType === 'employee' && $targetId) {
                $db->update('pay_cycle_assignments', 
                    ['is_active' => 0, 'end_date' => date('Y-m-d', strtotime($effectiveDate . ' -1 day'))], 
                    'assignment_type = ? AND assignment_id = ? AND is_active = 1', 
                    ['employee', $targetId]
                );
            }
            
            $assignmentId = $db->insert('pay_cycle_assignments', [
                'pay_cycle_id' => $payCycleId,
                'assignment_type' => $assignmentType,
                'assignment_id' => $targetId,
                'priority' => $priority,
                'effective_date' => $effectiveDate,
                'end_date' => $endDate,
                'is_active' => 1,
                'created_by' => $user['id']
            ]);
            
            // Update employee's current pay cycle if employee assignment
            if ($assignmentType === 'employee' && $targetId) {
                $db->update('employees', [
                    'current_pay_cycle_id' => $payCycleId,
                    'pay_cycle_assignment_source' => 'employee',
                    'pay_cycle_effective_date' => $effectiveDate
                ], 'id = ?', [$targetId]);
            }
            
            echo json_encode(['success' => true, 'assignment_id' => $assignmentId, 'message' => 'Pay cycle assigned successfully']);
            exit;
        }

        // ---- Remove pay cycle assignment ----
        if ($action === 'remove_assignment') {
            if (!hasPermission('payroll', 'update')) { 
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
                exit; 
            }
            
            $assignmentId = (int) ($data['assignment_id'] ?? 0);
            if (!$assignmentId) {
                echo json_encode(['success' => false, 'message' => 'Assignment ID required']);
                exit;
            }
            
            $assignment = $db->fetchOne("SELECT * FROM pay_cycle_assignments WHERE id = ?", [$assignmentId]);
            if (!$assignment) {
                echo json_encode(['success' => false, 'message' => 'Assignment not found']);
                exit;
            }
            
            // Hard delete the assignment (not a soft-deactivate) so it is
            // completely removed and no longer appears anywhere.
            $db->delete('pay_cycle_assignments', 'id = ?', [$assignmentId]);
            
            // Update employee if this was an employee assignment
            if ($assignment['assignment_type'] === 'employee' && $assignment['assignment_id']) {
                $db->update('employees', [
                    'current_pay_cycle_id' => null,
                    'pay_cycle_assignment_source' => null,
                    'pay_cycle_effective_date' => null
                ], 'id = ?', [$assignment['assignment_id']]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Assignment deleted successfully']);
            exit;
        }

        // ---- Generate payroll periods for a pay cycle ----
        if ($action === 'generate_periods') {
            if (!hasPermission('payroll', 'create')) { 
                echo json_encode(['success' => false, 'message' => 'Access denied'], 403); 
                exit; 
            }
            
            $payCycleId = (int) ($data['pay_cycle_id'] ?? 0);
            $startDate = $data['start_date'] ?? date('Y-m-01'); // Default to current month start
            $monthsToGenerate = (int) ($data['months_to_generate'] ?? 6); // Generate 6 months by default
            
            if (!$payCycleId) {
                echo json_encode(['success' => false, 'message' => 'Pay cycle ID required']);
                exit;
            }
            
            $payCycle = $db->fetchOne("SELECT * FROM pay_cycles WHERE id = ?", [$payCycleId]);
            if (!$payCycle) {
                echo json_encode(['success' => false, 'message' => 'Pay cycle not found']);
                exit;
            }
            
            $generatedPeriods = [];
            $currentDate = new DateTime($startDate);
            
            for ($i = 0; $i < $monthsToGenerate; $i++) {
                // Calculate period start and end based on pay cycle rules
                $periodStart = clone $currentDate;
                $periodStart->setDate((int)$currentDate->format('Y'), (int)$currentDate->format('m'), $payCycle['cycle_start_day']);
                
                $periodEnd = clone $currentDate;
                if ($payCycle['cycle_end_day'] == 99) {
                    // Last day of month
                    $periodEnd->modify('last day of this month');
                } else {
                    $periodEnd->setDate((int)$currentDate->format('Y'), (int)$currentDate->format('m'), $payCycle['cycle_end_day']);
                }
                
                // If end day is before start day, it means the period spans to next month
                if ($periodEnd < $periodStart) {
                    $periodEnd->modify('+1 month');
                    $periodEnd->setDate((int)$periodEnd->format('Y'), (int)$periodEnd->format('m'), $payCycle['cycle_end_day']);
                    if ($payCycle['cycle_end_day'] == 99) {
                        $periodEnd->modify('last day of this month');
                    }
                }
                
                // Check if period already exists
                $existing = $db->fetchOne(
                    "SELECT id FROM payroll_periods 
                     WHERE pay_cycle_id = ? AND period_start = ? AND period_end = ?",
                    [$payCycleId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]
                );
                
                if (!$existing) {
                    $periodName = $periodStart->format('F Y') . ' (' . $payCycle['cycle_start_day'] . 'th-' . 
                                   ($payCycle['cycle_end_day'] == 99 ? 'Last' : $payCycle['cycle_end_day'] . 'th') . ')';
                    
                    $periodId = $db->insert('payroll_periods', [
                        'pay_cycle_id' => $payCycleId,
                        'period_name' => $periodName,
                        'period_start' => $periodStart->format('Y-m-d'),
                        'period_end' => $periodEnd->format('Y-m-d'),
                        'status' => 'Draft'
                    ]);
                    
                    $generatedPeriods[] = [
                        'id' => $periodId,
                        'name' => $periodName,
                        'period_start' => $periodStart->format('Y-m-d'),
                        'period_end' => $periodEnd->format('Y-m-d')
                    ];
                }
                
                // Move to next month
                $currentDate->modify('+1 month');
            }
            
            echo json_encode([
                'success' => true, 
                'generated_count' => count($generatedPeriods),
                'periods' => $generatedPeriods,
                'message' => 'Generated ' . count($generatedPeriods) . ' payroll periods'
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
