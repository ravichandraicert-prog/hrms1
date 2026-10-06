# Orbit HRMS — Technical Requirements Document (Build Specification)

**Purpose of this document:** This is a complete functional and technical specification intended to be handed directly to an AI coding model/agent (or a development team) to build the system. Every module lists explicit functional requirements (FR-IDs), data entities, business rules, and edge cases. Nothing here should require the reader to infer intent — where the source requirements were ambiguous, a specific decision has been made and stated.

**Foundational rule that applies to the entire document:** Every module, field, role, permission, and status value described below must be implemented as **data that is created/read/updated/deleted through the UI**, not as hardcoded constants, enums baked into code, or `if (role == 'X')` logic. Anywhere this document lists a default (e.g. default roles, default leave types, default break types), treat it as **seed data**, not a fixed schema. This is a strict, non-negotiable architectural requirement — see Section 1.

---

## Table of contents

1. [Architectural principles (read first)](#1-architectural-principles-read-first)
2. [The shift-crossing-midnight attendance problem — solution](#2-the-shift-crossing-midnight-attendance-problem--solution)
3. [Roles, hierarchy & permissions](#3-roles-hierarchy--permissions)
4. [Global clock widget (navbar)](#4-global-clock-widget-navbar)
5. [Dashboard module](#5-dashboard-module)
6. [Employee management module](#6-employee-management-module)
7. [Department module](#7-department-module)
8. [Recruitment module](#8-recruitment-module)
9. [Task management module](#9-task-management-module)
10. [Notifications module](#10-notifications-module)
11. [Attendance management module](#11-attendance-management-module)
12. [Regularization module](#12-regularization-module)
13. [Leave management module](#13-leave-management-module)
14. [Payroll module](#14-payroll-module)
15. [Reports module](#15-reports-module)
16. [Notice board / announcements module](#16-notice-board--announcements-module)
17. [Settings module](#17-settings-module)
18. [Employee ID format & recruitment-to-employee data flow](#18-employee-id-format--recruitment-to-employee-data-flow)
19. [Complete employee field list](#19-complete-employee-field-list)
20. [Cross-cutting requirements](#20-cross-cutting-requirements)
21. [Core data entities (appendix)](#21-core-data-entities-appendix)
22. [Suggested build order](#22-suggested-build-order)

---

## 1. Architectural principles (read first)

| # | Principle | Implementation requirement |
|---|---|---|
| P1 | **Nothing is hardcoded.** Roles, permissions, departments, leave types, break types, shifts, holiday calendars, module/field labels, and task-assignment hierarchy are all records in the database, managed via CRUD screens. | Do not create enums or constants for any of the above. Use lookup tables. |
| P2 | **Permissions are enforced from one central, data-driven engine**, not scattered checks per module. | A single `permissions` table (Role/User × Module × Action × Scope) is checked by one authorization service, called by every module (UI rendering, API, exports, bulk actions). See Section 3. |
| P3 | **Every module supports full CRUD** wherever the module description mentions records (employees, departments, tasks, leave types, etc.), including edit and delete/deactivate, unless explicitly restricted by a business rule stated in this doc (e.g. shift deletion block). | — |
| P4 | **Every list/table screen supports filtering**, relevant to that module's data (see per-module filter lists below, and Section 20). | Filters must be combinable (AND logic) and persist per session. |
| P5 | **Raw activity data (attendance punches, audit logs) is immutable.** Corrections happen via a separate regularization/audit trail, never by editing/deleting the original log row. | Preserves data integrity and enables the shift-date logic in Section 2. |
| P6 | **All timestamps are stored in UTC** in the database and rendered in the organization's (or user's) configured timezone in the UI. | Prevents another entire class of date-boundary bugs beyond the shift issue. |

---

## 2. The shift-crossing-midnight attendance problem — solution

### 2.1 The problem, restated precisely

- **Day shift:** logs in ~10:00, logs out ~19:00 — same calendar date. No issue.
- **Night shift:** logs in ~20:00 on Day X, logs out ~04:00 on Day X+1. If attendance is calculated using the *calendar date of each timestamp*, the clock-in lands on Day X and the clock-out lands on Day X+1 — splitting one work session across two dates, corrupting daily totals, reports, and summaries.

### 2.2 The fix: separate "Calendar Date" from "Attendance Date"

Introduce a field called **`attendance_date`** (also referred to as **Shift Date** or **Work Date**) on every attendance-related record. This is **not** the calendar date of the timestamp — it is the date the *shift* is considered to belong to. All reporting, summaries, dashboards, and payroll calculations use `attendance_date`, never the raw timestamp's calendar date.

**Rule:** `attendance_date` = the calendar date on which the employee's shift is *scheduled to start*, for the shift instance that a given punch falls into. For a night shift scheduled to start at 20:00 on Aug 19 and end at 04:00 on Aug 20, every punch between roughly 20:00 Aug 19 and 04:00 Aug 20 (plus grace windows, see 2.4) is tagged `attendance_date = Aug 19`.

### 2.3 Data model

**`shifts` table** (already required elsewhere in this doc — see Section 17.3 — extended here with the fields needed for date logic):

| Field | Type | Notes |
|---|---|---|
| `id` | PK | |
| `name` | string | e.g. "Night Shift" |
| `start_time` | time | e.g. 20:00 |
| `end_time` | time | e.g. 04:00 |
| `spans_midnight` | boolean, **computed** | `true` automatically when `end_time < start_time`. Never manually set — derive it. |
| `grace_in_minutes` | int | how early/late a punch can be and still count as "clocking in for this shift" (e.g. 120 minutes) |
| `grace_out_minutes` | int | how late after `end_time` a clock-out still belongs to this shift instance (e.g. 180 minutes, to allow overtime) |
| `half_day_threshold_minutes` | int | editable, see Section 11.5 |
| `absent_threshold_minutes` | int | editable |
| `full_day_minutes` | int | expected worked duration for a full day, used in reports/payroll |

**`attendance_punches` table** (immutable raw log — every clock-in, clock-out, break-start, break-end event):

| Field | Type | Notes |
|---|---|---|
| `id` | PK | |
| `employee_id` | FK | |
| `event_type` | enum-as-data | `CLOCK_IN`, `CLOCK_OUT`, `BREAK_START`, `BREAK_END` — values come from a lookup table, not a hardcoded enum |
| `break_type_id` | FK, nullable | set when `event_type` is a break event; references the `break_types` table (Section 17.4) |
| `timestamp_utc` | datetime | exact server-recorded time, never edited |
| `source` | string | web / mobile / biometric / kiosk |
| `geolocation` | lat/long, nullable | for mobile geofenced punches |
| `attendance_date` | date, **computed at write time** | see algorithm in 2.4 — stored (not just computed on read) so historical reports remain stable even if shift settings change later |

**`attendance_daily` table** (aggregated summary — one row per employee per `attendance_date`):

| Field | Type | Notes |
|---|---|---|
| `id` | PK | |
| `employee_id` | FK | |
| `attendance_date` | date | the logical shift date |
| `shift_id` | FK | shift the employee was assigned on that date |
| `first_in` | datetime | earliest `CLOCK_IN` tagged to this `attendance_date` |
| `last_out` | datetime, nullable | latest `CLOCK_OUT` tagged to this `attendance_date`; null while still clocked in |
| `total_worked_minutes` | int, computed | sum of all clock-in→clock-out spans for this `attendance_date`, minus break time per break-type rules |
| `total_break_minutes` | int, computed | |
| `session_count` | int | number of separate clock-in/out pairs that day (supports "clock multiple times") |
| `status` | lookup value | Present / Absent / Half Day / On Leave / Holiday / Week Off — driven by thresholds (Section 11.5) and leave/holiday data, not hardcoded |
| `is_regularized` | boolean | true if any punch this day came from an approved regularization |
| `remarks` | text, nullable | |

### 2.4 Algorithm: assigning `attendance_date` to a punch

Run this at the moment a punch is recorded (not deferred to a nightly batch, so the live "who's logged in now" view in Section 5 is always correct):

```
INPUT: employee_id, event_type, timestamp_utc
1. Get the employee's currently assigned shift (from employee record).
2. Compute two candidate shift windows:
   a. "Today window": [today's date + shift.start_time − grace_in,
                        today's date (+1 day if spans_midnight) + shift.end_time + grace_out]
   b. "Yesterday window": same calculation, anchored to yesterday's date instead of today.
3. If timestamp_utc falls inside the Yesterday window → attendance_date = yesterday's date.
   Else if it falls inside the Today window → attendance_date = today's date.
   Else (punch is way outside any expected window, e.g. accidental double punch) →
      attendance_date = calendar date of the timestamp, and flag the record
      with a "review" tag for HR (do not silently misassign it).
4. If event_type is CLOCK_IN and there is already an OPEN session (a CLOCK_IN
   with no matching CLOCK_OUT) for this employee on this attendance_date,
   treat this as an additional session within the same day (supports
   "clock in/out multiple times" — see FR-ATT-02).
5. Store the punch with the resolved attendance_date.
6. Recompute (or upsert) the attendance_daily row for
   (employee_id, attendance_date): update first_in/last_out/
   total_worked_minutes/session_count/status.
```

### 2.5 Worked example (using the exact scenario described)

- Night-shift employee, shift = 20:00–04:00, `spans_midnight = true`, grace_in = 120 min, grace_out = 180 min.
- Clock-in at **19-Aug 20:05** → falls inside Today window (anchored to 19-Aug) → `attendance_date = 19-Aug`.
- Clock-out at **20-Aug 04:12** → falls inside the *same* window (20:00 19-Aug to 04:00+180min 20-Aug = 07:00 20-Aug) → `attendance_date = 19-Aug`.
- Both punches, and any breaks in between, are stored with `attendance_date = 19-Aug`.
- `attendance_daily` row for this employee, `19-Aug`: `first_in = 19-Aug 20:05`, `last_out = 20-Aug 04:12`, `total_worked_minutes ≈ 487` minus breaks.
- Every report, dashboard "present today" count, and payroll calculation that filters by date range simply queries `attendance_date` — the day-shift employee's `19-Aug` row and the night-shift employee's `19-Aug` row sit side by side correctly, with no special-case logic needed anywhere else in the system.

### 2.6 Edge cases the build must handle

| Case | Handling |
|---|---|
| Employee forgets to clock out; session runs past `grace_out_minutes` | Auto-close the session at `shift.end_time + grace_out`, mark `status` accordingly, and flag it for HR review/regularization rather than leaving it open indefinitely. |
| Employee clocks in early (before grace window even opens) | Punch falls outside both windows → tagged `attendance_date` = its own calendar date + flagged for review (per step 3 above), visible in the Regularization module. |
| Employee's shift is changed mid-cycle | Historical `attendance_punches.attendance_date` values are already stored and must **not** be recalculated retroactively; only future punches use the new shift. |
| Multiple clock-in/out pairs in one shift (e.g. steps out and back in) | All pairs aggregate into the same `attendance_daily` row via `session_count` (2.4 step 4). |
| Reports spanning a date range | Always filter/group by `attendance_date`, never by the raw `timestamp_utc` calendar date. This single rule is what keeps day-shift and night-shift data consistent everywhere downstream — dashboards, reports, payroll, regularization. |

---

## 3. Roles, hierarchy & permissions

### 3.1 Default roles (seed data, not hardcoded)

Seed the `roles` table with the following, each with a `hierarchy_level` (lower number = higher authority) — but the table must support adding, renaming, reordering, or deleting roles freely:

| Level | Role |
|---|---|
| 1 | Super Admin |
| 2 | Admin |
| 3 | HR Manager |
| 4 | HR Executive |
| 5 | Senior Manager |
| 6 | Manager |
| 7 | Team Leader |
| 8 | Senior Executive |
| 9 | Executive |
| 10 | Intern / Trainee |

- **FR-ROLE-01:** Multiple employees can hold the same role (e.g. 5 people with role "Manager").
- **FR-ROLE-02:** An employee can be placed at any hierarchy position independent of role label (i.e. hierarchy is driven by the **reporting structure** — who reports to whom — not solely by role name). Role = job title/permission bundle; hierarchy = actual org reporting tree. Both are stored and both matter (role for permissions, reporting line for approvals/visibility).
- **FR-ROLE-03:** Roles are full CRUD: create new roles, rename existing ones, delete/deactivate roles (blocked if employees are currently assigned — same pattern as shift deletion, Section 17.3).

### 3.2 Data visibility based on hierarchy

**FR-VIS-01:** Every module that lists records tied to employees (attendance, tasks, leave, payroll, reports, etc.) must support a **scope** setting per role, independent of the module:

| Scope value | Meaning |
|---|---|
| Self | Only the logged-in user's own records |
| Direct reports | Records of employees who report directly to this user |
| Team (direct + indirect reports) | Full downline in the reporting tree |
| Department | All employees in the user's assigned department(s) |
| Department + sub-departments | If department hierarchy is used |
| All | Org-wide |
| Custom rule | Admin-defined filter (e.g. "same location") |

**FR-VIS-02:** Scope is set per **Role × Module × Action** in the permission matrix (Section 3.4) — e.g. "Manager" role might have `Attendance: Read = Team scope` but `Payroll: Read = Self scope`.

### 3.3 Permission matrix — CRUD-driven, not hardcoded

**FR-PERM-01:** Build a `permissions` table: `role_id, module_id, action (Create/Read/Update/Delete/Approve/Export), field_id (nullable — null means module-level), scope, allowed (boolean)`.

**FR-PERM-02:** Provide a Settings screen ("Permissions") rendering this as an editable matrix (rows = roles, columns = modules/actions), with drill-down to field-level and scope settings per cell. This screen is described further in Section 17.6.

**FR-PERM-03:** Permission checks happen in one central authorization service/middleware, called before every read, write, and export operation across all modules — no per-module custom permission logic.

**FR-PERM-04:** In addition to role-based permissions, support **individual user-level overrides** (grant or revoke a specific permission for one specific person, without changing their role) — since the requirement explicitly asks for "role wise permissions" and "adding multiple people in different hierarchy," an override layer avoids needing a new role for every exception.

**FR-PERM-05:** Permission changes take effect immediately (no cache invalidation delay, no redeploy).

### 3.4 Task-assignment hierarchy (distinct from general org hierarchy)

**FR-PERM-06:** Provide a separate, editable **"who can assign tasks to whom"** rule set (Section 9.4) — because the requirement explicitly distinguishes this from general reporting hierarchy ("customised also that should be editable and creatable as well"). Default: a role/level can assign tasks to any role/level below it in hierarchy; allow custom overrides (e.g. a specific Executive can also assign tasks to a specific peer Executive).

---

## 4. Global clock widget (navbar)

**FR-CLOCK-01:** Persistent widget in the top-right of the navbar, visible on every screen, showing:
- A **live running clock** with seconds (HH:MM:SS), continuously updating client-side.
- The current date.
- **Clock In / Clock Out** button — toggles based on current state (shows "Clock In" if not clocked in; "Clock Out" if currently clocked in).
- A **break dropdown** — lists active break types (from Section 17.4); selecting one starts a break (`BREAK_START` punch); a "Resume work" / "End break" option sends `BREAK_END` and resumes the work session.
- When clocked in, display **elapsed time for the current session** (and optionally total time for the day) updating live, so the user can see time accruing in real time, down to the seconds shown in the clock.

**FR-CLOCK-02:** Every button press writes an immutable row to `attendance_punches` per the model in Section 2.3, and is immediately reflected in the "Who's logged in now" dashboard widget (Section 5) and in that user's `attendance_daily` aggregate.

**FR-CLOCK-03:** A user can clock in/out and start/end breaks multiple times per attendance date (see FR-ATT-02) — all logged individually, never overwritten.

**FR-CLOCK-04:** The widget must correctly attribute clock actions to the right `attendance_date` per the Section 2.4 algorithm, transparently to the user — the user only ever sees "today" in their own local context; the system handles the night-shift date logic in the background.

---

## 5. Dashboard module

**FR-DASH-01:** Dashboard is a configurable widget grid. Each widget below is a distinct, independently permissioned component — a role may see some widgets and not others, per the permission matrix (Section 3).

| Widget | Content |
|---|---|
| Headcount | Total people currently in the organization |
| Present today | Count of employees with `status = Present` for today's `attendance_date`, scoped per viewer's visibility (Section 3.2) |
| Who's logged in now | Live list of employees currently clocked in (no `CLOCK_OUT` since their last `CLOCK_IN`), with time-in and current session duration |
| Open / pending requests for review | Leave requests, regularization requests, expense requests (if enabled) awaiting the viewer's approval — scoped to what they're authorized to approve |
| Upcoming holidays | From the holiday calendar(s) assigned to the viewer |
| Birthdays & anniversaries | Upcoming, scoped to the viewer's visibility |
| Recent activity feed | System-wide or scoped activity log |

**FR-DASH-02:** Widget visibility and default layout are configurable per role in Settings (which widgets appear, in what order); individual users may further personalize their own view within what their role permits.

---

## 6. Employee management module

**FR-EMP-01:** Table view listing all employees (scoped per viewer's visibility), with columns configurable (Section 20) and, per row, an actions menu:

- **Edit** — opens the same dynamic form used for Add Employee (Section 19), pre-filled.
- **Reset password** — triggers a password reset (admin sets temporary password or sends reset link, per Settings login-method config, Section 17.7).
- **Blacklist** — marks the employee record as blacklisted (distinct from deactivation; implies "should not be rehired/engaged," retains historical data, blocks login).
- **Deactivate** — disables login and removes from active headcount/rosters, but **retains all historical data** (attendance, payroll, tasks) for records/compliance. Reversible (can be reactivated).
- **Delete** — hard delete, restricted to roles with explicit permission (Section 3.3); should generally be discouraged in favor of Deactivate, but available for genuine data-entry errors (e.g. duplicate record created same day).

**FR-EMP-02: Add Employee** — opens a modal/form built from the full field list in Section 19, organized into sections (Personal Info, Employment Info, System Access, Bank Details) matching that section's grouping.

**FR-EMP-03: Import Employees** — bulk import via CSV. Provide a downloadable template matching current field configuration (since fields are dynamic per Section 1/P1, the template must be generated from the live field list, not a fixed file). Validate on upload (required fields, duplicate Employee ID/email checks) and show a row-level error report before committing.

**FR-EMP-04: Export Employees** — export the current (filtered) employee list to CSV/Excel, respecting the exporting user's field-level permissions (a Manager exporting the list should not get salary fields if they don't have read access to that field).

**FR-EMP-05:** Filters on the employee list: department, role, status (active/inactive/blacklisted), employment type, shift, reporting manager, holiday calendar, joining date range.

---

## 7. Department module

**FR-DEPT-01:** Table listing all departments with columns: name, code, head of department, employee count, status (active/inactive), and row actions: **View, Edit, Activate/Deactivate**.

**FR-DEPT-02: Add Department** form fields: Name, Code, Description, Head of Department (select employee), initial employee assignment.

**FR-DEPT-03:** A department can have **multiple managers/senior managers assigned** (many-to-many between employees and departments in a managerial capacity), not just one head.

**FR-DEPT-04:** A single manager can be assigned to **multiple departments simultaneously** (many-to-many both directions).

**FR-DEPT-05:** Deactivating a department does not delete its historical data (past employees, reports); it simply prevents new assignments to it and hides it from active-selection dropdowns (still shown when filtering historical data).

---

## 8. Recruitment module

**FR-REC-01:** Four tabs, each a table of candidates in that stage: **Sourcing, Onboarding, Offboarding, Blacklisted.** (Stage list itself should be a CRUD lookup table per P1 — these four are seed data, not fixed.)

**FR-REC-02:** Each candidate row supports moving between stages via an action menu, with the following allowed transitions (implement as a configurable state-transition table, not hardcoded conditionals):
- Sourcing → Onboarding
- Sourcing → Blacklisted
- Onboarding → Offboarding (i.e. the candidate was in onboarding but the process was stopped/reversed)
- Onboarding → Blacklisted
- Any stage → Blacklisted (blacklisting should be reachable from any stage per the requirement)

**FR-REC-03:** Each candidate record includes a **resume upload** field (file), accessible to HR-permissioned roles per Section 3.

**FR-REC-04:** Each candidate has a detail view showing full profile, stage history (audit trail of transitions with timestamp + actor), notes, and attached documents.

**FR-REC-05:** When a candidate reaches "Onboarding" and is confirmed as hired, an **"Add as Employee"** action opens the Add Employee form (Section 6/19) with all matching fields **pre-filled from the candidate record** (name, contact info, resume, position applied for, etc.) — see Section 18.

**FR-REC-06:** Filters per tab: position/role applied for, source, date added, assigned recruiter.

---

## 9. Task management module

**FR-TASK-01:** Views: **My Tasks** (assigned to me), **Assigned by Me**, **All Tasks** (scoped by visibility, Section 3.2). Each view shows counts/tabs for: Total, Pending, In Progress, Completed, Overdue (status list is a CRUD lookup, not hardcoded — these are seed defaults).

**FR-TASK-02: Create Task** fields: Title, Description, Assignee (single or multiple), Assigned date (defaults to now), Deadline/due date, Priority, related module/record (optional link, e.g. to a candidate or department).

**FR-TASK-03:** A user can create a task for themselves, or assign to others **only within what the task-assignment hierarchy (Section 3.4) permits.** The UI must only offer assignees the current user is authorized to assign to.

**FR-TASK-04: Performance tracking.** For every task, capture `assigned_date` and `deadline`, and on completion capture `completed_date`. Compute a per-task on-time flag (`completed_date <= deadline`). Aggregate per employee into a **Task Performance Index** (e.g. % of tasks completed on time over a period, average days early/late) visible on the employee's profile and in Reports (Section 15).

**FR-TASK-05:** Filters: assignee, assigner, status, priority, department, date range (assigned/deadline).

---

## 10. Notifications module

**FR-NOTIF-01:** Unified, chronological feed of all system-generated notifications targeted at the logged-in user. At minimum, trigger notifications for:

| Event | Notified party |
|---|---|
| Late clock-in / late second (recorded punch flagged late) | Employee, and their manager per visibility rules |
| Leave request submitted | Approver(s) in the workflow chain |
| Leave request approved / rejected / cancelled | Requesting employee |
| Regularization request submitted | Approver(s) |
| Regularization approved / cancelled / rejected | Requesting employee |
| Task assigned to you | Assignee |
| Task deadline approaching / overdue | Assignee (and optionally assigner) |
| New announcement/notice targeted at you | Targeted employees (per Section 16) |

**FR-NOTIF-02:** Notification types are managed as data (Section 17), each with editable message templates and channel toggles (in-app / email / SMS) per Section 3 of the earlier architecture doc's Notification Engine concept — do not hardcode notification copy in code.

**FR-NOTIF-03:** Mark as read / unread, filter by type, and a badge count of unread notifications.

---

## 11. Attendance management module

**FR-ATT-01:** Primary list/table of attendance records (backed by `attendance_daily`, Section 2.3), filterable by: month/date range, employee, department, role, shift, status (Present/Absent/Half Day/On Leave/Holiday). Combinable filters per Section 20.

**FR-ATT-02:** Because employees can clock in/out multiple times a day, provide a **per-day log drill-down**: clicking a row/day expands or opens a detail view listing every individual punch (`attendance_punches`) for that employee on that `attendance_date` — clock-ins, clock-outs, and breaks, each with exact timestamp and source.

**FR-ATT-03: Calendar view.** A month calendar where each day cell shows a status indicator; clicking a day opens a modal listing, for that date: every employee's status, worked duration, department, and any remarks — essentially a daily roster snapshot.

**FR-ATT-04:** A separate **date-range log view** (as distinct from the calendar) supporting: pick a from/to date range, employee/role/department filter, and see all attendance records and their individual punches across that range in tabular form.

**FR-ATT-05: Threshold settings** (editable in Settings, referenced here since it directly drives this module's `status` computation): Absent threshold, Half-day threshold, Present threshold — expressed as worked-minutes cutoffs (see `shifts` table fields in Section 2.3; thresholds can be global default or overridden per shift).

**FR-ATT-06: Bulk mark attendance**, supporting:
- Mark a **single date** for a specific employee (e.g. manually set Present/Absent/Half Day, with remarks) — for cases with no punch data (e.g. field visit).
- Mark a **date range** for an employee.
- **Assign a shift** for a specific day (or date range) to a user as part of the same bulk-mark action (e.g. a temporary shift change for coverage), without altering their default assigned shift permanently.

**FR-ATT-07:** All bulk-marked/manual attendance entries are flagged as such (`source = manual`) and logged with the acting admin's identity and timestamp, for audit purposes (P5).

---

## 12. Regularization module

**FR-REG-01:** List of regularization requests, showing: requesting employee, date/time of request, the attendance date being regularized, requested change, current status (Pending/Approved/Rejected), and who approved/rejected it (if actioned).

**FR-REG-02:** A regularization request lets the employee (or an authorized admin/manager on their behalf) propose corrected clock-in/out times for a specific `attendance_date`, with a reason. On **approval**, the system inserts corrected punch data (as a new, clearly-flagged `attendance_punches` entry — original punches are never edited/deleted, per P5) and recomputes the `attendance_daily` row.

**FR-REG-03:** Available in both **Calendar view** (regularizable days indicated on the attendance calendar) and **Tabular view** (a straightforward list/table of all requests) — same underlying data, two presentations.

**FR-REG-04:** Approval routing follows the Workflow/approval-hierarchy rules (Section 3), typically the requester's reporting manager, with HR as a fallback/second approver — configurable, not hardcoded.

**FR-REG-05:** Filters: employee, department, status, date range.

---

## 13. Leave management module

**FR-LEAVE-01: Calendar view** showing leave applications from all visible employees (per Section 3.2 scope) plotted across a month calendar, so managers/HR can see team availability at a glance.

**FR-LEAVE-02: Leave applications list** — table of leave requests with employee, leave type, dates, duration, status, and approve/reject actions (subject to permissions).

**FR-LEAVE-03: Leave balances** — per-employee view of remaining balance for each leave type, with drill-down history (accrued, used, carried forward).

**FR-LEAVE-04: Leave assignment** — HR/Admin can directly assign/adjust leave balances for specific employees or bulk-assign by role/department (e.g. crediting annual leave at year start).

**FR-LEAVE-05: Leave type CRUD.** Leave types are fully create/edit/delete, each with configurable rules:

| Setting | Description |
|---|---|
| Name | e.g. "Casual Leave" |
| Calculation basis | Calendar days (includes weekends/holidays in the count) **or** Working days only (excludes weekends/holidays per the assigned holiday calendar) — must be selectable per leave type |
| Accrual method | Fixed annual quota / Monthly accrual / Custom formula |
| Max days per year | |
| Min days per request | (e.g. cannot apply for less than 0.5 day) |
| Carry forward | On/off, with a **max carry-forward cap** |
| Applicable to | All employees / specific roles / specific departments (reuses the same targeting pattern as Notices, Section 16) |
| Paid / unpaid | |
| Requires approval | On/off; if on, uses the Workflow engine (approval chain configurable) |

**FR-LEAVE-06:** Filters across leave screens: employee, department, role, leave type, status, date range.

---

## 14. Payroll module

**FR-PAY-01: Payroll records** — tabular list of generated payroll runs (by pay period), each row showing period, employee count, total payout, status (Draft/Generated/Paid). Actions: **View, Regenerate, Edit, Mark as Paid.**

**FR-PAY-02:** Regenerating/editing a payroll run must respect audit rules — keep history of what changed (P5-equivalent for payroll: don't silently overwrite prior generated figures without a trace).

**FR-PAY-03: Pay cycle settings** — configurable start-day and end-day of the month defining the pay period, settable **globally or per department** (department-level override takes precedence).

**FR-PAY-04: Payslip access** — employees can view/download their own payslips (self-service); HR/Admin can access all, per permission scope.

**FR-PAY-05: Salary structure builder** — per-employee (or template-based, then applied to selected employees) configuration of:

*Earnings:* Basic pay, Special allowance, Bonus amount, Shift allowance, Other allowance, HRA.
*Deductions:* Professional tax, Income tax (TDS), Other deductions.

Computed automatically: **Total Earnings**, **Total Deductions**, **Net Salary** (= Total Earnings − Total Deductions). These earning/deduction line items should themselves be a configurable list (component name, type earning/deduction, calculation method flat/percentage/formula) rather than a fixed set of six-ish fields, so new components (e.g. a new statutory deduction) can be added later without code changes — consistent with P1.

**FR-PAY-06:** Salary structures can be created/assigned for specific selected employees (multi-select), not just one at a time.

**FR-PAY-07:** Filters on payroll records: pay period, department, status.

---

## 15. Reports module

**FR-REP-01: Attendance summary report.** Filters: date range (required), department (optional), employee/role (optional). Output, per employee: count of Present, Absent, Late, Half Day, and Leave days within the selected period. Must use `attendance_date` for all grouping (Section 2.6).

**FR-REP-02: Payroll summary report.** Filters: month or custom date range, department (optional), employee (optional). Output, per employee: Gross pay, Deductions, Net paid, for the selected period(s).

**FR-REP-03:** Both reports exportable (CSV/Excel/PDF) respecting the exporting user's field-level permissions.

**FR-REP-04:** Per the earlier architecture principle (P1/dynamic core), reports should ideally be buildable/saveable by permissioned users beyond just these two fixed templates — treat Attendance Summary and Payroll Summary as **seeded default report definitions**, not the only two reports the system can ever produce.

---

## 16. Notice board / announcements module

**FR-NOTICE-01:** Create/edit/delete notices with: title, rich-text body, optional attachment, and **targeting**: All Employees, or specific Role(s), or specific Department(s) (combinable — e.g. "Engineering department + Manager role and above").

**FR-NOTICE-02:** Configurable **who is allowed to post notices** — a Settings-controlled list of which roles can create notices, and whether their targeting ability is restricted (e.g. a Team Leader might only be allowed to post to their own department, while HR can post org-wide) — see Section 17.6 permission tie-in.

**FR-NOTICE-03:** Read tracking — record which targeted employees have viewed a notice; optionally require acknowledgement.

**FR-NOTICE-04:** Filters: date posted, poster, target audience, status (active/expired — support an optional expiry date on notices).

---

## 17. Settings module

### 17.1 Holiday calendars

**FR-SET-HOL-01:** CRUD for holiday calendars (e.g. "National Calendar," "Regional — South"). Each calendar contains a list of holidays with: date, name, **type = Fixed or Optional**.

**FR-SET-HOL-02:** Each calendar is assignable to specific employees, departments, or roles (many-to-many, combinable targeting like Notices).

### 17.2 Break types

**FR-SET-BRK-01:** CRUD for break types (e.g. "Lunch," "Tea Break," "Prayer Break"). Fields: name, default duration, **Paid or Unpaid**, and whether the break duration is **deducted from total working time** or not (a break could theoretically be paid and not deducted, or unpaid and deducted — both flags are independent).

**FR-SET-BRK-02:** These are the break types offered in the navbar clock widget's dropdown (Section 4).

### 17.3 Shifts

**FR-SET-SHIFT-01:** CRUD for shifts: name, start time, end time, description, plus the date-logic fields defined in Section 2.3 (`grace_in_minutes`, `grace_out_minutes`, thresholds).

**FR-SET-SHIFT-02:** **A shift cannot be deleted while any employee is currently assigned to it.** Attempting deletion must block with a clear message and offer a **bulk reassignment flow** (move all affected employees to a different shift) before allowing the delete to proceed.

### 17.4 Modules, fields, roles & hierarchy configuration

**FR-SET-CFG-01:** A settings area to **rename modules and fields** system-wide (e.g. relabel "Employees" to "Team Members" org-wide) — supports organizations that use different internal terminology.

**FR-SET-CFG-02:** Manage the **universal role list and department hierarchy** from here: view/add/edit/delete roles, view/edit department hierarchy structure and heads, consistent with Section 3.

### 17.5 Permissions module

**FR-SET-PERM-01:** The master permission matrix UI described in Section 3.4 — described here as "the godfather of the entire [system]" per the source requirement: this single screen determines, for every Role (and optionally individual user override), which Modules/Actions/Fields they can access, and at what visibility Scope (Section 3.2). This includes the Notice-posting permissions from FR-NOTICE-02.

### 17.6 Change password

**FR-SET-PWD-01:** Self-service "Change Password" screen: current password field, new password field (with confirmation), validated before update.

### 17.7 Login method configuration

**FR-SET-LOGIN-01:** Configurable whether users log in via **email** or **username** (or both) — toggle-able system-wide in Settings, applied to the login page and to how usernames/emails are collected during Add Employee (Section 19).

### 17.8 Employee ID format

**FR-SET-EMPID-01:** Configure the auto-generated Employee ID **format and starting number** (e.g. prefix "EMP-", starting at 1000, zero-padded to 4 digits → `EMP-1000`, `EMP-1001`...). See Section 18.

---

## 18. Employee ID format & recruitment-to-employee data flow

**FR-ID-01:** On employee creation, auto-generate the Employee ID per the format configured in Section 17.8, unless the organization's settings allow manual override entry.

**FR-ID-02:** When adding an employee **from a recruitment record** (Section 8, FR-REC-05), the Add Employee form must be pre-populated from the candidate's stored data: name, contact details, resume/documents, and any other overlapping fields — the HR user should only need to fill in the remaining employment-specific fields (department, position, reporting manager, joining date, shift, role, etc.), not re-enter everything from scratch.

---

## 19. Complete employee field list

This is the authoritative field list for the Add/Edit Employee form (Section 6), grouped into sections. Per P1, this list is the **seed configuration** for the dynamic field engine — an admin can still add custom fields on top of this list via Settings, but this is what ships by default.

### Personal information
- Employee ID *(auto-generated, see Section 18; editable only if format settings allow manual entry)*
- First Name *(required)*
- Last Name *(required)*
- Personal Email ID
- Official Email ID *(required)*
- Alternate Email ID *(optional)*
- Phone Number *(required)*
- Alternate Phone Number *(optional)*
- Date of Birth
- Marital Status
- Address
- State
- City
- Zip Code

### Employment information
- Primary Department *(required)*
- Additional/Secondary Department(s) *(optional, multi-select — an employee can belong to more than one department, consistent with FR-DEPT-04's manager equivalent)*
- Position / Designation
- Reporting Manager *(select from employees)*
- Date of Joining
- Employment Type *(Permanent / Probation / Intern / Temporary — CRUD lookup list, not fixed)*
- Working Days configuration *(e.g. standard Mon–Fri, or custom patterns such as alternate Saturdays — configurable per employee or as an assignable template from Settings)*

### System access
- Role *(select from Roles, Section 3)*
- Username *(if username login is enabled, Section 17.7)*
- Password *(set on creation; supports "send reset link" alternative)*
- Holiday Calendar assignment *(Section 17.1)*
- Shift assignment *(Section 17.3)*

### Bank details
- Bank Name
- Account Number
- IFSC Code
- PAN Number

---

## 20. Cross-cutting requirements

**FR-CC-01: Filtering.** Every list/table screen in the system must expose filters relevant to its data (explicitly required across Attendance, Leave, Payroll, Recruitment, Tasks, Notices, Reports, Employees, Regularization — see each module's own filter list above; apply the same standard to any screen not explicitly listed).

**FR-CC-02: Permission enforcement.** Every create/read/update/delete/export/approve action, on every module and field, must pass through the central permission engine (Section 3) — no exceptions, no module-specific bypass logic.

**FR-CC-03: Audit trail.** All create/update/delete actions on all entities are logged (actor, timestamp, before/after values) for compliance and troubleshooting — this also underpins the immutability rule (P5) for attendance data.

**FR-CC-04: Consistent status/date handling.** Any module that deals with "which day did this happen on" for shift-based staff (attendance, tasks completed during a night shift, regularization) must use the `attendance_date` concept from Section 2, not raw timestamps, for consistency.

---

## 21. Core data entities (appendix)

Minimum entity list an implementer should expect to model (field-level detail is in the relevant module sections above):

`employees`, `departments`, `employee_departments` (join, supports multi-department), `roles`, `employee_roles`, `permissions`, `shifts`, `employee_shifts` (supports temporary/date-specific overrides per FR-ATT-06), `break_types`, `attendance_punches`, `attendance_daily`, `regularization_requests`, `leave_types`, `leave_applications`, `leave_balances`, `holiday_calendars`, `holidays`, `holiday_calendar_assignments`, `tasks`, `task_assignment_rules`, `candidates`, `candidate_stage_history`, `payroll_runs`, `payroll_line_items`, `salary_structures`, `salary_components`, `notices`, `notice_reads`, `notifications`, `notification_templates`, `audit_log`.

---

## 22. Suggested build order

Given the dependencies between modules, an AI agent or dev team should implement roughly in this order:

1. **Core auth + roles/permissions engine** (Section 3) — everything else depends on this.
2. **Employee & Department modules** (Sections 6–7) — the base entities most other modules reference.
3. **Shifts, break types, holiday calendars** (Section 17.1–17.3) — required before attendance can function correctly.
4. **Attendance punch logging + the shift-date algorithm** (Sections 2, 4, 11) — this is the highest-risk module; get it right before building anything downstream of it (payroll, reports).
5. **Regularization** (Section 12) — depends on attendance.
6. **Leave management** (Section 13) — depends on employees/roles/holiday calendars.
7. **Recruitment** (Section 8) — largely independent, can be parallelized.
8. **Task management** (Section 9) — depends on roles/hierarchy.
9. **Payroll** (Section 14) — depends on attendance (for paid days) and leave (for unpaid leave deductions).
10. **Reports** (Section 15) — depends on attendance and payroll data being correct.
11. **Notices & Notifications** (Sections 10, 16).
12. **Dashboard** (Section 5) — last, since it aggregates data from every other module.

---

*This document is written to be handed to an AI coding agent as a build spec. Where a requirement was ambiguous in the original source notes, a specific, stated decision was made (e.g. Blacklist vs Deactivate vs Delete as three distinct actions; grace windows for shift-date assignment) so that implementation does not require re-clarifying intent. If any stated decision doesn't match the intended behavior, flag that specific FR-ID for revision rather than reinterpreting the whole document.*
