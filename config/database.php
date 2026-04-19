<?php
// config/database.php

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'u916622264_facebook');
define('DB_USER',    getenv('DB_USER')    ?: 'u916622264_facebook');
define('DB_PASS',    getenv('DB_PASS')    ?: 'Yazenstars1@@');
define('DB_CHARSET', 'utf8mb4');

define('ENCRYPT_KEY', getenv('ENCRYPT_KEY') ?: 'change-this-32-char-secret-key!!');
define('ENCRYPT_IV',  getenv('ENCRYPT_IV')  ?: '1234567890abcdef');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            // إرجاع JSON بدل HTML عند فشل الاتصال
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في الاتصال بقاعدة البيانات',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}
