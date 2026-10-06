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
        
        // Check if export requested
        $export = isset($_GET['export']) && $_GET['export'] === 'csv';
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $actionType = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
        $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
        
        $sql = "SELECT al.*, u.username 
                FROM activity_logs al 
                LEFT JOIN users u ON al.user_id = u.id 
                WHERE 1=1";
        
        $countSql = "SELECT COUNT(*) as total 
                     FROM activity_logs al 
                     LEFT JOIN users u ON al.user_id = u.id 
                     WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($search) {
            $sql .= " AND (al.description LIKE ? OR u.username LIKE ? OR al.ip_address LIKE ?)";
            $countSql .= " AND (al.description LIKE ? OR u.username LIKE ? OR al.ip_address LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "sss";
        }
        
        if ($actionType) {
            $sql .= " AND al.action_type = ?";
            $countSql .= " AND al.action_type = ?";
            $params[] = $actionType;
            $types .= "s";
        }
        
        if ($dateFrom) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $countSql .= " AND DATE(al.created_at) >= ?";
            $params[] = $dateFrom;
            $types .= "s";
        }
        
        if ($dateTo) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $countSql .= " AND DATE(al.created_at) <= ?";
            $params[] = $dateTo;
            $types .= "s";
        }
        
        // Handle export
        if ($export) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="activity_logs_export_' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF";
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Action Type', 'Description', 'User', 'IP Address', 'Created At']);
            
            $exportSql = $sql . " ORDER BY al.created_at DESC LIMIT 10000";
            $stmt = $conn->prepare($exportSql);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['id'],
                    $row['action_type'],
                    $row['description'],
                    $row['username'] ?? 'N/A',
                    $row['ip_address'] ?? 'N/A',
                    $row['created_at']
                ]);
            }
            
            fclose($output);
            exit;
        }
        
        // Get total count
        $stmt = $conn->prepare($countSql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        
        // Get paginated results
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = [
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
            'data' => [
                'logs' => $logs,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching activity logs: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

