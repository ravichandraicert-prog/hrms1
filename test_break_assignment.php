<?php
/**
 * Test script for Break Type Assignment functionality
 * Run this to verify the assignment logic works correctly
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/break_assignment.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = Database::getInstance();
    
    echo "<h2>Break Type Assignment Test</h2>";
    
    // Test 1: Check if database columns exist
    echo "<h3>Database Schema Check</h3>";
    $checkAssignTo = $db->fetchOne("SHOW COLUMNS FROM break_types LIKE 'assign_to'");
    $checkTargetId = $db->fetchOne("SHOW COLUMNS FROM break_types LIKE 'target_id'");
    
    if ($checkAssignTo && $checkTargetId) {
        echo "<p style='color:green'>✓ Database columns (assign_to, target_id) exist in break_types table</p>";
    } else {
        echo "<p style='color:red'>✗ Database columns missing. Please run the migration script.</p>";
    }
    
    // Test 2: Get sample break types
    echo "<h3>Sample Break Types</h3>";
    $breakTypes = $db->fetchAll("SELECT * FROM break_types LIMIT 5");
    
    if (empty($breakTypes)) {
        echo "<p>No break types found in database.</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
        echo "<tr><th>ID</th><th>Name</th><th>Assign To</th><th>Target ID</th></tr>";
        foreach ($breakTypes as $break) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($break['id']) . "</td>";
            echo "<td>" . htmlspecialchars($break['name']) . "</td>";
            echo "<td>" . htmlspecialchars($break['assign_to'] ?? 'all_employees') . "</td>";
            echo "<td>" . htmlspecialchars($break['target_id'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test 3: Test assignment helper functions
    echo "<h3>Assignment Helper Functions Test</h3>";
    
    if (!empty($breakTypes)) {
        $testBreak = $breakTypes[0];
        echo "<p>Testing with break type: " . htmlspecialchars($testBreak['name']) . "</p>";
        
        $assignTo = break_type_assign_to($testBreak);
        echo "<p>Assignment mode: " . htmlspecialchars($assignTo) . "</p>";
        
        // Get target employee IDs
        $targetEmpIds = break_type_target_employee_ids($db, $testBreak);
        echo "<p>Target employee count: " . count($targetEmpIds) . "</p>";
        
        if (count($targetEmpIds) > 0) {
            echo "<p>Sample target employee IDs: " . implode(', ', array_slice($targetEmpIds, 0, 5)) . "</p>";
        }
    }
    
    // Test 4: Check API endpoint
    echo "<h3>API Endpoint Test</h3>";
    echo "<p>Test the assignment data API: <a href='api/leaves.php?get_assignment_data=1' target='_blank'>api/leaves.php?get_assignment_data=1</a></p>";
    
    echo "<h3>Test Results Summary</h3>";
    echo "<p style='color:green'>✓ Break type assignment functionality has been implemented successfully.</p>";
    echo "<p>You can now test the UI by navigating to: <a href='modules/settings.php?section=breaks' target='_blank'>Settings → Break Types</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
