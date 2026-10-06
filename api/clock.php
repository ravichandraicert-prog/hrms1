<?php
/**
 * Clock In/Out API Endpoint
 * Core PHP HRMS Application
 * Section 4 - Global clock widget
 */

// Disable error reporting first to prevent any HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Load config first to get session settings
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Load database and attendance class
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/attendance.php';
    
    $attendance = new Attendance();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // Fallback to POST data if JSON fails
            $data = $_POST;
        }
        $action = $data['action'] ?? '';
        
        if ($action === 'clock_in' || $action === 'clock_out') {
            // Idle-time integration (keeps existing punch behavior unchanged):
            //  - clock_in:  start fresh, drop any stale open idle marker
            //  - clock_out: save any open idle period before the session closes
            if ($action === 'clock_in') {
                unset($_SESSION['hrms_idle_start_utc'], $_SESSION['hrms_idle_attendance_date']);
            } else {
                $attendance->finalizeOpenIdlePeriod($userId, null, 'web');
            }

            // Use the Attendance class to record punch with proper shift-based date calculation
            $result = $attendance->recordPunch($userId, strtoupper($action));
            
            if ($result['success']) {
                $message = ucfirst(str_replace('_', ' ', $action)) . ' successfully';
                
                // Get current status to check if user is on break
                $status = $attendance->getCurrentStatus($userId);
                
                // Get updated totals using the shift-relative attendance_date returned by
                // recordPunch() - NOT date('Y-m-d'). A night-shift punch after midnight belongs
                // to the shift's primary date (e.g. 05-Sep), not the wall-clock date.
                $db = Database::getInstance();
                $attendanceDate = $result['attendance_date'] ?? date('Y-m-d');
                $todayAttendance = $db->fetchOne(
                    "SELECT total_worked_minutes, total_break_minutes, idle_minutes
                     FROM attendance_daily 
                     WHERE employee_id = ? AND attendance_date = ?",
                    [$userId, $attendanceDate]
                );
                
                $totalWorkedMinutes = $todayAttendance['total_worked_minutes'] ?? 0;
                $totalBreakMinutes = $todayAttendance['total_break_minutes'] ?? 0;
                $totalIdleMinutes = $todayAttendance['idle_minutes'] ?? 0;
                
                $response = [
                    'success' => true,
                    'message' => $message,
                    'attendance_date' => $result['attendance_date'],
                    'is_review_flagged' => $result['is_review_flagged'],
                    'total_worked_minutes' => $totalWorkedMinutes,
                    'total_break_minutes' => $totalBreakMinutes,
                    'total_idle_minutes' => $totalIdleMinutes,
                    'idle_settings' => $attendance->getIdleSettings(),
                    'net_worked_minutes' => max(0, $totalWorkedMinutes - $totalBreakMinutes - $totalIdleMinutes),
                    'break_deduct_from_work_time' => $status['break_deduct_from_work_time'] ?? 1
                ];
                
                // Add warning if punch was flagged for review
                if ($result['is_review_flagged']) {
                    $response['warning'] = 'Punch was flagged for review as it falls outside expected shift hours';
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
        } elseif ($action === 'break_start') {
            // Check if user is clocked in
            $status = $attendance->getCurrentStatus($userId);
            if ($status['status'] !== 'in') {
                echo json_encode(['success' => false, 'message' => 'You must be clocked in to start a break']);
                exit;
            }
            
            // Check if already on break
            if ($status['on_break']) {
                echo json_encode(['success' => false, 'message' => 'You are already on a break']);
                exit;
            }
            
            $breakTypeId = (int) ($data['break_type_id'] ?? 0);
            if (!$breakTypeId) {
                echo json_encode(['success' => false, 'message' => 'Break type ID required']);
                exit;
            }

            // Official Break and Training Break require a reason before the break can start.
            // The reason is stored on the BREAK_START punch row and stays attached
            // to the break for the whole lifecycle (end time + duration).
            $db = Database::getInstance();
            $breakType = $db->fetchOne("SELECT id, name, deduct_from_work_time FROM break_types WHERE id = ?", [$breakTypeId]);
            $reason = trim($data['reason'] ?? '');
            $breakNameLower = strtolower($breakType['name'] ?? '');
            $requiresReason = ($breakNameLower === 'official break' || $breakNameLower === 'training break'
                || $breakNameLower === 'meeting' || $breakNameLower === 'meeting break' || str_contains($breakNameLower, 'meeting'));
            if ($breakType && $requiresReason && empty($reason)) {
                $breakTypeName = $breakNameLower === 'official break' ? 'official break' : (str_contains($breakNameLower, 'meeting') ? 'meeting' : 'training break');
                echo json_encode(['success' => false, 'message' => "Please enter the required information before starting the {$breakTypeName}."]);
                exit;
            }

            // Record BREAK_START punch (save any open idle period first so pre-break
            // idle is never lost, and break time never counts as idle)
            $attendance->finalizeOpenIdlePeriod($userId, null, 'web');
            $result = $attendance->recordPunch($userId, 'BREAK_START', $breakTypeId, 'web', null, $reason);
            
            if ($result['success']) {
                $response = [
                    'success' => true, 
                    'message' => 'Break started',
                    'break_deduct_from_work_time' => ($breakType['deduct_from_work_time'] ?? 1)
                ];
                echo json_encode($response);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
        } elseif ($action === 'break_end') {
            // Check if user is on break
            $status = $attendance->getCurrentStatus($userId);
            if (!$status['on_break']) {
                echo json_encode(['success' => false, 'message' => 'You are not currently on a break']);
                exit;
            }
            
            $breakTypeId = (int) ($data['break_type_id'] ?? 0);
            
            // Record BREAK_END punch
            $result = $attendance->recordPunch($userId, 'BREAK_END', $breakTypeId, 'web');
            
            if ($result['success']) {
                echo json_encode(['success' => true, 'message' => 'Break ended']);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get current clock status using Attendance class
        $status = $attendance->getCurrentStatus($userId);
        
        // Read totals from the attendance_daily row tagged to the employee's current/last
        // shift's attendance_date - NOT date('Y-m-d'). Night-shift totals live under the
        // shift's primary date (e.g. 05-Sep) even after the wall clock passes midnight.
        $db = Database::getInstance();
        $attendanceDate = $status['last_punch']['attendance_date'] ?? date('Y-m-d');
        $todayAttendance = $db->fetchOne(
            "SELECT total_worked_minutes, total_break_minutes, idle_minutes, net_worked_minutes
             FROM attendance_daily 
             WHERE employee_id = ? AND attendance_date = ?",
            [$userId, $attendanceDate]
        );
        
        $totalWorkedMinutes = $todayAttendance['total_worked_minutes'] ?? 0;
        $totalBreakMinutes = $todayAttendance['total_break_minutes'] ?? 0;
        $totalIdleMinutes = $todayAttendance['idle_minutes'] ?? 0;
        $netWorkedMinutes = $todayAttendance['net_worked_minutes'] ?? max(0, $totalWorkedMinutes - $totalBreakMinutes);
        
        // If on break, get current break duration.
        // The stored timestamp is UTC and MUST be parsed as UTC - never as the local
        // timezone - otherwise the duration is shifted by the local UTC offset (e.g. +5.5h).
        $currentBreakDuration = 0;
        if ($status['on_break'] && isset($status['break_start'])) {
            $breakStart = new DateTime($status['break_start']['timestamp_utc'], new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $interval = $breakStart->diff($now);
            $currentBreakDuration = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        }
        
        echo json_encode([
            'success' => true, 
            'status' => $status,
            'attendance_date' => $attendanceDate,
            'total_worked_minutes' => $totalWorkedMinutes,
            'total_break_minutes' => $totalBreakMinutes,
            'total_idle_minutes' => $totalIdleMinutes,
            'net_worked_minutes' => $netWorkedMinutes,
            'idle_settings' => $attendance->getIdleSettings(),
            'current_break_duration' => $currentBreakDuration,
            'break_deduct_from_work_time' => $status['break_deduct_from_work_time'] ?? 1
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

