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

    $db_host = trim((string)(getenv('DB_HOST') ?: $db_host));
    $db_port = trim((string)(getenv('DB_PORT') ?: $db_port));
    $db_user = trim((string)(getenv('DB_USER') ?: $db_user));
    $db_pass = trim((string)(getenv('DB_PASSWORD') ?: $db_pass));
    $db_name = trim((string)(getenv('DB_NAME') ?: $db_name));
    $db_ssl = trim((string)(getenv('DB_SSL') ?: $db_ssl));

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

    // Vercel's PHP lambda has an unreliable system resolver: getaddrinfo()
    // (used by mysqlnd/streams) fails for some hostnames, and gethostbyname()
    // can too, while PHP's own dns_get_record() still resolves fine.
    // Resolve the hostname to an IP here so the connect skips getaddrinfo.
    if (!filter_var($db_host, FILTER_VALIDATE_IP)) {
        $orig = $db_host;
        $gb = gethostbyname($orig);
        $ip = $gb;
        if ($gb === $orig || !filter_var($gb, FILTER_VALIDATE_IP)) {
            $recs = @dns_get_record($orig, DNS_A);
            if (is_array($recs) && isset($recs[0]['ip'])) {
                $ip = $recs[0]['ip'];
            }
        }
        if ($ip !== $orig && filter_var($ip, FILTER_VALIDATE_IP)) {
            $db_host = $ip;
        }
    }

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
    // PHP 8.5 moved the pdo_mysql SSL attribute constants to Pdo\Mysql::
    // (the PDO::MYSQL_ATTR_* aliases are deprecated). Resolve whichever set
    // this PHP build exposes.
    $ssl_ca_attr       = defined('Pdo\\Mysql::ATTR_SSL_CA')
        ? constant('Pdo\\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA;
    $ssl_verify_attr   = defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
        ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

    if ($db_ssl === '1' || $db_ssl === 'true' || $db_ssl === 'on') {
        $ca = (string)(getenv('DB_SSL_CA') ?: '');
        if ($ca !== '' && is_file($ca)) {
            $options[$ssl_ca_attr] = $ca;
        } else {
            // Encrypt the connection without verifying the server certificate
            // (the practical path on Vercel, which cannot mount a CA file).
            $options[$ssl_verify_attr] = false;
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
