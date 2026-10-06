# Centralized Attendance Threshold Configuration - Implementation Summary

## Overview
This implementation redesigns the attendance threshold functionality so that Present, Half Day, and Absent rules are configurable from Settings → Attendance and become the single source of truth for attendance status throughout the entire HRMS.

## Core Implementation Details

### 1. Database Schema Changes

#### New Tables Created:
- **`attendance_settings`** - Stores the current active attendance threshold configuration
  - `present_threshold_minutes` (default: 495 = 8:15)
  - `half_day_threshold_minutes` (default: 255 = 4:15)
  - `effective_date` - When these thresholds become effective
  - `created_by` - User who created/last modified settings
  - `created_at`, `updated_at` - Timestamps

- **`attendance_threshold_history`** - Tracks changes to threshold configuration
  - Links to `attendance_settings` table
  - Stores historical threshold values with effective date ranges
  - Includes change tracking (who changed, when, and why)
  - Ensures historical attendance records maintain their original calculated status

### 2. Centralized Service Layer

#### File: `includes/attendance_threshold_service.php`
New service class that provides:
- `getCurrentThresholds()` - Get current active threshold settings
- `getThresholdsForDate($date)` - Get thresholds for historical date lookup
- `calculateStatus($workedMinutes, $breakMinutes, $attendanceDate, $employeeId)` - Centralized status calculation
- `updateThresholds($data, $userId, $reason)` - Update thresholds with history tracking
- `validateThresholds($data)` - Validate threshold configuration
- `getThresholdHistory($limit)` - Retrieve change history

### 3. Attendance Module Integration

#### File: `includes/attendance.php`
- Integrated `AttendanceThresholdService` into the `Attendance` class
- Replaced shift-specific threshold logic with centralized threshold calculation
- Modified `determineStatus()` method to use the centralized service
- All attendance status calculations now use the single source of truth

### 4. Settings UI Implementation

#### File: `modules/settings.php`
- Completely redesigned Settings → Attendance section
- New centralized threshold configuration interface with:
  - Present Threshold (Hours + Minutes inputs)
  - Half Day Threshold (Hours + Minutes inputs)
  - Clear explanation of status rules
  - Threshold change history display
- Added JavaScript function `saveCentralizedThresholds()` for threshold updates
- Maintains backward compatibility with existing shift-specific thresholds

### 5. API Integration

#### File: `api/settings.php`
- Added `save_centralized_thresholds` action endpoint
- Integrated `AttendanceThresholdService` for validation and updates
- Includes comprehensive validation before saving
- Maintains audit logging for all threshold changes

### 6. Status Calculation Logic

The implementation follows the exact requirements:

```
IF total_duration >= 08:15:00 (495 minutes)
    → PRESENT

ELSE IF total_duration > 04:15:00 (255 minutes) AND total_duration < 08:15:00
    → HALF DAY

ELSE
    → ABSENT
```

**Boundary Conditions Implemented:**
- Exactly 04:15 → Absent
- More than 04:15 → Half Day
- Exactly 08:15 → Present
- More than 08:15 → Present

### 7. Validation Rules

The centralized validation ensures:
- Thresholds must be positive values
- Present threshold must be greater than Half Day threshold
- Thresholds cannot exceed 24 hours (1440 minutes)
- Clear validation messages for users

### 8. Historical Record Preservation

The implementation ensures:
- Historical attendance records maintain their original calculated status
- Threshold changes are tracked in `attendance_threshold_history` table
- Historical threshold lookup is available for reporting purposes
- Finalized payroll records are not unexpectedly recalculated

### 9. Multi-Module Consistency

All modules now use the centralized threshold calculation:
- **Attendance Module**: Uses `AttendanceThresholdService` via `Attendance` class
- **Reports Module**: Consumes pre-calculated status from `attendance_daily` table
- **Payroll Module**: Uses status from `attendance_daily` table for calculations
- **Dashboard Module**: Displays status from `attendance_daily` table

