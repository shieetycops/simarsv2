-- SIMARS v2: patch revisi kedua alur disposisi SOP/AS/04 (temuan P1-P8).
--
-- Prinsip: SEMUA aturan "keputusan sementara" (K3, K6, K7, P1) disimpan
-- sebagai KONFIGURASI di tabel workflow_settings (bukan hardcode), supaya
-- mudah diubah tanpa membongkar kode saat pimpinan meratifikasi/merevisi.
-- Label di docs/CATATAN_ASUMSI_REVISI.md: "DEFAULT SEMENTARA - menunggu
-- review pimpinan".
--
-- Kolom konfigurasi (workflow_settings, satu baris id='wf_settings'):
--   tolak_reason_min          = K3: panjang minimal alasan TOLAK/RECALL (default 10)
--   wa_arahan_rahasia_blocked = K6: tolak perintah ARAHAN via WA untuk surat
--                                RAHASIA/SANGAT_RAHASIA (default 1 = aktif)
--   lembar1_wajib             = K7: wajibkan serah-terima lembar 1 disposisi
--                                sebelum DIARSIPKAN (default 0 = mati)
--   wa_stage_number_reply     = P1: balasan angka untuk menu aksi tahap
--                                (default 0 = NONAKTIF: angka tidak terikat
--                                agenda tertentu; hanya kata kunci + agenda)
--
-- Kolom baru di incoming_letters (K7, SOP/AS/04 langkah 17 — serah-terima
-- lembar 1 disposisi; dicatat, TIDAK wajib sebelum ARSIP kecuali toggle
-- lembar1_wajib dinyalakan):
--   lembar1_diserahkan_oleh = user_id penyerah lembar 1 (umumnya pelaksana)
--   lembar1_diterima_oleh   = user_id penerima (umumnya Arsiparis)
--   lembar1_diserahkan_at   = waktu serah-terima
--
-- Jalankan setelah 2026_09_28_revisi_disposisi_sop.sql.
-- Idempoten manual: cek kolom sebelum mengulang (MySQL lama tak punya
-- ADD COLUMN IF NOT EXISTS); tabel workflow_settings memakai IF NOT EXISTS
-- + INSERT IGNORE sehingga aman diulang.

CREATE TABLE IF NOT EXISTS workflow_settings (
  id                        VARCHAR(36) NOT NULL DEFAULT 'wf_settings',
  tolak_reason_min          INT NOT NULL DEFAULT 10,
  wa_arahan_rahasia_blocked TINYINT(1) NOT NULL DEFAULT 1,
  lembar1_wajib             TINYINT(1) NOT NULL DEFAULT 0,
  wa_stage_number_reply     TINYINT(1) NOT NULL DEFAULT 0,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO workflow_settings (id) VALUES ('wf_settings');

ALTER TABLE incoming_letters
  ADD COLUMN lembar1_diserahkan_oleh VARCHAR(36) NULL,
  ADD COLUMN lembar1_diterima_oleh VARCHAR(36) NULL,
  ADD COLUMN lembar1_diserahkan_at DATETIME NULL;
