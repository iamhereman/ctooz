<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/address_pool.php';

$order_id = $_GET['order_id'] ?? $_GET['id'] ?? '';
$address = $_GET['address'] ?? '';

if (!$order_id && !$address) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing order_id or address parameter']);
    exit;
}

try {
    $pdo = get_db_connection();

    if ($order_id) {
        $stmt = $pdo->prepare("SELECT * FROM btc_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM btc_orders WHERE bitcoin_address = :addr ORDER BY id DESC LIMIT 1");
        $stmt->execute([':addr' => $address]);
    }

    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    // If order already confirmed paid, return immediately
    if ($order['status'] === 'paid') {
        echo json_encode([
            'status' => 'paid',
            'settled' => true,
            'orderId' => $order['order_id'],
            'address' => $order['bitcoin_address'],
            'expectedSats' => (int)$order['expected_sats'],
            'receivedSats' => (int)$order['received_sats'],
            'paidAt' => $order['paid_at'],
            'redirect' => 'success'
        ]);
        exit;
    }

    $btc_address = $order['bitcoin_address'];
    $expected_sats = (int)$order['expected_sats'];

    // Query Mempool.space API (public, no api key required)
    $ch = curl_init(MEMPOOL_API_BASE . "/address/" . urlencode($btc_address));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $mempool_raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$mempool_raw) {
        // Fallback: Return current DB status if Mempool is momentarily unreachable
        echo json_encode([
            'status' => $order['status'],
            'settled' => false,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'receivedSats' => (int)$order['received_sats']
        ]);
        exit;
    }

    $mempool_data = json_decode($mempool_raw, true) ?? [];
    $chain_sats = (int)($mempool_data['chain_stats']['funded_txo_sum'] ?? 0);
    $mempool_sats = (int)($mempool_data['mempool_stats']['funded_txo_sum'] ?? 0);
    $total_received_sats = $chain_sats + $mempool_sats;

    $now = date('Y-m-d H:i:s');
    $is_expired = (strtotime($now) > strtotime($order['expires_at']));

    // Payment Verified (Mempool or Block confirmed)
    if ($total_received_sats >= $expected_sats) {
        $update = $pdo->prepare("
            UPDATE btc_orders 
            SET status = 'paid', received_sats = :rec, paid_at = :now 
            WHERE id = :id
        ");
        $update->execute([
            ':rec' => $total_received_sats,
            ':now' => $now,
            ':id' => $order['id']
        ]);

        // Permanently mark this address as used in the pool
        AddressPoolManager::mark_used($btc_address);

        echo json_encode([
            'status' => 'paid',
            'settled' => true,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'receivedSats' => $total_received_sats,
            'isMempoolZeroConf' => ($chain_sats < $expected_sats),
            'paidAt' => $now,
            'redirect' => 'success'
        ]);
        exit;
    }

    // Partial Payment Detected
    if ($total_received_sats > 0) {
        $update = $pdo->prepare("UPDATE btc_orders SET received_sats = :rec WHERE id = :id");
        $update->execute([':rec' => $total_received_sats, ':id' => $order['id']]);

        echo json_encode([
            'status' => 'partial',
            'settled' => false,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'receivedSats' => $total_received_sats,
            'dueSats' => $expected_sats - $total_received_sats,
            'message' => 'Partial payment received. Awaiting remaining satoshis.'
        ]);
        exit;
    }

    // Still awaiting payment or expired
    if ($is_expired) {
        echo json_encode([
            'status' => 'expired',
            'settled' => false,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'receivedSats' => 0,
            'message' => 'Order window expired. Please generate a new invoice.'
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'pending',
        'settled' => false,
        'orderId' => $order['order_id'],
        'address' => $btc_address,
        'expectedSats' => $expected_sats,
        'receivedSats' => 0,
        'expiresAt' => $order['expires_at']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Verification error: ' . $e->getMessage()]);
}
