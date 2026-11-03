<?php
/**
 * Autentikasi & otorisasi berbasis session.
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('BHMSESS');
    session_start();
}

/** Alamat IP pengunjung (dipakai pembatas percobaan login). */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Berapa kali login gagal dari IP ini dalam 15 menit terakhir.
 * Mencegah penebakan kata sandi secara beruntun.
 */
function login_gagal_terakhir(): int
{
    return (int) scalar('SELECT COUNT(*) FROM login_gagal
                         WHERE ip = ? AND waktu > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
                        [client_ip()], 0);
}

const LOGIN_MAKS_GAGAL = 8;

/**
 * Login memakai nomor WhatsApp (utama) atau email (untuk akun admin lama).
 * @return string|null null bila sukses, atau pesan error.
 */
function attempt_login(string $identitas, string $password): ?string
{
    $identitas = trim($identitas);

    if (login_gagal_terakhir() >= LOGIN_MAKS_GAGAL) {
        return 'Terlalu banyak percobaan masuk yang gagal. Silakan coba lagi sekitar 15 menit.';
    }
    if (str_contains($identitas, '@')) {
        $u = one('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower($identitas)]);
    } else {
        $u = one('SELECT * FROM users WHERE no_hp = ? LIMIT 1', [normalize_hp($identitas)]);
    }
    if (!$u || !password_verify($password, $u['password'])) {
        insert('login_gagal', ['ip' => client_ip(), 'identitas' => mb_substr($identitas, 0, 40)]);
        // Bersihkan catatan lama sesekali agar tabel tidak menumpuk.
        if (random_int(1, 20) === 1) {
            q('DELETE FROM login_gagal WHERE waktu < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
        return 'Nomor WhatsApp atau kata sandi salah.';
    }
    if ($u['status'] !== 'aktif') {
        return 'Akun Anda dinonaktifkan. Hubungi pengelola.';
    }
    // Rehash bila cost berubah
    if (password_needs_rehash($u['password'], PASSWORD_BCRYPT)) {
        update('users', ['password' => password_hash($password, PASSWORD_BCRYPT)], 'id = ?', [$u['id']]);
    }
    // Login berhasil -> hapus catatan gagal dari IP ini.
    q('DELETE FROM login_gagal WHERE ip = ?', [client_ip()]);

    session_regenerate_id(true);
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['role'] = $u['role'];
    $_SESSION['nama'] = $u['nama'];
    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function is_login(): bool
{
    return !empty($_SESSION['uid']);
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

/** Data user aktif (di-cache per request). */
function current_user(): ?array
{
    static $u = null;
    if ($u !== null) return $u;
    if (!is_login()) return null;
    $u = one('SELECT * FROM users WHERE id = ? LIMIT 1', [$_SESSION['uid']]);
    if (!$u || $u['status'] !== 'aktif') {
        logout();
        return null;
    }
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('Silakan masuk terlebih dahulu.', 'warn');
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('403 - Akses khusus admin.');
    }
    return $u;
}

function require_user(): array
{
    $u = require_login();
    if ($u['role'] === 'admin') {
        redirect('admin/index.php');
    }
    return $u;
}
