<?php
/**
 * Potongan HTML yang dipakai bersama oleh halaman katalog dan
 * endpoint JSON api/kamar.php (agar status real-time konsisten).
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/**
 * Pembungkus badge status kamar.
 * Kelasnya sengaja "room-stat", bukan "stat" — "stat" sudah dipakai kartu
 * statistik dashboard, sehingga badge ikut mewarisi kotak putih besar.
 */
function room_badge(array $k): string
{
    return '<span class="room-stat" data-status="' . e($k['status']) . '">' . badge($k['status']) . '</span>';
}

function room_button(array $k): string
{
    $id = (int) $k['id'];
    if ($k['status'] !== 'tersedia') {
        $txt = $k['status'] === 'perbaikan' ? 'Sedang Perbaikan'
             : ($k['status'] === 'dibooking' ? 'Sedang Dibooking' : 'Sudah Terisi');
        return '<span class="act btn btn-block off">' . $txt . '</span>';
    }
    if (!is_login()) {
        return '<a class="act btn btn-block" href="' . url('login.php?next=booking&kamar=' . $id) . '">Masuk untuk Booking</a>';
    }
    if (is_admin()) {
        return '<a class="act btn btn-block btn-line" href="' . url('admin/kamar.php') . '">Kelola Kamar</a>';
    }
    return '<a class="act btn btn-block" href="' . url('user/booking.php?kamar=' . $id) . '">Ajukan Booking</a>';
}

function room_card(array $k, float $delay = 0): string
{
    $foto = !empty($k['foto']) && is_file(KAMAR_DIR . $k['foto'])
        ? '<img src="' . e(KAMAR_URL . $k['foto']) . '" alt="Kamar ' . e($k['kode']) . '" loading="lazy">'
        : icon('kamar', 46, 'room-ph');

    $fac = '';
    foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $k['fasilitas']))), 0, 4) as $f) {
        $fac .= '<span>' . e($f) . '</span>';
    }
    $tag = !empty($k['kost_nama']) ? '<span class="kost-tag">' . e($k['kost_nama']) . '</span>' : '';

    return '<article class="room reveal" data-room="' . (int) $k['id'] . '" style="--d:' . $delay . 's">'
        . '<div class="room-img">' . $foto . $tag . room_badge($k) . '</div>'
        . '<div class="room-body">'
        . '<div class="room-code">Kamar ' . e($k['kode']) . ' &middot; Lantai ' . (int) $k['lantai'] . '</div>'
        . '<h3>' . e($k['tipe']) . '</h3>'
        . '<div class="room-price">' . rp($k['harga']) . ' <span>/ bulan</span></div>'
        . '<p class="small muted">' . e(mb_strimwidth((string) $k['deskripsi'], 0, 92, '…')) . '</p>'
        . '<div class="fac">' . $fac . '</div>'
        . '</div><div class="room-foot">' . room_button($k) . '</div></article>';
}
