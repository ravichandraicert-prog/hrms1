# Orbit HRMS

A comprehensive Human Resource Management System built with core PHP, Apache, and MySQL. This application follows a data-driven architecture where roles, permissions, departments, leave types, shifts, and other configurations are managed through the UI rather than hardcoded.

## Features

### Core Modules
- **Dashboard** - Configurable widget grid with real-time statistics
- **Employee Management** - Full CRUD with dynamic fields, import/export, and department assignments
- **Department Management** - Hierarchical department structure with multiple managers
- **Attendance Management** - Punch logging with shift-date algorithm for night shifts
- **Leave Management** - Configurable leave types, balances, and approval workflows
- **Task Management** - Task assignment with performance tracking
- **Recruitment** - Candidate pipeline from sourcing to onboarding
- **Payroll** - Salary structures, payroll generation, and payslips
- **Reports** - Attendance and payroll summary reports
- **Notice Board** - Targeted announcements with acknowledgment tracking
- **Notifications** - Unified notification feed
- **Settings** - Comprehensive configuration for all system aspects

### Key Architectural Features

#### Data-Driven Design (Section 1)
- **Nothing is hardcoded** - All roles, permissions, departments, leave types, shifts, and module labels are database records
- **Central permissions engine** - Single authorization service enforces permissions across all modules
- **Full CRUD support** - Every module supports create, read, update, delete where applicable
- **Combinable filters** - All list views support multiple filter criteria

#### Shift-Crossing-Midnight Solution (Section 2)
- **Separate attendance_date from calendar date** - Night shifts spanning midnight are correctly attributed to the shift date
- **Grace windows** - Configurable grace periods for clock-in/out
- **Immutable punch logs** - Original attendance data is never edited; corrections use regularization
- **UTC timestamp storage** - All timestamps stored in UTC, displayed in configured timezone

#### Permission System (Section 3)
- **Role-based permissions** - 10 default roles with hierarchical levels
- **Data scope control** - Self, direct reports, team, department, or org-wide visibility
- **User-level overrides** - Grant/revoke specific permissions for individual users
- **Task assignment hierarchy** - Configurable rules for who can assign tasks to whom

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher (remote server or local installation)
- Electron (for desktop app deployment)
- Network connectivity to remote MySQL server (if using remote database)

## Installation

### Web Server Installation

#### 1. Extract Files
Extract the HRMS files to your web server directory:
```
C:\xampp\htdocs\HRMS\
```
Or for other web servers, place in your web root directory.

#### 2. Configure Database
Edit `config/config.php` and update the database credentials:
```php
define('DB_HOST', '192.168.1.131'); // Your MySQL server address
define('DB_NAME', 'orbit_hrms');
define('DB_USER', 'root');
define('DB_PASS', 'GlobalIndia@123$'); // Your database password
```

