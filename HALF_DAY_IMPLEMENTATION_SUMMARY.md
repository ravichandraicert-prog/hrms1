# Half-Day Leave and Attendance Implementation Summary

## Overview
This implementation updates the HRMS attendance reports to properly handle half-day leave and half-day attendance calculations, ensuring that both are counted as 0.5 days instead of full days.

## Changes Made

### 1. Database Schema Update
- **File**: `database/add_duration_column.sql`
- **Change**: Added `duration` column to `leave_applications` table
- **Values**: 'full', 'first_half', 'second_half'
- **Purpose**: Store whether a leave application is for full day, first half, or second half

### 2. Leave Application API Updates
- **File**: `api/leaves.php`
- **Changes**:
  - Modified leave application submission to store the `duration` value
  - Updated leave approval logic to set appropriate attendance status:
    - Full day → 'On Leave'
    - First half → 'First Half Leave'
    - Second half → 'Second Half Leave'
  - Updated leave cancellation logic to remove leave status from attendance_daily and recompute based on punches
  - Updated leave rejection logic to remove leave status from attendance_daily and recompute based on punches

### 3. Reports Module Updates
- **File**: `modules/reports.php`
- **Changes**:
  - Updated attendance aggregation logic:
    - **Half Day attendance**: Counts as 0.5 in Present column, 1 in Half Day column
    - **First Half Leave**: Counts as 0.5 in Leave column, 1 in Half Day column
      - If there are punches for second half: adds 0.5 to Present
      - If no punches for second half: adds 0.5 to Absent
    - **Second Half Leave**: Counts as 0.5 in Leave column, 1 in Half Day column
      - If there are punches for first half: adds 0.5 to Present
      - If no punches for first half: adds 0.5 to Absent
  - Updated attendance percentage calculation to use the actual Present value (which already includes 0.5 for half-day attendance and half-day leave with punches)
  - Updated display to show decimal values for Present, Absent, and Leave columns

### 4. Reports API Updates
- **File**: `api/reports.php`
- **Changes**:
  - Updated CSV export to match the reports module logic
  - Updated attendance aggregation to handle half-day leave statuses
  - Updated CSV export to show decimal values for Present, Absent, and Leave columns

### 5. Attendance Threshold Service Updates
- **File**: `includes/attendance_threshold_service.php`
- **Changes**:
  - Updated calculateStatus method to handle 'First Half Leave' and 'Second Half Leave' statuses
  - Added helper methods `getPresentThreshold()` and `getHalfDayThreshold()` for cleaner code
  - Updated return type documentation to include new statuses

## Calculation Logic

### Half-Day Leave Count
- **Half Day column**: Shows the number of half-day leave instances (e.g., 5 half-day leaves = 5)
- **Leave column**: Shows the decimal leave value (e.g., 5 half-day leaves = 2.5)

### Half-Day Attendance
- **Present column**: Increases by 0.5 for each Half Day attendance record
- **Half Day column**: Increases by 1 for each Half Day attendance record

### First Half Leave with Attendance
- **Leave column**: +0.5
- **Half Day column**: +1
- **Present column**: +0.5 (if punches exist for second half)
- **Absent column**: +0.5 (if no punches for second half)

### Second Half Leave with Attendance
- **Leave column**: +0.5
- **Half Day column**: +1
- **Present column**: +0.5 (if punches exist for first half)
- **Absent column**: +0.5 (if no punches for first half)

## Examples

| Employee   | Half-Day Leaves Taken | Leaves | Present | Absent | Half Day |
| ---------- | --------------------: | -----: | ------: | -----: | -------: |
| Employee A |                     1 |    0.5 |       0 |    0.5 |        1 |
| Employee B |                     1 |    0.5 |     0.5 |      0 |        1 |
| Employee C |                     5 |    2.5 |       — |      — |        5 |

## Testing Instructions

1. **Test Half-Day Leave Application**:
   - Go to Leaves → Apply for Leave
   - Select "First half" or "Second half" from duration dropdown
   - Submit and approve the leave
   - Check Reports → Attendance Summary
   - Verify:
     - Leave column shows 0.5
     - Half Day column shows 1
     - Present or Absent column shows 0.5 based on punches

2. **Test Half-Day Attendance**:
   - Mark attendance for an employee with work time between half-day and present thresholds
   - Check Reports → Attendance Summary
   - Verify:
     - Present column shows 0.5
     - Half Day column shows 1

3. **Test CSV Export**:
   - Export attendance report to CSV
   - Verify decimal values are exported correctly

## Important Notes

- The Half Day column represents the number of half-day instances, not the decimal day value
- Half-day attendance is counted as 0.5 in Present, not 1
- First Half and Second Half leaves are handled separately
- Attendance punches determine whether the remaining half is Present (0.5) or Absent (0.5)
- The system avoids double-counting the same date when combining leave and attendance
- Full-day leave, full-day Present, full-day Absent, and Week Off calculations remain unchanged
