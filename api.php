<?php
// Hostinger Ctooz Backend API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

$BTCPAY_HOST = "https://mainnet.demo.btcpayserver.org";
$BTCPAY_API_KEY = "ff45b9924d037a80dfceb0a752204cfce7e782cd";
$BTCPAY_STORE_ID = "HxEKoDq5NDUA5YwqUYv1RsQGQs1D77cZYd5o1JAtFu9r";

$RESEND_API_KEY = getenv('RESEND_API_KEY') ?: ('re_' . 'ByLWgbEk_PpKVYAhqqnBfEQ1wjnjeitgv');
$RESEND_FROM    = "Ctooz Support <support@ctooz.com>";
$DOWNLOAD_LINK  = "https://ctooz.com";

$DB_FILE = __DIR__ . '/invoices_data.json';

function get_invoices_db() {
    global $DB_FILE;
    if (file_exists($DB_FILE)) {
        $content = file_get_contents($DB_FILE);
        $data = json_decode($content, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_invoices_db($data) {
    global $DB_FILE;
    file_put_contents($DB_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function trigger_resend_email($to_email, $invoice_id, $tx_hash = null) {
    global $RESEND_API_KEY, $RESEND_FROM, $DOWNLOAD_LINK;
    if (!$tx_hash) $tx_hash = $invoice_id;
    if (!$to_email || strpos($to_email, '@') === false) {
        return [false, "Invalid email address"];
    }

    $html_body = '<!DOCTYPE html>
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
        <span class="value">' . htmlspecialchars($tx_hash) . '</span>
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
</html>';

    $payload = [
        "from" => $RESEND_FROM,
        "to" => [$to_email],
        "subject" => "Transaction Confirmed - Your Download Link and Details",
        "html" => $html_body
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $RESEND_API_KEY,
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (compatible; Ctooz/1.0)'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return [false, $err];
    $res_json = json_decode($response, true);
    if ($http_code >= 200 && $http_code < 300) {
        return [true, $res_json['id'] ?? 'sent'];
    }
    return [false, "Resend HTTP $http_code: " . $response];
}

// Request path parsing
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);

// Also support query param: ?action=...
$action = $_GET['action'] ?? '';
if (!$action) {
    if (strpos($path, '/api/create-invoice') !== false) $action = 'create-invoice';
    elseif (strpos($path, '/api/simulate-payment') !== false) $action = 'simulate-payment';
    elseif (strpos($path, '/api/invoice-status') !== false) $action = 'invoice-status';
    elseif (strpos($path, '/api/send-confirmation-email') !== false) $action = 'send-confirmation-email';
}

$raw_input = file_get_contents('php://input');
$body = json_decode($raw_input, true) ?? [];

if ($action === 'create-invoice') {
    $email = $body['email'] ?? 'customer@ctooz.com';
    $amount = $body['amount'] ?? '499';
    $currency = $body['currency'] ?? 'USD';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $redirect_url = $body['redirectUrl'] ?? "{$scheme}://{$host}/success";

    $payload = [
        "amount" => (string)$amount,
        "currency" => $currency,
        "metadata" => [
            "orderId" => "CTOOZ-" . time(),
            "itemDesc" => "CTOOZ Complete Software License (Lifetime)",
            "buyerEmail" => $email
        ],
        "checkout" => [
            "redirectURL" => $redirect_url
        ]
    ];

    $ch = curl_init("{$BTCPAY_HOST}/api/v1/stores/{$BTCPAY_STORE_ID}/invoices");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$BTCPAY_API_KEY}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $inv = json_decode($response, true);
    if (!isset($inv['id'])) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "BTCPay error: " . $response]);
        exit;
    }

    $inv_id = $inv['id'];
    $db = get_invoices_db();
    $db[$inv_id] = ["email" => $email, "email_sent" => false, "created_at" => time()];
    save_invoices_db($db);

    // Fetch payment methods
    $ch_pm = curl_init("{$BTCPAY_HOST}/api/v1/stores/{$BTCPAY_STORE_ID}/invoices/{$inv_id}/payment-methods");
    curl_setopt($ch_pm, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_pm, CURLOPT_HTTPHEADER, [
        "Authorization: token {$BTCPAY_API_KEY}"
    ]);
    curl_setopt($ch_pm, CURLOPT_TIMEOUT, 10);
    $res_pm = curl_exec($ch_pm);
    curl_close($ch_pm);

    $pm_list = json_decode($res_pm, true) ?? [];
    $pm = $pm_list[0] ?? [];

    echo json_encode([
        "success" => true,
        "invoiceId" => $inv_id,
        "checkoutLink" => $inv['checkoutLink'] ?? null,
        "destination" => $pm['destination'] ?? "bc1qh9zgq5cr8q5qfmcpfkyr29n0vwx6w6xnax2gr9",
        "btcAmount" => $pm['amount'] ?? "0.0065",
        "paymentLink" => $pm['paymentLink'] ?? ("bitcoin:" . ($pm['destination'] ?? "") . "?amount=" . ($pm['amount'] ?? "")),
        "rate" => $pm['rate'] ?? ""
    ]);
    exit;
}

elseif ($action === 'simulate-payment') {
    $inv_id = $body['invoiceId'] ?? '';
    if (!$inv_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Missing invoiceId"]);
        exit;
    }

    $ch = curl_init("{$BTCPAY_HOST}/api/v1/stores/{$BTCPAY_STORE_ID}/invoices/{$inv_id}/status");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["status" => "Settled"]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$BTCPAY_API_KEY}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $db = get_invoices_db();
    $inv_info = $db[$inv_id] ?? null;
    $to_email = $body['email'] ?? ($inv_info['email'] ?? null);

    if ($to_email) {
        if (!$inv_info) {
            $inv_info = ["email" => $to_email, "email_sent" => false];
            $db[$inv_id] = $inv_info;
        }
        if (empty($inv_info['email_sent'])) {
            list($ok, $msg) = trigger_resend_email($to_email, $inv_id);
            if ($ok) {
                $db[$inv_id]['email_sent'] = true;
                save_invoices_db($db);
            }
        }
    }

    $res_data = json_decode($response, true) ?? [];
    echo json_encode([
        "success" => true,
        "status" => "Settled",
        "additionalStatus" => $res_data['additionalStatus'] ?? null,
        "redirectUrl" => "success"
    ]);
    exit;
}

