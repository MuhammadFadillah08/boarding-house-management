<?php
/** Form pengajuan booking + riwayat booking milik user. */
require_once __DIR__ . '/../app/bootstrap.php';
$u = require_user();

$kamarId = (int) get('kamar');
$err     = '';

// ---- Batalkan pengajuan --------------------------------------------
if (is_post() && post('aksi') === 'batal') {
    csrf_check();
    $b = one('SELECT * FROM booking WHERE id = ? AND user_id = ?', [(int) post('id'), $u['id']]);
    if ($b && $b['status'] === 'pending') {
        update('booking', ['status' => 'batal'], 'id = ?', [$b['id']]);
        update('kamar', ['status' => 'tersedia'], 'id = ? AND status = "dibooking"', [$b['kamar_id']]);
        flash('Pengajuan booking dibatalkan.', 'info');
    }
    redirect('user/booking.php');
}

// ---- Simpan pengajuan ----------------------------------------------
if (is_post() && post('aksi') === 'ajukan') {
    csrf_check();
    $kid    = (int) post('kamar_id');
    $masuk  = post('tgl_masuk');
    $durasi = max(1, min(24, (int) post('durasi')));
    $kamar  = one('SELECT * FROM kamar WHERE id = ?', [$kid]);

    if (!$kamar) {
        $err = 'Kamar tidak ditemukan.';
    } elseif ($kamar['status'] !== 'tersedia') {
        $err = 'Maaf, kamar ' . $kamar['kode'] . ' sudah tidak tersedia.';
    } elseif (!$masuk || strtotime($masuk) === false) {
        $err = 'Tanggal masuk tidak valid.';
    } elseif (strtotime($masuk) < strtotime(date('Y-m-d'))) {
        $err = 'Tanggal masuk tidak boleh sebelum hari ini.';
    } elseif (scalar('SELECT id FROM booking WHERE user_id = ? AND status = "pending"', [$u['id']])) {
        $err = 'Anda masih punya pengajuan yang menunggu konfirmasi.';
    } else {
        // Kunci kamar secara atomik. Tanpa ini, dua penyewa yang menekan tombol
        // bersamaan sama-sama lolos pengecekan di atas dan kamar jadi dobel-booking.
        $terkunci = q('UPDATE kamar SET status = "dibooking" WHERE id = ? AND status = "tersedia"',
                      [$kid])->rowCount();
        if ($terkunci === 0) {
            $err = 'Maaf, kamar ' . $kamar['kode'] . ' baru saja dipesan orang lain. Silakan pilih kamar lain.';
        } else {
            try {
                $id = insert('booking', [
                    'kode'      => kode_unik('BK', 'booking'),
                    'user_id'   => $u['id'],
                    'kamar_id'  => $kid,
                    'tgl_masuk' => $masuk,
                    'durasi'    => $durasi,
                    'catatan'   => post('catatan'),
                    'status'    => 'pending',
                ]);
            } catch (Throwable $ex) {
                // Gagal menyimpan booking -> lepaskan kembali kunci kamar.
                update('kamar', ['status' => 'tersedia'], 'id = ? AND status = "dibooking"', [$kid]);
                throw $ex;
            }
            notify_booking_masuk($id);   // -> WA ke admin + balasan ke user
            flash('Pengajuan terkirim! Pengelola akan menghubungi Anda via WhatsApp.', 'success');
            redirect('user/booking.php');
        }
    }
}

$kamarPilih = $kamarId
    ? one('SELECT m.*, ks.nama AS kost FROM kamar m JOIN kost ks ON ks.id = m.kost_id WHERE m.id = ?', [$kamarId])
    : null;
