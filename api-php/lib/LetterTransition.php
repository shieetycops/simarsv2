<?php
// LetterTransition — SATU pintu penerapan perpindahan tahap surat masuk.
//
// Dipakai oleh tiga jalur yang sebelum ini punya logika sendiri-sendiri:
//   1) WEB  : POST /control/:id/transition (Buku Kendali)
//   2) WA   : aksi menu tahap di chat pribadi (verifikasi/keputusan/arsip)
//   3) AUTO : laporan pelaksana PROSES/SELESAI (DispositionBridge)
//
// Tujuannya: apa pun jalurnya, validasi state machine + role + rute keputusan,
// penulisan incoming_letters, jejak letter_control_logs, dan notifikasi tahap
// (WaStageNotifier) SELALU terjadi bersamaan. Sebelumnya jalur WA sama sekali
// tidak menggerakkan tahap surat, sehingga Buku Kendali "macet".
//
// Kelas ini tidak menangani HTTP: pemanggil memetakan `code` hasilnya ke status
// HTTP (lihat handlers/control.php).
class LetterTransition
{
    // Nilai kolom letter_control_logs.action (VARCHAR(64) bebas -> tanpa migrasi).
    public const ACTION_TRANSITION = 'TRANSITION';
    public const ACTION_DECISION   = 'DISPOSITION_DECISION';
    public const ACTION_AUTO       = 'AUTO_STAGE';
    public const ACTION_REOPEN     = 'STAGE_REOPEN';
    public const ACTION_TOLAK      = 'STAGE_TOLAK';
    public const ACTION_RECALL     = 'STAGE_RECALL';

    // Sumber aksi, dicatat pada log aktivitas supaya bisa dibedakan saat audit.
    public const SOURCE_WEB  = 'WEB';
    public const SOURCE_WA   = 'WHATSAPP';
    public const SOURCE_AUTO = 'AUTO';

