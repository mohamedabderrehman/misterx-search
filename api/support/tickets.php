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
    error_log('Support tickets - Config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new ticket
    try {
        $userData = authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['subject']) || empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subject and message are required']);
            exit;
        }
        
        $ticket = new Ticket();
        $ticketId = $ticket->create(
            $userData['userId'],
            $data['subject'],
            $data['message'],
            $data['priority'] ?? 'medium'
        );
        
        if ($ticketId) {
            echo json_encode([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => ['ticketId' => $ticketId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create ticket']);
        }
    } catch (Exception $authError) {
        $statusCode = $authError->getCode() && $authError->getCode() >= 400 && $authError->getCode() < 600 ? $authError->getCode() : 401;
        http_response_code($statusCode);
        echo json_encode(['success' => false, 'message' => $authError->getMessage()]);
    } catch (Exception $e) {
        error_log('Support tickets create - Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create ticket']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get tickets
    try {
        $userData = authenticate();
        $ticket = new Ticket();
        
        // Check if admin
        if ($userData['role'] === 'admin') {
            // Admin can see all tickets
            $filters = [];
            if (!empty($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (!empty($_GET['priority'])) {
                $filters['priority'] = $_GET['priority'];
            }
            if (!empty($_GET['limit'])) {
                $filters['limit'] = (int)$_GET['limit'];
            }
            
            $tickets = $ticket->getAll($filters);
        } else {
            // Regular users see only their tickets
            $tickets = $ticket->getByUserId($userData['userId']);
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['tickets' => $tickets]
        ]);
    } catch (Exception $authError) {
        $statusCode = $authError->getCode() && $authError->getCode() >= 400 && $authError->getCode() < 600 ? $authError->getCode() : 401;
        http_response_code($statusCode);
        echo json_encode(['success' => false, 'message' => $authError->getMessage()]);
    } catch (Exception $e) {
        error_log('Support tickets get - Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to load tickets']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

