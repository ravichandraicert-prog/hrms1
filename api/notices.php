<?php
/**
 * Notices API
 * Handles notice CRUD, acknowledgement and attachment downloads
 * Core PHP HRMS Application
 * Section 16 - Notice board module
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();
    $db = Database::getInstance();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];

    // ---- Download attachment (streams the file) ----
    if ($method === 'GET' && isset($_GET['download'])) {
        $id = (int) $_GET['download'];
        $notice = $db->fetchOne("SELECT attachment FROM notices WHERE id = ?", [$id]);
        if (!$notice || empty($notice['attachment'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No attachment']);
            exit;
        }
        $filePath = UPLOAD_DIR . 'notices/' . $notice['attachment'];
        if (!file_exists($filePath)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Attachment file not found']);
            exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($notice['attachment']) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    // ---- GET single notice for editing ----
    if ($method === 'GET' && isset($_GET['notice_id'])) {
        $id = (int) $_GET['notice_id'];
        $notice = $db->fetchOne("SELECT * FROM notices WHERE id = ?", [$id]);
        echo json_encode($notice ? ['success' => true, 'notice' => $notice] : ['success' => false, 'message' => 'Notice not found']);
        exit;
    }

    // ---- GET: report as read (acknowledgement of viewing) ----
    if ($method === 'GET' && isset($_GET['mark_read'])) {
        $id = (int) $_GET['mark_read'];
        // Upsert into notice_reads
        $existing = $db->fetchOne("SELECT id FROM notice_reads WHERE notice_id = ? AND employee_id = ?", [$id, $user['id']]);
        if ($existing) {
            $db->update('notice_reads', ['read_at' => date('Y-m-d H:i:s')], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('notice_reads', ['notice_id' => $id, 'employee_id' => $user['id'], 'read_at' => date('Y-m-d H:i:s')]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- Parse JSON or multipart body for write actions ----
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false || !empty($_POST)) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
    }
    $action = $data['action'] ?? '';
if ($method === 'POST') {

        // ---- Save (create / update) notice ----
        if ($action === 'save') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $title = trim($data['title'] ?? '');
            $body = trim($data['body'] ?? '');
            if ($title === '' || $body === '') {
                echo json_encode(['success' => false, 'message' => 'Title and body are required']); exit;
            }
            $noticeData = [
                'title' => $title,
                'body' => $body,
                'priority' => $data['priority'] ?? 'Low',
                'posted_by' => $user['id'],
                'target_type' => $data['target_type'] ?? 'all',
                'target_roles' => !empty($data['target_roles']) ? $data['target_roles'] : null,
                'target_departments' => !empty($data['target_departments']) ? $data['target_departments'] : null,
                'expiry_date' => !empty($data['expiry_date']) ? $data['expiry_date'] : null,
                'require_acknowledgement' => !empty($data['require_acknowledgement']) ? $data['require_acknowledgement'] : 0,
                'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1
            ];

            // Handle optional attachment upload (multipart)
            if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['attachment'], 'notices');
                if ($upload['success']) {
                    $noticeData['attachment'] = $upload['filename'];
                }
            } elseif (isset($data['attachment']) && $data['attachment'] === '') {
                $noticeData['attachment'] = null; // allow clearing attachment
            }

            if ($id) {
                // Detect publish transition (draft -> active) so a notification is
                // generated whenever a notice is published, not just on first create.
                $previous = $db->fetchOne("SELECT is_active FROM notices WHERE id = ?", [$id]);
                $db->update('notices', $noticeData, 'id = ?', [$id]);
                // Re-insert notice_reads behaviour unaffected; log audit
                logAudit('notices', $id, 'update', null, $noticeData);

                // Send notifications only when transitioning from draft to published
                $sentCount = 0;
                if ($previous && (int)$previous['is_active'] === 0 && (int)$noticeData['is_active'] === 1) {
                    try {
                        $sentCount = sendNoticeNotifications($id, $title, $body, $noticeData);
                    } catch (Exception $e) {
                        error_log("Failed to send notice notifications: " . $e->getMessage());
                    }
                }

                $message = 'Notice updated';
                if ($sentCount > 0) {
                    $message .= " (notifications sent to {$sentCount} users)";
                }
                echo json_encode(['success' => true, 'notice_id' => $id, 'message' => $message, 'notifications_sent' => $sentCount]);
            } else {
                $newId = $db->insert('notices', array_merge($noticeData, ['created_at' => date('Y-m-d H:i:s')]));
                logAudit('notices', $newId, 'create', null, $noticeData);

                // Send notifications to targeted users when a new notice is published (active).
                // Drafts (is_active = 0) stay silent until they are published later.
                $sentCount = 0;
                if ((int)$noticeData['is_active'] === 1) {
                    try {
                        $sentCount = sendNoticeNotifications($newId, $title, $body, $noticeData);
                    } catch (Exception $e) {
                        error_log("Failed to send notice notifications: " . $e->getMessage());
                    }
                }

                $message = 'Notice posted';
                if ($sentCount > 0) {
                    $message .= " (notifications sent to {$sentCount} users)";
                } else {
                    $message .= " (no notifications sent)";
                }

                echo json_encode(['success' => true, 'notice_id' => $newId, 'message' => $message, 'notifications_sent' => $sentCount]);
            }
            exit;
        }

        // ---- Delete notice ----
        if ($action === 'delete') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Notice ID required']); exit; }
            $db->delete('notices', 'id = ?', [$id]);
            logAudit('notices', $id, 'delete', null, null);
            echo json_encode(['success' => true, 'message' => 'Notice deleted']);
            exit;
        }

        // ---- Acknowledge notice ----
        if ($action === 'acknowledge') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Notice ID required']); exit; }
            $existing = $db->fetchOne("SELECT id FROM notice_reads WHERE notice_id = ? AND employee_id = ?", [$id, $user['id']]);
            if ($existing) {
                $db->update('notice_reads', ['acknowledged_at' => date('Y-m-d H:i:s'), 'read_at' => date('Y-m-d H:i:s')], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('notice_reads', ['notice_id' => $id, 'employee_id' => $user['id'], 'read_at' => date('Y-m-d H:i:s'), 'acknowledged_at' => date('Y-m-d H:i:s')]);
            }
            echo json_encode(['success' => true, 'message' => 'Notice acknowledged']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}