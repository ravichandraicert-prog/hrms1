<?php
/**
 * Employees CSV Import API
 * Handles CSV file upload and bulk employee import
 * Supports the HRMS employee Excel/CSV template format
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/leave_assignment.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $user = getCurrentUser();
    $db = Database::getInstance();

    // Check permission - require create permission for importing employees
    if (!hasPermission('employees', 'create')) {
        jsonResponse(['success' => false, 'message' => 'Access denied. You do not have permission to import employees.'], 403);
    }

    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error occurred'], 400);
    }

    $file = $_FILES['file'];
    
    // Validate file type
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'])) {
        jsonResponse(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.'], 400);
    }

    // Validate file size (5MB max)
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonResponse(['success' => false, 'message' => 'File size exceeds the 5MB limit'], 400);
    }

    // Open and parse the CSV file
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        jsonResponse(['success' => false, 'message' => 'Failed to open uploaded file'], 500);
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'CSV file is empty or invalid'], 400);
    }

    // Column alias mapping - maps user's Excel column names to internal field names
    $columnAliases = [
        'timestamp' => 'timestamp',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'personal_email' => 'personal_email',
        'official_email' => 'official_email',
        'alternate_email' => 'alternate_email',
        'phone' => 'phone',
        'alternate_phone_no' => 'alternate_phone',
        'alternate_phone' => 'alternate_phone',
        'date_of_birth' => 'date_of_birth',
        'gender' => 'gender',
        'marital_status' => 'marital_status',
        'maritalstatus' => 'marital_status',
        'address' => 'address',
        'state' => 'state',
        'city' => 'city',
        'zip_code' => 'zip_code',
        'zipcode' => 'zip_code',
        'primary_department' => 'primary_department',
        'primarydepartment' => 'primary_department',
        'department' => 'primary_department',
        'secondary_departments' => 'secondary_departments',
        'secondarydepartments' => 'secondary_departments',
        'position' => 'position',
        'reporting_to' => 'reporting_to',
        'reportingto' => 'reporting_to',
        'reporting_manager' => 'reporting_to',
        'date_of_joining' => 'date_of_joining',
        'dateofjoining' => 'date_of_joining',
        'employee_type' => 'employee_type',
        'employeetype' => 'employee_type',
        'employment_type' => 'employee_type',
        'work_pattern' => 'work_pattern',
        'workpattern' => 'work_pattern',
        'working_days_config' => 'work_pattern',
        'assign_shift' => 'assign_shift',
        'assignshift' => 'assign_shift',
        'shift' => 'assign_shift',
        'assign_holiday_calendar' => 'assign_holiday_calendar',
        'assignholidaycalendar' => 'assign_holiday_calendar',
        'holiday_calendar' => 'assign_holiday_calendar',
        'bank_name' => 'bank_name',
        'bankname' => 'bank_name',
        'ifsc_code' => 'ifsc_code',
        'ifsccode' => 'ifsc_code',
        'account_number' => 'account_number',
        'accountnumber' => 'account_number',
        'pan_number' => 'pan_number',
        'pannumber' => 'pan_number',
        'username' => 'username',
        'employee_id' => 'employee_id',
        'status' => 'status'
    ];

    // Normalize headers and map to internal field names
    $headerMap = [];
    foreach ($headers as $index => $header) {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9_]/', '_', $normalized);
        // Map to internal field name using aliases
        $headerMap[$index] = $columnAliases[$normalized] ?? $normalized;
    }

    // Required columns
    $requiredColumns = ['first_name', 'last_name', 'official_email'];
    $missingColumns = [];
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $headerMap)) {
            $missingColumns[] = $col;
        }
    }

    if (!empty($missingColumns)) {
        fclose($handle);
        jsonResponse([
            'success' => false, 
            'message' => 'CSV is missing required columns: ' . implode(', ', $missingColumns) . 
                         '. Required columns: First Name, Last Name, Official Email'
        ], 400);
    }

    // Pre-load departments for name-to-ID resolution
    $departments = [];
    $deptRows = $db->fetchAll("SELECT id, name FROM departments WHERE is_active = 1");
    foreach ($deptRows as $dept) {
        $departments[strtolower(trim($dept['name']))] = $dept['id'];
    }

    // Pre-load shifts for name-to-ID resolution
    $shifts = [];
    $shiftRows = $db->fetchAll("SELECT id, name FROM shifts WHERE is_active = 1");
    foreach ($shiftRows as $shift) {
        $shifts[strtolower(trim($shift['name']))] = $shift['id'];
    }

    // Pre-load holiday calendars for name-to-ID resolution
    $calendars = [];
    $calRows = $db->fetchAll("SELECT id, name FROM holiday_calendars WHERE is_active = 1");
    foreach ($calRows as $cal) {
        $calendars[strtolower(trim($cal['name']))] = $cal['id'];
    }

    // Get existing employee IDs and emails for duplicate detection
    $existingEmployees = $db->fetchAll("SELECT employee_id, official_email FROM employees");
    $existingIds = [];
    $existingEmails = [];
    foreach ($existingEmployees as $emp) {
        $existingIds[strtolower(trim($emp['employee_id']))] = true;
        $existingEmails[strtolower(trim($emp['official_email']))] = true;
    }

    // Get all employees for reporting manager resolution
    $allEmployees = $db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees");
    $employeeNameMap = [];
    foreach ($allEmployees as $emp) {
        $fullName = strtolower(trim($emp['first_name'] . ' ' . $emp['last_name']));
        $employeeNameMap[$fullName] = $emp['id'];
        $employeeNameMap[strtolower(trim($emp['employee_id']))] = $emp['id'];
    }

    // Helper function to convert DD/MM/YYYY to YYYY-MM-DD
    function convertDate($dateStr) {
        if (empty($dateStr)) return null;
        $dateStr = trim($dateStr);
        // Check if already in YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }
        // Check for DD/MM/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }
        // Check for DD-MM-YYYY format
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $dateStr, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }
        // Try strtotime as fallback
        $ts = strtotime($dateStr);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return null;
    }

    // Begin transaction
    $db->beginTransaction();

    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $rowNumber = 1; // Header is row 1

    // Process each data row
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        
        // Skip empty rows
        if (count($row) <= 1 && (trim($row[0] ?? '') === '')) {
            continue;
        }

        // Build data array from row
        $data = [];
        foreach ($headerMap as $index => $colName) {
            $data[$colName] = trim($row[$index] ?? '');
        }

        // Validate required fields
        $rowErrors = [];
        if (empty($data['first_name'])) $rowErrors[] = 'First Name is required';
        if (empty($data['last_name'])) $rowErrors[] = 'Last Name is required';
        if (empty($data['official_email'])) {
            $rowErrors[] = 'Official Email is required';
        } elseif (!filter_var($data['official_email'], FILTER_VALIDATE_EMAIL)) {
            $rowErrors[] = 'Invalid email format: ' . $data['official_email'];
        }

        if (!empty($rowErrors)) {
            $failed++;
            $errors[] = "Row {$rowNumber}: " . implode('; ', $rowErrors);
            continue;
        }

        // Check for duplicates
        $empIdKey = strtolower($data['employee_id'] ?? '');
        $emailKey = strtolower($data['official_email']);
        
        if (!empty($empIdKey) && isset($existingIds[$empIdKey])) {
            $skipped++;
            $errors[] = "Row {$rowNumber}: Employee ID '{$data['employee_id']}' already exists - skipped";
            continue;
        }
        
        if (isset($existingEmails[$emailKey])) {
            $skipped++;
            $errors[] = "Row {$rowNumber}: Email '{$data['official_email']}' already exists - skipped";
            continue;
        }

        // Resolve primary department
        $primaryDepartmentId = null;
        if (!empty($data['primary_department'])) {
            $deptKey = strtolower($data['primary_department']);
            if (isset($departments[$deptKey])) {
                $primaryDepartmentId = $departments[$deptKey];
            } else {
                $failed++;
                $errors[] = "Row {$rowNumber}: Primary Department '{$data['primary_department']}' not found";
                continue;
            }
        }

        // Resolve secondary departments (comma-separated)
        $secondaryDepartmentIds = [];
        if (!empty($data['secondary_departments'])) {
            $secondaryDeptNames = explode(',', $data['secondary_departments']);
            foreach ($secondaryDeptNames as $deptName) {
                $deptName = trim($deptName);
                if (empty($deptName)) continue;
                $deptKey = strtolower($deptName);
                if (isset($departments[$deptKey])) {
                    $deptId = $departments[$deptKey];
                    // Skip if same as primary
                    if ($deptId != $primaryDepartmentId && !in_array($deptId, $secondaryDepartmentIds)) {
                        $secondaryDepartmentIds[] = $deptId;
                    }
                } else {
                    $failed++;
                    $errors[] = "Row {$rowNumber}: Secondary Department '{$deptName}' not found";
                    continue 2;
                }
            }
        }

        // Resolve reporting manager
        $reportingManagerId = null;
        if (!empty($data['reporting_to'])) {
            $managerKey = strtolower($data['reporting_to']);
            if (isset($employeeNameMap[$managerKey])) {
                $reportingManagerId = $employeeNameMap[$managerKey];
            }
            // If reporting manager name is not found, allow import to proceed
            // without assigning a reporting manager (leave as null)
        }

        // Resolve shift
        $shiftId = null;
        if (!empty($data['assign_shift'])) {
            $shiftKey = strtolower($data['assign_shift']);
            if (isset($shifts[$shiftKey])) {
                $shiftId = $shifts[$shiftKey];
            } else {
                $failed++;
                $errors[] = "Row {$rowNumber}: Shift '{$data['assign_shift']}' not found";
                continue;
            }
        }

        // Resolve holiday calendar
        $holidayCalendarId = null;
        if (!empty($data['assign_holiday_calendar'])) {
            $calKey = strtolower($data['assign_holiday_calendar']);
            if (isset($calendars[$calKey])) {
                $holidayCalendarId = $calendars[$calKey];
            } else {
                $failed++;
                $errors[] = "Row {$rowNumber}: Holiday Calendar '{$data['assign_holiday_calendar']}' not found";
                continue;
            }
        }

        // Determine status
        $isActive = 1;
        if (!empty($data['status'])) {
            $statusKey = strtolower($data['status']);
            if (in_array($statusKey, ['inactive', '0', 'no', 'false'])) {
                $isActive = 0;
            }
        }

        // Convert dates from DD/MM/YYYY to YYYY-MM-DD
        $dateOfBirth = convertDate($data['date_of_birth'] ?? '');
        $dateOfJoining = convertDate($data['date_of_joining'] ?? '');

        // Generate employee ID if not provided
        $employeeId = !empty($data['employee_id']) ? $data['employee_id'] : generateEmployeeId();

        // Insert employee
        $insertData = [
            'employee_id' => $employeeId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'official_email' => $data['official_email'],
            'phone_number' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'date_of_joining' => $dateOfJoining,
            'employment_type' => $data['employee_type'] ?? null,
            'reporting_manager_id' => $reportingManagerId,
            'shift_id' => $shiftId,
            'holiday_calendar_id' => $holidayCalendarId,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add optional fields if present
        if (!empty($data['personal_email'])) $insertData['personal_email'] = $data['personal_email'];
        if (!empty($data['alternate_email'])) $insertData['alternate_email'] = $data['alternate_email'];
        if (!empty($data['alternate_phone'])) $insertData['alternate_phone'] = $data['alternate_phone'];
        if (!empty($dateOfBirth)) $insertData['date_of_birth'] = $dateOfBirth;
        if (!empty($data['gender'])) $insertData['gender'] = $data['gender'];
        if (!empty($data['marital_status'])) $insertData['marital_status'] = $data['marital_status'];
        if (!empty($data['address'])) $insertData['address'] = $data['address'];
        if (!empty($data['state'])) $insertData['state'] = $data['state'];
        if (!empty($data['city'])) $insertData['city'] = $data['city'];
        if (!empty($data['zip_code'])) $insertData['zip_code'] = $data['zip_code'];
        if (!empty($data['work_pattern'])) $insertData['working_days_config'] = $data['work_pattern'];
        if (!empty($data['username'])) $insertData['username'] = $data['username'];
        if (!empty($data['bank_name'])) $insertData['bank_name'] = $data['bank_name'];
        if (!empty($data['account_number'])) $insertData['account_number'] = $data['account_number'];
        if (!empty($data['ifsc_code'])) $insertData['ifsc_code'] = $data['ifsc_code'];
        if (!empty($data['pan_number'])) $insertData['pan_number'] = $data['pan_number'];

        try {
            $newEmployeeId = $db->insert('employees', $insertData);
            
            // Assign to primary department
            if ($primaryDepartmentId) {
                $db->insert('employee_departments', [
                    'employee_id' => $newEmployeeId,
                    'department_id' => $primaryDepartmentId,
                    'is_primary' => 1
                ]);
            }
            
            // Assign to secondary departments
            foreach ($secondaryDepartmentIds as $deptId) {
                $db->insert('employee_departments', [
                    'employee_id' => $newEmployeeId,
                    'department_id' => $deptId,
                    'is_primary' => 0
                ]);
            }
            
            // Track for duplicate detection within this import
            $existingIds[strtolower($employeeId)] = true;
            $existingEmails[$emailKey] = true;
            
            // Add to name map for reporting manager resolution in later rows
            $fullName = strtolower(trim($data['first_name'] . ' ' . $data['last_name']));
            $employeeNameMap[$fullName] = $newEmployeeId;
            $employeeNameMap[strtolower($employeeId)] = $newEmployeeId;
            
            $imported++;
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Row {$rowNumber}: Database error - " . $e->getMessage();
        }
    }

    // Provision leave type balances for all newly imported employees so they
    // immediately appear in Leaves -> Balances (non-destructive sync).
    if (function_exists('sync_leave_type_balances')) {
        $activeLeaveTypes = $db->fetchAll("SELECT * FROM leave_types WHERE is_active = 1");
        foreach ($activeLeaveTypes as $leaveTypeToSync) {
            sync_leave_type_balances($db, $leaveTypeToSync);
        }
    }

    fclose($handle);

    // Commit transaction
    $db->commit();

    jsonResponse([
        'success' => true,
        'message' => "Import completed: {$imported} imported, {$skipped} skipped, {$failed} failed",
        'imported' => $imported,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    // Rollback on any unexpected error
    if (isset($db) && $db->getConnection()->inTransaction()) {
        $db->rollback();
    }
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}