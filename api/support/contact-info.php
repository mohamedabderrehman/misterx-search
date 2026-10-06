<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (function_exists('setCORSHeaders')) {
    setCORSHeaders();
} else {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    error_log('Support contact info - Config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare(
            "SELECT setting_key, setting_value FROM support_settings 
             WHERE setting_key IN ('support_email', 'support_telegram')"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'email' => $settings['support_email'] ?? 'support@misterx.com',
                'telegram' => $settings['support_telegram'] ?? '@NEISupport'
            ]
        ]);
    } catch (Exception $e) {
        error_log('Support contact info - Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to load contact info']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Admin only - update contact info
    try {
        require_once __DIR__ . '/../utils/auth.php';
        $userData = authenticate();
        
        if ($userData['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['email']) || empty($data['telegram'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email and Telegram are required']);
            exit;
        }
        
        $conn = getDBConnection();
        
        // Update email
        $stmt = $conn->prepare(
            "INSERT INTO support_settings (setting_key, setting_value) 
             VALUES ('support_email', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()"
        );
        $stmt->bind_param("ss", $data['email'], $data['email']);
        $stmt->execute();
        
        // Update telegram
        $stmt = $conn->prepare(
            "INSERT INTO support_settings (setting_key, setting_value) 
             VALUES ('support_telegram', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()"
        );
        $stmt->bind_param("ss", $data['telegram'], $data['telegram']);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Contact info updated successfully',
            'data' => [
                'email' => $data['email'],
                'telegram' => $data['telegram']
            ]
        ]);
    } catch (Exception $authError) {
        $statusCode = $authError->getCode() && $authError->getCode() >= 400 && $authError->getCode() < 600 ? $authError->getCode() : 401;
        http_response_code($statusCode);
        echo json_encode(['success' => false, 'message' => $authError->getMessage()]);
    } catch (Exception $e) {
        error_log('Support contact info update - Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update contact info']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

