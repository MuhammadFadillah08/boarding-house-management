<?php
/** CRUD Tagihan + generate massal + tandai lunas manual + kirim ulang reminder. */
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$err   = '';

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    if ($aksi === 'generate') {
        $periode = periode_atau_kini(post('periode'));
        $n = generate_tagihan_massal($periode);
        flash($n > 0
            ? "$n tagihan periode " . periode_label($periode) . ' diterbitkan & notifikasi WA dikirim.'
            : 'Tidak ada tagihan baru — semua penghuni aktif sudah punya tagihan periode itu.',
            $n > 0 ? 'success' : 'info');
        redirect('admin/tagihan.php');
    }

    if ($aksi === 'simpan') {
        $id = (int) post('id');
        $data = [
            'jumlah'      => (float) post('jumlah'),
            'denda'       => (float) post('denda'),
            'jatuh_tempo' => post('jatuh_tempo'),
            'keterangan'  => post('keterangan'),
        ];
        if ($id) {
            update('tagihan', $data, 'id = ?', [$id]);
            flash('Tagihan diperbarui.');
        } else {
            $pid     = (int) post('penghuni_id');
            $periode = post('periode');
            if (!$pid || periode_valid($periode) === null) {
                $err = 'Penghuni dan periode wajib diisi dengan benar (format YYYY-MM).';
            } elseif (scalar('SELECT id FROM tagihan WHERE penghuni_id = ? AND periode = ?', [$pid, $periode])) {
                $err = 'Tagihan periode tersebut sudah ada untuk penghuni ini.';
            } else {
                $newId = insert('tagihan', array_merge($data, [
                    'kode'        => kode_unik('INV', 'tagihan'),
                    'penghuni_id' => $pid,
                    'periode'     => $periode,
                    'status'      => 'belum_bayar',
                ]));
                notify_tagihan_baru($newId);
                flash('Tagihan dibuat dan notifikasi WhatsApp dikirim.');
            }
        }
        if ($err === '') redirect('admin/tagihan.php');
    }

    if ($aksi === 'lunas') {
        $id = (int) post('id');
        $t  = tagihan_detail($id);
        if ($t && $t['status'] !== 'lunas') {
            $total = (float) $t['jumlah'] + (float) $t['denda'];
            $bid = insert('pembayaran', [
                'kode'       => kode_unik('PAY', 'pembayaran'),
                'tagihan_id' => $id,
                'user_id'    => (int) scalar('SELECT user_id FROM penghuni WHERE id = ?', [$t['penghuni_id']], 0),
                'jumlah'     => $total,
                'metode'     => 'tunai',
                'tgl_bayar'  => date('Y-m-d'),
                'catatan'    => 'Dicatat manual oleh admin',
                'status'     => 'diterima',
                'verif_by'   => $admin['id'],
                'verif_at'   => date('Y-m-d H:i:s'),
            ]);
            update('tagihan', ['status' => 'lunas', 'tgl_lunas' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            notify_pembayaran_verif($bid);
            flash('Tagihan ditandai LUNAS dan bukti lunas dikirim via WhatsApp.');
        }
        redirect('admin/tagihan.php' . filter_qs(['f', 'periode', 'penghuni']));
    }

    if ($aksi === 'ingatkan') {
        $id = (int) post('id');
        $t  = one('SELECT jatuh_tempo FROM tagihan WHERE id = ?', [$id]);
        if ($t) {
            notify_reminder($id, (int) ((strtotime($t['jatuh_tempo']) - strtotime(date('Y-m-d'))) / 86400));
            flash('Pengingat WhatsApp dikirim.');
        }
        redirect('admin/tagihan.php' . filter_qs(['f', 'periode', 'penghuni']));
    }

    if ($aksi === 'hapus') {
        q('DELETE FROM tagihan WHERE id = ?', [(int) post('id')]);
        flash('Tagihan dihapus.', 'info');
        redirect('admin/tagihan.php');
    }
}

$f       = get('f', 'semua');
$periode = get('periode');
$phId    = (int) get('penghuni');

$w = []; $p = [];
if ($f !== 'semua')  { $w[] = 't.status = ?';  $p[] = $f; }
if ($periode !== '') { $w[] = 't.periode = ?'; $p[] = $periode; }
if ($phId)           { $w[] = 't.penghuni_id = ?'; $p[] = $phId; }

$list = all('SELECT t.*, u.nama, u.no_hp, k.kode AS kamar_kode, ks.nama AS kost FROM tagihan t
             JOIN penghuni h ON h.id = t.penghuni_id
             JOIN users u ON u.id = h.user_id
             JOIN kamar k ON k.id = h.kamar_id
             JOIN kost ks ON ks.id = k.kost_id'
           . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
           . ' ORDER BY t.periode DESC, ks.urutan, k.kode ASC', $p);

$penghuniAktif = all('SELECT h.id, u.nama, k.kode, k.harga, ks.nama AS kost FROM penghuni h
                      JOIN users u ON u.id = h.user_id
                      JOIN kamar k ON k.id = h.kamar_id
                      JOIN kost ks ON ks.id = k.kost_id
                      WHERE h.status = "aktif" ORDER BY ks.urutan, k.kode');
$periodeList = all('SELECT DISTINCT periode FROM tagihan ORDER BY periode DESC');
$sum = ['tagih' => 0, 'lunas' => 0, 'sisa' => 0];
foreach ($list as $t) {
    $tot = (float) $t['jumlah'] + (float) $t['denda'];
    $sum['tagih'] += $tot;
    if ($t['status'] === 'lunas') $sum['lunas'] += $tot; else $sum['sisa'] += $tot;
}

layout_head('Kelola Tagihan', 'admin', 'tagihan');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="grid g3 mb">
  <div class="stat s-mist"><div class="lbl">Total Ditagihkan</div><div class="val"><?= rp($sum['tagih']) ?></div>
    <div class="sub"><?= count($list) ?> tagihan (sesuai filter)</div></div>
  <div class="stat s-sage"><div class="lbl">Sudah Lunas</div><div class="val"><?= rp($sum['lunas']) ?></div></div>
  <div class="stat s-terra"><div class="lbl">Belum Terbayar</div><div class="val"><?= rp($sum['sisa']) ?></div></div>
</div>

<div class="spread mb">
  <form class="row" method="get">
    <select name="f" onchange="this.form.submit()">
      <?php foreach (['semua' => 'Semua status', 'belum_bayar' => 'Belum bayar', 'menunggu_verifikasi' => 'Menunggu verifikasi',
                      'terlambat' => 'Terlambat', 'lunas' => 'Lunas'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="periode" onchange="this.form.submit()">
      <option value="">Semua periode</option>
      <?php foreach ($periodeList as $pl): ?>
        <option value="<?= e($pl['periode']) ?>" <?= $periode === $pl['periode'] ? 'selected' : '' ?>>
          <?= e(periode_label($pl['periode'])) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="inp" style="max-width:200px" placeholder="Cari nama…" data-filter="#tt tr">
  </form>
  <div class="row">
    <button class="btn btn-line" onclick='openModal("mGen")'>Terbitkan Massal</button>
    <button class="btn" onclick='openModal("mTag",{id:"",judul:"Buat Tagihan Manual",jumlah:"",denda:0,jatuh_tempo:"<?= date('Y-m-d') ?>",keterangan:""})'>+ Tagihan</button>
  </div>
</div>

<section class="card">
  <?php if (!$list): ?>
    <div class="empty"><span class="big"><?= icon('tagihan', 44) ?></span>Belum ada tagihan pada filter ini.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>No. Tagihan</th><th>Penyewa</th><th>Periode</th><th>Tempo</th>
      <th class="num">Sewa</th><th class="num">Denda</th><th class="num">Total</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tt">
    <?php foreach ($list as $t): $tot = (float) $t['jumlah'] + (float) $t['denda']; ?>
      <tr>
        <td class="small"><?= e($t['kode']) ?></td>
        <td><b><?= e($t['nama']) ?></b><br><span class="small muted"><?= e($t['kost'] . ' · ' . $t['kamar_kode']) ?></span></td>
        <td class="small"><?= e(periode_label($t['periode'])) ?></td>
        <td class="small"><?= e(tgl($t['jatuh_tempo'])) ?></td>
        <td class="num"><?= rp($t['jumlah']) ?></td>
        <td class="num"><?= (float) $t['denda'] > 0 ? rp($t['denda']) : '—' ?></td>
        <td class="num"><b><?= rp($tot) ?></b></td>
        <td><?= badge($t['status']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($t['status'] !== 'lunas'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="lunas"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm" data-confirm="Tandai tagihan ini LUNAS (pembayaran tunai)?">Lunas</button></form>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="ingatkan"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm btn-warn" title="Kirim pengingat WhatsApp"><?= icon('wa', 15) ?></button></form>
          <?php endif; ?>
          <a class="btn btn-sm btn-line" target="_blank" href="<?= url('user/kuitansi.php?id=' . (int) $t['id']) ?>"><?= icon('cetak', 15) ?></a>
          <button class="btn btn-sm btn-soft" onclick='openModal("mTag", <?= json_encode([
            'id' => $t['id'], 'judul' => 'Edit ' . $t['kode'], 'jumlah' => $t['jumlah'], 'denda' => $t['denda'],
            'jatuh_tempo' => $t['jatuh_tempo'], 'keterangan' => $t['keterangan'],
          ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Hapus tagihan <?= e($t['kode']) ?> beserta riwayat pembayarannya?">×</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<!-- Generate massal -->
<div class="modal" id="mGen">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="generate">
    <div class="modal-head"><h3>Terbitkan Tagihan Massal</h3>
      <button type="button" class="modal-x" onclick="closeModal('mGen')">&times;</button></div>
    <div class="modal-body">
      <div class="field"><label>Periode</label>
        <input type="month" name="periode" value="<?= date('Y-m') ?>" required></div>
      <div class="alert alert-info">Tagihan dibuat untuk <b><?= count($penghuniAktif) ?> penghuni aktif</b>
        sesuai harga kamar masing-masing. Penghuni yang sudah punya tagihan pada periode ini dilewati.
        Notifikasi WhatsApp dikirim otomatis.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mGen')">Batal</button>
      <button class="btn">Terbitkan</button>
    </div>
  </form>
</div>

<!-- Tambah / edit tagihan -->
<div class="modal" id="mTag">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3 data-f="judul">Tagihan</h3>
      <button type="button" class="modal-x" onclick="closeModal('mTag')">&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label>Penghuni (untuk tagihan baru)</label>
          <select name="penghuni_id">
            <option value="">— pilih penghuni —</option>
            <?php foreach ($penghuniAktif as $ph): ?>
              <option value="<?= (int) $ph['id'] ?>" data-harga="<?= (float) $ph['harga'] ?>">
                <?= e($ph['kost'] . ' · ' . $ph['kode'] . ' · ' . $ph['nama']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Periode</label><input type="month" name="periode" value="<?= date('Y-m') ?>"></div>
        <div class="field"><label>Jumlah Sewa *</label>
          <input type="number" name="jumlah" data-f="jumlah" step="1000" data-rupiah="rpj" required>
          <div class="hint" id="rpj"></div></div>
        <div class="field"><label>Denda</label><input type="number" name="denda" data-f="denda" step="1000" value="0"></div>
        <div class="field"><label>Jatuh Tempo *</label><input type="date" name="jatuh_tempo" data-f="jatuh_tempo" required></div>
      </div>
      <div class="field"><label>Keterangan</label><input type="text" name="keterangan" data-f="keterangan"></div>
      <div class="hint">Kolom penghuni &amp; periode hanya dipakai saat membuat tagihan baru.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mTag')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>
<script>
document.querySelector('#mTag select[name=penghuni_id]').addEventListener('change', function () {
  var h = this.options[this.selectedIndex].dataset.harga;
  if (h) { var i = document.querySelector('#mTag input[name=jumlah]'); i.value = h; i.dispatchEvent(new Event('input')); }
});
</script>
<?php layout_foot('admin'); ?>
