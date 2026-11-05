import { BTC_XPUB, ORDER_EXPIRY_MINUTES } from './config.js';
import { deriveBip84Address } from './bip84.js';

let dbInitialized = false;

/**
 * Ensures required database tables exist in Cloudflare D1
 */
export async function ensureDbInitialized(db) {
    if (dbInitialized) return;
    try {
        await db.prepare(`
            CREATE TABLE IF NOT EXISTS btc_addresses_pool (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bitcoin_address TEXT NOT NULL UNIQUE,
                derivation_index INTEGER NOT NULL UNIQUE,
                assigned_to_order_id TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'free',
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        `).run();

        await db.prepare(`CREATE INDEX IF NOT EXISTS idx_pool_status ON btc_addresses_pool (status, expires_at)`).run();

        await db.prepare(`
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
            )
        `).run();

        await db.prepare(`CREATE INDEX IF NOT EXISTS idx_orders_addr ON btc_orders (bitcoin_address)`).run();
        await db.prepare(`CREATE INDEX IF NOT EXISTS idx_orders_status ON btc_orders (status)`).run();
        await db.prepare(`CREATE INDEX IF NOT EXISTS idx_orders_txid ON btc_orders (txid)`).run();

        dbInitialized = true;
    } catch (e) {
        console.error('DB initialization error:', e.message);
        throw e;
    }
}

export class AddressPoolManager {
    /**
     * Gets or derives an available Bitcoin address from Cloudflare D1 pool.
     * Reuses expired/free addresses to bypass the 20-address gap limit.
     */
    static async acquireAddress(db, orderId, expiryMinutes = ORDER_EXPIRY_MINUTES) {
        await ensureDbInitialized(db);

        const now = new Date();
        const expiresAt = new Date(now.getTime() + expiryMinutes * 60000).toISOString().replace('T', ' ').substring(0, 19);
        const nowIso = now.toISOString().replace('T', ' ').substring(0, 19);

        // Retire any addresses that have received partial payments to prevent reuse
        try {
            await db.prepare(`
                UPDATE btc_addresses_pool 
                SET status = 'used', updated_at = ? 
                WHERE status != 'used' 
                  AND bitcoin_address IN (SELECT DISTINCT bitcoin_address FROM btc_orders WHERE received_sats > 0)
            `).bind(nowIso).run();
        } catch (e) {}

        // 1. Look for a reusable address: status is 'free' OR ('pending' and expired)
        // Never reuse an address that has ever received satoshis!
        const reusable = await db.prepare(`
            SELECT id, bitcoin_address, derivation_index 
            FROM btc_addresses_pool 
            WHERE (status = 'free' OR (status = 'pending' AND expires_at < ?))
              AND bitcoin_address NOT IN (SELECT DISTINCT bitcoin_address FROM btc_orders WHERE received_sats > 0)
            ORDER BY derivation_index ASC 
            LIMIT 1
        `).bind(nowIso).first();

        if (reusable) {
            // Reuse existing address
            await db.prepare(`
                UPDATE btc_addresses_pool 
                SET status = 'pending', assigned_to_order_id = ?, expires_at = ?, updated_at = ?
                WHERE id = ?
            `).bind(orderId, expiresAt, nowIso, reusable.id).run();

            return {
                address: reusable.bitcoin_address,
                index: reusable.derivation_index,
                reused: true,
                expiresAt: expiresAt
            };
        }

        // 2. If no reusable address found, all active addresses are pending within window
        const maxRow = await db.prepare("SELECT MAX(derivation_index) as max_idx FROM btc_addresses_pool").first();
        const nextIndex = (maxRow && maxRow.max_idx !== null && maxRow.max_idx !== undefined) 
            ? (Number(maxRow.max_idx) + 1) 
            : 0;

        // Derive brand new Native SegWit (bc1q...) address from static xpub
        const newAddress = deriveBip84Address(BTC_XPUB, nextIndex, 0);

        // Insert into pool
        await db.prepare(`
            INSERT INTO btc_addresses_pool (bitcoin_address, derivation_index, assigned_to_order_id, status, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', ?, ?, ?)
        `).bind(newAddress, nextIndex, orderId, expiresAt, nowIso, nowIso).run();

        return {
            address: newAddress,
            index: nextIndex,
            reused: false,
            expiresAt: expiresAt
        };
    }

    /**
     * Marks an address permanently as 'used' when payment is verified or partial payment received
     */
    static async markUsed(db, bitcoinAddress) {
        await ensureDbInitialized(db);
        const nowIso = new Date().toISOString().replace('T', ' ').substring(0, 19);
        await db.prepare("UPDATE btc_addresses_pool SET status = 'used', updated_at = ? WHERE bitcoin_address = ?")
            .bind(nowIso, bitcoinAddress)
            .run();
    }
}
