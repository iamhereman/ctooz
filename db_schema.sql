-- CTOOZ Bitcoin Payment Gateway Database Schema (MySQL / MariaDB)

CREATE TABLE IF NOT EXISTS `btc_addresses_pool` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bitcoin_address` VARCHAR(64) NOT NULL UNIQUE,
    `derivation_index` INT NOT NULL UNIQUE,
    `assigned_to_order_id` VARCHAR(64) DEFAULT NULL,
    `status` ENUM('free', 'pending', 'used') NOT NULL DEFAULT 'free',
    `expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status_expires` (`status`, `expires_at`),
    INDEX `idx_assigned_order` (`assigned_to_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `btc_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` VARCHAR(64) NOT NULL UNIQUE,
    `buyer_email` VARCHAR(255) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL DEFAULT 'CTOOZ Lifetime License',
    `price_usd` DECIMAL(10, 2) NOT NULL,
    `expected_sats` BIGINT NOT NULL,
    `expected_btc` DECIMAL(16, 8) NOT NULL,
    `btc_rate_usd` DECIMAL(12, 2) NOT NULL,
    `bitcoin_address` VARCHAR(64) NOT NULL,
    `derivation_index` INT NOT NULL,
    `received_sats` BIGINT NOT NULL DEFAULT 0,
    `txid` VARCHAR(128) DEFAULT NULL,
    `status` ENUM('pending', 'paid', 'expired') NOT NULL DEFAULT 'pending',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at` DATETIME DEFAULT NULL,
    INDEX `idx_order_addr` (`bitcoin_address`),
    INDEX `idx_order_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
