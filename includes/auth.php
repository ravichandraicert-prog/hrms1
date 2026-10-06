<?php
/**
 * Authentication Class
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function login($username, $password) {
        $sql = "SELECT e.*, r.name as role_name, r.hierarchy_level 
                FROM employees e 
                LEFT JOIN roles r ON e.role_id = r.id 
                WHERE (e.username = ? OR e.official_email = ?) 
                AND e.is_active = 1";
        
        $user = $this->db->fetchOne($sql, [$username, $username]);
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if ($password !== $user['password']) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['hierarchy_level'] = $user['hierarchy_level'];
        $_SESSION['last_activity'] = time();
        
        // Update last login
        $this->db->update('employees', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        
        // Log audit
        logAudit('employee', $user['id'], 'login', null, ['last_login' => date('Y-m-d H:i:s')]);
        
        return ['success' => true, 'user' => $user];
    }
    
    public function logout() {
        $userId = getCurrentUserId();
        
        // Log audit
        if ($userId) {
            logAudit('employee', $userId, 'logout', null, null);
        }
        
        // Destroy session
        session_unset();
        session_destroy();
        
        return ['success' => true];
    }
    
    public function requireLogin() {
        if (!isLoggedIn()) {
            redirect(BASE_URL . '/login.php');
        }
        
        // Session timeout disabled - user will stay logged in indefinitely
        $_SESSION['last_activity'] = time();
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->db->fetchOne("SELECT * FROM employees WHERE id = ?", [$userId]);

        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }

        if ($currentPassword !== $user['password']) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters'];
        }

        $this->db->update('employees', ['password' => $newPassword], 'id = ?', [$userId]);

        // Log audit
        logAudit('employee', $userId, 'password_change', null, null);

        return ['success' => true, 'message' => 'Password changed successfully'];
    }

    public function resetPassword($userId, $newPassword) {
        $this->db->update('employees', ['password' => $newPassword], 'id = ?', [$userId]);

        // Log audit
        logAudit('employee', $userId, 'password_reset', null, null);

        return ['success' => true];
    }
    
    public function isSuperAdmin() {
        $user = getCurrentUser();
        return $user && $user['hierarchy_level'] == 1;
    }
    
    public function hasRole($roleName) {
        $user = getCurrentUser();
        if (!$user) return false;
        
        return strtolower($user['role_name']) === strtolower($roleName);
    }
}

