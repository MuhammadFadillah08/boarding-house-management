<?php
/** Endpoint JSON status kamar (dipakai polling katalog real-time). */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rows = all('SELECT m.id, m.kode, m.status, m.harga, k.nama AS kost_nama
             FROM kamar m JOIN kost k ON k.id = m.kost_id
             ORDER BY k.urutan, m.kode');
$data = [];
foreach ($rows as $k) {
    $data[] = [
        'id'     => (int) $k['id'],
        'kode'   => $k['kode'],
        'kost'   => $k['kost_nama'],
        'status' => $k['status'],
        'badge'  => room_badge($k),
        'tombol' => room_button($k),
    ];
}

echo json_encode([
    'ok'       => true,
    'ts'       => date('c'),
    'tersedia' => count(array_filter($rows, fn($r) => $r['status'] === 'tersedia')),
    'data'     => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
