-- Tambah kolom status ke incoming_letters — sudah ada di schema.sql (baris 44)
-- tapi belum pernah dijalankan ke database production (dibuat dari versi
-- schema.sql yang lebih lama). Tanpa ini, computeLetterStatus() di
-- helpers.php tidak bisa menyimpan status "SELESAI" untuk surat arsip lama
-- yang diinput tanpa disposisi.
-- Eksekusi: import file ini via phpMyAdmin SEBELUM import_surat_lama_2026.sql.
--
-- Aman untuk tabel yang sudah berisi data: kolom baru dapat DEFAULT
-- 'BELUM_DISPOSISI', jadi surat yang sudah ada (kalau ada) otomatis
-- konsisten dengan status yang dihitung computeLetterStatus() sekarang.

ALTER TABLE incoming_letters
  ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'BELUM_DISPOSISI' AFTER nature;
