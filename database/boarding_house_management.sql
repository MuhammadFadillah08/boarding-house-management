-- =====================================================================
--  BOARDING HOUSE MANAGEMENT - Skema Database (v2: multi-kost, login via WhatsApp)
--  MySQL 5.7+ / MariaDB 10.4+  (kompatibel XAMPP & InfinityFree)
--  Import via phpMyAdmin:  Import > pilih file ini > Go
-- =====================================================================
--  CATATAN INFINITYFREE:
--  Di InfinityFree nama database dipaksa jadi "if0_xxxxxxx_boarding_house_management".
--  Maka HAPUS / lewati 2 baris CREATE DATABASE + USE di bawah ini,
--  lalu import isinya ke database yang sudah dibuat di control panel.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `boarding_house_management`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `boarding_house_management`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. USERS  (admin & penyewa)
--    Penyewa mendaftar cukup dengan: nama, no. WhatsApp, kata sandi.
--    No. WhatsApp = identitas login. Email opsional (boleh NULL).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(100) NOT NULL,
  `no_hp`      VARCHAR(20)  NOT NULL COMMENT 'format 62xxxxxxxxxx - dipakai untuk login & WA',
  `email`      VARCHAR(120) DEFAULT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `no_ktp`     VARCHAR(30)  DEFAULT NULL,
  `alamat`     TEXT         DEFAULT NULL,
  `role`       ENUM('admin','user') NOT NULL DEFAULT 'user',
  `status`     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_hp` (`no_hp`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. KOST  (cabang / gedung kost)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `kost`;
CREATE TABLE `kost` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(80)  NOT NULL,
  `alamat`     VARCHAR(255) DEFAULT NULL,
  `tipe`       ENUM('campur','putra','putri') NOT NULL DEFAULT 'campur',
  `deskripsi`  TEXT DEFAULT NULL,
  `fasilitas`  TEXT DEFAULT NULL COMMENT 'fasilitas bersama, pisahkan dengan koma',
  `foto`       VARCHAR(255) DEFAULT NULL,
  `warna`      VARCHAR(20)  NOT NULL DEFAULT 'sage' COMMENT 'aksen kartu: sage|terra|mist|butter',
  `status`     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `urutan`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kost_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. KAMAR
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `kamar`;
CREATE TABLE `kamar` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kost_id`    INT UNSIGNED NOT NULL,
  `kode`       VARCHAR(20)  NOT NULL,
  `tipe`       VARCHAR(60)  NOT NULL DEFAULT 'Standard',
  `lantai`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `harga`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `fasilitas`  TEXT DEFAULT NULL COMMENT 'pisahkan dengan koma',
  `deskripsi`  TEXT DEFAULT NULL,
  `foto`       VARCHAR(255) DEFAULT NULL,
  `status`     ENUM('tersedia','dibooking','terisi','perbaikan') NOT NULL DEFAULT 'tersedia',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kamar_kode` (`kode`),
  KEY `idx_kamar_status` (`status`),
  KEY `fk_kamar_kost` (`kost_id`),
  CONSTRAINT `fk_kamar_kost` FOREIGN KEY (`kost_id`) REFERENCES `kost` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. BOOKING  (pengajuan sewa dari user)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `booking`;
CREATE TABLE `booking` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`       VARCHAR(25) NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `kamar_id`   INT UNSIGNED NOT NULL,
  `tgl_masuk`  DATE NOT NULL,
  `durasi`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'bulan',
  `catatan`    TEXT DEFAULT NULL,
  `status`     ENUM('pending','disetujui','ditolak','batal') NOT NULL DEFAULT 'pending',
  `alasan_tolak` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_kode` (`kode`),
  KEY `fk_booking_user` (`user_id`),
  KEY `fk_booking_kamar` (`kamar_id`),
  KEY `idx_booking_status` (`status`),
  CONSTRAINT `fk_booking_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_kamar` FOREIGN KEY (`kamar_id`) REFERENCES `kamar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PENGHUNI  (booking yang disetujui = kontrak aktif)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `penghuni`;
