# Deploy SIMARS (PHP + MySQL, shared hosting)

Checklist migrasi dari stack lama (Express/SQLite) ke PHP native + MySQL.

## 1. Build frontend
```bash
npm run build
```
Upload **isi** folder `dist/` ke document root subdomain (mis. `public_html/simars/`).

## 2. Database MySQL
1. Buat database + user MySQL di cPanel.
2. Buka phpMyAdmin, pilih database itu, import berurutan:
   - `api-php/schema.sql`  (struktur tabel)
   - `api-php/migrations/2026_08_05_add_outgoing_number_slots.sql`  (tabel slot "Ambil Nomor"; sudah ada di schema.sql, `IF NOT EXISTS` aman diimport dua kali)
   - `migration-data.sql`  (data — hasil langkah 3)
   - `api-php/import_surat_keluar_2026.sql`  (data arsip surat keluar 2026, opsional)

## 3. Export data lama (lokal)
```bash
node scripts/export-migration.mjs   # baca prisma/dev.db -> migration-data.sql
```
`fonnte_token` sengaja kosong (Baileys tak punya) — diisi di langkah 5.

## 4. Upload backend
- Upload folder `api-php/` sebagai folder `api/` di document root
  (`.../simars/api/`). `.htaccess` mengarahkan semua request ke `index.php`.
- Upload folder `uploads/` lama ke document root (`.../simars/uploads/`).

## 5. Konfigurasi
- Salin `api/config.example.php` -> `api/config.php`, isi kredensial DB.
- Isi Fonnte: buka aplikasi -> Pengaturan WhatsApp. `Device Token` didapat dari
  dashboard Fonnte (fonnte.com) setelah connect nomor WA di sana. Isi juga
  `Group Target` (ID grup) lalu klik **Kirim Pesan Tes**.
  Isi juga Kata Kunci Bot Grup bila grup dipakai untuk percakapan lain (bagian 9).
- Isi `api/config.php` untuk dua kunci keamanan. Keduanya **buat sendiri**, dan
  **bukan** token Fonnte (token Fonnte diisi lewat menu Pengaturan WhatsApp):
  ```php
  // Tanda tangan tautan lampiran view-only (/surats/{id}?t=...).
  // String acak panjang: php -r "echo bin2hex(random_bytes(32));"
  'app_secret' => '<64 karakter hex acak>',
  // Kunci webhook: URL webhook di Fonnte harus memuat ?k=<nilai ini>,
  // mis. https://<domain>/api/wabot?k=<nilai ini>
  'wabot_secret' => '<string acak lain>',
  ```
  - `app_secret` kosong -> tautan view-only bisa dibuka siapa pun yang memegang
    URL-nya. Kalau kosong, sistem memakai `turnstile_secret_key` sebagai cadangan.
  - `wabot_secret` kosong -> `/api/wabot` menerima request dari mana pun, sehingga
    siapa pun yang tahu nomor WA terdaftar bisa memalsukan `sender` dan menyamar
    sebagai pegawai. Isi ini lalu perbarui Webhook URL di Fonnte.
  Isi juga Kata Kunci Bot Grup bila grup dipakai untuk percakapan lain (lihat bagian 9).

> **JANGAN upload `api/config.php` dari komputer lokal.** File lokal berisi
> `db_user => 'root'` + password kosong; kalau tertimpa, seluruh API mati dengan
> `Access denied for user 'root'@'localhost'`. Upload hanya file di luar
> `config.php` (paket zip di root repo sudah mengecualikannya).

### Versi PHP yang dibutuhkan

Kode ini jalan di **PHP 7.4 sampai 8.4** — tidak ada satu pun sintaks PHP 8 yang
tidak bisa di-polyfill, dan bersih dari peringatan 8.2/8.3/8.4 (properti dinamis,
nullable implisit, konstanta bertipe). Jadi **PHP 8.0 aman**; menurunkan ke 7.4
tidak diperlukan.

Pilih versi di cPanel -> **MultiPHP Manager** -> pilih versi **untuk domain ini
saja**. Pengaturan di situ bersifat per-domain, jadi domain lain di akun yang sama
tidak ikut berubah.

Yang perlu dipertimbangkan justru umur versinya:

