<?php
/** Verifikasi pengajuan booking. Disetujui -> jadi penghuni + tagihan pertama. */
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $b  = one('SELECT * FROM booking WHERE id = ?', [$id]);

    if ($b && $b['status'] === 'pending' && post('aksi') === 'setujui') {
        db()->beginTransaction();
        try {
            update('booking', ['status' => 'disetujui'], 'id = ?', [$id]);
            update('kamar', ['status' => 'terisi'], 'id = ?', [$b['kamar_id']]);

            $penghuniId = insert('penghuni', [
                'user_id'    => $b['user_id'],
                'kamar_id'   => $b['kamar_id'],
                'booking_id' => $b['id'],
                'tgl_masuk'  => $b['tgl_masuk'],
                'tgl_tagih'  => (int) date('j', strtotime($b['tgl_masuk'])),
                'status'     => 'aktif',
            ]);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Gagal memproses: ' . $ex->getMessage(), 'danger');
            redirect('admin/booking.php');
        }

        notify_booking_status($id);
        // Tagihan pertama untuk bulan tanggal masuk
        generate_tagihan($penghuniId, date('Y-m', strtotime($b['tgl_masuk'])));
        flash('Booking disetujui. Penghuni & tagihan pertama dibuat, notifikasi WA terkirim.');
    }

    if ($b && $b['status'] === 'pending' && post('aksi') === 'tolak') {
        update('booking', ['status' => 'ditolak', 'alasan_tolak' => post('alasan')], 'id = ?', [$id]);
        update('kamar', ['status' => 'tersedia'], 'id = ? AND status = "dibooking"', [$b['kamar_id']]);
        notify_booking_status($id);
        flash('Booking ditolak dan penyewa sudah diberi tahu via WhatsApp.', 'info');
    }
    redirect('admin/booking.php?f=' . get('f'));
}

$f     = get('f', 'pending');
$where = $f && $f !== 'semua' ? 'WHERE b.status = ?' : '';
$par   = $where ? [$f] : [];
$list  = all("SELECT b.*, u.nama, u.no_hp, k.kode AS kamar_kode, k.tipe, k.harga, ks.nama AS kost
              FROM booking b
              JOIN users u ON u.id = b.user_id
              JOIN kamar k ON k.id = b.kamar_id
              JOIN kost ks ON ks.id = k.kost_id
              $where ORDER BY FIELD(b.status,'pending') DESC, b.id DESC", $par);

layout_head('Pengajuan Booking', 'admin', 'booking');
?>
<div class="spread mb">
  <div class="seg">
    <?php foreach (['pending' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'semua' => 'Semua'] as $k => $v): ?>
      <a class="<?= $f === $k ? 'on' : '' ?>" href="?f=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <input class="inp" style="max-width:250px" placeholder="Cari nama / kamar…" data-filter="#tb tr">
</div>

<section class="card">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('booking', 44) ?></span>Tidak ada data booking pada filter ini.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Kode</th><th>Penyewa</th><th>Kamar</th><th>Masuk</th><th>Durasi</th>
      <th>Catatan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tb">
    <?php foreach ($list as $b): ?>
      <tr>
        <td class="small"><?= e($b['kode']) ?><br><span class="muted"><?= e(tgl($b['created_at'], true)) ?></span></td>
        <td><b><?= e($b['nama']) ?></b><br>
          <a class="small" href="https://wa.me/<?= e($b['no_hp']) ?>" target="_blank"><?= e($b['no_hp']) ?></a></td>
        <td><b><?= e($b['kamar_kode']) ?></b><br>
          <span class="small muted"><?= e($b['kost']) ?> · <?= e($b['tipe']) ?><br><?= rp($b['harga']) ?></span></td>
        <td class="small"><?= e(tgl($b['tgl_masuk'])) ?></td>
        <td><?= (int) $b['durasi'] ?> bln</td>
        <td class="small muted"><?= e(mb_strimwidth((string) $b['catatan'], 0, 40, '…')) ?: '—' ?></td>
        <td><?= badge($b['status']) ?>
          <?php if ($b['alasan_tolak']): ?><br><span class="small muted"><?= e($b['alasan_tolak']) ?></span><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php if ($b['status'] === 'pending'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="setujui">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm" data-confirm="Setujui booking <?= e($b['kode']) ?>? Kamar akan ditandai terisi dan tagihan pertama dibuat.">Setujui</button>
            </form>
            <button class="btn btn-sm btn-danger" onclick='openModal("mTolak", <?= json_encode([
              'id' => $b['id'], 'info' => $b['nama'] . ' — kamar ' . $b['kamar_kode'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Tolak</button>
          <?php else: ?><span class="muted small">—</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="modal" id="mTolak">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="tolak">
    <input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3>Tolak Booking</h3>
      <button type="button" class="modal-x" onclick="closeModal('mTolak')">&times;</button></div>
    <div class="modal-body">
      <p class="mb" data-f="info"></p>
      <div class="field"><label>Alasan Penolakan</label>
        <textarea name="alasan" rows="3" placeholder="Contoh: kamar sudah dipesan calon penyewa lain."></textarea>
        <div class="hint">Alasan ini dikirimkan ke penyewa melalui WhatsApp.</div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mTolak')">Batal</button>
      <button class="btn btn-danger">Tolak Booking</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
