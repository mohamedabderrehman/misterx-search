<?php
/**
 * Search Diagnostic Script
 * This script tests search functionality to identify issues
 * Usage: php test_search.php <query>
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/fast_search.php';

// Get query from command line or use default
$query = isset($argv[1]) ? trim($argv[1]) : 'mamou022';
$limit = isset($argv[2]) ? (int)$argv[2] : 100;

echo "========================================\n";
echo "Search Diagnostic Script\n";
echo "========================================\n\n";

echo "Query: '{$query}'\n";
echo "Limit: {$limit}\n";
echo "Data Path: " . (defined('LEAKED_DATA_PATH') ? LEAKED_DATA_PATH : 'NOT DEFINED') . "\n\n";

// Test 1: Check if data path exists
echo "Test 1: Checking data path...\n";
$dataPath = defined('LEAKED_DATA_PATH') ? LEAKED_DATA_PATH : '/var/www/vhosts/mrx1.anondns.net/httpdocs/data';
if (is_dir($dataPath)) {
    echo "✓ Data path exists: {$dataPath}\n";
    echo "  Readable: " . (is_readable($dataPath) ? 'YES' : 'NO') . "\n";
} else {
    echo "✗ Data path does not exist: {$dataPath}\n";
    exit(1);
}
echo "\n";

// Test 2: Test ripgrep directly
echo "Test 2: Testing ripgrep directly...\n";
$rgPath = '/usr/bin/rg';
if (!file_exists($rgPath)) {
    $output = [];
    @exec('which rg 2>/dev/null', $output);
    if (!empty($output[0])) {
        $rgPath = trim($output[0]);
    }
}

if (file_exists($rgPath)) {
    echo "✓ ripgrep found: {$rgPath}\n";
    
    $sanitizedQuery = escapeshellarg($query);
    $command = sprintf(
        '%s -iF -n --max-count 5 --no-heading --no-filename --no-messages -g "*.txt" %s %s 2>/dev/null',
        escapeshellarg($rgPath),
        $sanitizedQuery,
        escapeshellarg($dataPath)
    );
    
    echo "Command: {$command}\n\n";
    
    $output = [];
    $returnCode = 0;
    @exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "✓ ripgrep executed successfully\n";
        echo "Results found: " . count($output) . "\n";
        if (count($output) > 0) {
            echo "First 3 results:\n";
            foreach (array_slice($output, 0, 3) as $idx => $line) {
                echo "  " . ($idx + 1) . ": " . substr($line, 0, 100) . (strlen($line) > 100 ? '...' : '') . "\n";
            }
        }
    } else {
        echo "✗ ripgrep failed with code: {$returnCode}\n";
    }
} else {
    echo "✗ ripgrep not found\n";
}
echo "\n";

// Test 3: Test FastSearch class
echo "Test 3: Testing FastSearch class...\n";
try {
    $fastSearch = new FastSearch();
    
    echo "Initializing search...\n";
    $startTime = microtime(true);
    $results = $fastSearch->search($query, $limit);
    $searchTime = (microtime(true) - $startTime) * 1000;
    
    echo "✓ Search completed in " . round($searchTime, 2) . "ms\n";
    echo "Results count: " . ($results['count'] ?? 'NOT SET') . "\n";
    echo "Results array count: " . (isset($results['results']) ? count($results['results']) : 'NOT SET') . "\n";
    echo "Method: " . ($results['method'] ?? 'NOT SET') . "\n\n";
    
    // Validate results
    if (isset($results['results']) && is_array($results['results'])) {
        echo "Validating results...\n";
        $queryLower = strtolower(trim($query));
        $validCount = 0;
        $invalidCount = 0;
        
        foreach ($results['results'] as $idx => $result) {
            if (isset($result['content'])) {
                $contentLower = strtolower($result['content']);
                if (strpos($contentLower, $queryLower) !== false) {
                    $validCount++;
                } else {
                    $invalidCount++;
                    if ($invalidCount <= 3) {
                        echo "  ✗ Invalid result #{$idx}: Does not contain '{$query}'\n";
                        echo "     Content: " . substr($result['content'], 0, 80) . "...\n";
                    }
                }
            }
        }
        
        echo "Valid results: {$validCount}\n";
        echo "Invalid results: {$invalidCount}\n";
        
        if ($invalidCount > 0 && $invalidCount <= 5) {
            echo "\nAll invalid results:\n";
            foreach ($results['results'] as $idx => $result) {
                if (isset($result['content'])) {
                    $contentLower = strtolower($result['content']);
                    if (strpos($contentLower, $queryLower) === false) {
                        echo "  #{$idx}: " . substr($result['content'], 0, 100) . "...\n";
                    }
                }
            }
        }
        
        // Show first 3 valid results
        echo "\nFirst 3 valid results:\n";
        $shown = 0;
        foreach ($results['results'] as $idx => $result) {
            if (isset($result['content'])) {
                $contentLower = strtolower($result['content']);
                if (strpos($contentLower, $queryLower) !== false && $shown < 3) {
                    echo "  " . ($shown + 1) . ": Line " . ($result['line'] ?? '?') . " - " . substr($result['content'], 0, 100) . "...\n";
                    $shown++;
                }
            }
        }
    } else {
        echo "✗ No results array found\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
echo "\n";

// Test 4: Test searchWithCache
echo "Test 4: Testing searchWithCache...\n";
try {
    $fastSearch = new FastSearch();
    
    echo "Initializing cached search...\n";
    $startTime = microtime(true);
    $results = $fastSearch->searchWithCache($query, $limit);
    $searchTime = (microtime(true) - $startTime) * 1000;
    
    echo "✓ Cached search completed in " . round($searchTime, 2) . "ms\n";
    echo "Results count: " . ($results['count'] ?? 'NOT SET') . "\n";
    echo "Results array count: " . (isset($results['results']) ? count($results['results']) : 'NOT SET') . "\n";
    echo "Method: " . ($results['method'] ?? 'NOT SET') . "\n";
    
    if (isset($results['method']) && strpos($results['method'], 'cached') !== false) {
        echo "⚠ Results came from cache!\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Check Redis cache
echo "Test 5: Checking Redis cache...\n";
if (extension_loaded('redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1);
        
        $cacheKey = 'search:' . md5($query . $limit);
        $cached = $redis->get($cacheKey);
        
        if ($cached !== false) {
            echo "⚠ Cache entry found for key: {$cacheKey}\n";
            if (is_array($cached)) {
                echo "  Cached count: " . ($cached['count'] ?? 'NOT SET') . "\n";
                echo "  Cached results: " . (isset($cached['results']) ? count($cached['results']) : 'NOT SET') . "\n";
            }
        } else {
            echo "✓ No cache entry found\n";
        }
    } catch (Exception $e) {
        echo "✗ Redis error: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ Redis extension not loaded\n";
}
echo "\n";

// Test 6: Test with non-existent query
echo "Test 6: Testing with non-existent query...\n";
$fakeQuery = 'jhgolijfgdwoigjdwrdu56486';
try {
    $fastSearch = new FastSearch();
    $results = $fastSearch->search($fakeQuery, 100);
    
    echo "Query: '{$fakeQuery}'\n";
    echo "Results count: " . ($results['count'] ?? 'NOT SET') . "\n";
    echo "Results array count: " . (isset($results['results']) ? count($results['results']) : 'NOT SET') . "\n";
    
    if (($results['count'] ?? 0) > 0) {
        echo "⚠ PROBLEM: Non-existent query returned " . ($results['count'] ?? 0) . " results!\n";
    } else {
        echo "✓ Correct: Non-existent query returned 0 results\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "========================================\n";
echo "Diagnostic complete!\n";
echo "========================================\n";

