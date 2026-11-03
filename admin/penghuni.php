<?php
/** CRUD Penghuni (termasuk tambah penghuni manual tanpa lewat booking). */
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
$err = '';

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    // --- Tambah penghuni manual: buat akun user + tempati kamar --------
    if ($aksi === 'tambah') {
        $email = strtolower(post('email'));
        $hp    = normalize_hp(post('no_hp'));
        $kid   = (int) post('kamar_id');
        $masuk = post('tgl_masuk') ?: date('Y-m-d');

        if (post('nama') === '' || strlen($hp) < 10 || !$kid) {
            $err = 'Nama, nomor WhatsApp, dan kamar wajib diisi.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Format email tidak valid.';
        } elseif ($email !== '' && scalar('SELECT id FROM users WHERE email = ?', [$email])) {
            $err = 'Email tersebut sudah dipakai akun lain.';
        } else {
            // Akun dicocokkan berdasarkan nomor WhatsApp
            $uid = (int) scalar('SELECT id FROM users WHERE no_hp = ?', [$hp], 0);
            if (!$uid) {
                $uid = insert('users', [
                    'nama'     => post('nama'),
                    'no_hp'    => $hp,
                    'email'    => $email !== '' ? $email : null,
                    'password' => password_hash(post('password') ?: 'changeme123', PASSWORD_BCRYPT),
                    'no_ktp'   => post('no_ktp'),
                    'alamat'   => post('alamat'),
                    'role'     => 'user',
                ]);
            }
            if (scalar('SELECT id FROM penghuni WHERE user_id = ? AND status = "aktif"', [$uid])) {
                $err = 'Orang ini sudah tercatat menempati kamar lain.';
            } else {
                $pid = insert('penghuni', [
                    'user_id'   => $uid,
                    'kamar_id'  => $kid,
                    'tgl_masuk' => $masuk,
                    'tgl_tagih' => max(1, min(28, (int) post('tgl_tagih') ?: (int) date('j', strtotime($masuk)))),
                    'status'    => 'aktif',
                ]);
                update('kamar', ['status' => 'terisi'], 'id = ?', [$kid]);
                if (post('buat_tagihan') === '1') {
                    generate_tagihan($pid, date('Y-m', strtotime($masuk)));
                }
                flash('Penghuni baru ditambahkan.' . (post('buat_tagihan') === '1' ? ' Tagihan pertama diterbitkan.' : ''));
                redirect('admin/penghuni.php');
            }
        }
    }

    // --- Ubah data kontrak --------------------------------------------
    if ($aksi === 'edit') {
        $pid = (int) post('id');
        update('penghuni', [
            'tgl_masuk' => post('tgl_masuk'),
            'tgl_tagih' => max(1, min(28, (int) post('tgl_tagih'))),
        ], 'id = ?', [$pid]);
        flash('Data penghuni diperbarui.');
        redirect('admin/penghuni.php');
    }

    // --- Check-out -----------------------------------------------------
    if ($aksi === 'keluar') {
        $pid = (int) post('id');
        $p   = one('SELECT * FROM penghuni WHERE id = ?', [$pid]);
        if ($p) {
            $sisa = (float) scalar('SELECT COALESCE(SUM(jumlah+denda),0) FROM tagihan
                                    WHERE penghuni_id = ? AND status IN ("belum_bayar","terlambat")', [$pid], 0);
            update('penghuni', ['status' => 'keluar', 'tgl_keluar' => post('tgl_keluar') ?: date('Y-m-d')], 'id = ?', [$pid]);
            update('kamar', ['status' => 'tersedia'], 'id = ?', [$p['kamar_id']]);
            $u = one('SELECT nama, no_hp FROM users WHERE id = ?', [$p['user_id']]);
            if ($u) {
                wa_send($u['no_hp'], "Halo *{$u['nama']}*,\n\nTerima kasih telah menjadi bagian dari *"
                    . setting('nama_kost') . "*. Data kontrak sewa Anda telah kami tutup per "
                    . tgl(date('Y-m-d')) . "."
                    . ($sisa > 0 ? "\n\n⚠️ Catatan: masih terdapat sisa tagihan sebesar *" . rp($sisa) . "*." : '')
                    . "\n\nSemoga sukses selalu 🙏", 'checkout', $pid, $u['nama']);
            }
            flash('Penghuni ditandai keluar dan kamar dikembalikan ke status tersedia.'
                . ($sisa > 0 ? ' Perhatian: masih ada tunggakan ' . rp($sisa) . '.' : ''),
                $sisa > 0 ? 'warn' : 'success');
        }
        redirect('admin/penghuni.php');
    }
}

