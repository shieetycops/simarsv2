<?php
// WaStageNotifier — notifikasi + menu aksi WhatsApp saat surat berpindah tahap.
//
// Fase 2 (keputusan #2 = "full WA"): Kasubag Umum, Sekretaris, dan Panitera ikut
// menerima pemberitahuan setiap surat masuk ke meja mereka DAN bisa bertindak
// langsung dari WhatsApp lewat menu bernomor. Sebelumnya notifikasi WA hanya
// terbit saat disposisi dibuat (PIMPINAN & pelaksana), sehingga surat yang
// menunggu di meja Kasubag/Sekretaris tidak pernah memberi tahu siapa pun.
//
// Dua hal dikerjakan untuk setiap perpindahan tahap:
//   1) Pesan pemberitahuan ke pemegang tahap BARU (V2Workflow::stageOwners).
//   2) Antrean tugas aksi di wa_sessions (kind VERIFIKASI/DEKISION/ARCHIVE)
//      untuk pemegang tahap yang memang BERWENANG bertindak di tahap itu.
//
// wa_sessions ber-PK user_id => satu sesi aktif per user. Karena itu:
//   - sesi LEADER/EMPLOYEE yang masih hidup TIDAK direbut (tugas tahap hanya
//     dikirim sebagai pesan; aksinya tetap bisa lewat web), dan
//   - tugas tahap berikutnya DITAMBAHKAN ke antrean (context.queue), bukan
//     menimpa tugas yang sedang ditampilkan.
//
// Semua kegagalan WA ditelan (error_log): pengiriman WhatsApp tidak boleh
// menggagalkan perpindahan tahap yang datanya sudah tersimpan.
class WaStageNotifier
{
    // Batas antrean tugas per user supaya menu tetap pendek & terbaca.
    public const QUEUE_MAX = 5;

    // Jenis sesi WA untuk aksi tahap. Daftar ini dipakai handler bot untuk
    // memutuskan bahwa angka balasan berarti PILIHAN TINDAKAN SURAT (bukan menu
    // disposisi pimpinan atau menu status pegawai).
    public const STAGE_KINDS = ['VERIFIKASI', 'DEKISION', 'ARCHIVE'];

    // Tahap yang pemegangnya = pelaksana (assignee surat), bukan role tertentu.
    private const PELAKSANA_STAGES = ['DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT'];

    // Jenis sesi aksi per tahap. Tahap lain memakai VERIFIKASI (penerusan surat);
    // jenis ini hanya terpakai bila pemegang tahap memang punya tindakan sah
    // menurut V2Workflow::allowedTransitionsFor().
    private const KIND_BY_STAGE = [
        'MENUNGGU_DISPOSISI'      => 'DEKISION',
        'DIDISPOSISIKAN'          => 'DEKISION',
        'MENUNGGU_PENGARSIPAN'    => 'ARCHIVE',
        'SELESAI_DITINDAKLANJUTI' => 'ARCHIVE',
    ];

    /** Jenis sesi WA untuk sebuah tahap (null bila tahap bukan tahap resmi). */
    public static function stageSessionKind(?string $stage): ?string
    {
        $key = strtoupper(trim((string) $stage));
        if ($key === '' || !in_array($key, V2Workflow::STAGES, true)) return null;
        return self::KIND_BY_STAGE[$key] ?? 'VERIFIKASI';
    }