    /**
     * Terapkan satu perpindahan tahap.
     *
     * $opts: notes (string), route (string|null), source (SOURCE_*),
     *        action (ACTION_*), notify (bool, default true)
     *
     * @return array{ok:bool,code:string,message:string,from:string,stage:?string,route:?string,isDecision:bool,steps:string[]}
     *   code: OK | SAME_STAGE | ILLEGAL_TRANSITION | ROLE_DENIED | ROUTE_REQUIRED | ROUTE_LOCKED
     */
    public static function apply(array $user, array $letter, string $to, array $opts = []): array
    {
        $id   = (string) ($letter['id'] ?? '');
        $from = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
        $to   = strtoupper(trim($to));
        $notes  = trim((string) ($opts['notes'] ?? ''));
        $source = (string) ($opts['source'] ?? self::SOURCE_WEB);

        if ($from === $to) {
            return self::result(false, 'SAME_STAGE', "Surat sudah berada di tahap $to.", $from);
        }
        if (!V2Workflow::canTransition($from, $to)) {
            return self::result(false, 'ILLEGAL_TRANSITION', 'Transisi workflow tidak diizinkan.', $from);
        }
        if (!in_array((string) ($user['role'] ?? ''), V2Workflow::allowedRolesForTransition($from, $to), true)) {
            return self::result(false, 'ROLE_DENIED', 'Role Anda tidak berwenang untuk transisi ini.', $from);
        }

        // P2 (revisi kedua): Kasubag tidak boleh maju ke "Menunggu disposisi"
        // tanpa rekomendasi rute yang terisi (SOP/AS/04 langkah 12 -> 13).
        // Berlaku untuk semua role (Sekretaris/Admin pun harus memastikan
        // rekomendasi terisi dulu; admin bisa memberi rekomendasi sendiri).
        if ($to === 'MENUNGGU_DISPOSISI') {
            $rek = strtoupper(trim((string) ($letter['rekomendasiRoute'] ?? '')));
            if ($rek === '') {
                return self::result(false, 'REKOMENDASI_REQUIRED',
                    'Rekomendasi rute Kasubag Umum (SOP/AS/04 langkah 12) wajib terisi sebelum surat maju ke "Menunggu disposisi". Simpan dulu lewat POST /control/:id/rekomendasi.',
                    $from);
            }
        }

        // Keputusan inti SOP/AS/04 langkah 13: wajib memilih rute SEKALIGUS saat
        // masuk DIDISPOSISIKAN (termasuk jalur pintas TERREGISTRASI -> DIDISPOSISIKAN).
        $routeRaw = (array_key_exists('route', $opts) && $opts['route'] !== null)
            ? strtoupper(trim((string) $opts['route'])) : null;
        $isDecision = ($to === 'DIDISPOSISIKAN' && $from !== 'DIDISPOSISIKAN');
        if ($isDecision) {
            // SOP/AS/04 langkah 13: rute FINAL hanya boleh ditetapkan
            // Sekretaris/Panitera (+ Admin). Kasubag/pegawai/Ketua DITOLAK (403/422).
            if (!V2Workflow::canSetFinalRoute((string) ($user['role'] ?? ''))) {
                return self::result(false, 'ROLE_DENIED',
                    'Hanya Sekretaris/Panitera yang berwenang menetapkan rute final (SOP/AS/04 langkah 13). Kasubag hanya memberi rekomendasi.', $from);
            }
            [$routeOk, $routeErr] = V2Workflow::validateDispositionRoute($routeRaw);
            if (!$routeOk) return self::result(false, 'ROUTE_REQUIRED', (string) $routeErr, $from);
        }

        // Rute mengunci percabangan TEPAT sesudah keputusan; setelah itu rute
        // menjadi catatan historis (lihat handlers/control.php).
        $storedRoute = strtoupper(trim((string) ($letter['dispositionRoute'] ?? '')));
        $effectiveRoute = $isDecision ? $routeRaw : ($storedRoute !== '' ? $storedRoute : null);
        if (!$isDecision && $from === 'DIDISPOSISIKAN' && $effectiveRoute !== null) {
            $lock = V2Workflow::stagesAllowedByRoute($effectiveRoute);
            if ($lock !== [] && !in_array($to, $lock, true)) {
                return self::result(false, 'ROUTE_LOCKED',
                    'Surat ini sudah diputuskan "' . V2Workflow::routeLabel($effectiveRoute)
                        . '". Transisi ke ' . $to . ' tidak sesuai rute keputusan tersebut.', $from);
            }
        }

        // Fix 3: Unit tujuan wajib sebelum masuk tahap unit pelaksana.
        // Surat "diteruskan ke pelaksana" berarti ke UNIT (Kasubag/Panmud), bukan
        // ke pegawai perorangan. Penunjukan pegawai hanya oleh kepala unit (TUNJUK).
        if ($to === 'DITERUSKAN_KE_PELAKSANA') {
            $unitRaw = strtoupper(trim((string) ($opts['unit_tujuan'] ?? $letter['unitTujuan'] ?? '')));
            if ($unitRaw === '') {
                return self::result(false, 'UNIT_TUJUAN_REQUIRED',
                    'Unit tujuan wajib dipilih (Kasubag/Panmud mana) sebelum surat diteruskan ke unit pelaksana.', $from);
            }
            [$unitOk, $unitErr] = V2Workflow::validateUnitTujuan($unitRaw);
            if (!$unitOk) return self::result(false, 'UNIT_TUJUAN_INVALID', (string) $unitErr, $from);
        }

        // Fix 4: Tutup jalan pintas ke pegawai perorangan.
        // Status "diteruskan ke pelaksana" = ke UNIT; assignee_user_id HARUS null
        // saat masuk tahap ini. Penunjukan pegawai hanya via TUNJUK oleh kepala unit.
        // Server menolak lompatan Sekretaris/Panitera atau Ketua/WK langsung ke pegawai.
        if ($to === 'DITERUSKAN_KE_PELAKSANA' && !empty($opts['assignee_user_id'])) {
            return self::result(false, 'DIRECT_ASSIGN_DENIED',
                'Surat diteruskan ke UNIT pelaksana, bukan ke pegawai perorangan. Penunjukan pegawai hanya oleh kepala unit (Kasubag/Panmud) via TUNJUK.', $from);
        }

        // SOP/AS/04 langkah 14 + Q1 locked: Ketua/WK mengisi ARAHAN (wajib) saat
        // surat kembali dari MENUNGGU_KEBIJAKAN_PIMPINAN ke Sekretaris.
        // Arahan = final, tidak ada opsi "kembali revisi setelah arahan".
        if ($from === 'MENUNGGU_KEBIJAKAN_PIMPINAN' && $to === 'DITERUSKAN_KE_SEKRETARIS_PANITERA') {
            $arahanRaw = trim((string) ($opts['arahan'] ?? ''));
            [$arahanOk, $arahanErr] = V2Workflow::validateArahan($arahanRaw);
            if (!$arahanOk) return self::result(false, 'ARAHAN_REQUIRED', (string) $arahanErr, $from);
        }

        // Fix 5 (Q2 locked): yang menandai DIARSIPKAN hanya ARSIPARIS (+ Admin).
        // Pegawai pelaksana TIDAK BOLEH.
        if ($to === 'DIARSIPKAN' && !V2Workflow::canMarkArchived((string) ($user['role'] ?? ''))) {
            return self::result(false, 'ROLE_DENIED',
                'Hanya Arsiparis (pencatat surat) yang berwenang menandai DIARSIPKAN (SOP/AS/04 langkah 17-18).', $from);
        }

        // K7 (DEFAULT SEMENTARA - menunggu review pimpinan): serah-terima lembar
        // 1 disposisi DICATAT tetapi TIDAK wajib sebelum ARSIP. Bila belum
        // tercatat: tampilkan peringatan + tulis di log, JANGAN blokir —
        // kecuali toggle workflow_settings.lembar1_wajib dinyalakan.
        $lembar1Warning = null;
        if ($to === 'DIARSIPKAN') {
            $lembar1By = trim((string) ($letter['lembar1DiserahkanOleh'] ?? ''));
            if ($lembar1By === '') {
                if (WorkflowConfig::lembar1Wajib()) {
                    return self::result(false, 'LEMBAR1_REQUIRED',
                        'Serah-terima lembar 1 disposisi (SOP/AS/04 langkah 17) belum tercatat. Catat dulu siapa menyerahkan/menerima lewat POST /control/:id/lembar1, atau matikan toggle "wajib" di Pengaturan.',
                        $from);
                }
                $lembar1Warning = 'PERINGATAN: surat diarsipkan TANPA serah-terima lembar 1 disposisi tercatat (SOP/AS/04 langkah 17).';
            }
        }

        return self::write($user, $letter, $from, $to, [
            'notes' => $notes,
            'action' => (string) ($opts['action'] ?? ($isDecision ? self::ACTION_DECISION : self::ACTION_TRANSITION)),
            'source' => $source,
            'route' => $isDecision ? $routeRaw : $effectiveRoute,
            'isDecision' => $isDecision,
            'notify' => $opts['notify'] ?? true,
            'unit_tujuan' => $opts['unit_tujuan'] ?? null,
            'lembar1Warning' => $lembar1Warning,
            'arahan' => $opts['arahan'] ?? null,
        ]);
    }

