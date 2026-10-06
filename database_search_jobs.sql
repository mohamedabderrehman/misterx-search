-- Search Jobs Table for Background Processing
-- This table stores search jobs that run in the background
-- Run this SQL in phpMyAdmin to create the table

CREATE TABLE IF NOT EXISTS `search_jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_id` VARCHAR(64) UNIQUE NOT NULL,
  `user_id` INT NOT NULL,
  `query` VARCHAR(255) NOT NULL,
  `result_limit` INT NOT NULL DEFAULT 500,
  `subscription_type` ENUM('free', 'vip', 'api') DEFAULT 'free',
  `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
  `results_count` INT DEFAULT 0,
  `search_time` DECIMAL(10, 3) DEFAULT NULL,
  `search_method` VARCHAR(50) DEFAULT NULL,
  `results_data` LONGTEXT NULL,
  `error_message` TEXT NULL,
  `search_history_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`search_history_id`) REFERENCES `search_history`(`id`) ON DELETE SET NULL,
  INDEX `idx_job_id` (`job_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes for better performance
ALTER TABLE `search_jobs` ADD INDEX IF NOT EXISTS `idx_user_status` (`user_id`, `status`);
ALTER TABLE `search_jobs` ADD INDEX IF NOT EXISTS `idx_created_status` (`created_at`, `status`);

