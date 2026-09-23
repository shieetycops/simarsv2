# SIMARS v2 — mode pembanding

Versi ini adalah salinan terisolasi dari aplikasi lama. Root versi lama tetap
dipertahankan di `D:\simars`; v2 berada di `D:\simars\v2`.

## Fitur v2 yang sudah tersedia

- Registrasi surat dengan sumber penerimaan, jenis Dinas/Pribadi, jenis naskah,
  empat level keamanan MA (`BIASA`, `TERBATAS`, `RAHASIA`, `SANGAT_RAHASIA`),
  kode klasifikasi arsip, dan status tahap `DITERIMA`.
- State machine 17 tahap dengan validasi transisi server-side; transisi ilegal
  ditolak HTTP 422, transisi tanpa role berwenang ditolak HTTP 403.
- Pemetaan role → aktor SOP (`V2Workflow::allowedRolesForTransition`): tahap
  administrasi persuratan (ADMIN/SEKRETARIS/PANITERA/KEPALA_SUB_UMUM), tahap
  pengarahan & disposisi (PIMPINAN/WAKIL_KETUA), tindak lanjut (STAFF dan
  pimpinan sub), pengarsipan (persuratan/panitera). Menunggu ratifikasi
  pemilik proses.
- Layar Buku Kendali `/v2/buku-kendali`: daftar naskah + filter, detail surat,
  tombol verifikasi alamat, checklist kelengkapan interaktif, tombol transisi
  sesuai role, timeline riwayat Buku Kendali dan riwayat pemeriksaan.
- API Buku Kendali:
  - `GET  /api/control` — daftar (surat rahasia difilter per role),
  - `GET  /api/control/stages` — kontrak tahap + level keamanan,
  - `GET  /api/control/classifications` — master kode arsip + 13 primer,
  - `GET  /api/control/:id` — detail + log + checklist + transisi yang sah,
  - `POST /api/control/:id/transition`,
  - `POST /api/control/:id/address-verification`,
  - `POST /api/control/:id/completeness`.
- Master kode klasifikasi arsip: 13 kategori primer resmi Lampiran I
  SK 627/2023 + kode sekunder/tersier HK yang tercantum eksplisit di
  ringkasan pedoman, disimpan di `archive_classifications` dengan
  `validation_status = OFFICIAL`. Kode pemakaian yang belum ada di master
  ditandai `PENDING_VALIDATION` (`archive_code_status` di surat) untuk
  divalidasi arsiparis; kode primer tidak resmi ditolak di API.
- Keamanan surat: WhatsApp otomatis dilewati untuk `RAHASIA`/`SANGAT_RAHASIA`,
  daftar surat & Buku Kendali memfilter rahasia per role
  (`V2Workflow::RAHASIA_ACCESS_ROLES`), dan tautan publik view-only ditolak
  (403) untuk surat rahasia meski token tautan valid.
- Tabel migrasi `archive_classifications`, `letter_control_logs`, dan
  `letter_completeness_checks`.
- Halaman pembanding di `/v2/surat-masuk`; menu versi lama tetap ada.

## Instalasi database v2

1. Buat database terpisah (contoh uji lokal: `simars_v2`).
2. Jalankan pada database tersebut, berurutan:
   - `api-php/schema.sql` (hanya bila database masih kosong),
   - `api-php/migrations/2026_09_23_v2_workflow.sql`,
   - `api-php/migrations/2026_09_23_v2_seed_classifications.sql`.
3. Arahkan `api-php/config.php` (salinan v2) ke database tersebut.
   Config v2 saat ini menunjuk `simars_v2` dan mematikan Turnstile untuk uji
   lokal — nyalakan kembali sebelum dipakai serius.
Jangan menjalankan migrasi ini pada database produksi versi lama tanpa backup
dan persetujuan administrator.

## Pengujian otomatis (lokal)

- `setup_test_db.php` — siapkan `simars_v2`: skema + migrasi + seed + 4 user
  uji (`admin_v2/admin123`, `sekre_v2/sekre123`, `pimpin_v2/pimpin123`,
  `staf_v2/staf123`).
- `api-php/router_dev.php` — router untuk `php -S`.
- `test_e2e.php` — 36 asersi end-to-end via HTTP nyata. Jalankan:
  `php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php` lalu
  `php test_e2e.php`. Hasil terakhir: **36 PASS / 0 FAIL** (login, kontrak
  workflow, registrasi BIASA & RAHASIA, penolakan kode arsip ilegal, transisi
  ilegal 422, verifikasi alamat, checklist lengkap/tidak, happy path
  DITERIMA→DIARSIPKAN dengan larangan role, filter rahasia untuk STAFF,
  penolakan tautan publik rahasia).

## Batasan yang masih harus diselesaikan

- Ratusan kode sekunder/tersier lengkap Lampiran I SK 627/2023 belum dimuat
  (sumber ringkasan hanya mencantumkan sebagian); kode pemakaian di luar seed
  ditandai `PENDING_VALIDATION` dan harus divalidasi arsiparis terhadap
  lampiran resmi sebelum mass assignment.
- Pemetaan role → aktor SOP masih draf dan wajib diratifikasi pemilik proses.
- Klasifikasi keamanan per kode arsip (Lampiran II) belum dipakai memaksa
  level keamanan surat; saat ini level ditetapkan petugas saat registrasi.
- Migrasi telah diuji pada database uji `simars_v2` (bukan produksi).