<?php
// config/app.php

define('APP_NAME', 'Wasel');
define('APP_URL',  getenv('APP_URL') ?: 'https://wasel.sy');
define('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v19.0');
define('SESSION_LIFETIME', 7200); // 2 hours

// ─── Encryption helpers ───────────────────────────────────────────────────────

function encryptToken(string $token): string {
    $key = substr(hash('sha256', ENCRYPT_KEY, true), 0, 32);
    $iv  = substr(hash('sha256', ENCRYPT_IV, true), 0, 16);
    $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($encrypted);
}

function decryptToken(string $encrypted): string {
    $key = substr(hash('sha256', ENCRYPT_KEY, true), 0, 32);
    $iv  = substr(hash('sha256', ENCRYPT_IV, true), 0, 16);
    $decoded = base64_decode($encrypted);
    return openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $iv);
}

// ─── Response helpers ─────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['success' => false, 'message' => $message], $code);
}

function jsonSuccess(array $data = [], string $message = 'success'): void {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

// ─── Session helpers ──────────────────────────────────────────────────────────

function sessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ─── Client IP (honours a single trusted proxy hop) ───────────────────────────

function clientIp(): string {
    // Only the first IP in X-Forwarded-For is semi-trusted. We fall back to
    // REMOTE_ADDR for anything unparseable. Never trust client-supplied IPs
    // for auth decisions, only for rate-limit bucketing.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
    }
    return $ip;
}

// ─── Rate limiter (fixed window, DB-backed) ───────────────────────────────────
//
// Returns remaining slots (>=0 means allowed, <0 means rejected). Buckets
// expire at `window_end`; a new window starts the next request.

function rateLimitHit(string $bucket, int $max, int $windowSec): int {
    try {
        $db  = getDB();
        $now = time();
        $key = substr($bucket, 0, 120);

        // Opportunistic GC (cheap, indexed)
        if (mt_rand(1, 50) === 1) {
            $db->prepare('DELETE FROM rate_limits WHERE window_end < ?')->execute([$now - 60]);
        }

        $db->beginTransaction();
        $sel = $db->prepare('SELECT count, window_end FROM rate_limits WHERE bucket=? FOR UPDATE');
        $sel->execute([$key]);
        $row = $sel->fetch();

        if (!$row || (int)$row['window_end'] <= $now) {
            $db->prepare(
                'REPLACE INTO rate_limits (bucket, count, window_end) VALUES (?, 1, ?)'
            )->execute([$key, $now + $windowSec]);
            $db->commit();
            return $max - 1;
        }

        $count = (int)$row['count'] + 1;
        $db->prepare('UPDATE rate_limits SET count=? WHERE bucket=?')->execute([$count, $key]);
        $db->commit();
        return $max - $count;
    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        // Fail-open on infrastructure error (better than locking users out);
        // the other limits (per-phone OTP, SameSite cookies, CSRF) still apply.
        return $max;
    }
}

