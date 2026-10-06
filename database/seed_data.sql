-- Orbit HRMS Seed Data
-- Core PHP HRMS Application
-- Initial seed data for roles, modules, leave types, etc.

USE `orbit_hrms`;

-- --------------------------------------------------------
-- Seed Modules
-- --------------------------------------------------------

INSERT INTO `modules` (`name`, `display_name`, `description`, `is_active`) VALUES
('dashboard', 'Dashboard', 'Main dashboard with widgets and statistics', 1),
('employees', 'Employees', 'Employee management module', 1),
('departments', 'Departments', 'Department management module', 1),
('attendance', 'Attendance', 'Attendance tracking and management', 1),
('leaves', 'Leaves', 'Leave management module', 1),
('tasks', 'Tasks', 'Task management module', 1),
('recruitment', 'Recruitment', 'Recruitment and candidate management', 1),
('payroll', 'Payroll', 'Payroll processing and management', 1),
('reports', 'Reports', 'Reports and analytics', 1),
('notices', 'Notices', 'Notice board and announcements', 1),
('notifications', 'Notifications', 'Notification management', 1),
('settings', 'Settings', 'System settings and configuration', 1);

-- --------------------------------------------------------
-- Seed Roles (Section 3.1)
-- --------------------------------------------------------

INSERT INTO `roles` (`name`, `hierarchy_level`, `description`, `is_active`) VALUES
('Super Admin', 1, 'Full system access with all permissions', 1),
('Admin', 2, 'Administrative access with most permissions', 1),
('HR Manager', 3, 'HR management with employee and leave permissions', 1),
('HR Executive', 4, 'HR operations with limited permissions', 1),
('Senior Manager', 5, 'Senior management with team oversight', 1),
('Manager', 6, 'Department manager with team permissions', 1),
('Team Leader', 7, 'Team leader with direct report permissions', 1),
('Senior Executive', 8, 'Senior executive role', 1),
('Executive', 9, 'Standard executive role', 1),
('Intern / Trainee', 10, 'Intern or trainee with basic access', 1);

-- --------------------------------------------------------
-- Seed Leave Types (Section 13.5)
-- --------------------------------------------------------

INSERT INTO `leave_types` (`name`, `calculation_basis`, `accrual_method`, `max_days_per_year`, `min_days_per_request`, `carry_forward`, `max_carry_forward_days`, `applicable_to`, `is_paid`, `requires_approval`, `description`, `is_active`) VALUES
('Casual Leave', 'working_days', 'fixed_annual', 12, 0.5, 1, 3, 'all', 1, 1, 'Short-term leave for personal reasons', 1),
('Sick Leave', 'working_days', 'fixed_annual', 10, 0.5, 1, 3, 'all', 1, 1, 'Leave for illness or medical reasons', 1),
('Annual Leave', 'working_days', 'fixed_annual', 21, 1.0, 1, 6, 'all', 1, 1, 'Annual vacation leave', 1),
('Maternity Leave', 'calendar_days', 'fixed_annual', 180, 7, 0, 0, 'all', 1, 1, 'Maternity leave for female employees', 1),
('Paternity Leave', 'calendar_days', 'fixed_annual', 15, 1, 0, 0, 'all', 1, 1, 'Paternity leave for male employees', 1),
('Compensatory Off', 'working_days', 'custom_formula', 0, 0.5, 0, 0, 'all', 1, 1, 'Compensatory leave for work on holidays', 1),
('Unpaid Leave', 'working_days', 'custom_formula', 0, 0.5, 0, 0, 'all', 0, 1, 'Unpaid leave for extended periods', 1);

-- --------------------------------------------------------
-- Seed Break Types (Section 17.2)
-- --------------------------------------------------------

INSERT INTO `break_types` (`name`, `default_duration_minutes`, `is_paid`, `deduct_from_work_time`, `description`, `is_active`) VALUES
('Lunch Break', 60, 1, 1, 'Standard lunch break', 1),
('Tea Break', 15, 1, 0, 'Short tea/coffee break', 1),
('Prayer Break', 15, 1, 0, 'Break for religious observance', 1),
('Rest Break', 10, 1, 0, 'Short rest break', 1);

-- --------------------------------------------------------
-- Seed Shifts (Section 2.3, 17.3)
-- --------------------------------------------------------

