<?php
// CTOOZ Custom Non-Custodial Bitcoin Payment Configuration

define('BTC_XPUB', 'xpub6BemYiVNp19ZzLHf9jYggapPP7UeApuWES8dnHaH8R377D9Mm7NEgwcxHV9FwR1zNQQPKURXn8XTZv5Wzvqxut2gnnTXq3Wj5fE2PGsBxGc');
define('BTC_CHANGE_INDEX', 0); // 0 for external receive addresses (/0/*)
define('PRODUCT_PRICE_USD', 499.00);
define('ORDER_EXPIRY_MINUTES', 30); // 30 minutes QR expiration window
define('ALLOWED_UNDERPAYMENT_PERCENTAGE', 1.5); // Allow up to 1.5% fee / underpayment tolerance
define('MEMPOOL_API_BASE', 'https://mempool.space/api');

// Email Notifications (via Resend)
define('RESEND_API_KEY', 're_ByLWgbEk_PpKVYAhqqnBfEQ1wjnjeitgv');
define('RESEND_FROM', 'Ctooz Support <support@ctooz.com>');
define('DOWNLOAD_LINK', 'https://ctooz.com/success');

// Database Configuration: Uses SQLite by default for zero-setup, or MySQL if configured
define('DB_TYPE', 'sqlite'); // 'sqlite' or 'mysql'
define('SQLITE_FILE', __DIR__ . '/btc_payments.db');

// MySQL Credentials (optional - only needed if DB_TYPE is 'mysql')
define('MYSQL_HOST', 'localhost');
define('MYSQL_PORT', 3306);
define('MYSQL_NAME', 'u194157074_ctooz');
define('MYSQL_USER', 'u194157074_user');
define('MYSQL_PASS', '');

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (DB_TYPE === 'sqlite') {
        $dsn = 'sqlite:' . SQLITE_FILE;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        init_sqlite_db($pdo);
    } else {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

function init_sqlite_db($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS btc_addresses_pool (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bitcoin_address TEXT NOT NULL UNIQUE,
            derivation_index INTEGER NOT NULL UNIQUE,
            assigned_to_order_id TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'free',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_pool_status ON btc_addresses_pool (status, expires_at);

        CREATE TABLE IF NOT EXISTS btc_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL UNIQUE,
            buyer_email TEXT NOT NULL,
            product_name TEXT NOT NULL DEFAULT 'CTOOZ License',
            price_usd REAL NOT NULL,
            expected_sats INTEGER NOT NULL,
            expected_btc REAL NOT NULL,
            btc_rate_usd REAL NOT NULL,
            bitcoin_address TEXT NOT NULL,
            derivation_index INTEGER NOT NULL,
            received_sats INTEGER NOT NULL DEFAULT 0,
            txid TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_orders_addr ON btc_orders (bitcoin_address);
        CREATE INDEX IF NOT EXISTS idx_orders_status ON btc_orders (status);
    ");
}
