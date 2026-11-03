<?php
/** Pengaturan kost, rekening, WhatsApp gateway, dan cron. */
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$err   = '';

$keys = ['nama_kost', 'tagline', 'alamat', 'wa_admin', 'bank_nama', 'bank_rek', 'bank_an',
         'denda_per_hari', 'reminder_h', 'wa_driver', 'wa_token', 'wa_endpoint', 'wa_footer', 'cron_key',
         'helpdesk_aktif', 'helpdesk_judul', 'helpdesk_teks', 'helpdesk_sapaan',
         'sheets_url', 'sheets_key', 'sheets_id'];

if (is_post()) {
    csrf_check();
    if (post('aksi') === 'simpan') {
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                $v = (string) $_POST[$k];
                if ($k === 'wa_admin') $v = normalize_hp($v);
                set_setting($k, $v);
            }
        }
        // Checkbox tidak terkirim saat tidak dicentang
        set_setting('helpdesk_aktif', isset($_POST['helpdesk_aktif']) ? '1' : '0');
        set_setting('sheets_aktif',   isset($_POST['sheets_aktif'])   ? '1' : '0');
        flash('Pengaturan disimpan.');
        redirect('admin/pengaturan.php');
    }

    // ---- Uji koneksi ke Apps Script ----------------------------------
    if (post('aksi') === 'sheets_tes') {
        $r = sheets_call('ping');
        flash($r['ok']
            ? 'Terhubung ke Apps Script. Waktu server: ' . (string) ($r['data']['waktu'] ?? '-')
            : 'Gagal terhubung: ' . $r['error'],
            $r['ok'] ? 'success' : 'danger');
        redirect('admin/pengaturan.php');
    }

    // ---- Kirim ulang seluruh data ke Spreadsheet ----------------------
    if (post('aksi') === 'sheets_sync') {
        $meta   = sheets_sync_meta();
        $dorong = sheets_dorong_tertinggal(200);
        $tarik  = sheets_kas_tarik();
        flash($meta['ok']
            ? "Sinkronisasi selesai: $dorong transaksi didorong ke Spreadsheet, $tarik ditarik dari WhatsApp."
            : 'Sinkronisasi gagal: ' . $meta['error'],
            $meta['ok'] ? 'success' : 'danger');
        redirect('admin/pengaturan.php');
    }

    // ---- Helpdesk: tambah / ubah kontak ------------------------------
    if (post('aksi') === 'helpdesk_simpan') {
        $id = (int) post('id');
        $hp = normalize_hp(post('no_hp'));
        if (post('nama') === '' || strlen($hp) < 10) {
            $err = 'Nama dan nomor WhatsApp kontak wajib diisi dengan benar.';
        } else {
            $data = [
                'nama'       => post('nama'),
                'peran'      => post('peran'),
                'no_hp'      => $hp,
                'jam'        => post('jam'),
                'pesan_awal' => post('pesan_awal'),
                'urutan'     => max(1, (int) post('urutan')),
                'status'     => post('status') === 'nonaktif' ? 'nonaktif' : 'aktif',
            ];
            if ($id) { update('helpdesk', $data, 'id = ?', [$id]); flash('Kontak helpdesk diperbarui.'); }
            else     { insert('helpdesk', $data);                  flash('Kontak helpdesk ditambahkan.'); }
            redirect('admin/pengaturan.php');
        }
    }

    if (post('aksi') === 'helpdesk_hapus') {
        q('DELETE FROM helpdesk WHERE id = ?', [(int) post('id')]);
        flash('Kontak helpdesk dihapus.', 'info');
        redirect('admin/pengaturan.php');
    }

    if (post('aksi') === 'jalankan_job') {
        $r = run_daily_jobs();
        flash("Job harian dijalankan: {$r['terlambat']} tagihan ditandai terlambat, "
            . "{$r['reminder']} pengingat dikirim, {$r['queue']} pesan antrean diproses.", 'info');
        redirect('admin/pengaturan.php');
    }

    if (post('aksi') === 'admin_baru') {
        $email = strtolower(post('email'));
        $hp    = normalize_hp(post('no_hp'));
        if (post('nama') === '' || strlen($hp) < 10) {
            $err = 'Nama dan nomor WhatsApp wajib diisi.';
        } elseif (strlen((string) ($_POST['password'] ?? '')) < 6) {
            $err = 'Kata sandi minimal 6 karakter.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Format email tidak valid.';
        } elseif (scalar('SELECT id FROM users WHERE no_hp = ?', [$hp])) {
            $err = 'Nomor WhatsApp sudah terdaftar.';
        } elseif ($email !== '' && scalar('SELECT id FROM users WHERE email = ?', [$email])) {
            $err = 'Email sudah terdaftar.';
        } else {
            insert('users', [
                'nama'     => post('nama'),
                'no_hp'    => $hp,
                'email'    => $email !== '' ? $email : null,
                'password' => password_hash((string) $_POST['password'], PASSWORD_BCRYPT),
                'role'     => 'admin',
            ]);
            flash('Admin baru ditambahkan.');
            redirect('admin/pengaturan.php');
        }
    }
}