INSERT INTO `shifts` (`name`, `start_time`, `end_time`, `grace_in_minutes`, `grace_out_minutes`, `half_day_threshold_minutes`, `absent_threshold_minutes`, `full_day_minutes`, `description`, `is_active`) VALUES
('General Shift', '09:00:00', '18:00:00', 120, 180, 240, 120, 540, 'Standard day shift 9 AM - 6 PM', 1),
('Morning Shift', '06:00:00', '15:00:00', 60, 120, 240, 120, 540, 'Early morning shift 6 AM - 3 PM', 1),
('Night Shift', '20:00:00', '04:00:00', 120, 180, 240, 120, 480, 'Night shift 8 PM - 4 AM (spans midnight)', 1),
('Flexi Shift', '10:00:00', '19:00:00', 180, 240, 240, 180, 540, 'Flexible shift with extended grace periods', 1);

-- --------------------------------------------------------
-- Seed Task Statuses
-- --------------------------------------------------------

INSERT INTO `task_statuses` (`name`, `display_order`, `is_active`) VALUES
('Pending', 1, 1),
('In Progress', 2, 1),
('Completed', 3, 1),
('On Hold', 4, 1),
('Cancelled', 5, 1);

-- --------------------------------------------------------
-- Seed Task Priorities
-- --------------------------------------------------------

INSERT INTO `task_priorities` (`name`, `display_order`, `is_active`) VALUES
('Critical', 1, 1),
('High', 2, 1),
('Medium', 3, 1),
('Low', 4, 1);

-- --------------------------------------------------------
-- Seed Recruitment Stages (Section 8)
-- --------------------------------------------------------

INSERT INTO `recruitment_stages` (`name`, `display_order`, `is_active`) VALUES
('Sourcing', 1, 1),
('Screening', 2, 1),
('Interview', 3, 1),
('Onboarding', 4, 1),
('Offboarding', 5, 1),
('Blacklisted', 6, 1);

-- --------------------------------------------------------
-- Seed Employment Types
-- --------------------------------------------------------

INSERT INTO `employment_types` (`name`, `description`, `is_active`) VALUES
('Permanent', 'Full-time permanent employment', 1),
('Probation', 'Probationary period', 1),
('Intern', 'Internship position', 1),
('Temporary', 'Temporary/contract position', 1),
('Consultant', 'Consultant/contractor', 1);

-- --------------------------------------------------------
-- Seed Default Permissions for Super Admin
-- --------------------------------------------------------

-- Get module IDs
SET @dashboard_id = (SELECT id FROM modules WHERE name = 'dashboard');
SET @employees_id = (SELECT id FROM modules WHERE name = 'employees');
SET @departments_id = (SELECT id FROM modules WHERE name = 'departments');
SET @attendance_id = (SELECT id FROM modules WHERE name = 'attendance');
SET @leaves_id = (SELECT id FROM modules WHERE name = 'leaves');
SET @tasks_id = (SELECT id FROM modules WHERE name = 'tasks');
SET @recruitment_id = (SELECT id FROM modules WHERE name = 'recruitment');
SET @payroll_id = (SELECT id FROM modules WHERE name = 'payroll');
SET @reports_id = (SELECT id FROM modules WHERE name = 'reports');
SET @notices_id = (SELECT id FROM modules WHERE name = 'notices');
SET @notifications_id = (SELECT id FROM modules WHERE name = 'notifications');
SET @settings_id = (SELECT id FROM modules WHERE name = 'settings');

-- Grant all permissions to Super Admin (role_id = 1)
INSERT INTO `permissions` (`role_id`, `module_id`, `action`, `scope`, `allowed`) VALUES
(1, @dashboard_id, 'read', 'all', 1),
(1, @employees_id, 'create', 'all', 1),
(1, @employees_id, 'read', 'all', 1),
(1, @employees_id, 'update', 'all', 1),
(1, @employees_id, 'delete', 'all', 1),
(1, @employees_id, 'export', 'all', 1),
(1, @departments_id, 'create', 'all', 1),
(1, @departments_id, 'read', 'all', 1),
(1, @departments_id, 'update', 'all', 1),
(1, @departments_id, 'delete', 'all', 1),
(1, @attendance_id, 'create', 'all', 1),
(1, @attendance_id, 'read', 'all', 1),
(1, @attendance_id, 'update', 'all', 1),
(1, @attendance_id, 'export', 'all', 1),
(1, @leaves_id, 'create', 'all', 1),
(1, @leaves_id, 'read', 'all', 1),
(1, @leaves_id, 'update', 'all', 1),
(1, @leaves_id, 'approve', 'all', 1),
(1, @leaves_id, 'delete', 'all', 1),
(1, @tasks_id, 'create', 'all', 1),
(1, @tasks_id, 'read', 'all', 1),
(1, @tasks_id, 'update', 'all', 1),
(1, @tasks_id, 'delete', 'all', 1),
(1, @recruitment_id, 'create', 'all', 1),
(1, @recruitment_id, 'read', 'all', 1),
(1, @recruitment_id, 'update', 'all', 1),
(1, @recruitment_id, 'delete', 'all', 1),
(1, @payroll_id, 'create', 'all', 1),
(1, @payroll_id, 'read', 'all', 1),
(1, @payroll_id, 'update', 'all', 1),
(1, @payroll_id, 'approve', 'all', 1),
(1, @payroll_id, 'delete', 'all', 1),
(1, @reports_id, 'read', 'all', 1),
(1, @reports_id, 'export', 'all', 1),
(1, @notices_id, 'create', 'all', 1),
(1, @notices_id, 'read', 'all', 1),
(1, @notices_id, 'update', 'all', 1),
(1, @notices_id, 'delete', 'all', 1),
(1, @notifications_id, 'read', 'all', 1),
(1, @settings_id, 'create', 'all', 1),
(1, @settings_id, 'read', 'all', 1),
(1, @settings_id, 'update', 'all', 1),
(1, @settings_id, 'delete', 'all', 1);

