<?php
/**
 * Simple test script to check database connection and run migration
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing database connection...\n";

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    
    $db = Database::getInstance();
    echo "✓ Database connection successful\n";
    
    // Check if columns exist
    echo "\nChecking if monthly accrual columns exist...\n";
    $checkColumn = $db->fetchOne("SHOW COLUMNS FROM leave_types LIKE 'monthly_accrual_enabled'");
    
    if ($checkColumn) {
        echo "✓ monthly_accrual_enabled column already exists\n";
    } else {
        echo "✗ monthly_accrual_enabled column does not exist - adding it now\n";
        
        $db->query("ALTER TABLE `leave_types`
            ADD COLUMN `monthly_accrual_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable automatic monthly leave accrual',
            ADD COLUMN `monthly_accrual_days` decimal(5,2) DEFAULT 0.00 COMMENT 'Number of days to accrue monthly when enabled',
            ADD COLUMN `accrual_timing` varchar(50) DEFAULT 'pay_cycle_end' COMMENT 'When to accrual: pay_cycle_end, pay_cycle_start, specific_day',
            ADD COLUMN `accrual_day_of_month` int(11) DEFAULT NULL COMMENT 'Specific day of month for accrual if timing is specific_day'");
        
        echo "✓ Monthly accrual columns added successfully\n";
    }
    
    // Check if table exists
    echo "\nChecking if leave_accrual_history table exists...\n";
    $checkTable = $db->fetchOne("SHOW TABLES LIKE 'leave_accrual_history'");
    
    if ($checkTable) {
        echo "✓ leave_accrual_history table already exists\n";
    } else {
        echo "✗ leave_accrual_history table does not exist - creating it now\n";
        
        $db->query("CREATE TABLE IF NOT EXISTS `leave_accrual_history` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `employee_id` int(11) NOT NULL,
          `leave_type_id` int(11) NOT NULL,
          `accrual_date` date NOT NULL,
          `accrual_period_start` date NOT NULL,
          `accrual_period_end` date NOT NULL,
          `days_accrued` decimal(5,2) NOT NULL,
          `pay_cycle_id` int(11) DEFAULT NULL,
          `notes` text DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `employee_id` (`employee_id`),
          KEY `leave_type_id` (`leave_type_id`),
          KEY `accrual_date` (`accrual_date`),
          KEY `accrual_period_end` (`accrual_period_end`),
          KEY `pay_cycle_id` (`pay_cycle_id`),
          CONSTRAINT `leave_accrual_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
          CONSTRAINT `leave_accrual_history_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
          CONSTRAINT `leave_accrual_history_ibfk_3` FOREIGN KEY (`pay_cycle_id`) REFERENCES `pay_cycles` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        echo "✓ leave_accrual_history table created successfully\n";
    }
    
    // Check for unique constraint
    echo "\nChecking for unique constraint...\n";
    $checkConstraint = $db->fetchOne("SELECT * FROM information_schema.table_constraints 
                                     WHERE constraint_schema = DATABASE() 
                                     AND table_name = 'leave_accrual_history' 
                                     AND constraint_name = 'unique_emp_type_period'");
    
    if ($checkConstraint) {
        echo "✓ Unique constraint already exists\n";
    } else {
        echo "✗ Unique constraint does not exist - adding it now\n";
        
        $db->query("ALTER TABLE `leave_accrual_history`
            ADD UNIQUE KEY `unique_emp_type_period` (`employee_id`, `leave_type_id`, `accrual_period_start`, `accrual_period_end`)");
        
        echo "✓ Unique constraint added successfully\n";
    }
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>