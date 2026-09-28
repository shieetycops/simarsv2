# Spesifikasi Form & Fitur — SIMARS (Sistem Informasi Manajemen Arsip Surat)

Dokumen ini melengkapi `Plan_Alur_Disposisi_WA_Web.md` (alur/state machine). Fokus di sini: **field per form, klasifikasi, upload, dashboard, notifikasi, dan pengaturan** — supaya tim/agent bisa langsung bikin skema database & UI tanpa nebak-nebak.

> Catatan: repo `simarsv2` yang sudah ada sebelumnya punya workflow 17-tahap yang lebih detail dari draf alur awal kita, dan sudah eksplisit memisahkan **level keamanan** (4 level resmi KMA 131) dari **urgensi** (Biasa/Penting/Segera) — pemisahan itu benar dan dipakai juga di spek ini. Kalau mau mulai dari 0, boleh pakai struktur data lama itu sebagai referensi, bukan berarti harus dibuang total.

---

## 1. Daftar Modul/Menu

| Modul | Isi |
|---|---|
| Dashboard | Ringkasan & antrian kerja per role |
| Surat Masuk | Registrasi + tracking disposisi (mengikuti alur di file sebelumnya) |
| Surat Keluar | Registrasi + ambil nomor surat |
| Arsip Surat | Surat yang sudah selesai/diarsipkan, searchable |
| Notifikasi | Pusat notifikasi in-app + log WA |
| Pengaturan → WhatsApp | Konfigurasi Fonnte |
| Pengaturan → Pengguna | CRUD user, role, no HP, hak akses |
| Pengaturan → Instansi | Data pengadilan, logo, kop surat |
| Pengaturan → Klasifikasi Arsip | Master kode HK/KP/dst + approval kode baru |
| Audit Log | Jejak semua aksi (siapa, kapan, apa) |

---

## 2. Form Surat Masuk

| Field | Tipe | Wajib? | Keterangan |
|---|---|---|---|
| Nomor Agenda | Auto | — | Format `AGD/[tahun]/[nomor urut 4 digit]`, di-generate sistem saat simpan pertama |
| Tanggal Surat | Date | ✅ | Tanggal yang tertulis di surat asli |
| Tanggal Terima | Date | ✅ | Default: hari ini |
| Asal/Pengirim Surat | Text | ✅ | Nama instansi/orang pengirim |
| Perihal | Text | ✅ | |
| Sumber Penerimaan | Dropdown | ✅ | Pos / Kurir / E-mail / Faximile / Datang Langsung / Lainnya |
| Jenis Naskah Dinas | Dropdown | ✅ | Lihat daftar di §3 |
| Jenis Surat | Radio | ✅ | Dinas / Pribadi (langkah 3 SOP — surat pribadi tidak lanjut ke alur disposisi resmi) |
| Urgensi | Dropdown | ✅ | Biasa / Penting / Segera — **bukan** level keamanan |
| Klasifikasi Keamanan | Dropdown | ✅ | Biasa / Terbatas / Rahasia / Sangat Rahasia (4 level resmi KMA 131 BAB V) |
| Kode Klasifikasi Arsip | Dropdown + input manual | ✅ | Primer wajib dari 13 kode resmi (§4); sekunder/tersier boleh manual → status `PENDING_VALIDATION` sampai divalidasi arsiparis |
| Unit/Pejabat Tujuan | Dropdown | ✅ | Diisi setelah tahap verifikasi alamat |
| Ringkasan Isi | Textarea | ✅ (kecuali Rahasia/Sangat Rahasia → opsional, boleh dikosongkan agar tak bocor lewat sistem non-aman) | |
| Lampiran Berkas | Upload (multi) | Opsional | Lihat §5 |
| Catatan Internal | Textarea | Opsional | |
| Status/Tahap | Auto | — | Mengikuti state machine, read-only bagi user |

**Validasi tambahan**: kalau Klasifikasi Keamanan = Rahasia/Sangat Rahasia, field Ringkasan Isi & Lampiran wajib ditandai "hanya bisa dilihat via Web dengan login", dan sistem **wajib menahan diri** mengirim isi field ini lewat WA (lihat file alur sebelumnya §6.6).

---

## 3. Jenis Naskah Dinas (Dropdown, dari KMA 131 BAB II)

