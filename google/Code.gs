/**
 * =====================================================================
 *  BOT WHATSAPP BOARDING HOUSE  —  Google Apps Script
 * =====================================================================
 *  Alur:
 *    WhatsApp admin -> Fonnte -> (webhook) -> SKRIP INI
 *                                   |
 *                                   +-> Groq AI  (memahami bahasa natural)
 *                                   +-> Spreadsheet (simpan/baca kas)
 *                                   +-> Fonnte /send (balasan ke admin)
 *
 *    Website InfinityFree -> (memanggil keluar) -> SKRIP INI -> Spreadsheet
 *
 *  Website SELALU jadi pemanggil, tidak pernah penerima. Itu sebabnya skema
 *  ini lolos, sementara webhook langsung ke InfinityFree diblokir proteksi
 *  anti-bot mereka (halaman tantangan JavaScript).
 *
 *  ── Perintah ADMIN ────────────────────────────────────────────────────
 *    KELUAR <nominal> <keterangan>   catat pengeluaran
 *    MASUK  <nominal> <keterangan>   catat pemasukan di luar sewa
 *    LAPORAN [periode]               ringkasan keuangan
 *    KAS [periode]                   daftar transaksi kas
 *    BATAL                           hapus catatan terakhir dari WhatsApp
 *    BANTUAN                         menu perintah
 *
 *  Selain perintah kaku di atas, pesan bahasa bebas juga dipahami lewat
 *  Groq AI, mis. "tadi beli galon 3 biji 60rb buat branch 2" — di chat
 *  pribadi maupun grup (lihat "Perintah di grup" di bawah).
 *
 *  ── Perintah di GRUP WhatsApp ──────────────────────────────────────────
 *  Bot ikut menerima semua pesan grup begitu dia jadi anggota — WhatsApp
 *  tidak membatasi pengiriman berdasarkan tag. Karena itu, di grup pesan
 *  HARUS diawali PREFIX_GRUP, yaitu "///" (mis. "/// LAPORAN" atau
 *  "/// beli galon 2 biji 40rb branch 2"). Setelah prefix itu terdeteksi,
 *  sisanya diproses PERSIS seperti chat pribadi (perintah baku -> Groq AI
 *  -> menu bantuan sebagai default).
 *  Pesan yang TIDAK diawali "///" dianggap obrolan biasa anggota grup lain:
 *  diamkan total dan TIDAK dicatat ke sheet `log` sama sekali (supaya log
 *  tidak penuh chat yang tidak ada hubungannya). "///" dipilih justru
 *  karena nyaris mustahil muncul di percakapan sehari-hari.
 *
 *  ⚠️ PENTING — grup baru wajib disinkronkan dulu ke Fonnte, kalau tidak
 *  balasan bot akan gagal terkirim dengan galat "invalid group id":
 *    1x saja per grup baru: POST https://api.fonnte.com/fetch-group
 *    (header Authorization: <token>, tanpa body). JANGAN dipanggil
 *    berulang tanpa perlu — dokumentasi Fonnte memperingatkan risiko ke
 *    arah pembatasan akun kalau API ini disalahgunakan.
 *
 *  ── Pemasangan ────────────────────────────────────────────────────────
 *  1. Isi Project Settings > Script properties (lihat STRUKTUR_SPREADSHEET.md)
 *  2. Deploy > New deployment > Web app
 *       Execute as       : Me
 *       Who has access   : Anyone            <-- WAJIB, agar Fonnte bisa akses
 *  3. Salin URL /exec, daftarkan di Fonnte kolom "Webhook" dengan tambahan
 *     ?key=<WEBHOOK_KEY>
 *  4. Jalankan fungsi tesPasang() sekali untuk memeriksa konfigurasi.
 * =====================================================================
 */

var TZ = 'Asia/Jakarta';
var FONNTE_SEND = 'https://api.fonnte.com/send';
var GROQ_URL    = 'https://api.groq.com/openai/v1/chat/completions';

// =====================================================================
//  KONFIGURASI
// =====================================================================

/** Ambil Script Property. */
function prop_(key, def) {
  var v = PropertiesService.getScriptProperties().getProperty(key);
  return (v === null || v === '') ? (def === undefined ? '' : def) : v;
}

// =====================================================================
//  TITIK MASUK (Web App)
// =====================================================================

function doPost(e) { return route_(e); }

/** Fonnte mensyaratkan URL webhook menerima GET maupun POST. */
function doGet(e)  { return route_(e); }

function route_(e) {
  try {
    var p = payload_(e);

    // ---- Panggilan dari website (InfinityFree) ----
    if (p.action) {
      if (p.key !== prop_('API_KEY')) return json_({ ok: false, error: 'kunci api tidak valid' });
      return json_(api_(p));
    }

    // ---- Webhook pesan masuk dari Fonnte ----
    if (p.sender || p.message || p.text) {
      var wk = prop_('WEBHOOK_KEY');
      if (wk !== '' && p.key !== wk) return json_({ ok: false, error: 'kunci webhook tidak valid' });
      return json_(webhook_(p));
    }

    // ---- Dibuka manusia di browser ----
    return json_({ ok: true, service: 'Bot WA Boarding House', waktu: nowStr_() });

  } catch (err) {
    // Jangan pernah melempar galat mentah: Fonnte butuh respons yang bisa dibaca.
    logBaris_(['', '', '', '', '', 'error', String(err)]);
    return json_({ ok: false, error: String(err) });
  }
}

/** Gabungkan parameter form-encoded, query string, dan body JSON. */
function payload_(e) {
  var out = {};
  e = e || {};
  if (e.parameter) for (var k in e.parameter) out[k] = e.parameter[k];

  if (e.postData && e.postData.contents) {
    try {
      var j = JSON.parse(e.postData.contents);
      if (j && typeof j === 'object') {
        for (var k2 in j) if (out[k2] === undefined) out[k2] = j[k2];

        // Bentuk bersarang WhatsApp Cloud API (kalau suatu saat dipakai)
        var m = j.entry && j.entry[0] && j.entry[0].changes && j.entry[0].changes[0] &&
                j.entry[0].changes[0].value && j.entry[0].changes[0].value.messages &&
                j.entry[0].changes[0].value.messages[0];
        if (m) {
          if (!out.sender)  out.sender  = m.from || '';
          if (!out.message) out.message = (m.text && m.text.body) || '';
        }
      }
    } catch (ignore) {}
  }
  return out;
}

