<?php
/**
 * Search Job Processor (for synchronous processing)
 * Used when background processing is not available
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/SearchJob.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/fast_search.php';

class SearchJobProcessor {
    
    public function process($jobId) {
        try {
            $searchJob = new SearchJob();
            $job = $searchJob->findByJobId($jobId);
            
            if (!$job) {
                throw new Exception("Job not found: {$jobId}");
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
                $searchResults,
                $resultsCount,
                $searchTime,
                $searchMethod,
                null,
                $searchHistoryId
            );
            
            return true;
            
        } catch (Exception $e) {
            error_log("Search job processor error for job {$jobId}: " . $e->getMessage());
            
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
            
            throw $e;
        }
    }
}