Dipakai sama di form Surat Masuk maupun Surat Keluar:

**Naskah Dinas Arahan**: Peraturan · Instruksi · Surat Edaran · Standar Operasional Prosedur (SOP) · Keputusan · Surat Perintah · Surat Tugas

**Naskah Dinas Korespondensi**: Memorandum · Nota Dinas · Surat Dinas · Surat Undangan Internal · Surat Undangan Eksternal · Nota Kesepahaman (MoU) · Surat Perjanjian Kerja Sama (PKS) · Surat Keterangan · Surat Pengantar · Disposisi

**Naskah Dinas Khusus**: Pengumuman · Maklumat · Telaahan Staf · Laporan · Notula

*(+ opsi "Lainnya" dengan input teks bebas untuk kasus di luar daftar)*

---

## 4. Kode Klasifikasi Arsip (dari SK Sekma 627/2023 Lampiran I)

13 kode primer resmi — **jangan biarkan user free-text di level primer**, harus dropdown tertutup:

| Kode | Kategori |
|---|---|
| HK | Hukum |
| HM | Humas dan Protokol |
| KA | Kearsipan |
| KP | Kepegawaian |
| PL | Perlengkapan |
| PS | Perpustakaan |
| PW | Pengawasan |
| RT | Rumah Tangga |
| TI | Teknologi Informasi |
| DL | Pendidikan dan Pelatihan |
| RA | Perencanaan Anggaran |
| KU | Keuangan |
| OT | Organisasi Tatalaksana |

Sekunder/tersier (mis. `HK1.1.2`) jumlahnya ratusan — buat **master table** yang bisa diisi bertahap oleh arsiparis, bukan di-hardcode semua di awal. Kode yang dipakai di luar master ditandai `PENDING_VALIDATION`, tetap tersimpan di surat tapi muncul di antrian validasi arsiparis.

**Klasifikasi Keamanan** (4 level, dipakai di Surat Masuk & Keluar):
`BIASA` → `TERBATAS` → `RAHASIA` → `SANGAT_RAHASIA` — makin tinggi levelnya, makin ketat pembatasan siapa yang boleh lihat isi (lihat §7c).

---

## 5. Upload Berkas

| Aturan | Rekomendasi *(bukan dari SOP/KMA — kebijakan teknis, boleh disesuaikan)* |
|---|---|
| Tipe file diizinkan | PDF (utama), JPG/PNG (scan/foto) |
| Ukuran maksimal | 10 MB per file, multi-file per surat |
| Penamaan file di storage | Hash/nama kanonik (bukan nama asli) — nama asli tetap disimpan di DB buat ditampilkan ke user |
| Preview | Inline viewer untuk PDF & gambar, jangan paksa download |
| Klasifikasi Rahasia/Sangat Rahasia | Wajib watermark otomatis (nama pengakses + waktu) tiap kali dibuka, view-only, tautan bertanda tangan (HMAC/token) yang expire |
| Retensi | Berkas tidak boleh terhapus permanen walau surat "dihapus" dari UI — arsip surat dinas resmi, soft-delete saja |

---

## 6. Form Surat Keluar

| Field | Tipe | Wajib? | Keterangan |
|---|---|---|---|
| Nomor Surat | Auto/manual | ✅ | Lihat fitur "Ambil Nomor" di bawah |
| Tanggal Surat | Date | ✅ | |
| Jenis Naskah Dinas | Dropdown | ✅ | Sama daftar §3 |
| Tujuan/Kepada | Text | ✅ | |
| Perihal | Text | ✅ | |
| Klasifikasi Keamanan | Dropdown | ✅ | Sama 4 level §4 |
| Kode Klasifikasi Arsip | Dropdown + manual | ✅ | Sama §4 |
| Konseptor/Penyusun | User picker | ✅ | Siapa yang menyusun naskah |
| Penandatangan | User picker | ✅ | Terkait wewenang a.n./u.b. (BAB VII KMA 131) |
| Status Penandatanganan | Auto | — | Draft → Menunggu TTD → Ditandatangani → Terkirim |
| Lampiran | Upload (multi) | Opsional | Sama §5 |
| Catatan | Textarea | Opsional | |

