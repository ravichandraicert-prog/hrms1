<?php
/**
 * Employees Module
 * Core PHP HRMS Application
 * Section 6 - Employee management module
 */

require_once __DIR__ . '/../includes/permissions.php';

$db = Database::getInstance();
$permissions = new Permissions();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('employees', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view employees.</div>';
    exit;
}

// Get scoped employee IDs
$scopedIds = $permissions->getScopedEmployeeIds($user['id'], 'employees', 'read');
$scopedIdsStr = implode(',', $scopedIds) ?: '0';

// Handle filters - default status to 'active' when not explicitly set
$filters = [
    'department' => $_GET['department'] ?? '',
    'role' => $_GET['role'] ?? '',
    'status' => isset($_GET['status']) ? $_GET['status'] : 'active',
    'search' => $_GET['search'] ?? ''
];

$whereConditions = ["e.id IN ({$scopedIdsStr})"];
$params = [];

// Employees hidden from the employees module list (by employee ID code).
// They are excluded from every filter/search combination of the list,
// but remain accessible elsewhere (profile pages, reporting-to dropdown, etc.).
$hiddenEmployeeIds = ['EMP-CEO-001'];
if (!empty($hiddenEmployeeIds)) {
    $whereConditions[] = "e.employee_id NOT IN (" . implode(',', array_fill(0, count($hiddenEmployeeIds), '?')) . ")";
    foreach ($hiddenEmployeeIds as $hiddenEmployeeId) {
        $params[] = $hiddenEmployeeId;
    }
}

if (!empty($filters['department'])) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = e.id AND ed.department_id = ? AND ed.is_primary = 1)";
    $params[] = $filters['department'];
}

if (!empty($filters['role'])) {
    $whereConditions[] = "e.role_id = ?";
    $params[] = $filters['role'];
}

if (!empty($filters['status']) && $filters['status'] != 'all') {
    if ($filters['status'] == 'active') {
        $whereConditions[] = "e.is_active = 1";
    } elseif ($filters['status'] == 'inactive') {
        $whereConditions[] = "e.is_active = 0";
    } elseif ($filters['status'] == 'blacklisted') {
        $whereConditions[] = "e.is_blacklisted = 1";
    }
}

