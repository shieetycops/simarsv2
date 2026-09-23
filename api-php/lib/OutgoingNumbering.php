<?php
// Logika penomoran surat keluar — murni (tanpa auth/HTTP), supaya bisa dites langsung.
//
// Skema penomoran (sesuai data arsip 2026):
//   Format nomor surat  : <nomorUrut>[.suffix]/<KODE>/<BULAN>/<TAHUN>
//   - Setiap unit penerbit (PPK / SEKRETARIS / KPA) punya urutan sendiri.
//   - Nomor berjalan terus sepanjang tahun, reset di tahun baru.
//   - Bulan: PPK pakai angka Arab (2), SEKRETARIS & KPA pakai Romawi (II).
//   - Surat sisipan: nomor dasar + suffix huruf (5.a, 5.b, ...). Data lama
//     juga ada yang tanpa titik (8a, 8b) — keduanya dikenali.
//   - Data import lama bisa "berantakan" (TANPA-NOMOR-1, gap, duplikat) —
//     generator selalu menghitung dari kondisi DB terkini dan SKIP nomor yang
//     sudah terpakai supaya tidak pernah tubrukan (letter_number punya UNIQUE).

final class OutgoingNumbering
{
    public const VALID_UNITS = ['PPK', 'SEKRETARIS', 'KPA'];

    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /**
     * Ekstrak {sequence, suffix} dari letter_number. Mengenal dua format sisipan:
     *   '5/...'    -> ['sequence' => 5, 'suffix' => null]
     *   '5.a/...'  -> ['sequence' => 5, 'suffix' => 'a']
     *   '5a/...'   -> ['sequence' => 5, 'suffix' => 'a']
     * Data tanpa angka di depan (mis. 'TANPA-NOMOR-1') -> null (diabaikan).
     */
    public static function parseNumber(string $letterNumber): ?array
    {
        if (!preg_match('/^(\d+)(?:\.([a-z]))?(?:([a-z]))?/i', trim($letterNumber), $m)) {
            return null;
        }
        $suffix = null;
        if (!empty($m[2])) {
            $suffix = strtolower($m[2]);
        } elseif (!empty($m[3])) {
            $suffix = strtolower($m[3]);
        }
        return ['sequence' => (int) $m[1], 'suffix' => $suffix];
    }

    /** Label bulan sesuai unit: PPK angka Arab, lainnya Romawi. */
    public static function monthLabel(string $unit, int $month): string
    {
        if ($unit === 'PPK') {
            return (string) $month;
        }
        return self::ROMAN[$month - 1] ?? (string) $month;
    }

    /** Rangkai nomor lengkap: <seq>[.suf]/<kode>/<bulan>/<tahun>. */
    public static function compose(string $unit, int $seq, ?string $suffix, ?string $kode, int $month, int $year): string
    {
        $num = $suffix !== null ? "{$seq}.{$suffix}" : (string) $seq;
        $tail = self::monthLabel($unit, $month) . '/' . $year;
        if ($kode !== null && $kode !== '') {
            return "{$num}/{$kode}/{$tail}";
        }
        return "{$num}/{$tail}";
    }

    /** Tahun surat (toleran terhadap '2026-02-03' / '2026-02-03 00:00:00'). */
    private static function yearOf(?string $date): ?int
    {
        if (!$date) {
            return null;
        }
        $y = (int) substr(trim($date), 0, 4);
        return $y > 0 ? $y : null;
    }

    /**
     * Nomor dasar (sequence) yang sudah terpakai untuk unit+tahun,
     * gabungan surat keluar + slot reservasi berstatus DIPESAN.
     * @return array<int,true>
     */
    public static function usedSequences(PDO $db, string $unit, int $year): array
    {
        $out = [];
        foreach (Db::all("SELECT letter_number, letter_date FROM outgoing_letters WHERE issuing_unit = ?", [$unit]) as $r) {
            if (self::yearOf($r['letterDate'] ?? null) !== $year) continue;
            $p = self::parseNumber((string) ($r['letterNumber'] ?? ''));
            if ($p) $out[$p['sequence']] = true;
        }
        foreach (Db::all(
            "SELECT letter_number, letter_date FROM outgoing_number_slots WHERE issuing_unit = ? AND status = 'DIPESAN'",
            [$unit]
        ) as $s) {
            if (self::yearOf($s['letterDate'] ?? null) !== $year) continue;
            $p = self::parseNumber((string) ($s['letterNumber'] ?? ''));
            if ($p) $out[$p['sequence']] = true;
        }
        return $out;
    }

