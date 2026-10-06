<?php
/**
 * Bulk Indexing Script for OpenSearch
 * Indexes all data files into OpenSearch
 * 
 * Usage: php opensearch_indexer.php
 * 
 * This will take 12-24 hours for 800GB of data
 * Run it once, then all searches are instant!
 */

require_once __DIR__ . '/../config.php';

// Load Composer autoloader
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    die("Composer autoload not found. Make sure you ran 'composer install' in the project root.\n");
}

// Check if OpenSearch client is available
if (!class_exists('\OpenSearch\Client')) {
    die("OpenSearch PHP client not installed. Run: composer require opensearch-project/opensearch-php\n");
}

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

// Configuration
$dataPath = defined('LEAKED_DATA_PATH') ? LEAKED_DATA_PATH : '/var/www/vhosts/mrx1.anondns.net/httpdocs/data';
$indexName = 'leaked_data_v2'; // New index for remaining data (old index leaked_data has 11% and reached shard limit)
$batchSize = 10000; // Documents per batch (optimized for large files 1-4GB)
$bulkSize = 50; // MB per bulk request
$progressLogInterval = 20; // Log progress every N batches (reduces I/O overhead for huge files)
$logFile = __DIR__ . '/../../logs/indexing.log'; // Log file for progress tracking
$progressFile = __DIR__ . '/../../logs/indexing_progress.json'; // JSON file for progress

// Connect to OpenSearch
$client = ClientBuilder::create()
    ->setHosts(['http://127.0.0.1:9200'])
    ->build();

// Create logs directory if not exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Function to log messages
function logMessage($message, $toFile = true) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    echo $logEntry;
    if ($toFile) {
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Function to save progress
function saveProgress($filesProcessed, $totalFiles, $documentsIndexed, $startTime) {
    global $progressFile;
    $elapsed = microtime(true) - $startTime;
    $progress = [
        'files_processed' => $filesProcessed,
        'total_files' => $totalFiles,
        'documents_indexed' => $documentsIndexed,
        'progress_percent' => $totalFiles > 0 ? round(($filesProcessed / $totalFiles) * 100, 2) : 0,
        'elapsed_seconds' => round($elapsed, 2),
        'elapsed_human' => gmdate('H:i:s', $elapsed),
        'avg_time_per_file' => $filesProcessed > 0 ? round($elapsed / $filesProcessed, 2) : 0,
        'estimated_remaining_seconds' => $filesProcessed > 0 ? round((($totalFiles - $filesProcessed) * $elapsed) / $filesProcessed, 2) : 0,
        'estimated_remaining_human' => $filesProcessed > 0 ? gmdate('H:i:s', (($totalFiles - $filesProcessed) * $elapsed) / $filesProcessed) : 'N/A',
        'status' => 'running',
        'last_updated' => date('Y-m-d H:i:s')
    ];
    @file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT), LOCK_EX);
}

logMessage("========================================");
logMessage("OpenSearch Bulk Indexing Script");
logMessage("========================================");
logMessage("");

// Create index
logMessage("Step 1: Creating index...");
try {
    $indexParams = [
        'index' => $indexName,
        'body' => [
            'settings' => [
                'number_of_shards' => 5, // Multiple shards to avoid shard limit (2.1B docs per shard)
                'number_of_replicas' => 0, // No replicas during indexing (faster)
                'refresh_interval' => '-1', // Disable refresh during indexing (MUCH faster - will enable after completion)
                'index' => [
                    'translog' => [
                        'durability' => 'async', // Async translog for better performance
                        'sync_interval' => '30s'
                    ]
                ],
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'standard',
                            'stopwords' => '_none_'
                        ]
                    ]
                ]
            ],
            'mappings' => [
                'properties' => [
                    'content' => [
                        'type' => 'text',
                        'analyzer' => 'standard'
                    ],
                    'line_number' => [
                        'type' => 'integer'
                    ],
                    'file_path' => [
                        'type' => 'keyword'
                    ],
                    'file_name' => [
                        'type' => 'keyword'
                    ]
                ]
            ]
        ]
    ];
    
    // Check if index exists - if yes, continue indexing; if no, create it
    if ($client->indices()->exists(['index' => $indexName])) {
        logMessage("Index {$indexName} already exists. Will continue indexing...");
        logMessage("Note: Old index 'leaked_data' (11%) will be searched together with this index.");
    } else {
        $client->indices()->create($indexParams);
        logMessage("✓ Index created successfully");
    }
    logMessage("");
} catch (Exception $e) {
    die("✗ Error creating index: " . $e->getMessage() . "\n");
}

