<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/password.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['username']) || empty($data['password'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

// Verify reCAPTCHA
if (empty($data['recaptcha_token'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification is required']);
    exit;
}

$recaptchaResult = verifyRecaptcha($data['recaptcha_token'], 'admin_login');
if (!$recaptchaResult['success']) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'reCAPTCHA verification failed. Please try again.'
    ]);
    exit;
}

$username = trim($data['username']);
$password = $data['password'];

$user = new User();
$userData = $user->findByUsername($username);

if (!$userData) {
    // Use generic error message to prevent username enumeration
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

// Verify user is admin
if ($userData['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// Verify password
if (!Password::verify($password, $userData['password_hash'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
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

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Admin login successful',
    'data' => [
        'token' => $token,
        'user' => [
            'id' => $userData['id'],
            'username' => $userData['username'],
            'email' => $userData['email'],
            'role' => $userData['role']
        ]
    ]
]);

