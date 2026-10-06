<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Subscription.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['plan_type']) || empty($data['duration'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Plan type and duration are required']);
    exit;
}

$planType = $data['plan_type'];
$duration = $data['duration'];

if (!in_array($planType, ['vip', 'api'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid plan type']);
    exit;
}

// Get pricing
$plans = Subscription::getPricingPlans();
$planOptions = $plans[$planType];
$selectedPlan = null;

foreach ($planOptions as $plan) {
    if ($plan['duration'] === $duration) {
        $selectedPlan = $plan;
        break;
    }
}

if (!$selectedPlan) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid duration']);
    exit;
}

$subscription = new Subscription();

// Create subscription (payment_status: pending)
$subscriptionId = $subscription->create(
    $userData['userId'],
    $planType,
    $duration,
    $selectedPlan['price'],
    'pending'
);

if (!$subscriptionId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create subscription']);
    exit;
}

// TODO: Integrate with payment gateway (Stripe, PayPal, etc.)
// For now, we'll simulate successful payment
// In production, you should:
// 1. Create payment intent
// 2. Return payment URL to frontend
// 3. Handle webhook for payment confirmation

// Simulate payment success (remove this in production)
$subscription->updateStatus($subscriptionId, 'completed');

// Calculate expiration date
$durationMap = [
    '1_week' => 7,
    '1_month' => 30,
    '3_months' => 90,
    '6_months' => 180,
    '12_months' => 365
];

$days = $durationMap[$duration] ?? 30;
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

// Update user subscription
$user = new User();
$user->updateSubscription(
    $userData['userId'],
    $planType,
    'active',
    $expiresAt
);

echo json_encode([
    'success' => true,
    'message' => 'Subscription activated successfully',
    'data' => [
        'subscriptionId' => $subscriptionId,
        'plan_type' => $planType,
        'duration' => $duration,
        'expiresAt' => $expiresAt
    ]
]);

