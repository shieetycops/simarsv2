-- SIMARS v2: pengendalian naskah dinas, klasifikasi arsip, dan buku kendali.
-- Jalankan setelah schema.sql. Semua ALTER bersifat idempoten secara manual:
-- cek kolom/tabel sebelum mengulang di hosting yang tidak mendukung IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS archive_classifications (
  code VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  primary_code VARCHAR(16) NOT NULL,
  secondary_code VARCHAR(16) NULL,
  tertiary_code VARCHAR(16) NULL,
  security_level VARCHAR(32) NOT NULL DEFAULT 'BIASA',
  minimum_role VARCHAR(64) NULL,
  -- OFFICIAL = kode tercantum di Lampiran SK 627/2023 yang sudah divalidasi;
  -- PENDING_VALIDATION = entri pemakaian yang menunggu verifikasi arsiparis.
  validation_status VARCHAR(32) NOT NULL DEFAULT 'PENDING_VALIDATION',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (code),
  KEY idx_archive_primary (primary_code),
  KEY idx_archive_security (security_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE incoming_letters
  ADD COLUMN source_channel VARCHAR(32) NULL,
  ADD COLUMN letter_category VARCHAR(32) NULL,
  ADD COLUMN document_type VARCHAR(64) NULL,
  ADD COLUMN security_level VARCHAR(32) NOT NULL DEFAULT 'BIASA',
  ADD COLUMN urgency_level VARCHAR(32) NOT NULL DEFAULT 'NORMAL',
  ADD COLUMN archive_code VARCHAR(32) NULL,
  ADD COLUMN archive_code_status VARCHAR(32) NULL,
  ADD COLUMN address_status VARCHAR(32) NOT NULL DEFAULT 'PERLU_VERIFIKASI',
  ADD COLUMN address_checked_by VARCHAR(36) NULL,
  ADD COLUMN address_checked_at DATETIME NULL,
  ADD COLUMN address_notes TEXT NULL,
  ADD COLUMN completeness_status VARCHAR(32) NOT NULL DEFAULT 'BELUM_DIPERIKSA',
  ADD COLUMN completeness_checked_by VARCHAR(36) NULL,
  ADD COLUMN completeness_checked_at DATETIME NULL,
  ADD COLUMN completeness_notes TEXT NULL,
  ADD COLUMN current_stage VARCHAR(64) NOT NULL DEFAULT 'DITERIMA',
  ADD COLUMN registered_at DATETIME NULL,
  ADD COLUMN archived_at DATETIME NULL,
  ADD COLUMN archived_by VARCHAR(36) NULL,
  ADD COLUMN canonical_file_name VARCHAR(255) NULL,
  ADD COLUMN file_hash VARCHAR(128) NULL;

CREATE TABLE IF NOT EXISTS letter_control_logs (
  id VARCHAR(36) NOT NULL,
  incoming_letter_id VARCHAR(36) NOT NULL,
  actor_user_id VARCHAR(36) NOT NULL,
  from_stage VARCHAR(64) NULL,
  to_stage VARCHAR(64) NOT NULL,
  action VARCHAR(64) NOT NULL,
  notes TEXT NULL,
  evidence_file VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_control_letter (incoming_letter_id, created_at),
  CONSTRAINT fk_control_letter FOREIGN KEY (incoming_letter_id) REFERENCES incoming_letters(id) ON DELETE CASCADE,
  CONSTRAINT fk_control_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS letter_completeness_checks (
  id VARCHAR(36) NOT NULL,
  incoming_letter_id VARCHAR(36) NOT NULL,
  checked_by VARCHAR(36) NOT NULL,
  address_correct TINYINT(1) NOT NULL DEFAULT 0,
  number_present TINYINT(1) NOT NULL DEFAULT 0,
  date_present TINYINT(1) NOT NULL DEFAULT 0,
  subject_present TINYINT(1) NOT NULL DEFAULT 0,
  attachment_complete TINYINT(1) NOT NULL DEFAULT 0,
  signature_present TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_check_letter (incoming_letter_id),
  CONSTRAINT fk_check_letter FOREIGN KEY (incoming_letter_id) REFERENCES incoming_letters(id) ON DELETE CASCADE,
  CONSTRAINT fk_check_actor FOREIGN KEY (checked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
