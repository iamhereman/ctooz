<?php
// CTOOZ API Router - Custom Non-Custodial Bitcoin Gateway
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$action = $_GET['action'] ?? '';

if (strpos($path, '/api/create-invoice') !== false || $action === 'create-invoice') {
    require __DIR__ . '/checkout.php';
    exit;
}

if (strpos($path, '/api/invoice-status') !== false || $action === 'invoice-status') {
    require __DIR__ . '/verify_payment.php';
    exit;
}

header('Content-Type: application/json');
http_response_code(404);
echo json_encode(['error' => 'API route not found', 'path' => $path]);
