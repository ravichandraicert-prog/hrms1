# Break Types Assignment Implementation

## Overview
This implementation adds assignment functionality to the Break Types module in settings, allowing administrators to assign break types to specific employees, departments, roles, users, or shifts.

## Features Implemented

### 1. Database Schema Changes
- Added `assign_to` column to `break_types` table (VARCHAR(50), default: 'all_employees')
- Added `target_id` column to `break_types` table (INT(11), default: NULL)
- Migration script: `database/add_break_assignment_fields.php`

### 2. Assignment Options
The following assignment options are now available in the Break Types form:
- **All Employees**: Assigns the break type to all employees
- **Department**: Assigns to employees in a specific department
- **Role**: Assigns to employees with a specific role
- **User**: Assigns to specific user(s)
- **Shift**: Assigns to all employees in a particular shift

### 3. Frontend Changes (modules/settings.php)
- Added "Assign To" dropdown field to the Break Type modal
- Added dynamic target selection field that changes based on assignment type
- Updated the Break Types table to display assignment information
- Implemented JavaScript functions for handling assignment UI:
  - `updateBreakTargetField()`: Updates target field based on assignment selection
  - Modified `openBreakTypeModal()`: Loads and restores assignment data
  - Modified `saveBreak()`: Includes assignment data in save payload

### 4. Backend Changes (api/settings.php)
- Updated `save_break` action to handle `assign_to` and `target_id` parameters
- Added support for storing assignment preferences in the database

### 5. API Enhancements (api/leaves.php)
- Enhanced `get_assignment_data` endpoint to include shifts data
- Now returns: departments, roles, users, and shifts for target selection

### 6. Helper Functions (includes/break_assignment.php)
Created a new helper file with assignment logic functions:
- `break_type_assign_to()`: Resolves assignment mode for a break type
- `break_type_is_applicable()`: Checks if a break type applies to a specific employee
- `break_type_target_employee_ids()`: Returns IDs of employees the break type applies to

## Files Modified/Created

### Modified Files:
1. `modules/settings.php` - Added assignment UI and updated JavaScript functions
2. `api/settings.php` - Updated save logic for break types
3. `api/leaves.php` - Enhanced assignment data API to include shifts

### New Files:
1. `database/add_break_assignment_fields.php` - Database migration script
2. `database/add_break_type_assignment_fields.sql` - SQL migration file
3. `includes/break_assignment.php` - Assignment helper functions
4. `test_break_assignment.php` - Test script for verification

## Testing

### Manual Testing Steps:
1. Run the database migration: `http://localhost/HRMS/database/add_break_assignment_fields.php`
2. Navigate to Settings → Break Types
3. Click "Add Break" or edit an existing break type
4. Select different "Assign To" options:
   - "All Employees" - no target selection needed
   - "Department" - select from available departments
   - "Role" - select from available roles
   - "User" - select from available users
   - "Shift" - select from available shifts
5. Save the break type
6. Verify the assignment is displayed correctly in the table
7. Edit the break type to confirm assignment data is preserved

### Automated Testing:
Run the test script: `http://localhost/HRMS/test_break_assignment.php`

## Assignment Logic

### All Employees
- When `assign_to = 'all_employees'`, the break type is available to all active employees
- `target_id` is NULL

### Department
- When `assign_to = 'department'`, the break type is available only to employees in the specified department
- `target_id` contains the department ID
- Assignment is checked via `employee_departments` table

### Role
- When `assign_to = 'role'`, the break type is available only to employees with the specified role
- `target_id` contains the role ID
- Assignment is checked via `employees.role_id`

### User
- When `assign_to = 'user'`, the break type is available only to the specified user
- `target_id` contains the employee ID
- Direct employee ID comparison

### Shift
- When `assign_to = 'shift'`, the break type is available only to employees assigned to the specified shift
- `target_id` contains the shift ID
- Assignment is checked via `employees.shift_id`

## Data Consistency

The implementation ensures:
- Assignment data is saved and retrieved correctly
- Editing assignments updates affected employees without overwriting unrelated data
- The same assignment logic is reflected consistently across the Break Types module
- No other HRMS modules/functions are affected

## Future Enhancements

Potential future improvements:
- Add bulk assignment functionality
- Implement assignment history tracking
- Add assignment validation rules
- Create assignment reports
- Add support for multiple targets per break type
