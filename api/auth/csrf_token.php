<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Generate and return CSRF token
$token = CSRF::generateToken();

echo json_encode([
    'success' => true,
    'data' => [
        'csrf_token' => $token
    ]
]);

