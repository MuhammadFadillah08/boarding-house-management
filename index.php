<?php
/** Landing + katalog kamar publik (4 cabang, status ketersediaan real-time). */
require_once __DIR__ . '/app/bootstrap.php';

$kostId = (int) get('kost');
$tipe   = get('tipe');
$urut   = get('urut', 'kode');
$hanya  = get('hanya');

$w = ['k.status <> "nonaktif"'];
$p = [];
if ($kostId)               { $w[] = 'm.kost_id = ?'; $p[] = $kostId; }
if ($tipe !== '')          { $w[] = 'm.tipe = ?';    $p[] = $tipe; }
if ($hanya === 'tersedia') { $w[] = 'm.status = "tersedia"'; }

$order = $urut === 'murah' ? 'm.harga ASC' : ($urut === 'mahal' ? 'm.harga DESC' : 'k.urutan ASC, m.kode ASC');
$kamar = all('SELECT m.*, k.nama AS kost_nama, k.warna FROM kamar m JOIN kost k ON k.id = m.kost_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . $order, $p);

$kostList = all('SELECT k.*,
                  COUNT(m.id) AS total,
                  SUM(m.status = "tersedia") AS tersedia,
                  MIN(m.harga) AS termurah
                FROM kost k LEFT JOIN kamar m ON m.kost_id = k.id
                WHERE k.status = "aktif" GROUP BY k.id ORDER BY k.urutan, k.id');
$tipeList = all('SELECT DISTINCT tipe FROM kamar ORDER BY tipe');
$stat = one('SELECT COUNT(*) total, SUM(status = "tersedia") tersedia, MIN(harga) termurah FROM kamar');
$jmlKost = count($kostList);
$kostAktif = $kostId ? one('SELECT * FROM kost WHERE id = ?', [$kostId]) : null;

layout_head('Katalog Kamar', 'public');
?>
<section class="hero">
  <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
  <div class="hero-eyebrow"><span class="live-dot"></span> <span id="sisa-kamar"><?= (int) $stat['tersedia'] ?></span> kamar siap huni hari ini</div>
  <h1><?= e(setting('nama_kost')) ?></h1>
  <p><?= e(setting('tagline')) ?>. <?= $jmlKost ?> cabang di pusat kota, <?= (int) $stat['total'] ?> kamar terawat,
     dengan pengingat tagihan otomatis lewat WhatsApp.</p>
  <div class="hero-cta">
    <a class="btn btn-lg" href="#katalog">Lihat Kamar</a>
    <a class="btn btn-lg btn-white" href="#cabang">Jelajahi Cabang</a>
  </div>
  <div class="hero-stats">
    <div><b data-count="<?= $jmlKost ?>">0</b><span>Cabang kost</span></div>
    <div><b data-count="<?= (int) $stat['total'] ?>">0</b><span>Total kamar</span></div>
    <div><b data-count="<?= (int) $stat['tersedia'] ?>">0</b><span>Tersedia</span></div>
    <div><b data-count="<?= (int) $stat['termurah'] ?>" data-prefix="Rp ">0</b><span>Mulai dari</span></div>
  </div>
</section>

<!-- ============ CABANG ============ -->
<section id="cabang" class="mb">
  <div class="spread mb">
    <div class="reveal">
      <h2>Pilih Cabang</h2>
      <p class="small muted">Setiap cabang punya karakter dan fasilitas bersama yang berbeda.</p>
    </div>
    <?php if ($kostId): ?>
      <a class="btn btn-sm btn-soft reveal" href="<?= url('index.php') ?>">&times; Tampilkan semua cabang</a>
    <?php endif; ?>
  </div>

  <div class="grid g4">
    <?php foreach ($kostList as $i => $k):
        $pct = (int) $k['total'] > 0 ? round((int) $k['tersedia'] / (int) $k['total'] * 100) : 0; ?>
      <a class="kost-card reveal acc-<?= e($k['warna']) ?> <?= $kostId === (int) $k['id'] ? 'on' : '' ?>"
         style="--d:<?= $i * .07 ?>s" href="<?= url('index.php?kost=' . (int) $k['id']) ?>#katalog">
        <div class="spread">
          <h3><?= e($k['nama']) ?></h3>
          <?= badge((int) $k['tersedia'] > 0 ? 'tersedia' : 'terisi') ?>
        </div>
        <div class="tiny muted"><?= e($k['alamat']) ?></div>
        <p class="small muted mt" style="margin-top:10px"><?= e(mb_strimwidth((string) $k['deskripsi'], 0, 96, '…')) ?></p>
        <div class="spread" style="margin-top:12px">
          <span class="small"><b><?= (int) $k['tersedia'] ?></b><span class="muted">/<?= (int) $k['total'] ?> kamar kosong</span></span>
          <span class="small muted">mulai <b><?= rp($k['termurah'] ?? 0) ?></b></span>
        </div>
        <div class="kost-meter"><i data-w="<?= $pct ?>"></i></div>
        <div class="tiny muted" style="margin-top:8px">
          <?= e(ucfirst($k['tipe'])) ?> &middot; <?= e(mb_strimwidth((string) $k['fasilitas'], 0, 46, '…')) ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ KATALOG ============ -->
<div id="katalog-anchor"></div>
<div class="mb reveal">
  <div class="spread mb">
    <div>
      <h2><?= $kostAktif ? 'Kamar di ' . e($kostAktif['nama']) : 'Semua Kamar' ?></h2>
      <p class="small muted">
        <span id="jml-hasil"><?= count($kamar) ?> kamar</span> &middot;
        status diperbarui otomatis tiap 15 detik.
      </p>
    </div>
    <?php if ($kostId || $tipe !== '' || $hanya !== ''): ?>
      <a class="btn btn-sm btn-soft" href="<?= url('index.php#katalog-anchor') ?>">&times; Reset filter</a>
    <?php endif; ?>
  </div>

  <form class="filterbar" method="get" action="<?= url('index.php') ?>#katalog-anchor">
    <label>
      <span>Pilih cabang</span>
      <select name="kost" onchange="this.form.submit()">
        <option value="">Semua cabang (<?= (int) $stat['total'] ?> kamar)</option>
        <?php foreach ($kostList as $k): ?>
          <option value="<?= (int) $k['id'] ?>" <?= $kostId === (int) $k['id'] ? 'selected' : '' ?>>
            <?= e($k['nama']) ?> — <?= (int) $k['tersedia'] ?>/<?= (int) $k['total'] ?> kosong
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Tipe kamar</span>
      <select name="tipe" onchange="this.form.submit()">
        <option value="">Semua tipe</option>
        <?php foreach ($tipeList as $t): ?>
          <option value="<?= e($t['tipe']) ?>" <?= $tipe === $t['tipe'] ? 'selected' : '' ?>><?= e($t['tipe']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Status</span>
      <select name="hanya" onchange="this.form.submit()">
        <option value="">Semua status</option>
        <option value="tersedia" <?= $hanya === 'tersedia' ? 'selected' : '' ?>>Hanya yang tersedia</option>
      </select>
    </label>
    <label>
      <span>Urutkan</span>
      <select name="urut" onchange="this.form.submit()">
        <option value="kode"  <?= $urut === 'kode'  ? 'selected' : '' ?>>Urutan kamar</option>
        <option value="murah" <?= $urut === 'murah' ? 'selected' : '' ?>>Harga termurah</option>
        <option value="mahal" <?= $urut === 'mahal' ? 'selected' : '' ?>>Harga tertinggi</option>
      </select>
    </label>
    <label class="grow">
      <span>Cari</span>
      <input class="inp" placeholder="Kode kamar, tipe, atau fasilitas…"
             data-filter="#katalog .room" data-count-into="jml-hasil">
    </label>
  </form>
</div>

<?php if (!$kamar): ?>
  <div class="card card-pad empty"><span class="big"><?= icon('kamar', 44) ?></span>Belum ada kamar yang cocok dengan filter Anda.<br>
    <a class="btn btn-sm mt" href="<?= url('index.php') ?>">Reset filter</a></div>
<?php else: ?>
  <div class="grid g3" id="katalog" data-api="<?= url('api/kamar.php') ?>">
    <?php foreach ($kamar as $i => $k) echo room_card($k, min($i, 5) * .06); ?>
  </div>
<?php endif; ?>

<!-- ============ CARA SEWA ============ -->
<section class="card card-pad mt reveal" style="margin-top:44px">
  <div class="center mb"><h2>Empat Langkah Saja</h2>
    <p class="small muted">Dari melihat kamar sampai kuitansi lunas, semuanya tercatat rapi.</p></div>
  <div class="grid g4 mt">
    <?php foreach ([
      ['profil',  'Daftar akun',          'Cukup nama, nomor WhatsApp, dan kata sandi. Tanpa email.'],
      ['kamar',   'Ajukan booking',       'Pilih kamar yang tersedia, tentukan tanggal masuk dan durasi.'],
      ['centang', 'Konfirmasi pengelola', 'Anda dihubungi lewat WhatsApp segera setelah diverifikasi.'],
      ['tagihan', 'Bayar & tempati',      'Unggah bukti transfer, kuitansi lunas terbit otomatis.'],
    ] as $i => [$ico, $judul, $isi]): ?>
      <div class="langkah reveal" style="--d:<?= $i * .08 ?>s">
        <span class="langkah-ico"><?= icon($ico, 24) ?></span>
        <b><?= $i + 1 ?>. <?= e($judul) ?></b>
        <p class="small muted"><?= e($isi) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!is_login()): ?>
    <div class="center mt" style="margin-top:26px">
      <a class="btn btn-lg btn-glow" href="<?= url('register.php') ?>">Buat Akun Gratis</a>
    </div>
  <?php endif; ?>
</section>
<?php layout_foot('public'); ?>
