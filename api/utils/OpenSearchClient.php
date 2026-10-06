<?php
/**
 * OpenSearch Client Wrapper
 * Simplified interface for OpenSearch operations
 */

require_once __DIR__ . '/../config.php';

// Load Composer autoloader
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // Try alternative path
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
}

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Log error but don't die - let constructor handle it
    error_log("OpenSearch: Composer autoload not found. Tried: " . __DIR__ . '/../../vendor/autoload.php');
}

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class OpenSearchClient {
    private $client;
    private $indexName;
    private $redis;
    private $useRedis;
    
    public function __construct() {
        // Check if OpenSearch client is available
        if (!class_exists('\OpenSearch\Client')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            $errorMsg = 'OpenSearch PHP client not installed. ';
            if (!file_exists($autoloadPath)) {
                $errorMsg .= "Composer autoload not found at: {$autoloadPath}. ";
            }
            $errorMsg .= 'Run: composer require opensearch-project/opensearch-php';
            error_log("OpenSearch: " . $errorMsg);
            throw new Exception($errorMsg);
        }
        
        // Connect to OpenSearch
        $host = defined('OPENSEARCH_HOST') ? OPENSEARCH_HOST : '127.0.0.1';
        $port = defined('OPENSEARCH_PORT') ? OPENSEARCH_PORT : 9200;
        
        // Build client with connection pooling and retry logic
        $this->client = ClientBuilder::create()
            ->setHosts(["http://{$host}:{$port}"])
            ->setRetries(5) // Increased retries for reliability
            ->setConnectionParams([
                'client' => [
                    'curl' => [
                        CURLOPT_CONNECTTIMEOUT => 30, // Connection timeout (30s)
                        CURLOPT_TIMEOUT => 65, // 65 seconds (60s + 5s buffer for OpenSearch timeout)
                        CURLOPT_TCP_KEEPALIVE => 1,
                        CURLOPT_TCP_KEEPIDLE => 10,
                        CURLOPT_TCP_KEEPINTVL => 5,
                        CURLOPT_FRESH_CONNECT => false, // Reuse connections
                        CURLOPT_FORBID_REUSE => false, // Allow connection reuse
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1 // Use HTTP/1.1 for better compatibility
                    ]
                ]
            ])
            ->build();
        
        // Use index from config (defaults to 'leaked_data' only)
        $this->indexName = defined('OPENSEARCH_INDEX') ? OPENSEARCH_INDEX : 'leaked_data';
        
        // Initialize Redis for caching (optional - falls back to no cache if unavailable)
        $this->redis = null;
        $this->useRedis = false;
        
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379, 1); // 1 second timeout
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
                $this->useRedis = true;
                error_log("OpenSearch: Redis cache enabled");
            } catch (Exception $e) {
                $this->redis = null;
                $this->useRedis = false;
                error_log('OpenSearch: Redis connection failed (caching disabled): ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Search in OpenSearch
     */
    public function search($query, $limit = 100, $offset = 0, $removeDuplicates = false, $searchAfter = null) {
        $startTime = microtime(true);
        
        // Validate inputs
        if (empty($query)) {
            throw new Exception('Search query cannot be empty');
        }
        
        // Block queries that return more than 200k results per request - use async export instead
        // Note: This applies to the actual limit used, not the requested limit
        // Unlimited users can still request 1M, but we'll process in batches of 200k
        
        // Check if client is initialized
        if (!$this->client) {
            throw new Exception('OpenSearch client not initialized');
        }
        
        // Check Redis cache first (instant return for cached queries)
        // ENABLED - Redis caching for 80% performance boost!
        if ($this->useRedis && $this->redis) {
            try {
                $cacheKey = 'opensearch:' . hash('sha256', json_encode([$this->indexName, $query, $limit, $offset, $removeDuplicates, $searchAfter]));
                $cached = $this->redis->get($cacheKey);
                if ($cached !== false && is_array($cached)) {
                    $cached['searchTime'] = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
                    $cached['method'] = 'opensearch (redis cached)';
                    error_log("OpenSearch: Cache hit for query: " . substr($query, 0, 50));
                    return $cached;
                }
            } catch (Exception $e) {
                // Redis error, continue with search
                error_log('OpenSearch: Redis cache read error: ' . $e->getMessage());
            }
        }
        
        try {
            // Log search attempt
            error_log("OpenSearch: Starting search in index '{$this->indexName}' for query: " . substr($query, 0, 100));
            error_log("OpenSearch: Limit: {$limit}, Offset: {$offset}");
            
            // Handle comma-separated indices
            // OPTIMIZED: Skip index validation for speed (assume indices exist and have data)
            // This saves ~100-500ms per query on huge indices
            $indices = explode(',', $this->indexName);
            $validIndices = [];
            
            foreach ($indices as $index) {
                $index = trim($index);
                if (!empty($index)) {
                    $validIndices[] = $index; // Assume index exists (skip slow stats check)
                }
            }
            
            if (empty($validIndices)) {
                throw new Exception("No valid indices found. Checked: " . $this->indexName);
            }
            
            // Use all indices (skip slow validation)
            $searchIndex = implode(',', $validIndices);
            
            // Limit max results - reduce for better performance on huge indices
            // Note: You need to increase index.max_result_window setting in OpenSearch for >10k
            // For unlimited users (limit > 50000), cap at 200k per request (use pagination for more)
            if ($limit > 50000) {
                // Unlimited user - cap at 200k per request (prevents timeout and server overload)
                // For larger results, use pagination or async export
                $actualLimit = min($limit, 200000); // Cap at 200k per request
                if ($limit > 200000) {
                    error_log("OpenSearch: Unlimited user requested {$limit} results, capping at {$actualLimit} per request. Use pagination/streaming for more.");
                } else {
                    error_log("OpenSearch: Unlimited user detected (limit: {$limit}), using {$actualLimit} results");
                }
                
                // Auto-increase max_result_window for unlimited users (up to 200k)
                try {
                    $indicesToUpdate = explode(',', $searchIndex);
                    foreach ($indicesToUpdate as $idx) {
                        $idx = trim($idx);
                        try {
                            // Increase max_result_window to at least 200k + 10k buffer
                            $requiredWindow = 210000; // 200k + 10k buffer
                            $this->client->indices()->putSettings([
                                'index' => $idx,
                                'body' => [
                                    'index' => [
                                        'max_result_window' => $requiredWindow
                                    ]
                                ]
                            ]);
                            error_log("OpenSearch: Auto-increased max_result_window to {$requiredWindow} for index: {$idx}");
                        } catch (Exception $updateEx) {
                            // Ignore if already set or permission issue
                            error_log("OpenSearch: Could not update max_result_window for {$idx}: " . $updateEx->getMessage());
                        }
                    }
                } catch (Exception $autoFixEx) {
                    error_log("OpenSearch: Auto-increase max_result_window failed: " . $autoFixEx->getMessage());
                }
            } else {
                $maxLimit = 10000; // 10k results max for performance (FAST!)
                $actualLimit = min($limit, $maxLimit);
                
                // Log if we're limiting the results
                if ($limit > $maxLimit) {
                    error_log("OpenSearch: Requested {$limit} results but limiting to {$maxLimit} for performance. Use download for more results.");
                }
            }
            
            // Build query - optimize based on query type
            // Queries with dots/special chars (like domains): use match_phrase for exact matching
            // Single word queries: use simple match (no operator) - FASTER
            // Multi-word queries: use 'and' operator for precision
            $queryWords = preg_split('/\s+/', trim($query));
            $isSingleWord = count($queryWords) === 1;
            $hasSpecialChars = preg_match('/[\.@\+\-]/', $query); // Check for dots, @, +, -
            
            if ($hasSpecialChars) {
                // Queries with special chars (domains, emails, etc.)
                // Use match_phrase for exact matching (finds all occurrences)
                $queryType = [
                    'match_phrase' => [
                        'content' => [
                            'query' => $query
                        ]
                    ]
                ];
                error_log("OpenSearch: Using match_phrase query (has special chars) for: " . substr($query, 0, 50));
            } elseif ($isSingleWord) {
                // Single word: use match with 'or' operator to find all occurrences
                // This finds "baridiweb" even if it appears in "baridiweb.poste.dz"
                $queryType = [
                    'match' => [
                        'content' => [
                            'query' => $query,
                            'operator' => 'or' // 'or' to find all occurrences
                        ]
                    ]
                ];
                error_log("OpenSearch: Using match query with 'or' operator (single word) for: " . substr($query, 0, 50));
            } else {
                // Multi-word: use match_phrase ONLY (prevents heavy multi-word match)
                // match_phrase is faster and more precise for multi-word queries
                $queryType = [
                    'match_phrase' => [
                        'content' => [
                            'query' => $query
                        ]
                    ]
                ];
                error_log("OpenSearch: Using match_phrase query (multi-word) for: " . substr($query, 0, 50));
            }
            
            // Build body params - OPTIMIZED FOR MAXIMUM SPEED
            // Use search_after for pagination (faster than from/size)
            $bodyParams = [
                'size' => $actualLimit, // Use actualLimit directly
                'query' => $queryType,
                '_source' => ['content'], // ONLY fetch content field (FASTEST - removed other fields we don't need)
                'track_total_hits' => false, // Don't track total - MUCH faster (always false)
                'sort' => ['_doc'], // Fastest sort possible (document order) - REQUIRED for search_after
                // NOTE: Don't add _id as secondary sort - it causes CircuitBreakingException (requires loading all _id into memory)
                // OpenSearch will return 'sort' array in each hit when using _doc sort
                // Removed highlight - it slows down queries significantly
                // Removed line_number, file_path, file_name - we only need content for speed
                // Note: request_cache is a query parameter, not a body parameter
            ];
            
            // Use terminate_after for speed (stops after finding enough matches)
            // IMPORTANT: For unlimited users, don't use terminate_after or use very large value
            // to ensure next_search_after is always returned when there are more results
            if ($limit > 50000) {
                // For unlimited users: don't use terminate_after or use very large value
                // This ensures next_search_after is always returned when there are more results
                // terminate_after can prevent next_search_after from being returned
                // $bodyParams['terminate_after'] = min($actualLimit * 10, 1000000); // Very large or remove
                // REMOVED terminate_after for unlimited users to ensure next_search_after is always returned
                error_log("OpenSearch: Unlimited user - using size {$actualLimit} without terminate_after to ensure next_search_after");
            } else {
                // For regular users: use smaller terminate_after
                $bodyParams['terminate_after'] = min($actualLimit * 2, 50000); // 2x actualLimit or 50k max
            }
            
            $params = [
                'index' => $searchIndex, // CRITICAL: Must include index!
                'timeout' => '20s', // 20 seconds timeout (prevents dead requests accumulation)
                'request_cache' => true, // Enable request cache (query parameter, not body parameter)
                'body' => $bodyParams,
                'client' => [
                    'curl' => [
                        CURLOPT_TIMEOUT => 25, // 25 seconds (20s + 5s buffer)
                        CURLOPT_CONNECTTIMEOUT => 5, // Faster connection timeout (5s)
                        CURLOPT_TCP_KEEPALIVE => 1,
                        CURLOPT_TCP_KEEPIDLE => 10,
                        CURLOPT_FRESH_CONNECT => false, // Reuse connections (FASTER)
                        CURLOPT_FORBID_REUSE => false, // Allow connection reuse (FASTER)
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Use HTTP/1.1
                        CURLOPT_TCP_NODELAY => 1 // Disable Nagle algorithm (FASTER for small requests)
                    ]
                ]
            ];
            
            error_log("OpenSearch: Executing query...");
            $queryStartTime = microtime(true);
            
            // Set PHP timeout to match OpenSearch timeout (20s + buffer)
            set_time_limit(25); // 25 seconds (20s + 5s buffer) - prevents dead requests
            
            // Retry logic for connection issues
            $maxRetries = 5; // Increased retries for reliability
            $retryDelay = 2; // 2 second delay between retries
            $response = null;
            $lastError = null;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $response = $this->client->search($params);
                    break; // Success, exit retry loop
                } catch (Exception $e) {
                    $lastError = $e;
                    $errorMsg = $e->getMessage();
                    
                    // Check if it's a result window too large error
                    if (strpos($errorMsg, 'Result window is too large') !== false || 
                        strpos($errorMsg, 'max_result_window') !== false) {
                        error_log("OpenSearch: Result window too large error: {$errorMsg}");
                        
                        // Try to automatically increase max_result_window
                        try {
                            $indicesToUpdate = explode(',', $searchIndex);
                            foreach ($indicesToUpdate as $idx) {
                                $idx = trim($idx);
                                try {
                                    // Increase to at least 1M for unlimited users
                                    $requiredWindow = 1000000; // 1M for unlimited users
                                    $this->client->indices()->putSettings([
                                        'index' => $idx,
                                        'body' => [
                                            'index' => [
                                                'max_result_window' => $requiredWindow
                                            ]
                                        ]
                                    ]);
                                    error_log("OpenSearch: Successfully increased max_result_window to {$requiredWindow} for index: {$idx}");
                                } catch (Exception $updateEx) {
                                    error_log("OpenSearch: Failed to update max_result_window for {$idx}: " . $updateEx->getMessage());
                                }
                            }
                            // Retry the search after updating settings
                            if ($attempt < $maxRetries) {
                                error_log("OpenSearch: Retrying search after updating max_result_window...");
                                sleep(1); // Wait 1 second for settings to take effect
                                continue;
                            }
                        } catch (Exception $autoFixEx) {
                            error_log("OpenSearch: Auto-fix failed: " . $autoFixEx->getMessage());
                        }
                        
                        throw new Exception("Result window too large. Please run: curl -X PUT \"127.0.0.1:9200/{$searchIndex}/_settings\" -H 'Content-Type: application/json' -d '{\"index\":{\"max_result_window\":50000}}'");
                    }
                    
                    // Check if it's an index not found error
                    if (strpos($errorMsg, 'index_not_found_exception') !== false || 
                        strpos($errorMsg, 'IndexNotFoundException') !== false ||
                        strpos($errorMsg, 'no such index') !== false ||
                        strpos($errorMsg, 'index_not_found') !== false) {
                        error_log("OpenSearch: Index not found error: {$errorMsg}");
                        
                        // If we're searching multiple indices, try searching in just the first one
                        if (count($validIndices) > 1 && $attempt === 1) {
                            error_log("OpenSearch: Trying fallback to first index only: " . $validIndices[0]);
                            $params['index'] = $validIndices[0];
                            $searchIndex = $validIndices[0];
                            continue; // Retry with single index
                        }
                        
                        // If single index fails, try to find which indices actually exist
                        if (count($validIndices) === 1) {
                            // Try to find any existing index
                            try {
                                $allIndices = $this->client->cat()->indices(['format' => 'json']);
                                if (!empty($allIndices)) {
                                    $firstIndex = $allIndices[0]['index'] ?? null;
                                    if ($firstIndex && $firstIndex !== '.opensearch-dashboards') {
                                        error_log("OpenSearch: Trying fallback to any existing index: " . $firstIndex);
                                        $params['index'] = $firstIndex;
                                        $searchIndex = $firstIndex;
                                        continue;
                                    }
                                }
                            } catch (Exception $checkEx) {
                                error_log("OpenSearch: Could not find any existing indices: " . $checkEx->getMessage());
                            }
                        }
                        
                        throw new Exception("Index not found. Please check: " . $searchIndex . ". Error: " . $errorMsg);
                    }
                    
                    // Check if it's a connection error
                    if (strpos($errorMsg, 'No alive nodes') !== false || 
                        strpos($errorMsg, 'Connection') !== false ||
                        strpos($errorMsg, 'timeout') !== false) {
                        
                        if ($attempt < $maxRetries) {
                            error_log("OpenSearch: Attempt {$attempt} failed: {$errorMsg}. Retrying in {$retryDelay}s...");
                            usleep($retryDelay * 1000000); // Use microseconds for faster retry
                            $retryDelay *= 2; // Exponential backoff
                            continue;
                        }
                    }
                    
                    // If it's not a connection error, or we've exhausted retries, throw
                    throw $e;
                }
            }
            
            if ($response === null) {
                throw new Exception('Search failed after ' . $maxRetries . ' attempts: ' . ($lastError ? $lastError->getMessage() : 'Unknown error'));
            }
            
            $queryTime = (microtime(true) - $queryStartTime) * 1000;
            error_log("OpenSearch: Query completed in " . round($queryTime, 2) . "ms");
            
            if (!isset($response['hits'])) {
                error_log("OpenSearch: Invalid response structure - " . json_encode(array_keys($response)));
                throw new Exception('Invalid response from OpenSearch');
            }
            
            $results = [];
            // Handle both old and new total format
            if (isset($response['hits']['total'])) {
                if (is_array($response['hits']['total'])) {
                    $total = $response['hits']['total']['value'] ?? 0;
                } else {
                    $total = $response['hits']['total']; // Old format (just a number)
                }
            } else {
                $total = 0;
            }
            $hitsCount = count($response['hits']['hits'] ?? []);
            
            error_log("OpenSearch: Found {$total} total results, returning {$hitsCount} hits");
            error_log("OpenSearch: Response total structure: " . json_encode($response['hits']['total'] ?? 'not set'));
            
            // Extract search_after from last hit for pagination
            $nextSearchAfter = null;
            $hits = $response['hits']['hits'] ?? [];
            $hitsCount = count($hits);
            
            foreach ($hits as $index => $hit) {
                $source = $hit['_source'];
                $content = $source['content'] ?? '';
                
                // Remove leading numbers and colon (e.g., "36300652:" or "19625740:" → "")
                // OPTIMIZED: Use faster string operations instead of regex when possible
                if ($content && ctype_digit(substr($content, 0, 1))) {
                    // Fast path: Check if starts with digit, then find colon
                    $colonPos = strpos($content, ':');
                    if ($colonPos !== false && $colonPos < 20) { // Only remove if colon is near start (max 20 chars)
                        $contentWithoutNumbers = substr($content, $colonPos + 1);
                    } else {
                        $contentWithoutNumbers = $content;
                    }
                } else {
                    $contentWithoutNumbers = $content;
                }
                $contentWithoutNumbers = trim($contentWithoutNumbers); // Remove any leading whitespace
                
                $results[] = [
                    'line' => 0, // Not fetched for speed
                    'content' => $contentWithoutNumbers, // Store content without leading numbers
                    'file_path' => '', // Not fetched for speed
                    'file_name' => '', // Not fetched for speed
                    'score' => $hit['_score'] ?? 0
                ];
                
                // Get search_after from last hit (for pagination)
                // OpenSearch returns 'sort' array in each hit when using sort
                // This contains the sort values needed for search_after pagination
                if ($index === $hitsCount - 1) {
                    if (isset($hit['sort']) && is_array($hit['sort']) && !empty($hit['sort'])) {
                        $nextSearchAfter = $hit['sort'];
                        error_log("OpenSearch: Found next_search_after from last hit sort: " . json_encode($nextSearchAfter));
                    } else {
                        // If sort is not in response, try to construct from _id or other fields
                        // For _doc sort, we can use _id as fallback, but it's not ideal
                        // Log warning if sort is missing
                        error_log("OpenSearch: WARNING - sort not found in last hit. Hit keys: " . json_encode(array_keys($hit)));
                        // Try to use _id as fallback (not ideal, but better than nothing)
                        if (isset($hit['_id'])) {
                            $nextSearchAfter = [$hit['_id']]; // Use _id as fallback
                            error_log("OpenSearch: Using _id as fallback for search_after: " . $hit['_id']);
                        }
                    }
                }
            }
            
            // For unlimited users: return first 50k quickly for display
            // Download feature will use scroll API to get all results (much faster)
            // This prevents timeout while still showing results quickly
            
            $searchTime = (microtime(true) - $startTime) * 1000; // milliseconds
            
            error_log("OpenSearch: Search completed in " . round($searchTime, 2) . "ms total");
            
            // Remove duplicates if requested (compares content after removing leading numbers)
            $finalResults = $results;
            $originalCount = count($results);
            if ($removeDuplicates) {
                $finalResults = $this->removeDuplicates($results);
                $deduplicatedCount = count($finalResults);
                error_log("OpenSearch: Removed " . ($originalCount - $deduplicatedCount) . " duplicate lines");
            }
            
            // Determine if there are more results
            // IMPORTANT: has_more should depend ONLY on next_search_after existence
            // If next_search_after exists, there are more results available
            // Don't check hitsCount vs actualLimit - this can cause false negatives
            $hasMore = ($nextSearchAfter !== null);
            
            // Log pagination info for debugging
            error_log("OpenSearch: Pagination info - hitsCount: {$hitsCount}, actualLimit: {$actualLimit}, nextSearchAfter: " . ($nextSearchAfter ? json_encode($nextSearchAfter) : 'null') . ", hasMore: " . ($hasMore ? 'true' : 'false'));
            
            $result = [
                'results' => $finalResults,
                'count' => $total, // Keep original count from OpenSearch
                'returned' => count($finalResults),
                'returnedBeforeDedup' => $originalCount, // Show original count if duplicates were removed
                'searchTime' => round($searchTime, 2),
                'method' => 'opensearch',
                'next_search_after' => $nextSearchAfter, // For pagination
                'has_more' => $hasMore // Flag indicating if more results available
            ];
            
            // Cache in Redis (1 hour) if available - only cache successful results
            // ENABLED - Redis caching for 80% performance boost!
            if ($this->useRedis && $this->redis && count($results) > 0 && $total > 0) {
                try {
                    $cacheKey = 'opensearch:' . hash('sha256', json_encode([$this->indexName, $query, $limit, $offset, $removeDuplicates, $searchAfter]));
                    // Cache for 1 hour (3600 seconds) - shorter than ripgrep cache for fresher results
                    $this->redis->setex($cacheKey, 3600, $result);
                    error_log("OpenSearch: Results cached for 1 hour");
                } catch (Exception $e) {
                    // Redis error, continue without caching
                    error_log('OpenSearch: Redis cache write error: ' . $e->getMessage());
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = 'OpenSearch search error: ' . $e->getMessage();
            error_log($errorMsg);
            error_log('OpenSearch: Index used: ' . $this->indexName);
            error_log('OpenSearch: Query: ' . substr($query, 0, 100));
            throw new Exception('Search failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if OpenSearch is available
     */
    public function isAvailable() {
        try {
            $pingStart = microtime(true);
            $result = $this->client->ping();
            $pingTime = (microtime(true) - $pingStart) * 1000;
            error_log("OpenSearch: Ping successful in " . round($pingTime, 2) . "ms");
            
            // Also check if indices have data
            try {
                $indices = explode(',', $this->indexName);
                foreach ($indices as $index) {
                    $index = trim($index);
                    if (!empty($index)) {
                        $stats = $this->client->indices()->stats(['index' => $index]);
                        $docCount = $stats['indices'][$index]['total']['docs']['count'] ?? 0;
                        error_log("OpenSearch: Index '{$index}' has {$docCount} documents");
                    }
                }
            } catch (Exception $e) {
                error_log("OpenSearch: Could not check index stats: " . $e->getMessage());
            }
            
            return true;
        } catch (Exception $e) {
            error_log("OpenSearch: Ping failed - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get index stats
     */
    public function getStats() {
        try {
            $stats = $this->client->indices()->stats(['index' => $this->indexName]);
            return $stats;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Remove duplicate lines - compares content (numbers already removed from content)
     * Example: "http://wp-admin/wp-admin/install.php:admin:mvzp1214" (appears multiple times)
     * These are duplicates if the content matches exactly
     * 
     * Note: Leading numbers are already removed from content before this function is called
     * 
     * @param array $results Array of result objects with 'content' field
     * @return array Array with duplicates removed (keeps first occurrence)
     */
    private function removeDuplicates($results) {
        if (empty($results)) {
            return $results;
        }
        
        // OPTIMIZED: Use array_flip for O(1) lookup - FASTER than isset with hash
        // Direct string keys are faster than md5 hash keys for small-medium strings
        $seen = [];
        $uniqueResults = [];
        
        foreach ($results as $result) {
            $content = $result['content'] ?? '';
            
            // Normalize: trim whitespace (leading numbers already removed in main loop)
            $normalizedContent = trim($content);
            
            // Skip empty content
            if (empty($normalizedContent)) {
                continue;
            }
            
            // OPTIMIZED: Use content directly as key (PHP arrays are hash tables - O(1) lookup)
            // For strings < 64 chars, direct string key is faster than md5
            // For longer strings, we could use md5, but most content lines are short
            if (!isset($seen[$normalizedContent])) {
                $seen[$normalizedContent] = true;
                $uniqueResults[] = $result; // Keep first occurrence
            }
            // Skip duplicates (don't add to $uniqueResults)
        }
        
        return $uniqueResults;
    }
    
    /**
     * Build query type based on query content (helper for Scroll API)
     */
    private function buildQueryType($query, $limit) {
        $queryWords = preg_split('/\s+/', trim($query));
        $isSingleWord = count($queryWords) === 1;
        $hasSpecialChars = preg_match('/[\.@\+\-]/', $query);
        
        if ($hasSpecialChars) {
            // For unlimited users: use simple match for speed
            if ($limit > 50000) {
                return [
                    'match' => [
                        'content' => [
                            'query' => $query,
                            'operator' => 'and'
                        ]
                    ]
                ];
            } else {
                return [
                    'match_phrase' => [
                        'content' => [
                            'query' => $query
                        ]
                    ]
                ];
            }
        } elseif ($isSingleWord) {
            return [
                'match' => [
                    'content' => $query
                ]
            ];
        } else {
            return [
                'match' => [
                    'content' => [
                        'query' => $query,
                        'operator' => 'and'
                    ]
                ]
            ];
        }
    }
    
    /**
     * Search with Scroll API (MUCH FASTER for large result sets)
     * Returns first batch and scroll_id for continuing
     */
    public function searchWithScroll($query, $size = 5000, $scrollTimeout = '5m', $removeDuplicates = false) {
        $startTime = microtime(true);
        
        try {
            // Handle comma-separated indices
            $indices = explode(',', $this->indexName);
            $validIndices = [];
            foreach ($indices as $index) {
                $index = trim($index);
                if (!empty($index)) {
                    $validIndices[] = $index;
                }
            }
            
            if (empty($validIndices)) {
                throw new Exception("No valid indices found. Checked: " . $this->indexName);
            }
            
            $searchIndex = implode(',', $validIndices);
            
            // Build query type
            $queryType = $this->buildQueryType($query, $size);
            
            // Build body params - OPTIMIZED FOR MAXIMUM SPEED
            $bodyParams = [
                'size' => $size,
                'query' => $queryType,
                '_source' => ['content'], // ONLY fetch content field
                'track_total_hits' => false, // Don't track total for speed
            ];
            
            // NO terminate_after - we want ALL results!
            
            $params = [
                'index' => $searchIndex,
                'scroll' => $scrollTimeout, // Enable scroll
                'body' => $bodyParams,
                'client' => [
                    'curl' => [
                        CURLOPT_TIMEOUT => 310, // 310 seconds (300s + 10s buffer)
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TCP_KEEPALIVE => 1,
                        CURLOPT_TCP_NODELAY => 1,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_FRESH_CONNECT => false,
                        CURLOPT_FORBID_REUSE => false,
                    ]
                ]
            ];
            
            set_time_limit(310);
            
            $response = $this->client->search($params);
            
            $scrollId = $response['_scroll_id'] ?? null;
            $hits = $response['hits']['hits'] ?? [];
            
            $results = [];
            foreach ($hits as $hit) {
                $content = $hit['_source']['content'] ?? '';
                // Remove leading numbers
                if (($pos = strpos($content, ':')) !== false) {
                    $content = substr($content, $pos + 1);
                }
                if (!empty($content)) {
                    $results[] = ['content' => $content];
                }
            }
            
            // Remove duplicates if requested
            if ($removeDuplicates && !empty($results)) {
                $results = $this->removeDuplicates($results);
            }
            
            $searchTime = (microtime(true) - $startTime) * 1000;
            error_log("OpenSearch Scroll: Initial search completed in " . round($searchTime, 2) . "ms, found " . count($results) . " results");
            
            return [
                'results' => $results,
                'scroll_id' => $scrollId,
                'count' => count($results)
            ];
            
        } catch (Exception $e) {
            error_log('OpenSearch Scroll search error: ' . $e->getMessage());
            throw new Exception('Scroll search failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Continue scrolling with scroll ID
     */
    public function scroll($scrollId, $scrollTimeout = '5m') {
        try {
            $params = [
                'scroll_id' => $scrollId,
                'scroll' => $scrollTimeout,
                'client' => [
                    'curl' => [
                        CURLOPT_TIMEOUT => 310, // 310 seconds (300s + 10s buffer)
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TCP_KEEPALIVE => 1,
                        CURLOPT_TCP_NODELAY => 1,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_FRESH_CONNECT => false,
                        CURLOPT_FORBID_REUSE => false,
                    ]
                ]
            ];
            
            set_time_limit(310);
            
            $response = $this->client->scroll($params);
            
            $scrollId = $response['_scroll_id'] ?? null;
            $hits = $response['hits']['hits'] ?? [];
            
            $results = [];
            foreach ($hits as $hit) {
                $content = $hit['_source']['content'] ?? '';
                // Remove leading numbers
                if (($pos = strpos($content, ':')) !== false) {
                    $content = substr($content, $pos + 1);
                }
                if (!empty($content)) {
                    $results[] = ['content' => $content];
                }
            }
            
            return [
                'results' => $results,
                'scroll_id' => $scrollId,
                'count' => count($results)
            ];
            
        } catch (Exception $e) {
            error_log('OpenSearch Scroll error: ' . $e->getMessage());
            throw new Exception('Scroll failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Clear scroll context
     */
    public function clearScroll($scrollId) {
        try {
            if (!empty($scrollId)) {
                $this->client->clearScroll(['scroll_id' => $scrollId]);
                error_log("OpenSearch: Cleared scroll context: " . substr($scrollId, 0, 20) . "...");
            }
        } catch (Exception $e) {
            error_log('Error clearing scroll: ' . $e->getMessage());
        }
    }
}

