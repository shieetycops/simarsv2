-- SIMARS v2: perbaikan ejaan nilai status verifikasi alamat.
--
-- Latar: sampai 2026-09-25 kode menulis nilai status yang salah eja,
-- 'ALAMAT_SESAI' dan 'ALAMAT_TIDAK_SESAI' (seharusnya "sesuai", bukan "sesai").
-- Nilai itu tersimpan di incoming_letters.address_status, ikut tertulis di
-- notes letter_control_logs, dan di details activity_logs. Kode sekarang
-- memakai 'ALAMAT_SESUAI' / 'ALAMAT_TIDAK_SESUAI'; migrasi ini menyelaraskan
-- data yang sudah ada.
--
-- Kolom address_status bertipe VARCHAR(32) tanpa constraint, jadi perbaikan
-- cukup dengan UPDATE (tidak perlu ALTER).
-- Jalankan pada database yang sudah menjalankan 2026_09_23_v2_workflow.sql.
-- Idempoten: setiap WHERE hanya cocok pada nilai ejaan lama, aman diulang.
--
-- Contoh: mysql -u root simars_v2 < api-php/migrations/2026_09_25_fix_address_status_typo.sql

UPDATE incoming_letters SET address_status = 'ALAMAT_SESUAI'
 WHERE address_status = 'ALAMAT_SESAI';

UPDATE incoming_letters SET address_status = 'ALAMAT_TIDAK_SESUAI'
 WHERE address_status = 'ALAMAT_TIDAK_SESAI';

-- Teks log kendali: "Alamat sesai." / "Alamat TIDAK sesai.".
UPDATE letter_control_logs SET notes = REPLACE(notes, 'Alamat sesai.', 'Alamat sesuai.')
 WHERE notes LIKE '%Alamat sesai.%';

UPDATE letter_control_logs SET notes = REPLACE(notes, 'Alamat TIDAK sesai.', 'Alamat TIDAK sesuai.')
 WHERE notes LIKE '%Alamat TIDAK sesai.%';

-- Jejak audit: logActivity(..., $status) menulis nilai status apa adanya.
UPDATE activity_logs SET details = 'ALAMAT_SESUAI'
 WHERE action = 'VERIFY_ADDRESS' AND details = 'ALAMAT_SESAI';

UPDATE activity_logs SET details = 'ALAMAT_TIDAK_SESUAI'
 WHERE action = 'VERIFY_ADDRESS' AND details = 'ALAMAT_TIDAK_SESAI';