function rateLimitOrFail(string $bucket, int $max, int $windowSec, string $msg): void {
    if (rateLimitHit($bucket, $max, $windowSec) < 0) {
        jsonError($msg, 429);
    }
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

function csrfToken(): string {
    sessionStart();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCheckOrFail(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $want  = $_SESSION['csrf'] ?? '';
    if (!$want || !is_string($token) || !hash_equals($want, $token)) {
        jsonError('طلب غير صالح (CSRF)', 403);
    }
}

// ─── Same-origin check (Origin/Referer) ───────────────────────────────────────

function sameOriginOrFail(): void {
    $host   = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $origin = $_SERVER['HTTP_ORIGIN']  ?? '';
    $ref    = $_SERVER['HTTP_REFERER'] ?? '';

    $hostOf = static function (string $u): string {
        $p = parse_url($u);
        return strtolower($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    };

    if ($origin !== '') {
        if ($hostOf($origin) !== $host) jsonError('Origin غير مسموح', 403);
        return;
    }
    if ($ref !== '') {
        if ($hostOf($ref) !== $host) jsonError('Referer غير مسموح', 403);
        return;
    }
    // Neither Origin nor Referer present on a state-changing request — reject.
    jsonError('طلب بدون مصدر', 403);
}

function currentUser(): ?array {
    sessionStart();
    return $_SESSION['user'] ?? null;
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) {
        jsonError('Unauthorized', 401);
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        jsonError('Forbidden', 403);
    }
    return $user;
}

function getProfitMarginPercent(): float {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $row = getDB()->query("SELECT value FROM site_settings WHERE `key`='profit_margin_percent' LIMIT 1")->fetch();
        $cached = $row ? max(0.0, (float)$row['value']) : 0.0;
    } catch (Throwable $e) {
        $cached = 0.0;
    }
    return $cached;
}

function applyMarginToSpend(float $realSpend): float {
    return round($realSpend * (1 + getProfitMarginPercent() / 100), 2);
}

// ─── Facebook Graph API ───────────────────────────────────────────────────────

/**
 * Fetch live insights for a Facebook campaign and persist totals to a
 * local campaign row. Returns ['ok' => bool, 'totals' => [...],
 * 'by_platform' => [...], 'error' => string].
 */
function fbFetchAndStoreInsights(int $localId, string $fbCampaignId, string $platform = 'all'): array {
    $db  = getDB();
    $row = $db->prepare("SELECT access_token FROM admin_tokens WHERE platform='facebook' LIMIT 1");
    $row->execute();
    $r = $row->fetch();
    if (!$r) return ['ok' => false, 'error' => 'لم يتم ضبط Access Token الخاص بالأدمن'];
    $token = decryptToken($r['access_token']);

    $params = [
        'fields'     => 'impressions,clicks,spend,cpc,ctr',
        'breakdowns' => 'publisher_platform',
        'level'      => 'campaign',
    ];
    if (in_array($platform, ['facebook','instagram'], true)) {
        $params['filtering'] = json_encode([[
            'field' => 'publisher_platform', 'operator' => 'IN', 'value' => [$platform],
        ]]);
    }

    $resp = fbGet('/' . $fbCampaignId . '/insights', $token, $params);
    if (isset($resp['error'])) {
        return ['ok' => false, 'error' => $resp['error']['message'] ?? 'خطأ من فيسبوك'];
    }

    $rows       = $resp['data'] ?? [];
    $byPlatform = [];
    $totals     = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];
    foreach ($rows as $r2) {
        $imp = (int)   ($r2['impressions'] ?? 0);
        $clk = (int)   ($r2['clicks']      ?? 0);
        $spd = (float) ($r2['spend']       ?? 0);
        $byPlatform[] = [
            'platform'    => $r2['publisher_platform'] ?? 'unknown',
            'impressions' => $imp,
            'clicks'      => $clk,
            'spend'       => $spd,
            'cpc'         => (float)($r2['cpc'] ?? 0),
            'ctr'         => (float)($r2['ctr'] ?? 0),
        ];
        $totals['impressions'] += $imp;
        $totals['clicks']      += $clk;
        $totals['spend']       += $spd;
    }
    $totals['ctr'] = $totals['impressions'] > 0
        ? round(($totals['clicks'] / $totals['impressions']) * 100, 2) : 0.0;
    $totals['cpc'] = $totals['clicks'] > 0
        ? round($totals['spend'] / $totals['clicks'], 2) : 0.0;

    if ($localId > 0) {
        $db->prepare(
            'UPDATE campaigns SET impressions=?, clicks=?, spend=?, fb_campaign_id=?, last_insights_at=NOW() WHERE id=?'
        )->execute([
            $totals['impressions'], $totals['clicks'], $totals['spend'],
            $fbCampaignId, $localId,
        ]);
    }

    return ['ok' => true, 'totals' => $totals, 'by_platform' => $byPlatform];
}

function fbGet(string $endpoint, string $token, array $params = []): array {
    $params['access_token'] = $token;
    $url = FACEBOOK_GRAPH_URL . $endpoint . '?' . http_build_query($params);

    // Retry transient network failures (DNS, TCP/SSL reset, timeouts).
    // HTTP-level errors (4xx/5xx with JSON body) are returned as-is so the
    // caller can surface Facebook's real message.
    $attempts   = 3;
    $lastError  = 'cURL request failed';
    $backoffMs  = 300;

    for ($i = 0; $i < $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FBManager/1.0',
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $response !== '') {
            $data = json_decode($response, true);
            return $data ?: ['error' => ['message' => 'Invalid JSON response']];
        }

        $lastError = $errstr ?: 'cURL request failed';

        // Only retry for known-transient errno values.
        $transient = in_array($errno, [
            CURLE_COULDNT_RESOLVE_HOST,      // 6
            CURLE_COULDNT_CONNECT,           // 7
            CURLE_OPERATION_TIMEOUTED,       // 28
            CURLE_SSL_CONNECT_ERROR,         // 35
            CURLE_GOT_NOTHING,               // 52
            CURLE_SEND_ERROR,                // 55
            CURLE_RECV_ERROR,                // 56
        ], true);

        if (!$transient || $i === $attempts - 1) break;

        usleep($backoffMs * 1000);
        $backoffMs *= 2;
    }

    return ['error' => ['message' => 'تعذّر الاتصال بـ Facebook حالياً، يرجى المحاولة بعد قليل. (' . $lastError . ')']];
}
