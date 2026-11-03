<?php
require_once __DIR__ . '/app/bootstrap.php';

if (is_login()) {
    redirect(is_admin() ? 'admin/index.php' : 'user/index.php');
}

$err  = '';
$next = get('next');
$kmr  = (int) get('kamar');

if (is_post()) {
    csrf_check();
    $err = attempt_login(post('identitas'), $_POST['password'] ?? '');
    if ($err === null) {
        flash('Selamat datang kembali, ' . $_SESSION['nama'] . '!');
        if (is_admin())                  redirect('admin/index.php');
        if ($next === 'booking' && $kmr) redirect('user/booking.php?kamar=' . $kmr);
        redirect('user/index.php');
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk &middot; <?= e(setting('nama_kost')) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="area-auth">
<div class="auth-wrap">
  <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>

  <div class="auth pop-in">
    <div class="auth-logo"><?= logo_mark(60) ?></div>
    <h1>Selamat Datang</h1>
    <p class="sub"><?= e(setting('nama_kost')) ?> — <?= e(setting('tagline')) ?></p>

    <?= render_flash() ?>
    <?php if ($err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field fade-up" style="--d:.05s">
        <label for="identitas">Nomor WhatsApp</label>
        <div class="inp-ico">
          <span><?= icon('wa', 17) ?></span>
          <input type="text" id="identitas" name="identitas" value="<?= e(post('identitas')) ?>"
                 placeholder="081234567890" required autofocus>
        </div>
      </div>
      <div class="field fade-up" style="--d:.1s">
        <label for="password">Kata Sandi</label>
        <div class="inp-ico">
          <span><?= icon('kunci', 17) ?></span>
          <input type="password" id="password" name="password" placeholder="Kata sandi Anda" required>
          <button type="button" class="eye" data-toggle="password" aria-label="Tampilkan kata sandi"><span class="ic-on"><?= icon('lihat', 17) ?></span><span class="ic-off"><?= icon('lihat-off', 17) ?></span></button>
        </div>
      </div>
      <button class="btn btn-block btn-glow fade-up" style="--d:.15s" type="submit">Masuk</button>
    </form>

    <p class="auth-alt">Belum punya akun? <a href="<?= url('register.php') ?>">Daftar sekarang</a></p>
    <p class="auth-alt"><a href="<?= url('index.php') ?>">&larr; Lihat katalog kamar</a></p>

    <div class="demo">
      <b>Akun demo</b>
      <div>Admin &nbsp;— <code>628123456789</code> / <code>admin123</code></div>
      <div>Penyewa — <code>628987654321</code> / <code>user123</code></div>
    </div>
  </div>
</div>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
