<?php
/**
 * Debug script for threshold save functionality
 * Run this to test the save process and identify issues
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/attendance_threshold_service.php';

echo "<h2>Threshold Save Debug</h2>";

$db = Database::getInstance();
$thresholdService = new AttendanceThresholdService();

// Test 1: Check if table exists
echo "<h3>Test 1: Check attendance_settings table</h3>";
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM attendance_settings");
    echo "<p style='color:green;'>✓ Table exists with {$result['count']} records</p>";
    
    // Show current settings
    $current = $thresholdService->getCurrentThresholds();
    echo "<pre>";
    print_r($current);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Table does not exist: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test 2: Test validation
echo "<h3>Test 2: Test validation</h3>";
$testData = [
    'present_threshold_minutes' => 495,
    'half_day_threshold_minutes' => 255
];
$validation = $thresholdService->validateThresholds($testData);
echo "<p>Validation result: " . ($validation['valid'] ? 'Valid' : 'Invalid') . "</p>";
echo "<p>Message: " . htmlspecialchars($validation['message']) . "</p>";

// Test 3: Test update with same values (should succeed - no update needed)
echo "<h3>Test 3: Test threshold update with same values</h3>";
try {
    $result = $thresholdService->updateThresholds($testData, 1, 'Test update - same values');
    echo "<p style='color:green;'>✓ Update successful (no changes needed): " . ($result ? 'true' : 'false') . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Update failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test 3b: Test update with different values (should update)
echo "<h3>Test 3b: Test threshold update with different values</h3>";
$differentData = [
    'present_threshold_minutes' => 500, // Different value
    'half_day_threshold_minutes' => 260  // Different value
];
try {
    $result = $thresholdService->updateThresholds($differentData, 1, 'Test update - different values');
    echo "<p style='color:green;'>✓ Update successful with different values: " . ($result ? 'true' : 'false') . "</p>";
    
    // Show updated values
    $updated = $thresholdService->getCurrentThresholds();
    echo "<p>Updated values: Present = {$updated['present_threshold_minutes']}, Half Day = {$updated['half_day_threshold_minutes']}</p>";
    
    // Restore original values
    $thresholdService->updateThresholds($testData, 1, 'Restore original values');
    echo "<p>Restored original values</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Update failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test 4: Check if history table exists
echo "<h3>Test 4: Check attendance_threshold_history table</h3>";
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM attendance_threshold_history");
    echo "<p style='color:green;'>✓ History table exists with {$result['count']} records</p>";
} catch (Exception $e) {
    echo "<p style='color:orange;'>⚠ History table does not exist (optional): " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test 5: Show current thresholds after update
echo "<h3>Test 5: Current thresholds after update</h3>";
try {
    $current = $thresholdService->getCurrentThresholds();
    echo "<pre>";
    print_r($current);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Failed to get current thresholds: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>Database Connection Info</h3>";
echo "<p>DB Host: " . DB_HOST . "</p>";
echo "<p>DB Name: " . DB_NAME . "</p>";
echo "<p>DB User: " . DB_USER . "</p>";

echo "<p><a href='index.php?module=settings&section=attendance'>Return to Settings</a></p>";
?>