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
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false, // set true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
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

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'FBManager/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => ['message' => 'cURL request failed']];
    }

    $data = json_decode($response, true);
    return $data ?: ['error' => ['message' => 'Invalid JSON response']];
}
