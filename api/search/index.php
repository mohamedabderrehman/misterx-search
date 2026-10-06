<?php
// Set error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}");
    return false; // Let PHP handle it normally
});

// Set exception handler
set_exception_handler(function($exception) {
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Search service unavailable'
    ]);
    exit;
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try { $userData = authenticate(); }
catch (Exception $e) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Authentication required']); exit; }
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['query'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Search query is required']);
    exit;
}

$query = trim($data['query']);
$removeDuplicates = isset($data['remove_duplicates']) && $data['remove_duplicates'] === true;
$searchAfter = isset($data['search_after']) && is_array($data['search_after']) ? $data['search_after'] : null;
$userId = $userData['userId'];

// Get user subscription
$user = new User();
$userInfo = $user->findById($userId);
$subscriptionType = $userInfo['subscription_type'] ?? 'free';
$userEmail = $userInfo['email'] ?? '';

// Special users with unlimited search
$unlimitedUsers = array_filter(array_map('trim', explode(',', getenv('UNLIMITED_USER_EMAILS') ?: '')));
$isUnlimitedUser = in_array(strtolower($userEmail), array_map('strtolower', $unlimitedUsers));

// Check rate limits
$search = new Search();

// Rate-limit even for unlimited users (prevent infinite parallel searches)
// Unlimited ≠ infinite parallel searches
if ($isUnlimitedUser) {
    $concurrentSearches = $search->getConcurrentCount($userId);
    if ($concurrentSearches >= 5) { // Max 5 concurrent searches for unlimited users
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many concurrent searches. Please wait for current searches to complete.'
        ]);
        exit;
    }
}

// Unlimited users bypass rate limits (but have concurrent limit)
if (!$isUnlimitedUser) {
    if ($subscriptionType === 'free') {
        $todayCount = $search->getTodayCount($userId);
        if ($todayCount >= RATE_LIMIT_FREE_DAILY) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => "Daily search limit reached. You have used {$todayCount} searches today. Upgrade to VIP for unlimited searches."
            ]);
            exit;
        }
    } else {
        $hourlyCount = $search->getHourlyCount($userId);
        $limit = $subscriptionType === 'vip' ? RATE_LIMIT_VIP_HOURLY : RATE_LIMIT_API_HOURLY;
        if ($hourlyCount >= $limit) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Hourly limit reached. Please try again later.'
            ]);
            exit;
        }
    }
}

// Determine result limit based on subscription and pagination
// Pagination limits per request:
// - Free: 100 results (first page only, no Load More)
// - VIP: 2,000 first page, 5,000 per Load More (max 10,000 total)
// - Unlimited: 5,000 per request (no max total)
if ($searchAfter !== null) {
    // Load More request - use pagination limits
    if ($isUnlimitedUser) {
        $resultLimit = 5000; // Unlimited: 5k per Load More
    } else if ($subscriptionType === 'vip' || $subscriptionType === 'api') {
        $resultLimit = 5000; // VIP: 5k per Load More
    } else {
        // Free users shouldn't reach here (no Load More), but just in case
        $resultLimit = 100;
    }
} else {
    // First page request
    if ($isUnlimitedUser) {
        $resultLimit = 5000; // Unlimited: 5k first page
    } else if ($subscriptionType === 'vip' || $subscriptionType === 'api') {
        $resultLimit = 2000; // VIP: 2k first page
    } else {
        $resultLimit = 100; // Free: 100 results only
    }
}

