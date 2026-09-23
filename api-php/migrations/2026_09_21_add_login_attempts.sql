-- 2026_09_21_add_login_attempts.sql
-- Tabel riwayat percobaan login. Dipakai Auth::guardLoginAttempts() untuk
-- membatasi brute force: maks 5 kegagalan per username+IP dalam 15 menit.
--
-- Jalankan sekali di phpMyAdmin (tab SQL) pada database simars:
--   SOURCE migrations/2026_09_21_add_login_attempts.sql;
-- atau tempel isi berkas ini. Aman diulang (IF NOT EXISTS).
--
-- Selama tabel ini belum ada, pembatasan login otomatis dilewati sehingga
-- login tetap berjalan normal (lihat Auth::guardLoginAttempts).

CREATE TABLE IF NOT EXISTS login_attempts (
  id          VARCHAR(36) NOT NULL,
  username    VARCHAR(255) NOT NULL,
  ip_address  VARCHAR(64) NOT NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_lookup (username, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
