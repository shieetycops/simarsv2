# Catatan Asumsi & Verifikasi — Penyelarasan SIMARS v2 dengan SOP/AS/04 revisi

Dokumen ini mencatat (a) asumsi yang diambil saat menerjemahkan dokumen acuan
`docs/Ringkasan_SOP_04_Penanganan_Surat_Masuk.md`,
`docs/Ringkasan_Tata_Naskah_Dinas_dan_Klasifikasi_Arsip_MA.md`, dan
`docs/Spesifikasi_Form_Fitur_SIMARS.md` menjadi kode, serta (b) bukti perintah
yang benar-benar dijalankan. Aturan yang dipakai: **tidak ada klaim "beres"
tanpa keluaran perintah mentah**; yang tidak terverifikasi ditandai
`TIDAK BISA DIVERIFIKASI`.

---

## 1. TEMUAN PENTING — ada dua salinan kode yang disajikan dua server berbeda

Saat menguji, ditemukan bahwa server dev yang sudah lama hidup **tidak
menyajikan folder repo ini**. Semua uji "hijau" sebelumnya pada port tersebut
karena itu hanya membuktikan perilaku kode yang sudah ter-commit, bukan
perubahan yang sedang dikerjakan.

| Port | Proses | Kode yang disajikan | Bukti |
| --- | --- | --- | --- |
| 8011 | php.exe PID 17072 & 20616 | **BUKAN repo ini** (`D:\simars\v2\api-php`, isi = versi HEAD) | `GET /api/control/{id}` tidak punya key `dispositionRoute`, `allowedTransitions`, `ratificationNotice` |
| 8012 | php.exe PID 23340 | **Repo ini** (`D:\simars\simars-v2-github\api-php`) | ketiga key di atas **ada** |

Bukti pendukung:

- `Get-FileHash` SHA256 `V2Workflow.php`: `D:\simars\v2` = `BE472A43…75FD`
  sedangkan repo = `13BE3625…15B5` → **dua berkas berbeda**. Dibuktikan lebih
  tajam dengan hash blob git:

  ```
  HEAD blob    : b142f24e8e2cd6726112ca60442704b425536d89
  repo kerja   : ac37577f055edd8ae9fc9ca4339c93f99c83e51a
  D:\simars\v2 : b142f24e8e2cd6726112ca60442704b425536d89   <- identik HEAD
  ```

  Artinya `D:\simars\v2` memang **salinan versi ter-commit**, sedangkan repo
  berisi pekerjaan yang belum di-commit.
- Kedua server memakai **database yang sama** (`simars_v2`): surat yang dibuat
  lewat 8012 langsung terlihat di daftar 8011.
- `D:\simars\v2\docs` **kosong**, sedangkan `simars-v2-github\docs` memuat
  seluruh dokumen acuan revisi (bertanggal 23–24 Sep 2026, termasuk
  `Spesifikasi_Form_Fitur_SIMARS.md` dan `Plan_Alur_Disposisi_WA_Web.md`).
  Ini menandakan `D:\simars\simars-v2-github` adalah **tempat kerja aktif**;
  `D:\simars\v2` adalah salinan runtime lama.

**Konsekuensi:** server di 8011 harus dihentikan lalu dijalankan ulang dari
root repo ini agar daftar "hijau" berikutnya bermakna. Selama belum
dilakukan, setiap uji manual di browser pada 8011 akan menguji kode lama
(gejala: tombol rute keputusan tidak muncul).

### Status: SUDAH DIPERBAIKI (disetujui pemilik repo)

`D:\simars\simars-v2-github` ditetapkan sebagai **sumber kebenaran**; dua proses
php yang memegang port 8011 (PID 17072 & 20616, menyajikan `D:\simars\v2`)
dihentikan, lalu server dijalankan ulang dari root repo:

```powershell
cd D:\simars\simars-v2-github
Start-Process php -ArgumentList '-S','127.0.0.1:8011','-t','api-php','api-php/router_dev.php' `
  -WorkingDirectory 'D:\simars\simars-v2-github' `
  -RedirectStandardOutput 'D:\simars\_tmp_verify\backend_8011.log' `
  -RedirectStandardError  'D:\simars\_tmp_verify\backend_8011.err'
```

Bukti setelah restart (probe yang sama):

```
PROBE http://127.0.0.1:8011/api
  ada key 'dispositionRoute'   : YA
  ada key 'allowedTransitions' : YA
  ada key 'ratificationNotice' : YA

php test_e2e.php            # tanpa SIMARS_API_BASE, memakai default 8011
HASIL: 55 PASS, 0 FAIL
```

Server duplikat di 8012 dihentikan karena sudah tidak diperlukan. `D:\simars\v2`
**tidak diubah** — salinan lama itu dibiarkan apa adanya sebagai arsip.

---

## 2. Slice #1 — titik keputusan disposisi (SOP/AS/04 langkah 12–13)

SOP memisahkan dua keputusan yang sebelumnya tercampur "pimpinan men-disposisi":

1. **Titik keputusan** (dipegang Sekretaris/Panitera): tentukan surat
   *perlu kebijakan pimpinan* (`KEBIJAKAN`) atau *langsung ke unit pelaksana*
   (`LANGSUNG`).
2. **Pengarahan & teruskan**: pengarahan dipegang Kasubag Umum/sekretaris
   persuratan (bukan pimpinan), dan tombol "teruskan ke pelaksana" setelah
   kebijakan pimpinan dijalankan Sekretaris/Panitera.

Yang diubah:

- `api-php/lib/V2Workflow.php` — konstanta `DISPOSITION_ROUTES`
  (`KEBIJAKAN`/`LANGSUNG`), `DISPOSITION_ROUTE_LABELS`,
  `DIDISPOSISIKAN_ACTION_LABELS`, `RATIFICATION_NOTICE`, `DECISION_POINT_ROLES`;
  metode `stagesAllowedByRoute()`, `validateDispositionRoute()`,
  `transitionLabel()`, `routeLabel()`, `mustWithholdWaContent()`,
  `allowedTransitionsFor()`; **perbaikan 3 kesalahan peta role** (pengarahan
  pindah dari `R_PIMPINAN` ke pengarah surat, "teruskan" pindah ke
  Sekretaris/Panitera).
- `api-php/lib/handlers/control.php` — `GET /api/control/:id` mengirim
  `allowedTransitions` (tiap entri punya `requiresRoute`), `dispositionRoute`,
  `dispositionRoutes`, `dispositionRouteLabels`, `ratificationNotice`.
  `POST /api/control/:id/transition` memvalidasi rute saat masuk
  `DIDISPOSISIKAN` (termasuk jalur pintas dari `TERREGISTRASI`), menolak
  rute asing/kosong `422` + `errors.field = dispositionRoute`, mengunci
  percabangan setelah keputusan (`422` + `allowedByRoute`), dan menulis
  `disposition_route`/`_by`/`_at` + log aksi `DISPOSITION_DECISION`.
- `api-php/migrations/2026_09_24_add_disposition_route.sql` — 3 kolom di
  `incoming_letters` + indeks `idx_incoming_stage`; terpasang otomatis lewat
  `setup_test_db.php` (penjaga `$columnExists`).
- `src/lib/v2Workflow.ts` + `src/components/control/ControlBook.tsx` — panel
  radio rute + tombol keputusan terpisah, mati sampai rute dipilih; rute
  tampil di grid informasi.
- `api-php/tests/V2WorkflowTest.php` (baru) dan `test_e2e.php` (13+ assertion
  tambahan).

---

## 3. Bukti verifikasi

Semua perintah dijalankan dari `D:\simars\simars-v2-github`. Uji pertama
memakai server 8012 (karena 8011 masih menyajikan salinan lama), lalu
**diulang di 8011 setelah server diperbaiki** (§1) — hasil akhirnya sama:
`test_e2e.php` tanpa `SIMARS_API_BASE` → `55 PASS, 0 FAIL`.

### 3a. Uji end-to-end API — `test_e2e.php`

```powershell
$env:SIMARS_API_BASE='http://127.0.0.1:8012/api'; php test_e2e.php
```

Hasil akhir: **`HASIL: 55 PASS, 0 FAIL`**.

Assertion yang khusus menguji titik keputusan (cuplikan keluaran):

```
PASS: pimpin dilarang memutuskan disposisi (SOP langkah 13)
PASS: disposisi tanpa rute ditolak 422
PASS: rute keputusan tak dikenal ditolak 422
PASS: tahap & rute tidak berubah setelah penolakan
PASS: allowedTransitions menandai tombol butuh rute
PASS: MENUNGGU_DISPOSISI -> DIDISPOSISIKAN (sekre, rute LANGSUNG)
PASS: rute keputusan tersimpan = LANGSUNG
PASS: rute LANGSUNG dikunci: ke pimpinan ditolak 422
PASS: rute tidak lagi mengunci setelah keluar DIDISPOSISIKAN
PASS: pintas TERREGISTRASI -> DIDISPOSISIKAN tanpa rute ditolak 422
PASS: RAHASIA: MENUNGGU_DISPOSISI -> DIDISPOSISIKAN (sekre, rute KEBIJAKAN)
PASS: rute KEBIJAKAN dikunci: langsung ke pelaksana ditolak 422
PASS: UI surat rute KEBIJAKAN hanya menawarkan tahap pimpinan
PASS: RAHASIA: pengarahan dipegang Kasubag/Sekretaris (SOP langkah 4-7)
```

`test_e2e.php` kini menerima `SIMARS_API_BASE` supaya bisa dipastikan menembak
repo ini, dan baris `PASS: pimpin dilarang menandai pengarahan` menjaga
perbaikan peta role tetap terpasang.

### 3b. Uji unit PHPUnit

```powershell
cd D:\simars\simars-v2-github\api-php
php vendor\phpunit\phpunit\phpunit
```

Hasil: **`OK (53 tests, 257 assertions)`** — naik dari 41 karena
`tests/V2WorkflowTest.php` menambah 12 uji aturan (titik keputusan, rute
tersimpan/divalidasi, penguncian percabangan, regresi rute tidak mengunci
tahap lanjutan, label, penahanan isi WA untuk Rahasia).

> Catatan: `DispositionTest` milik aplikasi lama memanggil `Whatsapp::$sender`
> secara nyata; pada run ini Fonnte menolak dengan `invalid token` sehingga
> **tidak ada pesan WA yang benar-benar terkirim**. Uji tetap lolos karena
> kegagalan kirim hanya dicatat.

### 3c. Type-check front-end

```powershell
npx tsc --noEmit     # tidak ada keluaran (0 error)
```

### 3d. Lint PHP

```powershell
php -l api-php\lib\V2Workflow.php      # No syntax errors detected
php -l api-php\lib\handlers\control.php # No syntax errors detected
php -l api-php\tests\V2WorkflowTest.php # No syntax errors detected
php -l test_e2e.php                     # No syntax errors detected
```

### 3e. Yang TIDAK BISA DIVERIFIKASI di sesi ini

- **Klik nyata di browser.** Sesi ini hanya memakai `php -S` + `curl`-style
  request; tidak ada browser terkendali. Server 8011 sudah menyajikan kode
  repo (§1) dan kontrak API-nya terbukti, tetapi tampilan panel rute +
  tombol keputusan perlu dikonfirmasi manusia di
  `http://127.0.0.1:8011/v2/buku-kendali` pada surat berstatus
  `MENUNGGU DISPOSISI`.
