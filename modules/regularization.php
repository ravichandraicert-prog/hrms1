<?php
/**
 * Regularization Module
 * Core PHP HRMS Application
 * Section 12 - Regularization module
 */

require_once __DIR__ . '/../includes/attendance.php';

$db = Database::getInstance();
$user = getCurrentUser();
$attendance = new Attendance();

// Check permissions
if (!hasPermission('regularization', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view regularization requests.</div>';
    exit;
}

// Get view type
$view = $_GET['view'] ?? 'list'; // list, calendar

// Get date range filter
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// Get regularization requests
$whereConditions = [];
$params = [];

// Filter by scope based on permissions
$scope = getDataScope('regularization');
if ($scope == 'self') {
    $whereConditions[] = "rr.employee_id = ?";
    $params[] = $user['id'];
} elseif ($scope == 'direct_reports') {
    $whereConditions[] = "rr.employee_id IN (SELECT id FROM employees WHERE reporting_manager_id = ?)";
    $params[] = $user['id'];
}

if (!empty($_GET['status'])) {
    $whereConditions[] = "rr.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['department'])) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM employee_departments ed WHERE ed.employee_id = rr.employee_id AND ed.department_id = ? AND ed.is_primary = 1)";
    $params[] = $_GET['department'];
}

$whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

$regularizationRequests = $db->fetchAll(
    "SELECT rr.*, e.first_name, e.last_name, e.employee_id
     FROM regularization_requests rr
     JOIN employees e ON rr.employee_id = e.id
     WHERE {$whereClause}
     ORDER BY rr.created_at DESC
     LIMIT 50",
    $params
);

// Get departments for filter
$departments = $db->fetchAll("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");

// Employees for the request modal (self by default)
$empOptions = $db->fetchAll(
    "SELECT e.id, e.first_name, e.last_name, e.employee_id
     FROM employees e WHERE e.is_active = 1 ORDER BY e.first_name, e.last_name"
);
?>

<div class="panel-head">
    <div class="toolbar">
        <?php if (hasPermission('regularization', 'create')): ?>
            <button class="btn primary" onclick="openRegularizationModal()">
                <i class="bi bi-plus-lg"></i> Request Regularization
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="tabs">
    <div class="tab <?php echo $view == 'list' ? 'active' : ''; ?>" onclick="switchView('list')">List View</div>
    <div class="tab <?php echo $view == 'calendar' ? 'active' : ''; ?>" onclick="switchView('calendar')">Calendar View</div>
</div>

<div class="toolbar">
    <div class="field" style="flex-direction:row;align-items:center;gap:8px;">
        <label style="margin:0;">From:</label>
        <input type="date" value="<?php echo $startDate; ?>" onchange="updateDateRange('start_date', this.value)">
        <label style="margin:0;">To:</label>
        <input type="date" value="<?php echo $endDate; ?>" onchange="updateDateRange('end_date', this.value)">
    </div>
    <select class="filter-chip" onchange="applyFilter('status', this.value)">
        <option value="">All Status</option>
        <option value="Pending" <?php echo ($_GET['status'] ?? '') == 'Pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="Approved" <?php echo ($_GET['status'] ?? '') == 'Approved' ? 'selected' : ''; ?>>Approved</option>
        <option value="Rejected" <?php echo ($_GET['status'] ?? '') == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
        <option value="Cancelled" <?php echo ($_GET['status'] ?? '') == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
    </select>
    <select class="filter-chip" onchange="applyFilter('department', this.value)">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?php echo $dept['id']; ?>" <?php echo ($_GET['department'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($dept['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($view == 'list'): ?>
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Requested Clock In</th>
                    <th>Requested Clock Out</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($regularizationRequests)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-pencil-square"></i>
                                <p>No regularization requests found</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($regularizationRequests as $request): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($request['employee_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo formatDate($request['attendance_date']); ?></td>
                            <td class="mono"><?php echo $request['requested_clock_in'] ? formatDateTime($request['requested_clock_in'], 'H:i') : '-'; ?></td>
                            <td class="mono"><?php echo $request['requested_clock_out'] ? formatDateTime($request['requested_clock_out'], 'H:i') : '-'; ?></td>
                            <td><?php echo htmlspecialchars(substr($request['reason'], 0, 50)) . (strlen($request['reason']) > 50 ? '...' : ''); ?></td>
                            <td>
                                <?php
                                $statusClass = 'pending';
                                if ($request['status'] == 'Approved') $statusClass = 'approved';
                                elseif ($request['status'] == 'Rejected') $statusClass = 'rejected';
                                elseif ($request['status'] == 'Cancelled') $statusClass = 'gray';
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($request['status']); ?></span>
                            </td>
                            <td><?php echo formatDate($request['created_at']); ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($request['status'] == 'Pending' && hasPermission('regularization', 'approve')): ?>
                                        <button class="icon-btn" onclick="approveRegularization(<?php echo $request['id']; ?>)" title="Approve" style="color:var(--success);">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button class="icon-btn" onclick="rejectRegularization(<?php echo $request['id']; ?>)" title="Reject" style="color:var(--danger);">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($request['status'] == 'Pending' && ($request['employee_id'] == $user['id'] || hasPermission('regularization', 'update'))): ?>
                                        <button class="icon-btn" onclick="cancelRegularization(<?php echo $request['id']; ?>)" title="Cancel">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="icon-btn" onclick="viewRegularizationDetails(<?php echo $request['id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <!-- Calendar View -->
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Calendar View</div>
        </div>
        <div style="padding:20px;text-align:center;">
            <p style="color:var(--muted);">Calendar view showing regularizable days</p>
            <p style="font-size:12px;color:var(--faint);margin-top:8px;">Click on a date to view/create regularization requests</p>
        </div>
    </div>
<?php endif; ?>
<!-- Regularization Request Modal -->
<div id="regularizationModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:580px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Request Regularization</h3>
            <button onclick="closeRegularizationModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Employee</label>
                <select id="regEmployee">
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></option>
                    <?php if (hasPermission('regularization', 'create')): ?>
                        <?php foreach ($empOptions as $emp): ?>
                            <?php if ($emp['id'] != $user['id']): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Attendance Date <span class="req">*</span></label>
                <input type="date" id="regDate">
            </div>
            <div class="field">
                <label>Requested Clock In</label>
                <input type="datetime-local" id="regIn">
            </div>
            <div class="field">
                <label>Requested Clock Out</label>
                <input type="datetime-local" id="regOut">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Reason <span class="req">*</span></label>
                <textarea id="regReason" rows="2" placeholder="Why are you requesting this change?"></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeRegularizationModal()">Cancel</button>
            <button class="btn primary" onclick="saveRegularization()">Submit Request</button>
        </div>
    </div>
</div>

<!-- Regularization Detail Modal -->
<div id="regDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:560px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Regularization Details</h3>
            <button onclick="closeRegDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="regDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button class="btn" onclick="closeRegDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
function switchView(view) {
    const url = new URL(window.location);
    url.searchParams.set('view', view);
    window.location = url.toString();
}

function updateDateRange(type, value) {
    const url = new URL(window.location);
    url.searchParams.set(type, value);
    window.location = url.toString();
}

function applyFilter(type, value) {
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    window.location = url.toString();
}

function openRegularizationModal() {
    document.getElementById('regDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('regIn').value = '';
    document.getElementById('regOut').value = '';
    document.getElementById('regReason').value = '';
    document.getElementById('regularizationModal').style.display = 'flex';
}

function closeRegularizationModal() {
    document.getElementById('regularizationModal').style.display = 'none';
}

function saveRegularization() {
    const date = document.getElementById('regDate').value;
    const reason = document.getElementById('regReason').value.trim();
    if (!date || !reason) {
        alert('Please select a date and provide a reason.');
        return;
    }
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'create_regularization',
            employee_id: document.getElementById('regEmployee').value,
            attendance_date: date,
            requested_clock_in: document.getElementById('regIn').value.replace('T', ' ') || null,
            requested_clock_out: document.getElementById('regOut').value.replace('T', ' ') || null,
            reason: reason
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeRegularizationModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not submit request'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error submitting request');
    });
}

function approveRegularization(id) {
    if (!ModalSystem.confirm({ 
        message: 'Approve this regularization request and update the attendance record?',
        confirmText: 'Approve' 
    })) return;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'approve_regularization', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else ModalSystem.error('Error: ' + (data.message || 'Could not approve'));
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error approving request');
    });
}

