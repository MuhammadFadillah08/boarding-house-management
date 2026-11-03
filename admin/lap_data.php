<?php
/**
 * Query bersama untuk laporan keuangan (dipakai layar, cetak PDF, dan Excel).
 * Input: $dari, $sampai (YYYY-MM-DD). Output: array data laporan.
 */
declare(strict_types=1);

function laporan_data(string $dari, string $sampai): array
{
    // Pastikan transaksi yang dicatat lewat bot WhatsApp sudah masuk dulu,
    // supaya laporan tidak menampilkan angka yang tertinggal. Dibatasi jeda
    // di dalam fungsinya, jadi aman dipanggil tiap membuka laporan.
    sheets_maybe_tarik();

    // Pemasukan = pembayaran berstatus diterima dalam rentang tanggal bayar
    $masuk = all('SELECT p.*, u.nama, t.kode AS kode_tagihan, t.periode,
                         k.kode AS kamar_kode, ks.nama AS kost
                  FROM pembayaran p
                  JOIN users u    ON u.id = p.user_id
                  JOIN tagihan t  ON t.id = p.tagihan_id
                  JOIN penghuni h ON h.id = t.penghuni_id
                  JOIN kamar k    ON k.id = h.kamar_id
                  JOIN kost  ks   ON ks.id = k.kost_id
                  WHERE p.status = "diterima" AND p.tgl_bayar BETWEEN ? AND ?
                  ORDER BY p.tgl_bayar ASC, p.id ASC', [$dari, $sampai]);

    // Tagihan yang terbit pada rentang (berdasarkan jatuh tempo)
    $tagihan = all('SELECT t.*, u.nama, k.kode AS kamar_kode, ks.nama AS kost,
                    (SELECT COALESCE(SUM(pb.jumlah),0) FROM pembayaran pb
                     WHERE pb.tagihan_id = t.id AND pb.status = "diterima") AS terbayar
                    FROM tagihan t
                    JOIN penghuni h ON h.id = t.penghuni_id
                    JOIN users u    ON u.id = h.user_id
                    JOIN kamar k    ON k.id = h.kamar_id
                    JOIN kost  ks   ON ks.id = k.kost_id
                    WHERE t.jatuh_tempo BETWEEN ? AND ?
                    ORDER BY ks.urutan, k.kode ASC', [$dari, $sampai]);

    // Buku kas manual: pemasukan lain-lain & pengeluaran
    $kasMasuk  = kas_daftar($dari, $sampai, 'masuk');
    $kasKeluar = kas_daftar($dari, $sampai, 'keluar');

    $perMetode = $perKost = $perKategori = [];
    foreach ($masuk as $m) {
        $perMetode[$m['metode']] = ($perMetode[$m['metode']] ?? 0) + (float) $m['jumlah'];
        $perKost[$m['kost']]     = ($perKost[$m['kost']] ?? 0) + (float) $m['jumlah'];
    }
    foreach ($kasMasuk as $m) {
        $perKost[$m['kost'] ?: 'Umum'] = ($perKost[$m['kost'] ?: 'Umum'] ?? 0) + (float) $m['jumlah'];
    }
    foreach ($kasKeluar as $m) {
        $perKategori[$m['kategori']] = ($perKategori[$m['kategori']] ?? 0) + (float) $m['jumlah'];
    }
    arsort($perKategori);

    $totalSewa   = array_sum(array_map(fn($m) => (float) $m['jumlah'], $masuk));
    $totalLain   = array_sum(array_map(fn($m) => (float) $m['jumlah'], $kasMasuk));
    $totalKeluar = array_sum(array_map(fn($m) => (float) $m['jumlah'], $kasKeluar));

    $totalTagih  = array_sum(array_map(fn($t) => (float) $t['jumlah'] + (float) $t['denda'], $tagihan));
    $totalDenda  = array_sum(array_map(fn($t) => (float) $t['denda'], $tagihan));
    $totalPiutang = array_sum(array_map(
        fn($t) => max(0, ((float) $t['jumlah'] + (float) $t['denda']) - (float) $t['terbayar']),
        $tagihan
    ));

    return [
        'dari'         => $dari,
        'sampai'       => $sampai,
        'masuk'        => $masuk,
        'kas_masuk'    => $kasMasuk,
        'kas_keluar'   => $kasKeluar,
        'tagihan'      => $tagihan,
        'per_metode'   => $perMetode,
        'per_kost'     => $perKost,
        'per_kategori' => $perKategori,
        'total'        => [
            'sewa'    => $totalSewa,
            'lain'    => $totalLain,
            'masuk'   => $totalSewa + $totalLain,   // seluruh pemasukan
            'keluar'  => $totalKeluar,
            'saldo'   => $totalSewa + $totalLain - $totalKeluar,
            'tagih'   => $totalTagih,
            'denda'   => $totalDenda,
            'piutang' => $totalPiutang,
            'lunas'   => count(array_filter($tagihan, fn($t) => $t['status'] === 'lunas')),
            'belum'   => count(array_filter($tagihan, fn($t) => $t['status'] !== 'lunas')),
            'hunian'  => (int) scalar('SELECT COUNT(*) FROM penghuni WHERE status = "aktif"', [], 0),
            'kamar'   => (int) scalar('SELECT COUNT(*) FROM kamar', [], 0),
        ],
    ];
}

/** Rentang tanggal dari input periode (YYYY-MM) atau custom. */
function laporan_rentang(): array
{
    $mode = get('mode', 'bulan');
    if ($mode === 'custom' && get('dari') && get('sampai')) {
        return [get('dari'), get('sampai'), 'Periode ' . tgl(get('dari')) . ' s/d ' . tgl(get('sampai'))];
    }
    $periode = periode_atau_kini(get('periode'));
    $dari    = $periode . '-01';
    $sampai  = date('Y-m-t', (int) strtotime($dari));
    return [$dari, $sampai, 'Periode ' . periode_label($periode)];
}
