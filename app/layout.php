<?php
/**
 * Layout bersama: header, navigasi, footer.
 *   layout_head('Judul', 'admin', 'kamar');  ... isi ...  layout_foot('admin');
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

function nav_menu(string $area): array
{
    if ($area === 'admin') {
        // Dua hitungan ini menghasilkan lencana "perlu tindakan" di sidebar.
        $perluBooking = (int) scalar('SELECT COUNT(*) FROM booking WHERE status = "pending"', [], 0);
        $perluBayar   = (int) scalar('SELECT COUNT(*) FROM pembayaran WHERE status = "pending"', [], 0);
        return [
            ['admin/index.php',      'Dashboard',  'dashboard',  'dashboard',  0],
            ['admin/kost.php',       'Data Kost',  'kost',       'kost',       0],
            ['admin/kamar.php',      'Kamar',      'kamar',      'kamar',      0],
            ['admin/booking.php',    'Booking',    'booking',    'booking',    $perluBooking],
            ['admin/penghuni.php',   'Penghuni',   'penghuni',   'penghuni',   0],
            ['admin/tagihan.php',    'Tagihan',    'tagihan',    'tagihan',    0],
            ['admin/pembayaran.php', 'Pembayaran', 'pembayaran', 'pembayaran', $perluBayar],
            ['admin/kas.php',        'Buku Kas',   'kas',        'kas',        0],
            ['admin/laporan.php',    'Laporan',    'laporan',    'laporan',    0],
            ['admin/wa_log.php',     'Log WA',     'wa',         'wa',         0],
            ['admin/pengaturan.php', 'Pengaturan', 'pengaturan', 'pengaturan', 0],
        ];
    }
    return [
        ['user/index.php',   'Beranda',       'dashboard', 'dashboard', 0],
        ['index.php',        'Katalog Kamar', 'katalog',   'katalog',   0],
        ['user/booking.php', 'Booking Saya',  'booking',   'booking',   0],
        ['user/tagihan.php', 'Tagihan',       'tagihan',   'tagihan',   0],
        ['user/profil.php',  'Profil',        'profil',    'profil',    0],
    ];
}

function layout_head(string $title, string $area = 'public', string $active = ''): void
{
    $u        = current_user();
    $namaKost = setting('nama_kost', APP_NAME);
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e($namaKost . ' — ' . setting('tagline') . '. ' . setting('alamat')) ?>">
<meta name="theme-color" content="#8FAF9B">
<title><?= e($title) ?> &middot; <?= e($namaKost) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
<script>
/* Animasi "muncul saat di-scroll" hanya dinyalakan bila JavaScript hidup.
   Tanpa ini, pengguna dengan JS mati akan melihat halaman kosong. */
document.documentElement.className += ' js';
</script>
</head>
<body class="area-<?= e($area) ?>">
<a class="skip-link" href="#konten">Lompat ke konten utama</a>
<?php if ($area === 'public'): ?>
<header class="topbar">
  <div class="container topbar-in">
    <a class="brand" href="<?= url('index.php') ?>"><?= logo_mark(34) ?><span><?= e($namaKost) ?></span></a>
    <nav class="topnav" aria-label="Navigasi utama">
      <a href="<?= url('index.php') ?>">Katalog</a>
      <a href="<?= url('index.php#cabang') ?>">Cabang</a>
      <?php if ($u && $u['role'] === 'admin'): ?>
        <a href="<?= url('admin/index.php') ?>">Dashboard</a>
        <a class="btn btn-sm btn-soft" href="<?= url('logout.php') ?>">Keluar</a>
      <?php elseif ($u): ?>
        <a href="<?= url('user/index.php') ?>">Akun Saya</a>
        <a class="btn btn-sm btn-soft" href="<?= url('logout.php') ?>">Keluar</a>
      <?php else: ?>
        <a href="<?= url('login.php') ?>">Masuk</a>
        <a class="btn btn-sm" href="<?= url('register.php') ?>">Daftar Gratis</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container page" id="konten">
<?= render_flash() ?>
<?php else: ?>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Menu <?= $area === 'admin' ? 'pengelola' : 'penyewa' ?>">
    <a class="brand" href="<?= url($area === 'admin' ? 'admin/index.php' : 'user/index.php') ?>">
      <?= logo_mark(32) ?><span><?= e($namaKost) ?></span>
    </a>
    <div class="side-role"><?= $area === 'admin' ? 'Panel Pengelola' : 'Akun Penyewa' ?></div>
    <nav class="sidenav">
      <?php foreach (nav_menu($area) as [$href, $label, $ico, $key, $badge]): ?>
        <a href="<?= url($href) ?>" class="<?= $active === $key ? 'on' : '' ?>"
           <?= $active === $key ? 'aria-current="page"' : '' ?>>
          <?= icon($ico) ?><span><?= e($label) ?></span>
          <?php if ($badge > 0): ?>
            <span class="pill" title="<?= $badge ?> perlu tindakan"><?= $badge ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= url('logout.php') ?>" class="danger"><?= icon('keluar') ?><span>Keluar</span></a>
    </nav>
  </aside>
  <div class="main">
    <header class="mainbar">
      <button class="burger" onclick="toggleSidebar()" aria-label="Buka menu"><?= icon('menu', 22) ?></button>
      <h1><?= e($title) ?></h1>
      <div class="who">
        <span class="who-name"><?= e($u['nama'] ?? '') ?></span>
        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['nama'] ?? '?', 0, 1))) ?></span>
      </div>
    </header>
    <div class="content" id="konten">
    <?= render_flash() ?>