| Versi | Dukungan keamanan berakhir | Catatan |
|---|---|---|
| 7.4 | Nov 2022 | sudah EOL — hindari |
| 8.0 | Nov 2023 | sudah EOL |
| 8.1 | Des 2025 | sudah EOL |
| 8.2 | Des 2026 | masih didukung |
| 8.3 / 8.4 | Des 2027 / 2028 | paling aman |

Saran: pakai **8.2 atau 8.3** kalau hosting menyediakannya. Kalau hanya tersedia
7.4 dan 8.0, pakai **8.0** — sama-sama EOL, tapi 8.0 lebih baru.

Yang lebih penting daripada nomor versinya: **ekstensi `pdo_mysql` harus aktif di
versi yang benar-benar dipakai domain ini.** Di cPanel ekstensi diatur *per versi
PHP*, jadi mengaktifkan `pdo_mysql` di versi 8.1 tidak berpengaruh apa pun kalau
domain Anda jalan di 8.0. Gejalanya `could not find driver` — lihat bagian di
bawah.

`api/lib/compat.php` menyediakan polyfill `str_contains`/`str_starts_with`/
`str_ends_with` (fungsi bawaan PHP 8) supaya kode tetap jalan di 7.4. Sintaks
`match()` sudah tidak dipakai lagi karena itu PHP 8 dan tidak bisa di-polyfill.

`run_php_compat_check.php` memindai **dua kelompok berkas**: kode produksi
(`bootstrap.php`, `index.php`, `lib/*.php`, `lib/handlers/*.php`) dan skrip
diagnostik mandiri di `tests/` (`check_*.php`, `run_*.php`) — keduanya dijalankan
langsung di hosting, jadi sintaks PHP 8 di salah satunya sama-sama berakibat
Parse error. Berkas `tests/*Test.php` dikecualikan karena itu PHPUnit dan tidak
pernah dijalankan di hosting.

Gejala kalau versi PHP terlalu tua — **API balas 500** dan log menampilkan:

```
Parse error: syntax error, unexpected '=>' (T_DOUBLE_ARROW) in .../api/lib/Disposition.php
```

Itu bukan bug kode, melainkan berkas `api/lib/*.php` versi lama yang belum
tertimpa (atau hosting masih PHP < 7.4). Uji cepat dari server:

```bash
php api/tests/check_php.php              # versi PHP + ekstensi yang dimuat
php api/tests/run_php_compat_check.php   # 21 pemeriksaan kompatibilitas
```

`check_php.php` mencetak versi PHP yang melayani request, daftar ekstensi yang
dimuat, dan baris **"Versi untuk cPanel"** (mis. `8.0`) yang bisa langsung
dicocokkan dengan dropdown di *Select PHP Version*.

### Situs tampil blank putih (halaman kosong)

HTML terkirim tapi berkas JS-nya tidak. Penyebab tersering: isi folder
`dist/assets/` belum ikut ter-upload (atau baru sebagian), sedangkan
`index.html` sudah menunjuk nama berkas ber-hash yang baru.

Karena `.htaccess` mengarahkan request berkas yang tidak ada ke `index.html`,
permintaan `/assets/index-xxxx.js` yang hilang dijawab dengan HTML — browser
menolak mengeksekusinya (MIME `text/html`, bukan JavaScript) dan halaman jadi
kosong tanpa pesan.

1. Buka DevTools -> **Network**, muat ulang. Berkas JS yang bermasalah akan
   tampak berstatus 200 tapi bertipe `text/html` (atau 404).
2. Bandingkan nama berkas di `dist/index.html` dengan isi `dist/assets/`:
   ketiganya (`index-*.js`, `vendor-*.js`, `index-*.css`) harus ada di server.
3. Upload ulang **seluruh isi** `dist/` — termasuk folder `assets/` (49 berkas).
4. Kalau domain di belakang Cloudflare, **Purge Cache** setelah upload:
   `index.html` lama yang masih ter-cache menunjuk nama berkas yang sudah hilang.

### Logo tampil rusak (ikon gambar pecah) padahal API normal

Bukan masalah berkas logo dan bukan masalah versi PHP. `app_settings.logo_url`
di database berisi URL **domain lain** (mis. `https://i.ibb.co.com/...`),
sedangkan `dist/.htaccess` memasang header CSP dengan daftar putih
`img-src 'self' data: blob:`. Browser menolak memuat gambar dari host di luar
daftar itu, sehingga yang muncul ikon gambar rusak.

Bukti di DevTools -> **Console**:

