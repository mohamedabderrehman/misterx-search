<?php
require_once __DIR__ . '/../config.php';

class ApiKey {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Generate a new API key
     */
    public static function generate() {
        return 'mx_' . bin2hex(random_bytes(32));
    }
    
    /**
     * Create a new API key for user
     */
    public function create($userId) {
        $apiKey = self::generate();
        
        $stmt = $this->conn->prepare(
            "INSERT INTO api_keys (user_id, api_key, rate_limit, requests_count, last_reset) 
             VALUES (?, ?, 1000, 0, NOW())"
        );
        $stmt->bind_param("is", $userId, $apiKey);
        
        if ($stmt->execute()) {
            return [
                'id' => $this->conn->insert_id,
                'api_key' => $apiKey
            ];
        }
        
        return false;
    }
    
    /**
     * Find API key by key string
     */
    public function findByKey($apiKey) {
        $stmt = $this->conn->prepare("SELECT * FROM api_keys WHERE api_key = ?");
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get all API keys for user
     */
    public function getByUserId($userId) {
        $stmt = $this->conn->prepare(
            "SELECT id, api_key, rate_limit, requests_count, last_reset, created_at 
             FROM api_keys WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }
        
        return $keys;
    }
    
    /**
     * Delete API key
     */
    public function delete($userId, $keyId) {
        $stmt = $this->conn->prepare(
            "DELETE FROM api_keys WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $keyId, $userId);
        return $stmt->execute();
    }
}

