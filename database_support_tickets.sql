-- Support Tickets and Settings Tables
-- Run this SQL to add support system tables

-- Support Settings Table (for contact info)
CREATE TABLE IF NOT EXISTS `support_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(50) UNIQUE NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default support settings
INSERT INTO `support_settings` (`setting_key`, `setting_value`) VALUES
  ('support_email', 'support@misterx.com'),
  ('support_telegram', '@MisterXSupport')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Tickets Table
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket Replies Table
CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NULL,
  `is_admin` BOOLEAN DEFAULT FALSE,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add exchange_rate column to crypto_wallets table
ALTER TABLE `crypto_wallets` ADD COLUMN IF NOT EXISTS `exchange_rate` DECIMAL(20, 8) NULL DEFAULT NULL AFTER `qr_code_url`;

-- Update crypto_wallets with default exchange rates (example rates, admin should update these)
-- These are example rates - admin should update them regularly
UPDATE `crypto_wallets` SET `exchange_rate` = 45000.00 WHERE `cryptocurrency` = 'BTC' AND `exchange_rate` IS NULL;
UPDATE `crypto_wallets` SET `exchange_rate` = 2500.00 WHERE `cryptocurrency` = 'ETH' AND `exchange_rate` IS NULL;
UPDATE `crypto_wallets` SET `exchange_rate` = 1.00 WHERE `cryptocurrency` = 'USDT' AND `exchange_rate` IS NULL;
UPDATE `crypto_wallets` SET `exchange_rate` = 1.00 WHERE `cryptocurrency` = 'USDC' AND `exchange_rate` IS NULL;


