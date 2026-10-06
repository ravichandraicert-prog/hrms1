-- Shutdown Auto Clock-Out implementation
-- 1) Create employee_live_sessions table - heartbeat "machine is alive" marker
-- 2) Seed settings defaults for shutdown auto clock-out configuration
--
-- This feature is ADDITIVE: it never changes how idle tracking or the
-- attendance punch pipeline works. The table is only written by the existing
-- once-per-minute heartbeat and the new sweep/cron script.

-- --------------------------------------------------------

-- Table: employee_live_sessions (server-side "machine is alive" marker)
-- One row per PHP session, refreshed by every heartbeat from api/idle.php.
-- Used by cron/auto_clockout_stale_sessions.php to detect employees whose
-- machine/app went down without a clean shutdown signal (power cut / crash /
-- forced kill) and clock them out at their last known alive moment.
CREATE TABLE IF NOT EXISTS `employee_live_sessions` (
  `session_id` varchar(64) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `last_seen_utc` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `employee_id` (`employee_id`),
  KEY `last_seen_utc` (`last_seen_utc`),
  CONSTRAINT `employee_live_sessions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Seed default shutdown auto clock-out configuration in the settings table.
-- INSERT ... ON DUPLICATE KEY UPDATE makes re-running the migration safe and
-- never overwrites an administrator-configured value.
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('shutdown_clockout_enabled', '1', 'Master switch for auto clock-out + logout when the HRMS desktop machine shuts down / quits / stops heartbeating (1 = enabled)'),
  ('shutdown_clockout_stale_minutes', '60', 'Server-side safety net: clock out employees whose last heartbeat is older than this many minutes (unclean shutdown / crash / power cut)'),
  ('shutdown_recovery_stale_minutes', '2', 'Startup recovery: when HRMS opens, close the open attendance session of any logged-in employee whose last heartbeat is older than this many minutes (previous Windows shutdown/restart/logoff/quit left it orphaned). Guarded so still-fresh sessions are never touched.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);