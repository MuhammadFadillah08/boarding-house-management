# Struktur Google Spreadsheet — Bot WA Boarding House

Spreadsheet ini menjadi **jembatan data** antara bot WhatsApp (Google Apps Script)
dan website (InfinityFree). Alasannya: InfinityFree memblokir request masuk dari
server luar, tapi **request keluar** dari InfinityFree lancar. Jadi:

```
Fonnte ──webhook──▶ Apps Script ──tulis/baca──▶ Spreadsheet
                         │
                         └──balasan──▶ Fonnte ──▶ WhatsApp admin

Website (InfinityFree) ──memanggil keluar──▶ Apps Script Web App ──▶ Spreadsheet
```

Website **selalu jadi pemanggil**, tidak pernah jadi penerima. Itu kunci kenapa
skema ini lolos sementara webhook langsung ke InfinityFree gagal.

---

## Cara membuat

1. Buat Spreadsheet baru di Google Drive, beri nama misal `Boarding House — Data Kas`.
2. Buat **6 sheet** dengan nama **tepat** seperti di bawah (huruf kecil semua).
3. Baris 1 setiap sheet adalah **header**; tulis persis sesuai daftar kolom.
4. Catat **Spreadsheet ID** dari URL:
   `https://docs.google.com/spreadsheets/d/`**`<ID_INI>`**`/edit`

> Jangan mengubah nama sheet atau urutan kolom — kode Apps Script membacanya
> berdasarkan posisi kolom.

---

## Sheet 1 — `kas`

Sumber data utama transaksi kas. Diisi oleh bot WA **dan** oleh halaman admin website.

| Kol | Header | Isi | Contoh |
|-----|--------|-----|--------|
| A | `kode` | Kode unik transaksi | `KK-202608-0001` |
| B | `tanggal` | Tanggal transaksi (YYYY-MM-DD) | `2026-08-06` |
| C | `jenis` | `masuk` atau `keluar` | `keluar` |
| D | `kategori` | Kategori otomatis | `listrik` |
| E | `jumlah` | Nominal, angka murni tanpa titik | `150000` |
| F | `keterangan` | Keterangan bebas | `Token listrik Branch 1` |
| G | `kost_id` | ID cabang, kosong = umum | `1` |
| H | `kost` | Nama cabang (untuk dibaca manusia) | `Branch 1` |
| I | `sumber` | `wa` atau `web` | `wa` |
| J | `user_id` | ID admin pencatat | `1` |
| K | `pencatat` | Nama admin pencatat | `Admin Boarding House` |
| L | `bukti` | Nama berkas bukti (dari website) | *(kosong)* |
| M | `created_at` | Waktu pencatatan | `2026-08-06 09:14:22` |

**Prefix kode dipisah berdasarkan sumbernya:**

| Prefix | Dibuat oleh | Contoh |
|--------|-------------|--------|
| `KM` / `KK` | Website (MySQL) | `KK-202608-0001` |
| `WM` / `WK` | Bot WhatsApp (Spreadsheet) | `WK-202608-0001` |

Serinya sengaja dipisah. Kalau keduanya memakai `KM`/`KK`, transaksi lewat web dan
lewat WhatsApp yang terjadi bersamaan bisa menghasilkan nomor identik, dan
penarikan ke MySQL gagal karena kolom `kode` di sana bersifat UNIQUE.
Bonusnya: sumber transaksi langsung terbaca dari kodenya.

---

## Sheet 2 — `kost`

Daftar cabang kost. **Disinkron otomatis dari website**, jangan diedit manual.
Dipakai bot untuk mengenali "branch 3" / "b3" dari teks chat.

| Kol | Header | Contoh |
|-----|--------|--------|
| A | `id` | `1` |
| B | `nama` | `Branch 1` |

---

## Sheet 3 — `admin`

Daftar nomor WhatsApp yang berhak memakai perintah keuangan.
**Disinkron otomatis dari website** (diambil dari `users` yang `role = admin`).

| Kol | Header | Contoh |
|-----|--------|--------|
| A | `no_hp` | `628123456789` |
| B | `nama` | `Admin Boarding House` |
| C | `user_id` | `1` |

> Nomor disimpan format internasional tanpa `+` (08xx → 628xx), sama seperti
> `normalize_hp()` di website. Nomor di luar daftar ini akan ditolak bot.

---

## Sheet 4 — `ringkasan`

Angka-angka yang **hanya ada di MySQL** dan tidak bisa dihitung bot
(pemasukan sewa dari tabel `pembayaran`, piutang dari `tagihan`, hunian dari
`penghuni`/`kamar`). Website mendorong angka ini ke sini secara berkala supaya
perintah `LAPORAN` di WhatsApp tetap lengkap.

| Kol | Header | Isi | Contoh |
|-----|--------|-----|--------|
| A | `periode` | Bulan `YYYY-MM` | `2026-08` |
| B | `sewa` | Total pembayaran sewa terverifikasi bulan itu | `4500000` |
| C | `piutang` | Total tagihan belum lunas (global) | `1200000` |
| D | `huni` | Jumlah penghuni aktif | `12` |
| E | `kamar` | Jumlah kamar seluruhnya | `18` |
| F | `updated_at` | Kapan terakhir disinkron | `2026-08-06 09:00:01` |

Satu baris per periode. Website memperbarui baris periode berjalan.

