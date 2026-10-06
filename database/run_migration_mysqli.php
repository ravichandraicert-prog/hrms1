<?php
/**
 * Direct migration runner using MySQLi
 */

require_once __DIR__ . '/../config/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected to database successfully.\n";

// Add candidate_type field to candidates table
$checkColumn = $conn->query("SHOW COLUMNS FROM candidates LIKE 'candidate_type'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE `candidates` ADD COLUMN `candidate_type` ENUM('Fresher', 'Experienced') DEFAULT 'Fresher' AFTER `notice_period`");
    echo "✓ Added candidate_type column to candidates table.\n";
} else {
    echo "✓ candidate_type column already exists in candidates table.\n";
}

// Create onboarding_documents table
$checkTable = $conn->query("SHOW TABLES LIKE 'onboarding_documents'");
if ($checkTable->num_rows == 0) {
    $conn->query("CREATE TABLE `onboarding_documents` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `employee_id` int(11) NOT NULL,
      `candidate_id` int(11) DEFAULT NULL,
      `document_type` varchar(100) NOT NULL,
      `document_name` varchar(255) NOT NULL,
      `file_path` varchar(500) NOT NULL,
      `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `uploaded_by` int(11) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `employee_id` (`employee_id`),
      KEY `candidate_id` (`candidate_id`),
      KEY `document_type` (`document_type`),
      CONSTRAINT `onboarding_documents_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
      CONSTRAINT `onboarding_documents_ibfk_2` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE SET NULL,
      CONSTRAINT `onboarding_documents_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `employees` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ Created onboarding_documents table.\n";
} else {
    echo "✓ onboarding_documents table already exists.\n";
}

echo "\n✓ Migration completed successfully!\n";

$conn->close();
?>