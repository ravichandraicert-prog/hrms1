<?php
require 'config/config.php';
require 'config/database.php';
$db = Database::getInstance();
echo '--- admin (id 1) last 8 punches ---' . PHP_EOL;
foreach ($db->fetchAll("SELECT id, event_type, break_type_id, reason, timestamp_utc FROM attendance_punches WHERE employee_id = 1 ORDER BY id DESC LIMIT 8") as $p) {
    echo "  id={$p['id']} {$p['event_type']} bt=[{$p['break_type_id']}] at={$p['timestamp_utc']}" . PHP_EOL;
}
echo '--- current status calc ---' . PHP_EOL;
$lastIn = $db->fetchOne("SELECT * FROM attendance_punches WHERE employee_id = 1 AND event_type = 'CLOCK_IN' ORDER BY timestamp_utc DESC, id DESC LIMIT 1");
echo $lastIn ? "lastIn id={$lastIn['id']} at={$lastIn['timestamp_utc']}" : 'no lastIn' . PHP_EOL;
if ($lastIn) {
    $co = $db->fetchOne("SELECT id, timestamp_utc FROM attendance_punches WHERE employee_id = 1 AND event_type = 'CLOCK_OUT' AND timestamp_utc >= ? ORDER BY timestamp_utc ASC, id ASC LIMIT 1", [$lastIn['timestamp_utc']]);
    echo $co ? "clockout found after in: id={$co['id']} at={$co['timestamp_utc']}" : 'no clockout after lastIn' . PHP_EOL;
}