## Files Modified/Created

### New Files:
1. `database/add_centralized_attendance_thresholds.sql` - SQL migration script
2. `database/add_attendance_threshold_history.sql` - History table migration
3. `database/run_centralized_threshold_migration.php` - PHP migration runner
4. `includes/attendance_threshold_service.php` - Centralized service class
5. `test_centralized_thresholds.php` - Comprehensive test script

### Modified Files:
1. `includes/attendance.php` - Integrated centralized service
2. `modules/settings.php` - New threshold configuration UI
3. `api/settings.php` - New API endpoint for threshold updates

## Migration Instructions

### Step 1: Run Database Migration
```bash
php database/run_centralized_threshold_migration.php
```

This will:
- Create `attendance_settings` table with default values (8:15, 4:15)
- Create `attendance_threshold_history` table for change tracking
- Insert default threshold configuration

### Step 2: Verify Installation
```bash
php test_centralized_thresholds.php
```

This will test:
- Database table creation
- Default threshold values
- Status calculation logic
- Validation rules
- Integration with existing modules

### Step 3: Access Settings UI
Navigate to: Settings → Attendance → Attendance Status Thresholds

Configure thresholds as needed:
- Present Threshold: Default 8 hours 15 minutes
- Half Day Threshold: Default 4 hours 15 minutes

## Acceptance Criteria Met

✅ **1.** Attendance thresholds are configurable from Settings → Attendance
✅ **2.** The default Present threshold is 8 hours 15 minutes (495 minutes)
✅ **3.** The default Half Day threshold is 4 hours 15 minutes (255 minutes)
✅ **4.** 8:15 exactly = Present
✅ **5.** More than 4:15 and less than 8:15 = Half Day
✅ **6.** 4:15 exactly = Absent
✅ **7.** Less than 4:15 = Absent
✅ **8.** Multiple attendance sessions are combined correctly
✅ **9.** Attendance status is calculated consistently across the HRMS
✅ **10.** The Attendance module uses the centralized calculation
✅ **11.** Reports use the same calculation (via attendance_daily table)
✅ **12.** Payroll uses the same attendance status (via attendance_daily table)
✅ **13.** Dashboard attendance indicators use the same calculation
✅ **14.** Threshold values are not hardcoded throughout individual modules
✅ **15.** Administrators can change the thresholds without code changes
✅ **16.** Threshold configuration is properly validated
✅ **17.** Historical/finalized payroll is not unexpectedly recalculated
✅ **18.** Time calculations are accurate and do not introduce problematic rounding
✅ **19.** Existing permissions are respected
✅ **20.** The system has one authoritative attendance-status calculation mechanism

## Benefits of This Implementation

1. **Single Source of Truth**: All attendance status calculations use the same centralized logic
2. **Easy Configuration**: Administrators can change thresholds without code modifications
3. **Historical Integrity**: Historical records maintain their original status
4. **Audit Trail**: All threshold changes are tracked with reasons and timestamps
5. **Validation**: Comprehensive validation prevents invalid configurations
6. **Consistency**: All modules show identical attendance status for the same data
7. **Maintainability**: Centralized logic is easier to maintain and debug
8. **Scalability**: Easy to extend with additional threshold types or rules

## Technical Notes

- The implementation maintains backward compatibility with existing shift-specific thresholds
- The centralized thresholds take precedence over shift-specific thresholds in status calculation
- Historical threshold lookup ensures accurate reporting for past periods
- The service layer approach allows for easy testing and mocking
- Database foreign keys ensure referential integrity
- Transaction handling ensures data consistency during threshold updates

## Future Enhancements

Potential improvements that could be added:
- Per-department threshold overrides
- Effective date scheduling for future threshold changes
- Threshold change approval workflow
- Advanced threshold rules (e.g., different thresholds for different days)
- Integration with attendance policies and compliance requirements