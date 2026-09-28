<?php
// Konstanta domain v2. Dipakai API dan UI sebagai kontrak yang sama.
class V2Workflow
{
    public const SECURITY_LEVELS = ['BIASA', 'TERBATAS', 'RAHASIA', 'SANGAT_RAHASIA'];

    // 4 level keamanan resmi KMA 131/2023 Bab V. PENTING/SEGERA TIDAK termasuk;
    // urgensi dicatat terpisah di kolom urgency_level.
    public const RAHASIA_LEVELS = ['RAHASIA', 'SANGAT_RAHASIA'];

    // 13 kategori primer resmi Lampiran I SK Sekretaris MA 627/2023.
    public const ARCHIVE_PRIMARIES = [
        'HK' => 'Hukum',
        'HM' => 'Humas dan Protokol',
        'KA' => 'Kearsipan',
        'KP' => 'Kepegawaian',
        'PL' => 'Perlengkapan',
        'PS' => 'Perpustakaan',
        'PW' => 'Pengawasan',
        'RT' => 'Rumah Tangga',
        'TI' => 'Teknologi Informasi',
        'DL' => 'Pendidikan dan Pelatihan',
        'RA' => 'Perencanaan Anggaran',
        'KU' => 'Keuangan',
        'OT' => 'Organisasi Tatalaksana',
    ];

    // Role yang boleh MENGAKSES surat rahasia. Keputusan sementara (masuk akal
    // untuk struktur PA), menunggu ratifikasi pemilik proses.
    public const RAHASIA_ACCESS_ROLES = ['ADMIN', 'PIMPINAN', 'WAKIL_KETUA', 'SEKRETARIS', 'PANITERA'];

    // ---------- Decision point SOP/AS/04 langkah 12-13 ----------
    // SOP Penanganan Surat Masuk PA Pasarwajo menaruh keputusan "surat ini perlu
    // kebijakan Ketua/Wakil Ketua atau tidak" pada Sekretaris/Panitera, BUKAN pada
    // pimpinan maupun Kasubag Umum. Pimpinan hanya menerima surat yang memang
    // butuh keputusannya. Kasubag Umum (langkah 12) hanya memberi REKOMENDASI
    // rute + alasan wajib; keputusan FINAL ada di Sekretaris/Panitera (langkah 13).
    // Karena itu transisi MENUNGGU_DISPOSISI -> DIDISPOSISIKAN dipegang
    // R_PANITERA (lihat allowedRolesForTransition), dan wajib menyertakan
    // pilihan rute.
    public const DECISION_POINT_ROLES = ['ADMIN', 'SEKRETARIS', 'PANITERA'];

    // P2 (revisi kedua): tahap yang SAH untuk keputusan rute final (KEBIJAKAN/
    // LANGSUNG / TERUSKAN). Sebelum revisi ini decideRoute tidak memeriksa
    // tahap, sehingga Sekretaris bisa merekam keputusan saat surat masih di
    // tahap awal (Disortir/pengarahan) — data berubah tanpa perpindahan tahap.
    // TERREGISTRASI ikut sah karena jalur pintas langkah 1-13 boleh langsung
    // DIDISPOSISIKAN; DITERUSKAN_KE_KASUBAG/DITERUSKAN_KE_SEKRETARIS_PANITERA
    // sah untuk kasus surat dikembalikan (TOLAK/RECALL) lalu diputuskan ulang.
    public const DECISION_ALLOWED_STAGES = [
        'MENUNGGU_DISPOSISI', 'TERREGISTRASI', 'DIDISPOSISIKAN',
        'DITERUSKAN_KE_KASUBAG', 'DITERUSKAN_KE_SEKRETARIS_PANITERA',
    ];

    // P2: TERUSKAN (melaksanakan arahan ke unit) hanya dari meja
    // Sekretaris/Panitera setelah arahan turun, atau dari DIDISPOSISIKAN
    // (rute LANGSUNG yang belum diteruskan).
    public const TERUSKAN_ALLOWED_STAGES = ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DIDISPOSISIKAN'];

    // Role yang boleh memberi REKOMENDASI rute (SOP/AS/04 langkah 12):
    // Kasubag Umum + Sekretaris/Panitera + Admin. Rekomendasi bukan keputusan final.
    public const REKOMENDASI_ROLES = ['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'];

    // Role yang boleh menetapkan RUTE FINAL (SOP/AS/04 langkah 13):
    // HANYA Sekretaris/Panitera (+ Admin). Kasubag/pegawai/Ketua DITOLAK (403/422).
    public const FINAL_ROUTE_ROLES = ['ADMIN', 'SEKRETARIS', 'PANITERA'];

    // Role pencatat surat yang berwenang menandai DIARSIPKAN (SOP/AS/04 langkah 17-18):
    // ARSIPARIS (+ ADMIN sebagai fallback). Pegawai pelaksana TIDAK BOLEH.
    // Q2 locked: ARSIPARIS.
    public const ARSIPARIS_ROLES = ['ADMIN', 'ARSIPARIS'];

    // Unit pelaksana tujuan yang sah (Kasubag/Panmud mana).
    // Dipilih Sekretaris/Panitera (jalur LANGSUNG) atau ditulis Ketua/WK di arahan
    // atau dipilih Sekretaris/Panitera saat melaksanakan arahan (jalur KEBIJAKAN).
    public const UNIT_TUJUAN_LIST = [
        'KASUBAG_UMUM', 'KASUBAG_KEPEGAWAIAN', 'KASUBAG_PTIP',
        'PANMUD_PERMOHONAN', 'PANMUD_GUGATAN', 'PANMUD_HUKUM',
    ];

    // Role kepala unit pelaksana yang boleh menunjuk pegawai (SOP langkah 17):
    // Kasubag (Umum/Kepegawaian/PTIP) + Panmud (Permohonan/Gugatan/Hukum) + Admin.
    public const UNIT_HEAD_ROLES = [
        'ADMIN', 'KEPALA_SUB_UMUM', 'KEPALA_SUB_KEPEGAWAIAN', 'KEPALA_SUB_PTIP',
        'PANITERA_MUDA_PERMOHONAN', 'PANITERA_MUDA_GUGATAN', 'PANITERA_MUDA_HUKUM',
    ];

    // Rute keputusan Sekretaris/Panitera:
    //   KEBIJAKAN = naik ke Ketua/Wakil Ketua untuk arahan/kebijakan.
    //   LANGSUNG  = diteruskan langsung ke unit pelaksana (Kasubag/Panmud).
    public const DISPOSITION_ROUTES = ['KEBIJAKAN', 'LANGSUNG'];

