<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('operation_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once BASE_PATH . '/app/core.php';
require_once BASE_PATH . '/db.php';
if (!$conn->set_charset('utf8mb4')) throw new DatabaseException('Veritabanı karakter seti ayarlanamadı: ' . $conn->error);
require_once BASE_PATH . '/app/authorization.php';
require_once BASE_PATH . '/app/services.php';
