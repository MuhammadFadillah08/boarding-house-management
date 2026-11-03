<?php
/**
 * =====================================================================
 *  JEMBATAN KE GOOGLE SPREADSHEET  (lewat Apps Script Web App)
 * =====================================================================
 *  InfinityFree memblokir request MASUK dari server luar (proteksi anti-bot
 *  berupa tantangan JavaScript), tapi request KELUAR lancar. Karena itu:
 *
 *    - Bot WhatsApp menulis transaksinya ke Spreadsheet (lewat Apps Script).
 *    - Website ini SELALU jadi pemanggil: menarik baris baru dari Spreadsheet
 *      ke MySQL, dan mendorong balik transaksi yang diinput lewat web.
 *
 *  ── Mengapa MySQL tetap jadi sumber utama ─────────────────────────────
 *  Setiap panggilan ke Apps Script butuh 1–4 detik. Kalau halaman Buku Kas
 *  dan Laporan membaca langsung ke sana, seluruh panel admin jadi lambat dan
 *  ikut mati setiap kali Apps Script lambat atau kuotanya habis. Jadi MySQL
 *  tetap dibaca untuk tampilan (cepat, selalu tersedia), sementara
 *  Spreadsheet berperan sebagai cermin yang selalu disinkronkan — sekaligus
 *  menjadi "laporan Spreadsheet" pengganti ekspor Excel.
 *
 *  Pemisahan kode transaksi:
 *    KM-/KK-  dibuat website (MySQL)
 *    WM-/WK-  dibuat bot WhatsApp (Spreadsheet)
 *  Seri dipisah supaya nomornya tidak pernah bertabrakan saat disinkronkan.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/** Jeda minimum antar penarikan otomatis (detik). */
const SHEETS_PULL_JEDA = 90;

/** Integrasi hidup hanya bila URL Web App dan kunci API sudah diisi. */
function sheets_aktif(): bool
{
    return setting('sheets_aktif', '0') === '1'
        && setting('sheets_url') !== ''
        && setting('sheets_key') !== '';
}

/**
 * Panggil satu action di Apps Script Web App.
 *
 * Catatan teknis: URL /exec selalu membalas 302 ke script.googleusercontent.com.
 * cURL dengan CURLOPT_FOLLOWLOCATION menangani ini dengan benar (POST dulu,
 * lalu GET ke lokasi baru untuk mengambil hasilnya) — jangan menambahkan
 * CURLOPT_CUSTOMREQUEST, karena memaksa POST saat redirect membuat Google
 * membalas galat 411/404.
 *
 * @return array{ok:bool,data:mixed,error:string}
 */
function sheets_call(string $action, array $params = [], int $timeout = 25): array
{
    if (!sheets_aktif()) {
        return ['ok' => false, 'data' => null, 'error' => 'Integrasi Spreadsheet belum diaktifkan.'];
    }

    $params['action'] = $action;
    $params['key']    = setting('sheets_key');

    [$httpOk, $resp] = http_post(setting('sheets_url'), $params, ['Accept: application/json'], $timeout);

    // http_post() mengembalikan teks "HTTP 200 | {...}"
    $body = preg_replace('/^HTTP \d+ \| /', '', $resp, 1);
    $json = json_decode((string) $body, true);

    if (!is_array($json)) {
        return [
            'ok'    => false,
            'data'  => null,
            'error' => $httpOk
                ? 'Balasan Apps Script bukan JSON: ' . mb_substr((string) $body, 0, 200)
                : mb_substr($resp, 0, 200),
        ];
    }
    return [
        'ok'    => !empty($json['ok']),
        'data'  => $json['data'] ?? null,
        'error' => (string) ($json['error'] ?? ''),
    ];
}

/** URL Spreadsheet untuk dibuka admin di browser. */
function sheets_url_dokumen(): string
{
    $id = setting('sheets_id');
    return $id === '' ? '' : 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id) . '/edit';
}

// =====================================================================
//  DORONG (website -> Spreadsheet)
// =====================================================================

/**
 * Kirim satu baris kas hasil input web ke Spreadsheet.
 * Kegagalan tidak boleh menggagalkan penyimpanan di MySQL — cukup dicatat,
 * lalu diperbaiki oleh sheets_dorong_tertinggal() pada job harian.
 */