    public const DISPOSITION_ROUTE_LABELS = [
        'KEBIJAKAN' => 'Perlu kebijakan pimpinan',
        'LANGSUNG' => 'Langsung ke unit pelaksana',
    ];

    // Label tombol per rute pada tahap DIDISPOSISIKAN.
    public const DIDISPOSISIKAN_ACTION_LABELS = [
        'DIDISPOSISIKAN|MENUNGGU_KEBIJAKAN_PIMPINAN' => 'Teruskan ke pimpinan (kebijakan)',
        'DIDISPOSISIKAN|DITERUSKAN_KE_KASUBAG' => 'Teruskan via Kasubag Umum',
        'DIDISPOSISIKAN|DITERUSKAN_KE_SEKRETARIS_PANITERA' => 'Teruskan ke Sekretaris/Panitera',
        'DIDISPOSISIKAN|DITERUSKAN_KE_PELAKSANA' => 'Teruskan langsung ke pelaksana',
    ];

    // Catatan wajib selama matriks/pemetaan role belum diratifikasi pemilik proses.
    public const RATIFICATION_NOTICE = 'Draf — belum diratifikasi pemilik proses (SOP/AS/04 & SK 627/2023).';

    /**
     * Tahap lanjutan YANG SAH DARI TAHAP DIDISPOSISIKAN menurut rute keputusan
     * Sekretaris/Panitera. [] = tidak ada pembatasan.
     *
     * PENTING: penguncian ini hanya berlaku pada percabangan tepat sesudah
     * DIDISPOSISIKAN. Setelah surat meninggalkan DIDISPOSISIKAN, rute berubah
     * menjadi catatan historis dan tahap berikutnya kembali mengikuti state
     * machine biasa (kalau tidak, surat "LANGSUNG" akan selamanya terkurung di
     * DITERUSKAN_KE_PELAKSANA dan tidak bisa maju ke DALAM_TINDAK_LANJUT).
     *
     * Catatan kejujuran sumber: yang PASTI dari SOP/AS/04 adalah langkah 13
     * dipegang Sekretaris/Panitera; urutan aktor langkah 14-17 masih ditandai
     * "belum bisa dipastikan" pada docs/Ringkasan_SOP_04_Penanganan_Surat_Masuk.md
     * (perlu verifikasi ke flowchart asli). Karena itu penguncian cabang di sini
     * adalah keputusan DESAIN yang konsisten dengan narasi ("kebijakan" naik ke
     * pimpinan; "langsung" tidak lewat pimpinan) dan wajib diratifikasi pemilik
     * proses -- lihat RATIFICATION_NOTICE.
     *
     * @return string[]
     */
    public static function stagesAllowedByRoute(?string $route): array
    {
        $route = $route === null ? null : strtoupper(trim($route));
        // Rute mengunci percabangan TEPAT sesudah DIDISPOSISIKAN:
        // KEBIJAKAN -> ke Sekretaris (lalu ke pimpinan); LANGSUNG -> ke Sekretaris
        // (lalu ke unit). Keduanya wajib lewat Sekretaris/Panitera (langkah 13).
        if ($route === 'KEBIJAKAN') return ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'];
        if ($route === 'LANGSUNG') return ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'];
        return [];
    }

    /**
     * Validasi pilihan rute keputusan. Return [ok, errorMessage].
     * Wajib diisi dan harus salah satu dari DISPOSITION_ROUTES.
     */
    public static function validateDispositionRoute(?string $route): array
    {
        $raw = $route === null ? '' : strtoupper(trim($route));
        if ($raw === '') {
            return [false, 'Keputusan Sekretaris/Panitera wajib diisi: KEBIJAKAN (perlu arahan pimpinan) atau LANGSUNG (langsung ke unit pelaksana).'];
        }
        if (!in_array($raw, self::DISPOSITION_ROUTES, true)) {
            return [false, 'Keputusan tidak dikenal. Pilihan sah: ' . implode(', ', self::DISPOSITION_ROUTES) . '.'];
        }
        return [true, null];
    }

    /**
     * Validasi REKOMENDASI rute dari Kasubag Umum (SOP/AS/04 langkah 12).
     * Rekomendasi: KEBIJAKAN/LANGSUNG + alasan wajib (min 10 karakter).
     * Return [ok, errorMessage].
     */
    public static function validateRekomendasi(?string $route, ?string $notes): array
    {
        [$ok, $err] = self::validateDispositionRoute($route);
        if (!$ok) {
            return [false, 'Rekomendasi Kasubag wajib diisi: KEBIJAKAN atau LANGSUNG.'];
        }
        $n = trim((string) $notes);
        if ($n === '') {
            return [false, 'Alasan rekomendasi wajib diisi (tercatat di riwayat Buku Kendali).'];
        }
        if (strlen($n) < 10) {
            return [false, 'Alasan rekomendasi minimal 10 karakter agar jelas saat diaudit.'];
        }
        return [true, null];
    }

    /**
     * Apakah role boleh memberi REKOMENDASI rute? (Kasubag + Sekretaris/Panitera + Admin)
     */
    public static function canGiveRekomendasi(?string $role): bool
    {
        return in_array((string) $role, self::REKOMENDASI_ROLES, true);
    }

    /**
     * Apakah role boleh menetapkan RUTE FINAL? (HANYA Sekretaris/Panitera + Admin)
     * Kasubag/pegawai/Ketua DITOLAK.
     */
    public static function canSetFinalRoute(?string $role): bool
    {
        return in_array((string) $role, self::FINAL_ROUTE_ROLES, true);
    }

    /**
     * Validasi UNIT TUJUAN (Kasubag/Panmud mana). Wajib diisi sebelum masuk
     * tahap unit pelaksana. Return [ok, errorMessage].
     */
    public static function validateUnitTujuan(?string $unit): array
    {
        $raw = $unit === null ? '' : strtoupper(trim($unit));
        if ($raw === '') {
            return [false, 'Unit tujuan wajib dipilih (Kasubag/Panmud mana) sebelum surat diteruskan ke unit pelaksana.'];
        }
        if (!in_array($raw, self::UNIT_TUJUAN_LIST, true)) {
            return [false, 'Unit tujuan tidak dikenal. Pilihan sah: ' . implode(', ', self::UNIT_TUJUAN_LIST) . '.'];
        }
        return [true, null];
    }

