-- FASE 1 — Tambah kolom paritas ke outgoing_letters
-- Eksekusi: import file ini via phpMyAdmin ke database SIMARS.
--
-- Catatan:
-- - agenda_number NULL-able karena data lama belum punya nilai.
-- - classification NULL-able karena data lama belum punya nilai.
-- - nature NOT NULL DEFAULT 'BIASA' supaya data lama otomatis terisi BIASA.
-- - Unique index di agenda_number aman dengan banyak NULL (MySQL mengizinkan).

ALTER TABLE outgoing_letters
  ADD COLUMN agenda_number VARCHAR(191) NULL AFTER id,
  ADD COLUMN classification VARCHAR(100) NULL AFTER subject,
  ADD COLUMN nature VARCHAR(50) NOT NULL DEFAULT 'BIASA' AFTER classification,
  ADD UNIQUE KEY outgoing_agenda_number_unique (agenda_number);
