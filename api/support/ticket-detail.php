<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (function_exists('setCORSHeaders')) {
    setCORSHeaders();
} else {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../models/Ticket.php';
    require_once __DIR__ . '/../utils/auth.php';
} catch (Exception $e) {
    error_log('Ticket detail - Config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

$ticketId = $_GET['id'] ?? null;

if (!$ticketId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ticket ID is required']);
    exit;
}

try {
    $userData = authenticate();
    $ticketModel = new Ticket();
    
    $ticket = $ticketModel->findById($ticketId);
    
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }
    
    // Check if user has access to this ticket
    if ($userData['role'] !== 'admin' && $ticket['user_id'] != $userData['userId']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
    
    // Get replies
    $replies = $ticketModel->getReplies($ticketId);
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'data' => [
                'ticket' => $ticket,
                'replies' => $replies
            ]
        ]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add reply
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Message is required']);
            exit;
        }
        
        // Sanitize message
        $message = trim($data['message']);
        if (empty($message)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
            exit;
        }
        
        // Use ticketId from URL parameter (already set above)
        $isAdmin = $userData['role'] === 'admin';
        
        try {
            $replyId = $ticketModel->addReply($ticketId, $userData['userId'], $message, $isAdmin);
            
            if ($replyId) {
                // Reload ticket with replies
                $replies = $ticketModel->getReplies($ticketId);
                $updatedTicket = $ticketModel->findById($ticketId);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Reply added successfully',
                    'data' => [
                        'ticket' => $updatedTicket,
                        'replies' => $replies
                    ]
                ]);
            } else {
                error_log('Failed to add reply - addReply returned false. TicketId: ' . $ticketId . ', UserId: ' . $userData['userId']);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to add reply. Please check server logs for details.']);
            }
        } catch (Exception $e) {
            error_log('Exception in addReply: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to add reply: ' . $e->getMessage()]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        // Update ticket (admin only for status/priority)
        if ($userData['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // ticketId can come from URL parameter (already set) or from body
        $actualTicketId = !empty($data['ticketId']) ? (int)$data['ticketId'] : $ticketId;
        
        if (!empty($data['status'])) {
            $ticketModel->updateStatus($actualTicketId, $data['status']);
        }
        
        if (!empty($data['priority'])) {
            $ticketModel->updatePriority($actualTicketId, $data['priority']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'data' => ['ticket' => $ticketModel->findById($actualTicketId)]
        ]);
    }
} catch (Exception $authError) {
    $statusCode = $authError->getCode() && $authError->getCode() >= 400 && $authError->getCode() < 600 ? $authError->getCode() : 401;
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $authError->getMessage()]);
} catch (Error $e) {
    error_log('Ticket detail - Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Operation failed. Please check server logs.']);
} catch (Exception $e) {
    error_log('Ticket detail - Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Operation failed. Please check server logs.']);
}

