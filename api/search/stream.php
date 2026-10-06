<?php
/**
 * Streaming Search Endpoint - DEPRECATED
 * 
 * This endpoint has been replaced with Load More pagination using search_after.
 * Streaming (SSE) is no longer used - all users now use regular search with pagination.
 * 
 * This file is kept for backward compatibility but should not be used.
 * All search requests should go through /api/search/index.php with search_after pagination.
 */

require_once __DIR__ . '/../config.php';

http_response_code(410); // Gone - endpoint deprecated
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'message' => 'Streaming search endpoint is deprecated. Please use regular search with Load More pagination.',
    'deprecated' => true
]);
exit;
