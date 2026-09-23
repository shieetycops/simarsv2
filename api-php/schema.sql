-- SIMARS — skema MySQL (terjemahan dari prisma/schema.prisma)
-- snake_case, InnoDB, utf8mb4. ID = VARCHAR(36) (bin2hex(random_bytes(16)) = 32 char).

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
  id            VARCHAR(36) NOT NULL,
  username      VARCHAR(100) NOT NULL,
  password      VARCHAR(255) NOT NULL,
  name          VARCHAR(255) NOT NULL,
  role          VARCHAR(50) NOT NULL DEFAULT 'STAFF',
  avatar        VARCHAR(255) NULL,
  wa_number     VARCHAR(50) NULL,
  supervisor_id VARCHAR(36) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_supervisor (supervisor_id),
  CONSTRAINT fk_users_supervisor FOREIGN KEY (supervisor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_tokens (
  token       VARCHAR(64) NOT NULL,
  user_id     VARCHAR(36) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NULL,
  PRIMARY KEY (token),
  KEY idx_auth_tokens_user (user_id),
  CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Riwayat percobaan login: dipakai Auth::guardLoginAttempts() untuk membatasi
-- brute force (maks 5 kegagalan per username+IP dalam 15 menit).
CREATE TABLE login_attempts (
  id          VARCHAR(36) NOT NULL,
  username    VARCHAR(255) NOT NULL,
  ip_address  VARCHAR(64) NOT NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_lookup (username, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE incoming_letters (
  id             VARCHAR(36) NOT NULL,
  agenda_number  VARCHAR(100) NOT NULL,
  letter_number  VARCHAR(255) NOT NULL,
  letter_date    DATETIME NOT NULL,
  received_date  DATETIME NOT NULL,
  sender         VARCHAR(255) NOT NULL,
  subject        VARCHAR(255) NOT NULL,
  classification VARCHAR(100) NOT NULL,
  nature         VARCHAR(50) NOT NULL DEFAULT 'BIASA',
  status         VARCHAR(50) NOT NULL DEFAULT 'BELUM_DISPOSISI',
  description    TEXT NULL,
  file_path      VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_incoming_agenda (agenda_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE outgoing_letters (
  id                  VARCHAR(36) NOT NULL,
  agenda_number       VARCHAR(191) NULL,
  letter_number       VARCHAR(255) NOT NULL,
  letter_date         DATETIME NOT NULL,
  destination         VARCHAR(255) NOT NULL,
  subject             VARCHAR(255) NOT NULL,
  signer              VARCHAR(255) NOT NULL,
  classification      VARCHAR(100) NULL,
  nature              VARCHAR(50) NOT NULL DEFAULT 'BIASA',
  reply_to_incoming_id VARCHAR(36) NULL,
  description         TEXT NULL,
  file_path           VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outgoing_number (letter_number),
  UNIQUE KEY outgoing_agenda_number_unique (agenda_number),
  KEY idx_outgoing_reply (reply_to_incoming_id),
  CONSTRAINT fk_outgoing_reply FOREIGN KEY (reply_to_incoming_id) REFERENCES incoming_letters (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE outgoing_number_slots (
  id            VARCHAR(36)  NOT NULL,
  issuing_unit  VARCHAR(50)  NOT NULL,
  letter_date   DATE         NOT NULL,
  sequence      INT          NOT NULL,
  suffix        VARCHAR(10)  NULL,
  kode          VARCHAR(255) NULL,
  letter_number VARCHAR(255) NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'DIPESAN',
  reserved_by   VARCHAR(36)  NOT NULL,
  reserved_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slots_letter_number (letter_number),
  KEY idx_slots_unit_date (issuing_unit, letter_date),
  CONSTRAINT fk_slots_user FOREIGN KEY (reserved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE dispositions (
  id                   VARCHAR(36) NOT NULL,
  incoming_letter_id   VARCHAR(36) NULL,
  outgoing_letter_id   VARCHAR(36) NULL,
  parent_disposition_id VARCHAR(36) NULL,
  from_user_id         VARCHAR(36) NOT NULL,
  to_user_id           VARCHAR(36) NOT NULL,
  notes                TEXT NULL,
  instruction          TEXT NULL,
  deadline             DATETIME NULL,
  status               VARCHAR(50) NOT NULL DEFAULT 'PENDING',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_disp_incoming (incoming_letter_id),
  KEY idx_disp_outgoing (outgoing_letter_id),
  KEY idx_disp_parent (parent_disposition_id),
  KEY idx_disp_from (from_user_id),
  KEY idx_disp_to (to_user_id),
  CONSTRAINT fk_disp_incoming FOREIGN KEY (incoming_letter_id) REFERENCES incoming_letters (id) ON DELETE CASCADE,
  CONSTRAINT fk_disp_outgoing FOREIGN KEY (outgoing_letter_id) REFERENCES outgoing_letters (id) ON DELETE SET NULL,
  CONSTRAINT fk_disp_parent FOREIGN KEY (parent_disposition_id) REFERENCES dispositions (id) ON DELETE SET NULL,
  CONSTRAINT fk_disp_from FOREIGN KEY (from_user_id) REFERENCES users (id),
  CONSTRAINT fk_disp_to FOREIGN KEY (to_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id         VARCHAR(36) NOT NULL,
  user_id    VARCHAR(36) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  message    TEXT NOT NULL,
  link       VARCHAR(255) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
  id                 VARCHAR(36) NOT NULL,
  file_name          VARCHAR(255) NOT NULL,
  file_path          VARCHAR(255) NOT NULL,
  file_size          INT NOT NULL,
  file_type          VARCHAR(100) NOT NULL,
  incoming_letter_id VARCHAR(36) NULL,
  outgoing_letter_id VARCHAR(36) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attach_incoming (incoming_letter_id),
  KEY idx_attach_outgoing (outgoing_letter_id),
  CONSTRAINT fk_attach_incoming FOREIGN KEY (incoming_letter_id) REFERENCES incoming_letters (id) ON DELETE CASCADE,
  CONSTRAINT fk_attach_outgoing FOREIGN KEY (outgoing_letter_id) REFERENCES outgoing_letters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_logs (
  id         VARCHAR(36) NOT NULL,
  user_id    VARCHAR(36) NOT NULL,
  action     VARCHAR(100) NOT NULL,
  entity     VARCHAR(100) NOT NULL,
  entity_id  VARCHAR(36) NULL,
  details    TEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_user (user_id),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE app_settings (
  id         VARCHAR(36) NOT NULL DEFAULT 'app_settings',
  name       VARCHAR(255) NOT NULL DEFAULT 'Pengadilan Agama Pasarwajo',
  short_name VARCHAR(100) NOT NULL DEFAULT 'PA Pasarwajo',
  address    VARCHAR(255) NOT NULL DEFAULT 'Jl. Poros Pasarwajo, Kab. Buton, Sulawesi Tenggara',
  phone      VARCHAR(50) NOT NULL DEFAULT '-',
  email      VARCHAR(100) NOT NULL DEFAULT '-',
  logo_url   VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE whatsapp_settings (
  id           VARCHAR(36) NOT NULL DEFAULT 'wa_settings',
  group_target VARCHAR(255) NULL,
  fonnte_token VARCHAR(255) NULL,
  wa_group_marker VARCHAR(64) NULL,
  app_url      VARCHAR(255) NULL,
  is_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wa_sessions (
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

SET FOREIGN_KEY_CHECKS = 1;
