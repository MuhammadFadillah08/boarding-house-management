<?php
/** Buku kas: pemasukan lain-lain & pengeluaran kost (web + hasil input bot WA). */
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$err   = '';

if (is_post()) {
    csrf_check();
    $aksi = post('aksi');

    if ($aksi === 'hapus') {
        $k = one('SELECT * FROM kas WHERE id = ?', [(int) post('id')]);
        if ($k) {
            if ($k['bukti'] && is_file(UPLOAD_DIR . $k['bukti'])) @unlink(UPLOAD_DIR . $k['bukti']);
            q('DELETE FROM kas WHERE id = ?', [$k['id']]);
            // Hapus juga di Spreadsheet, kalau tidak baris itu akan ditarik
            // kembali pada sinkronisasi berikutnya dan seolah-olah hidup lagi.
            sheets_kas_hapus($k['kode']);
            flash('Transaksi ' . $k['kode'] . ' dihapus.', 'info');
        }
        redirect('admin/kas.php' . filter_qs(['periode', 'f']));
    }

    if ($aksi === 'sinkron') {
        $tarik  = sheets_kas_tarik();
        $dorong = sheets_dorong_tertinggal();
        $meta   = sheets_sync_meta(get('periode'));
        flash($meta['ok']
            ? "Sinkronisasi selesai: $tarik transaksi WhatsApp ditarik, $dorong baris didorong ke Spreadsheet."
            : 'Sinkronisasi gagal: ' . $meta['error'],
            $meta['ok'] ? 'success' : 'danger');
        redirect('admin/kas.php' . filter_qs(['periode', 'f']));
    }

    if ($aksi === 'simpan') {
        $id     = (int) post('id');
        $jumlah = parse_nominal(post('jumlah')) ?? 0;
        $jenis  = post('jenis') === 'masuk' ? 'masuk' : 'keluar';

        if ($jumlah <= 0) {
            $err = 'Nominal harus lebih dari nol.';
        } elseif (post('keterangan') === '') {
            $err = 'Keterangan wajib diisi.';
        } else {
            $data = [
                'kost_id'    => (int) post('kost_id') ?: null,
                'jenis'      => $jenis,
                'kategori'   => post('kategori') ?: kas_tebak_kategori(post('keterangan'), $jenis),
                'jumlah'     => $jumlah,
                'keterangan' => post('keterangan'),
                'tanggal'    => post('tanggal') ?: date('Y-m-d'),
            ];
            if (!empty($_FILES['bukti']['name'])) {
                [$ok, $res] = upload_image('bukti', UPLOAD_DIR, 'kas');
                if ($ok) $data['bukti'] = $res; else $err = $res;
            }
            if ($err === '') {
                if ($id) {
                    $data['sinkron'] = 0;
                    update('kas', $data, 'id = ?', [$id]);
                    // Spreadsheet tidak punya operasi "ubah"; baris dikirim ulang
                    // dengan kode yang sama sehingga menimpa versi lamanya.
                    if (sheets_aktif()) {
                        $row = one('SELECT k.*, ks.nama AS kost, u.nama AS pencatat FROM kas k
                                    LEFT JOIN kost ks ON ks.id = k.kost_id
                                    LEFT JOIN users u ON u.id = k.user_id
                                    WHERE k.id = ?', [$id]);
                        if ($row) sheets_kas_dorong($row);
                    }
                    flash('Transaksi diperbarui.');
                } else {
                    kas_catat($jenis, $jumlah, post('keterangan'), [
                        'kost_id'  => $data['kost_id'],
                        'kategori' => $data['kategori'],
                        'tanggal'  => $data['tanggal'],
                        'sumber'   => 'web',
                        'user_id'  => $admin['id'],
                        'bukti'    => $data['bukti'] ?? null,
                    ]);
                    flash(($jenis === 'masuk' ? 'Pemasukan' : 'Pengeluaran') . ' ' . rp($jumlah) . ' dicatat.');
                }
                redirect('admin/kas.php?periode=' . substr($data['tanggal'], 0, 7));
            }
        }
    }
}

// Tarik transaksi baru dari bot WhatsApp sebelum menampilkan data.
// Dibatasi jeda agar tidak memanggil Apps Script di setiap muat halaman.
sheets_maybe_tarik();

$periode = periode_atau_kini(get('periode'));
$dari    = $periode . '-01';
$sampai  = date('Y-m-t', (int) strtotime($dari));
$f       = get('f', 'semua');

$ring     = kas_ringkasan($dari, $sampai);
$list     = kas_daftar($dari, $sampai, in_array($f, ['masuk', 'keluar'], true) ? $f : '');
$kostList = all('SELECT id, nama FROM kost ORDER BY urutan, id');
$dariWa   = (int) scalar('SELECT COUNT(*) FROM kas WHERE sumber = "wa" AND tanggal BETWEEN ? AND ?', [$dari, $sampai], 0);

layout_head('Buku Kas', 'admin', 'kas');
?>
<?php if ($err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endif; ?>

<div class="grid g4 mb">
  <div class="stat s-sage reveal"><div class="lbl">Pemasukan Sewa</div>
    <div class="val"><span data-count="<?= (int) $ring['sewa'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">dari pembayaran terverifikasi</div></div>
  <div class="stat s-mist reveal" style="--d:.06s"><div class="lbl">Pemasukan Lain</div>
    <div class="val"><span data-count="<?= (int) $ring['lain'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">deposit, denda, sewa aula, dll</div></div>
  <div class="stat s-terra reveal" style="--d:.12s"><div class="lbl">Pengeluaran</div>
    <div class="val"><span data-count="<?= (int) $ring['keluar'] ?>" data-prefix="Rp ">Rp 0</span></div>
    <div class="sub">operasional kost</div></div>
  <div class="stat s-butter reveal" style="--d:.18s"><div class="lbl">Saldo</div>
    <div class="val" style="color:<?= $ring['saldo'] >= 0 ? 'var(--sage-d)' : 'var(--danger-d)' ?>">
      <span data-count="<?= (int) abs($ring['saldo']) ?>" data-prefix="<?= $ring['saldo'] < 0 ? '-Rp ' : 'Rp ' ?>">Rp 0</span></div>
    <div class="sub">pemasukan &minus; pengeluaran</div></div>
</div>

<?php if ($dariWa > 0): ?>
  <div class="alert alert-info"><b><?= $dariWa ?> transaksi</b> pada periode ini dicatat langsung
    lewat chat WhatsApp ke bot. Ditandai lencana <span class="badge badge-info">WA</span> di tabel.</div>
<?php endif; ?>

<div class="spread mb">
  <form class="row" method="get">
    <input type="month" name="periode" value="<?= e($periode) ?>" onchange="this.form.submit()">
    <div class="seg">
      <?php foreach (['semua' => 'Semua', 'masuk' => 'Pemasukan', 'keluar' => 'Pengeluaran'] as $k => $v): ?>
        <a class="<?= $f === $k ? 'on' : '' ?>" href="?periode=<?= e($periode) ?>&f=<?= $k ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
  </form>
  <div class="row">
    <?php if (sheets_aktif()): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="sinkron">
        <input type="hidden" name="periode" value="<?= e($periode) ?>">
        <button class="btn btn-soft" title="Tarik transaksi WhatsApp &amp; perbarui Spreadsheet">Sinkron Spreadsheet</button>
      </form>
      <?php if ($sheetUrl = sheets_url_dokumen()): ?>
        <a class="btn btn-line" target="_blank" rel="noopener" href="<?= e($sheetUrl) ?>">Buka Spreadsheet</a>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn btn-line" href="<?= url('admin/laporan.php?periode=' . e($periode)) ?>">Buka Laporan</a>
    <button class="btn btn-warn" onclick='openModal("mKas",{id:"",jenis:"keluar",judul:"Catat Pengeluaran",jumlah:"",keterangan:"",kategori:"",kost_id:"",tanggal:"<?= date('Y-m-d') ?>"})'>&minus; Pengeluaran</button>
    <button class="btn" onclick='openModal("mKas",{id:"",jenis:"masuk",judul:"Catat Pemasukan",jumlah:"",keterangan:"",kategori:"",kost_id:"",tanggal:"<?= date('Y-m-d') ?>"})'>+ Pemasukan</button>
  </div>
</div>

<div class="grid g2 mb">
  <section class="card reveal">
    <div class="card-head"><h3>Transaksi <?= e(periode_label($periode)) ?></h3>
      <span class="small muted"><?= count($list) ?> baris</span></div>
    <?php if (!$list): ?>
      <div class="empty"><span class="big"><?= icon('kas', 44) ?></span>Belum ada transaksi kas pada periode ini.<br>
        <span class="small">Catat lewat tombol di atas, atau kirim pesan WhatsApp ke bot.</span></div>
    <?php else: ?>
    <div class="table-wrap"><table class="tb">
      <thead><tr><th>Tanggal</th><th>Kode</th><th>Keterangan</th><th>Kategori</th>
        <th>Cabang</th><th class="num">Masuk</th><th class="num">Keluar</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($list as $k): ?>
        <tr>
          <td class="small"><?= e(tgl($k['tanggal'])) ?></td>
          <td class="small"><?= e($k['kode']) ?>
            <?php if ($k['sumber'] === 'wa'): ?><br><span class="badge badge-info">WA</span><?php endif; ?></td>
          <td><?= e($k['keterangan']) ?>
            <?php if ($k['pencatat']): ?><br><span class="tiny muted">oleh <?= e($k['pencatat']) ?></span><?php endif; ?>
            <?php if ($k['bukti']): ?> <a class="tiny" target="_blank" href="<?= e(UPLOAD_URL . $k['bukti']) ?>">bukti</a><?php endif; ?></td>
          <td><span class="badge badge-muted"><?= e(ucfirst(str_replace('_', ' ', $k['kategori']))) ?></span></td>
          <td class="small muted"><?= e($k['kost'] ?: 'Umum') ?></td>
          <td class="num"><?= $k['jenis'] === 'masuk' ? '<b style="color:var(--sage-d)">' . rp($k['jumlah']) . '</b>' : '—' ?></td>
          <td class="num"><?= $k['jenis'] === 'keluar' ? '<b style="color:var(--danger-d)">' . rp($k['jumlah']) . '</b>' : '—' ?></td>
          <td style="white-space:nowrap">
            <button class="btn btn-sm btn-soft" onclick='openModal("mKas", <?= json_encode([
              'id' => $k['id'], 'jenis' => $k['jenis'], 'judul' => 'Edit ' . $k['kode'],
              'jumlah' => $k['jumlah'], 'keterangan' => $k['keterangan'], 'kategori' => $k['kategori'],
              'kost_id' => $k['kost_id'], 'tanggal' => $k['tanggal'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
              <button class="btn btn-sm btn-danger" data-confirm="Hapus transaksi <?= e($k['kode']) ?>?">&times;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="5">TOTAL</th>
        <th class="num"><?= rp($ring['lain']) ?></th>
        <th class="num"><?= rp($ring['keluar']) ?></th><th></th></tr></tfoot>
    </table></div>
    <?php endif; ?>
  </section>

  <div>
    <section class="card mb reveal">
      <div class="card-head"><h3>Rincian per Kategori</h3></div>
      <div class="table-wrap"><table class="tb">
        <thead><tr><th>Kategori</th><th>Jenis</th><th class="num">Jml</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php if (!$ring['per_kategori']): ?>
          <tr><td colspan="4" class="center muted">Belum ada data.</td></tr>
        <?php else: foreach ($ring['per_kategori'] as $k): ?>
          <tr><td><?= e(ucfirst(str_replace('_', ' ', $k['kategori']))) ?></td>
            <td><?= $k['jenis'] === 'masuk' ? badge('lunas') : badge('terlambat') ?></td>
            <td class="num"><?= (int) $k['jml'] ?></td>
            <td class="num"><?= rp($k['total']) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </section>

    <section class="card card-pad reveal">
      <h3><?= icon('wa', 19) ?> Catat Lewat WhatsApp</h3>
      <p class="small muted mb">Kirim pesan ke nomor bot. Hanya nomor terdaftar sebagai
        <b>admin</b> yang boleh mencatat kas.</p>
      <div class="kv small" style="grid-template-columns:auto 1fr;gap:10px 14px">
        <b><code>KELUAR 150rb token listrik</code></b><span class="muted">catat pengeluaran</span>
        <b><code>MASUK 500rb deposit kamar S1-03</code></b><span class="muted">catat pemasukan</span>
        <b><code>LAPORAN</code></b><span class="muted">ringkasan bulan ini</span>
        <b><code>LAPORAN JULI</code></b><span class="muted">ringkasan bulan lain</span>
        <b><code>BATAL</code></b><span class="muted">hapus catatan terakhir</span>
      </div>
      <p class="tiny muted mt">Nominal bisa ditulis <code>150000</code>, <code>150.000</code>,
        <code>150rb</code>, atau <code>1,5jt</code>. Kategori ditebak otomatis dari keterangan.</p>
    </section>
  </div>
</div>

<div class="modal" id="mKas">
  <form class="modal-box" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3 data-f="judul">Catat Transaksi</h3>
      <button type="button" class="modal-x" onclick="closeModal('mKas')">&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label>Jenis *</label>
          <select name="jenis" data-f="jenis" required>
            <option value="keluar">Pengeluaran</option>
            <option value="masuk">Pemasukan</option>
          </select></div>
        <div class="field"><label>Tanggal *</label><input type="date" name="tanggal" data-f="tanggal" required></div>
        <div class="field"><label>Nominal *</label>
          <input type="text" name="jumlah" data-f="jumlah" placeholder="150000 atau 150rb" required>
          <div class="hint">Boleh ditulis <code>150rb</code> atau <code>1,5jt</code>.</div></div>
        <div class="field"><label>Cabang</label>
          <select name="kost_id" data-f="kost_id">
            <option value="">Umum (semua cabang)</option>
            <?php foreach ($kostList as $k): ?>
              <option value="<?= (int) $k['id'] ?>"><?= e($k['nama']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="field"><label>Keterangan *</label>
        <input type="text" name="keterangan" data-f="keterangan" placeholder="Contoh: token listrik Branch 1" required></div>
      <div class="field"><label>Kategori</label>
        <input type="text" name="kategori" data-f="kategori" list="katList" placeholder="kosongkan = tebak otomatis">
        <datalist id="katList">
          <?php foreach (array_merge(array_keys(kas_kategori('keluar')), array_keys(kas_kategori('masuk'))) as $kk): ?>
            <option value="<?= e($kk) ?>"></option>
          <?php endforeach; ?>
        </datalist></div>
      <div class="field"><label>Foto Nota / Bukti</label>
        <input type="file" name="bukti" accept="image/*" data-preview="pv">
        <div id="pv"></div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mKas')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