```
Refused to load the image 'https://i.ibb.co.com/...' because it violates the
following Content Security Policy directive: "img-src 'self' data: blob:"
```

Logo dipakai di tiga tempat: halaman login (`App.tsx`), header sidebar
(`AppLayout.tsx`), dan kop cetak surat (`LetterView.tsx`) â€” ketiganya memakai
`settings.logoUrl` yang sama.

> **Jangan tertukar dengan halaman login yang tampak normal.** Kalau
> `/api/settings` gagal (mis. `pdo_mysql` mati), `src/lib/useSettings.ts` jatuh
> ke `defaultSettings` yang ber-`logoUrl: null`, lalu `App.tsx` menggambar
> kotak hijau berisi huruf **"S"**. Itu *placeholder*, bukan logo instansi â€”
> artinya logo tidak muncul sama sekali, bukan "berhasil tampil".

Dua cara memperbaiki:

**Cara 1 â€” pindahkan logo ke domain sendiri (disarankan).**
1. Simpan berkas logo ke document root, mis. `.../simars/logo.png`.
2. Buka aplikasi -> **Pengaturan** -> **URL Logo**, isi `/logo.png`
   (path relatif, jadi tidak ikut berubah bila domain berganti).
3. `img-src 'self'` sudah mengizinkan, jadi CSP tetap ketat dan logo tidak
   bergantung pada layanan pihak ketiga.

**Cara 2 â€” izinkan host logo di CSP.** Tambahkan host-nya ke `img-src` di
`public/.htaccess` **dan** `dist/.htaccess` (keduanya harus sama, karena
`dist/` adalah hasil build dari `public/`), lalu upload ulang `.htaccess`:

```apache
Header always set Content-Security-Policy "... img-src 'self' data: blob: https://i.ibb.co.com; ..."
```

Perubahan CSP **tidak** butuh build ulang â€” cukup upload `.htaccess` lalu
hard-refresh (Ctrl+F5), karena browser men-cache header bersama halaman.

> Bila URL logo diganti ke host lain lagi, `img-src` harus ikut diperbarui.
> Itulah alasan Cara 1 lebih tahan lama.

### Kalau pesan error memuat "could not find driver"

Ini **bukan** masalah kredensial. Ekstensi `pdo_mysql` belum aktif, jadi
`db_user`/`db_pass` belum sempat diuji sama sekali — mengganti password tidak
akan berpengaruh apa pun.

Gejala: `{"message":"Koneksi database gagal. ... [0] could not find driver ..."}`.
Perhatikan kode **`[0]`**: kode PDO `0` berarti driver tidak ditemukan, bukan
kredensial salah. Kredensial yang salah selalu memberi kode `1045`/`1044`.

Perbaikan: cPanel -> **Select PHP Version** -> tab **Extensions** -> centang
**pdo_mysql** (biasanya perlu juga **pdo** dan **mysqlnd**) -> **Save**.

**Kalau sudah dicentang tapi tetap error:** hampir pasti versinya tidak cocok.
Ekstensi di cPanel diatur *per versi PHP*. Lihat dropdown versi di bagian atas
halaman *Select PHP Version* — harus sama dengan versi yang dipakai domain ini
(bandingkan dengan baris `Versi untuk cPanel` dari `check_php.php`).
Mengaktifkan `pdo_mysql` di 8.1 tidak berpengaruh kalau domain jalan di 8.0.

Kalau cPanel Anda **hanya** punya *MultiPHP Manager* tanpa tab *Extensions*,
ekstensi tidak bisa diaktifkan sendiri — hubungi hosting dan minta `pdo_mysql`
+ `mysqlnd` diaktifkan untuk versi PHP yang dipakai.

Uji cepat dari server:
```bash
php api/tests/check_php.php   # versi PHP, ekstensi termuat, php.ini, kesimpulan
```
Atau:
```bash
php -r "echo implode(', ', PDO::getAvailableDrivers()), PHP_EOL;"
```
Harus memuat `mysql`. Kalau hasilnya kosong atau tanpa `mysql`, ekstensinya
belum aktif. `api/tests/check_php.php` paling lengkap: ia menyebut versi PHP
yang melayani request dan dari `php.ini` mana, sehingga ketahuan apakah
masalahnya versi salah atau `mysqlnd` yang dimatikan. `api/tests/check_db.php`
juga memeriksa ini di langkah paling awal dan berhenti dengan pesan yang jelas.

