<?php
require_once __DIR__ . '/../app/bootstrap.php';
$u = require_user();

$huni = one('SELECT h.*, k.kode, k.tipe, k.harga, k.fasilitas, ks.nama AS kost, ks.alamat AS kost_alamat
             FROM penghuni h JOIN kamar k ON k.id = h.kamar_id JOIN kost ks ON ks.id = k.kost_id
             WHERE h.user_id = ? AND h.status = "aktif" ORDER BY h.id DESC LIMIT 1', [$u['id']]);

$tunggak = $huni
    ? all('SELECT * FROM tagihan WHERE penghuni_id = ? AND status IN ("belum_bayar","terlambat")
           ORDER BY jatuh_tempo ASC', [$huni['id']])
    : [];
$totalTunggak = array_sum(array_map(fn($t) => (float) $t['jumlah'] + (float) $t['denda'], $tunggak));

$bookingAktif = all('SELECT b.*, k.kode AS kamar_kode, k.tipe, ks.nama AS kost
                     FROM booking b JOIN kamar k ON k.id = b.kamar_id JOIN kost ks ON ks.id = k.kost_id
                     WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 3', [$u['id']]);
$riwayat = $huni
    ? all('SELECT * FROM tagihan WHERE penghuni_id = ? ORDER BY periode DESC LIMIT 5', [$huni['id']])
    : [];
$sisaKamar = (int) scalar('SELECT COUNT(*) FROM kamar WHERE status = "tersedia"', [], 0);

layout_head('Beranda', 'user', 'dashboard');
?>
<div class="grid g4 mb">
  <div class="stat s-sage reveal">
    <div class="lbl">Kamar Saya</div>
    <div class="val"><?= $huni ? e($huni['kode']) : '—' ?></div>
    <div class="sub"><?= $huni ? e($huni['kost']) . ' · ' . rp($huni['harga']) . '/bln' : 'Belum menempati kamar' ?></div>
  </div>
  <div class="stat s-terra reveal" style="--d:.06s">
    <div class="lbl">Tunggakan</div>
    <div class="val"><span data-count="<?= (int) $totalTunggak ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub"><?= count($tunggak) ?> tagihan belum lunas</div>
  </div>
  <div class="stat s-mist reveal" style="--d:.12s">
    <div class="lbl">Sejak</div>
    <div class="val" style="font-size:1.15rem"><?= $huni ? tgl($huni['tgl_masuk']) : '—' ?></div>
    <div class="sub">Tanggal mulai menghuni</div>
  </div>
  <div class="stat s-butter reveal" style="--d:.18s">
    <div class="lbl">Kamar Tersedia</div>
    <div class="val"><span data-count="<?= $sisaKamar ?>">0</span></div>
    <div class="sub"><a href="<?= url('index.php') ?>">Lihat katalog &rarr;</a></div>
  </div>
</div>

<?php if ($tunggak): ?>
  <?php $t0 = $tunggak[0]; $sisa = (int) ((strtotime($t0['jatuh_tempo']) - strtotime(date('Y-m-d'))) / 86400); ?>
  <div class="alert alert-<?= $sisa < 0 ? 'danger' : 'warn' ?>">
    <?= $sisa < 0
        ? 'Tagihan ' . e(periode_label($t0['periode'])) . ' terlambat ' . abs($sisa) . ' hari.'
        : 'Tagihan ' . e(periode_label($t0['periode'])) . ' jatuh tempo ' . e(tgl($t0['jatuh_tempo'])) . ' (' . $sisa . ' hari lagi).' ?>
    Total <?= rp((float) $t0['jumlah'] + (float) $t0['denda']) ?>.
    <a href="<?= url('user/tagihan.php') ?>">Bayar sekarang &rarr;</a>
  </div>
<?php endif; ?>

<div class="grid g2">
  <section class="card">
    <div class="card-head"><h3>Tagihan Terakhir</h3><a class="btn btn-sm btn-soft" href="<?= url('user/tagihan.php') ?>">Semua</a></div>
    <?php if (!$riwayat): ?>
      <div class="empty"><span class="big"><?= icon('tagihan', 44) ?></span>Belum ada tagihan.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="tb">
        <thead><tr><th>Periode</th><th>Jatuh Tempo</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($riwayat as $t): ?>
          <tr>
            <td><b><?= e(periode_label($t['periode'])) ?></b><br><span class="small muted"><?= e($t['kode']) ?></span></td>
            <td><?= e(tgl($t['jatuh_tempo'])) ?></td>
            <td class="num"><?= rp((float) $t['jumlah'] + (float) $t['denda']) ?></td>
            <td><?= badge($t['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h3>Pengajuan Booking</h3><a class="btn btn-sm btn-soft" href="<?= url('user/booking.php') ?>">Semua</a></div>
    <?php if (!$bookingAktif): ?>
      <div class="empty"><span class="big"><?= icon('booking', 44) ?></span>Belum ada pengajuan booking.<br>
        <a class="btn btn-sm mt" href="<?= url('index.php') ?>">Lihat Katalog</a></div>
    <?php else: ?>
      <div class="table-wrap"><table class="tb">
        <thead><tr><th>Kode</th><th>Kamar</th><th>Masuk</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($bookingAktif as $b): ?>
          <tr>
            <td class="small"><?= e($b['kode']) ?></td>
            <td><b><?= e($b['kamar_kode']) ?></b><br><span class="small muted"><?= e($b['kost']) ?></span></td>
            <td><?= e(tgl($b['tgl_masuk'])) ?></td>
            <td><?= badge($b['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
</div>

<?php if ($huni): ?>
<section class="card card-pad mt reveal">
  <div class="spread">
    <div>
      <h3>Kamar <?= e($huni['kode']) ?> &middot; <?= e($huni['kost']) ?></h3>
      <p class="small muted"><?= e($huni['tipe']) ?> &middot; <?= e($huni['kost_alamat']) ?></p>
    </div>
    <a class="btn btn-sm btn-line" target="_blank" href="https://wa.me/<?= e(setting('wa_admin')) ?>">Hubungi Pengelola</a>
  </div>
  <div class="fac mt">
    <?php foreach (array_filter(array_map('trim', explode(',', (string) $huni['fasilitas']))) as $f): ?>
      <span><?= e($f) ?></span>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php layout_foot('user'); ?>
