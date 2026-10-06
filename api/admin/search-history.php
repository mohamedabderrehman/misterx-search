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
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $planType = isset($_GET['plan_type']) ? trim($_GET['plan_type']) : '';
        $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
        $sortField = isset($_GET['sort_field']) ? trim($_GET['sort_field']) : 'search_date';
        $sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $sql = "SELECT sh.*, u.username 
                FROM search_history sh 
                LEFT JOIN users u ON sh.user_id = u.id 
                WHERE 1=1";
        
        $countSql = "SELECT COUNT(*) as total 
                     FROM search_history sh 
                     LEFT JOIN users u ON sh.user_id = u.id 
                     WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($search) {
            $sql .= " AND (sh.search_query LIKE ? OR u.username LIKE ?)";
            $countSql .= " AND (sh.search_query LIKE ? OR u.username LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }
        
        if ($userId > 0) {
            $sql .= " AND sh.user_id = ?";
            $countSql .= " AND sh.user_id = ?";
            $params[] = $userId;
            $types .= "i";
        }
        
        if ($planType && in_array($planType, ['free', 'vip', 'api'])) {
            $sql .= " AND sh.plan_type_at_search = ?";
            $countSql .= " AND sh.plan_type_at_search = ?";
            $params[] = $planType;
            $types .= "s";
        }
        
        if ($dateFrom) {
            $sql .= " AND DATE(sh.search_date) >= ?";
            $countSql .= " AND DATE(sh.search_date) >= ?";
            $params[] = $dateFrom;
            $types .= "s";
        }
        
        if ($dateTo) {
            $sql .= " AND DATE(sh.search_date) <= ?";
            $countSql .= " AND DATE(sh.search_date) <= ?";
            $params[] = $dateTo;
            $types .= "s";
        }
        
        // Get total count
        $stmt = $conn->prepare($countSql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        
        // Validate sort field
        $allowedSortFields = ['id', 'username', 'search_query', 'results_count', 'plan_type_at_search', 'search_date'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'search_date';
        }
        
        // Get paginated results
        $orderBy = $sortField === 'username' ? 'u.username' : "sh.{$sortField}";
        $sql .= " ORDER BY {$orderBy} {$sortOrder} LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $searches = [];
        while ($row = $result->fetch_assoc()) {
            $searches[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'search_query' => $row['search_query'],
                'results_count' => $row['results_count'],
                'plan_type_at_search' => $row['plan_type_at_search'],
                'search_date' => $row['search_date']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'searches' => $searches,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching search history: ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete search history entry
    // Try to get ID from query parameter first
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    // If not in query, try to parse from request URI
    if (!$id) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uriParts = explode('/', trim($uri, '/'));
        $searchHistoryIndex = array_search('search-history', $uriParts);
        if ($searchHistoryIndex !== false && isset($uriParts[$searchHistoryIndex + 1])) {
            $id = (int)$uriParts[$searchHistoryIndex + 1];
        }
    }
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Search history ID is required']);
        exit;
    }
    
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM search_history WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            require_once __DIR__ . '/../utils/activity_logger.php';
            ActivityLogger::log('search_history_delete', "Deleted search history entry #{$id}", $userData['userId']);
            
            echo json_encode(['success' => true, 'message' => 'Search history deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete search history']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error deleting search history: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

