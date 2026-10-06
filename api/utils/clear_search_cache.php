<?php
/**
 * Clear Search Cache Script
 * Run this to clear all cached search results
 * Usage: php clear_search_cache.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/fast_search.php';

echo "Clearing search cache...\n";

// Clear Redis cache (both ripgrep and OpenSearch)
if (extension_loaded('redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1);
        
        // Clear ripgrep cache (search:*)
        $keys = $redis->keys('search:*');
        $ripgrepCount = 0;
        if ($keys && count($keys) > 0) {
            foreach ($keys as $key) {
                $redis->del($key);
                $ripgrepCount++;
            }
        }
        
        // Clear OpenSearch cache (opensearch:*)
        $opensearchKeys = $redis->keys('opensearch:*');
        $opensearchCount = 0;
        if ($opensearchKeys && count($opensearchKeys) > 0) {
            foreach ($opensearchKeys as $key) {
                $redis->del($key);
                $opensearchCount++;
            }
        }
        
        $totalCleared = $ripgrepCount + $opensearchCount;
        if ($totalCleared > 0) {
            echo "Cleared Redis cache:\n";
            echo "  - ripgrep cache: {$ripgrepCount} entries\n";
            echo "  - OpenSearch cache: {$opensearchCount} entries\n";
            echo "  - Total: {$totalCleared} entries\n";
        } else {
            echo "No Redis cache entries found\n";
        }
    } catch (Exception $e) {
        echo "Redis error: " . $e->getMessage() . "\n";
    }
}

// Clear file cache
$cacheDir = __DIR__ . '/../../cache/search';
if (is_dir($cacheDir)) {
    $files = glob($cacheDir . '/*.json');
    $deleted = 0;
    foreach ($files as $file) {
        if (@unlink($file)) {
            $deleted++;
        }
    }
    echo "Cleared $deleted file cache entries\n";
} else {
    echo "Cache directory not found\n";
}

echo "Done!\n";

