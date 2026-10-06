<?php
/**
 * Recruitment API
 * Handles candidate CRUD, stage transitions, interviews, offers and hiring
 * Core PHP HRMS Application
 * Section 8 - Recruitment module
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

    // ---- Helpers ----
    $stageIdByName = function ($name) use ($db) {
        $row = $db->fetchOne("SELECT id FROM recruitment_stages WHERE name = ?", [$name]);
        return $row ? (int) $row['id'] : null;
    };

    $currentStage = function ($candidateId) use ($db) {
        $c = $db->fetchOne("SELECT recruitment_stage_id FROM candidates WHERE id = ?", [$candidateId]);
        return $c ? (int) $c['recruitment_stage_id'] : null;
    };

    $moveStage = function ($candidateId, $toStageId, $notes = null) use ($db, $user, $currentStage) {
        $fromStageId = $currentStage($candidateId);
        if ($fromStageId === $toStageId) return;
        $db->update('candidates', ['recruitment_stage_id' => $toStageId, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$candidateId]);
        $db->insert('candidate_stage_history', [
            'candidate_id' => $candidateId,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStageId,
            'changed_by' => $user['id'],
            'notes' => $notes,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        logAudit('candidates', $candidateId, 'stage_change', ['from_stage_id' => $fromStageId], ['to_stage_id' => $toStageId]);
    };

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $action = $data['action'] ?? '';
// ============ GET: lookup employee linked to a candidate (offboarding flow) ============
    // NOTE: must be checked before the generic candidate_id handler below.
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'get_linked_employee' && isset($_GET['candidate_id'])) {
        $id = (int) $_GET['candidate_id'];
        $candidate = $db->fetchOne("SELECT * FROM candidates WHERE id = ?", [$id]);
        if (!$candidate) {
            echo json_encode(['success' => false, 'message' => 'Candidate not found']);
            exit;
        }

        $employee = null;
        // Preferred link: the employee record created during onboarding (onboarding_documents)
        $doc = $db->fetchOne(
            "SELECT employee_id FROM onboarding_documents WHERE candidate_id = ? AND employee_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$id]
        );
        if ($doc && !empty($doc['employee_id'])) {
            $employee = $db->fetchOne(
                "SELECT id, employee_id, first_name, last_name, official_email, last_working_day
                 FROM employees WHERE id = ?",
                [(int) $doc['employee_id']]
            );
        }
        // Fallback: an existing employee whose email matches the candidate
        if (!$employee) {
            $employee = $db->fetchOne(
                "SELECT id, employee_id, first_name, last_name, official_email, last_working_day
                 FROM employees WHERE official_email = ? OR personal_email = ? ORDER BY id LIMIT 1",
                [$candidate['email'], $candidate['email']]
            );
        }

        $candidateStage = null;
        if ($candidate) {
            $st = $db->fetchOne(
                "SELECT rs.name FROM recruitment_stages rs WHERE rs.id = ?",
                [$candidate['recruitment_stage_id']]
            );
            $candidateStage = $st ? $st['name'] : null;
        }

        if ($employee) {
            echo json_encode(['success' => true, 'employee' => $employee, 'candidate_stage' => $candidateStage]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No linked employee found for this candidate']);
        }
        exit;
    }

    // ============ GET: screening round details for a candidate ============
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'get_screening_details' && isset($_GET['candidate_id'])) {
        $id = (int) $_GET['candidate_id'];
        $candidate = $db->fetchOne("SELECT id FROM candidates WHERE id = ?", [$id]);
        if (!$candidate) {
            echo json_encode(['success' => false, 'message' => 'Candidate not found']);
            exit;
        }
        $screening = $db->fetchOne(
            "SELECT * FROM screening_rounds WHERE candidate_id = ? ORDER BY id DESC LIMIT 1",
            [$id]
        );
        echo json_encode(['success' => true, 'screening' => $screening ?: null]);
        exit;
    }

    // ============ GET: interview rounds for a candidate ============
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'get_interview_rounds' && isset($_GET['candidate_id'])) {
        $id = (int) $_GET['candidate_id'];
        $candidate = $db->fetchOne("SELECT id FROM candidates WHERE id = ?", [$id]);
        if (!$candidate) {
            echo json_encode(['success' => false, 'message' => 'Candidate not found']);
            exit;
        }
        $rounds = $db->fetchAll(
            "SELECT ir.*, e.first_name as interviewer_first, e.last_name as interviewer_last
             FROM interview_rounds ir
             LEFT JOIN employees e ON ir.interviewer_id = e.id
             WHERE ir.candidate_id = ?
             ORDER BY ir.round_type, ir.round_number, ir.id",
            [$id]
        );
        echo json_encode(['success' => true, 'rounds' => $rounds]);
        exit;
    }

    // ============ GET: single candidate with history ============
    if ($method === 'GET' && isset($_GET['candidate_id'])) {
        $id = (int) $_GET['candidate_id'];
        $candidate = $db->fetchOne(
            "SELECT c.*, rs.name as stage_name, e.first_name as recruiter_first, e.last_name as recruiter_last,
                    se.first_name as source_by_first, se.last_name as source_by_last
             FROM candidates c
             LEFT JOIN recruitment_stages rs ON c.recruitment_stage_id = rs.id
             LEFT JOIN employees e ON c.assigned_recruiter_id = e.id
             LEFT JOIN employees se ON c.source_by_id = se.id
             WHERE c.id = ?",
            [$id]
        );
        if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }
        $history = $db->fetchAll(
            "SELECT h.*, fs.name as from_stage, ts.name as to_stage, e.first_name, e.last_name
             FROM candidate_stage_history h
             LEFT JOIN recruitment_stages fs ON h.from_stage_id = fs.id
             LEFT JOIN recruitment_stages ts ON h.to_stage_id = ts.id
             LEFT JOIN employees e ON h.changed_by = e.id
             WHERE h.candidate_id = ?
             ORDER BY h.created_at DESC",
            [$id]
        );
        $candidate['history'] = $history;
        echo json_encode(['success' => true, 'candidate' => $candidate]);
        exit;
    }

    // ============ POST handlers ============
    if ($method === 'POST') {

        // ---- Create / update candidate ----
        if ($action === 'save') {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $stageId = $stageIdByName($data['stage'] ?? 'Sourcing') ?: $stageIdByName('Sourcing');
            $candidateData = [
                'first_name' => trim($data['first_name'] ?? ''),
                'last_name' => trim($data['last_name'] ?? ''),
                'email' => trim($data['email'] ?? ''),
                'phone' => trim($data['phone'] ?? ''),
                'position_applied' => $data['position_applied'] ?? null,
                'source' => $data['source'] ?? null,
                'location' => trim($data['location'] ?? '') ?: null,
                'relocate' => in_array($data['relocate'] ?? null, ['Yes', 'No'], true) ? $data['relocate'] : null,
                'recruitment_stage_id' => $stageId,
                'source_by_id' => !empty($data['source_by_id']) ? (int) $data['source_by_id'] : null,
                'assigned_recruiter_id' => !empty($data['assigned_recruiter_id']) ? (int) $data['assigned_recruiter_id'] : null,
                'notes' => $data['notes'] ?? null,
                'expected_salary' => !empty($data['expected_salary']) ? $data['expected_salary'] : null,
                'current_salary' => !empty($data['current_salary']) ? $data['current_salary'] : null,
                'notice_period' => !empty($data['notice_period']) ? (int) $data['notice_period'] : null,
                'candidate_type' => $data['candidate_type'] ?? 'Fresher'
            ];
            if (empty($candidateData['first_name'])) {
                echo json_encode(['success' => false, 'message' => 'First name is required']); exit;
            }
            if ($id) {
                $db->update('candidates', $candidateData, 'id = ?', [$id]);
                logAudit('candidates', $id, 'update', null, $candidateData);
                echo json_encode(['success' => true, 'candidate_id' => $id, 'message' => 'Candidate updated successfully']);
            } else {
                $newId = $db->insert('candidates', array_merge($candidateData, ['created_at' => date('Y-m-d H:i:s')]));
                // Log initial stage creation
                $db->insert('candidate_stage_history', [
                    'candidate_id' => $newId, 'from_stage_id' => null, 'to_stage_id' => $stageId,
                    'changed_by' => $user['id'], 'notes' => 'Candidate added', 'created_at' => date('Y-m-d H:i:s')
                ]);
                logAudit('candidates', $newId, 'create', null, $candidateData);
                echo json_encode(['success' => true, 'candidate_id' => $newId, 'message' => 'Candidate added successfully']);
            }
            exit;
        }

        // ---- Delete candidate ----
        if ($action === 'delete') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            
            // Check if candidate has any related data that should be preserved
            $hasInterviews = $db->fetchOne("SELECT COUNT(*) as count FROM interviews WHERE candidate_id = ?", [$id]);
            $hasStageHistory = $db->fetchOne("SELECT COUNT(*) as count FROM candidate_stage_history WHERE candidate_id = ?", [$id]);
            
            // Delete related data first
            $db->delete('candidate_stage_history', 'candidate_id = ?', [$id]);
            
            // Delete the candidate
            $db->delete('candidates', 'id = ?', [$id]);
            logAudit('candidates', $id, 'delete', null, null);
            echo json_encode(['success' => true, 'message' => 'Candidate deleted']);
            exit;
        }
// ---- Stage transition actions ----
        if (in_array($action, ['advance', 'blacklist', 'remove_blacklist', 'hire'], true)) {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $notes = $data['notes'] ?? '';

            if ($action === 'blacklist') $toStage = 'Blacklisted';
            elseif ($action === 'remove_blacklist') $toStage = 'Sourcing';
            elseif ($action === 'hire') $toStage = 'Onboarding';
            else $toStage = $data['to_stage'] ?? 'Screening'; // advance

            $toStageId = $stageIdByName($toStage);
            if (!$toStageId) { echo json_encode(['success' => false, 'message' => 'Invalid target stage']); exit; }
            $moveStage($id, $toStageId, $notes ?: null);
            echo json_encode(['success' => true, 'message' => 'Candidate moved to ' . $toStage]);
            exit;
        }

        // ---- Save screening round (create or update, then move to Screening stage) ----
        if ($action === 'save_screening') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $candidate = $db->fetchOne("SELECT id FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }

            $screeningData = [
                'candidate_id' => $id,
                'screening_date' => !empty($data['screening_date']) ? $data['screening_date'] : null,
                'screening_time' => !empty($data['screening_time']) ? $data['screening_time'] : null,
                'recruiter_id' => !empty($data['recruiter_id']) ? (int) $data['recruiter_id'] : null,
                'screening_status' => $data['screening_status'] ?? 'Screening Scheduled',
                'screening_notes' => $data['screening_notes'] ?? null,
                'screening_remarks' => $data['screening_remarks'] ?? null
            ];

            $existing = $db->fetchOne(
                "SELECT id FROM screening_rounds WHERE candidate_id = ? ORDER BY id DESC LIMIT 1",
                [$id]
            );
            if ($existing) {
                $db->update('screening_rounds', array_merge($screeningData, ['updated_at' => date('Y-m-d H:i:s')]), 'id = ?', [$existing['id']]);
            } else {
                $db->insert('screening_rounds', array_merge($screeningData, [
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]));
            }

            // Move candidate to Screening stage (no-op if already there)
            $moveStage($id, $stageIdByName('Screening'), 'Screening scheduled');

            logAudit('candidates', $id, 'save_screening', null, $screeningData);
            echo json_encode(['success' => true, 'message' => 'Screening saved successfully']);
            exit;
        }

        // ---- Save interview round (create or update) ----
        if ($action === 'save_interview_round' || $action === 'update_interview_round') {
            $id = (int) ($data['candidate_id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $candidate = $db->fetchOne("SELECT id FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }

            $roundData = [
                'interview_status' => $data['interview_status'] ?? 'Scheduled',
                'interview_feedback' => $data['interview_feedback'] ?? null,
                'interview_remarks' => $data['interview_remarks'] ?? null,
                'next_stage' => $data['next_stage'] ?? null,
                'interview_date' => !empty($data['interview_date']) ? $data['interview_date'] : null,
                'interview_time' => !empty($data['interview_time']) ? $data['interview_time'] : null,
                'interviewer_id' => !empty($data['interviewer_id']) ? (int) $data['interviewer_id'] : null,
                'round_name' => $data['round_name'] ?? null
            ];

            if ($action === 'save_interview_round') {
                // New interview round
                $roundData = array_merge($roundData, [
                    'candidate_id' => $id,
                    'round_type' => in_array($data['round_type'] ?? null, ['Technical', 'HR'], true) ? $data['round_type'] : 'Technical',
                    'round_number' => !empty($data['round_number']) ? (int) $data['round_number'] : 1,
                    'previous_stage_feedback' => $data['previous_stage_feedback'] ?? null,
                    'previous_stage_remarks' => $data['previous_stage_remarks'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $db->insert('interview_rounds', $roundData);

                // Move candidate to Interview stage (no-op if already there)
                $moveStage($id, $stageIdByName('Interview'), 'Interview round scheduled');
            } else {
                // Update existing round
                $roundId = (int) ($data['round_id'] ?? 0);
                if (!$roundId) { echo json_encode(['success' => false, 'message' => 'Round ID required']); exit; }
                $existing = $db->fetchOne("SELECT id FROM interview_rounds WHERE id = ? AND candidate_id = ?", [$roundId, $id]);
                if (!$existing) { echo json_encode(['success' => false, 'message' => 'Interview round not found']); exit; }
                $db->update('interview_rounds', array_merge($roundData, ['updated_at' => date('Y-m-d H:i:s')]), 'id = ?', [$roundId]);
            }

            logAudit('candidates', $id, $action, null, $data);
            echo json_encode(['success' => true, 'message' => 'Interview round saved successfully']);
            exit;
        }

        // ---- Offboard candidate: save Last Working Day + move to Offboarding stage ----
        if ($action === 'offboard_candidate') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $candidate = $db->fetchOne("SELECT * FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }

            $employeeId = (int) ($data['employee_id'] ?? 0);
            if (!$employeeId) {
                echo json_encode(['success' => false, 'message' => 'No linked employee found for this candidate. Use "Add as Employee" to create the employee first.']);
                exit;
            }

            // Last Working Day is mandatory
            $lwd = trim($data['last_working_day'] ?? '');
            if (empty($lwd)) {
                echo json_encode(['success' => false, 'message' => 'Last Working Day is required']);
                exit;
            }
            // Validate it is a real calendar date (YYYY-MM-DD) - rejects e.g. 2026-02-30
            $lwdTs = strtotime($lwd);
            $lwdNormalized = $lwdTs ? date('Y-m-d', $lwdTs) : '';
            if (!$lwdTs || $lwdNormalized !== $lwd) {
                echo json_encode(['success' => false, 'message' => 'Please provide a valid Last Working Day date (YYYY-MM-DD).']);
                exit;
            }

            // Prevent duplicate offboarding actions
            $offboardingStageId = $stageIdByName('Offboarding');
            if ((int) $candidate['recruitment_stage_id'] === $offboardingStageId) {
                echo json_encode(['success' => false, 'message' => 'This candidate is already offboarded.']);
                exit;
            }

            // Verify the employee exists and is actually linked to this candidate
            $employee = $db->fetchOne("SELECT id, employee_id, first_name, last_name FROM employees WHERE id = ?", [$employeeId]);
            if (!$employee) {
                echo json_encode(['success' => false, 'message' => 'Linked employee record not found']);
                exit;
            }
            $linkedDoc = $db->fetchOne(
                "SELECT employee_id FROM onboarding_documents WHERE candidate_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1",
                [$id, $employeeId]
            );
            if (!$linkedDoc) {
                $emailMatch = $db->fetchOne(
                    "SELECT id FROM employees WHERE id = ? AND (official_email = ? OR personal_email = ?)",
                    [$employeeId, $candidate['email'], $candidate['email']]
                );
                if (!$emailMatch) {
                    echo json_encode(['success' => false, 'message' => 'Employee is not linked to this candidate']);
                    exit;
                }
            }

            // 1) Save the Last Working Day on the employee record (idempotent, only this field)
            $db->update('employees', ['last_working_day' => $lwdNormalized, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$employeeId]);

            // 2) Mark the employee as offboarded / move candidate to Offboarding stage
            //    using the existing stage-transition workflow (keeps audit history + stage log).
            $moveStage($id, $offboardingStageId, 'Offboarded – Last Working Day ' . $lwdNormalized);

            logAudit('candidates', $id, 'offboard_candidate', null, ['employee_id' => $employeeId, 'last_working_day' => $lwdNormalized]);
            echo json_encode(['success' => true, 'message' => 'Employee offboarded successfully']);
            exit;
        }

        // ---- Schedule interview (create a task + advance to Interview) ----
        if ($action === 'schedule_interview') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $interviewDateTime = $data['interview_datetime'] ?? '';
            $interviewerId = !empty($data['interviewer_id']) ? (int) $data['interviewer_id'] : null;
            $candidate = $db->fetchOne("SELECT first_name, last_name, position_applied FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }

            // Move candidate to Interview stage
            $interviewStageId = $stageIdByName('Interview');
            if ($currentStage($id) !== $interviewStageId) {
                $moveStage($id, $interviewStageId, 'Interview scheduled');
            }

            // Create a task for the interviewer to track the interview
            $title = 'Interview: ' . $candidate['first_name'] . ' ' . $candidate['last_name'] . ' for ' . ($candidate['position_applied'] ?? 'Position');
            $db->insert('tasks', [
                'title' => $title,
                'description' => 'Interview scheduled for ' . ($interviewDateTime ?: 'TBD') . ' with ' . $candidate['first_name'] . ' ' . $candidate['last_name'] . '.',
                'assigned_by' => $user['id'],
                'deadline' => !empty($interviewDateTime) ? date('Y-m-d H:i:s', strtotime($interviewDateTime)) : null,
                'task_status_id' => $db->fetchOne("SELECT id FROM task_statuses WHERE name='Pending'")['id'] ?? null,
                'task_priority_id' => $db->fetchOne("SELECT id FROM task_priorities WHERE name='High'")['id'] ?? null
            ]);
            if ($interviewerId) {
                $taskId = $db->fetchOne("SELECT MAX(id) as id FROM tasks WHERE assigned_by = ? AND title = ?", [$user['id'], $title])['id'];
                $db->insert('task_assignments', ['task_id' => $taskId, 'assignee_id' => $interviewerId, 'created_at' => date('Y-m-d H:i:s')]);
                sendNotification($interviewerId, 'task', 'Interview Scheduled', $title, '?module=tasks&tab=my');
            }

            logAudit('candidates', $id, 'schedule_interview', null, $data);
            echo json_encode(['success' => true, 'message' => 'Interview scheduled']);
            exit;
        }

        // ---- Send offer (advance to Onboarding) ----
        if ($action === 'send_offer') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $candidate = $db->fetchOne("SELECT * FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }
            $moveStage($id, $stageIdByName('Onboarding'), 'Offer sent');
            logAudit('candidates', $id, 'send_offer', null, null);
            echo json_encode(['success' => true, 'message' => 'Offer sent to candidate']);
            exit;
        }

        // ---- Convert candidate to employee ----
        if ($action === 'add_as_employee') {
            $id = (int) ($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Candidate ID required']); exit; }
            $candidate = $db->fetchOne("SELECT * FROM candidates WHERE id = ?", [$id]);
            if (!$candidate) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }

            // Check for duplicate email
            $dup = $db->fetchOne("SELECT id FROM employees WHERE official_email = ? OR personal_email = ?", [$candidate['email'], $candidate['email']]);
            if ($dup) {
                echo json_encode(['success' => false, 'message' => 'An employee with this email already exists']); exit;
            }

            $roleId = $data['role_id'] ?? null;
            $departmentId = $data['department_id'] ?? null;
            $joiningDate = $data['date_of_joining'] ?? date('Y-m-d');

            $employeeData = [
                'employee_id' => generateEmployeeId(),
                'first_name' => $candidate['first_name'],
                'last_name' => $candidate['last_name'],
                'official_email' => $candidate['email'],
                'personal_email' => $candidate['email'],
                'phone_number' => $candidate['phone'],
                'position' => $candidate['position_applied'],
                'date_of_joining' => $joiningDate,
                'role_id' => !empty($roleId) ? (int) $roleId : null,
                'working_days_config' => 'Mon-Fri',
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $empId = $db->insert('employees', $employeeData);

            // Assign primary department if provided
            if (!empty($departmentId)) {
                $db->insert('employee_departments', [
                    'employee_id' => $empId, 'department_id' => (int) $departmentId, 'is_primary' => 1, 'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Move candidate to Onboarding stage
            $moveStage($id, $stageIdByName('Onboarding'), 'Converted to employee');
            logAudit('candidates', $id, 'add_as_employee', null, ['employee_id' => $empId]);
            echo json_encode(['success' => true, 'employee_id' => $empId, 'candidate_type' => $candidate['candidate_type'] ?? 'Fresher', 'message' => 'Candidate converted to employee']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}