### Fitur "Ambil Nomor Surat Keluar"
Ini fitur yang paling gampang bikin data korup kalau race condition — desain wajib:
1. Nomor **di-generate dan langsung di-*lock*** ke DB saat tombol "Ambil Nomor" ditekan (jangan generate on-the-fly pas submit final, karena 2 user bisa ambil nomor sama).
2. Format umum naskah dinas Indonesia: `[nomor urut]/[kode klasifikasi]/[bulan romawi]/[tahun]` — **format pasti perlu dikonfirmasi ke Sekretaris/Panitera PA Pasarwajo**, karena KMA 131 BAB III menyebut tiap jenis naskah dinas punya format nomor sendiri-sendiri (Instruksi, SE, Keputusan, Surat Perintah, dll masing-masing beda pola) — ini bukan satu format seragam.
3. Kalau surat batal dibuat setelah nomor diambil: nomor **tidak boleh dipakai ulang diam-diam** — tandai `DIBATALKAN` dengan catatan alasan, supaya kalau ada audit nomor urut, gap-nya bisa dijelaskan.

---

## 7. Dashboard (per Role)

| Role | Widget Utama |
|---|---|
| Staf Pelaksana | Antrian surat baru untuk disortir/dicatat; status arsip pending |
| Kasubag Umum | Antrian verifikasi surat masuk |
| Sekretaris/Panitera | Antrian keputusan (kebijakan/langsung); status semua disposisi aktif |
| Ketua/Wakil Ketua | Antrian surat yang butuh arahan/kebijakan mereka saja |
| Kasubag/Panmud | Antrian tindak lanjut unit + daftar pegawai yang sedang dikerjakan |
| Pegawai Pelaksana | Tugas pribadi yang ditunjuk ke mereka |
| Admin | Semua di atas + statistik total (jumlah surat/bulan, breakdown status, breakdown klasifikasi keamanan) |

Tambahan yang berguna di semua role: **alert surat yang mengendap lama** di satu tahap (SLA internal, bukan dari SOP — perlu angka jam/hari disepakati sendiri oleh pimpinan, SOP hanya kasih total waktu proses manual 135 menit, bukan SLA per tahap digital).

---

## 8. Notifikasi

**In-app**: ikon lonceng, daftar notifikasi, tandai terbaca, klik → langsung ke surat terkait.

**WhatsApp (Fonnte)**: dikirim di setiap transisi tahap yang butuh aksi manusia (lihat format pesan di `Plan_Alur_Disposisi_WA_Web.md` §5). Aturan tambahan:
- Level Biasa/Terbatas: boleh sertakan ringkasan perihal.
- Level Rahasia/Sangat Rahasia: **hanya notifikasi kosong** ("Ada surat #xxx menunggu tindak lanjut Anda") + link ke web yang wajib login.
- Semua pengiriman WA dicatat di log (sukses/gagal, sisa kuota Fonnte) — dipakai buat troubleshoot kalau notif nggak sampai.

---

## 9. Pengaturan

### a. Pengaturan WhatsApp (Fonnte)

Berdasarkan API resmi Fonnte (`api.fonnte.com`):

| Field Pengaturan | Keterangan |
|---|---|
| Token Device | Token dari dashboard Fonnte, dipakai sebagai header `Authorization` di tiap request |
| Nama Device | Label perangkat (info saja) |
| Nomor WA Pengirim | Nomor yang terhubung ke device Fonnte (read-only, info dari akun Fonnte) |
| Webhook URL (masuk) | URL endpoint di aplikasi kita, didaftarkan ke Fonnte lewat API `update-device` (param `webhook`) — ini yang nerima balasan WA (mis. `PROSES #123`, `SELESAI #123`) |
| Webhook Secret | Kunci rahasia untuk validasi payload webhook masuk (cocokkan dengan `?k=<secret>` di URL, biar orang luar nggak bisa spoof) |
| Country Code | Default `62`, dipakai buat normalisasi nomor HP dari form `08xxx` → `62xxx` |
| Delay Pengiriman | Detik jeda antar pesan (disarankan Fonnte sendiri untuk hindari nomor kena banned massal) |
| Toggle Aktif/Nonaktif | Matikan sementara semua notif WA tanpa hapus konfigurasi |
| Tombol Test Kirim | Kirim pesan uji ke 1 nomor, buat verifikasi token & device masih aktif |
| Log Pengiriman | Riwayat tiap pesan terkirim: target, isi, status sukses/gagal, sisa kuota |

