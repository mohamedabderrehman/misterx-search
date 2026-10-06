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
        
        // Total users
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
        $stmt->execute();
        $totalUsers = $stmt->get_result()->fetch_assoc()['total'];
        
        // VIP users
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE subscription_type = 'vip' AND subscription_status = 'active'");
        $stmt->execute();
        $vipUsers = $stmt->get_result()->fetch_assoc()['total'];
        
        // API users
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE subscription_type = 'api' AND subscription_status = 'active'");
        $stmt->execute();
        $apiUsers = $stmt->get_result()->fetch_assoc()['total'];
        
        // Pending payments
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM payment_requests WHERE status = 'pending'");
        $stmt->execute();
        $pendingPayments = $stmt->get_result()->fetch_assoc()['total'];
        
        // Total revenue (sum of approved payments)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_to_send), 0) as total FROM payment_requests WHERE status = 'approved'");
        $stmt->execute();
        $totalRevenue = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        
        // New users in last 7 days
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $newUsers7d = $stmt->get_result()->fetch_assoc()['total'];
        
        // Users change (users created in last 7 days vs previous 7 days)
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $previousWeekUsers = $stmt->get_result()->fetch_assoc()['total'];
        $usersChange7d = $newUsers7d - $previousWeekUsers;
        
        echo json_encode([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_users' => (int)$totalUsers,
                    'vip_users' => (int)$vipUsers,
                    'api_users' => (int)$apiUsers,
                    'pending_payments' => (int)$pendingPayments,
                    'total_revenue' => (float)$totalRevenue,
                    'new_users_7d' => (int)$newUsers7d,
                    'users_change_7d' => (int)$usersChange7d
                ]
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching stats: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

