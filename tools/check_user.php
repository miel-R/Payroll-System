<?php
require_once __DIR__ . '/../src/config/DBconnect.php';

dbconnect();

echo "<h2>🔍 Find My Password</h2>";

// Get the stored hash
$stmt = $pdo->prepare("SELECT username, password FROM users WHERE username = 'admin'");
$stmt->execute();
$user = $stmt->fetch();

if ($user) {
    echo "Username: <strong>" . $user['username'] . "</strong><br>";
    echo "Stored Hash: <code>" . $user['password'] . "</code><br><br>";
    
    // Test common passwords
    $commonPasswords = [
        'admin',
        'admin123',
        'password',
        '123456',
        'admin@123',
        'Admin@123',
        'wip0',
        'payroll',
        'admin123!',
        'password123'
    ];
    
    echo "<h3>Testing Common Passwords:</h3>";
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Password Tested</th><th>Result</th></tr>";
    
    $found = false;
    foreach ($commonPasswords as $testPassword) {
        if (password_verify($testPassword, $user['password'])) {
            echo "<tr style='background: #90EE90;'>";
            echo "<td><strong>" . htmlspecialchars($testPassword) . "</strong></td>";
            echo "<td>✅ <strong>MATCH FOUND!</strong> ← This is your password</td>";
            echo "</tr>";
            $found = true;
            break;
        } else {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($testPassword) . "</td>";
            echo "<td>❌ No match</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    
    if (!$found) {
        echo "<br>⚠️ None of the common passwords matched.<br>";
        echo "Try checking your create_user.php file to see what password was set.<br>";
        
        // Option to reset password
        echo "<h3>🔧 Reset Password</h3>";
        echo "<form method='POST'>";
        echo "<input type='text' name='new_password' placeholder='Enter new password' required>";
        echo "<button type='submit' name='reset'>Reset Password</button>";
        echo "</form>";
        
        if (isset($_POST['reset']) && !empty($_POST['new_password'])) {
            $newPass = $_POST['new_password'];
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
            $update->execute([$newHash]);
            echo "<br>✅ Password reset to: <strong>" . htmlspecialchars($newPass) . "</strong>";
            echo "<br><a href='index.php'>Go to Login</a>";
        }
    }
} else {
    echo "❌ User 'admin' not found in database.<br>";
    echo "<a href='create_user.php'>Create a user</a>";
}
?>