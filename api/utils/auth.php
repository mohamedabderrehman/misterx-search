<?php
require_once __DIR__ . '/jwt.php';

function getAuthToken() {
    // Try multiple sources because some servers strip Authorization header
    $authHeader = null;

    // 1) getallheaders (if available)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
        }
    }

    // 2) $_SERVER fallbacks used by many hosts (Apache/Nginx/FastCGI)
    if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!$authHeader && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!$authHeader && isset($_SERVER['Authorization'])) {
        $authHeader = $_SERVER['Authorization'];
    }

    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }

    return null;
}

function authenticate() {
    $token = getAuthToken();
    
    if (!$token) {
        // Log for debugging (only in debug mode)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('Authenticate failed: No token provided');
        }
        throw new Exception('No token provided', 401);
    }
    
    $decoded = JWT::verify($token);
    
    if (!$decoded) {
        // Log for debugging (only in debug mode)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('Authenticate failed: Token verification failed. Token length: ' . strlen($token));
        }
        throw new Exception('Invalid or expired token', 401);
    }
    
    return $decoded;
}

