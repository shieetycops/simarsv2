# SIMARS v2 — Sistem Informasi Manajemen Arsip Surat

Aplikasi registrasi dan manajemen arsip **surat masuk & surat keluar** untuk lingkungan Pengadilan Agama, disusun mengikuti:

- **KMA 131/2023** — Tata Naskah Dinas dan Arsip di Lingkungan Mahkamah Agung
- **SK Sekretaris MA 627/2023** — Kode Klasifikasi Arsip

Versi ini (**v2**) adalah generasi baru aplikasi: registrasi surat dengan **state machine 17 tahap**, layar **Buku Kendali Naskah Dinas**, **4 level keamanan naskah**, **master kode klasifikasi arsip**, serta integrasi **WhatsApp** untuk notifikasi dan bot disposisi.

---

## Fitur Utama

- **Registrasi surat masuk v2** — nomor agenda otomatis, sumber penerimaan (POS/KURIR/EMAIL/FAX/INTERNAL/LAINNYA), jenis naskah (Surat Dinas, Memorandum, Nota Dinas, Undangan, dst.), jenis surat Dinas/Pribadi, level keamanan, dan kode klasifikasi arsip.
- **Buku Kendali Naskah Dinas** (`/v2/buku-kendali`) — daftar & filter naskah, detail surat, verifikasi alamat, checklist kelengkapan 6 butir, tombol transisi tahap sesuai role, timeline riwayat kendali & pemeriksaan.
- **State machine 17 tahap** dengan validasi transisi server-side: transisi ilegal ditolak `HTTP 422`, transisi tanpa wewenang ditolak `HTTP 403`.
- **4 level keamanan resmi KMA 131/2023**: `BIASA`, `TERBATAS`, `RAHASIA`, `SANGAT_RAHASIA` (sifat *penting/segera* dicatat terpisah di kolom urgensi, bukan level keamanan).
- **Master klasifikasi arsip** (Lampiran I SK 627/2023): kode resmi berstatus `OFFICIAL`, kode pemakaian di luar master ditandai `PENDING_VALIDATION` untuk validasi arsiparis; kode primer non-resmi ditolak API.
- **Keamanan naskah rahasia**: daftar surat & Buku Kendali difilter per role, WhatsApp otomatis dilewati, tautan publik view-only ditolak `403`.
- **Integrasi WhatsApp** — notifikasi grup surat masuk, DM langsung ke pimpinan (dengan lampiran view-only bertanda tangan HMAC), serta bot disposisi berbasis sesi chat.
- **Menu pendukung** — surat keluar, ambil nomor surat, disposisi, manajemen pengguna, laporan cetak, arsip digital, audit log, pengaturan instansi.

## Teknologi

| Lapisan | Teknologi |
|---|---|
| Frontend | React 19, TypeScript, Vite 6, Tailwind CSS 4, komponen shadcn/Radix, Recharts |
| Backend | PHP native (tanpa framework), PDO MySQL, upload berkas dengan hash & nama kanonik |
| Database | MySQL / MariaDB (skema `api-php/schema.sql` + migrasi bertahap) |
| Auth | Token opak 24 jam (disimpan sebagai hash), password bcrypt, Cloudflare Turnstile opsional |
| WhatsApp | Fonnte (token perangkat) — notifikasi grup, DM pimpinan, webhook bot |

## Arsitektur Singkat

```
Browser — React SPA (Vite dev server mem-proxy /api → 127.0.0.1:8011)
   │ JSON + Bearer token
   ▼
api-php/  (PHP native; .htaccess → index.php → lib/handlers/*.php)
   │ PDO
   ▼
MySQL  (schema.sql + migrations/)
   ▲ webhook ?k=<secret>          ▲ API Fonnte
Fonnte / WhatsApp Cloud          notifikasi grup & DM pimpinan
```

---

## Alur Eksisting (Mode v2)

### Peran pengguna

