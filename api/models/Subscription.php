<?php
require_once __DIR__ . '/../config.php';

class Subscription {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    /**
     * Create a new subscription
     */
    public function create($userId, $planType, $duration, $price, $paymentStatus = 'pending') {
        // Calculate end date based on duration
        $durationMap = [
            '1_week' => 7,
            '1_month' => 30,
            '3_months' => 90,
            '6_months' => 180,
            '12_months' => 365
        ];
        
        $days = $durationMap[$duration] ?? 30;
        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        $stmt = $this->conn->prepare(
            "INSERT INTO subscriptions (user_id, plan_type, duration, price, payment_status, start_date, end_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("issdsss", $userId, $planType, $duration, $price, $paymentStatus, $startDate, $endDate);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get current subscription for user
     */
    public function getCurrent($userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM subscriptions 
             WHERE user_id = ? 
             AND payment_status = 'completed' 
             AND end_date > NOW() 
             ORDER BY created_at DESC 
             LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Update subscription status
     */
    public function updateStatus($subscriptionId, $paymentStatus) {
        $stmt = $this->conn->prepare(
            "UPDATE subscriptions SET payment_status = ? WHERE id = ?"
        );
        $stmt->bind_param("si", $paymentStatus, $subscriptionId);
        return $stmt->execute();
    }
    
    /**
     * Get pricing plans
     */
    public static function getPricingPlans() {
        return [
            'vip' => [
                ['duration' => '1_week', 'label' => '1 Week', 'price' => 25, 'savings' => null],
                ['duration' => '1_month', 'label' => '1 Month', 'price' => 50, 'savings' => null],
                ['duration' => '3_months', 'label' => '3 Months', 'price' => 99, 'savings' => 34],
                ['duration' => '6_months', 'label' => '6 Months', 'price' => 220, 'savings' => 27],
                ['duration' => '12_months', 'label' => '12 Months', 'price' => 390, 'savings' => 35]
            ],
            'api' => [
                ['duration' => '1_month', 'label' => '1 Month', 'price' => 99, 'savings' => null],
                ['duration' => '6_months', 'label' => '6 Months', 'price' => 499, 'savings' => 16],
                ['duration' => '12_months', 'label' => '12 Months', 'price' => 799, 'savings' => 33]
            ]
        ];
    }
}

