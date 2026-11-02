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
 * Sends a single confirmation email to the buyer via native PHP mail()
 */
function send_order_email($buyer_email, $order_id, $btc_address, $received_sats, $product_name = 'CTOOZ License', $tx_hash = null) {
    if (!filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (empty($tx_hash)) {
        $tx_hash = $order_id;
    }
    $safe_tx_hash = htmlspecialchars($tx_hash);

    $from_email = 'support@ctooz.com';
    $from_name = 'CTOOZ Support';
    $subject = 'Transaction Confirmed - Your ' . $product_name . ' Details & Access';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body, table, td, p, a, div {
    margin: 0;
    padding: 0;
    border: 0;
    font-size: 100%;
    font-family: Helvetica Neue, Helvetica, Arial, sans-serif;
    line-height: 1.6;
    -webkit-text-size-adjust: 100%;
    -ms-text-size-adjust: 100%;
  }
  body {
    background-color: #f4f6f9;
    padding: 24px 12px;
  }
  .email-container {
    max-width: 580px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.10);
    border: 1px solid #e9edf2;
  }
  .header {
    background: #D9540D;
    border-bottom: 4px solid #B84308;
    padding: 26px 30px;
  }
  .header h1 {
    color: #ffffff;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: 4px;
    margin: 0;
    text-transform: uppercase;
    text-shadow: 0 1px 2px rgba(0,0,0,0.15);
    font-family: Helvetica Neue, Helvetica, Arial, sans-serif;
  }
  .header .tagline {
    color: rgba(255,255,255,0.85);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 5px;
  }
  .content {
    padding: 34px 30px 30px 30px;
  }
  .content h2 {
    color: #1a202c;
    font-size: 21px;
    font-weight: 700;
    margin: 0 0 18px 0;
    line-height: 1.3;
    letter-spacing: -0.2px;
  }
  .content p {
    color: #4a5568;
    font-size: 15px;
    margin: 0 0 18px 0;
    line-height: 1.75;
  }
  .content p:last-child {
    margin-bottom: 0;
  }
  .hash-box {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #D9540D;
    border-radius: 8px;
    padding: 16px 18px;
    margin: 22px 0 26px 0;
    word-break: break-all;
  }
  .hash-box .label {
    font-size: 12px;
    font-weight: 700;
    color: #2d3748;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 7px;
    display: block;
  }
  .hash-box .value {
    font-family: Courier New, Courier, monospace;
    font-size: 14px;
    color: #1a202c;
    line-height: 1.55;
  }
  .note {
    background: #fffaf0;
    border-left: 4px solid #DD6B20;
    padding: 15px 18px;
    margin: 22px 0;
    border-radius: 0 8px 8px 0;
    font-size: 14px;
    color: #7b341e;
    line-height: 1.65;
  }
  .note strong {
    color: #9c4221;
  }
  .divider {
    height: 1px;
    background: #e2e8f0;
    margin: 26px 0;
  }
  .footer {
    background: #f7fafc;
    padding: 18px 30px;
    text-align: center;
    border-top: 1px solid #e2e8f0;
  }
  .footer p {
    font-size: 12px;
    color: #a0aec0;
    margin: 0;
    letter-spacing: 0.2px;
  }
  @media only screen and (max-width: 480px) {
    body {
      padding: 12px 6px;
    }
    .header {
      padding: 20px 20px;
    }
    .header h1 {
      font-size: 20px;
      letter-spacing: 3px;
    }
    .header .tagline {
      font-size: 9px;
      letter-spacing: 1.5px;
    }
    .content {
      padding: 24px 18px 22px 18px;
    }
    .content h2 {
      font-size: 18px;
    }
    .content p {
      font-size: 14px;
    }
    .hash-box {
      padding: 13px 14px;
    }
    .hash-box .value {
      font-size: 13px;
    }
    .note {
      padding: 12px 14px;
      font-size: 13px;
    }
    .footer {
      padding: 14px 18px;
    }
  }
</style>
</head>
<body>
  <div class="email-container">
    <div class="header">
      <h1>CTOOZ</h1>
      <div class="tagline">Secure Digital Delivery</div>
    </div>
    <div class="content">
      <h2>Transaction Confirmed</h2>
      <p>Dear Partner,</p>
      <p>Thank you for your transaction. We are pleased to confirm that your payment has been successfully processed.</p>

      <div class="hash-box">
        <span class="label">Transaction Hash</span>
        <span class="value">{$safe_tx_hash}</span>
      </div>

      <p>We would like to inform you that our servers have been temporarily seized by the authorities (vvv and ccc). As a result, our services are currently undergoing restoration.</p>

      <p>Our technical team is working to restore the infrastructure as soon as possible. Once the servers are operational, or if an alternative delivery option becomes available sooner, we will promptly provide you with a secure download link for your software.</p>

      <div class="note">
        <strong>Important:</strong> Please keep your transaction hash safe and secure. It will serve as your transaction reference and may be required to retrieve your files through our portal once the service is restored.
      </div>

      <p>We sincerely apologize for the inconvenience and appreciate your patience and understanding during this period.</p>

      <p>If you have any questions or experience any issues related to your transaction or software delivery, please feel free to contact our support team by replying to this email.</p>

      <p>Thank you for your continued trust in CTOOZ.</p>

      <div class="divider"></div>

      <p style="margin-bottom:0;">
        Best regards,<br>
        <strong>CTOOZ Support Team</strong><br>
        Ctooz
      </p>
    </div>
    <div class="footer">
      <p>© 2024 CTOOZ. All rights reserved.</p>
    </div>
  </div>
</body>
</html>
HTML;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'Reply-To: ' . $from_email,
        'X-Mailer: PHP/' . phpversion()
    ];

    if (function_exists('mail')) {
        return @mail($buyer_email, $subject, $html, implode("\r\n", $headers));
    }
    return false;
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

    // Ensure email_sent column exists
    try {
        $pdo->exec("ALTER TABLE btc_orders ADD COLUMN email_sent INTEGER DEFAULT 0");
    } catch (Exception $e) {}

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
    $tx_hash = !empty($order['txid']) ? $order['txid'] : $order['order_id'];

    // Rule 1 & Rule 2: Tolerance Percentage & Minimum Acceptable Sats calculation
    $tolerance_percentage = defined('ALLOWED_UNDERPAYMENT_PERCENTAGE') ? (float)ALLOWED_UNDERPAYMENT_PERCENTAGE : 1.5;
    $minimum_acceptable_sats = (int)round($expected_sats * (1 - ($tolerance_percentage / 100)));

    // Micro-test allowance: For sample / $1 test orders ($1.00 USD), allow 1000 sats so exchange test payments pass
    if ($price_usd <= 1.00 && $expected_sats <= 2500) {
        $minimum_acceptable_sats = min($minimum_acceptable_sats, 1000);
    }

    // If order already confirmed paid, return immediately
    // If email was never sent before, use atomic lock to ensure EXACTLY ONE email is sent
    if ($order['status'] === 'paid') {
        $rec = (int)$order['received_sats'];
        $is_under = ($rec < $expected_sats);
        $is_over = ($rec > $expected_sats);
        
        $email_sent = (int)($order['email_sent'] ?? 0);
        if ($email_sent === 0 && !empty($order['buyer_email'])) {
            // ATOMIC LOCK: Only 1 request can update from 0 to 1
            $lock = $pdo->prepare("UPDATE btc_orders SET email_sent = 1 WHERE id = :id AND email_sent = 0");
            $lock->execute([':id' => $order['id']]);
            if ($lock->rowCount() > 0) {
                send_order_email($order['buyer_email'], $order['order_id'], $btc_address, $rec, $order['product_name'] ?? 'CTOOZ License', $tx_hash);
                $email_sent = 1;
            }
        }

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
            'buyerEmail' => $order['buyer_email'],
            'emailSent' => true,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
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

        // 1. Immediately mark order as paid in DB
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

        // 2. ATOMIC LOCK for email: Only 1 process can update email_sent from 0 to 1
        // This guarantees EXACTLY ONE email is sent even if 10 concurrent requests arrive!
        $lock = $pdo->prepare("UPDATE btc_orders SET email_sent = 1 WHERE id = :id AND email_sent = 0");
        $lock->execute([':id' => $order['id']]);
        if ($lock->rowCount() > 0) {
            send_order_email($order['buyer_email'], $order['order_id'], $btc_address, $total_received_sats, $order['product_name'] ?? 'CTOOZ License', $tx_hash);
        }

        echo json_encode([
            'status' => 'paid',
            'settled' => true,
            'orderId' => $order['order_id'],
            'address' => $btc_address,
            'buyerEmail' => $order['buyer_email'],
            'emailSent' => true,
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
            'buyerEmail' => $order['buyer_email'],
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
            'buyerEmail' => $order['buyer_email'],
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
        'buyerEmail' => $order['buyer_email'],
        'expectedSats' => $expected_sats,
        'minimumAcceptableSats' => $minimum_acceptable_sats,
        'receivedSats' => 0,
        'expiresAt' => $order['expires_at']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Verification error: ' . $e->getMessage()]);
}
