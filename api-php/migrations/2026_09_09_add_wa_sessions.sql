-- 2026-09-09: Bot WA v2 — sesi "buat disposisi" (pimpinan) & menu status (pegawai).
-- Satu sesi aktif per user (PRIMARY KEY user_id). kind:
--   LEADER  = pimpinan memilih penerima disposisi dari menu bernomor
--             (kedaluwarsa 60 MENIT dari perintah mulai — revisi dari 15 menit
--             agar pimpinan yang sedang sibuk tidak kehilangan sesi).
--   EMPLOYEE= penerima disposisi melaporkan status via menu 1/2
--             (7 HARI, otomatis diperpanjang setiap balasan, ditutup permanen
--             saat SELESAI, juga saat BATAL/kadaluarsa).
--   CLOSED  = sesi selesai (baris dibiarkan utk audit; boleh dibersihkan berkala).
-- context = JSON keadaan sesi:
--   LEADER  : {letterId, agenda, subject, users:[{id,name,role}...]}
--             (snapshot menu -> penomoran konsisten antara kiriman & balasan)
--   EMPLOYEE: {dispositionId, subject, instruction, deadline}
-- Pembersihan berkala (opsional):
--   DELETE FROM wa_sessions WHERE kind='CLOSED' OR expires_at < NOW() - INTERVAL 7 DAY;
CREATE TABLE IF NOT EXISTS wa_sessions (
 user_id VARCHAR(36) NOT NULL,
 kind VARCHAR(20) NOT NULL DEFAULT 'LEADER',
 step VARCHAR(40) NOT NULL DEFAULT 'PICK_TARGET',
 context TEXT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id),
 KEY idx_wa_sessions_expiry (expires_at),
 CONSTRAINT fk_wa_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
