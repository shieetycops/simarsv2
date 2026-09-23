-- Tambah kolom issuing_unit ke outgoing_letters — supaya surat keluar bisa
-- dibedakan berdasarkan unit penerbit (PPK / Sekretaris / KPA), yang selama
-- ini dicatat di 3 buku agenda kertas terpisah dengan penomoran masing-masing
-- sendiri-sendiri. NULL-able karena data lama (kalau ada) belum punya nilai.
-- Eksekusi: import file ini via phpMyAdmin SEBELUM import data surat keluar.

ALTER TABLE outgoing_letters
  ADD COLUMN issuing_unit VARCHAR(50) NULL AFTER classification;
