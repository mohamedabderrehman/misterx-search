<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/PaymentRequest.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Subscription.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

// Check if user is admin
$userData = authenticate();

if ($userData['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$paymentRequest = new PaymentRequest();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if export requested
    $export = isset($_GET['export']) && $_GET['export'] === 'csv';
    
    // Get query parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Handle export
    if ($export) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="payments_export_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'User', 'Email', 'Plan', 'Duration', 'Amount', 'Cryptocurrency', 'Status', 'Transaction Hash', 'Created At']);
        
        // Get all matching records for export (no pagination)
        $allRequests = $paymentRequest->getAll($status, 10000, 0, $search);
        
        foreach ($allRequests as $request) {
            fputcsv($output, [
                $request['id'],
                $request['username'] ?? 'N/A',
                $request['email'] ?? 'N/A',
                strtoupper($request['plan_type'] ?? ''),
                str_replace('_', ' ', $request['duration'] ?? ''),
                $request['amount'] ?? '0',
                $request['cryptocurrency'] ?? 'N/A',
                $request['status'] ?? 'pending',
                $request['transaction_hash'] ?? 'N/A',
                $request['created_at'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    $requests = $paymentRequest->getAll($status, $limit, $offset, $search);
    $total = $paymentRequest->getCount($status, $search);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / $limit)
        ]
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['requestId']) || empty($data['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Request ID and action are required']);
        exit;
    }
    
    $requestId = (int)$data['requestId'];
    $action = $data['action']; // 'approve' or 'reject'
    $adminNotes = isset($data['adminNotes']) ? $data['adminNotes'] : null;
    
    $request = $paymentRequest->findById($requestId);
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment request not found']);
        exit;
    }
    
    if ($action === 'approve') {
        // Update payment request status
        $paymentRequest->updateStatus($requestId, 'approved', $adminNotes);
        
        // Update user subscription
        $user = new User();
        $subscription = new Subscription();
        
        // Calculate expiration date
        $durationMap = [
            '1_week' => 7,
            '1_month' => 30,
            '3_months' => 90,
            '6_months' => 180,
            '12_months' => 365
        ];
        $days = $durationMap[$request['duration']] ?? 30;
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        // Update user subscription
        $user->updateSubscription(
            $request['user_id'],
            $request['plan_type'],
            'active',
            $expiresAt
        );
        
        // Update subscription record if exists
        if ($request['subscription_id']) {
            $subscription->updateStatus($request['subscription_id'], 'completed');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment approved and user subscription updated'
        ]);
        
    } elseif ($action === 'reject') {
        $paymentRequest->updateStatus($requestId, 'rejected', $adminNotes);
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment request rejected'
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

