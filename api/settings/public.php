<?php
require_once __DIR__ . '/../config.php';

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Only get public settings (site_name, site_description, site_logo)
    $publicSettings = ['site_name', 'site_description', 'site_logo'];
    $placeholders = implode(',', array_fill(0, count($publicSettings), '?'));
    
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->bind_param(str_repeat('s', count($publicSettings)), ...$publicSettings);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'site_name' => $settings['site_name'] ?? 'New Era Intelligence (NEI)',
            'site_description' => $settings['site_description'] ?? '',
            'site_logo' => $settings['site_logo'] ?? ''
        ]
    ]);
} catch (Exception $e) {
    // Return defaults on error
    echo json_encode([
        'success' => true,
        'data' => [
            'site_name' => 'New Era Intelligence (NEI)',
            'site_description' => '',
            'site_logo' => ''
        ]
    ]);
}
?>

