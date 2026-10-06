<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Subscription.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$userId = $userData['userId'];

$user = new User();
$userInfo = $user->findById($userId);

$subscription = new Subscription();
$currentSubscription = $subscription->getCurrent($userId);

echo json_encode([
    'success' => true,
    'data' => [
        'subscription' => $currentSubscription,
        'currentPlan' => [
            'type' => $userInfo['subscription_type'],
            'status' => $userInfo['subscription_status'],
            'expiresAt' => $userInfo['subscription_expires_at']
        ]
    ]
]);

