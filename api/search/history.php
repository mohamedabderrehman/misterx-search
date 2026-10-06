<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$userId = $userData['userId'];

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$search = new Search();
$history = $search->getHistory($userId, $limit, $offset);

echo json_encode([
    'success' => true,
    'data' => ['history' => $history]
]);

