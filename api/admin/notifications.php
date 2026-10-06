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
        
        // Get pending payments count
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM payment_requests WHERE status = 'pending'");
        $stmt->execute();
        $pendingPayments = $stmt->get_result()->fetch_assoc()['count'];
        
        // Get new users in last 24 hours
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $newUsers24h = $stmt->get_result()->fetch_assoc()['count'];
        
        $notifications = [];
        
        if ($pendingPayments > 0) {
            $notifications[] = [
                'id' => 'pending_payments',
                'type' => 'payment',
                'message' => "You have {$pendingPayments} pending payment(s) awaiting approval",
                'count' => (int)$pendingPayments,
                'priority' => 'high'
            ];
        }
        
        if ($newUsers24h > 0) {
            $notifications[] = [
                'id' => 'new_users',
                'type' => 'user',
                'message' => "{$newUsers24h} new user(s) registered in the last 24 hours",
                'count' => (int)$newUsers24h,
                'priority' => 'medium'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['notifications' => $notifications]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching notifications: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

