<?php
// E:\PAYROLL\create_user.php
require_once __DIR__ . '/../src/config/DBgetPDO.php';

$username = 'admin';
$email = 'admin@example.com';
$password = 'admin123';

// Check if user already exists
if (dbUsernameExists($username)) {
    echo "⚠️ User already exists!<br>";
    echo "<a href='index.php'>Go to Login</a>";
    exit();
}

// Create user
$id = dbCreateUser($username, $email, $password);

if ($id) {
    echo "✅ User created successfully!<br>";
    echo "👤 Username: <strong>$username</strong><br>";
    echo "🔑 Password: <strong>$password</strong><br>";
    echo "📋 User ID: <strong>$id</strong><br>";
    echo "<br><a href='index.php'>Go to Login</a>";
} else {
    echo "❌ Failed to create user.";
}
?>