function json_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

// =====================================================================
//  WEBHOOK: PESAN MASUK DARI WHATSAPP
// =====================================================================

/**
 * Penyaring pesan grup WhatsApp. Bot menerima SEMUA pesan grup begitu dia
 * jadi anggota (WhatsApp tidak membatasi ini), jadi perlu penyaring supaya
 * tidak ikut membalas obrolan biasa anggota grup lain. "///" nyaris mustahil
 * muncul di percakapan sehari-hari, sehingga begitu prefix ini terdeteksi,
 * sisa isinya AMAN diproses sama seperti chat pribadi — termasuk bahasa
 * bebas lewat Groq AI (mis. "/// beli galon 2 biji 40rb branch 2").
 */
var PREFIX_GRUP = '///';

function webhook_(p) {
  // Untuk pesan GRUP, Fonnte mengisi field "member" dengan nomor pengirim
  // sesungguhnya; field "sender" berisi ID GRUP-nya (format xxxx@g.us), bukan
  // nomor personal. Balasan tetap harus dikirim ke ID grup itu supaya
  // muncul di grup, sedangkan hak akses admin dicek dari nomor member-nya.
  var idAsal    = pick_(p, ['sender', 'phone', 'from', 'pengirim', 'number']);
  var member    = pick_(p, ['member']);
  var isGrup    = member !== '';
  var pengirim  = normalizeHp_(isGrup ? member : idAsal);
  var tujuanKirim = isGrup ? idAsal : pengirim;   // ID grup (xxx@g.us) TIDAK boleh lewat normalizeHp_
  var isiAsli   = String(pick_(p, ['message', 'text', 'pesan', 'body', 'msg'])).trim();
  var namaWa    = pick_(p, ['name', 'nama']);

  if (!pengirim || !isiAsli) {
    return { ok: false, error: 'payload tidak dikenali' };
  }

  var isi = isiAsli;

  if (isGrup) {
    var isiTrim = isiAsli.trim();

    if (isiTrim.indexOf(PREFIX_GRUP) !== 0) {
      // Chat biasa anggota grup, bukan ditujukan ke bot -> diamkan TOTAL,
      // termasuk TIDAK dicatat ke log. Bot menerima setiap pesan di grup
      // (WhatsApp tidak membatasi ini), jadi tanpa pengecualian ini sheet
      // `log` akan penuh oleh obrolan biasa yang tidak ada hubungannya.
      return { ok: true, diam: true };
    }

    // Prefix terdeteksi -> ini upaya sungguhan memanggil bot. Sisa isinya
    // diproses persis seperti chat pribadi di bawah (jawabAdmin_ sudah
    // menangani perintah baku, lalu jatuh ke Groq AI, lalu ke menu bantuan
    // sebagai default) — makanya interaksi ini SELALU dicatat ke log.
    isi = isiTrim.slice(PREFIX_GRUP.length).trim();
  }

  var admin = cariAdmin_(pengirim);
  var balas, status;

  if (!admin) {
    balas  = 'Halo! 👋 Nomor Anda tidak terdaftar sebagai pengelola ' + cfg_('nama_kost', 'Boarding House') + '.\n\n' +
             'Bot ini khusus untuk pencatatan keuangan oleh pengelola.\n' +
             'Untuk pertanyaan seputar kamar dan tagihan, silakan kunjungi ' +
             cfg_('base_url', '') + ' atau hubungi pengelola langsung.';
    status = 'ditolak';
  } else {
    balas  = jawabAdmin_(isi, admin);
    status = 'ok';
  }

  balas += cfg_('wa_footer', '');
  var kirim = fonnteSend_(tujuanKirim, balas);

  // tujuanKirim disisipkan ke catatan supaya kalau kirim gagal, langsung
  // kelihatan persis string apa yang dikirim sebagai target ke Fonnte.
  logBaris_([nowStr_(), pengirim, namaWa || (admin ? admin.nama : ''), isiAsli,
             balas.slice(0, 500), kirim.ok ? status : 'gagal_kirim',
             (kirim.ok ? '' : kirim.info) + ' | target=' + tujuanKirim]);

  return { ok: kirim.ok, peran: admin ? 'admin' : 'bukan_admin', terkirim: kirim.ok, grup: isGrup };
}

/** Susun balasan untuk admin berdasarkan isi pesan. */
function jawabAdmin_(isi, admin) {
  var bagi  = isi.split(/\s+/);
  var cmd   = (bagi[0] || '').toUpperCase();
  var sisa  = isi.slice((bagi[0] || '').length).trim();

  switch (cmd) {
    case 'KELUAR':
    case 'MASUK':
      return catatManual_(cmd === 'MASUK' ? 'masuk' : 'keluar', sisa, admin);

    case 'LAPORAN':
      var a = kasPeriode_(sisa);
      return teksLaporan_(a[0], a[1], a[2]);

    case 'KAS':
      var b = kasPeriode_(sisa);
      return teksKas_(b[0], b[1], b[2]);

    case 'BATAL':
      return batalTerakhir_(admin);

    case 'BANTUAN':
    case 'HELP':
    case 'MENU':
      return menuAdmin_();
  }

  // Bukan perintah kaku -> coba pahami sebagai bahasa natural lewat Groq.
  var ai = groqParse_(isi);
  if (ai && ai.transaksi && ai.jumlah > 0) {
    return simpanTransaksi_({
      jenis:      ai.jenis === 'masuk' ? 'masuk' : 'keluar',
      jumlah:     ai.jumlah,
      keterangan: ai.keterangan || isi,
      kostTeks:   ai.kost || isi,
      tanggal:    ai.tanggal || '',
      kategori:   ai.kategori || ''
    }, admin, true);
  }

  return menuAdmin_();
}

