<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/activity_logger.php';

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
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_ids']) || !is_array($data['user_ids']) || empty($data['updates'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User IDs array and updates object are required']);
            exit;
        }
        
        $userIds = array_map('intval', $data['user_ids']);
        $updates = $data['updates'];
        $conn = getDBConnection();
        
        $updated = 0;
        $allowedFields = ['subscription_type', 'subscription_status'];
        
        foreach ($userIds as $userId) {
            $updateFields = [];
            $params = [];
            $types = "";
            
            foreach ($updates as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updateFields[] = "{$field} = ?";
                    $params[] = $value;
                    $types .= "s";
                }
            }
            
            if (!empty($updateFields)) {
                $params[] = $userId;
                $types .= "i";
                
                $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    $updated++;
                }
            }
        }
        
        ActivityLogger::log(
            'user_bulk_update',
            "Bulk updated {$updated} users: " . json_encode($updates),
            $userData['userId']
        );
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully updated {$updated} users",
            'data' => ['updated_count' => $updated]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating users: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

