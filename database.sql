-- MisterX Database Schema
-- Run this SQL in phpMyAdmin to create all tables
-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `profile_picture` VARCHAR(500) NULL,
  `role` ENUM('normal', 'admin') DEFAULT 'normal',
  `subscription_type` ENUM('free', 'vip', 'api') DEFAULT 'free',
  `subscription_status` ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
  `subscription_expires_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_username` (`username`),
  INDEX `idx_subscription` (`subscription_type`, `subscription_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add profile_picture column if it doesn't exist (for existing databases)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `profile_picture` VARCHAR(500) NULL AFTER `password_hash`;

-- Search History Table
CREATE TABLE IF NOT EXISTS `search_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `search_query` VARCHAR(255) NOT NULL,
  `results_count` INT DEFAULT 0,
  `plan_type_at_search` ENUM('free', 'vip', 'api') DEFAULT 'free',
  `search_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_date` (`user_id`, `search_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscriptions Table
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_type` ENUM('vip', 'api') NOT NULL,
  `duration` ENUM('1_week', '1_month', '3_months', '6_months', '12_months') NOT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `payment_status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`payment_status`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Keys Table
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `api_key` VARCHAR(100) UNIQUE NOT NULL,
  `rate_limit` INT DEFAULT 1000,
  `requests_count` INT DEFAULT 0,
  `last_reset` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Requests Table
CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subscription_id` INT NULL,
  `plan_type` ENUM('vip', 'api') NOT NULL,
  `duration` ENUM('1_week', '1_month', '3_months', '6_months', '12_months') NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `cryptocurrency` VARCHAR(20) NOT NULL,
  `wallet_address` VARCHAR(255) NOT NULL,
  `amount_to_send` DECIMAL(20, 8) NOT NULL,
  `payment_address` VARCHAR(255) NULL,
  `transaction_hash` VARCHAR(255) NULL,
  `status` ENUM('pending', 'waiting', 'confirming', 'confirmed', 'approved', 'rejected') DEFAULT 'pending',
  `admin_notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crypto Wallets Table
CREATE TABLE IF NOT EXISTS `crypto_wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cryptocurrency` VARCHAR(20) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `wallet_address` VARCHAR(255) NOT NULL,
  `network` VARCHAR(50) NULL,
  `logo_url` VARCHAR(500) NULL,
  `qr_code_url` VARCHAR(500) NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_crypto` (`cryptocurrency`, `symbol`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add logo_url column if it doesn't exist (for existing databases)
ALTER TABLE `crypto_wallets` ADD COLUMN IF NOT EXISTS `logo_url` VARCHAR(500) NULL AFTER `network`;

-- Add qr_code_url column if it doesn't exist (for existing databases)
ALTER TABLE `crypto_wallets` ADD COLUMN IF NOT EXISTS `qr_code_url` VARCHAR(500) NULL AFTER `logo_url`;

-- Activity Logs Table
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `description` TEXT NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_action_type` (`action_type`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) UNIQUE NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('site_name', 'MisterX'),
  ('site_description', 'MisterX Platform'),
  ('recaptcha_site_key', '6LepK0YsAAAAAMglSQrzvXx2eCDuiVFQaQdPMhTe'),
  ('recaptcha_secret_key', '6LepK0YsAAAAAPJbX9FYGVnrwZa7VHWY0A_jhOaC'),
  ('free_rate_limit', '10'),
  ('vip_rate_limit', '100'),
  ('api_rate_limit', '60'),
  ('maintenance_mode', '0'),
  ('maintenance_message', 'Site is under maintenance. Please check back later.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Update api_keys table to add usage tracking columns if they don't exist
ALTER TABLE `api_keys` ADD COLUMN IF NOT EXISTS `usage_count` INT DEFAULT 0 AFTER `requests_count`;
ALTER TABLE `api_keys` ADD COLUMN IF NOT EXISTS `last_used_at` TIMESTAMP NULL AFTER `usage_count`;