/** Perintah kaku: KELUAR/MASUK <nominal> <keterangan>. */
function catatManual_(jenis, sisa, admin) {
  var jumlah = parseNominal_(sisa);
  var label  = jenis === 'masuk' ? 'MASUK' : 'KELUAR';

  if (jumlah === null || jumlah <= 0) {
    return '⚠️ Nominal tidak terbaca.\n\nFormat:\n*' + label + ' <nominal> <keterangan>*\n\n' +
           'Contoh:\n• ' + label + ' 150000 token listrik\n• ' + label + ' 150rb token listrik\n' +
           '• ' + label + ' 1,5jt gaji penjaga\n\n' +
           'Atau tulis bebas saja, mis. "bayar tukang 300rb perbaikan kamar mandi".';
  }

  // Buang bagian nominal, sisanya jadi keterangan.
  var ket = sisa.replace(/^\s*[\d][\d.,]*\s*(jt|juta|rb|ribu|k)?\s*/i, '').trim();
  if (!ket) ket = (jenis === 'masuk' ? 'Pemasukan' : 'Pengeluaran') + ' via WhatsApp';

  return simpanTransaksi_({
    jenis: jenis, jumlah: jumlah, keterangan: ket, kostTeks: ket, tanggal: '', kategori: ''
  }, admin, false);
}

/** Tulis transaksi ke Spreadsheet lalu susun teks konfirmasi. */
function simpanTransaksi_(t, admin, viaAi) {
  var kost = cariKost_(t.kostTeks);
  var row  = kasCatat_({
    jenis:      t.jenis,
    jumlah:     t.jumlah,
    keterangan: t.keterangan,
    kategori:   t.kategori || tebakKategori_(t.keterangan, t.jenis),
    kost_id:    kost ? kost.id : '',
    kost:       kost ? kost.nama : '',
    tanggal:    ymdValid_(t.tanggal) || hariIni_(),
    sumber:     'wa',
    user_id:    admin.user_id,
    pencatat:   admin.nama
  });

  var per = kasPeriode_('');
  var r   = hitungRingkasan_(per[0], per[1]);

  return (t.jenis === 'masuk' ? '✅ *PEMASUKAN DICATAT*' : '✅ *PENGELUARAN DICATAT*') +
         (viaAi ? ' 🤖' : '') + '\n\n' +
         tt_('Kode', row.kode) +
         tt_('Nominal', rp_(row.jumlah)) +
         tt_('Keterangan', row.keterangan) +
         tt_('Kategori', ucfirst_(String(row.kategori).replace(/_/g, ' '))) +
         tt_('Cabang', row.kost || 'Umum') +
         tt_('Tanggal', tgl_(row.tanggal)) +
         '\nSudah masuk ke laporan ' + per[2] + ':\n' +
         '• Pemasukan : ' + rp_(r.masuk_total) + '\n' +
         '• Pengeluaran : ' + rp_(r.keluar) + '\n' +
         '• *Saldo : ' + rp_(r.saldo) + '*\n\n' +
         'Salah catat? Balas *BATAL* untuk menghapusnya.';
}

/** Hapus transaksi WhatsApp terakhir milik admin ini. */
function batalTerakhir_(admin) {
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh   = sheet_('kas');
    var data = sh.getDataRange().getValues();

    for (var i = data.length - 1; i >= 1; i--) {
      var sumber = String(data[i][8]).trim();               // kolom I
      var uid    = String(data[i][9]).trim();               // kolom J
      if (sumber === 'wa' && uid === String(admin.user_id)) {
        var kode = data[i][0], jenis = data[i][2],
            jml  = num_(data[i][4]), ket = data[i][5];
        sh.deleteRow(i + 1);
        return '🗑️ *DIBATALKAN*\n\nTransaksi ' + kode + ' (' +
               (jenis === 'masuk' ? 'pemasukan' : 'pengeluaran') + ' ' + rp_(jml) +
               ' — ' + ket + ') telah dihapus dari laporan.';
      }
    }
    return 'Tidak ada catatan kas dari WhatsApp yang bisa dibatalkan.';
  } finally {
    lock.releaseLock();
  }
}

function menuAdmin_() {
  return 'Halo! 👋 Saya asisten keuangan *' + cfg_('nama_kost', 'Boarding House') + '*.\n\n' +
         '*Perintah pengelola:*\n' +
         '▪️ *KELUAR 150rb token listrik* — catat pengeluaran\n' +
         '▪️ *MASUK 500rb deposit B1-03* — catat pemasukan\n' +
         '▪️ *LAPORAN* — ringkasan bulan ini\n' +
         '▪️ *LAPORAN JULI* — ringkasan bulan lain\n' +
         '▪️ *KAS* — daftar transaksi kas\n' +
         '▪️ *BATAL* — hapus catatan terakhir\n\n' +
         'Nominal boleh 150000, 150.000, 150rb, atau 1,5jt.\n' +
         'Bisa juga tulis bebas, mis. _"beli galon 2 biji 40rb branch 2"_ 🤖\n\n' +
         'Semua catatan langsung masuk ke laporan di website.';
}

// =====================================================================
//  LAPORAN & DAFTAR KAS
// =====================================================================

