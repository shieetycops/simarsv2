-- SIMARS v2 Fase 0: pelaksana surat (assignee) di Buku Kendali.
--
-- Latar: laporan tindak lanjut dari pelaksana (web/WA) selama ini hanya
-- mengubah baris dispositions, sedangkan incoming_letters.current_stage tidak
-- pernah bergerak. Akibatnya Buku Kendali selalu menampilkan surat "macet" di
-- DITERUSKAN_KE_PELAKSANA walau pekerjaannya sudah selesai, dan tidak ada kolom
-- yang bisa dipakai UI untuk menampilkan "siapa pelaksananya" tanpa membuka
-- seluruh riwayat disposisi.
--
-- Kolom:
--   assignee_user_id = pelaksana yang sedang ditunjuk untuk surat ini
--                      (diisi dari dispositions.to_user_id saat disposisi
--                      dibuat / saat surat diteruskan ke pelaksana).
--   assignee_set_at  = waktu penunjukan terakhir (audit).
--
-- Tahap berikutnya (Fase 1) memakai kolom ini untuk auto-advance:
--   laporan PROSES  -> DALAM_TINDAK_LANJUT
--   laporan SELESAI -> SELESAI_DITINDAKLANJUTI
--
-- Jalankan pada database yang sudah menjalankan 2026_09_23_v2_workflow.sql dan
-- 2026_09_24_add_disposition_route.sql.
-- Idempoten manual: cek kolom sebelum mengulang (MySQL lama tak punya
-- ADD COLUMN IF NOT EXISTS).

ALTER TABLE incoming_letters
  ADD COLUMN assignee_user_id VARCHAR(36) NULL,
  ADD COLUMN assignee_set_at DATETIME NULL;

-- Mempercepat kolom "Pelaksana" + filter "tugas saya" di Buku Kendali.
ALTER TABLE incoming_letters
  ADD KEY idx_incoming_assignee (assignee_user_id);

-- Backfill: surat yang sudah beredar memakai disposisi TERBARU sebagai pelaksana.
-- Hanya menyentuh baris yang belum punya pelaksana, jadi aman diulang.
UPDATE incoming_letters l
  JOIN (
    SELECT d.incoming_letter_id, d.to_user_id, d.updated_at
    FROM dispositions d
    JOIN (
      SELECT incoming_letter_id, MAX(created_at) AS mc
      FROM dispositions GROUP BY incoming_letter_id
    ) x ON x.incoming_letter_id = d.incoming_letter_id AND x.mc = d.created_at
  ) t ON t.incoming_letter_id = l.id
SET l.assignee_user_id = t.to_user_id,
    l.assignee_set_at  = t.updated_at
WHERE l.assignee_user_id IS NULL;