    /**
     * Validasi ARAHAN pimpinan (SOP/AS/04 langkah 14). Wajib diisi, min 10 karakter.
     * Q1 locked: arahan = final, tidak ada opsi "kembali revisi setelah arahan".
     * Return [ok, errorMessage].
     */
    public static function validateArahan(?string $arahan): array
    {
        $a = trim((string) $arahan);
        if ($a === '') {
            return [false, 'Arahan pimpinan wajib diisi (inti keputusan kebijakan, SOP/AS/04 langkah 14).'];
        }
        if (strlen($a) < 10) {
            return [false, 'Arahan pimpinan minimal 10 karakter agar jelas saat dilaksanakan.'];
        }
        return [true, null];
    }

    /**
     * Apakah role boleh menandai DIARSIPKAN? (HANYA ARSIPARIS + Admin, Q2 locked)
     */
    public static function canMarkArchived(?string $role): bool
    {
        return in_array((string) $role, self::ARSIPARIS_ROLES, true);
    }

    /**
     * Apakah role adalah kepala unit pelaksana yang boleh menunjuk pegawai? (langkah 17)
     */
    public static function isUnitHead(?string $role): bool
    {
        return in_array((string) $role, self::UNIT_HEAD_ROLES, true);
    }

    /** Label tombol/keterangan untuk sebuah transisi (fallback: nama tahap). */
    public static function transitionLabel(string $from, string $to): string
    {
        return self::DIDISPOSISIKAN_ACTION_LABELS["$from|$to"] ?? $to;
    }

    /**
     * Apakah konten surat (perihal/ringkasan/lampiran) harus DITAHAN dari
     * notifikasi WhatsApp. KMA 131/2023 BAB V + SK 627/2023 Lampiran II:
     * Rahasia & Sangat Rahasia hanya boleh memunculkan notifikasi keberadaan
     * naskah, isi wajib dibuka via web setelah login.
     */
    public static function mustWithholdWaContent(?string $securityLevel): bool
    {
        return in_array(strtoupper((string) $securityLevel), self::RAHASIA_LEVELS, true);
    }

    /** Label human-readable rute keputusan untuk log/audit. */
    public static function routeLabel(?string $route): string
    {
        $raw = strtoupper((string) $route);
        return self::DISPOSITION_ROUTE_LABELS[$raw] ?? ($raw === '' ? '-' : $raw);
    }

    public const STAGES = [
        'DITERIMA', 'VERIFIKASI_ALAMAT', 'SALAH_ALAMAT', 'DISORTIR',
        'MENUNGGU_PENGARAHAN', 'DIBACA_PENGARAH', 'TERREGISTRASI',
        'MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN', 'DITERUSKAN_KE_KASUBAG',
        'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN',
        'DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT',
        'SELESAI_DITINDAKLANJUTI', 'MENUNGGU_PENGARSIPAN', 'DIARSIPKAN'
    ];

    // Nilai sah untuk kolom-kolom klasifikasi v2. Cerminan src/lib/v2Workflow.ts
    // (UI) supaya klien tidak bisa menyimpan nilai di luar daftar resmi.
    public const SOURCE_CHANNELS  = ['POS', 'KURIR', 'EMAIL', 'FAX', 'INTERNAL', 'LAINNYA'];
    public const DOCUMENT_TYPES   = ['SURAT_DINAS', 'MEMORANDUM', 'NOTA_DINAS', 'UNDANGAN', 'SURAT_PENGANTAR', 'DISPOSISI', 'LAPORAN', 'NOTULA', 'TELAAHAN_STAF'];
    public const LETTER_CATEGORIES = ['DINAS', 'PRIBADI'];
    public const URGENCY_LEVELS   = ['NORMAL', 'SEGERA', 'PENTING'];

    /**
     * Tahap di mana data identitas surat masih boleh DIKOREKSI atau DIHAPUS oleh
     * persuratan (kasus nyata: salah ketik nomor/perihal, kode arsip salah,
     * level keamanan salah, pengirim salah).
     *
     * Batas yang dipilih: seluruh tahap SEBELUM ada keputusan disposisi
     * (SOP/AS/04 langkah 13), yaitu sampai MENUNGGU_DISPOSISI. Alasannya, sejak
     * surat DIDISPOSISIKAN salinan instruksinya sudah beredar ke
     * pimpinan/pelaksana: mengubah atau menghapus record surat akan membuat
     * riwayat orang lain tidak lagi cocok dengan kenyataan. Sejak tahap itu
     * koreksi hanya lewat catatan kendali/transisi.
     *
     * Daftar tahap ini BUKAN satu-satunya syarat: surat yang sudah punya baris
     * disposisi juga dikunci walau tahapnya masih di bawah (lihat
     * letterAllowsCorrection() di helpers.php, dipakai PUT/DELETE /api/incoming).
     *
     * AS-9 -- keputusan desain, belum diratifikasi pemilik proses
     * (lihat docs/CATATAN_ASUMSI_REVISI.md). Bila pemilik proses ingin lebih
     * ketat/longgar, cukup ubah daftar ini (dipakai server DAN UI).
     */
    public const CORRECTABLE_STAGES = [
        'DITERIMA', 'VERIFIKASI_ALAMAT', 'SALAH_ALAMAT', 'DISORTIR',
        'MENUNGGU_PENGARAHAN', 'DIBACA_PENGARAH', 'TERREGISTRASI', 'MENUNGGU_DISPOSISI',
    ];

    public static function stageAllowsCorrection(?string $stage): bool
    {
        return in_array(strtoupper(trim((string) $stage)), self::CORRECTABLE_STAGES, true);
    }

