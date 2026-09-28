-- SIMARS v2: rute keputusan Sekretaris/Panitera (SOP/AS/04 langkah 13).
--
-- Latar: SOP Penanganan Surat Masuk PA Pasarwajo menaruh keputusan
-- "surat ini perlu kebijakan Ketua/Wakil Ketua atau tidak" pada
-- Sekretaris/Panitera, bukan pada pimpinan. Tanpa kolom ini, tahap
-- DIDISPOSISIKAN tidak menyimpan APA keputusannya, sehingga:
--   - surat rute LANGSUNG bisa belakangan naik ke pimpinan, dan
--   - tidak ada bukti audit siapa yang memutuskan (KMA 131 BAB IV).
--
-- Nilai sah: 'KEBIJAKAN' (naik ke Ketua/WK) atau 'LANGSUNG' (ke unit pelaksana).
-- NULL = belum diputuskan (surat masih < MENUNGGU_DISPOSISI).
--
-- Jalankan pada database yang sudah menjalankan 2026_09_23_v2_workflow.sql.
-- Idempoten manual: cek kolom sebelum mengulang (MySQL lama tak punya
-- ADD COLUMN IF NOT EXISTS).

ALTER TABLE incoming_letters
  ADD COLUMN disposition_route VARCHAR(32) NULL,
  ADD COLUMN disposition_route_by VARCHAR(36) NULL,
  ADD COLUMN disposition_route_at DATETIME NULL;

-- Mempercepat widget "antrian keputusan" dashboard Sekretaris/Panitera.
ALTER TABLE incoming_letters
  ADD KEY idx_incoming_stage (current_stage);
