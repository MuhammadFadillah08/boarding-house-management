<?php
/**
 * Laporan keuangan versi cetak.
 * Buka -> Ctrl+P -> "Save as PDF" (tidak butuh pustaka PDF, aman di InfinityFree).
 */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lap_data.php';
require_admin();

[$dari, $sampai, $judul] = laporan_rentang();
$d = laporan_data($dari, $sampai);
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Keuangan <?= e($judul) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<style>@media screen{body{background:var(--cream-2)}}</style>
</head>
<body onload="window.print()">
<div class="no-print center" style="padding:16px">
  <button class="btn" onclick="window.print()"><?= icon('cetak', 17) ?> Cetak / Simpan PDF</button>
  <a class="btn btn-soft" href="<?= url('admin/laporan.php') ?>">Kembali</a>
</div>

<div class="paper">
  <div class="paper-head">
    <div>
      <div class="paper-title"><?= e(setting('nama_kost')) ?></div>
      <div class="small muted"><?= e(setting('alamat')) ?> &middot; WA <?= e(setting('wa_admin')) ?></div>
    </div>
    <div class="right">
      <div style="font-weight:800;letter-spacing:.08em">LAPORAN KEUANGAN</div>
      <div class="small muted"><?= e($judul) ?><br>Dicetak <?= e(tgl(date('Y-m-d'), true)) ?></div>
    </div>
  </div>

  <h3>A. Ringkasan</h3>
  <table class="tb">
    <tbody>
      <tr><td>Pemasukan &mdash; sewa kamar (pembayaran terverifikasi)</td><td class="num"><?= rp($d['total']['sewa']) ?></td></tr>
      <tr><td>Pemasukan &mdash; lain-lain (buku kas)</td><td class="num"><?= rp($d['total']['lain']) ?></td></tr>
      <tr><td><b>TOTAL PEMASUKAN</b></td><td class="num"><b><?= rp($d['total']['masuk']) ?></b></td></tr>
      <tr><td><b>TOTAL PENGELUARAN</b></td><td class="num"><b><?= rp($d['total']['keluar']) ?></b></td></tr>
      <tr style="background:#FCF3E0"><td><b>SALDO PERIODE INI</b></td>
        <td class="num"><b><?= rp($d['total']['saldo']) ?></b></td></tr>
      <tr><td>Total ditagihkan</td><td class="num"><?= rp($d['total']['tagih']) ?></td></tr>
      <tr><td>Denda keterlambatan</td><td class="num"><?= rp($d['total']['denda']) ?></td></tr>
      <tr><td>Piutang (belum terbayar)</td><td class="num"><?= rp($d['total']['piutang']) ?></td></tr>
      <tr><td>Tagihan lunas / belum lunas</td><td class="num"><?= $d['total']['lunas'] ?> / <?= $d['total']['belum'] ?></td></tr>
      <tr><td>Tingkat hunian</td><td class="num"><?= $d['total']['hunian'] ?> dari <?= $d['total']['kamar'] ?> kamar
        (<?= $d['total']['kamar'] ? round($d['total']['hunian'] / $d['total']['kamar'] * 100) : 0 ?>%)</td></tr>
    </tbody>
  </table>

  <h3 class="mt">B. Pemasukan per Cabang</h3>
  <table class="tb">
    <thead><tr><th>Cabang</th><th class="num">Jumlah</th><th class="num">Porsi</th></tr></thead>
    <tbody>
    <?php if (!$d['per_kost']): ?>
      <tr><td colspan="3" class="center muted">Tidak ada pemasukan pada periode ini.</td></tr>
    <?php else: foreach ($d['per_kost'] as $nm => $v): ?>
      <tr><td><?= e($nm) ?></td><td class="num"><?= rp($v) ?></td>
        <td class="num"><?= $d['total']['masuk'] > 0 ? round($v / $d['total']['masuk'] * 100, 1) : 0 ?>%</td></tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot><tr><th>TOTAL</th><th class="num"><?= rp($d['total']['masuk']) ?></th><th class="num">100%</th></tr></tfoot>
  </table>

  <h3 class="mt">C. Buku Kas Masuk</h3>
  <table class="tb">
    <thead><tr><th>#</th><th>Tanggal</th><th>Kode</th><th>Penyewa</th><th>Cabang</th><th>Kamar</th><th>Periode</th><th>Metode</th><th class="num">Nominal</th></tr></thead>
    <tbody>
    <?php if (!$d['masuk']): ?>
      <tr><td colspan="9" class="center muted">Tidak ada pemasukan pada periode ini.</td></tr>
    <?php else: $i = 0; foreach ($d['masuk'] as $m): $i++; ?>
      <tr><td><?= $i ?></td><td><?= e(tgl($m['tgl_bayar'])) ?></td><td><?= e($m['kode']) ?></td>
        <td><?= e($m['nama']) ?></td><td><?= e($m['kost']) ?></td><td><?= e($m['kamar_kode']) ?></td>
        <td><?= e(periode_label($m['periode'])) ?></td><td><?= e(ucfirst($m['metode'])) ?></td>
        <td class="num"><?= rp($m['jumlah']) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot><tr><th colspan="8">TOTAL</th><th class="num"><?= rp($d['total']['masuk']) ?></th></tr></tfoot>
  </table>

  <h3 class="mt">D. Pengeluaran Kost</h3>
  <table class="tb">
    <thead><tr><th>#</th><th>Tanggal</th><th>Kode</th><th>Kategori</th><th>Uraian</th>
      <th>Cabang</th><th>Dicatat</th><th class="num">Nominal</th></tr></thead>
    <tbody>
    <?php if (!$d['kas_keluar']): ?>
      <tr><td colspan="8" class="center muted">Belum ada pengeluaran pada periode ini.</td></tr>
    <?php else: $i = 0; foreach ($d['kas_keluar'] as $m): $i++; ?>
      <tr><td><?= $i ?></td><td><?= e(tgl($m['tanggal'])) ?></td><td><?= e($m['kode']) ?></td>
        <td><?= e(ucfirst(str_replace('_', ' ', $m['kategori']))) ?></td>
        <td><?= e($m['keterangan']) ?></td><td><?= e($m['kost'] ?: 'Umum') ?></td>
        <td><?= e($m['pencatat'] ?: '-') ?><?= $m['sumber'] === 'wa' ? ' (WA)' : '' ?></td>
        <td class="num"><?= rp($m['jumlah']) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="7">TOTAL PENGELUARAN</th><th class="num"><?= rp($d['total']['keluar']) ?></th></tr>
      <tr><th colspan="7">SALDO (pemasukan &minus; pengeluaran)</th><th class="num"><?= rp($d['total']['saldo']) ?></th></tr>
    </tfoot>
  </table>

  <h3 class="mt">E. Status Tagihan</h3>
  <table class="tb">
    <thead><tr><th>Kode</th><th>Penyewa</th><th>Cabang</th><th>Kamar</th><th>Tempo</th>
      <th class="num">Sewa</th><th class="num">Denda</th><th class="num">Terbayar</th><th class="num">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$d['tagihan']): ?>
      <tr><td colspan="10" class="center muted">Tidak ada tagihan jatuh tempo pada periode ini.</td></tr>
    <?php else: foreach ($d['tagihan'] as $t):
        $tot = (float) $t['jumlah'] + (float) $t['denda']; $sisa = max(0, $tot - (float) $t['terbayar']); ?>
      <tr><td><?= e($t['kode']) ?></td><td><?= e($t['nama']) ?></td><td><?= e($t['kost']) ?></td>
        <td><?= e($t['kamar_kode']) ?></td>
        <td><?= e(tgl($t['jatuh_tempo'])) ?></td><td class="num"><?= rp($t['jumlah']) ?></td>
        <td class="num"><?= rp($t['denda']) ?></td><td class="num"><?= rp($t['terbayar']) ?></td>
        <td class="num"><?= rp($sisa) ?></td>
        <td><?= e(ucwords(str_replace('_', ' ', $t['status']))) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot><tr><th colspan="8">TOTAL PIUTANG</th><th class="num"><?= rp($d['total']['piutang']) ?></th><th></th></tr></tfoot>
  </table>

  <div class="spread" style="margin-top:40px">
    <div></div>
    <div class="center">
      <div class="small"><?= e(setting('alamat')) ?>, <?= e(tgl(date('Y-m-d'))) ?></div>
      <div class="small muted">Pengelola,</div>
      <div style="height:60px"></div>
      <div style="border-top:1px solid #ccc;padding-top:6px;min-width:190px"><b><?= e(setting('bank_an')) ?></b></div>
    </div>
  </div>
</div>
</body>
</html>
