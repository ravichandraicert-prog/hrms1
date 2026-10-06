-- Work Patterns Table
-- Stores configurable work patterns for employees

CREATE TABLE IF NOT EXISTS `work_patterns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_work_pattern_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default work patterns
INSERT INTO `work_patterns` (`name`, `description`, `is_active`) VALUES
('Mon - Sat (6 Days) - All Sundays off', 'Monday to Saturday working, all Sundays off', 1),
('Mon - Fri (5 Days) - All Saturdays & Sundays off', 'Monday to Friday working, all Saturdays and Sundays off', 1),
('Mon - Sat (2nd & 4th Saturday off) - All Sundays off', 'Monday to Saturday working, 2nd and 4th Saturdays off, all Sundays off', 1),
('Mon - Sat (4th Saturday off) - All Sundays off', 'Monday to Saturday working, 4th Saturday off, all Sundays off', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);