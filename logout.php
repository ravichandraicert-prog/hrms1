<?php
/**
 * Logout Page
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
$auth->logout();

redirect(BASE_URL . '/login.php');
