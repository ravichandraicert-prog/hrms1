# Payroll Module Redesign — Implementation Summary

This document describes the complete redesign of the HRMS Payroll module into a
professional, integrated payroll system driven by the organization's existing
Employee, Salary Structure, Pay Cycle, Work Pattern, Attendance, Leave and
Reports data.

## Architecture & Data Flow

```
Employee
   ↓
Salary Structure  (reusable + assignable, CTC-based)
   ↓
Pay Cycle         (period derived from cycle)
   ↓
Attendance + Leave (central attendance_daily + leave_applications)
   ↓
PayrollService.calculatePayroll()   ← single source of truth
   ↓
Preview / Generate / History / Payslip  (all use the SAME calculation)
   ↓
Paid / Regenerate → Payslip → Employee Notification → My Payroll
```

No duplicate payroll/attendance logic lives in the UI or API. Everything routes
through `includes/payroll_service.php`.

## Files Added / Changed

| File | Description |
|------|-------------|
| `includes/payroll_service.php` | **New.** Central PayrollService engine: pay-cycle resolution, period derivation, salary component computation, attendance+leave summary, prorated payroll, generation/regeneration, payslip, status workflow, audit trail. |
| `api/payroll.php` | **Rewritten.** Endpoints for components, structures, assignments, pay-cycle periods, preview/generate/regenerate, history, payslip (with self-service access control), status workflow. |
| `modules/payroll.php` | **Rewritten.** Tabs: Generate Payroll, Salary Structure, Salary Components, Pay Cycles, Assignments, Payroll History, Payslip. Payroll Periods manual tab **removed** (period selected inside Generate). |
| `modules/my_payroll.php` | **New.** Employee self-service area — only their own payroll + generated payslips (snapshot based). |
| `database/run_payroll_redesign.php` | **New, idempotent.** Adds columns/tables + seeds 10 standard Indian salary components. Safe to re-run. |
| `config/database.php` | Added `Database::lastInsertId()` helper. |
| `index.php` | Registered `my_payroll` module + sidebar entry. |

## Database Changes (all additive, no data loss)

- `salary_components`: +`code`, `percentage`, `percentage_based_on`,
  `frequency`, `display_order` (flexible component mechanism, no hardcoding).
- `salary_structures`: +`annual_ctc`.
- `employee_salary_structures`: +`annual_ctc`, `is_active`, `assigned_by`,
  `created_at` (historical assignment retention).
- `payroll_runs`: +`version`, `parent_run_id`, `regenerate_reason`,
  `pay_cycle_id`, `payment_status`.
- `payroll_line_items`: +`salary_structure_id`, `pay_cycle_id`,
## Key Design Decisions

### 1. Salary Structures (spec #1–#6)
Administrators build reusable salary structures (e.g. Standard Employee,
Manager, Sales) from **flexible salary components**. Components carry name,
code, type (earning/deduction), calculation method (fixed / percentage /
formula-balancing), percentage-basis (CTC / Gross / another component),
frequency (monthly/annual), taxable flag, and display order. Statutory rules
are config, never hardcoded.

Structures are assigned to employees with an **Annual CTC + Effective Date**.
`employee_salary_structures` is history-preserving: payroll uses the structure
active during the payroll period (`resolveSalaryStructure(empId, periodEnd)`).

### 2. Pay Cycle & Period Derivation (spec #8–#9, #30)
The separate manual **Payroll Periods** admin workflow was removed from the
navigation. Periods are now **derived automatically from the Pay Cycle** inside
Generate Payroll via `PayrollService`. The same authoritative function
(`getPeriodForDate`) generates the periods shown in the UI and validates the
selected period when generating.

Verified against the seeded cycles:
- 1st–Last → `2026-08-01 .. 2026-08-31`
- 16th–15th → `2026-08-16 .. 2026-09-15` (correctly rolls end into next month)
- 26th–25th → `2026-08-26 .. 2026-09-25`

The `payroll_periods` table may still be generated/used internally (via Pay
Cycles tab → Generate Period Records) but is no longer a separate required
workflow.

