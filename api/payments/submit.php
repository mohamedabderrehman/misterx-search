<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/PaymentRequest.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['paymentId']) || empty($data['transactionHash'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payment ID and transaction hash are required']);
    exit;
}

$paymentRequest = new PaymentRequest();
$request = $paymentRequest->findById($data['paymentId']);

if (!$request || $request['user_id'] != $userData['userId']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Payment request not found']);
    exit;
}

// Update transaction hash and status
$paymentRequest->updateTransactionHash($data['paymentId'], $data['transactionHash']);

echo json_encode([
    'success' => true,
    'message' => 'Payment submitted successfully. Your request is under review.'
]);

