<?php
/**
 * Employee API - Dedicated for Onboarding
 * Minimal dependencies to ensure clean JSON output
 */

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

// Load database configuration
require_once __DIR__ . '/../config/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

ob_clean();

function sendJson($success, $message, $data = []) {
    ob_clean();
    $response = array_merge(['success' => $success, 'message' => $message], $data);
    echo json_encode($response);
    ob_end_flush();
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $employeeId = $data['employee_id'] ?? 'EMP-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['official_email'])) {
            sendJson(false, 'First name, last name and official email are required');
        }
        
        // Check for duplicate email
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE official_email = ?");
        $stmt->execute([$data['official_email']]);
        if ($stmt->fetch()) {
            sendJson(false, 'An employee with this official email already exists');
        }
        
        // Check for duplicate username
        if (!empty($data['username'])) {
            $stmt = $pdo->prepare("SELECT id FROM employees WHERE username = ?");
            $stmt->execute([$data['username']]);
            if ($stmt->fetch()) {
                sendJson(false, 'This username is already taken');
            }
        }
        
        // Insert employee
        $sql = "INSERT INTO employees (
            employee_id, first_name, last_name, personal_email, official_email, 
            alternate_email, phone_number, alternate_phone, date_of_birth, gender, 
            marital_status, address, state, city, zip_code, position, 
            reporting_manager_id, date_of_joining, employment_type, working_days_config, 
            role_id, username, bank_name, account_number, ifsc_code, pan_number, 
            is_active, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $employeeId,
            $data['first_name'],
            $data['last_name'],
            !empty($data['personal_email']) ? $data['personal_email'] : null,
            $data['official_email'],
            !empty($data['alternate_email']) ? $data['alternate_email'] : null,
            $data['phone'] ?? null,
            !empty($data['alternate_phone']) ? $data['alternate_phone'] : null,
            !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            !empty($data['gender']) ? $data['gender'] : null,
            !empty($data['marital_status']) ? $data['marital_status'] : null,
            !empty($data['address']) ? $data['address'] : null,
            !empty($data['state']) ? $data['state'] : null,
            !empty($data['city']) ? $data['city'] : null,
            !empty($data['zip_code']) ? $data['zip_code'] : null,
            !empty($data['position']) ? $data['position'] : null,
            !empty($data['reporting_manager_id']) ? $data['reporting_manager_id'] : null,
            !empty($data['date_of_joining']) ? $data['date_of_joining'] : null,
            !empty($data['employment_type']) ? $data['employment_type'] : null,
            !empty($data['working_days_config']) ? $data['working_days_config'] : null,
            !empty($data['role_id']) ? $data['role_id'] : null,
            !empty($data['username']) ? $data['username'] : null,
            !empty($data['bank_name']) ? $data['bank_name'] : null,
            !empty($data['account_number']) ? $data['account_number'] : null,
            !empty($data['ifsc_code']) ? $data['ifsc_code'] : null,
            !empty($data['pan_number']) ? $data['pan_number'] : null,
            $data['is_active'] ?? 1
        ]);
        
        $newEmployeeId = $pdo->lastInsertId();
        
        // Assign to department
        if (!empty($data['department_id'])) {
            $sql = "INSERT INTO employee_departments (employee_id, department_id, is_primary) VALUES (?, ?, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$newEmployeeId, $data['department_id']]);
        }
        
        sendJson(true, 'Employee created successfully', ['id' => $newEmployeeId]);
        break;

    default:
        sendJson(false, 'Method not allowed');
}

ob_end_flush();
exit;
?>