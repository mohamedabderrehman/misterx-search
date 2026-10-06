<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userData = authenticate();
$userId = $userData['userId'];

$searchId = isset($_GET['searchId']) ? (int)$_GET['searchId'] : 0;

if (!$searchId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Search ID is required']);
    exit;
}

$search = new Search();
$searchData = $search->findById($searchId, $userId);

if (!$searchData) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Search not found']);
    exit;
}

// Check if user can download
$user = new User();
$userInfo = $user->findById($userId);
$canDownload = $userInfo['subscription_type'] === 'vip' || $userInfo['subscription_type'] === 'api';

if (!$canDownload) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Upgrade to Premium to download results']);
    exit;
}

// Get actual results using OpenSearch
try {
    // Check if OpenSearch is enabled
    if (!defined('OPENSEARCH_ENABLED') || !OPENSEARCH_ENABLED) {
        throw new Exception('OpenSearch is not enabled. Please contact administrator.');
    }
    
    require_once __DIR__ . '/../utils/OpenSearchClient.php';
    $openSearchClient = new OpenSearchClient();
    
    // Check if OpenSearch is available
    if (!$openSearchClient->isAvailable()) {
        throw new Exception('OpenSearch is not available. Please contact administrator.');
    }
    
    // Determine result limit based on subscription
    $resultLimit = $userInfo['subscription_type'] === 'api' ? 50000 : 10000;
    
    // Get results using OpenSearch (super fast!)
    $searchResults = $openSearchClient->search($searchData['search_query'], $resultLimit, 0);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'search' => $searchData,
            'results' => $searchResults['results'],
            'totalCount' => $searchResults['count'],
            'searchTime' => $searchResults['searchTime'],
            'searchMethod' => $searchResults['method'] ?? 'unknown'
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving results: ' . $e->getMessage()
    ]);
}