function sheets_kas_dorong(array $k): bool
{
    if (!sheets_aktif()) return false;

    $r = sheets_call('kas_add', [
        'kode'       => $k['kode'],
        'jenis'      => $k['jenis'],
        'jumlah'     => (string) $k['jumlah'],
        'keterangan' => (string) ($k['keterangan'] ?? ''),
        'kategori'   => (string) ($k['kategori'] ?? ''),
        'kost_id'    => (string) ($k['kost_id'] ?? ''),
        'kost'       => (string) ($k['kost'] ?? ''),
        'tanggal'    => (string) $k['tanggal'],
        'user_id'    => (string) ($k['user_id'] ?? ''),
        'pencatat'   => (string) ($k['pencatat'] ?? ''),
        'bukti'      => (string) ($k['bukti'] ?? ''),
    ]);

    if (!$r['ok']) {
        error_log('[sheets] gagal dorong ' . $k['kode'] . ': ' . $r['error']);
        return false;
    }
    update('kas', ['sinkron' => 1], 'kode = ?', [$k['kode']]);
    return true;
}

/** Hapus satu transaksi di Spreadsheet berdasarkan kode. */
function sheets_kas_hapus(string $kode): bool
{
    if (!sheets_aktif() || $kode === '') return false;
    $r = sheets_call('kas_delete', ['kode' => $kode]);
    if (!$r['ok']) error_log('[sheets] gagal hapus ' . $kode . ': ' . $r['error']);
    return $r['ok'];
}

/**
 * Coba ulang semua baris yang belum tercermin di Spreadsheet.
 * Aman diulang: Apps Script menimpa baris berkode sama, tidak menambah kembar.
 */
