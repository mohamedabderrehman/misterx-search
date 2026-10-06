<?php
require_once __DIR__ . '/../config.php';

class Search {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new search record
     */
    public function create($userId, $searchQuery, $resultsCount, $planType) {
        $stmt = $this->conn->prepare(
            "INSERT INTO search_history (user_id, search_query, results_count, plan_type_at_search, search_date) 
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("isis", $userId, $searchQuery, $resultsCount, $planType);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get search history for a user
     */
    public function getHistory($userId, $limit = 50, $offset = 0) {
        $stmt = $this->conn->prepare(
            "SELECT id, search_query, results_count, plan_type_at_search, search_date 
             FROM search_history 
             WHERE user_id = ? 
             ORDER BY search_date DESC 
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("iii", $userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        return $history;
    }
    
    /**
     * Get search by ID
     */
    public function findById($searchId, $userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM search_history WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $searchId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get search count for user today
     */
    public function getTodayCount($userId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM search_history 
             WHERE user_id = ? AND DATE(search_date) = DATE(NOW())"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['count'];
    }
    
    /**
     * Get search count for user in last hour
     */
    public function getHourlyCount($userId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM search_history 
             WHERE user_id = ? AND search_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['count'];
    }
    
    /**
     * Get concurrent search count for user (searches started in last 30 seconds)
     */
    public function getConcurrentCount($userId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM search_history 
             WHERE user_id = ? AND search_date >= DATE_SUB(NOW(), INTERVAL 30 SECOND)"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['count'];
    }
}

