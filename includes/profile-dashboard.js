/**
 * Profile Dashboard Modal
 * Handles the employee profile dashboard popup
 * Core PHP HRMS Application
 */

const ProfileDashboard = (function() {
    'use strict';

    let modalElement = null;
    let profileData = null;

    // Initialize the profile dashboard
    function init() {
        // Create modal structure if it doesn't exist
        if (!document.getElementById('profileDashboardModal')) {
            createModalStructure();
        }

        // Add click handler to sidebar profile section
        const profileSection = document.querySelector('.sidebar-foot .user-mini');
        if (profileSection) {
            profileSection.addEventListener('click', function(e) {
                e.preventDefault();
                openProfileDashboard();
            });
        }
    }

    // Create modal structure
    function createModalStructure() {
        const modalHTML = `
            <div id="profileDashboardModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                <div style="background:var(--surface);border-radius:var(--radius);width:1000px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                        <h2 style="font-family:'Space Grotesk';font-size:22px;font-weight:600;margin:0;color:var(--ink);">My Profile Dashboard</h2>
                        <button onclick="ProfileDashboard.close()" class="profile-close-btn" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted);line-height:1;padding:4px;border-radius:4px;transition:background .15s;">&times;</button>
                    </div>

                    <div id="profileDashboardContent" style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">
                        <!-- Profile cards will be loaded here -->
                        <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">
                            <i class="bi bi-hourglass-split" style="font-size:32px;"></i>
                            <p style="margin-top:12px;">Loading profile data...</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modalElement = document.getElementById('profileDashboardModal');
    }

    // Open profile dashboard
    function openProfileDashboard() {
        if (!modalElement) {
            createModalStructure();
        }
        
        modalElement.style.display = 'flex';
        loadProfileData();
    }

    // Close profile dashboard
    function closeProfileDashboard() {
        if (modalElement) {
            modalElement.style.display = 'none';
        }
    }

    // Load profile data from API
    function loadProfileData() {
        const content = document.getElementById('profileDashboardContent');
        content.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">
                <i class="bi bi-hourglass-split" style="font-size:32px;"></i>
                <p style="margin-top:12px;">Loading profile data...</p>
            </div>
        `;

        fetch('api/profile_dashboard.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    profileData = data.data;
                    renderProfileDashboard(profileData);
                } else {
                    content.innerHTML = `
                        <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--danger);">
                            <i class="bi bi-exclamation-triangle" style="font-size:32px;"></i>
                            <p style="margin-top:12px;">Error loading profile data: ${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading profile data:', error);
                content.innerHTML = `
                    <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--danger);">
                        <i class="bi bi-exclamation-triangle" style="font-size:32px;"></i>
                        <p style="margin-top:12px;">Error loading profile data. Please try again.</p>
                    </div>
                `;
            });
    }

    // Render profile dashboard
    function renderProfileDashboard(data) {
        const content = document.getElementById('profileDashboardContent');

        content.innerHTML = `
            <!-- My Profile Card -->
            <div class="panel" style="grid-column:span 1;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-person"></i> My Profile</div>
                </div>
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                    <div class="avatar" style="width:64px;height:64px;font-size:24px;">
                        ${getInitials(data.profile.first_name + ' ' + data.profile.last_name)}
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:16px;color:var(--ink);">
                            ${data.profile.first_name} ${data.profile.last_name}
                        </div>
                        <div style="font-size:13px;color:var(--muted);margin-top:2px;">
                            ${data.profile.position || 'Employee'}
                        </div>
                        <div style="font-size:12px;color:var(--faint);margin-top:2px;">
                            ${data.profile.employee_id}
                        </div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Department</div>
                        <div style="color:var(--ink);font-weight:500;">${data.profile.department_name || 'Not assigned'}</div>
                    </div>
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Role</div>
                        <div style="color:var(--ink);font-weight:500;">${data.profile.role_name || 'Employee'}</div>
                    </div>
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Joining Date</div>
                        <div style="color:var(--ink);font-weight:500;">
                            ${data.profile.date_of_joining ? formatDate(data.profile.date_of_joining) : 'Not set'}
                        </div>
                    </div>
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Employment Type</div>
                        <div style="color:var(--ink);font-weight:500;">${data.profile.employment_type || 'Full-time'}</div>
                    </div>
                </div>
            </div>

            <!-- Pending Tasks Card -->
            <div class="panel" style="grid-column:span 1;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-list-task"></i> Pending Tasks</div>
                    <div style="display:flex;gap:8px;">
                        <select id="taskPriorityFilter" onchange="filterTasks(this.value)" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;background:var(--bg);color:var(--ink);">
                            <option value="all">All Priorities</option>
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div id="pendingTasksList" style="max-height:200px;overflow-y:auto;">
                    ${renderPendingTasks(data.pending_tasks)}
                </div>
            </div>

            <!-- Attendance Summary Card -->
            <div class="panel" style="grid-column:span 1;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-calendar-check"></i> Attendance Summary</div>
                    <div style="font-size:11px;color:var(--faint);">
                        ${formatDate(data.attendance.pay_cycle_start)} - ${formatDate(data.attendance.pay_cycle_end)}
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:12px;background:var(--success-bg);border-radius:8px;">
                        <div style="font-family:'Space Grotesk';font-size:24px;font-weight:600;color:var(--success);">
                            ${data.attendance.present_days}
                        </div>
                        <div style="font-size:11px;color:var(--success);margin-top:4px;">Present</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:var(--danger-bg);border-radius:8px;">
                        <div style="font-family:'Space Grotesk';font-size:24px;font-weight:600;color:var(--danger);">
                            ${data.attendance.absent_days}
                        </div>
                        <div style="font-size:11px;color:var(--danger);margin-top:4px;">Absent</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:var(--warning-bg);border-radius:8px;">
                        <div style="font-family:'Space Grotesk';font-size:24px;font-weight:600;color:var(--warning);">
                            ${data.attendance.half_days}
                        </div>
                        <div style="font-size:11px;color:var(--warning);margin-top:4px;">Half Days</div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Leave Days</div>
                        <div style="color:var(--ink);font-weight:500;">${data.attendance.leave_days}</div>
                    </div>
                    <div>
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Total Working Days</div>
                        <div style="color:var(--ink);font-weight:500;">${data.attendance.total_working_days}</div>
                    </div>
                    <div style="grid-column:1/-1;">
                        <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Attendance Percentage</div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="flex:1;height:8px;background:#F1EFE9;border-radius:4px;overflow:hidden;">
                                <div style="height:100%;background:${getAttendanceBarColor(data.attendance.attendance_percentage)};width:${data.attendance.attendance_percentage}%;transition:width 0.3s;"></div>
                            </div>
                            <div style="font-weight:600;color:var(--ink);">${data.attendance.attendance_percentage}%</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Approvals & Regularisation Card -->
            <div class="panel" style="grid-column:span 1;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-check-circle"></i> Pending Approvals</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div style="text-align:center;padding:16px;background:var(--accent-ink);border-radius:8px;">
                        <div style="font-family:'Space Grotesk';font-size:32px;font-weight:600;color:var(--accent-dark);">
                            ${data.approvals.pending_leave_approvals}
                        </div>
                        <div style="font-size:12px;color:var(--accent-dark);margin-top:4px;">Leave Approvals</div>
                        ${data.approvals.can_approve_leaves ? 
                            `<a href="?module=leaves" style="display:inline-block;margin-top:8px;font-size:11px;color:var(--accent);text-decoration:none;">Review →</a>` : 
                            `<div style="font-size:10px;color:var(--faint);margin-top:4px;">No permission</div>`
                        }
                    </div>
                    <div style="text-align:center;padding:16px;background:var(--accent-ink);border-radius:8px;">
                        <div style="font-family:'Space Grotesk';font-size:32px;font-weight:600;color:var(--accent-dark);">
                            ${data.approvals.pending_regularization}
                        </div>
                        <div style="font-size:12px;color:var(--accent-dark);margin-top:4px;">Regularisation</div>
                        ${data.approvals.can_approve_attendance ? 
                            `<a href="?module=attendance" style="display:inline-block;margin-top:8px;font-size:11px;color:var(--accent);text-decoration:none;">Review →</a>` : 
                            `<div style="font-size:10px;color:var(--faint);margin-top:4px;">No permission</div>`
                        }
                    </div>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="panel" style="grid-column:span 1;">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-key"></i> Change Password</div>
                </div>
                <form id="changePasswordForm" onsubmit="ProfileDashboard.changePassword(event)" style="display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Current Password</label>
                        <input type="password" id="currentPassword" required 
                            style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg);color:var(--ink);"
                            placeholder="Enter current password">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">New Password</label>
                        <input type="password" id="newPassword" required
                            style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg);color:var(--ink);"
                            placeholder="Enter new password (min 4 characters)">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Confirm New Password</label>
                        <input type="password" id="confirmPassword" required 
                            style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg);color:var(--ink);"
                            placeholder="Confirm new password">
                    </div>
                    <div id="passwordMessage" style="font-size:12px;"></div>
                    <button type="submit" id="changePasswordBtn"
                        style="padding:10px 16px;background:#8B84FF;color:var(--accent-dark);border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;">
                        Change Password
                    </button>
                </form>
            </div>
        `;
    }

    // Render pending tasks list
    function renderPendingTasks(tasks) {
        if (!tasks || tasks.length === 0) {
            return `
                <div class="empty-state">
                    <i class="bi bi-check-circle"></i>
                    <p>No pending tasks</p>
                </div>
            `;
        }

        return tasks.map(task => `
            <div class="req-item">
                <div class="req-info">
                    <div class="req-name">${task.title}</div>
                    <div class="req-meta">
                        ${task.priority_name ? `<span class="badge ${getPriorityBadgeClass(task.priority_name)}">${task.priority_name}</span>` : ''} 
                        ${task.deadline ? '· Due ' + formatDate(task.deadline) : ''}
                    </div>
                </div>
                <div class="badge ${getStatusBadgeClass(task.status_name)}">${task.status_name || 'Pending'}</div>
            </div>
        `).join('');
    }

    // Filter tasks by priority
    function filterTasks(priority) {
        if (!profileData) return;

        const params = priority !== 'all' ? `?priority=${priority}` : '';

        fetch(`api/profile_dashboard.php${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    profileData.data.pending_tasks = data.data.pending_tasks;
                    const tasksList = document.getElementById('pendingTasksList');
                    if (tasksList) {
                        tasksList.innerHTML = renderPendingTasks(data.data.pending_tasks);
                    }
                }
            })
            .catch(error => {
                console.error('Error filtering tasks:', error);
            });
    }

    // Change password
    function changePassword(event) {
        event.preventDefault();

        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const messageDiv = document.getElementById('passwordMessage');
        const submitBtn = document.getElementById('changePasswordBtn');

        // Client-side validation
        if (newPassword !== confirmPassword) {
            messageDiv.innerHTML = '<span style="color:var(--danger);">New passwords do not match</span>';
            return;
        }

        if (newPassword.length < 4) {
            messageDiv.innerHTML = '<span style="color:var(--danger);">Password must be at least 4 characters</span>';
            return;
        }

        if (currentPassword === newPassword) {
            messageDiv.innerHTML = '<span style="color:var(--danger);">New password must be different from current password</span>';
            return;
        }

        // Disable button and show loading state
        submitBtn.disabled = true;
        submitBtn.textContent = 'Changing...';
        messageDiv.innerHTML = '';

        // Send request to API
        fetch('api/change_password.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                current_password: currentPassword,
                new_password: newPassword
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                messageDiv.innerHTML = '<span style="color:var(--success);">Password changed successfully! You can now log in with your new password.</span>';
                // Clear form
                document.getElementById('changePasswordForm').reset();
            } else {
                messageDiv.innerHTML = `<span style="color:var(--danger);">${data.message}</span>`;
            }
        })
        .catch(error => {
            console.error('Error changing password:', error);
            messageDiv.innerHTML = '<span style="color:var(--danger);">Error changing password. Please try again.</span>';
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Change Password';
        });
    }

    // Helper functions
    function getInitials(name) {
        const words = name.split(' ');
        let initials = '';
        words.forEach(word => {
            initials += word.charAt(0).toUpperCase();
        });
        return initials.substring(0, 2);
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function getPriorityBadgeClass(priority) {
        const classes = {
            'Critical': 'danger',
            'High': 'warning',
            'Medium': 'info',
            'Low': 'gray'
        };
        return classes[priority] || 'gray';
    }

    function getStatusBadgeClass(status) {
        const classes = {
            'Pending': 'pending',
            'In Progress': 'info',
            'Completed': 'approved',
            'On Hold': 'warning',
            'Cancelled': 'rejected'
        };
        return classes[status] || 'gray';
    }

    function getAttendanceBarColor(percentage) {
        if (percentage >= 90) return 'var(--success)';
        if (percentage >= 75) return 'var(--warning)';
        return 'var(--danger)';
    }

    // Public API
    return {
        init: init,
        open: openProfileDashboard,
        close: closeProfileDashboard,
        changePassword: changePassword
    };
})();

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => ProfileDashboard.init());
} else {
    ProfileDashboard.init();
}

// Make it available globally
window.ProfileDashboard = ProfileDashboard;

