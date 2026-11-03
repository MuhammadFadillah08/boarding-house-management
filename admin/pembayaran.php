<?php
/** Verifikasi bukti pembayaran dari penyewa. */
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $p  = one('SELECT * FROM pembayaran WHERE id = ?', [$id]);

    if ($p && $p['status'] === 'pending') {
        if (post('aksi') === 'terima') {
            update('pembayaran', [
                'status'   => 'diterima',
                'verif_by' => $admin['id'],
                'verif_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            $t = one('SELECT * FROM tagihan WHERE id = ?', [$p['tagihan_id']]);
            $terbayar = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran
                                        WHERE tagihan_id = ? AND status = "diterima"', [$p['tagihan_id']], 0);
            $total = (float) $t['jumlah'] + (float) $t['denda'];

            if ($terbayar + 0.01 >= $total) {
                update('tagihan', ['status' => 'lunas', 'tgl_lunas' => date('Y-m-d H:i:s')], 'id = ?', [$t['id']]);
                flash('Pembayaran diterima. Tagihan LUNAS & bukti lunas dikirim via WhatsApp.');
            } else {
                // Pembayaran sebagian: kembalikan ke status semula, dan tetap
                // "terlambat" bila memang sudah lewat jatuh tempo.
                $st = $t['jatuh_tempo'] < date('Y-m-d') ? 'terlambat' : 'belum_bayar';
                update('tagihan', ['status' => $st], 'id = ?', [$t['id']]);
                flash('Pembayaran diterima sebagian. Sisa ' . rp($total - $terbayar) . ' belum terbayar.', 'warn');
            }
            notify_pembayaran_verif($id);

        } elseif (post('aksi') === 'tolak') {
            update('pembayaran', [
                'status'   => 'ditolak',
                'catatan'  => post('alasan') ?: $p['catatan'],
                'verif_by' => $admin['id'],
                'verif_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);
            // kembalikan tagihan agar user bisa unggah ulang
            $st = scalar('SELECT IF(jatuh_tempo < CURDATE(), "terlambat", "belum_bayar") FROM tagihan WHERE id = ?',
                         [$p['tagihan_id']], 'belum_bayar');
            update('tagihan', ['status' => $st], 'id = ?', [$p['tagihan_id']]);
            notify_pembayaran_verif($id);
            flash('Bukti pembayaran ditolak. Penyewa diberi tahu via WhatsApp.', 'info');
        }
    }
    redirect('admin/pembayaran.php?f=' . get('f'));
}

$f    = get('f', 'pending');
$list = all('SELECT p.*, u.nama, u.no_hp, t.kode AS kode_tagihan, t.periode, t.jumlah AS tagihan_jumlah,
             t.denda, k.kode AS kamar_kode, ks.nama AS kost, a.nama AS verifikator
             FROM pembayaran p
             JOIN users u    ON u.id = p.user_id
             JOIN tagihan t  ON t.id = p.tagihan_id
             JOIN penghuni h ON h.id = t.penghuni_id
             JOIN kamar k    ON k.id = h.kamar_id
             JOIN kost  ks   ON ks.id = k.kost_id
             LEFT JOIN users a ON a.id = p.verif_by'
           . ($f !== 'semua' ? ' WHERE p.status = ?' : '')
           . ' ORDER BY FIELD(p.status,"pending") DESC, p.id DESC', $f !== 'semua' ? [$f] : []);

layout_head('Verifikasi Pembayaran', 'admin', 'pembayaran');
?>
<div class="spread mb">
  <div class="seg">
    <?php foreach (['pending' => 'Perlu Verifikasi', 'diterima' => 'Diterima', 'ditolak' => 'Ditolak', 'semua' => 'Semua'] as $k => $v): ?>
      <a class="<?= $f === $k ? 'on' : '' ?>" href="?f=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <input class="inp" style="max-width:250px" placeholder="Cari nama / kode…" data-filter="#tp tr">
</div>

<section class="card">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('pembayaran', 44) ?></span>Tidak ada data pada filter ini.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Kode</th><th>Penyewa</th><th>Tagihan</th><th>Tgl Bayar</th>
      <th class="num">Nominal</th><th>Bukti</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tp">
    <?php foreach ($list as $p): $tot = (float) $p['tagihan_jumlah'] + (float) $p['denda']; ?>
      <tr>
        <td class="small"><?= e($p['kode']) ?><br><span class="muted"><?= e(tgl($p['created_at'], true)) ?></span></td>
        <td><b><?= e($p['nama']) ?></b><br>
          <a class="small" target="_blank" href="https://wa.me/<?= e($p['no_hp']) ?>"><?= e($p['no_hp']) ?></a></td>
        <td class="small"><?= e($p['kode_tagihan']) ?><br>
          <span class="muted"><?= e($p['kost'] . ' ' . $p['kamar_kode']) ?> · <?= e(periode_label($p['periode'])) ?> · <?= rp($tot) ?></span></td>
        <td class="small"><?= e(tgl($p['tgl_bayar'])) ?><br><span class="muted"><?= e(ucfirst($p['metode'])) ?></span></td>
        <td class="num"><b><?= rp($p['jumlah']) ?></b>
          <?php if ((float) $p['jumlah'] < $tot): ?><br><span class="small" style="color:var(--danger)">kurang <?= rp($tot - (float) $p['jumlah']) ?></span><?php endif; ?></td>
        <td><?php if ($p['bukti'] && is_file(UPLOAD_DIR . $p['bukti'])): ?>
              <a target="_blank" href="<?= e(UPLOAD_URL . $p['bukti']) ?>">
                <img src="<?= e(UPLOAD_URL . $p['bukti']) ?>" alt="bukti" style="width:54px;height:54px;object-fit:cover;border-radius:8px">
              </a>
            <?php else: ?><span class="muted small">—</span><?php endif; ?></td>
        <td><?= badge($p['status']) ?>
          <?php if ($p['verifikator']): ?><br><span class="small muted">oleh <?= e($p['verifikator']) ?></span><?php endif; ?>
          <?php if ($p['catatan']): ?><br><span class="small muted"><?= e($p['catatan']) ?></span><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php if ($p['status'] === 'pending'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="terima"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm" data-confirm="Terima pembayaran <?= rp($p['jumlah']) ?> dari <?= e($p['nama']) ?>?">Terima</button></form>
            <button class="btn btn-sm btn-danger" onclick='openModal("mTolak", <?= json_encode([
              'id' => $p['id'], 'info' => $p['nama'] . ' — ' . $p['kode_tagihan'] . ' — ' . rp($p['jumlah']),
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Tolak</button>
          <?php else: ?><span class="muted small">selesai</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="modal" id="mTolak">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3>Tolak Bukti Pembayaran</h3>
      <button type="button" class="modal-x" onclick="closeModal('mTolak')">&times;</button></div>
    <div class="modal-body">
      <p class="mb" data-f="info"></p>
      <div class="field"><label>Alasan Penolakan</label>
        <textarea name="alasan" rows="3" placeholder="Contoh: bukti transfer tidak terbaca / nominal tidak sesuai."></textarea>
        <div class="hint">Dikirim ke penyewa lewat WhatsApp agar bisa mengunggah ulang.</div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mTolak')">Batal</button>
      <button class="btn btn-danger">Tolak</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