- **Notifikasi WhatsApp sungguhan** untuk surat non-rahasia — sengaja tidak
  diuji; token Fonnte di DB uji tidak valid dan aturan sesi melarang kirim
  pesan nyata.

---

## 4. Asumsi desain yang dipakai (mohon dikonfirmasi)

| # | Asumsi | Alasan / catatan |
| --- | --- | --- |
| A1 | Rute hanya mengunci **percabangan yang keluar dari `DIDISPOSISIKAN`**; setelah surat melewatinya, `disposition_route` menjadi catatan historis dan tidak lagi membatasi tahap lanjutan. | Percobaan pertama mengunci semua tahap hilir dan membuat surat macet di `DITERUSKAN_KE_PELAKSANA` (tidak bisa `DALAM_TINDAK_LANJUT`). Ada uji regresi khusus untuk ini. |
| A2 | Rute wajib diisi **kapan pun** surat masuk `DIDISPOSISIKAN`, termasuk jalur pintas `TERREGISTRASI → DIDISPOSISIKAN` (bukan hanya dari `MENUNGGU_DISPOSISI`). | Agar tidak ada pintu masuk ke `DIDISPOSISIKAN` tanpa jejak keputusan. Ditegakkan di server, UI mengikuti `allowedTransitions[].requiresRoute`. |
| A3 | Nilai rute `KEBIJAKAN`/`LANGSUNG` disimpan huruf besar; input dari klien case-insensitive. | Konsistensi kolom `disposition_route` untuk pelaporan. |
| A4 | Pengarahan surat dipegang **Kasubag Umum + Sekretaris** (SOP langkah 4–7), bukan pimpinan. | Sesuai revisi. Deskripsi role lama di `docs/V2_IMPLEMENTATION.md` ("pengarahan & disposisi: PIMPINAN/WAKIL_KETUA") **sudah diperbarui** di sesi ini, begitu pula tabel peran & tabel 17 tahap di `README.md`. |
| A5 | Tombol "teruskan ke pelaksana" setelah kebijakan pimpinan dijalankan **Sekretaris/Panitera**, pimpinan tidak memegangnya. | SOP langkah 15–16; pimpinan hanya menyampaikan kebijakan. |
| A6 | Isi surat `RAHASIA`/`SANGAT_RAHASIA` tidak boleh muncul di muatan WhatsApp (hanya nomor agenda/klasifikasi). | KMA 131 BAB V; helper `mustWithholdWaContent()` sudah ada, penyambungan ke muatan WA dikerjakan di slice §2. |
| A7 | Data uji hidup di database `simars_v2` (bukan produksi) dan dibiarkan menumpuk seperti perilaku `test_e2e.php` sekarang. | Surat `PROBE/IX/2026` dari proses pemeriksaan sudah dibersihkan manual. |
| A8 | Pimpinan/Wakil Ketua **tidak memegang tombol transisi** di alur v2; arahan/kebijakan mereka dicatat sebagai catatan pada perpindahan `MENUNGGU_KEBIJAKAN_PIMPINAN → DITERUSKAN_KE_PELAKSANA` yang dijalankan Sekretaris/Panitera (konstanta `R_PIMPINAN` kini tidak dipakai satu pun di peta transisi). | Konsekuensi langsung dari SOP langkah 13 & 15-16. **Perlu keputusan produk**: apakah pimpinan tetap butuh aksi sendiri (mis. "arahan sudah diberikan") agar ada jejak eksplisit, atau cukup catatan dari Sekretaris/Panitera. |

> Catatan penting dari dokumen rencana sendiri: `docs/Plan_Alur_Disposisi_WA_Web.md`
> §2.3 dan §7.1 menandai **kriteria "perlu kebijakan" vs "langsung" sebagai
> asumsi yang wajib dikonfirmasi ke pimpinan PA Pasarwajo sebelum di-build**.
> Slice #1 ini membangun mekanisme pilihannya (rute tersimpan + terkunci), jadi
> yang perlu diputuskan tinggal **kriterianya**, bukan lagi mekanismenya.

---

## 5. Pertanyaan terbuka — MENGHALANGI slice §3 dan §6

Ketiganya belum bisa dijawab dari dokumen acuan yang ada, jadi pekerjaan
berhenti di titik ini untuk tiga hal tersebut.

1. **Format nomor surat keluar per jenis naskah.** `docs/Spesifikasi_Form_Fitur_SIMARS.md`
   menyebut nomor dibangkitkan otomatis, tetapi pola per jenis naskah
   (Nota Dinas, Undangan, Surat Tugas, Surat Biasa, Surat Keterangan,
   Pengumuman) tidak dicantumkan. Perlu daftar pola + unit penerbit
   (`issuing_unit`) untuk mengisi `outgoing_number_slots` dan uji
   "tidak ada nomor ganda saat dua orang menyimpan bersamaan".
2. **Siapa yang mengesahkan `PENDING_VALIDATION → OFFICIAL`?** Kode arsip
   yang belum ada di master kini ditandai `PENDING_VALIDATION`. Apakah
   Arsiparis, Sekretaris, atau Panitera yang berwenang menaikkannya, dan
   apakah wajib menyertakan dasar/kutipan SK?
3. **Matriks akses §9c Surat Rahasia** (`V2Workflow::RAHASIA_ACCESS_ROLES`
   = ADMIN + Sekretaris/Panitera/pimpinan terkait) dan **label ratifikasi**
   `RATIFICATION_NOTICE` — apakah sudah pas sebagai nilai awal, atau ada
   role yang harus ditambah/dikurangi sebelum dipakai di layar?

---

## 6. Batas slice #1 & urutan berikutnya

Sudah dikerjakan: titik keputusan (§1 dari rencana penyelarasan) end-to-end —
aturan server, kolom DB, kontrak API, tombol UI, uji unit + e2e, dan dokumen
ini.

Belum dikerjakan (menunggu jawaban §5 atau slice berikutnya):

| Slice | Isi | Bergantung pada |
| --- | --- | --- |
| §2 | Penahanan muatan WhatsApp untuk `RAHASIA`/`SANGAT_RAHASIA` (pakai `mustWithholdWaContent()`) | — |
| §3 | Penguncian penomoran surat keluar per naskah | **jawaban #1** |
| §4 | Pengaturan WhatsApp dari UI | — |
| §5 | CRUD pengguna | — |
| §6 | Matriks akses yang bisa diedit + label ratifikasi | **jawaban #2, #3** |
| §7 | Pengarsipan (kanonik nama berkas, hash, berkas bukti) | — |

Urutan menjalankan ulang verifikasi setelah perubahan apa pun:

```powershell
cd D:\simars\simars-v2-github
php setup_test_db.php                      # idempoten
# server dev (jalankan di terminal terpisah, CWD = root repo!)
php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php
php test_e2e.php                           # default base = 8011/api
cd api-php; php vendor\phpunit\phpunit\phpunit; cd ..
npx tsc --noEmit
```

Pastikan hanya ada **satu** proses php yang memegang 8011, dan proses itu
dijalankan dengan CWD = root repo (kesalahan inilah yang membuat sesi ini
sempat menguji kode lama):

```powershell
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Select-Object ProcessId,CommandLine
```

---

## 7. Sesi ini — konsolidasi pintu masuk Surat Masuk (Opsi A)

Catatan: nomor "7" di sini adalah nomor **bagian dokumen**, bukan baris slice
"§7 Pengarsipan" pada tabel di atas.

