<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/xpub_derivation.php';

class AddressPoolManager {
    /**
     * Gets or derives an available Bitcoin address from the pool.
     * Reuses expired/free addresses to bypass the 20-address gap limit.
     */
    public static function acquire_address($order_id, $expiry_minutes = 30) {
        $pdo = get_db_connection();
        $now = date('Y-m-d H:i:s');
        $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));

        // Begin transaction to prevent race conditions
        $pdo->beginTransaction();
        try {
            // 1. Look for a reusable address: status is 'free' OR ('pending' and expired)
            $stmt = $pdo->prepare("
                SELECT id, bitcoin_address, derivation_index 
                FROM btc_addresses_pool 
                WHERE status = 'free' OR (status = 'pending' AND expires_at < :now)
                ORDER BY derivation_index ASC 
                LIMIT 1
            ");
            $stmt->execute([':now' => $now]);
            $reusable = $stmt->fetch();

            if ($reusable) {
                // Reuse existing address
                $update = $pdo->prepare("
                    UPDATE btc_addresses_pool 
                    SET status = 'pending', assigned_to_order_id = :order_id, expires_at = :expires_at, updated_at = :now
                    WHERE id = :id
                ");
                $update->execute([
                    ':order_id' => $order_id,
                    ':expires_at' => $expires_at,
                    ':now' => $now,
                    ':id' => $reusable['id']
                ]);
                $pdo->commit();

                return [
                    'address' => $reusable['bitcoin_address'],
                    'index' => (int)$reusable['derivation_index'],
                    'reused' => true,
                    'expires_at' => $expires_at
                ];
            }

            // 2. If no reusable address found, all active addresses are pending within window
            // Find highest derivation index in the pool
            $stmt_max = $pdo->query("SELECT MAX(derivation_index) as max_idx FROM btc_addresses_pool");
            $max_row = $stmt_max->fetch();
            $next_index = ($max_row && $max_row['max_idx'] !== null) ? ((int)$max_row['max_idx'] + 1) : 0;

            // Derive brand new address from static xpub
            $new_address = BIP84Deriver::derive_address(BTC_XPUB, $next_index, BTC_CHANGE_INDEX);

            // Insert into pool
            $insert = $pdo->prepare("
                INSERT INTO btc_addresses_pool (bitcoin_address, derivation_index, assigned_to_order_id, status, expires_at, created_at, updated_at)
                VALUES (:addr, :idx, :order_id, 'pending', :expires_at, :now, :now)
            ");
            $insert->execute([
                ':addr' => $new_address,
                ':idx' => $next_index,
                ':order_id' => $order_id,
                ':expires_at' => $expires_at,
                ':now' => $now
            ]);
            $pdo->commit();

            return [
                'address' => $new_address,
                'index' => $next_index,
                'reused' => false,
                'expires_at' => $expires_at
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Marks an address permanently as 'used' when payment is verified
     */
    public static function mark_used($bitcoin_address) {
        $pdo = get_db_connection();
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE btc_addresses_pool SET status = 'used', updated_at = :now WHERE bitcoin_address = :addr");
        $stmt->execute([':now' => $now, ':addr' => $bitcoin_address]);
    }
}
