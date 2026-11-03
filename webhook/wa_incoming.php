<?php
/**
 * =====================================================================
 *  WEBHOOK PESAN MASUK WHATSAPP  (bot dua arah)
 * =====================================================================
 *  Daftarkan URL ini di dashboard gateway WA Anda:
 *      https://domain-anda/boarding-house-management/webhook/wa_incoming.php?key=CRON_KEY
 *
 *  ── Perintah PENYEWA ────────────────────────────────────────────────
 *      TAGIHAN    rincian tagihan miliknya
 *      KAMAR      daftar kamar yang masih tersedia
 *      REKENING   nomor rekening pembayaran
 *      BANTUAN    daftar perintah
 *
 *  ── Perintah ADMIN (hanya nomor ber-role admin) ─────────────────────
 *      KELUAR <nominal> <keterangan>   catat pengeluaran kost
 *      MASUK  <nominal> <keterangan>   catat pemasukan di luar sewa
 *      LAPORAN [periode]               ringkasan pemasukan/pengeluaran/saldo
 *      KAS [periode]                   daftar transaksi kas terakhir
 *      BATAL                           hapus transaksi terakhir yang dicatat
 *      TAGIHAN                         daftar tunggakan seluruh penyewa
 *
 *  Transaksi yang dicatat lewat chat LANGSUNG masuk ke tabel `kas`,
 *  sehingga otomatis muncul di halaman Buku Kas, Laporan, dan berkas Excel
 *  tanpa admin perlu mengetik ulang di website.
 *
 *  ⚠️ CATATAN INFINITYFREE
 *  Hosting gratis InfinityFree memasang proteksi anti-bot yang sering
 *  MENOLAK request POST dari server luar (gateway) dengan halaman
 *  JS-challenge. Jika bot balasan tidak berfungsi di sana, notifikasi
 *  keluar (reminder, laporan ke admin) tetap berjalan normal — hanya
 *  fitur balas-otomatis ini yang butuh hosting berbayar / VPS.
 * =====================================================================
 */
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ---- Keamanan -------------------------------------------------------
// Kunci kosong TIDAK boleh dianggap cocok: tanpa pemeriksaan ini, siapa pun
// bisa memanggil webhook dengan ?key= saat cron_key belum diisi.
$key   = (string) ($_GET['key'] ?? '');
$valid = setting('cron_key');
if ($valid === '' || !hash_equals($valid, $key)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'kunci tidak valid']));
}

// ---- Ambil payload (dukung form-encoded & JSON) ----------------------
$raw  = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
$in   = is_array($json) ? $json : $_POST;

/** Ambil nilai pertama yang ada dari beberapa kemungkinan nama field. */
function pick(array $a, array $keys, string $def = ''): string
{
    foreach ($keys as $k) {
        if (isset($a[$k]) && is_scalar($a[$k]) && trim((string) $a[$k]) !== '') {
            return trim((string) $a[$k]);
        }
    }
    return $def;
}

$pengirim = normalize_hp(pick($in, ['sender', 'phone', 'from', 'pengirim', 'number']));
$isi      = pick($in, ['message', 'text', 'pesan', 'body', 'msg']);

// Format WhatsApp Cloud API (Meta) bersarang
if ($pengirim === '' && isset($in['entry'][0]['changes'][0]['value']['messages'][0])) {
    $m        = $in['entry'][0]['changes'][0]['value']['messages'][0];
    $pengirim = normalize_hp((string) ($m['from'] ?? ''));
    $isi      = (string) ($m['text']['body'] ?? '');
}

if ($pengirim === '' || $isi === '') {
    exit(json_encode(['ok' => false, 'error' => 'payload tidak dikenali']));
}

// Catat pesan masuk
insert('wa_log', [
    'tujuan' => $pengirim, 'jenis' => 'pesan_masuk', 'pesan' => $isi,
    'arah' => 'masuk', 'status' => 'terkirim',
]);

// ---- Kenali pengirim -------------------------------------------------
$user    = one('SELECT * FROM users WHERE no_hp = ? AND status = "aktif" LIMIT 1', [$pengirim]);
$isAdmin = $user && $user['role'] === 'admin';

