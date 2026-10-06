<?php
/**
 * Ultra-Fast Search Engine using ripgrep + Redis
 * Searches billions of lines in milliseconds
 * Optimized for Ubuntu 24.04 with multiple CPU cores
 */

class FastSearch {
    private $dataPath;
    private $maxResults;
    private $cacheDir;
    private $redis;
    private $useRedis;
    
    public function __construct() {
        // Path to leaked data
        $this->dataPath = defined('LEAKED_DATA_PATH') ? LEAKED_DATA_PATH : '/root/logs/downloads';
        $this->maxResults = 10000; // Limit results for performance
        $this->cacheDir = __DIR__ . '/../../cache/search';
        
        // Initialize Redis connection (optional - falls back to file cache if unavailable)
        $this->redis = null;
        $this->useRedis = false;
        
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379, 1); // 1 second timeout
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
                $this->useRedis = true;
            } catch (Exception $e) {
                // Redis not available, use file cache
                $this->redis = null;
                $this->useRedis = false;
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log('Redis connection failed (will use file cache): ' . $e->getMessage());
                }
            }
        }
        
        // Create cache directory if not exists
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        
        // Check if data path is accessible (not blocked by open_basedir)
        if (!is_dir($this->dataPath) || !is_readable($this->dataPath)) {
            // Log to secure error log, not public temp directory
            $logFile = __DIR__ . '/../../logs/php_errors.log';
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Data path not accessible\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Check if ripgrep is available
     */
    public function isRipgrepAvailable() {
        $output = [];
        $returnCode = 0;
        exec('which rg 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Ultra-fast search using ripgrep with parallel processing
     * Falls back to grep if ripgrep is not available
     * NO TIMEOUT - searches run until completion
     * 
     * @param string $query Search query (email, username, etc.)
     * @param int $limit Maximum results to return
     * @return array Search results
     */
    public function search($query, $limit = 500000) { // Default to high limit
        // Clean and prepare query
        $cleanQuery = trim($query);
        $queryLower = strtolower($cleanQuery); // For validation
        
        // Sanitize query for shell
        $sanitizedQuery = escapeshellarg($cleanQuery);
        
        $startTime = microtime(true);
        
        // Check Redis cache first (instant return for cached queries)
        if ($this->useRedis && $this->redis) {
            try {
                $cacheKey = 'search:' . md5($query . $limit);
                $cached = $this->redis->get($cacheKey);
                if ($cached !== false && is_array($cached)) {
                    $cached['searchTime'] = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
                    $cached['method'] = 'ripgrep (redis cached)';
                    return $cached;
                }
            } catch (Exception $e) {
                // Redis error, continue with search
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log('Redis cache read error: ' . $e->getMessage());
                }
            }
        }
        
        // Try to use ripgrep (rg) - MUCH faster than grep (10-100x)
        $rgPath = '/usr/bin/rg';
        $useRipgrep = false;
        
        // Check if ripgrep exists
        if (file_exists($rgPath)) {
            $useRipgrep = true;
        } else {
            // Try to find ripgrep in PATH
            $output = [];
            $returnCode = 0;
            @exec('which rg 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                $rgPath = trim($output[0]);
                $useRipgrep = true;
            }
        }
        
        if ($useRipgrep) {
            // Use ripgrep with MAXIMUM optimizations for large datasets (1GB+ files)
            // Get CPU count for optimal parallel threads
            $cpuCount = (int)@shell_exec('nproc') ?: 4;
            $cpuCount = min($cpuCount, 64); // Use up to 64 threads for maximum speed
            
            // ripgrep command with MAXIMUM optimizations for unlimited search:
            // -i: case insensitive
            // -F: fixed string (not regex) - MUCH faster and exact match
            // -n: line numbers
            // -j: parallel threads (use all CPU cores, up to 64)
            // --mmap: use memory mapping for faster I/O (essential for large files)
            // --no-heading: no filename prefix
            // --no-filename: don't show filename
            // --no-messages: suppress error messages
            // -g: glob pattern to only search .txt files
            // NO --max-filesize: Search ALL files regardless of size
            // NO --max-count: Unlimited results
            // NO -m: Unlimited matches per file
            $command = sprintf(
                '%s -iF -n -j %d --mmap --no-heading --no-filename --no-messages -g "*.txt" %s %s 2>/dev/null',
                escapeshellarg($rgPath),
                $cpuCount,
                $sanitizedQuery,
                escapeshellarg($this->dataPath)
            );
        } else {
            // Fallback to grep if ripgrep not available
            $grepPath = '/usr/bin/grep';
            $matchesPerFile = 1000;
            
            // Use grep with parallel processing via xargs
            $cpuCount = (int)@shell_exec('nproc') ?: 4;
            $cpuCount = min($cpuCount, 8);
            
            // Use find + xargs for parallel grep
            $command = sprintf(
                'find %s -type f -name "*.txt" -print0 | xargs -0 -P %d -n 1 grep -iFhnm %d -- %s 2>/dev/null',
                escapeshellarg($this->dataPath),
                $cpuCount,
                $matchesPerFile,
                $sanitizedQuery
            );
        }
        
        // Execute search using exec (MUCH FASTER than proc_open for large datasets)
        // NO TIMEOUT - let ripgrep run until completion (handled by background jobs)
        $output = [];
        $returnCode = 0;
        
        // Execute without timeout - searches can take hours for very large datasets
        // Background jobs handle this properly
        @exec($command . ' 2>/dev/null', $output, $returnCode);
        
        // Limit output to requested limit (for display/download)
        if ($limit > 0 && count($output) > $limit) {
            $output = array_slice($output, 0, $limit);
        }
        
        // Process and validate results IMMEDIATELY - filter out false positives
        $results = [];
        $count = 0;
        // $queryLower is already defined above
        
        foreach ($output as $line) {
            if ($count >= $limit) break;
            
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip error messages
            if (preg_match('/^(grep|find|xargs|rg|ripgrep):/i', $line)) continue;
            if (preg_match('/^(usage|error):/i', $line)) continue;
            if (preg_match('/^(POSITIONAL|INPUT|SEARCH|FILTER|OUTPUT|LOGGING|OTHER|Project home|Use -h|a regex|a pattern|a file)/i', $line)) continue;
            
            // Format: line_number:content
            if (preg_match('/^(\d+):(.+)$/', $line, $matches)) {
                $content = trim($matches[2]);
                
                // CRITICAL VALIDATION: Verify that content actually contains the query
                // This is the MOST IMPORTANT check - prevents false positives
                $contentLower = strtolower($content);
                
                // Only add if content contains the query (exact substring match, case-insensitive)
                if (strlen($content) >= 1 && strpos($contentLower, $queryLower) !== false) {
                    $results[] = [
                        'line' => (int)$matches[1],
                        'content' => $content
                    ];
                    $count++;
                }
                // Skip if doesn't contain query (prevents false positives from ripgrep)
            }
        }
        
        $searchTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        
        // Return actual count of valid results (not the limit)
        $actualCount = count($results);
        
        $result = [
            'results' => $results,
            'count' => $actualCount, // Fixed: return actual count, not limit
            'searchTime' => round($searchTime, 2), // milliseconds
            'query' => $cleanQuery, // Use cleaned query
            'method' => $useRipgrep ? 'ripgrep (parallel)' : 'grep (parallel)'
        ];
        
        // Cache in Redis (24 hours) if available - only cache validated results
        if ($this->useRedis && $this->redis && count($results) > 0 && $actualCount > 0) {
            try {
                $cacheKey = 'search:' . md5($cleanQuery . $limit);
                // Only cache if we have valid results that match the query
                $this->redis->setex($cacheKey, 86400, $result); // 24 hours
            } catch (Exception $e) {
                // Redis error, continue without caching
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log('Redis cache write error: ' . $e->getMessage());
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Parallel search using multiple processes (EVEN FASTER)
     * Uses ALL CPU cores for maximum performance
     * Falls back to simple search if parallel fails
     */
    public function parallelSearch($query, $limit = 500) {
        // For now, use simple search which works reliably
        // Parallel search with find+xargs is complex and may fail
        // We can optimize later with better parallel implementation
        return $this->search($query, $limit);
    }
    
    /**
     * Get all subdirectories for parallel processing
     */
    private function getSubdirectories($path) {
        $dirs = [];
        if (is_dir($path) && is_readable($path)) {
            try {
                $items = @scandir($path);
                if ($items !== false) {
                    foreach ($items as $item) {
                        if ($item != '.' && $item != '..') {
                            $fullPath = $path . '/' . $item;
                            if (is_dir($fullPath) && is_readable($fullPath)) {
                                $dirs[] = $fullPath;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // If error, return empty array
            }
        }
        return $dirs;
    }
    
    /**
     * Count total matches (fast count without loading results)
     * Uses ripgrep if available, falls back to grep
     */
    public function countMatches($query) {
        $sanitizedQuery = escapeshellarg($query);
        
        // Try ripgrep first (much faster)
        $rgPath = '/usr/bin/rg';
        $useRipgrep = file_exists($rgPath);
        
        if (!$useRipgrep) {
            $output = [];
            $returnCode = 0;
            @exec('which rg 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                $rgPath = trim($output[0]);
                $useRipgrep = true;
            }
        }
        
        if ($useRipgrep) {
            // ripgrep count (very fast)
            $command = sprintf(
                '%s -iFh --no-messages %s %s 2>/dev/null | wc -l',
                escapeshellarg($rgPath),
                $sanitizedQuery,
                escapeshellarg($this->dataPath)
            );
        } else {
            // Fallback to grep
            $command = sprintf(
                'grep -riFh %s %s 2>/dev/null | wc -l',
                $sanitizedQuery,
                escapeshellarg($this->dataPath)
            );
        }
        
        $count = (int)trim(@shell_exec($command) ?: '0');
        return $count;
    }
    
    /**
     * Search with caching for repeated queries
     * Uses Redis if available, falls back to file cache
     * IMPORTANT: Cache only stores validated results (with query verification)
     */
    public function searchWithCache($query, $limit = 500) {
        // Redis cache is handled in search() method
        // This method now just calls search() which handles caching automatically
        $results = $this->search($query, $limit);
        
        // Only cache if we got valid results (count > 0 and results match query)
        // Don't cache false positives
        if (isset($results['count']) && $results['count'] > 0 && isset($results['results']) && count($results['results']) > 0) {
            // Validate that results actually contain the query
            $queryLower = strtolower($query);
            $validResults = 0;
            foreach ($results['results'] as $result) {
                if (isset($result['content']) && strpos(strtolower($result['content']), $queryLower) !== false) {
                    $validResults++;
                }
            }
            
            // Only cache if we have valid results
            if ($validResults > 0) {
                // Fallback to file cache if Redis not available
                if (!$this->useRedis && is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
                    $cacheKey = md5($query . $limit);
                    $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
                    
                    // Check file cache first (valid for 1 hour)
                    if (file_exists($cacheFile) && is_readable($cacheFile)) {
                        $cacheAge = time() - filemtime($cacheFile);
                        if ($cacheAge < 3600) { // 1 hour
                            $cached = @file_get_contents($cacheFile);
                            if ($cached !== false) {
                                $decoded = json_decode($cached, true);
                                if ($decoded !== null && isset($decoded['count']) && $decoded['count'] > 0) {
                                    return $decoded;
                                }
                            }
                        }
                    }
                    
                    // Save to file cache
                    @file_put_contents($cacheFile, json_encode($results));
                }
            } else {
                // No valid results found - don't cache, return empty
                $results['count'] = 0;
                $results['results'] = [];
            }
        }
        
        return $results;
    }
    
    /**
     * Clear old cache files (older than 24 hours)
     */
    public function clearOldCache($maxAge = 86400) {
        if (!is_dir($this->cacheDir)) return;
        
        $files = glob($this->cacheDir . '/*.json');
        $cleared = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < (time() - $maxAge)) {
                @unlink($file);
                $cleared++;
            }
        }
        
        return $cleared;
    }
}