    /** Pesan 422 yang seragam untuk PUT/DELETE yang ditolak karena tahap. */
    public static function correctionBlockedMessage(?string $stage): string
    {
        return 'Surat sudah pada tahap ' . strtoupper(trim((string) $stage))
            . '. Data surat hanya boleh diubah/dihapus sebelum ada keputusan disposisi ('
            . implode(', ', self::CORRECTABLE_STAGES)
            . '). Gunakan catatan kendali pada tahap berikutnya.';
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!in_array($to, self::STAGES, true)) return false;
        if ($from === $to) return true;
        $allowed = [
            'DITERIMA' => ['VERIFIKASI_ALAMAT', 'DISORTIR'],
            'VERIFIKASI_ALAMAT' => ['DISORTIR', 'SALAH_ALAMAT'],
            'DISORTIR' => ['MENUNGGU_PENGARAHAN', 'TERREGISTRASI'],
            'MENUNGGU_PENGARAHAN' => ['DIBACA_PENGARAH'],
            'DIBACA_PENGARAH' => ['TERREGISTRASI', 'MENUNGGU_DISPOSISI'],
            'TERREGISTRASI' => ['MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN'],
            'MENUNGGU_DISPOSISI' => ['DIDISPOSISIKAN'],
            'DIDISPOSISIKAN' => ['DITERUSKAN_KE_KASUBAG', 'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN', 'DITERUSKAN_KE_PELAKSANA'],
            'DITERUSKAN_KE_KASUBAG' => ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'],
            'DITERUSKAN_KE_SEKRETARIS_PANITERA' => ['MENUNGGU_KEBIJAKAN_PIMPINAN', 'DITERUSKAN_KE_PELAKSANA'],
            // SOP/AS/04 langkah 14-16 + Q1 locked: Ketua/WK mengisi ARAHAN lalu surat
            // KEMBALI ke Sekretaris/Panitera (DITERUSKAN_KE_SEKRETARIS_PANITERA) untuk
            // dilaksanakan; Sekretaris lalu TERUSKAN ke unit pelaksana. Tidak ada
            // opsi "kembali revisi setelah arahan" ke Kasubag, dan TIDAK ada jalan
            // pintas pimpinan -> pelaksana (itu memotong langkah Sekretaris).
            'MENUNGGU_KEBIJAKAN_PIMPINAN' => ['DITERUSKAN_KE_SEKRETARIS_PANITERA'],
            'DITERUSKAN_KE_PELAKSANA' => ['DALAM_TINDAK_LANJUT'],
            'DALAM_TINDAK_LANJUT' => ['SELESAI_DITINDAKLANJUTI'],
            'SELESAI_DITINDAKLANJUTI' => ['MENUNGGU_PENGARSIPAN'],
            'MENUNGGU_PENGARSIPAN' => ['DIARSIPKAN'],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }

    // ---------- Pemetaan role SIMARS ke aktor SOP ----------
    // Pemetaan awal mengikuti SOP Penanganan Surat Masuk PA Pasarwajo dan
    // struktur role yang sudah ada di aplikasi. Wajib diratifikasi pemilik
    // proses sebelum dipakai di produksi.

    private const R_PERSURATAN = ['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'];
    private const R_PIMPINAN   = ['PIMPINAN', 'WAKIL_KETUA'];
    private const R_PANITERA   = ['ADMIN', 'SEKRETARIS', 'PANITERA'];
    private const R_PELAKSANA  = ['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM', 'KEPALA_SUB_PTIP', 'KEPALA_SUB_KEPEGAWAIAN', 'PANITERA_MUDA_PERMOHONAN', 'PANITERA_MUDA_GUGATAN', 'PANITERA_MUDA_HUKUM', 'STAFF'];

    public static function allowedRolesForTransition(string $from, string $to): array
    {
        $map = [
            'DITERIMA|VERIFIKASI_ALAMAT'                       => self::R_PERSURATAN,
            'DITERIMA|SALAH_ALAMAT'                            => self::R_PERSURATAN,
            'DITERIMA|DISORTIR'                                => self::R_PERSURATAN,
            'VERIFIKASI_ALAMAT|DISORTIR'                       => self::R_PERSURATAN,
            'VERIFIKASI_ALAMAT|SALAH_ALAMAT'                   => self::R_PERSURATAN,
            'DISORTIR|MENUNGGU_PENGARAHAN'                     => self::R_PERSURATAN,
            'DISORTIR|TERREGISTRASI'                           => self::R_PERSURATAN,
            // SOP/AS/04 langkah 4-7: "Kepala Sub Bagian Umum selaku PENGARAH
            // SURAT" yang membuka, membaca, menentukan kualifikasi (B/P/RHS) dan
            // membubuhkan lembar disposisi. Jadi pengarahan bukan milik pimpinan.
            'MENUNGGU_PENGARAHAN|DIBACA_PENGARAH'              => self::R_PERSURATAN,
            'DIBACA_PENGARAH|TERREGISTRASI'                    => self::R_PERSURATAN,
            'DIBACA_PENGARAH|MENUNGGU_DISPOSISI'               => self::R_PERSURATAN,
            'TERREGISTRASI|MENUNGGU_DISPOSISI'                 => self::R_PANITERA,
            'TERREGISTRASI|DIDISPOSISIKAN'                     => self::R_PANITERA,
            // SOP/AS/04 langkah 12-13: keputusan "perlu kebijakan pimpinan atau
            // langsung" ada di Sekretaris/Panitera, bukan di pimpinan.
            'MENUNGGU_DISPOSISI|DIDISPOSISIKAN'                => self::R_PANITERA,
            'DIDISPOSISIKAN|DITERUSKAN_KE_KASUBAG'             => self::R_PANITERA,
            'DIDISPOSISIKAN|DITERUSKAN_KE_SEKRETARIS_PANITERA' => self::R_PANITERA,
            'DIDISPOSISIKAN|MENUNGGU_KEBIJAKAN_PIMPINAN'       => self::R_PANITERA,
            'DIDISPOSISIKAN|DITERUSKAN_KE_PELAKSANA'           => self::R_PANITERA,
            'DITERUSKAN_KE_KASUBAG|DITERUSKAN_KE_SEKRETARIS_PANITERA' => self::R_PERSURATAN,
            // Kasubag Umum TIDAK boleh melompat ke pimpinan: menaikkan surat ke
            // Ketua/WK adalah keputusan Sekretaris/Panitera (SOP/AS/04 langkah 13).
            'DITERUSKAN_KE_KASUBAG|MENUNGGU_KEBIJAKAN_PIMPINAN'       => self::R_PANITERA,
            // Plan Alur §3: setelah pimpinan memberi ARAHAN, surat kembali ke
            // Sekretaris/Panitera; perintah "TERUSKAN" (langkah 15-16 SOP) dipegang
            // Sekretaris/Panitera, bukan pimpinan.
            'DITERUSKAN_KE_SEKRETARIS_PANITERA|MENUNGGU_KEBIJAKAN_PIMPINAN' => self::R_PANITERA,
            'DITERUSKAN_KE_SEKRETARIS_PANITERA|DITERUSKAN_KE_PELAKSANA'     => self::R_PANITERA,
            // Baris lama 'MENUNGGU_KEBIJAKAN_PIMPINAN|DITERUSKAN_KE_PELAKSANA'
            // DIHAPUS: pimpinan tidak meneruskan surat ke pelaksana; ia mengisi
            // arahan lalu mengembalikan surat ke Sekretaris/Panitera (Q1: arahan
            // final, tanpa jalan pintas yang memotong langkah Sekretaris).
            'DITERUSKAN_KE_PELAKSANA|DALAM_TINDAK_LANJUT'             => self::R_PELAKSANA,
            'DALAM_TINDAK_LANJUT|SELESAI_DITINDAKLANJUTI'             => self::R_PELAKSANA,
            'SELESAI_DITINDAKLANJUTI|MENUNGGU_PENGARSIPAN'            => self::R_PERSURATAN,
            // SOP/AS/04 langkah 17-18 + Q2 locked: yang menandai DIARSIPKAN adalah
            // pencatat surat (ARSIPARIS + Admin), BUKAN pegawai penindak lanjut.
            'MENUNGGU_PENGARSIPAN|DIARSIPKAN'                         => ['ADMIN', 'ARSIPARIS'],
            // SOP/AS/04 langkah 14: Ketua/WK mengisi ARAHAN (wajib) di tahap
            // MENUNGGU_KEBIJAKAN_PIMPINAN. Q1 locked: arahan = final, tidak ada
            // opsi "kembali revisi setelah arahan". Surat kembali ke Sekretaris
            // via DITERUSKAN_KE_SEKRETARIS_PANITERA untuk dilaksanakan.
            'MENUNGGU_KEBIJAKAN_PIMPINAN|DITERUSKAN_KE_SEKRETARIS_PANITERA' => ['PIMPINAN', 'WAKIL_KETUA', 'ADMIN'],
        ];
        return $map["$from|$to"] ?? [];
    }