CREATE TABLE `penghuni` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `kamar_id`   INT UNSIGNED NOT NULL,
  `booking_id` INT UNSIGNED DEFAULT NULL,
  `tgl_masuk`  DATE NOT NULL,
  `tgl_keluar` DATE DEFAULT NULL,
  `tgl_tagih`  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'tanggal jatuh tempo tiap bulan',
  `status`     ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_penghuni_user` (`user_id`),
  KEY `fk_penghuni_kamar` (`kamar_id`),
  KEY `idx_penghuni_status` (`status`),
  CONSTRAINT `fk_penghuni_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_penghuni_kamar` FOREIGN KEY (`kamar_id`) REFERENCES `kamar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. TAGIHAN
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tagihan`;
CREATE TABLE `tagihan` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`        VARCHAR(25) NOT NULL,
  `penghuni_id` INT UNSIGNED NOT NULL,
  `periode`     CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `jumlah`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `denda`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `jatuh_tempo` DATE NOT NULL,
  `status`      ENUM('belum_bayar','menunggu_verifikasi','lunas','terlambat') NOT NULL DEFAULT 'belum_bayar',
  `tgl_lunas`   DATETIME DEFAULT NULL,
  `keterangan`  VARCHAR(255) DEFAULT NULL,
  `reminder_at` DATE DEFAULT NULL COMMENT 'tanggal terakhir reminder WA dikirim',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tagihan_kode` (`kode`),
  UNIQUE KEY `uq_tagihan_periode` (`penghuni_id`,`periode`),
  KEY `idx_tagihan_status` (`status`),
  KEY `idx_tagihan_tempo` (`jatuh_tempo`),
  CONSTRAINT `fk_tagihan_penghuni` FOREIGN KEY (`penghuni_id`) REFERENCES `penghuni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. PEMBAYARAN  (bukti transfer yang diupload user)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `pembayaran`;
CREATE TABLE `pembayaran` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`        VARCHAR(25) NOT NULL,
  `tagihan_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `jumlah`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `metode`      ENUM('transfer','tunai','ewallet') NOT NULL DEFAULT 'transfer',
  `tgl_bayar`   DATE NOT NULL,
  `bukti`       VARCHAR(255) DEFAULT NULL,
  `catatan`     VARCHAR(255) DEFAULT NULL,
  `status`      ENUM('pending','diterima','ditolak') NOT NULL DEFAULT 'pending',
  `verif_by`    INT UNSIGNED DEFAULT NULL,
  `verif_at`    DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bayar_kode` (`kode`),
  KEY `fk_bayar_tagihan` (`tagihan_id`),
  KEY `fk_bayar_user` (`user_id`),
  KEY `idx_bayar_status` (`status`),
  CONSTRAINT `fk_bayar_tagihan` FOREIGN KEY (`tagihan_id`) REFERENCES `tagihan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bayar_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. KAS  (pemasukan lain-lain & pengeluaran kost)
--    Dapat diisi lewat website MAUPUN chat WhatsApp ke bot oleh admin.
--    Pemasukan sewa TIDAK dicatat di sini (sudah ada di tabel pembayaran).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `kas`;
CREATE TABLE `kas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`       VARCHAR(25) NOT NULL,
  `kost_id`    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = umum / semua cabang',
  `jenis`      ENUM('masuk','keluar') NOT NULL,
  `kategori`   VARCHAR(40) NOT NULL DEFAULT 'lainnya',
  `jumlah`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `tanggal`    DATE NOT NULL,
  `sumber`     ENUM('web','wa') NOT NULL DEFAULT 'web',
  `user_id`    INT UNSIGNED DEFAULT NULL COMMENT 'admin yang mencatat',
  `bukti`      VARCHAR(255) DEFAULT NULL,
  `sinkron`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = sudah ada di Google Spreadsheet',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kas_kode` (`kode`),
  KEY `idx_kas_tanggal` (`tanggal`),
  KEY `idx_kas_jenis` (`jenis`),
  KEY `fk_kas_kost` (`kost_id`),
  CONSTRAINT `fk_kas_kost` FOREIGN KEY (`kost_id`) REFERENCES `kost` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kas_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. WA_LOG  (antrean & riwayat pesan WhatsApp)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `wa_log`;
