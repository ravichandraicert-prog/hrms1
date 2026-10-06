<?php
/**
 * Orbit HRMS Installation Script
 * Core PHP HRMS Application
 * 
 * This script sets up the database and creates the initial admin user.
 * Run this once after deploying the application.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>\n";
echo "<html lang='en'>\n";
echo "<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<title>Orbit HRMS - Installation</title>\n";
echo "<style>\n";
echo "body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }\n";
echo ".success { color: green; }\n";
echo ".error { color: red; }\n";
echo ".info { color: blue; }\n";
echo "pre { background: #f4f4f4; padding: 10px; overflow-x: auto; }\n";
echo "</style>\n";
echo "</head>\n";
echo "<body>\n";
echo "<h1>Orbit HRMS Installation</h1>\n";

try {
    $db = Database::getInstance();
    echo "<p class='success'>✓ Database connection successful</p>\n";
    
    // Check if already installed
    $tables = $db->fetchAll("SHOW TABLES");
    if (!empty($tables)) {
        echo "<p class='info'>Database already contains tables. Please clear the database or use a fresh database.</p>\n";
        echo "<p>Existing tables: " . implode(', ', array_column($tables, array_keys($tables)[0])) . "</p>\n";
        echo "</body></html>\n";
        exit;
    }
    
    echo "<h2>Step 1: Creating Database Schema</h2>\n";
    
    // Read and execute schema
    $schemaFile = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: $schemaFile");
    }
    
    $schema = file_get_contents($schemaFile);
    
    // Split by semicolon and execute each statement
    $statements = explode(';', $schema);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^(--|SET|START|COMMIT)/i', $statement)) {
            try {
                $db->query($statement);
            } catch (Exception $e) {
                // Some statements might fail, continue
                echo "<p class='error'>Error executing: " . htmlspecialchars(substr($statement, 0, 100)) . "...</p>\n";
            }
        }
    }
    
    echo "<p class='success'>✓ Database schema created successfully</p>\n";
    
    echo "<h2>Step 2: Seeding Initial Data</h2>\n";
    
    // Read and execute seed data
    $seedFile = __DIR__ . '/database/seed_data.sql';
    if (!file_exists($seedFile)) {
        throw new Exception("Seed data file not found: $seedFile");
    }
    
    $seedData = file_get_contents($seedFile);
    
    // Split by semicolon and execute each statement
    $seedStatements = explode(';', $seedData);
    foreach ($seedStatements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^(--|SET|USE)/i', $statement)) {
            try {
                $db->query($statement);
            } catch (Exception $e) {
                echo "<p class='error'>Error executing seed: " . htmlspecialchars(substr($statement, 0, 100)) . "...</p>\n";
            }
        }
    }
    
    echo "<p class='success'>✓ Initial data seeded successfully</p>\n";
    
    echo "<h2>Step 3: Creating Admin User</h2>\n";
    
    // Create default admin user
    $adminData = [
        'employee_id' => 'ADMIN-001',
        'first_name' => 'System',
        'last_name' => 'Administrator',
        'official_email' => 'admin@orbits.com',
        'phone_number' => '0000000000',
        'role_id' => 1, // Super Admin
        'username' => 'admin',
        'password' => 'admin123',
        'date_of_joining' => date('Y-m-d'),
        'is_active' => 1
    ];
    
    $db->insert('employees', $adminData);
    echo "<p class='success'>✓ Admin user created successfully</p>\n";
    
    echo "<h2>Installation Complete!</h2>\n";
    echo "<div class='success' style='background: #d4edda; padding: 20px; border-radius: 5px;'>\n";
    echo "<h3>Login Credentials:</h3>\n";
    echo "<p><strong>Username:</strong> admin</p>\n";
    echo "<p><strong>Password:</strong> admin123</p>\n";
    echo "<p><strong>Login URL:</strong> <a href='login.php'>login.php</a></p>\n";
    echo "<p style='color: #856404;'><strong>Important:</strong> Please change the admin password after first login!</p>\n";
    echo "</div>\n";
    
    echo "<h2>Next Steps:</h2>\n";
    echo "<ol>\n";
    echo "<li>Login with the admin credentials above</li>\n";
    echo "<li>Change the admin password in Settings</li>\n";
    echo "<li>Create departments and roles as needed</li>\n";
    echo "<li>Add employees to the system</li>\n";
    echo "<li>Configure shifts, leave types, and other settings</li>\n";
    echo "</ol>\n";
    
    echo "<p class='info'><strong>Note:</strong> Delete this file (install.php) after installation for security.</p>\n";
    
} catch (Exception $e) {
    echo "<p class='error'>✗ Installation failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "</body>\n";
echo "</html>\n";
