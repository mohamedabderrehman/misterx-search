<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'New Era Intelligence (NEI) API is running',
    'timestamp' => date('Y-m-d H:i:s')
]);

