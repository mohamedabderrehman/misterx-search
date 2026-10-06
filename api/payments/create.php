<?php
// Suppress errors and warnings to prevent HTML output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers first to ensure JSON output even on errors
if (function_exists('setCORSHeaders')) {
    setCORSHeaders();
} else {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../models/PaymentRequest.php';
    require_once __DIR__ . '/../models/CryptoWallet.php';
    require_once __DIR__ . '/../models/Subscription.php';
    require_once __DIR__ . '/../utils/auth.php';
} catch (Exception $e) {
    error_log('Payment create - File include error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error. Please try again later.'
    ]);
    exit;
} catch (Error $e) {
    error_log('Payment create - Fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error. Please try again later.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $userData = authenticate();
} catch (Exception $authError) {
    $statusCode = $authError->getCode() && $authError->getCode() >= 400 && $authError->getCode() < 600 ? $authError->getCode() : 401;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $authError->getMessage()
    ]);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['plan_type']) || empty($data['duration']) || empty($data['cryptocurrency'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Plan type, duration, and cryptocurrency are required']);
        exit;
    }

    // Get pricing
    $plans = Subscription::getPricingPlans();
    $planOptions = $plans[$data['plan_type']] ?? [];
    $selectedPlan = null;

    foreach ($planOptions as $plan) {
        if ($plan['duration'] === $data['duration']) {
            $selectedPlan = $plan;
            break;
        }
    }

    if (!$selectedPlan) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plan or duration']);
        exit;
    }

    // Get wallet for cryptocurrency
    $cryptoWallet = new CryptoWallet();
    $wallet = $cryptoWallet->getByCrypto($data['cryptocurrency']);

    if (!$wallet) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selected cryptocurrency is not available']);
        exit;
    }

    // Create subscription record (pending)
    $subscription = new Subscription();
    $subscriptionId = $subscription->create(
        $userData['userId'],
        $data['plan_type'],
        $data['duration'],
        $selectedPlan['price'],
        'pending'
    );

    if (!$subscriptionId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create subscription record']);
        exit;
    }

    // Calculate amount to send in cryptocurrency
    // amount is in USD, we need to convert it to crypto
    $amountUSD = $selectedPlan['price'];
    
    // Get exchange rate (price of 1 unit of crypto in USD)
    // If exchange_rate is null or 0, default to 1 (for stablecoins like USDT)
    $exchangeRate = !empty($wallet['exchange_rate']) && $wallet['exchange_rate'] > 0 
        ? (float)$wallet['exchange_rate'] 
        : 1.0;
    
    // Calculate crypto amount: USD amount / exchange rate
    // Add small fee (0.024436%) for network fees
    $amountToSend = ($amountUSD / $exchangeRate) * 1.00024436;

    // Create payment request
    $paymentRequest = new PaymentRequest();
    $paymentId = $paymentRequest->create([
        'user_id' => $userData['userId'],
        'subscription_id' => $subscriptionId,
        'plan_type' => $data['plan_type'],
        'duration' => $data['duration'],
        'amount' => $amountUSD,
        'cryptocurrency' => $data['cryptocurrency'],
        'wallet_address' => $wallet['wallet_address'],
        'amount_to_send' => $amountToSend,
        'payment_address' => $wallet['wallet_address']
    ]);

    if ($paymentId) {
        $request = $paymentRequest->findById($paymentId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment request created',
            'data' => [
                'paymentId' => $paymentId,
                'amount' => $amountUSD,
                'amountUSD' => $amountUSD,
                'amountToSend' => $amountToSend,
                'cryptocurrency' => $data['cryptocurrency'],
                'paymentAddress' => $wallet['wallet_address'],
                'plan' => $data['plan_type'],
                'duration' => $data['duration'],
                'exchangeRate' => $exchangeRate
            ]
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create payment request']);
        exit;
    }
} catch (Exception $e) {
    // Log error details but don't expose to user
    error_log('Payment creation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Payment creation failed. Please try again later.'
    ]);
    exit;
} catch (Error $e) {
    // Log fatal errors
    error_log('Payment creation fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Payment creation failed. Please try again later.'
    ]);
    exit;
}

