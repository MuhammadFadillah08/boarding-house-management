<?php
/** CRUD Kost (cabang/gedung). */
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
$err = '';

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    if ($aksi === 'hapus') {
        $id  = (int) post('id');
        $huni = (int) scalar('SELECT COUNT(*) FROM penghuni h JOIN kamar m ON m.id = h.kamar_id
                              WHERE m.kost_id = ? AND h.status = "aktif"', [$id], 0);
        if ($huni > 0) {
            flash("Kost tidak bisa dihapus karena masih ada $huni penghuni aktif di dalamnya.", 'danger');
        } else {
            // Kumpulkan nama berkas foto dulu — setelah baris kamar terhapus
            // (ON DELETE CASCADE) berkasnya tidak bisa ditelusuri lagi.
            $fotos = all('SELECT foto FROM kamar WHERE kost_id = ? AND foto IS NOT NULL', [$id]);
            $jml   = (int) scalar('SELECT COUNT(*) FROM kamar WHERE kost_id = ?', [$id], 0);
            q('DELETE FROM kost WHERE id = ?', [$id]);
            foreach ($fotos as $ft) {
                if ($ft['foto'] && is_file(KAMAR_DIR . $ft['foto'])) @unlink(KAMAR_DIR . $ft['foto']);
            }
            flash("Kost dihapus beserta $jml kamarnya.", 'info');
        }
        redirect('admin/kost.php');
    }

    if ($aksi === 'simpan') {
        $id   = (int) post('id');
        $nama = post('nama');
        if ($nama === '') {
            $err = 'Nama kost wajib diisi.';
        } elseif (scalar('SELECT id FROM kost WHERE nama = ? AND id <> ?', [$nama, $id])) {
            $err = 'Nama kost sudah dipakai.';
        } else {
            $data = [
                'nama'      => $nama,
                'alamat'    => post('alamat'),
                'tipe'      => in_array(post('tipe'), ['campur', 'putra', 'putri'], true) ? post('tipe') : 'campur',
                'deskripsi' => post('deskripsi'),
                'fasilitas' => post('fasilitas'),
                'warna'     => in_array(post('warna'), ['sage', 'terra', 'mist', 'butter'], true) ? post('warna') : 'sage',
                'status'    => post('status') === 'nonaktif' ? 'nonaktif' : 'aktif',
                'urutan'    => max(1, (int) post('urutan')),
            ];
            if ($id) {
                update('kost', $data, 'id = ?', [$id]);
                flash('Data ' . $nama . ' diperbarui.');
            } else {
                $newId = insert('kost', $data);
                // Buat kamar awal sekaligus (opsional)
                $jml = max(0, min(50, (int) post('jml_kamar')));
                if ($jml > 0) {
                    $pre   = strtoupper(post('prefix') ?: 'K');
                    $harga = (float) post('harga_awal');
                    for ($i = 1; $i <= $jml; $i++) {
                        $kode = sprintf('%s-%02d', $pre, $i);
                        if (scalar('SELECT id FROM kamar WHERE kode = ?', [$kode])) continue;
                        insert('kamar', [
                            'kost_id'   => $newId,
                            'kode'      => $kode,
                            'tipe'      => post('tipe_kamar') ?: 'Standard',
                            'lantai'    => (int) ceil($i / 4),
                            'harga'     => $harga,
                            'fasilitas' => post('fasilitas_kamar') ?: 'Kasur, Lemari, Meja Belajar, Wifi',
                            'status'    => 'tersedia',
                        ]);
                    }
                    flash("Kost $nama ditambahkan beserta $jml kamar.");
                } else {
                    flash('Kost ' . $nama . ' ditambahkan. Silakan tambahkan kamarnya.');
                }
            }
            if ($err === '') redirect('admin/kost.php');
        }
    }
}

