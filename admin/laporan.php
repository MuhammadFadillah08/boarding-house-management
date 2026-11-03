<?php
/** Laporan keuangan bulanan + tombol cetak PDF & tautan ke Google Spreadsheet. */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lap_data.php';
require_admin();

[$dari, $sampai, $judul] = laporan_rentang();
$d  = laporan_data($dari, $sampai);
$qs = http_build_query($_GET ?: ['periode' => date('Y-m')]);

// tren 12 bulan
$tren = [];
for ($i = 11; $i >= 0; $i--) {
    $p = date('Y-m', strtotime("-$i month"));
    $tren[$p] = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran
                                WHERE status = "diterima" AND DATE_FORMAT(tgl_bayar,"%Y-%m") = ?', [$p], 0);
}
$maxTren = max(1, max($tren));

layout_head('Laporan Keuangan', 'admin', 'laporan');
?>
<section class="card card-pad mb no-print">
  <form class="row" method="get">
    <div>
      <label class="small muted">Mode</label><br>
      <select name="mode" onchange="this.form.submit()">
        <option value="bulan"  <?= get('mode', 'bulan') === 'bulan'  ? 'selected' : '' ?>>Per bulan</option>
        <option value="custom" <?= get('mode') === 'custom' ? 'selected' : '' ?>>Rentang tanggal</option>
      </select>
    </div>
    <?php if (get('mode') === 'custom'): ?>
      <div><label class="small muted">Dari</label><br><input type="date" name="dari" value="<?= e($dari) ?>"></div>
      <div><label class="small muted">Sampai</label><br><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
    <?php else: ?>
      <div><label class="small muted">Bulan</label><br>
        <input type="month" name="periode" value="<?= e(substr($dari, 0, 7)) ?>"></div>
    <?php endif; ?>
    <div style="align-self:flex-end"><button class="btn">Tampilkan</button></div>
    <div class="grow"></div>
    <div style="align-self:flex-end" class="row">
      <a class="btn btn-line" target="_blank" href="<?= url('admin/laporan_cetak.php?' . $qs) ?>"><?= icon('cetak', 17) ?> Cetak / PDF</a>
      <?php if ($sheetUrl = sheets_url_dokumen()): ?>
        <a class="btn btn-warn" target="_blank" rel="noopener" href="<?= e($sheetUrl) ?>"><?= icon('laporan', 17) ?> Buka Spreadsheet</a>
      <?php else: ?>
        <a class="btn btn-warn btn-soft" href="<?= url('admin/pengaturan.php') ?>"
           title="Isi URL, kunci, dan ID Spreadsheet lebih dulu di Pengaturan">
          <?= icon('laporan', 17) ?> Spreadsheet belum diatur</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<h2 class="mb"><?= e($judul) ?></h2>

<div class="grid g4 mb">
  <div class="stat s-sage reveal"><div class="lbl">Total Pemasukan</div>
    <div class="val"><span data-count="<?= (int) $d['total']['masuk'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">sewa <?= rp($d['total']['sewa']) ?> &middot; lain <?= rp($d['total']['lain']) ?></div></div>
  <div class="stat s-terra reveal" style="--d:.06s"><div class="lbl">Total Pengeluaran</div>
    <div class="val"><span data-count="<?= (int) $d['total']['keluar'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub"><?= count($d['kas_keluar']) ?> transaksi operasional</div></div>
  <div class="stat s-butter reveal" style="--d:.12s"><div class="lbl">Saldo</div>
    <div class="val" style="color:<?= $d['total']['saldo'] >= 0 ? 'var(--sage-d)' : 'var(--danger-d)' ?>">
      <span data-count="<?= (int) abs($d['total']['saldo']) ?>"
            data-prefix="<?= $d['total']['saldo'] < 0 ? '-Rp ' : 'Rp ' ?>">Rp 0</span></div>
    <div class="sub">pemasukan &minus; pengeluaran</div></div>
  <div class="stat s-mist reveal" style="--d:.18s"><div class="lbl">Piutang</div>
    <div class="val"><span data-count="<?= (int) $d['total']['piutang'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub"><?= $d['total']['belum'] ?> tagihan belum lunas</div></div>
