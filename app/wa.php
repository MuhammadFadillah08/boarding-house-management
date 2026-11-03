<?php
/**
 * =====================================================================
 *  GATEWAY WHATSAPP  (outbound + template pesan + trigger event)
 * =====================================================================
 *  Driver didukung (atur di Admin > Pengaturan):
 *    - log     : tidak mengirim ke mana pun, hanya dicatat di tabel wa_log.
 *                Dipakai saat development di XAMPP. TIDAK butuh internet.
 *    - fonnte  : POST https://api.fonnte.com/send   (header: Authorization: TOKEN)
 *    - wablas  : POST https://xxx.wablas.com/api/send-message
 *    - cloud   : WhatsApp Cloud API resmi Meta (graph.facebook.com)
 *
 *  Semua pesan dicatat di tabel `wa_log`. Jika pengiriman gagal (mis. host
 *  memblokir koneksi keluar), pesan disimpan berstatus 'antre' dan bisa
 *  dikirim ulang oleh cron/reminder.php atau tombol di Admin > Log WA.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/**
 * Catat + kirim satu pesan WhatsApp.
 *
 * @param string $tujuan  nomor HP (bebas format, otomatis dinormalisasi)
 * @param string $pesan   isi pesan (mendukung \n)
 * @param string $jenis   label event untuk pelacakan
 * @param int|null $refId id referensi (booking/tagihan/pembayaran)
 */
function wa_send(string $tujuan, string $pesan, string $jenis = 'umum', ?int $refId = null, ?string $nama = null): bool
{
    $tujuan = normalize_hp($tujuan);
    if (strlen($tujuan) < 9) {
        return false;
    }
    $pesan .= setting('wa_footer');

    $logId = insert('wa_log', [
        'tujuan' => $tujuan,
        'nama'   => $nama,
        'jenis'  => $jenis,
        'pesan'  => $pesan,
        'arah'   => 'keluar',
        'status' => 'antre',
        'ref_id' => $refId,
    ]);

    return wa_dispatch($logId, $tujuan, $pesan);
}

/** Kirim pesan yang sudah tercatat di wa_log lalu perbarui statusnya. */
function wa_dispatch(int $logId, string $tujuan, string $pesan): bool
{
    $driver = setting('wa_driver', 'log');

    if ($driver === 'log') {
        update('wa_log', ['status' => 'terkirim', 'response' => 'MODE LOG (tidak dikirim ke WhatsApp)'], 'id = ?', [$logId]);
        return true;
    }

    [$ok, $resp] = wa_transport($driver, $tujuan, $pesan);
    update('wa_log', [
        'status'   => $ok ? 'terkirim' : 'gagal',
        'response' => mb_substr($resp, 0, 1000),
    ], 'id = ?', [$logId]);

    return $ok;
}

/**
 * Lapisan transport per-driver.
 * @return array{0:bool,1:string}
 */
function wa_transport(string $driver, string $tujuan, string $pesan): array
{
    $token    = setting('wa_token');
    $endpoint = setting('wa_endpoint');
    if ($token === '') {
        return [false, 'Token WA belum diisi di Pengaturan.'];
    }

    $headers = ['Accept: application/json'];
    $body    = [];

    switch ($driver) {
        case 'fonnte':
            $endpoint  = $endpoint ?: 'https://api.fonnte.com/send';
            $headers[] = 'Authorization: ' . $token;
            $body      = ['target' => $tujuan, 'message' => $pesan, 'countryCode' => '62'];
            break;

        case 'wablas':
            $endpoint  = $endpoint ?: 'https://console.wablas.com/api/send-message';
            $headers[] = 'Authorization: ' . $token;
            $body      = ['phone' => $tujuan, 'message' => $pesan];
            break;

        case 'cloud': // WhatsApp Cloud API (Meta) - endpoint harus berisi phone-number-id
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'Content-Type: application/json';
            $body      = json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $tujuan,
                'type'              => 'text',
                'text'              => ['preview_url' => false, 'body' => $pesan],
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            return [false, 'Driver WA tidak dikenal: ' . $driver];
    }

    [$httpOk, $resp] = http_post($endpoint, $body, $headers);
    return [wa_transport_ok($driver, $httpOk, $resp), $resp];
}

/**
 * Fonnte dan Wablas SELALU membalas HTTP 200 walau pesan gagal terkirim —
 * kegagalan sesungguhnya ditandai lewat field "status":false di badan JSON
 * (mis. saat perangkat WA sedang disconnected). Tanpa pengecekan ini, pesan
 * yang gagal akan tercatat "terkirim" di Log WA dan admin tidak sadar
 * notifikasi sebenarnya tidak pernah sampai.
 */
