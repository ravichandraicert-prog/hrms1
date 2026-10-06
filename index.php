<?php
/**
 * Main Application Entry Point
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Require login
$auth = new Auth();
$auth->requireLogin();

// Get current user
$user = getCurrentUser();

// Get current module from query string
$module = $_GET['module'] ?? 'dashboard';

// Validate module access
$allowedModules = ['dashboard', 'employees', 'departments', 'attendance', 'leaves', 'tasks', 'recruitment', 'payroll', 'my_payroll', 'reports', 'notices', 'notifications', 'settings', 'apps'];
if (!in_array($module, $allowedModules)) {
    $module = 'dashboard';
}

// Module titles mapping
$moduleTitles = [
    'dashboard' => 'Dashboard',
    'employees' => 'Employees',
    'departments' => 'Departments',
    'attendance' => 'Attendance',
    'leaves' => 'Leave Management',
    'tasks' => 'Task Management',
    'recruitment' => 'Recruitment',
    'payroll' => 'Payroll',
    'my_payroll' => 'My Payroll',
    'reports' => 'Reports',
    'notices' => 'Notice Board',
    'notifications' => 'Notifications',
    'settings' => 'Settings',
    'apps' => 'Apps'
];

$pageTitle = $moduleTitles[$module] ?? 'Dashboard';
$pageSub = date('l, j F') . ' — ' . $pageTitle;

// Load module file
$moduleFile = __DIR__ . "/modules/{$module}.php";
if (!file_exists($moduleFile)) {
    $module = 'dashboard';
    $moduleFile = __DIR__ . "/modules/dashboard.php";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo ucfirst($module); ?> - <?php echo APP_NAME; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="includes/modal-system.js?v=5"></script>
    <script src="includes/profile-dashboard.js?v=5"></script>
    <script>window.HRMS_USER_ID = <?php echo (int)$user['id']; ?>;</script>
    <script src="includes/notification-toast.js?v=2"></script>
    <style>
        :root{
            --bg:#F6F5F1;
            --surface:#FFFFFF;
            --ink:#191B1F;
            --muted:#6B6E76;
            --faint:#9A9CA3;
            --border:#E7E4DC;
            --accent:#5B4EE5;
            --accent-ink:#EFEEFC;
            --accent-dark:#4038B0;
            --success:#1F9E6D;
            --success-bg:#E7F5EE;
            --warning:#E0932E;
            --warning-bg:#FBF0E1;
            --danger:#D65C5C;
            --danger-bg:#FBEAEA;
            --info:#3B82C4;
            --info-bg:#E8F1F9;
            --radius:12px;
            --sidebar-w:240px;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        html{scroll-behavior:smooth;}
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--ink);
            -webkit-font-smoothing:antialiased;
            font-size:14px;
        }
        .mono{font-family:'IBM Plex Mono',monospace;}
        .display{font-family:'Space Grotesk',sans-serif;}

        /* Scrollbar */
        ::-webkit-scrollbar{width:8px;height:8px;}
        ::-webkit-scrollbar-track{background:transparent;}
        ::-webkit-scrollbar-thumb{background:#D8D5CC;border-radius:4px;}
        ::-webkit-scrollbar-thumb:hover{background:#C4C0B4;}
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* App shell */
        .app{display:flex;min-height:100vh;}

        /* Sidebar */
        .sidebar{
            width:var(--sidebar-w);flex-shrink:0;background:var(--surface);
            border-right:1px solid var(--border);
            padding:20px 14px;display:flex;flex-direction:column;
            position:sticky;top:0;height:100vh;overflow-y:auto;z-index:100;
            transition:margin-left .28s ease,transform .28s ease;
        }
        /* Sidebar collapsed (desktop): slides out, main content expands automatically.
           Offset by width + border so the border edge leaves the viewport too. */
        @media (min-width:769px){
            body.sidebar-collapsed .sidebar{margin-left:calc(-1 * var(--sidebar-w) - 1px);}
        }
        /* Sidebar edge/corner collapse button (desktop) — arrow pill straddling the sidebar edge */
        .sidebar-edge-toggle{
            display:none;position:fixed;top:70px;left:calc(var(--sidebar-w) + 1px);z-index:110;
            width:24px;height:24px;border-radius:50%;background:var(--surface);border:1px solid var(--border);
            align-items:center;justify-content:center;cursor:pointer;color:var(--accent);
            box-shadow:0 1px 6px rgba(25,27,31,0.18);transform:translateX(-50%);
            transition:left .28s ease,color .15s ease,border-color .15s ease;
        }
        .sidebar-edge-toggle i{font-size:13px;line-height:1;transition:transform .28s ease;}
        .sidebar-edge-toggle:hover{color:var(--accent-dark);border-color:var(--accent);background:var(--accent-ink);}
        body.sidebar-collapsed .sidebar-edge-toggle{left:13px;}
        body.sidebar-collapsed .sidebar-edge-toggle i{transform:rotate(180deg);}
        @media (min-width:769px){
            .sidebar-edge-toggle{display:flex;}
            .topbar .sidebar-toggle{display:none;}
        }
        @media (max-width:768px){
            .sidebar-edge-toggle{display:none!important;}
        }
        /* Sidebar backdrop (mobile off-canvas drawer) */
        .sidebar-backdrop{display:none;}
        .brand{display:flex;align-items:center;gap:10px;padding:0 8px 24px;}
        .brand-mark{width:30px;height:30px;border-radius:8px;background:var(--accent);position:relative;flex-shrink:0;}
        .brand-mark::after{content:'';position:absolute;inset:7px;border-radius:4px;background:var(--surface);}
        .brand-name{font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:17px;letter-spacing:-0.01em;}
        .brand-sub{font-size:10px;color:var(--faint);letter-spacing:0.08em;text-transform:uppercase;}

        .nav-group{margin-bottom:18px;}
        .nav-label{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--faint);padding:0 10px 7px;}
        .nav-item{
            display:flex;align-items:center;gap:11px;padding:8.5px 10px;border-radius:8px;
            font-size:13.5px;font-weight:500;color:var(--muted);cursor:pointer;
            transition:background .15s ease, color .15s ease;text-decoration:none;
        }
        .nav-item i{font-size:16px;width:18px;text-align:center;flex-shrink:0;opacity:0.85;}
        .nav-item:hover{background:#F5F4F0;color:var(--ink);}
        .nav-item.active{background:var(--accent-ink);color:var(--accent-dark);}
        .nav-item.active i{opacity:1;}
        .nav-badge{
            margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:600;
            min-width:17px;height:17px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px;
        }

        .sidebar-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--border);}
        .user-mini{display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:8px;text-decoration:none;color:inherit;cursor:pointer;transition:background .15s;}
        .user-mini:hover{background:#F5F4F0;}
        .avatar{
            width:32px;height:32px;border-radius:50%;background:var(--accent-ink);color:var(--accent-dark);
            display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;flex-shrink:0;
        }
        .user-mini-name{font-size:13px;font-weight:500;line-height:1.2;}
        .user-mini-role{font-size:11px;color:var(--muted);}
        .profile-close-btn:hover{background:var(--bg);}

        /* Main */
        .main{flex:1;min-width:0;display:flex;flex-direction:column;}
        .topbar{
            display:flex;align-items:center;justify-content:space-between;
            padding:16px 28px;border-bottom:1px solid var(--border);background:var(--surface);
            position:sticky;top:0;z-index:90;
        }
        .page-title{font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:600;letter-spacing:-0.01em;}
        .page-sub{font-size:12.5px;color:var(--muted);margin-top:2px;}
        .topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
        .topbar-right{display:flex;align-items:center;gap:12px;}
        .sidebar-toggle{flex-shrink:0;}
        .sidebar-toggle i{font-size:17px;color:var(--accent);transition:transform .28s ease,color .15s ease;}
        .sidebar-toggle:hover i{color:var(--accent-dark);}
        body.sidebar-collapsed .sidebar-toggle i{transform:rotate(180deg);}
        /* Narrow desktop/tablet widths: wrap the topbar instead of overlapping
           (below 768px it already wraps via the mobile rules) */
        @media (max-width:1100px){
            .topbar{flex-wrap:wrap;row-gap:10px;}
        }
        @media (min-width:769px){
            /* With the sidebar collapsed the widgets fit on one line again */
            body.sidebar-collapsed .topbar{flex-wrap:nowrap;}
        }
        .search{
            display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);
            border-radius:8px;padding:7px 12px;width:220px;
        }
        .search input{border:none;background:none;outline:none;font-size:13px;width:100%;font-family:'Inter';color:var(--ink);}
        .search i{font-size:14px;color:var(--faint);flex-shrink:0;}
        .icon-btn{
            width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:var(--surface);
            display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);position:relative;
            transition:background .15s;text-decoration:none;
        }
        .icon-btn:hover{background:var(--bg);}
        .icon-btn.logout{background:var(--ink);border-color:var(--ink);color:#fff;}
        .icon-btn.logout:hover{background:var(--danger);border-color:var(--danger);color:#fff;}
        .icon-btn i{font-size:16px;}
        .count-badge{
            position:absolute;top:-5px;right:-5px;background:var(--danger);color:#fff;font-size:9px;font-weight:600;
            min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 4px;
            border:1.5px solid var(--surface);
        }

        /* Content Styles */
        .content{padding:24px 28px 48px;flex:1;}

        /* Stat Grid */
        .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
        .stat-card{
            background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
            padding:16px 18px;transition:box-shadow .2s;
        }
        .stat-card:hover{box-shadow:0 2px 12px rgba(25,27,31,0.05);}
        .stat-label{font-size:12px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
        .stat-label i{font-size:14px;color:var(--faint);}
        .stat-value{font-family:'Space Grotesk';font-size:25px;font-weight:600;letter-spacing:-0.01em;}
        .stat-delta{font-size:11.5px;margin-top:5px;font-weight:500;color:var(--muted);}
        .stat-delta.up{color:var(--success);}
        .stat-delta.down{color:var(--danger);}

        /* Panel */
        .panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:20px;}
        .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
        .panel-title{font-family:'Space Grotesk';font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;}
        .panel-title i{font-size:15px;color:var(--accent);}
        .panel-link{font-size:12.5px;color:var(--accent);font-weight:500;cursor:pointer;}
        .panel-link:hover{text-decoration:underline;}

        /* Row Layout */
        .row{display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-bottom:14px;}
        .row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
        .row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;}

        /* Data Table */
        /* Scrollable container: horizontal scroll on narrow screens + sticky header support.
           Tables keep their natural column widths (no aggressive shrinking); a min-width
           floor prevents columns from being squeezed below a readable size. */
        .data-table{
            background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);width:100%;
            overflow:auto;max-height:calc(100vh - 180px);
            -webkit-overflow-scrolling:touch;
            scrollbar-width:thin;scrollbar-color:#D8D5CC transparent;
        }
        .data-table table{width:100%;min-width:560px;border-collapse:separate;border-spacing:0;}
        .data-table th{
            background:#FBFAF7;font-size:11px;font-weight:600;color:var(--faint);text-transform:uppercase;
            letter-spacing:0.04em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;
            position:sticky;top:0;z-index:5;
        }
        .data-table td{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
        .data-table tr:last-child td{border-bottom:none;}
        .data-table tbody tr{transition:background .12s;}
        .data-table tbody tr:hover{background:#FAF9F6;}
        /* Printing: tables must print fully — no internal scroll region, no sticky offsets */
        @media print{
            .data-table{max-height:none!important;overflow:visible!important;}
            .data-table th{position:static!important;}
        }

        /* Employee Cell */
        .emp-cell{display:flex;align-items:center;gap:10px;}
        .emp-name{font-weight:500;color:var(--ink);}
        .emp-id{font-size:11px;color:var(--faint);margin-top:2px;}
        .mono{font-family:'IBM Plex Mono',monospace;}

        /* Badge */
        .badge{font-size:11px;font-weight:600;padding:4px 9px;border-radius:6px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;}
        .badge.pending{background:var(--accent-ink);color:var(--accent-dark);}
        .badge.approved{background:var(--success-bg);color:var(--success);}
        .badge.rejected{background:var(--danger-bg);color:var(--danger);}
        .badge.info{background:var(--info-bg);color:var(--info);}
        .badge.warning{background:var(--warning-bg);color:var(--warning);}
        .badge.gray{background:#F0EEE8;color:var(--muted);}
        .badge.danger{background:var(--danger-bg);color:var(--danger);}
        .badge.success{background:var(--success-bg);color:var(--success);}

        /* Actions */
        .actions{display:flex;gap:6px;}
        .icon-btn{
            width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;
            cursor:pointer;color:var(--muted);transition:all .15s;background:var(--bg);
        }
        .icon-btn:hover{background:var(--accent-ink);color:var(--accent-dark);}

        /* Empty State */
        .empty-state{padding:40px;text-align:center;color:var(--muted);}
        .empty-state i{font-size:32px;margin-bottom:12px;display:block;color:var(--faint);}
        .empty-state p{font-size:13px;}

        /* Toolbar */
        .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;}
        .btn{
            padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;
            border:none;color:#fff;background:var(--accent);transition:background .15s;font-family:'Inter';
        }
        .btn:hover{background:var(--accent-dark);}
        .btn.sm{padding:6px 12px;font-size:11px;}
        .btn.primary{background:var(--accent);}
        .btn.primary:hover{background:var(--accent-dark);}
        .btn.sm{padding:6px 12px;font-size:11px;}
        .btn.sm.success{background:var(--success);}
        .btn.sm.success:hover{background:#0D8A50;}
        .btn.sm.danger{background:var(--danger);}
        .btn.sm.danger:hover{background:#C83030;}
        .filter-chip{
            padding:6px 12px;border-radius:8px;font-size:12px;border:1px solid var(--border);
            background:var(--surface);color:var(--ink);cursor:pointer;font-family:'Inter';
        }
        .filter-chip:hover{border-color:var(--faint);}
        .filter-chip.active{background:var(--ink);color:var(--surface);border-color:var(--ink);}
        .spacer{flex:1;}

        /* Search */
        .search{
            padding:7px 13px;border-radius:8px;border:1px solid var(--border);background:var(--surface);
            display:flex;align-items:center;gap:8px;
        }
        .search i{font-size:12px;color:var(--muted);}
        .search input{
            border:none;background:transparent;outline:none;font-size:12.5px;color:var(--ink);
            width:100%;font-family:'Inter';
        }
        .search input::placeholder{color:var(--muted);}

        /* Field */
        .field{margin-bottom:16px;}
        .field label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;}
        .field input,.field select,.field textarea{
            width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;
            font-size:13px;font-family:'Inter';background:var(--bg);color:var(--ink);outline:none;
        }
        .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent);background:var(--surface);}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}

        /* Tabs */
        .tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto;}
        .tab{
            padding:9px 16px;font-size:13px;font-weight:500;color:var(--muted);cursor:pointer;
            border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;
        }
        .tab:hover{color:var(--ink);}
        .tab.active{color:var(--accent-dark);border-bottom-color:var(--accent);font-weight:600;border-bottom:2px solid var(--accent);}

        /* Calendar */
        .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
        .cal-head{text-align:center;font-size:11px;font-weight:600;color:var(--faint);text-transform:uppercase;padding:8px 0;letter-spacing:0.04em;}
        .cal-day{
            min-height:72px;background:var(--surface);border:1px solid var(--border);border-radius:8px;
            padding:6px 8px;font-size:12px;cursor:pointer;transition:all .15s;position:relative;
        }
        .cal-day:hover{border-color:var(--accent);box-shadow:0 2px 8px rgba(91,78,229,0.1);}
        .cal-day.other{opacity:0.4;}
        .cal-day.today{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent);}
        .cal-day .cal-date{font-weight:600;font-size:12px;margin-bottom:4px;}
        .cal-day .cal-event{font-size:9.5px;padding:2px 4px;border-radius:3px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .cal-legend{display:flex;gap:14px;margin-top:14px;flex-wrap:wrap;}
        .legend-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
        .legend-dot{width:8px;height:8px;border-radius:2px;}

        /* Toggle switch */
        .toggle{position:relative;width:38px;height:21px;background:#D8D5CC;border-radius:11px;cursor:pointer;transition:background .2s;flex-shrink:0;}
        .toggle::after{content:'';position:absolute;top:2.5px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
        .toggle.on{background:var(--ink);}
        .toggle.on::after{left:19px;}

        /* Flow steps */
        .flow-wrap{max-width:800px;margin:0 auto;}
        .flow-steps{display:flex;align-items:center;gap:8px;margin-bottom:24px;}
        .flow-step{display:flex;flex-direction:column;align-items:center;gap:6px;min-width:60px;}
        .flow-num{width:28px;height:28px;border-radius:50%;background:var(--bg);color:var(--muted);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;}
        .flow-step.done .flow-num{background:var(--success);color:#fff;}
        .flow-step.current .flow-num{background:var(--accent);color:#fff;}
        .flow-step-label{font-size:11px;color:var(--muted);text-align:center;}
        .flow-step.done .flow-step-label,.flow-step.current .flow-step-label{color:var(--ink);font-weight:500;}
        .flow-line{flex:1;height:2px;background:var(--border);}
        .flow-line.done{background:var(--success);}

        /* Flow card */
        .flow-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;}
        .flow-title{font-family:'Space Grotesk';font-size:18px;font-weight:600;margin-bottom:8px;}
        .flow-desc{font-size:13px;color:var(--muted);margin-bottom:20px;}

        /* Balance strip */
        .balance-strip{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
        .balance-item{flex:1;min-width:100px;background:var(--accent-ink);padding:12px;border-radius:8px;text-align:center;}
        .balance-num{font-family:'Space Grotesk';font-size:24px;font-weight:600;color:var(--accent-dark);}
        .balance-lbl{font-size:11px;color:var(--accent-dark);margin-top:4px;}

        /* Progress */
        .progress{height:8px;background:#F1EFE9;border-radius:4px;overflow:hidden;}
        .progress-bar{height:100%;background:var(--accent);transition:width .3s;}
        .progress-bar.success{background:var(--success);}
        .progress-bar.warning{background:var(--warning);}
        .progress-bar.danger{background:var(--danger);}

        /* Progress Slider */
        .progress-slider{
            -webkit-appearance:none;
            appearance:none;
            width:100%;
            height:6px;
            border-radius:3px;
            background:#F1EFE9;
            outline:none;
            cursor:pointer;
        }
        .progress-slider::-webkit-slider-thumb{
            -webkit-appearance:none;
            appearance:none;
            width:16px;
            height:16px;
            border-radius:50%;
            background:var(--accent);
            cursor:pointer;
            transition:background .15s;
        }
        .progress-slider::-webkit-slider-thumb:hover{
            background:var(--accent-dark);
        }
        .progress-slider::-moz-range-thumb{
            width:16px;
            height:16px;
            border-radius:50%;
            background:var(--accent);
            cursor:pointer;
            border:none;
            transition:background .15s;
        }
        .progress-slider::-moz-range-thumb:hover{
            background:var(--accent-dark);
        }

        /* Flow actions */
        .flow-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;}

        /* Required field */
        .req{color:var(--danger);}

        /* Payslip */
        .payslip{background:#fff;padding:32px;border-radius:8px;max-width:800px;margin:0 auto;}
        .payslip-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid var(--ink);}
        .payslip-company{font-family:'Space Grotesk';font-size:18px;font-weight:600;color:var(--ink);}
        .payslip-period{font-size:12px;color:var(--muted);margin-top:4px;}
        .payslip-table{width:100%;border-collapse:collapse;}
        .payslip-table th{font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:0.04em;padding:8px 0;text-align:left;border-bottom:1px solid var(--border);}
        .payslip-table td{font-size:13px;padding:8px 0;border-bottom:1px solid #f0f0f0;}
        .payslip-table .total-row td{font-weight:600;color:var(--ink);border-top:2px solid var(--border);border-bottom:none;padding-top:12px;}
        .payslip-net{display:flex;justify-content:space-between;align-items:center;padding:20px;background:var(--accent-ink);border-radius:8px;margin-top:20px;}
        .payslip-net .label{font-size:11px;color:var(--accent-dark);text-transform:uppercase;letter-spacing:0.04em;}
        .payslip-net .amount{font-family:'Space Grotesk';font-size:32px;font-weight:600;color:var(--accent-dark);}

        /* Alert */
        .alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;}
        .alert.error{background:#FBEAEA;color:#A33A3A;border:1px solid #F0C9C9;}
        .alert.success{background:#E7F5EE;color:#146B47;border:1px solid #C5E8D6;}

        /* Request Item */
        .req-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
        .req-item:last-child{border-bottom:none;padding-bottom:0;}
        .req-info{flex:1;min-width:0;}
        .req-name{font-size:13px;font-weight:500;}
        .req-meta{font-size:11.5px;color:var(--muted);margin-top:1px;}

        /* Activity */
        .activity-item{display:flex;gap:12px;padding:9px 0;}
        .activity-dot{width:7px;height:7px;border-radius:50%;background:var(--accent);margin-top:5px;flex-shrink:0;}
        .activity-text{font-size:13px;line-height:1.5;}
        .activity-text b{font-weight:600;}
        .activity-time{font-size:11px;color:var(--faint);margin-top:2px;}

        /* Pulse Rail */
        .pulse-rail{display:flex;flex-direction:column;gap:8px;}
        .pulse-row{display:flex;align-items:center;gap:12px;padding:6px 0;}
        .pulse-name{width:140px;font-size:13px;font-weight:500;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .pulse-timeline{flex:1;display:flex;align-items:center;gap:4px;flex-wrap:wrap;min-width:0;}
        .pulse-session{display:flex;align-items:center;gap:2px;white-space:nowrap;}
        .session-time{font-size:11px;color:var(--muted);font-family:'IBM Plex Mono',monospace;}
        .session-bar{color:#5B4EE5;font-weight:400;letter-spacing:-0.5px;margin:0 2px;}
        .session-gap{color:var(--faint);font-size:8px;letter-spacing:1px;}
        .pulse-status{width:70px;text-align:right;font-size:11px;color:var(--muted);flex-shrink:0;font-family:'IBM Plex Mono',monospace;}
        .pulse-track{flex:1;height:8px;border-radius:4px;background:#F1EFE9;position:relative;overflow:hidden;}
        .pulse-seg{position:absolute;top:0;bottom:0;border-radius:4px;}
        .pulse-time{width:80px;text-align:right;font-size:11.5px;color:var(--muted);flex-shrink:0;}
        .pulse-legend{display:flex;gap:16px;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);flex-wrap:wrap;}
        .legend-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
        .legend-dot{width:8px;height:8px;border-radius:2px;}

        /* Settings Layout */
        .settings-layout{display:flex;gap:20px;}
        .settings-nav{width:240px;flex-shrink:0;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;}
        .settings-content{flex:1;min-width:0;}
        .settings-nav-item{
            display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;
            font-size:13px;font-weight:500;color:var(--muted);cursor:pointer;margin-bottom:4px;
        }
        .settings-nav-item:hover{background:var(--bg);color:var(--ink);}
        .settings-nav-item.active{background:var(--accent-ink);color:var(--accent-dark);}

        /* Notification Item */
        .notif-item{display:flex;align-items:center;gap:12px;padding:12px;border-bottom:1px solid var(--border);cursor:pointer;}
        .notif-item:hover{background:var(--bg);}
        .notif-item.unread{background:var(--accent-ink);}
        .notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .notif-info{flex:1;}
        .notif-title{font-weight:500;color:var(--ink);font-size:13px;}
        .notif-msg{font-size:12px;color:var(--muted);margin-top:2px;}
        .notif-time{font-size:11px;color:var(--faint);margin-top:4px;}

        /* Clock Widget */
        .clock-widget{
            display:flex;align-items:center;gap:12px;background:var(--accent-ink);
            padding:8px 16px;border-radius:8px;transition:background .15s;
        }
        .clock-widget.clocked-in{background:#FBEAEA;}
        .clock-widget.clocked-out{background:#E7F5EE;}
        .clock-time{font-family:'IBM Plex Mono',monospace;font-size:14px;font-weight:600;color:#191B1F;transition:color .15s;}
        .clock-widget.clocked-in .clock-time{color:#8A2424;}
        .clock-widget.clocked-out .clock-time{color:#0D6B37;}
        .clock-date{font-size:11px;color:var(--accent-dark);opacity:0.75;transition:color .15s;}
        .clock-widget.clocked-in .clock-date{color:#A33A3A;}
        .clock-widget.clocked-out .clock-date{color:#146B47;}
        .clock-btn{
            padding:6px 12px;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;
            border:none;color:#fff;background:var(--accent);transition:background .15s;
        }
        .clock-btn:hover{background:var(--accent-dark);}
        .clock-widget.clocked-in .clock-btn{background:var(--danger);}
        .clock-widget.clocked-in .clock-btn:hover{background:#C04A4A;}
        .clock-widget.clocked-out .clock-btn{background:var(--success);}
        .clock-widget.clocked-out .clock-btn:hover{background:#0D8A50;}

        /* Work Duration Widget (grey) */
        .duration-widget{
            display:flex;align-items:center;gap:12px;background:#EFECE8;
            padding:8px 16px;border-radius:8px;
        }
        .duration-time{font-family:'IBM Plex Mono',monospace;font-size:14px;font-weight:600;color:#43464E;}
        .duration-label{font-size:11px;color:var(--faint);}

        /* Break Widget (light orange) */
        .break-widget{
            display:flex;align-items:center;gap:12px;background:#FFF4E6;
            padding:8px 16px;border-radius:8px;transition:background .15s;
        }
        .break-widget:hover{background:#FFE4C4;}
        .break-widget.on-break{background:#FFE4C4;border:2px solid #FFA500;}
        .break-time{font-family:'IBM Plex Mono',monospace;font-size:14px;font-weight:600;color:#8B4513;}
        .break-label{font-size:11px;color:#D2691E;}
        .break-btn{
            padding:6px 12px;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;
            border:none;color:#fff;background:#FF8C00;transition:background .15s;
        }
        .break-btn:hover{background:#FF7F00;}
        .break-widget.on-break .break-btn{background:#FF6600;}
        .break-widget.on-break .break-btn:hover{background:#E65C00;}

        /* Break Type Button */
        .break-type-btn{
            width:100%;text-align:left;padding:12px 16px;background:var(--bg);
            border:1px solid var(--border);border-radius:8px;cursor:pointer;
            transition:all .15s;font-family:'Inter';
        }
        .break-type-btn:hover{
            background:var(--accent-ink);border-color:var(--accent-dark);
            transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,0.08);
        }
        .break-type-btn:active{transform:translateY(0);}

        /* Responsive */
        @media (max-width:1200px){
            .stat-grid{grid-template-columns:repeat(2,1fr);}
            .row,.row-2,.row-3{grid-template-columns:1fr;}
            .settings-layout{grid-template-columns:1fr;}
        }
        @media (max-width:768px){
            /* Off-canvas drawer: slides in over the content, backdrop dims the page */
            .sidebar{
                position:fixed;top:0;left:0;height:100vh;margin-left:0;
                transform:translateX(-105%);z-index:300;
                box-shadow:0 0 30px rgba(25,27,31,0.18);
            }
            body.sidebar-open .sidebar{transform:translateX(0);}
            .sidebar-backdrop{
                display:block;position:fixed;inset:0;background:rgba(25,27,31,0.4);
                z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease;
            }
            body.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto;}
            .topbar{padding:14px 16px;flex-wrap:wrap;gap:10px;}
            .search{width:100%;order:3;}
            .content{padding:16px;}
            .stat-grid{grid-template-columns:1fr;}
            .form-grid,.form-grid-3{grid-template-columns:1fr;}
            .toolbar{flex-direction:column;align-items:stretch;}
            .toolbar .spacer{display:none;}
            #profileDashboardModal > div {
                width: 95vw !important;
                padding: 20px !important;
            }
            #profileDashboardContent {
                grid-template-columns: 1fr !important;
            }
        }

        /* App Grid */
        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .app-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
        }

        .app-card:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(91,78,229,0.1);
            transform: translateY(-2px);
        }

        .app-icon {
            font-size: 32px;
            margin-bottom: 12px;
            display: block;
        }

        .app-name {
            font-size: 13px;
            font-weight: 500;
            color: var(--ink);
            word-break: break-word;
        }

        .app-menu-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            opacity: 0;
            transition: opacity 0.15s;
            cursor: pointer;
        }

        .app-card:hover .app-menu-btn {
            opacity: 1;
        }

        .app-menu-btn:hover {
            background: var(--bg);
            color: var(--ink);
        }

        /* App Menu Dropdown */
        .app-menu {
            position: absolute;
            top: 32px;
            right: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10;
            min-width: 120px;
        }

        .app-menu-item {
            padding: 8px 12px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--ink);
        }

        .app-menu-item:hover {
            background: var(--bg);
        }

        .app-menu-item.delete {
            color: var(--danger);
        }

        .app-menu-item.delete:hover {
            background: var(--danger-bg);
        }
    </style>
</head>
<body>
<script>
    // Restore sidebar collapsed state before the layout renders (prevents flicker)
    try{
        if (localStorage.getItem('orbit_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    }catch(e){}
</script>

<script>
    // ============================================================
    // Shared search-filter helpers (used by the module search boxes).
    // Fixes the "must type very fast or very slow" issue:
    //  - 450ms debounce that reads the LIVE input value when the
    //    timer fires (never a stale keyup value)
    //  - skips the reload when the filter value is unchanged
    //  - re-focuses the search box (caret at end) after the reload so
    //    typing can continue normally at any speed
    // ============================================================
    (function () {
        var FOCUS_KEY = 'hrms_search_focus';

        function saveFocus(input) {
            try {
                var el = input || document.activeElement;
                if (!el || el.tagName !== 'INPUT' || !el.closest || !el.closest('.search')) {
                    sessionStorage.removeItem(FOCUS_KEY);
                    return;
                }
                var list = Array.prototype.slice.call(document.querySelectorAll('.search input'));
                var idx = list.indexOf(el);
                if (idx === -1) {
                    sessionStorage.removeItem(FOCUS_KEY);
                    return;
                }
                sessionStorage.setItem(FOCUS_KEY, JSON.stringify({ i: idx, v: el.value }));
            } catch (e) { /* storage unavailable */ }
        }

        function restoreFocus() {
            try {
                var data = JSON.parse(sessionStorage.getItem(FOCUS_KEY) || 'null');
                sessionStorage.removeItem(FOCUS_KEY);
                if (!data) return;
                var list = document.querySelectorAll('.search input');
                var el = list[data.i];
                if (el && el.value === data.v) {
                    el.focus();
                    try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {}
                }
            } catch (e) { /* ignore */ }
        }

        // Navigate to a filter URL. Skips the reload when the target URL is
        // identical to the current one (e.g. a redundant keystroke after the
        // page has already reloaded with that value).
        function navigate(url) {
            if (String(url) === window.location.href) return;
            saveFocus(null);
            window.location = url;
        }

        // Debounced navigation for a single-param search box.
        // Usage: oninput="HrmsSearch.debounce(this, 'search')"
        function debounce(input, param, delayMs) {
            if (!input) return;
            clearTimeout(input._hrmsSearchTimer);
            input._hrmsSearchTimer = setTimeout(function () {
                var url = new URL(window.location);
                var value = input.value;
                if (value) {
                    url.searchParams.set(param, value);
                } else {
                    url.searchParams.delete(param);
                }
                navigate(url.toString());
            }, delayMs || 450);
        }

        window.HrmsSearch = { debounce: debounce, navigate: navigate, restoreFocus: restoreFocus };
        document.addEventListener('DOMContentLoaded', restoreFocus);
    })();
</script>

<div class="app">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <div class="brand-mark"></div>
            <div>
                <div class="brand-name">Orbit HRMS</div>
                <div class="brand-sub">Human Resources</div>
            </div>
        </div>

        <div class="nav-group">
            <div class="nav-label">Workspace</div>
            <a href="?module=dashboard" class="nav-item <?php echo $module == 'dashboard' ? 'active' : ''; ?>">
                <i class="bi bi-grid-1x2"></i> Dashboard
            </a>
            <?php if (hasModuleAccess('employees')): ?>
            <a href="?module=employees" class="nav-item <?php echo $module == 'employees' ? 'active' : ''; ?>">
                <i class="bi bi-people"></i> Employees
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('departments')): ?>
            <a href="?module=departments" class="nav-item <?php echo $module == 'departments' ? 'active' : ''; ?>">
                <i class="bi bi-diagram-3"></i> Departments
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('attendance')): ?>
            <a href="?module=attendance" class="nav-item <?php echo $module == 'attendance' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('leaves')): ?>
            <a href="?module=leaves" class="nav-item <?php echo $module == 'leaves' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-minus"></i> Leaves
            </a>
            <?php endif; ?>
        </div>

        <div class="nav-group">
            <div class="nav-label">Management</div>
            <?php if (hasModuleAccess('tasks')): ?>
            <a href="?module=tasks" class="nav-item <?php echo $module == 'tasks' ? 'active' : ''; ?>">
                <i class="bi bi-list-task"></i> Tasks
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('recruitment')): ?>
            <a href="?module=recruitment" class="nav-item <?php echo $module == 'recruitment' ? 'active' : ''; ?>">
                <i class="bi bi-person-plus"></i> Recruitment
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('payroll')): ?>
            <a href="?module=payroll" class="nav-item <?php echo in_array($module, ['payroll', 'my_payroll']) ? 'active' : ''; ?>">
                <i class="bi bi-cash-stack"></i> Payroll
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('my_payroll')): ?>
            <a href="?module=my_payroll" class="nav-item <?php echo $module == 'my_payroll' ? 'active' : ''; ?>">
                <i class="bi bi-wallet2"></i> My Payroll
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('reports')): ?>
            <a href="?module=reports" class="nav-item <?php echo $module == 'reports' ? 'active' : ''; ?>">
                <i class="bi bi-graph-up"></i> Reports
            </a>
            <?php endif; ?>
        </div>

        <div class="nav-group">
            <div class="nav-label">Organization</div>
            <?php if (hasModuleAccess('notices')): ?>
            <a href="?module=notices" class="nav-item <?php echo $module == 'notices' ? 'active' : ''; ?>">
                <i class="bi bi-megaphone"></i> Notices
                <?php
                $noticeCount = getUnreadNoticeNotificationCount();
                if ($noticeCount > 0):
                ?>
                    <div class="nav-badge"><?php echo $noticeCount; ?></div>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <a href="?module=notifications" class="nav-item <?php echo $module == 'notifications' ? 'active' : ''; ?>">
                <i class="bi bi-bell"></i> Notifications
                <?php if (getUnreadNotificationCount() > 0): ?>
                    <div class="nav-badge"><?php echo getUnreadNotificationCount(); ?></div>
                <?php endif; ?>
            </a>
            <?php if (hasModuleAccess('apps')): ?>
            <a href="?module=apps" class="nav-item <?php echo $module == 'apps' ? 'active' : ''; ?>">
                <i class="bi bi-grid"></i> Apps
            </a>
            <?php endif; ?>
            <?php if (hasModuleAccess('settings')): ?>
            <a href="?module=settings" class="nav-item <?php echo $module == 'settings' ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i> Settings
            </a>
            <?php endif; ?>
        </div>

        <div class="sidebar-foot">
            <div class="user-mini" style="cursor:pointer;">
                <div class="avatar"><?php echo getInitials($user['first_name'] . ' ' . $user['last_name']); ?></div>
                <div>
                    <div class="user-mini-name"><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></div>
                    <div class="user-mini-role"><?php echo $user['role_name'] ?? 'Employee'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar backdrop (mobile off-canvas drawer) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar edge collapse button (desktop, arrow pill on sidebar edge) -->
    <button type="button" class="sidebar-edge-toggle" id="sidebarEdgeToggle" aria-label="Toggle sidebar" title="Hide sidebar">
        <i class="bi bi-chevron-left"></i>
    </button>

    <!-- Main -->
    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <button type="button" class="icon-btn sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true" title="Hide sidebar">
                    <i class="bi bi-layout-sidebar"></i>
                </button>
                <div>
                    <div class="page-title" id="pageTitle"><?php echo $pageTitle; ?></div>
                    <div class="page-sub" id="pageSub"><?php echo $pageSub; ?></div>
                </div>
            </div>
            <div class="topbar-right">
                <!-- Net Time Widget -->
                <div class="duration-widget" id="durationWidget">
                    <div>
                        <div class="duration-time" id="netTime">00:00:00</div>
                        <div class="duration-label">Net Time</div>
                    </div>
                </div>

                <!-- Global Clock Widget -->
                <div class="clock-widget clocked-out" id="clockWidget">
                    <div>
                        <div class="clock-time" id="liveClock">00:00:00</div>
                        <div class="clock-date" id="liveDate"><?php echo date('M j, Y'); ?></div>
                    </div>
                    <button class="clock-btn" id="clockInOutBtn" onclick="clockInOut()">Clock In</button>
                </div>

                <!-- Break Widget -->
                <div class="break-widget" id="breakWidget">
                    <div>
                        <div class="break-time" id="breakDuration">00:00:00</div>
                        <div class="break-label">Break Time</div>
                    </div>
                    <button class="break-btn" id="breakBtn" onclick="openBreakModal()">Take Break</button>
                </div>

                <a href="?module=notifications" class="icon-btn">
                    <i class="bi bi-bell"></i>
                    <?php if (getUnreadNotificationCount() > 0): ?>
                        <div class="count-badge"><?php echo getUnreadNotificationCount(); ?></div>
                    <?php endif; ?>
                </a>
                <a href="logout.php" class="icon-btn logout" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>

        <div class="content">
            <?php include $moduleFile; ?>
        </div>
    </div>

</div>

<script>
    // ==================== Sidebar Collapse / Expand ====================
    (function(){
        var body = document.body;
        var STORAGE_KEY = 'orbit_sidebar_collapsed';
        var toggleBtn = document.getElementById('sidebarToggle');
        var edgeToggleBtn = document.getElementById('sidebarEdgeToggle');
        var backdrop = document.getElementById('sidebarBackdrop');
        var mobileQuery = window.matchMedia('(max-width: 768px)');

        function isMobileView(){ return mobileQuery.matches; }

        function updateToggleState(){
            var collapsed = body.classList.contains('sidebar-collapsed');
            var open = body.classList.contains('sidebar-open');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', isMobileView() ? String(open) : String(!collapsed));
                toggleBtn.title = isMobileView() ? 'Toggle menu' : (collapsed ? 'Show sidebar' : 'Hide sidebar');
            }
            if (edgeToggleBtn) {
                edgeToggleBtn.setAttribute('aria-expanded', String(!collapsed));
                edgeToggleBtn.title = collapsed ? 'Show sidebar' : 'Hide sidebar';
            }
        }

        function toggleSidebar(){
            if (isMobileView()) {
                body.classList.toggle('sidebar-open');
            } else {
                var collapsed = body.classList.toggle('sidebar-collapsed');
                try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch(e) {}
            }
            updateToggleState();
        }

        function closeMobileSidebar(){
            body.classList.remove('sidebar-open');
            updateToggleState();
        }

        if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
        if (edgeToggleBtn) edgeToggleBtn.addEventListener('click', toggleSidebar);
        if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);

        // Close the mobile drawer after picking a destination
        var navItems = document.querySelectorAll('.sidebar .nav-item');
        for (var i = 0; i < navItems.length; i++) {
            navItems[i].addEventListener('click', closeMobileSidebar);
        }

        // Reset transient drawer state when crossing the mobile/desktop breakpoint
        function onBreakpointChange(e){
            if (!e.matches) closeMobileSidebar(); // switched to desktop view
            updateToggleState();
        }
        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', onBreakpointChange);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(onBreakpointChange);
        }

        updateToggleState();
    })();
</script>

<script>
    // Universal Search Focus Preservation
    (function() {
        // Save focus state before page reload
        window.addEventListener('beforeunload', function() {
            const activeElement = document.activeElement;
            if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA')) {
                sessionStorage.setItem('hrms_focus_preserve', JSON.stringify({
                    selector: getElementSelector(activeElement),
                    value: activeElement.value,
                    selectionStart: activeElement.selectionStart,
                    selectionEnd: activeElement.selectionEnd,
                    scrollLeft: activeElement.scrollLeft,
                    scrollTop: activeElement.scrollTop
                }));
            } else {
                sessionStorage.removeItem('hrms_focus_preserve');
            }
        });

        // Restore focus state after page load
        window.addEventListener('DOMContentLoaded', function() {
            const focusData = sessionStorage.getItem('hrms_focus_preserve');
            if (focusData) {
                try {
                    const data = JSON.parse(focusData);
                    const element = document.querySelector(data.selector);
                    
                    if (element) {
                        // Restore value and cursor position
                        element.value = data.value;
                        element.setSelectionRange(data.selectionStart, data.selectionEnd);
                        element.scrollLeft = data.scrollLeft;
                        element.scrollTop = data.scrollTop;
                        
                        // Restore focus with a small delay to ensure DOM is ready
                        setTimeout(function() {
                            element.focus();
                        }, 50);
                    }
                    
                    // Clear the saved state
                    sessionStorage.removeItem('hrms_focus_preserve');
                } catch (e) {
                    console.error('Error restoring focus:', e);
                    sessionStorage.removeItem('hrms_focus_preserve');
                }
            }
        });

        // Helper function to get a unique selector for an element
        function getElementSelector(element) {
            if (element.id) {
                return '#' + element.id;
            }
            
            if (element.name) {
                return '[name="' + element.name + '"]';
            }
            
            // Build a path-based selector
            const path = [];
            let current = element;
            
            while (current && current.nodeType === Node.ELEMENT_NODE) {
                let selector = current.tagName.toLowerCase();
                
                if (current.id) {
                    selector += '#' + current.id;
                    path.unshift(selector);
                    break;
                } else {
                    let sibling = current;
                    let nth = 1;
                    while (sibling = sibling.previousElementSibling) {
                        if (sibling.tagName.toLowerCase() === selector.toLowerCase()) {
                            nth++;
                        }
                    }
                    selector += ':nth-child(' + nth + ')';
                }
                
                path.unshift(selector);
                current = current.parentElement;
            }
            
            return path.join(' > ');
        }
    })();

    // Work Duration Timer
    let clockInAt = null;
    let baseWorkedSeconds = 0; // Accumulated work time from database in seconds
    let baseBreakSeconds = 0; // Accumulated break time from database in seconds for Net Time calculation
    let baseIdleSeconds = 0; // Accumulated idle time from database in seconds for Net Time calculation

    // Idle Time Tracking state
    let idleSettings = { enabled: false, timeout_minutes: 20, min_record_minutes: 6 };
    let hrmsIsDesktop = !!(window.hrmsDesktop && typeof window.hrmsDesktop.getSystemIdleTime === 'function');
    let hrmsOsIdleSeconds = 0; // Last OS-level idle seconds sampled (Electron)
    let hrmsOsIdleAt = Date.now();
    let hrmsLastActivityAt = Date.now(); // Browser fallback last activity
    let hrmsHeartbeatTimer = null;
    let hrmsShutdownInProgress = false; // Electron main process signalled a shutdown/quit

    // Parse "YYYY-MM-DD HH:MM:SS" (stored UTC) into a JavaScript Date object
    function parseUtc(stamp) {
        const [datePart, timePart] = stamp.split(' ');
        const [y, mo, d] = datePart.split('-').map(Number);
        const [h, mi, s] = timePart.split(':').map(Number);
        return new Date(Date.UTC(y, mo - 1, d, h, mi, s));
    }

    function formatDuration(totalSeconds) {
        const hh = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
        const mm = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
        const ss = String(totalSeconds % 60).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }

    // Live estimate of the currently-accumulating idle seconds (not yet saved to DB).
    // Only counted while clocked in, not on a break, and tracking is enabled.
    function getCurrentIdleSeconds() {
        if (!isClockedIn || window.breakStartAt || !idleSettings.enabled) return 0;
        if (hrmsIsDesktop) {
            // OS-level (Electron powerMonitor): a sampled value that grows while the
            // whole OS is idle and resets on any real keyboard/mouse input anywhere.
            return hrmsOsIdleSeconds;
        }
        // Browser fallback: seconds since the last activity event in this page.
        return Math.max(0, Math.floor((Date.now() - hrmsLastActivityAt) / 1000));
    }

    function updateWorkDuration() {
        const el = document.getElementById('netTime');

        if (!clockInAt) {
            // When clocked out, show Net Time from database (Work - Break - Idle)
            let netTimeSeconds = baseWorkedSeconds - baseBreakSeconds - baseIdleSeconds;
            if (netTimeSeconds < 0) netTimeSeconds = 0;
            el.textContent = formatDuration(netTimeSeconds);
            return;
        }

        // When clocked in, add current session time to accumulated time
        let currentSessionSeconds = Math.floor((Date.now() - clockInAt.getTime()) / 1000);
        if (currentSessionSeconds < 0) currentSessionSeconds = 0;
        let totalWorkedSeconds = baseWorkedSeconds + currentSessionSeconds;

        // Calculate Net Time = Work Duration - Total Break Duration - Total Idle Time
        let totalBreakSeconds = baseBreakSeconds;
        if (window.breakStartAt) {
            // If currently on break, add current break duration to total
            let currentBreakSeconds = Math.floor((Date.now() - window.breakStartAt.getTime()) / 1000);
            if (currentBreakSeconds < 0) currentBreakSeconds = 0;
            totalBreakSeconds += currentBreakSeconds;
        }

        let totalIdleSeconds = baseIdleSeconds + getCurrentIdleSeconds();

        let netTimeSeconds = totalWorkedSeconds - totalBreakSeconds - totalIdleSeconds;
        if (netTimeSeconds < 0) netTimeSeconds = 0;

        el.textContent = formatDuration(netTimeSeconds);
    }

    // Live Clock
    function updateClock() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour12: true });
        const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        document.getElementById('liveClock').textContent = timeStr;
        document.getElementById('liveDate').textContent = dateStr;
        updateWorkDuration();
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Clock In/Out
    let isClockedIn = false;
    function clockInOut() {
        const btn = document.getElementById('clockInOutBtn');
        const widget = document.getElementById('clockWidget');
        const action = isClockedIn ? 'clock_out' : 'clock_in';
        
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append('action', action);
        
        fetch('api/clock.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                // Update widget state from the action we just performed
                if (action === 'clock_in') {
                    btn.textContent = 'Clock Out';
                    widget.classList.remove('clocked-out');
                    widget.classList.add('clocked-in');
                    isClockedIn = true;
                    clockInAt = new Date();
                    // Set base worked time from database response
                    baseWorkedSeconds = (data.total_worked_minutes || 0) * 60;
                    baseIdleSeconds = (data.total_idle_minutes || 0) * 60;
                    if (data.idle_settings) idleSettings = data.idle_settings;
                    if (data.warning) {
                        ModalSystem.warning(data.warning);
                    }
                } else {
                    btn.textContent = 'Clock In';
                    widget.classList.remove('clocked-in');
                    widget.classList.add('clocked-out');
                    isClockedIn = false;
                    clockInAt = null;
                    // If we clocked out while on a break, stop the live break timer and reset
                    // the break button so it doesn't linger in the "End Break" state.
                    window.breakStartAt = null;
                    const bWidget = document.getElementById('breakWidget');
                    const bBtn = document.getElementById('breakBtn');
                    if (bWidget) bWidget.classList.remove('on-break');
                    if (bBtn) { bBtn.textContent = 'Take Break'; bBtn.onclick = openBreakModal; }
                    // Update base worked/break/idle time to include the just-completed session
                    baseWorkedSeconds = (data.total_worked_minutes || 0) * 60;
                    baseBreakSeconds = (data.total_break_minutes || 0) * 60;
                    baseIdleSeconds = (data.total_idle_minutes || 0) * 60;
                }
                console.log(data.message);
                // Refresh attendance data if we're on the attendance module
                if (window.location.search.includes('module=attendance')) {
                    setTimeout(() => location.reload(), 300);
                }
            } else {
                // Server rejected the punch (e.g. already clocked in / not clocked in)
                ModalSystem.error(data.message || 'Action failed');
                // Re-sync the widget with the real server state
                checkClockStatus();
            }
        })
        .catch(error => {
            btn.disabled = false;
            console.error('Error:', error);
            ModalSystem.error('Error: ' + error.message);
        });
    }
    
    // Check initial clock status on page load
    function checkClockStatus() {
        fetch('api/clock.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.status) {
                const btn = document.getElementById('clockInOutBtn');
                const widget = document.getElementById('clockWidget');
                
                // Load base worked time from database
                baseWorkedSeconds = (data.total_worked_minutes || 0) * 60;

                // Load base break time from database for Net Time calculation
                baseBreakSeconds = (data.total_break_minutes || 0) * 60;

                // Load base idle time from database for Net Time calculation
                baseIdleSeconds = (data.total_idle_minutes || 0) * 60;
                if (data.idle_settings) idleSettings = data.idle_settings;
                
                if (data.status.status === 'in') {
                    btn.textContent = 'Clock Out';
                    widget.classList.remove('clocked-out');
                    widget.classList.add('clocked-in');
                    isClockedIn = true;
                    // Start the work-duration timer from the server-recorded clock-in time
                    if (data.status.last_punch && data.status.last_punch.timestamp_utc) {
                        clockInAt = parseUtc(data.status.last_punch.timestamp_utc);
                    } else {
                        clockInAt = new Date();
                    }
                    
                    // Check if on break and update break widget
                    if (data.status.on_break && data.status.break_start) {
                        const breakTypeId = data.status.break_start.break_type_id;
                        // Set the break start immediately (before the label fetch) so the Net
                        // Time timer is frozen at the break edge during the page-load tick -
                        // otherwise breakStartAt stays null for a moment and break time would
                        // be counted as working time.
                        window.breakStartAt = parseUtc(data.status.break_start.timestamp_utc);
                        fetch('api/break.php')
                        .then(response => response.json())
                        .then(breakData => {
                            if (breakData.success && breakData.break_types) {
                                const breakType = breakData.break_types.find(bt => bt.id === breakTypeId);
                                const breakName = breakType ? breakType.name : 'Break';
                                updateBreakWidget(true, breakTypeId, breakName, parseUtc(data.status.break_start.timestamp_utc));
                            }
                        });
                    } else {
                        // Reset to default state
                        const btn = document.getElementById('breakBtn');
                        if (btn) {
                            btn.textContent = 'Take Break';
                            btn.onclick = openBreakModal;
                        }
                    }
                } else {
                    btn.textContent = 'Clock In';
                    widget.classList.remove('clocked-in');
                    widget.classList.add('clocked-out');
                    isClockedIn = false;
                    clockInAt = null;
                }
            }
        })
        .catch(error => {
            console.error('Error checking clock status:', error);
        });
    }
    checkClockStatus();

    // Break Modal Functions
    function openBreakModal() {
        // Check if user is clocked in
        fetch('api/clock.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success || data.status.status !== 'in') {
                ModalSystem.warning('You must be clocked in to take a break.');
                return;
            }
            
            // Check if already on break
            if (data.status.on_break) {
                // End the current break instead
                const breakTypeId = data.status.break_start.break_type_id;
                fetch('api/break.php')
                .then(response => response.json())
                .then(breakData => {
                    if (breakData.success && breakData.break_types) {
                        const breakType = breakData.break_types.find(bt => bt.id === breakTypeId);
                        const breakName = breakType ? breakType.name : 'Break';
                        endBreak(breakTypeId, breakName);
                    }
                });
                return;
            }
            
            // Load break types
            fetch('api/break.php')
            .then(response => response.json())
            .then(breakData => {
                if (breakData.success && breakData.break_types) {
                    showBreakSelection(breakData.break_types);
                } else {
                    ModalSystem.error('Error loading break types');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                ModalSystem.error('Error loading break types');
            });
        })
        .catch(error => {
            console.error('Error:', error);
            ModalSystem.error('Error checking clock status');
        });
    }

    function showBreakSelection(breakTypes) {
        // Create modal if it doesn't exist
        if (!document.getElementById('breakSelectionModal')) {
            const modalHTML = `
                <div id="breakSelectionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:var(--radius);width:420px;max-width:95vw;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                            <h3 style="font-family:'Space Grotesk';font-size:20px;font-weight:600;margin:0;color:var(--ink);">Select Break Type</h3>
                            <button onclick="closeBreakSelectionModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted);line-height:1;padding:4px;border-radius:4px;transition:background .15s;">&times;</button>
                        </div>
                        <div id="breakTypesList" style="display:flex;flex-direction:column;gap:12px;">
                            <!-- Break types will be loaded here -->
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        
        // Populate break types
        const container = document.getElementById('breakTypesList');
        container.innerHTML = '';
        
        breakTypes.forEach(breakType => {
            if (breakType.is_active) {
                // Get initials (first 2 letters, uppercase)
                const initials = breakType.name.substring(0, 2).toUpperCase();
                // Embed the name inside single quotes within the double-quoted onclick.
                // (JSON.stringify would produce double quotes and break the attribute.)
                const escName = String(breakType.name).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

                container.innerHTML += `
                    <button class="break-type-btn" onclick="chooseBreak(${breakType.id}, '${escName}')">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:40px;height:40px;background:var(--accent-ink);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                                <span style="color:var(--accent-dark);font-weight:600;font-size:14px;">${initials}</span>
                            </div>
                            <div style="flex:1;text-align:left;">
                                <div style="font-weight:600;color:var(--ink);font-size:14px;">${breakType.name}</div>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px;">
                                    ${breakType.is_unlimited ? '<i class="bi bi-infinity"></i>' : breakType.default_duration_minutes + ' min'}
                                </div>
                            </div>
                        </div>
                    </button>
                `;
            }
        });
        
        document.getElementById('breakSelectionModal').style.display = 'flex';
    }

    function closeBreakSelectionModal() {
        const modal = document.getElementById('breakSelectionModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Dispatcher: "Official Break" and "Training Break" open mandatory-input popups first;
    // every other break type starts immediately exactly as before.
    function chooseBreak(breakTypeId, breakName) {
        const breakNameLower = String(breakName).trim().toLowerCase();
        if (breakNameLower === 'official break') {
            openOfficialBreakModal(breakTypeId, breakName);
        } else if (breakNameLower === 'training break' || breakNameLower.includes('training')) {
            openTrainingBreakModal(breakTypeId, breakName);
        } else if (breakNameLower === 'meeting' || breakNameLower === 'meeting break' || breakNameLower.includes('meeting')) {
            openMeetingBreakModal(breakTypeId, breakName);
        } else {
            startBreak(breakTypeId, breakName);
        }
    }

    // ---- Official Break: reason popup ----
    function openOfficialBreakModal(breakTypeId, breakName) {
        if (!document.getElementById('officialBreakModal')) {
            const modalHTML = `
                <div id="officialBreakModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:var(--radius);width:480px;max-width:95vw;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">Official Break Request</h3>
                            <button onclick="closeOfficialBreakModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
                        </div>
                        <div style="margin-bottom:16px;color:var(--muted);font-size:13px;line-height:1.6;">
                            Please provide a brief reason for taking an official break. This information will be recorded for attendance and break tracking purposes.
                        </div>
                        <input type="hidden" id="officialBreakTypeId">
                        <input type="hidden" id="officialBreakName">
                        <div class="field">
                            <label>Reason <span class="req">*</span></label>
                            <textarea id="officialBreakReason" rows="3" placeholder="Enter the reason for this official break..." style="resize:vertical;"></textarea>
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                            <button class="btn" onclick="closeOfficialBreakModal()">Cancel</button>
                            <button class="btn primary" onclick="saveOfficialBreak()">Save</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        document.getElementById('officialBreakTypeId').value = breakTypeId;
        document.getElementById('officialBreakName').value = breakName;
        document.getElementById('officialBreakReason').value = '';
        document.getElementById('officialBreakModal').style.display = 'flex';
    }

    function closeOfficialBreakModal() {
        const modal = document.getElementById('officialBreakModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function saveOfficialBreak() {
        const reason = document.getElementById('officialBreakReason').value.trim();
        const breakTypeId = document.getElementById('officialBreakTypeId').value;
        const breakName = document.getElementById('officialBreakName').value;

        if (!reason) {
            ModalSystem.warning('Please enter a reason before starting the official break.');
            return;
        }

        const saveBtn = document.getElementById('officialBreakModal').querySelector('.btn.primary');
        if (saveBtn) saveBtn.disabled = true;

        closeOfficialBreakModal();
        // Only after the reason is provided does the break start. The reason is sent
        // with the BREAK_START request and stored on the break record.
        startBreak(breakTypeId, breakName, reason);
    }

    // ---- Meeting Break: meeting name popup (same logic as Training/Official breaks) ----
    function openMeetingBreakModal(breakTypeId, breakName) {
        if (!document.getElementById('meetingBreakModal')) {
            const modalHTML = `
                <div id="meetingBreakModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:var(--radius);width:480px;max-width:95vw;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">Start Meeting Break</h3>
                            <button onclick="closeMeetingBreakModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
                        </div>
                        <div style="margin-bottom:16px;color:var(--muted);font-size:13px;line-height:1.6;">
                            Which meeting are you attending? Please enter the meeting name to proceed.
                        </div>
                        <input type="hidden" id="meetingBreakTypeId">
                        <input type="hidden" id="meetingBreakName">
                        <div class="field">
                            <label>Meeting Name <span class="req">*</span></label>
                            <input type="text" id="meetingBreakMeetingName" placeholder="e.g. Weekly Team Sync" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-family:'Inter';font-size:13px;">
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                            <button class="btn" onclick="closeMeetingBreakModal()">Cancel</button>
                            <button class="btn primary" onclick="saveMeetingBreak()">Start Break</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        document.getElementById('meetingBreakTypeId').value = breakTypeId;
        document.getElementById('meetingBreakName').value = breakName;
        document.getElementById('meetingBreakMeetingName').value = '';
        document.getElementById('meetingBreakModal').style.display = 'flex';
    }

    function closeMeetingBreakModal() {
        const modal = document.getElementById('meetingBreakModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function saveMeetingBreak() {
        const meetingName = document.getElementById('meetingBreakMeetingName').value.trim();
        const breakTypeId = document.getElementById('meetingBreakTypeId').value;
        const breakName = document.getElementById('meetingBreakName').value;

        if (!meetingName) {
            ModalSystem.warning('Please enter the meeting name before starting the meeting break.');
            return;
        }

        const saveBtn = document.getElementById('meetingBreakModal').querySelector('.btn.primary');
        if (saveBtn) saveBtn.disabled = true;

        closeMeetingBreakModal();
        // Only after the meeting name is provided does the break start. The meeting name is sent
        // with the BREAK_START request and stored on the break record.
        startBreak(breakTypeId, breakName, meetingName);
    }

    // ---- Training Break: trainer/training name popup ----
    function openTrainingBreakModal(breakTypeId, breakName) {
        if (!document.getElementById('trainingBreakModal')) {
            const modalHTML = `
                <div id="trainingBreakModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:var(--radius);width:480px;max-width:95vw;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">Start Training Break</h3>
                            <button onclick="closeTrainingBreakModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
                        </div>
                        <div style="margin-bottom:16px;color:var(--muted);font-size:13px;line-height:1.6;">
                            Please enter the Trainer Name or Training Name to proceed.
                        </div>
                        <input type="hidden" id="trainingBreakTypeId">
                        <input type="hidden" id="trainingBreakName">
                        <div class="field">
                            <label>Trainer / Training Name <span class="req">*</span></label>
                            <input type="text" id="trainingBreakTrainerName" placeholder="Enter name" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-family:'Inter';font-size:13px;">
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                            <button class="btn" onclick="closeTrainingBreakModal()">Cancel</button>
                            <button class="btn primary" onclick="saveTrainingBreak()">Start Break</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        document.getElementById('trainingBreakTypeId').value = breakTypeId;
        document.getElementById('trainingBreakName').value = breakName;
        document.getElementById('trainingBreakTrainerName').value = '';
        document.getElementById('trainingBreakModal').style.display = 'flex';
    }

    function closeTrainingBreakModal() {
        const modal = document.getElementById('trainingBreakModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function saveTrainingBreak() {
        const trainerName = document.getElementById('trainingBreakTrainerName').value.trim();
        const breakTypeId = document.getElementById('trainingBreakTypeId').value;
        const breakName = document.getElementById('trainingBreakName').value;

        if (!trainerName) {
            ModalSystem.warning('Please enter the Trainer / Training Name before starting the training break.');
            return;
        }

        const saveBtn = document.getElementById('trainingBreakModal').querySelector('.btn.primary');
        if (saveBtn) saveBtn.disabled = true;

        closeTrainingBreakModal();
        // Only after the trainer name is provided does the break start. The trainer name is sent
        // with the BREAK_START request and stored on the break record.
        startBreak(breakTypeId, breakName, trainerName);
    }

    function startBreak(breakTypeId, breakName, reason = null) {
        closeBreakSelectionModal();
        
        // Record BREAK_START punch (reason included for break types that require it,
        // e.g. Official Break)
        const payload = { action: 'break_start', break_type_id: breakTypeId };
        if (reason) {
            payload.reason = reason;
        }
        fetch('api/clock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update break widget to show on-break state
                updateBreakWidget(true, breakTypeId, breakName);
                ModalSystem.success(`Started ${breakName} break`);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not start break'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ModalSystem.error('Error starting break');
        });
    }

    function endBreak(breakTypeId, breakName) {
        // Record BREAK_END punch
        fetch('api/clock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'break_end', break_type_id: breakTypeId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update break widget to show take-break state
                updateBreakWidget(false);
                ModalSystem.success(`Ended ${breakName} break`);
            } else {
                ModalSystem.error('Error: ' + (data.message || 'Could not end break'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ModalSystem.error('Error ending break');
        });
    }

    function updateBreakWidget(onBreak, breakTypeId = null, breakName = '', startAt = null) {
        const widget = document.getElementById('breakWidget');
        const timeDisplay = document.getElementById('breakDuration');
        const btn = document.getElementById('breakBtn');

        if (onBreak) {
            widget.classList.add('on-break');
            btn.textContent = 'End Break';
            btn.onclick = () => endBreak(breakTypeId, breakName);
            // Start break duration timer. Prefer the server-recorded break start (UTC) when
            // provided (page-reload case); otherwise fall back to the local clock (freshly
            // punched break).
            window.breakStartAt = startAt || new Date();
            updateBreakDuration();
        } else {
            widget.classList.remove('on-break');
            btn.textContent = 'Take Break';
            btn.onclick = openBreakModal;
            // Don't reset the duration - keep showing the accumulated break time
            window.breakStartAt = null;
            // Update base break time for Net Time calculation when break ends
            fetch('api/clock.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.total_break_minutes) {
                    baseBreakSeconds = data.total_break_minutes * 60;
                }
                // Also refresh idle total + settings so Net Time stays correct
                if (data.success && typeof data.total_idle_minutes === 'number') {
                    baseIdleSeconds = data.total_idle_minutes * 60;
                }
                if (data.success && data.idle_settings) {
                    idleSettings = data.idle_settings;
                }
            });
        }
    }

    function updateBreakDuration() {
        if (!window.breakStartAt) return;
        
        const now = new Date();
        const diff = Math.floor((now - window.breakStartAt) / 1000);
        const hours = Math.floor(diff / 3600);
        const minutes = Math.floor((diff % 3600) / 60);
        const seconds = diff % 60;
        
        const timeDisplay = document.getElementById('breakDuration');
        timeDisplay.textContent = 
            String(hours).padStart(2, '0') + ':' + 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        
        setTimeout(updateBreakDuration, 1000);
    }
// ========================================================================
    // Idle Time Tracking
    // Detects inactivity at the OS level (Electron powerMonitor) or via page
    // activity (browser fallback), checks once per minute, records idle periods
    // to the database, and auto clocks-out / logs-out after the configured idle
    // timeout. Tracking runs ONLY while the employee status is "currently in"
    // (clocked in and not on a break).
    // ========================================================================

    function resetIdleActivity() {
        hrmsLastActivityAt = Date.now();
    }

    // Browser fallback: any interaction resets the local idle "clock"
    function bindIdleActivityEvents() {
        var ie = ['mousemove', 'mousedown', 'keydown', 'click', 'touchstart', 'scroll', 'wheel'];
        ie.forEach(function(ev) {
            document.addEventListener(ev, resetIdleActivity, { passive: true });
        });
    }
    bindIdleActivityEvents();

    // Electron: sample the OS-level system idle time (any mouse/keyboard input
    // anywhere on the OS resets it). Runs every 15s for display freshness; the
    // server-side check still runs once per minute only.
    async function pollOsIdle() {
        if (!hrmsIsDesktop) return;
        try {
            hrmsOsIdleSeconds = await window.hrmsDesktop.getSystemIdleTime();
            hrmsOsIdleAt = Date.now();
        } catch (e) {
            hrmsOsIdleSeconds = 0;
        }
    }

    function doIdleHeartbeat(idleSeconds) {
        var payload = JSON.stringify({
            action: 'heartbeat',
            idle_seconds: idleSeconds,
            source: hrmsIsDesktop ? 'desktop' : 'web'
        });

        fetch('api/idle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: payload,
            keepalive: true
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.action === 'auto_logout') {
                // Idle timeout reached: server clocked out + destroyed the session
                if (data.message) ModalSystem.warning(data.message);
                setTimeout(function() {
                    window.location.href = 'login.php?reason=idle';
                }, 1500);
                return;
            }
            if (typeof data.today_total_idle_minutes === 'number') {
                baseIdleSeconds = data.today_total_idle_minutes * 60;
            }
        })
        .catch(function() {
            // Heartbeats are best-effort; the next tick retries
        });
    }

    // Called once per minute (plus on resume/visibility/focus). The server
    // decides whether to open/keep/save an idle period.
    function sendIdleHeartbeat() {
        if (!isClockedIn) return;
        if (window.breakStartAt) {
            // On break: inform the server so it saves any pre-break idle and
            // never counts break time as idle.
            doIdleHeartbeat(0);
            return;
        }
        Promise.resolve()
            .then(function() {
                if (hrmsIsDesktop) return pollOsIdle();
            })
            .then(function() {
                var idle = Math.floor(getCurrentIdleSeconds());
                doIdleHeartbeat(idle);
            });
    }

    // Resume detection: when the user was idle and interacts again, immediately
    // send a heartbeat so the idle period is saved right away.
    document.addEventListener('mousemove', function() {
        var now = Date.now();
        var wasIdle = (now - hrmsLastActivityAt) > 45000;
        hrmsLastActivityAt = now;
        if (wasIdle && isClockedIn && !window.breakStartAt) {
            sendIdleHeartbeat();
        }
    });
    ['mousedown', 'keydown', 'click'].forEach(function(ev) {
        document.addEventListener(ev, function() {
            var now = Date.now();
            var wasIdle = (now - hrmsLastActivityAt) > 45000;
            hrmsLastActivityAt = now;
            if (wasIdle && isClockedIn && !window.breakStartAt) {
                sendIdleHeartbeat();
            }
        });
    });

    // Return to the tab -> sync immediately
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            sendIdleHeartbeat();
        }
    });
    window.addEventListener('focus', function() {
        sendIdleHeartbeat();
    });

    // Closing the tab -> best-effort finalize of an open idle period
    window.addEventListener('beforeunload', function() {
        if (!isClockedIn || window.breakStartAt) return;
        var idle = Math.floor(getCurrentIdleSeconds());
        try {
            navigator.sendBeacon('api/idle.php', new Blob(
                [JSON.stringify({
                    action: 'heartbeat',
                    idle_seconds: idle,
                    source: hrmsIsDesktop ? 'desktop' : 'web'
                })],
                { type: 'application/json' }
            ));
        } catch (e) {
            // ignore
        }
    });

    // Initialise: load idle config + today's total, start the once-per-minute check
    (function initIdleTracking() {
        fetch('api/idle.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (data.settings) idleSettings = data.settings;
                    if (typeof data.today_total_idle_minutes === 'number') {
                        baseIdleSeconds = data.today_total_idle_minutes * 60;
                    }
                    // A previous Windows shutdown/restart/logoff left a stale
                    // attendance session that was just closed & logged out by the
                    // server (api/idle.php GET recovery). Send the user back to
                    // login instead of showing a logged-out dashboard.
                    if (data.recovered_shutdown === true) {
                        window.location.href = 'login.php';
                        return;
                    }
                }
            })
            .catch(function() {});
        if (hrmsIsDesktop) {
            pollOsIdle();
            setInterval(pollOsIdle, 15000);
        }
        // Check idle time once per minute
        hrmsHeartbeatTimer = setInterval(sendIdleHeartbeat, 60000);
    })();

    // ========================================================================
    // Shutdown / app-exit auto clock-out + logout (Electron desktop only).
    // The Electron main process calls window.hrmsShutdownClockout() when the
    // OS shuts down / restarts / logs off or the user quits the app, so the
    // CLOCK_OUT is recorded at the exact moment the machine goes down and the
    // session is destroyed. In a plain web browser this is never invoked and
    // page-close behaviour is completely unchanged.
    // ========================================================================
    var hrmsShutdownClockout = function () {
        if (!hrmsIsDesktop) return Promise.resolve(false);
        hrmsShutdownInProgress = true;
        var payload = JSON.stringify({ action: 'shutdown', source: 'desktop' });
        return fetch('api/idle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: payload,
            keepalive: true
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (data) { return !!(data && data.success); })
        .catch(function () {
            // Best-effort only - the sweep/cron is the server-side fallback.
            return false;
        });
    };
    window.hrmsShutdownClockout = hrmsShutdownClockout;

    // Mark shutdown as in progress when the main process signals it (so the
    // pagehide beacon below only fires for real shutdowns, never for normal
    // navigation / reload inside the desktop app).
    if (hrmsIsDesktop && window.hrmsDesktop && typeof window.hrmsDesktop.onShutdownRequested === 'function') {
        window.hrmsDesktop.onShutdownRequested(function () {
            hrmsShutdownInProgress = true;
        });
    }

    // Fire-and-forget backup beacon: only when the Electron main process
    // signalled a shutdown/quit AND the employee is still clocked in.
    window.addEventListener('pagehide', function () {
        if (!hrmsIsDesktop || !hrmsShutdownInProgress || !isClockedIn) return;
        try {
            navigator.sendBeacon('api/idle.php', new Blob(
                [JSON.stringify({ action: 'shutdown', source: 'desktop' })],
                { type: 'application/json' }
            ));
        } catch (e) { /* ignore */ }
    });
</script>

</body>
</html>


