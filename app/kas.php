<?php
/**
 * =====================================================================
 *  BUKU KAS — pemasukan lain-lain & pengeluaran kost
 * =====================================================================
 *  Dipakai bersama oleh:
 *    - admin/kas.php          (input lewat website)
 *    - webhook/wa_incoming.php (input lewat chat WhatsApp ke bot)
 *
 *  Pemasukan sewa TIDAK dicatat di sini — sumbernya tabel `pembayaran`
 *  yang sudah diverifikasi admin. Tabel `kas` hanya untuk transaksi
 *  manual: pengeluaran operasional dan pemasukan di luar sewa.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/** Daftar kategori beserta kata kunci pengenalnya untuk bot WhatsApp. */
function kas_kategori(string $jenis): array
{
    if ($jenis === 'keluar') {
        return [
            'listrik'      => ['listrik', 'token', 'pln'],
            'air'          => ['air', 'pdam', 'pompa'],
            'internet'     => ['wifi', 'internet', 'indihome', 'kuota'],
            'kebersihan'   => ['sampah', 'kebersihan', 'cleaning', 'bersih', 'sapu'],
            'perbaikan'    => ['perbaikan', 'servis', 'service', 'rusak', 'bocor', 'cat', 'ganti', 'tukang'],
            'perlengkapan' => ['beli', 'belanja', 'alat', 'lampu', 'galon', 'gas', 'kasur', 'lemari'],
            'gaji'         => ['gaji', 'upah', 'penjaga', 'asisten', 'art'],
            'keamanan'     => ['keamanan', 'satpam', 'ronda'],
            'pajak'        => ['pajak', 'pbb', 'retribusi', 'iuran'],
            'lainnya'      => [],
        ];
    }
    return [
        'deposit'  => ['deposit', 'jaminan'],
        'denda'    => ['denda', 'telat'],
        'laundry'  => ['laundry', 'cuci'],
        'parkir'   => ['parkir'],
        'sewa_lain'=> ['aula', 'gudang', 'sewa'],
        'lainnya'  => [],
    ];
}

/** Tebak kategori dari teks keterangan. */
function kas_tebak_kategori(string $teks, string $jenis): string
{
    $teks = mb_strtolower($teks);
    foreach (kas_kategori($jenis) as $kat => $kunci) {
        foreach ($kunci as $k) {
            if (str_contains($teks, $k)) return $kat;
        }
    }
    return 'lainnya';
}

/**
 * Baca nominal dari teks bebas ala chat WhatsApp.
 *   "150000" "150.000" "150rb" "150 ribu" "1,5jt" "2 juta" "50k"
 * @return float|null null bila tidak dikenali
 */
function parse_nominal(string $teks): ?float
{
    $t = mb_strtolower(trim($teks));
    if (!preg_match('/([\d][\d.,]*)\s*(jt|juta|rb|ribu|k)?/u', $t, $m)) {
        return null;
    }
    $angka  = $m[1];
    $satuan = $m[2] ?? '';

    if ($satuan !== '') {
        // "1,5jt" / "1.5jt" -> pemisah desimal
        $angka = str_replace(',', '.', $angka);
        $titik = substr_count($angka, '.');
        if ($titik > 1) $angka = str_replace('.', '', $angka);
        $nilai = (float) $angka;
        $nilai *= in_array($satuan, ['jt', 'juta'], true) ? 1000000 : 1000;
    } else {
        // "150.000" / "150,000" -> pemisah ribuan
        $nilai = (float) preg_replace('/[.,]/', '', $angka);
    }
    return $nilai > 0 ? round($nilai, 2) : null;
}

/**
 * Simpan satu transaksi kas.
 *
 * Transaksi hasil input web langsung dicerminkan ke Google Spreadsheet supaya
 * laporan di sana lengkap dan bot WhatsApp menghitung saldo dengan benar.
 * Bila pengiriman gagal (Apps Script lambat/kuota habis), baris tetap tersimpan
 * di MySQL dengan `sinkron = 0` dan dicoba ulang oleh job harian — penyimpanan
 * lokal tidak boleh ikut gagal hanya karena layanan luar sedang bermasalah.
 *
 * @return int id baris baru
 */