CREATE TABLE `wa_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tujuan`     VARCHAR(25) NOT NULL,
  `nama`       VARCHAR(100) DEFAULT NULL,
  `jenis`      VARCHAR(40) NOT NULL DEFAULT 'umum',
  `pesan`      TEXT NOT NULL,
  `arah`       ENUM('keluar','masuk') NOT NULL DEFAULT 'keluar',
  `status`     ENUM('antre','terkirim','gagal') NOT NULL DEFAULT 'antre',
  `response`   TEXT DEFAULT NULL,
  `ref_id`     INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_status` (`status`),
  KEY `idx_wa_jenis` (`jenis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. HELPDESK  (kontak WhatsApp yang tampil di tombol bantuan)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `helpdesk`;
CREATE TABLE `helpdesk` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(60)  NOT NULL,
  `peran`      VARCHAR(60)  DEFAULT NULL COMMENT 'mis. Admin Branch 1, Teknisi',
  `no_hp`      VARCHAR(20)  NOT NULL,
  `jam`        VARCHAR(60)  DEFAULT NULL COMMENT 'mis. 08.00 - 21.00',
  `pesan_awal` VARCHAR(255) DEFAULT NULL COMMENT 'teks otomatis saat chat dibuka',
  `urutan`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status`     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_helpdesk_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. LOGIN_GAGAL  (pembatas percobaan masuk / anti tebak kata sandi)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_gagal`;