function wa_transport_ok(string $driver, bool $httpOk, string $resp): bool
{
    if (!$httpOk) return false;

    // http_post() mengembalikan teks "HTTP 200 | {...json...}"
    $json = preg_replace('/^HTTP \d+ \| /', '', $resp, 1);
    $data = json_decode((string) $json, true);
    if (!is_array($data)) return $httpOk; // bukan JSON -> percaya kode HTTP saja

    if (in_array($driver, ['fonnte', 'wablas'], true) && array_key_exists('status', $data)) {
        return $data['status'] !== false;
    }
    if ($driver === 'cloud' && isset($data['error'])) {
        return false;
    }
    return $httpOk;
}

/**
 * POST HTTP sederhana. Pakai cURL; kalau ekstensi cURL tidak ada
 * (beberapa shared hosting) jatuh ke stream context.
 *
 * @param array|string $body
 * @return array{0:bool,1:string}
 */
function http_post(string $url, $body, array $headers = [], int $timeout = 20): array
{
    $isJson  = is_string($body);
    $payload = $isJson ? $body : http_build_query($body);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // XAMPP lokal sering tanpa CA bundle
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            return [false, 'cURL error: ' . $err];
        }
        return [$code >= 200 && $code < 300, "HTTP $code | " . (string) $res];
    }

    // Fallback tanpa cURL
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", array_merge(
            [$isJson ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded'],
            $headers
        )),
        'content'       => $payload,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return [false, 'Koneksi keluar diblokir/gagal (file_get_contents).'];
    }
    return [true, (string) $res];
}

