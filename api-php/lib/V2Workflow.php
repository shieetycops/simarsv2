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

    public const STAGES = [
        'DITERIMA', 'VERIFIKASI_ALAMAT', 'SALAH_ALAMAT', 'DISORTIR',
        'MENUNGGU_PENGARAHAN', 'DIBACA_PENGARAH', 'TERREGISTRASI',
        'MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN', 'DITERUSKAN_KE_KASUBAG',
        'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN',
        'DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT',
        'SELESAI_DITINDAKLANJUTI', 'MENUNGGU_PENGARSIPAN', 'DIARSIPKAN'
    ];

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
            'MENUNGGU_KEBIJAKAN_PIMPINAN' => ['DITERUSKAN_KE_PELAKSANA'],
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
            'MENUNGGU_PENGARAHAN|DIBACA_PENGARAH'              => self::R_PIMPINAN,
            'DIBACA_PENGARAH|TERREGISTRASI'                    => self::R_PERSURATAN,
            'DIBACA_PENGARAH|MENUNGGU_DISPOSISI'               => self::R_PERSURATAN,
            'TERREGISTRASI|MENUNGGU_DISPOSISI'                 => self::R_PANITERA,
            'TERREGISTRASI|DIDISPOSISIKAN'                     => self::R_PANITERA,
            'MENUNGGU_DISPOSISI|DIDISPOSISIKAN'                => self::R_PIMPINAN,
            'DIDISPOSISIKAN|DITERUSKAN_KE_KASUBAG'             => self::R_PANITERA,
            'DIDISPOSISIKAN|DITERUSKAN_KE_SEKRETARIS_PANITERA' => self::R_PANITERA,
            'DIDISPOSISIKAN|MENUNGGU_KEBIJAKAN_PIMPINAN'       => self::R_PANITERA,
            'DIDISPOSISIKAN|DITERUSKAN_KE_PELAKSANA'           => self::R_PANITERA,
            'DITERUSKAN_KE_KASUBAG|DITERUSKAN_KE_SEKRETARIS_PANITERA' => self::R_PERSURATAN,
            'DITERUSKAN_KE_KASUBAG|MENUNGGU_KEBIJAKAN_PIMPINAN'       => self::R_PERSURATAN,
            'DITERUSKAN_KE_SEKRETARIS_PANITERA|MENUNGGU_KEBIJAKAN_PIMPINAN' => self::R_PELAKSANA,
            'DITERUSKAN_KE_SEKRETARIS_PANITERA|DITERUSKAN_KE_PELAKSANA'     => self::R_PELAKSANA,
            'MENUNGGU_KEBIJAKAN_PIMPINAN|DITERUSKAN_KE_PELAKSANA'     => self::R_PIMPINAN,
            'DITERUSKAN_KE_PELAKSANA|DALAM_TINDAK_LANJUT'             => self::R_PELAKSANA,
            'DALAM_TINDAK_LANJUT|SELESAI_DITINDAKLANJUTI'             => self::R_PELAKSANA,
            'SELESAI_DITINDAKLANJUTI|MENUNGGU_PENGARSIPAN'            => self::R_PERSURATAN,
            'MENUNGGU_PENGARSIPAN|DIARSIPKAN'                         => self::R_PANITERA,
        ];
        return $map["$from|$to"] ?? [];
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