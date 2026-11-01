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

function fetch_btc_usd_rate() {
    // 1. Try Mempool.space
    $ch = curl_init('https://mempool.space/api/v1/prices');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $json = json_decode($res, true);
        if (isset($json['USD']) && is_numeric($json['USD']) && $json['USD'] > 0) {
            return (float)$json['USD'];
        }
    }

    // 2. Fallback to Coinbase API
    $ch = curl_init('https://api.coinbase.com/v2/prices/BTC-USD/spot');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $json = json_decode($res, true);
        if (isset($json['data']['amount']) && is_numeric($json['data']['amount'])) {
            return (float)$json['data']['amount'];
        }
    }

    // Conservative default if both external APIs are unreachable
    return 78000.00;
}

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];

    $buyer_email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$buyer_email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
        exit;
    }

    // Price can be 499 (standard product) or 1 (sample test)
    $price_usd = isset($input['amount']) && is_numeric($input['amount']) && $input['amount'] > 0
        ? (float)$input['amount']
        : PRODUCT_PRICE_USD;

    $product_name = $price_usd <= 1.00 ? 'CTOOZ $1 Test Verification' : 'CTOOZ Lifetime License';

    // Unique Order ID
    $order_id = 'CTZ-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

    // Get real-time rate & calculate sats
    $rate = fetch_btc_usd_rate();
    $expected_sats = (int)round(($price_usd / $rate) * 100000000);
    $expected_btc = sprintf('%.8f', $expected_sats / 100000000);

    // Tolerance and minimum acceptable sats
    $tolerance_pct = defined('ALLOWED_UNDERPAYMENT_PERCENTAGE') ? (float)ALLOWED_UNDERPAYMENT_PERCENTAGE : 1.5;
    $min_acceptable_sats = (int)round($expected_sats * (1 - ($tolerance_pct / 100)));
    if ($price_usd <= 1.00 && $expected_sats <= 2500) {
        $min_acceptable_sats = min($min_acceptable_sats, 1000);
    }

    // Acquire address from pool (reuses expired address to bypass 20 gap limit)
    $pool_item = AddressPoolManager::acquire_address($order_id, ORDER_EXPIRY_MINUTES);
    $btc_address = $pool_item['address'];
    $derivation_index = $pool_item['index'];
    $expires_at = $pool_item['expires_at'];

    // Insert order into DB
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        INSERT INTO btc_orders (order_id, buyer_email, product_name, price_usd, expected_sats, expected_btc, btc_rate_usd, bitcoin_address, derivation_index, expires_at)
        VALUES (:order_id, :email, :prod, :usd, :sats, :btc, :rate, :addr, :idx, :exp)
    ");
    $stmt->execute([
        ':order_id' => $order_id,
        ':email' => $buyer_email,
        ':prod' => $product_name,
        ':usd' => $price_usd,
        ':sats' => $expected_sats,
        ':btc' => $expected_btc,
        ':rate' => $rate,
        ':addr' => $btc_address,
        ':idx' => $derivation_index,
        ':exp' => $expires_at
    ]);

    $payment_link = "bitcoin:{$btc_address}?amount={$expected_btc}";
    $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($payment_link);

    echo json_encode([
        'success' => true,
        'orderId' => $order_id,
        'productName' => $product_name,
        'priceUsd' => $price_usd,
        'btcAmount' => $expected_btc,
        'sats' => $expected_sats,
        'minimumAcceptableSats' => $min_acceptable_sats,
        'tolerancePercentage' => $tolerance_pct,
        'rate' => $rate,
        'address' => $btc_address,
        'derivationIndex' => $derivation_index,
        'isReused' => $pool_item['reused'],
        'paymentLink' => $payment_link,
        'qrCodeUrl' => $qr_code_url,
        'expiresAt' => $expires_at,
        'expiresInSeconds' => ORDER_EXPIRY_MINUTES * 60
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Checkout error: ' . $e->getMessage()]);
}
