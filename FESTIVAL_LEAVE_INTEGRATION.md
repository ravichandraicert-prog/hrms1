# Festival Leave - Holiday Calendar Integration (Fixed vs Optional)

## Overview
This integration allows employees to apply for Festival Leave by selecting from festival holidays configured in their assigned Holiday Calendar, with support for both Fixed and Optional festival leaves.

## Key Features

### Fixed Festival Leave
- Automatically deducted from the employee's leave balance
- Not displayed as an available optional leave balance
- Deduction happens automatically based on the configured festival/holiday
- No balance check required during application

### Optional Festival Leave
- Included in the employee's Leave Balance
- Only the available optional festival leave count is shown in the balance
- When an employee applies for an optional festival leave, the applicable amount is deducted from the optional festival leave balance
- Balance check required during application

## Changes Made

### 1. Database Changes

#### Migration Script: `database/run_festival_leave_migration.php`
- **Adds Festival Leave type** to `leave_types` table if it doesn't exist
  - Name: "Festival Leave"
  - For Fixed festival holidays
  - No balance requirement (max_days_per_year = 0)
  - Assigned to all employees
  - Requires approval

- **Adds Optional Festival Leave type** to `leave_types` table if it doesn't exist
  - Name: "Optional Festival Leave"
  - For Optional festival holidays
  - Balance is calculated based on number of optional festivals in assigned calendar
  - Assigned to all employees
  - Requires approval

- **Adds `festival_holiday_id` column** to `leave_applications` table
  - Stores reference to the selected festival holiday from `holidays` table
  - Foreign key constraint to `holidays.id` with ON DELETE SET NULL

- **Adds `festival_type` column** to `leave_applications` table
  - Stores whether the festival is 'Fixed' or 'Optional'
  - Used for balance calculation and validation

- **Creates optional festival leave balances** for all employees
  - Automatically calculates balance based on number of optional festivals in assigned calendar
  - Creates leave_balances entries for Optional Festival Leave type

### 2. Frontend Changes (`modules/leaves.php`)

#### Festival Holiday Data Fetching
- Fetches employee's assigned holiday calendar
- Retrieves festival holidays (Fixed and Optional types) from the assigned calendar
- Stores holidays in `$festivalHolidays` array for the dropdown

#### Leave Balance Display
- **Optional Festival Leaves Section**: Added at the top of the balance strip
  - Shows available optional festival leave count separately from regular leave types
  - Fixed festival leaves are not included in this displayed optional balance
  - Styled with a gradient background for visibility

#### Leave Application Form
- **Dynamic Festival Holiday Dropdown**: Added a dropdown field that appears when "Festival Leave" or "Optional Festival Leave" is selected
- **Fixed vs Optional Filtering**: 
  - When "Festival Leave" is selected, only shows Fixed festival holidays
  - When "Optional Festival Leave" is selected, only shows Optional festival holidays
  - Shows descriptive message about which type is being displayed
- **Auto-fill Dates**: When a festival holiday is selected, the start and end dates are automatically filled with the holiday date
- **Field Hiding**: When Festival Leave types are selected, hides Duration, Start Date, End Date, Reason, and Documentation fields
- **No Festival Holidays Message**: Shows appropriate message if employee has no festival holidays in their calendar

#### JavaScript Functions
- `handleLeaveTypeChange()`: 
  - Shows/hides festival holiday dropdown based on selected leave type
  - Filters festival options based on leave type (Fixed vs Optional)
  - Hides/shows other form fields accordingly
- `submitLeave()`: 
  - Enhanced to include festival holiday validation and submission
  - Includes festival type in the submission data
  - Auto-generates reason from holiday name

### 3. Backend Changes (`api/leaves.php`)

#### Leave Application Submission
- **Festival Holiday Validation**: 
  - Validates that a festival holiday is selected when Festival Leave types are chosen
  - Verifies the selected holiday exists and belongs to the employee's assigned calendar
  - Prevents selection of holidays from other calendars
  - Validates that the festival type matches the leave type (Fixed vs Optional)

