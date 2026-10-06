<?php
/**
 * Migration: Add target_ids column to break_types table.
 * Stores a comma-separated list of target ids (e.g. multiple shifts) for break
 * type assignment. The resolver prefers target_ids and falls back to the legacy
 * single-target target_id column, so existing assignments keep working.
 *
 * Backfills target_ids = target_id for existing rows so the multi-target logic
 * sees the currently assigned single target too.
 *
 * Run via browser: http://<host>/hrms1/database/add_break_target_ids_column.php
 * or CLI: php database/add_break_target_ids_column.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

// 1. Add the target_ids column if it doesn't exist
$checkTargetIds = $db->fetchOne("SHOW COLUMNS FROM break_types LIKE 'target_ids'");
if (!$checkTargetIds) {
    $db->query(
        "ALTER TABLE `break_types` ADD COLUMN `target_ids` VARCHAR(500) DEFAULT NULL " .
        "COMMENT 'Comma-separated target ids (e.g. multiple shifts) when assign_to requires multiple targets' " .
        "AFTER `target_id`"
    );
    echo "✓ Added target_ids column to break_types successfully.\n";
} else {
    echo "ℹ target_ids column already exists in break_types.\n";
}

// 2. Backfill target_ids from the legacy single target_id (never overwrite existing values)
$db->query(
    "UPDATE break_types SET target_ids = CAST(target_id AS CHAR) " .
    "WHERE (target_ids IS NULL OR target_ids = '') AND target_id IS NOT NULL"
);
echo "✓ Backfilled target_ids from target_id for existing rows.\n";

echo "Done.\n";