// Step 1.5: Get list of already indexed files from old index to avoid duplication
logMessage("Step 1.5: Checking already indexed files from 'leaked_data'...");
$alreadyIndexedFiles = [];
try {
    if ($client->indices()->exists(['index' => 'leaked_data'])) {
        // Get all unique file_path values from the old index
        $params = [
            'index' => 'leaked_data',
            'body' => [
                'size' => 0, // Don't return documents, only aggregations
                'aggs' => [
                    'indexed_files' => [
                        'terms' => [
                            'field' => 'file_path',
                            'size' => 10000 // Get up to 10k unique file paths
                        ]
                    ]
                ]
            ]
        ];
        
        $response = $client->search($params);
        
        if (isset($response['aggregations']['indexed_files']['buckets'])) {
            foreach ($response['aggregations']['indexed_files']['buckets'] as $bucket) {
                $alreadyIndexedFiles[] = $bucket['key'];
            }
            logMessage("✓ Found " . count($alreadyIndexedFiles) . " files already indexed in 'leaked_data'");
            logMessage("  These files will be skipped to avoid duplication.");
        } else {
            logMessage("⚠ Could not retrieve indexed files list from 'leaked_data'");
        }
    } else {
        logMessage("ℹ Old index 'leaked_data' does not exist. Starting fresh.");
    }
} catch (Exception $e) {
    logMessage("⚠ Error checking old index: " . $e->getMessage());
    logMessage("  Will continue without skipping files (may cause duplication).");
}
logMessage("");

// Find all .txt files
logMessage("Step 2: Finding all .txt files...");
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dataPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'txt') {
        $files[] = $file->getPathname();
    }
}

$originalFileCount = count($files);
logMessage("✓ Found {$originalFileCount} total .txt files");

// Filter out already indexed files to avoid duplication
if (!empty($alreadyIndexedFiles)) {
    $files = array_filter($files, function($filePath) use ($dataPath, $alreadyIndexedFiles) {
        $relativePath = str_replace($dataPath . '/', '', $filePath);
        // Normalize path separators for comparison
        $relativePath = str_replace('\\', '/', $relativePath);
        return !in_array($relativePath, $alreadyIndexedFiles);
    });
    $files = array_values($files); // Re-index array to maintain sequential keys
    $skippedCount = $originalFileCount - count($files);
    logMessage("✓ Filtered out {$skippedCount} already indexed files");
    logMessage("  Remaining files to index: " . count($files));
} else {
    logMessage("ℹ No files to skip (old index empty or not found)");
}

$totalFiles = count($files);
logMessage("");

// Index files
logMessage("Step 3: Indexing files...");
logMessage("This will take 12-24 hours for 800GB. Please be patient!");
logMessage("Progress will be saved to: {$progressFile}");
logMessage("Logs will be saved to: {$logFile}");
logMessage("");

$totalDocuments = 0;
$startTime = microtime(true);
$batch = [];
$batchCount = 0; // Counter for progress logging

