<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/CryptoWallet.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$wallet = new CryptoWallet();
$wallets = $wallet->getAllActive();

echo json_encode([
    'success' => true,
    'data' => ['wallets' => $wallets]
]);

