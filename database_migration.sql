-- ==========================================
-- TRADING PLATFORM DATABASE MIGRATION
-- Run this after the initial database.sql
-- ==========================================

-- Add multi-asset support to trading_pairs
ALTER TABLE `trading_pairs` ADD COLUMN `asset_type` ENUM('crypto','forex','indices','stocks','commodities') DEFAULT 'crypto' AFTER `binary_enabled`;
ALTER TABLE `trading_pairs` ADD COLUMN `category` VARCHAR(50) DEFAULT 'Major' AFTER `asset_type`;
ALTER TABLE `trading_pairs` ADD COLUMN `country_flag` VARCHAR(10) DEFAULT NULL AFTER `category`;
ALTER TABLE `trading_pairs` ADD COLUMN `display_order` INT DEFAULT 0 AFTER `country_flag`;
ALTER TABLE `trading_pairs` ADD INDEX `idx_asset_type` (`asset_type`);

-- Add logo to payment_gateways
ALTER TABLE `payment_gateways` ADD COLUMN `logo` VARCHAR(255) DEFAULT NULL AFTER `icon`;
ALTER TABLE `payment_gateways` ADD COLUMN `account_info` TEXT DEFAULT NULL AFTER `logo`;

-- Expand payment_gateways type enum
ALTER TABLE `payment_gateways` MODIFY COLUMN `type` ENUM('bank_transfer','crypto','e_wallet','card','mobile_wallet','p2p') NOT NULL DEFAULT 'bank_transfer';

-- Add withdrawal support columns to transactions
ALTER TABLE `transactions` ADD COLUMN `gateway_id` INT DEFAULT NULL AFTER `payment_gateway`;
ALTER TABLE `transactions` ADD COLUMN `user_account_details` TEXT DEFAULT NULL AFTER `gateway_id`;
ALTER TABLE `transactions` ADD COLUMN `fee_amount` DECIMAL(15,2) DEFAULT 0.00 AFTER `user_account_details`;
ALTER TABLE `transactions` ADD COLUMN `net_amount` DECIMAL(15,2) DEFAULT 0.00 AFTER `fee_amount`;
ALTER TABLE `transactions` ADD COLUMN `proof_image` VARCHAR(255) DEFAULT NULL AFTER `net_amount`;
ALTER TABLE `transactions` ADD COLUMN `processed_by` INT DEFAULT NULL AFTER `proof_image`;
ALTER TABLE `transactions` ADD COLUMN `reject_reason` TEXT DEFAULT NULL AFTER `processed_by`;

-- Add KYC fields to users
ALTER TABLE `users` ADD COLUMN `kyc_status` ENUM('none','pending','approved','rejected') DEFAULT 'none' AFTER `is_verified`;
ALTER TABLE `users` ADD COLUMN `kyc_document` VARCHAR(255) DEFAULT NULL AFTER `kyc_status`;
ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(20) DEFAULT NULL AFTER `kyc_document`;
ALTER TABLE `users` ADD COLUMN `referred_by` INT DEFAULT NULL AFTER `referral_code`;
ALTER TABLE `users` ADD COLUMN `daily_withdrawal_limit` DECIMAL(15,2) DEFAULT 50000.00 AFTER `referred_by`;
ALTER TABLE `users` ADD INDEX `idx_referral_code` (`referral_code`);

-- Insert Forex trading pairs
INSERT INTO `trading_pairs` (`symbol`, `base_currency`, `quote_currency`, `name_en`, `name_ar`, `spot_enabled`, `binary_enabled`, `asset_type`, `category`, `country_flag`, `current_price`) VALUES
('EURUSD', 'EUR', 'USD', 'EUR/USD', 'يورو/دولار', 1, 1, 'forex', 'Major Pairs', NULL, 1.08500),
('GBPUSD', 'GBP', 'USD', 'GBP/USD', 'جنيه استرليني/دولار', 1, 1, 'forex', 'Major Pairs', NULL, 1.26800),
('USDJPY', 'USD', 'JPY', 'USD/JPY', 'دولار/ين ياباني', 1, 1, 'forex', 'Major Pairs', NULL, 149.50000),
('AUDUSD', 'AUD', 'USD', 'AUD/USD', 'دولار استرالي/دولار', 1, 1, 'forex', 'Major Pairs', NULL, 0.65700),
('USDCAD', 'USD', 'CAD', 'USD/CAD', 'دولار/دولار كندي', 1, 1, 'forex', 'Major Pairs', NULL, 1.36200)
ON DUPLICATE KEY UPDATE `asset_type` = 'forex';

