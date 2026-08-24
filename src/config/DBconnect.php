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
    $db_driver = '';
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

    $db_driver = strtolower(trim((string)(getenv('DB_DRIVER') ?: $db_driver)));
    $db_host = trim((string)(getenv('DB_HOST') ?: $db_host));
    $db_port = trim((string)(getenv('DB_PORT') ?: $db_port));
    $db_user = trim((string)(getenv('DB_USER') ?: $db_user));
    $db_pass = trim((string)(getenv('DB_PASSWORD') ?: $db_pass));
    $db_name = trim((string)(getenv('DB_NAME') ?: $db_name));
    $db_ssl = trim((string)(getenv('DB_SSL') ?: $db_ssl));

    if ($db_driver !== 'pgsql' && $db_driver !== 'postgresql' && $db_driver !== 'postgres') {
        $db_driver = 'mysql';
    } else {
        $db_driver = 'pgsql';
    }

    if ($db_host === '' || $db_user === '' || $db_name === '') {
        // Local development fallback.
        $db_host = $db_host !== '' ? $db_host : 'localhost';
        $db_user = $db_user !== '' ? $db_user : 'root';
        $db_pass = $db_pass !== '' ? $db_pass : '';
        $db_name = $db_name !== '' ? $db_name : 'wip0';
    }

    return [$db_driver, $db_host, $db_port, $db_user, $db_pass, $db_name, $db_ssl];
}

/**
 * Driver name of the active PDO connection ('mysql' or 'pgsql').
 * Lets the rest of the app branch on dialect where SQL differs.
 */
function dbDriver() {
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    [$db_driver] = dbCreds();
    return $db_driver;
}

function dbconnect() {
    global $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$db_driver, $db_host, $db_port, $db_user, $db_pass, $db_name, $db_ssl] = dbCreds();

    // Vercel's PHP lambda sometimes fails getaddrinfo(); the old code ALWAYS
    // pre-resolved (blocking seconds when the resolver was slow). Now we try
    // the hostname directly first - pdo_pgsql/pdo_mysql resolve fine most of
    // the time - and only fall back to manual A-record lookup + IP connect
    // when the driver reports a name-resolution error.
    static $resolved_ip_cache = null;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if ($db_driver === 'pgsql') {
        $dsn = "pgsql:host=$db_host";
        $dsn .= ';port=' . ($db_port !== '' ? $db_port : '5432');
        $dsn .= ";dbname=$db_name";
        if ($db_ssl === '1' || $db_ssl === 'true' || $db_ssl === 'on') {
            // Supabase and other cloud Postgres endpoints require TLS.
            $dsn .= ';sslmode=require';
        }
    } else {
        $dsn = "mysql:host=$db_host";
        if ($db_port !== '') {
            $dsn .= ";port=$db_port";
        }
        $dsn .= ";dbname=$db_name;charset=utf8mb4";

        // Cloud MySQL hosts (Aiven, TiDB, ...) require TLS on their public
        // endpoints. PHP 8.5 moved the pdo_mysql SSL attribute constants to
        // Pdo\Mysql:: (the PDO::MYSQL_ATTR_* aliases are deprecated). Resolve
        // whichever set this PHP build exposes.
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
    }

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    } catch (PDOException $e) {
        // Name-resolution trouble? Resolve once (cached for this worker's
        // lifetime) and retry through the IP.
        $msg = strtolower($e->getMessage());
        $dns_fail = strpos($msg, 'could not translate') !== false
            || strpos($msg, 'getaddrinfo') !== false
            || strpos($msg, 'name or service') !== false
            || strpos($msg, 'no such host') !== false
            || strpos($msg, 'temporary failure in name') !== false;
        if ($dns_fail && !filter_var($db_host, FILTER_VALIDATE_IP)) {
            $ip = $resolved_ip_cache ?? gethostbyname($db_host);
            if ((!$ip || $ip === $db_host || !filter_var($ip, FILTER_VALIDATE_IP))) {
                $recs = @dns_get_record($db_host, DNS_A);
                $ip = is_array($recs) && isset($recs[0]['ip']) ? $recs[0]['ip'] : '';
            }
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $resolved_ip_cache = $ip;
                $retry_dsn = substr_replace($dsn, "host=$ip", 6, strlen("host=$db_host"));
                try {
                    $pdo = new PDO($retry_dsn, $db_user, $db_pass, $options);
                } catch (PDOException $e2) {
                    error_log("Database Connection Error: " . $e2->getMessage());
                    die("Database connection failed. Please try again later.");
                }
            } else {
                error_log("Database Connection Error: " . $e->getMessage());
                die("Database connection failed. Please try again later.");
            }
        } else {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }

    // Managed Postgres/MySQL tiers limit concurrent connections (Supabase
    // free tier especially). Vercel's PHP worker can stay warm across
    // requests, so drop the PDO when this request ends - every page reopens
    // lazily on demand via dbconnect(). This also covers hard exits
    // (header()+die redirects) that never reach the footer.
    //
    // ORDER MATTERS: flush the session BEFORE releasing the PDO. PHP closes
    // sessions after userspace shutdown functions, so without this the
    // session write would find $pdo gone, open a SECOND connection (which
    // intermittently fails under pooler limits) and silently lose the write
    // -> regenerated CSRF token -> every POST looked like a forced logout.
    static $shutdown_registered = false;
    if (!$shutdown_registered) {
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            global $pdo;
            $pdo = null;
        });
        $shutdown_registered = true;
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
