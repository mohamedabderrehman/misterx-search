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
        
        if (empty($data['user_ids']) || !is_array($data['user_ids'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User IDs array is required']);
            exit;
        }
        
        $userIds = array_map('intval', $data['user_ids']);
        
        // Prevent deleting yourself
        $userIds = array_filter($userIds, function($id) use ($userData) {
            return $id != $userData['userId'];
        });
        
        if (empty($userIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid user IDs to delete']);
            exit;
        }
        
        $conn = getDBConnection();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        
        $sql = "DELETE FROM users WHERE id IN ({$placeholders})";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$userIds);
        
        if ($stmt->execute()) {
            $deleted = $stmt->affected_rows;
            
            ActivityLogger::log(
                'user_bulk_delete',
                "Bulk deleted {$deleted} users (IDs: " . implode(', ', $userIds) . ")",
                $userData['userId']
            );
            
            echo json_encode([
                'success' => true,
                'message' => "Successfully deleted {$deleted} users",
                'data' => ['deleted_count' => $deleted]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete users']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error deleting users: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

