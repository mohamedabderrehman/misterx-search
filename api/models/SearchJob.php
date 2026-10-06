<?php
require_once __DIR__ . '/../config.php';

class SearchJob {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new search job
     */
    public function create($userId, $query, $resultLimit, $subscriptionType) {
        $jobId = $this->generateJobId();
        
        $stmt = $this->conn->prepare(
            "INSERT INTO search_jobs (job_id, user_id, query, result_limit, subscription_type, status, created_at) 
             VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
        );
        $stmt->bind_param("sisis", $jobId, $userId, $query, $resultLimit, $subscriptionType);
        
        if ($stmt->execute()) {
            return [
                'jobId' => $jobId,
                'id' => $this->conn->insert_id
            ];
        }
        
        return false;
    }
    
    /**
     * Generate unique job ID
     */
    private function generateJobId() {
        return 'job_' . bin2hex(random_bytes(16));
    }
    
    /**
     * Get job by job ID
     */
    public function findByJobId($jobId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM search_jobs WHERE job_id = ?"
        );
        $stmt->bind_param("s", $jobId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Update job status
     */
    public function updateStatus($jobId, $status, $resultsData = null, $resultsCount = 0, $searchTime = null, $searchMethod = null, $errorMessage = null, $searchHistoryId = null) {
        $sql = "UPDATE search_jobs SET status = ?, updated_at = NOW()";
        $params = [$status];
        $types = "s";
        
        if ($status === 'processing') {
            $sql .= ", started_at = NOW()";
        } elseif ($status === 'completed' || $status === 'failed') {
            $sql .= ", completed_at = NOW()";
        }
        
        if ($resultsCount > 0) {
            $sql .= ", results_count = ?";
            $params[] = $resultsCount;
            $types .= "i";
        }
        
        if ($searchTime !== null) {
            $sql .= ", search_time = ?";
            $params[] = $searchTime;
            $types .= "d";
        }
        
        if ($searchMethod !== null) {
            $sql .= ", search_method = ?";
            $params[] = $searchMethod;
            $types .= "s";
        }
        
        if ($resultsData !== null) {
            $sql .= ", results_data = ?";
            $params[] = json_encode($resultsData);
            $types .= "s";
        }
        
        if ($errorMessage !== null) {
            $sql .= ", error_message = ?";
            $params[] = $errorMessage;
            $types .= "s";
        }
        
        if ($searchHistoryId !== null) {
            $sql .= ", search_history_id = ?";
            $params[] = $searchHistoryId;
            $types .= "i";
        }
        
        $sql .= " WHERE job_id = ?";
        $params[] = $jobId;
        $types .= "s";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        return $stmt->execute();
    }
    
    /**
     * Get jobs for a user
     */
    public function getUserJobs($userId, $limit = 10, $offset = 0) {
        $stmt = $this->conn->prepare(
            "SELECT job_id, query, status, results_count, search_time, created_at, completed_at 
             FROM search_jobs 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("iii", $userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }
        
        return $jobs;
    }
    
    /**
     * Get pending jobs for processing
     */
    public function getPendingJobs($limit = 10) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM search_jobs 
             WHERE status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }
        
        return $jobs;
    }
    
    /**
     * Clean up old completed/failed jobs (older than 7 days)
     */
    public function cleanupOldJobs($days = 7) {
        $stmt = $this->conn->prepare(
            "DELETE FROM search_jobs 
             WHERE status IN ('completed', 'failed', 'cancelled') 
             AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->bind_param("i", $days);
        return $stmt->execute();
    }
}

