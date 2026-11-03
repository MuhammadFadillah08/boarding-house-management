<?php
require_once __DIR__ . '/app/bootstrap.php';

if (is_login()) redirect('user/index.php');

$err = '';
if (is_post()) {
    csrf_check();
    $nama  = post('nama');
    $hp    = normalize_hp(post('no_hp'));
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if ($nama === '' || $hp === '' || $pass === '') {
        $err = 'Semua kolom wajib diisi.';
    } elseif (mb_strlen($nama) < 3) {
        $err = 'Nama minimal 3 karakter.';
    } elseif (strlen($hp) < 10 || strlen($hp) > 15) {
        $err = 'Nomor WhatsApp tidak valid. Contoh: 081234567890';
    } elseif (strlen($pass) < 6) {
        $err = 'Kata sandi minimal 6 karakter.';
    } elseif ($pass !== $pass2) {
        $err = 'Konfirmasi kata sandi tidak cocok.';
    } elseif (scalar('SELECT id FROM users WHERE no_hp = ?', [$hp])) {
        $err = 'Nomor WhatsApp sudah terdaftar. Silakan masuk.';
    } else {
        $id = insert('users', [
            'nama'     => $nama,
            'no_hp'    => $hp,
            'password' => password_hash($pass, PASSWORD_BCRYPT),
            'role'     => 'user',
        ]);
        wa_send($hp, "Halo *$nama* 👋\n\nAkun Anda di *" . setting('nama_kost')
            . "* berhasil dibuat.\n\n" . tt('Nomor login', $hp)
            . "\nSilakan masuk ke website untuk melihat katalog kamar dan mengajukan booking.",
            'akun_baru', $id, $nama);

        attempt_login($hp, $pass);
        flash('Pendaftaran berhasil. Selamat datang, ' . $nama . '!');
        redirect('user/index.php');
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar &middot; <?= e(setting('nama_kost')) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="area-auth">
<div class="auth-wrap">
  <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>

  <div class="auth pop-in">
    <div class="auth-logo"><?= logo_mark(60) ?></div>
    <h1>Buat Akun</h1>
    <p class="sub">Cukup 3 langkah — nama, nomor WhatsApp, kata sandi.</p>

    <?php if ($err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field fade-up" style="--d:.05s">
        <label for="nama">Nama Lengkap</label>
        <div class="inp-ico">
          <span><?= icon('profil', 17) ?></span>
          <input type="text" id="nama" name="nama" value="<?= e(post('nama')) ?>"
                 placeholder="Nama sesuai KTP" required autofocus>
        </div>
      </div>
      <div class="field fade-up" style="--d:.1s">
        <label for="no_hp">Nomor WhatsApp</label>
        <div class="inp-ico">
          <span><?= icon('wa', 17) ?></span>
          <input type="tel" id="no_hp" name="no_hp" value="<?= e(post('no_hp')) ?>"
                 placeholder="081234567890" inputmode="numeric" required>
        </div>
        <div class="hint">Dipakai untuk masuk sekaligus menerima pengingat tagihan.</div>
      </div>
      <div class="field fade-up" style="--d:.15s">
        <label for="password">Kata Sandi</label>
        <div class="inp-ico">
          <span><?= icon('kunci', 17) ?></span>
          <input type="password" id="password" name="password" placeholder="Minimal 6 karakter" required>
          <button type="button" class="eye" data-toggle="password" aria-label="Tampilkan kata sandi"><span class="ic-on"><?= icon('lihat', 17) ?></span><span class="ic-off"><?= icon('lihat-off', 17) ?></span></button>
        </div>
        <div class="meter"><i></i></div>
      </div>
      <div class="field fade-up" style="--d:.2s">
        <label for="password2">Ulangi Kata Sandi</label>
        <div class="inp-ico">
          <span><?= icon('kunci', 17) ?></span>
          <input type="password" id="password2" name="password2" placeholder="Ketik ulang kata sandi" required>
        </div>
      </div>
      <button class="btn btn-block btn-glow fade-up" style="--d:.25s" type="submit">Daftar Sekarang</button>
    </form>

    <p class="auth-alt">Sudah punya akun? <a href="<?= url('login.php') ?>">Masuk di sini</a></p>
    <p class="auth-alt"><a href="<?= url('index.php') ?>">&larr; Lihat katalog kamar</a></p>
  </div>
</div>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
