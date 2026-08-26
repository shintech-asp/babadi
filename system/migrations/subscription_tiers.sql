-- system/migrations/subscription_tiers.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/subscription_tiers.sql
--
-- NOTE: ADD COLUMN IF NOT EXISTS requires MariaDB 10.1+ (XAMPP ships MariaDB).
-- On stock MySQL 5.7/8.0, use the IGNORE_ADD_COLUMN approach below or run columns
-- individually after checking INFORMATION_SCHEMA first.
--
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS subscription_plans (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL DEFAULT 'Pro Plan',
    description   TEXT,
    monthly_price DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    yearly_price  DECIMAL(10,2) NOT NULL DEFAULT 5000.00,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO subscription_plans (name, description, monthly_price, yearly_price)
SELECT 'Pro Plan', 'Unlock all portal features: HR, Finance, CRM, Attendance and Biometrics', 500.00, 5000.00
WHERE NOT EXISTS (SELECT 1 FROM subscription_plans LIMIT 1);

-- On MariaDB (XAMPP default): ADD COLUMN IF NOT EXISTS is supported.
-- If any column already exists, MariaDB skips it safely.
ALTER TABLE provider_subscriptions
    ADD COLUMN IF NOT EXISTS plan_id       INT DEFAULT NULL AFTER provider_id,
    ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','yearly') DEFAULT 'monthly' AFTER plan_id,
    ADD COLUMN IF NOT EXISTS grace_ends_at DATETIME DEFAULT NULL AFTER expires_at;

-- M6: Add 'pro' to plan enum and 'grace' to status enum.
-- These values are required by the new subscription flow; missing them causes silent INSERT failures.
ALTER TABLE provider_subscriptions
    MODIFY COLUMN plan   ENUM('hr','finance','crm','bundle','pro') NOT NULL DEFAULT 'pro',
    MODIFY COLUMN status ENUM('active','expired','cancelled','pending','grace') DEFAULT 'pending';

ALTER TABLE providers
    ADD COLUMN IF NOT EXISTS office_lat DECIMAL(10,8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS office_lng DECIMAL(11,8) DEFAULT NULL;
