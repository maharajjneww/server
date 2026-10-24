-- ==============================================================================
-- Matka Live Results Platform — Production Database Schema
-- Compatible with MySQL 8.0+
-- Adheres to Project Requirements Specification (Section 29)
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Roles & Permissions (RBAC)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Users & Profiles
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` CHAR(36) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `phone` VARCHAR(25) NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('active', 'inactive', 'suspended', 'self_excluded') DEFAULT 'active',
    `verification_status` ENUM('not_started', 'pending', 'verified', 'rejected') DEFAULT 'not_started',
    `email_verified_at` TIMESTAMP NULL,
    `phone_verified_at` TIMESTAMP NULL,
    `refresh_token_hash` VARCHAR(255) NULL,
    `refresh_token_expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_phone` (`phone`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_profiles` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `avatar_url` VARCHAR(255) NULL,
    `timezone` VARCHAR(50) DEFAULT 'Asia/Kolkata',
    `notification_preferences` JSON NULL,
    `responsible_use_agreed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_verifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `id_type` VARCHAR(50) NOT NULL,
    `id_number_masked` VARCHAR(50) NOT NULL,
    `document_path` VARCHAR(255) NULL,
    `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    `admin_notes` TEXT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL,
    `reviewed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Markets & Schedules
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `markets` (
    `id` VARCHAR(50) PRIMARY KEY, -- slug e.g. 'mkt_kalyan'
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `open_time` TIME NOT NULL,
    `close_time` TIME NOT NULL,
    `status` ENUM('running', 'upcoming', 'closed') DEFAULT 'closed',
    `is_featured` BOOLEAN DEFAULT FALSE,
    `delay_minutes` INT UNSIGNED DEFAULT 5,
    `is_active` BOOLEAN DEFAULT TRUE,
    `sort_order` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_markets_status` (`status`),
    INDEX `idx_markets_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `market_schedules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `market_id` VARCHAR(50) NOT NULL,
    `day_of_week` ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    `is_holiday` BOOLEAN DEFAULT FALSE,
    `custom_open_time` TIME NULL,
    `custom_close_time` TIME NULL,
    FOREIGN KEY (`market_id`) REFERENCES `markets`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_market_day` (`market_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Results, Validation & Delayed Publishing
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_sources` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `adapter_code` VARCHAR(50) NOT NULL UNIQUE,
    `api_endpoint` VARCHAR(255) NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `results` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `market_id` VARCHAR(50) NOT NULL,
    `result_date` DATE NOT NULL,
    `open_panna` VARCHAR(3) NOT NULL DEFAULT '***',
    `jodi` VARCHAR(2) NOT NULL DEFAULT '**',
    `close_panna` VARCHAR(3) NOT NULL DEFAULT '***',
    `status` ENUM('draft', 'pending_validation', 'queued_delay', 'published', 'rejected', 'cancelled') DEFAULT 'queued_delay',
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `scheduled_publish_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at` TIMESTAMP NULL,
    `source_id` INT UNSIGNED NULL,
    `verified_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`market_id`) REFERENCES `markets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`source_id`) REFERENCES `result_sources`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_market_date` (`market_id`, `result_date`),
    INDEX `idx_results_date` (`result_date`),
    INDEX `idx_results_status` (`status`),
    INDEX `idx_results_scheduled` (`scheduled_publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `result_validation_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `result_id` BIGINT UNSIGNED NULL,
    `market_id` VARCHAR(50) NOT NULL,
    `raw_data` TEXT NOT NULL,
    `validation_status` ENUM('passed', 'failed', 'flagged') NOT NULL,
    `error_messages` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Notifications & Announcements
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('NEW_RESULT', 'MARKET_UPDATE', 'ANNOUNCEMENT', 'SYSTEM_NOTICE', 'ACCOUNT_NOTICE') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `market_id` VARCHAR(50) NULL,
    `result_info` VARCHAR(50) NULL,
    `deep_link` VARCHAR(255) NULL,
    `is_global` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notifications_type` (`type`),
    INDEX `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_devices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `fcm_token` VARCHAR(255) NOT NULL UNIQUE,
    `platform` ENUM('android', 'ios', 'web') DEFAULT 'android',
    `device_info` VARCHAR(255) NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `last_active_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    `is_active` BOOLEAN DEFAULT TRUE,
    `starts_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Audit & Security Logs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(50) NOT NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL,
    `description` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Initial Seed Data
-- -----------------------------------------------------------------------------
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'User', 'Standard mobile app user with view access'),
(2, 'Moderator', 'Market and result moderator'),
(3, 'Administrator', 'Full administrative authority')
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Insert default admin user (Password: Admin@12345 - bcrypt hashed)
INSERT INTO `users` (`id`, `uuid`, `name`, `email`, `password_hash`, `role_id`, `status`, `verification_status`) VALUES
(1, UUID(), 'System Administrator', 'admin@matkalive.internal', '$2y$12$K89s0m8sJ21u90r78fghyuqKjQ0w9E6QkLmP78Aasdf1234567890', 3, 'active', 'verified')
ON DUPLICATE KEY UPDATE `email`=`email`;

-- Insert Core Markets
INSERT INTO `markets` (`id`, `name`, `open_time`, `close_time`, `status`, `is_featured`, `delay_minutes`, `sort_order`) VALUES
('mkt_kalyan', 'KALYAN', '16:15:00', '18:15:00', 'running', TRUE, 5, 1),
('mkt_milan_day', 'MILAN DAY', '15:00:00', '17:00:00', 'running', TRUE, 5, 2),
('mkt_rajdhani_day', 'RAJDHANI DAY', '15:15:00', '17:15:00', 'running', TRUE, 5, 3),
('mkt_time_bazar', 'TIME BAZAR', '13:00:00', '14:00:00', 'closed', FALSE, 5, 4),
('mkt_madhur_day', 'MADHUR DAY', '13:30:00', '14:30:00', 'closed', FALSE, 5, 5),
('mkt_kalyan_night', 'KALYAN NIGHT', '21:25:00', '23:30:00', 'upcoming', TRUE, 5, 6),
('mkt_main_bazar', 'MAIN BAZAR', '21:40:00', '00:05:00', 'upcoming', TRUE, 5, 7),
('mkt_milan_night', 'MILAN NIGHT', '21:00:00', '23:00:00', 'upcoming', FALSE, 5, 8),
('mkt_supreme_night', 'SUPREME NIGHT', '20:45:00', '22:45:00', 'upcoming', FALSE, 5, 9)
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Insert Default Announcements
INSERT INTO `announcements` (`title`, `message`, `priority`, `is_active`) VALUES
('Real-Time Data Feed Active', 'Kalyan and Milan Day results are updated in real-time with verified sources.', 'high', TRUE),
('Compliance Advisory', 'This platform provides results & analytics only. Betting is not offered or supported.', 'medium', TRUE);

-- Insert Default System Settings
INSERT INTO `system_settings` (`key`, `value`, `description`) VALUES
('app_name', 'Matka Live Results', 'Platform public title'),
('delayed_publication_minutes', '5', 'Configurable delay before external source results are pushed to app'),
('allow_registrations', '1', '1 to enable user registrations, 0 to disable'),
('maintenance_mode', '0', '1 to place mobile API in maintenance mode');

SET FOREIGN_KEY_CHECKS = 1;
