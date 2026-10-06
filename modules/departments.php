<?php
/**
 * Departments Module
 * Core PHP HRMS Application
 * Section 7 - Department module
 */

$db = Database::getInstance();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('departments', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view departments.</div>';
    exit;
}

// Handle filters
$filters = [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? ''
];

// Build query
$whereConditions = [];
$params = [];

if (!empty($filters['search'])) {
    $whereConditions[] = "(d.name LIKE ? OR d.code LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($filters['status'])) {
    if ($filters['status'] == 'active') {
        $whereConditions[] = "d.is_active = 1";
    } elseif ($filters['status'] == 'inactive') {
        $whereConditions[] = "d.is_active = 0";
    }
}

$whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

// Get departments
$departments = $db->fetchAll(
    "SELECT d.*, 
     (SELECT COUNT(*) FROM employee_departments WHERE department_id = d.id AND is_primary = 1) as employee_count,
     e.first_name, e.last_name 
     FROM departments d
     LEFT JOIN employees e ON d.head_of_department_id = e.id
     WHERE {$whereClause}
     ORDER BY d.name",
    $params
);
?>

<div class="panel-head">
</div>

<div class="toolbar">
    <div class="search" style="width:280px;">
        <i class="bi bi-search"></i>
        <input type="text" placeholder="Search departments..." value="<?php echo htmlspecialchars($filters['search']); ?>" 
               onchange="applyFilter('search', this.value)">
    </div>
    <select class="filter-chip" onchange="applyFilter('status', this.value)">
        <option value="">All Status</option>
        <option value="active" <?php echo $filters['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
        <option value="inactive" <?php echo $filters['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
    </select>
    <div class="spacer"></div>
    <?php if (hasPermission('departments', 'create')): ?>
        <button class="btn primary" onclick="openAddDepartmentModal()">
            <i class="bi bi-plus-lg"></i> Add Department
        </button>
    <?php endif; ?>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Head of Department</th>
                <th>Employees</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($departments)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:40px;">
                        <div class="empty-state">
                            <i class="bi bi-diagram-3"></i>
                            <p>No departments found</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($dept['name']); ?></td>
                        <td><?php echo htmlspecialchars($dept['code']); ?></td>
                        <td>
                            <?php if ($dept['head_of_department_id']): ?>
                                <?php echo htmlspecialchars($dept['first_name'] . ' ' . $dept['last_name']); ?>
                            <?php else: ?>
                                <span style="color:var(--faint);">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($dept['employee_count']); ?></td>
                        <td>
                            <?php if ($dept['is_active']): ?>
                                <span class="badge success">Active</span>
                            <?php else: ?>
                                <span class="badge gray">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <button class="icon-btn" onclick="viewDepartmentEmployees(<?php echo $dept['id']; ?>, '<?php echo htmlspecialchars($dept['name']); ?>')" title="View Employees">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if (hasPermission('departments', 'update')): ?>
                                <button class="icon-btn" onclick="editDepartment(<?php echo $dept['id']; ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="icon-btn" onclick="toggleDepartment(<?php echo $dept['id']; ?>, <?php echo $dept['is_active'] ? 0 : 1; ?>)"
                                    title="<?php echo $dept['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                    <i class="bi bi-<?php echo $dept['is_active'] ? 'dash-circle' : 'check-circle'; ?>"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (hasPermission('departments', 'delete')): ?>
                                <button class="icon-btn" onclick="deleteDepartment(<?php echo $dept['id']; ?>)" title="Delete" style="color:var(--danger);">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Department Modal -->
<div id="departmentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:960px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="modalTitle">Add Department</h3>
            <button onclick="closeModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);">&times;</button>
        </div>
        
        <!-- Tabs -->
        <div style="display:flex;gap:10px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <button type="button" class="tab-btn active" onclick="switchTab('basic')" id="tabBasic">Basic Info</button>
            <button type="button" class="tab-btn" onclick="switchTab('hierarchy')" id="tabHierarchy">Hierarchy & Employees</button>
        </div>
        
        <form id="departmentForm" onsubmit="saveDepartment(event)">
            <input type="hidden" id="departmentId">
            
            <!-- Basic Info Tab -->
            <div id="basicTab" class="tab-content">
                <div class="form-grid">
                    <div class="field full">
                        <label>Department Name <span class="req">*</span></label>
                        <input type="text" id="deptName" required>
                    </div>
                    <div class="field full">
                        <label>Department Code <span class="req">*</span></label>
                        <input type="text" id="deptCode" required>
                    </div>
                    <div class="field full">
                        <label>Description</label>
                        <textarea id="deptDescription" rows="3"></textarea>
                    </div>
                    <div class="field full">
                        <label>Parent Department</label>
                        <select id="deptParent">
                            <option value="">None (Root Department)</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field full">
                        <label>Head of Department</label>
                        <select id="deptHead">
                            <option value="">Not assigned</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label>Status</label>
                        <select id="deptStatus">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Hierarchy Tab -->
            <div id="hierarchyTab" class="tab-content" style="display:none;">
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 10px 0;font-size:14px;">Reporting Hierarchy</h4>
                    <p style="color:var(--muted);font-size:13px;margin:0;">Build the organization chart by selecting employees and setting their reporting managers. Only employees assigned to this department are shown.</p>
                </div>
                
                <!-- Controls -->
                <div style="margin-bottom:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn primary" style="padding:8px 16px;font-size:13px;" onclick="openAddToHierarchyModal()">
                        <i class="bi bi-plus"></i> Add to Hierarchy
                    </button>
                    <div style="margin-left:auto;">
                        <span style="font-size:13px;color:var(--muted);">
                            <strong>Available:</strong> <span id="availableCount">0</span> | 
                            <strong>In Hierarchy:</strong> <span id="hierarchyCount">0</span>
                        </span>
                    </div>
                </div>
                
                <!-- Available Employees List (Compact) -->
                <div style="margin-bottom:15px;padding:12px;background:var(--background);border-radius:var(--radius);border:1px solid var(--border);">
                    <h5 style="margin:0 0 10px 0;font-size:12px;">Employees Not in Hierarchy</h5>
                    <div id="availableEmployeesList" style="display:flex;flex-wrap:wrap;gap:8px;max-height:80px;overflow-y:auto;">
                        <span style="color:var(--muted);font-size:11px;">Loading...</span>
                    </div>
                </div>
                
                <!-- Full-width Hierarchy Diagram -->
                <div id="hierarchyDiagram" class="hierarchy-diagram" style="min-height:600px;border:1px solid var(--border);border-radius:var(--radius);padding:0;background:var(--surface);overflow:auto;">
                    <div class="empty-state" style="text-align:center;padding:40px;color:var(--muted);">
                        <i class="bi bi-diagram-3"></i>
                        <p style="margin:10px 0 0 0;font-size:12px;">No hierarchy established</p>
                        <p style="margin:5px 0 0 0;font-size:11px;">Click "Add to Hierarchy" to build the organization chart</p>
                    </div>
                </div>
                
                <!-- Hierarchy Summary -->
                <div style="margin-top:20px;padding:15px;background:var(--background);border-radius:var(--radius);border:1px solid var(--border);">
                    <h5 style="margin:0 0 10px 0;font-size:13px;">Hierarchy Statistics</h5>
                    <div id="hierarchyStats" style="font-size:12px;color:var(--muted);">
                        <p style="margin:0;">No hierarchy data available.</p>
                    </div>
                </div>
            </div>
            
            <!-- Add to Hierarchy Modal -->
            <div id="addToHierarchyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1100;align-items:center;justify-content:center;">
                <div style="background:var(--surface);border-radius:var(--radius);width:960px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:30px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;">
                        <h3 style="font-family:'Space Grotesk';font-size:20px;font-weight:600;margin:0;">Add Employee to Hierarchy</h3>
                        <button onclick="closeAddToHierarchyModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted);">&times;</button>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <label style="display:block;margin-bottom:8px;font-size:14px;font-weight:500;">Select Employee</label>
                        <select id="hierarchyEmployeeSelect" style="width:100%;padding:12px;border:1px solid var(--border);border-radius:6px;font-size:15px;">
                            <option value="">Choose an employee...</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <label style="display:block;margin-bottom:8px;font-size:14px;font-weight:500;">Reports To (Manager)</label>
                        <select id="hierarchyManagerSelect" style="width:100%;padding:12px;border:1px solid var(--border);border-radius:6px;font-size:15px;">
                            <option value="">No manager (Top level)</option>
                        </select>
                        <p style="margin:8px 0 0 0;font-size:12px;color:var(--muted;">Leave empty for top-level position</p>
                    </div>
                    
                    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:25px;">
                        <button type="button" class="btn" onclick="closeAddToHierarchyModal()" style="padding:10px 20px;">Cancel</button>
                        <button type="button" class="btn primary" onclick="addEmployeeToHierarchy()" style="padding:10px 20px;">Add to Hierarchy</button>
                    </div>
                </div>
            </div>
            
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn primary">Save Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Department Employees View Modal -->
<div id="departmentEmployeesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:900px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="deptEmployeesTitle">Department Employees</h3>
            <button onclick="closeDeptEmployeesModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);">&times;</button>
        </div>
        
        <div style="margin-bottom:20px;">
            <div class="search" style="width:280px;">
                <i class="bi bi-search"></i>
                <input type="text" id="deptEmployeeSearch" placeholder="Search employees..." onkeyup="filterDeptEmployees()">
            </div>
        </div>
        
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Employee ID</th>
                        <th>Position</th>
                        <th>Email</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="deptEmployeesList">
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p>Loading employees...</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button type="button" class="btn" onclick="closeDeptEmployeesModal()">Close</button>
        </div>
    </div>
</div>

<style>
:root{
    /* Alias this module's color names to the host theme variables defined in index.php */
    --primary: var(--accent);
    --text: var(--ink);
    --background: var(--bg);
}

.tab-btn {
    background:none;
    border:none;
    padding:8px 16px;
    cursor:pointer;
    font-size:14px;
    color:var(--muted);
    border-bottom:2px solid transparent;
    transition:all 0.2s;
}

.tab-btn.active {
    color:var(--primary);
    border-bottom-color:var(--primary);
    font-weight:600;
}

.tab-content {
    display:block;
}

/* Employee List Styles */
.employee-list-item {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 12px;
    margin:6px 0;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:6px;
    cursor:pointer;
    transition:all 0.2s;
}

.employee-list-item:hover {
    border-color:var(--primary);
    background:rgba(59,130,246,0.05);
}

.employee-list-item.selected {
    border-color:var(--primary);
    background:rgba(59,130,246,0.1);
    box-shadow:0 0 0 2px rgba(59,130,246,0.2);
}

.employee-list-item .employee-info {
    display:flex;
    align-items:center;
    gap:10px;
}

.employee-list-item .employee-avatar {
    width:32px;
    height:32px;
    border-radius:50%;
    background:linear-gradient(135deg, var(--primary), #4f46e5);
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:600;
}

.employee-list-item .employee-details {
    display:flex;
    flex-direction:column;
}

.employee-list-item .employee-name {
    font-size:13px;
    font-weight:500;
    color:var(--text);
}

.employee-list-item .employee-role {
    font-size:11px;
    color:var(--muted);
}

.employee-list-item .employee-status {
    font-size:10px;
    padding:2px 6px;
    border-radius:4px;
    background:var(--background);
    color:var(--muted);
}

.employee-list-item .employee-status.in-hierarchy {
    background:var(--primary);
    color:white;
}

/* Available Employees Chips */
.available-employee-chip {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:20px;
    font-size:11px;
    cursor:pointer;
    transition:all 0.2s;
}

.available-employee-chip:hover {
    border-color:var(--primary);
    background:rgba(59,130,246,0.05);
}

.available-employee-chip .chip-avatar {
    width:20px;
    height:20px;
    border-radius:50%;
    background:linear-gradient(135deg, var(--primary), #4f46e5);
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:9px;
    font-weight:600;
}

.available-employee-chip .chip-name {
    font-weight:500;
    color:var(--text);
}

button:disabled {
    opacity:0.5;
    cursor:not-allowed;
}

button:disabled {
    opacity:0.5;
    cursor:not-allowed;
}

/* Hierarchy Diagram Styles */
.hierarchy-diagram {
    position: relative;
    overflow-x: auto;
    overflow-y: auto;
    min-height: 400px;
    background: var(--background);
}

.org-chart-container {
    display: block;
    text-align: center;
    min-width: fit-content;
    padding: 8px 30px;
}

/* Clean organization tree with proper reporting lines */
.org-tree {
    text-align: center;
    min-width: fit-content;
}

.org-tree ul {
    list-style: none;
    margin: 0;
    padding: 36px 0 0;
    text-align: center;
    white-space: nowrap;
    font-size: 0;                 /* cancel the inline whitespace gap between nodes */
    position: relative;
}

.org-tree li {
    display: inline-block;
    list-style: none;
    text-align: center;
    position: relative;
    padding: 36px 16px 0;
    font-size: 14px;
    white-space: nowrap;
    vertical-align: top;
}

/* Top-level cards have no parent above them, so hide their dangling stubs */
.org-tree li.top::before,
.org-tree li.top::after {
    display: none;
}

.org-tree li.top {
    padding-top: 16px;
}

/* The outermost list has no parent card above it, so draw no dangling trunk */
.org-tree > ul::before {
    display: none;
}

.org-card {
    position: relative;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 170px;
    height: 150px;
    padding: 14px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    transition: all 0.2s ease;
    z-index: 2;
}

.org-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 8px rgba(0,0,0,0.12);
}

/* Close (delete) button pinned to the top-right corner of every card */
.card-close {
    position: absolute;
    top: 6px;
    right: 8px;
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    background: none;
    color: var(--muted);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    z-index: 3;
}

.card-close:hover {
    color: var(--danger);
    background: var(--danger-bg);
}

.card-avatar {
    display: block;
    margin: 0 auto;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), #4f46e5);
    color: white;
    line-height: 44px;
    text-align: center;
    font-size: 15px;
    font-weight: 600;
}

.card-name {
    margin-top: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

.card-position {
    margin-top: 2px;
    font-size: 10px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

/* Horizontal spine above each reports row + a short vertical leg into each card */
.org-tree li::before,
.org-tree li::after {
    content: '';
    position: absolute;
    top: 0;
    width: 50%;
    height: 36px;
    border-top: 2px solid var(--muted);
}

.org-tree li::before {
    left: 0;
}

.org-tree li::after {
    left: 50%;
    border-left: 2px solid var(--muted);
}

/* Trim the dangling outer stubs of the first / last columns */
.org-tree li:first-child::before {
    border-top: 0;
}

.org-tree li:last-child::after {
    border-top: 0;
}

/* Vertical trunk from a manager's card down to its reports row */
.org-tree li > ul::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    width: 0;
    height: 36px;
    border-left: 2px solid var(--muted);
}

.hierarchy-level-indicator {
    font-size:10px;
    padding:2px 6px;
    border-radius:4px;
    background:var(--background);
    color:var(--muted);
    margin-left:4px;
}

.collapsed-children {
    display:none;
}

.expand-btn {
    background:none;
    border:none;
    cursor:pointer;
    padding:2px 6px;
    font-size:10px;
    color:var(--primary);
    border-radius:4px;
    margin-top:4px;
}

.expand-btn:hover {
    background:rgba(59,130,246,0.1);
}
</style>

<script>
let allEmployees = [];
let departmentEmployees = []; // Only employees in current department
let hierarchyData = {}; // Stores reporting relationships: { employeeId: managerId }

function applyFilter(type, value) {
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    window.location.href = url.toString();
}

function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.style.display = 'none';
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + 'Tab').style.display = 'block';
    document.getElementById('tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1)).classList.add('active');
    
    // Load hierarchy data when switching to hierarchy tab
    if (tabName === 'hierarchy' && document.getElementById('departmentId').value) {
        loadDepartmentHierarchyData(document.getElementById('departmentId').value);
    }
}

function openAddDepartmentModal() {
    document.getElementById('modalTitle').textContent = 'Add Department';
    document.getElementById('departmentId').value = '';
    document.getElementById('departmentForm').reset();
    document.getElementById('departmentModal').style.display = 'flex';
    switchTab('basic');
    
    // Load employees for the hierarchy tab
    loadAllEmployees();
}

function editDepartment(id) {
    document.getElementById('modalTitle').textContent = 'Edit Department';
    document.getElementById('departmentId').value = id;
    
    // Fetch department data
    fetch(`api/department.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('deptName').value = data.department.name;
                document.getElementById('deptCode').value = data.department.code;
                document.getElementById('deptDescription').value = data.department.description || '';
                document.getElementById('deptStatus').value = data.department.is_active ? '1' : '0';
                document.getElementById('deptParent').value = data.department.parent_department_id || '';
                document.getElementById('deptHead').value = data.department.head_of_department_id || '';
                document.getElementById('departmentModal').style.display = 'flex';
                switchTab('basic');
                
                // Load employees for hierarchy tab
                loadAllEmployees();
            } else {
                alert('Error loading department data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading department data');
        });
}

function closeModal() {
    document.getElementById('departmentModal').style.display = 'none';
}

function loadAllEmployees() {
    fetch('api/employee.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allEmployees = data.employees;
                populateHeadSelect(allEmployees);
            }
        })
        .catch(error => {
            console.error('Error loading employees:', error);
        });
}

function populateHeadSelect(employees) {
    const select = document.getElementById('deptHead');
    select.innerHTML = '<option value="">Not assigned</option>';
    
    employees.forEach(emp => {
        const option = document.createElement('option');
        option.value = emp.id;
        option.textContent = `${emp.first_name} ${emp.last_name} (${emp.employee_id})`;
        select.appendChild(option);
    });
}

function loadDepartmentHierarchyData(departmentId) {
    // Load only employees assigned to this department
    fetch(`api/department.php?id=${departmentId}&action=hierarchy`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                departmentEmployees = data.employees || [];
                hierarchyData = {};
                
                // Build hierarchy data from reporting relationships and is_in_hierarchy flag
                departmentEmployees.forEach(emp => {
                    if (emp.is_in_hierarchy) {
                        hierarchyData[emp.id] = emp.reporting_manager_id || null;
                    }
                });
                
                renderAvailableEmployeesList();
                renderHierarchyDiagram();
                updateHierarchyStats();
            } else {
                // Handle case where department has no employees yet
                departmentEmployees = [];
                hierarchyData = {};
                renderAvailableEmployeesList();
                renderHierarchyDiagram();
                updateHierarchyStats();
            }
        })
        .catch(error => {
            console.error('Error loading hierarchy data:', error);
            departmentEmployees = [];
            hierarchyData = {};
            renderAvailableEmployeesList();
            renderHierarchyDiagram();
            updateHierarchyStats();
        });
}

function renderAvailableEmployeesList() {
    const container = document.getElementById('availableEmployeesList');
    const addToHierarchyBtn = document.querySelector('button[onclick="openAddToHierarchyModal()"]');
    
    // Update counts
    const hierarchyEmployeeIds = new Set([
        ...Object.keys(hierarchyData).map(id => parseInt(id)),
        ...Object.values(hierarchyData).filter(id => id !== null)
    ]);
    const availableEmployees = departmentEmployees.filter(emp => !hierarchyEmployeeIds.has(emp.id));
    const inHierarchyCount = hierarchyEmployeeIds.size;
    
    document.getElementById('availableCount').textContent = availableEmployees.length;
    document.getElementById('hierarchyCount').textContent = inHierarchyCount;
    
    if (departmentEmployees.length === 0) {
        container.innerHTML = `
            <span style="color:var(--muted);font-size:11px;">No employees assigned to this department</span>
        `;
        if (addToHierarchyBtn) addToHierarchyBtn.disabled = true;
        return;
    }
    
    if (availableEmployees.length === 0) {
        container.innerHTML = `
            <span style="color:var(--muted);font-size:11px;">All employees are in the hierarchy</span>
        `;
        if (addToHierarchyBtn) addToHierarchyBtn.disabled = true;
        return;
    }
    
    if (addToHierarchyBtn) {
        addToHierarchyBtn.disabled = false;
    }
    
    // Render available employees as chips
    container.innerHTML = availableEmployees.map(emp => {
        const initials = (emp.first_name[0] + emp.last_name[0]).toUpperCase();
        return `
            <div class="available-employee-chip" onclick="quickAddToHierarchy(${emp.id})">
                <div class="chip-avatar">${initials}</div>
                <div class="chip-name">${emp.first_name} ${emp.last_name}</div>
            </div>
        `;
    }).join('');
}

function quickAddToHierarchy(employeeId) {
    // Set as top-level by default
    hierarchyData[employeeId] = null;
    
    renderAvailableEmployeesList();
    renderHierarchyDiagram();
    updateHierarchyStats();
}

function renderHierarchyDiagram() {
    const container = document.getElementById('hierarchyDiagram');
    
    // Get employees who are in hierarchy (either have a manager or are managers)
    const hierarchyEmployeeIds = new Set([
        ...Object.keys(hierarchyData).map(id => parseInt(id)),
        ...Object.values(hierarchyData).filter(id => id !== null)
    ]);
    
    const hierarchyEmployees = departmentEmployees.filter(emp => hierarchyEmployeeIds.has(emp.id));
    
    if (hierarchyEmployees.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align:center;padding:40px;color:var(--muted);">
                <i class="bi bi-diagram-3"></i>
                <p style="margin:10px 0 0 0;font-size:12px;">No hierarchy established</p>
                <p style="margin:5px 0 0 0;font-size:11px;">Click "Add to Hierarchy" to build the organization chart</p>
            </div>
        `;
        return;
    }
    
    // Build hierarchy tree
    const treeRoots = buildHierarchyTree(hierarchyEmployees);
    container.innerHTML = `
        <div class="org-chart-container">
            <div class="org-tree">
                <ul>
                    ${treeRoots.map(root => createOrgTreeNode(root, true)).join('')}
                </ul>
            </div>
        </div>
    `;
}

function buildHierarchyTree(employees) {
    // Create employee map
    const employeeMap = {};
    employees.forEach(emp => {
        employeeMap[emp.id] = { ...emp, children: [], expanded: true };
    });
    
    // Build tree structure
    const roots = [];
    employees.forEach(emp => {
        const managerId = hierarchyData[emp.id];
        if (managerId && employeeMap[managerId]) {
            employeeMap[managerId].children.push(employeeMap[emp.id]);
        } else {
            roots.push(employeeMap[emp.id]);
        }
    });
    
    return roots;
}

function createOrgTreeNode(node, isTop = false) {
    const initials = (node.first_name[0] + node.last_name[0]).toUpperCase();
    const hasChildren = node.children && node.children.length > 0;
    const childrenHtml = hasChildren
        ? `<ul>${node.children.map(child => createOrgTreeNode(child)).join('')}</ul>`
        : '';

    return `
        <li class="org-node${isTop ? ' top' : ''}" data-employee-id="${node.id}">
            <div class="org-card">
                <button type="button" class="card-close" onclick="removeFromHierarchy(${node.id})" title="Remove">
                    &times;
                </button>
                <div class="card-avatar">${initials}</div>
                <div class="card-name">${node.first_name} ${node.last_name}</div>
                <div class="card-position">${node.position || 'No position'}</div>
            </div>
            ${childrenHtml}
        </li>
    `;
}

function countTotalReports(node) {
    if (!node.children || node.children.length === 0) return 0;
    let count = node.children.length;
    node.children.forEach(child => {
        count += countTotalReports(child);
    });
    return count;
}

function openAddToHierarchyModal() {
    const modal = document.getElementById('addToHierarchyModal');
    const employeeSelect = document.getElementById('hierarchyEmployeeSelect');
    const managerSelect = document.getElementById('hierarchyManagerSelect');
    
    // Populate employee select with department employees not in hierarchy
    const hierarchyEmployeeIds = new Set([
        ...Object.keys(hierarchyData).map(id => parseInt(id)),
        ...Object.values(hierarchyData).filter(id => id !== null)
    ]);
    
    const availableEmployees = departmentEmployees.filter(emp => !hierarchyEmployeeIds.has(emp.id));
    
    employeeSelect.innerHTML = '<option value="">Choose an employee...</option>';
    availableEmployees.forEach(emp => {
        const option = document.createElement('option');
        option.value = emp.id;
        option.textContent = `${emp.first_name} ${emp.last_name} (${emp.position || 'No position'})`;
        employeeSelect.appendChild(option);
    });
    
    // Populate manager select with employees already in hierarchy or all department employees if no hierarchy exists
    const potentialManagers = hierarchyEmployeeIds.size > 0 
        ? departmentEmployees.filter(emp => hierarchyEmployeeIds.has(emp.id))
        : departmentEmployees;
    
    managerSelect.innerHTML = '<option value="">No manager (Top level)</option>';
    potentialManagers.forEach(emp => {
        const option = document.createElement('option');
        option.value = emp.id;
        option.textContent = `${emp.first_name} ${emp.last_name} (${emp.position || 'No position'})`;
        managerSelect.appendChild(option);
    });
    
    if (availableEmployees.length === 0) {
        alert('All employees are already in the hierarchy. Use "Edit" to modify existing relationships.');
        return;
    }
    
    modal.style.display = 'flex';
}

function closeAddToHierarchyModal() {
    document.getElementById('addToHierarchyModal').style.display = 'none';
    document.getElementById('hierarchyEmployeeSelect').value = '';
    document.getElementById('hierarchyManagerSelect').value = '';
}

function addEmployeeToHierarchy() {
    const employeeId = parseInt(document.getElementById('hierarchyEmployeeSelect').value);
    const managerId = document.getElementById('hierarchyManagerSelect').value ? parseInt(document.getElementById('hierarchyManagerSelect').value) : null;
    
    if (!employeeId) {
        alert('Please select an employee');
        return;
    }
    
    // Check for circular reporting
    if (managerId && wouldCreateCircularReporting(employeeId, managerId)) {
        alert('This would create a circular reporting relationship');
        return;
    }
    
    // Set the reporting relationship
    if (managerId) {
        hierarchyData[employeeId] = managerId;
    } else {
        // Adding as top level - just ensure they're tracked
        if (!hierarchyData.hasOwnProperty(employeeId)) {
            hierarchyData[employeeId] = null; // Mark as in hierarchy but no manager
        }
    }
    
    closeAddToHierarchyModal();
    renderAvailableEmployeesList();
    renderHierarchyDiagram();
    updateHierarchyStats();
}

function editHierarchyNode(employeeId) {
    const employee = departmentEmployees.find(emp => emp.id === employeeId);
    if (!employee) return;
    
    const currentManagerId = hierarchyData[employeeId];
    const currentManagerName = currentManagerId ? getEmployeeName(currentManagerId) : 'None (Top level)';
    
    ModalSystem.prompt({
        title: 'Change Manager',
        message: `Current manager: ${currentManagerName}<br>Enter new manager ID (or leave empty for top level):`,
        defaultValue: currentManagerId || '',
        placeholder: 'Manager ID or leave empty',
        required: false
    }).then(newManager => {
        if (newManager === null) return; // Cancelled
    
        const newManagerId = newManager.trim() ? parseInt(newManager) : null;
        
        // Validate manager exists in department
        if (newManagerId) {
            const managerExists = departmentEmployees.find(emp => emp.id === newManagerId);
            if (!managerExists) {
                ModalSystem.warning('Manager not found in this department');
                return;
            }
            
            // Check for circular reporting
            if (wouldCreateCircularReporting(employeeId, newManagerId)) {
                ModalSystem.warning('This would create a circular reporting relationship');
                return;
            }
        }
        
        // Update hierarchy
        if (newManagerId) {
            hierarchyData[employeeId] = newManagerId;
        } else {
            // Set as top-level (still in hierarchy, no manager)
            hierarchyData[employeeId] = null;
        }
        
        renderAvailableEmployeesList();
        renderHierarchyDiagram();
        updateHierarchyStats();
    });
}

function removeFromHierarchy(employeeId) {
    if (!ModalSystem.confirm({ 
        message: `Remove ${getEmployeeName(employeeId)} from hierarchy?`,
        confirmText: 'Remove' 
    })) return;
    
    // Remove from hierarchy data
    delete hierarchyData[employeeId];
    
    // Remove any employees who report to this person
    Object.keys(hierarchyData).forEach(empId => {
        if (hierarchyData[empId] === employeeId) {
            delete hierarchyData[empId];
        }
    });
    
    renderAvailableEmployeesList();
    renderHierarchyDiagram();
    updateHierarchyStats();
}

function wouldCreateCircularReporting(employeeId, managerId) {
    // Check if managerId already reports to employeeId (directly or indirectly)
    let currentManager = hierarchyData[managerId];
    while (currentManager) {
        if (currentManager === employeeId) {
            return true;
        }
        currentManager = hierarchyData[currentManager];
    }
    return false;
}

function getEmployeeName(employeeId) {
    const employee = departmentEmployees.find(emp => emp.id === employeeId);
    return employee ? `${employee.first_name} ${employee.last_name}` : 'Unknown';
}

function updateHierarchyStats() {
    const container = document.getElementById('hierarchyStats');
    
    const hierarchyEmployeeIds = new Set([
        ...Object.keys(hierarchyData).map(id => parseInt(id)),
        ...Object.values(hierarchyData).filter(id => id !== null)
    ]);
    
    const totalInHierarchy = hierarchyEmployeeIds.size;
    const reportingRelationships = Object.keys(hierarchyData).filter(id => hierarchyData[id] !== null).length;
    const topLevelCount = Object.keys(hierarchyData).filter(id => hierarchyData[id] === null).length;
    
    container.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
            <div>
                <strong>Department Employees:</strong> ${departmentEmployees.length}
            </div>
            <div>
                <strong>In Hierarchy:</strong> ${totalInHierarchy}
            </div>
            <div>
                <strong>Reporting Lines:</strong> ${reportingRelationships}
            </div>
            <div>
                <strong>Top Level:</strong> ${topLevelCount}
            </div>
        </div>
    `;
}

function saveDepartment(event) {
    event.preventDefault();
    
    const departmentId = document.getElementById('departmentId').value;
    
    // Prepare hierarchy data for saving (include ALL employees in hierarchy, including top-level)
    const hierarchyUpdates = [];
    Object.keys(hierarchyData).forEach(empId => {
        hierarchyUpdates.push({
            employee_id: parseInt(empId),
            reporting_manager_id: hierarchyData[empId] !== null ? hierarchyData[empId] : null
        });
    });
    
    // Include department employees for proper hierarchy management
    const departmentEmployeeIds = departmentEmployees.map(emp => emp.id);
    
    const formData = {
        id: departmentId || null,
        name: document.getElementById('deptName').value,
        code: document.getElementById('deptCode').value,
        description: document.getElementById('deptDescription').value,
        parent_department_id: document.getElementById('deptParent').value || null,
        head_of_department_id: document.getElementById('deptHead').value || null,
        is_active: document.getElementById('deptStatus').value,
        hierarchy: hierarchyUpdates,
        department_employees: departmentEmployeeIds
    };
    
    const method = formData.id ? 'PUT' : 'POST';
    const url = formData.id ? `api/department.php?id=${formData.id}` : 'api/department.php';
    
    console.log('Saving department with hierarchy:', formData);
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            closeModal();
            location.reload();
        } else {
            ModalSystem.error('Error saving department: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        ModalSystem.error('Error saving department: ' + error.message);
    });
}

function toggleDepartment(id, status) {
    const action = status ? 'activate' : 'deactivate';
    if (ModalSystem.confirm({ 
        message: 'Are you sure you want to ' + action + ' this department?',
        confirmText: action.charAt(0).toUpperCase() + action.slice(1) 
    })) {
        fetch(`api/department.php?id=${id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ is_active: status })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                ModalSystem.error('Error updating department: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ModalSystem.error('Error updating department');
        });
    }
}

