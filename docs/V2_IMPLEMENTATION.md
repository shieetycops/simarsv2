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
  administrasi persuratan + **pengarahan surat** (ADMIN/SEKRETARIS/PANITERA/
  KEPALA_SUB_UMUM), **titik keputusan disposisi** `KEBIJAKAN`/`LANGSUNG`
  (Sekretaris/Panitera, SOP langkah 12-13), tindak lanjut (STAFF dan pimpinan
  sub), pengarsipan (persuratan/panitera). Pimpinan/Wakil Ketua tidak lagi
  memegang satu pun transisi v2 — kebijakan mereka masuk lewat tahap
  `MENUNGGU_KEBIJAKAN_PIMPINAN` yang diteruskan Sekretaris/Panitera. Menunggu
  ratifikasi pemilik proses.
- Titik keputusan disposisi: `POST /api/control/:id/transition` mewajibkan
  `dispositionRoute` (`KEBIJAKAN`/`LANGSUNG`) setiap kali surat masuk
  `DIDISPOSISIKAN` — termasuk jalur pintas dari `TERREGISTRASI` — menolak
  rute kosong/asing dengan 422 (`errors.field = dispositionRoute`), menyimpan
  `disposition_route`/`_by`/`_at` + log aksi `DISPOSITION_DECISION`, dan
  mengunci percabangan tepat sesudah `DIDISPOSISIKAN` sesuai rute.
- Layar Buku Kendali `/v2/buku-kendali`: daftar naskah + filter, detail surat,
  tombol verifikasi alamat, checklist kelengkapan interaktif, tombol transisi
  sesuai role, timeline riwayat Buku Kendali dan riwayat pemeriksaan.
- API Buku Kendali:
  - `GET  /api/control` — daftar (surat rahasia difilter per role),
  - `GET  /api/control/stages` — kontrak tahap + level keamanan,
  - `GET  /api/control/classifications` — master kode arsip + 13 primer,
  - `GET  /api/control/:id` — detail + log + checklist + transisi yang sah
    (`allowedTransitions` dengan penanda `requiresRoute`) + `dispositionRoute`
    + `ratificationNotice`,
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
- **Satu pintu surat masuk**: `/v2/surat-masuk` untuk registrasi (termasuk
  unggah scan) dan `/v2/buku-kendali` untuk pengelolaan; `/surat-masuk` lama
  dialihkan ke Buku Kendali. Halaman lama (`IncomingLetters.tsx`) sudah dihapus
  setelah fiturnya dipindahkan.
- Koreksi & hapus data surat dari Buku Kendali: `PUT /api/incoming/:id`
  (ADMIN/SEKRETARIS) dan `DELETE /api/incoming/:id` (ADMIN) hanya boleh selama
  surat **belum melewati keputusan disposisi dan belum punya disposisi**
  (`V2Workflow::CORRECTABLE_STAGES` + `letterAllowsCorrection()`), menolak dengan
  422 beserta alasannya; perubahan/penghapusan tercatat di audit log dan berkas
  lampiran lama ikut dibersihkan.
- Unggah lampiran: `POST /api/incoming` (registrasi) dan
  `POST /api/incoming/:id` + `_method=PUT` (ganti lampiran). Jalur `_method=PUT`
  ada karena PHP tidak memparsing body multipart pada request PUT.
- Layar Buku Kendali: filter (pencarian, tahap, keamanan, jenis surat, rentang
  tanggal terima), paginasi 10 baris, ekspor CSV (mengikuti filter aktif, BOM
  UTF-8, pemisah `;`), penanda ada lampiran, tautan buka lampiran.
- Tombol **Buka** di daftar membuka kartu Detail di bawah tabel, jadi kliknya
  wajib memberi umpan balik yang terlihat: indikator "Membuka detail surat…" +
  label tombol "Membuka…", banner status di atas tabel (selalu tampil walau kartu
  tidak dirender, mis. saat 403), gulir otomatis ke kartu (sekali per surat), dan
  baris yang sedang dibuka ditandai. Label tombol transisi memakai
  `V2_STAGE_LABELS` (bukan kunci tahap mentah dari API).

