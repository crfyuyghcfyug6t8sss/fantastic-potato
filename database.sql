-- ============================================================
-- Facebook Manager v2 — Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS fb_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fb_manager;

CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)  NOT NULL,
    phone      VARCHAR(20)   NOT NULL UNIQUE,
    password   VARCHAR(255)  NOT NULL,
    role       ENUM('admin','user') NOT NULL DEFAULT 'user',
    balance    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    points     INT UNSIGNED  NOT NULL DEFAULT 0,
    page_restricted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id       VARCHAR(50)   NOT NULL UNIQUE,
    page_name     VARCHAR(255)  NOT NULL,
    access_token  TEXT          NOT NULL,
    platform      ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
    instagram_id  VARCHAR(50)   NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_pages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    page_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_page (user_id, page_id),
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES pages(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform     ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
    access_token TEXT NOT NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    address     TEXT NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deposits (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    payment_method_id INT UNSIGNED NOT NULL,
    amount            DECIMAL(10,2) NOT NULL,
    receipt_image     VARCHAR(500),
    status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note        TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)           REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    page_id         VARCHAR(50)  NOT NULL,
    page_name       VARCHAR(255),
    post_id         VARCHAR(100) NOT NULL,
    post_message    TEXT,
    post_picture    TEXT,
    post_url        VARCHAR(500) NULL,
    campaign_name   VARCHAR(255) NOT NULL,
    objective       ENUM('followers','messages','engagement','visits','sales','video_views') NOT NULL DEFAULT 'engagement',
    gender          ENUM('all','male','female') NOT NULL DEFAULT 'all',
    age_min         TINYINT UNSIGNED NOT NULL DEFAULT 18,
    age_max         TINYINT UNSIGNED NOT NULL DEFAULT 65,
    locations       JSON NOT NULL,
    keywords        TEXT NULL,
    budget          DECIMAL(10,2) NOT NULL,
    duration_days   INT UNSIGNED NOT NULL DEFAULT 1,
    impressions     INT UNSIGNED NOT NULL DEFAULT 0,
    clicks          INT UNSIGNED NOT NULL DEFAULT 0,
    spend           DECIMAL(10,2) NOT NULL DEFAULT 0,
    results_note    TEXT NULL,
    fb_campaign_id  VARCHAR(100) NULL,
    last_insights_at DATETIME NULL,
    status          ENUM('pending','approved','rejected','running','paused','completed') NOT NULL DEFAULT 'pending',
    admin_note      TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(50)  NOT NULL UNIQUE,
    type         ENUM('percent','fixed') NOT NULL DEFAULT 'fixed',
    value        DECIMAL(10,2) NOT NULL,
    max_uses     INT UNSIGNED NOT NULL DEFAULT 0,
    used_count   INT UNSIGNED NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupon_user (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO users (name, phone, password, role)
VALUES ('Admin', '0000000000', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

INSERT IGNORE INTO payment_methods (name, description, address) VALUES
('تحويل بنكي', 'تحويل مباشر إلى الحساب البنكي', 'IBAN: SA00 0000 0000 0000 0000 0000'),
('PayPal', 'الدفع عبر PayPal', 'paypal@fbmanager.com');

-- ─── Site Settings ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS site_settings (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`     VARCHAR(100) NOT NULL UNIQUE,
    value     TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (`key`, value) VALUES
('site_name', 'FB Manager'),
('site_logo', NULL),
('support_whatsapp', ''),
('support_telegram', ''),
('support_form_url', ''),
('points_per_dollar', '1'),
('points_to_dollar', '100'),
('exchange_rate_usd_syp', '15000'),
('asset_version', UNIX_TIMESTAMP()),
('fb_ad_account_id', 'act_2573921513028991'),
('profit_margin_percent', '20');

-- ─── Page Link Requests ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS page_link_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    page_url     VARCHAR(500) NOT NULL,
    whatsapp     VARCHAR(30)  NOT NULL,
    platform     ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note   TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── OTP Codes (تخزين رموز التحقق في DB بدل Session) ──────────────────────
CREATE TABLE IF NOT EXISTS otp_codes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone      VARCHAR(20)  NOT NULL UNIQUE,
    code_hash  VARCHAR(255) NOT NULL DEFAULT '',
    expires_at DATETIME     NOT NULL,
    tries      TINYINT      NOT NULL DEFAULT 0,
    verified   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone_exp (phone, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── WhatsApp Messages Log ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_messages (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_type  ENUM('user','all') NOT NULL DEFAULT 'all',
    target_id    INT UNSIGNED NOT NULL DEFAULT 0,
    message      TEXT NOT NULL,
    status       ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    total_users  INT UNSIGNED NOT NULL DEFAULT 1,
    sent_count   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Instagram Support Migration ─────────────────────────────────────────────
-- Run these if upgrading from existing installation:
-- ALTER TABLE admin_tokens ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER id;
-- ALTER TABLE admin_tokens ADD UNIQUE KEY uq_platform (platform);
-- ALTER TABLE pages ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER access_token;
-- ALTER TABLE pages ADD COLUMN instagram_id VARCHAR(50) NULL AFTER platform;

-- ─── Mobile Optimization & UX Migration ──────────────────────────────────────
-- Run these if upgrading from a previous installation:
-- ALTER TABLE users ADD COLUMN points INT UNSIGNED NOT NULL DEFAULT 0 AFTER balance;
-- ALTER TABLE users ADD COLUMN page_restricted TINYINT(1) NOT NULL DEFAULT 0 AFTER points;
-- ALTER TABLE campaigns ADD COLUMN post_url VARCHAR(500) NULL AFTER post_picture;
-- ALTER TABLE campaigns MODIFY COLUMN objective ENUM('followers','messages','engagement','visits','sales','video_views') NOT NULL DEFAULT 'engagement';
-- ALTER TABLE campaigns ADD COLUMN keywords TEXT NULL AFTER locations;
-- ALTER TABLE campaigns ADD COLUMN duration_days INT UNSIGNED NOT NULL DEFAULT 1 AFTER budget;
-- ALTER TABLE campaigns ADD COLUMN impressions INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_days;
-- ALTER TABLE campaigns ADD COLUMN clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER impressions;
-- ALTER TABLE campaigns ADD COLUMN spend DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER clicks;
-- ALTER TABLE campaigns ADD COLUMN results_note TEXT NULL AFTER spend;
-- INSERT IGNORE INTO site_settings (`key`, value) VALUES
--   ('support_whatsapp', ''), ('support_telegram', ''), ('support_form_url', ''),
--   ('points_per_dollar', '1'), ('points_to_dollar', '100'),
--   ('exchange_rate_usd_syp', '15000');