### Kalau API balas 500 / "Koneksi database gagal"

Pesan itu sengaja tidak menyebut penyebab teknisnya. Ada dua cara melihat error
MySQL yang sebenarnya:

**Cara 1 — dari browser (tanpa akses CLI).** Set `'debug' => true` di
`api/config.php`, lalu buka `https://<domain>/api/settings`. Respons JSON akan
memuat kode + pesan driver, mis. `[1045] Access denied for user ...`.
**Set kembali ke `false` setelah selesai** — pesan itu membocorkan nama user & DB.

**Cara 2 — dari server (lebih lengkap).**
```bash
php api/tests/check_db.php
```
Skrip ini menjalankan tiga uji berurutan dan menerjemahkan kode error MySQL
menjadi langkah perbaikan konkret:

| Kode | Arti | Perbaikan di cPanel |
|---|---|---|
| `0` | `could not find driver` — ekstensi `pdo_mysql` belum aktif | **Select PHP Version** -> *Extensions* -> centang `pdo_mysql` |
| `1045` | Password/user salah, atau user belum dibuat | **MySQL Databases** -> *Current Users* -> *Change Password* |
| `1044` | User & password benar, tapi belum diberi akses ke DB itu | **MySQL Databases** -> **Add User To Database** -> pilih user + db -> ALL PRIVILEGES |
| `1049` | Nama database tidak ada | Uji 3 mencetak daftar database yang benar — samakan `db_name` |
| `2002` | `db_host` tidak bisa dihubungi | Ganti ke `localhost` |
| `2005` | Nama host MySQL tidak dikenal | Ganti ke `localhost` |

> **`1045` vs `1044` adalah pembeda yang paling sering salah didiagnosis.**
> Keduanya berbunyi "Access denied", tapi `1045` diperbaiki di *Change Password*
> sedangkan `1044` di *Add User To Database*. Uji 2 memisahkannya: kalau koneksi
> **tanpa** `db_name` berhasil, berarti user & password sudah benar dan
> masalahnya murni izin akses database.

Bila Uji 1 gagal, Uji 3 mencetak semua database yang bisa dilihat user tersebut —
pakai daftar itu untuk memastikan `db_name` yang benar:

```
--- Uji 3: database yang terlihat oleh user ini ---
  information_schema
  k9030796_simars   <-- cocok dengan db_name
```

1. Ambil kredensial benar dari cPanel -> **MySQL Databases** (user & nama DB
   berprefix akun, mis. `k9030796_simars`), lalu isi `api/config.php`:
   ```php
   'db_host' => 'localhost',
   'db_name' => 'k9030796_simars',
   'db_user' => 'k9030796_simars',
   'db_pass' => '<password user DB>',
   ```
2. Pastikan user DB sudah di-**Add User To Database** dengan ALL PRIVILEGES.
3. Kalau tabel kosong, impor `api/schema.sql` lewat phpMyAdmin lebih dulu.

### Cloudflare Turnstile (verifikasi keamanan di halaman login)

Widget Turnstile muncul di form login; token-nya diverifikasi backend ke
`challenges.cloudflare.com/turnstile/v0/siteverify` sebelum kredensial dicek.

1. Daftarkan domain di dashboard Cloudflare -> **Turnstile** -> site key
   (hostname `simars.pa-pasarwajo.go.id`). Tanpa ini widget gagal dimuat.
2. Isi `api/config.php`:
   ```php
   'turnstile_secret_key' => '<secret key dari dashboard>',
   'turnstile_enabled'    => true,
   ```
3. File yang WAJIB ikut ter-upload (kalau tertinggal, login balas 500):
   `api/lib/helpers.php`, `api/lib/Auth.php`, `api/lib/handlers/auth.php`,
   `api/index.php`, `api/config.php`. Tambahan dari paket terbaru:
   `api/lib/compat.php` (polyfill PHP 7.4) dan `api/lib/Upload.php`.
4. Cek kesiapan server (cURL/SSL + validitas secret key):
   ```bash
   php api/tests/check_turnstile.php
   ```
   `invalid-input-response` untuk token dummy = normal (secret diterima).
   `invalid-input-secret` = secret key salah. `cURL gagal: ...` = SSL/CA bundle
   bermasalah di hosting.
