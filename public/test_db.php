<?php
/**
 * Database Connection Test File
 * Use this to verify your database connection is working
 * Access: http://localhost:8080/test_db.php
 */

require_once __DIR__ . '/../src/config/DBconnect.php';

dbconnect();

echo "<!DOCTYPE html>";
echo "<html lang='en'>";
echo "<head>";
echo "    <meta charset='UTF-8'>";
echo "    <meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "    <title>Database Test</title>";
echo "    <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
            .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
            .success { color: #28a745; }
            .error { color: #dc3545; }
            .warning { color: #ffc107; }
            .info { color: #17a2b8; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { background: #764ba2; color: white; padding: 10px; text-align: left; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
            tr:hover { background: #f8f9fa; }
            .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
            .badge-success { background: #28a745; color: white; }
            .badge-danger { background: #dc3545; color: white; }
            .badge-warning { background: #ffc107; color: #333; }
            .badge-info { background: #17a2b8; color: white; }
            h2 { color: #333; }
            hr { border: 0; border-top: 1px solid #ddd; margin: 20px 0; }
        </style>";
echo "</head>";
echo "<body>";

echo "<div class='card'>";
echo "    <h2>🔍 Database Connection Test</h2>";
echo "    <hr>";

// Show environment
$environment = ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1') ? 'LOCAL' : 'PRODUCTION';
$badgeClass = $environment == 'LOCAL' ? 'badge-success' : 'badge-info';
echo "    <p><strong>Environment:</strong> <span class='badge $badgeClass'>$environment</span></p>";

try {
    // Test 1: Database connection
    echo "    <h3>📊 Database Connection</h3>";
    $stmt = $pdo->query("SELECT DATABASE() as db, VERSION() as version, CURRENT_TIMESTAMP as time");
    $info = $stmt->fetch();
    
    echo "    <table>";
    echo "        <tr><td><strong>Database Name:</strong></td><td><span class='success'>✅ " . htmlspecialchars($info['db']) . "</span></td></tr>";
    echo "        <tr><td><strong>MySQL Version:</strong></td><td>" . htmlspecialchars($info['version']) . "</td></tr>";
    echo "        <tr><td><strong>Server Time:</strong></td><td>" . htmlspecialchars($info['time']) . "</td></tr>";
    echo "    </table>";
    
    // Test 2: Check users table
    echo "    <h3>👥 Users Table</h3>";
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "    <p><span class='success'>✅</span> Table 'users' exists</p>";
        
        // Count users
        $count = $pdo->query("SELECT COUNT(*) as total FROM users")->fetch();
        echo "    <p><strong>Total Users:</strong> " . $count['total'] . "</p>";
        
        // List users
        if ($count['total'] > 0) {
            echo "    <h4>📋 User List</h4>";
            $users = $pdo->query("SELECT id, username, email, created_at FROM users ORDER BY id")->fetchAll();
            echo "    <table>";
            echo "        <tr><th>ID</th><th>Username</th><th>Email</th><th>Created</th></tr>";
            foreach ($users as $user) {
                echo "        <tr>";
                echo "            <td>" . htmlspecialchars($user['id']) . "</td>";
                echo "            <td><strong>" . htmlspecialchars($user['username']) . "</strong></td>";
                echo "            <td>" . htmlspecialchars($user['email']) . "</td>";
                echo "            <td>" . htmlspecialchars($user['created_at']) . "</td>";
                echo "        </tr>";
            }
            echo "    </table>";
        } else {
            echo "    <p><span class='warning'>⚠️</span> No users found. <a href='create_user.php'>Create a test user</a></p>";
        }
    } else {
        echo "    <p><span class='error'>❌</span> Table 'users' does not exist!</p>";
        echo "    <p>Please run this SQL to create it:</p>";
        echo "    <pre style='background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto;'>
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (username, email, password) 
VALUES ('admin', 'admin@example.com', '$2y$12$hT5Xx9kL.vT5Xx9kL.vT5Xu8GJ2nK4LmN5OpQ6RsT7UvW8XyZ9AbC');
        </pre>";
    }
    
    // Test 3: Test password verification
    echo "    <h3>🔐 Password Verification Test</h3>";
    $testUsername = 'admin';
    $testPassword = 'admin123';
    
    $stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
    $stmt->execute([$testUsername]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo "    <p>Testing username: <strong>" . htmlspecialchars($testUsername) . "</strong></p>";
        echo "    <p>Testing password: <strong>" . htmlspecialchars($testPassword) . "</strong></p>";
        
        if (password_verify($testPassword, $user['password'])) {
            echo "    <p><span class='success'>✅</span> Password verification: <span class='success'><strong>SUCCESS</strong></span></p>";
            echo "    <p>You can login with: <strong>admin</strong> / <strong>admin123</strong></p>";
        } else {
            echo "    <p><span class='error'>❌</span> Password verification: <span class='error'><strong>FAILED</strong></span></p>";
            echo "    <p><a href='reset_password.php'>Reset password</a></p>";
        }
    } else {
        echo "    <p><span class='warning'>⚠️</span> User 'admin' not found. <a href='create_user.php'>Create user</a></p>";
    }
    
    // Test 4: Connection info
    echo "    <h3>🔧 Connection Details</h3>";
    echo "    <table>";
    echo "        <tr><td><strong>Host:</strong></td><td>" . htmlspecialchars($host) . "</td></tr>";
    echo "        <tr><td><strong>Database:</strong></td><td>" . htmlspecialchars($dbname) . "</td></tr>";
    echo "        <tr><td><strong>Username:</strong></td><td>" . htmlspecialchars($username) . "</td></tr>";
    echo "        <tr><td><strong>Charset:</strong></td><td>utf8mb4</td></tr>";
    echo "        <tr><td><strong>PDO Status:</strong></td><td><span class='success'>✅ Connected</span></td></tr>";
    echo "    </table>";
    
} catch (PDOException $e) {
    echo "    <h3>❌ Connection Error</h3>";
    echo "    <div style='background: #fef2f2; padding: 15px; border-radius: 5px; border-left: 4px solid #dc3545;'>";
    echo "        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "    </div>";
    echo "    <h4>Troubleshooting:</h4>";
    echo "    <ul>";
    echo "        <li>Check if MySQL is running</li>";
    echo "        <li>Verify database credentials in <code>config/DBconnect.php</code></li>";
    echo "        <li>Make sure the database '" . htmlspecialchars($dbname) . "' exists</li>";
    echo "    </ul>";
}

echo "</div>";

// Quick links
echo "<div class='card'>";
echo "    <h4>🚀 Quick Links</h4>";
echo "    <ul style='list-style: none; padding: 0;'>";
echo "        <li style='margin: 8px 0;'>🔑 <a href='index.php'>Login Page</a></li>";
echo "        <li style='margin: 8px 0;'>📊 <a href='dashboard.php'>Dashboard</a></li>";
echo "        <li style='margin: 8px 0;'>👤 <a href='create_user.php'>Create User</a></li>";
echo "        <li style='margin: 8px 0;'>🔄 <a href='reset_password.php'>Reset Password</a></li>";
echo "        <li style='margin: 8px 0;'>🗄️ <a href='http://localhost:8080/phpmyadmin' target='_blank'>phpMyAdmin</a></li>";
echo "    </ul>";
echo "</div>";

echo "</body>";
echo "</html>";
?>