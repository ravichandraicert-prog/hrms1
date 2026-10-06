<?php
/**
 * Leave Accrual Management Module
 * Provides interface to view accrual history and trigger manual accrual
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('leaves', 'update') && !hasPermission('settings', 'update')) {
    die('Access denied');
}

$pageTitle = 'Monthly Leave Accrual';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Orbit HRMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #F6F5F1;
            --surface: #FFFFFF;
            --ink: #191B1F;
            --muted: #6B6E76;
            --border: #E7E4DC;
            --accent: #5B4EE5;
            --success: #1F9E6D;
            --danger: #D65C5C;
            --radius: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }
        .card h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #4038B0;
        }
        .btn-secondary {
            background: var(--border);
            color: var(--ink);
        }
        .btn-secondary:hover {
            background: #D8D5CC;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            font-weight: 600;
            font-size: 13px;
            color: var(--muted);
            text-transform: uppercase;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-success {
            background: #E7F5EE;
            color: var(--success);
        }
        .form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .form-group {
            flex: 1;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .spinner {
            border: 3px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            margin: 0 auto 12px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo $pageTitle; ?></h1>
            <div>
                <a href="settings.php?section=leave_types" class="btn btn-secondary">Back to Settings</a>
            </div>
        </div>

        <!-- Manual Accrual Trigger -->
        <div class="card">
            <h2>Manual Accrual Trigger</h2>
            <p style="color: var(--muted); margin-bottom: 16px;">
                Manually trigger the monthly leave accrual process. This will process all eligible leave types and employees 
                based on their pay cycles and accrual settings.
            </p>
            <button onclick="processAccrual()" class="btn btn-primary">
                <i class="bi bi-play-circle"></i> Process Accrual Now
            </button>
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Processing accrual...</p>
            </div>
            <div id="result" style="margin-top: 16px; display: none;"></div>
        </div>

        <!-- Accrual Settings -->
        <div class="card">
            <h2>Leave Types with Monthly Accrual</h2>
            <div id="accrualSettings">
                <p style="color: var(--muted);">Loading...</p>
            </div>
        </div>

        <!-- Accrual History -->
        <div class="card">
            <h2>Accrual History</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" id="startDate" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" id="endDate" value="<?php echo date('Y-m-t'); ?>">
                </div>
                <div class="form-group">
                    <label>Leave Type</label>
                    <select id="leaveTypeFilter">
                        <option value="">All Leave Types</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Employee</label>
                    <select id="employeeFilter">
                        <option value="">All Employees</option>
                    </select>
                </div>
            </div>
            <button onclick="loadAccrualHistory()" class="btn btn-secondary">
                <i class="bi bi-search"></i> Filter
            </button>
            <div id="historyContainer" style="margin-top: 16px;">
                <p style="color: var(--muted);">Loading history...</p>
            </div>
        </div>
    </div>

    <script>
        // Load initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadAccrualSettings();
            loadFilters();
            loadAccrualHistory();
        });

        function processAccrual() {
            const loading = document.getElementById('loading');
            const result = document.getElementById('result');
            
            loading.style.display = 'block';
            result.style.display = 'none';
            
            fetch('api/leave_accrual.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'process_accrual' })
            })
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                result.style.display = 'block';
                
                if (data.success) {
                    result.innerHTML = `
                        <div class="status-badge status-success">
                            <i class="bi bi-check-circle"></i> Accrual completed successfully
                        </div>
                        <pre style="margin-top: 12px; padding: 12px; background: var(--bg); border-radius: 6px; overflow: auto;">${data.output || 'No output'}</pre>
                    `;
                    loadAccrualHistory();
                } else {
                    result.innerHTML = `
                        <div class="status-badge" style="background: var(--danger-bg); color: var(--danger);">
                            <i class="bi bi-exclamation-circle"></i> Error: ${data.message}
                        </div>
                    `;
                }
            })
            .catch(err => {
                loading.style.display = 'none';
                result.style.display = 'block';
                result.innerHTML = `
                    <div class="status-badge" style="background: var(--danger-bg); color: var(--danger);">
                        <i class="bi bi-exclamation-circle"></i> Error: ${err.message}
                    </div>
                `;
            });
        }

        function loadAccrualSettings() {
            fetch('api/leave_accrual.php?action=get_accrual_settings')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderAccrualSettings(data.leave_types);
                    }
                });
        }

        function renderAccrualSettings(leaveTypes) {
            const container = document.getElementById('accrualSettings');
            
            if (leaveTypes.length === 0) {
                container.innerHTML = '<p style="color: var(--muted);">No leave types with monthly accrual enabled.</p>';
                return;
            }
            
            let html = '<table><thead><tr><th>Leave Type</th><th>Days to Accrue</th><th>Timing</th><th>Total Accruals</th></tr></thead><tbody>';
            
            leaveTypes.forEach(lt => {
                html += `
                    <tr>
                        <td>${lt.name}</td>
                        <td>${lt.monthly_accrual_days} days</td>
                        <td>${formatTiming(lt.accrual_timing, lt.accrual_day_of_month)}</td>
                        <td>${lt.total_accruals || 0}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function formatTiming(timing, day) {
            if (timing === 'pay_cycle_end') return 'After pay cycle ends';
            if (timing === 'pay_cycle_start') return 'After pay cycle starts';
            if (timing === 'specific_day') return `Day ${day} of month`;
            return timing;
        }

        function loadFilters() {
            // Load leave types
            fetch('api/leaves.php?action=get_types')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.types) {
                        const select = document.getElementById('leaveTypeFilter');
                        data.types.forEach(lt => {
                            const option = document.createElement('option');
                            option.value = lt.id;
                            option.textContent = lt.name;
                            select.appendChild(option);
                        });
                    }
                });
            
            // Load employees
            fetch('api/employee.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.employees) {
                        const select = document.getElementById('employeeFilter');
                        data.employees.forEach(emp => {
                            const option = document.createElement('option');
                            option.value = emp.id;
                            option.textContent = `${emp.first_name} ${emp.last_name} (${emp.employee_id})`;
                            select.appendChild(option);
                        });
                    }
                });
        }

        function loadAccrualHistory() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const leaveTypeId = document.getElementById('leaveTypeFilter').value;
            const employeeId = document.getElementById('employeeFilter').value;
            
            let url = `api/leave_accrual.php?action=get_accrual_history&start_date=${startDate}&end_date=${endDate}`;
            if (leaveTypeId) url += `&leave_type_id=${leaveTypeId}`;
            if (employeeId) url += `&employee_id=${employeeId}`;
            
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderAccrualHistory(data.history);
                    }
                });
        }

        function renderAccrualHistory(history) {
            const container = document.getElementById('historyContainer');
            
            if (history.length === 0) {
                container.innerHTML = '<p style="color: var(--muted);">No accrual history found for the selected filters.</p>';
                return;
            }
            
            let html = '<table><thead><tr><th>Date</th><th>Employee</th><th>Leave Type</th><th>Days Accrued</th><th>Period</th><th>Pay Cycle</th></tr></thead><tbody>';
            
            history.forEach(record => {
                html += `
                    <tr>
                        <td>${record.accrual_date}</td>
                        <td>${record.first_name} ${record.last_name} (${record.employee_id})</td>
                        <td>${record.leave_type_name}</td>
                        <td>${record.days_accrued}</td>
                        <td>${record.accrual_period_start} to ${record.accrual_period_end}</td>
                        <td>${record.pay_cycle_name || 'N/A'}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
    </script>
</body>
</html>