<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/activity_logger.php';

setCORSHeaders();
header('Content-Type: application/json');

// Check if user is admin
$userData = authenticate();

if ($userData['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create API key for a user
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }
        
        $userId = (int)$data['user_id'];
        $conn = getDBConnection();
        
        // Generate API key
        $apiKey = 'mx_' . bin2hex(random_bytes(32));
        
        $stmt = $conn->prepare("
            INSERT INTO api_keys (user_id, api_key, rate_limit) 
            VALUES (?, ?, 1000)
        ");
        $stmt->bind_param("is", $userId, $apiKey);
        
        if ($stmt->execute()) {
            ActivityLogger::log(
                'api_key_create',
                "Created API key for user ID {$userId}",
                $userData['userId']
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'API key created successfully',
                'data' => ['api_key' => $apiKey, 'id' => $conn->insert_id]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create API key']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error creating API key: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

