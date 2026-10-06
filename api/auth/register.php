<?php
// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users, but log them
ini_set('log_errors', 1);

// Catch any fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Set CORS headers if function exists
        if (function_exists('setCORSHeaders')) {
            setCORSHeaders();
        } else {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred. Please check server logs.',
            'error_type' => 'Fatal Error'
        ]);
        error_log('Fatal error in register.php: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../models/User.php';
    require_once __DIR__ . '/../utils/jwt.php';
    require_once __DIR__ . '/../utils/password.php';
} catch (Exception $e) {
    // Set basic CORS headers if config.php failed to load
    if (!function_exists('setCORSHeaders')) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    } else {
        setCORSHeaders();
    }
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error. Please contact administrator.',
        'error' => $e->getMessage()
    ]);
    error_log('Error loading required files in register.php: ' . $e->getMessage());
    exit;
}

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Validation
if (empty($data['email']) || empty($data['username']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

// Verify reCAPTCHA if token is provided (reCAPTCHA v2 Checkbox)
if (isset($data['recaptcha_token']) && !empty($data['recaptcha_token'])) {
    try {
        // For reCAPTCHA v2, don't pass action parameter
        $recaptchaResult = verifyRecaptcha($data['recaptcha_token']);
        if (!$recaptchaResult || !$recaptchaResult['success']) {
            error_log('reCAPTCHA verification failed: ' . ($recaptchaResult['message'] ?? 'Unknown error'));
            // Require reCAPTCHA for registration
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'reCAPTCHA verification failed. Please complete the reCAPTCHA and try again.'
            ]);
            exit;
        }
    } catch (Exception $recaptchaException) {
        error_log('reCAPTCHA verification exception: ' . $recaptchaException->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'reCAPTCHA verification error. Please try again.'
        ]);
        exit;
    }
} else {
    // Require reCAPTCHA token for registration
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'reCAPTCHA verification required. Please complete the reCAPTCHA and try again.'
    ]);
    exit;
}

$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$username = trim($data['username']);
$password = $data['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 30) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username must be between 3 and 30 characters']);
    exit;
}

// Strong password validation
if (strlen($password) < 12) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Password must be at least 12 characters long'
    ]);
    exit;
}

// Check password strength
$passwordErrors = [];
if (!preg_match('/[a-z]/', $password)) {
    $passwordErrors[] = 'lowercase letter';
}
if (!preg_match('/[A-Z]/', $password)) {
    $passwordErrors[] = 'uppercase letter';
}
if (!preg_match('/[0-9]/', $password)) {
    $passwordErrors[] = 'number';
}
if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
    $passwordErrors[] = 'special character';
}

if (!empty($passwordErrors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Password must contain at least one: ' . implode(', ', $passwordErrors)
    ]);
    exit;
}

// Check for common weak passwords
$commonPasswords = ['password', '12345678', 'qwerty', 'admin', 'letmein'];
if (in_array(strtolower($password), $commonPasswords)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Password is too common. Please choose a stronger password.'
    ]);
    exit;
}

$user = new User();

// Check if email exists
if ($user->emailExists($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email already exists']);
    exit;
}

// Check if username exists
if ($user->usernameExists($username)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username already exists']);
    exit;
}

// Create user
try {
    // Test database connection first
    $testConn = getDBConnection();
    if (!$testConn) {
        throw new Exception('Database connection failed. Please check your database configuration.');
    }
    
    $userId = $user->create($email, $username, $password);

    if ($userId && $userId > 0) {
        // Generate token
        try {
            $token = JWT::generate([
                'userId' => $userId,
                'email' => $email,
                'username' => $username,
                'role' => 'normal',
                'subscriptionType' => 'free'
            ]);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $userId,
                        'email' => $email,
                        'username' => $username,
                        'role' => 'normal',
                        'subscriptionType' => 'free'
                    ]
                ]
            ]);
        } catch (Exception $tokenError) {
            error_log('Token generation error: ' . $tokenError->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Account created but authentication failed. Please try logging in.'
            ]);
        }
    } else {
        // Get the last error from the database connection
        $conn = getDBConnection();
        $dbError = '';
        if ($conn) {
            $dbError = $conn->error ?? 'Unknown database error';
        } else {
            $dbError = 'Database connection not available';
        }
        
        error_log('Failed to create user. Email: ' . $email . ', Username: ' . $username);
        error_log('DB Error: ' . $dbError);
        
        // Check if it's a duplicate entry error
        if (strpos($dbError, 'Duplicate entry') !== false) {
            if (strpos($dbError, 'email') !== false || strpos($dbError, 'users.email') !== false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Email already exists. Please use a different email.'
                ]);
            } elseif (strpos($dbError, 'username') !== false || strpos($dbError, 'users.username') !== false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Username already exists. Please choose a different username.'
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => 'User already exists. Please try logging in instead.'
                ]);
            }
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'message' => 'Failed to create user account. Please try again later.'
            ]);
        }
    }
} catch (Exception $e) {
    // Log error details but don't expose to user
    error_log('Registration error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Registration failed: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    // Log fatal errors
    error_log('Registration fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Registration failed. Please try again later.'
    ]);
}

