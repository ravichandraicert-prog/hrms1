<?php
/**
 * Onboarding API
 * Handles document uploads and onboarding workflow
 * Core PHP HRMS Application
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json');

// Custom upload function for onboarding documents (allows any file type)
function uploadOnboardingDocument($file) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }
    
    // Check file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File size exceeds 10MB limit'];
    }
    
    // Sanitize filename
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $basename = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBasename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    
    $uploadDir = rtrim(UPLOAD_DIR, '/\\') . '/onboarding';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = uniqid() . '_' . $safeBasename . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $filepath];
    }
    
    return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    
    // Handle multipart form data for file uploads
    if ($method === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';
    
    // GET: Fetch uploaded documents for an employee
    if ($method === 'GET' && isset($_GET['employee_id'])) {
        $employeeId = (int) $_GET['employee_id'];
        
        $documents = $db->fetchAll(
            "SELECT od.*, e.first_name as uploader_first, e.last_name as uploader_last
             FROM onboarding_documents od
             LEFT JOIN employees e ON od.uploaded_by = e.id
             WHERE od.employee_id = ?
             ORDER BY od.uploaded_at DESC",
            [$employeeId]
        );
        
        echo json_encode(['success' => true, 'documents' => $documents]);
        exit;
    }
    
    // POST: Upload document
    if ($method === 'POST' && $action === 'upload_document') {
        if (!hasPermission('recruitment', 'update')) {
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        
        $candidateId = (int) ($data['candidate_id'] ?? 0);
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $documentType = trim($data['document_type'] ?? '');
        
        if (!$candidateId || !$employeeId || !$documentType) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Please select a file to upload']);
            exit;
        }
        
        // Upload file - allow any file type for onboarding documents
        $upload = uploadOnboardingDocument($_FILES['document']);
        if (!$upload['success']) {
            echo json_encode(['success' => false, 'message' => $upload['error']]);
            exit;
        }
        
        // Save document record
        $documentData = [
            'employee_id' => $employeeId,
            'candidate_id' => $candidateId,
            'document_type' => $documentType,
            'document_name' => $_FILES['document']['name'],
            'file_path' => $upload['filename'],
            'uploaded_by' => $user['id']
        ];
        
        $documentId = $db->insert('onboarding_documents', $documentData);
        
        logAudit('onboarding_documents', $documentId, 'create', null, $documentData);
        
        echo json_encode(['success' => true, 'document_id' => $documentId, 'message' => 'Document uploaded successfully']);
        exit;
    }
    
    // POST: Delete document
    if ($method === 'POST' && $action === 'delete_document') {
        if (!hasPermission('recruitment', 'update')) {
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        
        $documentId = (int) ($data['id'] ?? 0);
        if (!$documentId) {
            echo json_encode(['success' => false, 'message' => 'Document ID required']);
            exit;
        }
        
        $document = $db->fetchOne("SELECT * FROM onboarding_documents WHERE id = ?", [$documentId]);
        if (!$document) {
            echo json_encode(['success' => false, 'message' => 'Document not found']);
            exit;
        }
        
        // Delete file from filesystem
        $filePath = UPLOAD_DIR . '/onboarding/' . $document['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete database record
        $db->delete('onboarding_documents', 'id = ?', [$documentId]);
        
        logAudit('onboarding_documents', $documentId, 'delete', $document, null);
        
        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
        exit;
    }
    
    // POST: Complete onboarding
    if ($method === 'POST' && $action === 'complete_onboarding') {
        if (!hasPermission('recruitment', 'update')) {
            echo json_encode(['success' => false, 'message' => 'Access denied'], 403);
            exit;
        }
        
        $candidateId = (int) ($data['candidate_id'] ?? 0);
        $employeeId = (int) ($data['employee_id'] ?? 0);
        
        if (!$candidateId || !$employeeId) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        // Move candidate to final stage (can be customized based on workflow)
        $finalStageId = $db->fetchOne("SELECT id FROM recruitment_stages WHERE name = 'Onboarding' LIMIT 1");
        if ($finalStageId) {
            $db->update('candidates', ['recruitment_stage_id' => $finalStageId['id']], 'id = ?', [$candidateId]);
        }
        
        // Log onboarding completion
        logAudit('candidates', $candidateId, 'complete_onboarding', null, ['employee_id' => $employeeId]);
        
        echo json_encode(['success' => true, 'message' => 'Onboarding completed successfully']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>