<?php
/**
 * CSRF Protection Utility
 * Generates and validates CSRF tokens
 */

class CSRF {
    /**
     * Start session if not started
     */
    private static function ensureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true
            ]);
        }
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateToken() {
        self::ensureSession();
        
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        
        // Regenerate token every 30 minutes for security
        if (time() - $_SESSION['csrf_token_time'] > 1800) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Get current CSRF token
     */
    public static function getToken() {
        self::ensureSession();
        return $_SESSION['csrf_token'] ?? self::generateToken();
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateToken($token) {
        self::ensureSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        // Token expires after 2 hours
        if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 7200) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Require CSRF token for POST/PUT/DELETE requests
     * Returns true if valid, exits with error if invalid
     */
    public static function requireToken() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Only require CSRF for state-changing methods
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return true;
        }
        
        // Get token from header or body
        $token = null;
        
        // Try header first
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        // Try POST data
        elseif (isset($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        }
        // Try JSON body
        else {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            if (isset($data['csrf_token'])) {
                $token = $data['csrf_token'];
            }
        }
        
        if (!$token || !self::validateToken($token)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or missing CSRF token'
            ]);
            exit;
        }
        
        return true;
    }
}

