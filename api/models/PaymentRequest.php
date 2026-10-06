<?php
require_once __DIR__ . '/../config.php';

class PaymentRequest {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new payment request
     */
    public function create($data) {
        $stmt = $this->conn->prepare(
            "INSERT INTO payment_requests 
             (user_id, subscription_id, plan_type, duration, amount, cryptocurrency, 
              wallet_address, amount_to_send, payment_address, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        
        if (!$stmt) {
            error_log("PaymentRequest::create - Prepare failed: " . $this->conn->error);
            return false;
        }
        
        // Convert amount_to_send to string for DECIMAL type
        $amountToSend = (string)$data['amount_to_send'];
        
        $stmt->bind_param(
            "iissdssss",
            $data['user_id'],
            $data['subscription_id'],
            $data['plan_type'],
            $data['duration'],
            $data['amount'],
            $data['cryptocurrency'],
            $data['wallet_address'],
            $amountToSend,
            $data['payment_address']
        );
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        } else {
            error_log("PaymentRequest::create - Execute failed: " . $stmt->error);
            return false;
        }
    }
    
    /**
     * Get payment request by ID
     */
    public function findById($id) {
        $stmt = $this->conn->prepare(
            "SELECT pr.*, u.username, u.email 
             FROM payment_requests pr
             JOIN users u ON pr.user_id = u.id
             WHERE pr.id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get all payment requests (for admin) with pagination and filters
     */
    public function getAll($status = null, $limit = 50, $offset = 0, $search = '') {
        $sql = "SELECT pr.*, u.username, u.email 
                FROM payment_requests pr
                JOIN users u ON pr.user_id = u.id
                WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($status) {
            $sql .= " AND pr.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        if ($search) {
            $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR pr.transaction_hash LIKE ? OR pr.cryptocurrency LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ssss";
        }
        
        $sql .= " ORDER BY pr.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $this->conn->prepare($sql);
        
        if ($types && count($params) > 0) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        
        return $requests;
    }
    
    /**
     * Get total count of payment requests with filters
     */
    public function getCount($status = null, $search = '') {
        $sql = "SELECT COUNT(*) as total 
                FROM payment_requests pr
                JOIN users u ON pr.user_id = u.id
                WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($status) {
            $sql .= " AND pr.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        if ($search) {
            $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR pr.transaction_hash LIKE ? OR pr.cryptocurrency LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ssss";
        }
        
        $stmt = $this->conn->prepare($sql);
        if ($types && count($params) > 0) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['total'];
    }
    
    /**
     * Get payment requests for a user
     */
    public function getByUserId($userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM payment_requests 
             WHERE user_id = ? 
             ORDER BY created_at DESC"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        
        return $requests;
    }
    
    /**
     * Update payment request status
     */
    public function updateStatus($id, $status, $adminNotes = null) {
        $stmt = $this->conn->prepare(
            "UPDATE payment_requests 
             SET status = ?, admin_notes = ?, updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param("ssi", $status, $adminNotes, $id);
        return $stmt->execute();
    }
    
    /**
     * Update transaction hash
     */
    public function updateTransactionHash($id, $transactionHash) {
        $stmt = $this->conn->prepare(
            "UPDATE payment_requests 
             SET transaction_hash = ?, status = 'waiting', updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param("si", $transactionHash, $id);
        return $stmt->execute();
    }
}

