<?php
/**
 * Task Occurrences API
 * Handles CRUD operations for task occurrences (individual instances of recurring tasks)
 * Core PHP HRMS Application
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/recurring_task_service.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();

    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $parentTaskId = isset($_GET['parent_task_id']) ? (int) $_GET['parent_task_id'] : null;

    $recurringService = new RecurringTaskService();

    switch ($method) {
        case 'GET':
            if (!hasPermission('tasks', 'read')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            if ($id) {
                // Get single occurrence
                $occurrence = $db->fetchOne(
                    "SELECT to.*, 
                            e.first_name, e.last_name, e.employee_id,
                            t.title as task_title, t.description as task_description,
                            tp.name as priority_name,
                            ab.first_name as assigned_by_first, ab.last_name as assigned_by_last
                     FROM task_occurrences to
                     JOIN employees e ON to.assignee_id = e.id
                     JOIN tasks t ON to.parent_task_id = t.id
                     LEFT JOIN task_priorities tp ON t.task_priority_id = tp.id
                     LEFT JOIN employees ab ON t.assigned_by = ab.id
                     WHERE to.id = ?",
                    [$id]
                );
                
                if (!$occurrence) {
                    jsonResponse(['success' => false, 'message' => 'Occurrence not found'], 404);
                }
                
                jsonResponse(['success' => true, 'occurrence' => $occurrence]);
            } elseif ($parentTaskId) {
                // Get all occurrences for a parent task
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
                $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
                $status = $_GET['status'] ?? '';
                
                $occurrences = $recurringService->getOccurrences($parentTaskId, $limit, $offset);
                
                // Filter by status if provided
                if ($status) {
                    $occurrences = array_filter($occurrences, function($occ) use ($status) {
                        return $occ['status'] === $status;
                    });
                }
                
                $stats = $recurringService->getOccurrenceStats($parentTaskId);
                
                jsonResponse(['success' => true, 'occurrences' => $occurrences, 'stats' => $stats]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Occurrence ID or parent task ID required'], 400);
            }
            break;

        case 'PUT':
            if (!hasPermission('tasks', 'update')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => 'Occurrence ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $action = $data['action'] ?? '';
            
            // Handle different occurrence actions
            switch ($action) {
                case 'complete':
                    $result = $recurringService->updateOccurrenceStatus($id, 'completed', date('Y-m-d H:i:s'));
                    break;
                case 'skip':
                    $result = $recurringService->skipOccurrence($id);
                    break;
                case 'cancel':
                    $result = $recurringService->cancelOccurrence($id);
                    break;
                case 'reschedule':
                    $newDate = $data['new_date'] ?? null;
                    $newTime = $data['new_time'] ?? null;
                    if (!$newDate) {
                        jsonResponse(['success' => false, 'message' => 'New date required for rescheduling'], 400);
                    }
                    $result = $recurringService->rescheduleOccurrence($id, $newDate, $newTime);
                    break;
                case 'pending':
                    $result = $recurringService->updateOccurrenceStatus($id, 'pending');
                    break;
                default:
                    // Standard update
                    $updateData = [];
                    if (isset($data['status'])) {
                        $updateData['status'] = $data['status'];
                        if ($data['status'] === 'completed') {
                            $updateData['completed_at'] = date('Y-m-d H:i:s');
                        }
                    }
                    if (isset($data['occurrence_date'])) {
                        $updateData['occurrence_date'] = $data['occurrence_date'];
                    }
                    if (isset($data['occurrence_time'])) {
                        $updateData['occurrence_time'] = $data['occurrence_time'];
                    }
                    
                    if (!empty($updateData)) {
                        $db->update('task_occurrences', $updateData, 'id = ?', [$id]);
                        $result = ['success' => true, 'message' => 'Occurrence updated'];
                    } else {
                        $result = ['success' => false, 'message' => 'No valid fields to update'];
                    }
            }
            
            jsonResponse($result);
            break;

        case 'DELETE':
            if (!hasPermission('tasks', 'delete')) {
                jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => 'Occurrence ID required'], 400);
            }
            
            $existing = $db->fetchOne("SELECT * FROM task_occurrences WHERE id = ?", [$id]);
            if (!$existing) {
                jsonResponse(['success' => false, 'message' => 'Occurrence not found'], 404);
            }
            
            $db->delete('task_occurrences', 'id = ?', [$id]);
            logAudit('task_occurrences', $id, 'delete', $existing, null);
            jsonResponse(['success' => true, 'message' => 'Occurrence deleted successfully']);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}