Konteks: aplikasi punya dua pintu masuk surat yang menulis ke pipeline yang sama
(`POST /api/incoming` â†’ tabel `incoming_letters`, keduanya mulai dari tahap
`DITERIMA`). Dua daftar dengan isi yang bisa berbeda membingungkan pengguna.

### 7a. Keputusan yang dipakai
- **Opsi A (satu pintu)**: `/surat-masuk` dialihkan ke `/v2/buku-kendali`; menu
  sidebar "Surat Masuk" â†’ `/v2/surat-masuk`, "Buku Kendali" â†’ `/v2/buku-kendali`;
  notifikasi dalam aplikasi untuk surat baru diarahkan ke `/v2/buku-kendali`;
  berkas `src/components/letters/IncomingLetters.tsx` **dihapus** setelah
  fiturnya dipindahkan, supaya tidak ada fitur yang hilang.
- Fitur menu lama yang dipindahkan ke `ControlBook.tsx`: Edit (koreksi data),
  Hapus (dengan konfirmasi), buka lampiran, filter tanggal terima + jenis surat,
  paginasi 10 baris.
- "Export Excel / Cetak PDF" lama **tidak dipindahkan karena memang tidak
  berfungsi** (keduanya `DropdownMenuItem` tanpa `onClick`). Diganti ekspor CSV
  sungguhan: mengikuti filter yang sedang aktif, BOM UTF-8, pemisah `;`.
- **Unggah lampiran dipertahankan** (permintaan tegas pemilik proses). Form
  registrasi v2 sebelumnya tidak punya input berkas sama sekali; sekarang ada di
  `/v2/surat-masuk` dan di dialog koreksi Buku Kendali.
- Menu `/disposisi` **tetap** ada: instruksi pimpinan ditulis di sana
  (`POST /api/dispositions`), sedangkan perpindahan tahap dilakukan di Buku
  Kendali. "Buat Disposisi" dari halaman lama tidak dipindahkan agar tidak ada
  dua jalur penulisan disposisi.

### 7b. Aturan koreksi/hapus (AS-9 — perlu ratifikasi)
Surat boleh dikoreksi/dihapus selama **kedua** syarat terpenuhi:

1. tahapnya ada di `V2Workflow::CORRECTABLE_STAGES` (`DITERIMA` … `MENUNGGU_DISPOSISI`,
   yaitu sebelum ada keputusan disposisi), dan
2. belum ada baris `dispositions` untuk surat itu (`letterHasDispositions()`).

Sesudah `DIDISPOSISIKAN` salinan instruksi sudah beredar ke
pimpinan/pelaksana, sehingga mengubah atau menghapus record surat membuat
riwayat mereka tidak lagi cocok. Aturan yang sama dipakai server untuk menentukan
tombol mana yang tampil (`canEdit`, `canDelete`, `correctableStages`,
`hasDispositions` pada `GET /api/control/:id`), jadi tombol yang tampil = yang
divalidasi.

**Mohon dikonfirmasi**: (a) apakah surat di `MENUNGGU_DISPOSISI` (menunggu
keputusan Sekretaris/Panitera) tetap boleh dikoreksi seperti sekarang, dan
(b) apakah ADMIN cukup sebagai satu-satunya role yang boleh menghapus?

### 7c. Temuan bug nyata selama pengerjaan
1. **Multipart `PUT` tidak pernah mengunggah berkas.** PHP hanya memparsing body
   multipart pada request POST; pada PUT, `$_POST`/`$_FILES` selalu kosong dan
   `php://input` berisi multipart mentah. Akibatnya dialog Edit lama yang
   mengirim `FormData` berisi berkas dengan method PUT selalu dibalas 200 tanpa
   mengganti lampiran — **kegagalan senyap**. Perbaikan: server menerima
   `POST /api/incoming/:id` + field `_method=PUT` (multipart) dan UI memakai
   jalur itu saat ada berkas; `PUT` + JSON tetap didukung untuk koreksi data.
2. **Berkas lampiran lama kini dihapus lewat satu tempat** (`Upload::delete()`),
   dengan penjagaan pola `/uploads/<nama aman>` supaya nilai kolom `file_path`
   tidak bisa dipakai menghapus berkas di luar folder uploads. Sebelumnya
   penghapusan berkas tersebar dan tidak konsisten (lampiran yatim menumpuk).
3. **Uji PHPUnit menembak Fonnte sungguhan.** `Whatsapp::$sender` disetel `null`
   oleh satu kelas uji dan terbawa ke kelas uji berikutnya sehingga dua uji
   benar-benar memanggil `https://api.fonnte.com/send` (ditolak karena token
   kosong, tetapi tetap panggilan jaringan). `tests/bootstrap.php` +
   `tests/WaStub.php` sekarang memasang stub di setiap kelas; keluaran uji bersih
   dari log Fonnte.
4. **`nature=RAHASIA` (temuan lama; dari UI kini tidak mungkin lagi)** — form
   lama menawarkan sifat "RAHASIA" yang **tidak dibaca** backend: surat tersimpan
   `security_level='BIASA'`, lolos filter akses Rahasia, dan ikut dikirim ke
   WhatsApp. Halaman lama sudah dihapus dan form v2 hanya memakai
   `securityLevel`. Kolom `nature` tetap ada untuk data lama.
   **Perlu keputusan**: apakah surat lama ber-`nature` `RAHASIA`/`PENTING` perlu
   disisir dan level keamanannya diperbaiki?
5. **Tombol "Buka" di Buku Kendali tampak tidak berfungsi** (dilaporkan pemilik
   repo). Kartu detail dirender **di bawah tabel**, sehingga pada tinggi layar
   805 px kartu baru muncul di `y≈1137` px: klik tidak mengubah apa pun yang
   terlihat. Lebih buruk, pesan kegagalan ("Detail surat tidak dapat dibuka.")
   hanya dirender **di dalam** kartu itu, jadi bila permintaannya gagal (mis. 403
   surat rahasia) tidak ada satu pun pesan di layar. Perbaikan: indikator memuat
   ("Membuka detail surat…" + label tombol "Membuka…"), banner status di atas
   tabel yang selalu terlihat, gulir otomatis ke kartu detail (sekali per surat),
   baris yang sedang dibuka ditandai, dan label tombol transisi memakai
   `V2_STAGE_LABELS` (sebelumnya tampil `VERIFIKASI_ALAMAT` mentah). Bukti
   sebelum/sesudah di 7d.
6. **Tautan lampiran tidak bisa dibuka di lingkungan pengembangan.** `uploads/`
   ada di root repo, sedangkan server dev dijalankan `-t api-php` dan Vite hanya
   mem-proxy `/api`: `/uploads/<berkas>` tidak dilayani siapa pun walau berkasnya
   ada (di produksi tidak terjadi karena `uploads/` satu document root dengan
   `dist/`). Perbaikan: cabang `/uploads/...` di `api-php/router_dev.php` dengan
   pola nama berkas dibatasi seperti `Upload::delete()` (supaya `..` tidak bisa
   dipakai membaca berkas lain) + proxy `/uploads` di `vite.config.ts`.
