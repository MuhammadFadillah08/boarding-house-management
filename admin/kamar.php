<?php
/** CRUD Kamar (terhubung ke kost/cabang). */
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
$err = '';

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    if ($aksi === 'hapus') {
        $id = (int) post('id');
        if (scalar('SELECT id FROM penghuni WHERE kamar_id = ? AND status = "aktif"', [$id])) {
            flash('Kamar tidak bisa dihapus karena masih dihuni.', 'danger');
        } else {
            $foto = scalar('SELECT foto FROM kamar WHERE id = ?', [$id]);
            q('DELETE FROM kamar WHERE id = ?', [$id]);
            if ($foto && is_file(KAMAR_DIR . $foto)) @unlink(KAMAR_DIR . $foto);
            flash('Kamar dihapus.');
        }
        redirect('admin/kamar.php?kost=' . get('kost'));
    }

    if ($aksi === 'simpan') {
        $id     = (int) post('id');
        $kode   = strtoupper(post('kode'));
        $harga  = (float) post('harga');
        $kostId = (int) post('kost_id');

        if ($kode === '' || $harga <= 0 || !$kostId) {
            $err = 'Kost, kode kamar, dan harga wajib diisi.';
        } elseif (scalar('SELECT id FROM kamar WHERE kode = ? AND id <> ?', [$kode, $id])) {
            $err = 'Kode kamar sudah dipakai.';
        } else {
            $data = [
                'kost_id'   => $kostId,
                'kode'      => $kode,
                'tipe'      => post('tipe') ?: 'Standard',
                'lantai'    => max(1, (int) post('lantai')),
                'harga'     => $harga,
                'fasilitas' => post('fasilitas'),
                'deskripsi' => post('deskripsi'),
                'status'    => in_array(post('status'), ['tersedia', 'dibooking', 'terisi', 'perbaikan'], true) ? post('status') : 'tersedia',
            ];
            if (!empty($_FILES['foto']['name'])) {
                [$ok, $res] = upload_image('foto', KAMAR_DIR, 'kamar');
                if ($ok) $data['foto'] = $res; else $err = $res;
            }
            if ($err === '') {
                if ($id) { update('kamar', $data, 'id = ?', [$id]); flash('Kamar ' . $kode . ' diperbarui.'); }
                else     { insert('kamar', $data);                  flash('Kamar ' . $kode . ' ditambahkan.'); }
                redirect('admin/kamar.php?kost=' . $kostId);
            }
        }
    }
}

$kostId   = (int) get('kost');
$kostList = all('SELECT * FROM kost ORDER BY urutan, id');
$list = all('SELECT m.*, k.nama AS kost_nama, k.warna, u.nama AS penghuni
             FROM kamar m
             JOIN kost k ON k.id = m.kost_id
             LEFT JOIN penghuni h ON h.kamar_id = m.id AND h.status = "aktif"
             LEFT JOIN users u ON u.id = h.user_id'
           . ($kostId ? ' WHERE m.kost_id = ?' : '')
           . ' ORDER BY k.urutan, m.kode', $kostId ? [$kostId] : []);

layout_head('Kelola Kamar', 'admin', 'kamar');
?>
<?php if ($err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endif; ?>

<div class="spread mb">
  <div class="seg">
    <a class="<?= $kostId ? '' : 'on' ?>" href="?">Semua (<?= (int) scalar('SELECT COUNT(*) FROM kamar', [], 0) ?>)</a>
    <?php foreach ($kostList as $k): ?>
      <a class="<?= $kostId === (int) $k['id'] ? 'on' : '' ?>" href="?kost=<?= (int) $k['id'] ?>"><?= e($k['nama']) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <input class="inp" style="max-width:230px" placeholder="Cari kode / tipe…" data-filter="#tk tr">
    <button class="btn" onclick='openModal("mKamar",{id:"",kost_id:"<?= $kostId ?: '' ?>",kode:"",tipe:"Standard",lantai:1,harga:"",fasilitas:"",deskripsi:"",status:"tersedia",judul:"Tambah Kamar"})'>+ Tambah Kamar</button>
  </div>
</div>

<section class="card reveal">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('kamar', 44) ?></span>Belum ada kamar pada cabang ini.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Kost</th><th>Kode</th><th>Tipe</th><th>Lt</th><th class="num">Harga</th>
      <th>Fasilitas</th><th>Penghuni</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tk">
    <?php foreach ($list as $k): ?>
      <tr>
        <td class="small"><span class="badge badge-muted"><?= e($k['kost_nama']) ?></span></td>
        <td><b><?= e($k['kode']) ?></b></td>
        <td><?= e($k['tipe']) ?></td>
        <td><?= (int) $k['lantai'] ?></td>
        <td class="num"><?= rp($k['harga']) ?></td>
        <td class="small muted"><?= e(mb_strimwidth((string) $k['fasilitas'], 0, 38, '…')) ?></td>
        <td><?= $k['penghuni'] ? e($k['penghuni']) : '<span class="muted">—</span>' ?></td>
        <td><?= badge($k['status']) ?></td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-soft" onclick='openModal("mKamar", <?= json_encode([
            'id' => $k['id'], 'kost_id' => $k['kost_id'], 'kode' => $k['kode'], 'tipe' => $k['tipe'],
            'lantai' => $k['lantai'], 'harga' => $k['harga'], 'fasilitas' => $k['fasilitas'],
            'deskripsi' => $k['deskripsi'], 'status' => $k['status'], 'judul' => 'Edit Kamar ' . $k['kode'],
          ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Hapus kamar <?= e($k['kode']) ?>?">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="modal" id="mKamar">
  <form class="modal-box" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3 data-f="judul">Tambah Kamar</h3>
      <button type="button" class="modal-x" onclick="closeModal('mKamar')">&times;</button></div>
    <div class="modal-body">
      <div class="field"><label>Kost / Cabang *</label>
        <select name="kost_id" data-f="kost_id" required>
          <option value="">— pilih kost —</option>
          <?php foreach ($kostList as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= e($k['nama'] . ' · ' . $k['alamat']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-grid">
        <div class="field"><label>Kode Kamar *</label><input type="text" name="kode" data-f="kode" placeholder="B1-08" required></div>
        <div class="field"><label>Tipe *</label><input type="text" name="tipe" data-f="tipe" placeholder="Standard" required></div>
        <div class="field"><label>Lantai</label><input type="number" name="lantai" data-f="lantai" min="1" value="1"></div>
        <div class="field"><label>Harga / bulan *</label>
          <input type="number" name="harga" data-f="harga" step="50000" data-rupiah="hp" required>
          <div class="hint" id="hp"></div></div>
      </div>
      <div class="field"><label>Status</label>
        <select name="status" data-f="status">
          <option value="tersedia">Tersedia</option><option value="dibooking">Dibooking</option>
          <option value="terisi">Terisi</option><option value="perbaikan">Perbaikan</option>
        </select></div>
      <div class="field"><label>Fasilitas</label>
        <input type="text" name="fasilitas" data-f="fasilitas" placeholder="Kasur, Lemari, AC, Wifi">
        <div class="hint">Pisahkan dengan koma.</div></div>
      <div class="field"><label>Deskripsi</label><textarea name="deskripsi" data-f="deskripsi" rows="2"></textarea></div>
      <div class="field"><label>Foto Kamar</label>
        <input type="file" name="foto" accept="image/*" data-preview="pk">
        <div class="hint">Kosongkan bila tidak ingin mengubah foto.</div><div id="pk"></div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mKamar')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
