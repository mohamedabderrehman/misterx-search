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
    require_once __DIR__ . '/../models/ApiKey.php';
    require_once __DIR__ . '/../utils/auth.php';
} catch (Exception $e) {
    error_log('API Keys - File include error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error. Please try again later.'
    ]);
    exit;
} catch (Error $e) {
    error_log('API Keys - Fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

try {
    $userData = authenticate();
    $userId = $userData['userId'];
    $apiKey = new ApiKey();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $keys = $apiKey->getByUserId($userId);
        
        echo json_encode([
            'success' => true,
            'data' => ['keys' => $keys]
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        $keyId = isset($data['keyId']) ? (int)$data['keyId'] : 0;
        
        if (!$keyId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Key ID is required']);
            exit;
        }
        
        if ($apiKey->delete($userId, $keyId)) {
            echo json_encode([
                'success' => true,
                'message' => 'API key deleted successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete API key']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    $statusCode = $e->getCode() && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    error_log('API Keys error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

