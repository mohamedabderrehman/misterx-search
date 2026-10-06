<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/auth.php';

// Check if user can download
$userData = authenticate();
$userId = $userData['userId'];

$searchId = isset($_GET['searchId']) ? (int)$_GET['searchId'] : 0;
$removeDuplicates = isset($_GET['remove_duplicates']) && ($_GET['remove_duplicates'] === 'true' || $_GET['remove_duplicates'] === '1');

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

// Special users with unlimited search and download
$unlimitedUsers = ['ilovematoub@gmail.com'];
$isUnlimitedUser = in_array(strtolower($userInfo['email'] ?? ''), array_map('strtolower', $unlimitedUsers));

// Free users can download 100 lines only, unlimited users/VIP/API can download unlimited
$canDownload = $isUnlimitedUser || $userInfo['subscription_type'] === 'vip' || $userInfo['subscription_type'] === 'api' || $userInfo['subscription_type'] === 'free';
$isFreeUser = !$isUnlimitedUser && $userInfo['subscription_type'] === 'free';
$freeUserDownloadLimit = 100; // Free users can download 100 lines only

if (!$canDownload) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Upgrade to Premium to download results']);
    exit;
}

// Get actual results using OpenSearch
try {
    // Set timeout for large downloads (5 minutes)
    set_time_limit(300);
    
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
    
    // Special users with unlimited search
    $unlimitedUsers = ['ilovematoub@gmail.com'];
    $isUnlimitedUser = in_array(strtolower($userInfo['email'] ?? ''), array_map('strtolower', $unlimitedUsers));
    
    // UNLIMITED DOWNLOAD - REAL STREAMING (prevents timeout by sending data immediately)
    // Stream each batch as soon as it's fetched - keeps connection alive
    
    // Sanitize filename first (before any output)
    $sanitizedSearchId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$searchId);
    $filename = 'search_results_' . $sanitizedSearchId . '_' . date('Y-m-d') . '.txt';
    
    // Send headers IMMEDIATELY (before fetching data) to prevent timeout
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Transfer-Encoding: chunked'); // Use chunked encoding for streaming
    header('X-Accel-Buffering: no'); // Disable nginx buffering
    header('Cache-Control: no-cache');
    
    // Disable output buffering for instant streaming
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_implicit_flush(true);
    
    // Stream results using search_after pagination (same as Load More)
    // Get all search_after tokens from Redis to download ONLY the loaded results
    $searchAfterTokens = [null]; // Start with null (first page - always loaded)
    if (extension_loaded('redis')) {
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379, 1);
            $paginationKey = 'search_pagination:' . $searchId;
            $storedTokens = $redis->get($paginationKey);
            if ($storedTokens) {
                $tokens = json_decode($storedTokens, true);
                if (is_array($tokens) && !empty($tokens)) {
                    // Only use stored tokens (these are the search_after tokens that were actually used)
                    // This ensures we download ONLY the results that were loaded via Load More
                    $searchAfterTokens = array_merge([null], $tokens); // Start with null (first page), then all stored tokens
                }
            }
            // If no tokens in Redis, it means only first page was loaded, so we'll just use [null]
        } catch (Exception $e) {
            error_log('Failed to get pagination tokens from Redis: ' . $e->getMessage());
        }
    }
    // If Redis is not available or no tokens found, download only first page (null = first page)
    
    $batchSize = $isFreeUser ? min(100, 5000) : 5000; // Free users: smaller batches (max 100 lines total)
    $resultCount = 0;
    $batchNumber = 0;
    $hasResults = false;
    $maxResults = $isFreeUser ? $freeUserDownloadLimit : PHP_INT_MAX; // Free users: 100 lines max, unlimited users: unlimited
    
    // Stream using search_after pagination (same as Load More)
    // Only download the results that were actually loaded (from Redis tokens)
    // If no tokens in Redis, only download first page (5k for unlimited, 2k for VIP, 100 for free)
    foreach ($searchAfterTokens as $searchAfter) {
        // Check if we've reached the limit (for free users)
        if ($resultCount >= $maxResults) {
            break;
        }
        
        try {
            // Calculate how many results to fetch for this batch
            // For first page (null), use the batch size that was used in search
            // For Load More pages, use 5k (same as Load More)
            $currentBatchSize = $searchAfter === null ? $batchSize : 5000;
            $remainingLimit = $maxResults - $resultCount;
            $currentBatchSize = min($currentBatchSize, $remainingLimit);
            
            // Fetch batch using search_after (same as Load More)
            $batch = $openSearchClient->search($searchData['search_query'], $currentBatchSize, 0, $removeDuplicates, $searchAfter);
            
            if (!isset($batch['results']) || !is_array($batch['results']) || empty($batch['results'])) {
                // No more results
                break;
            }
            
            // Process and output batch immediately (stream to client)
            $batchOutput = '';
            foreach ($batch['results'] as $result) {
                // Get content
                $resultContent = null;
                if (isset($result['content'])) {
                    $resultContent = $result['content'];
                } elseif (isset($result['text'])) {
                    $resultContent = $result['text'];
                } elseif (isset($result['line_content'])) {
                    $resultContent = $result['line_content'];
                }
                
                if ($resultContent !== null) {
                    // Content should already be cleaned from OpenSearchClient (leading numbers removed)
                    $trimmedContent = trim($resultContent);
                    
                    if (!empty($trimmedContent) && strlen($trimmedContent) >= 1) {
                        // Check free user limit
                        if ($isFreeUser && $resultCount >= $maxResults) {
                            break 2; // Break out of both loops
                        }
                        
                        // Add to batch output (no line numbers - content only)
                        $batchOutput .= $trimmedContent . "\n";
                        $resultCount++;
                        $hasResults = true;
                    }
                }
            }
            
            // Output batch immediately (streaming)
            if (!empty($batchOutput)) {
                echo $batchOutput;
                ob_flush();
                flush(); // Force send to client immediately
            }
            
            // Check if we got fewer results than requested (end of results)
            if (count($batch['results']) < $batchSize) {
                break; // No more results
            }
            
            $offset += $batchSize;
            $batchNumber++;
            
            // Progress logging every 20 batches (less frequent to avoid slowing down)
            if ($batchNumber % 20 === 0) {
                error_log('Download - Streaming batch ' . $batchNumber . ', ' . $resultCount . ' results sent so far');
            }
            
            // Check if there are more results (for this search_after token)
            // If this is the last token and no more results, break
            $hasMore = $batch['has_more'] ?? false;
            $nextSearchAfter = $batch['next_search_after'] ?? null;
            
            // If no more results for this token, break
            if (!$hasMore || $nextSearchAfter === null) {
                // If this was the last stored token, we're done
                if ($searchAfter === end($searchAfterTokens)) {
                    break;
                }
            }
            
        } catch (Exception $e) {
            error_log('Download - Error fetching batch with search_after ' . json_encode($searchAfter) . ': ' . $e->getMessage());
            // Continue with next token instead of breaking
            continue;
        }
        
        // Check if we've reached the limit (for free users)
        if ($resultCount >= $maxResults) {
            break;
        }
    }
    
    // Final flush
    ob_flush();
    flush();
    
    // Check if we have content (after streaming)
    if (!$hasResults) {
        // We already sent headers, so we can't change response code
        // But we can output error message
        echo "\n[ERROR: No results found for this search query]";
        error_log('Download - No results found after streaming');
        exit;
    }
    
    error_log('Download - Streaming completed. Total results sent: ' . $resultCount);
    
} catch (Exception $e) {
    // Log error details but don't expose to user
    error_log('Download error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving results. Please try again later.'
    ]);
} catch (Error $e) {
    // Log fatal errors
    error_log('Download fatal error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving results. Please try again later.'
    ]);
}

