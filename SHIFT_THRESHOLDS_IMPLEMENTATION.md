# Shift-Specific Attendance Thresholds Implementation

## Overview
Adds per-shift **Present / Half Day / Absent Threshold** configuration in **Settings → Attendance**,
and applies the correct shift-specific thresholds when computing attendance status
(Present / Half Day / Absent).

## Features Implemented

### 1. Database (`shifts` table)
- Added `present_threshold_minutes int(11) DEFAULT NULL` (nullable, **no default**).
- Existing `half_day_threshold_minutes` and `absent_threshold_minutes` columns are reused for Half Day / Absent.
- `full_day_minutes` is untouched (still used by payroll/reports only).

### 2. Settings → Attendance UI (`modules/settings.php`)
- Replaced the placeholder Attendance Settings block with a **"Attendance Thresholds by Shift"** table
  listing **all shifts from the Shifts module** (`SELECT * FROM shifts ORDER BY start_time`).
- Each shift row has a **Threshold** section with three `type="time"` inputs (HH:MM):
  - **Present Threshold** — left empty until set (no default).
  - **Half Day Threshold** — prefilled from `half_day_threshold_minutes`.
  - **Absent Threshold** — prefilled from `absent_threshold_minutes`.
- Each row has a per-shift **Save** button → `saveShiftThresholds(shiftId, btn)`.

### 3. API (`api/settings.php`)
- New `save_shift_thresholds` POST action: guarded by `settings` update permission; converts HH:MM → minutes
  (empty Present → `NULL`), updates the three columns on the shift, and writes an audit log.

### 4. Status computation (`includes/attendance.php`)
- `determineStatus()` now uses the employee's **assigned shift** thresholds:
  - **Present**  = `present_threshold_minutes` (falls back to `half_day_threshold_minutes` when unset,
    so existing behavior is preserved until a distinct value is configured).
  - **Half Day** = `half_day_threshold_minutes`.
  - **Absent**  = anything below the Half Day cutoff / below `absent_threshold_minutes`.
- Leave / Holiday / Week Off checks are unchanged; other modules are unaffected.

## Files Changed
1. `modules/settings.php`
2. `api/settings.php`
3. `includes/attendance.php`
4. `database/schema.sql` (shift table definition for fresh installs)

## New File
- `database/add_present_threshold.sql` — migration script to add the column to an existing DB.

## Migration
Apply to an existing database:
```
ALTER TABLE `shifts` ADD COLUMN `present_threshold_minutes` int(11) DEFAULT NULL AFTER `half_day_threshold_minutes`;
```

## Validation
- Integration test confirmed: with Present unset → behavior unchanged; setting Present to a higher value
  correctly reclassifies a part-day worker from Present → Half Day; a lower value keeps them Present.