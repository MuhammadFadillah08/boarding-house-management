<?php
/**
 * Pekerjaan terjadwal: hitung denda, tandai terlambat, kirim pengingat WA.
 *
 * Dua cara menjalankan:
 *  1. cron/reminder.php?key=CRON_KEY  -> dipanggil cron-job.org (disarankan)
 *  2. maybe_run_daily() -> dipanggil otomatis saat ada pengunjung pertama
 *     tiap hari. Ini plan B untuk InfinityFree yang tidak punya cron job.
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

/**
 * Jalankan semua pekerjaan harian.
 * @return array ringkasan hasil
 */
function run_daily_jobs(): array
{
    $today       = date('Y-m-d');
    $dendaHarian = (float) setting('denda_per_hari', '0');
    $reminderH   = (int) setting('reminder_h', '3');
    $out         = ['denda' => 0, 'terlambat' => 0, 'reminder' => 0, 'queue' => 0];

    // 1. Tandai terlambat + hitung denda untuk tagihan yang lewat jatuh tempo
    $lewat = all("SELECT id, jatuh_tempo, status FROM tagihan
                  WHERE status IN ('belum_bayar','terlambat') AND jatuh_tempo < ?", [$today]);
    foreach ($lewat as $t) {
        $hari  = (int) ((strtotime($today) - strtotime($t['jatuh_tempo'])) / 86400);
        $denda = round($hari * $dendaHarian, 2);
        update('tagihan', ['status' => 'terlambat', 'denda' => $denda], 'id = ?', [$t['id']]);
        if ($t['status'] !== 'terlambat') $out['terlambat']++;
        if ($denda > 0) $out['denda']++;
    }

    // 2. Pengingat H-n, hari-H, dan tagihan terlambat (maks 1x/hari per tagihan)
    $target = all(
        "SELECT id, jatuh_tempo FROM tagihan
         WHERE status IN ('belum_bayar','terlambat')
           AND (reminder_at IS NULL OR reminder_at < ?)
           AND jatuh_tempo <= DATE_ADD(?, INTERVAL ? DAY)",
        [$today, $today, $reminderH]
    );
    foreach ($target as $t) {
        $selisih = (int) ((strtotime($t['jatuh_tempo']) - strtotime($today)) / 86400);
        // tagihan terlambat: ingatkan tiap 3 hari saja agar tidak spam
        if ($selisih < 0 && (abs($selisih) % 3) !== 0) continue;
        notify_reminder((int) $t['id'], $selisih);
        $out['reminder']++;
    }

    // 3. Coba kirim ulang pesan yang masih antre/gagal
    $out['queue'] = wa_flush_queue();

    // 4. Sinkronisasi Google Spreadsheet (bot WhatsApp).
    //    Dibungkus try/catch: layanan luar yang bermasalah tidak boleh
    //    menggagalkan penagihan dan pengingat yang sudah berjalan di atas.
    $out['tarik'] = $out['dorong'] = 0;
    if (sheets_aktif()) {
        try {
            $out['tarik']  = sheets_kas_tarik();
            $out['dorong'] = sheets_dorong_tertinggal();
            sheets_sync_meta();
        } catch (Throwable $e) {
            error_log('[daily-job] sinkron spreadsheet: ' . $e->getMessage());
        }
    }

    set_setting('last_daily_run', date('Y-m-d H:i:s'));
    return $out;
}

/** Jalankan sekali sehari saat ada pengunjung (pengganti cron di hosting gratis). */
function maybe_run_daily(): void
{
    $last = setting('last_daily_run');
    if ($last !== '' && substr($last, 0, 10) === date('Y-m-d')) {
        return; // sudah jalan hari ini
    }
    // Kunci sederhana agar tidak dobel saat banyak request bersamaan
    set_setting('last_daily_run', date('Y-m-d H:i:s'));
    try {
        run_daily_jobs();
    } catch (Throwable $e) {
        error_log('[daily-job] ' . $e->getMessage());
    }
}

/**
 * Terbitkan tagihan untuk satu penghuni pada satu periode (YYYY-MM).
 * Aman dipanggil berulang: melewati yang sudah ada.
 * @return int|null id tagihan baru
 */
function generate_tagihan(int $penghuniId, string $periode, bool $kirimWa = true): ?int
{
    // Periode wajib sah — "2026-13" akan membuat strtotime() gagal.
    if (periode_valid($periode) === null) return null;

    $p = one('SELECT h.*, k.harga FROM penghuni h JOIN kamar k ON k.id = h.kamar_id
              WHERE h.id = ? AND h.status = "aktif"', [$penghuniId]);
    if (!$p) return null;

    $ada = scalar('SELECT id FROM tagihan WHERE penghuni_id = ? AND periode = ?', [$penghuniId, $periode]);
    if ($ada) return null;

    $hari  = (int) $p['tgl_tagih'];
    $maxHr = (int) date('t', (int) strtotime($periode . '-01'));
    $tempo = $periode . '-' . str_pad((string) min($hari, $maxHr), 2, '0', STR_PAD_LEFT);

    $id = insert('tagihan', [
        'kode'        => kode_unik('INV', 'tagihan'),
        'penghuni_id' => $penghuniId,
        'periode'     => $periode,
        'jumlah'      => $p['harga'],
        'denda'       => 0,
        'jatuh_tempo' => $tempo,
        'status'      => 'belum_bayar',
    ]);

    if ($kirimWa) notify_tagihan_baru($id);
    return $id;
}

/** Terbitkan tagihan massal untuk semua penghuni aktif. */
function generate_tagihan_massal(string $periode): int
{
    $n = 0;
    foreach (all('SELECT id FROM penghuni WHERE status = "aktif"') as $p) {
        if (generate_tagihan((int) $p['id'], $periode)) $n++;
    }
    return $n;
}
