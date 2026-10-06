<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ApiKey.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$userId = $userData['userId'];

// Check if user has API subscription
$user = new User();
$userInfo = $user->findById($userId);

if ($userInfo['subscription_type'] !== 'api') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'API access requires API subscription'
    ]);
    exit;
}

$apiKey = new ApiKey();
$keyData = $apiKey->create($userId);

if ($keyData) {
    echo json_encode([
        'success' => true,
        'message' => 'API key generated successfully',
        'data' => $keyData
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate API key']);
}

