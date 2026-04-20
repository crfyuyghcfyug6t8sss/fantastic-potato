<?php
define('ROOT', __DIR__);
require ROOT . '/config/database.php';
require ROOT . '/config/app.php';

// ─── Global error → JSON (منع HTML errors تُكسر الـ JSON) ────────────────────
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في السيرفر: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function (int $severity, string $msg, string $file, int $line) {
    throw new ErrorException($msg, 0, $severity, $file, $line);
});

ini_set('display_errors', '0');  // لا تعرض HTML errors أبداً
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');
    $seg = substr($uri, 5);

    $routes = [
        'GET:auth/me'                    => ['AuthController',  'me'],
        'POST:auth/send-otp'             => ['AuthController',  'sendOtp'],
        'POST:auth/verify-otp'           => ['AuthController',  'verifyOtp'],
        'POST:auth/verify-admin-otp'     => ['AuthController',  'verifyAdminOtp'],
        'POST:auth/complete-name'        => ['AuthController',  'completeName'],
        'POST:auth/login'                => ['AuthController',  'login'],
        'POST:auth/logout'               => ['AuthController',  'logout'],

        'POST:admin/token'               => ['AdminController', 'saveToken'],
        'GET:admin/token-status'         => ['AdminController', 'getTokenStatus'],
        'GET:admin/pages/fetch'          => ['AdminController', 'fetchPages'],
        'GET:admin/pages'                => ['AdminController', 'listPages'],
        'GET:admin/users'                => ['AdminController', 'listUsers'],
        'POST:admin/assign-page'         => ['AdminController', 'assignPage'],
        'POST:admin/revoke-page'         => ['AdminController', 'revokePage'],
        'GET:admin/user-pages'           => ['AdminController', 'userPages'],
        'GET:admin/stats'                => ['AdminController', 'stats'],
        'POST:admin/user-balance'        => ['AdminController', 'updateUserBalance'],

        'GET:admin/payment-methods'      => ['AdminController', 'listPaymentMethods'],
        'POST:admin/payment-methods'     => ['AdminController', 'savePaymentMethod'],
        'POST:admin/payment-methods/delete' => ['AdminController', 'deletePaymentMethod'],

        'GET:admin/deposits'             => ['AdminController', 'listDeposits'],
        'POST:admin/deposits/update'     => ['AdminController', 'updateDeposit'],

        'GET:admin/campaigns'            => ['AdminController', 'listCampaigns'],
        'POST:admin/campaigns/update'    => ['AdminController', 'updateCampaign'],
        'POST:admin/campaigns/results'   => ['AdminController', 'updateCampaignResults'],
        'POST:admin/campaigns/insights'  => ['AdminController', 'fetchInsights'],
        'POST:admin/bust-cache'          => ['AdminController', 'bustCache'],

        'GET:admin/coupons'              => ['AdminController', 'listCoupons'],
        'POST:admin/coupons'             => ['AdminController', 'saveCoupon'],
        'POST:admin/coupons/delete'      => ['AdminController', 'deleteCoupon'],

        'POST:admin/user-restrict'       => ['AdminController', 'setPageRestricted'],
        'POST:admin/user-points'         => ['AdminController', 'adjustPoints'],
        'GET:admin/accounting'           => ['AdminController', 'accounting'],
        'POST:admin/support-links'       => ['AdminController', 'saveSupportLinks'],

        'GET:user/pages'                 => ['UserController',  'myPages'],
        'GET:user/page-pic'              => ['UserController',  'getPagePicture'],
        'GET:user/page-posts'            => ['UserController',  'pagePosts'],
        'GET:user/campaigns'             => ['UserController',  'myCampaigns'],
        'GET:user/campaign-details'      => ['UserController',  'campaignDetails'],
        'POST:user/campaigns'            => ['UserController',  'createCampaign'],
        'POST:user/redeem-coupon'        => ['UserController',  'redeemCoupon'],
        'GET:user/active-coupon'         => ['UserController',  'activeCoupon'],
        'POST:user/convert-points'       => ['UserController',  'convertPoints'],
        'GET:user/payment-history'       => ['UserController',  'paymentHistory'],
        'GET:user/wallet'                => ['UserController',  'walletInfo'],
        'POST:user/deposit'              => ['UserController',  'submitDeposit'],
        'POST:user/link-request'         => ['UserController',  'submitLinkRequest'],
        'GET:user/site-settings'         => ['UserController',  'getSiteSettings'],

        'GET:admin/site-settings'        => ['AdminController', 'getSiteSettings'],
        'POST:admin/site-settings'       => ['AdminController', 'saveSiteSettings'],
        'GET:admin/link-requests'        => ['AdminController', 'listLinkRequests'],
        'POST:admin/link-requests/update'=> ['AdminController', 'updateLinkRequest'],
        'GET:admin/link-requests/count'  => ['AdminController', 'pendingLinkRequests'],

        'POST:admin/whatsapp/send-one'   => ['AdminController', 'waSendOne'],
        'POST:admin/whatsapp/broadcast'  => ['AdminController', 'waBroadcast'],
        'GET:admin/whatsapp/logs'        => ['AdminController', 'waLogs'],
    ];

    $key = $method . ':' . $seg;
    if (isset($routes[$key])) {
        [$ctrl, $action] = $routes[$key];
        require ROOT . '/controllers/' . $ctrl . '.php';
        (new $ctrl())->$action();
    } else {
        jsonError('Not Found', 404);
    }
    exit;
}

require ROOT . '/views/app.php';