function teksLaporan_(dari, sampai, judul) {
  var r = hitungRingkasan_(dari, sampai);
  var m = '📊 *LAPORAN KEUANGAN*\n_' + judul + '_\n\n' +
          '*PEMASUKAN*\n' +
          '• Sewa kamar : ' + rp_(r.sewa) + '\n' +
          '• Lain-lain  : ' + rp_(r.lain) + '\n' +
          '• Total      : *' + rp_(r.masuk_total) + '*\n\n' +
          '*PENGELUARAN*\n';

  var ada = false;
  for (var kat in r.keluar_kategori) {
    ada = true;
    m += '• ' + ucfirst_(kat.replace(/_/g, ' ')) + ' : ' + rp_(r.keluar_kategori[kat]) + '\n';
  }
  if (!ada) m += '• (belum ada)\n';

  m += '• Total      : *' + rp_(r.keluar) + '*\n\n' +
       '💰 *SALDO : ' + rp_(r.saldo) + '*\n\n';

  if (r.punya_ringkasan) {
    m += 'Piutang belum tertagih: ' + rp_(r.piutang) + '\n' +
         'Hunian: ' + r.huni + ' dari ' + r.kamar + ' kamar\n\n';
  } else {
    m += '_Data sewa & hunian belum tersinkron dari website._\n\n';
  }

  var url = cfg_('base_url', '');
  if (url) m += 'Rincian lengkap: ' + url + 'admin/laporan.php';
  return m;
}

function teksKas_(dari, sampai, judul) {
  var rows = kasDaftar_(dari, sampai);
  if (!rows.length) return 'Belum ada transaksi kas pada periode *' + judul + '*.';

  var m = '📒 *BUKU KAS*\n_' + judul + '_\n';
  var tampil = rows.slice(-15);
  for (var i = 0; i < tampil.length; i++) {
    var r = tampil[i];
    m += '\n' + (r.jenis === 'masuk' ? '🟢' : '🔴') + ' ' + tgl_(r.tanggal) +
         ' · ' + rp_(r.jumlah) + '\n   ' + r.keterangan + ' (' + r.kategori + ')\n';
  }
  if (rows.length > 15) {
    m += '\n_Menampilkan 15 transaksi terakhir dari ' + rows.length + '._\n';
  }
  var r2 = hitungRingkasan_(dari, sampai);
  return m + '\n*Saldo periode ini: ' + rp_(r2.saldo) + '*';
}

/**
 * Ringkasan gabungan: transaksi kas dari Spreadsheet + angka MySQL
 * (sewa/piutang/hunian) yang didorong website ke sheet `ringkasan`.
 */
function hitungRingkasan_(dari, sampai) {
  var lain = 0, keluar = 0, kategori = {};

  kasDaftar_(dari, sampai).forEach(function (r) {
    if (r.jenis === 'masuk') {
      lain += r.jumlah;
    } else {
      keluar += r.jumlah;
      kategori[r.kategori] = (kategori[r.kategori] || 0) + r.jumlah;
    }
  });

  // Angka dari website
  var sewa = 0, piutang = 0, huni = 0, kamar = 0, ada = false, terbaru = '';
  rows_('ringkasan').forEach(function (r) {
    var per = ym_(r.periode);
    if (!per) return;
    var awal = per + '-01';
    if (awal >= dari.slice(0, 7) + '-01' && awal <= sampai) {
      sewa += num_(r.sewa);
      ada = true;
    }
    var up = String(r.updated_at || '');
    if (up >= terbaru) {
      terbaru = up;
      piutang = num_(r.piutang);
      huni    = num_(r.huni);
      kamar   = num_(r.kamar);
    }
  });

  return {
    sewa: sewa, lain: lain, masuk_total: sewa + lain,
    keluar: keluar, keluar_kategori: kategori,
    saldo: sewa + lain - keluar,
    piutang: piutang, huni: huni, kamar: kamar,
    punya_ringkasan: ada
  };
}

/** Transaksi kas dalam rentang tanggal, urut tanggal naik. */
function kasDaftar_(dari, sampai, jenis) {
  var out = [];
  rows_('kas').forEach(function (r) {
    var t = ymd_(r.tanggal);
    if (!t || t < dari || t > sampai) return;
    if (jenis && String(r.jenis) !== jenis) return;
    out.push({
      _row: r._row,
      kode: String(r.kode || ''), tanggal: t,
      jenis: String(r.jenis || '').trim(),
      kategori: String(r.kategori || 'lainnya').trim(),
      jumlah: num_(r.jumlah),
      keterangan: String(r.keterangan || ''),
      kost_id: r.kost_id === '' ? null : num_(r.kost_id),
      kost: String(r.kost || ''),
      sumber: String(r.sumber || ''),
      user_id: r.user_id === '' ? null : num_(r.user_id),
      pencatat: String(r.pencatat || ''),
      bukti: String(r.bukti || '')
    });
  });
  out.sort(function (a, b) { return a.tanggal < b.tanggal ? -1 : (a.tanggal > b.tanggal ? 1 : 0); });
  return out;
}

/** Tambah satu baris kas. Dikunci agar kode tidak dobel saat bersamaan. */
function kasCatat_(d) {
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = sheet_('kas');
    // Website mengirim kode buatannya sendiri (seri KM/KK dari MySQL);
    // bot memakai seri WM/WK agar nomornya tidak pernah bertabrakan.
    var kode = String(d.kode || '').trim() || kodeUnik_(sh, d.jenis);
    var baris = [
      kode,
      d.tanggal || hariIni_(),
      d.jenis === 'masuk' ? 'masuk' : 'keluar',
      d.kategori || 'lainnya',
      Number(d.jumlah) || 0,
      String(d.keterangan || '').slice(0, 255),
      d.kost_id === null || d.kost_id === undefined ? '' : d.kost_id,
      d.kost || '',
      d.sumber === 'wa' ? 'wa' : 'web',
      d.user_id === null || d.user_id === undefined ? '' : d.user_id,
      d.pencatat || '',
      d.bukti || '',
      nowStr_()
    ];

    // Idempoten: bila kode itu sudah ada, baris lama DITIMPA, bukan ditambah.
    // Tanpa ini, percobaan dorong ulang dari website (mis. setelah timeout
    // yang sebenarnya berhasil) akan melahirkan transaksi kembar.
    var lama = 0;
    var data = sh.getDataRange().getValues();
    for (var i = 1; i < data.length; i++) {
      if (String(data[i][0]).trim() === kode) { lama = i + 1; break; }
    }
    if (lama) {
      sh.getRange(lama, 1, 1, baris.length).setValues([baris]);
    } else {
      sh.appendRow(baris);
    }

    return {
      kode: kode, tanggal: baris[1], jenis: baris[2], kategori: baris[3],
      jumlah: baris[4], keterangan: baris[5], kost_id: baris[6], kost: baris[7],
      sumber: baris[8], user_id: baris[9], pencatat: baris[10]
    };
  } finally {
    lock.releaseLock();
  }
}

