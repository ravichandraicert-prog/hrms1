<?php
/**
 * Notification System Diagnostic Test
 * Run this file to test if the notification system is working
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h2>Notification System Diagnostic Test</h2>";

$db = Database::getInstance();
$user = getCurrentUser();

echo "<h3>1. Check Database Tables</h3>";

// Check if notifications table exists
try {
    $check = $db->fetchOne("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notifications'");
    echo "✓ notifications table exists<br>";
} catch (Exception $e) {
    echo "❌ notifications table does NOT exist: " . $e->getMessage() . "<br>";
}

// Check if user_notification_preferences table exists
try {
    $check = $db->fetchOne("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_notification_preferences'");
    if ($check['count'] > 0) {
        echo "✓ user_notification_preferences table exists<br>";
    } else {
        echo "❌ user_notification_preferences table does NOT exist<br>";
        echo "<strong>Please run the database migration:</strong> <a href='database/add_notification_preferences.php'>Click here</a><br>";
    }
} catch (Exception $e) {
    echo "❌ user_notification_preferences table does NOT exist: " . $e->getMessage() . "<br>";
    echo "<strong>Please run the database migration:</strong> <a href='database/add_notification_preferences.php'>Click here</a><br>";
}

// Check if priority column exists in notifications
try {
    $check = $db->fetchOne("SELECT COUNT(*) as count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'priority'");
    if ($check['count'] > 0) {
        echo "✓ priority column exists in notifications table<br>";
    } else {
        echo "⚠ priority column does NOT exist in notifications table (optional but recommended)<br>";
    }
} catch (Exception $e) {
    echo "⚠ Could not check priority column: " . $e->getMessage() . "<br>";
}

echo "<h3>2. Test Send Notification Function</h3>";

if ($user) {
    echo "Current User ID: " . $user['id'] . "<br>";
    
    try {
        $result = sendNotification($user['id'], 'test', 'Test Notification', 'This is a test notification', null, 'Medium');
        if ($result) {
            echo "✓ sendNotification() worked - Notification ID: " . $result . "<br>";
        } else {
            echo "❌ sendNotification() returned false (user preferences may have disabled notifications)<br>";
        }
    } catch (Exception $e) {
        echo "❌ sendNotification() failed: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ Not logged in - cannot test<br>";
}

echo "<h3>3. Check Existing Notifications</h3>";

try {
    $notifications = $db->fetchAll("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
    echo "Found " . count($notifications) . " recent notifications:<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Type</th><th>Title</th><th>Created</th></tr>";
    foreach ($notifications as $notif) {
        echo "<tr>";
        echo "<td>" . $notif['id'] . "</td>";
        echo "<td>" . $notif['user_id'] . "</td>";
        echo "<td>" . htmlspecialchars($notif['type']) . "</td>";
        echo "<td>" . htmlspecialchars($notif['title']) . "</td>";
        echo "<td>" . $notif['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "❌ Could not fetch notifications: " . $e->getMessage() . "<br>";
}

echo "<h3>4. Test Polling API</h3>";
echo "<p>Open browser console and check for errors when the page loads. The polling API should be called every 30 seconds.</p>";

echo "<h3>5. Recommended Actions</h3>";
echo "<ol>";
echo "<li><strong>Run database migration:</strong> <a href='database/add_notification_preferences.php'>Click here</a></li>";
echo "<li><strong>Check browser console:</strong> Press F12 and look for JavaScript errors</li>";
echo "<li><strong>Check PHP error logs:</strong> Look at error_log.txt in the HRMS directory</li>";
echo "<li><strong>Test creating a notice:</strong> Go to Notices module and create a new notice</li>";
echo "</ol>";
?>
