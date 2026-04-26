<?php
/**
 * Setup System Admin Account
 * Run this once to create/reset the system admin account
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = getPDO();
    
    // Check if system_admins table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_admins'");
    $tableExists = $stmt->fetch();
    
    if (!$tableExists) {
        echo "<h1 style='color: red;'>❌ Error: system_admins table does not exist!</h1>";
        echo "<p>Please run the migration first:</p>";
        echo "<pre>SOURCE " . __DIR__ . "/../v1/migrations/audit_system.sql;</pre>";
        exit;
    }
    
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT * FROM system_admins WHERE username = ?");
    $stmt->execute(['sysadmin']);
    $existingAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingAdmin) {
        echo "<h1>🔍 System Admin Account Found</h1>";
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>ID</td><td>{$existingAdmin['id']}</td></tr>";
        echo "<tr><td>Username</td><td>{$existingAdmin['username']}</td></tr>";
        echo "<tr><td>Email</td><td>{$existingAdmin['email']}</td></tr>";
        echo "<tr><td>Full Name</td><td>{$existingAdmin['full_name']}</td></tr>";
        echo "<tr><td>Is Active</td><td>" . ($existingAdmin['is_active'] ? 'Yes' : 'No') . "</td></tr>";
        echo "<tr><td>2FA Enabled</td><td>" . ($existingAdmin['two_factor_enabled'] ? 'Yes' : 'No') . "</td></tr>";
        echo "<tr><td>Last Login</td><td>{$existingAdmin['last_login']}</td></tr>";
        echo "<tr><td>Created At</td><td>{$existingAdmin['created_at']}</td></tr>";
        echo "</table>";
        
        echo "<h2>🔄 Reset Password?</h2>";
        echo "<form method='POST'>";
        echo "<input type='hidden' name='action' value='reset_password'>";
        echo "<label>New Password: <input type='password' name='new_password' value='password' required></label><br><br>";
        echo "<button type='submit' style='padding: 10px 20px; background: #4CAF50; color: white; border: none; cursor: pointer;'>Reset Password</button>";
        echo "</form>";
    } else {
        echo "<h1 style='color: orange;'>⚠️ System Admin Account Not Found</h1>";
        echo "<p>Creating new system admin account...</p>";
        
        // Create new admin
        $username = 'sysadmin';
        $password = 'password';
        $email = 'sysadmin@gtbdhaka.edu';
        $fullName = 'System Administrator';
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("
            INSERT INTO system_admins (username, password, email, full_name, is_active, two_factor_enabled, created_at)
            VALUES (?, ?, ?, ?, 1, 0, NOW())
        ");
        
        $stmt->execute([$username, $hashedPassword, $email, $fullName]);
        
        echo "<h2 style='color: green;'>✅ System Admin Account Created!</h2>";
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>Username</td><td>{$username}</td></tr>";
        echo "<tr><td>Password</td><td>{$password}</td></tr>";
        echo "<tr><td>Email</td><td>{$email}</td></tr>";
        echo "<tr><td>Full Name</td><td>{$fullName}</td></tr>";
        echo "</table>";
    }
    
    // Handle password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $newPassword = $_POST['new_password'];
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("UPDATE system_admins SET password = ? WHERE username = 'sysadmin'");
        $stmt->execute([$hashedPassword]);
        
        echo "<h2 style='color: green;'>✅ Password Reset Successfully!</h2>";
        echo "<p><strong>New Password:</strong> {$newPassword}</p>";
    }
    
    echo "<hr>";
    echo "<h2>🧪 Test Login</h2>";
    echo "<p>Try logging in with these credentials:</p>";
    echo "<ul>";
    echo "<li><strong>Username:</strong> sysadmin</li>";
    echo "<li><strong>Password:</strong> password (or your custom password)</li>";
    echo "</ul>";
    
    echo "<h3>Test in Postman:</h3>";
    echo "<pre>";
    echo "POST http://localhost/School_Project/Final_Enhancements/new_api_deploy/system-admin/auth\n";
    echo "Content-Type: application/json\n\n";
    echo "{\n";
    echo "  \"username\": \"sysadmin\",\n";
    echo "  \"password\": \"password\"\n";
    echo "}";
    echo "</pre>";
    
    echo "<h3>Test in Browser Console:</h3>";
    echo "<pre>";
    echo "fetch('http://localhost/School_Project/Final_Enhancements/new_api_deploy/system-admin/auth', {\n";
    echo "  method: 'POST',\n";
    echo "  headers: { 'Content-Type': 'application/json' },\n";
    echo "  body: JSON.stringify({ username: 'sysadmin', password: 'password' })\n";
    echo "})\n";
    echo ".then(r => r.json())\n";
    echo ".then(d => console.log(d));";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<h1 style='color: red;'>❌ Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
