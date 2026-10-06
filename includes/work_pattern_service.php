<?php
/**
 * Work Pattern Service
 * Centralized work-day calculation service
 * Core PHP HRMS Application
 * Single source of truth for employee work pattern logic
 */

require_once __DIR__ . '/../config/database.php';

class WorkPatternService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get employee's work pattern for a specific date
     * @param int $employeeId Employee ID
     * @param string $date Date to check (Y-m-d format)
     * @return array|false Work pattern configuration or false if not found
     */
    public function getEmployeeWorkPattern($employeeId, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        // First check employee_work_patterns for historical assignments
        $pattern = $this->db->fetchOne(
            "SELECT wp.* 
             FROM employee_work_patterns ewp
             JOIN work_patterns wp ON ewp.work_pattern_id = wp.id
             WHERE ewp.employee_id = ? 
             AND ewp.effective_from <= ? 
             AND (ewp.effective_to IS NULL OR ewp.effective_to >= ?)
             AND ewp.is_active = 1
             ORDER BY ewp.effective_from DESC
             LIMIT 1",
            [$employeeId, $date, $date]
        );
        
        // If no historical assignment found, check current assignment in employees table
        if (!$pattern) {
            $pattern = $this->db->fetchOne(
                "SELECT wp.* 
                 FROM employees e
                 JOIN work_patterns wp ON e.work_pattern_id = wp.id
                 WHERE e.id = ?",
                [$employeeId]
            );
        }
        
        return $pattern;
    }
    
    /**
     * Determine if a date is a working day for an employee
     * @param int $employeeId Employee ID
     * @param string $date Date to check (Y-m-d format)
     * @return bool True if working day, false if weekly off
     */
    public function isWorkingDay($employeeId, $date) {
        $pattern = $this->getEmployeeWorkPattern($employeeId, $date);
        
        if (!$pattern) {
            // Default pattern if none assigned: Monday-Saturday working
            return $this->isDefaultWorkingDay($date);
        }
        
        $dayOfWeek = date('N', strtotime($date)); // 1=Monday, 7=Sunday
        
        switch ($dayOfWeek) {
            case 1: // Monday
                return $pattern['monday'] === 'working';
            case 2: // Tuesday
                return $pattern['tuesday'] === 'working';
            case 3: // Wednesday
                return $pattern['wednesday'] === 'working';
            case 4: // Thursday
                return $pattern['thursday'] === 'working';
            case 5: // Friday
                return $pattern['friday'] === 'working';
            case 6: // Saturday
                return $this->isSaturdayWorkingDay($pattern, $date);
            case 7: // Sunday
                return $pattern['sunday'] === 'working';
            default:
                return true; // Default to working day
        }
    }
    
    /**
     * Determine if a Saturday is a working day based on pattern rules
     * @param array $pattern Work pattern configuration
     * @param string $date Date to check (Y-m-d format)
     * @return bool True if working day, false if weekly off
     */
    private function isSaturdayWorkingDay($pattern, $date) {
        if ($pattern['saturday'] === 'working') {
            return true;
        }
        
        if ($pattern['saturday'] === 'weekly_off') {
            return false;
        }
        
        // Handle conditional Saturday rules
        if ($pattern['saturday'] === 'conditional' && $pattern['saturday_rule']) {
            $saturdayNumber = $this->getSaturdayNumber($date);
            
            switch ($pattern['saturday_rule']) {
                case '2nd_4th':
                    // 2nd and 4th Saturdays are off
                    return !in_array($saturdayNumber, [2, 4]);
                case '4th_only':
                    // Only 4th Saturday is off
                    return $saturdayNumber !== 4;
                default:
                    return true; // Default to working
            }
        }
        
        return true; // Default to working
    }
    
    /**
     * Get the Saturday number in a month (1st, 2nd, 3rd, 4th, or 5th)
     * @param string $date Date (Y-m-d format)
     * @return int Saturday number (1-5)
     */
    private function getSaturdayNumber($date) {
        $dateTime = new DateTime($date);
        $day = (int)$dateTime->format('j'); // Day of month without leading zeros
        return (int)ceil($day / 7);
    }
    
    /**
     * Default working day logic for employees without assigned patterns
     * @param string $date Date to check (Y-m-d format)
     * @return bool True if working day, false if weekly off
     */
    private function isDefaultWorkingDay($date) {
        // date('N') returns a string; cast to int so the strict comparison works.
        $dayOfWeek = (int) date('N', strtotime($date));
        // Default: Monday-Saturday working, Sunday off
        return $dayOfWeek !== 7;
    }
    
    /**
     * Get the day type for a specific date
     * @param int $employeeId Employee ID
     * @param string $date Date to check (Y-m-d format)
     * @return string Day type: 'working', 'weekly_off', 'non_working'
     */
    public function getDayType($employeeId, $date) {
        if ($this->isWorkingDay($employeeId, $date)) {
            return 'working';
        }
        return 'weekly_off';
    }
    
    /**
     * Calculate working days between two dates for an employee
     * @param int $employeeId Employee ID
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return int Number of working days
     */
    public function calculateWorkingDays($employeeId, $startDate, $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $workingDays = 0;
        foreach ($period as $date) {
            if ($this->isWorkingDay($employeeId, $date->format('Y-m-d'))) {
                $workingDays++;
            }
        }
        
        return $workingDays;
    }
    
    /**
     * Get week off dates for an employee within a date range based on their
     * assigned Work Pattern (employee-specific; no global weekend rule).
     * Dates are evaluated dynamically against the pattern - attendance or
     * leave records are NOT required to exist for these dates.
     *
     * @param int    $employeeId Employee ID
     * @param string $startDate  Start date (Y-m-d)
     * @param string $endDate    End date (Y-m-d)
     * @return array Map of week off dates (Y-m-d => true) inside the range
     */
    public function getEmployeeWeekOffDates($employeeId, $startDate, $endDate) {
        $offDates = [];
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        foreach ($period as $date) {
            $ds = $date->format('Y-m-d');
            if (!$this->isWorkingDay($employeeId, $ds)) {
                $offDates[$ds] = true;
            }
        }

        return $offDates;
    }
    
    /**
     * Get all work patterns
     * @return array All work patterns
     */
    public function getAllWorkPatterns() {
        return $this->db->fetchAll("SELECT * FROM work_patterns WHERE is_active = 1 ORDER BY name");
    }
    
    /**
     * Get work pattern by ID
     * @param int $patternId Work pattern ID
     * @return array|false Work pattern or false if not found
     */
    public function getWorkPatternById($patternId) {
        return $this->db->fetchOne("SELECT * FROM work_patterns WHERE id = ?", [$patternId]);
    }
    
    /**
     * Assign work pattern to employee
     * @param int $employeeId Employee ID
     * @param int $patternId Work pattern ID
     * @param string $effectiveFrom Effective from date (Y-m-d format)
     * @param int|null $assignedBy User ID who made the assignment
     * @param string|null $reason Reason for assignment
     * @return bool Success status
     */
    public function assignWorkPattern($employeeId, $patternId, $effectiveFrom, $assignedBy = null, $reason = null) {
        try {
            $this->db->beginTransaction();
            
            // Get current pattern for history
            $currentPattern = $this->getEmployeeWorkPattern($employeeId, $effectiveFrom);
            
            // Update current assignment in employees table
            $this->db->update('employees', 
                ['work_pattern_id' => $patternId], 
                'id = ?', 
                [$employeeId]
            );
            
            // Create assignment record
            $this->db->insert('employee_work_patterns', [
                'employee_id' => $employeeId,
                'work_pattern_id' => $patternId,
                'effective_from' => $effectiveFrom,
                'is_active' => 1,
                'assigned_by' => $assignedBy,
                'assignment_reason' => $reason
            ]);
            
            // Create history record if pattern changed
            if ($currentPattern && $currentPattern['id'] != $patternId) {
                $this->db->insert('work_pattern_history', [
                    'employee_id' => $employeeId,
                    'old_work_pattern_id' => $currentPattern['id'],
                    'new_work_pattern_id' => $patternId,
                    'effective_from' => $effectiveFrom,
                    'changed_by' => $assignedBy,
                    'change_reason' => $reason
                ]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Failed to assign work pattern: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get work pattern assignment history for an employee
     * @param int $employeeId Employee ID
     * @return array Assignment history
     */
    public function getAssignmentHistory($employeeId) {
        return $this->db->fetchAll(
            "SELECT wph.*, 
                    wp_old.name as old_pattern_name,
                    wp_new.name as new_pattern_name,
                    e.first_name, e.last_name
             FROM work_pattern_history wph
             LEFT JOIN work_patterns wp_old ON wph.old_work_pattern_id = wp_old.id
             LEFT JOIN work_patterns wp_new ON wph.new_work_pattern_id = wp_new.id
             LEFT JOIN employees e ON wph.changed_by = e.id
             WHERE wph.employee_id = ?
             ORDER BY wph.effective_from DESC",
            [$employeeId]
        );
    }
}
?>
