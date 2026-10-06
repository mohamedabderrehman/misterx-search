<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/password.php';

class User {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new user
     */
    public function create($email, $username, $password) {
        try {
            $passwordHash = Password::hash($password);
            
            $stmt = $this->conn->prepare(
                "INSERT INTO users (email, username, password_hash, subscription_type, role) 
                 VALUES (?, ?, ?, 'free', 'normal')"
            );
            
            if (!$stmt) {
                error_log('User::create() - Prepare failed: ' . $this->conn->error);
                return false;
            }
            
            $stmt->bind_param("sss", $email, $username, $passwordHash);
            
            if ($stmt->execute()) {
                return $this->conn->insert_id;
            } else {
                error_log('User::create() - Execute failed: ' . $stmt->error);
                return false;
            }
        } catch (Exception $e) {
            error_log('User::create() - Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Find user by username
     */
    public function findByUsername($username) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Find user by email or username
     */
    public function findByEmailOrUsername($emailOrUsername) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $emailOrUsername, $emailOrUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Find user by ID
     */
    public function findById($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, email, username, role, subscription_type, subscription_status, 
             subscription_expires_at, profile_picture, created_at, updated_at 
             FROM users WHERE id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get profile picture URL for user
     */
    public function getProfilePicture($userId) {
        $stmt = $this->conn->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ? $row['profile_picture'] : null;
    }
    
    /**
     * Update user profile
     */
    public function update($id, $email = null, $username = null) {
        $updates = [];
        $params = [];
        $types = "";
        
        if ($email !== null) {
            $updates[] = "email = ?";
            $params[] = $email;
            $types .= "s";
        }
        
        if ($username !== null) {
            $updates[] = "username = ?";
            $params[] = $username;
            $types .= "s";
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $id;
        $types .= "i";
        
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            return $this->findById($id);
        }
        
        return false;
    }
    
    /**
     * Update password
     */
    public function updatePassword($id, $password) {
        $passwordHash = Password::hash($password);
        
        $stmt = $this->conn->prepare(
            "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param("si", $passwordHash, $id);
        return $stmt->execute();
    }
    
    /**
     * Update subscription
     */
    public function updateSubscription($userId, $subscriptionType, $subscriptionStatus, $expiresAt) {
        $stmt = $this->conn->prepare(
            "UPDATE users SET subscription_type = ?, subscription_status = ?, 
             subscription_expires_at = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param("sssi", $subscriptionType, $subscriptionStatus, $expiresAt, $userId);
        return $stmt->execute();
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $excludeId);
        } else {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $username, $excludeId);
        } else {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
}