## Instalasi database v2

1. Buat database terpisah (contoh uji lokal: `simars_v2`).
2. Jalankan pada database tersebut, berurutan:
   - `api-php/schema.sql` (hanya bila database masih kosong),
   - `api-php/migrations/2026_09_23_v2_workflow.sql`,
   - `api-php/migrations/2026_09_23_v2_seed_classifications.sql`,
   - `api-php/migrations/2026_09_24_add_disposition_route.sql`,
   - `api-php/migrations/2026_09_25_fix_address_status_typo.sql` (perbaikan data
     status alamat yang salah eja `SESAI` → `SESUAI` pada database yang sudah
     menjalankan migrasi v2 sebelumnya).
3. Arahkan `api-php/config.php` (salinan v2) ke database tersebut.
   Config v2 saat ini menunjuk `simars_v2` dan mematikan Turnstile untuk uji
   lokal — nyalakan kembali sebelum dipakai serius.
Jangan menjalankan migrasi ini pada database produksi versi lama tanpa backup
dan persetujuan administrator.

Kondisi nyata kedua database (diperiksa read-only pada sesi ini):

| Database | Kolom v2 di `incoming_letters` | Surat | User |
| --- | --- | --- | --- |
| `simars_v2` (uji) | 5 dari 5 ada | 29 | 4 |
| `simars` (produksi lama) | **0 dari 5** | 121 | 28 |

Artinya: selama `api-php/config.php` masih menunjuk `simars`, registrasi v2 dan
Buku Kendali akan gagal (kolom `current_stage`/`security_level`/`archive_code`/
`source_channel`/`disposition_route` belum ada). Migrasi **wajib** dijalankan
lebih dulu pada database tujuan, setelah backup.

## Pengujian otomatis (lokal)

- `setup_test_db.php` — siapkan `simars_v2`: skema + migrasi + seed + 4 user
  uji (`admin_v2/admin123`, `sekre_v2/sekre123`, `pimpin_v2/pimpin123`,
  `staf_v2/staf123`).
- `api-php/router_dev.php` — router untuk `php -S`.
- `test_e2e.php` — 36 asersi end-to-end via HTTP nyata. Jalankan:
  `php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php` lalu
  `php test_e2e.php`. Hasil terakhir: **98 PASS / 0 FAIL** (login, kontrak
  workflow, registrasi BIASA & RAHASIA, penolakan kode arsip ilegal, transisi
  ilegal 422, verifikasi alamat, checklist lengkap/tidak, happy path
  DITERIMA→DIARSIPKAN dengan larangan role, filter rahasia untuk STAFF,
  penolakan tautan publik rahasia).
  Bagian 10 uji ini mencakup pemindahan fitur menu lama: koreksi (PUT) & hapus
  (DELETE) surat, batas tahap/role (422/403), unggah & ganti lampiran (keberadaan
  berkas di `uploads/` ikut diperiksa), validasi enum v2, dan aturan "surat yang
  sudah berdisposisi tidak boleh diubah/dihapus".
- PHPUnit (`cd api-php; php vendor/bin/phpunit`) — **60 uji, 292 asersi**.
  `tests/bootstrap.php` + `tests/WaStub.php` memastikan uji **tidak pernah**
  mengirim WhatsApp sungguhan (sebelumnya beberapa uji menembak api.fonnte.com).
  `LetterCorrectionRuleTest` menguji aturan koreksi vs disposisi dengan SQLite
  in-memory; `V2WorkflowTest` menguji `stageAllowsCorrection()` + kontrak enum.
- Type-check: `npx tsc --noEmit` (0 error). Lint PHP: `php -l` pada semua berkas
  yang diubah (tanpa error).

