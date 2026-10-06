<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/SearchJob.php';
require_once __DIR__ . '/../utils/auth.php';

setCORSHeaders();
header('Content-Type: application/json');

// Disable output buffering for better performance
if (ob_get_level()) {
    ob_end_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $userData = authenticate();
} catch (Exception $e) {
    $statusCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}

if (empty($_GET['jobId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Job ID is required']);
    exit;
}

$jobId = trim($_GET['jobId']);
$userId = $userData['userId'];

$searchJob = new SearchJob();
$job = $searchJob->findByJobId($jobId);
// Enforce ownership before polling can trigger any processing side effect.
if ($job && (int)$job['user_id'] !== (int)$userId) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

// If job is pending and hasn't started, try to start it now
if ($job && $job['status'] === 'pending' && empty($job['started_at'])) {
    // Try to process the job now (in case background process didn't start)
    require_once __DIR__ . '/../utils/process_search_job.php';
    
    try {
        // Only process if it's been pending for more than 2 seconds
        $createdAt = strtotime($job['created_at']);
        $now = time();
        
        if (($now - $createdAt) >= 2) {
            // Process in background if possible, otherwise process synchronously
            $processor = new SearchJobProcessor();
            // Run in background using fastcgi_finish_request if available
            if (function_exists('fastcgi_finish_request')) {
                // Send response first, then process
                $job['status'] = 'processing';
                $searchJob->updateStatus($jobId, 'processing');
                
                // Process after response is sent
                register_shutdown_function(function() use ($processor, $jobId) {
                    try {
                        $processor->process($jobId);
                    } catch (Exception $e) {
                        error_log("Background job processing error: " . $e->getMessage());
                    }
                });
            } else {
                // Process synchronously (may take time but will complete)
                try {
                    $processor->process($jobId);
                    // Reload job data
                    $job = $searchJob->findByJobId($jobId);
                } catch (Exception $e) {
                    error_log("Job processing error: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error trying to process pending job: " . $e->getMessage());
    }
}

$job = $searchJob->findByJobId($jobId);

if (!$job) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Job not found'
    ]);
    exit;
}

// Verify job belongs to user
if ($job['user_id'] != $userId) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied'
    ]);
    exit;
}

// Prepare response
$response = [
    'success' => true,
    'data' => [
        'jobId' => $job['job_id'],
        'status' => $job['status'],
        'query' => $job['query'],
        'resultsCount' => (int)$job['results_count'],
        'searchTime' => $job['search_time'] ? (float)$job['search_time'] : null,
        'searchMethod' => $job['search_method'],
        'createdAt' => $job['created_at'],
        'completedAt' => $job['completed_at']
    ]
];

// If completed, include results (limit to prevent ERR_HTTP2_PROTOCOL_ERROR)
if ($job['status'] === 'completed' && !empty($job['results_data'])) {
    $resultsData = json_decode($job['results_data'], true);
    if ($resultsData && isset($resultsData['results'])) {
        // Limit results in response to prevent HTTP/2 errors (send max 1000 in response)
        // Full results are stored in database for download
        $limitedResults = array_slice($resultsData['results'], 0, 1000);
        $response['data']['results'] = $limitedResults;
        $response['data']['resultsLimited'] = count($resultsData['results']) > 1000;
        $response['data']['totalResultsAvailable'] = count($resultsData['results']);
        $response['data']['searchHistoryId'] = $job['search_history_id'];
        
        // Determine if user can download
        $canDownload = $job['subscription_type'] === 'vip' || $job['subscription_type'] === 'api';
        $response['data']['canDownload'] = $canDownload;
    }
}

// If failed, include error message
if ($job['status'] === 'failed' && !empty($job['error_message'])) {
    $response['data']['error'] = $job['error_message'];
}

echo json_encode($response);