-- Insert Indices trading pairs
INSERT INTO `trading_pairs` (`symbol`, `base_currency`, `quote_currency`, `name_en`, `name_ar`, `spot_enabled`, `binary_enabled`, `asset_type`, `category`, `current_price`) VALUES
('US30', 'US30', 'USD', 'Dow Jones', 'داو جونز', 0, 1, 'indices', 'US Indices', 38750.00000),
('NAS100', 'NAS100', 'USD', 'Nasdaq 100', 'ناسداك 100', 0, 1, 'indices', 'US Indices', 17250.00000),
('SPX500', 'SPX500', 'USD', 'S&P 500', 'إس آند بي 500', 0, 1, 'indices', 'US Indices', 5100.00000),
('UK100', 'UK100', 'GBP', 'FTSE 100', 'فوتسي 100', 0, 1, 'indices', 'European Indices', 7680.00000),
('GER40', 'GER40', 'EUR', 'DAX 40', 'داكس 40', 0, 1, 'indices', 'European Indices', 17900.00000)
ON DUPLICATE KEY UPDATE `asset_type` = 'indices';

-- Insert Stock trading pairs
INSERT INTO `trading_pairs` (`symbol`, `base_currency`, `quote_currency`, `name_en`, `name_ar`, `spot_enabled`, `binary_enabled`, `asset_type`, `category`, `current_price`) VALUES
('AAPL', 'AAPL', 'USD', 'Apple Inc.', 'أبل', 0, 1, 'stocks', 'Technology', 182.50000),
('GOOGL', 'GOOGL', 'USD', 'Alphabet Inc.', 'جوجل', 0, 1, 'stocks', 'Technology', 141.80000),
('MSFT', 'MSFT', 'USD', 'Microsoft Corp.', 'مايكروسوفت', 0, 1, 'stocks', 'Technology', 410.30000),
('TSLA', 'TSLA', 'USD', 'Tesla Inc.', 'تسلا', 0, 1, 'stocks', 'Technology', 175.20000),
('AMZN', 'AMZN', 'USD', 'Amazon.com Inc.', 'أمازون', 0, 1, 'stocks', 'Technology', 178.50000)
ON DUPLICATE KEY UPDATE `asset_type` = 'stocks';

-- Update existing crypto pairs to have asset_type
UPDATE `trading_pairs` SET `asset_type` = 'crypto', `category` = 'Major' WHERE `symbol` IN ('BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'SOLUSDT', 'DOGEUSDT', 'MATICUSDT');

-- Add additional settings
INSERT INTO `settings` (`key`, `value`, `type`, `group`, `description`) VALUES
('spot_trading_fee', '0.1', 'number', 'trading', 'Spot trading fee percentage'),
('forex_enabled', '1', 'boolean', 'trading', 'Enable forex trading'),
('indices_enabled', '1', 'boolean', 'trading', 'Enable indices trading'),
('stocks_enabled', '1', 'boolean', 'trading', 'Enable stocks trading'),
('daily_withdrawal_limit', '50000', 'number', 'trading', 'Daily withdrawal limit per user'),
('auto_approve_deposit_under', '0', 'number', 'trading', 'Auto-approve deposits under this amount (0=disabled)'),
('auto_approve_withdrawal_under', '0', 'number', 'trading', 'Auto-approve withdrawals under this amount (0=disabled)'),
('withdrawal_processing_hours', '24', 'number', 'trading', 'Withdrawal processing time in hours'),
('smtp_encryption', 'tls', 'text', 'email', 'SMTP encryption type'),
('smtp_from_email', '', 'text', 'email', 'From email address'),
('smtp_from_name', '', 'text', 'email', 'From name'),
('enable_2fa', '0', 'boolean', 'security', 'Enable 2FA for users'),
('require_email_verification', '0', 'boolean', 'security', 'Require email verification'),
('enable_kyc', '0', 'boolean', 'security', 'Enable KYC verification'),
('max_login_attempts', '5', 'number', 'security', 'Maximum login attempts'),
('session_timeout', '30', 'number', 'security', 'Session timeout in minutes'),
('log_all_activities', '1', 'boolean', 'security', 'Log all user activities'),
('maintenance_message_ar', 'الموقع تحت الصيانة حالياً. يرجى المحاولة لاحقاً.', 'text', 'general', 'Maintenance message Arabic'),
('maintenance_message_en', 'Site is currently under maintenance. Please try again later.', 'text', 'general', 'Maintenance message English'),
('platform_name_en', 'HeroTrade', 'text', 'general', 'Platform name English'),
('platform_name_ar', 'هيرو تريد', 'text', 'general', 'Platform name Arabic'),
('price_update_interval', '5', 'number', 'api', 'Price update interval in seconds'),
('api_plan', 'free', 'text', 'api', 'TwelveData API plan')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- ==========================================
-- END OF MIGRATION
-- ==========================================