function kas_catat(string $jenis, float $jumlah, string $keterangan, array $opt = []): int
{
    $jenis  = $jenis === 'masuk' ? 'masuk' : 'keluar';
    $sumber = ($opt['sumber'] ?? 'web') === 'wa' ? 'wa' : 'web';

    $id = insert('kas', [
        'kode'       => $opt['kode'] ?? kode_unik($jenis === 'masuk' ? 'KM' : 'KK', 'kas'),
        'kost_id'    => $opt['kost_id'] ?? null,
        'jenis'      => $jenis,
        'kategori'   => $opt['kategori'] ?? kas_tebak_kategori($keterangan, $jenis),
        'jumlah'     => $jumlah,
        'keterangan' => mb_substr($keterangan, 0, 255),
        'tanggal'    => $opt['tanggal'] ?? date('Y-m-d'),
        'sumber'     => $sumber,
        'user_id'    => $opt['user_id'] ?? null,
        'bukti'      => $opt['bukti'] ?? null,
        // Baris dari WA sudah ada di Spreadsheet, tidak perlu didorong balik.
        'sinkron'    => $sumber === 'wa' ? 1 : 0,
    ]);

    if ($sumber === 'web' && sheets_aktif()) {
        $row = one('SELECT k.*, ks.nama AS kost, u.nama AS pencatat FROM kas k
                    LEFT JOIN kost ks ON ks.id = k.kost_id
                    LEFT JOIN users u ON u.id = k.user_id
                    WHERE k.id = ?', [$id]);
        if ($row) sheets_kas_dorong($row);
    }
    return $id;
}

/** Cari kost berdasarkan potongan nama ("branch 3", "b3", "3"). */
function kas_cari_kost(string $teks): ?array
{
    $t = trim(mb_strtolower($teks));
    if ($t === '') return null;
    if (preg_match('/(?:branch\s*|b)?(\d+)/u', $t, $m)) {
        $k = one('SELECT * FROM kost WHERE nama = ?', ['Branch ' . $m[1]]);
        if ($k) return $k;
    }
    return one('SELECT * FROM kost WHERE LOWER(nama) LIKE ? LIMIT 1', ['%' . $t . '%']);
}

/**
 * Ringkasan keuangan satu rentang tanggal.
 * Pemasukan sewa diambil dari pembayaran yang sudah diverifikasi.
 */
function kas_ringkasan(string $dari, string $sampai): array
{
    $sewa = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran
                            WHERE status = "diterima" AND tgl_bayar BETWEEN ? AND ?', [$dari, $sampai], 0);
    $lain = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM kas
                            WHERE jenis = "masuk" AND tanggal BETWEEN ? AND ?', [$dari, $sampai], 0);
    $keluar = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM kas
                              WHERE jenis = "keluar" AND tanggal BETWEEN ? AND ?', [$dari, $sampai], 0);

    return [
        'sewa'         => $sewa,
        'lain'         => $lain,
        'masuk_total'  => $sewa + $lain,
        'keluar'       => $keluar,
        'saldo'        => $sewa + $lain - $keluar,
        'per_kategori' => all('SELECT kategori, jenis, SUM(jumlah) total, COUNT(*) jml FROM kas
                               WHERE tanggal BETWEEN ? AND ?
                               GROUP BY jenis, kategori ORDER BY jenis, total DESC', [$dari, $sampai]),
    ];
}

/** Daftar transaksi kas pada rentang tanggal. */
function kas_daftar(string $dari, string $sampai, string $jenis = ''): array
{
    $w = ['k.tanggal BETWEEN ? AND ?'];
    $p = [$dari, $sampai];
    if ($jenis !== '') { $w[] = 'k.jenis = ?'; $p[] = $jenis; }

    return all('SELECT k.*, ks.nama AS kost, u.nama AS pencatat
                FROM kas k
                LEFT JOIN kost ks ON ks.id = k.kost_id
                LEFT JOIN users u ON u.id = k.user_id
                WHERE ' . implode(' AND ', $w) . '
                ORDER BY k.tanggal ASC, k.id ASC', $p);
}

/**
 * Ringkasan keuangan dalam bentuk teks WhatsApp.
 * Dipakai bot saat admin mengetik "LAPORAN".
 */
function kas_teks_laporan(string $dari, string $sampai, string $judul): string
{
    $r = kas_ringkasan($dari, $sampai);
    $piutang = (float) scalar('SELECT COALESCE(SUM(jumlah + denda),0) FROM tagihan
                               WHERE status IN ("belum_bayar","terlambat")', [], 0);
    $huni  = (int) scalar('SELECT COUNT(*) FROM penghuni WHERE status = "aktif"', [], 0);
    $kamar = (int) scalar('SELECT COUNT(*) FROM kamar', [], 0);

    $msg = "📊 *LAPORAN KEUANGAN*\n_" . $judul . "_\n\n"
         . "*PEMASUKAN*\n"
         . "• Sewa kamar : " . rp($r['sewa']) . "\n"
         . "• Lain-lain  : " . rp($r['lain']) . "\n"
         . "• Total      : *" . rp($r['masuk_total']) . "*\n\n"
         . "*PENGELUARAN*\n";

    $adaKeluar = false;
    foreach ($r['per_kategori'] as $k) {
        if ($k['jenis'] !== 'keluar') continue;
        $adaKeluar = true;
        $msg .= "• " . ucfirst(str_replace('_', ' ', $k['kategori'])) . " : " . rp($k['total']) . "\n";
    }
    if (!$adaKeluar) $msg .= "• (belum ada)\n";
    $msg .= "• Total      : *" . rp($r['keluar']) . "*\n\n"
          . "💰 *SALDO : " . rp($r['saldo']) . "*\n\n"
          . "Piutang belum tertagih: " . rp($piutang) . "\n"
          . "Hunian: $huni dari $kamar kamar\n\n"
          . "Rincian lengkap: " . BASE_URL . "admin/laporan.php";

    return $msg;
}

/**
 * Terjemahkan kata periode jadi rentang tanggal.
 *   ""            -> bulan berjalan
 *   "2026-07"     -> Juli 2026
 *   "juli"        -> Juli tahun berjalan
 *   "kemarin"     -> bulan lalu
 *   "hari ini"    -> hari ini
 * @return array{0:string,1:string,2:string} [dari, sampai, judul]
 */
function kas_periode(string $teks): array
{
    $t = trim(mb_strtolower($teks));

    if ($t === 'hari ini' || $t === 'harian' || $t === 'hariini') {
        return [date('Y-m-d'), date('Y-m-d'), 'Hari ini, ' . tgl(date('Y-m-d'))];
    }
    if ($t === 'kemarin' || $t === 'bulan lalu' || $t === 'lalu') {
        $p = date('Y-m', strtotime('first day of last month'));
        return [$p . '-01', date('Y-m-t', strtotime($p . '-01')), periode_label($p)];
    }
    if ($t === 'tahun ini' || $t === 'tahunan') {
        return [date('Y') . '-01-01', date('Y') . '-12-31', 'Tahun ' . date('Y')];
    }
    if (periode_valid($t)) {
        return [$t . '-01', date('Y-m-t', (int) strtotime($t . '-01')), periode_label($t)];
    }

    $bulan = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
              'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12];
    foreach ($bulan as $nama => $no) {
        if ($t !== '' && str_starts_with($nama, $t)) {
            $p = date('Y') . '-' . str_pad((string) $no, 2, '0', STR_PAD_LEFT);
            return [$p . '-01', date('Y-m-t', strtotime($p . '-01')), periode_label($p)];
        }
    }

    $p = date('Y-m');
    return [$p . '-01', date('Y-m-t'), periode_label($p)];
}