5. Saat troubleshooting, `turnstile_debug` dan `debug` boleh `true` di
   `config.php` agar pesan error memuat penyebab teknisnya. **Matikan keduanya
   setelah selesai** (keduanya membocorkan detail internal).
6. Uji tanpa PHPUnit: `php api/tests/run_turnstile_check.php` (31 pemeriksaan).

## 6. Izin folder
```bash
chmod 755 uploads backups     # 775 bila web server user beda dari pemilik file
```
Kedua folder harus writable (upload surat & backup DB tulis ke sini).

## 7. Bersih-bersih
- **Jangan** upload `baileys_auth/` — berisi kredensial sesi WA Baileys, tak
  dipakai lagi di arsitektur Fonnte. Hapus setelah migrasi.

## 8. Uji terima (manual)
- [ ] Login
- [ ] Dashboard tampil (statistik + grafik)
- [ ] CRUD surat masuk & keluar (termasuk upload lampiran)
- [ ] Form surat keluar: nomor terisi otomatis (`NomorUrut/KODE/BULAN/TAHUN` per unit,
      melanjutkan data arsip 2026), bisa sisipan (`N.a`, `N.b`, …), dan bisa memakai
      nomor yang sudah dipesan di menu **Ambil Nomor**
- [ ] Menu **Ambil Nomor** muncul untuk semua role; ambil nomor → tercatat DIPESAN →
      user lain tidak dapat nomor yang sama; batalkan/tandai terbit sesuai peran
- [ ] Input surat masuk baru -> Pimpinan mendapat notifikasi in-app & WA grup
- [ ] Buat disposisi -> cek pesan masuk ke grup WA lewat Fonnte
- [ ] Generate laporan (incoming/outgoing/dispositions)
- [ ] Bot WA: kirim `DISPOSISI` dari nomor WA terdaftar PIMPINAN -> bot membalas daftar; `DISPOSISI 1 SELESAI` -> status berubah + notifikasi

## 9. Bot WhatsApp (update disposisi via WA)

PIMPINAN/ADMIN yang nomor WhatsApp-nya terdaftar di profil dapat memperbarui disposisi
langsung dari chat WhatsApp:

| Perintah | Efek |
| --- | --- |
| `DISPOSISI` | Daftar disposisi aktif milik pengirim (terbaru di atas, bernomor) |
| `DISPOSISI BANTUAN` | Bantuan perintah |
| `DISPOSISI <n> PROSES` | Tandai disposisi nomor `n` sedang dikerjakan |
| `DISPOSISI <n> SELESAI [catatan]` | Tandai selesai + catatan, mis. `DISPOSISI 1 SELESAI sudah diarsipkan` |

Persyaratan:

- Fonnte: menu **Device -> Edit** -> isi **Webhook URL** = `https://<domain>/api/wabot` dan **Auto Read = ON** (webhook tidak berjalan tanpa Auto Read).
- Nomor WA pegawai diisi admin di menu **Manajemen Pengguna** (kolom *Nomor WhatsApp*). Hanya nomor terdaftar milik akun **aktif** yang dilayani; pesan dari nomor lain diabaikan senyap. Perintah teks pada bagian 9 khusus **PIMPINAN/ADMIN** — akun dengan role lain melapor lewat menu sesi (bagian 10).
- Di grup, bot hanya aktif pada grup yang sama dengan **Grup Target** pengaturan WhatsApp (opsional: kata kunci **Kata Kunci Bot Grup**). Chat pribadi ke nomor Fonnte juga dilayani.
- Hanya penerima disposisi yang bisa memprogres statusnya (aturan sama dengan web). `Kata Kunci Bot Grup` menghindari bot memproses chat grup yang bukan perintah.

Uji webhook tanpa menunggu pesan WA sungguhan (jalankan dari server/lokal):

```bash
curl -k -X POST https://<domain>/api/wabot -H "Content-Type: application/json" \
  -d '{"sender":"628123456789@s.whatsapp.net","member":"628123456789@s.whatsapp.net","message":"DISPOSISI","inboxid":"TEST-1"}'
```

Ganti `628123456789` dengan nomor WA yang terdaftar pada akun PIMPINAN/ADMIN. Respons `{"status":"ok"}` berarti webhook diterima; balasan bot dikirim via Fonnte ke asal pesan.

## 10. Bot WA v2 — sesi "buat disposisi" & menu status pegawai

