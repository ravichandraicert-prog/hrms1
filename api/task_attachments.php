<?php
/**
 * Task Attachments API
 * Handles file upload, retrieval, and deletion for task attachments
 * Core PHP HRMS Application
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();

    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $taskId = isset($_GET['task_id']) ? (int) $_GET['task_id'] : (isset($_POST['task_id']) ? (int) $_POST['task_id'] : null);
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

    // Supported file types
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
        'application/pdf', 
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    // File extensions mapping
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $maxFileSize = 100 * 1024 * 1024; // 100MB

    switch ($method) {
        case 'GET':
            if ($id) {
                // Get single attachment
                $attachment = $db->fetchOne(
                    "SELECT ta.*, 
                            e.first_name, e.last_name, e.employee_id as uploader_emp_id,
                            t.title as task_title
                     FROM task_attachments ta
                     JOIN employees e ON ta.uploaded_by = e.id
                     JOIN tasks t ON ta.task_id = t.id
                     WHERE ta.id = ?",
                    [$id]
                );
                
                if (!$attachment) {
                    jsonResponse(['success' => false, 'message' => 'Attachment not found'], 404);
                }
                
                // Check if user can view this specific attachment
                $canView = hasPermission('tasks', 'read') || 
                          isTaskAssigner($attachment['task_id'], $user['id']) || 
                          isTaskAssignee($attachment['task_id'], $user['id']);
                if (!$canView) {
                    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
                }
                
                jsonResponse(['success' => true, 'attachment' => $attachment]);
            } elseif ($taskId) {
                // Check if user can view attachments for this task
                $canView = hasPermission('tasks', 'read') || 
                          isTaskAssigner($taskId, $user['id']) || 
                          isTaskAssignee($taskId, $user['id']);
                if (!$canView) {
                    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
                }
                
                // Get all attachments for a task
                $attachments = $db->fetchAll(
                    "SELECT ta.*, 
                            e.first_name, e.last_name, e.employee_id as uploader_emp_id
                     FROM task_attachments ta
                     JOIN employees e ON ta.uploaded_by = e.id
                     WHERE ta.task_id = ?
                     ORDER BY ta.created_at DESC",
                    [$taskId]
                );
                
                jsonResponse(['success' => true, 'attachments' => $attachments]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Task ID or Attachment ID required'], 400);
            }
            break;

        case 'POST':
            if (!hasPermission('tasks', 'update')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            if (!$taskId) {
                jsonResponse(['success' => false, 'message' => 'Task ID required'], 400);
            }
            
            // Verify task exists and user has access
            $task = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);
            if (!$task) {
                jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
            }
            
            // Check if user can add attachments to this task
            // Allow if: has general update permission, is assigner, or is assignee
            $isAssigner = isTaskAssigner($taskId, $user['id']);
            $isAssignee = isTaskAssignee($taskId, $user['id']);
            
            if (!hasPermission('tasks', 'update') && !$isAssigner && !$isAssignee) {
                jsonResponse(['success' => false, 'message' => 'You do not have permission to add attachments to this task'], 403);
            }
            
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error'], 400);
            }
            
            $file = $_FILES['file'];
            
            // Validate file size
            if ($file['size'] > $maxFileSize) {
                jsonResponse(['success' => false, 'message' => 'File size exceeds maximum of 100MB'], 400);
            }
            
            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                jsonResponse(['success' => false, 'message' => 'File type not allowed. Allowed types: ' . implode(', ', $allowedExtensions)], 400);
            }
            
            // Validate file extension
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions)) {
                jsonResponse(['success' => false, 'message' => 'File extension not allowed'], 400);
            }
            
            // Generate unique filename (sanitize original filename)
            $fileName = $file['name'];
            $fileBaseName = pathinfo($fileName, PATHINFO_FILENAME);
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            
            // Sanitize filename - remove special characters and spaces
            $sanitizedBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileBaseName);
            $uniqueFileName = $sanitizedBaseName . '_' . time() . '_' . rand(1000, 9999) . '.' . $fileExtension;
            
            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../uploads/tasks/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $filePath = $uploadDir . $uniqueFileName;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                jsonResponse(['success' => false, 'message' => 'Failed to save file'], 500);
            }
            
            // Store attachment in database
            $attachmentData = [
                'task_id' => $taskId,
                'uploaded_by' => $user['id'],
                'file_name' => $fileName,
                'file_path' => 'uploads/tasks/' . $uniqueFileName,
                'file_type' => $mimeType,
                'file_size' => $file['size'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $attachmentId = $db->insert('task_attachments', $attachmentData);
            
            logAudit('task_attachments', $attachmentId, 'create', null, $attachmentData);
            
            jsonResponse([
                'success' => true, 
                'attachment_id' => $attachmentId,
                'message' => 'Attachment uploaded successfully'
            ]);
            break;

        case 'DELETE':
            if (!hasPermission('tasks', 'update')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => 'Attachment ID required'], 400);
            }
            
            $attachment = $db->fetchOne("SELECT * FROM task_attachments WHERE id = ?", [$id]);
            if (!$attachment) {
                jsonResponse(['success' => false, 'message' => 'Attachment not found'], 404);
            }
            
            // Check if user can delete this attachment
            // Allow if: uploaded by user, is assigner, is assignee, or has general update permission
            $isAssigner = isTaskAssigner($attachment['task_id'], $user['id']);
            $isAssignee = isTaskAssignee($attachment['task_id'], $user['id']);
            $canDelete = ($attachment['uploaded_by'] == $user['id']) || $isAssigner || $isAssignee || hasPermission('tasks', 'update');
            
            if (!$canDelete) {
                jsonResponse(['success' => false, 'message' => 'You can only delete your own attachments'], 403);
            }
            
            // Delete file from filesystem
            $filePath = __DIR__ . '/../' . $attachment['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete from database
            $db->delete('task_attachments', 'id = ?', [$id]);
            
            logAudit('task_attachments', $id, 'delete', $attachment, null);
            
            jsonResponse(['success' => true, 'message' => 'Attachment deleted successfully']);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
