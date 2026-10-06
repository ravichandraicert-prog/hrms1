-- Work Patterns System Schema Update
-- Adds comprehensive work pattern configuration and assignment system

-- ============================================================
-- 1. Update existing work_patterns table with detailed configuration
-- ============================================================

ALTER TABLE `work_patterns` 
ADD COLUMN `monday` enum('working', 'weekly_off') DEFAULT 'working',
ADD COLUMN `tuesday` enum('working', 'weekly_off') DEFAULT 'working',
ADD COLUMN `wednesday` enum('working', 'weekly_off') DEFAULT 'working',
ADD COLUMN `thursday` enum('working', 'weekly_off') DEFAULT 'working',
ADD COLUMN `friday` enum('working', 'weekly_off') DEFAULT 'working',
ADD COLUMN `saturday` enum('working', 'weekly_off', 'conditional') DEFAULT 'working',
ADD COLUMN `sunday` enum('working', 'weekly_off') DEFAULT 'weekly_off',
ADD COLUMN `saturday_rule` varchar(50) DEFAULT NULL COMMENT 'For conditional Saturday: all, 2nd_4th, 4th_only',
ADD COLUMN `description` text DEFAULT NULL;

-- ============================================================
-- 2. Create work pattern assignments table
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Add work_pattern_id to employees table for current assignment
-- ============================================================

ALTER TABLE `employees`
ADD COLUMN `work_pattern_id` int(11) DEFAULT NULL AFTER `shift_id`,
ADD INDEX `work_pattern_id` (`work_pattern_id`),
ADD CONSTRAINT `employees_ibfk_5` FOREIGN KEY (`work_pattern_id`) REFERENCES `work_patterns` (`id`) ON DELETE SET NULL;

-- ============================================================
-- 4. Update existing work patterns with proper configuration
-- ============================================================

-- Pattern 1: Monday to Friday
UPDATE `work_patterns` 
SET 
  `name` = 'Monday - Friday (5 Days)',
  `description` = 'Monday to Friday working, all Saturdays and Sundays off',
  `monday` = 'working',
  `tuesday` = 'working',
  `wednesday` = 'working',
  `thursday` = 'working',
  `friday` = 'working',
  `saturday` = 'weekly_off',
  `sunday` = 'weekly_off',
  `saturday_rule` = NULL
WHERE `name` LIKE '%Mon - Fri%';

-- Pattern 2: Monday to Saturday
UPDATE `work_patterns` 
SET 
  `name` = 'Monday - Saturday (6 Days)',
  `description` = 'Monday to Saturday working, all Sundays off',
  `monday` = 'working',
  `tuesday` = 'working',
  `wednesday` = 'working',
  `thursday` = 'working',
  `friday` = 'working',
  `saturday` = 'working',
  `sunday` = 'weekly_off',
  `saturday_rule` = NULL
WHERE `name` LIKE '%Mon - Sat (6 Days)%' AND `name` NOT LIKE '%2nd%';

-- Pattern 3: Monday to Saturday except 2nd & 4th Saturday
UPDATE `work_patterns` 
SET 
  `name` = 'Monday - Saturday (2nd & 4th Saturday off)',
  `description` = 'Monday to Saturday working, 2nd and 4th Saturdays off, all Sundays off',
  `monday` = 'working',
  `tuesday` = 'working',
  `wednesday` = 'working',
  `thursday` = 'working',
  `friday` = 'working',
  `saturday` = 'conditional',
  `sunday` = 'weekly_off',
  `saturday_rule` = '2nd_4th'
WHERE `name` LIKE '%2nd & 4th%';

-- Pattern 4: Monday to Saturday except 4th Saturday
UPDATE `work_patterns` 
SET 
  `name` = 'Monday - Saturday (4th Saturday off)',
  `description` = 'Monday to Saturday working, 4th Saturday off, all Sundays off',
  `monday` = 'working',
  `tuesday` = 'working',
  `wednesday` = 'working',
  `thursday` = 'working',
  `friday` = 'working',
  `saturday` = 'conditional',
  `sunday` = 'weekly_off',
  `saturday_rule` = '4th_only'
WHERE `name` LIKE '%4th Saturday off%' AND `name` NOT LIKE '%2nd%';

-- ============================================================
-- 5. Create default work pattern assignment for existing employees
-- ============================================================

-- Assign Monday-Saturday pattern to all existing employees
UPDATE `employees` 
SET `work_pattern_id` = (SELECT id FROM work_patterns WHERE `name` = 'Monday - Saturday (6 Days)' LIMIT 1)
WHERE `work_pattern_id` IS NULL;

-- ============================================================
-- 6. Create work pattern history table for tracking changes
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