Revisi 2026-09-09. Butuh migrasi: jalankan `migrations/2026_09_09_add_wa_sessions.sql`
di database hosting (membuat tabel `wa_sessions` — satu sesi aktif per pengguna).

### Alur pimpinan (sesi LEADER — 60 menit)

1. Kirim `DISPOSISI <nomor agenda>` di grup (atau chat pribadi), mis. `DISPOSISI 12`.
   Bisa juga kata kunci perihal: `DISPOSISI undangan rapat` (bila ambigu, bot minta nomor agenda).
2. Bot mengirim **chat pribadi** berisi daftar pegawai aktif bernomor (snapshot menu).
3. Balas `<nomor>` atau `<nomor> <instruksi>` → disposisi dibuat:
   - grup menerima pengumuman resmi,
   - pimpinan menerima konfirmasi,
   - pegawai penerima menerima DM menu status (lihat bawah).
4. `MENU` tampilkan ulang daftar, `BATAL` batalkan sesi. Sesi berakhir otomatis setelah 60 menit
   (tidak bergeser).

### Alur pegawai (sesi EMPLOYEE — 7 hari)

- Saat disposisi dibuat, pegawai penerima (nomor WA terdaftar) menerima DM:
  `1` = tandai PROSES, `2 [catatan]` = tandai SELESAI.
- Sesi diperpanjang otomatis setiap balasan; ditutup permanen saat SELESAI atau `BATAL`.
- Perintah lama tetap berfungsi (khusus PIMPINAN/ADMIN): `DISPOSISI` (daftar), `DISPOSISI <n> SELESAI [catatan]`.

Catatan:
- Bot selalu membalas ke **chat pribadi**; grup hanya menerima pengumuman resmi.
- Bila pegawai sedang memegang sesi pimpinan, DM menu status dikirim tanpa menu
  agar nomor pegawai tidak tertukar dengan nomor 1/2 (menu aktif setelah sesi berakhir).
- Satu pengguna hanya memegang satu sesi (`wa_sessions` ber-PK `user_id`): saat
  pimpinan memulai sesi LEADER, sesi status pegawainya ditimpa lalu dipulihkan
  otomatis ketika sesi pimpinan berakhir (disposisi dibuat / `BATAL` / kedaluwarsa).
- Nomor agenda: `DISPOSISI 12` dicari sebagai agenda `AGD/<tahun>/012` terbaru
  (varian `12`, `012`, `0012` ikut dicoba). Kata kunci perihal memakai pencarian
  subjek/agenda; lebih dari satu hasil -> bot meminta nomor agenda.
- Menu pegawai dibatasi 25 nama teratas (urut A–Z) agar pesan tetap pendek;
  sisanya pilih lewat aplikasi web. Balasan tanpa instruksi memakai teks baku
  "Segera ditindaklanjuti."
- Sesi pimpinan yang kedaluwarsa diberi tahu satu kali saat dibalas, lalu ditutup.
  Sesi pegawai yang lewat 7 hari otomatis diperpanjang lagi selama tugasnya masih
  PENDING/PROSES; kalau tugasnya sudah beres, sesi ditutup permanen.

## 11. Uji otomatis bot WA (tanpa server & tanpa MySQL)

```bash
# 1) Parser + pembangun pesan murni (132 pemeriksaan)
php api-php/tests/run_wa_bot_check.php

# 2) Alur dispatcher + cabang v1/v2 dengan test-double DB/Fonnte
#    (45 skenario: whitelist grup, kata kunci, idempotensi inboxid, daftar,
#     bantu, update status, sesi pimpinan, sesi pegawai, kedaluwarsa, role)
php api-php/tests/run_wa_branch_check.php

# 3) PHPUnit (butuh `composer install` di api-php untuk vendor/)
cd api-php && vendor/bin/phpunit --filter Wabot
```

Keduanya keluar dengan *exit code* 0 bila lulus; `run_wa_branch_check.php` juga
memeriksa jumlah `?` vs parameter setiap SQL yang dijalankan cabang bot.

### Uji cepat webhook (ganti nomor & agenda)

```bash
curl -k -X POST https://<domain>/api/wabot -H "Content-Type: application/json" \
 -d '{"sender":"628123456789@s.whatsapp.net","member":"628123456789@s.whatsapp.net","message":"DISPOSISI 12","inboxid":"TEST-V2-1"}'
```

Respons `{"status":"ok"}`; menu pegawai dikirim ke chat pribadi nomor tersebut.