</div>

<div class="grid g2 mb">
  <section class="card card-pad">
    <h3>Tren Pemasukan 12 Bulan</h3>
    <div class="bars mt">
      <?php foreach ($tren as $p => $v): ?>
        <div class="b" title="<?= e(periode_label($p)) ?>: <?= rp($v) ?>">
          <i style="height:<?= max(2, round($v / $maxTren * 100)) ?>%"></i>
          <em><?= e(substr(periode_label($p), 0, 3)) ?></em>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="card card-pad">
    <h3>Pemasukan per Cabang</h3>
    <table class="tb mt">
      <thead><tr><th>Cabang</th><th class="num">Jumlah</th><th class="num">Porsi</th></tr></thead>
      <tbody>
      <?php if (!$d['per_kost']): ?>
        <tr><td colspan="3" class="muted center">Belum ada pemasukan pada periode ini.</td></tr>
      <?php else: foreach ($d['per_kost'] as $m => $v): ?>
        <tr><td><b><?= e($m) ?></b></td><td class="num"><?= rp($v) ?></td>
            <td class="num"><?= $d['total']['masuk'] > 0 ? round($v / $d['total']['masuk'] * 100, 1) : 0 ?>%</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th class="num"><?= rp($d['total']['masuk']) ?></th><th class="num">100%</th></tr></tfoot>
    </table>
    <h3 class="mt">Pengeluaran per Kategori</h3>
    <table class="tb mt">
      <thead><tr><th>Kategori</th><th class="num">Jumlah</th><th class="num">Porsi</th></tr></thead>
      <tbody>
      <?php if (!$d['per_kategori']): ?>
        <tr><td colspan="3" class="muted center">Belum ada pengeluaran pada periode ini.</td></tr>
      <?php else: foreach ($d['per_kategori'] as $kat => $v): ?>
        <tr><td><?= e(ucfirst(str_replace('_', ' ', $kat))) ?></td><td class="num"><?= rp($v) ?></td>
            <td class="num"><?= $d['total']['keluar'] > 0 ? round($v / $d['total']['keluar'] * 100, 1) : 0 ?>%</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th class="num"><?= rp($d['total']['keluar']) ?></th><th class="num">100%</th></tr></tfoot>
    </table>
  </section>
</div>

<section class="card mb reveal">
  <div class="card-head"><h3>Pengeluaran Kost</h3>
    <div class="row">
      <span class="small muted"><?= count($d['kas_keluar']) ?> transaksi &middot; <?= rp($d['total']['keluar']) ?></span>
      <a class="btn btn-sm btn-soft" href="<?= url('admin/kas.php?periode=' . substr($dari, 0, 7)) ?>">Kelola Buku Kas</a>
    </div>
  </div>
  <?php if (!$d['kas_keluar']): ?>
    <div class="empty"><span class="big"><?= icon('kas', 44) ?></span>Belum ada pengeluaran tercatat pada periode ini.<br>
      <span class="small">Catat lewat menu Buku Kas atau kirim <code>KELUAR 150rb token listrik</code> ke bot WhatsApp.</span></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Tanggal</th><th>Kode</th><th>Kategori</th><th>Uraian</th>
      <th>Cabang</th><th>Dicatat</th><th class="num">Nominal</th></tr></thead>
    <tbody>
    <?php foreach ($d['kas_keluar'] as $m): ?>
      <tr><td class="small"><?= e(tgl($m['tanggal'])) ?></td>
        <td class="small"><?= e($m['kode']) ?></td>
        <td><span class="badge badge-danger"><?= e(ucfirst(str_replace('_', ' ', $m['kategori']))) ?></span></td>
        <td><?= e($m['keterangan']) ?></td>
        <td class="small muted"><?= e($m['kost'] ?: 'Umum') ?></td>
        <td class="small muted"><?= e($m['pencatat'] ?: '—') ?>
          <?= $m['sumber'] === 'wa' ? ' <span class="badge badge-info">WA</span>' : '' ?></td>
        <td class="num"><b><?= rp($m['jumlah']) ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="6">TOTAL PENGELUARAN</th><th class="num"><?= rp($d['total']['keluar']) ?></th></tr></tfoot>
  </table></div>
  <?php endif; ?>
