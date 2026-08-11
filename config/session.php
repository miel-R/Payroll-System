<?php
// E:\PAYROLL\config\session.php
// Session bootstrap.
//
// Vercel serverless functions are stateless: PHP's file-based sessions would
// live on an ephemeral filesystem and be lost between requests, so logins
// would randomly fail. When the environment variable PAYROLL_DB_SESSIONS is
// set to "1", this registers a session handler backed by the shared MySQL
// `sessions` table so login state survives across lambda instances.
// Otherwise it falls back to native file sessions (local development).

require_once __DIR__ . '/DBconnect.php';

// SessionHandlerInterface object (not the deprecated per-callback form, which
// PHP 8.5 rejects and which printed warnings that broke session_start()).
class PayrollDbSessionHandler implements SessionHandlerInterface {
    public function open(string $save_path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        try {
            $pdo = dbconnect();
            $stmt = $pdo->prepare('SELECT data FROM sessions WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetchColumn();
            return $row === false ? '' : (string)$row;
        } catch (Exception $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool {
        try {
            $pdo = dbconnect();
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (id, data, last_accessed) VALUES (:id, :data, :ts)
                 ON DUPLICATE KEY UPDATE data = :data2, last_accessed = :ts2'
            );
            $ts = time();
            $stmt->execute([':id' => $id, ':data' => $data, ':ts' => $ts, ':data2' => $data, ':ts2' => $ts]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function destroy(string $id): bool {
        try {
            $pdo = dbconnect();
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false {
        try {
            $pdo = dbconnect();
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE last_accessed < :cutoff');
            $stmt->execute([':cutoff' => time() - (int)$max_lifetime]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

function payroll_session_start() {
    // trim(): env vars set via the Vercel CLI can carry a trailing newline
    // ("1\n\r\n"), which made this comparison false and silently fell back to
    // native file sessions (ephemeral on serverless -> random logouts).
    if (trim((string)getenv('PAYROLL_DB_SESSIONS')) !== '1') {
        session_start();
        return;
    }

    static $handler = null;

    if ($handler === null) {
        try {
            $pdo = dbconnect();
            $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(128) PRIMARY KEY,
                data TEXT NOT NULL,
                last_accessed INT NOT NULL
            ) ENGINE=InnoDB');
        } catch (Exception $e) {
            session_start();
            return;
        }

        $handler = new PayrollDbSessionHandler();
        session_set_save_handler($handler);
    }

    session_start();
}