| Kelompok aktor | Role di aplikasi | Tugas dalam alur |
|---|---|---|
| Persuratan | `ADMIN`, `SEKRETARIS`, `PANITERA`, `KEPALA_SUB_UMUM` | Registrasi, verifikasi alamat, sortir, registrasi ulang, kirim ke pengarsipan |
| Pimpinan | `PIMPINAN`, `WAKIL_KETUA` | Membaca/mengarahkan surat, disposisi, kebijakan pimpinan |
| Panitera | `ADMIN`, `SEKRETARIS`, `PANITERA` | Registrasi→disposisi, memilih jalur terusan, pengarsipan akhir |
| Pelaksana | `STAFF`, `KEPALA_SUB_*`, `PANITERA_MUDA_*`, dll. | Tindak lanjut surat sampai selesai |

### 1. Registrasi — `/v2/surat-masuk`

Petugas persuratan (ADMIN/SEKRETARIS) mengisi: nomor agenda (otomatis `AGD/<tahun>/<seq>`), nomor & tanggal surat, tanggal terima, pengirim, perihal, sumber penerimaan, jenis naskah, jenis surat, **level keamanan**, **kode klasifikasi arsip**, catatan, dan berkas lampiran (opsional). Simpan → surat masuk pada tahap **DITERIMA**.

Efek otomatis saat registrasi (hanya untuk level BIASA/TERBATAS):

- notifikasi in-app ke seluruh PIMPINAN aktif,
- pesan WhatsApp ke **grup target**,
- **DM langsung** ke tiap pimpinan + lampiran view-only (`/surats/{id}?t=<token HMAC>`).

### 2. Buku Kendali — `/v2/buku-kendali`

Layar kerja utama alur v2. Untuk tiap naskah:

- **Verifikasi alamat tujuan** — catat SESAI / TIDAK SESAI (naskah salah alamat berhenti di tahap `SALAH_ALAMAT`).
- **Checklist kelengkapan** (6 butir): alamat, nomor, tanggal, perihal, lampiran, tanda tangan/stempel → status LENGKAP / TIDAK LENGKAP, tersimpan sebagai riwayat pemeriksaan.
- **Transisi tahap** — hanya tombol tahap yang sah untuk role Anda yang ditampilkan; server memvalidasi ulang.
- **Timeline** — riwayat Buku Kendali (aksi, aktor, catatan) dan riwayat pemeriksaan kelengkapan.

### 3. Alur 17 tahap dan pelakunya

| # | Tahap | Transisi lanjut | Pelaku |
|---|---|---|---|
| 1 | DITERIMA | → Verifikasi alamat / Disortir | Persuratan |
| 2 | VERIFIKASI ALAMAT | → Disortir / Salah alamat | Persuratan |
| 3 | SALAH ALAMAT | (titik berhenti) | — |
| 4 | DISORTIR | → Menunggu pengarahan / Terregistrasi | Persuratan |
| 5 | MENUNGGU PENGARAHAN | → Dibaca pengarah | Pimpinan |
| 6 | DIBACA PENGARAH | → Terregistrasi / Menunggu disposisi | Persuratan |
| 7 | TERREGISTRASI | → Menunggu disposisi / Didisposisikan | Panitera |
| 8 | MENUNGGU DISPOSISI | → Didisposisikan | Pimpinan |
| 9 | DIDISPOSISIKAN | → Ke Kasubag / Ke Sekretaris-Panitera / Menunggu kebijakan / Ke pelaksana | Panitera |
| 10 | DITERUSKAN KE KASUBAG UMUM | → Ke Sekretaris-Panitera / Menunggu kebijakan | Persuratan |
| 11 | DITERUSKAN KE SEKRETARIS-PANITERA | → Menunggu kebijakan / Ke pelaksana | Pelaksana |
| 12 | MENUNGGU KEBIJAKAN PIMPINAN | → Ke pelaksana | Pimpinan |
| 13 | DITERUSKAN KE PELAKSANA | → Dalam tindak lanjut | Pelaksana |
| 14 | DALAM TINDAK LANJUT | → Selesai ditindaklanjuti | Pelaksana |
| 15 | SELESAI DITINDAKLANJUTI | → Menunggu pengarsipan | Persuratan |
| 16 | MENUNGGU PENGARSIPAN | → Diarsipkan | Panitera |
| 17 | DIARSIPKAN | (arsip final) | — |

