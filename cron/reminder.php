<?php
/**
 * Endpoint penjadwalan harian.
 *
 * Panggil:  https://domain-anda/boarding-house-management/cron/reminder.php?key=CRON_KEY
 *
 * InfinityFree tidak menyediakan cron job pada paket gratis. Solusinya:
 *   1. Daftar gratis di https://cron-job.org
 *   2. Buat job baru, tempel URL di atas, jadwalkan tiap hari pukul 08:00.
 * Cadangan: app/jobs.php juga menjalankan tugas ini otomatis maksimal
 * satu kali sehari saat ada pengunjung membuka website.
 */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/../app/bootstrap.php';

$key   = $_GET['key'] ?? ($argv[1] ?? '');
$valid = setting('cron_key');

if ($valid === '' || !hash_equals($valid, (string) $key)) {
    http_response_code(403);
    exit("403 Forbidden - kunci cron tidak cocok.\n");
}

$mulai = microtime(true);
$hasil = run_daily_jobs();
$durasi = round(microtime(true) - $mulai, 2);

$out = [
    'ok'              => true,
    'waktu'           => date('c'),
    'durasi_detik'    => $durasi,
    'tandai_terlambat'=> $hasil['terlambat'],
    'denda_diperbarui'=> $hasil['denda'],
    'pengingat_wa'    => $hasil['reminder'],
    'antrean_dikirim' => $hasil['queue'],
];

if (PHP_SAPI === 'cli') {
    foreach ($out as $k => $v) echo str_pad($k, 20) . ': ' . var_export($v, true) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
