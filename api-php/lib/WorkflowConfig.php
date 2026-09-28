<?php
// WorkflowConfig — aturan alur disposisi yang bisa diubah TANPA membongkar kode.
//
// Patch revisi kedua (P1-P8): keputusan K3/K6/K7/P1 bersifat "DEFAULT
// SEMENTARA - menunggu review pimpinan", jadi nilainya disimpan di tabel
// workflow_settings (migrasi 2026_09_29_sop_patch.sql) dan bisa diubah:
//   - lewat UI: Pengaturan -> "Aturan disposisi" (PUT /api/settings/workflow)
//   - lewat DB: UPDATE workflow_settings SET ... WHERE id = 'wf_settings'
//
// Fallback: bila tabel/baris/DB tidak tersedia (mis. uji unit tanpa MySQL),
// dipakai default yang sama dengan DEFAULT migrasi, jadi perilaku konsisten.
// Cache per-proses (satu request) supaya tidak bolak-balik query.
class WorkflowConfig
{
    /** Default yang sama dengan migrasi 2026_09_29_sop_patch.sql. */
    public const DEFAULTS = [
        'tolakReasonMin'          => 10,
        'waArahanRahasiaBlocked'  => 1,
        'lembar1Wajib'            => 0,
        'waStageNumberReply'      => 0,
    ];

    /** @var array|null Baris workflow_settings (camelCase) atau null. */
    private static ?array $row = null;
    private static bool $loaded = false;

    /** Kosongkan cache (dipakai uji yang mengubah konfigurasi). */
    public static function reset(): void
    {
        self::$row = null;
        self::$loaded = false;
    }

    private static function row(): ?array
    {
        if (self::$loaded) return self::$row;
        self::$loaded = true;
        self::$row = null;
        try {
            // Db boleh belum dimuat (konteks uji murni) -> pakai default.
            if (!class_exists('Db') || !Db::$pdo) return null;
            $r = Db::one("SELECT * FROM workflow_settings WHERE id = 'wf_settings'");
            if ($r) self::$row = $r;
        } catch (Throwable $e) {
            // Tabel belum ada / DB offline: pakai default (jangan gagalkan
            // perpindahan tahap hanya karena konfigurasi tak terbaca).
            self::$row = null;
        }
        return self::$row;
    }

    /** K3: panjang minimal alasan TOLAK/RECALL (default 10 karakter). */
    public static function tolakReasonMin(): int
    {
        $r = self::row();
        $v = $r['tolakReasonMin'] ?? self::DEFAULTS['tolakReasonMin'];
        $n = (int) $v;
        return $n > 0 ? $n : (int) self::DEFAULTS['tolakReasonMin'];
    }

    /** K6: tolak perintah ARAHAN via WA untuk surat RAHASIA/SANGAT_RAHASIA. */
    public static function waArahanRahasiaBlocked(): bool
    {
        $r = self::row();
        return (bool) ($r['waArahanRahasiaBlocked'] ?? self::DEFAULTS['waArahanRahasiaBlocked']);
    }

    /** K7: wajibkan serah-terima lembar 1 sebelum DIARSIPKAN (default mati). */
    public static function lembar1Wajib(): bool
    {
        $r = self::row();
        return (bool) ($r['lembar1Wajib'] ?? self::DEFAULTS['lembar1Wajib']);
    }

    /** P1: balasan angka menu aksi tahap aktif/tidak (default NONAKTIF). */
    public static function waStageNumberReply(): bool
    {
        $r = self::row();
        return (bool) ($r['waStageNumberReply'] ?? self::DEFAULTS['waStageNumberReply']);
    }

    /** Seluruh konfigurasi (untuk GET /settings/workflow & UI). */
    public static function all(): array
    {
        $r = self::row() ?? [];
        return [
            'tolakReasonMin'         => self::tolakReasonMin(),
            'waArahanRahasiaBlocked' => self::waArahanRahasiaBlocked(),
            'lembar1Wajib'           => self::lembar1Wajib(),
            'waStageNumberReply'     => self::waStageNumberReply(),
            'source'                 => $r ? 'DB' : 'DEFAULT',
        ];
    }
}
