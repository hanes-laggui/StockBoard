<?php
/**
 * includes/db.php  PDO connection + shared logging helpers.
 * Edit the constants below to match your MySQL/XAMPP environment.
 */
define('DB_HOST',    'localhost');
define('DB_NAME',    'stockboard_dealer');
define('DB_USER',    'root');     //  change to your MySQL username
define('DB_PASS',    '');         //  change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

//  PDO singleton 
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);

            //  Auto-migrate: add is_pending column if missing 
            //    Runs once silently; safe to call on every request.
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_pending'")->fetchAll();
            if (empty($col)) {
                $pdo->exec(
                    "ALTER TABLE users
                     ADD COLUMN is_pending TINYINT(1) NOT NULL DEFAULT 0
                     AFTER is_active"
                );
            }
            $col2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetchAll();
            if (empty($col2)) {
                $pdo->exec(
                    "ALTER TABLE users
                     ADD COLUMN last_seen DATETIME DEFAULT NULL
                     AFTER is_pending"
                );
            }
            // Auto-migrate: add acknowledged_at to agent_commission_payouts
            try {
                $col3 = $pdo->query("SHOW COLUMNS FROM agent_commission_payouts LIKE 'acknowledged_at'")->fetchAll();
                if (empty($col3)) {
                    $pdo->exec(
                        "ALTER TABLE agent_commission_payouts
                         ADD COLUMN acknowledged_at DATETIME DEFAULT NULL AFTER cleared_at"
                    );
                }
            } catch (Throwable $ignored) { /* table may not exist yet */ }
            // 
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// logAudit() and logStockMovement() are declared in includes/auth.php
// Do NOT redeclare them here.

