<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/password.php';

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['emailOrUsername']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email/username and password are required']);
    exit;
}

// Verify reCAPTCHA if token is provided (reCAPTCHA v2 Checkbox)
if (DEMO_MODE) {
    // Synthetic local demonstration only.
} elseif (isset($data['recaptcha_token']) && !empty($data['recaptcha_token'])) {
    try {
        // For reCAPTCHA v2, don't pass action parameter
        $recaptchaResult = verifyRecaptcha($data['recaptcha_token']);
        if (!$recaptchaResult || !$recaptchaResult['success']) {
            error_log('reCAPTCHA verification failed for login: ' . ($recaptchaResult['message'] ?? 'Unknown error'));
            // Require reCAPTCHA for login
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'reCAPTCHA verification failed. Please complete the reCAPTCHA and try again.'
            ]);
            exit;
        }
    } catch (Exception $recaptchaException) {
        error_log('reCAPTCHA verification exception: ' . $recaptchaException->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'reCAPTCHA verification error. Please try again.'
        ]);
        exit;
    }
} else {
    // Require reCAPTCHA token for login
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'reCAPTCHA verification required. Please complete the reCAPTCHA and try again.'
    ]);
    exit;
}

$emailOrUsername = $data['emailOrUsername'];
$password = $data['password'];

$user = new User();
$userData = $user->findByEmailOrUsername($emailOrUsername);

if (!$userData) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email/username or password']);
    exit;
}

if (!Password::verify($password, $userData['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email/username or password']);
    exit;
}

// Generate token
$token = JWT::generate([
    'userId' => $userData['id'],
    'email' => $userData['email'],
    'username' => $userData['username'],
    'role' => $userData['role'],
    'subscriptionType' => $userData['subscription_type']
]);

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'data' => [
        'token' => $token,
        'user' => [
            'id' => $userData['id'],
            'email' => $userData['email'],
            'username' => $userData['username'],
            'role' => $userData['role'],
            'subscriptionType' => $userData['subscription_type'],
            'subscriptionStatus' => $userData['subscription_status']
        ]
    ]
]);

