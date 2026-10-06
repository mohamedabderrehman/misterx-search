<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../models/User.php';

setCORSHeaders();

// Check authentication
$userData = authenticate();
if (!$userData) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No file uploaded or upload error occurred'
    ]);
    exit;
}

$file = $_FILES['profile_picture'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.'
    ]);
    exit;
}

// Validate file size (max 2MB)
if ($file['size'] > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'File size must be less than 2MB'
    ]);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../../uploads/profile-pictures/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create upload directory'
        ]);
        exit;
    }
}

// Create .htaccess to prevent direct access to PHP files
$htaccessFile = $uploadDir . '.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "Allow from all\n");
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'profile_' . $userData['userId'] . '_' . time() . '.' . $extension;
$filepath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save uploaded file'
    ]);
    exit;
}

// Generate URL for the uploaded image (relative to root)
$profilePictureUrl = '/misterx/uploads/profile-pictures/' . $filename;

// Update user's profile picture in database
try {
    $user = new User();
    $conn = getDBConnection();
    
    // Delete old profile picture if exists
    $oldPicture = $user->getProfilePicture($userData['userId']);
    if ($oldPicture && file_exists(__DIR__ . '/../../' . $oldPicture)) {
        @unlink(__DIR__ . '/../../' . $oldPicture);
    }
    
    // Update database
    $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
    $stmt->bind_param("si", $profilePictureUrl, $userData['userId']);
    
        if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Profile picture uploaded successfully',
            'data' => [
                'profile_picture_url' => $profilePictureUrl
            ]
        ]);
    } else {
        // Delete uploaded file if database update fails
        @unlink($filepath);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update profile picture in database'
        ]);
    }
} catch (Exception $e) {
    // Delete uploaded file on error
    @unlink($filepath);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

