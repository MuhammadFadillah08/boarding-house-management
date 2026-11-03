<?php
/**
 * Konfigurasi utama Boarding House Management.
 *
 * Kredensial TIDAK disimpan di sini. Salin app/config.local.example.php menjadi
 * app/config.local.php lalu isi sesuai server Anda (file itu diabaikan git).
 * Tanpa config.local.php, aplikasi memakai bawaan XAMPP lokal (root, tanpa password).
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

// ---- Database -----------------------------------------------------------
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

$__lokal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true);

if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'boarding_house_management'); }
if (!defined('DB_USER')) { define('DB_USER', 'root'); }
if (!defined('DB_PASS')) { define('DB_PASS', ''); }
if (!defined('APP_DEBUG')) { define('APP_DEBUG', $__lokal); }   // wajib false saat live

// ---- Aplikasi -------------------------------------------------------------
define('APP_NAME', 'Boarding House');

/** Base URL otomatis: jalan di localhost maupun di hosting. */
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__base   = rtrim(str_replace('\\', '/', dirname(dirname(__FILE__))), '/');
$__docs   = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?: $__base)), '/');
$__path   = trim(str_replace($__docs, '', $__base), '/');
define('BASE_URL', $__scheme . '://' . $__host . ($__path ? '/' . $__path : '') . '/');
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/uploads/bukti/');
define('UPLOAD_URL', BASE_URL . 'uploads/bukti/');
define('KAMAR_DIR', BASE_PATH . '/uploads/kamar/');
define('KAMAR_URL', BASE_URL . 'uploads/kamar/');

define('MAX_UPLOAD', 2 * 1024 * 1024); // 2 MB (aman untuk InfinityFree)

date_default_timezone_set('Asia/Jakarta');

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}
