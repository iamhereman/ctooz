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

/**
 * Sends a confirmation email to the buyer via Resend API
 */
function send_order_email($buyer_email, $order_id, $btc_address, $received_sats) {
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY || !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        $ch = curl_init('https://api.resend.com/emails');
        $from = defined('RESEND_FROM') ? RESEND_FROM : 'Ctooz Support <support@ctooz.com>';
        $download_link = defined('DOWNLOAD_LINK') ? DOWNLOAD_LINK : 'https://ctooz.com/success';
        $formatted_sats = number_format($received_sats);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background: #f9f9f9; margin: 0; padding: 20px;">
  <div style="max-width: 560px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #edf2f7;">
    <div style="background: #1a202c; padding: 24px 30px;">
      <h1 style="color: #ffffff; font-size: 22px; margin: 0; letter-spacing: 1px;">CTOOZ</h1>
    </div>
    <div style="padding: 30px;">
      <h2 style="color: #1a202c; font-size: 18px; margin-top: 0;">Payment Confirmed - Order Access Ready</h2>
      <p style="color: #4a5568; font-size: 14px; line-height: 1.6;">Hello,</p>
      <p style="color: #4a5568; font-size: 14px; line-height: 1.6;">
        We have successfully verified your Bitcoin payment on the blockchain! Your access and license details are ready.
      </p>
      <div style="text-align: center; margin: 25px 0;">
        <a href="{$download_link}" style="background: #D9540D; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">
          Access Your License (ctooz.com/success)
        </a>
      </div>
      <div style="background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 20px 0;">
        <p style="margin: 0 0 8px 0; font-size: 13px; color: #4a5568;"><strong>Order ID:</strong> {$order_id}</p>
        <p style="margin: 0 0 8px 0; font-size: 13px; color: #4a5568;"><strong>Payment Address:</strong> {$btc_address}</p>
        <p style="margin: 0; font-size: 13px; color: #4a5568;"><strong>Amount Paid:</strong> {$formatted_sats} Sats</p>
      </div>
      <p style="color: #718096; font-size: 12px; line-height: 1.5;">
        If you have questions or require direct support, reply to this email or visit ctooz.com.
      </p>
    </div>
  </div>
</body>
</html>
HTML;

        $payload = [
            'from' => $from,
            'to' => [$buyer_email],
            'subject' => 'Transaction Confirmed - Your CTOOZ License Access and Details',
            'html' => $html
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        curl_close($ch);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

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

    $expected_sats = (int)$order['expected_sats'];
    $price_usd = (float)$order['price_usd'];
    $btc_address = $order['bitcoin_address'];

    // Rule 1 & Rule 2: Tolerance Percentage & Minimum Acceptable Sats calculation
    $tolerance_percentage = defined('ALLOWED_UNDERPAYMENT_PERCENTAGE') ? (float)ALLOWED_UNDERPAYMENT_PERCENTAGE : 1.5;
    $minimum_acceptable_sats = (int)round($expected_sats * (1 - ($tolerance_percentage / 100)));

    // Micro-test allowance: For sample / $1 test orders ($1.00 USD), allow 1000 sats so exchange test payments pass
    if ($price_usd <= 1.00 && $expected_sats <= 2500) {
        $minimum_acceptable_sats = min($minimum_acceptable_sats, 1000);
    }

    // If order already confirmed paid, return immediately
    if ($order['status'] === 'paid') {
        $rec = (int)$order['received_sats'];
        $is_under = ($rec < $expected_sats);
        $is_over = ($rec > $expected_sats);
        
        $msg = 'Payment confirmed on Bitcoin blockchain!';
        if ($is_under) {
            $msg = 'Payment detected with slight fee deduction, successfully processing your order...';
        } elseif ($is_over) {
            $msg = 'Payment verified (overpayment received)! Successfully processing your order...';
        }

        echo json_encode([
            'status' => 'paid',
            'settled' => true,
            'orderId' => $order['order_id'],
            'address' => $order['bitcoin_address'],
            'expectedSats' => $expected_sats,
            'receivedSats' => $rec,
            'minimumAcceptableSats' => $minimum_acceptable_sats,
            'tolerancePercentage' => $tolerance_percentage,
            'underpayment_tolerated' => $is_under,
            'isUnderpaidTolerated' => $is_under,
            'isOverpaid' => $is_over,
            'message' => $msg,
            'paidAt' => $order['paid_at'],
            'redirect' => 'success'
        ]);
        exit;
    }

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

    // Rule 2: Payment Verified if total_received_sats >= minimum_acceptable_sats
    // Covers: Exact payment, Overpayment, and Underpayment within tolerance percentage
    if ($total_received_sats >= $minimum_acceptable_sats) {
        $is_underpaid_tolerated = ($total_received_sats < $expected_sats);
        $is_overpaid = ($total_received_sats > $expected_sats);

        $msg = 'Payment confirmed on Bitcoin blockchain!';
        if ($is_underpaid_tolerated) {
            $msg = 'Payment detected with slight fee deduction, successfully processing your order...';
        } elseif ($is_overpaid) {
            $msg = 'Payment verified (overpayment received)! Successfully processing your order...';
        }

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

        // Send order confirmation email via Resend
        send_order_email($order['buyer_email'], $order['order_id'], $btc_address, $total_received_sats);

        echo json_encode([
            'status' => 'paid',
            'settled' => true,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'receivedSats' => $total_received_sats,
            'minimumAcceptableSats' => $minimum_acceptable_sats,
            'tolerancePercentage' => $tolerance_percentage,
            'underpayment_tolerated' => $is_underpaid_tolerated,
            'isUnderpaidTolerated' => $is_underpaid_tolerated,
            'isOverpaid' => $is_overpaid,
            'isMempoolZeroConf' => ($chain_sats < $minimum_acceptable_sats),
            'message' => $msg,
            'paidAt' => $now,
            'redirect' => 'success'
        ]);
        exit;
    }

    // Partial Payment: Received some sats, but below minimum acceptable threshold
    if ($total_received_sats > 0) {
        $update = $pdo->prepare("UPDATE btc_orders SET received_sats = :rec WHERE id = :id");
        $update->execute([':rec' => $total_received_sats, ':id' => $order['id']]);

        $due_sats = $expected_sats - $total_received_sats;

        echo json_encode([
            'status' => 'partial',
            'settled' => false,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'expectedSats' => $expected_sats,
            'minimumAcceptableSats' => $minimum_acceptable_sats,
            'tolerancePercentage' => $tolerance_percentage,
            'receivedSats' => $total_received_sats,
            'dueSats' => $due_sats,
            'underpayment_tolerated' => false,
            'isUnderpaidTolerated' => false,
            'isOverpaid' => false,
            'message' => "Partial payment received ({$total_received_sats} Sats). Still due: {$due_sats} Sats."
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
        'minimumAcceptableSats' => $minimum_acceptable_sats,
        'receivedSats' => 0,
        'expiresAt' => $order['expires_at']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Verification error: ' . $e->getMessage()]);
}
