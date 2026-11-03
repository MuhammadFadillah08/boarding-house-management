<?php
/** Riwayat & antrean pesan WhatsApp + uji kirim. */
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    if ($aksi === 'flush') {
        $n = wa_flush_queue(50);
        flash("$n pesan berhasil dikirim ulang.", $n ? 'success' : 'warn');
    }
    if ($aksi === 'kirim_ulang') {
        $r = one('SELECT * FROM wa_log WHERE id = ?', [(int) post('id')]);
        if ($r) {
            flash(wa_dispatch((int) $r['id'], $r['tujuan'], $r['pesan'])
                ? 'Pesan terkirim.' : 'Pengiriman masih gagal, cek kolom respons.',
                'info');
        }
    }
    if ($aksi === 'tes') {
        $hp = normalize_hp(post('tujuan'));
        $ok = wa_send($hp, "🔧 *Tes koneksi WhatsApp*\n\nJika pesan ini sampai, integrasi WA di "
            . setting('nama_kost') . " sudah berfungsi.\nWaktu uji: " . date('d/m/Y H:i'), 'tes', null, 'Tes');
        flash($ok ? 'Pesan uji diproses. Cek statusnya di tabel.' : 'Gagal mengirim pesan uji.', $ok ? 'success' : 'danger');
    }
    if ($aksi === 'bersihkan') {
        q('DELETE FROM wa_log WHERE status = "terkirim" AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        flash('Log terkirim lebih dari 30 hari dihapus.', 'info');
    }
    redirect('admin/wa_log.php?f=' . get('f'));
}

$f     = get('f', 'semua');
$list  = all('SELECT * FROM wa_log' . ($f !== 'semua' ? ' WHERE status = ?' : '')
           . ' ORDER BY id DESC LIMIT 200', $f !== 'semua' ? [$f] : []);
$antre = (int) scalar('SELECT COUNT(*) FROM wa_log WHERE status IN ("antre","gagal")', [], 0);
$drv   = setting('wa_driver');

layout_head('Log WhatsApp', 'admin', 'wa');
?>
<?php if ($drv === 'log'): ?>
  <div class="alert alert-info">Mode <b>LOG</b> aktif: pesan hanya dicatat, tidak benar-benar dikirim.
    Untuk mengaktifkan pengiriman nyata, isi driver &amp; token di
    <a href="<?= url('admin/pengaturan.php') ?>">Pengaturan</a>.</div>
<?php endif; ?>

<div class="grid g3 mb">
  <div class="stat s-sage"><div class="lbl">Driver Aktif</div><div class="val" style="font-size:1.2rem"><?= e(strtoupper($drv)) ?></div>
    <div class="sub"><?= e(setting('wa_endpoint')) ?></div></div>
  <div class="stat s-terra"><div class="lbl">Belum Terkirim</div><div class="val"><?= $antre ?></div>
    <div class="sub">antre + gagal</div></div>
  <div class="stat s-mist"><div class="lbl">Total Log</div>
    <div class="val"><?= (int) scalar('SELECT COUNT(*) FROM wa_log', [], 0) ?></div>
    <div class="sub">200 terbaru ditampilkan</div></div>
</div>

<div class="spread mb">
  <div class="seg">
    <?php foreach (['semua' => 'Semua', 'antre' => 'Antre', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal'] as $k => $v): ?>
      <a class="<?= $f === $k ? 'on' : '' ?>" href="?f=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <button class="btn btn-line" onclick='openModal("mTes")'>Kirim Pesan Uji</button>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="flush">
      <button class="btn btn-warn">Kirim Ulang Antrean</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="bersihkan">
      <button class="btn btn-soft" data-confirm="Hapus log terkirim yang lebih lama dari 30 hari?">Bersihkan</button></form>
  </div>
</div>

<section class="card">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('wa', 44) ?></span>Belum ada log pesan.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Waktu</th><th>Tujuan</th><th>Jenis</th><th>Pesan</th><th>Status</th><th>Respons</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $w): ?>
      <tr>
        <td class="small"><?= e(tgl($w['created_at'], true)) ?></td>
        <td class="small"><a target="_blank" href="https://wa.me/<?= e($w['tujuan']) ?>"><?= e($w['tujuan']) ?></a>
          <?= $w['nama'] ? '<br><span class="muted">' . e($w['nama']) . '</span>' : '' ?></td>
        <td class="small"><?= e($w['jenis']) ?></td>
        <td class="small" style="max-width:340px">
          <?= nl2br(e(mb_strimwidth($w['pesan'], 0, 150, '…'))) ?></td>
        <td><?= badge($w['status']) ?></td>
        <td class="small muted" style="max-width:200px"><?= e(mb_strimwidth((string) $w['response'], 0, 90, '…')) ?></td>
        <td><?php if ($w['status'] !== 'terkirim'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="kirim_ulang">
            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button class="btn btn-sm btn-soft"><?= icon('refresh', 15) ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="modal" id="mTes">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="tes">
    <div class="modal-head"><h3>Kirim Pesan Uji</h3>
      <button type="button" class="modal-x" onclick="closeModal('mTes')">&times;</button></div>
    <div class="modal-body">
      <div class="field"><label>Nomor Tujuan</label>
        <input type="text" name="tujuan" value="<?= e(setting('wa_admin')) ?>" required>
        <div class="hint">Format bebas (08…, 62…, +62…), akan dinormalisasi otomatis.</div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mTes')">Batal</button>
      <button class="btn">Kirim</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