### 3. Attendance + Leave Integration (spec #10–#15)
`getAttendanceSummary()` reads the same central data the Reports module uses:
`attendance_daily` statuses and `leave_applications`, and expected working days
from `WorkPatternService::calculateWorkingDays()` (which respects 2nd/4th
Saturday rules). It distinguishes **Approved paid** leave from Pending/
Rejected/Cancelled, and flags **Leave + Attendance conflicts** (e.g. a leave
record plus a Present attendance day) so the admin reviews rather than the
engine silently deducting.

### 4. Proration (spec #17)
Payable salary scales with paid working days / expected working days, so
mid-period joins, resignations, leaves, and LOP are handled consistently.

### 6. Regenerate + Audit Trail (spec #21–#23)
Regeneration requires a reason, re-reads the applicable structure/attendance/
leave/work-pattern, recomputes, bumps the run version, archives the prior value,
and logs to `payroll_versions`. **Paid payroll is locked** from regeneration and
status changes.

### 7. Duplicate Protection (spec #37)
`existingRunForPeriod()` blocks accidental duplicate generation for the same
(cycle + period); regeneration only via the explicit Regenerate action.

### 8. Employee Self-Service (spec #27–#29, #35)
`modules/my_payroll.php` lists the employee's own payroll records and lets them
view/print their generated payslips. The API `line_id` and `my_payroll`
endpoints enforce that a non-admin can only access their own records. Payslip
generation triggers a notification via the existing `sendNotification()`
mechanism.

### 9. Permissions (spec #35)
All admin endpoints check `hasPermission('payroll', ...)`; the module top-level
checks `hasPermission('payroll', 'read')`. Employee self-service uses the
standard authenticated session.

## Validation Performed

- All PHP files pass `php -l`.
- Engine smoke tests passed:
  - Ratio computation (1.2M CTC → gross 100,000 / PF+PT 5,000 / net 95,000).
  - Pay-cycle period derivation for all seed cycles + containment for dates
    before/after the cycle start day.
  - Assignment resolution (employee, org-levels), employee resolution by cycle,
    batch computation, run save, line items, payslip generation + duplicate
    protection, version history, and the full Review→Approve→Paid workflow plus
    the Paid lock.
  - Confirmed **payslip net == payroll net** (snapshot integrity).
- All 7 payroll tabs + My Payroll render without PHP warnings/errors.

## Notes & Limitations

- The 34 employees without a salary-structure assignment are correctly reported
  as "skipped" (spec #36 validation) — assign each employee a structure via
  Salary Structure → Assign before generating their payroll.
- Component deletion is disabled in the UI to protect structure integrity; set
  components inactive instead (or manage via DB).
- Print/Download of payslips uses the browser print dialog.
- The `pay_cycles.php` API (`save_pay_cycle`, `assign_pay_cycle`,
  `generate_periods`) from the prior implementation remains in place and drives
  the Pay Cycles / Assignments tabs.
### 5. Preview → Generate → History → Payslip (spec #18–#26)
- **Preview** computes but does not save.
- **Generate** persists a `payroll_runs` record + line items + component
  details + snapshots, and enforces duplicate/paid protection.
- **Payroll History** lists runs with status/payment/version and offers
  View, Regenerate, Payslip, and Review→Approve→Paid.
- **Payslip** is generated **from the stored payroll snapshot only** — it never
  recalculates, guaranteeing the payslip equals the payroll figure.
  `salary_snapshot`, `attendance_snapshot`, `expected_working_days`,
  `present_days`, `half_days`, `leave_days`, `lop_days`, `has_conflict`,
  `conflicts` (full payroll snapshot for historical integrity).
- **New tables:** `payslips`, `payroll_versions` (audit trail).
- Seeded default components: Basic (40% CTC), HRA (40% Basic), Conveyance,
  Medical, Special Allowance (balancing), Other Allowances, Employee PF (12%
  Basic), ESI, Professional Tax, Other Deductions.

Run: `C:\xampp\php\php.exe database/run_payroll_redesign.php`