if (!empty($filters['search'])) {
    $whereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.official_email LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

// Get employees
$employees = $db->fetchAll(
    "SELECT e.*, r.name as role_name, d.name as department_name,
     (SELECT name FROM departments WHERE id = (SELECT department_id FROM employee_departments WHERE employee_id = e.id AND is_primary = 1 LIMIT 1)) as primary_dept
     FROM employees e
     LEFT JOIN roles r ON e.role_id = r.id
     LEFT JOIN employee_departments ed ON e.id = ed.employee_id AND ed.is_primary = 1
     LEFT JOIN departments d ON ed.department_id = d.id
     WHERE {$whereClause}
     ORDER BY e.first_name, e.last_name
     LIMIT 100",
    $params
);

// Get filter options
$departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
$roles = $db->fetchAll("SELECT * FROM roles WHERE is_active = 1 ORDER BY hierarchy_level");

// Get employees for Reporting To dropdown (filtered by hierarchy)
$allEmployees = $db->fetchAll(
    "SELECT e.id, e.first_name, e.last_name, e.employee_id
     FROM employees e
     LEFT JOIN roles r ON e.role_id = r.id
     WHERE e.id IN ({$scopedIdsStr})
     ORDER BY e.first_name, e.last_name"
);

// Make employees data available to JavaScript
$allEmployeesJson = json_encode($allEmployees);
?>

<div class="panel-head">
</div>

<div class="toolbar">
    <div class="search" style="width:280px;">
        <i class="bi bi-search"></i>
        <input type="text" placeholder="Search employees..." value="<?php echo htmlspecialchars($filters['search']); ?>" 
               onkeyup="applyFilterDebounced('search', this.value)">
    </div>
    <select class="filter-chip" onchange="applyFilter('department', this.value)">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?php echo $dept['id']; ?>" <?php echo $filters['department'] == $dept['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($dept['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select class="filter-chip" onchange="applyFilter('role', this.value)">
        <option value="">All Roles</option>
        <?php foreach ($roles as $role): ?>
            <option value="<?php echo $role['id']; ?>" <?php echo $filters['role'] == $role['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($role['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select class="filter-chip" id="statusFilter" onchange="applyFilter('status', this.value)">
        <option value="">All Status</option>
        <option value="active" <?php echo $filters['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
        <option value="inactive" <?php echo $filters['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        <option value="blacklisted" <?php echo $filters['status'] == 'blacklisted' ? 'selected' : ''; ?>>Blacklisted</option>
    </select>
    <div class="spacer"></div>
    <?php if (hasPermission('employees', 'export')): ?>
        <button class="btn" onclick="exportEmployees()">
            <i class="bi bi-download"></i> Export
        </button>
    <?php endif; ?>
    <?php if (hasPermission('employees', 'create')): ?>
        <button class="btn" onclick="document.getElementById('importFileInput').click()">
            <i class="bi bi-upload"></i> Import
        </button>
        <input type="file" id="importFileInput" accept=".csv,.txt" style="display:none;" onchange="importEmployees(this)">
    <?php endif; ?>
    <?php if (hasPermission('employees', 'create')): ?>
        <button class="btn primary" onclick="openAddEmployeeModal()">
            <i class="bi bi-plus-lg"></i> Add Employee
        </button>
    <?php endif; ?>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Position</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:40px;">
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>No employees found</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td>
                            <div class="emp-cell">
                                <div class="avatar"><?php echo getInitials($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                <div>
                                    <div class="emp-name"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                    <div class="emp-id mono"><?php echo htmlspecialchars($emp['employee_id']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($emp['department_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($emp['official_email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($emp['phone_number'] ?? '-'); ?></td>
                        <td>
                            <?php if ($emp['is_blacklisted']): ?>
                                <span class="badge danger"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Blacklisted</span>
                            <?php elseif (!$emp['is_active']): ?>
                                <span class="badge danger"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Inactive</span>
                            <?php else: ?>
                                <span class="badge success"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if (hasPermission('employees', 'read')): ?>
                                    <div class="icon-btn" title="View" onclick="viewEmployee(<?php echo $emp['id']; ?>)">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if (hasPermission('employees', 'update')): ?>
                                <div class="icon-btn" title="Edit" onclick="editEmployee(<?php echo $emp['id']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </div>
                                <?php endif; ?>
                                <?php if (hasPermission('employees', 'delete')): ?>
                                <div class="icon-btn" title="Delete" style="color:var(--danger);" onclick="deleteEmployee(<?php echo $emp['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Employee Modal -->
<div id="employeeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:800px;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="modalTitle">Add Employee</h3>
            <button onclick="closeModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);">&times;</button>
        </div>
        <form id="employeeForm" onsubmit="saveEmployee(event)">
            <input type="hidden" id="employeeId">
            
            <!-- Personal Information -->
            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 16px 0;color:var(--accent);">Personal Information</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Employee ID</label>
                    <input type="text" id="employeeIdField" readonly style="background:var(--bg);">
                </div>
                <div class="field">
                    <label>First Name <span class="req">*</span></label>
                    <input type="text" id="firstName" required>
                </div>
                <div class="field">
                    <label>Last Name <span class="req">*</span></label>
                    <input type="text" id="lastName" required>
                </div>
                <div class="field">
                    <label>Personal Email</label>
                    <input type="email" id="personalEmail">
                </div>
                <div class="field">
                    <label>Official Email <span class="req">*</span></label>
                    <input type="email" id="officialEmail" required>
                </div>
                <div class="field">
                    <label>Alternate Email</label>
                    <input type="email" id="alternateEmail">
                </div>
                <div class="field">
                    <label>Phone <span class="req">*</span></label>
                    <input type="text" id="phone" required>
                </div>
                <div class="field">
                    <label>Alternate Phone</label>
                    <input type="text" id="alternatePhone">
                </div>
                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" id="dob">
                </div>
                <div class="field">
                    <label>Gender</label>
                    <select id="gender">
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="field">
                    <label>Marital Status</label>
                    <select id="maritalStatus">
                        <option value="">Select Status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Divorced">Divorced</option>
                        <option value="Widowed">Widowed</option>
                    </select>
                </div>
                <div class="field full">
                    <label>Address</label>
                    <textarea id="address" rows="2"></textarea>
                </div>
                <div class="field">
                    <label>State</label>
                    <input type="text" id="state">
                </div>
                <div class="field">
                    <label>City</label>
                    <input type="text" id="city">
                </div>
                <div class="field">
                    <label>Zip Code</label>
                    <input type="text" id="zipCode">
                </div>
            </div>
            
            <!-- Employment Information -->
            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:24px 0 16px 0;color:var(--accent);">Employment Information</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Primary Department <span class="req">*</span></label>
                    <select id="department" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field full">
                    <label>Secondary Departments</label>
                    <div style="position:relative;">
                        <div id="secondaryDeptDropdown" style="border:1px solid var(--border);border-radius:4px;padding:8px 12px;cursor:pointer;background:var(--surface);min-height:38px;display:flex;align-items:center;justify-content:space-between;" onclick="toggleSecondaryDeptDropdown()">
                            <span id="selectedSecondaryDepts" style="color:var(--muted);">Select departments...</span>
                            <i class="bi bi-chevron-down" style="color:var(--muted);"></i>
                        </div>
                        <div id="secondaryDeptOptions" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:4px;max-height:200px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,0.1);margin-top:4px;">
                            <?php foreach ($departments as $dept): ?>
                                <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0;font-size:13px;cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.2s;" data-dept-id="<?php echo $dept['id']; ?>">
                                    <input type="checkbox" name="secondary_dept" value="<?php echo $dept['id']; ?>" class="secondary-dept-checkbox" data-dept-name="<?php echo htmlspecialchars($dept['name']); ?>" style="width:16px;height:16px;cursor:pointer;">
                                    <span style="flex:1;"><?php echo htmlspecialchars($dept['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small style="color:var(--muted);font-size:11px;margin-top:4px;">Select additional departments for this employee (excluding primary department)</small>
                </div>
                <div class="field">
                    <label>Position</label>
                    <input type="text" id="position">
                </div>
                <div class="field">
                    <label>Reporting To</label>
                    <select id="reportingTo">
                        <option value="">Select Manager</option>
                        <?php foreach ($allEmployees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Date of Joining <span class="req">*</span></label>
                    <input type="date" id="doj" required>
                </div>
                <div class="field">
                    <label>Last Working Day</label>
                    <input type="date" id="lastWorkingDay">
                </div>
                <div class="field">
                    <label>Employment Type</label>
                    <select id="employmentType">
                        <option value="">Select Type</option>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                        <option value="Intern">Intern</option>
                    </select>
                </div>
                <div class="field">
                    <label>Role <span class="req">*</span></label>
                    <select id="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Username</label>
                    <input type="text" id="username">
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" id="password">
                </div>
                <div class="field">
                    <label>Holiday Calendar</label>
                    <select id="holidayCalendar">
                        <option value="">Select Calendar</option>
                        <?php 
                        $calendars = $db->fetchAll("SELECT * FROM holiday_calendars WHERE is_active = 1");
                        foreach ($calendars as $cal): 
                        ?>
                            <option value="<?php echo $cal['id']; ?>"><?php echo htmlspecialchars($cal['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Shift</label>
                    <select id="shift">
                        <option value="">Select Shift</option>
                        <?php
                        $shifts = $db->fetchAll("SELECT * FROM shifts WHERE is_active = 1");
                        foreach ($shifts as $shift):
                        ?>
                            <option value="<?php echo $shift['id']; ?>"><?php echo htmlspecialchars($shift['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Work Pattern</label>
                    <select id="workPatternId">
                        <option value="">Select Pattern</option>
                        <?php
                        $workPatterns = $db->fetchAll("SELECT * FROM work_patterns WHERE is_active = 1 ORDER BY name");
                        foreach ($workPatterns as $wp):
                        ?>
                            <option value="<?php echo $wp['id']; ?>"><?php echo htmlspecialchars($wp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Bank Details -->
            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:24px 0 16px 0;color:var(--accent);">Bank Details</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Bank Name</label>
                    <input type="text" id="bankName">
                </div>
                <div class="field">
                    <label>Account Number</label>
                    <input type="text" id="accountNumber">
                </div>
                <div class="field">
                    <label>IFSC Code</label>
                    <input type="text" id="ifscCode">
                </div>
                <div class="field">
                    <label>PAN Number</label>
                    <input type="text" id="panNumber">
                </div>
            </div>
            
            <!-- Status -->
            <div style="margin-top:24px;">
                <div class="field">
                    <label>Status</label>
                    <select id="status">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn primary">Save Employee</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1001;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:500px;max-width:90%;padding:24px;">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="width:48px;height:48px;background:var(--danger);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-exclamation-triangle" style="color:white;font-size:24px;"></i>
            </div>
            <div>
                <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0 0 8px 0;">Permanent Deletion Warning</h3>
                <p style="margin:0;color:var(--muted);font-size:14px;">This action cannot be undone</p>
            </div>
        </div>
        
        <div style="background:var(--bg);border-radius:var(--radius);padding:16px;margin-bottom:20px;">
            <p style="margin:0 0 12px 0;font-weight:600;">This will permanently delete the employee and all related data:</p>
            <ul style="margin:0;padding-left:20px;font-size:14px;color:var(--muted);">
                <li>Employee profile and personal information</li>
                <li>Department assignments and permissions</li>
                <li>Attendance records (punches and daily summaries)</li>
                <li>Leave balances and leave applications</li>
                <li>Salary structure assignments</li>
                <li>Shift assignments and work patterns</li>
                <li>Task assignments and related tasks</li>
                <li>Notifications and notice reads</li>
                <li>All other related records across the system</li>
            </ul>
        </div>
        
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn danger" id="confirmDeleteBtn">Yes, Delete Permanently</button>
        </div>
    </div>
</div>

<!-- View Employee Modal -->
<div id="viewEmployeeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:700px;max-width:90%;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Employee Details</h3>
            <button onclick="closeViewEmployeeModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);">&times;</button>
        </div>
        <div id="viewEmployeeContent">
            <!-- Content will be loaded dynamically -->
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:24px;">
            <button type="button" class="btn" onclick="closeViewEmployeeModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Make employees data available for view modal
const allEmployees = <?php echo $allEmployeesJson; ?>;

// Helper function to get initials (JavaScript version)
function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}

function applyFilter(type, value) {
    const url = new URL(window.location);
    if (value !== null && value !== undefined && value !== '') {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    window.location = url.toString();
}

// Set default status filter to 'active' on page load if no status parameter in URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('status')) {
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.value = 'active';
        }
    }
});

function applyFilterDebounced(type, value) {
    clearTimeout(window._searchTimer);
    window._searchTimer = setTimeout(() => applyFilter(type, value), 500);
}

function openAddEmployeeModal() {
    document.getElementById('modalTitle').textContent = 'Add Employee';
    document.getElementById('employeeId').value = '';
    document.getElementById('employeeForm').reset();
    // Generate employee ID
    document.getElementById('employeeIdField').value = 'EMP-' + Math.floor(1000 + Math.random() * 9000);
    
    // Clear all secondary department checkboxes
    document.querySelectorAll('.secondary-dept-checkbox').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.disabled = false;
    });
    
    // Reset dropdown display
    document.getElementById('selectedSecondaryDepts').textContent = 'Select departments...';
    document.getElementById('selectedSecondaryDepts').style.color = 'var(--muted)';
    document.getElementById('secondaryDeptOptions').style.display = 'none';
    
    document.getElementById('employeeModal').style.display = 'flex';
}

function editEmployee(id) {
    document.getElementById('modalTitle').textContent = 'Edit Employee';
    document.getElementById('employeeId').value = id;
    
    // Fetch employee data
    fetch(`api/employee.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const emp = data.employee;
                document.getElementById('employeeIdField').value = emp.employee_id;
                document.getElementById('firstName').value = emp.first_name;
                document.getElementById('lastName').value = emp.last_name;
                document.getElementById('personalEmail').value = emp.personal_email || '';
                document.getElementById('officialEmail').value = emp.official_email;
                document.getElementById('alternateEmail').value = emp.alternate_email || '';
                document.getElementById('phone').value = emp.phone_number;
                document.getElementById('alternatePhone').value = emp.alternate_phone || '';
                document.getElementById('dob').value = emp.date_of_birth || '';
                document.getElementById('gender').value = emp.gender || '';
                document.getElementById('maritalStatus').value = emp.marital_status || '';
                document.getElementById('address').value = emp.address || '';
                document.getElementById('state').value = emp.state || '';
                document.getElementById('city').value = emp.city || '';
                document.getElementById('zipCode').value = emp.zip_code || '';
                document.getElementById('department').value = emp.department_id || '';
                document.getElementById('position').value = emp.position || '';
                document.getElementById('reportingTo').value = emp.reporting_manager_id || '';
                document.getElementById('doj').value = emp.date_of_joining || '';
                // Last Working Day: prefer a value passed from the Recruitment Offboard popup,
                // otherwise fall back to the saved employee value.
                const lwdParam = new URLSearchParams(window.location.search).get('last_working_day');
                document.getElementById('lastWorkingDay').value = lwdParam || emp.last_working_day || '';
                document.getElementById('employmentType').value = emp.employment_type || '';
                document.getElementById('workPatternId').value = emp.work_pattern_id || '';
                document.getElementById('role').value = emp.role_id || '';
                document.getElementById('username').value = emp.username || '';
                document.getElementById('password').value = '';
                document.getElementById('holidayCalendar').value = emp.holiday_calendar_id || '';
                document.getElementById('shift').value = emp.shift_id || '';
                document.getElementById('bankName').value = emp.bank_name || '';
                document.getElementById('accountNumber').value = emp.account_number || '';
                document.getElementById('ifscCode').value = emp.ifsc_code || '';
                document.getElementById('panNumber').value = emp.pan_number || '';
                document.getElementById('status').value = emp.is_active ? '1' : '0';
                
                // Handle secondary departments
                const secondaryDepts = emp.secondary_departments || [];
                document.querySelectorAll('.secondary-dept-checkbox').forEach(checkbox => {
                    const deptId = parseInt(checkbox.value);
                    // Check if this department is in secondary departments
                    checkbox.checked = secondaryDepts.includes(deptId);
                    // Disable the checkbox if it's the primary department
                    checkbox.disabled = (deptId === parseInt(emp.department_id));
                });
                
                // Update the dropdown display
                updateSelectedSecondaryDepts();
                
                document.getElementById('employeeModal').style.display = 'flex';
            } else {
                ModalSystem.error('Error loading employee data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ModalSystem.error('Error loading employee data');
        });
}

function closeModal() {
    document.getElementById('employeeModal').style.display = 'none';
    // Close secondary departments dropdown
    document.getElementById('secondaryDeptOptions').style.display = 'none';
}

// Handle primary department change to update secondary department checkboxes
document.getElementById('department').addEventListener('change', function() {
    const primaryDeptId = parseInt(this.value);
    document.querySelectorAll('.secondary-dept-checkbox').forEach(checkbox => {
        const deptId = parseInt(checkbox.value);
        // Disable if it's the primary department
        checkbox.disabled = (deptId === primaryDeptId);
        // Uncheck if it was selected as secondary but now became primary
        if (deptId === primaryDeptId && checkbox.checked) {
            checkbox.checked = false;
            updateSelectedSecondaryDepts();
        }
    });
    
    // Re-enable checkboxes that were previously disabled but are no longer primary
    document.querySelectorAll('.secondary-dept-checkbox').forEach(checkbox => {
        const deptId = parseInt(checkbox.value);
        if (deptId !== primaryDeptId) {
            checkbox.disabled = false;
        }
    });
});

// Secondary departments dropdown functionality
function toggleSecondaryDeptDropdown() {
    const dropdown = document.getElementById('secondaryDeptOptions');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

function updateSelectedSecondaryDepts() {
    const selectedDepts = [];
    document.querySelectorAll('.secondary-dept-checkbox:checked').forEach(checkbox => {
        const deptName = checkbox.getAttribute('data-dept-name');
        selectedDepts.push(deptName);
    });
    
    const displaySpan = document.getElementById('selectedSecondaryDepts');
    if (selectedDepts.length === 0) {
        displaySpan.textContent = 'Select departments...';
        displaySpan.style.color = 'var(--muted)';
    } else {
        displaySpan.textContent = selectedDepts.join(', ');
        displaySpan.style.color = 'var(--ink)';
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('secondaryDeptOptions');
    const dropdownButton = document.getElementById('secondaryDeptDropdown');
    
    // Don't close if clicking on a checkbox
    if (event.target.classList.contains('secondary-dept-checkbox')) {
        return;
    }
    
    if (!dropdownButton.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// Update display when checkboxes change
document.querySelectorAll('.secondary-dept-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        updateSelectedSecondaryDepts();
    });
});

function saveEmployee(event) {
    event.preventDefault();
    
    // Collect secondary departments
    const secondaryDepartments = [];
    document.querySelectorAll('.secondary-dept-checkbox:checked').forEach(checkbox => {
        secondaryDepartments.push(parseInt(checkbox.value));
    });
    
    // Debug: Log collected secondary departments
    console.log('Secondary Departments collected:', secondaryDepartments);
    
    const formData = {
        id: document.getElementById('employeeId').value || null,
        employee_id: document.getElementById('employeeIdField').value,
        first_name: document.getElementById('firstName').value,
        last_name: document.getElementById('lastName').value,
        personal_email: document.getElementById('personalEmail').value,
        official_email: document.getElementById('officialEmail').value,
        alternate_email: document.getElementById('alternateEmail').value,
        phone: document.getElementById('phone').value,
        alternate_phone: document.getElementById('alternatePhone').value,
        date_of_birth: document.getElementById('dob').value,
        gender: document.getElementById('gender').value,
        marital_status: document.getElementById('maritalStatus').value,
        address: document.getElementById('address').value,
        state: document.getElementById('state').value,
        city: document.getElementById('city').value,
        zip_code: document.getElementById('zipCode').value,
        department_id: document.getElementById('department').value,
        position: document.getElementById('position').value,
        reporting_manager_id: document.getElementById('reportingTo').value || null,
        date_of_joining: document.getElementById('doj').value,
        last_working_day: document.getElementById('lastWorkingDay').value,
        employment_type: document.getElementById('employmentType').value,
        work_pattern_id: document.getElementById('workPatternId').value || null,
        role_id: document.getElementById('role').value,
        username: document.getElementById('username').value,
        password: document.getElementById('password').value || null,
        holiday_calendar_id: document.getElementById('holidayCalendar').value || null,
        shift_id: document.getElementById('shift').value || null,
        bank_name: document.getElementById('bankName').value,
        account_number: document.getElementById('accountNumber').value,
        ifsc_code: document.getElementById('ifscCode').value,
        pan_number: document.getElementById('panNumber').value,
        is_active: document.getElementById('status').value,
        secondary_departments: secondaryDepartments
    };
    
    const method = formData.id ? 'PUT' : 'POST';
    const url = formData.id ? `api/employee.php?id=${formData.id}` : 'api/employee.php';
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal();
            location.reload();
        } else {
            ModalSystem.error('Error saving employee: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        ModalSystem.error('Error saving employee');
    });
}

function deleteEmployee(id) {
    // Show the custom delete confirmation modal
    const modal = document.getElementById('deleteConfirmModal');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    // Set up the confirm button to perform the actual deletion
    confirmBtn.onclick = function() {
        fetch(`api/employee.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeDeleteModal();
                location.reload();
            } else {
                closeDeleteModal();
                ModalSystem.error('Error deleting employee: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            closeDeleteModal();
            ModalSystem.error('Error deleting employee');
        });
    };
    
    // Show the modal
    modal.style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
}

function viewEmployee(id) {
    // Show loading state
    const modal = document.getElementById('viewEmployeeModal');
    const content = document.getElementById('viewEmployeeContent');
    content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;font-size:24px;"></i><p style="margin-top:16px;color:var(--muted);">Loading employee details...</p></div>';
    modal.style.display = 'flex';
    
    // Fetch employee data
    fetch(`api/employee.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const emp = data.employee;
                const reportingManager = (emp.reporting_manager_first_name && emp.reporting_manager_last_name) 
                    ? `${emp.reporting_manager_first_name} ${emp.reporting_manager_last_name}` 
                    : '-';
                
                const statusBadge = emp.is_blacklisted ?
                    '<span class="badge danger"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Blacklisted</span>' :
                    (!emp.is_active ? '<span class="badge danger"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Inactive</span>' :
                    '<span class="badge success"><i class="bi bi-circle-fill" style="font-size:6px;"></i> Active</span>');
                
                const doj = emp.date_of_joining ? new Date(emp.date_of_joining).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
                const dob = emp.date_of_birth ? new Date(emp.date_of_birth).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
                const lwd = emp.last_working_day ? new Date(emp.last_working_day).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
                
                content.innerHTML = `
                    <div style="display:flex;align-items:start;gap:20px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border);">
                        <div class="avatar" style="width:80px;height:80px;font-size:28px;flex-shrink:0;">${getInitials(emp.first_name + ' ' + emp.last_name)}</div>
                        <div style="flex:1;">
                            <h3 style="font-family:'Space Grotesk';font-size:20px;font-weight:600;margin:0 0 4px 0;">${emp.first_name} ${emp.last_name}</h3>
                            <div style="color:var(--muted);font-size:13px;margin-bottom:8px;">${emp.employee_id}</div>
                            <div>${statusBadge}</div>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                        <div>
                            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 16px 0;color:var(--accent);">Work Information</h4>
                            <div style="display:grid;grid-template-columns:100px 1fr;gap:12px;font-size:13px;">
                                <div style="color:var(--muted);">Department</div>
                                <div>${emp.department_name || '-'}</div>
                                
                                <div style="color:var(--muted);">Position</div>
                                <div>${emp.position || '-'}</div>
                                
                                <div style="color:var(--muted);">Role</div>
                                <div>${emp.role_name || '-'}</div>
                                
                                <div style="color:var(--muted);">Reporting To</div>
                                <div>${reportingManager}</div>
                                
                                <div style="color:var(--muted);">Joining Date</div>
                                <div>${doj}</div>
                                
                                <div style="color:var(--muted);">Last Working Day</div>
                                <div>${lwd}</div>
                                
                                <div style="color:var(--muted);">Employment Type</div>
                                <div>${emp.employment_type || '-'}</div>
                            </div>
                        </div>
                        
                        <div>
                            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 16px 0;color:var(--accent);">Contact Information</h4>
                            <div style="display:grid;grid-template-columns:100px 1fr;gap:12px;font-size:13px;">
                                <div style="color:var(--muted);">Official Email</div>
                                <div>${emp.official_email || '-'}</div>
                                
                                <div style="color:var(--muted);">Personal Email</div>
                                <div>${emp.personal_email || '-'}</div>
                                
                                <div style="color:var(--muted);">Phone</div>
                                <div>${emp.phone_number || '-'}</div>
                                
                                <div style="color:var(--muted);">Alternate Phone</div>
                                <div>${emp.alternate_phone || '-'}</div>
                            </div>
                            
                            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:16px 0 16px 0;color:var(--accent);">Personal Information</h4>
                            <div style="display:grid;grid-template-columns:100px 1fr;gap:12px;font-size:13px;">
                                <div style="color:var(--muted);">Date of Birth</div>
                                <div>${dob}</div>
                                
                                <div style="color:var(--muted);">Gender</div>
                                <div>${emp.gender || '-'}</div>
                                
                                <div style="color:var(--muted);">Marital Status</div>
                                <div>${emp.marital_status || '-'}</div>
                            </div>
                        </div>
                    </div>
                    
                    ${emp.address ? `
                    <div style="margin-top:24px;padding-top:24px;border-top:1px solid var(--border);">
                        <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 12px 0;color:var(--accent);">Address</h4>
                        <div style="font-size:13px;color:var(--muted);">${emp.address}${emp.city ? ', ' + emp.city : ''}${emp.state ? ', ' + emp.state : ''}${emp.zip_code ? ' - ' + emp.zip_code : ''}</div>
                    </div>
                    ` : ''}
                `;
            } else {
                content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="bi bi-exclamation-triangle" style="font-size:32px;color:var(--danger);"></i><p style="margin-top:16px;color:var(--muted);">Unable to load employee details.<br>Please try again.</p></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="bi bi-exclamation-triangle" style="font-size:32px;color:var(--danger);"></i><p style="margin-top:16px;color:var(--muted);">Unable to load employee details.<br>Please try again.</p></div>';
        });
}

function closeViewEmployeeModal() {
    document.getElementById('viewEmployeeModal').style.display = 'none';
}

// Close view employee modal when clicking outside
document.getElementById('viewEmployeeModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeViewEmployeeModal();
    }
});

// Close view employee modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const viewModal = document.getElementById('viewEmployeeModal');
        if (viewModal && viewModal.style.display === 'flex') {
            closeViewEmployeeModal();
        }
    }
});

function exportEmployees() {
    const params = new URLSearchParams(window.location.search);
    const dept = params.get('department') || '';
    const role = params.get('role') || '';
    const status = params.get('status') || '';
    const search = params.get('search') || '';
    window.location.href = `api/employees_export.php?department=${encodeURIComponent(dept)}&role=${encodeURIComponent(role)}&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
}

function importEmployees(input) {
    const file = input.files[0];
    if (!file) return;
    
    // Validate file type
    const fileName = file.name.toLowerCase();
    if (!fileName.endsWith('.csv') && !fileName.endsWith('.txt')) {
        ModalSystem.warning('Please select a CSV file.');
        input.value = '';
        return;
    }
    
    // Show loading state
    const importBtn = document.querySelector('.toolbar .btn[onclick*="importFileInput"]');
    const originalHtml = importBtn.innerHTML;
    importBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Importing...';
    importBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('file', file);
    
    fetch('api/employees_import.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        importBtn.innerHTML = originalHtml;
        importBtn.disabled = false;
        input.value = '';
        
        if (data.success) {
            let message = data.message;
            if (data.errors && data.errors.length > 0) {
                message += '\n\nDetails:\n' + data.errors.slice(0, 10).join('\n');
                if (data.errors.length > 10) {
                    message += '\n... and ' + (data.errors.length - 10) + ' more';
                }
            }
            ModalSystem.info(message);
            if (data.imported > 0) {
                location.reload();
            }
        } else {
            ModalSystem.error('Import failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        importBtn.innerHTML = originalHtml;
        importBtn.disabled = false;
        input.value = '';
        console.error('Error:', error);
        ModalSystem.error('Error importing employees. Please try again.');
    });
}

// Auto-open the Edit Employee form when navigated from the Recruitment Offboard flow,
// e.g. index.php?module=employees&edit=<id>&last_working_day=YYYY-MM-DD
(function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('module') === 'employees' && params.get('edit')) {
        editEmployee(parseInt(params.get('edit'), 10));
    }
})();
</script>