## Batasan yang masih harus diselesaikan

- Ratusan kode sekunder/tersier lengkap Lampiran I SK 627/2023 belum dimuat
  (sumber ringkasan hanya mencantumkan sebagian); kode pemakaian di luar seed
  ditandai `PENDING_VALIDATION` dan harus divalidasi arsiparis terhadap
  lampiran resmi sebelum mass assignment.
- Pemetaan role → aktor SOP masih draf dan wajib diratifikasi pemilik proses.
- Klasifikasi keamanan per kode arsip (Lampiran II) belum dipakai memaksa
  level keamanan surat; saat ini level ditetapkan petugas saat registrasi.
- Migrasi telah diuji pada database uji `simars_v2` (bukan produksi).
- Batas tahap koreksi/hapus (AS-9) memakai daftar
  `V2Workflow::CORRECTABLE_STAGES` dan **belum diratifikasi** pemilik proses.
  Yang belum disepakati: apakah surat di `MENUNGGU_DISPOSISI` (menunggu keputusan
  Sekretaris/Panitera) masih boleh dikoreksi — sekarang: ya, selama belum ada
  baris disposisi.
- Unggah lampiran dibatasi `Upload::MAX` (10 MB) di kode, tetapi `php.ini`
  lingkungan uji ini `upload_max_filesize=2M`; server produksi perlu dinaikkan
  (mis. 12M + `post_max_size` yang lebih besar) supaya lampiran besar tidak
  gagal tanpa penjelasan.
- Folder lampiran berada di `uploads/` pada root repo (bukan `api-php/uploads`).
  Saat deploy: folder harus ada, writable oleh PHP, tersaji web, dan berisi
  `.htaccess` pengaman (dibuat otomatis oleh `Upload::save`). Karena letaknya di
  luar document root server dev (`-t api-php`), `api-php/router_dev.php` yang
  menyajikan `/uploads/...` di lingkungan pengembangan (pola nama berkas dibatasi
  sama seperti `Upload::delete()`), dan `vite.config.ts` mem-proxy `/uploads` ke
  backend 8011 — tanpa keduanya tautan "Lihat lampiran scan" gagal di dev walau
  berkasnya ada.
- Status verifikasi alamat memakai nilai `ALAMAT_SESUAI` / `ALAMAT_TIDAK_SESUAI`.
  Sebelum 2026-09-25 nilainya salah eja (`ALAMAT_SESAI` / `ALAMAT_TIDAK_SESAI`)
  dan ditampilkan apa adanya ke pengguna. Nilai ini dikembalikan API apa adanya,
  jadi konsumen yang membandingkan stringnya harus ikut diperbarui; data lama
  diselaraskan oleh `api-php/migrations/2026_09_25_fix_address_status_typo.sql`.
- Tombol "Buat Disposisi" pimpinan tidak dipindahkan ke Buku Kendali: instruksi
  tetap dibuat dari menu `/disposisi` (`POST /api/dispositions`), sedangkan
  perpindahan tahap dilakukan di Buku Kendali. Ini pilihan sadar agar tidak ada
  dua jalur penulisan disposisi.
- Verifikasi dengan klik nyata di browser SUDAH dijalankan memakai Chrome
  headless 154: redirect `/surat-masuk` -> `/v2/buku-kendali`, tombol Buka
  (kartu detail tergulir ke layar: posisi puncak kartu 1137 px -> 80 px pada
  layar 805 px), label tombol transisi, dan tautan lampiran (PNG -> 200 dengan
  byte asli; lampiran hilang -> 404). Yang BELUM diuji lewat klik: dialog
  Koreksi/Hapus, unduh + isi CSV dari UI, pemilihan berkas pada input unggah,
  dan pratinjau PDF (Chrome headless tidak punya PDF viewer). Rincian bukti di
  `docs/CATATAN_ASUMSI_REVISI.md` bagian 7d-7e.