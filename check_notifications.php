<?php
/**
 * Quick Notification Test
 * Run this to check if notifications are working
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h2>Quick Notification System Check</h2>";

$db = Database::getInstance();

// Check 1: Does user_notification_preferences table exist?
echo "<h3>1. Check if preferences table exists</h3>";
try {
    $check = $db->fetchOne("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_notification_preferences'");
    if ($check['count'] > 0) {
        echo "<span style='color:green'>✓ user_notification_preferences table EXISTS</span><br>";
    } else {
        echo "<span style='color:red'>❌ user_notification_preferences table DOES NOT EXIST</span><br>";
        echo "<strong>SOLUTION:</strong> <a href='database/add_notification_preferences.php'>Run the database migration</a><br><br>";
    }
} catch (Exception $e) {
    echo "<span style='color:red'>❌ Error checking table: " . $e->getMessage() . "</span><br>";
}

// Check 2: Test creating a notification
echo "<h3>2. Test creating a notification</h3>";
$user = getCurrentUser();
if ($user) {
    echo "Current User ID: " . $user['id'] . "<br><br>";
    
    try {
        echo "Attempting to create test notification...<br>";
        $result = sendNotification($user['id'], 'test', 'Test Notification', 'This is a test from the diagnostic page', null, 'Medium');
        
        if ($result) {
            echo "<span style='color:green'>✓ Notification created successfully! ID: " . $result . "</span><br>";
            echo "<br><strong>Next steps:</strong>";
            echo "<ol>";
            echo "<li>Check if in-app toast appears (bottom-right corner)</li>";
            echo "<li>Check if browser/Electron desktop notification appears</li>";
            echo "<li>Check browser console (F12) for any errors</li>";
            echo "<li>Check PHP error_log.txt for debug messages</li>";
            echo "</ol>";
        } else {
            echo "<span style='color:red'>❌ sendNotification() returned FALSE</span><br>";
            echo "<strong>Possible reasons:</strong>";
            echo "<ul>";
            echo "<li>User has notifications globally disabled in settings</li>";
            echo "<li>User has 'test' notification type disabled in settings</li>";
            echo "<li>Database error (check error_log.txt)</li>";
            echo "</ul>";
            echo "<br><strong>SOLUTION:</strong> Go to Settings > Notifications and enable notifications<br>";
        }
    } catch (Exception $e) {
        echo "<span style='color:red'>❌ Error creating notification: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span style='color:red'>❌ Not logged in</span><br>";
    echo "<strong>SOLUTION:</strong> Log in to HRMS first<br>";
}

// Check 3: Show recent notifications
echo "<h3>3. Recent notifications in database</h3>";
try {
    $notifications = $db->fetchAll("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 10");
    echo "Total notifications in database: " . count($notifications) . "<br><br>";
    
    if (count($notifications) > 0) {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
        echo "<tr style='background:#f0f0f0;'><th>ID</th><th>User ID</th><th>Type</th><th>Title</th><th>Priority</th><th>Created</th></tr>";
        foreach ($notifications as $notif) {
            echo "<tr>";
            echo "<td>" . $notif['id'] . "</td>";
            echo "<td>" . $notif['user_id'] . "</td>";
            echo "<td>" . htmlspecialchars($notif['type']) . "</td>";
            echo "<td>" . htmlspecialchars($notif['title']) . "</td>";
            echo "<td>" . htmlspecialchars($notif['priority'] ?? 'NULL') . "</td>";
            echo "<td>" . $notif['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No notifications found in database.<br>";
    }
} catch (Exception $e) {
    echo "<span style='color:red'>❌ Error fetching notifications: " . $e->getMessage() . "</span><br>";
}

// Check 4: Check if users have preferences
echo "<h3>4. Check user preferences</h3>";
try {
    $prefCount = $db->fetchOne("SELECT COUNT(*) as count FROM user_notification_preferences");
    $userCount = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE is_active = 1");
    
    echo "Active employees: " . $userCount['count'] . "<br>";
    echo "Users with preferences: " . $prefCount['count'] . "<br><br>";
    
    if ($userCount['count'] > 0 && $prefCount['count'] == 0) {
        echo "<span style='color:red'>⚠ WARNING: Active employees exist but no preferences set!</span><br>";
        echo "<strong>SOLUTION:</strong> Run this SQL in phpMyAdmin to insert default preferences:<br>";
        echo "<pre style='background:#f0f0f0;padding:10px;border:1px solid #ccc;'>";
        echo "INSERT INTO `user_notification_preferences` (`user_id`, `enabled`, `types_enabled`, `browser_notifications`, `sound_enabled`)
SELECT 
    `id` as user_id,
    1 as enabled,
    '{\"leave\":1,\"task\":1,\"attendance\":1,\"notice\":1,\"payroll\":1,\"approval\":1}' as types_enabled,
    0 as browser_notifications,
    1 as sound_enabled
FROM `employees` 
WHERE `is_active` = 1
AND `id` NOT IN (SELECT `user_id` FROM `user_notification_preferences`);";
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "<span style='color:red'>❌ Error checking preferences: " . $e->getMessage() . "</span><br>";
}

echo "<br><hr>";
echo "<h3>Quick Fix Checklist</h3>";
echo "<ol>";
echo "<li><strong>Run database migration:</strong> <a href='database/add_notification_preferences.php'>Click here</a></li>";
echo "<li><strong>Check browser console:</strong> Press F12 and look for JavaScript errors</li>";
echo "<li><strong>Check PHP error log:</strong> Open error_log.txt in the HRMS directory</li>";
echo "<li><strong>Check Settings:</strong> Go to Settings > Notifications and ensure notifications are enabled</li>";
echo "<li><strong>Test creating a notice:</strong> Go to Notices module and create a new notice</li>";
echo "</ol>";
?>