$isi   = trim($isi);
$parts = preg_split('/\s+/', $isi, 2);
$cmd   = mb_strtoupper($parts[0] ?? '');
$sisa  = trim($parts[1] ?? '');

$balas = '';

// =====================================================================
//  PERINTAH KHUSUS ADMIN
// =====================================================================
if (in_array($cmd, ['KELUAR', 'MASUK', 'LAPORAN', 'KAS', 'BATAL'], true)) {

    if (!$isAdmin) {
        // Jangan bocorkan keberadaan perintah admin ke penyewa biasa.
        $balas = "Maaf, perintah *$cmd* hanya dapat digunakan oleh pengelola kost.\n\n"
               . "Ketik *BANTUAN* untuk melihat perintah yang tersedia untuk Anda.";
        wa_send($pengirim, $balas, 'balasan_bot', null, $user['nama'] ?? null);
        exit(json_encode(['ok' => true, 'perintah' => $cmd, 'ditolak' => 'bukan admin']));
    }

    switch ($cmd) {

        // ---- Catat pengeluaran / pemasukan --------------------------
        case 'KELUAR':
        case 'MASUK':
            $jenis  = $cmd === 'MASUK' ? 'masuk' : 'keluar';
            $jumlah = parse_nominal($sisa);

            if ($jumlah === null || $jumlah <= 0) {
                $balas = "⚠️ Nominal tidak terbaca.\n\nFormat:\n*$cmd <nominal> <keterangan>*\n\n"
                       . "Contoh:\n• $cmd 150000 token listrik\n• $cmd 150rb token listrik\n• $cmd 1,5jt gaji penjaga";
                break;
            }

            // Buang bagian nominal dari teks, sisanya jadi keterangan.
            $ket = trim(preg_replace('/^\s*[\d][\d.,]*\s*(jt|juta|rb|ribu|k)?\s*/iu', '', $sisa) ?? '');
            if ($ket === '') $ket = ($jenis === 'masuk' ? 'Pemasukan' : 'Pengeluaran') . ' via WhatsApp';

            $kost   = kas_cari_kost($ket);
            $kodeId = kas_catat($jenis, $jumlah, $ket, [
                'kost_id' => $kost['id'] ?? null,
                'sumber'  => 'wa',
                'user_id' => $user['id'],
            ]);
            $baris = one('SELECT * FROM kas WHERE id = ?', [$kodeId]);

            [$d1, $d2, $lbl] = kas_periode('');
            $r = kas_ringkasan($d1, $d2);

            $balas = ($jenis === 'masuk' ? "✅ *PEMASUKAN DICATAT*" : "✅ *PENGELUARAN DICATAT*") . "\n\n"
                   . tt('Kode', $baris['kode'])
                   . tt('Nominal', rp($jumlah))
                   . tt('Keterangan', $ket)
                   . tt('Kategori', ucfirst(str_replace('_', ' ', $baris['kategori'])))
                   . tt('Cabang', $kost['nama'] ?? 'Umum')
                   . tt('Tanggal', tgl($baris['tanggal']))
                   . "\nSudah masuk ke laporan " . $lbl . ":\n"
                   . "• Pemasukan : " . rp($r['masuk_total']) . "\n"
                   . "• Pengeluaran : " . rp($r['keluar']) . "\n"
                   . "• *Saldo : " . rp($r['saldo']) . "*\n\n"
                   . "Salah catat? Balas *BATAL* untuk menghapusnya.";
            break;

        // ---- Hapus catatan terakhir ---------------------------------
        case 'BATAL':
            $last = one('SELECT * FROM kas WHERE sumber = "wa" AND user_id = ?
                         ORDER BY id DESC LIMIT 1', [$user['id']]);
            if (!$last) {
                $balas = "Tidak ada catatan kas dari WhatsApp yang bisa dibatalkan.";
                break;
            }
            q('DELETE FROM kas WHERE id = ?', [$last['id']]);
            $balas = "🗑️ *DIBATALKAN*\n\nTransaksi " . $last['kode'] . " ("
                   . ($last['jenis'] === 'masuk' ? 'pemasukan' : 'pengeluaran') . " "
                   . rp($last['jumlah']) . " — " . $last['keterangan'] . ") telah dihapus dari laporan.";
            break;

        // ---- Laporan keuangan ---------------------------------------
        case 'LAPORAN':
            [$d1, $d2, $lbl] = kas_periode($sisa);
            $balas = kas_teks_laporan($d1, $d2, $lbl);
            break;

        // ---- Daftar transaksi kas -----------------------------------
        case 'KAS':
            [$d1, $d2, $lbl] = kas_periode($sisa);
            $rows = kas_daftar($d1, $d2);
            if (!$rows) {
                $balas = "Belum ada transaksi kas pada periode *$lbl*.";
                break;
            }
            $balas = "📒 *BUKU KAS*\n_" . $lbl . "_\n";
            foreach (array_slice($rows, -15) as $r) {
                $balas .= "\n" . ($r['jenis'] === 'masuk' ? '🟢' : '🔴') . " " . tgl($r['tanggal'])
                        . " · " . rp($r['jumlah']) . "\n   " . $r['keterangan']
                        . " (" . $r['kategori'] . ")\n";
            }
            if (count($rows) > 15) {
                $balas .= "\n_Menampilkan 15 transaksi terakhir dari " . count($rows) . "._\n";
            }
            $ring = kas_ringkasan($d1, $d2);
            $balas .= "\n*Saldo periode ini: " . rp($ring['saldo']) . "*";
            break;
    }

// =====================================================================
//  PERINTAH UMUM
// =====================================================================
} else {
    switch ($cmd) {

        case 'TAGIHAN':
        case 'TAGIHANKU':
            // Admin -> rekap tunggakan seluruh penyewa
            if ($isAdmin) {
                $rows = all('SELECT t.*, u.nama, k.kode AS kamar, ks.nama AS kost FROM tagihan t
                             JOIN penghuni h ON h.id = t.penghuni_id
                             JOIN users u    ON u.id = h.user_id
                             JOIN kamar k    ON k.id = h.kamar_id
                             JOIN kost  ks   ON ks.id = k.kost_id
                             WHERE t.status IN ("belum_bayar","terlambat")
                             ORDER BY t.jatuh_tempo ASC LIMIT 15');
                if (!$rows) { $balas = "✅ Tidak ada tunggakan. Semua tagihan sudah lunas."; break; }
                $tot = 0;
                $balas = "📋 *DAFTAR TUNGGAKAN*\n";
                foreach ($rows as $r) {
                    $n = (float) $r['jumlah'] + (float) $r['denda'];
                    $tot += $n;
                    $balas .= "\n• *" . $r['nama'] . "* (" . $r['kost'] . " " . $r['kamar'] . ")\n"
                            . "  " . periode_label($r['periode']) . " · " . rp($n)
                            . " · tempo " . tgl($r['jatuh_tempo']) . "\n";
                }
                $balas .= "\n*Total: " . rp($tot) . "*";
                break;
            }
            // Penyewa -> tagihan miliknya
            if (!$user) {
                $balas = "Nomor Anda belum terdaftar di sistem *" . setting('nama_kost')
                       . "*.\nSilakan daftar melalui website atau hubungi pengelola.";
                break;
            }
            $rows = all('SELECT t.*, k.kode AS kamar, ks.nama AS kost FROM tagihan t
                         JOIN penghuni h ON h.id = t.penghuni_id
                         JOIN kamar k    ON k.id = h.kamar_id
                         JOIN kost  ks   ON ks.id = k.kost_id
                         WHERE h.user_id = ? AND t.status <> "lunas"
                         ORDER BY t.jatuh_tempo ASC LIMIT 5', [$user['id']]);
            if (!$rows) {
                $balas = "Halo *{$user['nama']}* 👋\n\n✅ Tidak ada tagihan tertunggak. Terima kasih sudah tertib membayar!";
                break;
            }
            $balas = "Halo *{$user['nama']}* 👋\n\nRincian tagihan Anda:\n";
            $tot = 0;
            foreach ($rows as $r) {
                $t = (float) $r['jumlah'] + (float) $r['denda'];
                $tot += $t;
                $balas .= "\n🧾 " . $r['kode'] . "\n  " . $r['kost'] . " kamar " . $r['kamar'] . " · " . periode_label($r['periode'])
                        . "\n  Jatuh tempo: " . tgl($r['jatuh_tempo'])
                        . "\n  Total: " . rp($t) . " (" . strtoupper(str_replace('_', ' ', $r['status'])) . ")\n";
            }
            $balas .= "\n*Total keseluruhan: " . rp($tot) . "*\n\n" . info_rekening()
                    . "\nUnggah bukti transfer di " . BASE_URL;
            break;

        case 'KAMAR':
        case 'KOSONG':
            $rows = all('SELECT m.*, ks.nama AS kost FROM kamar m
                         JOIN kost ks ON ks.id = m.kost_id
                         WHERE m.status = "tersedia" AND ks.status = "aktif"
                         ORDER BY ks.urutan, m.harga ASC LIMIT 12');
            if (!$rows) {
                $balas = "Mohon maaf, saat ini semua kamar di *" . setting('nama_kost') . "* sudah terisi. 🙏";
                break;
            }
            $balas = "🏡 *Kamar tersedia di " . setting('nama_kost') . "*\n";
            $kostSebelumnya = '';
            foreach ($rows as $r) {
                if ($r['kost'] !== $kostSebelumnya) {
                    $balas .= "\n📍 *" . $r['kost'] . "*\n";
                    $kostSebelumnya = $r['kost'];
                }
                $balas .= "▪️ " . $r['kode'] . " — " . $r['tipe'] . ", " . rp($r['harga']) . "/bulan\n";
            }
            $balas .= "\nAjukan booking di: " . BASE_URL;
            break;

        case 'REKENING':
        case 'BAYAR':
            $balas = "💳 *Informasi Pembayaran*\n\n" . info_rekening()
                   . "\nSetelah transfer, unggah bukti di " . BASE_URL . " agar segera diverifikasi.";
            break;

        default:
            $balas = "Halo! 👋 Saya asisten otomatis *" . setting('nama_kost') . "*.\n\n";
            if ($isAdmin) {
                $balas .= "*Perintah pengelola:*\n"
                        . "▪️ *KELUAR 150rb token listrik* — catat pengeluaran\n"
                        . "▪️ *MASUK 500rb deposit S1-03* — catat pemasukan\n"
                        . "▪️ *LAPORAN* — ringkasan bulan ini\n"
                        . "▪️ *LAPORAN JULI* — ringkasan bulan lain\n"
                        . "▪️ *KAS* — daftar transaksi kas\n"
                        . "▪️ *BATAL* — hapus catatan terakhir\n"
                        . "▪️ *TAGIHAN* — daftar tunggakan penyewa\n"
                        . "▪️ *KAMAR* — kamar yang masih kosong\n\n"
                        . "Nominal boleh ditulis 150000, 150.000, 150rb, atau 1,5jt.\n"
                        . "Semua catatan langsung masuk ke laporan di website.";
            } else {
                $balas .= "Ketik salah satu perintah berikut:\n"
                        . "▪️ *TAGIHAN* — cek tagihan Anda\n"
                        . "▪️ *KAMAR* — lihat kamar yang tersedia\n"
                        . "▪️ *REKENING* — info rekening pembayaran\n"
                        . "▪️ *BANTUAN* — tampilkan menu ini\n\n"
                        . "Untuk berbicara dengan pengelola, hubungi wa.me/" . setting('wa_admin');
            }
            break;
    }
}

$ok = wa_send($pengirim, $balas, 'balasan_bot', null, $user['nama'] ?? null);
echo json_encode([
    'ok'        => $ok,
    'perintah'  => $cmd,
    'peran'     => $isAdmin ? 'admin' : ($user ? 'penyewa' : 'tamu'),
], JSON_UNESCAPED_UNICODE);
