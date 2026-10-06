<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/auth.php';

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    // Check if user is admin
    $userData = authenticate();
    
    if ($userData['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
} catch (Exception $e) {
    $statusCode = $e->getCode() && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conn = getDBConnection();
    
    // Check if export requested
    $export = isset($_GET['export']) && $_GET['export'] === 'csv';
    
    // Get query parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $subscriptionFilter = isset($_GET['subscription']) ? trim($_GET['subscription']) : '';
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $sortField = isset($_GET['sort_field']) ? trim($_GET['sort_field']) : 'created_at';
    $sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
    
    // Validate sort field
    $allowedSortFields = ['id', 'email', 'username', 'subscription_type', 'subscription_status', 'subscription_expires_at', 'created_at'];
    if (!in_array($sortField, $allowedSortFields)) {
        $sortField = 'created_at';
    }
    
    $sql = "SELECT id, email, username, role, subscription_type, subscription_status, 
                   subscription_expires_at, created_at, updated_at 
            FROM users WHERE 1=1";
    
    $countSql = "SELECT COUNT(*) as total FROM users WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if ($search) {
        $sql .= " AND (email LIKE ? OR username LIKE ?)";
        $countSql .= " AND (email LIKE ? OR username LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "ss";
    }
    
    if ($subscriptionFilter && in_array($subscriptionFilter, ['free', 'vip', 'api'])) {
        $sql .= " AND subscription_type = ?";
        $countSql .= " AND subscription_type = ?";
        $params[] = $subscriptionFilter;
        $types .= "s";
    }
    
    if ($statusFilter && in_array($statusFilter, ['active', 'expired', 'cancelled'])) {
        $sql .= " AND subscription_status = ?";
        $countSql .= " AND subscription_status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }
    
    if ($dateFrom) {
        $sql .= " AND DATE(created_at) >= ?";
        $countSql .= " AND DATE(created_at) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(created_at) <= ?";
        $countSql .= " AND DATE(created_at) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }
    
    // Handle export
    if ($export) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Email', 'Username', 'Role', 'Subscription Type', 'Subscription Status', 'Expires At', 'Created At']);
        
        // Remove LIMIT for export, keep existing ORDER BY
        $exportSql = preg_replace('/\s*LIMIT\s+\?\s+OFFSET\s+\?/i', '', $sql);
        if (!preg_match('/ORDER\s+BY/i', $exportSql)) {
            $exportSql .= " ORDER BY created_at DESC";
        }
        
        $exportParams = array_slice($params, 0, -2); // Remove limit and offset params
        $exportTypes = preg_replace('/ii$/', '', $types); // Remove ii types
        
        $stmt = $conn->prepare($exportSql);
        if ($exportTypes && count($exportParams) > 0) {
            $stmt->bind_param($exportTypes, ...$exportParams);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['email'],
                $row['username'],
                $row['role'],
                $row['subscription_type'],
                $row['subscription_status'],
                $row['subscription_expires_at'] ?? 'N/A',
                $row['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    $sql .= " ORDER BY {$sortField} {$sortOrder} LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    if ($types && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // Get total count
    $countParams = array_slice($params, 0, -2); // Remove limit and offset
    $countTypes = preg_replace('/ii$/', '', $types); // Remove ii types
    
    $countStmt = $conn->prepare($countSql);
    if ($countTypes && count($countParams) > 0) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $users,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / $limit)
        ]
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = isset($data['userId']) ? (int)$data['userId'] : 0;
    $action = isset($data['action']) ? $data['action'] : 'update';
    
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit;
    }
    
    $user = new User();
    $userInfo = $user->findById($userId);
    
    if (!$userInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    if ($action === 'update') {
        // Update user info (email, username)
        $email = isset($data['email']) ? filter_var($data['email'], FILTER_SANITIZE_EMAIL) : null;
        $username = isset($data['username']) ? trim($data['username']) : null;
        
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit;
        }
        
        if ($username && (strlen($username) < 3 || strlen($username) > 30)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username must be between 3 and 30 characters']);
            exit;
        }
        
        // Check if email/username already exists (excluding current user)
        if ($email && $user->emailExists($email, $userId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
        
        if ($username && $user->usernameExists($username, $userId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
        
        $updatedUser = $user->update($userId, $email, $username);
        
        if ($updatedUser) {
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => ['user' => $updatedUser]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
        }
        
    } elseif ($action === 'password') {
        // Update password
        $newPassword = isset($data['password']) ? $data['password'] : '';
        
        if (strlen($newPassword) < 12) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 12 characters long']);
            exit;
        }
        
        // Check password strength
        if (!preg_match('/[a-z]/', $newPassword) || 
            !preg_match('/[A-Z]/', $newPassword) || 
            !preg_match('/[0-9]/', $newPassword) || 
            !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Password must contain at least one lowercase, uppercase, number, and special character'
            ]);
            exit;
        }
        
        $success = $user->updatePassword($userId, $newPassword);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update password']);
        }
        
    } elseif ($action === 'subscription') {
        // Update subscription
        $subscriptionType = isset($data['subscription_type']) ? $data['subscription_type'] : null;
        $subscriptionStatus = isset($data['subscription_status']) ? $data['subscription_status'] : null;
        $expiresAt = isset($data['subscription_expires_at']) ? $data['subscription_expires_at'] : null;
        
        if (!in_array($subscriptionType, ['free', 'vip', 'api'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid subscription type']);
            exit;
        }
        
        if (!in_array($subscriptionStatus, ['active', 'expired', 'cancelled'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid subscription status']);
            exit;
        }
        
        // If expiresAt is not provided and subscription is active, set default expiration
        if ($expiresAt === null && $subscriptionStatus === 'active' && $subscriptionType !== 'free') {
            // Default to 30 days from now
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        } elseif ($subscriptionType === 'free') {
            $expiresAt = null;
        }
        
        $success = $user->updateSubscription($userId, $subscriptionType, $subscriptionStatus, $expiresAt);
        
        if ($success) {
            $updatedUser = $user->findById($userId);
            echo json_encode([
                'success' => true,
                'message' => 'Subscription updated successfully',
                'data' => ['user' => $updatedUser]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update subscription']);
        }
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