$f    = get('f', 'aktif');
$list = all('SELECT h.*, u.nama, u.email, u.no_hp, k.kode AS kamar_kode, k.tipe, k.harga,
             ks.nama AS kost,
             (SELECT COALESCE(SUM(t.jumlah+t.denda),0) FROM tagihan t
              WHERE t.penghuni_id = h.id AND t.status IN ("belum_bayar","terlambat")) AS tunggakan
             FROM penghuni h
             JOIN users u ON u.id = h.user_id
             JOIN kamar k ON k.id = h.kamar_id
             JOIN kost ks ON ks.id = k.kost_id'
             . ($f !== 'semua' ? ' WHERE h.status = ?' : '')
             . ' ORDER BY h.status ASC, ks.urutan, k.kode', $f !== 'semua' ? [$f] : []);
$kamarKosong = all('SELECT m.*, ks.nama AS kost FROM kamar m JOIN kost ks ON ks.id = m.kost_id
                    WHERE m.status IN ("tersedia","dibooking") ORDER BY ks.urutan, m.kode');

layout_head('Data Penghuni', 'admin', 'penghuni');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="spread mb">
  <div class="seg">
    <?php foreach (['aktif' => 'Aktif', 'keluar' => 'Sudah Keluar', 'semua' => 'Semua'] as $k => $v): ?>
      <a class="<?= $f === $k ? 'on' : '' ?>" href="?f=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <input class="inp" style="max-width:230px" placeholder="Cari penghuni…" data-filter="#tp tr">
    <button class="btn" onclick='openModal("mTambah")'>+ Tambah Penghuni</button>
  </div>
</div>

<section class="card">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('penghuni', 44) ?></span>Belum ada data penghuni.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Nama</th><th>Kontak</th><th>Kamar</th><th>Masuk</th><th>Tgl Tagih</th>
      <th class="num">Tunggakan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tp">
    <?php foreach ($list as $p): ?>
      <tr>
        <td><b><?= e($p['nama']) ?></b><br><span class="small muted"><?= e($p['email'] ?: '—') ?></span></td>
        <td><a class="small" target="_blank" href="https://wa.me/<?= e($p['no_hp']) ?>"><?= e($p['no_hp']) ?></a></td>
        <td><b><?= e($p['kamar_kode']) ?></b><br>
          <span class="small muted"><?= e($p['kost']) ?> · <?= rp($p['harga']) ?>/bln</span></td>
        <td class="small"><?= e(tgl($p['tgl_masuk'])) ?>
          <?= $p['tgl_keluar'] ? '<br><span class="muted">keluar ' . e(tgl($p['tgl_keluar'])) . '</span>' : '' ?></td>
        <td>tgl <?= (int) $p['tgl_tagih'] ?></td>
        <td class="num"><?= (float) $p['tunggakan'] > 0
            ? '<b style="color:var(--danger)">' . rp($p['tunggakan']) . '</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= badge($p['status']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($p['status'] === 'aktif'): ?>
            <button class="btn btn-sm btn-soft" onclick='openModal("mEdit", <?= json_encode([
              'id' => $p['id'], 'tgl_masuk' => $p['tgl_masuk'], 'tgl_tagih' => $p['tgl_tagih'],
              'info' => $p['nama'] . ' — kamar ' . $p['kamar_kode'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
            <button class="btn btn-sm btn-warn" onclick='openModal("mKeluar", <?= json_encode([
              'id' => $p['id'], 'info' => $p['nama'] . ' — kamar ' . $p['kamar_kode'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Check-out</button>
          <?php endif; ?>
          <a class="btn btn-sm btn-line" href="<?= url('admin/tagihan.php?penghuni=' . (int) $p['id']) ?>">Tagihan</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<!-- Tambah penghuni -->
<div class="modal" id="mTambah">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
    <div class="modal-head"><h3>Tambah Penghuni</h3>
      <button type="button" class="modal-x" onclick="closeModal('mTambah')">&times;</button></div>
    <div class="modal-body">
      <p class="small muted mb">Akun penyewa dibuat otomatis dengan nomor WhatsApp sebagai identitas login.
        Bila nomor sudah terdaftar, akun lama yang dipakai.</p>
      <div class="form-grid">
        <div class="field"><label>Nama Lengkap *</label><input type="text" name="nama" required></div>
        <div class="field"><label>No. WhatsApp *</label><input type="text" name="no_hp" placeholder="081234567890" required></div>
        <div class="field"><label>Email <span class="muted">(opsional)</span></label><input type="email" name="email"></div>
        <div class="field"><label>No. KTP</label><input type="text" name="no_ktp"></div>
        <div class="field"><label>Kamar *</label>
          <select name="kamar_id" required>
            <option value="">— pilih kamar —</option>
            <?php $kostSeb = ''; foreach ($kamarKosong as $k):
                if ($k['kost'] !== $kostSeb) { if ($kostSeb !== '') echo '</optgroup>';
                  echo '<optgroup label="' . e($k['kost']) . '">'; $kostSeb = $k['kost']; } ?>
              <option value="<?= (int) $k['id'] ?>"><?= e($k['kode'] . ' · ' . $k['tipe'] . ' · ' . rp($k['harga'])) ?></option>
            <?php endforeach; if ($kostSeb !== '') echo '</optgroup>'; ?>
          </select></div>
        <div class="field"><label>Tanggal Masuk *</label>
          <input type="date" name="tgl_masuk" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><label>Tanggal Tagih Tiap Bulan</label>
          <input type="number" name="tgl_tagih" min="1" max="28" value="<?= (int) date('j') ?>"></div>
        <div class="field"><label>Kata Sandi Awal</label>
          <input type="text" name="password" placeholder="changeme123">
          <div class="hint">Kosongkan untuk memakai <b>changeme123</b>.</div></div>
      </div>
      <div class="field"><label>Alamat Asal</label><textarea name="alamat" rows="2"></textarea></div>
      <label class="row"><input type="checkbox" name="buat_tagihan" value="1" checked style="width:auto">
        <span>Langsung terbitkan tagihan bulan ini &amp; kirim WhatsApp</span></label>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mTambah')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>

<!-- Edit kontrak -->
<div class="modal" id="mEdit">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="edit"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3>Edit Data Penghuni</h3>
      <button type="button" class="modal-x" onclick="closeModal('mEdit')">&times;</button></div>
    <div class="modal-body">
      <p class="mb" data-f="info"></p>
      <div class="form-grid">
        <div class="field"><label>Tanggal Masuk</label><input type="date" name="tgl_masuk" data-f="tgl_masuk" required></div>
        <div class="field"><label>Tanggal Tagih</label><input type="number" name="tgl_tagih" data-f="tgl_tagih" min="1" max="28" required></div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mEdit')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>

<!-- Check-out -->
<div class="modal" id="mKeluar">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="keluar"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3>Check-out Penghuni</h3>
      <button type="button" class="modal-x" onclick="closeModal('mKeluar')">&times;</button></div>
    <div class="modal-body">
      <p class="mb" data-f="info"></p>
      <div class="field"><label>Tanggal Keluar</label><input type="date" name="tgl_keluar" value="<?= date('Y-m-d') ?>" required></div>
      <div class="alert alert-warn">Kamar akan otomatis kembali berstatus <b>tersedia</b> dan penyewa menerima pesan WhatsApp.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mKeluar')">Batal</button>
      <button class="btn btn-warn">Proses Check-out</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
