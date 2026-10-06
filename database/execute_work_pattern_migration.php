<?php
/**
 * Work Pattern Migration Script - Web Version
 * Execute this via browser: http://localhost/HRMS/database/execute_work_pattern_migration.php
 */

// Manual database connection for migration
require_once __DIR__ . '/../config/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Work Pattern Schema Migration</h2>";
    echo "<p>Connected to database: $dbname</p>";
    echo "<pre>";
    
    echo "<h2>Work Pattern Schema Migration</h2>";
    echo "<pre>";
    
    // 1. Add columns to work_patterns table
    echo "Adding columns to work_patterns table...\n";
    
    $alterStatements = [
        "ALTER TABLE `work_patterns` ADD COLUMN `monday` enum('working', 'weekly_off') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `tuesday` enum('working', 'weekly_off') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `wednesday` enum('working', 'weekly_off') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `thursday` enum('working', 'weekly_off') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `friday` enum('working', 'weekly_off') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `saturday` enum('working', 'weekly_off', 'conditional') DEFAULT 'working'",
        "ALTER TABLE `work_patterns` ADD COLUMN `sunday` enum('working', 'weekly_off') DEFAULT 'weekly_off'",
        "ALTER TABLE `work_patterns` ADD COLUMN `saturday_rule` varchar(50) DEFAULT NULL",
        "ALTER TABLE `work_patterns` MODIFY COLUMN `description` text DEFAULT NULL"
    ];
    
    foreach ($alterStatements as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ " . substr($sql, 0, 60) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "⊘ Column already exists: " . substr($sql, 0, 60) . "...\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // 2. Create employee_work_patterns table
    echo "\nCreating employee_work_patterns table...\n";
    $createAssignmentTable = "
    CREATE TABLE IF NOT EXISTS `employee_work_patterns` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `employee_id` int(11) NOT NULL,
      `work_pattern_id` int(11) NOT NULL,
      `effective_from` date NOT NULL,
      `effective_to` date DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT 1,
      `assigned_by` int(11) DEFAULT NULL,
      `assignment_reason` text DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `employee_id` (`employee_id`),
      KEY `work_pattern_id` (`work_pattern_id`),
      KEY `effective_from` (`effective_from`),
      CONSTRAINT `employee_work_patterns_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
      CONSTRAINT `employee_work_patterns_ibfk_2` FOREIGN KEY (`work_pattern_id`) REFERENCES `work_patterns` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        $pdo->exec($createAssignmentTable);
        echo "✓ employee_work_patterns table created\n";
    } catch (PDOException $e) {
        echo "⊘ Table may already exist: " . $e->getMessage() . "\n";
    }
    
    // 3. Add work_pattern_id to employees table
    echo "\nAdding work_pattern_id to employees table...\n";
    try {
        $pdo->exec("ALTER TABLE `employees` ADD COLUMN `work_pattern_id` int(11) DEFAULT NULL AFTER `shift_id`");
        echo "✓ work_pattern_id column added to employees\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⊘ Column already exists in employees table\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // Add foreign key constraint if it doesn't exist
    echo "\nAdding foreign key constraint...\n";
    try {
        $pdo->exec("ALTER TABLE `employees` ADD CONSTRAINT `employees_ibfk_5` FOREIGN KEY (`work_pattern_id`) REFERENCES `work_patterns` (`id`) ON DELETE SET NULL");
        echo "✓ Foreign key constraint added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate foreign key') !== false || strpos($e->getMessage(), 'foreign key constraint') !== false) {
            echo "⊘ Foreign key constraint already exists\n";
        } else {
            echo "⊘ Foreign key constraint skipped: " . $e->getMessage() . "\n";
        }
    }
    
    // 4. Update existing work patterns
    echo "\nUpdating existing work patterns...\n";
    
    $updatePatterns = [
        "Monday - Friday" => [
            "name" => "Monday - Friday (5 Days)",
            "description" => "Monday to Friday working, all Saturdays and Sundays off",
            "monday" => "working", "tuesday" => "working", "wednesday" => "working", 
            "thursday" => "working", "friday" => "working", "saturday" => "weekly_off", 
            "sunday" => "weekly_off", "saturday_rule" => null
        ],
        "Monday - Saturday" => [
            "name" => "Monday - Saturday (6 Days)",
            "description" => "Monday to Saturday working, all Sundays off",
            "monday" => "working", "tuesday" => "working", "wednesday" => "working", 
            "thursday" => "working", "friday" => "working", "saturday" => "working", 
            "sunday" => "weekly_off", "saturday_rule" => null
        ]
    ];
    
    foreach ($updatePatterns as $oldName => $config) {
        $sql = "UPDATE work_patterns SET 
                name = ?, description = ?, monday = ?, tuesday = ?, wednesday = ?, 
                thursday = ?, friday = ?, saturday = ?, sunday = ?, saturday_rule = ?
                WHERE name LIKE ?";
        $params = array_merge(array_values($config), ["%$oldName%"]);
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo "✓ Updated pattern: " . $config['name'] . "\n";
        } catch (PDOException $e) {
            echo "✗ Error updating $oldName: " . $e->getMessage() . "\n";
        }
    }
    
    // 5. Create work pattern history table
    echo "\nCreating work_pattern_history table...\n";
    $createHistoryTable = "
    CREATE TABLE IF NOT EXISTS `work_pattern_history` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `employee_id` int(11) NOT NULL,
      `old_work_pattern_id` int(11) DEFAULT NULL,
      `new_work_pattern_id` int(11) NOT NULL,
      `effective_from` date NOT NULL,
      `changed_by` int(11) DEFAULT NULL,
      `change_reason` text DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `employee_id` (`employee_id`),
      KEY `old_work_pattern_id` (`old_work_pattern_id`),
      KEY `new_work_pattern_id` (`new_work_pattern_id`),
      KEY `effective_from` (`effective_from`),
      CONSTRAINT `work_pattern_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
      CONSTRAINT `work_pattern_history_ibfk_2` FOREIGN KEY (`old_work_pattern_id`) REFERENCES `work_patterns` (`id`) ON DELETE SET NULL,
      CONSTRAINT `work_pattern_history_ibfk_3` FOREIGN KEY (`new_work_pattern_id`) REFERENCES `work_patterns` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        $pdo->exec($createHistoryTable);
        echo "✓ work_pattern_history table created\n";
    } catch (PDOException $e) {
        echo "⊘ Table may already exist: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "</pre>";
    
} catch (PDOException $e) {
    echo "<h3>Database Connection Error</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
}