$adminList = all('SELECT id, nama, email, no_hp, created_at FROM users WHERE role = "admin" ORDER BY id');
$helpdesk  = all('SELECT * FROM helpdesk ORDER BY urutan, id');
$statKost  = all('SELECT k.nama, COUNT(m.id) jml FROM kost k LEFT JOIN kamar m ON m.kost_id = k.id
                  GROUP BY k.id ORDER BY k.urutan');
$cronUrl   = url('cron/reminder.php?key=' . rawurlencode(setting('cron_key')));

layout_head('Pengaturan', 'admin', 'pengaturan');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<form method="post">
<?= csrf_field() ?><input type="hidden" name="aksi" value="simpan">
<div class="grid g2">

  <section class="card">
    <div class="card-head"><h3>Identitas Kost</h3></div>
    <div class="card-pad">
      <div class="field"><label>Nama Kost</label><input type="text" name="nama_kost" value="<?= e(setting('nama_kost')) ?>"></div>
      <div class="field"><label>Tagline</label><input type="text" name="tagline" value="<?= e(setting('tagline')) ?>"></div>
      <div class="field"><label>Alamat</label><textarea name="alamat" rows="2"><?= e(setting('alamat')) ?></textarea></div>
      <div class="field"><label>WhatsApp Admin (penerima notifikasi)</label>
        <input type="text" name="wa_admin" value="<?= e(setting('wa_admin')) ?>">
        <div class="hint">Semua laporan booking &amp; pembayaran masuk dikirim ke nomor ini.</div></div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Rekening &amp; Tagihan</h3></div>
    <div class="card-pad">
      <div class="form-grid">
        <div class="field"><label>Nama Bank</label><input type="text" name="bank_nama" value="<?= e(setting('bank_nama')) ?>"></div>
        <div class="field"><label>No. Rekening</label><input type="text" name="bank_rek" value="<?= e(setting('bank_rek')) ?>"></div>
      </div>
      <div class="field"><label>Atas Nama</label><input type="text" name="bank_an" value="<?= e(setting('bank_an')) ?>"></div>
      <div class="form-grid">
        <div class="field"><label>Denda per Hari (Rp)</label>
          <input type="number" name="denda_per_hari" value="<?= e(setting('denda_per_hari')) ?>" step="500"></div>
        <div class="field"><label>Ingatkan H- (hari)</label>
          <input type="number" name="reminder_h" value="<?= e(setting('reminder_h')) ?>" min="0" max="30">
          <div class="hint">Pengingat pertama dikirim H-<?= e(setting('reminder_h')) ?> sebelum jatuh tempo.</div></div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Integrasi WhatsApp</h3></div>
    <div class="card-pad">
      <div class="field"><label>Driver</label>
        <select name="wa_driver">
          <?php foreach ([
            'log'    => 'Log saja (tanpa kirim — untuk uji coba)',
            'fonnte' => 'Fonnte',
            'wablas' => 'Wablas',
            'cloud'  => 'WhatsApp Cloud API (Meta)',
          ] as $k => $v): ?>
            <option value="<?= $k ?>" <?= setting('wa_driver') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Token / API Key</label>
        <input type="text" name="wa_token" value="<?= e(setting('wa_token')) ?>" placeholder="tempel token gateway di sini"></div>
      <div class="field"><label>Endpoint</label>
        <input type="text" name="wa_endpoint" value="<?= e(setting('wa_endpoint')) ?>">
        <div class="hint">Fonnte: https://api.fonnte.com/send &middot;
          Cloud API: https://graph.facebook.com/v20.0/&lt;PHONE_NUMBER_ID&gt;/messages</div></div>
      <div class="field"><label>Footer Pesan</label>
        <textarea name="wa_footer" rows="2"><?= e(setting('wa_footer')) ?></textarea></div>
      <a class="btn btn-line btn-sm" href="<?= url('admin/wa_log.php') ?>">Buka Log &amp; Uji Kirim</a>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Helpdesk WhatsApp</h3>
      <span class="small muted">tombol bantuan melayang</span></div>
    <div class="card-pad">
      <label class="row mb" style="gap:8px">
        <input type="checkbox" name="helpdesk_aktif" value="1" style="width:auto"
               <?= setting('helpdesk_aktif', '1') === '1' ? 'checked' : '' ?>>
        <span>Tampilkan tombol bantuan di website</span>
      </label>
      <div class="form-grid">
        <div class="field"><label>Judul</label>
          <input type="text" name="helpdesk_judul" value="<?= e(setting('helpdesk_judul')) ?>"
                 placeholder="Butuh Bantuan?"></div>
        <div class="field"><label>Teks Ajakan</label>
          <input type="text" name="helpdesk_teks" value="<?= e(setting('helpdesk_teks')) ?>"
                 placeholder="Chat dengan Kami"></div>
      </div>
      <div class="field"><label>Kalimat Sapaan</label>
        <textarea name="helpdesk_sapaan" rows="2"><?= e(setting('helpdesk_sapaan')) ?></textarea>
        <div class="hint">Muncul di atas daftar kontak saat panel bantuan dibuka.</div></div>
      <div class="alert alert-info small">Nomor kontaknya dikelola pada kartu
        <b>Kontak Helpdesk</b> di bawah halaman ini.</div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Google Spreadsheet</h3>
      <span class="small muted">bot WhatsApp laporan admin</span></div>
    <div class="card-pad">
      <label class="row mb" style="gap:8px">
        <input type="checkbox" name="sheets_aktif" value="1" style="width:auto"
               <?= setting('sheets_aktif', '0') === '1' ? 'checked' : '' ?>>
        <span>Aktifkan integrasi Spreadsheet</span>
      </label>
      <div class="field"><label>URL Web App (Apps Script)</label>
        <input type="text" name="sheets_url" value="<?= e(setting('sheets_url')) ?>"
               placeholder="https://script.google.com/macros/s/..../exec">
        <div class="hint">Salin dari Apps Script &rarr; Deploy &rarr; Web app. Harus diakhiri <code>/exec</code>.</div></div>
      <div class="field"><label>Kunci API</label>
        <input type="text" name="sheets_key" value="<?= e(setting('sheets_key')) ?>"
               placeholder="nilai API_KEY di Script properties">
        <div class="hint">Harus sama persis dengan <code>API_KEY</code> di Script properties Apps Script.</div></div>
      <div class="field"><label>ID Spreadsheet</label>
        <input type="text" name="sheets_id" value="<?= e(setting('sheets_id')) ?>"
               placeholder="ambil dari URL Spreadsheet">
        <div class="hint">Untuk tombol <b>Buka Spreadsheet</b> di Buku Kas &amp; Laporan.</div></div>
      <div class="alert alert-info small">
        Transaksi dari chat WhatsApp ditarik otomatis saat halaman Buku Kas atau
        Laporan dibuka (maksimal sekali tiap <?= SHEETS_PULL_JEDA ?> detik) dan pada job harian.<br>
        Sinkron terakhir: <b><?= e(setting('sheets_last_sync') ?: 'belum pernah') ?></b>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Penjadwalan Otomatis</h3></div>
    <div class="card-pad">
      <div class="field"><label>Kunci Cron</label>
        <input type="text" name="cron_key" value="<?= e(setting('cron_key')) ?>">
        <div class="hint">Rahasia. Dipakai untuk mengamankan endpoint cron.</div></div>
      <div class="alert alert-info small" style="word-break:break-all">
        <b>URL cron:</b><br><?= e($cronUrl) ?><br><br>
        InfinityFree tidak menyediakan cron job. Daftarkan URL di atas pada layanan gratis
        seperti <b>cron-job.org</b> (jadwal harian, mis. pukul 08.00).
        Sebagai cadangan, sistem juga menjalankan job ini otomatis satu kali sehari saat
        ada pengunjung membuka website.
      </div>
      <div class="small muted mb">Terakhir dijalankan: <b><?= e(setting('last_daily_run') ?: 'belum pernah') ?></b></div>
    </div>
  </section>
</div>
<div class="mt"><button class="btn">Simpan Semua Pengaturan</button></div>
</form>

<div class="grid g2 mt">
  <section class="card">
    <div class="card-head"><h3>Jalankan Job Manual</h3></div>
    <div class="card-pad">
      <p class="small muted mb">Menghitung denda, menandai tagihan terlambat, mengirim pengingat WhatsApp,
        dan memproses antrean pesan.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="jalankan_job">
        <button class="btn btn-warn" data-confirm="Jalankan job harian sekarang? Pesan pengingat akan dikirim.">Jalankan Sekarang</button>
      </form>
      <?php if (sheets_aktif()): ?>
        <hr class="sep">
        <p class="small muted mb">Uji sambungan ke Apps Script, atau kirim ulang seluruh
          transaksi &amp; data pendukung ke Spreadsheet.</p>
        <div class="row">
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="aksi" value="sheets_tes">
            <button class="btn btn-sm btn-line">Uji Koneksi</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="aksi" value="sheets_sync">
            <button class="btn btn-sm btn-soft" data-confirm="Kirim ulang semua data ke Spreadsheet?">Sinkron Penuh</button></form>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>Akun Admin</h3></div>
    <div class="table-wrap"><table class="tb">
      <thead><tr><th>Nama</th><th>WhatsApp (login)</th><th>Email</th></tr></thead>
      <tbody><?php foreach ($adminList as $a): ?>
        <tr><td><?= e($a['nama']) ?></td><td class="small"><b><?= e($a['no_hp']) ?></b></td>
            <td class="small muted"><?= e($a['email'] ?: '—') ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="card-pad" style="border-top:1px solid var(--line)">
      <button class="btn btn-sm btn-line" onclick='openModal("mAdmin")'>+ Tambah Admin</button>
    </div>
  </section>
</div>

<section class="card mt">
  <div class="card-head"><h3>Kontak Helpdesk</h3>
    <button class="btn btn-sm" onclick='openModal("mHelp",{id:"",judul:"Tambah Kontak Helpdesk",nama:"",peran:"",no_hp:"",jam:"",pesan_awal:"",urutan:<?= count($helpdesk) + 1 ?>,status:"aktif"})'>+ Tambah Kontak</button>
  </div>
  <?php if (!$helpdesk): ?>
    <div class="empty"><span class="big"><?= icon('wa', 44) ?></span>Belum ada kontak helpdesk.
      Tombol bantuan tidak akan muncul sampai ada minimal satu kontak aktif.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tb">
    <thead><tr><th>Urut</th><th>Nama</th><th>Peran</th><th>WhatsApp</th><th>Jam Layanan</th>
      <th>Pesan Awal</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($helpdesk as $h): ?>
      <tr>
        <td><?= (int) $h['urutan'] ?></td>
        <td><b><?= e($h['nama']) ?></b></td>
        <td class="small muted"><?= e($h['peran'] ?: '—') ?></td>
        <td class="small"><a target="_blank" href="https://wa.me/<?= e($h['no_hp']) ?>"><?= e($h['no_hp']) ?></a></td>
        <td class="small muted"><?= e($h['jam'] ?: '—') ?></td>
        <td class="small muted"><?= e(mb_strimwidth((string) $h['pesan_awal'], 0, 40, '…')) ?: '—' ?></td>
        <td><?= $h['status'] === 'aktif' ? badge('aktif') : badge('keluar') ?></td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-soft" onclick='openModal("mHelp", <?= json_encode([
            'id' => $h['id'], 'judul' => 'Edit ' . $h['nama'], 'nama' => $h['nama'], 'peran' => $h['peran'],
            'no_hp' => $h['no_hp'], 'jam' => $h['jam'], 'pesan_awal' => $h['pesan_awal'],
            'urutan' => $h['urutan'], 'status' => $h['status'],
          ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="helpdesk_hapus">
            <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Hapus kontak <?= e($h['nama']) ?>?">&times;</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="modal" id="mHelp">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="helpdesk_simpan">
    <input type="hidden" name="id" data-f="id">
    <div class="modal-head"><h3 data-f="judul">Kontak Helpdesk</h3>
      <button type="button" class="modal-x" onclick="closeModal('mHelp')">&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label>Nama *</label><input type="text" name="nama" data-f="nama" placeholder="Ibu Pengelola" required></div>
        <div class="field"><label>Peran</label><input type="text" name="peran" data-f="peran" placeholder="Pengelola Branch 1"></div>
        <div class="field"><label>No. WhatsApp *</label><input type="text" name="no_hp" data-f="no_hp" placeholder="081234567890" required></div>
        <div class="field"><label>Jam Layanan</label><input type="text" name="jam" data-f="jam" placeholder="08.00 - 21.00 WIB"></div>
        <div class="field"><label>Urutan Tampil</label><input type="number" name="urutan" data-f="urutan" min="1" value="1"></div>
        <div class="field"><label>Status</label>
          <select name="status" data-f="status">
            <option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option>
          </select></div>
      </div>
      <div class="field"><label>Pesan Awal</label>
        <input type="text" name="pesan_awal" data-f="pesan_awal"
               placeholder="Halo Boarding House, saya ingin bertanya tentang ">
        <div class="hint">Teks ini sudah terketik otomatis di WhatsApp pengunjung saat chat dibuka.</div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mHelp')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>

<div class="modal" id="mAdmin">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="admin_baru">
    <div class="modal-head"><h3>Tambah Admin</h3>
      <button type="button" class="modal-x" onclick="closeModal('mAdmin')">&times;</button></div>
    <div class="modal-body">
      <div class="field"><label>Nama *</label><input type="text" name="nama" required></div>
      <div class="field"><label>No. WhatsApp *</label><input type="text" name="no_hp" placeholder="081234567890" required>
        <div class="hint">Dipakai sebagai identitas login admin.</div></div>
      <div class="field"><label>Email <span class="muted">(opsional)</span></label><input type="email" name="email"></div>
      <div class="field"><label>Kata Sandi *</label><input type="password" name="password" required></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-soft" onclick="closeModal('mAdmin')">Batal</button>
      <button class="btn">Simpan</button>
    </div>
  </form>
</div>
<?php layout_foot('admin'); ?>