function deleteDepartment(id) {
    if (ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this department?',
        destructive: true,
        confirmText: 'Delete' 
    })) {
        fetch(`api/department.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error deleting department: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting department');
        });
    }
}

let currentDepartmentEmployees = [];

function viewDepartmentEmployees(departmentId, departmentName) {
    document.getElementById('deptEmployeesTitle').textContent = `Employees - ${departmentName}`;
    document.getElementById('departmentEmployeesModal').style.display = 'flex';
    document.getElementById('deptEmployeeSearch').value = '';
    
    // Load employees for this department
    fetch(`api/department.php?id=${departmentId}&action=employees`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentDepartmentEmployees = data.employees || [];
                renderDepartmentEmployees(currentDepartmentEmployees);
            } else {
                document.getElementById('deptEmployeesList').innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-exclamation-triangle"></i>
                                <p>Error loading employees: ${data.message || 'Unknown error'}</p>
                            </div>
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading department employees:', error);
            document.getElementById('deptEmployeesList').innerHTML = `
                <tr>
                    <td colspan="5" style="text-align:center;padding:40px;">
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error loading employees</p>
                        </div>
                    </td>
                </tr>
            `;
        });
}

function closeDeptEmployeesModal() {
    document.getElementById('departmentEmployeesModal').style.display = 'none';
    currentDepartmentEmployees = [];
}

function renderDepartmentEmployees(employees) {
    const container = document.getElementById('deptEmployeesList');
    
    if (employees.length === 0) {
        container.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center;padding:40px;">
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <p>No employees found in this department</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    container.innerHTML = employees.map(emp => {
        const initials = (emp.first_name[0] + emp.last_name[0]).toUpperCase();
        const statusBadge = emp.is_active 
            ? '<span class="badge success">Active</span>' 
            : '<span class="badge gray">Inactive</span>';
        
        return `
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg, var(--accent), #4f46e5);color:white;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;">${initials}</div>
                        <div>
                            <div style="font-weight:500;color:var(--ink);">${emp.first_name} ${emp.last_name}</div>
                            ${emp.is_primary ? '<div style="font-size:10px;color:var(--muted);">Primary</div>' : ''}
                        </div>
                    </div>
                </td>
                <td style="font-family:monospace;font-size:13px;">${emp.employee_id}</td>
                <td>${emp.position || '-'}</td>
                <td>${emp.official_email}</td>
                <td>${statusBadge}</td>
            </tr>
        `;
    }).join('');
}

function filterDeptEmployees() {
    const searchTerm = document.getElementById('deptEmployeeSearch').value.toLowerCase();
    const filteredEmployees = currentDepartmentEmployees.filter(emp => {
        return emp.first_name.toLowerCase().includes(searchTerm) ||
               emp.last_name.toLowerCase().includes(searchTerm) ||
               emp.employee_id.toLowerCase().includes(searchTerm) ||
               (emp.position && emp.position.toLowerCase().includes(searchTerm)) ||
               emp.official_email.toLowerCase().includes(searchTerm);
    });
    renderDepartmentEmployees(filteredEmployees);
}
</script>