```
DITERIMA → VERIFIKASI ALAMAT → DISORTIR ─┬→ MENUNGGU PENGARAHAN → DIBACA PENGARAH ─┐
    │              │                     │      (pimpinan)                         │
    │              └→ SALAH ALAMAT       └→ TERREGISTRASI ←─────────────────────────┘
    │                                                    │
    │                                        MENUNGGU DISPOSISI → DIDISPOSISIKAN
    │        ┌───────────────────────┬───────────────────────┼──────────────┐
    │        ▼                       ▼                       ▼              ▼
    │  KE KASUBAG UMUM → KE SEKRIS/PANITERA → MENUNGGU KEBIJAKAN → KE PELAKSANA
    │   (persuratan)        (pelaksana)        PIMPINAN (pimpinan)     │
    │                                                                ▼
    └─ DISORTIR dapat langsung TERREGISTRASI     DALAM TINDAK LANJUT → SELESAI DITINDAKLANJUTI
                                                                    │ (persuratan)
                                                                    ▼
                                                        MENUNGGU PENGARSIPAN → DIARSIPKAN
```

### 4. Keamanan & klasifikasi

- **Level keamanan** menentukan akses: `RAHASIA`/`SANGAT_RAHASIA` hanya dapat dilihat `ADMIN`, `PIMPINAN`, `WAKIL_KETUA`, `SEKRETARIS`, `PANITERA`; tidak dikirim ke WhatsApp; tautan publik ditolak.
- **Kode klasifikasi arsip** wajib salah satu dari 13 kategori primer resmi (HK, HM, KA, KP, PL, PS, PW, RT, TI, DL, RA, KU, OT). Kode spesifik yang belum terdaftar tetap diterima tapi berstatus `PENDING_VALIDATION` dan wajib divalidasi arsiparis terhadap Lampiran I SK 627/2023.

### 5. Contoh alur konkret

Surat masuk dari **DPRD Kabupaten Buton** perihal *"Undangan Rapat Paripurna DPRD Kabupaten Buton"* yang bersifat **penting**:

1. `SEKRETARIS` registrasikan di `/v2/surat-masuk` — jenis naskah **UNDANGAN**, level keamanan **BIASA** ("penting" dicatat di catatan/urgensi, bukan level keamanan), kode arsip **HM** (Humas dan Protokol). Notifikasi + WA otomatis ke pimpinan.
2. Di Buku Kendali: verifikasi alamat **SESAI** → checklist kelengkapan **LENGKAP** → transisi DITERIMA → VERIFIKASI ALAMAT → DISORTIR → MENUNGGU PENGARAHAN.
3. `PIMPINAN` membaca (sudah dapat DM WA + lampiran) → DIBACA PENGARAH.
4. Persuratan: TERREGISTRASI → MENUNGGU DISPOSISI; `PIMPINAN` disposisi → DIDISPOSISIKAN.
5. Panitera teruskan → KE PELAKSANA; `STAFF` tindak lanjut → DALAM TINDAK LANJUT → SELESAI DITINDAKLANJUTI.
6. Persuratan kirim ke pengarsipan → `PANITERA` arsipkan → **DIARSIPKAN**.

### 6. Menu lain (tetap tersedia)

`/surat-masuk` (registrasi versi lama), `/surat-keluar`, `/ambil-nomor`, `/disposisi`, `/users`, `/laporan`, `/arsip`, `/audit`, `/pengaturan`, `/bot-whatsapp`. Mode v2 berjalan paralel di menu `/v2/...` tanpa mengubah data alur lama.

---

## Menjalankan secara Lokal

Prasyarat: **PHP ≥ 8.1** (ekstensi `pdo_mysql`), **Node.js ≥ 18**, **MySQL/MariaDB**.

1. **Siapkan database** — buat database kosong (contoh: `simars_v2`), lalu jalankan skrip sekali-jalan (skema + migrasi + seed klasifikasi + akun uji):

   ```bash
   # sesuaikan kredensial di api-php/config.php terlebih dahulu
   php setup_test_db.php
   ```

2. **Konfigurasi API** — salin `api-php/config.example.php` → `api-php/config.php`, isi `db_host/db_name/db_user/db_pass`. Untuk lokal, `turnstile_enabled` boleh `false`.

3. **Jalankan backend**:

   ```bash
   php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php
   ```

4. **Jalankan frontend**:

   ```bash
   npm install
   npm run dev     # buka http://localhost:5173
   ```

5. **Masuk dengan akun uji** (dibuat oleh `setup_test_db.php`):

