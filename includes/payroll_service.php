<?php
/**
 * PayrollService - Centralized Payroll Calculation Engine
 * Core PHP HRMS Application
 *
 * Single source of truth used by Payroll Preview, Generate, History,
 * Regenerate and Payslip. Reuses existing Employee, Salary Structure,
 * Pay Cycle, Work Pattern, Attendance (attendance_daily) and Leave data.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/work_pattern_service.php';
require_once __DIR__ . '/attendance_threshold_service.php';
require_once __DIR__ . '/functions.php';

class PayrollService {

    private $db;
    private $workPatternService;
    private $thresholdService;
    private $currentMonthlyCtc = 0.0;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->workPatternService = new WorkPatternService();
        $this->thresholdService = new AttendanceThresholdService();
    }

    // ================================================================
    // 1. PAY CYCLE RESOLUTION  (spec #8, #9, #30)
    // ================================================================

    public function resolveEmployeePayCycle($employeeId, $asOf = null) {
        $asOf = $asOf ?: date('Y-m-d');
        $emp = $this->db->fetchOne("SELECT id, role_id FROM employees WHERE id = ?", [$employeeId]);
        if (!$emp) return null;

        $deptIds = [];
        $rows = $this->db->fetchAll(
            "SELECT department_id FROM employee_departments WHERE employee_id = ?", [$employeeId]
        );
        foreach ($rows as $r) $deptIds[] = (int) $r['department_id'];

        $assignments = $this->db->fetchAll(
            "SELECT pca.*, pc.name as pay_cycle_name, pc.frequency, pc.cycle_start_day, pc.cycle_end_day, pc.is_default
             FROM pay_cycle_assignments pca
             JOIN pay_cycles pc ON pca.pay_cycle_id = pc.id
             WHERE pca.is_active = 1
               AND (pca.effective_date IS NULL OR pca.effective_date <= ?)
               AND (pca.end_date IS NULL OR pca.end_date >= ?)
             ORDER BY pca.priority DESC, pca.effective_date DESC",
            [$asOf, $asOf]
        );

        foreach ($assignments as $a) {
            if ($a['assignment_type'] === 'employee' && (int) $a['assignment_id'] === (int) $employeeId) return $a;
        }
        foreach ($assignments as $a) {
            if ($a['assignment_type'] === 'role' && (int) $a['assignment_id'] === (int) $emp['role_id']) return $a;
        }
        foreach ($assignments as $a) {
            if ($a['assignment_type'] === 'department' && in_array((int) $a['assignment_id'], $deptIds)) return $a;
        }
        foreach ($assignments as $a) {
            if ($a['assignment_type'] === 'organization') return $a;
        }
        return null;
    }

    /**
     * Single authoritative payroll period generator: returns the payroll
     * period (start/end) for a pay cycle that contains the given date.
     */
    public function getPeriodForDate($cycle, $forDate) {
        $freq = $cycle['frequency'] ?? 'monthly';
        $startDay = (int) ($cycle['cycle_start_day'] ?? 1);
        $endDay = (int) ($cycle['cycle_end_day'] ?? 99);

        if ($freq === 'monthly') {
            $dt = new DateTime($forDate);
            $y = (int) $dt->format('Y'); $m = (int) $dt->format('m');
            $candA = $this->monthlyPeriod($y, $m, $startDay, $endDay);
            $prev = $this->monthlyPeriod($y, $m - 1, $startDay, $endDay);
            foreach ([$prev, $candA] as $c) {
                $cs = $c['start']->format('Y-m-d');
                $ce = $c['end']->format('Y-m-d');
                if ($ce >= $forDate && $cs <= $forDate) {
                    return ['start' => $cs, 'end' => $ce];
                }
            }
            return ['start' => $candA['start']->format('Y-m-d'), 'end' => $candA['end']->format('Y-m-d')];
        }
        return ['start' => date('Y-m-01', strtotime($forDate)), 'end' => date('Y-m-t', strtotime($forDate))];
    }

    private function monthlyPeriod($y, $m, $startDay, $endDay) {
        $start = new DateTime(sprintf('%04d-%02d-01', $y, $m));
        $start->setDate($y, $m, min(max($startDay, 1), (int) $start->format('t')));

        // End day belongs to next month when it precedes the start day (e.g. 16th -> 15th)
        $endMo = ($endDay < $startDay) ? $m + 1 : $m;
        $end = new DateTime(sprintf('%04d-%02d-01', $y, 1));
        $end->modify('+' . ($endMo - 1) . ' months');
        if ($endDay == 99) {
            $end->modify('last day of this month');
        } else {
            $end->setDate((int) $end->format('Y'), (int) $end->format('m'), min(max($endDay, 1), (int) $end->format('t')));
        }
        return ['start' => $start, 'end' => $end];
    }

    public function generatePeriodSeries($cycle, $startDate, $months = 6) {
        $periods = [];
        $cursor = new DateTime($startDate);
        for ($i = 0; $i < (int) $months; $i++) {
            $p = $this->getPeriodForDate($cycle, $cursor->format('Y-m-d'));
            $keys = array_map(fn($x) => $x['start'] . '|' . $x['end'], $periods);
            if (in_array($p['start'] . '|' . $p['end'], $keys)) {
                $cursor->modify('+2 months');
            } else {
                $periods[] = $p;
                $cursor->modify('+1 month');
            }
            if (count($periods) >= (int) $months) break;
        }
        return $periods;
    }

    // ================================================================
    // 2. SALARY STRUCTURE + COMPONENT COMPUTATION  (spec #1-#5, #33)
    // ================================================================

    public function resolveSalaryStructure($employeeId, $asOf = null) {
        $asOf = $asOf ?: date('Y-m-d');
        return $this->db->fetchOne(
            "SELECT ess.id as assignment_id, ess.salary_structure_id, ess.annual_ctc, ess.effective_date,
                    ss.name as structure_name, ss.description as structure_description, ss.is_active as structure_active
             FROM employee_salary_structures ess
             JOIN salary_structures ss ON ess.salary_structure_id = ss.id
             WHERE ess.employee_id = ? AND ess.effective_date <= ?
             ORDER BY ess.effective_date DESC, ess.id DESC LIMIT 1",
            [$employeeId, $asOf]
        );
    }

    public function computeComponents($structureId, $annualCtc, $overrides = null) {
        $monthlyCtc = $annualCtc > 0 ? (float) $annualCtc / 12 : 0.0;
        $this->currentMonthlyCtc = $monthlyCtc;
        $overrideMap = [];
        foreach (($overrides ?? []) as $cid => $amt) {
            $overrideMap[(int) $cid] = (float) $amt;
        }

        $rows = $this->db->fetchAll(
            "SELECT sc.id as component_id, sc.name, sc.code, sc.type, sc.calculation_method,
                    sc.default_value, sc.percentage, sc.percentage_based_on, sc.frequency,
                    ssc.value as struct_value, sc.is_taxable, sc.display_order
             FROM salary_structure_components ssc
             JOIN salary_components sc ON ssc.salary_component_id = sc.id
             WHERE ssc.salary_structure_id = ?
             ORDER BY sc.display_order ASC, sc.name",
            [$structureId]
        );

        $monthlies = [];
        $earnings = [];
        $deductions = [];
        $rem = [];

        foreach ($rows as $c) {
            $code = strtolower(trim((string) ($c['code'] ?: str_replace(' ', '_', $c['name']))));
            if ($code === '') $code = 'comp_' . $c['component_id'];
            $row = array_merge($c, ['_code' => $code]);
            $method = strtolower((string) ($c['calculation_method'] ?? ''));
            $isFormula = in_array($method, ['formula', 'balancing']) || strtolower((string) ($c['percentage_based_on'] ?? '')) === 'balancing';
            if ($isFormula) { $rem[] = $row; continue; }
            $cid = (int) $c['component_id'];
            if (isset($overrideMap[$cid])) {
                // Employee-specific override: use the fixed monthly amount.
                $monthlies[$code] = round($overrideMap[$cid], 2);
            } else {
                $monthlies[$code] = round($this->resolveComponentAmount($row, $monthlies), 2);
            }
            $this->appendComponentResult($row, $monthlies[$code], $earnings, $deductions);
        }

        foreach ($rem as $row) {
            // An employee override on a balancing/formula component pins it.
            $cid = (int) $row['component_id'];
            if (isset($overrideMap[$cid])) {
                $monthlies[$row['_code']] = round($overrideMap[$cid], 2);
                $this->appendComponentResult($row, $monthlies[$row['_code']], $earnings, $deductions);
                continue;
            }
            $otherEarnings = 0.0; foreach ($earnings as $e) $otherEarnings += (float) $e['amount'];
            $otherDeductions = 0.0; foreach ($deductions as $d) $otherDeductions += (float) $d['amount'];
            $amount = max(0.0, $monthlyCtc - $otherEarnings);
            if (strtolower((string) $row['type']) === 'deduction') {
                $amount = max(0.0, $monthlyCtc - $otherEarnings - $otherDeductions);
            }
            $monthlies[$row['_code']] = round($amount, 2);
            $this->appendComponentResult($row, $monthlies[$row['_code']], $earnings, $deductions);
        }

        $gross = 0.0; foreach ($earnings as $e) $gross += (float) $e['amount'];
        $ded = 0.0; foreach ($deductions as $d) $ded += (float) $d['amount'];

        return [
            'earnings' => $earnings,
            'deductions' => $deductions,
            'gross' => round($gross, 2),
            'total_deductions' => round($ded, 2),
            'net' => round($gross - $ded, 2),
            'monthly_ctc' => round($monthlyCtc, 2),
            'overrides' => $overrideMap,
        ];
    }

    /**
     * Load active employee-specific component overrides for one employee on a
     * salary structure. Returns a map of salary_component_id -> monthly_amount.
     */
    public function getEmployeeOverrides($employeeId, $structureId) {
        $rows = $this->db->fetchAll(
            "SELECT salary_component_id, monthly_amount
             FROM employee_salary_overrides
             WHERE employee_id = ? AND salary_structure_id = ? AND is_active = 1",
            [$employeeId, $structureId]
        );
        $map = [];
        foreach ($rows as $r) $map[(int) $r['salary_component_id']] = (float) $r['monthly_amount'];
        return $map;
    }

    private function codeUsed($list, $code) {
        foreach ($list as $x) if (($x['code'] ?? '') === $code) return true;
        return false;
    }

    private function resolveComponentAmount($c, $monthlies) {
        $method = strtolower((string) ($c['calculation_method'] ?? 'flat'));
        $frequency = strtolower((string) ($c['frequency'] ?? 'monthly'));
        $value = (float) ($c['struct_value'] !== null && $c['struct_value'] !== '' ? $c['struct_value'] : ($c['default_value'] ?? 0));

        if ($method === 'percentage' || $method === 'percent') {
            $percent = 0.0;
            if ($value > 0) $percent = $value;
            if ($percent == 0.0) $percent = (float) ($c['percentage'] ?? 0);
            if ($percent == 0.0) return 0.0;

            // A percentage component whose per-structure value exceeds 100 is an
            // absolute amount (the admin typed ₹, not %). Interpret it as an
            // ANNUAL amount ÷ 12, capped at the monthly CTC so earnings always
            // stay within the employee's cost-to-company.
            if ($percent > 100) {
                $amount = $value / 12.0;
                $monthlyCtc = (float) $this->currentMonthlyCtc;
                if ($monthlyCtc > 0) $amount = min($amount, $monthlyCtc);
                return $amount;
            }

            $basis = $this->resolveBasis($c, $monthlies);
            $amount = $basis['monthly'] * ($percent / 100.0);
            if ($basis['annual']) $amount = ($basis['annual'] * ($percent / 100.0)) / 12.0;
            return $amount;
        }
        if ($frequency === 'annual') return $value / 12.0;
        return $value;
    }

    private function resolveBasis($c, $monthlies) {
        $ref = strtolower(trim((string) ($c['percentage_based_on'] ?? 'ctc')));
        if ($ref === 'ctc') return ['monthly' => (float) $this->currentMonthlyCtc, 'annual' => 0.0];
        if ($ref === 'gross') {
            $m = 0.0; foreach ($monthlies as $v) $m += (float) $v;
            return ['monthly' => $m, 'annual' => 0.0];
        }
        if (isset($monthlies[$ref])) return ['monthly' => (float) $monthlies[$ref], 'annual' => 0.0];
        foreach ($monthlies as $k => $v) {
            if (strpos(strtolower($k), $ref) !== false) return ['monthly' => (float) $v, 'annual' => 0.0];
        }
        return ['monthly' => 0.0, 'annual' => 0.0];
    }

    private function appendComponentResult($c, $amount, &$earnings, &$deductions) {
        $entry = [
            'component_id' => (int) $c['component_id'],
            'name' => $c['name'],
            'code' => $c['_code'],
            'amount' => $amount,
            'type' => $c['type'],
            'is_taxable' => (int) ($c['is_taxable'] ?? 0),
        ];
        if (strtolower((string) $c['type']) === 'deduction') {
            $deductions[] = $entry;
        } else {
            $earnings[] = $entry;
        }
    }

    // ================================================================
    // 3. ATTENDANCE + LEAVE SUMMARY  (spec #10-#15)
    // Uses the same central data as Reports: attendance_daily statuses,
    // leave_applications, and WorkPatternService expected days.
    // ================================================================

    public function getAttendanceSummary($employeeId, $start, $end) {
        $expectedWorkingDays = $this->workPatternService->calculateWorkingDays($employeeId, $start, $end);

        $attRows = $this->db->fetchAll(
            "SELECT ad.attendance_date, ad.status, ad.total_worked_minutes
             FROM attendance_daily ad
             WHERE ad.employee_id = ? AND ad.attendance_date BETWEEN ? AND ?",
            [$employeeId, $start, $end]
        );
        $attByDate = [];
        foreach ($attRows as $a) $attByDate[$a['attendance_date']] = $a;

        $leaveRows = $this->db->fetchAll(
            "SELECT la.start_date, la.end_date, la.status AS leave_status, la.total_days,
                    lt.name AS leave_type_name, lt.is_paid AS leave_is_paid
             FROM leave_applications la
             JOIN leave_types lt ON la.leave_type_id = lt.id
             WHERE la.employee_id = ? AND la.status IN ('Approved','Pending','Cancelled','Rejected')
               AND (la.start_date BETWEEN ? AND ? OR la.end_date BETWEEN ? AND ?)",
            [$employeeId, $start, $end, $start, $end]
        );

        $present = 0.0; $half = 0.0; $absent = 0.0; $leaveDays = 0.0; $weekOffDays = 0;
        $conflicts = [];
        $attRecordCount = count($attByDate);

        $leaveCoverMap = [];
        foreach ($leaveRows as $l) {
            if (strtolower((string) $l['leave_status']) === 'approved' && (int) ($l['leave_is_paid'] ?? 1) === 1) {
                $d0 = new DateTime($l['start_date']); $d1 = new DateTime($l['end_date']);
                $d1->modify('+1 day');
                foreach (new DatePeriod($d0, new DateInterval('P1D'), $d1) as $d) {
                    $leaveCoverMap[$d->format('Y-m-d')] = $l['leave_type_name'];
                }
            }
        }

        $d0 = new DateTime($start); $d1 = new DateTime($end);
        $d1->modify('+1 day');
        foreach (new DatePeriod($d0, new DateInterval('P1D'), $d1) as $date) {
            $ds = $date->format('Y-m-d');
            if (!$this->workPatternService->isWorkingDay($employeeId, $ds)) {
                // Employee-specific Week Off (same Work Pattern logic as Reports).
                // Count it only when the day is not already recorded as an attended
                // day/leave so it is never double-counted (e.g. an employee who
                // actually worked/clocked-in on a scheduled Week Off, or an approved
                // paid leave covering a Week Off - leave/attendance takes priority).
                $woSt = isset($attByDate[$ds]) ? strtolower((string) $attByDate[$ds]['status']) : null;
                if (!in_array($woSt, ['present', 'half day', 'on leave', 'leave', 'first half leave', 'second half leave', 'absent'], true)) {
                    $weekOffDays++;
                }
                continue;
            }

            $st = null;
            if (isset($attByDate[$ds])) $st = strtolower((string) $attByDate[$ds]['status']);
            $hasApprovedLeave = isset($leaveCoverMap[$ds]);
            $hasAnyLeave = $this->leaveOnDate($leaveRows, $ds);

            if ($hasAnyLeave && $st !== null && in_array($st, ['present', 'half day'])) {
                $conflicts[] = [
                    'date' => $ds, 'type' => 'leave_attendance_conflict',
                    'message' => 'Leave record exists but attendance marked ' . ucwords($st),
                    'leave_status' => $this->leaveStatusOnDate($leaveRows, $ds),
                ];
            }
            if ($hasApprovedLeave && $st === 'absent') {
                $conflicts[] = [
                    'date' => $ds, 'type' => 'leave_vs_absent_conflict',
                    'message' => 'Approved leave exists but attendance marked Absent',
                    'leave_status' => 'Approved',
                ];
            }

            if ($st === 'holiday' || $st === 'week off') continue;

            if ($st === 'present' || $st === 'on leave' || $st === 'leave') {
                $present += 1.0;
            } elseif ($st === 'half day' || $st === 'first half leave' || $st === 'second half leave') {
                $half += 0.5;
                if (in_array($st, ['first half leave', 'second half leave'])) $leaveDays += 0.5;
            } elseif ($st === 'absent') {
                $absent += 1.0;
            } elseif ($hasApprovedLeave) {
                $present += 1.0;
                $leaveDays += 1.0;
            } elseif (isset($attByDate[$ds])) {
                $present += 1.0;
            }
        }

        // No attendance data captured at all => full-pay assumption.
        if ($attRecordCount === 0 && count($leaveRows) === 0) {
            $present = (float) $expectedWorkingDays;
            $absent = 0.0;
        }

        $paidDays = $present + $half;
        $lopDays = max(0.0, $expectedWorkingDays - $paidDays);

        $wp = $this->workPatternService->getEmployeeWorkPattern($employeeId, $start);
        return [
            'expected_working_days' => (float) $expectedWorkingDays,
            'week_off_days' => (int) $weekOffDays,
            'present_days' => round($present, 1),
            'half_days' => round($half, 1),
            'absent_days' => round($absent, 1),
            'leave_days' => round($leaveDays, 1),
            'paid_days' => round($paidDays, 1),
            'lop_days' => round($lopDays, 1),
            'work_pattern' => $wp['name'] ?? 'Default',
            'conflicts' => $conflicts,
            'has_conflict' => count($conflicts) > 0,
        ];
    }

    private function leaveOnDate($leaveRows, $date) {
        foreach ($leaveRows as $l) {
            if ($l['start_date'] <= $date && $date <= $l['end_date']) return true;
        }
        return false;
    }

    private function leaveStatusOnDate($leaveRows, $date) {
        foreach ($leaveRows as $l) {
            if ($l['start_date'] <= $date && $date <= $l['end_date']) return $l['leave_status'];
        }
        return null;
    }

    // ================================================================
    // 4. MAIN CALCULATOR  (spec #16, #17, #32)
    // ================================================================

    public function calculatePayroll($employee, $periodStart, $periodEnd) {
        $empId = is_array($employee) ? $employee['id'] : $employee;

        // Validate the employee has a pay cycle covering this period first,
        // so the preview can clearly report "no cycle" instead of silently
        // dropping the employee.
        $cyc = $this->resolveEmployeePayCycle($empId, $periodEnd);
        if (!$cyc) {
            return ['error' => 'no_cycle', 'message' => 'Employee has no Pay Cycle assignment covering this period'];
        }
        // Verify the resolved cycle actually spans the requested period
        // (guards against employees assigned to a different cycle).
        $p = $this->getPeriodForDate($cyc, $periodEnd);
        if ((string) ($p['start'] ?? '') !== $periodStart || (string) ($p['end'] ?? '') !== $periodEnd) {
            return ['error' => 'no_cycle', 'message' => 'Employee is assigned to a different Pay Cycle for this period'];
        }

        $struct = $this->resolveSalaryStructure($empId, $periodEnd);
        if (!$struct) {
            return ['error' => 'no_structure', 'message' => 'Employee has no Salary Structure assigned for this period'];
        }
        if (!is_array($employee)) {
            $employee = $this->db->fetchOne(
                "SELECT e.id, e.first_name, e.last_name, e.employee_id, e.date_of_joining, e.position,
                        d.name as department_name
                 FROM employees e
                 LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
                 LEFT JOIN departments d ON ed.department_id = d.id
                 WHERE e.id = ?", [$empId]
            );
        }

        $overrides = $this->getEmployeeOverrides($empId, (int) $struct['salary_structure_id']);
        $components = $this->computeComponents($struct['salary_structure_id'], (float) $struct['annual_ctc'], $overrides);
        $attendance = $this->getAttendanceSummary($empId, $periodStart, $periodEnd);

        $expected = (float) $attendance['expected_working_days'];
        $paid = (float) $attendance['paid_days'];
        $proration = $expected > 0 ? min(1.0, $paid / $expected) : 1.0;

        // ------------------------------------------------------------------
        // PERIOD PAYROLL FIGURES (single source of truth for this payroll
        // period - attendance/leave are already scoped to $periodStart..$periodEnd,
        // never the calendar month unless that IS the selected pay cycle).
        //
        //   Gross Pay    = total earnings before deductions (salary structure)
        //   Deductions   = total deduction components
        //   Net Pay      = Gross Pay - Deductions      (never Gross == Net)
        //
        // All three are pro-rated by paid/expected working days so mid-cycle
        // joins, leaves and LOP days are handled consistently.
        // ------------------------------------------------------------------
        $grossMonthly = 0.0;
        foreach ($components['earnings'] as $e) $grossMonthly += (float) $e['amount'];
        $dedMonthly = 0.0;
        foreach ($components['deductions'] as $d) $dedMonthly += (float) $d['amount'];
        $grossMonthly = round($grossMonthly, 2);
        $dedMonthly = round($dedMonthly, 2);
        $netMonthly = round($grossMonthly - $dedMonthly, 2);

        $grossPay = round($grossMonthly * $proration, 2);
        $deductionsPay = round($dedMonthly * $proration, 2);
        $netPay = round($netMonthly * $proration, 2);
        $basic = $this->basicFromComponents($components['earnings']);

        // Component-level PAYABLE amounts for the period (same rounding used
        // when the payroll run / payslip is saved), so the preview Details
        // view and the generated payroll always agree exactly.
        $payableEarnings = [];
        foreach ($components['earnings'] as $e) {
            $pe = array_merge($e, ['payable_amount' => round((float) $e['amount'] * $proration, 2)]);
            $payableEarnings[] = $pe;
        }
        $payableDeductions = [];
        foreach ($components['deductions'] as $d) {
            $pd = array_merge($d, ['payable_amount' => round((float) $d['amount'] * $proration, 2)]);
            $payableDeductions[] = $pd;
        }

        return [
            'employee_id' => $employee['id'],
            'employee_code' => $employee['employee_id'] ?? '',
            'first_name' => $employee['first_name'] ?? '',
            'last_name' => $employee['last_name'] ?? '',
            'department_name' => $employee['department_name'] ?? null,
            'position' => $employee['position'] ?? null,
            'date_of_joining' => $employee['date_of_joining'] ?? null,
            'structure_id' => (int) $struct['salary_structure_id'],
            'structure_name' => $struct['structure_name'],
            'annual_ctc' => (float) $struct['annual_ctc'],
            'earnings' => $components['earnings'],
            'deductions' => $components['deductions'],
            'payable_earnings' => $payableEarnings,
            'payable_deductions' => $payableDeductions,
            'basic_pay' => $basic,
            'allowances' => round($grossMonthly - $basic, 2),
            'gross_monthly' => $grossMonthly,
            'deductions_monthly' => $dedMonthly,
            'net_monthly' => $netMonthly,
            'gross_pay' => $grossPay,
            'deductions_amount' => $deductionsPay,
            'net_salary' => $netPay,
            'proration_factor' => round($proration, 4),
            'attendance' => $attendance,
            'has_conflict' => $attendance['has_conflict'],
            'conflicts' => $attendance['conflicts'],
        ];
    }

    private function basicFromComponents($earnings) {
        foreach ($earnings as $e) {
            if (strpos(strtolower((string) $e['code']), 'basic') !== false) return (float) $e['amount'];
            if (in_array(strtolower((string) $e['name']), ['basic', 'basic pay', 'basic salary'])) return (float) $e['amount'];
        }
        return 0.0;
    }

    // ================================================================
    // 5. GENERATION / REGENERATION / PAYSLIP  (spec #18-#26, #37)
    // ================================================================

    public function computePayrollBatch($employees, $start, $end) {
        $items = [];
        $errors = [];
        foreach ($employees as $emp) {
            $r = $this->calculatePayroll($emp, $start, $end);
            if (isset($r['error'])) {
                $errors[] = [
                    'first_name' => $emp['first_name'] ?? '',
                    'last_name' => $emp['last_name'] ?? '',
                    'employee_id' => $emp['employee_id'] ?? '',
                    'error' => $r['error'],
                    'message' => $r['message'],
                ];
                continue;
            }
            $items[] = $r;
        }
        $totalNet = 0.0; foreach ($items as $i) $totalNet += (float) $i['net_salary'];
        return ['items' => $items, 'errors' => $errors, 'total_payout' => round($totalNet, 2)];
    }

    public function resolvePeriodEmployees($payCycle, $start, $end, $departmentId = null, $employeeId = null) {
        $cycleId = $payCycle ? (int) $payCycle['id'] : null;
        $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_id, e.date_of_joining, e.position
                FROM employees e
                LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
                WHERE e.is_active = 1";
        $params = [];
        if ($departmentId) { $sql .= " AND ed.department_id = ?"; $params[] = $departmentId; }
        if ($employeeId) { $sql .= " AND e.id = ?"; $params[] = $employeeId; }
        $rows = $this->db->fetchAll($sql, $params);
        if (!$cycleId) return $rows;

        // When a department filter is EXPLICITLY selected, return every active
        // employee in that department so the preview can show them and clearly
        // flag anyone not on this pay cycle (or without a structure). Payroll
        // generation still only keeps cycle-members (calculatePayroll classifies
        // the rest as errors and skips them).
        if ($departmentId) {
            return $rows;
        }

        // Default (no department filter): only employees whose resolved pay
        // cycle matches this run's cycle.
        $out = [];
        foreach ($rows as $r) {
            $cyc = $this->resolveEmployeePayCycle($r['id'], $end);
            if ($cyc && (int) ($cyc['pay_cycle_id'] ?? $cyc['id']) === $cycleId) $out[] = $r;
        }
        return $out;
    }

    public function existingRunForPeriod($payCycleId, $start, $end) {
        return $this->db->fetchOne(
            "SELECT pr.id, pr.status, pr.payment_status, pr.version
             FROM payroll_runs pr
             WHERE pr.pay_cycle_id = ? AND pr.period_start = ? AND pr.period_end = ?
             ORDER BY pr.id DESC LIMIT 1",
            [$payCycleId, $start, $end]
        );
    }

    public function savePayrollRun($computed, $meta) {
        $start = $meta['period_start']; $end = $meta['period_end'];
        $payCycleId = $meta['pay_cycle_id'] ?? null;
        $payrollPeriodId = $meta['payroll_period_id'] ?? null;
        $name = $meta['name'] ?? (date('M Y', strtotime($end)));
        $userId = $meta['generated_by'] ?? null;
        $existing = $meta['existing_run'] ?? null;
        $reason = $meta['reason'] ?? null;
        $items = $computed['items'];

        if ($existing) {
            $runId = (int) $existing['id'];
            $newVersion = (int) ($existing['version'] ?? 1) + 1;
            $this->db->update('payroll_runs', [
                'status' => 'Generated', 'payment_status' => 'Unpaid',
                'version' => $newVersion, 'regenerate_reason' => $reason,
                'total_employees' => count($items), 'total_payout' => $computed['total_payout'],
                'generated_by' => $userId, 'generated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$runId]);
            $this->db->delete('payroll_line_items', 'payroll_run_id = ?', [$runId]);
        } else {
            $this->db->insert('payroll_runs', [
                'name' => $name, 'period_start' => $start, 'period_end' => $end,
                'pay_cycle_id' => $payCycleId, 'payroll_period_id' => $payrollPeriodId,
                'status' => 'Generated', 'payment_status' => 'Unpaid', 'version' => 1,
                'total_employees' => count($items), 'total_payout' => $computed['total_payout'],
                'generated_by' => $userId, 'generated_at' => date('Y-m-d H:i:s'),
            ]);
            $runId = (int) $this->db->lastInsertId();
            $newVersion = 1;
        }

        foreach ($items as $i) {
            $lineId = (int) $this->db->insert('payroll_line_items', [
                'payroll_run_id' => $runId, 'employee_id' => $i['employee_id'],
                'salary_structure_id' => $i['structure_id'], 'pay_cycle_id' => $payCycleId,
                'basic_pay' => $i['basic_pay'], 'allowances' => $i['allowances'],
                'deductions' => $i['deductions_amount'] ?? 0, 'gross_pay' => $i['gross_pay'], 'net_salary' => $i['net_salary'],
                'paid_days' => $i['attendance']['paid_days'], 'unpaid_days' => $i['attendance']['lop_days'],
                'expected_working_days' => $i['attendance']['expected_working_days'],
                'present_days' => $i['attendance']['present_days'], 'half_days' => $i['attendance']['half_days'],
                'leave_days' => $i['attendance']['leave_days'], 'lop_days' => $i['attendance']['lop_days'],
                'has_conflict' => $i['has_conflict'] ? 1 : 0,
                'conflicts' => json_encode($i['conflicts']),
                'salary_snapshot' => json_encode([
                    'structure_id' => $i['structure_id'], 'structure_name' => $i['structure_name'],
                    'annual_ctc' => $i['annual_ctc'], 'earnings' => $i['earnings'], 'deductions' => $i['deductions'],
                    'gross_monthly' => $i['gross_monthly'], 'net_monthly' => $i['net_monthly'],
                ]),
                'attendance_snapshot' => json_encode($i['attendance']),
            ]);

            foreach (array_merge($i['earnings'], $i['deductions']) as $c) {
                $this->db->insert('payroll_component_details', [
                    'payroll_line_item_id' => $lineId,
                    'salary_component_id' => $c['component_id'],
                    'amount' => round((float) $c['amount'] * (float) ($i['proration_factor'] ?? 1), 2),
                ]);
            }

            $this->db->insert('payroll_versions', [
                'payroll_run_id' => $runId, 'line_item_id' => $lineId, 'employee_id' => $i['employee_id'],
                'version' => $newVersion, 'action' => $existing ? 'regenerated' : 'generated',
                'reason' => $reason, 'net_salary' => $i['net_salary'],
                'snapshot' => json_encode(['gross' => $i['gross_pay'], 'deductions' => $i['deductions'], 'net' => $i['net_salary']]),
                'changed_by' => $userId,
            ]);
        }
        return $runId;
    }

    public function generatePayslip($lineItemId, $userId = null) {
        $item = $this->db->fetchOne(
            "SELECT pl.*, pr.name as run_name, pr.period_start, pr.period_end, pr.payment_status
             FROM payroll_line_items pl
             JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
             WHERE pl.id = ?", [$lineItemId]
        );
        if (!$item) return ['success' => false, 'message' => 'Payroll line item not found'];

        $existing = $this->db->fetchOne("SELECT id FROM payslips WHERE payroll_line_item_id = ?", [$lineItemId]);
        if ($existing) {
            return ['success' => false, 'message' => 'Payslip already generated for this payroll record', 'payslip_id' => $existing['id']];
        }

        $snapshot = json_decode((string) $item['salary_snapshot'], true) ?: [];

        $this->db->insert('payslips', [
            'payroll_run_id' => $item['payroll_run_id'],
            'payroll_line_item_id' => $lineItemId,
            'employee_id' => $item['employee_id'],
            'period_start' => $item['period_start'],
            'period_end' => $item['period_end'],
            'net_salary' => $item['net_salary'],
            'gross_salary' => $item['gross_pay'],
            'total_deductions' => $item['deductions'],
            'structure_name' => $snapshot['structure_name'] ?? null,
            'snapshot' => $item['salary_snapshot'],
            'generated_by' => $userId,
            'payment_status' => $item['payment_status'] ?? 'Unpaid',
        ]);
        $payslipId = (int) $this->db->lastInsertId();

        $this->db->insert('payroll_versions', [
            'payroll_run_id' => $item['payroll_run_id'], 'line_item_id' => $lineItemId,
            'employee_id' => $item['employee_id'], 'version' => 0,
            'action' => 'payslip_generated', 'net_salary' => $item['net_salary'],
            'snapshot' => json_encode(['payslip_id' => $payslipId]), 'changed_by' => $userId,
        ]);

        try {
            $periodLabel = date('d M Y', strtotime($item['period_start'])) . ' – ' . date('d M Y', strtotime($item['period_end']));
            sendNotification(
                $item['employee_id'], 'payroll', 'Payslip Available',
                'Your payslip for ' . $periodLabel . ' has been generated and is available in your Payroll section.',
                '?module=my_payroll'
            );
        } catch (Exception $e) { /* notification must never block payslip generation */ }

        return ['success' => true, 'payslip_id' => $payslipId, 'line_item_id' => $lineItemId, 'net_salary' => $item['net_salary']];
    }

    public function transitionRunStatus($runId, $toStatus, $userId) {
        $run = $this->db->fetchOne("SELECT * FROM payroll_runs WHERE id = ?", [$runId]);
        if (!$run) return ['success' => false, 'message' => 'Payroll run not found'];
        if ($run['status'] === 'Paid' && $toStatus !== 'Paid') {
            return ['success' => false, 'message' => 'Paid payroll is locked from further changes.'];
        }
        $map = ['Generated' => 1, 'Reviewed' => 2, 'Approved' => 3, 'Paid' => 4];
        $cur = $map[$run['status']] ?? 1; $next = $map[$toStatus] ?? 1;
        if ($next < $cur) return ['success' => false, 'message' => 'Cannot move payroll status backwards.'];

        $data = ['status' => $toStatus];
        if ($toStatus === 'Paid') {
            $data['payment_status'] = 'Paid';
            $data['marked_paid_by'] = $userId;
            $data['marked_paid_at'] = date('Y-m-d H:i:s');
            $this->db->query("UPDATE payslips SET payment_status = 'Paid' WHERE payroll_run_id = ?", [$runId]);
        } elseif ($toStatus === 'Reviewed') {
            $data['reviewed_by'] = $userId;
            $data['reviewed_at'] = date('Y-m-d H:i:s');
        }
        $this->db->update('payroll_runs', $data, 'id = ?', [$runId]);

        $this->db->insert('payroll_versions', [
            'payroll_run_id' => $runId, 'employee_id' => null, 'version' => $run['version'] ?? 1,
            'action' => 'status_' . strtolower($toStatus),
            'net_salary' => $run['total_payout'], 'snapshot' => json_encode(['status' => $toStatus]),
            'changed_by' => $userId,
        ]);
        logAudit('payroll_runs', $runId, 'status_' . strtolower($toStatus), ['status' => $run['status']], ['status' => $toStatus]);

        if ($run['payroll_period_id']) {
            $this->db->update('payroll_periods', ['status' => $toStatus], 'id = ?', [$run['payroll_period_id']]);
        }
        return ['success' => true, 'message' => 'Payroll marked as ' . $toStatus];
    }

    public function getRunLineItems($runId) {
        return $this->db->fetchAll(
            "SELECT pl.*, e.first_name, e.last_name, e.employee_id,
                    d.name as department_name,
                    (SELECT id FROM payslips ps WHERE ps.payroll_line_item_id = pl.id) as payslip_id,
                    ss.name as structure_name
             FROM payroll_line_items pl
             JOIN employees e ON pl.employee_id = e.id
             LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
             LEFT JOIN departments d ON ed.department_id = d.id
             LEFT JOIN salary_structures ss ON pl.salary_structure_id = ss.id
             WHERE pl.payroll_run_id = ?
             ORDER BY e.first_name, e.last_name", [$runId]
        );
    }

    public function getVersionHistory($lineItemId) {
        return $this->db->fetchAll(
            "SELECT pv.*, e.first_name, e.last_name
             FROM payroll_versions pv
             LEFT JOIN employees e ON pv.changed_by = e.id
             WHERE pv.line_item_id = ?
             ORDER BY pv.created_at DESC, pv.version DESC", [$lineItemId]
        );
    }
}