-- --------------------------------------------------------
-- Seed Default Settings
-- --------------------------------------------------------

INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('organization_name', 'Orbit HRMS', 'Organization name'),
('timezone', 'Asia/Kolkata', 'Default timezone'),
('date_format', 'Y-m-d', 'Default date format'),
('login_method', 'both', 'Login method: email, username, or both'),
('employee_id_prefix', 'EMP', 'Employee ID prefix'),
('employee_id_start', '1000', 'Employee ID starting number'),
('employee_id_padding', '4', 'Employee ID zero padding'),
('pay_period_start_day', '1', 'Payroll period start day'),
('pay_period_end_day', '30', 'Payroll period end day');

-- --------------------------------------------------------
-- Seed Default Holiday Calendar
-- --------------------------------------------------------

INSERT INTO `holiday_calendars` (`name`, `description`, `year`, `is_active`) VALUES
('National Calendar 2025', 'India national holidays for 2025', 2025, 1);

-- Get calendar ID
SET @calendar_id = LAST_INSERT_ID();

-- Seed some holidays
INSERT INTO `holidays` (`holiday_calendar_id`, `date`, `name`, `type`, `description`) VALUES
(@calendar_id, '2025-01-26', 'Republic Day', 'Fixed', 'Republic Day of India'),
(@calendar_id, '2025-03-14', 'Holi', 'Fixed', 'Festival of Colors'),
(@calendar_id, '2025-03-31', 'Ramadan Eid', 'Optional', 'Eid ul-Fitr'),
(@calendar_id, '2025-04-14', 'Dr. Ambedkar Jayanti', 'Fixed', 'Birth anniversary of Dr. B.R. Ambedkar'),
(@calendar_id, '2025-05-01', 'Labour Day', 'Fixed', 'International Workers Day'),
(@calendar_id, '2025-08-15', 'Independence Day', 'Fixed', 'Independence Day of India'),
(@calendar_id, '2025-08-19', 'Janmashtami', 'Optional', 'Birthday of Lord Krishna'),
(@calendar_id, '2025-10-02', 'Gandhi Jayanti', 'Fixed', 'Birth anniversary of Mahatma Gandhi'),
(@calendar_id, '2025-10-20', 'Dussehra', 'Fixed', 'Victory of good over evil'),
(@calendar_id, '2025-10-21', 'Dussehra Holiday', 'Optional', 'Additional Dussehra holiday'),
(@calendar_id, '2025-11-01', 'Diwali', 'Fixed', 'Festival of Lights'),
(@calendar_id, '2025-11-02', 'Diwali Holiday', 'Optional', 'Additional Diwali holiday'),
(@calendar_id, '2025-12-25', 'Christmas', 'Fixed', 'Christmas Day');

-- --------------------------------------------------------
-- Seed Notification Templates
-- --------------------------------------------------------

