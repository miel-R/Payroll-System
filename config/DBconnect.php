<?php
// E:\PAYROLL\config\DBconnect.php

/**
 * Resolve database credentials in this order:
 *   1. Environment variables  DB_HOST, DB_PORT, DB_USER, DB_PASSWORD,
 *      DB_NAME, DB_SSL, DB_SSL_CA  (used on Vercel and any host that
 *      supports env vars)
 *   2. A gitignored local config/db_credentials.php that may define the
 *      same $db_host/$db_port/$db_user/$db_pass/$db_name/$db_ssl globals
 *      (used on shared hosts like InfinityFree so real credentials are
 *      never committed).
 *   3. Local development defaults (XAMPP/WAMP: root, no password, wip0).
 */
function dbCreds() {
    $db_host = '';
    $db_port = '';
    $db_user = '';
    $db_pass = '';
    $db_name = '';
    $db_ssl = '';

    $env_file = __DIR__ . '/db_credentials.php';
    if (is_file($env_file)) {
        require $env_file;
    }

    $db_host = (string)(getenv('DB_HOST') ?: $db_host);
    $db_port = (string)(getenv('DB_PORT') ?: $db_port);
    $db_user = (string)(getenv('DB_USER') ?: $db_user);
    $db_pass = (string)(getenv('DB_PASSWORD') ?: $db_pass);
    $db_name = (string)(getenv('DB_NAME') ?: $db_name);
    $db_ssl = (string)(getenv('DB_SSL') ?: $db_ssl);

    if ($db_host === '' || $db_user === '' || $db_name === '') {
        // Local development fallback.
        $db_host = $db_host !== '' ? $db_host : 'localhost';
        $db_user = $db_user !== '' ? $db_user : 'root';
        $db_pass = $db_pass !== '' ? $db_pass : '';
        $db_name = $db_name !== '' ? $db_name : 'wip0';
    }

    return [$db_host, $db_port, $db_user, $db_pass, $db_name, $db_ssl];
}

function dbconnect() {
    global $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$db_host, $db_port, $db_user, $db_pass, $db_name, $db_ssl] = dbCreds();

    $dsn = "mysql:host=$db_host";
    if ($db_port !== '') {
        $dsn .= ";port=$db_port";
    }
    $dsn .= ";dbname=$db_name;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // Cloud hosts (Aiven, TiDB, ...) require TLS on their public endpoints.
    if ($db_ssl === '1' || $db_ssl === 'true' || $db_ssl === 'on') {
        $ca = (string)(getenv('DB_SSL_CA') ?: '');
        if ($ca !== '' && is_file($ca)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        } else {
            // Encrypt the connection without verifying the server certificate
            // (the practical path on Vercel, which cannot mount a CA file).
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
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
