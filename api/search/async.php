<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../models/SearchJob.php';
require_once __DIR__ . '/../utils/auth.php';

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['query'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Search query is required']);
    exit;
}

$query = trim($data['query']);
$userId = $userData['userId'];

// Get user subscription
$user = new User();
$userInfo = $user->findById($userId);
$subscriptionType = $userInfo['subscription_type'] ?? 'free';

// Check rate limits
$search = new Search();

if ($subscriptionType === 'free') {
    $todayCount = $search->getTodayCount($userId);
    if ($todayCount >= RATE_LIMIT_FREE_DAILY) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => "Daily search limit reached. You have used {$todayCount} searches today. Upgrade to VIP for unlimited searches."
        ]);
        exit;
    }
} else {
    $hourlyCount = $search->getHourlyCount($userId);
    $limit = $subscriptionType === 'vip' ? RATE_LIMIT_VIP_HOURLY : RATE_LIMIT_API_HOURLY;
    if ($hourlyCount >= $limit) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Hourly limit reached. Please try again later.'
        ]);
        exit;
    }
}

// Determine result limit based on subscription
$resultLimit = $subscriptionType === 'free' ? 500 : 1000000;

// Create search job
$searchJob = new SearchJob();
$job = $searchJob->create($userId, $query, $resultLimit, $subscriptionType);

if (!$job) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create search job'
    ]);
    exit;
}

// Start background process
$scriptPath = __DIR__ . '/../utils/background_search.php';
$jobIdEscaped = escapeshellarg($job['jobId']);
$phpPath = '/usr/bin/php'; // Adjust if different

// Check if PHP CLI is available
if (!file_exists($phpPath)) {
    $phpOutput = [];
    @exec('which php 2>/dev/null', $phpOutput);
    if (!empty($phpOutput[0])) {
        $phpPath = trim($phpOutput[0]);
    } else {
        // Fallback: try direct processing (will still be async via frontend polling)
        // The job is already created, frontend will poll and we can process it
        error_log("PHP CLI not found, job created but not started: {$job['jobId']}");
        
        // Return job ID - frontend will poll and we can process on-demand
        echo json_encode([
            'success' => true,
            'data' => [
                'jobId' => $job['jobId'],
                'status' => 'pending',
                'message' => 'Search job created. Processing will start shortly.'
            ]
        ]);
        exit;
    }
}

// Execute background search in detached process (non-blocking)
$command = sprintf(
    'cd %s && nohup %s %s %s > /dev/null 2>&1 & echo $!',
    escapeshellarg(dirname($scriptPath)),
    escapeshellarg($phpPath),
    escapeshellarg($scriptPath),
    $jobIdEscaped
);

// Try multiple methods to start background process
$processStarted = false;

// Method 1: exec with output
$output = [];
$returnCode = 0;
@exec($command, $output, $returnCode);

if ($returnCode === 0 && !empty($output)) {
    $processStarted = true;
} else {
    // Method 2: shell_exec
    $pid = @shell_exec($command);
    if (!empty($pid)) {
        $processStarted = true;
    } else {
        // Method 3: Use proc_open in background
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = @proc_open(
            sprintf('%s %s %s', escapeshellarg($phpPath), escapeshellarg($scriptPath), $jobIdEscaped),
            $descriptorspec,
            $pipes,
            dirname($scriptPath)
        );
        
        if (is_resource($process)) {
            // Close pipes immediately to detach
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            // Don't wait for process - it will run in background
            $processStarted = true;
        }
    }
}

if (!$processStarted) {
    error_log("Failed to start background process for job: {$job['jobId']}");
    // Job is created, frontend will poll and can trigger processing
}

// Return job ID immediately (no waiting)
echo json_encode([
    'success' => true,
    'data' => [
        'jobId' => $job['jobId'],
        'status' => 'pending',
        'message' => 'Search job created. Processing in background. Please check status in a moment.'
    ]
]);

