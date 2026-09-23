-- Seed master kode klasifikasi arsip resmi (SK Sekretaris MA No. 627/2023).
-- SUMBER: Ringkasan_Tata_Naskah_Dinas_dan_Klasifikasi_Arsip_MA.md (Lampiran I & II).
-- Hanya kode yang TERCANTUM di ringkasan yang diisi OFFICIAL. Ratusan kode
-- sekunder/tersier lainnya di Lampiran I tidak diarang di sini; kode pemakaian
-- yang belum terdaftar akan diberi status PENDING_VALIDATION oleh API dan
-- wajib divalidasi arsiparis terhadap lampiran resmi.

INSERT INTO archive_classifications
  (code, name, primary_code, secondary_code, tertiary_code, security_level, minimum_role, validation_status)
VALUES
  -- 13 kategori primer resmi (Lampiran I)
  ('HK', 'Hukum',                      'HK', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('HM', 'Humas dan Protokol',         'HM', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('KA', 'Kearsipan',                  'KA', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('KP', 'Kepegawaian',                'KP', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('PL', 'Perlengkapan',               'PL', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('PS', 'Perpustakaan',               'PS', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('PW', 'Pengawasan',                 'PW', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('RT', 'Rumah Tangga',               'RT', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('TI', 'Teknologi Informasi',        'TI', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('DL', 'Pendidikan dan Pelatihan',   'DL', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('RA', 'Perencanaan Anggaran',       'RA', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('KU', 'Keuangan',                   'KU', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  ('OT', 'Organisasi Tatalaksana',     'OT', NULL, NULL, 'BIASA',    NULL, 'OFFICIAL'),
  -- Kode sekunder/tersier kategori HK yang tercantum eksplisit di ringkasan
  ('HK1',     'Peraturan Perundang-undangan', 'HK', '1',     NULL,    'BIASA',    NULL,       'OFFICIAL'),
  ('HK1.1',   'Peraturan perundang-undangan eksternal', 'HK', '1.1',   NULL,    'TERBATAS', 'ESELON_IV', 'OFFICIAL'),
  ('HK1.1.1', 'UU/Perpu',                     'HK', '1.1',   '1.1.1', 'TERBATAS', 'ESELON_IV', 'OFFICIAL'),
  ('HK1.1.2', 'PP',                           'HK', '1.1',   '1.1.2', 'TERBATAS', 'ESELON_IV', 'OFFICIAL'),
  ('HK1.1.3', 'Perpres',                      'HK', '1.1',   '1.1.3', 'TERBATAS', 'ESELON_IV', 'OFFICIAL'),
  ('HK1.2',   'Peraturan perundang-undangan internal', 'HK', '1.2',   NULL,    'BIASA',    NULL,       'OFFICIAL'),
  ('HK2',     'Penyelesaian Perkara',         'HK', '2',     NULL,    'BIASA',    NULL,       'OFFICIAL'),
  ('HK2.1',   'Pidana Umum',                  'HK', '2',     '2.1',   'BIASA',    NULL,       'OFFICIAL'),
  ('HK2.2',   'Pidana Khusus',                'HK', '2',     '2.2',   'BIASA',    NULL,       'OFFICIAL'),
  ('HK2.3',   'Pidana Militer',               'HK', '2',     '2.3',   'BIASA',    NULL,       'OFFICIAL'),
  ('HK2.4',   'Perdata Umum',                 'HK', '2',     '2.4',   'BIASA',    NULL,       'OFFICIAL')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  primary_code = VALUES(primary_code),
  secondary_code = VALUES(secondary_code),
  tertiary_code = VALUES(tertiary_code),
  security_level = VALUES(security_level),
  minimum_role = VALUES(minimum_role),
  validation_status = VALUES(validation_status);
