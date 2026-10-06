<?php
/**
 * One-time backfill: provision leave_balances rows for active employees that are
 * missing them for the current year (e.g. newly added employees).
 * Uses the same non-destructive sync_leave_type_balances() helper that runs when a
 * leave type is saved in Settings -> Leave Types / Leaves -> Leave Types.
 *
 * Usage: php database/backfill_leave_balances.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/leave_assignment.php';

$db = Database::getInstance();
$year = (int) date('Y');

$types = $db->fetchAll("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
$grandTotal = 0;

foreach ($types as $type) {
    $n = sync_leave_type_balances($db, $type, $year);
    echo "Synced '{$type['name']}' for year {$year}: {$n} row(s) created/healed\n";
    $grandTotal += $n;
}

echo "Done. {$grandTotal} leave_balances row(s) created/healed total.\n";