#### 3. Create Database
Create a new MySQL database named `orbit_hrms`:
```sql
CREATE DATABASE orbit_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### 4. Run Installation Script
Open your browser and navigate to:
```
http://192.168.1.131/HRMS/install.php
```

This will:
- Create all database tables
- Seed initial data (roles, leave types, shifts, etc.)
- Create the default admin user

#### 5. Login
Use the default credentials:
- **Username:** `admin`
- **Password:** `admin123`

**Important:** Change the admin password immediately after first login!

#### 6. Delete Installation File
For security, delete `install.php` after successful installation:
```bash
rm /path/to/HRMS/install.php
```

### Electron Desktop App Installation

#### 1. Prepare PHP Environment
Ensure PHP is installed and available in your system PATH.

#### 2. Configure Database
Edit `config/config.php` with your database credentials (same as above).

#### 3. Start PHP Built-in Server
The Electron app will automatically start PHP built-in server, or you can test manually:
```bash
php -S 127.0.0.1:8000 -t /path/to/HRMS
```

#### 4. Launch Electron App
Run your Electron application which will:
- Start PHP built-in server on 127.0.0.1:8000
- Load the application at http://127.0.0.1:8000
- Connect to your remote MySQL database

## Directory Structure

```
HRMS/
├── api/                    # API endpoints
│   ├── clock.php          # Clock in/out API
│   └── employees.php      # Employee CRUD API
├── config/                # Configuration files
│   ├── config.php         # Application configuration
│   └── database.php       # Database connection class
├── database/              # Database files
│   ├── schema.sql         # Complete database schema
│   └── seed_data.sql      # Initial seed data
├── includes/              # Core classes and functions
│   ├── attendance.php     # Attendance punch logging
│   ├── auth.php           # Authentication class
│   ├── functions.php      # Common functions
│   └── permissions.php    # Permissions engine
├── modules/               # Module views
│   ├── dashboard.php
│   ├── employees.php
│   ├── departments.php
│   ├── attendance.php
│   ├── leaves.php
│   ├── tasks.php
│   ├── recruitment.php
│   ├── payroll.php
│   ├── reports.php
│   ├── notices.php
│   ├── notifications.php
│   └── settings.php
├── uploads/               # File upload directory (auto-created)
├── index.php              # Main application entry point
├── login.php              # Login page
├── logout.php             # Logout handler
├── install.php            # Installation script (delete after use)
└── README.md              # This file
```

## Default Credentials

After installation, the system includes:

### Admin User
- **Username:** admin
- **Password:** admin123
- **Role:** Super Admin

### Default Roles
1. Super Admin (Level 1) - Full system access
2. Admin (Level 2) - Administrative access
3. HR Manager (Level 3) - HR management
4. HR Executive (Level 4) - HR operations
5. Senior Manager (Level 5) - Senior management
6. Manager (Level 6) - Department manager
7. Team Leader (Level 7) - Team leadership
8. Senior Executive (Level 8) - Senior executive
9. Executive (Level 9) - Standard executive
10. Intern/Trainee (Level 10) - Basic access

### Default Leave Types
- Casual Leave (12 days/year)
- Sick Leave (10 days/year)
- Annual Leave (21 days/year)
- Maternity Leave (180 days)
- Paternity Leave (15 days)
- Compensatory Off
- Unpaid Leave

### Default Shifts
- General Shift (9 AM - 6 PM)
- Morning Shift (6 AM - 3 PM)
- Night Shift (8 PM - 4 AM) - spans midnight
- Flexi Shift (10 AM - 7 PM)

## Configuration

### Timezone
Default timezone is set to Asia/Kolkata (IST). Change in `config/config.php`:
```php
define('TIMEZONE', 'Asia/Kolkata');
```

### Employee ID Format
Configure in Settings → Employee ID Format:
- Prefix: EMP
- Starting Number: 1000
- Zero Padding: 4
- Result: EMP-1000, EMP-1001, etc.

### Pay Period
Configure in Settings:
- Pay Period Start Day: 1
- Pay Period End Day: 30

## Usage

### Clock In/Out
Use the global clock widget in the top-right corner of any page:
- Click "Clock In" to start your work session
- Click "Clock Out" to end your work session
- Select break types from the dropdown for breaks

### Managing Employees
1. Navigate to Employees module
2. Click "Add Employee" to create a new employee
3. Fill in the form (Personal Info, Employment Info, System Access, Bank Details)
4. Employee ID is auto-generated based on configured format
5. Assign department, role, shift, and holiday calendar

### Managing Leaves
1. Navigate to Leaves module
2. Click "Apply Leave" to submit a leave request
3. Select leave type, dates, and provide reason
4. Managers can approve/reject pending requests
5. Leave balances are automatically tracked

### Attendance Regularization
1. Navigate to Attendance module
2. Click on an attendance record to view details
3. Use Regularization to correct missed/incorrect punches
4. Requires manager approval

### Payroll Processing
1. Navigate to Payroll module
2. Configure salary structures in Settings
3. Assign salary structures to employees
4. Generate payroll for a pay period
5. Review and mark as paid

## Security Considerations

1. **Delete install.php** after installation
2. **Change default admin password** immediately
3. **Use HTTPS** in production
4. **Restrict file permissions** on config files
5. **Regular backups** of the database
6. **Review permissions** regularly

## API Endpoints

### Clock API
- `POST /api/clock.php` - Clock in/out
- `GET /api/clock.php` - Get current clock status

### Employees API
- `GET /api/employees.php` - List employees
- `POST /api/employees.php` - Create employee
- `PUT /api/employees.php?id=X` - Update employee
- `DELETE /api/employees.php?id=X` - Delete employee

## Troubleshooting

### Database Connection Error
- Verify MySQL service is running and accessible
- Check network connectivity to remote MySQL server (if using remote database)
- Check database credentials in `config/config.php`
- Ensure database `orbit_hrms` exists
- Test connection: `mysql -h 192.168.1.131 -u root -p orbit_hrms`

### Session Timeout
- Session timeout is disabled - users stay logged in indefinitely
- Previously: Session timeout was set to 2 hours (7200 seconds) in `config/config.php`

### File Upload Issues
- Ensure `uploads/` directory is writable
- Check file size limits in `config/config.php`
- Verify allowed file types

## Support

For issues or questions:
1. Check the requirements specification: `orbit-hrms-requirements-spec.md`
2. Review the database schema: `database/schema.sql`
3. Check the seed data for default configurations: `database/seed_data.sql`

## License

This is a proprietary HRMS solution. All rights reserved.

## Version

Current Version: 1.0.0