    /**
     * Transisi yang benar-benar boleh dijalankan seorang user dari tahap $from,
     * sudah memperhitungkan (a) legalitas state machine, (b) role, dan
     * (c) rute keputusan Sekretaris/Panitera ($route) bila sudah ditetapkan.
     *
     * Dipakai API (allowedNextStages/allowedTransitions) DAN UI supaya tombol
     * yang tampil selalu sama dengan yang divalidasi server.
     *
     * @param array|null $user  Baris user (butuh key 'role').
     * @param string|null $route Nilai kolom incoming_letters.disposition_route.
     * @return array<int,array{toStage:string,label:string,requiresRoute:bool}>
     */
    public static function allowedTransitionsFor(?array $user, string $from, ?string $route = null): array
    {
        $role = (string) ($user['role'] ?? '');
        // Rute hanya mengunci percabangan tepat sesudah keputusan (DIDISPOSISIKAN).
        // Setelah itu rute = catatan historis; state machine normal berlaku lagi.
        $routeLock = $from === 'DIDISPOSISIKAN' ? self::stagesAllowedByRoute($route) : [];
        $out = [];
        foreach (self::STAGES as $to) {
            if (!self::canTransition($from, $to)) continue;
            if (!in_array($role, self::allowedRolesForTransition($from, $to), true)) continue;
            if ($routeLock !== [] && !in_array($to, $routeLock, true)) continue;
            $out[] = [
                'toStage'       => $to,
                'label'         => self::transitionLabel($from, $to),
                // Masuk ke DIDISPOSISIKAN = momen keputusan SOP langkah 13, jadi
                // rute wajib dipilih sekaligus. Termasuk jalur pintas
                // TERREGISTRASI -> DIDISPOSISIKAN supaya tidak ada cara melewati
                // keputusan tersebut (lihat control.php $isDecision).
                'requiresRoute' => $to === 'DIDISPOSISIKAN' && $from !== 'DIDISPOSISIKAN',
            ];
        }
        return $out;
    }

    // ---------- Fase 0/1: pemetaan peristiwa disposisi -> tahap surat ----------
    // Surat berhenti di DITERUSKAN_KE_PELAKSANA sampai pelaksana melapor. Laporan
    // itu (web PATCH /dispositions/:id/status maupun balasan WhatsApp pegawai)
    // adalah PERISTIWA yang harus menggerakkan Buku Kendali; tanpa pemetaan ini
    // surat tampak macet walau pekerjaannya sudah jalan/selesai.
    public const DISPOSITION_EVENT_STAGES = [
        'PROSES'  => 'DALAM_TINDAK_LANJUT',
        'SELESAI' => 'SELESAI_DITINDAKLANJUTI',
    ];

    /** Tahap tujuan untuk status disposisi; null = status ini tidak menggerakkan tahap. */
    public static function stageForDispositionEvent(?string $status): ?string
    {
        $key = strtoupper(trim((string) $status));
        return self::DISPOSITION_EVENT_STAGES[$key] ?? null;
    }

    /**
     * Tahap asal yang SAH untuk sebuah peristiwa laporan. Auto-advance hanya
     * berjalan bila surat memang sedang berada di rantai pelaksanaan; surat yang
     * masih di meja Kasubag/Sekretaris TIDAK boleh dilompati (lihat H1/H4 pada
     * docs/CATATAN_ASUMSI_REVISI.md).
     *
     * @return string[]
     */
    public static function autoAdvanceSourcesForEvent(?string $status): array
    {
        $key = strtoupper(trim((string) $status));
        if ($key === 'PROSES') return ['DITERUSKAN_KE_PELAKSANA'];
        // SELESAI boleh langsung dari DITERUSKAN_KE_PELAKSANA (pelaksana sering
        // tak pernah melapor PROSES lebih dulu) -> dua langkah sekaligus.
        if ($key === 'SELESAI') return ['DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT'];
        return [];
    }

    /**
     * Rencana auto-advance MURNI (tanpa DB) supaya bisa diuji dan dipakai bersama
     * oleh web + WhatsApp. Langkah hanya dihasilkan bila SETIAP mata rantai sah
     * menurut canTransition(); fungsi ini tidak pernah menebak jalan pintas.
     *
     * @return array{applied:bool, reason:string, target:?string, path:string[]}
     *   reason: STATUS_IRRELEVAN | SUDAH_PADA_TAHAP | BUKAN_TAHAP_PELAKSANAAN | JALUR_TIDAK_SAH
     */
    public static function planAutoAdvance(?string $from, ?string $status): array
    {
        $target = self::stageForDispositionEvent($status);
        if ($target === null) {
            return ['applied' => false, 'reason' => 'STATUS_IRRELEVAN', 'target' => null, 'path' => []];
        }
        $from = strtoupper(trim((string) $from));
        if ($from === $target) {
            // Idempoten: laporan kedua (mis. PROSES dua kali) tidak menaikkan tahap.
            return ['applied' => false, 'reason' => 'SUDAH_PADA_TAHAP', 'target' => $target, 'path' => []];
        }
        if (!in_array($from, self::autoAdvanceSourcesForEvent($status), true)) {
            return ['applied' => false, 'reason' => 'BUKAN_TAHAP_PELAKSANAAN', 'target' => $target, 'path' => []];
        }
        $i = array_search($from, self::STAGES, true);
        $t = array_search($target, self::STAGES, true);
        if ($i === false || $t === false || $t < $i) {
            return ['applied' => false, 'reason' => 'JALUR_TIDAK_SAH', 'target' => $target, 'path' => []];
        }
        $path = [];
        $cur = $from;
        for ($k = $i + 1; $k <= $t; $k++) {
            $next = self::STAGES[$k];
            if (!self::canTransition($cur, $next)) {
                return ['applied' => false, 'reason' => 'JALUR_TIDAK_SAH', 'target' => $target, 'path' => []];
            }
            $path[] = $next;
            $cur = $next;
        }
        return ['applied' => true, 'reason' => 'DITERAPKAN', 'target' => $target, 'path' => $path];
    }

