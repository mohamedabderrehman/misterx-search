<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../utils/auth.php';

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
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
        
        // Get recent activity logs
        $stmt = $conn->prepare("
            SELECT al.*, u.username 
            FROM activity_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $activities = [];
        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'id' => $row['id'],
                'action_type' => $row['action_type'],
                'description' => $row['description'],
                'username' => $row['username'],
                'ip_address' => $row['ip_address'],
                'created_at' => $row['created_at']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['activities' => $activities]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching activity: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

