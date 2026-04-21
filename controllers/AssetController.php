<?php
// controllers/AssetController.php
//
// Serves the main application bundle (private/app.js) only to browsers that:
//   • carry a session cookie bound to this origin (SameSite=Strict), AND
//   • send a Referer from the same host (hotlink protection).
//
// This blocks casual curl/cross-origin scraping of the client code even
// for anonymous visitors, while still letting the login page bootstrap.

class AssetController {

    public function appJs(): void {
        sessionStart();

        // Hotlink / embedding protection. A missing Referer on a sub-resource
        // load from our own page is unusual, so we require it.
        $host   = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $ref    = $_SERVER['HTTP_REFERER'] ?? '';
        $refHost = '';
        if ($ref !== '') {
            $p = parse_url($ref);
            $refHost = strtolower($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        if ($refHost === '' || $refHost !== $host) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo '// forbidden';
            exit;
        }

        // Per-IP throttle to deter enumeration even from within the app origin.
        if (rateLimitHit('asset:js:ip:' . clientIp(), 30, 60) < 0) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            header('Retry-After: 30');
            echo '// too many requests';
            exit;
        }

        $path = ROOT . '/private/app.js';
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '// not found';
            exit;
        }

        $etag  = '"' . substr(hash_file('sha256', $path), 0, 16) . '"';
        $mtime = filemtime($path);

        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        // Cleared Content-Type header from the JSON default in index.php.
        header_remove('Content-Type');
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: private, max-age=600, must-revalidate');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Referrer-Policy: same-origin');

        readfile($path);
        exit;
    }
}
