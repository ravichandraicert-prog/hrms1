<?php
/**
 * Centralized Attendance Threshold Service
 * Core PHP HRMS Application
 * Single source of truth for attendance status calculation
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/work_pattern_service.php';

class AttendanceThresholdService {
    private $db;
    private $workPatternService;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->workPatternService = new WorkPatternService();
    }
    
    /**
     * Get current active attendance threshold settings
     * @return array|false Current threshold settings or false if not found
     */
    public function getCurrentThresholds() {
        try {
            return $this->db->fetchOne(
                "SELECT * FROM attendance_settings 
                 WHERE effective_date <= CURDATE() 
                 ORDER BY effective_date DESC, id DESC 
                 LIMIT 1"
            );
        } catch (Exception $e) {
            // Table doesn't exist yet - ensure it's created and return current settings
            $this->ensureTableExists();
            return $this->getCurrentThresholds();
        }
    }
    
    /**
     * Get threshold settings for a specific date (for historical records)
     * @param string $date The date to get thresholds for
     * @return array|false Threshold settings for that date or false if not found
     */
    public function getThresholdsForDate($date) {
        try {
            return $this->db->fetchOne(
                "SELECT * FROM attendance_settings 
                 WHERE effective_date <= ? 
                 ORDER BY effective_date DESC, id DESC 
                 LIMIT 1",
                [$date]
            );
        } catch (Exception $e) {
            // Table doesn't exist yet - ensure it's created and retry
            $this->ensureTableExists();
            return $this->getThresholdsForDate($date);
        }
    }
    
    /**
     * Get present threshold for a specific date
     * @param string $date The date to get threshold for
     * @return int Present threshold in minutes
     */
    private function getPresentThreshold($date) {
        $thresholds = $this->getThresholdsForDate($date);
        if (!$thresholds) {
            // Ensure table exists and retry
            $this->ensureTableExists();
            $thresholds = $this->getThresholdsForDate($date);
        }
        return (int) ($thresholds['present_threshold_minutes'] ?? 495);
    }

    /**
     * Get half-day threshold for a specific date
     * @param string $date The date to get threshold for
     * @return int Half-day threshold in minutes
     */
    private function getHalfDayThreshold($date) {
        $thresholds = $this->getThresholdsForDate($date);
        if (!$thresholds) {
            // Ensure table exists and retry
            $this->ensureTableExists();
            $thresholds = $this->getThresholdsForDate($date);
        }
        return (int) ($thresholds['half_day_threshold_minutes'] ?? 255);
    }
    
    /**
     * Get the employee's assigned shift id (employees.shift_id).
     * The assigned shift is the single source of truth for which shift-specific
     * attendance thresholds apply to this employee.
     * @param int $employeeId Employee ID
     * @return int|null Shift id, or null when the employee has no assigned shift
     */
    private function getEmployeeShiftId($employeeId) {
        try {
            $emp = $this->db->fetchOne("SELECT shift_id FROM employees WHERE id = ?", [$employeeId]);
            return $emp ? $emp['shift_id'] : null;
        } catch (Exception $e) {
            // Never block attendance status on a failed shift lookup - fall back to defaults
            return null;
        }
    }

    /**
     * Get the per-shift threshold configuration for a shift.
     * @param int $shiftId Shift id
     * @return array|false Shift thresholds (present/half_day/absent minutes), or false when not found
     */
    public function getShiftThresholds($shiftId) {
        if (!$shiftId) {
            return false;
        }
        try {
            return $this->db->fetchOne(
                "SELECT present_threshold_minutes, half_day_threshold_minutes, absent_threshold_minutes
                 FROM shifts WHERE id = ?",
                [$shiftId]
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Resolve the effective Present / Half Day thresholds (in minutes) for an employee's
     * assigned shift on a given attendance date.
     *
     * Shift-wise rules (backward compatible):
     *   1. When the employee's assigned shift has a per-shift Present threshold configured
     *      (present_threshold_minutes IS NOT NULL), ONLY that shift's thresholds are used.
     *      A threshold from any other shift is never applied.
     *   2. Otherwise the organization-wide attendance_settings thresholds apply, preserving
     *      the existing behavior for unconfigured shifts and employees without a shift.
     * @param string $attendanceDate The attendance date for historical threshold lookup
     * @param int|null $shiftId The employee's assigned shift id (null when unassigned)
     * @return array Resolved thresholds: 'present_threshold_minutes', 'half_day_threshold_minutes'
     */
    private function resolveThresholds($attendanceDate, $shiftId) {
        $presentThreshold = null;
        $halfDayThreshold = null;

        if ($shiftId) {
            $shift = $this->getShiftThresholds($shiftId);
            if ($shift && $shift['present_threshold_minutes'] !== null) {
                $presentThreshold = (int) $shift['present_threshold_minutes'];
                if ($shift['half_day_threshold_minutes'] !== null && (int) $shift['half_day_threshold_minutes'] > 0) {
                    $halfDayThreshold = (int) $shift['half_day_threshold_minutes'];
                }
            }
        }

        // Fall back to the organization-wide settings for any threshold not covered by the shift
        if ($presentThreshold === null || $halfDayThreshold === null) {
            $central = $this->getThresholdsForDate($attendanceDate);
            if (!$central) {
                // Ensure table exists and retry - should not happen if the table is properly set up
                $this->ensureTableExists();
                $central = $this->getThresholdsForDate($attendanceDate);
            }
            if ($presentThreshold === null) {
                $presentThreshold = (int) ($central ? ($central['present_threshold_minutes'] ?? 495) : 495); // 8:15
            }
            if ($halfDayThreshold === null) {
                $halfDayThreshold = (int) ($central ? ($central['half_day_threshold_minutes'] ?? 255) : 255); // 4:15
            }
        }

        return [
            'present_threshold_minutes' => $presentThreshold,
            'half_day_threshold_minutes' => $halfDayThreshold,
            'shift_id' => $shiftId
        ];
    }

    /**
     * Calculate attendance status using the thresholds of the employee's assigned shift
     * @param int $netWorkedMinutes Net worked minutes (Work Duration - Total Break Duration)
     * @param string $attendanceDate The attendance date for historical threshold lookup
     * @param int $employeeId Employee ID for leave/holiday checks and shift resolution
     * @return string Attendance status: 'Present', 'Half Day', 'Absent', 'On Leave', 'First Half Leave', 'Second Half Leave', 'Holiday', 'Week Off'
     */
    public function calculateStatus($netWorkedMinutes, $attendanceDate, $employeeId) {
        // netWorkedMinutes is now passed directly (Work Duration - Total Break Duration)
        // No need to recalculate net worked minutes
        
        // Check if on leave (exclude cancelled leaves)
        $leave = $this->db->fetchOne(
            "SELECT * FROM leave_applications 
             WHERE employee_id = ? AND status = 'Approved' 
             AND ? BETWEEN start_date AND end_date",
            [$employeeId, $attendanceDate]
        );
        
        if ($leave) {
            $duration = $leave['duration'] ?? 'full';
            if ($duration === 'first_half') {
                return 'First Half Leave';
            } elseif ($duration === 'second_half') {
                return 'Second Half Leave';
            } else {
                return 'On Leave';
            }
        }
        
        // Check if holiday
        $holiday = $this->db->fetchOne(
            "SELECT h.* FROM holidays h
             JOIN holiday_calendar_assignments hca ON h.holiday_calendar_id = hca.holiday_calendar_id
             WHERE h.date = ? AND (hca.assignable_type = 'all' OR hca.assignable_id = ?)",
            [$attendanceDate, $employeeId]
        );
        
        if ($holiday) {
            return 'Holiday';
        }
        
        // Check if employee has actual attendance records for this date (including manual marks)
        // Changed condition to >= 0 to include manual attendance marks that might have 0 or low worked minutes
        $hasAttendance = $this->db->fetchOne(
            "SELECT id FROM attendance_daily 
             WHERE employee_id = ? AND attendance_date = ? 
             AND total_worked_minutes >= 0",
            [$employeeId, $attendanceDate]
        );
        
        // Resolve the thresholds that apply to this employee.
        // Shift-wise: the thresholds always come from the employee's assigned shift
        // (falling back to the organization-wide defaults for unconfigured shifts).
        $thresholds = $this->resolveThresholds($attendanceDate, $this->getEmployeeShiftId($employeeId));
        $presentThreshold = $thresholds['present_threshold_minutes'];
        $halfDayThreshold = $thresholds['half_day_threshold_minutes'];

        // If employee has actual attendance records (including manual marks), apply threshold logic
        if ($hasAttendance || $netWorkedMinutes > 0) {
            // Apply the threshold logic as per requirements:
            // Net Time >= Present threshold → PRESENT
            // Net Time >= Half Day threshold AND < Present threshold → HALF DAY
            // Net Time < Half Day threshold → ABSENT
            if ($netWorkedMinutes >= $presentThreshold) {
                return 'Present';
            } elseif ($netWorkedMinutes >= $halfDayThreshold) {
                return 'Half Day';
            } else {
                return 'Absent';
            }
        }
        
        // No attendance records - check if week off using work pattern service
        if (!$this->workPatternService->isWorkingDay($employeeId, $attendanceDate)) {
            return 'Week Off';
        }
        
        // Apply the same threshold logic for regular weekdays without attendance
        if ($netWorkedMinutes >= $presentThreshold) {
            return 'Present';
        } elseif ($netWorkedMinutes >= $halfDayThreshold) {
            return 'Half Day';
        } else {
            return 'Absent';
        }
    }
    
    /**
     * Update threshold settings with history tracking
     * @param array $data Threshold data with keys: present_threshold_minutes, half_day_threshold_minutes
     * @param int $userId User ID making the change
     * @param string|null $reason Optional reason for the change
     * @return bool Success status
     */
    public function updateThresholds($data, $userId, $reason = null) {
        try {
            $this->db->beginTransaction();
            
            // First, ensure the attendance_settings table exists
            $this->ensureTableExists();
            
            // Get current settings
            $currentSettings = $this->getCurrentThresholds();
            
            $presentThreshold = (int) $data['present_threshold_minutes'];
            $halfDayThreshold = (int) $data['half_day_threshold_minutes'];
            
            // Check if values are actually different
            $needsUpdate = !$currentSettings || 
                          !isset($currentSettings['id']) ||
                          ($currentSettings['present_threshold_minutes'] != $presentThreshold || 
                           $currentSettings['half_day_threshold_minutes'] != $halfDayThreshold);
            
            if ($needsUpdate && $currentSettings && isset($currentSettings['id'])) {
                // Archive current settings to history only when values actually change
                try {
                    $this->db->insert('attendance_threshold_history', [
                        'attendance_settings_id' => $currentSettings['id'],
                        'present_threshold_minutes' => $currentSettings['present_threshold_minutes'],
                        'half_day_threshold_minutes' => $currentSettings['half_day_threshold_minutes'],
                        'effective_date' => $currentSettings['effective_date'],
                        'end_date' => date('Y-m-d'),
                        'changed_by' => $userId,
                        'change_reason' => $reason
                    ]);
                } catch (Exception $e) {
                    // History table might not exist - continue without it
                    error_log("Could not archive threshold history: " . $e->getMessage());
                }
            }
            
            // Update or insert new settings
            if ($currentSettings && isset($currentSettings['id'])) {
                if ($needsUpdate) {
                    $result = $this->db->update('attendance_settings', [
                        'present_threshold_minutes' => $presentThreshold,
                        'half_day_threshold_minutes' => $halfDayThreshold,
                        'effective_date' => date('Y-m-d'),
                        'created_by' => $userId
                    ], 'id = ?', [$currentSettings['id']]);
                    
                    if ($result === 0) {
                        throw new Exception("Update affected 0 rows");
                    }
                }
                // If values are the same, consider it a success (no update needed)
            } else {
                $newId = $this->db->insert('attendance_settings', [
                    'present_threshold_minutes' => $presentThreshold,
                    'half_day_threshold_minutes' => $halfDayThreshold,
                    'effective_date' => date('Y-m-d'),
                    'created_by' => $userId
                ]);
                
                if (!$newId) {
                    throw new Exception("Insert failed - no ID returned");
                }
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Failed to update attendance thresholds: " . $e->getMessage());
            throw $e; // Re-throw to provide detailed error to caller
        }
    }
    
    /**
     * Ensure the attendance_settings table exists
     */
    private function ensureTableExists() {
        try {
            $this->db->fetchOne("SELECT 1 FROM attendance_settings LIMIT 1");
        } catch (Exception $e) {
            // Table doesn't exist, create it
            $createTable = "
            CREATE TABLE IF NOT EXISTS `attendance_settings` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `present_threshold_minutes` int(11) NOT NULL,
              `half_day_threshold_minutes` int(11) NOT NULL,
              `effective_date` date NOT NULL DEFAULT (CURDATE()),
              `created_by` int(11) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->db->query($createTable);

            // Insert default values if table is empty (these are initial defaults per requirements)
            $checkExisting = $this->db->fetchOne("SELECT COUNT(*) as count FROM attendance_settings");
            if ($checkExisting['count'] == 0) {
                $this->db->query("INSERT INTO `attendance_settings` (`present_threshold_minutes`, `half_day_threshold_minutes`, `effective_date`)
                                 VALUES (495, 255, CURDATE())");
            }
        }
    }
    
    /**
     * Validate threshold configuration
     * @param array $data Threshold data to validate
     * @return array Validation result with 'valid' boolean and 'message' string
     */
    public function validateThresholds($data) {
        $presentThreshold = (int) ($data['present_threshold_minutes'] ?? 0);
        $halfDayThreshold = (int) ($data['half_day_threshold_minutes'] ?? 0);
        
        // Check if thresholds are valid positive numbers
        if ($presentThreshold <= 0 || $halfDayThreshold <= 0) {
            return ['valid' => false, 'message' => 'Thresholds must be positive values'];
        }
        
        // Check if present threshold is greater than half day threshold
        if ($presentThreshold <= $halfDayThreshold) {
            return ['valid' => false, 'message' => 'Present threshold must be greater than Half Day threshold'];
        }
        
        // Check reasonable bounds (no more than 24 hours = 1440 minutes)
        if ($presentThreshold > 1440 || $halfDayThreshold > 1440) {
            return ['valid' => false, 'message' => 'Thresholds cannot exceed 24 hours (1440 minutes)'];
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    /**
     * Get threshold change history
     * @param int $limit Number of records to return
     * @return array History records
     */
    public function getThresholdHistory($limit = 20) {
        try {
            return $this->db->fetchAll(
                "SELECT ath.*, e.first_name, e.last_name 
                 FROM attendance_threshold_history ath
                 LEFT JOIN employees e ON ath.changed_by = e.id
                 ORDER BY ath.changed_at DESC 
                 LIMIT ?",
                [$limit]
            );
        } catch (Exception $e) {
            // Table doesn't exist yet - return empty array
            return [];
        }
    }
}
?>
