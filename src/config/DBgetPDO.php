<?php
/**
 * Database Functions - PDO
 * Uses dbconnect() function for connection
 * File: config/DBgetPDO.php
 */

require_once __DIR__ . '/DBconnect.php';

// ============================================================
// USER FUNCTIONS
// ============================================================

/**
 * Get user by username or email for login
 * @param string $username Username or email
 * @param string $password Plain text password
 * @return array|false User data or false if failed
 */
function dbGetLoginUser($username, $password) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT * FROM users WHERE username = :username OR email = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":username" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    
    if ($user && password_verify($password, $user['password'])) {
        return $user;
    }
    return false;
}

/**
 * Get user by ID
 * @param int $user_id User ID
 * @return array|false User data or false if not found
 */
function dbGetUserById($user_id) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT * FROM users WHERE id = :user_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Get user by username
 * @param string $username Username
 * @return array|false User data or false if not found
 */
function dbGetUserByUsername($username) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":username" => $username]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Get user by email
 * @param string $email Email address
 * @return array|false User data or false if not found
 */
function dbGetUserByEmail($email) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":email" => $email]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Get all users
 * @param string $order_by Column to order by
 * @param string $order Order direction (ASC/DESC)
 * @return array Array of users
 */
function dbGetAllUsers($order_by = 'id', $order = 'ASC') {
    dbconnect();
    global $pdo;
    
    $allowed_columns = ['id', 'username', 'email', 'role', 'created_at'];
    if (!in_array($order_by, $allowed_columns)) {
        $order_by = 'id';
    }
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    
    $sql = "SELECT id, username, email, role, created_at FROM users ORDER BY $order_by $order";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Create a new user
 * @param string $username Username
 * @param string $email Email
 * @param string $password Plain text password
 * @param string $role Role ('admin' or 'finance')
 * @return int|false Last insert ID or false on error
 */
function dbCreateUser($username, $email, $password, $role = 'admin') {
    dbconnect();
    global $pdo;

    $role = in_array($role, ['admin', 'finance'], true) ? $role : 'admin';
    $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $sql = "INSERT INTO users (username, email, password, role) VALUES (:username, :email, :password, :role)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":username" => $username,
        ":email" => $email,
        ":password" => $hashed_password,
        ":role" => $role
    ]);
    $id = dbLastInsertId('users');
    $stmt = null;
    return $id;
}

/**
 * Last inserted auto-increment/serial id, portable across drivers.
 * MySQL's PDO returns it with no argument; PostgreSQL needs the
 * sequence name (our schema uses "<table>_id_seq" for every id column).
 */
function dbLastInsertId($table) {
    global $pdo;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        return $pdo->lastInsertId($table . '_id_seq');
    }
    return $pdo->lastInsertId();
}

/**
 * Update user
 * @param int $user_id User ID
 * @param array $data Array of fields to update
 * @return bool True on success
 */
function dbUpdateUser($user_id, $data) {
    dbconnect();
    global $pdo;
    
    $allowed_fields = ['username', 'email'];
    $updates = [];
    $params = [":user_id" => $user_id];
    
    foreach ($data as $field => $value) {
        if (in_array($field, $allowed_fields)) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $value;
        }
    }
    
    // Handle password separately
    if (isset($data['password']) && !empty($data['password'])) {
        $hashed_password = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $updates[] = "password = :password";
        $params[":password"] = $hashed_password;
    }
    
    if (empty($updates)) {
        return false;
    }
    
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :user_id";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    $stmt = null;
    return $result;
}

/**
 * Delete user
 * @param int $user_id User ID
 * @return bool True on success
 */
function dbDeleteUser($user_id) {
    dbconnect();
    global $pdo;
    
    $sql = "DELETE FROM users WHERE id = :user_id";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([":user_id" => $user_id]);
    $stmt = null;
    return $result;
}

/**
 * Check if username exists
 * @param string $username Username to check
 * @return bool True if exists
 */
function dbUsernameExists($username) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM users WHERE username = :username";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":username" => $username]);
    $count = $stmt->fetchColumn();
    $stmt = null;
    return $count > 0;
}

/**
 * Check if email exists
 * @param string $email Email to check
 * @return bool True if exists
 */
function dbEmailExists($email) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM users WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":email" => $email]);
    $count = $stmt->fetchColumn();
    $stmt = null;
    return $count > 0;
}

/**
 * Count total users
 * @return int Number of users
 */
function dbCountUsers() {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM users";
    $stmt = $pdo->query($sql);
    $count = (int)$stmt->fetchColumn();
    $stmt = null;
    return $count;
}

/**
 * Get user's specific field
 * @param int $user_id User ID
 * @param string $field Column name to get
 * @return mixed|null Field value or null
 */
function dbGetUserField($user_id, $field) {
    dbconnect();
    global $pdo;
    
    $sql = "SELECT $field FROM users WHERE id = :user_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data[$field] ?? null;
}