---

## Sheet 5 — `config`

Pengaturan tampilan pesan bot. **Disinkron dari tabel `pengaturan` website**.

| Kol | Header | Contoh |
|-----|--------|--------|
| A | `k` | `nama_kost` |
| B | `v` | `Boarding House` |

Kunci yang dipakai bot: `nama_kost`, `wa_footer`, `bank_nama`, `bank_rek`,
`bank_an`, `base_url`.

---

## Sheet 6 — `log`

Riwayat aktivitas bot, untuk menelusuri masalah.

| Kol | Header | Isi |
|-----|--------|-----|
| A | `waktu` | Waktu pesan masuk |
| B | `pengirim` | Nomor pengirim |
| C | `nama` | Nama pengirim (dari Fonnte) |
| D | `masuk` | Isi pesan masuk |
| E | `balasan` | Isi balasan bot |
| F | `status` | `ok`, `ditolak`, `gagal_kirim`, dst |
| G | `catatan` | Pesan galat bila ada |

---

## Perintah lewat grup WhatsApp

Bot ikut menerima **semua** pesan di grup begitu nomornya jadi anggota — WhatsApp
tidak membatasi pengiriman pesan berdasarkan tag. Karena itu, di grup pesan
**harus diawali `///`**, contoh: `/// LAPORAN` atau bahasa bebas
`/// beli galon 2 biji 40rb branch 2`.

Setelah prefix `///` terdeteksi, sisa isinya diproses **persis seperti chat
pribadi** — perintah baku (`KELUAR`, `MASUK`, `LAPORAN`, `KAS`, `BATAL`,
`BANTUAN`), lalu bahasa bebas lewat **Groq AI** kalau bukan perintah baku,
lalu menu bantuan sebagai default. `///` dipilih justru karena nyaris
mustahil muncul di percakapan sehari-hari, jadi begitu prefix ini terdeteksi
sudah cukup jadi tanda pesan itu memang ditujukan ke bot.

**Soal pencatatan ke sheet `log`:** pesan yang **tidak** diawali `///`
(obrolan biasa anggota grup) diamkan total dan **tidak dicatat ke log sama
sekali** — supaya log tidak penuh chat yang tidak ada hubungannya dengan bot.
Pesan yang diawali `///` selalu dicatat, apa pun hasilnya.

⚠️ **Grup baru wajib disinkronkan dulu ke Fonnte** sebelum bot bisa membalas
di sana, kalau tidak balasannya akan gagal terkirim dengan galat
`"invalid group id"` meski nomor bot sudah jadi anggota grup itu:

```
POST https://api.fonnte.com/fetch-group
Header: Authorization: <token device>
(tanpa body)
```

Panggil **sekali saja** setiap kali bot ditambahkan ke grup baru — dokumentasi
Fonnte memperingatkan risiko ke arah pembatasan akun kalau API ini dipanggil
berulang tanpa perlu.

## Yang **TIDAK** disimpan di Spreadsheet

Rahasia disimpan di **Script Properties** Apps Script (bukan di Spreadsheet,
karena Spreadsheet bisa ikut ter-share):

| Properti | Isi |
|----------|-----|
| `SPREADSHEET_ID` | ID Spreadsheet ini |
| `FONNTE_TOKEN` | Token device Fonnte |
| `GROQ_API_KEY` | API key dari console.groq.com |
| `WEBHOOK_KEY` | Kata sandi untuk URL webhook (`?key=...`) |
| `API_KEY` | Kata sandi untuk API yang dipanggil website |
| `GROQ_MODEL` | *(opsional)* default `llama-3.3-70b-versatile` |

Cara mengisi: editor Apps Script → **Project Settings** → **Script properties**.

---

## Hubungan dengan website

MySQL tetap menjadi sumber utama yang dibaca halaman Buku Kas dan Laporan —
setiap panggilan ke Apps Script butuh 1–4 detik, jadi kalau tampilan membaca
langsung ke sana seluruh panel admin ikut lambat dan ikut mati saat Apps Script
bermasalah. Spreadsheet berperan sebagai cermin yang selalu disinkronkan:

| Arah | Kapan | Dikerjakan oleh |
|------|-------|-----------------|
| Website → Spreadsheet | tiap transaksi disimpan lewat web | `sheets_kas_dorong()` |
| Spreadsheet → Website | saat Buku Kas/Laporan dibuka (jeda 90 detik) & job harian | `sheets_maybe_tarik()` |
| Website → Spreadsheet | job harian & tombol Sinkron | `sheets_sync_meta()` |

Pengaturan di **Admin > Pengaturan > Google Spreadsheet**:
`sheets_aktif`, `sheets_url` (URL `/exec`), `sheets_key` (= `API_KEY`),
`sheets_id` (ID Spreadsheet).

Tabel `kas` mendapat kolom baru `sinkron` (0 = belum tercermin di Spreadsheet,
akan dicoba ulang otomatis). Jalankan `database/migrasi_spreadsheet.sql` sekali
di phpMyAdmin lokal **dan** live.

---

## Catatan penting soal SFTP

Berkas di folder `google/` ini **tidak perlu diunggah ke InfinityFree** —
isinya dokumentasi dan kode yang jalan di server Google, bukan di hosting.
Cukup simpan di komputer sebagai arsip proyek.
