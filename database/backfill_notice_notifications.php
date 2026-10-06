<?php
/**
 * Backfill: Generate Notifications for Existing Notices
 *
 * Notices created before notice -> notification dispatch was added never produced
 * notification feed entries. This one-time maintenance script generates a
 * 'notice' notification for every existing active (published), non-expired notice
 * so they appear in the Notifications module under the "Notices" filter.
 *
 * SAFE TO RE-RUN (idempotent):
 * - sendNoticeNotifications() skips any notice that has already been broadcast,
 *   so re-running this script never creates duplicates.
 * - Individual user notification preferences are respected, so users who disabled
 *   notice notifications will not receive them.
 *
 * Usage (CLI):   php database/backfill_notice_notifications.php
 * Usage (web):   open the script in a browser (single-use admin task)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = Database::getInstance();

    // Only published, non-expired notices
    $notices = $db->fetchAll(
        "SELECT * FROM notices
         WHERE is_active = 1
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())
         ORDER BY created_at"
    );

    echo "Found " . count($notices) . " active notice(s) to process.\n\n";

    $totalSent = 0;
    foreach ($notices as $notice) {
        $noticeData = [
            'priority' => $notice['priority'] ?? 'Low',
            'target_type' => $notice['target_type'] ?? 'all',
            'target_roles' => $notice['target_roles'] ?? null,
            'target_departments' => $notice['target_departments'] ?? null
        ];
        $sent = sendNoticeNotifications($notice['id'], $notice['title'], $notice['body'], $noticeData);
        if ($sent > 0) {
            echo "Notice #{$notice['id']} \"{$notice['title']}\": {$sent} notification(s) generated\n";
        } else {
            echo "Notice #{$notice['id']} \"{$notice['title']}\": skipped (already broadcast or no recipients)\n";
        }
        $totalSent += $sent;
    }

    echo "\nDone. {$totalSent} total notification(s) generated.\n";
    echo "Open the Notifications module and use the \"Notices\" filter to verify.\n";
} catch (Exception $e) {
    echo "\nBackfill failed: " . $e->getMessage() . "\n";
    exit(1);
}