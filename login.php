<?php
/**
 * Login Page
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    redirect(BASE_URL . '/index.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            redirect(BASE_URL . '/index.php');
        } else {
            $error = $result['message'];
        }
    }
}

if (isset($_GET['timeout'])) {
    $success = 'Your session has expired. Please login again.';
}

if (isset($_GET['reason']) && $_GET['reason'] === 'idle') {
    $success = 'You were logged out because your system was idle for too long. Please login again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #F6F5F1;
            --surface: #FFFFFF;
            --ink: #191B1F;
            --muted: #6B6E76;
            --faint: #9A9CA3;
            --border: #E7E4DC;
            --accent: #5B4EE5;
            --accent-dark: #4038B0;
            --success: #1F9E6D;
            --danger: #D65C5C;
            --radius: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(25,27,31,0.1);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
            justify-content: center;
        }
        .brand-mark {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--accent);
            position: relative;
        }
        .brand-mark::after {
            content: '';
            position: absolute;
            inset: 10px;
            border-radius: 6px;
            background: var(--surface);
        }
        .brand-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }
        .brand-sub {
            font-size: 11px;
            color: var(--faint);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter';
            background: var(--bg);
            color: var(--ink);
            outline: none;
            transition: border-color 0.15s;
        }
        .form-group input:focus {
            border-color: var(--accent);
            background: var(--surface);
        }
        .btn {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            border: none;
            color: #fff;
            background: var(--accent);
            transition: background 0.15s;
            font-family: 'Inter';
        }
        .btn:hover {
            background: var(--accent-dark);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .alert.error {
            background: #FBEAEA;
            color: #A33A3A;
            border: 1px solid #F0C9C9;
        }
        .alert.success {
            background: #E7F5EE;
            color: #146B47;
            border: 1px solid #C5E8D6;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--faint);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand">
            <div class="brand-mark"></div>
            <div>
                <div class="brand-name">Orbit HRMS</div>
                <div class="brand-sub">Human Resources</div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert error">
                <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert success">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Sign In</button>
        </form>
        
        <div class="footer">
            <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?>
        </div>
    </div>
</body>
</html>
