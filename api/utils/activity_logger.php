<?php
require_once __DIR__ . '/../config.php';

class ActivityLogger {
    private static $conn = null;
    
    private static function getConnection() {
        if (self::$conn === null) {
            self::$conn = getDBConnection();
        }
        return self::$conn;
    }
    
    public static function log($actionType, $description, $userId = null, $ipAddress = null) {
        try {
            $conn = self::getConnection();
            
            if ($ipAddress === null) {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action_type, description, ip_address) 
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->bind_param("isss", $userId, $actionType, $description, $ipAddress);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            // Don't throw - logging failures shouldn't break the application
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
}
?>

