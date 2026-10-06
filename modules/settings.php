<?php
/**
 * Settings Module
 * Core PHP HRMS Application
 * Section 17 - Settings module
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

$db = Database::getInstance();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('settings', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view settings.</div>';
    exit;
}

// Get settings section
$section = $_GET['section'] ?? 'general';
?>

<div class="panel-head">
</div>

<div class="settings-layout">
    <div class="settings-nav">
        <div class="settings-nav-item <?php echo $section == 'general' ? 'active' : ''; ?>" onclick="switchSection('general')">
            <i class="bi bi-building"></i> General
        </div>
        <div class="settings-nav-item <?php echo $section == 'roles' ? 'active' : ''; ?>" onclick="switchSection('roles')">
            <i class="bi bi-person-badge"></i> Roles & Hierarchy
        </div>
        <div class="settings-nav-item <?php echo $section == 'permissions' ? 'active' : ''; ?>" onclick="switchSection('permissions')">
            <i class="bi bi-shield-lock"></i> Permissions
        </div>
        <div class="settings-nav-item <?php echo $section == 'shifts' ? 'active' : ''; ?>" onclick="switchSection('shifts')">
            <i class="bi bi-clock"></i> Shifts
        </div>
        <div class="settings-nav-item <?php echo $section == 'work_patterns' ? 'active' : ''; ?>" onclick="switchSection('work_patterns')">
            <i class="bi bi-calendar-week"></i> Work Patterns
        </div>
        <div class="settings-nav-item <?php echo $section == 'breaks' ? 'active' : ''; ?>" onclick="switchSection('breaks')">
            <i class="bi bi-cup-hot"></i> Break Types
        </div>
        <div class="settings-nav-item <?php echo $section == 'holidays' ? 'active' : ''; ?>" onclick="switchSection('holidays')">
            <i class="bi bi-calendar-heart"></i> Holiday Calendars
        </div>
        <div class="settings-nav-item <?php echo $section == 'leaves' ? 'active' : ''; ?>" onclick="switchSection('leaves')">
            <i class="bi bi-calendar-minus"></i> Leave Types
        </div>
        <div class="settings-nav-item <?php echo $section == 'employee_id' ? 'active' : ''; ?>" onclick="switchSection('employee_id')">
            <i class="bi bi-card-text"></i> Employee ID Format
        </div>
        <div class="settings-nav-item <?php echo $section == 'attendance' ? 'active' : ''; ?>" onclick="switchSection('attendance')">
            <i class="bi bi-calendar-check"></i> Attendance
        </div>
        <div class="settings-nav-item <?php echo $section == 'leave_settings' ? 'active' : ''; ?>" onclick="switchSection('leave_settings')">
            <i class="bi bi-calendar-x"></i> Leave Settings
        </div>
        <div class="settings-nav-item <?php echo $section == 'role_permissions' ? 'active' : ''; ?>" onclick="switchSection('role_permissions')">
            <i class="bi bi-shield-check"></i> Role Permissions
        </div>
        <div class="settings-nav-item <?php echo $section == 'password' ? 'active' : ''; ?>" onclick="switchSection('password')">
            <i class="bi bi-key"></i> Change Password
        </div>
        <div class="settings-nav-item <?php echo $section == 'notifications' ? 'active' : ''; ?>" onclick="switchSection('notifications')">
            <i class="bi bi-bell"></i> Notifications
        </div>
    </div>

    <div class="settings-content">
        <?php if ($section == 'general'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-building"></i> Company Information</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Company Name</label>
                        <input type="text" id="companyName" value="<?php echo APP_NAME; ?>">
                    </div>
                    <div class="field">
                        <label>Company Email</label>
                        <input type="email" id="companyEmail" value="hr@orbit.co">
                    </div>
                    <div class="field">
                        <label>Company Phone</label>
                        <input type="text" id="companyPhone" value="+91 80 4567 8900">
                    </div>
                    <div class="field">
                        <label>Company Website</label>
                        <input type="text" id="companyWebsite" value="www.orbit.co">
                    </div>
                    <div class="field">
                        <label>Currency Symbol</label>
                        <select id="companyCurrency">
                            <option>₹ (INR)</option>
                            <option>$ (USD)</option>
                            <option>€ (EUR)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Pay Cycle Start Day</label>
                        <select id="payCycleStart">
                            <option>16</option>
                            <option>1</option>
                            <option>15</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label>Company Address</label>
                        <textarea id="companyAddress">Orbit Tower, 4th Floor, Tech Park, Whitefield, Bengaluru, Karnataka 560066</textarea>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:20px;">
                    <div class="btn primary" onclick="saveSettings()">Save Changes</div>
                </div>
            </div>

        <?php elseif ($section == 'roles'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-person-badge"></i> Roles & Hierarchy</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddRoleModal()">
                            <i class="bi bi-plus-lg"></i> Add Role
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Role Name</th>
                                <th>Hierarchy Level</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $roles = $db->fetchAll("SELECT * FROM roles ORDER BY hierarchy_level");
                            foreach ($roles as $role):
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($role['name']); ?></b></td>
                                    <td><?php echo $role['hierarchy_level']; ?></td>
                                    <td><?php echo htmlspecialchars($role['description'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $role['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $role['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editRole(<?php echo $role['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'permissions'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-shield-lock"></i> Permissions</div>
                </div>
                <div style="padding:20px;text-align:center;">
                    <p style="color:var(--muted);margin-bottom:16px;">Configure role-based permissions for each module and action.</p>
                    <button class="btn primary" onclick="openPermissionMatrix()">
                        <i class="bi bi-grid"></i> Open Permission Matrix
                    </button>
                </div>
            </div>

        <?php elseif ($section == 'shifts'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-clock"></i> Shift Management</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddShiftModal()">
                            <i class="bi bi-plus-lg"></i> Add Shift
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Shift Name</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Late Threshold</th>
                                <th>Continuous</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $shifts = $db->fetchAll("SELECT * FROM shifts ORDER BY start_time");
                            foreach ($shifts as $shift):
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($shift['name']); ?></b></td>
                                    <td class="mono"><?php echo $shift['start_time']; ?></td>
                                    <td class="mono"><?php echo $shift['end_time']; ?></td>
                                    <td><?php echo $shift['grace_in_minutes']; ?> min</td>
                                    <td>
                                        <span class="badge <?php echo $shift['spans_midnight'] ? 'warning' : 'success'; ?>">
                                            <?php echo $shift['spans_midnight'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $shift['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $shift['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editShift(<?php echo $shift['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'work_patterns'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-week"></i> Work Patterns</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddWorkPatternModal()">
                            <i class="bi bi-plus-lg"></i> Add Work Pattern
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Pattern Name</th>
                                <th>Description</th>
                                <th>Mon-Fri</th>
                                <th>Saturday</th>
                                <th>Sunday</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $workPatterns = $db->fetchAll("SELECT * FROM work_patterns ORDER BY name");
                            foreach ($workPatterns as $pattern):
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($pattern['name']); ?></b></td>
                                    <td><?php echo htmlspecialchars($pattern['description'] ?? '-'); ?></td>
                                    <td>
                                        <?php 
                                        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
                                        $allWorking = true;
                                        foreach ($weekdays as $day) {
                                            if (($pattern[$day] ?? 'working') !== 'working') {
                                                $allWorking = false;
                                                break;
                                            }
                                        }
                                        echo $allWorking ? 'Working' : 'Mixed';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $sat = $pattern['saturday'] ?? 'working';
                                        if ($sat === 'working') echo 'Working';
                                        elseif ($sat === 'weekly_off') echo 'Off';
                                        else {
                                            $rule = $pattern['saturday_rule'] ?? '';
                                            echo ucfirst(str_replace('_', ' ', $rule)) . ' Off';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo ($pattern['sunday'] ?? 'weekly_off') === 'working' ? 'Working' : 'Off'; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $pattern['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $pattern['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editWorkPattern(<?php echo $pattern['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (hasPermission('settings', 'delete')): ?>
                                                <button class="icon-btn" style="color:var(--danger);" onclick="deleteWorkPattern(<?php echo $pattern['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'breaks'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-cup-hot"></i> Break Types</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddBreakTypeModal()">
                            <i class="bi bi-plus-lg"></i> Add Break
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table" style="overflow-x: auto;">
                    <table style="min-width: 1000px;">
                        <thead>
                            <tr>
                                <th>Break Name</th>
                                <th>Default Duration</th>
                                <th>Min</th>
                                <th>Max</th>
                                <th>Paid</th>
                                <th>Deduct Net Time</th>
                                <th>Assign To</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $breakTypes = $db->fetchAll("SELECT * FROM break_types ORDER BY name");
                            // Lookup maps for resolving assignment targets
                            $deptMap = [];
                            foreach ($db->fetchAll("SELECT id, name FROM departments") as $r) { $deptMap[$r['id']] = $r['name']; }
                            $roleMap = [];
                            foreach ($db->fetchAll("SELECT id, name FROM roles") as $r) { $roleMap[$r['id']] = $r['name']; }
                            $userMap = [];
                            foreach ($db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees") as $r) { $userMap[$r['id']] = $r['first_name'] . ' ' . $r['last_name']; }
                            $shiftMap = [];
                            foreach ($db->fetchAll("SELECT id, name FROM shifts") as $r) { $shiftMap[$r['id']] = $r['name']; }
                            foreach ($breakTypes as $break):
                                $assignToText = 'All Employees';
                                $assignToMode = $break['assign_to'] ?? 'all_employees';
                                $targetId = (int) ($break['target_id'] ?? 0);
                                if ($assignToMode === 'department') {
                                    $assignToText = 'Department: ' . ($deptMap[$targetId] ?? '#' . $targetId);
                                } elseif ($assignToMode === 'role') {
                                    $assignToText = 'Role: ' . ($roleMap[$targetId] ?? '#' . $targetId);
                                } elseif ($assignToMode === 'user') {
                                    $assignToText = 'User: ' . ($userMap[$targetId] ?? '#' . $targetId);
                                } elseif ($assignToMode === 'shift') {
                                    // Multiple shifts are stored comma-separated in target_ids
                                    $shiftIds = [];
                                    $rawShiftIds = isset($break['target_ids']) ? trim((string) $break['target_ids']) : '';
                                    if ($rawShiftIds !== '') {
                                        foreach (explode(',', $rawShiftIds) as $sid) {
                                            $sid = trim($sid);
                                            if ($sid !== '' && is_numeric($sid) && (int) $sid > 0) {
                                                $shiftIds[] = (int) $sid;
                                            }
                                        }
                                    }
                                    if (empty($shiftIds) && $targetId > 0) {
                                        $shiftIds[] = $targetId;
                                    }
                                    $shiftNames = [];
                                    foreach ($shiftIds as $sid) {
                                        $shiftNames[] = $shiftMap[$sid] ?? ('#' . $sid);
                                    }
                                    $assignToText = 'Shift: ' . (count($shiftNames) ? implode(', ', $shiftNames) : 'None');
                                }
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($break['name']); ?></b></td>
                                    <td>
                                        <?php if (!empty($break['is_unlimited'])): ?>
                                            <span class="badge success" title="No time limit"><i class="bi bi-infinity"></i> Unlimited</span>
                                        <?php else: ?>
                                            <?php echo $break['default_duration_minutes']; ?> min
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo ($break['is_unlimited'] || $break['min_duration_minutes'] === null) ? '-' : ($break['min_duration_minutes'] . ' min'); ?></td>
                                    <td><?php echo ($break['is_unlimited'] || $break['max_duration_minutes'] === null) ? '-' : ($break['max_duration_minutes'] . ' min'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $break['is_paid'] ? 'success' : 'gray'; ?>">
                                            <?php echo $break['is_paid'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $break['deduct_from_work_time'] ? 'warning' : 'success'; ?>">
                                            <?php echo $break['deduct_from_work_time'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($assignToText); ?></td>
                                    <td>
                                        <span class="badge <?php echo $break['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $break['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editBreakType(<?php echo $break['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (hasPermission('settings', 'delete')): ?>
                                                <button class="icon-btn danger" onclick="deleteBreakType(<?php echo $break['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'holidays'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-heart"></i> Holiday Calendars</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddHolidayCalendarModal()">
                            <i class="bi bi-plus-lg"></i> Add Calendar
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Calendar Name</th>
                                <th>Description</th>
                                <th>Holidays</th>
                                <th>Assigned Employees</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $calendars = $db->fetchAll("SELECT hc.*, (SELECT COUNT(*) FROM holidays WHERE holiday_calendar_id = hc.id) as holiday_count FROM holiday_calendars hc ORDER BY year DESC");
                            foreach ($calendars as $calendar):
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($calendar['name']); ?></b></td>
                                    <td><?php echo htmlspecialchars($calendar['description'] ?? '-'); ?></td>
                                    <td><?php echo $calendar['holiday_count']; ?></td>
                                    <td><?php echo rand(50, 300); ?></td>
                                    <td>
                                        <span class="badge <?php echo $calendar['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $calendar['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editHolidayCalendar(<?php echo $calendar['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'leaves'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-minus"></i> Leave Types</div>
                    <?php if (hasPermission('settings', 'create')): ?>
                        <button class="btn primary sm" onclick="openAddLeaveTypeModal()">
                            <i class="bi bi-plus-lg"></i> Add Leave Type
                        </button>
                    <?php endif; ?>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Assign To</th>
                                <th>Calculation Basis</th>
                                <th>Max Days/Year</th>
                                <th>Carry Forward</th>
                                <th>Paid</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $leaveTypes = $db->fetchAll("SELECT * FROM leave_types ORDER BY name");
                            // Lookup maps for resolving assignment targets
                            $deptMap = [];
                            foreach ($db->fetchAll("SELECT id, name FROM departments") as $r) { $deptMap[$r['id']] = $r['name']; }
                            $roleMap = [];
                            foreach ($db->fetchAll("SELECT id, name FROM roles") as $r) { $roleMap[$r['id']] = $r['name']; }
                            $userMap = [];
                            foreach ($db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees") as $r) { $userMap[$r['id']] = $r['first_name'] . ' ' . $r['last_name']; }
                            foreach ($leaveTypes as $leaveType):
                                $assignToText = 'All Employees';
                                $assignToMode = '';
                                if (isset($leaveType['assign_to']) && $leaveType['assign_to'] !== '' && $leaveType['assign_to'] !== null) {
                                    $assignToMode = $leaveType['assign_to'];
                                } elseif (isset($leaveType['applicable_to'])) {
                                    if ($leaveType['applicable_to'] === 'specific_roles') $assignToMode = 'role';
                                    elseif ($leaveType['applicable_to'] === 'specific_departments') $assignToMode = 'department';
                                }
                                $targetId = (int) ($leaveType['target_id'] ?? 0);
                                if ($assignToMode === 'department') {
                                    $assignToText = 'Department: ' . ($deptMap[$targetId] ?? '#' . $targetId);
                                } elseif ($assignToMode === 'role') {
                                    $assignToText = 'Role: ' . ($roleMap[$targetId] ?? '#' . $targetId);
                                } elseif ($assignToMode === 'user') {
                                    $assignToText = 'User: ' . ($userMap[$targetId] ?? '#' . $targetId);
                                }
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($leaveType['name']); ?></b></td>
                                    <td><?php echo htmlspecialchars($assignToText); ?></td>
                                    <td><?php echo htmlspecialchars($leaveType['calculation_basis']); ?></td>
                                    <td><?php echo $leaveType['max_days_per_year']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $leaveType['carry_forward'] ? 'success' : 'gray'; ?>">
                                            <?php echo $leaveType['carry_forward'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $leaveType['is_paid'] ? 'success' : 'gray'; ?>">
                                            <?php echo $leaveType['is_paid'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $leaveType['is_active'] ? 'success' : 'gray'; ?>">
                                            <?php echo $leaveType['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (hasPermission('settings', 'update')): ?>
                                                <button class="icon-btn" onclick="editLeaveType(<?php echo $leaveType['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="icon-btn" style="color:var(--danger);" onclick="deleteLeaveType(<?php echo $leaveType['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($section == 'employee_id'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-card-text"></i> Employee ID Format Configuration</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>ID Prefix</label>
                        <input type="text" id="empIdPrefix" value="EMP" placeholder="e.g., EMP">
                    </div>
                    <div class="field">
                        <label>Starting Number</label>
                        <input type="number" id="empIdStart" value="1000" placeholder="e.g., 1000">
                    </div>
                    <div class="field">
                        <label>Zero Padding</label>
                        <input type="number" id="empIdPadding" value="4" placeholder="e.g., 4 for EMP-0001">
                    </div>
                    <div class="field">
                        <label>Allow Manual Override</label>
                        <select id="empIdManual">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <button class="btn primary" onclick="saveEmployeeIdFormat()">Save Configuration</button>
                </div>
            </div>

        <?php elseif ($section == 'attendance'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-check"></i> Default Attendance Status Thresholds</div>
                </div>
                <div class="settings-note" style="margin:12px 0 18px;padding:10px 14px;background:var(--bg);border-radius:var(--radius);color:var(--muted);font-size:13px;">
                    <i class="bi bi-info-circle"></i> These organization-wide defaults apply to employees whose assigned shift does <strong>not</strong> have its own configuration in "Attendance Thresholds by Shift" below (and to employees without an assigned shift). Configuring a shift-specific threshold overrides these defaults for that shift's employees only.
                </div>
                
                <?php
                // Check if attendance_settings table exists before trying to use it
                $currentThresholds = null;
                try {
                    require_once __DIR__ . '/../includes/attendance_threshold_service.php';
                    $thresholdService = new AttendanceThresholdService();
                    $currentThresholds = $thresholdService->getCurrentThresholds();
                } catch (Exception $e) {
                    // Table doesn't exist yet - show default values as per requirements
                    $currentThresholds = [
                        'present_threshold_minutes' => 495,  // 8:15 default
                        'half_day_threshold_minutes' => 255  // 4:15 default
                    ];
                }
                ?>
                
                <div class="form-grid">
                    <div class="field">
                        <label>Present Threshold</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="number" id="presentHours" min="0" max="23" value="<?php echo floor(($currentThresholds['present_threshold_minutes'] ?? 495) / 60); ?>" style="width:80px;">
                            <span>Hours</span>
                            <input type="number" id="presentMinutes" min="0" max="59" value="<?php echo ($currentThresholds['present_threshold_minutes'] ?? 495) % 60; ?>" style="width:80px;">
                            <span>Minutes</span>
                        </div>
                        <small style="color:var(--muted);font-size:12px;">Employee must complete this duration or more to be marked Present</small>
                    </div>
                    
                    <div class="field">
                        <label>Half Day Threshold</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="number" id="halfDayHours" min="0" max="23" value="<?php echo floor(($currentThresholds['half_day_threshold_minutes'] ?? 255) / 60); ?>" style="width:80px;">
                            <span>Hours</span>
                            <input type="number" id="halfDayMinutes" min="0" max="59" value="<?php echo ($currentThresholds['half_day_threshold_minutes'] ?? 255) % 60; ?>" style="width:80px;">
                            <span>Minutes</span>
                        </div>
                        <small style="color:var(--muted);font-size:12px;">Employee must complete more than this duration (but less than Present threshold) to be marked Half Day</small>
                    </div>
                </div>
                
                <div class="settings-note" style="margin:18px 0;padding:12px 14px;background:var(--accent-ink);border-radius:var(--radius);color:var(--accent-dark);font-size:13px;">
                    <strong>Status Rules (based on Net Time):</strong><br>
                    • Net Time ≥ Present threshold → <strong>Present</strong><br>
                    • Net Time ≥ Half Day threshold AND < Present threshold → <strong>Half Day</strong><br>
                    • Net Time < Half Day threshold → <strong>Absent</strong>
                </div>
                
                <div style="margin-top:20px;">
                    <button class="btn primary" onclick="saveCentralizedThresholds()">Save Threshold Settings</button>
                </div>
            </div>
            
            <!-- Attendance Thresholds by Shift -->
            <div class="panel" style="margin-top:20px;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-arrow-repeat"></i> Attendance Thresholds by Shift</div>
                </div>
                <div class="settings-note" style="margin:12px 0 18px;padding:10px 14px;background:var(--bg);border-radius:var(--radius);color:var(--muted);font-size:13px;">
                    <i class="bi bi-info-circle"></i> Each shift has its own independent Present / Half Day thresholds, configured exactly like the organization-wide defaults shown above. An employee's attendance status is always calculated using the thresholds of their <strong>assigned shift only</strong> - changing one shift's thresholds never affects employees of another shift.
                </div>
                <?php
                // Organization-wide defaults prefill every shift; per-shift values override them once saved.
                $shiftDefaultThresholds = null;
                try {
                    if (isset($thresholdService)) {
                        $shiftDefaultThresholds = $thresholdService->getCurrentThresholds();
                    }
                } catch (Exception $e) {
                    $shiftDefaultThresholds = null;
                }
                $shiftDefPresent = (int) ($shiftDefaultThresholds ? ($shiftDefaultThresholds['present_threshold_minutes'] ?? 495) : 495);
                $shiftDefHalf    = (int) ($shiftDefaultThresholds ? ($shiftDefaultThresholds['half_day_threshold_minutes'] ?? 255) : 255);
                $shiftThresholdShifts = $db->fetchAll("SELECT * FROM shifts ORDER BY start_time");
                ?>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Shift</th>
                                <th>Present Threshold</th>
                                <th>Half Day Threshold</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shiftThresholdShifts as $shiftRow):
                                $rowPresent = $shiftRow['present_threshold_minutes'] !== null ? (int) $shiftRow['present_threshold_minutes'] : $shiftDefPresent;
                                $rowHalf = ($shiftRow['present_threshold_minutes'] !== null && $shiftRow['half_day_threshold_minutes'] !== null && (int) $shiftRow['half_day_threshold_minutes'] > 0)
                                           ? (int) $shiftRow['half_day_threshold_minutes'] : $shiftDefHalf;
                            ?>
                                <tr data-shift-id="<?php echo $shiftRow['id']; ?>">
                                    <td>
                                        <b><?php echo htmlspecialchars($shiftRow['name']); ?></b>
                                        <span class="mono" style="display:block;color:var(--muted);font-size:12px;"><?php echo $shiftRow['start_time']; ?> - <?php echo $shiftRow['end_time']; ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
                                            <input type="number" class="thr-present-hours" min="0" max="23" value="<?php echo floor($rowPresent / 60); ?>" style="width:70px;"><span>h</span>
                                            <input type="number" class="thr-present-minutes" min="0" max="59" value="<?php echo $rowPresent % 60; ?>" style="width:70px;"><span>m</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
                                            <input type="number" class="thr-half-hours" min="0" max="23" value="<?php echo floor($rowHalf / 60); ?>" style="width:70px;"><span>h</span>
                                            <input type="number" class="thr-half-minutes" min="0" max="59" value="<?php echo $rowHalf % 60; ?>" style="width:70px;"><span>m</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (hasPermission('settings', 'update')): ?>
                                            <button class="btn primary sm" onclick="saveShiftThresholds(<?php echo $shiftRow['id']; ?>, this)">Save</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small style="color:var(--muted);font-size:12px;"><i class="bi bi-info-circle"></i> Each shift is prefilled with the organization-wide defaults. Saving applies these thresholds to that shift's employees only; other shifts are never affected.</small>
            </div>

            <!-- Threshold History Section -->
            <div class="panel" style="margin-top:20px;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-clock-history"></i> Threshold Change History</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Date Changed</th>
                                <th>Present Threshold</th>
                                <th>Half Day Threshold</th>
                                <th>Changed By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $history = [];
                            try {
                                if (isset($thresholdService)) {
                                    $history = $thresholdService->getThresholdHistory(10);
                                }
                            } catch (Exception $e) {
                                // Table doesn't exist yet
                            }
                            
                            if (empty($history)):
                            ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No threshold changes recorded</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y H:i', strtotime($record['changed_at'])); ?></td>
                                        <td class="mono"><?php echo sprintf('%02d:%02d', floor($record['present_threshold_minutes'] / 60), $record['present_threshold_minutes'] % 60); ?></td>
                                        <td class="mono"><?php echo sprintf('%02d:%02d', floor($record['half_day_threshold_minutes'] / 60), $record['half_day_threshold_minutes'] % 60); ?></td>
                                        <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($record['change_reason'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>


<!-- Idle Time Tracking Settings -->
            <div class="panel" style="margin-top:20px;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-hourglass-split"></i> Idle Time Tracking</div>
                </div>
                <div class="settings-note" style="margin:12px 0 18px;padding:10px 14px;background:var(--bg);border-radius:var(--radius);color:var(--muted);font-size:13px;">
                    <i class="bi bi-info-circle"></i> Detects OS-level keyboard/mouse inactivity and records idle periods while an employee is clocked in ("currently in"). Break time Never counts as idle. After the configured idle timeout the employee is automatically clocked out and logged out.
                </div>

                <?php
                // Load current idle settings (defaults match the migration)
                $idleEnabled = getSetting('idle_tracking_enabled', '1');
                $idleTimeout = getSetting('idle_timeout_minutes', '20');
                $idleMinRecord = getSetting('idle_min_record_minutes', '6');
                $idleAssignTo = (string) getSetting('idle_assign_to', 'all_employees');
                $idleTargetId = (string) getSetting('idle_target_id', '');
                $idleStartMinutes = (int) getSetting('idle_start_minutes', '5');
                $idleDepartments = (string) getSetting('idle_departments', '');
                $shutdownClockoutEnabled = getSetting('shutdown_clockout_enabled', '1');
                $shutdownClockoutStale = (int) getSetting('shutdown_clockout_stale_minutes', '60');
                ?>

                <div class="form-grid">
                    <div class="field">
                        <label>Enable Idle Time Tracking</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="checkbox" id="idleTrackingEnabled" <?php echo $idleEnabled == '1' ? 'checked' : ''; ?> style="width:auto;">
                            <span>Enabled</span>
                        </div>
                        <small style="color:var(--muted);font-size:12px;">When disabled, no idle time is recorded and no auto clock-out/logout occurs.</small>
                    </div>

                    <div class="field">
                        <label>Idle Timeout (minutes)</label>
                        <input type="number" id="idleTimeoutMinutes" min="1" max="1440" value="<?php echo (int) $idleTimeout; ?>">
                        <small style="color:var(--muted);font-size:12px;">After this many consecutive idle minutes (default: 20) the employee is automatically clocked out and logged out.</small>
                    </div>

                    <div class="field">
                        <label>Idle Time Starts After (minutes)</label>
                        <input type="number" id="idleStartMinutes" min="1" max="1440" value="<?php echo (int) $idleStartMinutes; ?>">
                        <small style="color:var(--muted);font-size:12px;">Idle time is treated as started after this many idle minutes (default: 5). Triggers an in-app + Windows notification; the 2-minute warning is shown 2 minutes before this point.</small>
                    </div>

                    <div class="field">
                        <label>Minimum Recordable Idle (minutes)</label>
                        <input type="number" id="idleMinRecordMinutes" min="1" max="60" value="<?php echo (int) $idleMinRecord; ?>">
                        <small style="color:var(--muted);font-size:12px;">Idle periods shorter than this are recorded as 0 minutes. E.g. with default 6: 4 or 5 min idle → recorded 0 min; 6/7 min idle → recorded 6/7 min.</small>
                    </div>

                    <div class="field" style="grid-column:1/-1;border-top:1px solid var(--border);padding-top:14px;margin-top:6px;">
                        <label>Auto Clock-Out on System Shutdown</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="checkbox" id="shutdownClockoutEnabled" <?php echo $shutdownClockoutEnabled == '1' ? 'checked' : ''; ?> style="width:auto;">
                            <span>Enabled</span>
                        </div>
                        <small style="color:var(--muted);font-size:12px;">When the desktop app is closed, the machine shuts down / restarts / logs off, or the app crashes, the employee is automatically clocked out (marked "Shutdown" in attendance) and logged out. This is independent of idle-time tracking.</small>
                    </div>

                    <div class="field">
                        <label>Shutdown Stale Threshold (minutes)</label>
                        <input type="number" id="shutdownClockoutStaleMinutes" min="1" max="2880" value="<?php echo (int) $shutdownClockoutStale; ?>">
                        <small style="color:var(--muted);font-size:12px;">Server safety net: if no heartbeat is received for this many minutes while clocked in (default: 60), the employee is clocked out at their last heartbeat time (numeric last-seen moment, not detection time).</small>
                    </div>

                    <div class="field" style="grid-column:1/-1;">
                        <label>Apply To</label>
                        <select id="idleAssignTo" onchange="updateIdleTargetField()">
                            <option value="all_employees" <?php echo $idleAssignTo === 'all_employees' ? 'selected' : ''; ?>>All Employees</option>
                            <option value="department" <?php echo $idleAssignTo === 'department' ? 'selected' : ''; ?>>Department</option>
                            <option value="role" <?php echo $idleAssignTo === 'role' ? 'selected' : ''; ?>>Role</option>
                            <option value="user" <?php echo $idleAssignTo === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="shift" <?php echo $idleAssignTo === 'shift' ? 'selected' : ''; ?>>Shift</option>
                        </select>
                        <small style="color:var(--muted);font-size:12px;">Idle time tracking is enabled only for this audience. Choose All Employees for the previous behavior.</small>
                    </div>

                    <div class="field" id="idleTargetFieldContainer" style="display:none;grid-column:1/-1;">
                        <div id="idleDepartmentLayer" style="display:none;">
                            <label id="idleDepartmentLabel">Select Departments</label>
                            <div id="idleDepartmentCheckboxes" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;max-height:240px;overflow-y:auto;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--bg);">
                                <!-- department checkboxes populated by updateIdleTargetField() -->
                            </div>
                            <small style="color:var(--muted);font-size:12px;">Tick the departments. Their employees will appear below.</small>
                        </div>

                        <label id="idleTargetLabel" style="margin-top:12px;">Select Users</label>
                        <div id="idleTargetCheckboxes" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;max-height:240px;overflow-y:auto;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--bg);">
                            <!-- employee checkboxes populated by updateIdleTargetField() -->
                        </div>
                        <small style="color:var(--muted);font-size:12px;">Tick all that apply. Unchecked items are excluded.</small>
                        <?php if ($idleAssignTo !== 'all_employees'): ?>
                            <input type="hidden" id="idleSavedTargetValue" value="<?php echo htmlspecialchars($idleTargetId); ?>">
                            <input type="hidden" id="idleSavedDepartmentsValue" value="<?php echo htmlspecialchars($idleDepartments); ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-top:20px;">
                    <button class="btn primary" onclick="saveIdleSettings()">Save Idle Settings</button>
                </div>
            </div>


        <?php elseif ($section == 'leave_settings'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-x"></i> Leave Settings</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Max Carry Forward Days</label>
                        <input type="number" id="leaveMaxCarry" value="10">
                    </div>
                    <div class="field">
                        <label>Carry Forward Validity (months)</label>
                        <input type="number" id="leaveCarryValidity" value="3">
                    </div>
                    <div class="field">
                        <label>Require Approval for All Leaves</label>
                        <select id="leaveReqApproval">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Auto-approve Short Leaves</label>
                        <select id="leaveAutoApprove">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <button class="btn primary" onclick="saveLeaveSettings()">Save Settings</button>
                </div>
            </div>

        <?php elseif ($section == 'role_permissions'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-shield-check"></i> Role Permissions</div>
                </div>
                <div style="padding:20px;text-align:center;">
                    <p style="color:var(--muted);margin-bottom:16px;">Configure role-based permissions for each module and action.</p>
                    <button class="btn primary" onclick="openRolePermissionMatrix()">
                        <i class="bi bi-grid"></i> Open Role Permission Matrix
                    </button>
                </div>
            </div>

        <?php elseif ($section == 'password'): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-key"></i> Change Password</div>
                </div>
                <div class="form-grid">
                    <div class="field full">
                        <label>Current Password</label>
                        <input type="password" id="currentPass" placeholder="Enter current password">
                    </div>
                    <div class="field full">
                        <label>New Password</label>
                        <input type="password" id="newPass" placeholder="Enter new password">
                    </div>
                    <div class="field full">
                        <label>Confirm New Password</label>
                        <input type="password" id="confirmPass" placeholder="Confirm new password">
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <button class="btn primary" onclick="changePassword()">Change Password</button>
                </div>
            </div>

        <?php elseif ($section == 'notifications'): ?>
            <?php
            // Get user's notification preferences
            try {
                $notifPrefs = $db->fetchOne(
                    "SELECT * FROM user_notification_preferences WHERE user_id = ?",
                    [$user['id']]
                );
            } catch (Exception $e) {
                $notifPrefs = null;
            }

            // Default preferences if none exist
            if (!$notifPrefs) {
                $notifPrefs = [
                    'enabled' => 1,
                    'types_enabled' => json_encode([
                        'leave' => 1,
                        'task' => 1,
                        'attendance' => 1,
                        'notice' => 1,
                        'payroll' => 1,
                        'approval' => 1,
                        'idle' => 1
                    ]),
                    'browser_notifications' => 0,
                    'sound_enabled' => 1
                ];
            }

            $typesEnabled = json_decode($notifPrefs['types_enabled'] ?? '{}', true);
            if (!is_array($typesEnabled)) {
                $typesEnabled = [];
            }
            ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-bell"></i> Notification Preferences</div>
                </div>
                <div class="form-grid">
                    <div class="field" style="grid-column:1/-1;">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" id="notifEnabled" <?php echo $notifPrefs['enabled'] ? 'checked' : ''; ?>>
                            Enable Notifications
                        </label>
                        <div style="font-size:12px;color:var(--faint);margin-top:4px;">
                            Turn off to disable all notifications globally
                        </div>
                    </div>

                    <div class="field" style="grid-column:1/-1;">
                        <label>Notification Types</label>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:8px;">
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="leave" <?php echo ($typesEnabled['leave'] ?? 1) ? 'checked' : ''; ?>>
                                Leave Requests
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="task" <?php echo ($typesEnabled['task'] ?? 1) ? 'checked' : ''; ?>>
                                Tasks
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="attendance" <?php echo ($typesEnabled['attendance'] ?? 1) ? 'checked' : ''; ?>>
                                Attendance
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="notice" <?php echo ($typesEnabled['notice'] ?? 1) ? 'checked' : ''; ?>>
                                Notices & Announcements
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="payroll" <?php echo ($typesEnabled['payroll'] ?? 1) ? 'checked' : ''; ?>>
                                Payroll
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="approval" <?php echo ($typesEnabled['approval'] ?? 1) ? 'checked' : ''; ?>>
                                Approvals
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="notif-type" data-type="idle" <?php echo ($typesEnabled['idle'] ?? 1) ? 'checked' : ''; ?>>
                                Idle Time
                            </label>
                        </div>
                    </div>

                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" id="browserNotif" <?php echo $notifPrefs['browser_notifications'] ? 'checked' : ''; ?>>
                            Browser Notifications
                        </label>
                        <div style="font-size:12px;color:var(--faint);margin-top:4px;">
                            Show desktop notifications when browser is minimized
                        </div>
                    </div>

                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" id="soundEnabled" <?php echo $notifPrefs['sound_enabled'] ? 'checked' : ''; ?>>
                            Notification Sound
                        </label>
                        <div style="font-size:12px;color:var(--faint);margin-top:4px;">
                            Play sound when notification arrives
                        </div>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:20px;">
                    <button class="btn primary" onclick="saveNotificationPreferences()">Save Preferences</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Role Modal -->
<div id="roleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:520px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="roleModalTitle">Add Role</h3>
            <button onclick="closeRoleModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="roleId">
        <div class="form-grid">
            <div class="field">
                <label>Role Name <span class="req">*</span></label>
                <input type="text" id="roleName" placeholder="e.g. Senior Executive">
            </div>
            <div class="field">
                <label>Hierarchy Level <span class="req">*</span></label>
                <input type="number" id="roleLevel" min="1" value="10">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="roleDescription" rows="2" placeholder="Optional role description"></textarea>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="roleActive" checked> Active</label>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeRoleModal()">Cancel</button>
            <button class="btn primary" onclick="saveRole()">Save Role</button>
        </div>
    </div>
</div>

<!-- Shift Modal -->
<div id="shiftModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="shiftModalTitle">Add Shift</h3>
            <button onclick="closeShiftModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="shiftId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Shift Name <span class="req">*</span></label>
                <input type="text" id="shiftName" placeholder="e.g. Day Shift">
            </div>
            <div class="field">
                <label>Start Time</label>
                <input type="time" id="shiftStart" value="09:00">
            </div>
            <div class="field">
                <label>End Time</label>
                <input type="time" id="shiftEnd" value="18:00">
            </div>
            <div class="field">
                <label>Grace In (minutes)</label>
                <input type="number" id="shiftGraceIn" value="120">
            </div>
            <div class="field">
                <label>Grace Out (minutes)</label>
                <input type="number" id="shiftGraceOut" value="180">
            </div>
            <div class="field">
                <label>Half-day Threshold (minutes)</label>
                <input type="number" id="shiftHalfDay" value="240">
            </div>
            <div class="field">
                <label>Full Day (minutes)</label>
                <input type="number" id="shiftFullDay" value="480">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="shiftDescription" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="shiftActive" checked> Active</label>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeShiftModal()">Cancel</button>
            <button class="btn primary" onclick="saveShift()">Save Shift</button>
        </div>
    </div>
</div>

<!-- Work Pattern Modal -->
<div id="workPatternModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="workPatternModalTitle">Add Work Pattern</h3>
            <button onclick="closeWorkPatternModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="workPatternId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Pattern Name <span class="req">*</span></label>
                <input type="text" id="workPatternName" placeholder="e.g. Mon - Sat (6 Days) - All Sundays off">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="workPatternDescription" rows="2" placeholder="Optional description"></textarea>
            </div>
            
            <div style="grid-column:1/-1;margin:16px 0 8px 0;padding-bottom:8px;border-bottom:1px solid var(--border);">
                <h4 style="margin:0;font-size:14px;font-weight:600;">Weekly Schedule</h4>
            </div>
            
            <div class="field">
                <label>Monday</label>
                <select id="workPatternMonday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            <div class="field">
                <label>Tuesday</label>
                <select id="workPatternTuesday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            <div class="field">
                <label>Wednesday</label>
                <select id="workPatternWednesday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            <div class="field">
                <label>Thursday</label>
                <select id="workPatternThursday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            <div class="field">
                <label>Friday</label>
                <select id="workPatternFriday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            <div class="field">
                <label>Saturday</label>
                <select id="workPatternSaturday" onchange="toggleSaturdayRule()">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                    <option value="conditional">Conditional</option>
                </select>
            </div>
            <div class="field" id="saturdayRuleField" style="display:none;">
                <label>Saturday Rule</label>
                <select id="workPatternSaturdayRule">
                    <option value="2nd_4th">2nd & 4th Saturday Off</option>
                    <option value="4th_only">4th Saturday Only Off</option>
                </select>
            </div>
            <div class="field">
                <label>Sunday</label>
                <select id="workPatternSunday">
                    <option value="working">Working</option>
                    <option value="weekly_off">Weekly Off</option>
                </select>
            </div>
            
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="workPatternActive" checked> Active</label>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeWorkPatternModal()">Cancel</button>
            <button class="btn primary" onclick="saveWorkPattern()">Save Work Pattern</button>
        </div>
    </div>
</div>

<!-- Break Type Modal -->
<div id="breakModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="breakModalTitle">Add Break</h3>
            <button onclick="closeBreakModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="breakId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Break Name <span class="req">*</span></label>
                <input type="text" id="breakName" placeholder="e.g. Lunch">
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="breakUnlimited" onchange="toggleBreakDurationFields()"> Unlimited Time (no fixed duration)</label>
            </div>
            <div id="breakDurationFields" style="display:contents;">
                <div class="field">
                    <label>Default Duration (minutes)</label>
                    <input type="number" id="breakDefaultDuration" value="30">
                </div>
                <div class="field">
                    <label>Minimum Time (minutes)</label>
                    <input type="number" id="breakMinDuration" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Maximum Time (minutes)</label>
                    <input type="number" id="breakMaxDuration" placeholder="Optional">
                </div>
            </div>
            <div class="field" id="breakUnlimitedNote" style="display:none;grid-column:1/-1;">
                <div style="font-size:12px;color:var(--muted);background:var(--bg, rgba(0,0,0,0.03));padding:8px 12px;border-radius:8px;">
                    <i class="bi bi-infinity"></i> This break has no time limit — employees can end it whenever they are ready.
                </div>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="breakPaid" checked> Paid</label>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="breakDeduct" checked> Deduct from work time</label>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="breakActive" checked> Active</label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="breakDescription" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Assign To</label>
                <select id="breakAssignTo" onchange="updateBreakTargetField()">
                    <option value="all_employees">All Employees</option>
                    <option value="department">Department</option>
                    <option value="role">Role</option>
                    <option value="user">User</option>
                    <option value="shift">Shift</option>
                </select>
            </div>
            <div class="field" id="breakTargetFieldContainer" style="display:none;grid-column:1/-1;">
                <label id="breakTargetLabel">Target</label>
                <select id="breakTarget">
                    <option value="">Select target...</option>
                </select>
                <div id="breakShiftCheckboxes" style="display:none;margin-top:6px;max-height:240px;overflow-y:auto;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--bg);">
                    <!-- shift checkboxes populated by updateBreakTargetField() -->
                </div>
                <small id="breakShiftHint" style="display:none;color:var(--muted);font-size:12px;">Tick all shifts that should have this break type.</small>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeBreakModal()">Cancel</button>
            <button class="btn primary" onclick="saveBreak()">Save Break</button>
        </div>
    </div>
</div>

<!-- Holiday Calendar Modal -->
<div id="holidayCalendarModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:760px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="calendarModalTitle">Add Holiday Calendar</h3>
            <button onclick="closeCalendarModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="calendarId">
        <div class="form-grid">
            <div class="field">
                <label>Calendar Name <span class="req">*</span></label>
                <input type="text" id="calendarName" placeholder="e.g. National Holiday Calendar">
            </div>
            <div class="field">
                <label>Year</label>
                <input type="number" id="calendarYear" value="<?php echo date('Y'); ?>">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Description</label>
                <textarea id="calendarDescription" rows="2" placeholder="Optional description"></textarea>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="calendarActive" checked> Active</label>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h4 style="font-family:'Inter';font-size:14px;font-weight:600;margin:0;">Holidays</h4>
            <button class="btn sm primary" onclick="addHolidayRow()"><i class="bi bi-plus-lg"></i> Add Holiday</button>
        </div>
        <div id="holidayRows" style="margin-bottom:16px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeCalendarModal()">Cancel</button>
            <button class="btn primary" onclick="saveHolidayCalendar()">Save Calendar</button>
        </div>
    </div>
</div>

<!-- Leave Type Modal (Settings) -->
<div id="leaveTypeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="leaveTypeModalTitle">Add Leave Type</h3>
            <button onclick="closeLeaveTypeModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="leaveTypeId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Name <span class="req">*</span></label>
                <input type="text" id="ltName" placeholder="e.g. Wedding Leave">
            </div>
            <div class="field">
                <label>Max Days / Year</label>
                <input type="number" id="ltMaxDays" min="0" value="0">
            </div>
            <div class="field">
                <label>Min Days / Request</label>
                <input type="number" id="ltMinDays" min="0" step="0.5" value="0.5">
            </div>
            <div class="field">
                <label>Calculation Basis</label>
                <select id="ltBasis">
                    <option value="working_days">Working days (excl. weekends)</option>
                    <option value="calendar">Calendar days</option>
                </select>
            </div>
            <div class="field">
                <label>Accrual Method</label>
                <select id="ltAccrual">
                    <option value="fixed_annual">Fixed Annual</option>
                    <option value="monthly_prorated">Monthly Prorated</option>
                </select>
            </div>
            <div class="field">
                <label>Assign To</label>
                <select id="ltAssignTo" onchange="updateTargetField()">
                    <option value="all_employees">All Employees</option>
                    <option value="department">Department</option>
                    <option value="role">Role</option>
                    <option value="user">User</option>
                </select>
            </div>
            <div class="field" id="targetFieldContainer" style="display:none;">
                <label id="targetLabel">Target</label>
                <select id="ltTarget">
                    <option value="">Select target...</option>
                </select>
            </div>
        </div>
        
        <!-- Monthly Accrual Settings -->
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
            <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 12px 0;">Monthly Accrual Settings</h4>
            <div style="display:flex;gap:16px;margin-bottom:12px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltMonthlyAccrualEnabled" onchange="toggleMonthlyAccrualFields()"> Enable monthly accrual</label>
            </div>
            <div id="monthlyAccrualFields" style="display:none;">
                <div class="form-grid">
                    <div class="field">
                        <label>Days to Accrue Monthly</label>
                        <input type="number" id="ltMonthlyAccrualDays" min="0" step="0.5" value="0" placeholder="e.g. 1.5">
                    </div>
                    <div class="field">
                        <label>Accrual Timing</label>
                        <select id="ltAccrualTiming" onchange="toggleAccrualDayField()">
                            <option value="pay_cycle_end">After pay cycle ends</option>
                            <option value="pay_cycle_start">After pay cycle starts</option>
                            <option value="specific_day">Specific day of month</option>
                        </select>
                    </div>
                    <div class="field" id="accrualDayField" style="display:none;">
                        <label>Day of Month</label>
                        <input type="number" id="ltAccrualDayOfMonth" min="1" max="31" value="1" placeholder="1-31">
                    </div>
                </div>
            </div>
        </div>
        
        <div style="display:flex;gap:16px;margin-top:12px;">
            <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltCarry" value="1"> Carry forward</label>
            <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltPaid" checked> Paid</label>
            <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltApproval" checked> Requires approval</label>
            <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ltActive" checked> Active</label>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button class="btn" onclick="closeLeaveTypeModal()">Cancel</button>
            <button class="btn primary" onclick="saveLeaveType()">Save Leave Type</button>
        </div>
    </div>
</div>

<!-- Role Permission Matrix Modal -->
<div id="permissionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:1100px;max-width:97vw;overflow-y:auto;max-height:92vh;padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:17px;font-weight:600;margin:0;">Role Permission Matrix</h3>
            <button onclick="closePermissionModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="permissionMatrixContainer" style="overflow:auto;max-height:60vh;margin-bottom:16px;">
            <p style="color:var(--muted);">Loading permission matrix...</p>
        </div>
        <div style="display:flex;justify-content:flex-end;">
            <button class="btn" onclick="closePermissionModal()">Close</button>
        </div>
    </div>
</div>

<script>
function switchSection(section) {
    const url = new URL(window.location);
    url.searchParams.set('section', section);
    window.location = url.toString();
}

function saveSettings() {
    const settings = {
        company_name: document.getElementById('companyName').value,
        company_email: document.getElementById('companyEmail').value,
        company_phone: document.getElementById('companyPhone').value,
        company_website: document.getElementById('companyWebsite').value,
        company_currency: document.getElementById('companyCurrency').value,
        pay_cycle_start_day: document.getElementById('payCycleStart').value,
        company_address: document.getElementById('companyAddress').value
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings: settings })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            ModalSystem.success('Settings saved successfully');
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not save settings'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving settings');
    });
}

function openRoleModal(role) {
    const isEdit = !!role && role.id;
    document.getElementById('roleModalTitle').textContent = isEdit ? 'Edit Role' : 'Add Role';
    document.getElementById('roleId').value = role ? role.id : '';
    document.getElementById('roleName').value = role ? (role.name || '') : '';
    document.getElementById('roleLevel').value = role ? (role.hierarchy_level || 10) : 10;
    document.getElementById('roleDescription').value = role ? (role.description || '') : '';
    document.getElementById('roleActive').checked = role ? !!role.is_active : true;
    document.getElementById('roleModal').style.display = 'flex';
}

function openAddRoleModal() {
    openRoleModal(null);
}

function editRole(id) {
    fetch(`api/settings.php?role_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openRoleModal(data.role);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not load role'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading role');
        });
}

function closeRoleModal() {
    document.getElementById('roleModal').style.display = 'none';
}

function saveRole() {
    const name = document.getElementById('roleName').value.trim();
    const nameField = document.getElementById('roleName');
    
    if (!name) {
        nameField.style.borderColor = 'var(--danger)';
        nameField.focus();
        return;
    } else {
        nameField.style.borderColor = 'var(--border)';
    }
    
    const hierarchyLevel = parseInt(document.getElementById('roleLevel').value);
    const levelField = document.getElementById('roleLevel');
    
    if (!hierarchyLevel || hierarchyLevel < 1) {
        levelField.style.borderColor = 'var(--danger)';
        levelField.focus();
        return;
    } else {
        levelField.style.borderColor = 'var(--border)';
    }
    const payload = {
        action: 'save_role',
        id: document.getElementById('roleId').value || null,
        name: name,
        hierarchy_level: hierarchyLevel,
        description: document.getElementById('roleDescription').value,
        is_active: document.getElementById('roleActive').checked ? 1 : 0
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeRoleModal();
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not save role'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving role');
    });
}

function openPermissionMatrix() {
    loadPermissionMatrix();
    document.getElementById('permissionModal').style.display = 'flex';
}

function closePermissionModal() {
    document.getElementById('permissionModal').style.display = 'none';
}

function loadPermissionMatrix() {
    const container = document.getElementById('permissionMatrixContainer');
    container.innerHTML = '<p style="color:var(--muted);">Loading permission matrix...</p>';
    fetch('api/settings.php?get_matrix=1')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.matrix) {
                container.innerHTML = '<p style="color:var(--danger);">Failed to load permissions.</p>';
                return;
            }
            const m = data.matrix;
            const roles = m.roles;
            const modules = m.modules;
            const actions = m.actions;
            const matrix = m.matrix;

            let html = '<div style="overflow:auto;"><table style="width:auto;border-collapse:collapse;font-size:12px;">';
            html += '<thead><tr><th style="padding:6px;text-align:left;border:1px solid var(--border);">Module</th>';
            roles.forEach(role => {
                html += `<th style="padding:6px;text-align:center;border:1px solid var(--border);">${role.name}</th>`;
            });
            html += '</tr></thead><tbody>';
            modules.forEach(mod => {
                html += `<tr><td style="padding:6px;border:1px solid var(--border);font-weight:600;">${mod.display_name}</td>`;
                roles.forEach(role => {
                    const cell = (matrix[role.id] && matrix[role.id][mod.id]) ? matrix[role.id][mod.id] : {};
                    let cellHtml = '<div style="display:flex;flex-direction:column;gap:2px;">';
                    actions.forEach(act => {
                        const allowed = cell[act] ? cell[act].allowed : false;
                        cellHtml += `<label style="display:flex;align-items:center;gap:4px;font-size:11px;cursor:pointer;">
                            <input type="checkbox" data-role="${role.id}" data-module="${mod.id}" data-action="${act}" ${allowed ? 'checked' : ''} onchange="savePermissionCell(this)">
                            ${act.charAt(0).toUpperCase() + act.slice(1)}
                        </label>`;
                    });
                    cellHtml += '</div>';
                    html += `<td style="padding:6px;border:1px solid var(--border);vertical-align:top;">${cellHtml}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += '<div style="font-size:11px;color:var(--faint);margin-top:10px;">Check to grant that role+module+action permission; uncheck to revoke. Changes apply immediately.</div>';
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = '<p style="color:var(--danger);">Error loading permissions.</p>';
            console.error(err);
        });
}

function savePermissionCell(el) {
    if (!el || !el.dataset) return;
    const roleId = el.dataset.role;
    const moduleId = el.dataset.module;
    const action = el.dataset.action;
    const payload = {
        action: 'save_permission',
        role_id: roleId,
        module_id: moduleId,
        perm_action: action,
        allowed: el.checked ? 1 : 0,
        scope: 'self'
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            ModalSystem.error('Error: ' + (data.message || 'Could not update permission'));
            el.checked = !el.checked;
        }
    })
    .catch(err => {
        el.checked = !el.checked;
        console.error(err);
        ModalSystem.error('Error updating permission');
    });
}

function openShiftModal(shift) {
    const isEdit = !!shift && shift.id;
    document.getElementById('shiftModalTitle').textContent = isEdit ? 'Edit Shift' : 'Add Shift';
    document.getElementById('shiftId').value = shift ? shift.id : '';
    document.getElementById('shiftName').value = shift ? (shift.name || '') : '';
    const start = shift ? String(shift.start_time).slice(0, 5) : '09:00';
    const end = shift ? String(shift.end_time).slice(0, 5) : '18:00';
    document.getElementById('shiftStart').value = start;
    document.getElementById('shiftEnd').value = end;
    document.getElementById('shiftGraceIn').value = shift ? (shift.grace_in_minutes || 120) : 120;
    document.getElementById('shiftGraceOut').value = shift ? (shift.grace_out_minutes || 180) : 180;
    document.getElementById('shiftHalfDay').value = shift ? (shift.half_day_threshold_minutes || 240) : 240;
    document.getElementById('shiftFullDay').value = shift ? (shift.full_day_minutes || 480) : 480;
    document.getElementById('shiftDescription').value = shift ? (shift.description || '') : '';
    document.getElementById('shiftActive').checked = shift ? !!shift.is_active : true;
    document.getElementById('shiftModal').style.display = 'flex';
}

function openAddShiftModal() {
    openShiftModal(null);
}

function editShift(id) {
    fetch(`api/settings.php?shift_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openShiftModal(data.shift);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not load shift'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading shift');
        });
}

function closeShiftModal() {
    document.getElementById('shiftModal').style.display = 'none';
}

function saveShift() {
    const name = document.getElementById('shiftName').value.trim();
    const nameField = document.getElementById('shiftName');
    
    if (!name) {
        nameField.style.borderColor = 'var(--danger)';
        nameField.focus();
        return;
    } else {
        nameField.style.borderColor = 'var(--border)';
    }
    const payload = {
        action: 'save_shift',
        id: document.getElementById('shiftId').value || null,
        name: name,
        start_time: document.getElementById('shiftStart').value + ':00',
        end_time: document.getElementById('shiftEnd').value + ':00',
        grace_in_minutes: document.getElementById('shiftGraceIn').value,
        grace_out_minutes: document.getElementById('shiftGraceOut').value,
        half_day_threshold_minutes: document.getElementById('shiftHalfDay').value,
        full_day_minutes: document.getElementById('shiftFullDay').value,
        description: document.getElementById('shiftDescription').value,
        is_active: document.getElementById('shiftActive').checked ? 1 : 0
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeShiftModal();
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not save shift'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving shift');
    });
}

function openWorkPatternModal(pattern) {
    const isEdit = !!pattern && pattern.id;
    document.getElementById('workPatternModalTitle').textContent = isEdit ? 'Edit Work Pattern' : 'Add Work Pattern';
    document.getElementById('workPatternId').value = pattern ? pattern.id : '';
    document.getElementById('workPatternName').value = pattern ? (pattern.name || '') : '';
    document.getElementById('workPatternDescription').value = pattern ? (pattern.description || '') : '';
    document.getElementById('workPatternActive').checked = pattern ? !!pattern.is_active : true;
    
    // Set day values
    const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    days.forEach(day => {
        const select = document.getElementById('workPattern' + day.charAt(0).toUpperCase() + day.slice(1));
        if (select && pattern) {
            select.value = pattern[day] || 'working';
        }
    });
    
    // Set Saturday rule
    if (pattern && pattern.saturday_rule) {
        document.getElementById('workPatternSaturdayRule').value = pattern.saturday_rule;
    }
    
    toggleSaturdayRule();
    document.getElementById('workPatternModal').style.display = 'flex';
}

function toggleSaturdayRule() {
    const saturday = document.getElementById('workPatternSaturday').value;
    const ruleField = document.getElementById('saturdayRuleField');
    ruleField.style.display = saturday === 'conditional' ? 'block' : 'none';
}

function openAddWorkPatternModal() {
    openWorkPatternModal(null);
}

function editWorkPattern(id) {
    fetch(`api/settings.php?work_pattern_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openWorkPatternModal(data.work_pattern);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not load work pattern'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading work pattern');
        });
}

function closeWorkPatternModal() {
    document.getElementById('workPatternModal').style.display = 'none';
}

function saveWorkPattern() {
    const name = document.getElementById('workPatternName').value.trim();
    const nameField = document.getElementById('workPatternName');
    
    if (!name) {
        nameField.style.borderColor = 'var(--danger)';
        nameField.focus();
        return;
    } else {
        nameField.style.borderColor = 'var(--border)';
    }
    const payload = {
        action: 'save_work_pattern',
        id: document.getElementById('workPatternId').value || null,
        name: name,
        description: document.getElementById('workPatternDescription').value,
        monday: document.getElementById('workPatternMonday').value,
        tuesday: document.getElementById('workPatternTuesday').value,
        wednesday: document.getElementById('workPatternWednesday').value,
        thursday: document.getElementById('workPatternThursday').value,
        friday: document.getElementById('workPatternFriday').value,
        saturday: document.getElementById('workPatternSaturday').value,
        sunday: document.getElementById('workPatternSunday').value,
        saturday_rule: document.getElementById('workPatternSaturday').value === 'conditional' 
            ? document.getElementById('workPatternSaturdayRule').value 
            : null,
        is_active: document.getElementById('workPatternActive').checked ? 1 : 0
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeWorkPatternModal();
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not save work pattern'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving work pattern');
    });
}

function deleteWorkPattern(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this work pattern?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_work_pattern', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not delete work pattern'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error deleting work pattern');
    });
}

function openBreakTypeModal(breakType) {
    const isEdit = !!breakType && breakType.id;
    document.getElementById('breakModalTitle').textContent = isEdit ? 'Edit Break' : 'Add Break';
    document.getElementById('breakId').value = breakType ? breakType.id : '';
    document.getElementById('breakName').value = breakType ? (breakType.name || '') : '';
    document.getElementById('breakDefaultDuration').value = breakType ? (breakType.default_duration_minutes || 30) : 30;
    document.getElementById('breakMinDuration').value = breakType ? (breakType.min_duration_minutes || '') : '';
    document.getElementById('breakMaxDuration').value = breakType ? (breakType.max_duration_minutes || '') : '';
    const isUnlimited = breakType ? !!(breakType.is_unlimited) : false;
    document.getElementById('breakUnlimited').checked = isUnlimited;
    toggleBreakDurationFields();
    document.getElementById('breakPaid').checked = breakType ? !!breakType.is_paid : true;
    document.getElementById('breakDeduct').checked = breakType ? !!breakType.deduct_from_work_time : true;
    document.getElementById('breakActive').checked = breakType ? !!breakType.is_active : true;
    document.getElementById('breakDescription').value = breakType ? (breakType.description || '') : '';
    document.getElementById('breakModal').style.display = 'flex';

    // Load assignment data and restore the saved assignment when editing
    loadAssignmentData().then(() => {
        const assignToField = document.getElementById('breakAssignTo');
        const targetField = document.getElementById('breakTarget');
        if (assignToField && targetField) {
            assignToField.value = breakType && breakType.assign_to ? breakType.assign_to : 'all_employees';
            // Pre-compute saved target ids (comma-separated target_ids, or legacy single target_id)
            const savedIds = [];
            const rawIds = breakType && breakType.target_ids
                ? String(breakType.target_ids)
                : (breakType && breakType.target_id ? String(breakType.target_id) : '');
            String(rawIds || '').split(',').forEach(t => {
                const n = parseInt(t, 10);
                if (!isNaN(n) && n > 0) savedIds.push(n);
            });
            breakSavedTargetIds = savedIds;
            // Populate target options first, then restore the saved selection
            updateBreakTargetField();
            if (breakType && breakType.assign_to && breakType.assign_to !== 'all_employees'
                && breakType.assign_to !== 'shift' && breakType.target_id) {
                targetField.value = breakType.target_id;
            }
        }
    });
}

function openAddBreakTypeModal() {
    openBreakTypeModal(null);
}

function editBreakType(id) {
    fetch(`api/settings.php?break_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openBreakTypeModal(data.break);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not load break'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading break');
        });
}

function closeBreakModal() {
    document.getElementById('breakModal').style.display = 'none';
}

function toggleBreakDurationFields() {
    const unlimited = document.getElementById('breakUnlimited').checked;
    const durationFields = document.getElementById('breakDurationFields');
    const unlimitedNote = document.getElementById('breakUnlimitedNote');
    if (durationFields) {
        durationFields.style.display = unlimited ? 'none' : 'contents';
    }
    if (unlimitedNote) {
        unlimitedNote.style.display = unlimited ? 'block' : 'none';
    }
    if (unlimited) {
        // Clear duration inputs so stale values are never saved for unlimited breaks
        document.getElementById('breakDefaultDuration').value = '';
        document.getElementById('breakMinDuration').value = '';
        document.getElementById('breakMaxDuration').value = '';
    } else if (!document.getElementById('breakDefaultDuration').value) {
        document.getElementById('breakDefaultDuration').value = 30;
    }
}


function saveBreak() {
    const name = document.getElementById('breakName').value.trim();
    if (!name) {
        alert('Break name is required');
        return;
    }
    const assignTo = document.getElementById('breakAssignTo').value || 'all_employees';

    // For "Shift" assignments the user selects multiple shifts via checkboxes.
    let targetIdsString = '';
    let singleTargetId = null;
    if (assignTo === 'shift') {
        const checked = [];
        document.querySelectorAll('#breakShiftCheckboxes input[type="checkbox"]:checked').forEach(cb => {
            if (cb.value) checked.push(cb.value);
        });
        targetIdsString = checked.join(',');
        singleTargetId = checked.length ? checked[0] : null;
    } else {
        singleTargetId = document.getElementById('breakTarget').value || null;
    }

    const payload = {
        action: 'save_break',
        id: document.getElementById('breakId').value || null,
        name: name,
        is_unlimited: document.getElementById('breakUnlimited').checked ? 1 : 0,
        default_duration_minutes: document.getElementById('breakDefaultDuration').value,
        min_duration_minutes: document.getElementById('breakMinDuration').value || null,
        max_duration_minutes: document.getElementById('breakMaxDuration').value || null,
        is_paid: document.getElementById('breakPaid').checked ? 1 : 0,
        deduct_from_work_time: document.getElementById('breakDeduct').checked ? 1 : 0,
        is_active: document.getElementById('breakActive').checked ? 1 : 0,
        description: document.getElementById('breakDescription').value,
        assign_to: assignTo,
        target_id: singleTargetId,
        target_ids: targetIdsString
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeBreakModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not save break'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving break');
    });
}

function deleteBreakType(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this break type? This will not affect existing attendance records.',
        destructive: true,
        confirmText: 'Delete' 
    })) {
        return;
    }
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_break', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not delete break type'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error deleting break type');
    });
}

function openHolidayCalendarModal(calendar) {
    const isEdit = !!calendar && calendar.id;
    document.getElementById('calendarModalTitle').textContent = isEdit ? 'Edit Holiday Calendar' : 'Add Holiday Calendar';
    document.getElementById('calendarId').value = calendar ? calendar.id : '';
    document.getElementById('calendarName').value = calendar ? (calendar.name || '') : '';
    document.getElementById('calendarYear').value = calendar ? (calendar.year || new Date().getFullYear()) : new Date().getFullYear();
    document.getElementById('calendarDescription').value = calendar ? (calendar.description || '') : '';
    document.getElementById('calendarActive').checked = calendar ? !!calendar.is_active : true;
    // Populate holiday rows
    const container = document.getElementById('holidayRows');
    container.innerHTML = '';
    const holidays = (calendar && calendar.holidays) ? calendar.holidays : [];
    if (holidays.length === 0) {
        addHolidayRow();
    } else {
        holidays.forEach(h => addHolidayRow(h));
    }
    document.getElementById('holidayCalendarModal').style.display = 'flex';
}

function openAddHolidayCalendarModal() {
    openHolidayCalendarModal(null);
}

function editHolidayCalendar(id) {
    fetch(`api/settings.php?cal_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openHolidayCalendarModal(data.calendar);
            } else {
                alert('Error: ' + (data.message || 'Could not load calendar'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading calendar');
        });
}

function closeCalendarModal() {
    document.getElementById('holidayCalendarModal').style.display = 'none';
}

function addHolidayRow(holiday) {
    const container = document.getElementById('holidayRows');
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px;';
    row.innerHTML = `
        <input type="date" value="${holiday && holiday.date ? holiday.date : ''}" style="flex:1;min-width:130px;">
        <input type="text" value="${holiday ? (holiday.name || '') : ''}" placeholder="Holiday name" style="flex:2;">
        <select style="width:110px;">
            <option value="Fixed" ${holiday && holiday.type === 'Fixed' ? 'selected' : ''}>Fixed</option>
            <option value="Optional" ${holiday && holiday.type === 'Optional' ? 'selected' : ''}>Optional</option>
        </select>
        <button class="btn sm danger" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
    `;
    container.appendChild(row);
}

function saveHolidayCalendar() {
    const name = document.getElementById('calendarName').value.trim();
    if (!name) {
        alert('Calendar name is required');
        return;
    }
    const rows = document.getElementById('holidayRows');
    const holidays = [];
    Array.from(rows.children).forEach(r => {
        const inputs = r.querySelectorAll('input');
        const sel = r.querySelector('select');
        const date = inputs[0].value;
        const hname = inputs[1].value.trim();
        if (date && hname) {
            holidays.push({ date, name: hname, type: sel.value });
        }
    });
    const payload = {
        action: 'save_holiday_calendar',
        id: document.getElementById('calendarId').value || null,
        name: name,
        year: document.getElementById('calendarYear').value,
        description: document.getElementById('calendarDescription').value,
        is_active: document.getElementById('calendarActive').checked ? 1 : 0,
        holidays: holidays
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeCalendarModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not save calendar'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving calendar');
    });
}

// Assignment data for Assign To target selection
let assignmentData = {
    departments: [],
    roles: [],
    users: [],
    shifts: [],
    user_departments: {}
};

// Saved shift ids for the Break type "Assign To > Shift" checkbox list
let breakSavedTargetIds = [];

function loadAssignmentData() {
    return fetch('api/leaves.php?get_assignment_data=1')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                assignmentData.departments = data.departments || [];
                assignmentData.roles = data.roles || [];
                assignmentData.users = data.users || [];
                assignmentData.shifts = data.shifts || [];
                assignmentData.user_departments = data.user_departments || {};
            }
        })
        .catch(err => {
            console.error('Error loading assignment data:', err);
        });
}

function updateTargetField() {
    const assignToField = document.getElementById('ltAssignTo');
    const targetContainer = document.getElementById('targetFieldContainer');
    const targetLabel = document.getElementById('targetLabel');
    const targetSelect = document.getElementById('ltTarget');

    if (!assignToField || !targetContainer || !targetLabel || !targetSelect) {
        return;
    }

    const assignTo = assignToField.value;

    targetSelect.innerHTML = '<option value="">Select target...</option>';

    if (assignTo === 'all_employees') {
        targetContainer.style.display = 'none';
        targetSelect.value = '';
    } else {
        targetContainer.style.display = 'block';

        if (assignTo === 'department') {
            targetLabel.textContent = 'Select Department';
            assignmentData.departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'role') {
            targetLabel.textContent = 'Select Role';
            assignmentData.roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'user') {
            targetLabel.textContent = 'Select User';
            assignmentData.users.forEach(user => {
                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = `${user.first_name} ${user.last_name} (${user.employee_id})`;
                targetSelect.appendChild(option);
            });
        }
    }
}

function updateBreakTargetField() {
    const assignToField = document.getElementById('breakAssignTo');
    const targetContainer = document.getElementById('breakTargetFieldContainer');
    const targetLabel = document.getElementById('breakTargetLabel');
    const targetSelect = document.getElementById('breakTarget');
    const shiftBox = document.getElementById('breakShiftCheckboxes');
    const shiftHint = document.getElementById('breakShiftHint');

    if (!assignToField || !targetContainer || !targetLabel || !targetSelect) {
        return;
    }

    const assignTo = assignToField.value;

    targetSelect.innerHTML = '<option value="">Select target...</option>';
    if (shiftBox) shiftBox.innerHTML = '';
    if (shiftHint) shiftHint.style.display = 'none';

    if (assignTo === 'all_employees') {
        targetContainer.style.display = 'none';
        targetSelect.value = '';
        if (shiftBox) shiftBox.style.display = 'none';
    } else if (assignTo === 'shift') {
        // Multi-select shifts via checkbox list
        targetContainer.style.display = 'block';
        targetLabel.textContent = 'Select Shifts';
        targetSelect.style.display = 'none';
        if (shiftBox) {
            shiftBox.style.display = 'block';
            (assignmentData.shifts || []).forEach(shift => {
                const label = document.createElement('label');
                label.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = shift.id;
                cb.checked = breakSavedTargetIds.indexOf(Number(shift.id)) !== -1;
                label.appendChild(cb);
                const span = document.createElement('span');
                span.textContent = shift.name;
                label.appendChild(span);
                shiftBox.appendChild(label);
            });
        }
        if (shiftHint) shiftHint.style.display = 'block';
    } else {
        targetContainer.style.display = 'block';
        targetSelect.style.display = 'block';
        if (shiftBox) shiftBox.style.display = 'none';

        if (assignTo === 'department') {
            targetLabel.textContent = 'Select Department';
            assignmentData.departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'role') {
            targetLabel.textContent = 'Select Role';
            assignmentData.roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.name;
                targetSelect.appendChild(option);
            });
        } else if (assignTo === 'user') {
            targetLabel.textContent = 'Select User';
            assignmentData.users.forEach(user => {
                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = `${user.first_name} ${user.last_name} (${user.employee_id})`;
                targetSelect.appendChild(option);
            });
        }
    }
}

function openLeaveTypeModalFn(type) {
    const isEdit = !!type && type.id;
    document.getElementById('leaveTypeModalTitle').textContent = isEdit ? 'Edit Leave Type' : 'Add Leave Type';
    document.getElementById('leaveTypeId').value = type ? type.id : '';
    document.getElementById('ltName').value = type ? (type.name || '') : '';
    document.getElementById('ltMaxDays').value = type ? (type.max_days_per_year || 0) : 0;
    document.getElementById('ltMinDays').value = type ? (type.min_days_per_request || 0.5) : 0.5;
    document.getElementById('ltBasis').value = type ? (type.calculation_basis || 'working_days') : 'working_days';
    document.getElementById('ltAccrual').value = type ? (type.accrual_method || 'fixed_annual') : 'fixed_annual';
    document.getElementById('ltCarry').checked = type ? !!type.carry_forward : false;
    document.getElementById('ltPaid').checked = type ? !!type.is_paid : true;
    document.getElementById('ltApproval').checked = type ? !!type.requires_approval : true;
    document.getElementById('ltActive').checked = type ? !!type.is_active : true;
    
    // Monthly accrual fields
    document.getElementById('ltMonthlyAccrualEnabled').checked = type ? !!type.monthly_accrual_enabled : false;
    document.getElementById('ltMonthlyAccrualDays').value = type ? (type.monthly_accrual_days || 0) : 0;
    document.getElementById('ltAccrualTiming').value = type ? (type.accrual_timing || 'pay_cycle_end') : 'pay_cycle_end';
    document.getElementById('ltAccrualDayOfMonth').value = type ? (type.accrual_day_of_month || 1) : 1;
    
    // Toggle monthly accrual fields visibility
    toggleMonthlyAccrualFields();
    toggleAccrualDayField();
    
    document.getElementById('leaveTypeModal').style.display = 'flex';

    // Load assignment data and restore the saved assignment when editing
    loadAssignmentData().then(() => {
        const assignToField = document.getElementById('ltAssignTo');
        const targetField = document.getElementById('ltTarget');
        if (assignToField && targetField) {
            assignToField.value = type && type.assign_to ? type.assign_to : 'all_employees';
            // Populate target options first, then restore the saved selection
            updateTargetField();
            if (type && type.assign_to && type.assign_to !== 'all_employees' && type.target_id) {
                targetField.value = type.target_id;
            }
        }
    });
}

function openAddLeaveTypeModal() {
    openLeaveTypeModalFn(null);
}

function editLeaveType(id) {
    fetch(`api/settings.php?type_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openLeaveTypeModalFn(data.type);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not load leave type'));
            }
        })
        .catch(err => {
            console.error(err);
            ModalSystem.error('Error loading leave type');
        });
}

function deleteLeaveType(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this leave type? This will completely remove it from the system. Historical leave records will preserve the leave type name for reporting purposes.',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    
    fetch('api/leaves.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_type', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not delete leave type'));
        }
    })
    .catch(err => {
        console.error('Delete error:', err);
        alert('Error deleting leave type: ' + err.message);
    });
}

function closeLeaveTypeModal() {
    document.getElementById('leaveTypeModal').style.display = 'none';
}

function toggleMonthlyAccrualFields() {
    const enabled = document.getElementById('ltMonthlyAccrualEnabled').checked;
    const fields = document.getElementById('monthlyAccrualFields');
    fields.style.display = enabled ? 'block' : 'none';
    
    // Reset values when disabled
    if (!enabled) {
        document.getElementById('ltMonthlyAccrualDays').value = 0;
        document.getElementById('ltAccrualTiming').value = 'pay_cycle_end';
        document.getElementById('ltAccrualDayOfMonth').value = 1;
    }
}

function toggleAccrualDayField() {
    const timing = document.getElementById('ltAccrualTiming').value;
    const dayField = document.getElementById('accrualDayField');
    dayField.style.display = timing === 'specific_day' ? 'block' : 'none';
}

function saveLeaveType() {
    const name = document.getElementById('ltName').value.trim();
    if (!name) {
        alert('Leave type name is required');
        return;
    }
    const payload = {
        action: 'save_type',
        id: document.getElementById('leaveTypeId').value || null,
        name: name,
        max_days_per_year: document.getElementById('ltMaxDays').value,
        min_days_per_request: document.getElementById('ltMinDays').value,
        calculation_basis: document.getElementById('ltBasis').value,
        accrual_method: document.getElementById('ltAccrual').value,
        carry_forward: document.getElementById('ltCarry').checked ? 1 : 0,
        is_paid: document.getElementById('ltPaid').checked ? 1 : 0,
        requires_approval: document.getElementById('ltApproval').checked ? 1 : 0,
        is_active: document.getElementById('ltActive').checked ? 1 : 0,
        assign_to: document.getElementById('ltAssignTo').value || 'all_employees',
        target_id: document.getElementById('ltTarget').value || null,
        // Monthly accrual fields
        monthly_accrual_enabled: document.getElementById('ltMonthlyAccrualEnabled').checked ? 1 : 0,
        monthly_accrual_days: document.getElementById('ltMonthlyAccrualDays').value,
        accrual_timing: document.getElementById('ltAccrualTiming').value,
        accrual_day_of_month: document.getElementById('ltAccrualDayOfMonth').value || null
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeLeaveTypeModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not save leave type'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving leave type');
    });
}

function saveEmployeeIdFormat() {
    const settings = {
        emp_id_prefix: document.getElementById('empIdPrefix').value,
        emp_id_start: document.getElementById('empIdStart').value,
        emp_id_padding: document.getElementById('empIdPadding').value,
        emp_id_manual_override: document.getElementById('empIdManual').value
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings: settings })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? 'Employee ID format saved' : ('Error: ' + (data.message || 'Save failed')));
    })
    .catch(err => {
        console.error(err);
        alert('Error saving employee ID format');
    });
}

function saveCentralizedThresholds() {
    const presentHours = parseInt(document.getElementById('presentHours').value) || 0;
    const presentMinutes = parseInt(document.getElementById('presentMinutes').value) || 0;
    const halfDayHours = parseInt(document.getElementById('halfDayHours').value) || 0;
    const halfDayMinutes = parseInt(document.getElementById('halfDayMinutes').value) || 0;
    
    const presentThresholdMinutes = (presentHours * 60) + presentMinutes;
    const halfDayThresholdMinutes = (halfDayHours * 60) + halfDayMinutes;
    
    if (presentThresholdMinutes <= 0 || halfDayThresholdMinutes <= 0) {
        alert('Thresholds must be positive values');
        return;
    }
    
    if (presentThresholdMinutes <= halfDayThresholdMinutes) {
        alert('Present threshold must be greater than Half Day threshold');
        return;
    }
    
    const reason = prompt('Optional: Enter reason for this threshold change:', '');
    
    const payload = {
        action: 'save_centralized_thresholds',
        present_threshold_minutes: presentThresholdMinutes,
        half_day_threshold_minutes: halfDayThresholdMinutes,
        reason: reason
    };
    
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Attendance threshold settings saved successfully');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Save failed'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving threshold settings');
    });
}

function saveShiftThresholds(shiftId, btn) {
    const row = document.querySelector(`tr[data-shift-id="${shiftId}"]`);

    // Same controls and rules as the organization-wide "Default Attendance Status Thresholds".
    const presentHours = parseInt(row.querySelector('.thr-present-hours').value) || 0;
    const presentMinutes = parseInt(row.querySelector('.thr-present-minutes').value) || 0;
    const halfDayHours = parseInt(row.querySelector('.thr-half-hours').value) || 0;
    const halfDayMinutes = parseInt(row.querySelector('.thr-half-minutes').value) || 0;

    const presentThresholdMinutes = (presentHours * 60) + presentMinutes;
    const halfDayThresholdMinutes = (halfDayHours * 60) + halfDayMinutes;

    if (presentThresholdMinutes <= 0 || halfDayThresholdMinutes <= 0) {
        alert('Thresholds must be positive values');
        return;
    }

    if (presentThresholdMinutes <= halfDayThresholdMinutes) {
        alert('Present threshold must be greater than Half Day threshold');
        return;
    }

    if (presentThresholdMinutes > 1440 || halfDayThresholdMinutes > 1440) {
        alert('Thresholds cannot exceed 24 hours (1440 minutes)');
        return;
    }

    const payload = {
        action: 'save_shift_thresholds',
        shift_id: shiftId,
        present_threshold_minutes: presentThresholdMinutes,
        half_day_threshold_minutes: halfDayThresholdMinutes
    };

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Thresholds saved for shift');
        } else {
            alert('Error: ' + (data.message || 'Save failed'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving thresholds');
    });
}


function saveLeaveSettings() {
    const settings = {
        leave_max_carry_forward: document.getElementById('leaveMaxCarry').value,
        leave_carry_validity: document.getElementById('leaveCarryValidity').value,
        leave_require_approval: document.getElementById('leaveReqApproval').value,
        leave_auto_approve: document.getElementById('leaveAutoApprove').value
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings: settings })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? 'Leave settings saved' : ('Error: ' + (data.message || 'Save failed')));
    })
    .catch(err => {
        console.error(err);
        alert('Error saving leave settings');
    });
}
function saveIdleSettings() {
    const assignTo = document.getElementById('idleAssignTo').value || 'all_employees';

    // Collect checked department checkboxes
    const checkedDepts = [];
    document.querySelectorAll('#idleDepartmentCheckboxes input[type="checkbox"]:checked').forEach(function(cb) {
        if (cb.value) checkedDepts.push(cb.value);
    });

    // Collect checked employee checkboxes
    const checked = [];
    document.querySelectorAll('#idleTargetCheckboxes input[type="checkbox"]:checked').forEach(function(cb) {
        if (cb.value) checked.push(cb.value);
    });

    const settings = {
        idle_tracking_enabled: document.getElementById('idleTrackingEnabled').checked ? '1' : '0',
        idle_timeout_minutes: document.getElementById('idleTimeoutMinutes').value,
        idle_start_minutes: document.getElementById('idleStartMinutes').value,
        idle_min_record_minutes: document.getElementById('idleMinRecordMinutes').value,
        idle_assign_to: assignTo,
        idle_target_id: assignTo === 'all_employees' ? '' : checked.join(','),
        idle_departments: assignTo === 'department' ? checkedDepts.join(',') : '',
        shutdown_clockout_enabled: document.getElementById('shutdownClockoutEnabled').checked ? '1' : '0',
        shutdown_clockout_stale_minutes: document.getElementById('shutdownClockoutStaleMinutes').value
    };
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings: settings })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? 'Idle Time Tracking settings saved' : ('Error: ' + (data.message || 'Save failed')));
        if (data.success) location.reload();
    })
    .catch(err => {
        console.error(err);
        alert('Error saving idle settings');
    });
}

function updateIdleTargetField() {
    const assignToField = document.getElementById('idleAssignTo');
    const targetContainer = document.getElementById('idleTargetFieldContainer');
    const targetLabel = document.getElementById('idleTargetLabel');
    const targetBox = document.getElementById('idleTargetCheckboxes');
    const deptLayer = document.getElementById('idleDepartmentLayer');
    const deptLabel = document.getElementById('idleDepartmentLabel');
    const deptBox = document.getElementById('idleDepartmentCheckboxes');

    if (!assignToField || !targetContainer || !targetLabel || !targetBox) {
        return;
    }

    const assignTo = assignToField.value;

    targetBox.innerHTML = '';
    if (deptBox) deptBox.innerHTML = '';
    if (deptLayer) deptLayer.style.display = 'none';

    if (assignTo === 'all_employees') {
        targetContainer.style.display = 'none';
        return;
    }

    targetContainer.style.display = 'block';

    // Saved employee selection (comma-separated ids)
    const saved = (document.getElementById('idleSavedTargetValue') || {}).value || '';
    const savedIds = saved.split(',').map(s => parseInt(s, 10)).filter(n => !isNaN(n));

    // Saved department selection
    const savedDepts = (document.getElementById('idleSavedDepartmentsValue') || {}).value || '';
    const savedDeptIds = savedDepts.split(',').map(s => parseInt(s, 10)).filter(n => !isNaN(n));

    // ===== DEPARTMENT MODE: two-level (departments -> employees) =====
    if (assignTo === 'department') {
        if (!deptLayer || !deptLabel || !deptBox) return;

        deptLayer.style.display = 'block';
        deptLabel.textContent = 'Select Departments';
        targetLabel.textContent = 'Select Users (from selected departments)';

        // Render department checkboxes
        (assignmentData.departments || []).forEach(function(dept) {
            const label = document.createElement('label');
            label.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = dept.id;
            cb.checked = savedDeptIds.indexOf(Number(dept.id)) !== -1;
            cb.addEventListener('change', renderDeptEmployees);
            label.appendChild(cb);
            const span = document.createElement('span');
            span.textContent = dept.name;
            label.appendChild(span);
            deptBox.appendChild(label);
        });

        renderDeptEmployees();
        return;
    }

    // ===== ROLE / USER / SHIFT MODE: single-level checkbox list =====
    let entries = [];
    if (assignTo === 'role') {
        targetLabel.textContent = 'Select Roles';
        (assignmentData.roles || []).forEach(item => {
            entries.push({ id: item.id, label: item.name });
        });
    } else if (assignTo === 'user') {
        targetLabel.textContent = 'Select Users';
        (assignmentData.users || []).forEach(item => {
            entries.push({ id: item.id, label: `${item.first_name} ${item.last_name} (${item.employee_id})` });
        });
    } else if (assignTo === 'shift') {
        targetLabel.textContent = 'Select Shifts';
        (assignmentData.shifts || []).forEach(item => {
            entries.push({ id: item.id, label: item.name });
        });
    } else {
        targetContainer.style.display = 'none';
        return;
    }

    // Render a checkbox per entry, pre-ticking saved ones
    entries.forEach(function(entry) {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = entry.id;
        cb.checked = savedIds.indexOf(Number(entry.id)) !== -1;
        label.appendChild(cb);
        const span = document.createElement('span');
        span.textContent = entry.label;
        label.appendChild(span);
        targetBox.appendChild(label);
    });
}

// Render employee checkboxes for the currently-ticked departments in department mode
function renderDeptEmployees() {
    const targetBox = document.getElementById('idleTargetCheckboxes');
    if (!targetBox) return;

    const checkedDeptIds = [];
    document.querySelectorAll('#idleDepartmentCheckboxes input[type="checkbox"]:checked').forEach(function(cb) {
        checkedDeptIds.push(Number(cb.value));
    });

    // List of all users, with their departments from user_departments
    const users = assignmentData.users || [];
    const userDepts = assignmentData.user_departments || {};

    // Filter users who belong to at least one selected department
    const eligible = users.filter(u => {
        const ud = userDepts[u.id] || [];
        return ud.some(d => checkedDeptIds.indexOf(d) !== -1);
    });

    targetBox.innerHTML = '';

    const saved = (document.getElementById('idleSavedTargetValue') || {}).value || '';
    const savedIds = saved.split(',').map(s => parseInt(s, 10)).filter(n => !isNaN(n));

    eligible.forEach(function(u) {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = u.id;
        cb.checked = savedIds.indexOf(Number(u.id)) !== -1;
        label.appendChild(cb);
        const span = document.createElement('span');
        span.textContent = `${u.first_name} ${u.last_name} (${u.employee_id})`;
        label.appendChild(span);
        targetBox.appendChild(label);
    });

    if (eligible.length === 0) {
        targetBox.innerHTML = '<div style="color:var(--muted);font-size:13px;">No employees found in the selected department(s).</div>';
    }
}

function openRolePermissionMatrix() {
    openPermissionMatrix();
}

function changePassword() {
    const current = document.getElementById('currentPass').value;
    const next = document.getElementById('newPass').value;
    const confirmPassword = document.getElementById('confirmPass').value;
    if (!current || !next || !confirmPassword) {
        alert('Please fill in all password fields.');
        return;
    }
    if (next !== confirmPassword) {
        alert('New password and confirmation do not match.');
        return;
    }
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'change_password', current_password: current, new_password: next })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Password changed successfully. Please log in again.');
            window.location.href = 'logout.php';
        } else {
            alert('Error: ' + (data.message || 'Could not change password'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error changing password');
    });
}

function saveNotificationPreferences() {
    const enabled = document.getElementById('notifEnabled').checked ? 1 : 0;
    const browserNotifications = document.getElementById('browserNotif').checked ? 1 : 0;
    const soundEnabled = document.getElementById('soundEnabled').checked ? 1 : 0;

    // Collect type preferences
    const typesEnabled = {};
    document.querySelectorAll('.notif-type').forEach(checkbox => {
        const type = checkbox.dataset.type;
        typesEnabled[type] = checkbox.checked ? 1 : 0;
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_notification_preferences',
            enabled: enabled,
            types_enabled: JSON.stringify(typesEnabled),
            browser_notifications: browserNotifications,
            sound_enabled: soundEnabled
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Notification preferences saved successfully.');
        } else {
            alert('Error: ' + (data.message || 'Could not save preferences'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving notification preferences');
    });
}

// Initialise the Idle Time Tracking "Apply To" target field (once the page is ready)
(function initIdleTargetField() {
    var idleAssign = document.getElementById('idleAssignTo');
    if (!idleAssign) return;
    loadAssignmentData().then(function() {
        updateIdleTargetField();
    });
})();
</script>


