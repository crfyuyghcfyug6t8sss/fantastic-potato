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