    /** Kalimat penjelasan kenapa tahap surat tidak bergerak (dipakai API & bot WA). */
    public static function autoAdvanceReasonText(string $reason): string
    {
        if ($reason === 'SUDAH_PADA_TAHAP') return 'Tahap surat sudah sesuai dengan laporan ini.';
        if ($reason === 'BUKAN_TAHAP_PELAKSANAAN') {
            return 'Surat belum berada di rantai pelaksana, jadi tahapnya tidak diubah otomatis. '
                . 'Petugas persuratan/Kasubag perlu meneruskan surat lebih dulu.';
        }
        if ($reason === 'JALUR_TIDAK_SAH') return 'Tahap surat tidak dapat dimajukan otomatis dari posisinya sekarang.';
        return 'Status laporan ini tidak mengubah tahap surat.';
    }

    // ---------- Fase 2: siapa pemegang surat pada tiap tahap ----------
    // Dipakai WaStageNotifier untuk memberi tahu orang yang tepat saat surat
    // berpindah tahap (sebelum ini notifikasi hanya berbunyi saat disposisi
    // dibuat, sehingga Kasubag/Sekretaris/Panitera tak pernah diberi tahu).
    // Pelaksana (DITERUSKAN_KE_PELAKSANA / DALAM_TINDAK_LANJUT) tidak didaftarkan
    // di sini karena pemegangnya = assignee surat (kolom assignee_user_id).
    public const STAGE_OWNER_ROLES = [
        // P2 (revisi kedua): tahap awal (langkah 4-11) = meja KASUBAG UMUM
        // selaku pengarah surat. Sekretaris/Panitera baru menjadi pemegang
        // mulai langkah 13 (MENUNGGU_DISPOSISI dst) — sebelumnya Sekretaris
        // ikut terdaftar di MENUNGGU_PENGARAHAN/DIBACA_PENGARAH sehingga
        // menerima WA (bahkan berpotensi memegang menu aksi) di tahap yang
        // bukan mejainya; itu dikoreksi di sini.
        'MENUNGGU_PENGARAHAN'               => ['KEPALA_SUB_UMUM'],
        'DIBACA_PENGARAH'                   => ['KEPALA_SUB_UMUM'],
        'MENUNGGU_DISPOSISI'                => ['SEKRETARIS', 'PANITERA'],
        'DIDISPOSISIKAN'                    => ['SEKRETARIS', 'PANITERA'],
        'DITERUSKAN_KE_KASUBAG'             => ['KEPALA_SUB_UMUM'],
        'DITERUSKAN_KE_SEKRETARIS_PANITERA' => ['SEKRETARIS', 'PANITERA'],
        'MENUNGGU_KEBIJAKAN_PIMPINAN'       => ['PIMPINAN', 'WAKIL_KETUA'],
        'SELESAI_DITINDAKLANJUTI'           => ['SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'],
        // P8 (revisi kedua): ARSIPARIS ditambahkan sebagai pemilik
        // MENUNGGU_PENGARSIPAN — sebelumnya hanya SEKRETARIS/PANITERA,
        // sehingga Arsiparis tidak pernah menerima WA dan harus memantau
        // web sendiri. Role lain tetap (tidak kehilangan notifikasi).
        'MENUNGGU_PENGARSIPAN'              => ['SEKRETARIS', 'PANITERA', 'ARSIPARIS'],
    ];

    /**
     * Role pemegang surat pada $stage (tanpa ADMIN — admin tidak perlu diberi
     * tahu setiap perpindahan). [] = tahap ini tidak punya "meja" tertentu.
     *
     * @return string[]
     */
    public static function stageOwners(?string $stage): array
    {
        return self::STAGE_OWNER_ROLES[strtoupper(trim((string) $stage))] ?? [];
    }

    // ---------- K3 (DEFAULT SEMENTARA - menunggu review pimpinan) ----------
    // Aturan TOLAK/RECALL hasil keputusan revisi kedua, menggantikan aturan
    // lama "Kasubag Umum boleh menarik surat dari rantai pelaksanaan".
    //
    // 1) TOLAK satu langkah oleh PEMEGANG surat (alasan wajib >=10 karakter):
    //      pegawai (assignee) -> kepala unit   : DALAM_TINDAK_LANJUT -> DITERUSKAN_KE_PELAKSANA
    //      kepala unit -> Sekretaris/Panitera  : DITERUSKAN_KE_PELAKSANA -> DITERUSKAN_KE_SEKRETARIS_PANITERA
    //      Sekretaris/Panitera -> Kasubag Umum : DITERUSKAN_KE_SEKRETARIS_PANITERA -> DITERUSKAN_KE_KASUBAG
    //    Ketua/WK TIDAK punya TOLAK (Q1: arahan final; ketidaksetujuan ditulis
    //    di isi arahan). ADMIN tetap boleh mengoreksi kasus khusus (dicatat).
    // 2) RECALL oleh PENGIRIM surat yang baru ia kirim, HANYA selama penerima
    //    belum bertindak. Setelah penerima bertindak (mis. TUNJUK_PEGAWAI atau
    //    memindahkan surat), recall DITOLAK. Kasubag Umum karenanya TIDAK lagi
    //    bisa menarik surat yang sudah diputuskan/diteruskan Sekretaris.
    //
    // Semua tolak/recall tercatat di letter_control_logs: pelaku (actor_user_id),
    // alasan (notes), waktu (created_at), tahap asal (from_stage), tahap tujuan
    // (to_stage) — memenuhi KMA 131 BAB IV. Gerak mundur TIDAK masuk
    // canTransition supaya tidak bisa dipakai lewat transisi biasa.
    public const REOPEN_TARGET_STAGE = 'DITERUSKAN_KE_KASUBAG';
    // REOPEN_ROLES kini = kanal koreksi kasus khusus ADMIN saja (K3).
    public const REOPEN_ROLES = ['ADMIN'];
    public const REOPEN_ADMIN_STAGES = [
        'DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT', 'SELESAI_DITINDAKLANJUTI',
        'MENUNGGU_KEBIJAKAN_PIMPINAN', 'MENUNGGU_PENGARSIPAN',
    ];
    // Alasan wajib cukup spesifik; "ok"/"salah" tidak berguna saat diaudit.
    // Nilainya bisa diubah lewat workflow_settings.tolak_reason_min.
    public const REOPEN_REASON_MIN = 10;

