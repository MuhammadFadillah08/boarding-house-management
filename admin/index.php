<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$k = one('SELECT COUNT(*) total, SUM(status="tersedia") tersedia, SUM(status="terisi") terisi,
                 SUM(status="dibooking") dibooking, SUM(status="perbaikan") perbaikan FROM kamar');
$bulan = date('Y-m');
$masukBulanIni = (float) scalar('SELECT COALESCE(SUM(p.jumlah),0) FROM pembayaran p
                                 WHERE p.status = "diterima" AND DATE_FORMAT(p.tgl_bayar,"%Y-%m") = ?', [$bulan], 0);
$piutang   = (float) scalar('SELECT COALESCE(SUM(jumlah + denda),0) FROM tagihan
                             WHERE status IN ("belum_bayar","terlambat")', [], 0);
$pendingBk = (int) scalar('SELECT COUNT(*) FROM booking WHERE status = "pending"', [], 0);
$pendingPy = (int) scalar('SELECT COUNT(*) FROM pembayaran WHERE status = "pending"', [], 0);
$penghuni  = (int) scalar('SELECT COUNT(*) FROM penghuni WHERE status = "aktif"', [], 0);
$waGagal   = (int) scalar('SELECT COUNT(*) FROM wa_log WHERE status IN ("antre","gagal")', [], 0);

// Grafik 6 bulan terakhir
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $p = date('Y-m', strtotime("-$i month"));
    $chart[$p] = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran
                                 WHERE status = "diterima" AND DATE_FORMAT(tgl_bayar,"%Y-%m") = ?', [$p], 0);
}
$max = max(1, max($chart));

$bookingBaru = all('SELECT b.*, u.nama, k.kode AS kamar_kode, ks.nama AS kost FROM booking b
                    JOIN users u ON u.id = b.user_id
                    JOIN kamar k ON k.id = b.kamar_id
                    JOIN kost ks ON ks.id = k.kost_id
                    WHERE b.status = "pending" ORDER BY b.id DESC LIMIT 5');
$bayarBaru   = all('SELECT p.*, u.nama, t.kode AS kode_tagihan FROM pembayaran p
                    JOIN users u ON u.id = p.user_id JOIN tagihan t ON t.id = p.tagihan_id
                    WHERE p.status = "pending" ORDER BY p.id DESC LIMIT 5');
$jatuhTempo  = all('SELECT t.*, u.nama, k.kode AS kamar_kode, ks.nama AS kost FROM tagihan t
                    JOIN penghuni h ON h.id = t.penghuni_id
                    JOIN users u ON u.id = h.user_id
                    JOIN kamar k ON k.id = h.kamar_id
                    JOIN kost ks ON ks.id = k.kost_id
                    WHERE t.status IN ("belum_bayar","terlambat")
                    ORDER BY t.jatuh_tempo ASC LIMIT 6');

// Ringkasan per cabang
$perKost = all('SELECT ks.id, ks.nama, ks.warna,
                COUNT(m.id) total, SUM(m.status = "terisi") terisi, SUM(m.status = "tersedia") tersedia,
                COALESCE(SUM(CASE WHEN m.status = "terisi" THEN m.harga ELSE 0 END),0) potensi
                FROM kost ks LEFT JOIN kamar m ON m.kost_id = ks.id
                WHERE ks.status = "aktif" GROUP BY ks.id ORDER BY ks.urutan, ks.id');

layout_head('Dashboard', 'admin', 'dashboard');
?>
<div class="grid g4 mb">
  <div class="stat s-sage reveal" style="--d:0s"><div class="lbl">Pemasukan <?= e(periode_label($bulan)) ?></div>
    <div class="val"><span data-count="<?= (int) $masukBulanIni ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">pembayaran terverifikasi</div></div>
  <div class="stat s-terra reveal" style="--d:.06s"><div class="lbl">Piutang</div>
    <div class="val"><span data-count="<?= (int) $piutang ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">tagihan belum lunas</div></div>
  <div class="stat s-mist reveal" style="--d:.12s"><div class="lbl">Hunian</div>
    <div class="val"><?= (int) $k['terisi'] ?>/<?= (int) $k['total'] ?></div>
    <div class="sub"><?= (int) $k['tersedia'] ?> tersedia · <?= (int) $k['dibooking'] ?> dibooking</div></div>
  <div class="stat s-butter reveal" style="--d:.18s"><div class="lbl">Penghuni Aktif</div>
    <div class="val"><span data-count="<?= $penghuni ?>">0</span></div>
    <div class="sub"><?= (int) $k['perbaikan'] ?> kamar perbaikan</div></div>
</div>

<section class="card mb reveal">
  <div class="card-head"><h3>Ringkasan per Cabang</h3>
    <a class="btn btn-sm btn-soft" href="<?= url('admin/kost.php') ?>">Kelola Kost</a></div>
  <div class="card-pad">
    <div class="grid g4">
      <?php foreach ($perKost as $i => $ks):
          $pct = (int) $ks['total'] > 0 ? round((int) $ks['terisi'] / (int) $ks['total'] * 100) : 0; ?>
        <div class="acc-<?= e($ks['warna']) ?> reveal" style="--d:<?= $i * .06 ?>s">
          <div class="spread"><b><?= e($ks['nama']) ?></b><span class="tiny muted"><?= $pct ?>%</span></div>
          <div class="tiny muted"><?= (int) $ks['terisi'] ?> terisi · <?= (int) $ks['tersedia'] ?> kosong
            dari <?= (int) $ks['total'] ?></div>
          <div class="kost-meter"><i data-w="<?= $pct ?>"></i></div>
          <div class="tiny muted" style="margin-top:6px"><?= rp($ks['potensi']) ?>/bln</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($pendingBk || $pendingPy || $waGagal): ?>
<div class="alert alert-warn">
  Perlu tindakan:
  <?php if ($pendingBk): ?><a href="<?= url('admin/booking.php') ?>"><?= $pendingBk ?> booking menunggu</a> · <?php endif; ?>
  <?php if ($pendingPy): ?><a href="<?= url('admin/pembayaran.php') ?>"><?= $pendingPy ?> pembayaran perlu verifikasi</a> · <?php endif; ?>
  <?php if ($waGagal): ?><a href="<?= url('admin/wa_log.php') ?>"><?= $waGagal ?> pesan WA belum terkirim</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="grid g2 mb">
  <section class="card card-pad">
    <h3>Pemasukan 6 Bulan Terakhir</h3>
    <div class="bars mt">
      <?php foreach ($chart as $p => $v): ?>
        <div class="b" title="<?= e(periode_label($p)) ?>: <?= rp($v) ?>">
          <i style="height:<?= max(2, round($v / $max * 100)) ?>%"></i>
          <em><?= e(substr(periode_label($p), 0, 3)) ?></em>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="right mt"><a class="btn btn-sm btn-line" href="<?= url('admin/laporan.php') ?>">Laporan Lengkap</a></div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Jatuh Tempo Terdekat</h3></div>
    <?php if (!$jatuhTempo): ?>
      <div class="empty"><span class="big"><?= icon('centang', 44) ?></span>Tidak ada tagihan tertunggak.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="tb">
      <thead><tr><th>Penyewa</th><th>Periode</th><th>Tempo</th><th class="num">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($jatuhTempo as $t): $sisa = (int) ((strtotime($t['jatuh_tempo']) - time()) / 86400); ?>
        <tr>
          <td><b><?= e($t['nama']) ?></b><br><span class="small muted"><?= e($t['kost'] . ' ' . $t['kamar_kode']) ?></span></td>
          <td class="small"><?= e(periode_label($t['periode'])) ?></td>
          <td class="small"><?= e(tgl($t['jatuh_tempo'])) ?><br>
            <span class="muted"><?= $sisa < 0 ? 'telat ' . abs($sisa) . ' hr' : $sisa . ' hr lagi' ?></span></td>
          <td class="num"><?= rp((float) $t['jumlah'] + (float) $t['denda']) ?></td>
          <td><?= badge($t['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>

<div class="grid g2">
  <section class="card">
    <div class="card-head"><h3>Booking Menunggu</h3><a class="btn btn-sm btn-soft" href="<?= url('admin/booking.php') ?>">Kelola</a></div>
    <?php if (!$bookingBaru): ?><div class="empty"><span class="big"><?= icon('booking', 44) ?></span>Tidak ada pengajuan baru.</div><?php else: ?>
    <div class="table-wrap"><table class="tb">
      <thead><tr><th>Kode</th><th>Nama</th><th>Kamar</th><th>Masuk</th></tr></thead>
      <tbody><?php foreach ($bookingBaru as $b): ?>
        <tr><td class="small"><?= e($b['kode']) ?></td><td><?= e($b['nama']) ?></td>
            <td class="small"><b><?= e($b['kamar_kode']) ?></b><br><span class="muted"><?= e($b['kost']) ?></span></td>
            <td class="small"><?= e(tgl($b['tgl_masuk'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h3>Pembayaran Perlu Verifikasi</h3><a class="btn btn-sm btn-soft" href="<?= url('admin/pembayaran.php') ?>">Verifikasi</a></div>
    <?php if (!$bayarBaru): ?><div class="empty"><span class="big"><?= icon('pembayaran', 44) ?></span>Semua pembayaran sudah diverifikasi.</div><?php else: ?>
    <div class="table-wrap"><table class="tb">
      <thead><tr><th>Kode</th><th>Nama</th><th>Tagihan</th><th class="num">Nominal</th></tr></thead>
      <tbody><?php foreach ($bayarBaru as $p): ?>
        <tr><td class="small"><?= e($p['kode']) ?></td><td><?= e($p['nama']) ?></td>
            <td class="small"><?= e($p['kode_tagihan']) ?></td><td class="num"><?= rp($p['jumlah']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
</div>
<?php layout_foot('admin'); ?>
