# Secondary Department Functionality Fixes

## Summary of Changes

This document describes the fixes implemented to resolve the Secondary Department functionality issues in the Employee module.

## Issues Fixed

### 1. Secondary Department Persistence
- **Problem**: Secondary departments were not being retained when editing an employee
- **Solution**: Fixed API endpoint to properly handle secondary department updates when department_id is not provided

### 2. Frontend Dropdown Click Handling
- **Problem**: Checkbox clicks in the secondary department dropdown were not working properly
- **Solution**: Added `event.preventDefault()` to checkbox click handlers to prevent event propagation issues

### 3. Primary Department Change Handling
- **Problem**: When changing the primary department, secondary department checkboxes were not being properly re-enabled
- **Solution**: Added logic to re-enable checkboxes for departments that are no longer the primary department

### 4. API Field Name Consistency
- **Problem**: The GET endpoint was returning `department_id` which could conflict with the department name field
- **Solution**: Explicitly aliased the field as `department_id` in the SELECT statement for consistency

## Files Modified

### 1. `api/employee.php`
- **Line 48-71**: Fixed GET endpoint to properly alias department_id field
- **Line 268-299**: Fixed PUT endpoint to handle secondary departments when department_id is not provided

### 2. `modules/employees.php`
- **Line 323**: Fixed checkbox click event handling in secondary department dropdown
- **Line 595-613**: Improved primary department change event handler to properly manage checkbox states

## Database Structure

The `employee_departments` table already has the correct structure:
- `employee_id` (FK to employees)
- `department_id` (FK to departments)
- `is_primary` (BOOLEAN: 1 for primary, 0 for secondary)
- UNIQUE constraint on `(employee_id, department_id)` to prevent duplicates

## How It Works

### Creating an Employee
1. Employee record is created in the `employees` table
2. Primary department is assigned to `employee_departments` with `is_primary = 1`
3. Secondary departments are assigned to `employee_departments` with `is_primary = 0`
4. The UNIQUE constraint prevents duplicate department assignments

### Editing an Employee
1. Employee details are loaded including secondary departments
2. Primary department is shown in the main dropdown
3. Secondary departments are shown in a multi-select dropdown
4. When saved, existing secondary departments are removed and new ones are added
5. The employee record remains single (no duplicates)

### Employee List Display
1. The query joins with `employee_departments` using `is_primary = 1`
2. This ensures each employee appears only once based on their primary department
3. Secondary departments are only visible in the employee edit/view details

## Testing Instructions

### Option 1: Automated Test Script
Run the test script to verify the functionality:
```bash
php database/test_secondary_departments.php
```

### Option 2: Manual Testing
1. **Test Secondary Department Persistence**:
   - Add a new employee with primary department and secondary departments
   - Save the employee
   - Edit the same employee
   - Verify that secondary departments are still selected

2. **Test No Duplicate Records**:
   - Add an employee with multiple secondary departments
   - Check the employee list - the employee should appear only once
   - Verify in database that there's only one record in `employees` table
   - Verify in database that there are multiple records in `employee_departments` table (one primary, one or more secondary)

3. **Test Primary Department Change**:
   - Edit an employee
   - Change the primary department
   - Verify that the old primary department checkbox is re-enabled in secondary departments
   - Verify that the new primary department checkbox is disabled in secondary departments

4. **Test Employee List Display**:
   - Add several employees with different primary and secondary departments
   - Check the employee list
   - Each employee should appear only once
   - The department shown should be their primary department

## Database Maintenance Scripts

### Check Current State
```bash
php database/check_and_fix_departments.php
```

### Fix Any Issues
If the test script reveals issues, run:
```bash
mysql -u root -p orbit_hrms < database/fix_secondary_departments.sql
```

## Verification Checklist

- [ ] Secondary departments are saved when creating an employee
- [ ] Secondary departments are retained when editing an employee
- [ ] Secondary departments checkbox clicks work properly
- [ ] Primary department change properly disables/enables secondary checkboxes
- [ ] Employee list shows each employee only once
- [ ] No duplicate employee records are created
- [ ] Database has proper UNIQUE constraint on employee_departments
- [ ] Each employee has exactly one primary department

## Notes

- The system uses a many-to-many relationship between employees and departments
- The `is_primary` flag distinguishes between primary and secondary departments
- The UNIQUE constraint ensures an employee cannot be assigned to the same department twice
- The employee list query uses `is_primary = 1` to show each employee only once
- Secondary departments are only relevant in the employee details/edit view
