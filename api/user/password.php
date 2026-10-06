<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/password.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['currentPassword']) || empty($data['newPassword'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Current password and new password are required']);
    exit;
}

if (strlen($data['newPassword']) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
    exit;
}

if (isset($data['confirmNewPassword']) && $data['newPassword'] !== $data['confirmNewPassword']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
    exit;
}

$user = new User();
$userInfo = $user->findByEmailOrUsername($userData['email']);

if (!$userInfo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

if (!Password::verify($data['currentPassword'], $userInfo['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
    exit;
}

if ($user->updatePassword($userData['userId'], $data['newPassword'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
}