function sheets_dorong_tertinggal(int $limit = 20): int
{
    if (!sheets_aktif()) return 0;

    $rows = all("SELECT k.*, ks.nama AS kost, u.nama AS pencatat
                 FROM kas k
                 LEFT JOIN kost ks ON ks.id = k.kost_id
                 LEFT JOIN users u ON u.id = k.user_id
                 WHERE k.sinkron = 0
                 ORDER BY k.id ASC LIMIT " . max(1, $limit));

    $n = 0;
    foreach ($rows as $k) {
        if (sheets_kas_dorong($k)) $n++;
    }
    return $n;
}

/**
 * Dorong data pendukung yang hanya ada di MySQL:
 *  - daftar cabang    -> bot bisa mengenali "branch 3"
 *  - daftar admin     -> bot tahu nomor mana yang berhak
 *  - pengaturan teks  -> bot memakai nama kost, footer, info rekening
 *  - ringkasan bulan  -> bot bisa menampilkan sewa/piutang/hunian di LAPORAN
 */
function sheets_sync_meta(?string $periode = null): array
{
    if (!sheets_aktif()) return ['ok' => false, 'error' => 'Integrasi belum aktif.'];

    $periode = periode_atau_kini($periode);
    $dari    = $periode . '-01';
    $sampai  = date('Y-m-t', (int) strtotime($dari));

    $kost = array_map(
        fn($r) => ['id' => (int) $r['id'], 'nama' => $r['nama']],
        all('SELECT id, nama FROM kost ORDER BY urutan, id')
    );

    $adminList = array_map(
        fn($r) => ['no_hp' => $r['no_hp'], 'nama' => $r['nama'], 'user_id' => (int) $r['id']],
        all('SELECT id, nama, no_hp FROM users WHERE role = "admin" AND status = "aktif" ORDER BY id')
    );

    $config = [];
    foreach (['nama_kost', 'wa_footer', 'bank_nama', 'bank_rek', 'bank_an'] as $k) {
        $config[] = ['k' => $k, 'v' => setting($k)];
    }
    $config[] = ['k' => 'base_url', 'v' => BASE_URL];

    $sewa = (float) scalar('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran
                            WHERE status = "diterima" AND tgl_bayar BETWEEN ? AND ?', [$dari, $sampai], 0);
    $piutang = (float) scalar('SELECT COALESCE(SUM(jumlah + denda),0) FROM tagihan
                               WHERE status IN ("belum_bayar","terlambat")', [], 0);

    $ringkasan = [
        'periode' => $periode,
        'sewa'    => $sewa,
        'piutang' => $piutang,
        'huni'    => (int) scalar('SELECT COUNT(*) FROM penghuni WHERE status = "aktif"', [], 0),
        'kamar'   => (int) scalar('SELECT COUNT(*) FROM kamar', [], 0),
    ];

    $r = sheets_call('sync_meta', [
        'kost'      => json_encode($kost, JSON_UNESCAPED_UNICODE),
        'admin'     => json_encode($adminList, JSON_UNESCAPED_UNICODE),
        'config'    => json_encode($config, JSON_UNESCAPED_UNICODE),
        'ringkasan' => json_encode($ringkasan, JSON_UNESCAPED_UNICODE),
    ], 40);

    if ($r['ok']) set_setting('sheets_last_sync', date('Y-m-d H:i:s'));
    return $r;
}

// =====================================================================
//  TARIK (Spreadsheet -> website)
// =====================================================================

/**
 * Ambil transaksi hasil chat WhatsApp (kode WM-/WK-) yang belum ada di MySQL.
 * Hanya baris bersumber 'wa' yang ditarik; baris 'web' memang berasal dari sini.
 *
 * @return int jumlah baris baru
 */
function sheets_kas_tarik(int $hariKeBelakang = 120): int
{
    if (!sheets_aktif()) return 0;

    $r = sheets_call('kas_list', [
        'dari'   => date('Y-m-d', strtotime('-' . max(1, $hariKeBelakang) . ' days')),
        'sampai' => date('Y-m-d', strtotime('+2 days')), // toleransi selisih zona waktu
    ]);
    if (!$r['ok'] || !is_array($r['data'])) {
        if (!$r['ok']) error_log('[sheets] gagal tarik: ' . $r['error']);
        return 0;
    }

    $n = 0;
    foreach ($r['data'] as $row) {
        if (!is_array($row)) continue;
        if (($row['sumber'] ?? '') !== 'wa') continue;

        $kode = trim((string) ($row['kode'] ?? ''));
        if ($kode === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['tanggal'] ?? ''))) continue;
        if (scalar('SELECT id FROM kas WHERE kode = ?', [$kode])) continue;   // sudah ada

        // Relasi divalidasi dulu: id yang tidak ada akan ditolak foreign key.
        $kostId = (int) ($row['kost_id'] ?? 0);
        if ($kostId && !scalar('SELECT id FROM kost WHERE id = ?', [$kostId]))   $kostId = 0;
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId && !scalar('SELECT id FROM users WHERE id = ?', [$userId])) $userId = 0;

        $jumlah = (float) ($row['jumlah'] ?? 0);
        if ($jumlah <= 0) continue;

        try {
            insert('kas', [
                'kode'       => $kode,
                'kost_id'    => $kostId ?: null,
                'jenis'      => ($row['jenis'] ?? '') === 'masuk' ? 'masuk' : 'keluar',
                'kategori'   => mb_substr((string) ($row['kategori'] ?? 'lainnya'), 0, 40),
                'jumlah'     => $jumlah,
                'keterangan' => mb_substr((string) ($row['keterangan'] ?? ''), 0, 255),
                'tanggal'    => (string) $row['tanggal'],
                'sumber'     => 'wa',
                'user_id'    => $userId ?: null,
                'sinkron'    => 1,
            ]);
            $n++;
        } catch (Throwable $e) {
            error_log('[sheets] gagal simpan ' . $kode . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/**
 * Tarik bila jeda terakhir sudah terlampaui. Dipanggil dari halaman admin
 * yang menampilkan data kas, supaya transaksi dari WhatsApp muncul tanpa
 * admin perlu menekan tombol apa pun.
 */
function sheets_maybe_tarik(): void
{
    if (!sheets_aktif()) return;

    $last = setting('sheets_last_pull');
    if ($last !== '' && (time() - (int) strtotime($last)) < SHEETS_PULL_JEDA) {
        return;
    }
    set_setting('sheets_last_pull', date('Y-m-d H:i:s'));   // kunci sederhana

    try {
        sheets_kas_tarik();
    } catch (Throwable $e) {
        error_log('[sheets] tarik otomatis gagal: ' . $e->getMessage());
    }
}