$list = all('SELECT k.*,
             COUNT(m.id) AS total,
             SUM(m.status = "tersedia") AS tersedia,
             SUM(m.status = "terisi")   AS terisi,
             SUM(m.status = "perbaikan") AS perbaikan,
             MIN(m.harga) AS termurah, MAX(m.harga) AS termahal,
             COALESCE(SUM(CASE WHEN m.status = "terisi" THEN m.harga ELSE 0 END),0) AS potensi
             FROM kost k LEFT JOIN kamar m ON m.kost_id = k.id
             GROUP BY k.id ORDER BY k.urutan, k.id');

layout_head('Data Kost', 'admin', 'kost');
?>
<?php if ($err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endif; ?>

<div class="spread mb">
  <div>
    <h2><?= count($list) ?> Cabang Terdaftar</h2>
    <p class="small muted">Setiap kost menampung kamar-kamarnya sendiri.</p>
  </div>
  <button class="btn" onclick='openModal("mKost",{id:"",judul:"Tambah Kost Baru",nama:"",alamat:"",tipe:"campur",deskripsi:"",fasilitas:"",warna:"sage",status:"aktif",urutan:<?= count($list) + 1 ?>})'>+ Tambah Kost</button>
</div>

<div class="grid g2 mb">
  <?php foreach ($list as $i => $k):
      $pct = (int) $k['total'] > 0 ? round((int) $k['terisi'] / (int) $k['total'] * 100) : 0; ?>
    <section class="card reveal acc-<?= e($k['warna']) ?>" style="--d:<?= $i * .06 ?>s">
      <div class="card-head">
        <div>
          <h3><?= e($k['nama']) ?> <?= $k['status'] === 'nonaktif' ? badge('keluar') : '' ?></h3>
          <span class="tiny muted"><?= e($k['alamat']) ?: 'Alamat belum diisi' ?></span>
        </div>
        <span class="badge badge-<?= $k['tipe'] === 'putri' ? 'danger' : ($k['tipe'] === 'putra' ? 'info' : 'muted') ?>">
          <?= e(ucfirst($k['tipe'])) ?></span>
      </div>
      <div class="card-pad">
        <div class="grid g4" style="gap:10px">
          <div><div class="tiny muted">TOTAL</div><b style="font-size:1.25rem"><?= (int) $k['total'] ?></b></div>
          <div><div class="tiny muted">TERSEDIA</div><b style="font-size:1.25rem;color:var(--sage-d)"><?= (int) $k['tersedia'] ?></b></div>
          <div><div class="tiny muted">TERISI</div><b style="font-size:1.25rem;color:var(--mist-d)"><?= (int) $k['terisi'] ?></b></div>
          <div><div class="tiny muted">PERBAIKAN</div><b style="font-size:1.25rem;color:var(--danger-d)"><?= (int) $k['perbaikan'] ?></b></div>
        </div>
        <div class="kost-meter" style="margin-top:14px"><i data-w="<?= $pct ?>"></i></div>
        <div class="spread tiny muted" style="margin-top:6px">
          <span>Tingkat hunian <?= $pct ?>%</span>
          <span>Pemasukan potensial <?= rp($k['potensi']) ?>/bln</span>
        </div>
        <p class="small muted" style="margin-top:12px"><?= e(mb_strimwidth((string) $k['deskripsi'], 0, 120, '…')) ?></p>
        <?php if ($k['fasilitas']): ?>
          <div class="fac" style="margin-top:10px">
            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $k['fasilitas']))), 0, 5) as $f): ?>
              <span><?= e($f) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="row" style="margin-top:16px">
          <a class="btn btn-sm btn-line" href="<?= url('admin/kamar.php?kost=' . (int) $k['id']) ?>">Kelola Kamar</a>
          <button class="btn btn-sm btn-soft" onclick='openModal("mKost", <?= json_encode([
            'id' => $k['id'], 'judul' => 'Edit ' . $k['nama'], 'nama' => $k['nama'], 'alamat' => $k['alamat'],
            'tipe' => $k['tipe'], 'deskripsi' => $k['deskripsi'], 'fasilitas' => $k['fasilitas'],
            'warna' => $k['warna'], 'status' => $k['status'], 'urutan' => $k['urutan'],
          ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
            <button class="btn btn-sm btn-danger"
              data-confirm="Hapus <?= e($k['nama']) ?> beserta <?= (int) $k['total'] ?> kamarnya? Tindakan ini tidak bisa dibatalkan.">Hapus</button>
          </form>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<div class="modal" id="mKost">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3 data-f="judul">Tambah Kost</h3>
      <button type="button" class="modal-x" onclick="closeModal('mKost')">&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label>Nama Kost *</label><input type="text" name="nama" data-f="nama" placeholder="Branch 5" required></div>
        <div class="field"><label>Tipe Penghuni</label>
          <select name="tipe" data-f="tipe">
            <option value="campur">Campur</option><option value="putra">Putra</option><option value="putri">Putri</option>
          </select></div>
      </div>
      <div class="field"><label>Alamat</label><input type="text" name="alamat" data-f="alamat"></div>
      <div class="field"><label>Deskripsi</label><textarea name="deskripsi" data-f="deskripsi" rows="2"></textarea></div>
      <div class="field"><label>Fasilitas Bersama</label>
        <input type="text" name="fasilitas" data-f="fasilitas" placeholder="Dapur Bersama, CCTV, Parkir, Wifi">
        <div class="hint">Pisahkan dengan koma.</div></div>
      <div class="form-grid">
        <div class="field"><label>Warna Aksen</label>
          <select name="warna" data-f="warna">
            <option value="sage">Sage (hijau)</option><option value="terra">Terracotta</option>
            <option value="mist">Mist (biru)</option><option value="butter">Butter (kuning)</option>
          </select></div>
        <div class="field"><label>Urutan Tampil</label><input type="number" name="urutan" data-f="urutan" min="1" value="1"></div>
        <div class="field"><label>Status</label>
          <select name="status" data-f="status">
            <option value="aktif">Aktif</option><option value="nonaktif">Nonaktif (sembunyikan)</option>
          </select></div>
      </div>

      <div id="blokKamarAwal" style="border-top:1px solid var(--line);padding-top:16px;margin-top:6px">
        <b class="small">Buat kamar sekaligus <span class="muted">(hanya saat menambah kost baru)</span></b>
        <div class="form-grid" style="margin-top:10px">
          <div class="field"><label>Jumlah Kamar</label><input type="number" name="jml_kamar" min="0" max="50" value="7"></div>
          <div class="field"><label>Awalan Kode</label><input type="text" name="prefix" placeholder="S5" maxlength="6">
            <div class="hint">Hasil: S5-01, S5-02, …</div></div>
          <div class="field"><label>Tipe Kamar</label><input type="text" name="tipe_kamar" value="Standard"></div>
          <div class="field"><label>Harga per Bulan</label>
            <input type="number" name="harga_awal" step="50000" value="800000" data-rupiah="hk">
            <div class="hint" id="hk"></div></div>
        </div>
        <div class="field"><label>Fasilitas Kamar</label>
          <input type="text" name="fasilitas_kamar" value="Kasur, Lemari, Meja Belajar, Wifi"></div>
        <div class="hint">Detail tiap kamar bisa diubah satu per satu di menu Kamar.</div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mKost')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>
<script>
/* Sembunyikan blok "buat kamar sekaligus" saat sedang mengedit kost */
(function () {
  var form = document.querySelector('#mKost');
  var id = form.querySelector('input[name=id]');
  var blok = document.getElementById('blokKamarAwal');
  new MutationObserver(function () {}).observe(form, {});
  form.addEventListener('input', function () { blok.style.display = id.value ? 'none' : ''; });
  var asli = window.openModal;
  window.openModal = function (mid, data) {
    asli(mid, data);
    if (mid === 'mKost') blok.style.display = (data && data.id) ? 'none' : '';
  };
})();
</script>
<?php layout_foot('admin'); ?>
