-- Tabel reservasi nomor surat keluar ("Ambil Nomor").
-- Dipakai untuk mengunci nomor yang sudah diambil orang lain sebelum suratnya
-- diregistrasi, supaya dua orang tidak mendapat nomor yang sama dan tidak
-- tubrukan dengan data import terbaru.
--
-- status:
--   DIPESAN  -> nomor sudah diambil, menunggu suratnya diregistrasi
--   TERBIT   -> surat dengan nomor ini sudah diregistrasi
--   BATAL    -> dibatalkan, nomor bebas dipakai lagi
--
-- Cara pakai: import file ini lewat phpMyAdmin / mysql CLI ke database SIMARS.
--   mysql -u <user> -p <dbname> < migrations/2026_08_05_add_outgoing_number_slots.sql

CREATE TABLE IF NOT EXISTS outgoing_number_slots (
  id            VARCHAR(36)  NOT NULL,
  issuing_unit  VARCHAR(50)  NOT NULL,
  letter_date   DATE         NOT NULL,
  sequence      INT          NOT NULL,
  suffix        VARCHAR(10)  NULL,
  kode          VARCHAR(255) NULL,
  letter_number VARCHAR(255) NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'DIPESAN',
  reserved_by   VARCHAR(36)  NOT NULL,
  reserved_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slots_letter_number (letter_number),
  KEY idx_slots_unit_date (issuing_unit, letter_date),
  CONSTRAINT fk_slots_user FOREIGN KEY (reserved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