/** Kirim ulang semua pesan berstatus antre/gagal. Dipanggil cron & tombol admin. */
function wa_flush_queue(int $limit = 30): int
{
    $sent = 0;
    foreach (all("SELECT id, tujuan, pesan FROM wa_log
                  WHERE arah='keluar' AND status IN ('antre','gagal')
                  ORDER BY id ASC LIMIT $limit") as $row) {
        if (wa_dispatch((int) $row['id'], $row['tujuan'], $row['pesan'])) {
            $sent++;
        }
    }
    return $sent;
}

// =====================================================================
//  TEMPLATE + TRIGGER EVENT
//  Panggil fungsi notify_* dari controller, bukan wa_send() langsung.
// =====================================================================

function wa_admin_number(): string
{
    return setting('wa_admin');
}

function tt(string $label, string $value): string
{
    return "*$label:* $value\n";
}

/** [ADMIN] Ada booking baru masuk. */
function notify_booking_masuk(int $bookingId): void
{
    $b = one('SELECT b.*, u.nama, u.no_hp, k.kode AS kamar, k.tipe, k.harga, ks.nama AS kost
              FROM booking b
              JOIN users u ON u.id = b.user_id
              JOIN kamar k ON k.id = b.kamar_id
              JOIN kost ks ON ks.id = k.kost_id
              WHERE b.id = ?', [$bookingId]);
    if (!$b) return;

    $msg = "🔔 *BOOKING BARU MASUK*\n\n"
         . tt('Kode', $b['kode'])
         . tt('Nama', $b['nama'])
         . tt('No. HP', $b['no_hp'])
         . tt('Kost', $b['kost'])
         . tt('Kamar', $b['kamar'] . ' (' . $b['tipe'] . ')')
         . tt('Masuk', tgl($b['tgl_masuk']))
         . tt('Durasi', $b['durasi'] . ' bulan')
         . tt('Harga', rp($b['harga']) . '/bulan')
         . ($b['catatan'] ? tt('Catatan', $b['catatan']) : '')
         . "\nSegera verifikasi di dashboard admin.";

    wa_send(wa_admin_number(), $msg, 'booking_masuk', $bookingId, 'Admin');

    // Balasan otomatis ke penyewa
    $msgU = "Halo *{$b['nama']}* 👋\n\nPengajuan sewa Anda sudah kami terima.\n\n"
          . tt('Kode Booking', $b['kode'])
          . tt('Kost', $b['kost'])
          . tt('Kamar', $b['kamar'] . ' (' . $b['tipe'] . ')')
          . tt('Rencana masuk', tgl($b['tgl_masuk']))
          . "\nStatus saat ini: *Menunggu konfirmasi pengelola*. "
          . "Kami akan mengabari Anda segera setelah diverifikasi.";
    wa_send($b['no_hp'], $msgU, 'booking_diterima', $bookingId, $b['nama']);
}

/** [USER] Booking disetujui / ditolak. */
function notify_booking_status(int $bookingId): void
{
    $b = one('SELECT b.*, u.nama, u.no_hp, k.kode AS kamar, k.tipe, k.harga,
                     ks.nama AS kost, ks.alamat AS kost_alamat
              FROM booking b
              JOIN users u ON u.id = b.user_id
              JOIN kamar k ON k.id = b.kamar_id
              JOIN kost ks ON ks.id = k.kost_id
              WHERE b.id = ?', [$bookingId]);
    if (!$b) return;

    if ($b['status'] === 'disetujui') {
        $msg = "✅ *BOOKING DISETUJUI*\n\nSelamat *{$b['nama']}*, pengajuan sewa Anda disetujui!\n\n"
             . tt('Kode', $b['kode'])
             . tt('Kost', $b['kost'])
             . tt('Kamar', $b['kamar'] . ' (' . $b['tipe'] . ')')
             . tt('Tanggal masuk', tgl($b['tgl_masuk']))
             . tt('Sewa', rp($b['harga']) . '/bulan')
             . "\nTagihan pertama akan muncul di menu *Tagihan* pada akun Anda.\n"
             . "Alamat: " . $b['kost_alamat'] . "\n\nSelamat datang di " . setting('nama_kost') . " 🏡";
    } else {
        $msg = "❌ *BOOKING BELUM DAPAT DIPROSES*\n\nHalo *{$b['nama']}*, mohon maaf pengajuan Anda untuk kamar "
             . "*{$b['kamar']}* belum dapat kami proses.\n\n"
             . ($b['alasan_tolak'] ? tt('Alasan', $b['alasan_tolak']) : '')
             . "\nAnda dapat memilih kamar lain yang masih tersedia di website kami.";
    }
    wa_send($b['no_hp'], $msg, 'booking_status', $bookingId, $b['nama']);
}

/** [USER] Tagihan baru diterbitkan. */
function notify_tagihan_baru(int $tagihanId): void
{
    $t = tagihan_detail($tagihanId);
    if (!$t) return;

    $msg = "🧾 *TAGIHAN BARU*\n\nHalo *{$t['nama']}*, tagihan sewa kamar Anda telah terbit.\n\n"
         . tt('No. Tagihan', $t['kode'])
         . tt('Kamar', $t['kost'] . ' — ' . $t['kamar'])
         . tt('Periode', periode_label($t['periode']))
         . tt('Jumlah', rp($t['jumlah']))
         . tt('Jatuh tempo', tgl($t['jatuh_tempo']))
         . "\n" . info_rekening()
         . "\nUnggah bukti transfer melalui menu *Tagihan* di website agar segera kami verifikasi.";

    wa_send($t['no_hp'], $msg, 'tagihan_baru', $tagihanId, $t['nama']);
}

/** [USER] Pengingat H-n / lewat jatuh tempo. */
function notify_reminder(int $tagihanId, int $selisihHari): void
{
    $t = tagihan_detail($tagihanId);
    if (!$t) return;

    if ($selisihHari > 0) {
        $judul = "⏰ *PENGINGAT TAGIHAN*";
        $isi   = "Tagihan sewa Anda akan jatuh tempo dalam *$selisihHari hari* lagi.";
    } elseif ($selisihHari === 0) {
        $judul = "⏰ *JATUH TEMPO HARI INI*";
        $isi   = "Tagihan sewa Anda jatuh tempo *hari ini*.";
    } else {
        $lewat = abs($selisihHari);
        $judul = "⚠️ *TAGIHAN TERLAMBAT*";
        $isi   = "Tagihan sewa Anda sudah lewat jatuh tempo *$lewat hari*."
               . ((float) $t['denda'] > 0 ? " Denda berjalan: *" . rp($t['denda']) . "*." : '');
    }

    $total = (float) $t['jumlah'] + (float) $t['denda'];
    $msg = "$judul\n\nHalo *{$t['nama']}*, $isi\n\n"
         . tt('No. Tagihan', $t['kode'])
         . tt('Kamar', $t['kost'] . ' — ' . $t['kamar'])
         . tt('Periode', periode_label($t['periode']))
         . tt('Jatuh tempo', tgl($t['jatuh_tempo']))
         . tt('Total bayar', rp($total))
         . "\n" . info_rekening()
         . "\nAbaikan pesan ini jika Anda sudah membayar dan menunggu verifikasi.";

    wa_send($t['no_hp'], $msg, 'reminder', $tagihanId, $t['nama']);
    update('tagihan', ['reminder_at' => date('Y-m-d')], 'id = ?', [$tagihanId]);
}

/** [ADMIN] Ada pembayaran/bukti transfer baru diunggah. */
function notify_pembayaran_masuk(int $bayarId): void
{
    $p = one('SELECT p.*, u.nama, u.no_hp, t.kode AS kode_tagihan, t.periode,
                     k.kode AS kamar, ks.nama AS kost
              FROM pembayaran p
              JOIN users u    ON u.id = p.user_id
              JOIN tagihan t  ON t.id = p.tagihan_id
              JOIN penghuni h ON h.id = t.penghuni_id
              JOIN kamar k    ON k.id = h.kamar_id
              JOIN kost  ks   ON ks.id = k.kost_id
              WHERE p.id = ?', [$bayarId]);
    if (!$p) return;

    $msg = "💰 *PEMBAYARAN BARU MASUK*\n\n"
         . tt('Dari', $p['nama'] . ' (' . $p['kost'] . ' ' . $p['kamar'] . ')')
         . tt('No. Tagihan', $p['kode_tagihan'])
         . tt('Periode', periode_label($p['periode']))
         . tt('Nominal', rp($p['jumlah']))
         . tt('Metode', ucfirst($p['metode']))
         . tt('Tgl bayar', tgl($p['tgl_bayar']))
         . ($p['bukti'] ? "\nBukti transfer: " . UPLOAD_URL . $p['bukti'] . "\n" : "\n")
         . "\nMohon segera diverifikasi di dashboard admin.";

    wa_send(wa_admin_number(), $msg, 'bayar_masuk', $bayarId, 'Admin');
}

/** [USER] Pembayaran diverifikasi -> bukti lunas / ditolak. */
function notify_pembayaran_verif(int $bayarId): void
{
    $p = one('SELECT p.*, u.nama, u.no_hp, t.kode AS kode_tagihan, t.periode, t.id AS tid,
                     k.kode AS kamar, ks.nama AS kost
              FROM pembayaran p
              JOIN users u    ON u.id = p.user_id
              JOIN tagihan t  ON t.id = p.tagihan_id
              JOIN penghuni h ON h.id = t.penghuni_id
              JOIN kamar k    ON k.id = h.kamar_id
              JOIN kost  ks   ON ks.id = k.kost_id
              WHERE p.id = ?', [$bayarId]);
    if (!$p) return;

    if ($p['status'] === 'diterima') {
        $msg = "✅ *PEMBAYARAN LUNAS*\n\nTerima kasih *{$p['nama']}*, pembayaran Anda sudah kami terima dan diverifikasi.\n\n"
             . tt('No. Tagihan', $p['kode_tagihan'])
             . tt('Kamar', $p['kost'] . ' — ' . $p['kamar'])
             . tt('Periode', periode_label($p['periode']))
             . tt('Dibayar', rp($p['jumlah']))
             . tt('Diverifikasi', tgl(date('Y-m-d H:i:s'), true))
             . tt('Status', 'LUNAS ✔')
             . "\nKuitansi resmi dapat diunduh/dicetak melalui menu *Tagihan* di website.\n"
             . "Simpan pesan ini sebagai bukti pembayaran Anda.";
    } else {
        $msg = "⚠️ *BUKTI PEMBAYARAN DITOLAK*\n\nHalo *{$p['nama']}*, bukti pembayaran untuk tagihan "
             . "*{$p['kode_tagihan']}* belum dapat kami terima.\n\n"
             . ($p['catatan'] ? tt('Catatan pengelola', $p['catatan']) : '')
             . "\nSilakan unggah ulang bukti transfer yang benar melalui website. Terima kasih.";
    }
    wa_send($p['no_hp'], $msg, 'lunas', $bayarId, $p['nama']);
}

/** Data tagihan + penghuni + user + kamar (dipakai banyak template). */
function tagihan_detail(int $id): ?array
{
    return one('SELECT t.*, u.nama, u.no_hp, u.email, k.kode AS kamar, k.tipe,
                       ks.nama AS kost, ks.alamat AS kost_alamat, h.tgl_masuk
               FROM tagihan t
               JOIN penghuni h ON h.id = t.penghuni_id
               JOIN users u    ON u.id = h.user_id
               JOIN kamar k    ON k.id = h.kamar_id
               JOIN kost  ks   ON ks.id = k.kost_id
               WHERE t.id = ?', [$id]);
}

function info_rekening(): string
{
    return "Pembayaran dapat ditransfer ke:\n"
         . "*" . setting('bank_nama') . "* " . setting('bank_rek') . "\n"
         . "a.n. " . setting('bank_an') . "\n";
}
