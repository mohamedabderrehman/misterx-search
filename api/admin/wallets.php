<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/CryptoWallet.php';
require_once __DIR__ . '/../utils/auth.php';

setCORSHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check if user is admin
try {
    $userData = authenticate();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

if ($userData['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$wallet = new CryptoWallet();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wallets = $wallet->getAll();
    
    echo json_encode([
        'success' => true,
        'data' => ['wallets' => $wallets]
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['cryptocurrency']) || empty($data['symbol']) || empty($data['wallet_address'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cryptocurrency, symbol, and wallet address are required']);
        exit;
    }
    
    $id = $wallet->create(
        $data['cryptocurrency'],
        $data['symbol'],
        $data['wallet_address'],
        $data['network'] ?? null,
        $data['logo_url'] ?? null,
        $data['qr_code_url'] ?? null,
        !empty($data['exchange_rate']) ? (float)$data['exchange_rate'] : null
    );
    
    if ($id) {
        echo json_encode([
            'success' => true,
            'message' => 'Wallet added successfully',
            'data' => ['id' => $id]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to add wallet']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Suppress errors to prevent HTML output
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }
        
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Wallet ID is required']);
            exit;
        }
        
        if (empty($data['cryptocurrency']) || empty($data['symbol']) || empty($data['wallet_address'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cryptocurrency, symbol, and wallet address are required']);
            exit;
        }
        
        // Validate and sanitize exchange_rate
        $exchangeRate = null;
        if (isset($data['exchange_rate']) && $data['exchange_rate'] !== '' && $data['exchange_rate'] !== null) {
            $exchangeRate = filter_var($data['exchange_rate'], FILTER_VALIDATE_FLOAT);
            if ($exchangeRate === false || $exchangeRate < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid exchange rate. Must be a positive number.']);
                exit;
            }
        }
        
        $success = $wallet->update(
            (int)$data['id'],
            trim($data['cryptocurrency']),
            trim($data['symbol']),
            trim($data['wallet_address']),
            isset($data['network']) && $data['network'] !== '' ? trim($data['network']) : null,
            isset($data['is_active']) ? (bool)$data['is_active'] : true,
            isset($data['logo_url']) && $data['logo_url'] !== '' ? trim($data['logo_url']) : null,
            isset($data['qr_code_url']) && $data['qr_code_url'] !== '' ? trim($data['qr_code_url']) : null,
            $exchangeRate
        );
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Wallet updated successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update wallet. Please check server logs for details.']);
        }
    } catch (Exception $e) {
        error_log('Wallet update error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating wallet: ' . $e->getMessage()]);
    } catch (Error $e) {
        error_log('Wallet update fatal error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal error updating wallet. Please check server logs.']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Try to get ID from query parameter first, then from body
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = isset($data['id']) ? intval($data['id']) : null;
    }
    
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wallet ID is required']);
        exit;
    }
    
    $success = $wallet->delete($id);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Wallet deleted successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete wallet']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

