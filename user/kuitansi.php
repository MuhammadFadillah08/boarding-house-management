<?php
/** Kuitansi/bukti lunas — siap cetak atau simpan sebagai PDF dari browser. */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$id = (int) get('id');
$t  = tagihan_detail($id);
if (!$t) { http_response_code(404); exit('Tagihan tidak ditemukan.'); }

// Penyewa hanya boleh melihat kuitansi miliknya sendiri
$pemilik = (int) scalar('SELECT h.user_id FROM tagihan t JOIN penghuni h ON h.id = t.penghuni_id WHERE t.id = ?', [$id], 0);
if ($u['role'] !== 'admin' && $pemilik !== (int) $u['id']) { http_response_code(403); exit('403 - Akses ditolak.'); }

$bayar = all('SELECT * FROM pembayaran WHERE tagihan_id = ? AND status = "diterima" ORDER BY id', [$id]);
$total = (float) $t['jumlah'] + (float) $t['denda'];
$lunas = $t['status'] === 'lunas';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kuitansi <?= e($t['kode']) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body style="background:var(--cream-2)">
<div class="no-print center" style="padding:18px">
  <button class="btn" onclick="window.print()"><?= icon('cetak', 17) ?> Cetak / Simpan PDF</button>
  <a class="btn btn-soft" href="<?= url($u['role'] === 'admin' ? 'admin/tagihan.php' : 'user/tagihan.php') ?>">Kembali</a>
</div>

<div class="paper">
  <div class="paper-head">
    <div>
      <div class="paper-title"><?= e(setting('nama_kost')) ?></div>
      <div class="small muted"><?= e($t['kost']) ?> — <?= e($t['kost_alamat']) ?><br>WA: <?= e(setting('wa_admin')) ?></div>
    </div>
    <div class="right">
      <div style="font-weight:800;letter-spacing:.1em"><?= $lunas ? 'KUITANSI' : 'INVOICE' ?></div>
      <div class="small muted">No. <?= e($t['kode']) ?><br>Dicetak <?= e(tgl(date('Y-m-d'), true)) ?></div>
    </div>
  </div>

  <div class="grid g2">
    <div>
      <b class="small muted">DITAGIHKAN KEPADA</b>
      <div class="kv mt">
        <b>Nama</b><span><?= e($t['nama']) ?></span>
        <b>No. WhatsApp</b><span><?= e($t['no_hp']) ?></span>
        <b>Cabang</b><span><?= e($t['kost']) ?></span>
        <b>Kamar</b><span><?= e($t['kamar']) ?> (<?= e($t['tipe']) ?>)</span>
        <b>Mulai huni</b><span><?= e(tgl($t['tgl_masuk'])) ?></span>
      </div>
    </div>
    <div>
      <b class="small muted">RINCIAN</b>
      <div class="kv mt">
        <b>Periode</b><span><?= e(periode_label($t['periode'])) ?></span>
        <b>Jatuh tempo</b><span><?= e(tgl($t['jatuh_tempo'])) ?></span>
        <b>Status</b><span><?= badge($t['status']) ?></span>
        <b>Tgl lunas</b><span><?= $t['tgl_lunas'] ? e(tgl($t['tgl_lunas'], true)) : '—' ?></span>
      </div>
    </div>
  </div>

  <table class="tb mt">
    <thead><tr><th>Deskripsi</th><th class="num">Jumlah</th></tr></thead>
    <tbody>
      <tr><td>Sewa kamar <?= e($t['kamar']) ?> (<?= e($t['kost']) ?>) — <?= e(periode_label($t['periode'])) ?></td>
          <td class="num"><?= rp($t['jumlah']) ?></td></tr>
      <?php if ((float) $t['denda'] > 0): ?>
      <tr><td>Denda keterlambatan</td><td class="num"><?= rp($t['denda']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="total-row"><span>TOTAL</span><span><?= rp($total) ?></span></div>

  <?php if ($bayar): ?>
    <h3 class="mt">Pembayaran Diterima</h3>
    <table class="tb">
      <thead><tr><th>Kode</th><th>Tanggal</th><th>Metode</th><th class="num">Nominal</th></tr></thead>
      <tbody>
      <?php foreach ($bayar as $p): ?>
        <tr><td><?= e($p['kode']) ?></td><td><?= e(tgl($p['tgl_bayar'])) ?></td>
            <td><?= e(ucfirst($p['metode'])) ?></td><td class="num"><?= rp($p['jumlah']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="spread mt" style="margin-top:34px">
    <div><?php if ($lunas): ?><span class="stamp">LUNAS</span><?php endif; ?></div>
    <div class="center">
      <div class="small muted">Pengelola,</div>
      <div style="height:56px"></div>
      <div style="border-top:1px solid var(--line);padding-top:6px"><b><?= e(setting('bank_an')) ?></b></div>
    </div>
  </div>

  <p class="small muted center mt" style="margin-top:26px">
    Dokumen ini dicetak dari sistem <?= e(setting('nama_kost')) ?> dan sah tanpa tanda tangan basah.
  </p>
</div>
</body>
</html>