function rejectRegularization(id) {
    ModalSystem.prompt({
        title: 'Reject Regularization',
        message: 'Enter rejection reason:',
        placeholder: 'Reason for rejection',
        required: true
    }).then(reason => {
        if (reason === null) return;
        
        fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject_regularization', id: id, reason: reason.trim() })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else ModalSystem.error('Error: ' + (data.message || 'Could not reject'));
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error rejecting request');
        });
    });
}

function cancelRegularization(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to cancel this regularization request?',
        confirmText: 'Cancel' 
    })) return;
    fetch('api/attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel_regularization', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else ModalSystem.error('Error: ' + (data.message || 'Could not cancel'));
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error cancelling request');
    });
}

function viewRegularizationDetails(id) {
    fetch(`api/attendance.php?reg_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Could not load request'));
                return;
            }
            const req = data.request;
            const statusClass = req.status === 'Approved' ? 'success' : (req.status === 'Rejected' ? 'danger' : 'pending');
            document.getElementById('regDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Employee:</b> ${req.first_name || ''} ${req.last_name || ''} <span class="mono" style="color:var(--faint);">(${req.employee_id || ''})</span></span>
                        <span><b>Status:</b> <span class="badge ${statusClass}">${req.status}</span></span>
                    </div>
                    <div style="margin-top:8px;"><b>Attendance Date:</b> ${req.attendance_date}</div>
                    <div><b>Requested In:</b> ${req.requested_clock_in ? new Date(req.requested_clock_in).toLocaleString() : '—'}</div>
                    <div><b>Requested Out:</b> ${req.requested_clock_out ? new Date(req.requested_clock_out).toLocaleString() : '—'}</div>
                    <div style="margin-top:8px;"><b>Reason:</b> ${req.reason}</div>
                    ${req.rejection_reason ? '<div style="margin-top:8px;color:var(--danger);"><b>Rejection Reason:</b> ' + req.rejection_reason + '</div>' : ''}
                    ${req.approver_first ? '<div style="margin-top:8px;"><b>Decision By:</b> ' + req.approver_first + ' ' + req.approver_last + (req.approved_at ? ' on ' + new Date(req.approved_at).toLocaleString() : '') + '</div>' : ''}
                </div>
            `;
            document.getElementById('regDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading request details');
        });
}

function closeRegDetailModal() {
    document.getElementById('regDetailModal').style.display = 'none';
}
</script>
