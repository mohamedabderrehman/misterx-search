<?php
require_once __DIR__ . '/../config.php';

class Ticket {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new ticket
     */
    public function create($userId, $subject, $message, $priority = 'medium') {
        $stmt = $this->conn->prepare(
            "INSERT INTO tickets (user_id, subject, message, priority, status) 
             VALUES (?, ?, ?, ?, 'open')"
        );
        $stmt->bind_param("isss", $userId, $subject, $message, $priority);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get ticket by ID
     */
    public function findById($id) {
        $stmt = $this->conn->prepare(
            "SELECT t.*, u.username, u.email 
             FROM tickets t
             JOIN users u ON t.user_id = u.id
             WHERE t.id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get all tickets for a user
     */
    public function getByUserId($userId) {
        $stmt = $this->conn->prepare(
            "SELECT t.*, 
             (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as reply_count,
             (SELECT MAX(created_at) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as last_reply_at
             FROM tickets t
             WHERE t.user_id = ?
             ORDER BY t.created_at DESC"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        
        return $tickets;
    }
    
    /**
     * Get all tickets (for admin)
     */
    public function getAll($filters = []) {
        $query = "SELECT t.*, u.username, u.email,
                  (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as reply_count
                  FROM tickets t
                  JOIN users u ON t.user_id = u.id
                  WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if (!empty($filters['status'])) {
            $query .= " AND t.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }
        
        if (!empty($filters['priority'])) {
            $query .= " AND t.priority = ?";
            $params[] = $filters['priority'];
            $types .= "s";
        }
        
        $query .= " ORDER BY t.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $query .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            $types .= "i";
        }
        
        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        
        return $tickets;
    }
    
    /**
     * Update ticket status
     */
    public function updateStatus($id, $status) {
        $stmt = $this->conn->prepare(
            "UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }
    
    /**
     * Update ticket priority
     */
    public function updatePriority($id, $priority) {
        $stmt = $this->conn->prepare(
            "UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param("si", $priority, $id);
        return $stmt->execute();
    }
    
    /**
     * Get ticket replies
     */
    public function getReplies($ticketId) {
        $stmt = $this->conn->prepare(
            "SELECT tr.*, u.username, u.email 
             FROM ticket_replies tr
             LEFT JOIN users u ON tr.user_id = u.id
             WHERE tr.ticket_id = ?
             ORDER BY tr.created_at ASC"
        );
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $replies = [];
        while ($row = $result->fetch_assoc()) {
            $replies[] = $row;
        }
        
        return $replies;
    }
    
    /**
     * Add reply to ticket
     */
    public function addReply($ticketId, $userId, $message, $isAdmin = false) {
        // Validate inputs
        $ticketId = (int)$ticketId;
        $userId = (int)$userId;
        $isAdminInt = $isAdmin ? 1 : 0;
        
        if ($ticketId <= 0 || $userId <= 0 || empty(trim($message))) {
            error_log('Ticket::addReply - Invalid parameters: ticketId=' . $ticketId . ', userId=' . $userId);
            return false;
        }
        
        $stmt = $this->conn->prepare(
            "INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) 
             VALUES (?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            error_log('Ticket::addReply - Prepare failed: ' . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("iisi", $ticketId, $userId, $message, $isAdminInt);
        
        if (!$stmt->execute()) {
            error_log('Ticket::addReply - Execute failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $replyId = $this->conn->insert_id;
        $stmt->close();
        
        // Update ticket status if admin replies
        if ($isAdmin) {
            $updateStmt = $this->conn->prepare(
                "UPDATE tickets SET status = 'in_progress', updated_at = NOW() 
                 WHERE id = ? AND status = 'open'"
            );
            if ($updateStmt) {
                $updateStmt->bind_param("i", $ticketId);
                if (!$updateStmt->execute()) {
                    error_log('Ticket::addReply - Failed to update ticket status: ' . $updateStmt->error);
                }
                $updateStmt->close();
            } else {
                error_log('Ticket::addReply - Failed to prepare status update: ' . $this->conn->error);
            }
        } else {
            // If user replies, set status back to open if it was resolved/closed
            $updateStmt = $this->conn->prepare(
                "UPDATE tickets SET status = 'open', updated_at = NOW() 
                 WHERE id = ? AND status IN ('resolved', 'closed')"
            );
            if ($updateStmt) {
                $updateStmt->bind_param("i", $ticketId);
                if (!$updateStmt->execute()) {
                    error_log('Ticket::addReply - Failed to update ticket status: ' . $updateStmt->error);
                }
                $updateStmt->close();
            } else {
                error_log('Ticket::addReply - Failed to prepare status update: ' . $this->conn->error);
            }
        }
        
        return $replyId;
    }
}