    /** Tahap asal TOLAK -> tahap tujuan (satu langkah ke pengirim sebelumnya). */
    public const TOLAK_TARGETS = [
        'DALAM_TINDAK_LANJUT'               => 'DITERUSKAN_KE_PELAKSANA',           // pegawai -> kepala unit
        'DITERUSKAN_KE_PELAKSANA'           => 'DITERUSKAN_KE_SEKRETARIS_PANITERA', // kepala unit -> Sekretaris/Panitera
        'DITERUSKAN_KE_SEKRETARIS_PANITERA' => 'DITERUSKAN_KE_KASUBAG',             // Sekretaris/Panitera -> Kasubag Umum
    ];

    /** Tahap asal RECALL -> tahap tujuan (pengirim menarik sebelum penerima bertindak). */
    public const RECALL_TARGETS = [
        'DITERUSKAN_KE_SEKRETARIS_PANITERA' => 'DITERUSKAN_KE_KASUBAG',             // Kasubag Umum menarik kiriman ke Sekretaris
        'DITERUSKAN_KE_PELAKSANA'           => 'DITERUSKAN_KE_SEKRETARIS_PANITERA', // Sekretaris/Panitera menarik kiriman ke unit
        'MENUNGGU_KEBIJAKAN_PIMPINAN'       => 'DITERUSKAN_KE_SEKRETARIS_PANITERA', // Sekretaris/Panitera menarik dari meja pimpinan
    ];

    /** @return string[] Tahap yang boleh dikoreksi (ditarik) oleh role user ini. */
    public static function reopenableStagesFor(?array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        return in_array($role, self::REOPEN_ROLES, true) ? self::REOPEN_ADMIN_STAGES : [];
    }

    /** Boleh/tidaknya user memakai kanal koreksi ADMIN pada $stage (K3). */
    public static function canReopenLetter(?array $user, ?string $stage): bool
    {
        $stage = strtoupper(trim((string) $stage));
        return $stage !== '' && in_array($stage, self::reopenableStagesFor($user), true);
    }

    /**
     * Validasi alasan TOLAK/RECALL/koreksi. Return [ok, errorMessage].
     * $min null = pakai konstanta; pemanggil ber-DB boleh meneruskan
     * WorkflowConfig::tolakReasonMin() agar batasnya bisa diatur pimpinan.
     */
    public static function validateReopenReason(?string $reason, ?int $min = null): array
    {
        $min = ($min === null || $min < 1) ? self::REOPEN_REASON_MIN : $min;
        $r = trim((string) $reason);
        if ($r === '') {
            return [false, 'Alasan penolakan/penarikan surat wajib diisi (tercatat di riwayat Buku Kendali).'];
        }
        if (strlen($r) < $min) {
            return [false, 'Alasan penolakan/penarikan surat minimal ' . $min . ' karakter agar jelas saat diaudit.'];
        }
        return [true, null];
    }

    /** Label tindakan penarikan untuk tombol UI & log. */
    public static function reopenLabel(): string
    {
        return 'Tarik kembali (koreksi ADMIN)';
    }

    /**
     * Rencana penolakan/pengembalian surat untuk seorang user (K3) — MURNI,
     * tanpa DB, supaya bisa dikunci lewat uji unit. Pemanggil ber-DB meneruskan
     * baris log kendali TERAKHIR agar syarat "penerima belum bertindak" bisa
     * diperiksa untuk RECALL.
     *
     * $lastLog: baris letter_control_logs terakhir (kolom actor_user_id,
     * from_stage, to_stage; boleh camelCase atau snake_case), atau null.
     *
     * Return ['ok'=>bool, 'mode'=>?'TOLAK'|'RECALL'|'ADMIN_REOPEN',
     * 'to'=>?string, 'message'=>string].
     */
    public static function planReject(?array $user, array $letter, ?array $lastLog = null): array
    {
        $role  = (string) ($user['role'] ?? '');
        $stage = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
        $uid   = (string) ($user['id'] ?? '');
        $denied = function (string $msg): array {
            return ['ok' => false, 'mode' => null, 'to' => null, 'message' => $msg];
        };

        // Ketua/WK tidak punya TOLAK (Q1: arahan final). Mereka juga bukan
        // pengirim pada rantai recall, jadi tidak ada recall untuk mereka.
        if (in_array($role, ['PIMPINAN', 'WAKIL_KETUA'], true)) {
            return $denied('Ketua/Wakil Ketua tidak memiliki TOLAK; arahan pimpinan bersifat final (SOP/AS/04 Q1). Ketidaksetujuan dituliskan di isi arahan.');
        }

        // ADMIN: kanal koreksi kasus khusus, tetap tercatat di log (K3).
        if ($role === 'ADMIN' && in_array($stage, self::REOPEN_ADMIN_STAGES, true)) {
            return ['ok' => true, 'mode' => 'ADMIN_REOPEN', 'to' => self::REOPEN_TARGET_STAGE,
                'message' => 'Koreksi ADMIN (kasus khusus): surat dikembalikan ke meja Kasubag Umum, tercatat di log.'];
        }

        // TOLAK satu langkah oleh pemegang surat pada tahap ini.
        if (isset(self::TOLAK_TARGETS[$stage])) {
            $isHolder = false;
            if ($stage === 'DALAM_TINDAK_LANJUT') {
                // Pemegang = pegawai pelaksana yang ditunjuk (assignee surat).
                $isHolder = $uid !== '' && (string) ($letter['assigneeUserId'] ?? '') === $uid;
            } elseif ($stage === 'DITERUSKAN_KE_PELAKSANA') {
                // Pemegang = kepala unit tujuan (unit kosong/data lama: semua
                // kepala unit sah agar surat tidak buntu).
                $isHolder = self::isUnitHead($role)
                    && self::roleMatchesUnit($role, (string) ($letter['unitTujuan'] ?? ''));
            } else {
                // DITERUSKAN_KE_SEKRETARIS_PANITERA: pemegang = Sekretaris/Panitera.
                $isHolder = in_array($role, ['SEKRETARIS', 'PANITERA'], true);
            }
            if ($role === 'ADMIN' || $isHolder) {
                $to = self::TOLAK_TARGETS[$stage];
                return ['ok' => true, 'mode' => 'TOLAK', 'to' => $to,
                    'message' => 'Surat dikembalikan satu langkah ke ' . self::stageLabel($to) . ' beserta alasan penolakan.'];
            }
            // Bukan pemegang: jangan gagal dulu — mungkin ia pengirim yang
            // berhak RECALL (dicoba di bawah).
        }

        // RECALL oleh pengirim, hanya bila penerima belum bertindak.
        if (isset(self::RECALL_TARGETS[$stage]) && self::lastLogIsFreshArrival($lastLog, $stage, $uid)) {
            $recallRoles = $stage === 'DITERUSKAN_KE_SEKRETARIS_PANITERA'
                ? ['KEPALA_SUB_UMUM'] : ['SEKRETARIS', 'PANITERA'];
            if ($role === 'ADMIN' || in_array($role, $recallRoles, true)) {
                $to = self::RECALL_TARGETS[$stage];
                return ['ok' => true, 'mode' => 'RECALL', 'to' => $to,
                    'message' => 'Surat ditarik kembali oleh pengirimnya ke ' . self::stageLabel($to)
                        . ' (penerima belum melakukan aksi apa pun).'];
            }
        }

        return $denied('Role Anda tidak berwenang menolak/menarik surat pada tahap ini.');
    }

