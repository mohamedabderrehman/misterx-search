<?php
// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DEMO_MODE', getenv('DEMO_MODE') === '1');
define('DB_USER', getenv('DB_USER') ?: 'demo');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'portfolio_demo');

// JWT Configuration
// IMPORTANT: Generate a new secret using: bin2hex(random_bytes(32))
// Store this in environment variable or secure config file
// FIXED: Use a constant secret instead of generating random each time
// You should set this via environment variable in production: JWT_SECRET
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');
if (strlen(JWT_SECRET)<32 && PHP_SAPI!=='cli') {http_response_code(503);exit('Configure JWT_SECRET (32+ characters).');}
define('JWT_EXPIRES_IN', 2592000); // 30 days in seconds

// Google reCAPTCHA Configuration
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '');
define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');

// CORS Configuration
// Set to your actual domain in production, or use environment variable
// For development, you can use '*' but it's insecure for production
$allowedOrigins = [
    'https://yourdomain.com',
    'https://www.yourdomain.com',
    'http://localhost:3000', // For local development only - REMOVE IN PRODUCTION
];

// Get CORS origin from environment or validate against whitelist
$envCors = getenv('CORS_ORIGIN');
if ($envCors) {
    $corsOrigin = $envCors;
} else {
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($requestOrigin && in_array($requestOrigin, $allowedOrigins)) {
        $corsOrigin = $requestOrigin;
    } else {
        // Fallback: use first allowed origin or '*' for development
        $corsOrigin = $allowedOrigins[0] ?? '*';
    }
}
define('CORS_ORIGIN', $corsOrigin);

// Rate Limits
define('RATE_LIMIT_FREE_DAILY', 3);
define('RATE_LIMIT_VIP_HOURLY', 100); // Reduced from 1000 for better security
define('RATE_LIMIT_API_HOURLY', 200); // Reduced from 1000 for better security

// Leaked Data Database Path
// IMPORTANT: Update this path to match your actual server path
// If project is in root directory: /var/www/vhosts/yourdomain.com/httpdocs/data
// If project is in subdirectory: /var/www/vhosts/yourdomain.com/httpdocs/misterx/data
// Use symlink path if open_basedir restriction exists
// Example symlink: /var/www/vhosts/yourdomain.com/httpdocs/data -> /root/logs
// Update this path according to your server setup
define('LEAKED_DATA_PATH', getenv('LEAKED_DATA_PATH') ?: '');

// OpenSearch Configuration
define('OPENSEARCH_HOST', getenv('OPENSEARCH_HOST') ?: '127.0.0.1');
define('OPENSEARCH_PORT', getenv('OPENSEARCH_PORT') ?: '9200');
define('OPENSEARCH_INDEX', getenv('OPENSEARCH_INDEX') ?: 'portfolio_records');
define('OPENSEARCH_ENABLED', true); // OpenSearch is enabled and ready for searches

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (disable in production)
// In production, set to 0 to disable error reporting and log errors to file
define('DEBUG_MODE', getenv('DEBUG_MODE') === 'true' || false);
error_reporting(DEBUG_MODE ? E_ALL : 0); // 0 disables error reporting
ini_set('display_errors', 0); // Never display errors to users
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Database Connection
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            
            $conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed'
            ]);
            exit;
        }
    }
    
    return $conn;
}

// CORS Headers Function
function setCORSHeaders() {
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Allow specific origins or use wildcard for development
    $allowedOrigins = [
        'https://mrx1.anondns.net',
        'http://mrx1.anondns.net',
        'https://yourdomain.com',
        'http://localhost:3000'
    ];
    
    // Get origin from environment or use request origin if allowed
    $envCors = getenv('CORS_ORIGIN');
    if ($envCors) {
        $origin = $envCors;
    } elseif ($requestOrigin && in_array($requestOrigin, $allowedOrigins)) {
        $origin = $requestOrigin;
    } elseif (defined('CORS_ORIGIN')) {
        $origin = CORS_ORIGIN;
    } else {
        $origin = '*'; // Fallback for development - change in production
    }
    
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

// Security Headers
function setSecurityHeaders() {
    // Only set headers if running via web server (not CLI)
    if (php_sapi_name() === 'cli') {
        return;
    }
    
    // CORS headers with origin validation
    $origin = CORS_ORIGIN;
    if ($origin !== '*') {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Credentials: true");
    } else {
        header("Access-Control-Allow-Origin: *");
    }
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
    header("Access-Control-Max-Age: 86400"); // 24 hours
    
    // Security headers
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    
    // Content-Type
    header("Content-Type: application/json; charset=UTF-8");
    
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Note: Don't call setSecurityHeaders() or setCORSHeaders() automatically
// Each endpoint should call setCORSHeaders() explicitly before sending any output

// reCAPTCHA Verification Function
function verifyRecaptcha($token, $action = null) {
    if (empty($token)) {
        return ['success' => false, 'message' => 'reCAPTCHA token is required'];
    }
    
    $data = [
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents(RECAPTCHA_VERIFY_URL, false, $context);
    
    if ($result === false) {
        return ['success' => false, 'message' => 'Failed to verify reCAPTCHA - network error'];
    }
    
    $response = json_decode($result, true);
    
    if (!$response || !isset($response['success'])) {
        return ['success' => false, 'message' => 'Invalid reCAPTCHA response from server'];
    }
    
    if (!$response['success']) {
        $errorCodes = $response['error-codes'] ?? [];
        $errorMessage = 'reCAPTCHA verification failed';
        if (!empty($errorCodes)) {
            $errorMessage .= ': ' . implode(', ', $errorCodes);
        }
        return ['success' => false, 'message' => $errorMessage];
    }
    
    // Verify action if provided (for reCAPTCHA v3 only)
    if ($action !== null && isset($response['action']) && $response['action'] !== $action) {
        return ['success' => false, 'message' => 'reCAPTCHA action mismatch'];
    }
    
    // Check score for v3 (recommended threshold: 0.5) - v2 doesn't have score
    if (isset($response['score']) && $response['score'] < 0.5) {
        return ['success' => false, 'message' => 'reCAPTCHA score too low'];
    }
    
    // For v2 Checkbox, if success is true, verification passed
    return ['success' => true, 'score' => $response['score'] ?? null, 'challenge_ts' => $response['challenge_ts'] ?? null];
}

