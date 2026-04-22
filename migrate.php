<?php
// ============================================================
// Idempotent schema migration for existing installations.
// Run once from the browser: https://yourdomain/migrate.php
// Safe to re-run — checks information_schema before each change.
// Delete this file after a successful run.
// ============================================================

define('ROOT', __DIR__);
require ROOT . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getDB();
$db  = DB_NAME;

function columnExists(PDO $pdo, string $db, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$db, $table, $column]);
    return (bool)$st->fetchColumn();
}

function tableExists(PDO $pdo, string $db, string $table): bool {
    $sql = "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$db, $table]);
    return (bool)$st->fetchColumn();
}

function runStep(string $label, callable $fn): void {
    try {
        $result = $fn();
        echo ($result === 'skip' ? '•' : '✓') . ' ' . $label .
             ($result === 'skip' ? ' (موجود مسبقاً)' : '') . "\n";
    } catch (Throwable $e) {
        echo '✗ ' . $label . ' — ' . $e->getMessage() . "\n";
    }
}

echo "=== بدء ترحيل قاعدة البيانات ===\n\n";

// ── users: points, page_restricted ────────────────────────────
runStep("users.points", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'users', 'points')) return 'skip';
    $pdo->exec("ALTER TABLE users ADD COLUMN points INT UNSIGNED NOT NULL DEFAULT 0 AFTER balance");
});
runStep("users.page_restricted", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'users', 'page_restricted')) return 'skip';
    $pdo->exec("ALTER TABLE users ADD COLUMN page_restricted TINYINT(1) NOT NULL DEFAULT 0 AFTER points");
});

// ── campaigns: new columns + objective enum ───────────────────
runStep("campaigns.post_url", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'post_url')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN post_url VARCHAR(500) NULL AFTER post_picture");
});
runStep("campaigns.keywords", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'keywords')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN keywords TEXT NULL AFTER locations");
});
runStep("campaigns.duration_days", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'duration_days')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN duration_days INT UNSIGNED NOT NULL DEFAULT 1 AFTER budget");
});
runStep("campaigns.impressions", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'impressions')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN impressions INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_days");
});
runStep("campaigns.clicks", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'clicks')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER impressions");
});
runStep("campaigns.spend", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'spend')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN spend DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER clicks");
});
runStep("campaigns.results_note", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'results_note')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN results_note TEXT NULL AFTER spend");
});
runStep("campaigns.fb_campaign_id", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'fb_campaign_id')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN fb_campaign_id VARCHAR(100) NULL AFTER results_note");
});
runStep("campaigns.last_insights_at", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'campaigns', 'last_insights_at')) return 'skip';
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN last_insights_at DATETIME NULL AFTER fb_campaign_id");
});
runStep("campaigns.objective enum", function () use ($pdo) {
    $pdo->exec("ALTER TABLE campaigns MODIFY COLUMN objective
                ENUM('followers','messages','engagement','visits','sales','video_views')
                NOT NULL DEFAULT 'engagement'");
});

// ── coupons + coupon_redemptions tables ───────────────────────
runStep("coupons table", function () use ($pdo, $db) {
    if (tableExists($pdo, $db, 'coupons')) return 'skip';
    $pdo->exec("CREATE TABLE coupons (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code         VARCHAR(50)  NOT NULL UNIQUE,
        type         ENUM('percent','fixed') NOT NULL DEFAULT 'fixed',
        value        DECIMAL(10,2) NOT NULL,
        max_uses     INT UNSIGNED NOT NULL DEFAULT 0,
        used_count   INT UNSIGNED NOT NULL DEFAULT 0,
        is_active    TINYINT(1)   NOT NULL DEFAULT 1,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});
runStep("coupon_redemptions table", function () use ($pdo, $db) {
    if (tableExists($pdo, $db, 'coupon_redemptions')) return 'skip';
    $pdo->exec("CREATE TABLE coupon_redemptions (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coupon_id   INT UNSIGNED NOT NULL,
        user_id     INT UNSIGNED NOT NULL,
        amount      DECIMAL(10,2) NOT NULL,
        consumed_at DATETIME NULL,
        consumed_in_campaign_id INT UNSIGNED NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coupon_user (coupon_id, user_id),
        FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});
runStep("coupon_redemptions.consumed_at", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'coupon_redemptions', 'consumed_at')) return 'skip';
    $pdo->exec("ALTER TABLE coupon_redemptions ADD COLUMN consumed_at DATETIME NULL AFTER amount");
    // Backfill: existing rows were all immediately consumed under old logic
    $pdo->exec("UPDATE coupon_redemptions SET consumed_at = created_at WHERE consumed_at IS NULL");
});
runStep("coupon_redemptions.consumed_in_campaign_id", function () use ($pdo, $db) {
    if (columnExists($pdo, $db, 'coupon_redemptions', 'consumed_in_campaign_id')) return 'skip';
    $pdo->exec("ALTER TABLE coupon_redemptions ADD COLUMN consumed_in_campaign_id INT UNSIGNED NULL AFTER consumed_at");
});

// ── site_settings rows ────────────────────────────────────────
runStep("site_settings new rows", function () use ($pdo) {
    $rows = [
        'support_whatsapp'       => '',
        'support_telegram'       => '',
        'support_form_url'       => '',
        'points_per_dollar'      => '1',
        'points_to_dollar'       => '100',
        'exchange_rate_usd_syp'  => '15000',
        'asset_version'          => (string) time(),
        'fb_ad_account_id'       => 'act_2573921513028991',
        'profit_margin_percent'  => '20',
        'min_daily_budget'       => '2',
        'min_total_budget'       => '7',
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO site_settings (`key`, value) VALUES (?, ?)");
    foreach ($rows as $k => $v) $st->execute([$k, $v]);
});

// ── rate_limits table ─────────────────────────────────────────
runStep("rate_limits table", function () use ($pdo, $db) {
    if (tableExists($pdo, $db, 'rate_limits')) return 'skip';
    $pdo->exec("CREATE TABLE rate_limits (
        bucket     VARCHAR(128) NOT NULL PRIMARY KEY,
        count      INT UNSIGNED NOT NULL DEFAULT 0,
        window_end INT UNSIGNED NOT NULL,
        INDEX idx_window_end (window_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

echo "\n=== اكتمل الترحيل ===\n";
echo "احذف ملف migrate.php من السيرفر الآن.\n";