/**
 * WM-202608-0001 (masuk) / WK-202608-0001 (keluar) — seri khusus bot WhatsApp.
 *
 * Sengaja BEDA dari seri KM/KK milik website. Kalau keduanya memakai seri sama,
 * transaksi lewat web dan lewat WA yang terjadi bersamaan akan menghasilkan
 * nomor identik, dan penarikan ke MySQL gagal karena kolom `kode` bersifat
 * UNIQUE. Bonusnya: sumber transaksi langsung terbaca dari kodenya.
 */
function kodeUnik_(sh, jenis) {
  var pre  = (jenis === 'masuk' ? 'WM' : 'WK') + '-' +
             Utilities.formatDate(new Date(), TZ, 'yyyyMM') + '-';
  var data = sh.getDataRange().getValues();
  var n = 0;
  for (var i = 1; i < data.length; i++) {
    if (String(data[i][0]).indexOf(pre) === 0) n++;
  }
  return pre + ('0000' + (n + 1)).slice(-4);
}

// =====================================================================
//  GROQ AI — memahami pesan bahasa bebas
// =====================================================================

/**
 * Ubah pesan bebas menjadi objek transaksi.
 * @return {Object|null} {transaksi, jenis, jumlah, keterangan, kost, kategori, tanggal}
 *                        atau null bila Groq tidak tersedia / gagal.
 */
function groqParse_(teks) {
  var key = prop_('GROQ_API_KEY');
  if (!key) return null;

  var katKeluar = Object.keys(KATEGORI_KELUAR).join(', ');
  var katMasuk  = Object.keys(KATEGORI_MASUK).join(', ');
  var kostNama  = rows_('kost').map(function (k) { return String(k.nama); }).join(', ');

  var sistem =
    'Kamu adalah pengurai pesan keuangan untuk sebuah rumah kost di Indonesia. ' +
    'Hari ini ' + hariIni_() + ' (zona Asia/Jakarta). ' +
    'Tugasmu mengubah pesan pengelola menjadi JSON. Balas HANYA JSON, tanpa penjelasan.\n\n' +
    'Skema:\n' +
    '{"transaksi":boolean,"jenis":"masuk"|"keluar","jumlah":number,' +
    '"keterangan":string,"kategori":string,"kost":string,"tanggal":"YYYY-MM-DD"}\n\n' +
    'Aturan:\n' +
    '- transaksi=false bila pesan bukan pencatatan uang (mis. sapaan, pertanyaan, minta laporan).\n' +
    '- jenis="keluar" untuk uang keluar (beli, bayar, gaji, servis); "masuk" untuk uang diterima ' +
    'di luar sewa (deposit, denda, laundry, parkir).\n' +
    '- jumlah dalam rupiah sebagai angka bulat tanpa pemisah. "150rb"=150000, "1,5jt"=1500000.\n' +
    '- keterangan: ringkas, maksimal 10 kata, tanpa menyebut nominal.\n' +
    '- kategori keluar pilih salah satu: ' + katKeluar + '.\n' +
    '- kategori masuk pilih salah satu: ' + katMasuk + '.\n' +
    '- kost: nama cabang bila disebut, pilih dari: [' + (kostNama || 'tidak ada') + ']. ' +
    'Kosongkan bila tidak jelas.\n' +
    '- tanggal: hitung dari kata relatif ("kemarin", "senin lalu"). Kosongkan bila tidak disebut.';

  try {
    var res = UrlFetchApp.fetch(GROQ_URL, {
      method: 'post',
      contentType: 'application/json',
      headers: { Authorization: 'Bearer ' + key },
      payload: JSON.stringify({
        model: prop_('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        temperature: 0,
        max_tokens: 300,
        response_format: { type: 'json_object' },
        messages: [
          { role: 'system', content: sistem },
          { role: 'user',   content: String(teks).slice(0, 1000) }
        ]
      }),
      muteHttpExceptions: true
    });

    if (res.getResponseCode() !== 200) {
      logBaris_([nowStr_(), '', '', String(teks).slice(0, 200), '', 'groq_gagal',
                 'HTTP ' + res.getResponseCode() + ' ' + res.getContentText().slice(0, 300)]);
      return null;
    }

    var body = JSON.parse(res.getContentText());
    var isi  = body.choices && body.choices[0] && body.choices[0].message &&
               body.choices[0].message.content;
    if (!isi) return null;

    var o = JSON.parse(isi);
    return {
      transaksi:  o.transaksi === true,
      jenis:      o.jenis === 'masuk' ? 'masuk' : 'keluar',
      jumlah:     Number(o.jumlah) || 0,
      keterangan: String(o.keterangan || '').slice(0, 255),
      kategori:   String(o.kategori || ''),
      kost:       String(o.kost || ''),
      tanggal:    String(o.tanggal || '')
    };

  } catch (err) {
    logBaris_([nowStr_(), '', '', String(teks).slice(0, 200), '', 'groq_error', String(err)]);
    return null;  // jatuh ke penguraian manual
  }
}

// =====================================================================
//  API UNTUK WEBSITE (dipanggil dari InfinityFree)
// =====================================================================

function api_(p) {
  switch (p.action) {

    case 'ping':
      return { ok: true, waktu: nowStr_() };

    case 'kas_list':
      return { ok: true, data: kasDaftar_(ymdValid_(p.dari) || hariIni_(),
                                          ymdValid_(p.sampai) || hariIni_(),
                                          p.jenis || '') };

    case 'kas_ringkasan':
      return { ok: true, data: hitungRingkasan_(ymdValid_(p.dari) || hariIni_(),
                                                ymdValid_(p.sampai) || hariIni_()) };

    case 'kas_add':
      var kost = p.kost_id ? { id: p.kost_id, nama: p.kost || '' } : cariKost_(p.kost || p.keterangan || '');
      return { ok: true, data: kasCatat_({
        kode:       p.kode || '',
        jenis:      p.jenis,
        jumlah:     parseFloat(p.jumlah) || 0,
        keterangan: p.keterangan || '',
        kategori:   p.kategori || tebakKategori_(p.keterangan || '', p.jenis),
        kost_id:    kost ? kost.id : '',
        kost:       kost ? kost.nama : '',
        tanggal:    ymdValid_(p.tanggal) || hariIni_(),
        sumber:     'web',
        user_id:    p.user_id || '',
        pencatat:   p.pencatat || '',
        bukti:      p.bukti || ''
      }) };

    case 'kas_delete':
      return { ok: true, dihapus: kasHapus_(String(p.kode || '')) };

    case 'sync_meta':
      return syncMeta_(p);
  }
  return { ok: false, error: 'action tidak dikenal: ' + p.action };
}

function kasHapus_(kode) {
  if (!kode) return 0;
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = sheet_('kas'), data = sh.getDataRange().getValues();
    for (var i = data.length - 1; i >= 1; i--) {
      if (String(data[i][0]) === kode) { sh.deleteRow(i + 1); return 1; }
    }
    return 0;
  } finally {
    lock.releaseLock();
  }
}