    /**
     * Penulisan DB + jejak audit satu langkah. Dipisah supaya apply() dan
     * applyPath() memakai kode yang sama persis.
     */
    private static function write(array $user, array $letter, string $from, string $to, array $o): array
    {
        $id         = (string) ($letter['id'] ?? '');
        $actorId    = (string) ($user['id'] ?? '');
        $isDecision = (bool) ($o['isDecision'] ?? false);
        $route      = $o['route'] ?? null;
        $route      = is_string($route) && $route !== '' ? $route : null;
        $notes      = trim((string) ($o['notes'] ?? ''));
        $action     = (string) ($o['action'] ?? self::ACTION_TRANSITION);
        $source     = (string) ($o['source'] ?? self::SOURCE_WEB);
        $unitTujuan = isset($o['unit_tujuan']) && is_string($o['unit_tujuan']) && trim($o['unit_tujuan']) !== ''
            ? strtoupper(trim($o['unit_tujuan'])) : null;
        $arahan     = isset($o['arahan']) ? trim((string) $o['arahan']) : null;
        $arahan     = ($arahan !== null && $arahan !== '') ? $arahan : null;
        $logNotes   = $isDecision
            ? trim('Keputusan Sekretaris/Panitera: ' . V2Workflow::routeLabel($route) . '.' . ($notes !== '' ? ' ' . $notes : ''))
            : $notes;

        $pdo = Db::$pdo;
        $pdo->beginTransaction();
        try {
            Db::q("UPDATE incoming_letters SET current_stage = ?,
                registered_at = CASE WHEN ? = 'TERREGISTRASI' THEN COALESCE(registered_at, NOW()) ELSE registered_at END,
                archived_at = CASE WHEN ? = 'DIARSIPKAN' THEN NOW() ELSE archived_at END,
                archived_by = CASE WHEN ? = 'DIARSIPKAN' THEN ? ELSE archived_by END,
                unit_tujuan = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_tujuan END,
                unit_tujuan_by = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_tujuan_by END,
                unit_tujuan_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE unit_tujuan_at END,
                arahan_pimpinan = CASE WHEN ? IS NOT NULL THEN ? ELSE arahan_pimpinan END,
                arahan_by = CASE WHEN ? IS NOT NULL THEN ? ELSE arahan_by END,
                arahan_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE arahan_at END
                WHERE id = ?", [$to, $to, $to, $to, $actorId,
                    $unitTujuan, $unitTujuan, $unitTujuan, $actorId, $unitTujuan,
                    $arahan, $arahan, $arahan, $actorId, $arahan, $id]);
            if ($isDecision) {
                Db::q('UPDATE incoming_letters SET disposition_route = ?, disposition_route_by = ?, disposition_route_at = NOW() WHERE id = ?',
                    [$route, $actorId, $id]);
            }
            Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Db::generateId(), $id, $actorId, $from, $to, $action, $logNotes !== '' ? $logNotes : null]);
            // K7: arsip tanpa serah-terima lembar 1 -> peringatan TERSIMPAN di
            // log (tidak memblokir, kecuali toggle lembar1_wajib aktif).
            $lembar1Warning = trim((string) ($o['lembar1Warning'] ?? ''));
            if ($to === 'DIARSIPKAN' && $lembar1Warning !== '') {
                Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [Db::generateId(), $id, $actorId, $to, $to, 'ARSIP_TANPA_LEMBAR1', $lembar1Warning]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        logActivity($actorId, $action, 'INCOMING_LETTER', $id,
            $isDecision ? "$from -> $to ($route) [$source]" : "$from -> $to [$source]");

        if (($o['notify'] ?? true) !== false) {
            // Surat di memori sudah pindah tahap: notifikasi memakai keadaan baru.
            $letter['currentStage'] = $to;
            WaStageNotifier::afterTransition($letter, $from, $to, $user,
                ['source' => $source, 'notes' => $logNotes]);
        }

        return [
            'ok' => true, 'code' => 'OK',
            'message' => 'Tahap surat diperbarui ke ' . $to . '.',
            'from' => $from, 'stage' => $to, 'route' => $route,
            'isDecision' => $isDecision, 'steps' => [$to],
            'warning' => ($to === 'DIARSIPKAN' && $lembar1Warning !== '') ? $lembar1Warning : null,
        ];
    }

    /**
     * Terapkan RENTETAN langkah (dipakai auto-advance laporan pelaksana).
     * Setiap langkah menulis jejak auditnya sendiri; langkah pertama yang gagal
     * menghentikan sisa rantai supaya tidak ada lompatan tahap diam-diam.
     * Notifikasi hanya dikirim untuk langkah TERAKHIR (pemegang tahap akhir).
     */
    public static function applyPath(array $user, array $letter, array $steps, array $opts = []): array
    {
        $steps = array_values(array_filter($steps, fn($s) => is_string($s) && $s !== ''));
        if (!$steps) {
            return self::result(false, 'NO_STEPS', 'Tidak ada langkah tahap yang bisa diterapkan.',
                (string) ($letter['currentStage'] ?? ''));
        }
        $done = [];
        $current = $letter;
        $last = count($steps) - 1;
        foreach ($steps as $i => $step) {
            $res = self::apply($user, $current, (string) $step, array_merge($opts, [
                'notify' => $i === $last ? ($opts['notify'] ?? true) : false,
            ]));
            if (!$res['ok']) {
                return array_merge($res, ['steps' => $done]);
            }
            $done[] = (string) $step;
            $current['currentStage'] = (string) $step;
            if (!empty($res['route'])) $current['dispositionRoute'] = $res['route'];
        }
        return [
            'ok' => true, 'code' => 'OK',
            'message' => 'Tahap surat diperbarui ke ' . end($done) . '.',
            'from' => (string) ($letter['currentStage'] ?? ''),
            'stage' => (string) end($done),
            'route' => $current['dispositionRoute'] ?? null,
            'isDecision' => false, 'steps' => $done,
        ];
    }

    /**
     * TOLAK / RECALL / koreksi ADMIN (K3 — DEFAULT SEMENTARA, menunggu review
     * pimpinan). Satu pintu untuk SEMUA gerak mundur:
     *   - TOLAK: pemegang surat mengembalikan SATU langkah ke pengirim
     *     sebelumnya (pegawai -> kepala unit -> Sekretaris/Panitera ->
     *     Kasubag Umum). Alasan wajib >= tolak_reason_min.
     *   - RECALL: pengirim menarik kiriman yang baru ia kirim, HANYA bila
     *     penerima belum melakukan aksi apa pun (dicek dari baris log kendali
     *     terakhir). Setelah penerima bertindak -> ditolak.
     *   - ADMIN_REOPEN: koreksi kasus khusus oleh ADMIN (tercatat).
     * Ketua/WK tidak punya TOLAK (Q1: arahan final).
     *
     * Semua tercatat di letter_control_logs dengan action STAGE_TOLAK /
     * STAGE_RECALL / STAGE_REOPEN: pelaku, alasan, waktu, tahap asal, tahap
     * tujuan — gerak mundur tidak pernah tersamar sebagai kemajuan tahap.
     *
     * $opts: source (SOURCE_WEB|SOURCE_WA, default WEB).
     */
    public static function reopen(array $user, array $letter, string $reason, array $opts = []): array
    {
        $from   = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
        $id     = (string) ($letter['id'] ?? '');
        $source = (string) ($opts['source'] ?? self::SOURCE_WEB);

        // Syarat RECALL K3: baris KEDATANGAN terakhir ke tahap sekarang +
        // penerima belum melakukan aksi apa pun di tahap ini sejak kedatangan.
        // (Baris log dalam detik yang sama tak bisa diurutkan andal — id-nya
        // acak — jadi syaratnya dihitung eksplisit, bukan "baris terakhir".)
        $lastLog = null;
        if ($id !== '' && Db::$pdo) {
            try {
                $arrival = Db::one("SELECT actor_user_id, from_stage, to_stage, created_at
                    FROM letter_control_logs
                    WHERE incoming_letter_id = ? AND to_stage = ? AND from_stage <> ?
                    ORDER BY created_at DESC, id DESC LIMIT 1", [$id, $from, $from]);
                if ($arrival) {
                    $acted = (int) Db::one("SELECT COUNT(*) AS c FROM letter_control_logs
                        WHERE incoming_letter_id = ? AND from_stage = ? AND to_stage = ? AND created_at >= ?",
                        [$id, $from, $from, $arrival['createdAt'] ?? $arrival['created_at'] ?? null])['c'];
                    if ($acted === 0) $lastLog = $arrival;
                }
            } catch (Throwable $e) {
                $lastLog = null;
            }
        }

        $plan = V2Workflow::planReject($user, $letter, $lastLog);
        if (!$plan['ok']) {
            return self::result(false, 'REOPEN_DENIED', (string) $plan['message'], $from);
        }
        [$ok, $err] = V2Workflow::validateReopenReason($reason, WorkflowConfig::tolakReasonMin());
        if (!$ok) return self::result(false, 'REOPEN_REASON', (string) $err, $from);

        $to    = (string) $plan['to'];
        $mode  = (string) $plan['mode'];
        $actorId = (string) ($user['id'] ?? '');
        $action = $mode === 'TOLAK' ? self::ACTION_TOLAK
            : ($mode === 'RECALL' ? self::ACTION_RECALL : self::ACTION_REOPEN);
        $reasonTrim = trim($reason);
        if ($mode === 'TOLAK') {
            $logNotes = 'TOLAK satu langkah (' . V2Workflow::stageLabel($from) . ' -> '
                . V2Workflow::stageLabel($to) . '): ' . $reasonTrim;
        } elseif ($mode === 'RECALL') {
            $logNotes = 'RECALL oleh pengirim, penerima belum bertindak ('
                . V2Workflow::stageLabel($from) . ' -> ' . V2Workflow::stageLabel($to) . '): ' . $reasonTrim;
        } else {
            $logNotes = 'Koreksi ADMIN kasus khusus (' . V2Workflow::stageLabel($from) . ' -> '
                . V2Workflow::stageLabel($to) . '): ' . $reasonTrim;
        }

        $pdo = Db::$pdo;
        $pdo->beginTransaction();
        try {
            Db::q('UPDATE incoming_letters SET current_stage = ? WHERE id = ?', [$to, $id]);
            Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Db::generateId(), $id, $actorId, $from, $to, $action, $logNotes]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        logActivity($actorId, $action, 'INCOMING_LETTER', $id, "$from -> $to [$source][$mode]");

        $letter['currentStage'] = $to;
        WaStageNotifier::afterTransition($letter, $from, $to, $user,
            ['source' => $source, 'notes' => $logNotes, 'reopen' => true]);

        return [
            'ok' => true, 'code' => 'OK', 'mode' => $mode,
            'message' => (string) $plan['message'],
            'from' => $from, 'stage' => $to, 'route' => $letter['dispositionRoute'] ?? null,
            'isDecision' => false, 'steps' => [$to],
        ];
    }

    /**
     * K7: catat serah-terima LEMBAR 1 disposisi (SOP/AS/04 langkah 17) —
     * penyerah, penerima, waktu. Sekali catat, tidak bisa ditimpa (audit).
     */
    public static function recordLembar1(array $user, array $letter, string $penyerahId, string $penerimaId): array
    {
        $from = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
        $id   = (string) ($letter['id'] ?? '');
        if (trim((string) ($letter['lembar1DiserahkanOleh'] ?? '')) !== '') {
            return self::result(false, 'LEMBAR1_ALREADY',
                'Serah-terima lembar 1 disposisi sudah tercatat untuk surat ini dan tidak boleh ditimpa.', $from);
        }
        $penyerahId = trim($penyerahId) !== '' ? trim($penyerahId) : (string) ($user['id'] ?? '');
        $penerimaId = trim($penerimaId);
        if ($penerimaId === '') {
            return self::result(false, 'LEMBAR1_PENERIMA_REQUIRED',
                'Penerima lembar 1 disposisi wajib dipilih.', $from);
        }
        $penyerah = Db::one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$penyerahId]);
        $penerima = Db::one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$penerimaId]);
        if (!$penyerah || !$penerima) {
            return self::result(false, 'LEMBAR1_INVALID',
                'Penyerah/penerima lembar 1 tidak ditemukan atau nonaktif.', $from);
        }

        $actorId = (string) ($user['id'] ?? '');
        $pdo = Db::$pdo;
        $pdo->beginTransaction();
        try {
            Db::q('UPDATE incoming_letters SET lembar1_diserahkan_oleh = ?, lembar1_diterima_oleh = ?, lembar1_diserahkan_at = NOW() WHERE id = ?',
                [$penyerahId, $penerimaId, $id]);
            Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Db::generateId(), $id, $actorId, $from, $from, 'LEMBAR1_SERAH_TERIMA',
                    'Serah-terima lembar 1 disposisi: ' . $penyerah['name'] . ' -> ' . $penerima['name'] . '.']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        logActivity($actorId, 'LEMBAR1_SERAH_TERIMA', 'INCOMING_LETTER', $id,
            'Lembar 1: ' . $penyerah['name'] . ' -> ' . $penerima['name']);

        return [
            'ok' => true, 'code' => 'OK', 'mode' => 'LEMBAR1',
            'message' => 'Serah-terima lembar 1 disposisi tercatat: ' . $penyerah['name'] . ' -> ' . $penerima['name'] . '.',
            'from' => $from, 'stage' => $from, 'route' => null,
            'isDecision' => false, 'steps' => [],
        ];
    }

    /**
     * Simpan REKOMENDASI rute Kasubag Umum (SOP/AS/04 langkah 12).
     * Rekomendasi BUKAN keputusan final; tidak menimpa disposition_route.
     * Role: REKOMENDASI_ROLES (Kasubag + Sekretaris/Panitera + Admin).
     */
    public static function saveRekomendasi(array $user, array $letter, string $route, string $notes): array
    {
        $role = (string) ($user['role'] ?? '');
        if (!V2Workflow::canGiveRekomendasi($role)) {
            return self::result(false, 'ROLE_DENIED',
                'Role Anda tidak berwenang memberi rekomendasi rute.', (string) ($letter['currentStage'] ?? ''));
        }
        [$ok, $err] = V2Workflow::validateRekomendasi($route, $notes);
        if (!$ok) return self::result(false, 'REKOMENDASI_INVALID', (string) $err, (string) ($letter['currentStage'] ?? ''));

        $id      = (string) ($letter['id'] ?? '');
        $actorId = (string) ($user['id'] ?? '');
        $routeUp = strtoupper(trim($route));
        $from    = strtoupper(trim((string) ($letter['currentStage'] ?? '')));

        $pdo = Db::$pdo;
        $pdo->beginTransaction();
        try {
            Db::q('UPDATE incoming_letters SET rekomendasi_route = ?, rekomendasi_route_by = ?, rekomendasi_route_at = NOW(), rekomendasi_notes = ? WHERE id = ?',
                [$routeUp, $actorId, trim($notes), $id]);
            Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Db::generateId(), $id, $actorId, $from, $from, 'REKOMENDASI_ROUTE',
                    'Rekomendasi Kasubag: ' . V2Workflow::routeLabel($routeUp) . '. ' . trim($notes)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        logActivity($actorId, 'REKOMENDASI_ROUTE', 'INCOMING_LETTER', $id, "Rekomendasi: $routeUp [WEB]");

        return [
            'ok' => true, 'code' => 'OK',
            'message' => 'Rekomendasi tersimpan: ' . V2Workflow::routeLabel($routeUp) . '. Keputusan final tetap di Sekretaris/Panitera.',
            'from' => $from, 'stage' => $from, 'route' => $routeUp, 'isDecision' => false, 'steps' => [],
        ];
    }

    /**
     * Tetapkan RUTE FINAL Sekretaris/Panitera (SOP/AS/04 langkah 13).
     * HANYA FINAL_ROUTE_ROLES. Tidak menimpa rekomendasi_route.
     * $opts: unit_tujuan (wajib bila LANGSUNG), notes.
     */
    public static function decideRoute(array $user, array $letter, string $route, array $opts = []): array
    {
        $role = (string) ($user['role'] ?? '');
        $from = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
        if (!V2Workflow::canSetFinalRoute($role)) {
            return self::result(false, 'ROLE_DENIED',
                'Hanya Sekretaris/Panitera yang berwenang menetapkan rute final (SOP/AS/04 langkah 13).', $from);
        }
        // P2 (revisi kedua): keputusan hanya sah saat surat sudah pada tahap
        // keputusan. Tanpa ini, KEBIJAKAN/LANGSUNG dari Sekretaris saat surat
        // masih Disortir/pengarahan merekam rute TANPA memindahkan tahap.
        if (!in_array($from, V2Workflow::DECISION_ALLOWED_STAGES, true)) {
            return self::result(false, 'STAGE_NOT_READY',
                'Keputusan rute hanya boleh saat surat sudah pada tahap keputusan ('
                . implode(' / ', V2Workflow::DECISION_ALLOWED_STAGES)
                . '). Surat ini masih di tahap ' . ($from !== '' ? V2Workflow::stageLabel($from) : '-') . '.',
                $from);
        }
        [$ok, $err] = V2Workflow::validateDispositionRoute($route);
        if (!$ok) return self::result(false, 'ROUTE_REQUIRED', (string) $err, $from);
        $routeUp = strtoupper(trim($route));
        $notes = trim((string) ($opts['notes'] ?? ''));

        // LANGSUNG wajib menyertakan unit tujuan.
        $unitTujuan = null;
        if ($routeUp === 'LANGSUNG') {
            $unitTujuan = strtoupper(trim((string) ($opts['unit_tujuan'] ?? '')));
            [$uok, $uerr] = V2Workflow::validateUnitTujuan($unitTujuan);
            if (!$uok) return self::result(false, 'UNIT_TUJUAN_REQUIRED', (string) $uerr, $from);
        }

        $id      = (string) ($letter['id'] ?? '');
        $actorId = (string) ($user['id'] ?? '');

        $pdo = Db::$pdo;
        $pdo->beginTransaction();
        try {
            Db::q('UPDATE incoming_letters SET disposition_route = ?, disposition_route_by = ?, disposition_route_at = NOW(),'
                . ' unit_tujuan = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_tujuan END,'
                . ' unit_tujuan_by = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_tujuan_by END,'
                . ' unit_tujuan_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE unit_tujuan_at END'
                . ' WHERE id = ?',
                [$routeUp, $actorId, $unitTujuan, $unitTujuan, $unitTujuan, $actorId, $unitTujuan, $id]);
            Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Db::generateId(), $id, $actorId, $from, $from, self::ACTION_DECISION,
                    'Keputusan final Sekretaris/Panitera: ' . V2Workflow::routeLabel($routeUp) . '.'
                    . ($unitTujuan ? ' Unit: ' . $unitTujuan . '.' : '') . ($notes !== '' ? ' ' . $notes : '')]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        logActivity($actorId, self::ACTION_DECISION, 'INCOMING_LETTER', $id, "Keputusan final: $routeUp" . ($unitTujuan ? " unit $unitTujuan" : ''));

        return [
            'ok' => true, 'code' => 'OK',
            'message' => 'Keputusan final tersimpan: ' . V2Workflow::routeLabel($routeUp) . '.',
            'from' => $from, 'stage' => $from, 'route' => $routeUp, 'isDecision' => true, 'steps' => [],
        ];
    }

    /** Bentuk hasil gagal yang seragam (dipakai pemanggil untuk memetakan HTTP). */
    private static function result(bool $ok, string $code, string $message, string $from): array
    {
        return [
            'ok' => $ok, 'code' => $code, 'message' => $message, 'from' => $from,
            'stage' => null, 'route' => null, 'isDecision' => false, 'steps' => [],
        ];
    }

}

// Dependensi konfigurasi K3/K7 (batas alasan tolak, lembar 1) — di-require_once
// supaya file ini juga aman dipakai runner uji yang memuat lib satu per satu.
require_once __DIR__ . '/WorkflowConfig.php';