    /**
     * Suffix sisipan yang sudah terpakai untuk nomor dasar $seq (unit+tahun).
     * @return array<string,true>
     */
    public static function usedSuffixes(PDO $db, string $unit, int $year, int $seq): array
    {
        $out = [];
        foreach (Db::all("SELECT letter_number, letter_date FROM outgoing_letters WHERE issuing_unit = ?", [$unit]) as $r) {
            if (self::yearOf($r['letterDate'] ?? null) !== $year) continue;
            $p = self::parseNumber((string) ($r['letterNumber'] ?? ''));
            if ($p && $p['sequence'] === $seq && $p['suffix'] !== null) $out[$p['suffix']] = true;
        }
        foreach (Db::all(
            "SELECT letter_number, letter_date FROM outgoing_number_slots WHERE issuing_unit = ? AND status = 'DIPESAN'",
            [$unit]
        ) as $s) {
            if (self::yearOf($s['letterDate'] ?? null) !== $year) continue;
            $p = self::parseNumber((string) ($s['letterNumber'] ?? ''));
            if ($p && $p['sequence'] === $seq && $p['suffix'] !== null) $out[$p['suffix']] = true;
        }
        return $out;
    }

    /**
     * Hitung nomor berikutnya untuk unit pada tanggal surat tertentu.
     * $after > 0 = mode sisipan (sisipkan setelah nomor dasar $after).
     * $kode opsional untuk merangkai nomor lengkap.
     *
     * @return array{letterNumber:string, sequence:int, suffix:?string, month:string, year:string, kode:string}
     */
    public static function next(PDO $db, string $unit, string $date, ?int $after = null, ?string $kode = null): array
    {
        if (!in_array($unit, self::VALID_UNITS, true)) {
            throw new InvalidArgumentException("Unit penerbit tidak dikenal: {$unit}");
        }
        $ts = strtotime($date);
        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);

        if ($after !== null && $after > 0) {
            // Mode sisipan: cari suffix huruf bebas terkecil (a, b, c, ...).
            $used = self::usedSuffixes($db, $unit, $year, $after);
            $suffix = null;
            foreach (range('a', 'z') as $letter) {
                if (!isset($used[$letter])) {
                    $suffix = $letter;
                    break;
                }
            }
            if ($suffix === null) {
                // Semua 26 suffix terpakai (nyaris mustahil) — ambil nomor baru biasa.
                return self::next($db, $unit, $date, null, $kode);
            }
            $seq = $after;
        } else {
            // Nomor normal: lanjut dari nomor terbesar + 1, skip yang terpakai.
            $used = self::usedSequences($db, $unit, $year);
            $seq = 1;
            foreach ($used as $s => $_) {
                if ($s >= $seq) $seq = $s + 1;
            }
            while (isset($used[$seq])) $seq++;
        }

        return [
            'letterNumber' => self::compose($unit, $seq, $suffix ?? null, $kode, $month, $year),
            'sequence' => $seq,
            'suffix' => $suffix ?? null,
            'month' => self::monthLabel($unit, $month),
            'year' => (string) $year,
            'kode' => $kode ?? '',
        ];
    }

    /** Daftar nomor dasar terpakai (urut) untuk unit+tahun — buat dropdown sisipan. @return int[] */
    public static function usedNumbers(PDO $db, string $unit, int $year): array
    {
        $seqs = self::usedSequences($db, $unit, $year);
        ksort($seqs);
        return array_map('intval', array_keys($seqs));
    }
}
