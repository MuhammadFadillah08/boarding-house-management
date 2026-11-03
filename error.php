<?php
/**
 * Halaman kesalahan bergaya (404 / 403 / 500).
 * Dipanggil oleh .htaccess lewat ErrorDocument.
 */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/app/bootstrap.php';

$kode = (int) ($_SERVER['REDIRECT_STATUS'] ?? get('kode') ?: 404);
$peta = [
    403 => ['Akses Ditolak', 'Anda tidak memiliki izin untuk membuka halaman ini.', 'kunci'],
    404 => ['Halaman Tidak Ditemukan', 'Alamat yang Anda tuju tidak ada atau sudah dipindahkan.', 'cari'],
    500 => ['Terjadi Gangguan', 'Ada kendala di sisi server. Silakan coba beberapa saat lagi.', 'peringatan'],
];
[$judul, $pesan, $ico] = $peta[$kode] ?? $peta[404];
http_response_code(isset($peta[$kode]) ? $kode : 404);

layout_head($judul, 'public');
?>
<section class="hero" style="padding:70px 30px">
  <span class="blob b1"></span><span class="blob b2"></span>
  <div class="galat-ico"><?= icon($ico, 40) ?></div>
  <div class="galat-kode"><?= $kode ?></div>
  <h1><?= e($judul) ?></h1>
  <p><?= e($pesan) ?></p>
  <div class="hero-cta">
    <a class="btn btn-lg" href="<?= url('index.php') ?>">Kembali ke Katalog</a>
    <?php if (is_login()): ?>
      <a class="btn btn-lg btn-white" href="<?= url(is_admin() ? 'admin/index.php' : 'user/index.php') ?>">Buka Dashboard</a>
    <?php else: ?>
      <a class="btn btn-lg btn-white" href="<?= url('login.php') ?>">Masuk</a>
    <?php endif; ?>
  </div>
</section>

<div class="center small muted">
  Butuh bantuan? Hubungi pengelola lewat tombol WhatsApp di pojok kanan bawah.
</div>
<?php layout_foot('public'); ?>
