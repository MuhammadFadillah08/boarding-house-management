<?php
require_once __DIR__ . '/../app/bootstrap.php';
$u   = require_user();
$err = '';

if (is_post() && post('aksi') === 'profil') {
    csrf_check();
    $hp    = normalize_hp(post('no_hp'));
    $email = strtolower(post('email'));
    if (post('nama') === '' || strlen($hp) < 10) {
        $err = 'Nama dan nomor WhatsApp wajib diisi dengan benar.';
    } elseif (scalar('SELECT id FROM users WHERE no_hp = ? AND id <> ?', [$hp, $u['id']])) {
        $err = 'Nomor WhatsApp tersebut sudah dipakai akun lain.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Format email tidak valid.';
    } elseif ($email !== '' && scalar('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
        $err = 'Email tersebut sudah dipakai akun lain.';
    } else {
        update('users', [
            'nama'   => post('nama'),
            'no_hp'  => $hp,
            'email'  => $email !== '' ? $email : null,
            'no_ktp' => post('no_ktp'),
            'alamat' => post('alamat'),
        ], 'id = ?', [$u['id']]);
        $_SESSION['nama'] = post('nama');
        flash('Profil berhasil diperbarui.');
        redirect('user/profil.php');
    }
}

if (is_post() && post('aksi') === 'sandi') {
    csrf_check();
    $lama = (string) ($_POST['lama'] ?? '');
    $baru = (string) ($_POST['baru'] ?? '');
    if (!password_verify($lama, $u['password'])) {
        $err = 'Kata sandi lama salah.';
    } elseif (strlen($baru) < 6) {
        $err = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($baru !== (string) ($_POST['baru2'] ?? '')) {
        $err = 'Konfirmasi kata sandi tidak cocok.';
    } else {
        update('users', ['password' => password_hash($baru, PASSWORD_BCRYPT)], 'id = ?', [$u['id']]);
        flash('Kata sandi berhasil diganti.');
        redirect('user/profil.php');
    }
}

$u = one('SELECT * FROM users WHERE id = ?', [$u['id']]);
layout_head('Profil Saya', 'user', 'profil');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<div class="grid g2">
  <section class="card">
    <div class="card-head"><h3>Data Diri</h3></div>
    <form class="card-pad" method="post">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="profil">
      <div class="field"><label>Nama Lengkap</label><input type="text" name="nama" value="<?= e($u['nama']) ?>" required></div>
      <div class="field"><label>No. WhatsApp</label><input type="text" name="no_hp" value="<?= e($u['no_hp']) ?>" required>
        <div class="hint">Dipakai untuk masuk ke akun sekaligus menerima pengingat tagihan.</div></div>
      <div class="field"><label>Email <span class="muted">(opsional)</span></label>
        <input type="email" name="email" value="<?= e($u['email']) ?>" placeholder="belum diisi">
        <div class="hint">Boleh dikosongkan — semua notifikasi dikirim lewat WhatsApp.</div></div>
      <div class="field"><label>No. KTP</label><input type="text" name="no_ktp" value="<?= e($u['no_ktp']) ?>"></div>
      <div class="field"><label>Alamat Asal</label><textarea name="alamat" rows="3"><?= e($u['alamat']) ?></textarea></div>
      <button class="btn">Simpan Perubahan</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h3>Ganti Kata Sandi</h3></div>
    <form class="card-pad" method="post">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="sandi">
      <div class="field"><label>Kata Sandi Lama</label><input type="password" name="lama" required></div>
      <div class="field"><label>Kata Sandi Baru</label><input type="password" name="baru" required></div>
      <div class="field"><label>Ulangi Sandi Baru</label><input type="password" name="baru2" required></div>
      <button class="btn">Ganti Kata Sandi</button>
    </form>
    <div class="card-pad" style="border-top:1px solid var(--line)">
      <div class="small muted">Terdaftar sejak <?= e(tgl($u['created_at'])) ?></div>
    </div>
  </section>
</div>
<?php layout_foot('user'); ?>
