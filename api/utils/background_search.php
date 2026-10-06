<?php
/**
 * Background Search Job Processor
 * This script processes search jobs in the background
 * Run via: php background_search.php <jobId>
 */

// Set unlimited execution time and memory
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '8G'); // Increased for large files (1GB+ files)

// Change to script directory
chdir(__DIR__);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/SearchJob.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/fast_search.php';

// Get job ID from command line
if ($argc < 2) {
    error_log('Background search: Job ID required');
    exit(1);
}

$jobId = $argv[1];

try {
    $searchJob = new SearchJob();
    $job = $searchJob->findByJobId($jobId);
    
    if (!$job) {
        error_log("Background search: Job not found: {$jobId}");
        exit(1);
    }
    
    // Update status to processing
    $searchJob->updateStatus($jobId, 'processing');
    
    // Perform search
    $fastSearch = new FastSearch();
    $searchResults = $fastSearch->searchWithCache($job['query'], $job['result_limit']);
    
    if (!isset($searchResults['count'])) {
        throw new Exception('Invalid search results format');
    }
    
    $resultsCount = $searchResults['count'];
    $searchTime = $searchResults['searchTime'] ?? 0;
    $searchMethod = $searchResults['method'] ?? 'ripgrep';
    
    // Save search to history
    $search = new Search();
    $searchHistoryId = $search->create($job['user_id'], $job['query'], $resultsCount, $job['subscription_type']);
    
    // Update job with results
    $searchJob->updateStatus(
        $jobId,
        'completed',
        $searchResults, // results_data
        $resultsCount,
        $searchTime,
        $searchMethod,
        null, // error_message
        $searchHistoryId
    );
    
    error_log("Background search completed: Job {$jobId}, Results: {$resultsCount}, Time: {$searchTime}ms");
    
} catch (Exception $e) {
    error_log("Background search error for job {$jobId}: " . $e->getMessage());
    
    // Update job status to failed
    if (isset($searchJob) && isset($jobId)) {
        $searchJob->updateStatus(
            $jobId,
            'failed',
            null,
            0,
            null,
            null,
            $e->getMessage()
        );
    }
    
    exit(1);
} catch (Error $e) {
    error_log("Background search fatal error for job {$jobId}: " . $e->getMessage());
    
    if (isset($searchJob) && isset($jobId)) {
        $searchJob->updateStatus(
            $jobId,
            'failed',
            null,
            0,
            null,
            null,
            'Fatal error: ' . $e->getMessage()
        );
    }
    
    exit(1);
}

exit(0);

