<?php
require_once __DIR__ . '/../config.php';

class CryptoWallet {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Get all active wallets
     */
    public function getAllActive() {
        $stmt = $this->conn->prepare(
            "SELECT * FROM crypto_wallets WHERE is_active = 1 ORDER BY cryptocurrency"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        
        $wallets = [];
        while ($row = $result->fetch_assoc()) {
            $wallets[] = $row;
        }
        
        return $wallets;
    }
    
    /**
     * Get all wallets (for admin)
     */
    public function getAll() {
        $result = $this->conn->query(
            "SELECT * FROM crypto_wallets ORDER BY cryptocurrency"
        );
        
        $wallets = [];
        while ($row = $result->fetch_assoc()) {
            $wallets[] = $row;
        }
        
        return $wallets;
    }
    
    /**
     * Get wallet by cryptocurrency
     */
    public function getByCrypto($cryptocurrency) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM crypto_wallets 
             WHERE cryptocurrency = ? AND is_active = 1 
             LIMIT 1"
        );
        $stmt->bind_param("s", $cryptocurrency);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Create a new wallet
     */
    public function create($cryptocurrency, $symbol, $walletAddress, $network = null, $logoUrl = null, $qrCodeUrl = null, $exchangeRate = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO crypto_wallets (cryptocurrency, symbol, wallet_address, network, logo_url, qr_code_url, exchange_rate) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssssd", $cryptocurrency, $symbol, $walletAddress, $network, $logoUrl, $qrCodeUrl, $exchangeRate);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update wallet
     */
    public function update($id, $cryptocurrency, $symbol, $walletAddress, $network = null, $isActive = true, $logoUrl = null, $qrCodeUrl = null, $exchangeRate = null) {
        // Sanitize and prepare values - convert empty strings to NULL
        $network = ($network === '' || $network === null) ? null : trim($network);
        $logoUrl = ($logoUrl === '' || $logoUrl === null) ? null : trim($logoUrl);
        $qrCodeUrl = ($qrCodeUrl === '' || $qrCodeUrl === null) ? null : trim($qrCodeUrl);
        $isActiveInt = $isActive ? 1 : 0;
        
        // Handle exchange_rate - convert to float if provided, otherwise NULL
        if ($exchangeRate === '' || $exchangeRate === null) {
            $exchangeRate = null;
        } else {
            $exchangeRate = (float)$exchangeRate;
            if ($exchangeRate < 0) {
                $exchangeRate = null;
            }
        }
        
        // Build SQL with NULL values directly in the query (MySQLi bind_param limitation)
        $setParts = ['cryptocurrency = ?', 'symbol = ?', 'wallet_address = ?'];
        $params = [$cryptocurrency, $symbol, $walletAddress];
        $types = "sss";
        
        // Handle network
        if ($network === null) {
            $setParts[] = 'network = NULL';
        } else {
            $setParts[] = 'network = ?';
            $params[] = $network;
            $types .= "s";
        }
        
        $setParts[] = 'is_active = ?';
        $params[] = $isActiveInt;
        $types .= "i";
        
        // Handle logo_url
        if ($logoUrl === null) {
            $setParts[] = 'logo_url = NULL';
        } else {
            $setParts[] = 'logo_url = ?';
            $params[] = $logoUrl;
            $types .= "s";
        }
        
        // Handle qr_code_url
        if ($qrCodeUrl === null) {
            $setParts[] = 'qr_code_url = NULL';
        } else {
            $setParts[] = 'qr_code_url = ?';
            $params[] = $qrCodeUrl;
            $types .= "s";
        }
        
        // Handle exchange_rate
        if ($exchangeRate === null) {
            $setParts[] = 'exchange_rate = NULL';
        } else {
            $setParts[] = 'exchange_rate = ?';
            $params[] = $exchangeRate;
            $types .= "d";
        }
        
        $setParts[] = 'updated_at = NOW()';
        $params[] = $id;
        $types .= "i";
        
        $sql = "UPDATE crypto_wallets SET " . implode(', ', $setParts) . " WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log('CryptoWallet update prepare error: ' . $this->conn->error);
            error_log('SQL: ' . $sql);
            return false;
        }
        
        // Bind parameters using call_user_func_array for dynamic binding
        $bindParams = array_merge([$types], $params);
        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        
        if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
            error_log('CryptoWallet update bind_param error');
            error_log('Types: ' . $types);
            error_log('Params count: ' . count($params));
            $stmt->close();
            return false;
        }
        
        $result = $stmt->execute();
        
        if (!$result) {
            error_log('CryptoWallet update execute error: ' . $stmt->error);
            error_log('SQL: ' . $sql);
            error_log('MySQL Error: ' . $this->conn->error);
            $stmt->close();
            return false;
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        return $affectedRows >= 0; // Return true even if 0 rows affected (no changes made)
    }
    
    /**
     * Delete wallet
     */
    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM crypto_wallets WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    
    /**
     * Toggle wallet active status
     */
    public function toggleActive($id) {
        $stmt = $this->conn->prepare(
            "UPDATE crypto_wallets 
             SET is_active = NOT is_active, updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}