| Username | Password | Role |
|---|---|---|
| `admin_v2` | `admin123` | ADMIN |
| `sekre_v2` | `sekre123` | SEKRETARIS |
| `pimpin_v2` | `pimpin123` | PIMPINAN |
| `staf_v2` | `staf123` | STAFF |

> ⚠️ Akun uji hanya untuk pengembangan. **Wajib ganti** sebelum dipakai nyata.

## Pengujian

```bash
cd api-php
composer install                 # phpunit (dev dependency)
vendor/bin/phpunit               # unit test: Auth, Disposition, Wabot, dst.

# dari root proyek (backend harus jalan di :8011)
php test_e2e.php                 # 36 asersi end-to-end alur v2 via HTTP
```

Tersedia juga pemeriksa mandiri di `api-php/tests/run_*_check.php` (auth, turnstile, upload, WA bot).

## Struktur Proyek

```
├── api-php/                # Backend PHP native
│   ├── index.php           # Entry + router
│   ├── router_dev.php      # Router untuk php -S (lokal)
│   ├── config.example.php  # Contoh konfigurasi (config.php di-gitignore)
│   ├── schema.sql          # Skema MySQL lengkap
│   ├── migrations/         # Migrasi bertahap (v2 workflow, seed klasifikasi, dll.)
│   ├── lib/                # Db, Auth, Upload, V2Workflow, Wabot, Whatsapp, handlers/
│   └── tests/              # PHPUnit + pemeriksa mandiri
├── src/                    # Frontend React (halaman, komponen fitur)
├── components/             # Komponen UI shadcn
├── lib/                    # Util frontend (AuthContext, v2Workflow, dsb.)
├── public/
├── scripts/                # Helper deploy (zip cPanel) & util pengembangan
├── docs/                   # DEPLOY.md, V2_IMPLEMENTATION.md, SOP, panduan
├── setup_test_db.php       # Setup DB lokal: skema + migrasi + seed + akun uji
├── test_e2e.php            # Uji end-to-end API v2
└── vite.config.ts
```

## Deployment (cPanel)

Paket zip siap upload dibuat dengan:

```powershell
powershell -File scripts\build_api_zip.ps1
powershell -File scripts\build_frontend_zip.ps1
```

Panduan lengkap (persiapan hosting, import MySQL di phpMyAdmin, pengaturan PHP) ada di **[docs/DEPLOY.md](docs/DEPLOY.md)**.

## Catatan Keamanan

- `api-php/config.php` **tidak pernah di-commit** (berisi kredensial DB & kunci rahasia).
- Untuk produksi wajib mengisi: `app_secret` (tanda tangan tautan lampiran view-only), `wabot_secret` (webhook WhatsApp), `cors_origin` (domain asli), dan menyalakan `turnstile_enabled`.
- Dump data asli (`*.sql` berisi data personal) sengaja tidak disertakan di repositori.

## Batasan yang Diketahui

- Pemetaan role → aktor SOP masih draf, wajib diratifikasi pemilik proses.
- Sebagian besar kode sekunder/tersier Lampiran I SK 627/2023 belum dimuat ke master (yang dipakai di luar seed berstatus `PENDING_VALIDATION`).
- Level keamanan per kode arsip (Lampiran II) belum dipakai memaksa level keamanan surat; dropdown urgensi di UI v2 belum tersedia (kolom `urgency_level` sudah ada di API/DB).

## Dokumentasi Lanjutan

- [docs/DEPLOY.md](docs/DEPLOY.md) — checklist deploy ke hosting cPanel
- [docs/V2_IMPLEMENTATION.md](docs/V2_IMPLEMENTATION.md) — rincian implementasi workflow v2 & hasil uji
- [docs/Ringkasan_SOP_04_Penanganan_Surat_Masuk.md](docs/Ringkasan_SOP_04_Penanganan_Surat_Masuk.md) — dasar SOP alur surat masuk
- [docs/Ringkasan_Tata_Naskah_Dinas_dan_Klasifikasi_Arsip_MA.md](docs/Ringkasan_Tata_Naskah_Dinas_dan_Klasifikasi_Arsip_MA.md) — dasar hukum & kode klasifikasi
- [docs/Panduan_Penggunaan_SIMARS.docx](docs/Panduan_Penggunaan_SIMARS.docx) — panduan pengguna akhir


