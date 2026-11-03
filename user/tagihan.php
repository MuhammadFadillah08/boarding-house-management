<?php
/** Riwayat tagihan + unggah bukti bayar. */
require_once __DIR__ . '/../app/bootstrap.php';
$u = require_user();

$huni = one('SELECT h.*, k.kode AS kamar_kode, k.tipe, ks.nama AS kost
             FROM penghuni h JOIN kamar k ON k.id = h.kamar_id JOIN kost ks ON ks.id = k.kost_id
             WHERE h.user_id = ? AND h.status = "aktif" ORDER BY h.id DESC LIMIT 1', [$u['id']]);
$err = '';

// ---- Unggah bukti bayar --------------------------------------------
if (is_post() && post('aksi') === 'bayar') {
    csrf_check();
    $tid = (int) post('tagihan_id');
    $t   = one('SELECT t.* FROM tagihan t JOIN penghuni h ON h.id = t.penghuni_id
                WHERE t.id = ? AND h.user_id = ?', [$tid, $u['id']]);

    if (!$t) {
        $err = 'Tagihan tidak ditemukan.';
    } elseif ($t['status'] === 'lunas') {
        $err = 'Tagihan ini sudah lunas.';
    } elseif ($t['status'] === 'menunggu_verifikasi') {
        $err = 'Bukti bayar sebelumnya masih menunggu verifikasi pengelola.';
    } else {
        [$ok, $res] = upload_image('bukti', UPLOAD_DIR, 'bukti');
        if (!$ok) {
            $err = $res;
        } else {
            $id = insert('pembayaran', [
                'kode'       => kode_unik('PAY', 'pembayaran'),
                'tagihan_id' => $tid,
                'user_id'    => $u['id'],
                'jumlah'     => (float) post('jumlah'),
                'metode'     => in_array(post('metode'), ['transfer', 'tunai', 'ewallet'], true) ? post('metode') : 'transfer',
                'tgl_bayar'  => post('tgl_bayar') ?: date('Y-m-d'),
                'bukti'      => $res,
                'catatan'    => post('catatan'),
                'status'     => 'pending',
            ]);
            update('tagihan', ['status' => 'menunggu_verifikasi'], 'id = ?', [$tid]);
            notify_pembayaran_masuk($id);  // -> WA instan ke admin
            flash('Bukti pembayaran terkirim. Menunggu verifikasi pengelola.', 'success');
            redirect('user/tagihan.php');
        }
    }
}

$tagihan = $huni
    ? all('SELECT * FROM tagihan WHERE penghuni_id = ? ORDER BY periode DESC', [$huni['id']])
    : [];
$bayar = [];
foreach (all('SELECT * FROM pembayaran WHERE user_id = ? ORDER BY id DESC', [$u['id']]) as $p) {
    $bayar[$p['tagihan_id']][] = $p;
}
$totalLunas = array_sum(array_map(fn($t) => $t['status'] === 'lunas' ? (float) $t['jumlah'] + (float) $t['denda'] : 0, $tagihan));
$totalUtang = array_sum(array_map(fn($t) => in_array($t['status'], ['belum_bayar', 'terlambat'], true) ? (float) $t['jumlah'] + (float) $t['denda'] : 0, $tagihan));

layout_head('Tagihan Saya', 'user', 'tagihan');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<?php if (!$huni): ?>
  <div class="card card-pad empty"><span class="big"><?= icon('tagihan', 44) ?></span>
    Anda belum menempati kamar, jadi belum ada tagihan.<br>
    <a class="btn mt" href="<?= url('index.php') ?>">Lihat Katalog Kamar</a>
  </div>
<?php else: ?>

<div class="grid g3 mb">
  <div class="stat s-sage reveal"><div class="lbl">Kamar</div>
    <div class="val"><?= e($huni['kamar_kode']) ?></div>
    <div class="sub"><?= e($huni['kost']) ?> · <?= e($huni['tipe']) ?></div></div>
  <div class="stat s-terra reveal" style="--d:.06s"><div class="lbl">Belum Dibayar</div>
    <div class="val"><span data-count="<?= (int) $totalUtang ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">termasuk denda</div></div>
  <div class="stat s-mist reveal" style="--d:.12s"><div class="lbl">Total Dibayar</div>
    <div class="val"><span data-count="<?= (int) $totalLunas ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub"><?= count($tagihan) ?> tagihan tercatat</div></div>
</div>

<div class="card card-pad mb">
  <b>Rekening pembayaran:</b>
  <?= e(setting('bank_nama')) ?> <b><?= e(setting('bank_rek')) ?></b> a.n. <?= e(setting('bank_an')) ?>
  &middot; <span class="muted small">Denda keterlambatan <?= rp(setting('denda_per_hari', '0')) ?>/hari.</span>
</div>

<section class="card">
  <div class="card-head"><h3>Riwayat Tagihan</h3>
    <input class="inp" style="max-width:220px" placeholder="Cari periode…" data-filter="#tg tr">
  </div>
  <?php if (!$tagihan): ?>
    <div class="empty"><span class="big"><?= icon('tagihan', 44) ?></span>Belum ada tagihan diterbitkan.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>No. Tagihan</th><th>Periode</th><th>Jatuh Tempo</th><th class="num">Sewa</th>
      <th class="num">Denda</th><th class="num">Total</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tg">
    <?php foreach ($tagihan as $t):
        $total = (float) $t['jumlah'] + (float) $t['denda'];
        $last  = $bayar[$t['id']][0] ?? null; ?>
      <tr>
        <td class="small"><?= e($t['kode']) ?></td>
        <td><b><?= e(periode_label($t['periode'])) ?></b></td>
        <td><?= e(tgl($t['jatuh_tempo'])) ?></td>
        <td class="num"><?= rp($t['jumlah']) ?></td>
        <td class="num"><?= (float) $t['denda'] > 0 ? '<span style="color:var(--danger)">' . rp($t['denda']) . '</span>' : '—' ?></td>
        <td class="num"><b><?= rp($total) ?></b></td>
        <td><?= badge($t['status']) ?>
          <?php if ($last && $last['status'] === 'ditolak'): ?>
            <br><span class="small" style="color:var(--danger)">Bukti ditolak</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($t['status'] === 'lunas'): ?>
            <a class="btn btn-sm btn-line" target="_blank" href="<?= url('user/kuitansi.php?id=' . (int) $t['id']) ?>">Kuitansi</a>
          <?php elseif ($t['status'] === 'menunggu_verifikasi'): ?>
            <span class="small muted">Diproses…</span>
          <?php else: ?>
            <button class="btn btn-sm" onclick='openModal("mBayar", <?= json_encode([
                "tagihan_id" => $t["id"], "jumlah" => $total,
                "info" => periode_label($t["periode"]) . " · " . $t["kode"] . " · " . rp($total),
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Bayar</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php foreach ($bayar[$t['id']] ?? [] as $p): ?>
      <tr style="background:var(--cream)">
        <td colspan="8" class="small muted">
          ↳ <?= e($p['kode']) ?> · <?= e(tgl($p['tgl_bayar'])) ?> · <?= rp($p['jumlah']) ?> · <?= e(ucfirst($p['metode'])) ?>
          · <?= badge($p['status']) ?>
          <?php if ($p['bukti']): ?> · <a target="_blank" href="<?= e(UPLOAD_URL . $p['bukti']) ?>">lihat bukti</a><?php endif; ?>
          <?= $p['catatan'] ? ' · ' . e($p['catatan']) : '' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<!-- Modal unggah bukti -->
<div class="modal" id="mBayar">
  <form class="modal-box" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="bayar">
    <input type="hidden" name="tagihan_id" data-f="tagihan_id">
    <div class="modal-head"><h3>Unggah Bukti Pembayaran</h3>
      <button type="button" class="modal-x" onclick="closeModal('mBayar')">&times;</button></div>
    <div class="modal-body">
      <div class="alert alert-info" data-f="info"></div>
      <div class="form-grid">
        <div class="field"><label>Nominal Dibayar *</label>
          <input type="number" name="jumlah" data-f="jumlah" step="1000" required></div>
        <div class="field"><label>Tanggal Bayar *</label>
          <input type="date" name="tgl_bayar" value="<?= date('Y-m-d') ?>" required></div>
      </div>
      <div class="field"><label>Metode</label>
        <select name="metode">
          <option value="transfer">Transfer Bank</option>
          <option value="ewallet">E-Wallet</option>
          <option value="tunai">Tunai</option>
        </select></div>
      <div class="field"><label>Foto/Screenshot Bukti Transfer *</label>
        <input type="file" name="bukti" accept="image/*" data-preview="prev" required>
        <div class="hint">JPG/PNG/WEBP, maksimal <?= round(MAX_UPLOAD / 1048576, 1) ?> MB.</div>
        <div id="prev"></div></div>
      <div class="field"><label>Catatan</label>
        <input type="text" name="catatan" placeholder="opsional"></div>
      <div class="alert alert-warn">Transfer ke <?= e(setting('bank_nama')) ?>
        <b><?= e(setting('bank_rek')) ?></b> a.n. <?= e(setting('bank_an')) ?>.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mBayar')">Batal</button>
      <button class="btn" type="submit">Kirim Bukti</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot('user'); ?>
