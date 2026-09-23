-- 2026-09-09: Bot WA disposisi — kata kunci opsional untuk filter pesan grup.
-- Bila diisi (mis. "SIMARS"), webhook hanya memproses pesan dari grup yang
-- mengandung kata kunci tersebut. Nullable -> upgrade aman untuk data lama.
ALTER TABLE whatsapp_settings
    ADD COLUMN wa_group_marker VARCHAR(64) NULL AFTER fonnte_token;