/**
 * Update a user's role ('admin' or 'finance').
 */
function dbUpdateUserRole($user_id, $role) {
    $role = in_array($role, ['admin', 'finance'], true) ? $role : 'admin';
    return dbUpdate('users', ['role' => $role], ['id' => (int)$user_id]);
}

/**
 * Set a new password for a user (hashed with bcrypt).
 */
function dbUpdateUserPassword($user_id, $password) {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    return dbUpdate('users', ['password' => $hash], ['id' => (int)$user_id]);
}

/**
 * Rename a user's username.
 */
function dbRenameUser($user_id, $username) {
    return dbUpdate('users', ['username' => $username], ['id' => (int)$user_id]);
}

/**
 * Ensure the users table has the role column (self-healing, like
 * dbEnsurePayrollSchema). Existing users default to 'admin'.
 */
function dbEnsureUserRoleColumn() {
    try {
        if (!dbTableExists('users')) {
            return;
        }
        if (!dbColumnExists('users', 'role')) {
            $after = dbDriver() === 'pgsql' ? '' : ' AFTER email';
            dbExecute("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'" . $after);
        }
    } catch (PDOException $e) {
        error_log('dbEnsureUserRoleColumn: ' . $e->getMessage());
    }
}

/**
 * Role of the currently logged-in user (defaults to 'admin').
 */
function currentUserRole() {
    return $_SESSION['role'] ?? 'admin';
}

/**
 * Redirect to dashboard unless the logged-in user has the given role.
 * Returns true when the caller may continue.
 */
function requireRole($role) {
    if (currentUserRole() !== $role) {
        header('Location: dashboard.php');
        exit();
    }
    return true;
}

// ============================================================
// GENERIC QUERY FUNCTIONS
// ============================================================

/**
 * Fetch all rows from a query
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array Results
 */
function dbFetchAll($sql, $params = []) {
    dbconnect();
    global $pdo;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Fetch one row from a query
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array|false Single result or false
 */
function dbFetchOne($sql, $params = []) {
    dbconnect();
    global $pdo;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    return $data;
}

/**
 * Fetch a single column value
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return mixed Column value
 */
function dbFetchColumn($sql, $params = []) {
    dbconnect();
    global $pdo;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchColumn();
    $stmt = null;
    return $data;
}

/**
 * Execute a query (INSERT/UPDATE/DELETE)
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return int Number of affected rows
 */
function dbExecute($sql, $params = []) {
    dbconnect();
    global $pdo;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt = null;
    return $count;
}

/**
 * Insert a record and get last insert ID
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @return int|false Last insert ID or false
 */
function dbInsert($table, $data) {
    dbconnect();
    global $pdo;
    
    $columns = array_keys($data);
    $placeholders = array_map(function($col) {
        return ":$col";
    }, $columns);
    
    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    $id = dbLastInsertId($table);
    $stmt = null;
    return $id;
}

/**
 * Update a record
 * @param string $table Table name
 * @param array $data Associative array of column => value (to update)
 * @param array $where Associative array of column => value (conditions)
 * @return int Number of affected rows
 */
function dbUpdate($table, $data, $where) {
    dbconnect();
    global $pdo;
    
    $set = array_map(function($col) {
        return "$col = :$col";
    }, array_keys($data));
    
    $where_clauses = array_map(function($col) {
        return "$col = :where_$col";
    }, array_keys($where));
    
    $params = [];
    foreach ($data as $key => $value) {
        $params[":$key"] = $value;
    }
    foreach ($where as $key => $value) {
        $params[":where_$key"] = $value;
    }
    
    $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where_clauses);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt = null;
    return $count;
}

/**
 * Delete a record
 * @param string $table Table name
 * @param array $where Associative array of column => value (conditions)
 * @return int Number of affected rows
 */
function dbDelete($table, $where) {
    dbconnect();
    global $pdo;
    
    $where_clauses = array_map(function($col) {
        return "$col = :$col";
    }, array_keys($where));
    
    $sql = "DELETE FROM $table WHERE " . implode(' AND ', $where_clauses);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($where);
    $count = $stmt->rowCount();
    $stmt = null;
    return $count;
}

/**
 * Check if a table exists
 * @param string $table Table name
 * @return bool True if exists
 */
function dbTableExists($table) {
    dbconnect();
    global $pdo;

    $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public' : 'database()';
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = $schema AND table_name = :table"
    );
    $stmt->execute([":table" => $table]);
    $exists = (int)$stmt->fetchColumn() > 0;
    $stmt = null;
    return $exists;
}

/**
 * Check whether a column exists on a table (driver-portable).
 */
function dbColumnExists($table, $column) {
    dbconnect();
    global $pdo;

    $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public' : 'database()';
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = $schema AND table_name = :table AND column_name = :column"
    );
    $stmt->execute([":table" => $table, ":column" => $column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    $stmt = null;
    return $exists;
}
?>