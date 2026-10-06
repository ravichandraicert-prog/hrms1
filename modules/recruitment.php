<?php
/**
 * Recruitment Module
 * Core PHP HRMS Application
 * Section 8 - Recruitment module
 */

$db = Database::getInstance();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('recruitment', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view recruitment.</div>';
    exit;
}

// Check if migration has been run
$migrationNeeded = false;
try {
    $checkSourceBy = $db->fetchOne("SHOW COLUMNS FROM candidates LIKE 'source_by_id'");
    $checkScreening = $db->fetchOne("SHOW TABLES LIKE 'screening_rounds'");
    $checkInterview = $db->fetchOne("SHOW TABLES LIKE 'interview_rounds'");
    
    if (!$checkSourceBy || !$checkScreening || !$checkInterview) {
        $migrationNeeded = true;
    }
} catch (Exception $e) {
    $migrationNeeded = true;
}

if ($migrationNeeded) {
    echo '<div class="alert warning" style="margin-bottom: 20px;">
            <i class="bi bi-exclamation-triangle"></i> 
            <strong>Database Migration Required:</strong> The new recruitment workflow features require a database migration. 
            <a href="migrate_recruitment.php" style="text-decoration: underline; font-weight: bold;">Click here to run the migration</a> 
            to enable screening, interview rounds, and source by functionality.
          </div>';
}

// Get tab
$tab = $_GET['tab'] ?? 'sourcing';

// Get recruitment stages
$stages = $db->fetchAll("SELECT * FROM recruitment_stages WHERE is_active = 1 ORDER BY display_order");

