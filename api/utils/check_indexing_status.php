<?php
/**
 * Check Indexing Status Script
 * Shows current progress of indexing
 * 
 * Usage: php check_indexing_status.php
 */

$progressFile = __DIR__ . '/../../logs/indexing_progress.json';
$logFile = __DIR__ . '/../../logs/indexing.log';

echo "========================================\n";
echo "Indexing Status Check\n";
echo "========================================\n\n";

// Check if indexing is running
if (file_exists($progressFile)) {
    $progress = json_decode(file_get_contents($progressFile), true);
    
    if ($progress) {
        echo "Status: " . strtoupper($progress['status']) . "\n";
        echo "Files Processed: {$progress['files_processed']} / {$progress['total_files']}\n";
        echo "Progress: {$progress['progress_percent']}%\n";
        echo "Documents Indexed: " . number_format($progress['documents_indexed']) . "\n";
        echo "Elapsed Time: {$progress['elapsed_human']}\n";
        
        if ($progress['status'] === 'running') {
            echo "Estimated Remaining: {$progress['estimated_remaining_human']}\n";
            echo "Average Time per File: " . round($progress['avg_time_per_file'], 2) . " seconds\n";
        }
        
        echo "Last Updated: {$progress['last_updated']}\n";
        
        if ($progress['status'] === 'completed') {
            echo "\n✓ Indexing completed at: {$progress['completed_at']}\n";
        }
    } else {
        echo "⚠ Progress file exists but is invalid\n";
    }
} else {
    echo "⚠ No indexing in progress\n";
    echo "Progress file not found: {$progressFile}\n";
}

// Show last 10 lines of log
echo "\n========================================\n";
echo "Recent Log Entries (last 10 lines):\n";
echo "========================================\n";

if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -10);
    foreach ($lastLines as $line) {
        echo $line;
    }
} else {
    echo "Log file not found: {$logFile}\n";
}

// Check OpenSearch index stats
echo "\n========================================\n";
echo "OpenSearch Index Stats:\n";
echo "========================================\n";

try {
    require_once __DIR__ . '/../config.php';
    
    // Load Composer autoloader
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }
    
    if (!class_exists('\OpenSearch\Client')) {
        echo "⚠ OpenSearch PHP client not installed\n";
    } else {

        
        $client = \OpenSearch\ClientBuilder::create()
            ->setHosts(['http://127.0.0.1:9200'])
            ->build();
        
        $indexName = defined('OPENSEARCH_INDEX') ? OPENSEARCH_INDEX : 'leaked_data';
        
        if ($client->indices()->exists(['index' => $indexName])) {
            $stats = $client->indices()->stats(['index' => $indexName]);
            $docCount = $stats['indices'][$indexName]['total']['docs']['count'] ?? 0;
            $size = $stats['indices'][$indexName]['total']['store']['size_in_bytes'] ?? 0;
            $sizeGB = round($size / 1024 / 1024 / 1024, 2);
            
            echo "Index Name: {$indexName}\n";
            echo "Documents in Index: " . number_format($docCount) . "\n";
            echo "Index Size: {$sizeGB} GB\n";
        } else {
            echo "⚠ Index '{$indexName}' does not exist yet\n";
        }
    }
} catch (Exception $e) {
    echo "⚠ Error checking OpenSearch: " . $e->getMessage() . "\n";
}

echo "\n";