7. **Salah eja nilai status verifikasi alamat: `SESAI` → `SESUAI`.** Kode menulis
   `ALAMAT_SESAI` / `ALAMAT_TIDAK_SESAI` lalu menampilkannya apa adanya:
   kolom "Alamat" di tabel Buku Kendali, checkbox checklist ("Alamat tujuan
   sesai"), pesan sukses ("Alamat dicatat SESAI."), catatan log kendali, dan
   label di README. Perbaikan: nilai baru `ALAMAT_SESUAI` / `ALAMAT_TIDAK_SESUAI`
   di API (`api-php/lib/handlers/control.php`), label UI
   (`src/lib/v2Workflow.ts`), teks tombol/checkbox/pesan (`ControlBook.tsx`), uji
   (`test_e2e.php`), dan migrasi data
   `api-php/migrations/2026_09_25_fix_address_status_typo.sql` yang menyelaraskan
   `incoming_letters.address_status`, `letter_control_logs.notes`, dan
   `activity_logs.details`. Penjagaan "sudah diverifikasi" di server diubah jadi
   perbandingan negatif terhadap `PERLU_VERIFIKASI`, supaya data lama ber-ejaan
   lama tetap tidak bisa diverifikasi dua kali walau migrasi belum dijalankan.
   **Dampak kompatibilitas**: string pada respons API berubah; konsumen yang
   membandingkan `'ALAMAT_SESAI'` harus diperbarui (di repo ini hanya uji e2e).
   Ejaan lama sengaja hanya tersisa di dalam berkas migrasi di atas.
8. **Dua salah eja lain di teks yang dilihat pengguna** (ditemukan saat menyisir
   bersama temuan #7): "Sesi anda telah berakhir, silahkan login kembali."
   → "Sesi Anda telah berakhir, silakan login kembali."
   (`api-php/lib/Auth.php` + `api-php/tests/AuthTest.php`; KBBI: "silakan", dan
   sapaan "Anda" ditulis kapital) dan instruksi bawaan disposisi "Silahkan
   laksanakan tindak lanjut…" → "Silakan laksanakan tindak lanjut…"
   (`src/components/disposition/Dispositions.tsx`). Penyisiran kata sejenis
   (Nomer, Sekertaris, Verivikasi, Praktek, Jadual, Aktifitas, "di atas",
   kata ulang ganda, sisa label Inggris di UI v2) tidak menemukan temuan lain.
   Penyisiran seluruh string UI (`src/**`) menemukan satu label yang **salah
   menyebut format**: tombol di halaman Laporan berlabel "Excel"
   (`handleExportExcel`) sebenarnya mengunduh berkas **CSV**
   (`exportToCSV` → `..._yyyyMMdd.csv`). Label, nama fungsi, dan komentarnya
   diganti menjadi "CSV" / `handleExportCsv` (`src/components/reports/Reports.tsx`).
9. **Tombol "Export" di halaman Surat Keluar tidak berfungsi (belum diubah).**
   Tombol itu (`src/components/letters/OutgoingLetters.tsx`, sekitar baris 827)
   sama sekali **tanpa `onClick`** — pola yang persis sama dengan
   tombol "Export Excel"/"Cetak PDF" halaman Surat Masuk lama yang sudah dihapus
   (lihat README). Karena ini penambahan fitur (ekspor CSV Surat Keluar), belum
   dikerjakan tanpa keputusan pemilik repo: dihubungkan seperti Ekspor CSV Buku
   Kendali, atau tombolnya dihapus.
10. **Mojibake em dash pada dokumen.** README (4 tempat), `docs/DEPLOY.md` (5),
   dan `docs/V2_IMPLEMENTATION.md` (2) memuat em dash yang salah encoding
   (`â€”` sebagai ganti satu em dash `—`) akibat berkas pernah ditulis ulang dengan encoding
   berbeda. Diganti pada tingkat byte (UTF-8, tanpa BOM, line-ending tidak
   berubah), sehingga tidak ada lagi teks rusak saat dokumen dibaca/dibuka.

### 7d. Bukti verifikasi sesi ini
- `php test_e2e.php` → **100 PASS / 0 FAIL** (bagian 10 menguji koreksi, hapus,
  batas tahap/role, unggah + ganti lampiran **dengan pemeriksaan berkas di disk**,
  validasi enum, dan aturan "surat berdisposisi tidak boleh diubah"; dua check
  terakhir memastikan lampiran bisa diambil lewat HTTP sebagai `application/pdf`
  dan permintaan di luar `uploads/` ditolak 404).
- `cd api-php; php vendor/bin/phpunit` → **OK (60 uji, 292 asersi)**, tanpa
  panggilan jaringan WhatsApp.
- `npx tsc --noEmit` → 0 error; `npx vite build` → sukses (9,5 s) dan chunk
  `ControlBook-*.js` memuat teks "Membuka detail surat" + "Lihat lampiran scan".
- Database diperiksa read-only: `simars_v2` 5/5 kolom v2 (29 surat, 4 user),
  `simars` **0/5** kolom v2 (121 surat, 28 user) → migrasi wajib dijalankan
  sebelum kode v2 dipakai pada database `simars`.
- **Klik nyata di browser** (Chrome headless 154, halaman dari Vite dev `:5199`
  yang mem-proxy ke backend `:8011`, login `admin_v2`, halaman `/v2/buku-kendali`,
  bukan simulasi DOM):
  - buka `/surat-masuk` → mendarat di `/v2/buku-kendali` dengan 10 baris tabel;
  - klik **Buka** pada baris pertama — **sebelum** perbaikan: kartu detail ada di
    `top=1137`, `tinggiLayar=805`, `terlihat=false` (inilah keluhan "tombol tidak
    berfungsi"); **sesudah**: `top=80`, `terlihat=true`, tanpa error konsol dan
    tanpa respons ≥400;
  - label tombol transisi terbaca "Verifikasi alamat", "Disortir" (sebelumnya
    "VERIFIKASI_ALAMAT", "DISORTIR");
  - tautan lampiran diuji dengan surat uji ber-lampiran: **PNG → `200`,
    70 byte, diawali magic bytes PNG**; lampiran yang tidak ada → `404` JSON.
    Berkas uji dihapus lagi sesudahnya (`uploads/` kembali hanya `.htaccess`).
- Catatan pembacaan hasil uji: `fetch()` sebuah **PDF** di Chrome *headless*
  dibalas `204` kosong oleh Chrome sendiri (tidak ada PDF viewer di headless),
  sedangkan `curl.exe` ke URL yang sama menerima `200 application/pdf`, 58 byte,
  berisi `%PDF-1.4`. Jadi `204` itu artefak alat uji, **bukan** bug server —
  jangan dipakai sebagai bukti kegagalan tautan lampiran.
- Selama pengujian, profil Chrome diletakkan di luar repo (`%TEMP%`). Profil yang
  ditaruh di dalam folder repo membuat watcher Vite terus memicu `page reload`
  sehingga hasil uji jadi acak (gejala: halaman tampak "kosong" lalu memuat ulang).
- **Perbaikan ejaan status alamat (temuan 7c#7)**: migrasi
  `2026_09_25_fix_address_status_typo.sql` dijalankan pada `simars_v2` —
  sebelum: `ALAMAT_SESAI` 20 baris; sesudah: `ALAMAT_SESUAI` 20 baris, dan sisa
  ejaan lama di `letter_control_logs` maupun `activity_logs` = **0**.
- Bukti API nyata pada server dev `:8011` (kode dari repo ini, bukan salinan
  lama): `GET /api/control/506840b0…` (agenda `AGD/2026/9065839`) →
  `addressStatus: "ALAMAT_SESUAI"` dengan catatan log
  `"Alamat sesuai. Alamat kantor sesuai"`; permintaan memakai token salah
  dibalas `"Sesi Anda telah berakhir, silakan login kembali."` (string baru di
  `Auth.php`) — jadi server memang menyajikan kode terbaru.
- Setelah perbaikan: `php test_e2e.php` → **100 PASS / 0 FAIL** (check
  "verifikasi alamat sesuai" membandingkan `ALAMAT_SESUAI`);
  `php vendor/bin/phpunit` → **OK (60 uji, 292 asersi)**; `npx tsc --noEmit` →
  0 error; `npx vite build` → sukses (10,1 s) dengan chunk `v2Workflow-*.js`
  memuat "Alamat sesuai"/"Alamat tidak sesuai" dan `Reports-*.js` memuat label
  "CSV" (tidak lagi "Excel"); `php -l` bersih pada `control.php`, `Auth.php`,
  dan `AuthTest.php`.

---

## 8. Sesi ini — penyelarasan WA + Buku Kendali (Fase 0–4)

Fokus sesi: **laporan tindak lanjut pelaksana menggerakkan tahap surat** (sebelumnya
hanya baris `dispositions` yang berubah) dan **hak rollback Kasubag Umum**, plus
**peran WA yang diperluas** ke Kasubag/Sekretaris/Panitera.

### 8a. Keputusan yang dipakai (disetujui pemilik proses)

| # | Keputusan | Isi |
| --- | --- | --- |
| #1 = C | Auto-advance + rollback | Laporan `PROSES` → `DALAM_TINDAK_LANJUT`; `SELESAI` → `SELESAI_DITINDAKLANJUTI`. Perpindahan lain tetap manual. Kasubag Umum boleh **menarik surat kembali** ke mejanya dengan alasan wajib; `MENUNGGU_PENGARSIPAN → DIARSIPKAN` tetap manual. |
| #2 = A | "Full WA" | Kasubag/Sekretaris/Panitera menerima notifikasi per tahap **dan** boleh bertindak langsung dari WhatsApp lewat menu bernomor (sesi `VERIFIKASI`/`DEKISION`/`ARCHIVE`). |

Catatan penyimpangan kecil dari #2 yang disengaja: **`Wabot::ALLOWED_ROLES` tidak
diperluas** (tetap `PIMPINAN`, `ADMIN`). Perintah teks v1 (`DISPOSISI <n> SELESAI`)
dan sesi "buat disposisi" tetap milik Pimpinan/Admin — sedangkan Kasubag/
Sekretaris/Panitera bertindak lewat gate baru `Wabot::STAGE_ACTOR_ROLES`, yaitu
menu aksi atas surat yang **memang sudah ada di mejanya**. Alasannya prinsip
kewenangan minimum dan karena `tests/WabotTest.php` +
`tests/run_wa_bot_check.php` sudah mengunci daftar role v1.

### 8b. Pertanyaan terbuka H1–H7 — default yang dipakai

| # | Pertanyaan | Default yang dijalankan | Dampak bila pemilik proses berbeda |
| --- | --- | --- | --- |
| H1 | Arahan pimpinan: dicatat saja atau di-WA-kan? | Arahan pimpinan hanya **dicatat**; yang meneruskan tetap Sekretaris/Panitera (`MENUNGGU_KEBIJAKAN_PIMPINAN` → `DITERUSKAN_KE_PELAKSANA` = R_PANITERA). Pimpinan menerima notifikasi, tanpa menu aksi. | Bila pimpinan harus bisa menekan "TERUSKAN" dari WA, tambahkan `PIMPINAN`/`WAKIL_KETUA` ke `allowedRolesForTransition` untuk transisi itu. |
| H2 | Siapa yang boleh menginput surat? | Tetap `ADMIN`/`SEKRETARIS` (tidak diubah di sesi ini; UI tetap menjadi gerbang pertama). | Perlu perubahan `Auth::requireRole` di `handlers/incoming.php`. |
| H3 | Kapan pimpinan diberi tahu? | Notifikasi DM pimpinan per tahap **tetap** seperti sebelumnya, dan bertambah saat surat benar-benar masuk `MENUNGGU_KEBIJAKAN_PIMPINAN`. | Bila DM saat surat masuk dianggap terlalu dini, hapus pemanggilan `notifyLeaderDm` di `incoming.php`. |
| H4 | Auto-advance saat surat belum sampai rantai pelaksana | **Tidak** di-advance; laporan diterima tetapi tahapnya tidak diubah, dan alasannya dikembalikan (`BUKAN_TAHAP_PELAKSANAAN`). | Bila laporan harus tetap memajukan tahap walau surat masih di meja Kasubag, longgarkan `V2Workflow::autoAdvanceSourcesForEvent()`. |
| H5 | Cabut/pindah tugas (`dispositions`) | Belum ada fitur revoke/reassign; koreksi dilakukan lewat penarikan kembali Kasubag. | Fitur baru (endpoint + UI). |
| H6 | Balasan WA bentuk bebas (mis. "surat sudah saya kerjakan") | Tidak didukung; balasan wajib **bernomor** sesuai menu. | Perlu parser bahasa bebas (berisiko salah tafsir). |
| H7 | Cakupan baca Buku Kendali | Tidak diubah: daftar `/api/control` tetap menampilkan semua surat (difilter level keamanan) ke semua role. | Bila daftar harus dibatasi ke surat yang relevan dengan pengguna, ubah filter di `handlers/control.php`. |

### 8c. Asumsi baru (AS-10 s.d. AS-13)

- **AS-10 — Varian ejaan status alamat tidak lagi dipakai.** Kolom
  `address_status` dan log kendali memakai `ALAMAT_SESUAI`/`ALAMAT_TIDAK_SESUAI`;
  data lama sudah dimigrasi (`2026_09_25_fix_address_status_typo.sql`). Perbandingan
  di `control.php` sengaja memakai bentuk negatif (`!== 'PERLU_VERIFIKASI'`) agar
  baris lama apa pun tetap terkunci dan tidak bisa diverifikasi dua kali.
- **AS-11 — Hierarki `users.supervisor_id` adalah dasar wewenang disposisi.**
  Sampai Admin mengisi kolom **Atasan Langsung** (kini bisa dari menu Pengguna),
  Kasubag/Sekretaris/Panitera **tidak bisa** mendisposisi ke siapa pun
  (`Disposition::canDispose`), dan menu WhatsApp mereka kosong dengan pesan yang
  menjelaskan penyebabnya (`Wabot::buildNoSubordinateTargetText`). ADMIN/PIMPINAN
  tetap memakai daftar lama (semua pegawai aktif bernomor WA).
- **AS-12 — Penarikan kembali (rollback) hanya untuk Kasubag Umum & ADMIN.**
  Sekretaris/Panitera tidak diberi tombol serupa karena surat di meja pimpinan
  (`MENUNGGU_KEBIJAKAN_PIMPINAN`) hanya boleh ditarik ADMIN — keputusan pimpinan
  tidak dibatalkan diam-diam. Penarikan **bukan** bagian state machine maju: tidak
  ada di `canTransition`, punya endpoint sendiri (`POST /api/control/:id/reopen`),
  dan dicatat sebagai action `STAGE_REOPEN`. Alasan wajib ≥ 10 karakter.
- **AS-13 — Satu sesi WhatsApp per pengguna (`wa_sessions` PK `user_id`).**
  Konsekuensinya: bila seorang pemegang tahap sedang memegang sesi `LEADER`
  (buat disposisi) atau `EMPLOYEE` (menu status tugas), tugas tahap **tidak**
  merebut sesi itu — pesannya tetap dikirim dan aksinya dilakukan lewat web
  (dicatat `WABOT_STAGE_SESSION_SKIPPED` di activity log). Tugas tahap yang
  datang berikutnya masuk **antrean** (`wa_sessions.context.queue`, maksimum 5)
  dan bukan menimpa tugas yang sedang tampil.

- Klik manusia pada **dialog Koreksi/Hapus**, unduhan + isi berkas **CSV** lewat
  UI, dan pemilihan berkas pada **input unggah** di browser biasa. Jalur API-nya
  sudah diuji otomatis (e2e) dan konstruksi CSV-nya sudah diuji baris-per-baris di
  luar browser; yang belum: lapisan DOM-nya.
- Pratinjau **PDF** di browser normal (butuh viewer; di headless tidak ada).
- Ratifikasi **AS-9** (bagian 7b) dan dua pertanyaan terbuka lain di bagian 8.

### 8d. Berkas yang diubah/ditambah

Backend (PHP):

| Berkas | Perubahan |
| --- | --- |
| `api-php/migrations/2026_09_26_add_letter_assignee.sql` | **Baru.** Kolom `assignee_user_id` + `assignee_set_at` + indeks + backfill dari disposisi terbaru. |
| `api-php/lib/V2Workflow.php` | `DISPOSITION_EVENT_STAGES`, `stageForDispositionEvent()`, `autoAdvanceSourcesForEvent()`, `planAutoAdvance()` (murni), `autoAdvanceReasonText()`, `STAGE_OWNER_ROLES`, `stageOwners()`, `STAGE_LABELS`, `stageLabel()`, aturan penarikan (`REOPEN_*`). |
| `api-php/lib/LetterTransition.php` | **Baru.** Satu pintu perpindahan tahap (`apply()`, `applyPath()`, `reopen()`), penulisan `letter_control_logs` (`TRANSITION`/`DISPOSITION_DECISION`/`AUTO_STAGE`/`STAGE_REOPEN`), lalu memanggil notifikasi tahap. |
| `api-php/lib/DispositionBridge.php` | **Baru.** `applyStatus()` (laporan → tahap surat, idempoten & menjelaskan alasan bila tidak maju) + `assignLetter()` (pelaksana surat). |
| `api-php/lib/WaStageNotifier.php` | **Baru.** Notifikasi per tahap ke pemegang tahap + pelaksana, plus antrean tugas aksi `wa_sessions` (kind `VERIFIKASI`/`DEKISION`/`ARCHIVE`). |
| `api-php/lib/Wabot.php` | `STAGE_ACTOR_ROLES`, `isStageActorRole()`, `STAGE_SESSION_HOURS`, `newStageExpiry()`, `parseRouteChoice()`, `buildStageNoticeText()`, `buildStageTaskMenu()`, `buildStageTaskApplied()`, `buildStageTaskFailed()`, `buildNoStageTaskText()`, `buildNoSubordinateTargetText()`, `pendingTaskCount()`. |
| `api-php/lib/helpers.php` | `waDispositionCandidates()` — kandidat menu WA: bawahan langsung untuk role berwewenang hierarki. |
| `api-php/lib/Whatsapp.php` | `ensureLeaderSession()` memakai `waDispositionCandidates()`. |
| `api-php/lib/handlers/control.php` | Transisi memakai `LetterTransition`; endpoint **baru** `POST /control/:id/reopen`; payload detail bertambah (`stageLabel`, `stageOwnerRoles`, `assignee`, `canReopen`, `reopen*`); daftar bertambah `assigneeUserId`/`assigneeName`. |
| `api-php/lib/handlers/dispositions.php` | `assignLetter()` saat disposisi dibuat; `applyStatus()` saat status berubah; hasilnya dikirim sebagai `stageAdvance`. |
| `api-php/lib/handlers/wabot_v1.php` + `wabot_v2.php` | Laporan status via WA memanggil `DispositionBridge::applyStatus()`; **handler baru** sesi aksi tahap (`wabotV2StageReply()` + pembantu antrean); role bukan aktor tahap dilewati. |
| `api-php/lib/handlers/users.php` | `POST`/`PUT /users` menerima & memvalidasi `supervisorId` (`resolveSupervisorId()`); daftar memuat `supervisor_id`. |

Frontend (TS):

| Berkas | Perubahan |
| --- | --- |
| `src/lib/v2Workflow.ts` | Cermin kontrak baru: `V2_STAGE_OWNER_ROLES`, `V2_ROLE_LABELS`, `V2_DISPOSITION_EVENT_STAGES`, `V2_REOPEN_*`, `v2StageOwnerText()`, `v2CanReopenLetter()`, tipe `V2ControlDetail` diperluas. |
| `src/components/control/ControlBook.tsx` | Kolom **Pelaksana** (tabel + CSV), baris "Pelaksana"/"Menunggu di meja" di detail, tombol **Tarik kembali** + dialog alasan wajib. |
| `src/components/users/UserManagement.tsx` | Dropdown **Atasan Langsung** di dialog Tambah & Edit (dikirim sebagai `supervisorId`, "tanpa atasan" = `null`). |

Uji:

| Berkas | Perubahan |
| --- | --- |
| `api-php/tests/run_fase1_check.php` | **Baru.** Uji integrasi MySQL lokal (`simars_v2_test`, DB terpisah): migrasi → seed → auto-advance → notifikasi WA (stub) → antrean sesi → penarikan kembali → RAHASIA → kandidat menu. |
| `api-php/tests/LetterTransitionTest.php` | **Baru.** 7 uji penjaga (tanpa DB): tahap sama, transisi ilegal, role ditolak, rute wajib, rute terkunci, guard penarikan, jenis sesi. |
| `api-php/tests/V2WorkflowTest.php` | +4 uji: `planAutoAdvance`, `stageForDispositionEvent`, `stageOwners`, aturan & alasan penarikan. |
| `api-php/tests/WabotTest.php` | +5 uji: role aktor tahap, masa berlaku sesi, `parseRouteChoice`, notifikasi tahap (termasuk RAHASIA), menu & konfirmasi aksi. |
| `api-php/tests/run_wa_branch_check.php` | Memuat kelas baru + stub `waDispositionCandidates()`; +6 kasus sesi aksi tahap. |


---

## 9. Revisi disposisi SOP/AS/04 langkah 11-18 (sesi 28 Sep 2026)

Rujukan tahap 1-18 = SOP/AS/04 PA Pasarwajo (BUKAN KMA 131/2023; KMA 131 hanya
BAB IV audit & BAB V pengamanan). Bagian yang diimplementasi: langkah 11-18.
Langkah 1-11 (verifikasi alamat/sortir/klasifikasi/scan) tercakup sebagian
(verifikasi alamat + checklist kelengkapan ada; sortir/klasifikasi rinci belum).

### 9a. Keputusan locked (STOP dijawab pemilik proses)
- **Q1: "kembali revisi setelah arahan pimpinan" = TIDAK ADA.** Arahan Ketua/WK
  = final dan mengikat. Jika arahan tidak jelas, Sekretaris klarifikasi offline,
  bukan via tombol return (mencegah infinite loop).
- **Q2: pencatat surat = ARSIPARIS.** Role baru `ARSIPARIS` (+ `ADMIN` fallback)
  satu-satunya yang boleh menandai `DIARSIPKAN`. Pegawai pelaksana hanya sampai
  `SELESAI_DITINDAKLANJUTI`.

### 9b. Yang diubah (Fix 1-6)
- **Fix 1 (rekomendasi vs keputusan):** kolom baru `rekomendasi_route/_by/_at/_notes`
  (Kasubag, langkah 12) TERPISAH dari `disposition_route/_by/_at` (Sekretaris
  final, langkah 13). Endpoint baru `POST /control/:id/rekomendasi` +
  `POST /control/:id/keputusan` (3 opsi: KEBIJAKAN / LANGSUNG+unit / KEMBALI via
  reopen). Server 403 bila Kasubag/pegawai/Ketua coba tetapkan rute final.
- **Fix 2 (kata kunci WA):** `Wabot::parseKeywordCommand()` + handler baru
  `wabot_keyword.php`: TERIMA/TOLAK/KEBIJAKAN/LANGSUNG/ARAHAN/TERUSKAN/TUNJUK/
  PROSES/SELESAI/ARSIP `<agenda>` [isian]. Tanpa agenda / agenda bukan milik
  pengirim -> error, data tidak berubah. Satu kata kunci = satu arti
  (SELESAI tidak pernah mengembalikan; hanya TOLAK).
- **Fix 3 (unit tujuan):** kolom baru `unit_tujuan/_by/_at`
  (KASUBAG_UMUM/KEPEGAWAIAN/PTIP, PANMUD_PERMOHONAN/GUGATAN/HUKUM). Transisi ke
  `DITERUSKAN_KE_PELAKSANA` ditolak bila unit kosong.
- **Fix 4 (tutup pintasan):** `DITERUSKAN_KE_PELAKSANA` = ke UNIT
  (assignee harus null saat masuk). Penunjukan pegawai hanya kepala unit via
  `POST /control/:id/tunjuk` atau `TUNJUK <agenda> <nama>` (langkah 17).
- **Fix 5 (penutup 17-18):** `MENUNGGU_PENGARSIPAN -> DIARSIPKAN` hanya
  ARSIPARIS/ADMIN. Endpoint `POST /control/:id/arsip`. WA notify Arsiparis
  saat masuk MENUNGGU_PENGARSIPAN.
- **Fix 6 (dokumentasi):** rujukan langkah 1-18 diperbaiki ke SOP/AS/04 di
  README/Plan/komentar kode; KMA 131 hanya BAB IV/V.

### 9c. Migrasi baru
- `api-php/migrations/2026_09_28_revisi_disposisi_sop.sql` — 10 kolom baru di
  `incoming_letters` (rekomendasi x4, arahan x3, unit x3). Idempoten manual.

### 9d. Bukti verifikasi sesi 28 Sep 2026 (lanjutan, server `:8011` repo ini)

```
php -l (12 berkas yang diedit)                     ->  No syntax errors detected (semua)
cd api-php && vendor\bin\phpunit.bat --no-coverage ->  OK (89 tests, 602 assertions)
php test_e2e.php                                   ->  HASIL: 105 PASS, 0 FAIL
php test_disposisi_sop.php                         ->  RINGKASAN: 8 PASS, 0 FAIL, 2 SKIP/TIDAK-BISA-DIVERIFIKASI
php api-php/tests/run_status_dm_check.php          ->  SEMUA LULUS (13 cek)
php api-php/tests/run_php_compat_check.php         ->  PHP compat check: 21 pass, 0 fail
php api-php/tests/run_auth_check.php               ->  Auth check: 57 pass, 0 fail
php api-php/tests/run_upload_check.php             ->  Upload check: 42 pass, 0 fail
php api-php/tests/run_turnstile_check.php          ->  Turnstile check: 31 pass, 0 fail
php api-php/tests/run_fase1_check.php              ->  == Fase 1 check: 32 PASS, 0 FAIL ==
php api-php/tests/run_wa_bot_check.php             ->  Wabot: 132 PASS, 0 FAIL
php api-php/tests/run_wa_branch_check.php          ->  SEMUA KASUS LULUS
npx tsc --noEmit                                   ->  0 error
```

Bukti T1-T10 `test_disposisi_sop.php` (surat dibuat via API, jalur SOP penuh):

```
[T1] PASS  rekomendasi=200 keputusan=200 DISORTIR=200 MENUNGGU_PENGARAHAN=200
           DIBACA_PENGARAH=200 MENUNGGU_DISPOSISI=200 DIDISPOSISIKAN=200 ke-sekret=200
           ke-pimpinan=200 arahan=200 ke-unit=200 tunjuk=200 DALAM_TINDAK_LANJUT=200
           SELESAI_DITINDAKLANJUTI=200 MENUNGGU_PENGARSIPAN=200 arsip=200 final=DIARSIPKAN
[T2] PASS  stage=DITERUSKAN_KE_PELAKSANA pimpinan=TIDAK (jalur LANGSUNG tanpa meja pimpinan)
[T3] PASS  rek=KEBIJAKAN final=LANGSUNG (rekomendasi Kasubag ≠ keputusan Sekretaris)
[T4] PASS  kasubag=403 pimpinan=403 pegawai=403 final=NULL (rute final hanya Sekretaris/Panitera)
[T5] PASS  tanpa-unit=422 tunjuk-salah-tahap=422 pegawai-keputusan=403
[T6] PASS  pintas=422 (pimpinan→pelaksana tertutup) arahan-kosong=422 arahan-pendek=422
[T10] PASS GET /control/stages -> HTTP 200 (migrasi tidak merusak kontrak)
```

Pemeriksaan simbol yang semula diragukan (semuanya ADA dan teruji):
`Wabot::parseKeywordCommand/WA_KEYWORDS/buildKeywordErrorText/buildKeywordHelpText`,
`LetterTransition::decideRoute/applyPath/reopen/SOURCE_WA/ACTION_AUTO/saveRekomendasi`,
`WaStageNotifier`, migrasi `2026_09_28_revisi_disposisi_sop.sql`, endpoint
`POST /control/:id/rekomendasi|keputusan|arahan|tunjuk|arsip`, dan helper
`V2Workflow::canSetFinalRoute/canGiveRekomendasi/validateRekomendasi/validateUnitTujuan/
validateArahan/canMarkArchived/isUnitHead/UNIT_TUJUAN_LIST/stageOwners/planAutoAdvance/
autoAdvanceReasonText/canReopenLetter/userCanAccessLetter`. Casing camelCase di
`wabot_keyword.php` cocok dengan `Db::one/all` (konversi snake→camel otomatis).

Yang MASIH tidak bisa diverifikasi (sama seperti 8f): pengiriman Fonnte nyata (T7/T9),
klik manusia pada panel UI baru (form rekomendasi, select unit, textarea arahan,
dropdown tunjuk — kontrak API + `tsc --noEmit` hijau), dan ratifikasi pemilik proses.

### 8e. Bukti perintah mentah (dijalankan di sesi ini)

Migrasi pada basis data pengembangan `simars_v2` (skrip sementara, lalu dihapus):

```
diterapkan: 3, dilewati: 0
assignee_user_id: ADA
backfill: 10 dari 50 surat sudah punya pelaksana
```

Uji integrasi MySQL (DB terpisah `simars_v2_test`, dibuat ulang tiap jalan):

```
-- basis data uji simars_v2_test siap (schema.sql + 13 migrasi)
PASS: Fase 1 - laporan PROSES maju satu tahap
PASS: Fase 1 - log kendali AUTO_STAGE
PASS: Fase 1 - laporan PROSES kedua tidak menaikkan tahap
PASS: Fase 1 - laporan SELESAI maju ke tahap akhir pelaksanaan
PASS: Fase 2 - tiap penerima dapat pemberitahuan + menu
PASS: Fase 1 - Sekretaris tidak boleh menarik surat
PASS: Fase 1 - Kasubag boleh menarik surat
PASS: Fase 1 - log penarikan tercatat STAGE_REOPEN
PASS: Fase 2 - perihal surat RAHASIA TIDAK dikirim ke WhatsApp
PASS: Fase 3 - daftar menu Kasubag = bawahannya saja
PASS: Fase 3 - role hierarki tanpa bawahan -> daftar kosong

== Fase 1 check: 32 PASS, 0 FAIL ==
```

```
php vendor/phpunit/phpunit/phpunit          ->  OK (77 tests, 531 assertions)
php api-php/tests/run_wa_branch_check.php   ->  SEMUA KASUS LULUS
php api-php/tests/run_wa_bot_check.php      ->  Wabot: 132 PASS, 0 FAIL
php api-php/tests/run_status_dm_check.php   ->  SEMUA LULUS (13 cek)
php api-php/tests/run_php_compat_check.php  ->  PHP compat check: 21 pass, 0 fail
php test_e2e.php                            ->  HASIL: 100 PASS, 0 FAIL
npx tsc --noEmit                            ->  0 error
npx vite build                              ->  built in 10,80s
php -l pada semua lib/ dan handlers/        ->  tanpa Parse error
```

Bukti API nyata pada server dev `:8011` (kode dari repo ini):

```
kunci listing pertama: id, agendaNumber, ..., dispositionRoute, assigneeUserId, assigneeName, controlLogCount
detail.stageLabel: "Surat diterima"
detail.assignee: {"id":"2fe5e75c...","name":"Staf Uji v2","role":"STAFF"}
detail.canReopen: false
detail.reopenTarget: "DITERUSKAN_KE_KASUBAG"
detail.reopenReasonMin: 10
POST /control/<id>/reopen -> HTTP 403 {"code":"REOPEN_DENIED","fromStage":"DITERIMA"}
```

### 8f. Belum bisa diverifikasi di sesi ini

- **Pengiriman WhatsApp sungguhan**: seluruh uji memakai stub `Whatsapp::$sender`
  (`tests/bootstrap.php` + stub di `run_fase1_check.php`). Yang terverifikasi adalah
  ISI pesan, sasaran, dan urutannya — bukan keterkiriman lewat Fonnte.
- **Percakapan WA end-to-end dengan nomor asli** (Kasubag membalas `1 KEBIJAKAN`
  dari HP). Handler sesi aksi tahap diuji lewat `run_wa_branch_check.php` (6 kasus,
  DB tiruan) dan penulisan tahap diuji lewat MySQL nyata, tetapi keduanya belum
  dijalankan bersamaan di perangkat nyata.
- **Klik manusia pada dialog** Tarik Kembali (browser) dan dropdown Atasan
  Langsung: kontrak API + build sudah hijau, lapisan DOM belum diuji otomatis.
- **Ratifikasi**: seluruh pemetaan role→tahap dan aturan penarikan tetap berstatus
  draf (`V2Workflow::RATIFICATION_NOTICE`).

### 8g. Urutan migrasi saat deploy

Migrasi baru ini **aditif** dan boleh dijalankan kapan saja setelah
`2026_09_24_add_disposition_route.sql`:

```
phpMyAdmin -> simars_v2 -> Import -> api-php/migrations/2026_09_26_add_letter_assignee.sql
```

Baris surat lama diisi otomatis dari disposisi terbaru (`UPDATE ... JOIN` di akhir
berkas). Tanpa migrasi ini, halaman Buku Kendali akan meminta kolom
`assignee_user_id` yang belum ada, jadi jalankan **sebelum** menaikkan versi kode.

## 10. Patch revisi kedua — temuan P1–P8 (sesi 29 Sep 2026)

> **SEMUA keputusan berlabel di bawah ini adalah "DEFAULT SEMENTARA - menunggu
> review pimpinan"** (permintaan eksplisit pemilik proses). Tidak ada yang
> berasal dari SOP/AS/04 atau KMA; semuanya disimpan sebagai KONFIGURASI di
> tabel `workflow_settings` (migrasi `2026_09_29_sop_patch.sql`) sehingga bisa
> diubah dari menu **Pengaturan → Aturan Disposisi** (ADMIN) atau langsung
> `UPDATE workflow_settings ... WHERE id='wf_settings'` — tanpa menyentuh kode.

### 10a. Pemetaan istilah dokumen uji ↔ kode (baca dulu)

| Istilah di dokumen uji | Nama di kode |
| --- | --- |
| pegawai (pemegang surat) | `assignee_user_id` surat (`assigneeUserId`); tahap `DALAM_TINDAK_LANJUT` |
| kepala unit (Kasubag/Panmud) | `V2Workflow::UNIT_HEAD_ROLES` + `roleMatchesUnit()` (peta unit→role) |
| Sekretaris/Panitera | role `SEKRETARIS` / `PANITERA` (meja `DITERUSKAN_KE_SEKRETARIS_PANITERA`) |
| Kasubag Umum | role `KEPALA_SUB_UMUM` (meja `DITERUSKAN_KE_KASUBAG`) |
| "Menunggu disposisi" | tahap `MENUNGGU_DISPOSISI` |
| menu angka WA | sesi aksi tahap `wa_sessions` kind `VERIFIKASI/DEKISION/ARCHIVE` |
| lembar 1 disposisi | kolom `incoming_letters.lembar1_*` |

### 10b. K3 — aturan TOLAK/RECALL (DEFAULT SEMENTARA)

Menggantikan aturan lama "Kasubag Umum boleh menarik surat dari rantai
pelaksanaan" (`run_fase1_check` bagian 7 lama). Implementasi:
`V2Workflow::planReject()` (murni, teruji unit) + `LetterTransition::reopen()`
(satu pintu tulis). Tidak ada edge mundur di `canTransition` — gerak mundur
tidak bisa dipakai lewat `/transition` biasa.

- **TOLAK satu langkah** oleh PEMEGANG, alasan wajib ≥ `tolak_reason_min` (10):
  - pegawai → kepala unit: `DALAM_TINDAK_LANJUT → DITERUSKAN_KE_PELAKSANA`
    (pemegang = assignee surat; **asumsi**: `assignee_user_id` TIDAK dikosongkan
    saat TOLAK supaya jejak "siapa yang menolak" terbaca di kolom Pelaksana;
    kepala unit bisa TUNJUK ulang).
  - kepala unit → Sekretaris/Panitera: `DITERUSKAN_KE_PELAKSANA → DITERUSKAN_KE_SEKRETARIS_PANITERA`
    (pemegang = kepala unit sesuai `unit_tujuan`; bila `unit_tujuan` kosong —
    data lama — semua kepala unit sah, supaya surat tidak buntu).
  - Sekretaris/Panitera → Kasubag Umum: `DITERUSKAN_KE_SEKRETARIS_PANITERA → DITERUSKAN_KE_KASUBAG`.
- **RECALL oleh pengirim**, HANYA selama penerima belum bertindak. Syarat dicek
  dari `letter_control_logs`: baris KEDATANGAN terakhir ke tahap sekarang
  (from ≠ to) oleh pengirim itu, dan TIDAK ada aksi penerima di tempat
  (from = to, mis. `TUNJUK_PEGAWAI`/`DISPOSITION_DECISION`) sejak kedatangan.
  Catatan teknis: id log acak + `created_at` presisi detik membuat "baris
  terakhir" tak bisa diandalkan dalam detik yang sama, maka syarat dihitung
  eksplisit (lihat `reopen()`). **Asumsi**: aksi penerima pada detik yang sama
  dengan kedatangan dianggap "sudah bertindak" (konservatif — recall ditolak).
- **Ketua/WK tidak punya TOLAK** (Q1: arahan final); ketidaksetujuan ditulis
  di isi arahan. RECALL juga tidak (pimpinan bukan pengirim kaki mana pun).
- **ADMIN** boleh mengoreksi kasus khusus dari `REOPEN_ADMIN_STAGES` (action
  `STAGE_REOPEN`). **Konsekuensi**: Kasubag Umum TIDAK lagi bisa menarik surat
  yang sudah diputuskan Sekretaris (di luar K3) — sesuai P3.
- **Semua tolak/recall tercatat** di `letter_control_logs` dengan action
  `STAGE_TOLAK` / `STAGE_RECALL` / `STAGE_REOPEN`: pelaku (`actor_user_id`),
  alasan (`notes`), waktu (`created_at`), tahap asal (`from_stage`), tahap
  tujuan (`to_stage`).
- Jalur: WA kata kunci `TOLAK <agenda> <alasan>` dan web tombol
  "Tolak & kembalikan" (rencana tombol dihitung server di
  `GET /control/:id` → `rejectMode/rejectTarget/rejectLabel`).

### 10c. K5 — jalur web arahan pimpinan (DEFAULT SEMENTARA)

Endpoint `POST /control/:id/arahan` (sudah ada) + panel arahan di Buku Kendali
yang kini menyertakan **unit tujuan opsional** (dropdown, sama seperti penanda
`#KODE_UNIT` di WA). Aturan identik dengan versi WA: isi arahan wajib ≥ 10
karakter (`ARAHAN_REQUIRED`), final tanpa tombol "kembalikan", role
Pimpinan/WK/ADMIN saja (edge `MENUNGGU_KEBIJAKAN_PIMPINAN|DITERUSKAN_KE_SEKRETARIS_PANITERA`).

### 10d. K6 — RAHASIA/SANGAT_RAHASIA (DEFAULT SEMENTARA, toggle `wa_arahan_rahasia_blocked`, default 1)

Perintah `ARAHAN` via WA untuk surat RAHASIA/SANGAT_RAHASIA **ditolak** dengan
balasan "Surat ini berlevel RAHASIA; tindak lanjut hanya lewat aplikasi web".
Isi arahan tidak pernah masuk percakapan WA (balasan sukses tidak mengecho
arahan; perihal ditahan lewat `mustWithholdWaContent`; balasan bot
`buildStageTaskApplied` kini juga menyembunyikan perihal surat RAHASIA —
sebelumnya perihal masih ikut balasan TINDAKAN TERSIMPAN). Toggle bisa
dimatikan di Pengaturan; terbukti di `run_sop_patch_check` T16: meski mati,
perihal tetap tidak bocor.

### 10e. K7 — lembar 1 disposisi (DEFAULT SEMENTARA, toggle `lembar1_wajib`, default 0)

- Kolom baru `incoming_letters.lembar1_diserahkan_oleh / lembar1_diterima_oleh /
  lembar1_diserahkan_at` + endpoint `POST /control/:id/lembar1`
  (`LetterTransition::recordLembar1`; dicatat SEKALI, tidak bisa ditimpa).
- ARSIP tanpa lembar 1: **tidak diblokir** (sesuai keputusan pelonggaran);
  server mengembalikan `warning` (banner kuning di UI + balasan WA) dan log
  `ARSIP_TANPA_LEMBAR1`. Bila toggle `lembar1_wajib` dinyalakan: `DIARSIPKAN`
  ditolak `LEMBAR1_REQUIRED`.
- Panel "Serah-terima lembar 1 disposisi" di Buku Kendali (role yang boleh
  mencatat: ADMIN/ARSIPARIS/SEKRETARIS/PANITERA/kepala unit; penyerah & penerima
  dipilih dari daftar user aktif).

### 10f. Temuan P1–P8 dan penanganannya

- **P1 (menu angka WA)** — BUKTI dari kode: `wabotV2StageReply()` selalu
  memakai `$task = $queue[0]` (tugas TERDEPAN antrean) dan `pushActionTask()`
  menambah tugas baru di EKOR antrean — angka TIDAK terikat agenda tertentu;
  dengan ≥2 surat aktif balasan angka bisa salah mengenai surat. KEPUTUSAN:
  penerimaan balasan angka sesi aksi tahap **dinonaktifkan** (default
  `wa_stage_number_reply = 0`); menu menjadi daftar tanpa nomor + arahan ke
  kata kunci + nomor agenda; balasan angka dijawab teks penjelasan
  (`Wabot::buildStageNumberDisabledText`). Sesi LEADER/EMPLOYEE tetap menerima
  angka karena terikat SATU surat/disposisi di context-nya (aman).
- **P2 (pemilik tahap awal + guard keputusan)** — `STAGE_OWNER_ROLES`
  `MENUNGGU_PENGARAHAN`/`DIBACA_PENGARAH` kini hanya `KEPALA_SUB_UMUM`
  (Sekretaris menjadi pemegang mulai langkah 13 = `MENUNGGU_DISPOSISI` dst);
  Sekretaris TIDAK sengaja diberi WA info di tahap awal (dihapus saja — lebih
  bersih daripada "info tanpa menu", karena ia juga bukan pengarah surat).
  Tambahan guard server: `decideRoute()` menolak `STAGE_NOT_READY` bila surat
  belum di `DECISION_ALLOWED_STAGES` (MENUNGGU_DISPOSISI/TERREGISTRASI/
  DIDISPOSISIKAN/DITERUSKAN_KE_KASUBAG/DITERUSKAN_KE_SEKRETARIS_PANITERA);
  `TERUSKAN` ditolak bila bukan dari `TERUSKAN_ALLOWED_STAGES`; transisi ke
  `MENUNGGU_DISPOSISI` ditolak `REKOMENDASI_REQUIRED` tanpa rekomendasi rute.
- **P3 (TOLAK)** — lihat 10b (K3). Setiap role penerima punya jalan sah
  mengembalikan (pegawai/kepala unit/Sekretaris/Panitera) atau ditolak dengan
  alasan tertulis (Ketua/WK = Q1 final; tidak ada penerima lain).
- **P4 (unit pada ARAHAN)** — tebakan "kata terakhir mirip kode unit" DIGANTI
  penanda eksplisit `#KODE_UNIT` (`Wabot::parseArahanUnit` +
  `resolveUnitFromTokens`). Typo → balasan error `UNIT_INVALID`, data tidak
  berubah; tanpa penanda → arahan tersimpan TANPA unit (Sekretaris memilih
  saat TERUSKAN; tidak ada penerusan diam-diam). Bonus perbaikan bug laten:
  `cleanText()` membuang `_` (penanda italic WA) sehingga `KASUBAG_UMUM`
  datang sebagai dua token — kini token kode unit digabung ulang otomatis
  untuk ARAHAN/LANGSUNG/TERUSKAN.
- **P5 (web arahan)** — lihat 10c. **P6 (RAHASIA)** — lihat 10d.
  **P7 (lembar 1)** — lihat 10e.
- **P8 (Arsiparis)** — `ARSIPARIS` ditambahkan ke
  `STAGE_OWNER_ROLES['MENUNGGU_PENGARSIPAN']` (role lain tetap — tidak
  kehilangan notifikasi; terbukti di `run_fase1_check` bagian P8) dan ke
  `Wabot::STAGE_ACTOR_ROLES`. Soal key 'MENGGU_PENGARSIPAN': **typo itu hanya
  ada di dokumen/ringkasan lama — TIDAK ADA di kode** (dibuktikan dengan
  pencarian `MENGGU_PENGARSIPAN` di seluruh repo hasil kosong; kode memakai
  `MENUNGGU_PENGARSIPAN` yang benar). Tetap ditambahkan test enum-key yang
  gagal bila ada key tak dikenali:
  `LetterTransitionTest::testWaStageMapsUseOfficialStages` (KIND_BY_STAGE via
  refleksi, STAGE_OWNER_ROLES, DISPOSITION_EVENT_STAGES) +
  `V2WorkflowTest::testRejectMapsUseOfficialStages`.

### 10g. Migrasi `2026_09_29_sop_patch.sql`

- Tabel `workflow_settings` (satu baris `wf_settings`): `tolak_reason_min` (10),
  `wa_arahan_rahasia_blocked` (1), `lembar1_wajib` (0), `wa_stage_number_reply` (0).
- Kolom `incoming_letters`: `lembar1_diserahkan_oleh`, `lembar1_diterima_oleh`,
  `lembar1_diserahkan_at`.
- Idempoten (IF NOT EXISTS + INSERT IGNORE); dipasang otomatis oleh
  `setup_test_db.php` (bagian 3f). Terbukti: DB `simars_v2` setelah migrasi tetap
  memuat 91 surat lama tanpa rusak (T10).

### 10h. Bukti verifikasi sesi 29 Sep 2026

Perintah ringkas + hasil (semua dijalankan ulang setelah edit terakhir):

- `php -l` semua file yang diubah → "No syntax errors detected".
- `cd api-php && vendor/bin/phpunit --no-coverage` → **OK (94 tests, 682 assertions)**
  (naik dari 89/602: +`testPlanRejectK3`, +`testRejectMapsUseOfficialStages`,
  +`testParseArahanUnit`, +`testResolveUnitFromTokens`,
  +`testWaStageMapsUseOfficialStages`, revisi assert reopen/menu).
- `php api-php/tests/run_fase1_check.php` → **48 PASS, 0 FAIL** (K3 a–f + P8).
- `php api-php/tests/run_sop_patch_check.php` → **38 PASS, 0 FAIL**
  (T7/T9/T12/T14/T16 — handler kata kunci WA ASLI + stub pengirim Fonnte,
  DB uji terpisah `simars_v2_test2`, TIDAK ada WhatsApp nyata).
- `php setup_test_db.php` → SETUP_DB_OK (migrasi 2026_09_29 diterapkan).
- `php test_e2e.php` (server `127.0.0.1:8011`) → **107 PASS, 0 FAIL**
  (naik dari 105: +2 cek rekomendasi pra-MENUNGGU_DISPOSISI).
- `php test_disposisi_sop.php` → **16 PASS, 0 FAIL, 0 SKIP** (T1–T16;
  sebelumnya T7/T9 "TIDAK BISA DIVERIFIKASI" kini terverifikasi via stub/mock).
- `run_auth_check` 57/0 · `run_php_compat_check` 21/0 · `run_status_dm_check`
  SEMUA LULUS (13) · `run_turnstile_check` 31/0 · `run_upload_check` 42/0 ·
  `run_wa_bot_check` 132/0 · `run_wa_branch_check` SEMUA KASUS LULUS
  (3 kasus menu-angka diperbarui ke kontrak P1 — angka dinonaktifkan —
  bukan dihapus).
- `npx tsc --noEmit` → 0 error; `npx vite build` → built in 8.16s.

### 10i. Yang TETAP belum bisa diverifikasi otomatis

- Klik manusia di browser pada panel baru (form arahan+unit, panel lembar 1,
  toggle Pengaturan) — hanya bisa diuji manual.
- Pengiriman Fonnte NYATA (sesuai aturan uji: tidak boleh ke nomor pegawai
  asli) — menunggu tes manual akhir atas permintaan pemilik proses.
- Ratifikasi pemilik proses untuk semua item "DEFAULT SEMENTARA" (K3/K5/K6/K7,
  P1) dan `RATIFICATION_NOTICE` yang sudah ada.

