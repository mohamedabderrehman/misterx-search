<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/Subscription.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$plans = Subscription::getPricingPlans();

echo json_encode([
    'success' => true,
    'data' => ['plans' => $plans]
]);