INSERT INTO `notification_templates` (`type`, `title_template`, `message_template`, `channel_in_app`, `channel_email`, `channel_sms`, `is_active`) VALUES
('late_clock_in', 'Late Clock In', 'You clocked in late today at {time}. Please ensure timely attendance.', 1, 1, 0, 1),
('leave_request_submitted', 'Leave Request Submitted', '{employee_name} has submitted a leave request for {days} days from {start_date} to {end_date}.', 1, 1, 0, 1),
('leave_request_approved', 'Leave Request Approved', 'Your leave request for {days} days from {start_date} to {end_date} has been approved.', 1, 1, 0, 1),
('leave_request_rejected', 'Leave Request Rejected', 'Your leave request for {days} days from {start_date} to {end_date} has been rejected. Reason: {reason}', 1, 1, 0, 1),
('regularization_request', 'Attendance Regularization', '{employee_name} has requested attendance regularization for {date}.', 1, 1, 0, 1),
('task_assigned', 'New Task Assigned', 'You have been assigned a new task: {task_title}. Deadline: {deadline}.', 1, 1, 0, 1),
('task_deadline', 'Task Deadline Approaching', 'Task "{task_title}" is due on {deadline}. Please ensure completion.', 1, 1, 0, 1),
('new_notice', 'New Notice', 'New notice: {notice_title}. Please check the notice board.', 1, 1, 0, 1);

-- --------------------------------------------------------
-- Seed Default Salary Components
-- --------------------------------------------------------

INSERT INTO `salary_components` (`name`, `type`, `calculation_method`, `default_value`, `is_taxable`, `description`, `is_active`) VALUES
('Basic Pay', 'earning', 'flat', 0.00, 1, 'Basic salary component', 1),
('HRA', 'earning', 'percentage', 40.00, 1, 'House Rent Allowance', 1),
('Special Allowance', 'earning', 'flat', 0.00, 1, 'Special allowance', 1),
('Bonus', 'earning', 'flat', 0.00, 1, 'Performance bonus', 1),
('Shift Allowance', 'earning', 'flat', 0.00, 1, 'Shift allowance', 1),
('Other Allowance', 'earning', 'flat', 0.00, 1, 'Other allowances', 1),
('Professional Tax', 'deduction', 'flat', 200.00, 0, 'Professional tax deduction', 1),
('Income Tax (TDS)', 'deduction', 'percentage', 0.00, 0, 'Income tax deduction', 1),
('Other Deductions', 'deduction', 'flat', 0.00, 0, 'Other deductions', 1);

-- --------------------------------------------------------
-- Seed Default Salary Structure
-- --------------------------------------------------------

INSERT INTO `salary_structures` (`name`, `description`, `is_active`) VALUES
('Standard Structure', 'Standard salary structure for regular employees', 1);

-- Get structure ID
SET @structure_id = LAST_INSERT_ID();

-- Assign components to structure
SET @basic_id = (SELECT id FROM salary_components WHERE name = 'Basic Pay');
SET @hra_id = (SELECT id FROM salary_components WHERE name = 'HRA');
SET @special_id = (SELECT id FROM salary_components WHERE name = 'Special Allowance');
SET @pt_id = (SELECT id FROM salary_components WHERE name = 'Professional Tax');

INSERT INTO `salary_structure_components` (`salary_structure_id`, `salary_component_id`, `value`) VALUES
(@structure_id, @basic_id, 0.00),
(@structure_id, @hra_id, 40.00),
(@structure_id, @special_id, 0.00),
(@structure_id, @pt_id, 200.00);

-- --------------------------------------------------------
-- Seed Default Task Assignment Rules
-- --------------------------------------------------------

-- Allow higher hierarchy to assign to lower hierarchy
INSERT INTO `task_assignment_rules` (`from_role_id`, `to_role_id`, `is_allowed`) VALUES
-- Super Admin can assign to anyone
(1, 1, 1), (1, 2, 1), (1, 3, 1), (1, 4, 1), (1, 5, 1), (1, 6, 1), (1, 7, 1), (1, 8, 1), (1, 9, 1), (1, 10, 1),
-- Admin can assign to level 3 and below
(2, 3, 1), (2, 4, 1), (2, 5, 1), (2, 6, 1), (2, 7, 1), (2, 8, 1), (2, 9, 1), (2, 10, 1),
-- HR Manager can assign to HR Executive and below
(3, 4, 1), (3, 5, 1), (3, 6, 1), (3, 7, 1), (3, 8, 1), (3, 9, 1), (3, 10, 1),
-- Senior Manager can assign to Manager and below
(5, 6, 1), (5, 7, 1), (5, 8, 1), (5, 9, 1), (5, 10, 1),
-- Manager can assign to Team Leader and below
(6, 7, 1), (6, 8, 1), (6, 9, 1), (6, 10, 1),
-- Team Leader can assign to Senior Executive and below
(7, 8, 1), (7, 9, 1), (7, 10, 1),
-- Senior Executive can assign to Executive
(8, 9, 1), (8, 10, 1);
