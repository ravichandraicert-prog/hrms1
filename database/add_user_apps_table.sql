-- Add user_apps table for application shortcuts
-- This table stores user-specific application shortcuts

CREATE TABLE IF NOT EXISTS `user_apps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Reference to employees table',
  `app_name` varchar(100) NOT NULL COMMENT 'Display name of the app',
  `app_type` enum('website','desktop_app','file','folder','hrms_module') NOT NULL COMMENT 'Type of application',
  `target` text NOT NULL COMMENT 'URL, file path, or module name',
  `icon` varchar(255) DEFAULT NULL COMMENT 'Custom icon path or emoji',
  `sort_order` int(11) DEFAULT 0 COMMENT 'Display order',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Whether the shortcut is active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `user_id_active` (`user_id`, `is_active`),
  CONSTRAINT `user_apps_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