CREATE TABLE `login_gagal` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`        VARCHAR(45) NOT NULL,
  `identitas` VARCHAR(40) DEFAULT NULL,
  `waktu`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lg_ip` (`ip`,`waktu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. PENGATURAN  (key-value)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `pengaturan`;
CREATE TABLE `pengaturan` (
  `k` VARCHAR(50) NOT NULL,
  `v` TEXT DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  DATA AWAL
-- =====================================================================

-- Admin: login pakai no. WA 628123456789 (atau email), sandi: admin123
-- Penyewa contoh: login pakai no. WA 628987654321, sandi: user123
INSERT INTO `users` (`nama`,`no_hp`,`email`,`password`,`role`) VALUES
('Admin Boarding House','628123456789','admin@example.com','$2y$10$QzqxQSXwEEkiO5CtkmscTOJqLzXALiQsso8DTfb9EPHRg49Cl0iZy','admin'),
('Penyewa Contoh','628987654321',NULL,'$2y$10$GKZbWKCfcGm0.LHL.XeBIO9QFE7kVfjj10p/CQJNTZWCgrZI/4cdm','user');

-- 4 cabang kost -------------------------------------------------------
INSERT INTO `kost` (`id`,`nama`,`alamat`,`tipe`,`deskripsi`,`fasilitas`,`warna`,`urutan`) VALUES
(1,'Branch 1','Jl. Contoh No. 12, Kota Contoh','putri',
 'Cabang pertama dan paling dekat pusat kota. Lingkungan tenang dengan induk semang yang tinggal di lokasi.',
 'Dapur Bersama, Ruang Tamu, Parkir Motor, CCTV, Wifi 50 Mbps, Laundry','sage',1),
(2,'Branch 2','Jl. Contoh No. 24, Kota Contoh','putra',
 'Khusus putra, bersebelahan dengan Branch 1. Cocok untuk pekerja dan mahasiswa.',
 'Dapur Bersama, Parkir Mobil & Motor, CCTV, Wifi 50 Mbps, Air Panas','mist',2),
(3,'Branch 3','Jl. Melati No. 8, Kota Contoh','campur',
 'Dekat area kampus, hanya 5 menit berjalan kaki. Tersedia ruang belajar bersama.',
 'Ruang Belajar, Dapur Bersama, Parkir Motor, CCTV, Wifi 100 Mbps','butter',3),
(4,'Branch 4','Jl. Kenanga No. 3, Kota Contoh','campur',
 'Cabang terbaru dengan konsep eksklusif. Seluruh kamar sudah AC dan kamar mandi dalam.',
 'Lift, Rooftop, Dapur Modern, Parkir Luas, CCTV 24 Jam, Wifi 100 Mbps, Cleaning Service','terra',4);

-- Kamar: Branch 1 = 12, Branch 2 = 7, Branch 3 = 24, Branch 4 = 6  (total 49)
INSERT INTO `kamar` (`kost_id`,`kode`,`tipe`,`lantai`,`harga`,`fasilitas`,`deskripsi`) VALUES
-- ---------- Branch 1 (12 kamar, putri) ----------
(1,'B1-01','Standard',1, 800000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar menghadap taman depan, cahaya matahari pagi melimpah.'),
(1,'B1-02','Standard',1, 800000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar dekat dapur bersama, praktis untuk yang suka memasak.'),
(1,'B1-03','Standard',1, 800000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar tenang di sisi timur bangunan.'),
(1,'B1-04','Standard',1, 850000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Sedikit lebih luas dengan jendela ganda.'),
(1,'B1-05','Standard',1, 850000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Dilengkapi rak dinding tambahan.'),
(1,'B1-06','Deluxe',  2,1100000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Kamar mandi dalam dengan shower dan water heater.'),
(1,'B1-07','Deluxe',  2,1100000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Posisi pojok, lebih privat dan tenang.'),
(1,'B1-08','Deluxe',  2,1100000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Menghadap halaman belakang yang asri.'),
(1,'B1-09','Deluxe',  2,1150000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Dilengkapi meja kerja besar untuk yang bekerja dari kamar.'),
(1,'B1-10','Deluxe',  2,1150000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Kasur spring bed dengan lemari dua pintu.'),
(1,'B1-11','Premium', 3,1400000,'Spring Bed, Lemari, AC, Water Heater, TV, Kamar Mandi Dalam, Wifi','Lengkap dengan TV dan balkon kecil.'),
(1,'B1-12','Premium', 3,1400000,'Spring Bed, Lemari, AC, Water Heater, TV, Kamar Mandi Dalam, Wifi','Kamar terbaik di Branch 1, paling luas dan terang.'),
-- ---------- Branch 2 (7 kamar, putra) ----------
(2,'B2-01','Standard',1, 750000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar ekonomis di lantai dasar, dekat parkir motor.'),
(2,'B2-02','Standard',1, 750000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar hemat dengan ventilasi silang yang baik.'),
(2,'B2-03','Standard',1, 780000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Berada di ujung lorong, jauh dari keramaian.'),
(2,'B2-04','Deluxe',  2,1000000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Kamar mandi dalam, cocok untuk pekerja kantoran.'),
(2,'B2-05','Deluxe',  2,1000000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Menghadap halaman belakang yang asri.'),
(2,'B2-06','Deluxe',  2,1050000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Kasur spring bed dengan lemari dua pintu.'),
(2,'B2-07','Premium', 2,1300000,'Spring Bed, Lemari, AC, Water Heater, TV, Kamar Mandi Dalam, Wifi','Kamar terluas di Branch 2 dengan area santai.'),
-- ---------- Branch 3 (24 kamar, campur, dekat kampus) ----------
(3,'B3-01','Standard',1, 900000,'Kasur, Lemari, Meja Belajar, Wifi','Paling dekat dengan ruang belajar bersama.'),
(3,'B3-02','Standard',1, 900000,'Kasur, Lemari, Meja Belajar, Wifi','Kamar tenang, ideal untuk mahasiswa tingkat akhir.'),
(3,'B3-03','Standard',1, 900000,'Kasur, Lemari, Meja Belajar, Wifi','Dekat pintu utama, mudah dijangkau.'),
(3,'B3-04','Standard',1, 900000,'Kasur, Lemari, Meja Belajar, Wifi','Menghadap koridor hijau dengan tanaman.'),
(3,'B3-05','Standard',1, 950000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Dilengkapi rak buku tambahan.'),
(3,'B3-06','Standard',1, 950000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Sirkulasi udara baik sepanjang hari.'),
(3,'B3-07','Standard',1, 950000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Kamar sudut dengan dua jendela.'),
(3,'B3-08','Standard',1, 950000,'Kasur, Lemari, Meja Belajar, Kipas Angin, Wifi','Dekat area jemuran, praktis untuk mencuci.'),
(3,'B3-09','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Lantai 2 dengan pemandangan taman kampus.'),
(3,'B3-10','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Kamar mandi dalam dengan shower.'),
(3,'B3-11','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Tenang karena jauh dari tangga.'),
(3,'B3-12','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Wifi','Menghadap jalan, banyak cahaya alami.'),
(3,'B3-13','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Meja kerja lebar dan stopkontak berlimpah.'),
(3,'B3-14','Deluxe',  2,1250000,'Kasur, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Cocok untuk mahasiswa yang sering mengerjakan tugas.'),
(3,'B3-15','Deluxe',  2,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Kamar sudut dengan dua jendela besar.'),
(3,'B3-16','Deluxe',  2,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Kasur spring bed, lemari tiga pintu.'),
(3,'B3-17','Deluxe',  3,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Lantai 3, paling tenang di seluruh gedung.'),
(3,'B3-18','Deluxe',  3,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Pemandangan kota dari jendela.'),
(3,'B3-19','Deluxe',  3,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Meja kerja menghadap jendela.'),
(3,'B3-20','Deluxe',  3,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Dekat area santai lantai 3.'),
(3,'B3-21','Premium', 3,1600000,'Spring Bed, Lemari, AC, Water Heater, TV, Kulkas Mini, Wifi','Kamar premium lengkap dengan kulkas mini.'),
(3,'B3-22','Premium', 3,1600000,'Spring Bed, Lemari, AC, Water Heater, TV, Kulkas Mini, Wifi','Dilengkapi TV dan meja rias.'),
(3,'B3-23','Premium', 3,1600000,'Spring Bed, Lemari, AC, Water Heater, TV, Kulkas Mini, Wifi','Kamar luas dengan area kerja terpisah.'),
(3,'B3-24','Premium', 3,1650000,'Spring Bed, Lemari, AC, Water Heater, Smart TV, Kulkas Mini, Wifi','Unit terbaik Branch 3, sudut dengan balkon.'),
-- ---------- Branch 4 (6 kamar, eksklusif) ----------
(4,'B4-01','Deluxe',  1,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Seluruh kamar Branch 4 sudah AC dan kamar mandi dalam.'),
(4,'B4-02','Deluxe',  1,1300000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Wifi','Akses langsung ke dapur modern bersama.'),
(4,'B4-03','Deluxe',  2,1400000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Dilengkapi meja kerja ergonomis.'),
(4,'B4-04','Deluxe',  2,1400000,'Spring Bed, Lemari, AC, Kamar Mandi Dalam, Meja Kerja, Wifi','Dekat lift, praktis untuk naik turun.'),
(4,'B4-05','Premium', 3,1750000,'Spring Bed, Lemari, AC, Water Heater, TV, Kulkas Mini, Wifi','Akses rooftop, cocok untuk yang suka bersantai sore.'),
(4,'B4-06','Suite',   3,2200000,'King Bed, Walk-in Closet, AC, Water Heater, Smart TV, Kulkas, Sofa, Wifi','Unit terbaik: kamar suite dengan ruang tamu pribadi.');

INSERT INTO `helpdesk` (`nama`,`peran`,`no_hp`,`jam`,`pesan_awal`,`urutan`) VALUES
('Ibu Pengelola','Pengelola Utama','628123456789','08.00 - 21.00 WIB',
 'Halo Boarding House, saya ingin bertanya tentang ',1),
('Pak Teknisi','Teknisi & Perbaikan','628123456780','09.00 - 17.00 WIB',
 'Halo, saya ingin melaporkan kendala di kamar ',2);

INSERT INTO `pengaturan` (`k`,`v`) VALUES
('nama_kost','Boarding House'),
('helpdesk_aktif','1'),
('helpdesk_judul','Butuh Bantuan?'),
('helpdesk_teks','Chat dengan Kami'),
('helpdesk_sapaan','Tim kami siap membantu Anda. Pilih kontak di bawah ini untuk mulai mengobrol lewat WhatsApp.'),
('tagline','Hunian nyaman rasa rumah'),
('alamat','Jl. Contoh No. 12, Kota Contoh'),
('wa_admin','628123456789'),
('bank_nama','BCA'),
('bank_rek','1234567890'),
('bank_an','Yayasan Contoh'),
('denda_per_hari','5000'),
('reminder_h','3'),
('wa_driver','log'),
('wa_token',''),
('wa_endpoint','https://api.fonnte.com/send'),
('wa_footer','\n\n_Pesan otomatis dari sistem Boarding House. Mohon tidak membalas pesan ini._'),
('cron_key','ganti-dengan-kunci-acak'),
('sheets_aktif','0'),
('sheets_url',''),
('sheets_key',''),
('sheets_id',''),
('sheets_last_sync',''),
('sheets_last_pull',''),
('last_daily_run','');
