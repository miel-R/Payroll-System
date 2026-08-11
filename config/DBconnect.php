<?php
// E:\PAYROLL\config\DBconnect.php

/**
 * Resolve database credentials in this order:
 *   1. Environment variables  DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
 *      (used on Vercel and any other host that supports env vars)
 *   2. A gitignored local config/db_credentials.php that may define the
 *      same $db_host/$db_user/$db_pass/$db_name globals (used on shared
 *      hosts like InfinityFree so real credentials are never committed).
 *   3. Local development defaults (XAMPP/WAMP: root, no password, wip0).
 */
function dbCreds() {
    $db_host = '';
    $db_user = '';
    $db_pass = '';
    $db_name = '';

    $env_file = __DIR__ . '/db_credentials.php';
    if (is_file($env_file)) {
        require $env_file;
    }

    $db_host = (string)(getenv('DB_HOST') ?: $db_host);
    $db_user = (string)(getenv('DB_USER') ?: $db_user);
    $db_pass = (string)(getenv('DB_PASSWORD') ?: $db_pass);
    $db_name = (string)(getenv('DB_NAME') ?: $db_name);

    if ($db_host === '' || $db_user === '' || $db_name === '') {
        // Local development fallback.
        $db_host = $db_host !== '' ? $db_host : 'localhost';
        $db_user = $db_user !== '' ? $db_user : 'root';
        $db_pass = $db_pass !== '' ? $db_pass : '';
        $db_name = $db_name !== '' ? $db_name : 'wip0';
    }

    return [$db_host, $db_user, $db_pass, $db_name];
}

function dbconnect() {
    global $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$db_host, $db_user, $db_pass, $db_name] = dbCreds();

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Connection Error: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    }
    
    return $pdo;
}

function verifyPassword($inputPassword, $hashedPassword) {
    return password_verify($inputPassword, $hashedPassword);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}
?>