/**
 * Website mendorong data yang hanya ada di MySQL:
 *   kost      : [{id,nama}]
 *   admin     : [{no_hp,nama,user_id}]
 *   config    : [{k,v}]
 *   ringkasan : {periode,sewa,piutang,huni,kamar}
 * Semua parameter dikirim sebagai string JSON.
 */
function syncMeta_(p) {
  var hasil = {};

  if (p.kost)   hasil.kost   = tulisUlang_('kost',   JSON.parse(p.kost),   ['id', 'nama']);
  if (p.admin)  hasil.admin  = tulisUlang_('admin',  JSON.parse(p.admin),  ['no_hp', 'nama', 'user_id']);
  if (p.config) hasil.config = tulisUlang_('config', JSON.parse(p.config), ['k', 'v']);

  if (p.ringkasan) {
    var r  = JSON.parse(p.ringkasan);
    var sh = sheet_('ringkasan');
    var data = sh.getDataRange().getValues();
    var baris = [String(r.periode), num_(r.sewa), num_(r.piutang),
                 num_(r.huni), num_(r.kamar), nowStr_()];
    var found = 0;
    for (var i = 1; i < data.length; i++) {
      if (ym_(data[i][0]) === ym_(r.periode)) {
        sh.getRange(i + 1, 1, 1, baris.length).setValues([baris]);
        found = i + 1;
        break;
      }
    }
    if (!found) sh.appendRow(baris);
    hasil.ringkasan = r.periode;
  }

  return { ok: true, data: hasil };
}

/** Ganti seluruh isi sheet (selain header) dengan data baru. */
function tulisUlang_(nama, arr, kolom) {
  var sh = sheet_(nama);
  var akhir = sh.getLastRow();
  if (akhir > 1) sh.getRange(2, 1, akhir - 1, sh.getLastColumn()).clearContent();
  if (!arr || !arr.length) return 0;

  var nilai = arr.map(function (o) {
    return kolom.map(function (k) { return o[k] === undefined || o[k] === null ? '' : o[k]; });
  });
  sh.getRange(2, 1, nilai.length, kolom.length).setValues(nilai);
  return nilai.length;
}

// =====================================================================
//  AKSES SPREADSHEET
// =====================================================================

function ss_() {
  var id = prop_('SPREADSHEET_ID');
  if (!id) throw new Error('SPREADSHEET_ID belum diisi di Script properties.');
  return SpreadsheetApp.openById(id);
}

function sheet_(nama) {
  var s = ss_().getSheetByName(nama);
  if (!s) throw new Error('Sheet "' + nama + '" tidak ditemukan di Spreadsheet.');
  return s;
}

/** Baca sheet jadi array objek, kunci = header baris 1. */
function rows_(nama) {
  var v = sheet_(nama).getDataRange().getValues();
  if (v.length < 2) return [];
  var head = v[0].map(function (h) { return String(h).trim(); });
  var out = [];
  for (var i = 1; i < v.length; i++) {
    if (v[i].join('') === '') continue;             // lewati baris kosong
    var o = { _row: i + 1 };
    for (var c = 0; c < head.length; c++) o[head[c]] = v[i][c];
    out.push(o);
  }
  return out;
}

/** Nilai dari sheet `config`, dengan cache agar tidak dibaca berulang. */
var _cfgCache = null;
function cfg_(kunci, def) {
  if (_cfgCache === null) {
    _cfgCache = {};
    try {
      rows_('config').forEach(function (r) { _cfgCache[String(r.k).trim()] = String(r.v); });
    } catch (ignore) {}
  }
  var v = _cfgCache[kunci];
  return (v === undefined || v === '') ? (def === undefined ? '' : def) : v;
}

function cariAdmin_(hp) {
  var t = normalizeHp_(hp), hasil = null;
  rows_('admin').forEach(function (r) {
    if (normalizeHp_(String(r.no_hp)) === t) {
      hasil = { no_hp: t, nama: String(r.nama || 'Admin'), user_id: r.user_id || '' };
    }
  });
  return hasil;
}

