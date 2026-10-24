-- ==============================================================================
-- Virtual Wallet & Demo Wager System Migration
-- Compatible with MySQL 8.0+
-- Strict Non-Redeemable Simulation (Zero Real-Money Value)
-- ==============================================================================

USE matka_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Wallets
CREATE TABLE IF NOT EXISTS `wallets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `virtual_balance` BIGINT NOT NULL DEFAULT 0,
    `reserved_balance` BIGINT NOT NULL DEFAULT 0,
    `status` ENUM('active', 'locked', 'suspended') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_wallets_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Wallet Transactions (Immutable Ledger)
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `wallet_id` BIGINT UNSIGNED NOT NULL,
    `transaction_type` ENUM(
        'DEMO_INITIAL_CREDIT',
        'DEMO_CREDIT',
        'DEMO_WITHDRAWAL',
        'WAGER_DEBIT',
        'WAGER_RESERVE',
        'WAGER_RELEASE',
        'WINNING_CREDIT',
        'ADMIN_ADJUSTMENT',
        'WITHDRAWAL_REFUND'
    ) NOT NULL,
    `amount` BIGINT NOT NULL,
    `balance_before` BIGINT NOT NULL,
    `balance_after` BIGINT NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` VARCHAR(100) NULL,
    `status` ENUM('COMPLETED', 'PENDING', 'CANCELLED', 'FAILED') DEFAULT 'COMPLETED',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
    INDEX `idx_wallet_tx_user` (`user_id`),
    INDEX `idx_wallet_tx_wallet` (`wallet_id`),
    INDEX `idx_wallet_tx_type` (`transaction_type`),
    INDEX `idx_wallet_tx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Demo Wagers
CREATE TABLE IF NOT EXISTS `demo_wagers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `market_id` VARCHAR(50) NOT NULL,
    `wager_type` ENUM('single_ank', 'jodi', 'single_panna', 'double_panna', 'triple_panna') NOT NULL DEFAULT 'jodi',
    `selection` VARCHAR(20) NOT NULL,
    `wager_amount` BIGINT NOT NULL,
    `multiplier` DECIMAL(8,2) NOT NULL DEFAULT 90.00,
    `potential_win` BIGINT NOT NULL,
    `status` ENUM('PENDING', 'WON', 'LOST', 'CANCELLED', 'REFUNDED') DEFAULT 'PENDING',
    `result_id` BIGINT UNSIGNED NULL,
    `settlement_status` ENUM('UNSETTLED', 'SETTLED') DEFAULT 'UNSETTLED',
    `winning_amount` BIGINT NOT NULL DEFAULT 0,
    `idempotency_key` VARCHAR(100) NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `settled_at` TIMESTAMP NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`market_id`) REFERENCES `markets`(`id`) ON DELETE CASCADE,
    INDEX `idx_wagers_user` (`user_id`),
    INDEX `idx_wagers_market` (`market_id`),
    INDEX `idx_wagers_status` (`status`),
    INDEX `idx_wagers_settlement` (`settlement_status`),
    INDEX `idx_wagers_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Demo Withdrawals (Virtual Simulation Only)
CREATE TABLE IF NOT EXISTS `demo_withdrawals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `wallet_id` BIGINT UNSIGNED NOT NULL,
    `amount` BIGINT NOT NULL,
    `status` ENUM('PENDING', 'APPROVED', 'REJECTED', 'CANCELLED') DEFAULT 'PENDING',
    `admin_note` VARCHAR(255) NULL,
    `processed_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
    INDEX `idx_withdrawals_user` (`user_id`),
    INDEX `idx_withdrawals_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Add default settings for Demo Points
INSERT INTO `system_settings` (`key`, `value`, `description`)
VALUES 
    ('initial_demo_points', '10000', 'Default virtual demo points granted on user registration'),
    ('multiplier_single_ank', '9.00', 'Winning multiplier for Single Ank demo wagers (9x)'),
    ('multiplier_jodi', '90.00', 'Winning multiplier for Jodi demo wagers (90x)'),
    ('multiplier_single_panna', '140.00', 'Winning multiplier for Single Panna demo wagers (140x)'),
    ('multiplier_double_panna', '280.00', 'Winning multiplier for Double Panna demo wagers (280x)'),
    ('multiplier_triple_panna', '600.00', 'Winning multiplier for Triple Panna demo wagers (600x)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