$tersedia = all('SELECT m.*, ks.nama AS kost FROM kamar m JOIN kost ks ON ks.id = m.kost_id
                 WHERE m.status = "tersedia" AND ks.status = "aktif" ORDER BY ks.urutan, m.kode');
$list = all('SELECT b.*, k.kode AS kamar_kode, k.tipe, k.harga, ks.nama AS kost
             FROM booking b JOIN kamar k ON k.id = b.kamar_id JOIN kost ks ON ks.id = k.kost_id
             WHERE b.user_id = ? ORDER BY b.id DESC', [$u['id']]);
$punyaKamar = (bool) scalar('SELECT id FROM penghuni WHERE user_id = ? AND status = "aktif"', [$u['id']]);

layout_head('Booking Saya', 'user', 'booking');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<?php if ($punyaKamar): ?>
  <div class="alert alert-info">Anda sedang menempati sebuah kamar. Hubungi pengelola bila ingin pindah kamar.</div>
<?php elseif (!$tersedia): ?>
  <div class="alert alert-warn">Saat ini semua kamar sedang penuh. Silakan cek kembali nanti.</div>
<?php else: ?>
<section class="card mb">
  <div class="card-head"><h3>Ajukan Booking Kamar</h3></div>
  <form class="card-pad" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="ajukan">
    <div class="form-grid">
      <div class="field">
        <label>Pilih Kamar *</label>
        <select name="kamar_id" required>
          <option value="">— pilih kamar —</option>
          <?php $kostSeb = ''; foreach ($tersedia as $k):
              if ($k['kost'] !== $kostSeb) { if ($kostSeb !== '') echo '</optgroup>';
                echo '<optgroup label="' . e($k['kost']) . '">'; $kostSeb = $k['kost']; } ?>
            <option value="<?= (int) $k['id'] ?>" <?= $kamarId === (int) $k['id'] ? 'selected' : '' ?>>
              <?= e($k['kode'] . ' · ' . $k['tipe'] . ' · ' . rp($k['harga']) . '/bln') ?>
            </option>
          <?php endforeach; if ($kostSeb !== '') echo '</optgroup>'; ?>
        </select>
      </div>
      <div class="field">
        <label>Tanggal Masuk *</label>
        <input type="date" name="tgl_masuk" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="field">
        <label>Durasi Sewa (bulan) *</label>
        <input type="number" name="durasi" value="1" min="1" max="24" required>
      </div>
    </div>
    <div class="field">
      <label>Catatan untuk Pengelola</label>
      <textarea name="catatan" rows="2" placeholder="Contoh: rencana tiba sore hari, butuh tambahan meja."><?= e(post('catatan')) ?></textarea>
    </div>
    <?php if ($kamarPilih): ?>
      <div class="alert alert-info">Kamar dipilih: <b><?= e($kamarPilih['kost'] . ' — ' . $kamarPilih['kode']) ?></b>,
        <?= e($kamarPilih['tipe']) ?>, <?= rp($kamarPilih['harga']) ?>/bulan.
        Fasilitas: <?= e($kamarPilih['fasilitas']) ?>.</div>
    <?php endif; ?>
    <button class="btn" type="submit">Kirim Pengajuan</button>
    <a class="btn btn-soft" href="<?= url('index.php') ?>">Lihat Katalog</a>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h3>Riwayat Pengajuan</h3></div>
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('booking', 44) ?></span>Belum ada pengajuan booking.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Kode</th><th>Kamar</th><th>Masuk</th><th>Durasi</th><th class="num">Harga</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $b): ?>
      <tr>
        <td class="small"><?= e($b['kode']) ?><br><span class="muted"><?= e(tgl($b['created_at'])) ?></span></td>
        <td><b><?= e($b['kamar_kode']) ?></b><br>
          <span class="small muted"><?= e($b['kost']) ?> · <?= e($b['tipe']) ?></span></td>
        <td><?= e(tgl($b['tgl_masuk'])) ?></td>
        <td><?= (int) $b['durasi'] ?> bln</td>
        <td class="num"><?= rp($b['harga']) ?></td>
        <td><?= badge($b['status']) ?>
          <?php if ($b['status'] === 'ditolak' && $b['alasan_tolak']): ?>
            <br><span class="small muted"><?= e($b['alasan_tolak']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($b['status'] === 'pending'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="batal">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm btn-soft" data-confirm="Batalkan pengajuan ini?">Batal</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php layout_foot('user'); ?>