/** Cari cabang dari potongan teks ("branch 3", "b3", "3"). */
function cariKost_(teks) {
  var t = String(teks || '').toLowerCase().trim();
  if (!t) return null;

  var daftar = rows_('kost').map(function (r) {
    return { id: r.id, nama: String(r.nama || '') };
  }).filter(function (k) { return k.nama !== ''; });
  if (!daftar.length) return null;

  // "branch 3" / "b3" / "3" -> cocokkan angkanya
  var m = t.match(/(?:branch\s*|b)?(\d+)/);
  if (m) {
    var target = ('branch ' + m[1]);
    for (var i = 0; i < daftar.length; i++) {
      if (daftar[i].nama.toLowerCase() === target) return daftar[i];
    }
  }
  // cocokkan sebagian nama
  for (var j = 0; j < daftar.length; j++) {
    if (t.indexOf(daftar[j].nama.toLowerCase()) !== -1) return daftar[j];
  }
  return null;
}

function logBaris_(baris) {
  try { sheet_('log').appendRow(baris); } catch (ignore) {}
}

// =====================================================================
//  KATEGORI  (disamakan dengan app/kas.php di website)
// =====================================================================

var KATEGORI_KELUAR = {
  listrik:      ['listrik', 'token', 'pln'],
  air:          ['air', 'pdam', 'pompa'],
  internet:     ['wifi', 'internet', 'indihome', 'kuota'],
  kebersihan:   ['sampah', 'kebersihan', 'cleaning', 'bersih', 'sapu'],
  perbaikan:    ['perbaikan', 'servis', 'service', 'rusak', 'bocor', 'cat', 'ganti', 'tukang'],
  perlengkapan: ['beli', 'belanja', 'alat', 'lampu', 'galon', 'gas', 'kasur', 'lemari'],
  gaji:         ['gaji', 'upah', 'penjaga', 'asisten', 'art'],
  keamanan:     ['keamanan', 'satpam', 'ronda'],
  pajak:        ['pajak', 'pbb', 'retribusi', 'iuran'],
  lainnya:      []
};

var KATEGORI_MASUK = {
  deposit:   ['deposit', 'jaminan'],
  denda:     ['denda', 'telat'],
  laundry:   ['laundry', 'cuci'],
  parkir:    ['parkir'],
  sewa_lain: ['aula', 'gudang', 'sewa'],
  lainnya:   []
};

function tebakKategori_(teks, jenis) {
  var t   = String(teks || '').toLowerCase();
  var map = jenis === 'masuk' ? KATEGORI_MASUK : KATEGORI_KELUAR;
  for (var kat in map) {
    var kunci = map[kat];
    for (var i = 0; i < kunci.length; i++) {
      if (t.indexOf(kunci[i]) !== -1) return kat;
    }
  }
  return 'lainnya';
}

// =====================================================================
//  UTILITAS  (padanan helpers.php)
// =====================================================================

/** "150000" "150.000" "150rb" "1,5jt" "50k" -> angka */
function parseNominal_(teks) {
  var t = String(teks || '').toLowerCase().trim();
  var m = t.match(/([\d][\d.,]*)\s*(jt|juta|rb|ribu|k)?/);
  if (!m) return null;

  var angka  = m[1];
  var satuan = m[2] || '';
  var nilai;

  if (satuan) {
    angka = angka.replace(/,/g, '.');
    if ((angka.match(/\./g) || []).length > 1) angka = angka.replace(/\./g, '');
    nilai = parseFloat(angka) * ((satuan === 'jt' || satuan === 'juta') ? 1000000 : 1000);
  } else {
    nilai = parseFloat(angka.replace(/[.,]/g, ''));
  }
  if (!isFinite(nilai) || nilai <= 0) return null;
  return Math.round(nilai * 100) / 100;
}

/** Terjemahkan kata periode jadi [dari, sampai, judul]. */
function kasPeriode_(teks) {
  var t   = String(teks || '').toLowerCase().trim();
  var now = new Date();

  if (t === 'hari ini' || t === 'harian' || t === 'hariini') {
    var h = hariIni_();
    return [h, h, 'Hari ini, ' + tgl_(h)];
  }
  if (t === 'kemarin' || t === 'bulan lalu' || t === 'lalu') {
    return bulanKe_(now.getFullYear(), now.getMonth());        // getMonth() = bulan lalu (0-based)
  }
  if (t === 'tahun ini' || t === 'tahunan') {
    var y = now.getFullYear();
    return [y + '-01-01', y + '-12-31', 'Tahun ' + y];
  }
  if (/^\d{4}-\d{2}$/.test(t)) {
    var bagi = t.split('-'), bl = parseInt(bagi[1], 10), th = parseInt(bagi[0], 10);
    if (bl >= 1 && bl <= 12 && th >= 2000 && th <= 2100) return bulanKe_(th, bl);
  }

  var namaBulan = ['januari', 'februari', 'maret', 'april', 'mei', 'juni',
                   'juli', 'agustus', 'september', 'oktober', 'november', 'desember'];
  if (t) {
    for (var i = 0; i < 12; i++) {
      if (namaBulan[i].indexOf(t) === 0) return bulanKe_(now.getFullYear(), i + 1);
    }
  }
  return bulanKe_(now.getFullYear(), now.getMonth() + 1);      // bulan berjalan
}

/** [dari, sampai, judul] untuk satu bulan. bulan = 1..12 */
function bulanKe_(tahun, bulan) {
  if (bulan < 1)  { bulan = 12; tahun--; }
  if (bulan > 12) { bulan = 1;  tahun++; }
  var mm     = ('0' + bulan).slice(-2);
  var akhir  = new Date(tahun, bulan, 0).getDate();            // hari terakhir bulan
  var label  = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
                'Agustus', 'September', 'Oktober', 'November', 'Desember'][bulan - 1];
  return [tahun + '-' + mm + '-01',
          tahun + '-' + mm + '-' + ('0' + akhir).slice(-2),
          label + ' ' + tahun];
}

