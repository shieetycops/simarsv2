-- SIMARS v2: revisi disposisi SOP/AS/04 langkah 11-18 (rekomendasi vs keputusan final).
--
-- Latar: SOP/AS/04 PA Pasarwajo langkah 12-13 memisahkan dua keputusan:
--   1. Kasubag Umum: verifikasi, isi disposisi, beri REKOMENDASI rute (KEBIJAKAN/LANGSUNG) + alasan wajib.
--      Rekomendasi BUKAN keputusan final.
--   2. Sekretaris/Panitera: PUTUSKAN rute final (langkah 13). Boleh mengubah rekomendasi Kasubag.
--
-- Kolom baru di incoming_letters:
--   rekomendasi_route     = rekomendasi Kasubag (KEBIJAKAN/LANGSUNG), NULL = belum diberi
--   rekomendasi_route_by  = user_id pemberi rekomendasi
--   rekomendasi_route_at  = waktu rekomendasi
--   rekomendasi_notes     = alasan/catatan rekomendasi (wajib)
--   arahan_pimpinan       = isi arahan Ketua/WK (wajib saat KEBIJAKAN, langkah 14)
--   arahan_by             = user_id Ketua/WK pemberi arahan
--   arahan_at             = waktu arahan
--   unit_tujuan           = unit pelaksana tujuan (Kasubag/Panmud mana)
--   unit_tujuan_by        = user_id penentu unit
--   unit_tujuan_at        = waktu penentuan unit
--
-- Nilai sah unit_tujuan: KASUBAG_UMUM, KASUBAG_KEPEGAWAIAN, KASUBAG_PTIP,
--   PANMUD_PERMOHONAN, PANMUD_GUGATAN, PANMUD_HUKUM.
--
-- Q1 (CATATAN_ASUMSI_REVISI): tidak ada opsi "kembali revisi setelah arahan pimpinan".
--   Arahan Ketua/WK = final dan mengikat.
-- Q2: role pencatat surat untuk DIARSIPKAN = ARSIPARIS.
--
-- Jalankan setelah 2026_09_26_add_letter_assignee.sql.
-- Idempoten manual: cek kolom sebelum mengulang (MySQL lama tak punya ADD COLUMN IF NOT EXISTS).

ALTER TABLE incoming_letters
  ADD COLUMN rekomendasi_route VARCHAR(32) NULL,
  ADD COLUMN rekomendasi_route_by VARCHAR(36) NULL,
  ADD COLUMN rekomendasi_route_at DATETIME NULL,
  ADD COLUMN rekomendasi_notes TEXT NULL,
  ADD COLUMN arahan_pimpinan TEXT NULL,
  ADD COLUMN arahan_by VARCHAR(36) NULL,
  ADD COLUMN arahan_at DATETIME NULL,
  ADD COLUMN unit_tujuan VARCHAR(64) NULL,
  ADD COLUMN unit_tujuan_by VARCHAR(36) NULL,
  ADD COLUMN unit_tujuan_at DATETIME NULL;