<?php endif; ?>
<?php
}

/**
 * Tombol bantuan melayang yang tersambung ke WhatsApp.
 * Daftar kontak dikelola admin di Pengaturan > Helpdesk WhatsApp.
 */
function helpdesk_widget(): void
{
    if (setting('helpdesk_aktif', '1') !== '1') return;
    $kontak = all('SELECT * FROM helpdesk WHERE status = "aktif" ORDER BY urutan, id');
    if (!$kontak) return;
    ?>
<div class="helpdesk no-print" id="helpdesk">
  <div class="hd-panel" id="hdPanel" role="dialog" aria-label="Bantuan WhatsApp" aria-modal="false">
    <div class="hd-head">
      <div>
        <b><?= e(setting('helpdesk_judul', 'Butuh Bantuan?')) ?></b>
        <div class="tiny"><?= e(setting('nama_kost')) ?></div>
      </div>
      <button class="hd-x" onclick="toggleHelpdesk(false)" aria-label="Tutup bantuan"><?= icon('tutup', 20) ?></button>
    </div>
    <div class="hd-body">
      <p class="small muted"><?= e(setting('helpdesk_sapaan')) ?></p>
      <?php foreach ($kontak as $k):
          $pesan = $k['pesan_awal'] ?: 'Halo ' . setting('nama_kost') . ', saya ingin bertanya.'; ?>
        <a class="hd-item" target="_blank" rel="noopener"
           href="https://wa.me/<?= e(normalize_hp($k['no_hp'])) ?>?text=<?= rawurlencode($pesan) ?>">
          <span class="hd-ava"><?= e(mb_strtoupper(mb_substr($k['nama'], 0, 1))) ?></span>
          <span class="hd-info">
            <b><?= e($k['nama']) ?></b>
            <span class="tiny muted"><?= e($k['peran'] ?: 'Pengelola') ?></span>
            <?php if ($k['jam']): ?>
              <span class="tiny muted hd-jam"><?= icon('jam', 13) ?> <?= e($k['jam']) ?></span>
            <?php endif; ?>
          </span>
          <span class="hd-go"><?= icon('kanan', 18) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <button class="hd-fab" onclick="toggleHelpdesk()" aria-label="Buka bantuan WhatsApp" aria-expanded="false">
    <span class="hd-bubble"><?= e(setting('helpdesk_judul', 'Butuh Bantuan?')) ?>
      <b><?= e(setting('helpdesk_teks', 'Chat dengan Kami')) ?></b></span>
    <span class="hd-circle"><?= icon_whatsapp(28) ?></span>
  </button>
</div>
<?php
}

function layout_foot(string $area = 'public'): void
{
    if ($area === 'public') { ?>
</main>
<footer class="footer">
  <div class="container footer-in">
    <div>
      <div class="brand footer-brand"><?= logo_mark(30) ?><span><?= e(setting('nama_kost', APP_NAME)) ?></span></div>
      <p class="small muted"><?= e(setting('tagline')) ?></p>
    </div>
    <div class="small muted">
      <div><?= icon('lokasi', 15) ?> <?= e(setting('alamat')) ?></div>
      <div><?= icon('wa', 15) ?> <a href="https://wa.me/<?= e(setting('wa_admin')) ?>"><?= e(setting('wa_admin')) ?></a></div>
    </div>
    <div class="small muted">
      <a href="<?= url('index.php') ?>">Katalog kamar</a><br>
      <a href="<?= url('login.php') ?>">Masuk</a> &middot;
      <a href="<?= url('register.php') ?>">Daftar</a>
    </div>
  </div>
  <div class="container footer-bawah small muted">
    &copy; <?= date('Y') ?> <?= e(setting('nama_kost', APP_NAME)) ?>. Seluruh hak cipta dilindungi.
  </div>
</footer>
<?php } else { ?>
    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /shell -->
<?php }
    // Widget WA WAJIB ditaruh di sini — setelah semua div pembungkus ditutup,
    // sebagai anak langsung <body>. Bila ditaruh di dalam .content/.shell,
    // animasi fadeUp pada .content (yang menganimasikan `transform`) membuat
    // .content menjadi "containing block" baru bagi elemen position:fixed di
    // dalamnya, sehingga tombol WA ikut terjebak relatif ke .content dan
    // muncul di tengah halaman, bukan menempel ke sudut layar sungguhan.
    if ($area !== 'admin') helpdesk_widget(); ?>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
<?php
}