foreach ($files as $fileIndex => $filePath) {
    try {
        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            echo "⚠ Cannot open file: {$filePath}\n";
            continue;
        }
        
        // Enable buffered reading for large files (1-4GB) - improves performance
        stream_set_read_buffer($fileHandle, 1024 * 1024); // 1MB buffer
        
        $lineNumber = 0;
        $fileName = basename($filePath);
        $relativePath = str_replace($dataPath . '/', '', $filePath);
        
        while (($line = fgets($fileHandle)) !== false) {
            $lineNumber++;
            $content = trim($line);
            
            if (empty($content)) {
                continue;
            }
            
            // Optimized string processing for large files
            // Only clean control characters if necessary (mb_convert_encoding is slow for millions of lines)
            // Most text files are already UTF-8, so skip conversion unless needed
            if (strpos($content, "\0") !== false || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
                $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
            }
            
            // Add to batch - use proper bulk format
            $batch[] = [
                'index' => [
                    '_index' => $indexName
                ]
            ];
            $batch[] = [
                'content' => $content,
                'line_number' => $lineNumber,
                'file_path' => $relativePath,
                'file_name' => $fileName
            ];
            
            // Bulk index when batch is full
            // Each document needs 2 entries: action (index) + source (data)
            if (count($batch) >= ($batchSize * 2)) {
                // OpenSearch bulk API expects array of alternating action and source
                // The format is already correct: [action, source, action, source, ...]
                $params = [
                    'body' => $batch,
                    'timeout' => '600s', // 10 minute timeout for very large batches (1-4GB files)
                    'client' => [
                        'curl' => [
                            CURLOPT_TIMEOUT => 600,
                            CURLOPT_TCP_KEEPALIVE => 1 // Keep connection alive for large transfers
                        ]
                    ]
                ];
                
                try {
                    $response = $client->bulk($params);
                    
                    if (isset($response['errors']) && $response['errors']) {
                        // Log detailed errors (but limit to first 5 errors per batch to avoid spam)
                        $errorCount = 0;
                        if (isset($response['items'])) {
                            foreach ($response['items'] as $item) {
                                if (isset($item['index']['error']) && $errorCount < 5) {
                                    $error = $item['index']['error'];
                                    logMessage("⚠ Bulk error: " . json_encode($error));
                                    $errorCount++;
                                }
                            }
                            if ($errorCount >= 5) {
                                logMessage("⚠ ... and more errors (showing first 5 only)");
                            }
                        }
                    }
                    
                    $totalDocuments += count($batch) / 2; // Divide by 2 (index action + source)
                    $batch = [];
                    $batchCount++;
                    
                    // Log progress only every N batches to reduce I/O overhead
                    if ($batchCount % $progressLogInterval == 0) {
                        $elapsed = microtime(true) - $startTime;
                        $filesProcessed = $fileIndex + 1;
                        $progress = ($filesProcessed / $totalFiles) * 100;
                        $avgTime = $filesProcessed > 0 ? $elapsed / $filesProcessed : 0;
                        $remaining = ($totalFiles - $filesProcessed) * $avgTime;
                        $eta = gmdate('H:i:s', $remaining);
                        
                        // Calculate indexing speed
                        $docsPerSecond = $totalDocuments / ($elapsed > 0 ? $elapsed : 1);
                        
                        $progressMsg = sprintf(
                            "Progress: %.2f%% | Files: %d/%d | Documents: %d | Speed: %.0f docs/s | ETA: %s",
                            $progress,
                            $filesProcessed,
                            $totalFiles,
                            $totalDocuments,
                            $docsPerSecond,
                            $eta
                        );
                        logMessage($progressMsg);
                        
                        // Save progress to JSON file
                        saveProgress($filesProcessed, $totalFiles, $totalDocuments, $startTime);
                    }
                } catch (Exception $e) {
                    logMessage("⚠ Bulk indexing error: " . $e->getMessage());
                    // Continue with next batch
                    $batch = [];
                }
            }
        }
        
        fclose($fileHandle);
        
    } catch (Exception $e) {
        logMessage("⚠ Error processing file {$filePath}: " . $e->getMessage());
        continue;
    }
}

// Index remaining batch
if (!empty($batch)) {
    try {
        $params = [
            'body' => $batch,
            'timeout' => '300s'
        ];
        $client->bulk($params);
        $totalDocuments += count($batch) / 2;
    } catch (Exception $e) {
        logMessage("⚠ Error indexing final batch: " . $e->getMessage());
    }
}

// Step 4: Enable refresh and optimize index
logMessage("");
logMessage("Step 4: Optimizing index (this may take a few minutes)...");

// Enable refresh interval (was disabled for faster indexing)
try {
    $client->indices()->putSettings([
        'index' => $indexName,
        'body' => [
            'settings' => [
                'refresh_interval' => '1s' // Enable refresh for search availability
            ]
        ]
    ]);
    logMessage("✓ Refresh interval enabled");
} catch (Exception $e) {
    logMessage("⚠ Error enabling refresh: " . $e->getMessage());
}

// Flush to ensure all data is written
try {
    $client->indices()->flush(['index' => $indexName]);
    logMessage("✓ Index flushed");
} catch (Exception $e) {
    logMessage("⚠ Error flushing index: " . $e->getMessage());
}

// Refresh index to make it searchable
try {
    $client->indices()->refresh(['index' => $indexName]);
    logMessage("✓ Index refreshed and ready for searches");
} catch (Exception $e) {
    logMessage("⚠ Error refreshing index: " . $e->getMessage());
}

logMessage("");

// Final stats
$endTime = microtime(true);
$totalTime = $endTime - $startTime;
$hours = floor($totalTime / 3600);
$minutes = floor(($totalTime % 3600) / 60);
$seconds = $totalTime % 60;

logMessage("========================================");
logMessage("Indexing Complete!");
logMessage("========================================");
logMessage("Total files: {$totalFiles}");
logMessage("Total documents: {$totalDocuments}");
logMessage("Total time: {$hours}h {$minutes}m " . round($seconds) . "s");
logMessage("Average: " . round($totalDocuments / $totalTime, 2) . " documents/second");
logMessage("");
logMessage("Index is ready for searches!");

// Update progress file with completion status
$finalProgress = [
    'files_processed' => $totalFiles,
    'total_files' => $totalFiles,
    'documents_indexed' => $totalDocuments,
    'progress_percent' => 100,
    'elapsed_seconds' => round($totalTime, 2),
    'elapsed_human' => gmdate('H:i:s', $totalTime),
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s'),
    'last_updated' => date('Y-m-d H:i:s')
];
@file_put_contents($progressFile, json_encode($finalProgress, JSON_PRETTY_PRINT), LOCK_EX);

