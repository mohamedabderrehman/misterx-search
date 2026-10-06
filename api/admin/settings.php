<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';

setCORSHeaders();
header('Content-Type: application/json');

// Check if user is admin
$userData = authenticate();

if ($userData['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['settings' => $settings]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching settings: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $conn = getDBConnection();
        
        $allowedSettings = [
            'site_name', 'site_description', 'site_logo', 'recaptcha_site_key', 'recaptcha_secret_key',
            'free_rate_limit', 'vip_rate_limit', 'api_rate_limit', 
            'maintenance_mode', 'maintenance_message'
        ];
        
        $updated = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedSettings)) {
                $stmt = $conn->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $updated[] = $key;
            }
        }
        
        require_once __DIR__ . '/../utils/activity_logger.php';
        ActivityLogger::log('settings_update', "Updated settings: " . implode(', ', $updated), $userData['userId']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => ['updated' => $updated]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating settings: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

