<?php
/**
 * Fungsi bantu umum: escaping, format, flash message, CSRF, upload.
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/** Escape output HTML. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * URL aset + penanda versi dari waktu ubah berkas.
 * Tanpa ini, pengunjung lama bisa memakai CSS/JS usang karena cache browser
 * (.htaccess menyimpan aset statis selama beberapa hari).
 */
function asset(string $path): string
{
    $file = BASE_PATH . '/' . ltrim($path, '/');
    $ver  = is_file($file) ? filemtime($file) : time();
    return url($path) . '?v=' . $ver;
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^https?://~', $path) ? $path : url($path)));
    exit;
}

/**
 * Bangun ulang query string HANYA dari parameter yang diizinkan.
 * Dipakai saat mengarahkan kembali setelah POST — meneruskan
 * $_SERVER['QUERY_STRING'] mentah ikut membawa parameter asing dari URL.
 * Mengembalikan "" bila tidak ada yang cocok, atau "?a=1&b=2".
 */
function filter_qs(array $izinkan): string
{
    $out = [];
    foreach ($izinkan as $k) {
        $v = get($k);
        if ($v !== '') $out[$k] = $v;
    }
    return $out ? '?' . http_build_query($out) : '';
}

/** Rp 1.250.000 — nilai negatif ditulis -Rp 1.250.000 */
function rp($n): string
{
    $n = (float) $n;
    return ($n < 0 ? '-Rp ' : 'Rp ') . number_format(abs($n), 0, ',', '.');
}

/** 05 Agu 2026 */
function tgl(?string $date, bool $withTime = false): string
{
    if (!$date || str_starts_with($date, '0000')) return '-';
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts  = strtotime($date);
    $out = date('d', $ts) . ' ' . $bln[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    return $withTime ? $out . ' ' . date('H:i', $ts) : $out;
}

/**
 * Validasi periode "YYYY-MM".
 * Regex \d{2} saja tidak cukup: "2026-13" lolos, lalu strtotime() mengembalikan
 * false dan date('Y-m-t', false) melempar TypeError di PHP 8.
 *
 * @return string|null periode yang sah, atau null bila tidak valid
 */
function periode_valid(?string $p): ?string
{
    if (!$p || !preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return null;
    $th = (int) $m[1];
    $bl = (int) $m[2];
    if ($bl < 1 || $bl > 12 || $th < 2000 || $th > 2100) return null;
    return $p;
}

/** Periode yang sah, atau bulan berjalan sebagai cadangan. */
function periode_atau_kini(?string $p): string
{
    return periode_valid($p) ?? date('Y-m');
}

/** 2026-08 -> Agustus 2026 */
function periode_label(string $periode): string
{
    $bln = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    [$y, $m] = array_pad(explode('-', $periode), 2, '1');
    return ($bln[(int) $m] ?? '?') . ' ' . $y;
}

/** Normalisasi nomor HP ke format internasional tanpa +  (08xx -> 628xx). */
function normalize_hp(string $hp): string
{
    $hp = preg_replace('/\D+/', '', $hp) ?? '';
    if (str_starts_with($hp, '0'))   return '62' . substr($hp, 1);
    if (str_starts_with($hp, '620')) return '62' . substr($hp, 3);
    if (str_starts_with($hp, '8'))   return '62' . $hp;
    return $hp;
}

// ---------------------------------------------------------------------
// Flash message
// ---------------------------------------------------------------------
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function render_flash(): string
{
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg'])
               . '<span class="alert-x" onclick="this.parentNode.remove()">&times;</span></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

/** Wajib dipanggil di awal setiap handler POST. */
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Sesi kedaluwarsa atau token tidak valid. Silakan muat ulang halaman.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function get(string $key, $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

// ---------------------------------------------------------------------
// Upload gambar (bukti bayar / foto kamar)
// ---------------------------------------------------------------------
/**
 * @return array{0:bool,1:string} [sukses, namafile|pesanError]
 */
function upload_image(string $field, string $destDir, string $prefix = 'img'): array
{
    if (empty($_FILES[$field]['name'])) {
        return [false, 'Tidak ada berkas yang dipilih.'];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Gagal mengunggah berkas (kode ' . $f['error'] . ').'];
    }
    if ($f['size'] > MAX_UPLOAD) {
        return [false, 'Ukuran berkas maksimal ' . round(MAX_UPLOAD / 1048576, 1) . ' MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        return [false, 'Format harus JPG, PNG, atau WEBP.'];
    }
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }
    $name = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $destDir . $name)) {
        return [false, 'Berkas gagal disimpan di server.'];
    }
    return [true, $name];
}

/** Kode unik: INV-202608-0007 */
function kode_unik(string $prefix, string $table, string $col = 'kode'): string
{
    $ym  = date('Ym');
    $n   = (int) scalar("SELECT COUNT(*) FROM `$table` WHERE `$col` LIKE ?", ["$prefix-$ym-%"], 0);
    return sprintf('%s-%s-%04d', $prefix, $ym, $n + 1);
}

/** Badge warna status. */
function badge(string $status): string
{
    $map = [
        'tersedia'            => ['Tersedia', 'ok'],
        'dibooking'           => ['Dibooking', 'warn'],
        'terisi'              => ['Terisi', 'muted'],
        'perbaikan'           => ['Perbaikan', 'danger'],
        'pending'             => ['Menunggu', 'warn'],
        'disetujui'           => ['Disetujui', 'ok'],
        'ditolak'             => ['Ditolak', 'danger'],
        'batal'               => ['Batal', 'muted'],
        'aktif'               => ['Aktif', 'ok'],
        'keluar'              => ['Keluar', 'muted'],
        'belum_bayar'         => ['Belum Bayar', 'warn'],
        'menunggu_verifikasi' => ['Menunggu Verifikasi', 'info'],
        'lunas'               => ['Lunas', 'ok'],
        'terlambat'           => ['Terlambat', 'danger'],
        'diterima'            => ['Diterima', 'ok'],
        'antre'               => ['Antre', 'warn'],
        'terkirim'            => ['Terkirim', 'ok'],
        'gagal'               => ['Gagal', 'danger'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'muted'];
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}
