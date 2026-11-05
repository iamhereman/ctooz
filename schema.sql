-- Cloudflare D1 Database Schema for CTOOZ Bitcoin Payments

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
    email_sent INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_orders_addr ON btc_orders (bitcoin_address);
CREATE INDEX IF NOT EXISTS idx_orders_status ON btc_orders (status);
CREATE INDEX IF NOT EXISTS idx_orders_txid ON btc_orders (txid);