    /**
     * Syarat RECALL K3: $lastLog = baris KEDATANGAN surat ke tahap ini
     * (from != to) dan pelakunya = pengirim yang mau menarik. Pemanggil
     * bertanggung jawab memastikan tidak ada aksi penerima (mis.
     * TUNJUK_PEGAWAI / keputusan, from == to) sejak kedatangan itu —
     * setelah penerima bertindak, recall ditolak.
     */
    public static function lastLogIsFreshArrival(?array $lastLog, string $stage, string $senderId): bool
    {
        if ($lastLog === null) return false;
        $to    = strtoupper(trim((string) ($lastLog['toStage'] ?? $lastLog['to_stage'] ?? '')));
        $from  = strtoupper(trim((string) ($lastLog['fromStage'] ?? $lastLog['from_stage'] ?? '')));
        $actor = (string) ($lastLog['actorUserId'] ?? $lastLog['actor_user_id'] ?? '');
        return $to === $stage && $from !== $stage && $actor !== '' && $actor === $senderId;
    }

    /** Kepala unit mana yang berwenang atas sebuah unit tujuan (langkah 17). */
    public static function roleMatchesUnit(string $role, string $unit): bool
    {
        $map = [
            'KASUBAG_UMUM'        => 'KEPALA_SUB_UMUM',
            'KASUBAG_KEPEGAWAIAN' => 'KEPALA_SUB_KEPEGAWAIAN',
            'KASUBAG_PTIP'        => 'KEPALA_SUB_PTIP',
            'PANMUD_PERMOHONAN'   => 'PANITERA_MUDA_PERMOHONAN',
            'PANMUD_GUGATAN'      => 'PANITERA_MUDA_GUGATAN',
            'PANMUD_HUKUM'        => 'PANITERA_MUDA_HUKUM',
        ];
        $unit = strtoupper(trim($unit));
        // Unit kosong/tidak dikenal (data lama): semua kepala unit boleh,
        // supaya surat tidak macet.
        if ($unit === '' || !isset($map[$unit])) return true;
        return $map[$unit] === $role;
    }

    // Label Indonesia tiap tahap untuk pesan WA & UI yang ramah pengguna.
    // Dipakai Wabot/WaStageNotifier supaya pesan bot tidak menampilkan kode
    // kolom mentah seperti DITERUSKAN_KE_SEKRETARIS_PANITERA.
    public const STAGE_LABELS = [
        'DITERIMA'                          => 'Surat diterima',
        'VERIFIKASI_ALAMAT'                 => 'Verifikasi alamat',
        'SALAH_ALAMAT'                      => 'Alamat tidak sesuai',
        'DISORTIR'                          => 'Disortir',
        'MENUNGGU_PENGARAHAN'               => 'Menunggu pengarahan',
        'DIBACA_PENGARAH'                   => 'Sudah dibaca pengarah',
        'TERREGISTRASI'                     => 'Terregistrasi',
        'MENUNGGU_DISPOSISI'                => 'Menunggu disposisi',
        'DIDISPOSISIKAN'                    => 'Sudah didisposisikan',
        'DITERUSKAN_KE_KASUBAG'             => 'Di Kasubag Umum',
        'DITERUSKAN_KE_SEKRETARIS_PANITERA' => 'Di Sekretaris/Panitera',
        'MENUNGGU_KEBIJAKAN_PIMPINAN'       => 'Menunggu kebijakan pimpinan',
        'DITERUSKAN_KE_PELAKSANA'           => 'Diteruskan ke pelaksana',
        'DALAM_TINDAK_LANJUT'               => 'Dalam tindak lanjut',
        'SELESAI_DITINDAKLANJUTI'           => 'Selesai ditindaklanjuti',
        'MENUNGGU_PENGARSIPAN'              => 'Menunggu pengarsipan',
        'DIARSIPKAN'                        => 'Diarsipkan',
    ];

    /** Label ramah pengguna untuk $stage (fallback: kode tahap apa adanya). */
    public static function stageLabel(?string $stage): string
    {
        $key = strtoupper(trim((string) $stage));
        return self::STAGE_LABELS[$key] ?? ($key !== '' ? $key : '-');
    }

    public static function userCanAccessLetter(?array $user, string $securityLevel): bool
    {
        if (!in_array(strtoupper($securityLevel), self::RAHASIA_LEVELS, true)) return true;
        if (!$user) return false;
        return in_array($user['role'] ?? '', self::RAHASIA_ACCESS_ROLES, true);
    }

    // Validasi format + primer kode arsip. Return [ok, errorMessage].
    // Kode lengkap P/S/T dijamin formatnya (contoh HK1.1.2) dan primer-nya
    // wajib salah satu dari 13 kategori resmi.
    public static function validateArchiveCode(?string $code): array
    {
        if ($code === null || trim($code) === '') return [true, null];
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{2}(\d+(\.\d+)*)?$/', $code)) {
            return [false, 'Format kode arsip tidak valid. Contoh yang benar: HK1.1.2.'];
        }
        $primary = substr($code, 0, 2);
        if (!isset(self::ARCHIVE_PRIMARIES[$primary])) {
            return [false, "Kode primer \"$primary\" bukan kategori klasifikasi arsip resmi MA (SK 627/2023)."];
        }
        return [true, null];
    }
}