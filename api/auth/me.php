<?php
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../models/User.php';
    require_once __DIR__ . '/../utils/auth.php';
} catch (Exception $e) {
    if (function_exists('setCORSHeaders')) {
        setCORSHeaders();
    } else {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }
    header('Content-Type: application/json; charset=UTF-8');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error: ' . $e->getMessage()
    ]);
    exit;
}

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $userData = authenticate();
    $user = new User();
    $userInfo = $user->findById($userData['userId']);

    if (!$userInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => ['user' => $userInfo]
    ]);
} catch (Exception $e) {
    $statusCode = $e->getCode() && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    error_log('Error in me.php: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ')');
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