function rp_(n) {
  n = Number(n) || 0;
  var neg = n < 0;
  var s = Math.round(Math.abs(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return (neg ? '-Rp ' : 'Rp ') + s;
}

/** 06 Agu 2026 */
function tgl_(ymd) {
  var t = ymd_(ymd);
  var m = t.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return '-';
  var bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
             'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  return m[3] + ' ' + bln[parseInt(m[2], 10)] + ' ' + m[1];
}

/** Sheets kadang mengubah teks tanggal jadi objek Date — samakan ke YYYY-MM-DD. */
function ymd_(v) {
  if (v instanceof Date) return Utilities.formatDate(v, TZ, 'yyyy-MM-dd');
  var s = String(v || '').trim();
  var m = s.match(/^\d{4}-\d{2}-\d{2}/);
  return m ? m[0] : '';
}

function ymdValid_(v) {
  var t = ymd_(v);
  return /^\d{4}-\d{2}-\d{2}$/.test(t) ? t : '';
}

/**
 * Samakan nilai periode ke "YYYY-MM".
 * Google Sheets sering mengubah teks "2026-08" menjadi objek Date begitu
 * ditulis lewat setValues(); tanpa penyamaan ini angka sewa tidak akan
 * pernah terhitung di LAPORAN dan baris ringkasan jadi berlipat tiap sinkron.
 */
function ym_(v) {
  if (v instanceof Date) return Utilities.formatDate(v, TZ, 'yyyy-MM');
  var m = String(v || '').trim().match(/^(\d{4})-(\d{2})/);
  return m ? m[1] + '-' + m[2] : '';
}

function hariIni_() { return Utilities.formatDate(new Date(), TZ, 'yyyy-MM-dd'); }
function nowStr_()  { return Utilities.formatDate(new Date(), TZ, 'yyyy-MM-dd HH:mm:ss'); }

function num_(v) {
  if (typeof v === 'number') return v;
  var n = parseFloat(String(v || '0').replace(/[^\d.-]/g, ''));
  return isFinite(n) ? n : 0;
}

function ucfirst_(s) {
  s = String(s || '');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function tt_(label, nilai) { return '*' + label + ':* ' + nilai + '\n'; }

function pick_(obj, keys) {
  for (var i = 0; i < keys.length; i++) {
    var v = obj[keys[i]];
    if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
  }
  return '';
}

/** 08xx -> 628xx (sama dengan normalize_hp() di PHP). */
function normalizeHp_(hp) {
  var s = String(hp || '').replace(/\D+/g, '');
  if (s.indexOf('0') === 0)   return '62' + s.slice(1);
  if (s.indexOf('620') === 0) return '62' + s.slice(3);
  if (s.indexOf('8') === 0)   return '62' + s;
  return s;
}

// =====================================================================
//  KIRIM PESAN LEWAT FONNTE
// =====================================================================

/**
 * Fonnte SELALU membalas HTTP 200 walau pesan gagal terkirim — kegagalan
 * ditandai lewat field "status":false di badan JSON (mis. device disconnect).
 * Tanpa pengecekan ini, pesan gagal akan tercatat "terkirim".
 *
 * ID grup (format "xxxx@g.us") HARUS dikirim apa adanya — normalizeHp_()
 * membuang semua karakter selain digit, yang akan merusak format itu dan
 * membuat balasan gagal terkirim ke grup.
 */
function fonnteSend_(tujuan, pesan) {
  var token = prop_('FONNTE_TOKEN');
  if (!token) return { ok: false, info: 'FONNTE_TOKEN belum diisi di Script properties.' };

  var target = String(tujuan).indexOf('@g.us') !== -1 ? tujuan : normalizeHp_(tujuan);

  try {
    var res = UrlFetchApp.fetch(FONNTE_SEND, {
      method: 'post',
      headers: { Authorization: token },
      payload: { target: target, message: pesan, countryCode: '62' },
      muteHttpExceptions: true
    });
    var kode = res.getResponseCode();
    var txt  = res.getContentText();
    var data = {};
    try { data = JSON.parse(txt); } catch (ignore) {}

    return {
      ok:   kode >= 200 && kode < 300 && data.status !== false,
      info: 'HTTP ' + kode + ' | ' + txt.slice(0, 500)
    };
  } catch (err) {
    return { ok: false, info: String(err) };
  }
}

// =====================================================================
//  PEMERIKSAAN PEMASANGAN
//  Jalankan sekali dari editor (pilih fungsi ini lalu Run), lihat Execution log.
// =====================================================================

function tesPasang() {
  var pesan = [];

  ['SPREADSHEET_ID', 'FONNTE_TOKEN', 'WEBHOOK_KEY', 'API_KEY'].forEach(function (k) {
    pesan.push((prop_(k) ? '✅' : '❌') + ' ' + k + (prop_(k) ? ' terisi' : ' KOSONG'));
  });
  pesan.push((prop_('GROQ_API_KEY') ? '✅' : '⚠️') + ' GROQ_API_KEY' +
             (prop_('GROQ_API_KEY') ? ' terisi' : ' kosong (bahasa bebas nonaktif, perintah kaku tetap jalan)'));
  pesan.push('ℹ️ Perintah di grup WA diawali "' + PREFIX_GRUP + '", mis. "' + PREFIX_GRUP + ' LAPORAN"');

  ['kas', 'kost', 'admin', 'ringkasan', 'config', 'log'].forEach(function (n) {
    try { sheet_(n); pesan.push('✅ sheet "' + n + '" ada'); }
    catch (e) { pesan.push('❌ ' + e.message); }
  });

  try {
    var adm = rows_('admin');
    pesan.push((adm.length ? '✅' : '⚠️') + ' daftar admin: ' + adm.length + ' nomor' +
               (adm.length ? '' : ' (sinkronkan dari website dulu)'));
  } catch (e) { pesan.push('❌ ' + e.message); }

  var out = pesan.join('\n');
  Logger.log(out);
  return out;
}

/** Uji kirim pesan ke satu nomor. Ubah nomornya lalu Run. */
function tesKirim() {
  var hasil = fonnteSend_('628123456789',
    '🔧 *Tes bot Boarding House*\n\nJika pesan ini sampai, Apps Script sudah tersambung ke Fonnte.\n' +
    'Waktu uji: ' + nowStr_());
  Logger.log(JSON.stringify(hasil));
  return hasil;
}
