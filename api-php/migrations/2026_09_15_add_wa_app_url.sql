-- 2026_09_15_add_wa_app_url.sql
-- Kolom app_url di whatsapp_settings: URL publik aplikasi SIMARS (mis.
-- https://simars.example.gov.id). Dipakai helpers::appUrl() untuk membangun
-- link lampiran view-only (letterViewUrl => {appUrl}/surats/{id}) yang
-- dikirim WA ke PIMPINAN & pegawai. NULL/blank = fallback auto-detect dari
-- request/config. Upgrade aman: hanya menambah kolom nullable.
-- Idempotent: cek information_schema dulu supaya bisa dijalankan ulang
-- tanpa error #1060 Duplicate column name bila kolom sudah ada.
SET @col_exists := (
 SELECT COUNT(*)
 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
 AND TABLE_NAME = 'whatsapp_settings'
 AND COLUMN_NAME = 'app_url'
);
SET @ddl := IF(
 @col_exists = 0,
 'ALTER TABLE whatsapp_settings ADD COLUMN app_url VARCHAR(255) NULL AFTER wa_group_marker',
 'SELECT ''kolom app_url sudah ada, migrasi dilewati'' AS info'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;