elseif ($action === 'invoice-status') {
    $inv_id = $_GET['id'] ?? '';
    if (!$inv_id) {
        http_response_code(400);
        echo json_encode(["error" => "Missing id parameter"]);
        exit;
    }

    $ch = curl_init("{$BTCPAY_HOST}/api/v1/stores/{$BTCPAY_STORE_ID}/invoices/{$inv_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$BTCPAY_API_KEY}"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $inv = json_decode($response, true) ?? [];
    $status = $inv['status'] ?? 'Invalid';
    $settled = in_array($status, ["Settled", "Complete", "Paid"]);

    if ($settled) {
        $db = get_invoices_db();
        $inv_info = $db[$inv_id] ?? null;
        $buyer_email = $inv_info['email'] ?? ($inv['metadata']['buyerEmail'] ?? null);
        if ($buyer_email) {
            if (!$inv_info) {
                $inv_info = ["email" => $buyer_email, "email_sent" => false];
                $db[$inv_id] = $inv_info;
            }
            if (empty($inv_info['email_sent'])) {
                list($ok, $msg) = trigger_resend_email($buyer_email, $inv_id);
                if ($ok) {
                    $db[$inv_id]['email_sent'] = true;
                    save_invoices_db($db);
                }
            }
        }
    }

    echo json_encode([
        "status" => $status,
        "additionalStatus" => $inv['additionalStatus'] ?? null,
        "settled" => $settled,
        "checkoutLink" => $inv['checkoutLink'] ?? null
    ]);
    exit;
}

elseif ($action === 'send-confirmation-email') {
    $to_email = $body['email'] ?? '';
    $invoice_id = $body['invoiceId'] ?? 'N/A';
    $tx_hash = $body['txHash'] ?? $invoice_id;

    if (!$to_email || strpos($to_email, '@') === false) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid email address"]);
        exit;
    }

    $db = get_invoices_db();
    $inv_info = $db[$invoice_id] ?? null;
    if (!empty($inv_info['email_sent'])) {
        echo json_encode(["success" => true, "alreadySent" => true]);
        exit;
    }

    list($ok, $res) = trigger_resend_email($to_email, $invoice_id, $tx_hash);
    if ($ok) {
        if (!$inv_info) {
            $db[$invoice_id] = ["email" => $to_email, "email_sent" => true];
        } else {
            $db[$invoice_id]['email_sent'] = true;
        }
        save_invoices_db($db);
        echo json_encode(["success" => true, "emailId" => $res]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $res]);
    }
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not Found", "action" => $action]);
