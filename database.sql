-- ==========================================
-- TRADING PLATFORM DATABASE SCHEMA
-- Compatible with MySQL 5.7+ / MariaDB 10.2+
-- PHP 7.4 / 8.3 Compatible
-- ==========================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Database Creation
CREATE DATABASE IF NOT EXISTS `trading_platform` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `trading_platform`;

-- ==========================================
-- TABLE: users
-- ==========================================
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `country` varchar(50) DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `language` enum('ar','en') DEFAULT 'ar',
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: admin_users
-- ==========================================
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','admin','moderator') DEFAULT 'admin',
  `permissions` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Admin Account (Password: Admin@123456)
INSERT INTO `admin_users` (`username`, `email`, `password`, `full_name`, `role`) VALUES
('admin', 'admin@platform.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Administrator', 'super_admin');

-- ==========================================
-- TABLE: trading_pairs
-- ==========================================
CREATE TABLE `trading_pairs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(20) NOT NULL,
  `base_currency` varchar(10) NOT NULL,
  `quote_currency` varchar(10) NOT NULL,
  `name_en` varchar(100) NOT NULL,
  `name_ar` varchar(100) NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `current_price` decimal(20,8) DEFAULT 0.00000000,
  `price_change_24h` decimal(10,2) DEFAULT 0.00,
  `volume_24h` decimal(20,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `min_trade_amount` decimal(15,2) DEFAULT 10.00,
  `max_trade_amount` decimal(15,2) DEFAULT 100000.00,
  `spot_enabled` tinyint(1) DEFAULT 1,
  `binary_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `symbol` (`symbol`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Trading Pairs
INSERT INTO `trading_pairs` (`symbol`, `base_currency`, `quote_currency`, `name_en`, `name_ar`, `spot_enabled`, `binary_enabled`) VALUES
('BTCUSDT', 'BTC', 'USDT', 'Bitcoin', 'بيتكوين', 1, 1),
('ETHUSDT', 'ETH', 'USDT', 'Ethereum', 'إيثريوم', 1, 1),
('BNBUSDT', 'BNB', 'USDT', 'Binance Coin', 'بينانس كوين', 1, 1),
('XRPUSDT', 'XRP', 'USDT', 'Ripple', 'ريبل', 1, 1),
('ADAUSDT', 'ADA', 'USDT', 'Cardano', 'كاردانو', 1, 1),
('SOLUSDT', 'SOL', 'USDT', 'Solana', 'سولانا', 1, 1),
('DOGEUSDT', 'DOGE', 'USDT', 'Dogecoin', 'دوجكوين', 1, 1),
('MATICUSDT', 'MATIC', 'USDT', 'Polygon', 'بوليجون', 1, 1);

-- ==========================================
-- TABLE: spot_trades
-- ==========================================
CREATE TABLE `spot_trades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pair_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `type` enum('buy','sell') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `current_price` decimal(20,8) DEFAULT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `quantity` decimal(20,8) NOT NULL,
  `total_cost` decimal(15,2) NOT NULL,
  `profit_loss` decimal(15,2) DEFAULT 0.00,
  `profit_loss_percentage` decimal(10,2) DEFAULT 0.00,
  `status` enum('open','closed','cancelled') DEFAULT 'open',
  `stop_loss` decimal(20,8) DEFAULT NULL,
  `take_profit` decimal(20,8) DEFAULT NULL,
  `leverage` decimal(5,2) DEFAULT 1.00,
  `fee` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pair_id` (`pair_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_spot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_pair` FOREIGN KEY (`pair_id`) REFERENCES `trading_pairs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: binary_trades
-- ==========================================
CREATE TABLE `binary_trades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pair_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `direction` enum('up','down') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `duration_seconds` int(11) NOT NULL DEFAULT 60,
  `expiry_time` timestamp NOT NULL,
  `payout_percentage` decimal(5,2) DEFAULT 85.00,
  `profit_loss` decimal(15,2) DEFAULT 0.00,
  `status` enum('pending','won','lost','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pair_id` (`pair_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expiry_time` (`expiry_time`),
  CONSTRAINT `fk_binary_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binary_pair` FOREIGN KEY (`pair_id`) REFERENCES `trading_pairs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: transactions
-- ==========================================
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('deposit','withdrawal','trade_profit','trade_loss','bonus','fee','refund') NOT NULL,
  `method` varchar(50) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'USD',
  `status` enum('pending','completed','failed','cancelled') DEFAULT 'pending',
  `reference_id` varchar(100) DEFAULT NULL,
  `payment_gateway` varchar(50) DEFAULT NULL,
  `payment_details` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_transaction_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: payment_gateways
-- ==========================================
CREATE TABLE `payment_gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name_en` varchar(100) NOT NULL,
  `display_name_ar` varchar(100) NOT NULL,
  `type` enum('bank_transfer','crypto','e_wallet','card') NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `min_deposit` decimal(15,2) DEFAULT 10.00,
  `max_deposit` decimal(15,2) DEFAULT 100000.00,
  `fee_percentage` decimal(5,2) DEFAULT 0.00,
  `fee_fixed` decimal(10,2) DEFAULT 0.00,
  `api_credentials` text DEFAULT NULL,
  `instructions_en` text DEFAULT NULL,
  `instructions_ar` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Payment Gateways
INSERT INTO `payment_gateways` (`name`, `display_name_en`, `display_name_ar`, `type`, `is_active`, `min_deposit`, `max_deposit`) VALUES
('bank_transfer', 'Bank Transfer', 'تحويل بنكي', 'bank_transfer', 1, 50.00, 50000.00),
('usdt_trc20', 'USDT (TRC20)', 'USDT (TRC20)', 'crypto', 1, 10.00, 100000.00),
('bitcoin', 'Bitcoin', 'بيتكوين', 'crypto', 1, 20.00, 100000.00),
('vodafone_cash', 'Vodafone Cash', 'فودافون كاش', 'e_wallet', 1, 10.00, 10000.00);

-- ==========================================
-- TABLE: price_history
-- ==========================================
CREATE TABLE `price_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `pair_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `open` decimal(20,8) NOT NULL,
  `high` decimal(20,8) NOT NULL,
  `low` decimal(20,8) NOT NULL,
  `close` decimal(20,8) NOT NULL,
  `volume` decimal(20,2) DEFAULT 0.00,
  `timestamp` timestamp NOT NULL,
  `interval` enum('1m','5m','15m','1h','4h','1d') DEFAULT '1m',
  PRIMARY KEY (`id`),
  KEY `idx_pair_id` (`pair_id`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_interval` (`interval`),
  CONSTRAINT `fk_price_pair` FOREIGN KEY (`pair_id`) REFERENCES `trading_pairs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: user_sessions
-- ==========================================
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: notifications
-- ==========================================
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('trade','transaction','system','security') NOT NULL,
  `title_en` varchar(200) NOT NULL,
  `title_ar` varchar(200) NOT NULL,
  `message_en` text NOT NULL,
  `message_ar` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- TABLE: settings
-- ==========================================
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` enum('text','number','boolean','json') DEFAULT 'text',
  `group` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Settings
INSERT INTO `settings` (`key`, `value`, `type`, `group`, `description`) VALUES
('site_name_en', 'Trading Platform', 'text', 'general', 'Site name in English'),
('site_name_ar', 'منصة التداول', 'text', 'general', 'Site name in Arabic'),
('site_logo', '/assets/logo.png', 'text', 'general', 'Site logo path'),
('default_language', 'ar', 'text', 'general', 'Default language'),
('maintenance_mode', '0', 'boolean', 'general', 'Maintenance mode'),
('registration_enabled', '1', 'boolean', 'users', 'Allow new registrations'),
('email_verification_required', '0', 'boolean', 'users', 'Require email verification'),
('min_deposit', '10', 'number', 'trading', 'Minimum deposit amount'),
('max_deposit', '100000', 'number', 'trading', 'Maximum deposit amount'),
('min_withdrawal', '20', 'number', 'trading', 'Minimum withdrawal amount'),
('max_withdrawal', '50000', 'number', 'trading', 'Maximum withdrawal amount'),
('spot_trading_enabled', '1', 'boolean', 'trading', 'Enable spot trading'),
('binary_trading_enabled', '1', 'boolean', 'trading', 'Enable binary options'),
('binary_min_duration', '60', 'number', 'trading', 'Minimum binary trade duration (seconds)'),
('binary_max_duration', '3600', 'number', 'trading', 'Maximum binary trade duration (seconds)'),
('binary_payout_percentage', '85', 'number', 'trading', 'Binary options payout percentage'),
('twelvedata_api_key', '', 'text', 'api', 'TwelveData API Key'),
('smtp_host', '', 'text', 'email', 'SMTP Host'),
('smtp_port', '587', 'number', 'email', 'SMTP Port'),
('smtp_username', '', 'text', 'email', 'SMTP Username'),
('smtp_password', '', 'text', 'email', 'SMTP Password'),
('support_email', 'support@platform.com', 'text', 'contact', 'Support email'),
('support_phone', '', 'text', 'contact', 'Support phone');

-- ==========================================
-- TABLE: activity_logs
-- ==========================================
CREATE TABLE `activity_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- INDEXES FOR PERFORMANCE
-- ==========================================
ALTER TABLE `spot_trades` ADD INDEX `idx_user_status` (`user_id`, `status`);
ALTER TABLE `binary_trades` ADD INDEX `idx_user_status` (`user_id`, `status`);
ALTER TABLE `transactions` ADD INDEX `idx_user_type` (`user_id`, `type`);

-- ==========================================
-- END OF DATABASE SCHEMA
-- ==========================================