### b. Manajemen Pengguna (CRUD)

| Field | Keterangan |
|---|---|
| Nama Lengkap | |
| NIP | |
| Username | Unik |
| Password | Di-hash (bcrypt), wajib ganti saat akun baru dibuat pertama kali |
| Role | Lihat matriks §9c |
| Unit/Jabatan | Untuk penentuan "unit tujuan" di alur disposisi |
| Nomor HP (WA) | Wajib format `62xxx`, dipakai Fonnte buat notif — validasi format saat input |
| E-mail | Opsional |
| Status | Aktif / Nonaktif (nonaktifkan, jangan hard-delete — biar histori disposisi lama tidak kehilangan referensi user) |

Fitur pendukung: reset password oleh admin, dan idealnya riwayat login (kapan, dari IP mana) buat keperluan audit.

### c. Matriks Hak Akses (Role × Fitur)

| Role | Surat Masuk (input) | Verifikasi | Putuskan Kebijakan/Langsung | Beri Arahan | Tunjuk Pelaksana | Eksekusi/Lapor | Arsipkan | Surat Keluar | Ambil Nomor | Kelola User | Lihat Rahasia/S.Rahasia |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Staf Pelaksana | ✅ | — | — | — | — | — | ✅ (langkah 18) | ✅ | ✅ | — | — |
| Kasubag Umum | — | ✅ | — | — | — | — | — | — | — | — | — |
| Sekretaris/Panitera | — | — | ✅ | — | — | — | — | ✅ | ✅ | — | ✅ |
| Ketua/Wakil Ketua | — | — | — | ✅ | — | — | — | ✅ (ttd) | — | — | ✅ |
| Kasubag/Panmud | — | — | — | — | ✅ | ✅ | — | — | — | — | tergantung unit |
| Pegawai Pelaksana | — | — | — | — | — | ✅ | — | — | — | — | — |

Ini **draf awal, wajib diratifikasi pimpinan PA Pasarwajo** sebelum jadi acuan final — sama seperti disclaimer di repo lama ("pemetaan role → aktor SOP masih draf").

### d. Pengaturan Instansi
Nama pengadilan, alamat, nomor telepon/fax resmi, logo (buat kop surat & watermark lampiran), nama & NIP pejabat aktif per jabatan (buat auto-fill penandatangan).

### e. Master Klasifikasi Arsip
List 13 kode primer (fix, dari §4) + tabel kode sekunder/tersier yang bisa ditambah bertahap oleh role Admin/Arsiparis, dengan status `OFFICIAL` (sudah divalidasi) vs `PENDING_VALIDATION` (dipakai user tapi belum dicek arsiparis).

---

## 10. Arsip Surat

- Daftar semua surat berstatus akhir (`SELESAI_DIARSIPKAN`), searchable & filterable: nomor agenda, kode klasifikasi, jenis naskah, rentang tanggal, klasifikasi keamanan.
- Export daftar ke Excel/PDF untuk laporan.
- Opsional: field lokasi fisik arsip (nomor ordner/rak) kalau masih ada arsip fisik paralel dengan e-arsip.

---

## 11. Hal yang Perlu Dikonfirmasi Sebelum Mulai Coding

Selain 3 poin di `Plan_Alur_Disposisi_WA_Web.md` §7, tambahan dari spesifikasi form ini:

1. **Format nomor surat keluar per jenis naskah** — KMA 131 BAB III bilang tiap jenis (Instruksi/SE/Keputusan/Surat Perintah/dst) punya pola nomor sendiri-sendiri. Perlu daftar pasti tiap pola dari Sekretaris/Panitera sebelum fitur "Ambil Nomor" di-build, supaya nggak asal pakai 1 format buat semua jenis.
2. **Siapa yang boleh menandai kode arsip sebagai `OFFICIAL`** dari status `PENDING_VALIDATION` — apakah perlu role Arsiparis terpisah, atau cukup Admin.
3. **Angka SLA reminder per tahap** (kalau mau dipakai) — ini kebijakan internal, bukan dari SOP/KMA MA.
