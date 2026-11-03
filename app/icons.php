<?php
/**
 * Kumpulan ikon SVG inline (garis, 24×24, mewarisi currentColor).
 * Menggantikan emoji agar tampilan konsisten di semua sistem operasi —
 * emoji dirender berbeda-beda di Windows, Android, dan iOS.
 *
 *   <?= icon('kamar') ?>            ukuran bawaan 20px
 *   <?= icon('kamar', 28) ?>        ukuran khusus
 *   <?= icon('kamar', 20, 'kelas') ?>
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

function icon_path(string $name): string
{
    static $set = [
        // -- navigasi ------------------------------------------------
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'kost'      => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-5h6v5"/><path d="M9 11h.01M15 11h.01"/>',
        'kamar'     => '<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><path d="M14 12h.01"/>',
        'booking'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'penghuni'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'tagihan'   => '<path d="M4 3v18l2.5-1.5L9 21l2.5-1.5L14 21l2.5-1.5L19 21V3l-2.5 1.5L14 3l-2.5 1.5L9 3 6.5 4.5 4 3Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'pembayaran'=> '<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
        'kas'       => '<path d="M3 6h18v12H3z"/><circle cx="12" cy="12" r="2.5"/><path d="M7 12h.01M17 12h.01"/>',
        'laporan'   => '<path d="M3 3v18h18"/><path d="m7 15 3.5-4 3 2.5L20 7"/>',
        'wa'        => '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.4 8.5 8.5 0 0 1-4-1L3 21l2.2-5.4A8.4 8.4 0 0 1 12.5 3a8.4 8.4 0 0 1 8.5 8.5Z"/>',
        'pengaturan'=> '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 8.9 19a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 5 8.9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'keluar'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'profil'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'katalog'   => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',

        // -- aksi ----------------------------------------------------
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'minus'     => '<path d="M5 12h14"/>',
        'cari'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'unduh'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'cetak'     => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 15h12v6H6z"/>',
        'lihat'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'lihat-off' => '<path d="M10.6 5.2A9.6 9.6 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.4 3.3M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.5 9.5 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/>',
        'kunci'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'hapus'     => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
        'tutup'     => '<path d="M18 6 6 18M6 6l12 12"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'atas'      => '<path d="m18 15-6-6-6 6"/>',
        'kanan'     => '<path d="m9 18 6-6-6-6"/>',
        'kiri'      => '<path d="m15 18-6-6 6-6"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
        'centang'   => '<path d="m5 13 4 4L19 7"/>',
        'jam'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'lokasi'    => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'peringatan'=> '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
    ];
    return $set[$name] ?? '';
}

function icon(string $name, int $size = 20, string $class = ''): string
{
    $p = icon_path($name);
    if ($p === '') return '';
    return '<svg class="ic ' . e($class) . '" width="' . $size . '" height="' . $size . '"'
         . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $p . '</svg>';
}

/** Logo rumah, dipakai di brand & halaman masuk. */
function logo_mark(int $size = 30): string
{
    return '<svg class="logo-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32"'
         . ' fill="none" aria-hidden="true" focusable="false">'
         . '<rect width="32" height="32" rx="9" fill="url(#lg)"/>'
         . '<path d="M8 15.5 16 9l8 6.5V23a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 8 23v-7.5Z"'
         . ' stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/>'
         . '<path d="M13.5 24.5v-5h5v5" stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/>'
         . '<defs><linearGradient id="lg" x1="0" y1="0" x2="32" y2="32">'
         . '<stop stop-color="#8FAF9B"/><stop offset="1" stop-color="#5F8471"/>'
         . '</linearGradient></defs></svg>';
}

/** Logo WhatsApp resmi (dipakai tombol helpdesk). */
function icon_whatsapp(int $size = 28): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" fill="currentColor"'
         . ' aria-hidden="true" focusable="false"><path d="M16 3C8.8 3 3 8.8 3 16c0 2.3.6 4.5 1.7 6.4L3 29l6.8-1.8c1.9 1 4 1.6'
         . ' 6.2 1.6 7.2 0 13-5.8 13-13S23.2 3 16 3zm0 23.6c-2 0-3.9-.5-5.5-1.5l-.4-.2-4 1.1 1.1-3.9-.3-.4a10.5 10.5 0 1 1 9.1'
         . ' 4.9zm5.8-7.9c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2s-.8 1-1 1.2c-.2.2-.4.2-.7.1-1.9-.9-3.1-1.7-4.4-3.8-.3-.6.3-.5.9-1.7.1-.2'
         . ' 0-.4 0-.6s-.7-1.7-1-2.3c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-1.1 1.1-1.1 2.6-.1 4.2 1 1.6 2.4 3.9 5.6 5.3 2.5 1.1'
         . ' 3.3.9 4.3.8.7-.1 1.9-.8 2.2-1.6.3-.8.3-1.4.2-1.6-.1-.1-.3-.2-.6-.4z"/></svg>';
}