// Get filter values
$sourceFilter = $_GET['source'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$positionFilter = $_GET['position'] ?? '';
$screeningStatusFilter = $_GET['screening_status'] ?? '';
$sourceByFilter = $_GET['source_by'] ?? '';

// Build query based on tab - filter by recruitment_stage_id
$whereConditions = [];
$params = [];

switch ($tab) {
    case 'all':
        break;
    case 'sourcing':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Sourcing' LIMIT 1)";
        break;
    case 'screening':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Screening' LIMIT 1)";
        break;
    case 'interview':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Interview' LIMIT 1)";
        break;
    case 'onboarding':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Onboarding' LIMIT 1)";
        break;
    case 'offboarding':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Offboarding' LIMIT 1)";
        break;
    case 'blacklisted':
        $whereConditions[] = "c.recruitment_stage_id = (SELECT id FROM recruitment_stages WHERE name = 'Blacklisted' LIMIT 1)";
        break;
}

// Apply additional filters
if ($sourceByFilter) {
    $whereConditions[] = "c.source_by_id = ?";
    $params[] = $sourceByFilter;
}

if ($sourceFilter) {
    $whereConditions[] = "c.source = ?";
    $params[] = $sourceFilter;
}

if ($positionFilter) {
    $whereConditions[] = "c.position_applied = ?";
    $params[] = $positionFilter;
}

if ($searchFilter) {
    $whereConditions[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.position_applied LIKE ?)";
    $searchTerm = '%' . $searchFilter . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($screeningStatusFilter && $tab == 'screening') {
    $whereConditions[] = "(SELECT sr.screening_status FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) = ?";
    $params[] = $screeningStatusFilter;
}

$whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

// Get candidates with screening and interview details (with backward compatibility)
try {
    // First try the full query with new features
    $candidates = $db->fetchAll(
        "SELECT c.*, rs.name as stage_name, e.first_name as recruiter_first, e.last_name as recruiter_last,
                se.first_name as source_by_first, se.last_name as source_by_last,
                (SELECT sr.screening_date FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_date,
                (SELECT sr.screening_time FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_time,
                (SELECT sr.screening_status FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_status,
                (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_recruiter_id,
                (SELECT e.first_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_first,
                (SELECT e.last_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_last,
                (SELECT COUNT(*) FROM interview_rounds ir WHERE ir.candidate_id = c.id) as interview_rounds_count,
                (SELECT GROUP_CONCAT(CONCAT(ir.round_type, ' Round ', ir.round_number, ' (', ir.interview_status, ')') SEPARATOR ', ') 
                 FROM interview_rounds ir WHERE ir.candidate_id = c.id) as interview_rounds_summary
         FROM candidates c
         LEFT JOIN recruitment_stages rs ON c.recruitment_stage_id = rs.id
         LEFT JOIN employees e ON c.assigned_recruiter_id = e.id
         LEFT JOIN employees se ON c.source_by_id = se.id
         WHERE {$whereClause}
         ORDER BY c.created_at DESC
         LIMIT 50",
        $params
    );
} catch (Exception $e) {
    // Fallback to query without source_by_id if column doesn't exist
    try {
        $candidates = $db->fetchAll(
            "SELECT c.*, rs.name as stage_name, e.first_name as recruiter_first, e.last_name as recruiter_last,
                    (SELECT sr.screening_date FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_date,
                    (SELECT sr.screening_time FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_time,
                    (SELECT sr.screening_status FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_status,
                    (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_recruiter_id,
                    (SELECT e.first_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_first,
                    (SELECT e.last_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_last
             FROM candidates c
             LEFT JOIN recruitment_stages rs ON c.recruitment_stage_id = rs.id
             LEFT JOIN employees e ON c.assigned_recruiter_id = e.id
             WHERE {$whereClause}
             ORDER BY c.created_at DESC
             LIMIT 50",
            $params
        );
    } catch (Exception $e2) {
        // Final fallback - most basic query
        $candidates = $db->fetchAll(
            "SELECT c.*, rs.name as stage_name,
                    (SELECT sr.screening_date FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_date,
                    (SELECT sr.screening_time FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_time,
                    (SELECT sr.screening_status FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_status,
                    (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1) as screening_recruiter_id,
                    (SELECT e.first_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_first,
                    (SELECT e.last_name FROM employees e WHERE e.id = (SELECT sr.recruiter_id FROM screening_rounds sr WHERE sr.candidate_id = c.id ORDER BY sr.id DESC LIMIT 1)) as screening_recruiter_last
             FROM candidates c
             LEFT JOIN recruitment_stages rs ON c.recruitment_stage_id = rs.id
             WHERE {$whereClause}
             ORDER BY c.created_at DESC
             LIMIT 50",
            $params
        );
    }
}

// For the offboarding tab: load the linked employee record for each candidate
// (employee id, department, designation, last working day) so the table can show
// real employee data without a per-row query.
$offboardEmployeeIndex = [];
if ($tab === 'offboarding' && !empty($candidates)) {
    $candIds = [];
    $placeholders = [];
    foreach ($candidates as $c) {
        $candIds[] = $c['id'];
        $placeholders[] = '?';
    }
    $inClause = implode(',', $placeholders);

    // 1) Prefer the employee created during onboarding (onboarding_documents link)
    $offboardRows = $db->fetchAll(
        "SELECT od.candidate_id, e.id as employee_id, e.employee_id as emp_code,
                e.first_name, e.last_name, e.last_working_day, e.position as designation,
                d.name as department_name
         FROM onboarding_documents od
         JOIN employees e ON e.id = od.employee_id
         LEFT JOIN employee_departments ed ON ed.employee_id = e.id AND ed.is_primary = 1
         LEFT JOIN departments d ON d.id = ed.department_id
         WHERE od.candidate_id IN ({$inClause})
         ORDER BY od.id DESC",
        $candIds
    );
    foreach ($offboardRows as $oe) {
        if (!isset($offboardEmployeeIndex[(int) $oe['candidate_id']])) {
            $offboardEmployeeIndex[(int) $oe['candidate_id']] = $oe;
        }
    }

    // 2) Fallback: an existing employee whose email matches the candidate email
    $offboardRows2 = $db->fetchAll(
        "SELECT c.id as candidate_id, e.id as employee_id, e.employee_id as emp_code,
                e.first_name, e.last_name, e.last_working_day, e.position as designation,
                d.name as department_name
         FROM candidates c
         JOIN employees e ON e.official_email = c.email OR e.personal_email = c.email
         LEFT JOIN employee_departments ed ON ed.employee_id = e.id AND ed.is_primary = 1
         LEFT JOIN departments d ON d.id = ed.department_id
         WHERE c.id IN ({$inClause})
         ORDER BY c.id",
        $candIds
    );
    foreach ($offboardRows2 as $oe) {
        if (!isset($offboardEmployeeIndex[(int) $oe['candidate_id']])) {
            $offboardEmployeeIndex[(int) $oe['candidate_id']] = $oe;
        }
    }
}

// Options for candidate forms
$recruiters = $db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees WHERE is_active = 1 ORDER BY first_name, last_name");

// Try to get HR employees with multiple fallback approaches
$hrEmployees = [];
try {
    // First try: Check if employee_departments table exists and query HR department employees
    $checkTable = $db->fetchOne("SHOW TABLES LIKE 'employee_departments'");
    if ($checkTable) {
        $hrEmployees = $db->fetchAll("SELECT e.id, e.first_name, e.last_name, e.employee_id 
                                      FROM employees e 
                                      INNER JOIN employee_departments ed ON e.id = ed.employee_id 
                                      INNER JOIN departments d ON ed.department_id = d.id 
                                      WHERE e.is_active = 1 AND d.name = 'HR' 
                                      ORDER BY e.first_name, e.last_name");
    }
    
    // If no HR employees found, try alternative approaches
    if (empty($hrEmployees)) {
        // Try checking for 'Human Resources' department name
        $hrEmployees = $db->fetchAll("SELECT e.id, e.first_name, e.last_name, e.employee_id 
                                      FROM employees e 
                                      INNER JOIN employee_departments ed ON e.id = ed.employee_id 
                                      INNER JOIN departments d ON ed.department_id = d.id 
                                      WHERE e.is_active = 1 AND (d.name = 'HR' OR d.name = 'Human Resources') 
                                      ORDER BY e.first_name, e.last_name");
    }
    
    // If still empty, try checking by department code
    if (empty($hrEmployees)) {
        $hrEmployees = $db->fetchAll("SELECT e.id, e.first_name, e.last_name, e.employee_id 
                                      FROM employees e 
                                      INNER JOIN employee_departments ed ON e.id = ed.employee_id 
                                      INNER JOIN departments d ON ed.department_id = d.id 
                                      WHERE e.is_active = 1 AND (d.code = 'HR' OR d.code = 'hr') 
                                      ORDER BY e.first_name, e.last_name");
    }
    
} catch (Exception $e) {
    // If all queries fail, use all employees as fallback
    $hrEmployees = $recruiters;
}

// Final fallback: if still empty, use all active employees
if (empty($hrEmployees)) {
    $hrEmployees = $recruiters;
}

$allEmployees = $db->fetchAll("SELECT id, first_name, last_name, employee_id FROM employees WHERE is_active = 1 ORDER BY first_name, last_name");
$deptOptions = $db->fetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
$roleOptions = $db->fetchAll("SELECT id, name FROM roles WHERE is_active = 1 ORDER BY hierarchy_level");

// Get unique positions for filter
$positionOptions = $db->fetchAll("SELECT DISTINCT position_applied FROM candidates WHERE position_applied IS NOT NULL AND position_applied != '' ORDER BY position_applied");

// Source options for filter
$sourceOptions = [
    'Referral' => 'Referral',
    'Job Portal' => 'Job Portal', 
    'LinkedIn' => 'LinkedIn',
    'Walk-in' => 'Walk-in',
    'Campus' => 'Campus',
    'Company Website' => 'Company Website'
];
?>

<style>
/* ===== Recruitment module: table layout & feedback cell ===== */
/* Tables follow the app-wide .data-table behavior from the shell:
   natural column widths (no aggressive shrinking), horizontal scrolling on
   narrow screens and sticky headers. The percentage column hints below act
   as preferred widths under the automatic table layout. */
.recruitment-tables table{width:100%;border-collapse:separate;border-spacing:0;}
.recruitment-tables .actions{flex-wrap:wrap;}

/* Feedback cell: fixed max width/height, wraps, ellipsis, full text on hover */
.recruit-feedback-cell{
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;
    overflow:hidden;overflow-wrap:break-word;word-wrap:break-word;
    max-width:100%;max-height:60px;line-height:20px;cursor:help;
}

/* Sourcing table */
.recruitment-tables.sourcing-table th:nth-child(1){width:8%;}
.recruitment-tables.sourcing-table th:nth-child(2){width:14%;}
.recruitment-tables.sourcing-table th:nth-child(3){width:12%;}
.recruitment-tables.sourcing-table th:nth-child(4){width:8%;}
.recruitment-tables.sourcing-table th:nth-child(5){width:9%;}
.recruitment-tables.sourcing-table th:nth-child(6){width:13%;}
.recruitment-tables.sourcing-table th:nth-child(7){width:8%;}
.recruitment-tables.sourcing-table th:nth-child(8){width:16%;}
.recruitment-tables.sourcing-table th:nth-child(9){width:12%;}

/* Screening table */
.recruitment-tables.screening-table th:nth-child(1){width:20%;}
.recruitment-tables.screening-table th:nth-child(2){width:14%;}
.recruitment-tables.screening-table th:nth-child(3){width:14%;}
.recruitment-tables.screening-table th:nth-child(4){width:12%;}
.recruitment-tables.screening-table th:nth-child(5){width:10%;}
.recruitment-tables.screening-table th:nth-child(6){width:14%;}
.recruitment-tables.screening-table th:nth-child(7){width:16%;}

/* Interview table */
.recruitment-tables.interview-table th:nth-child(1){width:20%;}
.recruitment-tables.interview-table th:nth-child(2){width:14%;}
.recruitment-tables.interview-table th:nth-child(3){width:13%;}
.recruitment-tables.interview-table th:nth-child(4){width:20%;}
.recruitment-tables.interview-table th:nth-child(5){width:10%;}
.recruitment-tables.interview-table th:nth-child(6){width:23%;}

/* Onboarding table */
.recruitment-tables.onboarding-table th:nth-child(1){width:24%;}
.recruitment-tables.onboarding-table th:nth-child(2){width:18%;}
.recruitment-tables.onboarding-table th:nth-child(3){width:15%;}
.recruitment-tables.onboarding-table th:nth-child(4){width:12%;}
.recruitment-tables.onboarding-table th:nth-child(5){width:31%;}

/* Offboarding table */
.recruitment-tables.offboarding-table th:nth-child(1){width:24%;}
.recruitment-tables.offboarding-table th:nth-child(2){width:13%;}
.recruitment-tables.offboarding-table th:nth-child(3){width:13%;}
.recruitment-tables.offboarding-table th:nth-child(4){width:15%;}
.recruitment-tables.offboarding-table th:nth-child(5){width:15%;}
.recruitment-tables.offboarding-table th:nth-child(6){width:20%;}

/* Blacklisted table */
.recruitment-tables.blacklist-table th:nth-child(1){width:26%;}
.recruitment-tables.blacklist-table th:nth-child(2){width:18%;}
.recruitment-tables.blacklist-table th:nth-child(3){width:24%;}
.recruitment-tables.blacklist-table th:nth-child(4){width:14%;}
.recruitment-tables.blacklist-table th:nth-child(5){width:18%;}

/* All candidates table */
.recruitment-tables.all-table th:nth-child(1){width:12%;}
.recruitment-tables.all-table th:nth-child(2){width:6%;}
.recruitment-tables.all-table th:nth-child(3){width:8%;}
.recruitment-tables.all-table th:nth-child(4){width:6%;}
.recruitment-tables.all-table th:nth-child(5){width:9%;}
.recruitment-tables.all-table th:nth-child(6){width:6%;}
.recruitment-tables.all-table th:nth-child(7){width:8%;}
.recruitment-tables.all-table th:nth-child(8){width:4%;}
.recruitment-tables.all-table th:nth-child(9){width:8%;}
.recruitment-tables.all-table th:nth-child(10){width:9%;}
.recruitment-tables.all-table th:nth-child(11){width:8%;}
.recruitment-tables.all-table th:nth-child(12){width:5%;}
.recruitment-tables.all-table th:nth-child(13){width:11%;}

/* All candidates: stage sections */
.all-stage-section{margin-bottom:22px;}
.all-stage-head{
    display:flex;align-items:center;gap:10px;padding:10px 16px;
    background:#FBFAF7;border:1px solid var(--border);border-bottom:none;
    border-radius:var(--radius) var(--radius) 0 0;font-weight:600;font-size:13px;
}
.all-stage-count{font-size:11px;font-weight:600;color:var(--muted);background:#F0EEE8;padding:2px 8px;border-radius:10px;}
.all-stage-section .data-table{border-top-left-radius:0;border-top-right-radius:0;}
.toolbar label.filter-chip{cursor:pointer;user-select:none;}
</style>

<div class="panel-head"></div>

<div class="tabs">
    <div class="tab <?php echo $tab == 'all' ? 'active' : ''; ?>" onclick="switchTab('all')">All Candidates</div>
    <div class="tab <?php echo $tab == 'sourcing' ? 'active' : ''; ?>" onclick="switchTab('sourcing')">Sourcing</div>
    <div class="tab <?php echo $tab == 'screening' ? 'active' : ''; ?>" onclick="switchTab('screening')">Screening</div>
    <div class="tab <?php echo $tab == 'interview' ? 'active' : ''; ?>" onclick="switchTab('interview')">Interview</div>
    <div class="tab <?php echo $tab == 'onboarding' ? 'active' : ''; ?>" onclick="switchTab('onboarding')">Onboarding</div>
    <div class="tab <?php echo $tab == 'offboarding' ? 'active' : ''; ?>" onclick="switchTab('offboarding')">Offboarding</div>
    <div class="tab <?php echo $tab == 'blacklisted' ? 'active' : ''; ?>" onclick="switchTab('blacklisted')">Blacklisted</div>
</div>

<?php if ($tab == 'all'): ?>
    <?php
    // Group candidates by stage, preserving recruitment_stages display order
    $allCandidatesByStage = [];
    foreach ($candidates as $cand) {
        $stageName = $cand['stage_name'] ?? '';
        if ($stageName === '' || $stageName === null) {
            $stageName = 'Unassigned';
        }
        $allCandidatesByStage[$stageName][] = $cand;
    }
    $stageSections = [];
    foreach ($stages as $s) {
        $stageSections[] = ['name' => $s['name'], 'candidates' => $allCandidatesByStage[$s['name']] ?? []];
    }
    if (!empty($allCandidatesByStage['Unassigned'])) {
        $stageSections[] = ['name' => 'Unassigned', 'candidates' => $allCandidatesByStage['Unassigned']];
    }
    $stageBadgeMap = [
        'Sourcing'    => 'warning',
        'Screening'   => 'info',
        'Interview'   => 'pending',
        'Onboarding'  => 'approved',
        'Offboarding' => 'gray',
        'Blacklisted' => 'danger',
    ];
    ?>
    <div class="toolbar" style="flex-wrap:wrap;">
        <?php foreach ($stageSections as $sec): ?>
            <label class="filter-chip">
                <input type="checkbox" class="stage-check" value="<?php echo htmlspecialchars($sec['name']); ?>"
                       onchange="applyAllStageFilter()" <?php echo empty($sec['candidates']) ? 'disabled' : ''; ?>>
                <?php echo htmlspecialchars($sec['name']); ?> (<?php echo count($sec['candidates']); ?>)
            </label>
        <?php endforeach; ?>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..."
                   value="<?php echo htmlspecialchars($searchFilter); ?>"
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" onchange="applyFilter('source_by', this.value)">
            <option value="">All Source By (HR)</option>
            <?php foreach ($hrEmployees as $hr): ?>
                <option value="<?php echo $hr['id']; ?>" <?php echo $sourceByFilter == $hr['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($hr['first_name'] . ' ' . $hr['last_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>

    <?php if (empty($candidates)): ?>
        <div class="data-table">
            <table>
                <tbody>
                    <tr>
                        <td style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p>No candidates found</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?php foreach ($stageSections as $sec): ?>
            <?php if (empty($sec['candidates'])) continue; ?>
            <div class="all-stage-section" data-stage="<?php echo htmlspecialchars($sec['name']); ?>">
                <div class="all-stage-head">
                    <span class="badge <?php echo $stageBadgeMap[$sec['name']] ?? 'gray'; ?>"><?php echo htmlspecialchars($sec['name']); ?></span>
                    <span class="all-stage-count"><?php echo count($sec['candidates']); ?> candidate(s)</span>
                </div>
                <div class="data-table recruitment-tables all-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Candidate</th>
                                <th>Phone</th>
                                <th>Role Applied For</th>
                                <th>Experience</th>
                                <th>Source</th>
                                <th>Location</th>
                                <th>Current / Expected CTC</th>
                                <th>Notice</th>
                                <th>Recruiter</th>
                                <th>Screening</th>
                                <th>Interview Rounds</th>
                                <th>Added On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sec['candidates'] as $candidate): ?>
                                <tr>
                                    <td>
                                        <div class="emp-cell">
                                            <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                            <div>
                                                <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                                <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($candidate['phone'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($candidate['candidate_type'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($candidate['source'] ?? '-'); ?>
                                        <?php if (!empty($candidate['source_by_first'])): ?>
                                            <div class="emp-id">By: <?php echo htmlspecialchars($candidate['source_by_first'] . ' ' . ($candidate['source_by_last'] ?? '')); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($candidate['location'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo !empty($candidate['current_salary']) ? '&#8377;' . number_format((float) $candidate['current_salary']) : '-'; ?>
                                        <?php if (!empty($candidate['expected_salary'])): ?>
                                            <div class="emp-id">Exp: &#8377;<?php echo number_format((float) $candidate['expected_salary']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($candidate['notice_period']) ? htmlspecialchars($candidate['notice_period']) . ' days' : '-'; ?></td>
                                    <td><?php $recruiterName = trim(($candidate['recruiter_first'] ?? '') . ' ' . ($candidate['recruiter_last'] ?? '')); echo $recruiterName !== '' ? htmlspecialchars($recruiterName) : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($candidate['screening_status'])): ?>
                                            <div><?php echo htmlspecialchars($candidate['screening_status']); ?></div>
                                            <?php if (!empty($candidate['screening_date'])): ?>
                                                <div class="emp-id"><?php echo formatDate($candidate['screening_date']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($candidate['interview_rounds_count'])): ?>
                                            <div><?php echo (int) $candidate['interview_rounds_count']; ?> round(s)</div>
                                            <div class="emp-id"><?php echo htmlspecialchars($candidate['interview_rounds_summary'] ?? ''); ?></div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="mono"><?php echo formatDate($candidate['created_at']); ?></td>
                                    <td>
                                        <div class="actions">
                                            <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </div>
                                            <div class="icon-btn" onclick="editCandidate(<?php echo $candidate['id']; ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </div>
                                            <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                                <i class="bi bi-person-x"></i>
                                            </div>
                                            <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($tab == 'sourcing'): ?>
    <div class="toolbar">
        <div class="filter-chip active">Sourcing</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn" onclick="openImportModal()">
            <i class="bi bi-upload"></i> Import
        </button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables sourcing-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Candidate</th>
                    <th>Role Applied For</th>
                    <th>Experience</th>
                    <th>Source</th>
                    <th style="min-width:150px;">Source By</th>
                    <th>Status</th>
                    <th>Feedback</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No candidates in sourcing</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td class="mono"><?php echo formatDate($candidate['created_at']); ?></td>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($candidate['candidate_type'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($candidate['source'] ?? '-'); ?></td>
                            <td><?php echo isset($candidate['source_by_first']) ? htmlspecialchars($candidate['source_by_first'] . ' ' . $candidate['source_by_last']) : '-'; ?></td>
                            <td><span class="badge <?php echo $candidate['stage_name'] === 'Sourcing' ? 'pending' : ($candidate['stage_name'] === 'Screening' ? 'info' : 'success'); ?>"><?php echo htmlspecialchars($candidate['stage_name']); ?></span></td>
                            <td>
                                <div class="recruit-feedback-cell" title="<?php echo htmlspecialchars($candidate['notes'] ?? ''); ?>"><?php echo htmlspecialchars($candidate['notes'] ?? ''); ?></div>
                            </td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <div class="icon-btn" onclick="editCandidate(<?php echo $candidate['id']; ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </div>
                                    <?php if (!$migrationNeeded): ?>
                                    <button class="btn sm primary" onclick="openScreeningModal(<?php echo $candidate['id']; ?>)">Screening</button>
                                    <?php else: ?>
                                    <button class="btn sm primary" onclick="moveToScreening(<?php echo $candidate['id']; ?>)">Screening</button>
                                    <?php endif; ?>
                                    <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                        <i class="bi bi-person-x"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'screening'): ?>
    <div class="toolbar">
        <div class="filter-chip <?php echo $screeningStatusFilter === '' ? 'active' : ''; ?>" onclick="applyScreeningFilter('')">All</div>
        <div class="filter-chip <?php echo $screeningStatusFilter === 'Screening Scheduled' ? 'active' : ''; ?>" onclick="applyScreeningFilter('Screening Scheduled')">Screening Scheduled</div>
        <div class="filter-chip <?php echo $screeningStatusFilter === 'Screening Completed' ? 'active' : ''; ?>" onclick="applyScreeningFilter('Screening Completed')">Screening Completed</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables screening-table">
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Role Applied For</th>
                    <th>Source By</th>
                    <th>Screening Date</th>
                    <th>Screening Time</th>
                    <th>Screening Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No candidates in screening</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                            <td><?php echo isset($candidate['source_by_first']) && $candidate['source_by_first'] ? htmlspecialchars($candidate['source_by_first'] . ' ' . $candidate['source_by_last']) : '-'; ?></td>
                            <td class="mono"><?php echo isset($candidate['screening_date']) && $candidate['screening_date'] ? formatDate($candidate['screening_date']) : '-'; ?></td>
                            <td class="mono"><?php echo isset($candidate['screening_time']) && $candidate['screening_time'] ? substr($candidate['screening_time'], 0, 5) : '-'; ?></td>
                            <td><span class="badge <?php echo isset($candidate['screening_status']) && $candidate['screening_status'] === 'Screening Completed' ? 'success' : 'info'; ?>"><?php echo isset($candidate['screening_status']) ? htmlspecialchars($candidate['screening_status']) : 'Not Scheduled'; ?></span></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <button class="btn sm primary" onclick="openScreeningModal(<?php echo $candidate['id']; ?>)">Edit Screening</button>
                                    <?php if (isset($candidate['screening_status']) && $candidate['screening_status'] === 'Screening Completed'): ?>
                                        <button class="btn sm success" onclick="openInterviewRoundsModal(<?php echo $candidate['id']; ?>)">Interview</button>
                                    <?php endif; ?>
                                    <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                        <i class="bi bi-person-x"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'interview'): ?>
    <div class="toolbar">
        <div class="filter-chip active">All</div>
        <div class="filter-chip">Technical Round</div>
        <div class="filter-chip">HR Round</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables interview-table">
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Role Applied For</th>
                    <th>Source By</th>
                    <th>Interview Rounds</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No candidates in interview</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                            <td><?php echo isset($candidate['source_by_first']) && $candidate['source_by_first'] ? htmlspecialchars($candidate['source_by_first'] . ' ' . $candidate['source_by_last']) : '-'; ?></td>
                            <td><?php echo isset($candidate['interview_rounds_count']) && $candidate['interview_rounds_count'] > 0 ? htmlspecialchars($candidate['interview_rounds_summary']) : 'No rounds'; ?></td>
                            <td><span class="badge pending"><?php echo htmlspecialchars($candidate['stage_name']); ?></span></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <button class="btn sm primary" onclick="openInterviewRoundsModal(<?php echo $candidate['id']; ?>)">Manage Rounds</button>
                                    <button class="btn sm success" onclick="sendOffer(<?php echo $candidate['id']; ?>)">Send Offer</button>
                                    <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                        <i class="bi bi-person-x"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'onboarding'): ?>
    <div class="toolbar">
        <div class="filter-chip active">All</div>
        <div class="filter-chip">Offer Accepted</div>
        <div class="filter-chip">Onboarding</div>
        <div class="filter-chip">Joined</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables onboarding-table">
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Role Applied For</th>
                    <th>Created Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No candidates in onboarding</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                            <td class="mono"><?php echo formatDate($candidate['created_at']); ?></td>
                            <td><span class="badge success"><?php echo htmlspecialchars($candidate['stage_name']); ?></span></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <button class="btn sm primary" onclick="addAsEmployee(<?php echo $candidate['id']; ?>)">Add as Employee</button>
                                    <button class="btn sm" onclick="offboardCandidate(<?php echo $candidate['id']; ?>)" title="Offboard – set Last Working Day">Offboard</button>
                                    <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                        <i class="bi bi-person-x"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'offboarding'): ?>
    <div class="toolbar">
        <div class="filter-chip active">All</div>
        <div class="filter-chip">Offer Rejected</div>
        <div class="filter-chip">Not Interested</div>
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables offboarding-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Employee ID</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Last Working Day</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No candidates in offboarding</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <?php $offboardEmp = $offboardEmployeeIndex[$candidate['id']] ?? null; ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mono"><?php echo $offboardEmp && !empty($offboardEmp['emp_code']) ? htmlspecialchars($offboardEmp['emp_code']) : '-'; ?></td>
                            <td><?php echo $offboardEmp && !empty($offboardEmp['department_name']) ? htmlspecialchars($offboardEmp['department_name']) : '-'; ?></td>
                            <td><?php echo $offboardEmp && !empty($offboardEmp['designation']) ? htmlspecialchars($offboardEmp['designation']) : '-'; ?></td>
                            <td class="mono"><?php echo $offboardEmp && !empty($offboardEmp['last_working_day']) ? formatDate($offboardEmp['last_working_day'], 'd-m-Y') : '-'; ?></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <div class="icon-btn" onclick="blacklistCandidate(<?php echo $candidate['id']; ?>)" title="Blacklist" style="color:var(--danger);">
                                        <i class="bi bi-person-x"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab == 'blacklisted'): ?>
    <div class="toolbar">
        <div class="spacer"></div>
        <div class="search" style="width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" id="candidateSearch" placeholder="Search candidates..." 
                   value="<?php echo htmlspecialchars($searchFilter); ?>" 
                   oninput="filterCandidatesDebounced(this)">
        </div>
        <select class="filter-chip" id="sourceFilter" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $sourceFilter == $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-chip" id="positionFilter" onchange="applyFilter('position', this.value)">
            <option value="">All Positions</option>
            <?php foreach ($positionOptions as $pos): ?>
                <option value="<?php echo htmlspecialchars($pos['position_applied']); ?>" <?php echo $positionFilter == $pos['position_applied'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pos['position_applied']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn sm" onclick="clearFilters()">Clear Filters</button>
        <button class="btn primary" onclick="addCandidate()">
            <i class="bi bi-plus-lg"></i> Add Candidate
        </button>
    </div>
    
    <div class="data-table recruitment-tables blacklist-table">
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Role Applied For</th>
                    <th>Notes</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;">
                            <div class="empty-state">
                                <i class="bi bi-person-plus"></i>
                                <p>No blacklisted candidates</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="emp-cell">
                                    <div class="avatar"><?php echo getInitials($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></div>
                                        <div class="emp-id"><?php echo htmlspecialchars($candidate['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['position_applied'] ?? '-'); ?></td>
                            <td title="<?php echo htmlspecialchars($candidate['notes'] ?? ''); ?>"><?php echo htmlspecialchars(substr($candidate['notes'] ?? '-', 0, 50)); ?><?php echo (strlen($candidate['notes'] ?? '') > 50 ? '...' : ''); ?></td>
                            <td class="mono"><?php echo formatDate($candidate['created_at']); ?></td>
                            <td>
                                <div class="actions">
                                    <div class="icon-btn" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <div class="icon-btn" onclick="removeFromBlacklist(<?php echo $candidate['id']; ?>)" title="Remove from Blacklist" style="color:var(--success);">
                                        <i class="bi bi-check-lg"></i>
                                    </div>
                                    <div class="icon-btn" style="color:var(--danger);" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Candidate Modal -->
<div id="candidateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:760px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="candidateModalTitle">Add Candidate</h3>
            <button onclick="closeCandidateModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="candidateId">
        <div class="form-grid">
            <div class="field">
                <label>First Name <span class="req">*</span></label>
                <input type="text" id="candFirst">
            </div>
            <div class="field">
                <label>Last Name</label>
                <input type="text" id="candLast">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" id="candEmail">
            </div>
            <div class="field">
                <label>Phone <span class="req">*</span></label>
                <input type="text" id="candPhone">
            </div>
            <div class="field">
                <label>Position Applied For</label>
                <input type="text" id="candPosition">
            </div>
            <div class="field">
                <label>Source</label>
                <input type="text" id="candSource" placeholder="e.g. Referral, Job Portal, LinkedIn">
            </div>
            <div class="field">
                <label>Source By (HR Employee)</label>
                <select id="candSourceBy">
                    <option value="">Select HR employee</option>
                    <?php if (!empty($hrEmployees)): ?>
                        <?php foreach ($hrEmployees as $hr): ?>
                            <option value="<?php echo $hr['id']; ?>"><?php echo htmlspecialchars($hr['first_name'] . ' ' . $hr['last_name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No employees available</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="field">
                <label>Stage</label>
                <select id="candStage">
                    <?php foreach ($stages as $s): ?>
                        <option value="<?php echo htmlspecialchars($s['name']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Candidate Type</label>
                <select id="candType">
                    <option value="Fresher">Fresher</option>
                    <option value="0-1 Years">0-1 Years Experience</option>
                    <option value="1-2 Years">1-2 Years Experience</option>
                    <option value="2-3 Years">2-3 Years Experience</option>
                    <option value="3-4 Years">3-4 Years Experience</option>
                    <option value="4-5 Years">4-5 Years Experience</option>
                    <option value="5-6 Years">5-6 Years Experience</option>
                    <option value="6-7 Years">6-7 Years Experience</option>
                    <option value="7-8 Years">7-8 Years Experience</option>
                    <option value="8-9 Years">8-9 Years Experience</option>
                    <option value="9-10 Years">9-10 Years Experience</option>
                    <option value="10+ Years">10+ Years Experience</option>
                </select>
            </div>
            <div class="field">
                <label>Current Salary (₹)</label>
                <input type="number" id="candCurrentSalary" min="0" step="0.01">
            </div>
            <div class="field">
                <label>Expected Salary (₹)</label>
                <input type="number" id="candExpectedSalary" min="0" step="0.01">
            </div>
            <div class="field">
                <label>Notice Period (days)</label>
                <input type="number" id="candNotice" min="0">
            </div>
            <div class="field">
                <label>Location</label>
                <input type="text" id="candLocation" placeholder="e.g. Pune, Mumbai, Remote">
            </div>
            <div class="field">
                <label>Relocate</label>
                <select id="candRelocate">
                    <option value="">Select</option>
                    <option value="Yes">Yes</option>
                    <option value="No">No</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Notes / Feedback</label>
                <textarea id="candNotes" rows="3" placeholder="Internal notes, feedback, etc."></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeCandidateModal()">Cancel</button>
            <button class="btn primary" onclick="saveCandidate()">Save Candidate</button>
        </div>
    </div>
</div>

<!-- Offboard Modal (Last Working Day) -->
<div id="offboardModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1001;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:520px;max-width:95vw;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Offboard Employee</h3>
            <button onclick="closeOffboardModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="offboardCandidateId">
        <input type="hidden" id="offboardEmployeeId">
        <div class="field">
            <label>Employee</label>
            <input type="text" id="offboardEmployeeLabel" readonly style="background:var(--background);">
        </div>
        <div style="margin-bottom:14px;color:var(--muted);font-size:13px;line-height:1.6;">
            Select the employee's <b>Last Working Day</b>. Saving will complete the offboarding and move the employee to the Offboard tab. No employee data is deleted.
        </div>
        <div class="field">
            <label>Last Working Day <span class="req">*</span></label>
            <input type="date" id="offboardLastWorkingDay" required>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeOffboardModal()">Cancel</button>
            <button class="btn primary" onclick="saveOffboard()">Save</button>
        </div>
    </div>
</div>

<!-- Import Candidates Modal -->
<div id="importModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:600px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Import Candidates</h3>
            <button onclick="closeImportModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block;margin-bottom:8px;font-weight:500;">Upload CSV File</label>
            <input type="file" id="importFile" accept=".csv" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;">
            <p style="font-size:12px;color:var(--muted);margin-top:8px;">
                CSV should contain columns: first_name, last_name, email, phone, position_applied, source, candidate_type, current_salary, expected_salary, notice_period, notes
            </p>
        </div>
        <div style="background:var(--background);border-radius:8px;padding:16px;margin-bottom:20px;">
            <h4 style="font-size:14px;font-weight:600;margin:0 0 12px 0;">Sample CSV Format:</h4>
            <pre style="font-size:11px;color:var(--muted);margin:0;overflow-x:auto;">first_name,last_name,email,phone,position_applied,source,candidate_type,current_salary,expected_salary,notice_period,notes
John,Doe,john@example.com,1234567890,Software Engineer,LinkedIn,2-3 Years,500000,600000,30,Good candidate
Jane,Smith,jane@example.com,9876543210,Data Analyst,Job Portal,Fresher,,400000,15,Fresh graduate</pre>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeImportModal()">Cancel</button>
            <button class="btn primary" onclick="importCandidates()">Import Candidates</button>
        </div>
    </div>
</div>

<!-- Candidate Detail Modal -->
<div id="candidateDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:720px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="candidateDetailTitle">Candidate Details</h3>
            <button onclick="closeCandidateDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <div id="candidateDetailBody" style="font-size:13px;color:var(--muted);line-height:1.8;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button class="btn" onclick="closeCandidateDetailModal()">Close</button>
            <button class="btn sm primary" id="candidateEditBtn" onclick="editCandidate(currentCandidateDetailId)" style="display:none;">Edit</button>
        </div>
    </div>
</div>

<!-- Screening Modal -->
<div id="screeningModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:680px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Screening Details</h3>
            <button onclick="closeScreeningModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="screeningCandidateId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Candidate Name</label>
                <input type="text" id="screeningCandidateName" readonly style="background:var(--background);">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Role Applied For</label>
                <input type="text" id="screeningRole" readonly style="background:var(--background);">
            </div>
            <div class="field">
                <label>Screening Date</label>
                <input type="date" id="screeningDate">
            </div>
            <div class="field">
                <label>Screening Time</label>
                <input type="time" id="screeningTime">
            </div>
            <div class="field">
                <label>Recruiter</label>
                <select id="screeningRecruiter">
                    <option value="">Select recruiter</option>
                    <?php foreach ($recruiters as $r): ?>
                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Source By</label>
                <input type="text" id="screeningSourceBy" readonly style="background:var(--background);">
            </div>
            <div class="field">
                <label>Screening Status</label>
                <select id="screeningStatus">
                    <option value="Screening Scheduled">Screening Scheduled</option>
                    <option value="Screening Completed">Screening Completed</option>
                    <option value="Screening Cancelled">Screening Cancelled</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Screening Notes</label>
                <textarea id="screeningNotes" rows="3" placeholder="Add screening notes..."></textarea>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Screening Remarks</label>
                <textarea id="screeningRemarks" rows="2" placeholder="Add screening remarks..."></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeScreeningModal()">Cancel</button>
            <button class="btn primary" onclick="saveScreening()">Save Screening</button>
        </div>
    </div>
</div>

<!-- Interview Rounds Modal -->
<div id="interviewRoundsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:900px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Interview Rounds</h3>
            <button onclick="closeInterviewRoundsModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="interviewCandidateId">
        
        <div id="candidateInfo" style="background:var(--background);border-radius:8px;padding:16px;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <span><b>Candidate:</b> <span id="interviewCandidateName">-</span></span>
                <span><b>Role:</b> <span id="interviewRole">-</span></span>
                <span><b>Source By:</b> <span id="interviewSourceBy">-</span></span>
            </div>
        </div>
        
        <div style="display:flex;gap:10px;margin-bottom:20px;">
            <button class="btn sm primary" onclick="addTechnicalRound()">+ Add Technical Round</button>
            <button class="btn sm success" onclick="addHRRound()">+ Add HR Round</button>
        </div>
<!-- Interview Round Form (shown inline when adding/editing a round) -->
        <div id="interviewRoundForm" style="display:none;background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-family:'Space Grotesk';font-size:16px;font-weight:600;margin:0;" id="interviewRoundTitle">Add Interview Round</h3>
                <button onclick="closeInterviewRoundModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
            </div>
            <input type="hidden" id="interviewRoundCandidateId">
            <input type="hidden" id="interviewRoundType">
            <input type="hidden" id="interviewRoundId">
            <div class="form-grid">
                <div class="field" style="grid-column:1/-1;">
                    <label>Round Type</label>
                    <input type="text" id="interviewRoundTypeDisplay" readonly style="background:var(--background);">
                </div>
                <div class="field">
                    <label>Round Number</label>
                    <input type="number" id="interviewRoundNumber" min="1" value="1">
                </div>
                <div class="field">
                    <label>Round Name</label>
                    <input type="text" id="interviewRoundName" placeholder="e.g., Technical Round 1">
                </div>
                <div class="field">
                    <label>Interview Date</label>
                    <input type="date" id="interviewRoundDate">
                </div>
                <div class="field">
                    <label>Interview Time</label>
                    <input type="time" id="interviewRoundTime">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Interviewed By</label>
                    <select id="interviewRoundInterviewer">
                        <option value="">Select interviewer (all employees)</option>
                        <?php foreach ($allEmployees as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Interview Status</label>
                    <select id="interviewRoundStatus">
                        <option value="Scheduled">Scheduled</option>
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div class="field">
                    <label>Next Stage</label>
                    <select id="interviewRoundNextStage">
                        <option value="">Select next stage</option>
                        <option value="Technical Round">Technical Round</option>
                        <option value="HR Round">HR Round</option>
                        <option value="Onboarding">Onboarding</option>
                        <option value="Selected">Selected</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Previous Stage Feedback</label>
                    <textarea id="previousStageFeedback" rows="3" readonly style="background:var(--background);color:var(--muted);" placeholder="No previous stage feedback available..."></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Previous Stage Remarks</label>
                    <textarea id="previousStageRemarks" rows="2" readonly style="background:var(--background);color:var(--muted);" placeholder="No previous stage remarks available..."></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Interview Feedback</label>
                    <textarea id="interviewRoundFeedback" rows="3" placeholder="Add interview feedback..."></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Interview Remarks</label>
                    <textarea id="interviewRoundRemarks" rows="2" placeholder="Add interview remarks..."></textarea>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                <button class="btn" onclick="closeInterviewRoundModal()">Cancel</button>
                <button class="btn primary" onclick="saveInterviewRound()">Save Round</button>
            </div>
        </div>

        <div style="margin-bottom:8px;font-weight:600;font-size:14px;">Saved Interview Rounds</div>
        
        <div id="interviewRoundsList" style="margin-bottom:20px;">
            <!-- Interview rounds will be loaded here -->
        </div>
        
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeInterviewRoundsModal()">Close</button>
        </div>
    </div>
</div>


<script>
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.location = url.toString();
}

function applyFilter(type, value) {
    const url = new URL(window.location);
    if (value) {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    HrmsSearch.navigate(url.toString());
}

function applyScreeningFilter(status) {
    const url = new URL(window.location);
    if (status) {
        url.searchParams.set('screening_status', status);
    } else {
        url.searchParams.delete('screening_status');
    }
    window.location = url.toString();
}

function applyAllStageFilter() {
    const checked = Array.from(document.querySelectorAll('.stage-check:checked')).map(function (cb) { return cb.value; });
    document.querySelectorAll('.all-stage-section').forEach(function (sec) {
        const stage = sec.getAttribute('data-stage');
        sec.style.display = (checked.length === 0 || checked.indexOf(stage) !== -1) ? '' : 'none';
    });
}

let filterTimeout;
function filterCandidatesDebounced(input) {
    // Live-value debounce: use the input that actually fired (several tabs
    // reuse the same id="candidateSearch"), never a stale keyup value.
    var el = input || document.getElementById('candidateSearch');
    if (!el) return;
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(function () {
        applyFilter('search', el.value);
    }, 450);
}

function clearFilters() {
    const url = new URL(window.location);
    url.searchParams.delete('search');
    url.searchParams.delete('source');
    url.searchParams.delete('position');
    url.searchParams.delete('source_by');
    url.searchParams.delete('screening_status');
    window.location = url.toString();
}

let currentCandidateDetailId = null;

function openCandidateModal(candidate) {
    const isEdit = !!candidate && candidate.id;
    document.getElementById('candidateModalTitle').textContent = isEdit ? 'Edit Candidate' : 'Add Candidate';
    document.getElementById('candidateId').value = candidate ? candidate.id : '';
    document.getElementById('candFirst').value = candidate ? (candidate.first_name || '') : '';
    document.getElementById('candLast').value = candidate ? (candidate.last_name || '') : '';
    document.getElementById('candEmail').value = candidate ? (candidate.email || '') : '';
    document.getElementById('candPhone').value = candidate ? (candidate.phone || '') : '';
    document.getElementById('candPosition').value = candidate ? (candidate.position_applied || '') : '';
    document.getElementById('candSource').value = candidate ? (candidate.source || '') : '';
    
    // Set source_by value if element exists and candidate has the data
    const sourceByElement = document.getElementById('candSourceBy');
    if (sourceByElement) {
        sourceByElement.value = (candidate && candidate.source_by_id) ? candidate.source_by_id : '';
    }
    
    document.getElementById('candStage').value = candidate ? (candidate.stage_name || 'Sourcing') : 'Sourcing';
    document.getElementById('candType').value = candidate ? (candidate.candidate_type || 'Fresher') : 'Fresher';
    document.getElementById('candCurrentSalary').value = candidate ? (candidate.current_salary || '') : '';
    document.getElementById('candExpectedSalary').value = candidate ? (candidate.expected_salary || '') : '';
    document.getElementById('candNotice').value = candidate ? (candidate.notice_period || '') : '';
    document.getElementById('candLocation').value = candidate ? (candidate.location || '') : '';
    document.getElementById('candRelocate').value = candidate ? (candidate.relocate || '') : '';
    document.getElementById('candNotes').value = candidate ? (candidate.notes || '') : '';
    document.getElementById('candidateModal').style.display = 'flex';
}

function addCandidate() {
    openCandidateModal(null);
}

function editCandidate(id) {
    fetch(`api/recruitment.php?candidate_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openCandidateModal(data.candidate);
            } else {
                alert('Error loading candidate: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading candidate');
        });
}

function closeCandidateModal() {
    document.getElementById('candidateModal').style.display = 'none';
}

function saveCandidate() {
    const id = document.getElementById('candidateId').value;
    const first = document.getElementById('candFirst').value.trim();
    if (!first) {
        alert('First name is required');
        return;
    }
    if (!document.getElementById('candPhone').value.trim()) {
        alert('Phone is required');
        return;
    }
    
    const payload = {
        action: 'save',
        id: id || null,
        first_name: first,
        last_name: document.getElementById('candLast').value.trim(),
        email: document.getElementById('candEmail').value.trim(),
        phone: document.getElementById('candPhone').value.trim(),
        position_applied: document.getElementById('candPosition').value,
        source: document.getElementById('candSource').value,
        stage: document.getElementById('candStage').value,
        candidate_type: document.getElementById('candType').value,
        current_salary: document.getElementById('candCurrentSalary').value,
        expected_salary: document.getElementById('candExpectedSalary').value,
        notice_period: document.getElementById('candNotice').value,
        location: document.getElementById('candLocation').value,
        relocate: document.getElementById('candRelocate').value,
        notes: document.getElementById('candNotes').value
    };
    
    // Send source_by_id (HR employee who sourced the candidate).
    // Always sent so an empty selection also clears a previously saved value.
    const sourceByElement = document.getElementById('candSourceBy');
    payload.source_by_id = sourceByElement ? (sourceByElement.value || null) : null;
    
    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeCandidateModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not save candidate'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving candidate');
    });
}

function openCandidateDetail(id) {
    fetch(`api/recruitment.php?candidate_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Could not load candidate'));
                return;
            }
            const c = data.candidate;
            currentCandidateDetailId = id;
            const history = (c.history && c.history.length)
                ? c.history.map(h => `<div style="padding:6px 0;border-bottom:1px solid var(--border);">
                        <b>${h.from_stage || '—'}</b> → <b>${h.to_stage || '—'}</b>
                        <div style="font-size:11px;color:var(--faint);">${h.first_name ? h.first_name + ' ' + h.last_name + ' · ' : ''}${h.created_at ? new Date(h.created_at).toLocaleString() : ''}${h.notes ? ' · ' + h.notes : ''}</div>
                    </div>`).join('')
                : '<div style="color:var(--faint);">No stage history yet.</div>';
            document.getElementById('candidateDetailTitle').textContent = c.first_name + ' ' + (c.last_name || '');
            document.getElementById('candidateDetailBody').innerHTML = `
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span><b>Stage:</b> <span class="badge pending">${c.stage_name || '—'}</span></span>
                        <span><b>Position:</b> ${c.position_applied || '—'}</span>
                        <span><b>Source:</b> ${c.source || '—'}</span>
                    </div>
                    <div style="margin-top:8px;"><b>Email:</b> ${c.email}</div>
                    <div><b>Phone:</b> ${c.phone}</div>
                    ${c.assigned_recruiter_id ? '<div><b>Recruiter:</b> ' + (c.recruiter_first || '') + ' ' + (c.recruiter_last || '') + '</div>' : ''}
                    ${c.source_by_first ? '<div><b>Source By:</b> ' + c.source_by_first + ' ' + (c.source_by_last || '') + '</div>' : ''}
                    <div style="margin-top:8px;">
                        <b>Salary:</b> Current ₹${c.current_salary ? Number(c.current_salary).toLocaleString() : '0'} · Expected ₹${c.expected_salary ? Number(c.expected_salary).toLocaleString() : '0'} · Notice: ${c.notice_period ? c.notice_period + ' days' : '—'}
                    </div>
                    ${c.location || c.relocate ? '<div style="margin-top:4px;"><b>Location:</b> ' + (c.location || '—') + ' &nbsp;·&nbsp; <b>Relocate:</b> ' + (c.relocate || '—') + '</div>' : ''}
                </div>
                ${c.notes ? '<p style="color:var(--ink);"><b>Notes:</b><br>' + c.notes.replace(/\\n/g, '<br>') + '</p>' : ''}
                <h4 style="font-size:13px;font-weight:600;margin:12px 0 8px;color:var(--ink);">Stage History</h4>
                <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;padding:12px;">${history}</div>
            `;
            document.getElementById('candidateEditBtn').style.display = 'inline-block';
            document.getElementById('candidateDetailModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading candidate');
        });
}

function viewCandidate(id) {
    openCandidateDetail(id);
}

function closeCandidateDetailModal() {
    document.getElementById('candidateDetailModal').style.display = 'none';
}

function postCandidateAction(id, action, extra, redirectTab) {
    const payload = Object.assign({ action: action, id: id }, extra || {});
    return fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (redirectTab) {
                switchTab(redirectTab);
            } else {
                location.reload();
            }
        } else {
            alert('Error: ' + (data.message || 'Action failed'));
        }
        return data.success;
    })
    .catch(err => {
        console.error(err);
        alert('Error performing action');
        return false;
    });
}

function blacklistCandidate(id) {
    if (!ModalSystem.confirm({
        message: 'Move this candidate to the Blacklist?',
        destructive: true,
        confirmText: 'Blacklist'
    })) return;
    postCandidateAction(id, 'blacklist', null, 'blacklisted');
}

function hireCandidate(id) {
    if (!ModalSystem.confirm({ 
        message: 'Confirm this candidate for hiring? They will move to Onboarding.',
        confirmText: 'Hire' 
    })) return;
    postCandidateAction(id, 'hire');
}

function moveToScreening(id) {
    if (!ModalSystem.confirm({ 
        message: 'Move this candidate to Screening stage?',
        confirmText: 'Move to Screening' 
    })) return;
    postCandidateAction(id, 'advance', { to_stage: 'Screening' });
}

// Screening Modal Functions
function openScreeningModal(candidateId) {
    fetch(`api/recruitment.php?action=get_screening_details&candidate_id=${candidateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.message && data.message.includes('migration')) {
                alert('Please run the database migration first. Open: migrate_recruitment.php');
                return;
            }
            
            if (data.success && data.screening) {
                const s = data.screening;
                document.getElementById('screeningCandidateId').value = candidateId;
                document.getElementById('screeningDate').value = s.screening_date || '';
                document.getElementById('screeningTime').value = s.screening_time || '';
                document.getElementById('screeningRecruiter').value = s.recruiter_id || '';
                document.getElementById('screeningStatus').value = s.screening_status || 'Screening Scheduled';
                document.getElementById('screeningNotes').value = s.screening_notes || '';
                document.getElementById('screeningRemarks').value = s.screening_remarks || '';
            } else {
                // Reset form for new screening
                document.getElementById('screeningCandidateId').value = candidateId;
                document.getElementById('screeningDate').value = '';
                document.getElementById('screeningTime').value = '';
                document.getElementById('screeningRecruiter').value = '';
                document.getElementById('screeningStatus').value = 'Screening Scheduled';
                document.getElementById('screeningNotes').value = '';
                document.getElementById('screeningRemarks').value = '';
            }
            
            // Load candidate info
            fetch(`api/recruitment.php?candidate_id=${candidateId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const c = data.candidate;
                        document.getElementById('screeningCandidateName').value = c.first_name + ' ' + c.last_name;
                        document.getElementById('screeningRole').value = c.position_applied || '';
                        document.getElementById('screeningSourceBy').value = c.source_by_first ? c.source_by_first + ' ' + (c.source_by_last || '') : 'Not assigned';
                    }
                });
            
            document.getElementById('screeningModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading screening details');
        });
}

function closeScreeningModal() {
    document.getElementById('screeningModal').style.display = 'none';
}

function saveScreening() {
    const candidateId = document.getElementById('screeningCandidateId').value;
    const payload = {
        action: 'save_screening',
        id: candidateId,
        screening_date: document.getElementById('screeningDate').value,
        screening_time: document.getElementById('screeningTime').value,
        recruiter_id: document.getElementById('screeningRecruiter').value,
        screening_status: document.getElementById('screeningStatus').value,
        screening_notes: document.getElementById('screeningNotes').value,
        screening_remarks: document.getElementById('screeningRemarks').value
    };
    
    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.message && data.message.includes('migration')) {
            alert('Please run the database migration first. Open: migrate_recruitment.php');
            return;
        }
        
        if (data.success) {
            closeScreeningModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not save screening'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving screening');
    });
}

// Interview Rounds Modal Functions
function openInterviewRoundsModal(candidateId) {
    document.getElementById('interviewCandidateId').value = candidateId;
    
    // Load candidate info
    fetch(`api/recruitment.php?candidate_id=${candidateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const c = data.candidate;
                document.getElementById('interviewCandidateName').textContent = c.first_name + ' ' + c.last_name;
                document.getElementById('interviewRole').textContent = c.position_applied || '-';
                document.getElementById('interviewSourceBy').textContent = c.source_by_first ? c.source_by_first + ' ' + (c.source_by_last || '') : 'Not assigned';
            }
        });
    
    // Load interview rounds
    loadInterviewRounds(candidateId);
    
    document.getElementById('interviewRoundsModal').style.display = 'flex';
}

function closeInterviewRoundsModal() {
    document.getElementById('interviewRoundsModal').style.display = 'none';
    const roundForm = document.getElementById('interviewRoundForm');
    if (roundForm) roundForm.style.display = 'none';
}

function loadInterviewRounds(candidateId) {
    fetch(`api/recruitment.php?action=get_interview_rounds&candidate_id=${candidateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayInterviewRounds(data.rounds);
            }
        })
        .catch(err => {
            console.error(err);
        });
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

function toggleRound(id) {
    const detail = document.getElementById('roundDetail_' + id);
    const arrow = document.getElementById('roundArrow_' + id);
    if (detail) {
        const expanded = detail.style.display !== 'none';
        detail.style.display = expanded ? 'none' : 'block';
        if (arrow) arrow.innerHTML = expanded ? '&#9662;' : '&#9652;';
    }
}

function displayInterviewRounds(rounds) {
    const container = document.getElementById('interviewRoundsList');
    
    if (!rounds || rounds.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);">No interview rounds scheduled yet</div>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:10px;">';
    
    rounds.forEach(round => {
        const status = escapeHtml(round.interview_status || '-');
        const statusColor = round.interview_status === 'Completed' ? 'success' :
                           round.interview_status === 'Rejected' ? 'danger' :
                           round.interview_status === 'Scheduled' ? 'info' : 'pending';
        const canonicalName = (round.round_type || '') + ' Round ' + (round.round_number != null ? round.round_number : 1);
        const storedName = round.round_name ? String(round.round_name).trim() : '';
        const roundName = escapeHtml(canonicalName);
        const roundNameSecondary = storedName && storedName !== canonicalName ? escapeHtml(storedName) : '';
        const roundNumber = escapeHtml(round.round_number || '-');
        const dispDate = escapeHtml(round.interview_date || '-');
        const dispTime = escapeHtml(round.interview_time ? round.interview_time.substring(0, 5) : '-');
        const interviewer = escapeHtml(round.interviewer_first ? round.interviewer_first + ' ' + (round.interviewer_last || '') : '-');
        const nextStage = escapeHtml(round.next_stage || '-');
        const feedback = escapeHtml(round.interview_feedback);
        const remarks = escapeHtml(round.interview_remarks);
        const prevFeedback = escapeHtml(round.previous_stage_feedback);
        const prevRemarks = escapeHtml(round.previous_stage_remarks);
        
        html += `
            <div style="background:var(--background);border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                <div onclick="toggleRound(${round.id})" style="cursor:pointer;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;" title="Click to expand/collapse details">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-weight:600;">${roundName}</span>
                        ${roundNameSecondary ? `<span style="color:var(--muted);font-size:13px;">(${roundNameSecondary})</span>` : ''}
                        <span class="badge ${statusColor}">${status}</span>
                    </div>
                    <span id="roundArrow_${round.id}" style="color:var(--muted);font-size:14px;">&#9662;</span>
                </div>
                <div id="roundDetail_${round.id}" style="display:none;padding:14px 16px;border-top:1px solid var(--border);">
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;font-size:13px;">
                        <div><b>Round:</b> ${roundName}</div>
                        <div><b>Round Number:</b> ${roundNumber}</div>
                        <div><b>Interview Date:</b> ${dispDate}</div>
                        <div><b>Interview Time:</b> ${dispTime}</div>
                        <div><b>Interviewed By:</b> ${interviewer}</div>
                        <div><b>Status:</b> ${status}</div>
                        <div><b>Next Stage:</b> ${nextStage}</div>
                    </div>
                    ${prevFeedback ? `<div style="margin-top:10px;font-size:13px;"><b>Previous Stage Feedback:</b><div style="white-space:pre-wrap;margin-top:2px;">${prevFeedback}</div></div>` : ''}
                    ${prevRemarks ? `<div style="margin-top:10px;font-size:13px;"><b>Previous Stage Remarks:</b><div style="white-space:pre-wrap;margin-top:2px;">${prevRemarks}</div></div>` : ''}
                    ${feedback ? `<div style="margin-top:10px;font-size:13px;"><b>Feedback:</b><div style="white-space:pre-wrap;margin-top:2px;">${feedback}</div></div>` : ''}
                    ${remarks ? `<div style="margin-top:10px;font-size:13px;"><b>Remarks:</b><div style="white-space:pre-wrap;margin-top:2px;">${remarks}</div></div>` : ''}
                    <div style="margin-top:12px;display:flex;gap:8px;">
                        <button class="btn sm" onclick="editInterviewRound(${round.id})">Edit</button>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

function addTechnicalRound() {
    const candidateId = document.getElementById('interviewCandidateId').value;
    openInterviewRoundModal(candidateId, 'Technical');
}

function addHRRound() {
    const candidateId = document.getElementById('interviewCandidateId').value;
    openInterviewRoundModal(candidateId, 'HR');
}

function openInterviewRoundModal(candidateId, roundType, roundData = null) {
    document.getElementById('interviewRoundCandidateId').value = candidateId;
    document.getElementById('interviewRoundType').value = roundType;
    document.getElementById('interviewRoundTypeDisplay').value = roundType + ' Round';
    document.getElementById('interviewRoundId').value = roundData ? roundData.id : '';
    
    if (roundData) {
        document.getElementById('interviewRoundNumber').value = roundData.round_number;
        document.getElementById('interviewRoundName').value = roundData.round_name || '';
        document.getElementById('interviewRoundDate').value = roundData.interview_date || '';
        document.getElementById('interviewRoundTime').value = roundData.interview_time || '';
        document.getElementById('interviewRoundInterviewer').value = roundData.interviewer_id || '';
        document.getElementById('interviewRoundStatus').value = roundData.interview_status || 'Scheduled';
        document.getElementById('interviewRoundNextStage').value = roundData.next_stage || '';
        document.getElementById('interviewRoundFeedback').value = roundData.interview_feedback || '';
        document.getElementById('interviewRoundRemarks').value = roundData.interview_remarks || '';
        document.getElementById('previousStageFeedback').value = roundData.previous_stage_feedback || '';
        document.getElementById('previousStageRemarks').value = roundData.previous_stage_remarks || '';
        document.getElementById('interviewRoundTitle').textContent = 'Edit Interview Round';
    } else {
        // Auto-generate round number and fetch previous stage feedback
        fetch(`api/recruitment.php?action=get_interview_rounds&candidate_id=${candidateId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const sameTypeRounds = data.rounds.filter(r => r.round_type === roundType);
                    const nextRoundNumber = sameTypeRounds.length + 1;
                    document.getElementById('interviewRoundNumber').value = nextRoundNumber;
                    document.getElementById('interviewRoundName').value = `${roundType} Round ${nextRoundNumber}`;
                    
                    // Get the most recent round's feedback for carry-forward
                    const allRounds = data.rounds;
                    if (allRounds.length > 0) {
                        const mostRecentRound = allRounds[allRounds.length - 1];
                        document.getElementById('previousStageFeedback').value = mostRecentRound.interview_feedback || '';
                        document.getElementById('previousStageRemarks').value = mostRecentRound.interview_remarks || '';
                    } else {
                        // No previous interview rounds, check screening
                        fetch(`api/recruitment.php?action=get_screening_details&candidate_id=${candidateId}`)
                            .then(r => r.json())
                            .then(screeningData => {
                                if (screeningData.success && screeningData.screening) {
                                    document.getElementById('previousStageFeedback').value = screeningData.screening.screening_notes || '';
                                    document.getElementById('previousStageRemarks').value = screeningData.screening.screening_remarks || '';
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                document.getElementById('previousStageFeedback').value = '';
                                document.getElementById('previousStageRemarks').value = '';
                            });
                    }
                } else {
                    document.getElementById('interviewRoundNumber').value = 1;
                    document.getElementById('interviewRoundName').value = `${roundType} Round 1`;
                }
            })
            .catch(err => {
                console.error(err);
                document.getElementById('interviewRoundNumber').value = 1;
                document.getElementById('interviewRoundName').value = `${roundType} Round 1`;
                document.getElementById('previousStageFeedback').value = '';
                document.getElementById('previousStageRemarks').value = '';
            });
        
        document.getElementById('interviewRoundDate').value = '';
        document.getElementById('interviewRoundTime').value = '';
        document.getElementById('interviewRoundInterviewer').value = '';
        document.getElementById('interviewRoundStatus').value = 'Scheduled';
        document.getElementById('interviewRoundNextStage').value = '';
        document.getElementById('interviewRoundFeedback').value = '';
        document.getElementById('interviewRoundRemarks').value = '';
        document.getElementById('previousStageFeedback').value = '';
        document.getElementById('previousStageRemarks').value = '';
        document.getElementById('interviewRoundTitle').textContent = 'Add Interview Round';
    }
    
    const roundForm = document.getElementById('interviewRoundForm');
    if (roundForm) {
        roundForm.style.display = 'block';
        roundForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeInterviewRoundModal() {
    document.getElementById('interviewRoundForm').style.display = 'none';
}

function saveInterviewRound() {
    const roundId = document.getElementById('interviewRoundId').value;
    const candidateId = document.getElementById('interviewRoundCandidateId').value;
    
    let payload = {
        action: roundId ? 'update_interview_round' : 'save_interview_round',
        candidate_id: candidateId,
        round_id: roundId || null,
        interview_status: document.getElementById('interviewRoundStatus').value,
        interview_feedback: document.getElementById('interviewRoundFeedback').value,
        interview_remarks: document.getElementById('interviewRoundRemarks').value,
        next_stage: document.getElementById('interviewRoundNextStage').value
    };
    
    // Only include these fields for new rounds
    if (!roundId) {
        payload.round_type = document.getElementById('interviewRoundType').value;
        payload.round_number = document.getElementById('interviewRoundNumber').value;
        payload.round_name = document.getElementById('interviewRoundName').value;
        payload.interview_date = document.getElementById('interviewRoundDate').value;
        payload.interview_time = document.getElementById('interviewRoundTime').value;
        payload.interviewer_id = document.getElementById('interviewRoundInterviewer').value;
    } else {
        // For updates, always send all editable fields
        payload.interview_date = document.getElementById('interviewRoundDate').value;
        payload.interview_time = document.getElementById('interviewRoundTime').value;
        payload.interviewer_id = document.getElementById('interviewRoundInterviewer').value;
        payload.round_name = document.getElementById('interviewRoundName').value;
    }
    
    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Only after the database save succeeds do we:
            // 1) close the round form, 2) reload rounds from the DB, 3) notify the user
            closeInterviewRoundModal();
            loadInterviewRounds(candidateId);
            alert(data.message || 'Interview round saved successfully');
        } else {
            alert('Error: ' + (data.message || 'Could not save interview round'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error saving interview round');
    });
}

function editInterviewRound(roundId) {
    // Find the round data from the displayed rounds
    fetch(`api/recruitment.php?action=get_interview_rounds&candidate_id=${document.getElementById('interviewCandidateId').value}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const round = data.rounds.find(r => r.id === roundId);
                if (round) {
                    openInterviewRoundModal(document.getElementById('interviewCandidateId').value, round.round_type, round);
                }
            }
        });
}

function moveToInterview(id) {
    if (!ModalSystem.confirm({ 
        message: 'Move this candidate to Interview stage?',
        confirmText: 'Move to Interview' 
    })) return;
    postCandidateAction(id, 'advance', { to_stage: 'Interview' });
}

function deleteCandidate(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this candidate? This action cannot be undone.',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    
    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not delete candidate'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error deleting candidate');
    });
}

// Onboarding Documents Modal Functions
function openOnboardingDocumentsModal(candidateId, employeeId) {
    // Create the modal if it doesn't exist
    if (!document.getElementById('onboardingDocumentsModal')) {
        const modalHTML = `
            <div id="onboardingDocumentsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1001;align-items:center;justify-content:center;">
                <div style="background:var(--surface);border-radius:var(--radius);width:720px;max-width:95vw;max-height:92vh;overflow-y:auto;padding:24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Upload Onboarding Documents</h3>
                        <button onclick="closeOnboardingDocumentsModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
                    </div>
                    <input type="hidden" id="onboardingCandidateId">
                    <input type="hidden" id="onboardingEmployeeId">
                    <input type="hidden" id="candidateType">
                    
                    <div id="mandatoryDocumentsList" style="margin-bottom:20px;">
                        <!-- Mandatory documents will be dynamically loaded here -->
                    </div>
                    
                    <div id="documentUploadForm">
                        <div class="field">
                            <label>Select Document Type <span class="req">*</span></label>
                            <select id="documentType" required>
                                <option value="">Select document type</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Upload Document <span class="req">*</span></label>
                            <input type="file" id="documentFile" required>
                        </div>
                        <button type="button" class="btn primary" onclick="uploadOnboardingDocument()">Upload Document</button>
                    </div>
                    
                    <div style="margin-top:24px;">
                        <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 16px 0;color:var(--accent);">Uploaded Documents</h4>
                        <div id="uploadedDocumentsList" style="background:var(--background);border-radius:8px;padding:16px;">
                            <div style="color:var(--muted);text-align:center;">No documents uploaded yet</div>
                        </div>
                    </div>
                    
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                        <button class="btn" onclick="closeOnboardingDocumentsModal()">Cancel</button>
                        <button class="btn primary" onclick="completeOnboarding()">Complete Onboarding & Create Employee</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
    
    document.getElementById('onboardingCandidateId').value = candidateId;
    document.getElementById('onboardingEmployeeId').value = employeeId || '';
    
    // Initialize temporary document storage
    window.tempUploadedDocuments = [];
    
    // Fetch candidate type
    fetch(`api/recruitment.php?candidate_id=${candidateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const candidateType = data.candidate.candidate_type || 'Fresher';
                document.getElementById('candidateType').value = candidateType;
                loadMandatoryOnboardingDocuments(candidateType);
                if (employeeId) {
                    loadUploadedOnboardingDocuments(employeeId);
                }
                document.getElementById('onboardingDocumentsModal').style.display = 'flex';
            } else {
                alert('Error loading candidate information');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading candidate information');
        });
}

function closeOnboardingDocumentsModal() {
    document.getElementById('onboardingDocumentsModal').style.display = 'none';
}

function loadMandatoryOnboardingDocuments(candidateType) {
    const mandatoryDocs = candidateType === 'Experienced' 
        ? ['PAN Card', 'AADHAR Card', 'Relieving Letter', 'Offer Letter', 'Payslip', 'Educational Documents']
        : ['PAN Card', 'AADHAR Card', 'Educational Documents'];
    
    const container = document.getElementById('mandatoryDocumentsList');
    container.innerHTML = '<h4 style="font-family:\'Space Grotesk\';font-size:14px;font-weight:600;margin:0 0 12px 0;color:var(--accent);">Mandatory Documents</h4>';
    
    mandatoryDocs.forEach(doc => {
        container.innerHTML += `
            <div class="document-item" data-document="${doc}" style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--background);border-radius:8px;margin-bottom:8px;">
                <div style="width:32px;height:32px;background:var(--accent-ink);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-file-earmark" style="color:var(--accent-dark);"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:500;">${doc}</div>
                    <div class="document-status" style="font-size:12px;color:var(--faint);">Not uploaded</div>
                </div>
                <div class="upload-status" style="color:var(--success);display:none;">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
        `;
    });
    
    // Populate document type dropdown
    const docTypeSelect = document.getElementById('documentType');
    docTypeSelect.innerHTML = '<option value="">Select document type</option>';
    mandatoryDocs.forEach(doc => {
        docTypeSelect.innerHTML += `<option value="${doc}">${doc}</option>`;
    });
}

function loadUploadedOnboardingDocuments(employeeId) {
    if (!employeeId) return;
    
    fetch(`api/onboarding.php?employee_id=${employeeId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.documents.length > 0) {
                const container = document.getElementById('uploadedDocumentsList');
                container.innerHTML = '';
                
                data.documents.forEach(doc => {
                    // Update document status in mandatory list
                    const docItem = document.querySelector(`.document-item[data-document="${doc.document_type}"]`);
                    if (docItem) {
                        docItem.querySelector('.document-status').textContent = 'Uploaded';
                        docItem.querySelector('.document-status').style.color = 'var(--success)';
                        docItem.querySelector('.upload-status').style.display = 'block';
                    }
                    
                    container.innerHTML += `
                        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);">
                            <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                            <div style="flex:1;">
                                <div style="font-weight:500;">${doc.document_name}</div>
                                <div style="font-size:11px;color:var(--faint);">${doc.document_type}</div>
                            </div>
                            <button class="btn sm danger" onclick="deleteOnboardingDocument(${doc.id})">Delete</button>
                        </div>
                    `;
                });
            } else {
                document.getElementById('uploadedDocumentsList').innerHTML = '<div style="color:var(--muted);text-align:center;">No documents uploaded yet</div>';
            }
        })
        .catch(err => {
            console.error(err);
        });
}

function deleteOnboardingDocument(documentId) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this document?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    
    fetch('api/onboarding.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_document', id: documentId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const employeeId = document.getElementById('onboardingEmployeeId').value;
            loadUploadedOnboardingDocuments(employeeId);
        } else {
            alert('Error: ' + (data.message || 'Could not delete document'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error deleting document');
    });
}

function uploadOnboardingDocument() {
    console.log('uploadOnboardingDocument called');
    
    const candidateId = document.getElementById('onboardingCandidateId').value;
    const documentType = document.getElementById('documentType').value;
    const fileInput = document.getElementById('documentFile');
    
    console.log('candidateId:', candidateId);
    console.log('documentType:', documentType);
    console.log('fileInput:', fileInput);
    console.log('fileInput.files:', fileInput.files);
    
    if (!documentType) {
        alert('Please select document type');
        return;
    }
    
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert('Please select a file');
        return;
    }
    
    // Since we don't have employee ID yet, store the file in memory
    const file = fileInput.files[0];
    const documentData = {
        document_type: documentType,
        document_name: file.name,
        file: file
    };
    
    console.log('Document data:', documentData);
    
    // Add to temporary storage
    if (!window.tempUploadedDocuments) {
        window.tempUploadedDocuments = [];
    }
    window.tempUploadedDocuments.push(documentData);
    
    console.log('tempUploadedDocuments:', window.tempUploadedDocuments);
    
    // Update UI
    const container = document.getElementById('uploadedDocumentsList');
    container.innerHTML = '';
    
    window.tempUploadedDocuments.forEach((doc, index) => {
        container.innerHTML += `
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);">
                <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                <div style="flex:1;">
                    <div style="font-weight:500;">${doc.document_name}</div>
                    <div style="font-size:11px;color:var(--faint);">${doc.document_type}</div>
                </div>
                <button class="btn sm danger" onclick="removeTempDocument(${index})">Remove</button>
            </div>
        `;
    });
    
    // Update mandatory document status
    const docItem = document.querySelector(`.document-item[data-document="${documentType}"]`);
    if (docItem) {
        docItem.querySelector('.document-status').textContent = 'Uploaded';
        docItem.querySelector('.document-status').style.color = 'var(--success)';
        docItem.querySelector('.upload-status').style.display = 'block';
    }
    
    // Clear form
    fileInput.value = '';
    document.getElementById('documentType').value = '';
    
    console.log('Upload completed');
}

function removeTempDocument(index) {
    const doc = window.tempUploadedDocuments[index];
    window.tempUploadedDocuments.splice(index, 1);
    
    // Update mandatory document status
    const docItem = document.querySelector(`.document-item[data-document="${doc.document_type}"]`);
    if (docItem) {
        docItem.querySelector('.document-status').textContent = 'Not uploaded';
        docItem.querySelector('.document-status').style.color = 'var(--faint)';
        docItem.querySelector('.upload-status').style.display = 'none';
    }
    
    // Update UI
    const container = document.getElementById('uploadedDocumentsList');
    if (window.tempUploadedDocuments.length === 0) {
        container.innerHTML = '<div style="color:var(--muted);text-align:center;">No documents uploaded yet</div>';
    } else {
        container.innerHTML = '';
        window.tempUploadedDocuments.forEach((doc, idx) => {
            container.innerHTML += `
                <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);">
                    <i class="bi bi-file-earmark" style="color:var(--accent);"></i>
                    <div style="flex:1;">
                        <div style="font-weight:500;">${doc.document_name}</div>
                        <div style="font-size:11px;color:var(--faint);">${doc.document_type}</div>
                    </div>
                    <button class="btn sm danger" onclick="removeTempDocument(${idx})">Remove</button>
                </div>
            `;
        });
    }
}

function completeOnboarding() {
    const candidateId = document.getElementById('onboardingCandidateId').value;
    const candidateType = document.getElementById('candidateType').value;
    
    // Check if all mandatory documents are uploaded
    const mandatoryDocs = candidateType === 'Experienced' 
        ? ['PAN Card', 'AADHAR Card', 'Relieving Letter', 'Offer Letter', 'Payslip', 'Educational Documents']
        : ['PAN Card', 'AADHAR Card', 'Educational Documents'];
    
    const uploadedDocs = [];
    window.tempUploadedDocuments.forEach(doc => {
        uploadedDocs.push(doc.document_type);
    });
    
    const missingDocs = mandatoryDocs.filter(doc => !uploadedDocs.includes(doc));
    
    if (missingDocs.length > 0) {
        alert('Please upload all mandatory documents before completing onboarding:\n\nMissing: ' + missingDocs.join(', '));
        return;
    }
    
    // First, create the employee
    if (!window.employeeFormData) {
        alert('Employee data not found. Please fill the employee form first.');
        return;
    }
    
    const formData = window.employeeFormData;
    
    fetch('api/employee_onboarding.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Employee created:', data);
        
        if (data.success) {
            const employeeId = data.id;
            
            // Now upload all documents
            uploadAllDocuments(candidateId, employeeId, window.tempUploadedDocuments);
        } else {
            alert('Error creating employee: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating employee: ' + error.message);
    });
}

function uploadAllDocuments(candidateId, employeeId, documents) {
    let uploadedCount = 0;
    let failedCount = 0;
    
    documents.forEach((doc, index) => {
        const formData = new FormData();
        formData.append('action', 'upload_document');
        formData.append('candidate_id', candidateId);
        formData.append('employee_id', employeeId);
        formData.append('document_type', doc.document_type);
        formData.append('document', doc.file);
        
        fetch('api/onboarding.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                uploadedCount++;
            } else {
                failedCount++;
            }
            
            // Check if all documents are processed
            if (uploadedCount + failedCount === documents.length) {
                if (failedCount === 0) {
                    // All documents uploaded successfully
                    markOnboardingComplete(candidateId, employeeId);
                } else {
                    alert(`${failedCount} document(s) failed to upload. Employee was created but some documents may be missing.`);
                    markOnboardingComplete(candidateId, employeeId);
                }
            }
        })
        .catch(err => {
            console.error('Error uploading document:', err);
            failedCount++;
            
            if (uploadedCount + failedCount === documents.length) {
                alert('Some documents failed to upload. Employee was created.');
                markOnboardingComplete(candidateId, employeeId);
            }
        });
    });
}

function markOnboardingComplete(candidateId, employeeId) {
    // Mark onboarding as complete
    fetch('api/onboarding.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'complete_onboarding',
            candidate_id: candidateId,
            employee_id: employeeId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeOnboardingDocumentsModal();
            ModalSystem.success('Onboarding completed successfully! Employee has been created.');
            location.reload();
        } else {
            ModalSystem.error('Onboarding marked as complete but there was an error: ' + (data.message || 'Unknown error'));
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error completing onboarding');
        location.reload();
    });
}

function sendOffer(id) {
    if (!ModalSystem.confirm({ 
        message: 'Send an offer to this candidate? They will move to Onboarding.',
        confirmText: 'Send Offer' 
    })) return;
    postCandidateAction(id, 'send_offer');
}

function removeFromBlacklist(id) {
    if (!ModalSystem.confirm({ 
        message: 'Remove this candidate from the blacklist?',
        confirmText: 'Remove' 
    })) return;
    postCandidateAction(id, 'remove_blacklist');
}

function openSimpleInterviewModal(id) {
    document.getElementById('interviewCandidateId').value = id;
    document.getElementById('interviewDatetime').value = '';
    document.getElementById('interviewer').value = '';
    document.getElementById('interviewModal').style.display = 'flex';
}

function scheduleInterview(id) {
    openSimpleInterviewModal(id);
}

function closeSimpleInterviewModal() {
    document.getElementById('interviewModal').style.display = 'none';
}

function saveInterview() {
    const id = document.getElementById('interviewCandidateId').value;
    const dt = document.getElementById('interviewDatetime').value;
    const interviewer = document.getElementById('interviewer').value;
    if (!dt) {
        alert('Please select an interview date & time.');
        return;
    }
    const payload = {
        action: 'schedule_interview',
        id: id,
        interview_datetime: dt.replace('T', ' '),
        interviewer_id: interviewer
    };
    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeSimpleInterviewModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not schedule interview'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error scheduling interview');
    });
}

function addAsEmployee(id) {
    // Open employee form as modal within recruitment module
    fetch(`api/recruitment.php?candidate_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const candidate = data.candidate;
                openEmployeeFormInRecruitment(candidate);
            } else {
                alert('Error loading candidate: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading candidate');
        });
}

// Offboarding – Last Working Day flow
function offboardCandidate(id) {
    fetch(`api/recruitment.php?action=get_linked_employee&candidate_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                ModalSystem.error('Error: ' + (data.message || 'Could not load employee details'));
                return;
            }
            if (!data.employee) {
                ModalSystem.error('No linked employee found for this candidate. Use "Add as Employee" to create the employee first.');
                return;
            }
            // Prevent duplicate offboarding actions if the candidate is already offboarded
            if (data.candidate_stage && data.candidate_stage === 'Offboarding') {
                ModalSystem.error('This candidate is already offboarded.');
                return;
            }
            document.getElementById('offboardCandidateId').value = id;
            document.getElementById('offboardEmployeeId').value = data.employee.id;
            document.getElementById('offboardEmployeeLabel').value = data.employee.first_name + ' ' + (data.employee.last_name || '') + ' (' + data.employee.employee_id + ')';
            document.getElementById('offboardLastWorkingDay').value = data.employee.last_working_day || '';
            document.getElementById('offboardModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Error loading employee details');
        });
}

function closeOffboardModal() {
    document.getElementById('offboardModal').style.display = 'none';
}

function saveOffboard() {
    const lwd = document.getElementById('offboardLastWorkingDay').value;
    const employeeId = document.getElementById('offboardEmployeeId').value;
    const candidateId = document.getElementById('offboardCandidateId').value;

    if (!lwd) {
        ModalSystem.warning('Please select a valid Last Working Day.');
        return;
    }

    // Validate that the selected value is a real calendar date (YYYY-MM-DD)
    const m = lwd.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) {
        ModalSystem.warning('Please select a valid Last Working Day.');
        return;
    }
    const y = parseInt(m[1], 10), mo = parseInt(m[2], 10), d = parseInt(m[3], 10);
    const dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
        ModalSystem.warning('Please select a valid Last Working Day.');
        return;
    }

    if (!employeeId) {
        ModalSystem.error('No linked employee found for this candidate. Use "Add as Employee" to create the employee first.');
        return;
    }

    // POST to the API – saves the Last Working Day, marks the employee offboarded and
    // moves the candidate to the Offboarding stage (no Edit form is opened).
    // Disable the Save button while the request is in flight.
    const saveBtn = document.querySelector('#offboardModal .btn.primary');
    if (saveBtn) saveBtn.disabled = true;
    const originalText = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) saveBtn.textContent = 'Saving...';

    fetch('api/recruitment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'offboard_candidate',
            id: candidateId,
            employee_id: employeeId,
            last_working_day: lwd
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeOffboardModal();
            ModalSystem.success(data.message || 'Employee offboarded successfully');
            location.reload();
        } else {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = originalText; }
            ModalSystem.error('Error: ' + (data.message || 'Could not complete offboarding'));
        }
    })
    .catch(err => {
        console.error(err);
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = originalText; }
        ModalSystem.error('Error completing offboarding');
    });
}

function openEmployeeFormInRecruitment(candidate) {
    // Store candidate ID for onboarding workflow
    window.onboardingCandidateId = candidate.id;
    window.isOnboardingMode = true;
    window.candidateData = candidate;
    
    // Check if employee modal exists, if not create it
    if (!document.getElementById('employeeFormModal')) {
        const modalHTML = `
            <div id="employeeFormModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
                <div style="background:var(--surface);border-radius:var(--radius);width:800px;max-height:90vh;overflow-y:auto;padding:24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;">Add Employee</h3>
                        <button onclick="closeEmployeeFormModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);">&times;</button>
                    </div>
                    <form id="employeeForm" onsubmit="proceedToOnboarding(event)">
                        <input type="hidden" id="employeeId">
                        
                        <!-- Personal Information -->
                        <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:0 0 16px 0;color:var(--accent);">Personal Information</h4>
                        <div class="form-grid">
                            <div class="field">
                                <label>Employee ID</label>
                                <input type="text" id="employeeIdField" readonly style="background:var(--bg);">
                            </div>
                            <div class="field">
                                <label>First Name <span class="req">*</span></label>
                                <input type="text" id="firstName" required>
                            </div>
                            <div class="field">
                                <label>Last Name <span class="req">*</span></label>
                                <input type="text" id="lastName" required>
                            </div>
                            <div class="field">
                                <label>Personal Email</label>
                                <input type="email" id="personalEmail">
                            </div>
                            <div class="field">
                                <label>Official Email <span class="req">*</span></label>
                                <input type="email" id="officialEmail" required>
                            </div>
                            <div class="field">
                                <label>Alternate Email</label>
                                <input type="email" id="alternateEmail">
                            </div>
                            <div class="field">
                                <label>Phone <span class="req">*</span></label>
                                <input type="text" id="phone" required>
                            </div>
                            <div class="field">
                                <label>Alternate Phone</label>
                                <input type="text" id="alternatePhone">
                            </div>
                            <div class="field">
                                <label>Date of Birth</label>
                                <input type="date" id="dob">
                            </div>
                            <div class="field">
                                <label>Gender</label>
                                <select id="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Marital Status</label>
                                <select id="maritalStatus">
                                    <option value="">Select Status</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                            <div class="field full">
                                <label>Address</label>
                                <textarea id="address" rows="2"></textarea>
                            </div>
                            <div class="field">
                                <label>State</label>
                                <input type="text" id="state">
                            </div>
                            <div class="field">
                                <label>City</label>
                                <input type="text" id="city">
                            </div>
                            <div class="field">
                                <label>Zip Code</label>
                                <input type="text" id="zipCode">
                            </div>
                        </div>
                        
                        <!-- Employment Information -->
                        <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:24px 0 16px 0;color:var(--accent);">Employment Information</h4>
                        <div class="form-grid">
                            <div class="field">
                                <label>Primary Department <span class="req">*</span></label>
                                <select id="department" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($deptOptions as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Position</label>
                                <input type="text" id="position">
                            </div>
                            <div class="field">
                                <label>Reporting To</label>
                                <select id="reportingTo">
                                    <option value="">Select Manager</option>
                                    <?php foreach ($recruiters as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Date of Joining <span class="req">*</span></label>
                                <input type="date" id="doj" required>
                            </div>
                            <div class="field">
                                <label>Employment Type</label>
                                <select id="employmentType">
                                    <option value="">Select Type</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Intern">Intern</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Work Pattern</label>
                                <select id="workPattern">
                                    <option value="">Select Work Pattern</option>
                                    <option value="Mon-Fri">Mon-Fri</option>
                                    <option value="Mon-Sat">Mon-Sat</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Role <span class="req">*</span></label>
                                <select id="role" required>
                                    <option value="">Select Role</option>
                                    <?php foreach ($roleOptions as $role): ?>
                                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Username</label>
                                <input type="text" id="username">
                            </div>
                        </div>
                        
                        <!-- Bank Details -->
                        <h4 style="font-family:'Space Grotesk';font-size:14px;font-weight:600;margin:24px 0 16px 0;color:var(--accent);">Bank Details</h4>
                        <div class="form-grid">
                            <div class="field">
                                <label>Bank Name</label>
                                <input type="text" id="bankName">
                            </div>
                            <div class="field">
                                <label>Account Number</label>
                                <input type="text" id="accountNumber">
                            </div>
                            <div class="field">
                                <label>IFSC Code</label>
                                <input type="text" id="ifscCode">
                            </div>
                            <div class="field">
                                <label>PAN Number</label>
                                <input type="text" id="panNumber">
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div style="margin-top:24px;">
                            <div class="field">
                                <label>Status</label>
                                <select id="status">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                            <button type="button" class="btn" onclick="closeEmployeeFormModal()">Cancel</button>
                            <button type="submit" class="btn primary">Next: Upload Documents</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
    
    // Open the modal
    document.getElementById('employeeFormModal').style.display = 'flex';
    
    // Reset form
    document.getElementById('employeeForm').reset();
    document.getElementById('employeeId').value = '';
    
    // Pre-fill with candidate data
    document.getElementById('employeeIdField').value = 'EMP-' + Math.floor(1000 + Math.random() * 9000);
    document.getElementById('firstName').value = candidate.first_name || '';
    document.getElementById('lastName').value = candidate.last_name || '';
    document.getElementById('officialEmail').value = candidate.email || '';
    document.getElementById('phone').value = candidate.phone || '';
    document.getElementById('position').value = candidate.position_applied || '';
    document.getElementById('doj').value = new Date().toISOString().slice(0, 10);
    
    console.log('Form pre-filled with candidate data:', candidate);
}

function closeEmployeeFormModal() {
    document.getElementById('employeeFormModal').style.display = 'none';
}

function proceedToOnboarding(event) {
    event.preventDefault();
    
    // Store form data for later use
    window.employeeFormData = {
        employee_id: document.getElementById('employeeIdField').value,
        first_name: document.getElementById('firstName').value,
        last_name: document.getElementById('lastName').value,
        personal_email: document.getElementById('personalEmail').value,
        official_email: document.getElementById('officialEmail').value,
        alternate_email: document.getElementById('alternateEmail').value,
        phone: document.getElementById('phone').value,
        alternate_phone: document.getElementById('alternatePhone').value,
        date_of_birth: document.getElementById('dob').value,
        gender: document.getElementById('gender').value,
        marital_status: document.getElementById('maritalStatus').value,
        address: document.getElementById('address').value,
        state: document.getElementById('state').value,
        city: document.getElementById('city').value,
        zip_code: document.getElementById('zipCode').value,
        department_id: document.getElementById('department').value,
        position: document.getElementById('position').value,
        reporting_manager_id: document.getElementById('reportingTo').value || null,
        date_of_joining: document.getElementById('doj').value,
        employment_type: document.getElementById('employmentType').value,
        working_days_config: document.getElementById('workPattern').value,
        role_id: document.getElementById('role').value,
        username: document.getElementById('username').value,
        bank_name: document.getElementById('bankName').value,
        account_number: document.getElementById('accountNumber').value,
        ifsc_code: document.getElementById('ifscCode').value,
        pan_number: document.getElementById('panNumber').value,
        is_active: document.getElementById('status').value
    };
    
    // Close employee form
    closeEmployeeFormModal();
    
    // Open onboarding documents modal
    openOnboardingDocumentsModal(window.onboardingCandidateId, null);
}

// Import Candidates Modal Functions
function openImportModal() {
    document.getElementById('importFile').value = '';
    document.getElementById('importModal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function importCandidates() {
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('Please select a CSV file to import');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const csv = e.target.result;
        const lines = csv.split('\n');
        
        if (lines.length < 2) {
            alert('CSV file is empty or has no data rows');
            return;
        }
        
        // Parse CSV headers and data
        const headers = lines[0].split(',').map(h => h.trim().toLowerCase());
        const candidates = [];
        
        for (let i = 1; i < lines.length; i++) {
            if (lines[i].trim() === '') continue;
            
            const values = lines[i].split(',');
            const candidate = {};
            
            headers.forEach((header, index) => {
                candidate[header] = values[index] ? values[index].trim() : '';
            });
            
            candidates.push(candidate);
        }
        
        if (candidates.length === 0) {
            alert('No valid candidate data found in CSV');
            return;
        }
        
        // Import candidates one by one
        let imported = 0;
        let failed = 0;
        
        candidates.forEach(candidate => {
            const payload = {
                action: 'save',
                first_name: candidate.first_name || '',
                last_name: candidate.last_name || '',
                email: candidate.email || '',
                phone: candidate.phone || '',
                position_applied: candidate.position_applied || '',
                source: candidate.source || '',
                candidate_type: candidate.candidate_type || 'Fresher',
                current_salary: candidate.current_salary || '',
                expected_salary: candidate.expected_salary || '',
                notice_period: candidate.notice_period || '',
                notes: candidate.notes || '',
                stage: 'Sourcing'
            };
            
            fetch('api/recruitment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    imported++;
                } else {
                    failed++;
                }
                
                // Check if all imports are done
                if (imported + failed === candidates.length) {
                    closeImportModal();
                    alert(`Import completed: ${imported} candidates imported successfully, ${failed} failed`);
                    location.reload();
                }
            })
            .catch(err => {
                failed++;
                console.error(err);
                
                if (imported + failed === candidates.length) {
                    closeImportModal();
                    alert(`Import completed: ${imported} candidates imported successfully, ${failed} failed`);
                    location.reload();
                }
            });
        });
    };
    
    reader.readAsText(file);
}
</script>

