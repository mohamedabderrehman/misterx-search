<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

$userData = authenticate();
$user = new User();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userInfo = $user->findById($userData['userId']);
    
    if (!$userInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => ['user' => $userInfo]
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $email = isset($data['email']) ? filter_var($data['email'], FILTER_SANITIZE_EMAIL) : null;
    $username = isset($data['username']) ? trim($data['username']) : null;
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }
    
    if ($username && (strlen($username) < 3 || strlen($username) > 30)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username must be between 3 and 30 characters']);
        exit;
    }
    
    // Check if email/username already exists (excluding current user)
    if ($email && $user->emailExists($email, $userData['userId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }
    
    if ($username && $user->usernameExists($username, $userData['userId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }
    
    $updatedUser = $user->update($userData['userId'], $email, $username);
    
    if ($updatedUser) {
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => ['user' => $updatedUser]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