</section>

<section class="card mb">
  <div class="card-head"><h3>Buku Kas Masuk</h3><span class="small muted"><?= count($d['masuk']) ?> transaksi</span></div>
  <?php if (!$d['masuk']): ?><div class="empty">Tidak ada pemasukan pada periode ini.</div><?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>#</th><th>Tanggal</th><th>Kode</th><th>Penyewa</th><th>Cabang</th><th>Kamar</th>
      <th>Periode Tagihan</th><th>Metode</th><th class="num">Nominal</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($d['masuk'] as $m): $i++; ?>
      <tr><td><?= $i ?></td><td><?= e(tgl($m['tgl_bayar'])) ?></td><td class="small"><?= e($m['kode']) ?></td>
        <td><?= e($m['nama']) ?></td><td class="small"><?= e($m['kost']) ?></td><td><?= e($m['kamar_kode']) ?></td>
        <td class="small"><?= e(periode_label($m['periode'])) ?></td><td><?= e(ucfirst($m['metode'])) ?></td>
        <td class="num"><?= rp($m['jumlah']) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($d['kas_masuk'] as $m): $i++; ?>
      <tr><td><?= $i ?></td><td><?= e(tgl($m['tanggal'])) ?></td><td class="small"><?= e($m['kode']) ?></td>
        <td><?= e($m['keterangan']) ?></td><td class="small"><?= e($m['kost'] ?: 'Umum') ?></td><td>—</td>
        <td class="small"><span class="badge badge-info"><?= e(ucfirst(str_replace('_', ' ', $m['kategori']))) ?></span></td>
        <td class="small"><?= $m['sumber'] === 'wa' ? 'via WA' : 'Web' ?></td>
        <td class="num"><?= rp($m['jumlah']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="8">TOTAL PEMASUKAN</th><th class="num"><?= rp($d['total']['masuk']) ?></th></tr></tfoot>
  </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h3>Status Tagihan Periode Ini</h3></div>
  <?php if (!$d['tagihan']): ?><div class="empty">Tidak ada tagihan jatuh tempo pada periode ini.</div><?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Kode</th><th>Penyewa</th><th>Cabang</th><th>Kamar</th><th>Jatuh Tempo</th>
      <th class="num">Sewa</th><th class="num">Denda</th><th class="num">Terbayar</th><th class="num">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($d['tagihan'] as $t): $tot = (float) $t['jumlah'] + (float) $t['denda']; $sisa = max(0, $tot - (float) $t['terbayar']); ?>
      <tr><td class="small"><?= e($t['kode']) ?></td><td><?= e($t['nama']) ?></td>
        <td class="small"><?= e($t['kost']) ?></td><td><?= e($t['kamar_kode']) ?></td>
        <td class="small"><?= e(tgl($t['jatuh_tempo'])) ?></td>
        <td class="num"><?= rp($t['jumlah']) ?></td><td class="num"><?= rp($t['denda']) ?></td>
        <td class="num"><?= rp($t['terbayar']) ?></td>
        <td class="num"><?= $sisa > 0 ? '<b style="color:var(--danger)">' . rp($sisa) . '</b>' : '—' ?></td>
        <td><?= badge($t['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="8">TOTAL PIUTANG</th><th class="num"><?= rp($d['total']['piutang']) ?></th><th></th></tr></tfoot>
  </table></div>
  <?php endif; ?>
</section>
<?php layout_foot('admin'); ?>