    /**
     * Panggil SETELAH tahap surat tersimpan (LetterTransition). Return ringkasan
     * untuk log/uji: ['notified'=>int, 'sessions'=>int, 'skipped'=>int, 'reason'=>?string]
     */
    public static function afterTransition(array $letter, ?string $from, string $to, ?array $actor, array $opts = []): array
    {
        $summary = ['notified' => 0, 'sessions' => 0, 'skipped' => 0, 'reason' => null];
        try {
            $letterId = (string) ($letter['id'] ?? '');
            if ($letterId === '') { $summary['reason'] = 'TANPA_ID_SURAT'; return $summary; }

            $settings = Whatsapp::settings(Db::$pdo);
            if (!(int) ($settings['isEnabled'] ?? 0)) { $summary['reason'] = 'WA_NONAKTIF'; return $summary; }

            $recipients = self::recipients($letter, $to);
            $actorId = (string) ($actor['id'] ?? '');
            $sensitive = V2Workflow::mustWithholdWaContent((string) ($letter['securityLevel'] ?? 'BIASA'));
            $token = (string) ($settings['fonnteToken'] ?? '');
            $kind = self::stageSessionKind($to);

            foreach ($recipients as $u) {
                $uid = (string) ($u['id'] ?? '');
                if ($uid === '' || $uid === $actorId) continue;
                $wa = Wabot::normalizeNumber($u['waNumber'] ?? null);
                if (!$wa) { $summary['skipped']++; continue; }

                // Menu aksi hanya dibuat bila role user ini memang berwenang
                // bertindak pada tahap baru; kalau tidak, cukup pemberitahuan.
                $task = null;
                if ($kind !== null) {
                    $task = self::pushActionTask($u, $letter, $to, $kind, $actorId);
                    if ($task !== null) $summary['sessions']++;
                }

                try {
                    Whatsapp::send($token, $wa, Wabot::buildStageNoticeText([
                        'name'         => (string) ($u['name'] ?? ''),
                        'letterId'     => $letterId,
                        'agendaNumber' => $letter['agendaNumber'] ?? null,
                        'subject'      => $letter['subject'] ?? null,
                        'toStage'      => $to,
                        'fromStage'    => $from,
                        'actorName'    => $actor['name'] ?? null,
                        'sensitive'    => $sensitive,
                        'withMenu'     => $task !== null,
                        'actionHint'   => $opts['notes'] ?? null,
                        'reopened'     => !empty($opts['reopen']),
                    ]), $actorId !== '' ? $actorId : null);
                    $summary['notified']++;
                    // Menu bernomor dikirim terpisah supaya pemberitahuan tetap
                    // ringkas dan mudah dibaca ulang lewat perintah MENU.
                    if ($task !== null) {
                        Whatsapp::send($token, $wa, Wabot::buildStageTaskMenu($task, (string) ($u['name'] ?? '')),
                            $actorId !== '' ? $actorId : null);
                    }
                } catch (Throwable $e) {
                    // send() sudah menulis error_log + audit WHATSAPP_FAILED.
                    error_log('[WaStageNotifier] gagal kirim ke ' . $uid . ': ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[WaStageNotifier] afterTransition gagal: ' . $e->getMessage());
            $summary['reason'] = 'GALAT';
        }
        return $summary;
    }

    /**
     * Penerima pemberitahuan tahap $to: role pemegang tahap + pelaksana
     * (assignee surat; fallback penerima disposisi terbaru).
     *
     * @return array<int,array{id:string,name:string,role:string,waNumber:?string}>
     */
    private static function recipients(array $letter, string $to): array
    {
        $out = [];
        $roles = V2Workflow::stageOwners($to);
        if ($roles) {
            $in = implode(',', array_fill(0, count($roles), '?'));
            foreach (Db::all("SELECT id, name, role, wa_number FROM users
                              WHERE is_active = 1 AND role IN ($in)", $roles) as $u) {
                $out[(string) $u['id']] = $u;
            }
        }
        if (in_array($to, self::PELAKSANA_STAGES, true)) {
            $assignee = (string) ($letter['assigneeUserId'] ?? '');
            if ($assignee === '') {
                $row = Db::one("SELECT to_user_id FROM dispositions WHERE incoming_letter_id = ?
                                ORDER BY created_at DESC, id DESC LIMIT 1", [(string) ($letter['id'] ?? '')]);
                $assignee = (string) ($row['toUserId'] ?? '');
            }
            if ($assignee !== '') {
                $u = Db::one("SELECT id, name, role, wa_number FROM users WHERE id = ? AND is_active = 1", [$assignee]);
                if ($u) $out[(string) $u['id']] = $u;
            }
        }
        return array_values($out);
    }

    /**
     * Tambahkan tugas aksi tahap ke antrean sesi WA user. Return TASK-nya bila
     * benar-benar masuk antrean (pemanggil lalu mengirim menu bernomor), atau
     * null bila dilewati.
     *
     * Dilewati bila: role tak berwenang bertindak (tidak ada transisi sah), user
     * memegang sesi LEADER/EMPLOYEE yang masih hidup, atau surat itu sudah ada
     * di antreannya (anti-dobel: satu surat satu tugas aktif per user).
     */
    private static function pushActionTask(array $u, array $letter, string $stage, string $kind, string $actorId): ?array
    {
        $userId = (string) ($u['id'] ?? '');
        if ($userId === '') return null;

        $sender = ['id' => $userId, 'role' => (string) ($u['role'] ?? '')];
        $options = V2Workflow::allowedTransitionsFor($sender, $stage, $letter['dispositionRoute'] ?? null);
        if (!$options) return null; // mis. pimpinan di MENUNGGU_KEBIJAKAN_PIMPINAN

        $menuOptions = [];
        foreach (array_values($options) as $i => $t) {
            $menuOptions[] = [
                'n'             => $i + 1,
                'toStage'       => (string) $t['toStage'],
                'label'         => (string) $t['label'],
                'requiresRoute' => !empty($t['requiresRoute']),
            ];
        }
        $task = [
            'letterId'  => (string) ($letter['id'] ?? ''),
            'agenda'    => $letter['agendaNumber'] ?? null,
            'subject'   => $letter['subject'] ?? null,
            'stage'     => $stage,
            'sensitive' => V2Workflow::mustWithholdWaContent((string) ($letter['securityLevel'] ?? 'BIASA')),
            'options'   => $menuOptions,
            'addedAt'   => date('Y-m-d H:i:s'),
        ];

        $row = Db::one("SELECT kind, step, context, expires_at FROM wa_sessions WHERE user_id = ?", [$userId]);
        $queue = [];
        $existingKind = '';
        if ($row) {
            $existingKind = (string) ($row['kind'] ?? '');
            // Sesi pimpinan/pegawai yang masih hidup tidak boleh direbut: nomor
            // balasan mereka berarti hal lain (menu disposisi/status tugas).
            if (in_array($existingKind, ['LEADER', 'EMPLOYEE'], true) && !Wabot::isExpired($row['expiresAt'] ?? null)) {
                logActivity($userId, 'WABOT_STAGE_SESSION_SKIPPED', 'INCOMING_LETTER', $task['letterId'],
                    'Sesi ' . $existingKind . ' masih aktif; tugas tahap ' . $stage . ' hanya dikirim sebagai pesan.');
                return null;
            }
            if (in_array($existingKind, self::STAGE_KINDS, true)) {
                $ctx = json_decode((string) ($row['context'] ?? ''), true) ?: [];
                $queue = array_values(array_filter((array) ($ctx['queue'] ?? []), 'is_array'));
                foreach ($queue as $q) {
                    if (($q['letterId'] ?? '') === $task['letterId']) {
                        return null; // sudah antre untuk surat ini
                    }
                }
            }
        }
        $queue[] = $task;
        if (count($queue) > self::QUEUE_MAX) {
            $dropped = array_slice($queue, 0, count($queue) - self::QUEUE_MAX);
            $queue = array_slice($queue, -self::QUEUE_MAX);
            foreach ($dropped as $d) {
                logActivity($userId, 'WABOT_STAGE_TASK_DROPPED', 'INCOMING_LETTER', (string) ($d['letterId'] ?? ''),
                    'Antrean tugas tahap penuh (' . self::QUEUE_MAX . '); surat ini harus ditangani lewat aplikasi web.');
            }
        }

        $ctxJson = json_encode(['queue' => $queue], JSON_UNESCAPED_UNICODE);
        Db::q("INSERT INTO wa_sessions (user_id, kind, step, context, expires_at)
                VALUES (?, ?, 'PICK_ACTION', ?, ?)
                ON DUPLICATE KEY UPDATE kind = VALUES(kind), step = 'PICK_ACTION',
                  context = VALUES(context), expires_at = VALUES(expires_at)",
            [$userId, $kind, $ctxJson, Wabot::newStageExpiry()]);
        return $task;
    }

}