- **Special Handling for Festival Leave Types**:
  - **Fixed Festival Leave**: 
    - Skips work pattern validation (since festival holidays are meant to be non-working days)
    - Skips balance check (Fixed Festival Leave doesn't consume leave balance)
  - **Optional Festival Leave**: 
    - Skips work pattern validation
    - Requires balance check (consumes from optional festival leave balance)
  - Stores `festival_holiday_id` and `festival_type` in the leave application record

#### Leave Approval Process
- **Balance Deduction**: 
  - Fixed Festival Leave: No balance deduction
  - Optional Festival Leave: Deducts from optional festival leave balance
  - Regular leave types: Normal balance deduction

#### Leave Cancellation Process
- **Balance Restoration**: 
  - Fixed Festival Leave: No balance restoration (since no balance was deducted)
  - Optional Festival Leave: Restores optional festival leave balance
  - Regular leave types: Normal balance restoration

#### Leave Type Filtering
- Modified balance check logic:
  - Festival Leave: Always available regardless of balance
  - Optional Festival Leave: Available only if balance > 0
  - Regular leave types: Available only if balance > 0

## How It Works

### For Fixed Festival Leave
1. **Employee Selection**: When an employee applies for leave, the system checks their assigned holiday calendar
2. **Dynamic Dropdown**: If "Festival Leave" is selected, a dropdown appears showing only Fixed festival holidays from their assigned calendar
3. **Holiday Selection**: Employee selects a Fixed festival holiday (e.g., "Diwali - 20 Oct (Fixed)")
4. **Auto-fill Dates**: The system automatically fills the start and end dates with the holiday date
5. **Validation**: The system validates that:
   - A Fixed festival holiday is selected
   - The holiday belongs to the employee's assigned calendar
6. **Submission**: The leave application is saved with the festival holiday reference and festival_type = 'Fixed'
7. **No Balance Deduction**: Fixed Festival Leave doesn't consume leave balance
8. **Approval**: When approved, no balance is deducted

### For Optional Festival Leave
1. **Employee Selection**: When an employee applies for leave, the system checks their assigned holiday calendar
2. **Dynamic Dropdown**: If "Optional Festival Leave" is selected, a dropdown appears showing only Optional festival holidays from their assigned calendar
3. **Holiday Selection**: Employee selects an Optional festival holiday (e.g., "Pongal - 14 Jan (Optional)")
4. **Auto-fill Dates**: The system automatically fills the start and end dates with the holiday date
5. **Validation**: The system validates that:
   - An Optional festival holiday is selected
   - The holiday belongs to the employee's assigned calendar
   - The employee has sufficient optional festival leave balance
6. **Submission**: The leave application is saved with the festival holiday reference and festival_type = 'Optional'
7. **Balance Check**: Optional Festival Leave requires and consumes optional festival leave balance
8. **Approval**: When approved, the balance is deducted from optional festival leave balance

## Testing Instructions

### 1. Run Database Migration
Execute the migration script to add the Festival Leave types and database columns:
```bash
php database/run_festival_leave_migration.php
```

Alternatively, access the web-based migration:
```
http://localhost/HRMS/run_festival_migration.php
```

### 2. Configure Holiday Calendar
- Go to Settings → Holiday Calendars
- Create or edit a holiday calendar with both Fixed and Optional festival holidays
- Example:
  - Diwali - Fixed
  - Christmas - Fixed
  - Pongal - Optional
  - Onam - Optional

### 3. Assign Holiday Calendar to Employee
- Go to Employees module
- Edit an employee and assign them to the holiday calendar configured above
- Verify that the optional festival leave balance is created automatically

### 4. Test Fixed Festival Leave Application
- Login as the assigned employee
- Go to Leaves module → Apply Leave tab
- Select "Festival Leave" from the Leave Type dropdown
- Verify that only Fixed festival holidays are shown in the dropdown
- Verify the message "Showing Fixed festival holidays only"
- Select a Fixed festival holiday
- Verify that dates are auto-filled with the holiday date
- Submit the leave application
- Verify that the application is saved successfully
- Verify that no balance was deducted

### 5. Test Optional Festival Leave Application
- Go to Leaves module → Apply Leave tab
- Select "Optional Festival Leave" from the Leave Type dropdown
- Verify that only Optional festival holidays are shown in the dropdown
- Verify the message "Showing Optional festival holidays only"
- Verify the Optional Festival Leaves balance is displayed at the top
- Select an Optional festival holiday
- Verify that dates are auto-filled with the holiday date
- Submit the leave application
- Verify that the application is saved successfully
- Verify that the optional festival leave balance was deducted

### 6. Test Validation
- Try to submit Festival Leave without selecting a festival holiday (should fail)
- Try to submit Optional Festival Leave with zero balance (should fail)
- Try to select an Optional festival with Festival Leave type (should fail)
- Try to select a Fixed festival with Optional Festival Leave type (should fail)
- Verify that other leave types still work normally

### 7. Test Approval and Cancellation
- Approve a Fixed Festival Leave and verify no balance deduction
- Approve an Optional Festival Leave and verify balance deduction
- Cancel an approved Fixed Festival Leave and verify no balance restoration
- Cancel an approved Optional Festival Leave and verify balance restoration

## Database Schema Changes

### leave_types table
Two new rows added:
```sql
-- Fixed Festival Leave
INSERT INTO leave_types (
    name, calculation_basis, accrual_method, max_days_per_year, 
    min_days_per_request, carry_forward, max_carry_forward_days, 
    applicable_to, is_paid, requires_approval, description, 
    is_active, assign_to, target_id
) VALUES (
    'Festival Leave', 'working_days', 'fixed_annual', 0, 
    1.0, 0, 0, 'all', 1, 1, 
    'Leave for festival holidays as per assigned holiday calendar', 
    1, 'all_employees', NULL
);

-- Optional Festival Leave
INSERT INTO leave_types (
    name, calculation_basis, accrual_method, max_days_per_year, 
    min_days_per_request, carry_forward, max_carry_forward_days, 
    applicable_to, is_paid, requires_approval, description, 
    is_active, assign_to, target_id
) VALUES (
    'Optional Festival Leave', 'working_days', 'fixed_annual', 0, 
    1.0, 0, 0, 'all', 1, 1, 
    'Optional festival leave as per assigned holiday calendar', 
    1, 'all_employees', NULL
);
```

### leave_applications table
Two new columns added:
```sql
ALTER TABLE leave_applications 
ADD COLUMN festival_holiday_id int(11) DEFAULT NULL 
COMMENT 'Reference to holidays table for Festival Leave type' 
AFTER leave_type_id,
ADD KEY festival_holiday_id (festival_holiday_id),
ADD CONSTRAINT leave_applications_ibfk_4 
FOREIGN KEY (festival_holiday_id) REFERENCES holidays (id) 
ON DELETE SET NULL;

ALTER TABLE leave_applications 
ADD COLUMN festival_type varchar(20) DEFAULT NULL 
COMMENT 'Festival type: Fixed or Optional' 
AFTER festival_holiday_id;
```

### leave_balances table
Automatic creation of Optional Festival Leave balances:
- For each employee, counts the number of Optional festivals in their assigned calendar
- Creates a leave_balances entry with total_accrued = count of optional festivals
- balance is calculated as total_accrued - total_used

## Files Modified

1. `database/run_festival_leave_migration.php` - Updated migration script
2. `database/add_festival_leave_type.sql` - SQL for adding Festival Leave type
3. `database/add_optional_festival_leave_type.sql` - SQL for adding Optional Festival Leave type
4. `database/add_festival_holiday_column.sql` - SQL for adding database columns
5. `run_festival_migration.php` - Web-based migration runner
6. `modules/leaves.php` - Frontend changes for Fixed vs Optional festival leave
7. `api/leaves.php` - Backend changes for Fixed vs Optional festival leave logic

## Benefits

1. **Employee Convenience**: Employees can easily select festival holidays from their assigned calendar
2. **Data Consistency**: Festival holidays are sourced from the centralized Holiday Calendar
3. **Validation**: Ensures employees only select holidays they're entitled to
4. **Balance Management**: 
   - Fixed Festival Leave: No balance impact
   - Optional Festival Leave: Proper balance tracking and deduction
5. **Auto-fill**: Dates are automatically filled based on the selected holiday
6. **Flexibility**: Supports multiple holiday calendars with different festival assignments
7. **Clear Display**: Optional festival leave balance is prominently displayed separately
8. **Type Safety**: Prevents mixing Fixed and Optional festival types

## Notes

- Festival Leave types are assigned to all employees by default
- Festival holidays include both "Fixed" and "Optional" type holidays from the holiday calendar
- The integration respects existing holiday calendar assignments and permissions
- The system reuses existing Holiday Calendar infrastructure
- No duplicate holiday data is created
- Optional festival leave balance is automatically calculated based on the holiday calendar
- Fixed festival leaves never appear in the optional festival leave balance display
