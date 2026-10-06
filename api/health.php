<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'demoMode' => DEMO_MODE,
    'recaptchaSiteKey' => RECAPTCHA_SITE_KEY,
    'message' => 'New Era Intelligence (NEI) API is running',
    'timestamp' => date('Y-m-d H:i:s')
]);