// Perform actual search using OpenSearch
try {
    // Check if OpenSearch is enabled FIRST (before any file operations)
    if (!defined('OPENSEARCH_ENABLED') || !OPENSEARCH_ENABLED) {
        throw new Exception('OpenSearch is not enabled. Please contact administrator.');
    }
    
    // Log search start (to PHP error log first)
    $searchStartTime = microtime(true);
    $logMsg = "Search API: Starting search for query: " . substr($query, 0, 100) . " | Index: " . (defined('OPENSEARCH_INDEX') ? OPENSEARCH_INDEX : 'not set') . " | Limit: {$resultLimit}";
    error_log($logMsg);
    
    // Ensure logs directory exists (but don't fail if we can't create it)
    $logsDir = __DIR__ . '/../../logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    
    // Try to log to file, but don't fail if it doesn't work
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $logMsg . "\n", FILE_APPEND);
    }
    
    // Load OpenSearchClient
    $openSearchClientPath = __DIR__ . '/../utils/OpenSearchClient.php';
    if (!file_exists($openSearchClientPath)) {
        throw new Exception('OpenSearchClient.php not found at: ' . $openSearchClientPath);
    }
    
    require_once $openSearchClientPath;
    
    // Initialize OpenSearch client
    try {
        error_log("Search API: Attempting to create OpenSearchClient...");
        $openSearchClient = new OpenSearchClient();
        error_log("Search API: OpenSearchClient created successfully");
    } catch (Throwable $e) {
        $errorMsg = "Search API: Failed to initialize OpenSearch client: " . $e->getMessage();
        $errorMsg .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
        error_log($errorMsg);
        if (is_dir($logsDir) && is_writable($logsDir)) {
            @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $errorMsg . "\n", FILE_APPEND);
        }
        throw new Exception('Failed to initialize OpenSearch: ' . $e->getMessage() . ' (Check if OpenSearch PHP client is installed: composer require opensearch-project/opensearch-php)');
    }
    
    // Check if OpenSearch is available (with timeout)
    $pingStart = microtime(true);
    try {
        if (!$openSearchClient->isAvailable()) {
            $pingTime = (microtime(true) - $pingStart) * 1000;
            $errorMsg = "Search API: OpenSearch ping failed after " . round($pingTime, 2) . "ms";
            error_log($errorMsg);
            if (is_dir($logsDir) && is_writable($logsDir)) {
                @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $errorMsg . "\n", FILE_APPEND);
            }
            throw new Exception('OpenSearch is not available. Please contact administrator.');
        }
    } catch (Exception $e) {
        $pingTime = (microtime(true) - $pingStart) * 1000;
        $errorMsg = "Search API: OpenSearch ping error after " . round($pingTime, 2) . "ms: " . $e->getMessage();
        error_log($errorMsg);
        if (is_dir($logsDir) && is_writable($logsDir)) {
            @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $errorMsg . "\n", FILE_APPEND);
        }
        throw $e;
    }
    
    $pingTime = (microtime(true) - $pingStart) * 1000;
    $pingMsg = "Search API: OpenSearch ping successful in " . round($pingTime, 2) . "ms";
    error_log($pingMsg);
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $pingMsg . "\n", FILE_APPEND);
    }
    
    // Set PHP timeout to match OpenSearch timeout (60s + buffer)
    set_time_limit(70); // 70 seconds (60s + 10s buffer)
    
    // Perform search using OpenSearch (super fast - milliseconds!)
    // Note: OpenSearchClient limits to 10k results per query for performance
    // For larger result sets, we'll get the count and first batch
    error_log("Search API: Calling search() method...");
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - Search API: Calling search() method...' . "\n", FILE_APPEND);
    }
    
    $searchResults = $openSearchClient->search($query, $resultLimit, 0, $removeDuplicates, $searchAfter);
    
    error_log("Search API: search() method returned");
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - Search API: search() method returned' . "\n", FILE_APPEND);
    }
    
    $apiTime = (microtime(true) - $searchStartTime) * 1000;
    $timeMsg = "Search API: Total API time: " . round($apiTime, 2) . "ms";
    error_log($timeMsg);
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ' . $timeMsg . "\n", FILE_APPEND);
    }
    
    if (!isset($searchResults['count'])) {
        throw new Exception('Invalid search results format');
    }
    
    // Use returned count (after deduplication if enabled), fallback to original count
    $resultsCount = $searchResults['returned'] ?? $searchResults['count'];
    $originalCount = $searchResults['returnedBeforeDedup'] ?? $searchResults['count'];
    $searchTime = $searchResults['searchTime'] ?? 0;
    $searchMethod = $searchResults['method'] ?? 'opensearch';
    
    // Log if duplicates were removed
    if ($removeDuplicates && isset($searchResults['returnedBeforeDedup'])) {
        $removedCount = $searchResults['returnedBeforeDedup'] - $resultsCount;
        error_log("Search API: Removed {$removedCount} duplicate lines. Original: {$originalCount}, After dedup: {$resultsCount}");
    }
    
    // Save search to history (only for first page, not Load More)
    $searchId = null;
    if ($searchAfter === null) {
        $searchId = $search->create($userId, $query, $resultsCount, $subscriptionType);
    }
    
    // Get pagination info from search results
    $nextSearchAfter = $searchResults['next_search_after'] ?? null;
    $hasMore = $searchResults['has_more'] ?? false;
    
    // Log pagination info for debugging
    error_log("Search API: Pagination info - nextSearchAfter: " . ($nextSearchAfter ? json_encode($nextSearchAfter) : 'null') . ", hasMore: " . ($hasMore ? 'true' : 'false') . ", resultsCount: {$resultsCount}");
    
    // Calculate total results loaded so far (for VIP max limit check)
    $totalLoaded = $resultsCount;
    if ($searchAfter !== null && isset($data['total_loaded'])) {
        $totalLoaded = (int)$data['total_loaded'] + $resultsCount;
    }
    
    // For VIP: check if we've reached 10k limit
    // Only override has_more for VIP users (not unlimited)
    if (!$isUnlimitedUser && ($subscriptionType === 'vip' || $subscriptionType === 'api')) {
        if ($totalLoaded >= 10000) {
            $hasMore = false; // No more results for VIP (10k limit)
            $nextSearchAfter = null;
        }
    }
    // For unlimited users: has_more depends ONLY on next_search_after (already set correctly from OpenSearchClient)
    
    // Store search session in Redis (for pagination continuity and download)
    // Store ALL search_after tokens for this search so download can get all results
    if (extension_loaded('redis')) {
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379, 1);
            $sessionKey = 'search_session:' . $searchId; // Use searchId as key for easier retrieval
            $paginationKey = 'search_pagination:' . $searchId; // Store all search_after tokens
            
            // Store current session data (always store, even if searchId is null for Load More)
            if ($searchId !== null) {
                $sessionData = [
                    'query' => $query,
                    'remove_duplicates' => $removeDuplicates,
                    'next_search_after' => $nextSearchAfter,
                    'total_loaded' => $totalLoaded,
                    'subscription_type' => $subscriptionType,
                    'is_unlimited' => $isUnlimitedUser
                ];
                $redis->setex($sessionKey, 3600, json_encode($sessionData)); // 1 hour expiry
                
                // Store pagination chain (all search_after tokens for this search)
                // This allows download to get all results that were loaded
                $paginationData = $redis->get($paginationKey);
                $paginationTokens = $paginationData ? json_decode($paginationData, true) : [];
                
                // For first page: store null (first page) and nextSearchAfter
                if ($searchAfter === null) {
                    // First page - store nextSearchAfter for Load More
                    if ($nextSearchAfter !== null && !in_array($nextSearchAfter, $paginationTokens, true)) {
                        $paginationTokens[] = $nextSearchAfter;
                    }
                } else {
                    // Load More - add the search_after we used and the next one
                    if (!in_array($searchAfter, $paginationTokens, true)) {
                        $paginationTokens[] = $searchAfter; // Add the search_after we used for this request
                    }
                    if ($nextSearchAfter !== null && !in_array($nextSearchAfter, $paginationTokens, true)) {
                        $paginationTokens[] = $nextSearchAfter; // Add the next search_after for future requests
                    }
                }
                
                $redis->setex($paginationKey, 3600, json_encode($paginationTokens)); // 1 hour expiry
            } else {
                // Load More request - update existing pagination tokens
                $paginationData = $redis->get($paginationKey);
                if ($paginationData) {
                    $paginationTokens = json_decode($paginationData, true);
                    if (is_array($paginationTokens)) {
                        // Add current search_after and next one
                        if ($searchAfter !== null && !in_array($searchAfter, $paginationTokens, true)) {
                            $paginationTokens[] = $searchAfter;
                        }
                        if ($nextSearchAfter !== null && !in_array($nextSearchAfter, $paginationTokens, true)) {
                            $paginationTokens[] = $nextSearchAfter;
                        }
                        $redis->setex($paginationKey, 3600, json_encode($paginationTokens));
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Failed to store search session in Redis: ' . $e->getMessage());
        }
    }
    
    // Determine if user can download
    // Free users can download 100 lines only, unlimited users/VIP/API can download unlimited
    $canDownload = $isUnlimitedUser || $subscriptionType === 'vip' || $subscriptionType === 'api' || $subscriptionType === 'free';
    
    echo json_encode([
        'success' => true,
        'data' => [
            'searchId' => $searchId,
            'query' => $query,
            'results' => $searchResults['results'] ?? [], // Return actual results
            'resultsCount' => $resultsCount,
            'totalLoaded' => $totalLoaded, // Total results loaded so far
            'next_search_after' => $nextSearchAfter, // For Load More pagination
            'has_more' => $hasMore, // Flag indicating if more results available
            'searchTime' => $searchTime,
            'searchMethod' => $searchMethod,
            'canDownload' => $canDownload,
            'isUnlimitedUser' => $isUnlimitedUser,
            'message' => $canDownload 
                ? "Search completed in {$searchTime}s - Found {$resultsCount} results" 
                : "Found {$resultsCount} results. Upgrade to Premium to download these results"
        ]
    ]);
    
} catch (Exception $e) {
    // Log error details to PHP error log (always works)
    $errorMsg = 'Search error: ' . $e->getMessage();
    $fullErrorMsg = $errorMsg . ' - ' . $e->getTraceAsString();
    error_log($fullErrorMsg);
    
    // Also log to search.log if possible
    $logsDir = __DIR__ . '/../../logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - ERROR: ' . $fullErrorMsg . "\n", FILE_APPEND);
        @file_put_contents($logsDir . '/search_errors.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    // Return error message - this will be visible even with security measures
    echo json_encode([
        'success' => false,
        'message' => 'Search service unavailable. Check the configured synthetic index and service connectivity.', // Show actual error for debugging
        'error_type' => 'Exception',
        'error_file' => null,
        'error_line' => null
    ]);
    exit;
} catch (Error $e) {
    // Log fatal errors to PHP error log (always works)
    $errorMsg = 'Search fatal error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString();
    error_log($errorMsg);
    
    // Also log to search.log if possible
    $logsDir = __DIR__ . '/../../logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - FATAL ERROR: ' . $errorMsg . "\n", FILE_APPEND);
        @file_put_contents($logsDir . '/search_errors.log', date('Y-m-d H:i:s') . ' - FATAL: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Search service unavailable. Check the configured synthetic index and service connectivity.',
        'error_type' => 'Fatal Error',
        'error_file' => null,
        'error_line' => null
    ]);
    exit;
} catch (Throwable $e) {
    // Log throwable errors to PHP error log (always works)
    $errorMsg = 'Search throwable: ' . $e->getMessage() . ' - ' . $e->getTraceAsString();
    error_log($errorMsg);
    
    // Also log to search.log if possible
    $logsDir = __DIR__ . '/../../logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    if (is_dir($logsDir) && is_writable($logsDir)) {
        @file_put_contents($logsDir . '/search.log', date('Y-m-d H:i:s') . ' - THROWABLE: ' . $errorMsg . "\n", FILE_APPEND);
        @file_put_contents($logsDir . '/search_errors.log', date('Y-m-d H:i:s') . ' - THROWABLE: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Search service unavailable. Check the configured synthetic index and service connectivity.',
        'error_type' => 'Throwable',
        'error_file' => null,
        'error_line' => null
    ]);
    exit;
}

