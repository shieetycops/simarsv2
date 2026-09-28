<?php
// tests/run_wa_branch_check.php — jalankan CABANG dispatcher (wabot_v1.php dan
// wabot_v2.php) dengan Db + Whatsapp PALSU, tanpa MySQL/phpunit.
//
//   php tests/run_wa_branch_check.php
//
// Setiap kasus jalan di sub-proses sendiri: wabot_v2.php mendeklarasikan
// fungsi, jadi tidak bisa di-require dua kali dalam satu proses.
// Yang diverifikasi: percabangan prioritas, jumlah ? vs parameter setiap query,
// kunci array camelCase dari baris DB, dan isi balasan bot.

$root = dirname(__DIR__);
$case = $argv[1] ?? null;

if ($case === null) {
    $fail = 0;
    require_once $root . '/lib/Wabot.php';
    require_once $root . '/lib/Disposition.php';
    // Catatan: lib/helpers.php sengaja tidak dimuat (lihat komentar di bagian child).

    foreach (array_keys(wa_all_cases()) as $name) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name) . ' 2>&1';
        $out = trim((string) shell_exec($cmd));
        // Buang peringatan startup ekstensi (mis. pdo_sqlite tak ada di CLI lokal).
        $out = (string) preg_replace('/^(PHP )?Warning:\s+PHP Startup:.*$/mi', '', $out);
        $lines = array_filter(explode("\n", $out));
        $bad = array_values(array_filter($lines, fn($l) => str_contains($l, 'FAIL')));
        $n = count(array_filter($lines, fn($l) => str_contains($l, 'PASS')));
        $crash = (bool) preg_match('/fatal error|uncaught|warning:|notice:|deprecated:/i', $out);
        if ($bad || $crash || $n < 2) {
            $fail++;
            echo str_pad($name, 34) . "GAGAL ({$n} cek ok)\n";
            foreach ($bad as $l) echo '    ' . $l . "\n";
            foreach (explode("\n", $out) as $l) {
                if (preg_match('/fatal error|uncaught|warning:|notice:|deprecated:/i', $l)) echo '    ' . $l . "\n";
            }
        } else {
            echo str_pad($name, 34) . "ok   {$n} cek\n";
        }
    }
    echo $fail === 0 ? "\nSEMUA KASUS LULUS\n" : "\n{$fail} KASUS GAGAL\n";
    exit($fail === 0 ? 0 : 1);
}

$GLOBALS['wa_root'] = $root;

// ---------------------------------------------------------------- child ----
require_once $root . '/lib/Wabot.php';
require_once $root . '/lib/Disposition.php';
// Fase 0-3 (WA + Buku Kendali): Wabot memakai V2Workflow untuk label tahap &
// pilihan rute; wabot_v2.php memakai WaStageNotifier (jenis sesi aksi tahap)
// serta LetterTransition + DispositionBridge saat surat/status benar-benar
// berpindah. Kelas-kelas ini dimuat nyata (bukan di-stub) supaya harness ikut
// menangkap perubahan kontraknya.
require_once $root . '/lib/V2Workflow.php';
require_once $root . '/lib/WaStageNotifier.php';
require_once $root . '/lib/LetterTransition.php';
require_once $root . '/lib/DispositionBridge.php';
// Harness TIDAK memuat lib/helpers.php: berkas itu mendeklarasikan logActivity()
// dan letterViewUrl() yang di sini di-stub agar efeknya bisa diperiksa. Helper
// yang tetap dibutuhkan wabot.php (maskPhone) di-stub di bawah ini.
// Sensor nomor WA untuk log, sama seperti helpers.php (nomor WA = data pribadi).
function maskPhone(?string $phone): string
{
    $p = (string) $phone;
    if (strlen($p) <= 8) {
        return str_repeat('*', strlen($p));
    }
    return substr($p, 0, 4) . str_repeat('*', strlen($p) - 8) . substr($p, -4);
}

// Fase 3: kandidat menu "buat disposisi". harness ini TIDAK memuat helpers.php,
// jadi fungsi yang di sana didefinisikan harus disediakan sendiri — SELECT-nya
// sengaja ditulis sama supaya fixture SQL milik harness tetap cocok.
function waDispositionCandidates(string $actorId, string $actorRole, int $limit): array
{
    $limit = max(1, $limit);
    if (!in_array($actorRole, ['ADMIN', 'PIMPINAN'], true)) {
        return Db::all("SELECT id, name, role FROM users
                        WHERE is_active = 1 AND supervisor_id = ? AND id <> ?
                          AND wa_number IS NOT NULL AND wa_number <> ''
                        ORDER BY name ASC LIMIT " . ($limit + 1), [$actorId, $actorId]);
    }
    return Db::all("SELECT id, name, role FROM users
                    WHERE is_active = 1 AND id <> ?
                      AND wa_number IS NOT NULL AND wa_number <> ''
                    ORDER BY name ASC LIMIT " . ($limit + 1), [$actorId]);
}


class Db
{
    public static $pdo = null;
    public static array $fixtures = [];   // [['re' => '/…/i', 'rows' => [...]], …]
    public static array $queries = [];    // log TULISAN (INSERT/UPDATE/DELETE)
    public static array $reads = [];      // log PEMBACAAN
    public static int $seq = 0;
    public static array $errors = [];

    public static function generateId(): string
    {
        return 'gen-' . str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    public static function all(string $sql, array $params = []): array
    {
        self::audit($sql, $params);
        self::$reads[] = ['sql' => $sql, 'params' => $params];
        $s = strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));
        foreach (self::$fixtures as $f) {
            if (str_contains($s, $f['has'])) return $f['rows'];
        }
        return [];
    }

    public static function q(string $sql, array $params = []): object
    {
        self::audit($sql, $params);
        self::$queries[] = ['sql' => $sql, 'params' => $params];
        return new class {
            public function fetch(): ?array { return null; }
            public function fetchColumn() { return 0; }
        };
    }

    private static function audit(string $sql, array $params): void
    {
        $bare = preg_replace("/'[^']*'/", '', $sql);
        $want = substr_count((string) $bare, '?');
        if ($want !== count($params)) {
            self::$errors[] = "parameter tidak cocok ({$want} tandingan, " . count($params) . ' diberikan): '
                . trim((string) preg_replace('/\s+/', ' ', $sql));
        }
    }
}

class Whatsapp
{
    public static array $calls = [];
    public static ?array $settingsOverride = null;

    public static function settings($db = null): array
    {
        return self::$settingsOverride
            ?? ['isEnabled' => 1, 'fonnteToken' => 'tok', 'groupTarget' => '120363@g.us', 'waGroupMarker' => ''];
    }
    public static function send(string $token, string $target, string $message, ?string $actor = null, ?string $inbox = null): void
    {
        self::$calls[] = ['send', $target, $message];
    }
    public static function notifyGroup($db, string $message, ?string $actor = null): void
    {
        self::$calls[] = ['group', '120363@g.us', $message];
    }
    public static function notifyDispositionStatus($db, array $a): void
    {
        self::$calls[] = ['status', '-', json_encode($a)];
    }
    public static function notifyNewDisposition($db, array $a): void
    {
        self::$calls[] = ['new', '-', json_encode($a)];
    }
    public static function notifyDispositionAssignedDm($db, array $a): void
    {
        self::$calls[] = ['dm', (string) ($a['waTarget'] ?? ''), json_encode($a)];
    }
    public static function normalizePhone(?string $input): ?string
    {
        return $input;
    }
}

class WabotHook
{
    public static array $replies = [];   // [target, teks]
    public static array $closed = [];    // [userId, dispositionId|null]
    public static array $logs = [];     // [userId, action, entity, entityId, details]
}

function logActivity(string $userId, string $action, string $entity, ?string $entityId, ?string $details): void
{
    WabotHook::$logs[] = [$userId, $action, $entity, $entityId, $details];
}

function letterViewUrl(string $id): string
{
    return 'http://test-host/surats/' . rawurlencode($id);
}
// --------------------------------------------------------------- runner ----

/** stream php://input palsu supaya dispatcher bisa dijalankan apa adanya. */
class WaInput
{
    public static string $data = '';
    public int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;
        return true;
    }
    public function stream_read(int $count): string
    {
        $s = substr(self::$data, $this->pos, $count);
        $this->pos += strlen($s);
        return $s;
    }
    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$data);
    }
    public function stream_stat(): array { return []; }
    public function url_stat(string $path, int $flags): array { return []; }
}

/** Jalankan DISPATCHER asli (lib/handlers/wabot.php) dengan payload Fonnte. */
function wa_run_dispatcher(array $payload, array $fixtures, ?array $settings = null): array
{
    Db::$fixtures = $fixtures;
    Db::$queries = []; Db::$reads = []; Db::$errors = [];
    WabotHook::$replies = []; WabotHook::$closed = []; WabotHook::$logs = [];
    Whatsapp::$calls = [];
    Whatsapp::$settingsOverride = $settings;

    WaInput::$data = json_encode($payload, JSON_UNESCAPED_UNICODE);
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'WaInput');

    $run = function () {
        $method = 'POST';
        $segments = ['wabot'];
        require $GLOBALS['wa_root'] . '/lib/handlers/wabot.php';
    };
    ob_start();
    try {
        $run();
    } catch (Throwable $e) {
        Db::$errors[] = 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage();
    }
    $out = (string) ob_get_clean();
    stream_wrapper_restore('php');

    // Balasan bot = panggilan Whatsapp::send() target pribadi (wabotReply asli).
    $replies = [];
    foreach (Whatsapp::$calls as $c) if ($c[0] === 'send') $replies[] = [$c[1], $c[2]];
    return ['out' => $out, 'text' => implode("\n", array_column($replies, 1)), 'replies' => $replies,
        'q' => Db::$queries, 'reads' => Db::$reads, 'errors' => Db::$errors,
        'calls' => Whatsapp::$calls, 'closed' => [], 'logs' => WabotHook::$logs];
}

/** Include cabang handler di scope terpisah, kumpulkan efek sampingnya. */
function wa_run_branch(string $file, array $vars, array $fixtures): array
{
    Db::$fixtures = $fixtures;
    Db::$queries = []; Db::$reads = []; Db::$errors = [];
    WabotHook::$replies = []; WabotHook::$closed = []; WabotHook::$logs = [];
    Whatsapp::$calls = [];
    Whatsapp::$settingsOverride = null;

    $run = function () use ($file, $vars) {
        // Cabang dipanggil sendiri (tanpa dispatcher): sediakan helper balas.
        function wabotSend(string $target, string $text, array $settings, string $inboxId): void
        {
            WabotHook::$replies[] = [$target, $text];
        }
        function wabotReply(string $text, array $settings, string $inboxId, string $target): void
        {
            WabotHook::$replies[] = [$target, $text];
        }
        function wabotCloseEmployeeSession(string $userId, ?string $dispositionId = null): void
        {
            WabotHook::$closed[] = [$userId, $dispositionId];
        }
        extract($vars);
        require $GLOBALS['wa_root'] . '/lib/handlers/' . $file;
    };
    ob_start();
    try {
        $run();
    } catch (Throwable $e) {
        Db::$errors[] = 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage();
    }
    $out = ob_get_clean();

    return ['out' => (string) $out, 'text' => implode("\n", array_column(WabotHook::$replies, 1)),
        'replies' => WabotHook::$replies, 'q' => Db::$queries, 'reads' => Db::$reads,
        'errors' => Db::$errors, 'calls' => Whatsapp::$calls, 'closed' => WabotHook::$closed,
        'logs' => WabotHook::$logs];
}

function wa_assert(string $label, bool $cond): void
{
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n";
}

function wa_sql(string $s): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));
}

function wa_saw(array $res, string $needle, ?int $params = null): bool
{
    foreach ($res['q'] as $r) {
        if (str_contains(wa_sql($r['sql']), wa_sql($needle))
            && ($params === null || count($r['params']) === $params)) return true;
    }
    return false;
}

function wa_saw_call(array $res, string $kind, string $needle = ''): bool
{
    foreach ($res['calls'] as $c) {
        if ($c[0] === $kind && ($needle === '' || stripos($c[1] . ' ' . $c[2], $needle) !== false)) return true;
    }
    return false;
}

function wa_reply_to(array $res, string $needle): bool
{
    foreach ($res['replies'] as $r) {
        if (str_contains($r[1], $needle)) return str_ends_with($r[0], '@s.whatsapp.net');
    }
    return false;
}

function wa_fix(string $has, array $rows): array
{
    return ['has' => wa_sql($has), 'rows' => $rows];
}

function wa_writes(array $res): int
{
    return count($res['q']);
}

function wa_users(): array
{
    return [
        ['id' => 'u-lead', 'name' => 'Budi Pimpinan', 'role' => 'PIMPINAN', 'waNumber' => '6281110000001',
            'isActive' => 1, 'supervisorId' => null],
        ['id' => 'u-admin', 'name' => 'Admin Simars', 'role' => 'ADMIN', 'waNumber' => '6281110000003',
            'isActive' => 1, 'supervisorId' => null],
        ['id' => 'u-siti', 'name' => 'Siti Staf', 'role' => 'STAFF', 'waNumber' => '6281110000002',
            'isActive' => 1, 'supervisorId' => 'u-lead'],
    ];
}

function wa_actor(string $role): array
{
    foreach (wa_users() as $u) if ($u['role'] === $role) return $u;
    return ['id' => 'u-x', 'name' => 'Entah', 'role' => $role, 'waNumber' => '628999',
        'isActive' => 1, 'supervisorId' => null];
}

function wa_letter(string $id = 'ltr-1', string $agenda = 'AGD/2026/012',
    string $subject = 'Undangan Rapat Koordinasi'): array
{
    return ['id' => $id, 'agendaNumber' => $agenda, 'subject' => $subject];
}

function wa_session(string $kind, array $ctx, bool $active = true): array
{
    return ['kind' => $kind, 'step' => $kind === 'LEADER' ? 'PICK_TARGET' : 'REPORT',
        'context' => json_encode($ctx, JSON_UNESCAPED_UNICODE),
        'expiresAt' => date('Y-m-d H:i:s', time() + ($active ? 3600 : -3600))];
}

function wa_leader_ctx(): array
{
    return ['letterId' => 'ltr-1', 'agenda' => 'AGD/2026/012', 'subject' => 'Undangan Rapat Koordinasi',
        'users' => [['id' => 'u-siti', 'name' => 'Siti Staf', 'role' => 'STAFF'],
            ['id' => 'u-admin', 'name' => 'Admin Simars', 'role' => 'ADMIN']]];
}

function wa_emp_ctx(string $dispId = 'dsp-1'): array
{
    return ['dispositionId' => $dispId, 'subject' => 'Undangan Rapat Koordinasi',
        'instruction' => 'Hadir dan bawa dokumen', 'deadline' => '2026-09-20 00:00:00', 'withMenu' => 1];
}

/** Variabel yang biasa disediakan dispatcher untuk cabang. */
function wa_vars(string $role, string $message, ?array $cmd = null, ?array $start = null,
    bool $isGroup = false): array
{
    $actor = wa_actor($role);
    $wa = $actor['waNumber'];
    return [
        'settings' => ['isEnabled' => 1, 'fonnteToken' => 'tok', 'groupTarget' => '120363@g.us',
            'waGroupMarker' => ''],
        'ident' => ['isGroup' => $isGroup, 'actor' => $wa . '@s.whatsapp.net',
            'chatTarget' => $isGroup ? '120363@g.us' : $wa . '@s.whatsapp.net',
            'label' => $isGroup ? 'grup ini' : 'nomor ini'],
        'sender' => $isGroup ? '120363@g.us' : $wa . '@s.whatsapp.net',
        'member' => $wa . '@s.whatsapp.net',
        'message' => $message, 'inboxId' => 'inbox-1',
        'actor' => $actor, 'actorUserId' => $actor['id'],
        'replyTarget' => $wa . '@s.whatsapp.net', 'cmd' => $cmd, 'start' => $start,
    ];
}

/** Fixture cadangan: semua SELECT dikenali & kosong; $extra dicocokkan lebih dulu. */
function wa_base_fix(array $extra = []): array
{
    return array_merge($extra, [
        wa_fix('from users where wa_number is not null', wa_users()),
        wa_fix("from activity_logs where action = 'wabot_inbox'", []),
        wa_fix('select context from wa_sessions', []),
        wa_fix("from wa_sessions where user_id = ? and kind = 'leader' and expires_at > now()", []),
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at > now()", []),
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at <= now()", []),
        wa_fix('from users where id = ?', []),
        wa_fix('from users where is_active = 1 and id <> ?', []),
        wa_fix('from incoming_letters where id = ?', []),
        wa_fix('where subject like ? or agenda_number like ?', []),
        wa_fix('from incoming_letters', []),
        wa_fix("where d.to_user_id = ? and d.status in ('pending', 'proses')", []),
        wa_fix("where d.id = ? and d.to_user_id = ? and d.status in", []),
        wa_fix('where d.id = ? and d.to_user_id = ?', []),
    ]);
}

function wa_disp(string $status = 'PENDING', ?string $notes = null, string $id = 'dsp-1',
    string $toUserId = 'u-lead'): array
{
    return ['id' => $id, 'toUserId' => $toUserId, 'fromUserId' => 'u-lead', 'fromName' => 'Budi Pimpinan',
        'letterSubject' => 'Undangan Rapat Koordinasi', 'deadline' => '2026-09-20 00:00:00',
        'instruction' => 'Hadir dan bawa dokumen', 'notes' => $notes, 'status' => $status,
        'createdAt' => '2026-09-08 10:00:00', 'incomingLetterId' => 'ltr-1'];
}

function wa_common(array $res): void
{
    wa_assert('tanpa kesalahan', $res['errors'] === []);
    foreach ($res['errors'] as $e) echo '  -> ' . $e . "\n";
    wa_assert('tidak fatal', !str_contains($res['out'], 'Fatal error'));
}


// ---------------------------------------------------------------- kasus ----

function wa_cases_v1(): array
{
    $listFix = function (array $rows) {
        return wa_base_fix([wa_fix("where d.to_user_id = ? and d.status in", $rows)]);
    };
    $updCmd = fn($n, $status, $notes) =>
        ['action' => 'UPDATE', 'status' => $status, 'ordinal' => $n, 'notes' => $notes];

    return [
        'v1-list' => function () use ($listFix) {
            $r = wa_run_branch('wabot_v1.php', wa_vars('PIMPINAN', 'DISPOSISI', ['action' => 'LIST']),
                $listFix([wa_disp(), wa_disp('PROSES', null, 'dsp-2')]));
            wa_common($r);
            wa_assert('daftar terformat', str_contains($r['text'], 'DAFTAR DISPOSISI'));
            wa_assert('ada nomor', str_contains($r['text'], '1. Undangan Rapat Koordinasi'));
            wa_assert('balasan ke chat pribadi',
                wa_reply_to($r, 'DAFTAR DISPOSISI'));
            wa_assert('tidak ada penulisan DB', wa_writes($r) === 0);
            return $r;
        },
        'v1-list-staff-denied' => function () {
            $r = wa_run_branch('wabot_v1.php', wa_vars('STAFF', 'DISPOSISI', ['action' => 'LIST']),
                wa_base_fix());
            wa_common($r);
            wa_assert('role non-v1 ditolak', str_contains($r['text'], 'tidak berwenang'));
            wa_assert('arahkan ke menu 1/2', str_contains($r['text'], '2 [catatan]'));
            wa_assert('tidak ada penulisan DB', wa_writes($r) === 0);
            return $r;
        },
        'v1-help' => function () {
            $r = wa_run_branch('wabot_v1.php', wa_vars('ADMIN', 'DISPOSISI BANTUAN', ['action' => 'HELP']),
                wa_base_fix());
            wa_common($r);
            wa_assert('bantuan terkirim', str_contains($r['text'], 'BOT DISPOSISI SIMARS'));
            return $r;
        },
        'v1-invalid' => function () {
            $r = wa_run_branch('wabot_v1.php', wa_vars('ADMIN', 'DISPOSISI 2', ['action' => 'INVALID']),
                wa_base_fix());
            wa_common($r);
            wa_assert('minta lengkapi', str_contains($r['text'], 'Perintah belum lengkap'));
            return $r;
        },
        'v1-update-selesai' => function () use ($listFix, $updCmd) {
            $r = wa_run_branch('wabot_v1.php',
                wa_vars('PIMPINAN', 'DISPOSISI 1 SELESAI sudah diarsipkan', $updCmd(1, 'SELESAI', 'sudah diarsipkan')),
                $listFix([wa_disp('PENDING', 'catatan awal')]));
            wa_common($r);
            wa_assert('status ditulis', wa_saw($r, 'update dispositions set status = ?', 3));
            $upd = [];
            foreach ($r['q'] as $w) if (str_contains(wa_sql($w['sql']), 'update dispositions')) $upd = $w['params'];
            wa_assert('catatan di-append', str_contains((string) $upd[1], 'catatan awal')
                && str_contains((string) $upd[1], 'sudah diarsipkan'));
            wa_assert('notifikasi in-app', wa_saw($r, 'insert into notifications'));
            wa_assert('grup dikabari', wa_saw_call($r, 'status', 'Undangan'));
            wa_assert('sesi pegawai ditutup', $r['closed'] === [['u-lead', 'dsp-1']]);
            wa_assert('konfirmasi personal', str_contains($r['text'], 'STATUS DISPOSISI DIPERBARUI'));
            return $r;
        },
        'v1-update-proses' => function () use ($listFix, $updCmd) {
            $r = wa_run_branch('wabot_v1.php',
                wa_vars('PIMPINAN', 'DISPOSISI 1 PROSES', $updCmd(1, 'PROSES', null)),
                $listFix([wa_disp()]));
            wa_common($r);
            wa_assert('status PROSES', wa_saw($r, 'update dispositions set status = ?', 3));
            wa_assert('sesi TIDAK ditutup', !wa_saw($r, "update wa_sessions set kind = 'closed'"));
            wa_assert('grup dikabari', wa_saw_call($r, 'status'));
            return $r;
        },
        'v1-update-notfound' => function () use ($listFix, $updCmd) {
            $r = wa_run_branch('wabot_v1.php',
                wa_vars('PIMPINAN', 'DISPOSISI 5 SELESAI', $updCmd(5, 'SELESAI', null)), $listFix([wa_disp()]));
            wa_common($r);
            wa_assert('nomor tak ada', str_contains($r['text'], 'Disposisi nomor 5 tidak ada'));
            wa_assert('tidak ada penulisan DB', wa_writes($r) === 0);
            return $r;
        },
        'v1-update-empty-list' => function () use ($listFix, $updCmd) {
            $r = wa_run_branch('wabot_v1.php',
                wa_vars('PIMPINAN', 'DISPOSISI 1 SELESAI', $updCmd(1, 'SELESAI', null)), $listFix([]));
            wa_common($r);
            wa_assert('dijelaskan kosong', str_contains($r['text'], 'Tidak ada disposisi aktif'));
            wa_assert('tidak ada penulisan DB', wa_writes($r) === 0);
            return $r;
        },
        'v1-update-already' => function () use ($listFix, $updCmd) {
            $r = wa_run_branch('wabot_v1.php',
                wa_vars('PIMPINAN', 'DISPOSISI 1 SELESAI', $updCmd(1, 'SELESAI', null)),
                $listFix([wa_disp('SELESAI')]));
            wa_common($r);
            wa_assert('sudah berstatus', str_contains($r['text'], 'sudah *SELESAI*'));
            wa_assert('tidak ada penulisan DB', wa_writes($r) === 0);
            return $r;
        },
    ];
}


/** Fixture: sesi pegawai + disposisinya (aktif atau sudah kedaluwarsa). */
function wa_emp_fix(array $ctx, ?array $disp, bool $active = true, array $extra = []): array
{
    return wa_base_fix(array_merge($extra, [
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at > now()",
            $active ? [wa_session('EMPLOYEE', $ctx)] : []),
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at <= now()",
            $active ? [] : [wa_session('EMPLOYEE', $ctx, false)]),
        wa_fix('where d.id = ? and d.to_user_id = ?', $disp === null ? [] : [$disp]),
        wa_fix("where d.to_user_id = ? and d.status in ('pending', 'proses')", $disp === null ? [] : [$disp]),
    ]));
}

// Tanpa return type: 'mixed' baru ada di PHP 8, sedangkan skrip ini harus tetap
// bisa dijalankan di hosting PHP 7.4.
function wa_param(array $res, string $needle, int $i)
{
    foreach ($res['q'] as $w) {
        if (str_contains(wa_sql($w['sql']), wa_sql($needle))) return $w['params'][$i] ?? null;
    }
    return null;
}

function wa_session_insert(array $res): ?array
{
    foreach ($res['q'] as $w) if (str_contains(wa_sql($w['sql']), 'insert into wa_sessions')) return $w['params'];
    return null;
}

/** Tabel pemeriksaan: label => [operator, arg1, arg2, arg3]. */
function wa_eval(array $res, array $checks): void
{
    foreach ($res['errors'] as $e) wa_assert('SQL: ' . $e, false);
    wa_assert('tidak fatal', !str_contains($res['out'], 'Fatal error'));
    foreach ($checks as $label => $c) {
        [$op, $a, $b, $d] = [$c[0], $c[1] ?? null, $c[2] ?? null, $c[3] ?? null];
        // if-chain, bukan match(): match() = sintaks PHP 8, dan skrip ini harus
        // tetap bisa dijalankan di hosting PHP 7.4. Perbandingan tetap '==='
        // supaya semantiknya sama persis dengan match().
        if ($op === 'saw') {
            $ok = wa_saw($res, (string) $a, $b);
        } elseif ($op === 'notsaw') {
            $ok = !wa_saw($res, (string) $a, $b);
        } elseif ($op === 'has') {
            $ok = str_contains($res['text'], (string) $a);
        } elseif ($op === 'nothas') {
            $ok = !str_contains($res['text'], (string) $a);
        } elseif ($op === 'call') {
            $ok = wa_saw_call($res, (string) $a, (string) ($b ?? ''));
        } elseif ($op === 'writes') {
            $ok = wa_writes($res) === (int) $a;
        } elseif ($op === 'sessins') {
            $ok = wa_session_insert($res) !== null;
        } elseif ($op === 'nosessins') {
            $ok = wa_session_insert($res) === null;
        } elseif ($op === 'param') {
            $ok = str_contains((string) wa_param($res, (string) $a, (int) $b), (string) $d);
        } elseif ($op === 'out') {
            $ok = str_contains($res['out'], (string) $a);
        } elseif ($op === 'personal') {
            $ok = wa_reply_to($res, (string) $a);
        } elseif ($op === 'sends') {
            $ok = count($res['replies']) === (int) $a;
        } elseif ($op === 'notext') {
            $ok = trim($res['text']) === '';
        } elseif ($op === 'log') {
            $ok = (bool) array_filter($res['logs'], fn($l) => $l[1] === $a);
        } elseif ($op === 'sessexp') {
            $ok = (bool) array_filter($res['q'], fn($w) => str_contains(wa_sql($w['sql']), 'insert into wa_sessions')
                && abs(strtotime((string) $w['params'][2]) - time() - (int) $a) < 10);
        } elseif ($op === 'ttl') {
            $ok = (bool) array_filter($res['q'], fn($w) => str_contains(wa_sql($w['sql']), wa_sql((string) $a))
                && isset($w['params'][$d ?? 0])
                && abs(strtotime((string) $w['params'][$d ?? 0]) - time() - (int) $b) < 10);
        } else {
            $ok = false;
        }
        if (!$ok && in_array($op, ['out', 'has', 'nothas'], true)) {
            // Bantu penelusuran: tampilkan teks aktual, bukan cuma PASS/FAIL.
            echo '  -> aktual: ' . var_export($op === 'out' ? $res['out'] : $res['text'], true) . "\n";
        }
        wa_assert((string) $label, $ok);
    }
}

function wa_case(string $file, array $vars, array $fix, array $checks): array
{
    $r = wa_run_branch($file, $vars, $fix);
    wa_eval($r, $checks);
    return $r;
}


function wa_cases_employee(): array
{
    $v = fn(string $msg) => wa_vars('STAFF', $msg);
    $pend = wa_emp_fix(wa_emp_ctx(), wa_disp('PENDING', null, 'dsp-1', 'u-siti'));
    $done = wa_emp_fix(wa_emp_ctx(), wa_disp('SELESAI', null, 'dsp-1', 'u-siti'));
    $stale = wa_emp_fix(wa_emp_ctx(), wa_disp('PENDING', null, 'dsp-1', 'u-siti'), false,
        [wa_fix('select context from wa_sessions', [['context' => json_encode(wa_emp_ctx())]])]);

    return [
        'v2-employee-proses' => fn() => wa_case('wabot_v2.php', $v('1'), $pend, [
            'status PROSES ditulis' => ['saw', 'update dispositions set status = ?', 3],
            'nilai status PROSES' => ['param', 'update dispositions', 0, 'PROSES'],
            'sesi diperpanjang' => ['saw', 'update wa_sessions set expires_at = ?', 2],
            'perpanjangan 7 hari' => ['ttl', 'update wa_sessions set expires_at = ?', 7 * 86400],
            'balasan PROSES' => ['has', 'STATUS: PROSES'],
            'pemberi tugas dikabari' => ['call', 'status'],
            'sesi tidak ditutup' => ['notsaw', "update wa_sessions set kind = 'closed'"],
        ]),
        'v2-employee-selesai' => fn() => wa_case('wabot_v2.php', $v('2 sudah diarsipkan'),
            wa_emp_fix(wa_emp_ctx(), wa_disp('PENDING', 'awal', 'dsp-1', 'u-siti')), [
                'status SELESAI ditulis' => ['param', 'update dispositions', 0, 'SELESAI'],
                'catatan baru ter-append' => ['param', 'update dispositions', 1, 'diarsipkan'],
                'catatan lama dipertahankan' => ['param', 'update dispositions', 1, 'awal'],
                'sesi ditutup permanen' => ['saw', "update wa_sessions set kind = 'closed'", 1],
                'balasan SELESAI' => ['has', 'STATUS: SELESAI'],
            ]),
        'v2-employee-cancel' => fn() => wa_case('wabot_v2.php', $v('BATAL'), $pend, [
            'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
            'balasan dibatalkan' => ['has', 'dibatalkan'],
            'status tidak berubah' => ['notsaw', 'update dispositions'],
        ]),
        'v2-employee-remenu' => fn() => wa_case('wabot_v2.php', $v('MENU'), $pend, [
            'menu diulang' => ['has', 'DISPOSISI BARU UNTUK ANDA'],
            'menyebut 2 [catatan]' => ['has', '2 [catatan]'],
            'tanpa perubahan status' => ['notsaw', 'update dispositions'],
        ]),
        'v2-employee-unknown' => fn() => wa_case('wabot_v2.php', $v('halo apa kabar'), $pend, [
            'minta MENU/BATAL' => ['has', 'Balasan tidak dikenali'],
            'sesi diperpanjang' => ['saw', 'update wa_sessions set expires_at = ?', 2],
            'tanpa perubahan status' => ['notsaw', 'update dispositions'],
        ]),
        'v2-employee-luar-rentang' => fn() => wa_case('wabot_v2.php', $v('7'), $pend, [
            'petunjuk 1-2' => ['has', 'pilih nomor 1-2'],
            'tanpa perubahan status' => ['notsaw', 'update dispositions'],
        ]),
        'v2-employee-tugas-sudah-beres' => fn() => wa_case('wabot_v2.php', $v('1'), $done, [
            'dijelaskan selesai' => ['has', 'sudah *SELESAI*'],
            'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
            'tanpa update ulang' => ['notsaw', 'update dispositions'],
        ]),
        'v2-employee-tugas-hilang' => fn() => wa_case('wabot_v2.php', $v('1'), wa_emp_fix(wa_emp_ctx(), null), [
            'diarahkan cek daftar' => ['has', 'Tidak ada disposisi aktif'],
            'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
        ]),
        'v2-employee-kedaluwarsa-dipulihkan' => fn() => wa_case('wabot_v2.php', $v('1'), $stale, [
            'sesi dibuat ulang' => ['sessins'],
            'balasan langsung diproses' => ['saw', 'update dispositions set status = ?', 3],
            'balasan PROSES' => ['has', 'STATUS: PROSES'],
        ]),
        'v2-employee-kedaluwarsa-tanpa-tugas' => fn() => wa_case('wabot_v2.php', $v('1'),
            wa_emp_fix(wa_emp_ctx(), null, false,
                [wa_fix('select context from wa_sessions', [['context' => json_encode(wa_emp_ctx())]])]), [
                'dijelaskan tidak ada tugas' => ['has', 'Tidak ada disposisi aktif'],
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
                'sesi tidak dibuat ulang' => ['nosessins'],
            ]),
        'v2-tanpa-sesi-diabaikan' => fn() => wa_case('wabot_v2.php', $v('halo'), wa_base_fix(), [
            'status ignored' => ['out', 'ignored'],
            'tidak ada balasan' => ['writes', 0],
        ]),
    ];
}


function wa_cases_start(): array
{
    $fix = fn(array $extra) => wa_base_fix($extra);
    // Surat AGD/2026/012 + dua pegawai bernomor WA (urut nama: Admin, Siti).
    $letter = [wa_fix('from incoming_letters', [wa_letter()])];
    $staff = [wa_fix('from users where is_active = 1 and id <> ?', [
        ['id' => 'u-admin', 'name' => 'Admin Simars', 'role' => 'ADMIN'],
        ['id' => 'u-siti', 'name' => 'Siti Staf', 'role' => 'STAFF'],
    ])];
    $ready = fn() => $fix(array_merge($letter, $staff));

    // Fase 2: tugas sesi aksi tahap (menu bernomor untuk Kasubag/Sekretaris/
    // Panitera). $butuhRute=true -> pilihan masuk DIDISPOSISIKAN yang wajib
    // menyertakan rute keputusan (KEBIJAKAN/LANGSUNG).
    $stageTask = function (bool $butuhRute = false): array {
        return ['letterId' => 'ltr-1', 'agenda' => 'AGD/2026/012',
            'subject' => 'Undangan Rapat Koordinasi', 'stage' => 'DITERUSKAN_KE_KASUBAG',
            'sensitive' => false, 'addedAt' => '2026-09-24 08:00:00',
            'options' => [['n' => 1, 'toStage' => 'DITERUSKAN_KE_PELAKSANA',
                'label' => 'Teruskan ke pelaksana', 'requiresRoute' => $butuhRute]]];
    };
    $stageSes = fn(array $queue) => $fix([
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at > now()",
            [wa_session('VERIFIKASI', ['queue' => $queue])]),
    ]);

    return [
        'v2-mulai-agenda' => fn() => wa_case('wabot_v2.php',
            wa_vars('PIMPINAN', 'DISPOSISI 12', null, ['action' => 'START', 'agenda' => 12], true), $ready(), [
                'sesi pimpinan dibuat' => ['saw', "insert into wa_sessions", 3],
                'masa berlaku 60 menit' => ['sessexp', 3600],
                'snapshot surat tersimpan' => ['param', 'insert into wa_sessions', 1, 'ltr-1'],
                'menu terkirim' => ['has', 'BUAT DISPOSISI'],
                'menu menyebut 60 menit' => ['has', '60 menit'],
                'menu bernomor' => ['has', '1. Admin Simars'],
                'balasan ke chat pribadi' => ['personal', 'BUAT DISPOSISI'],
                'tidak dibuat di grup' => ['notsaw', 'insert into dispositions'],
                'dicatat di activity log' => ['log', 'WABOT_SESSION_START'],
            ]),
        'v2-mulai-agenda-tak-diketahui' => fn() => wa_case('wabot_v2.php',
            wa_vars('PIMPINAN', 'DISPOSISI 999', null, ['action' => 'START', 'agenda' => 999]),
            $fix([wa_fix('from incoming_letters', [])]), [
                'dijelaskan tidak ditemukan' => ['has', 'tidak ditemukan'],
                'sesi tidak dibuat' => ['nosessins'],
            ]),
        'v2-mulai-kata-kunci-tunggal' => fn() => wa_case('wabot_v2.php',
            wa_vars('ADMIN', 'DISPOSISI undangan rapat', null,
                ['action' => 'KEYWORD', 'keyword' => 'undangan rapat']),
            $fix(array_merge([wa_fix('where subject like ? or agenda_number like ?', [wa_letter()])], $staff)), [
                'sesi dibuat' => ['sessins'],
                'menu terkirim' => ['has', 'BUAT DISPOSISI'],
            ]),
        'v2-mulai-kata-kunci-ambigu' => fn() => wa_case('wabot_v2.php',
            wa_vars('ADMIN', 'DISPOSISI undangan', null, ['action' => 'KEYWORD', 'keyword' => 'undangan']),
            $fix([wa_fix('where subject like ? or agenda_number like ?',
                [wa_letter(), wa_letter('ltr-2', 'AGD/2026/013', 'Undangan Rapat Dinas')])]), [
                'diminta nomor agenda' => ['has', 'Ada *2* surat'],
                'sesi tidak dibuat' => ['nosessins'],
            ]),
        'v2-mulai-tanpa-pegawai' => fn() => wa_case('wabot_v2.php',
            wa_vars('PIMPINAN', 'DISPOSISI 12', null, ['action' => 'START', 'agenda' => 12]),
            $fix($letter), [
                'dijelaskan tidak ada target' => ['has', 'Tidak ada pegawai aktif'],
                'sesi tidak dibuat' => ['nosessins'],
            ]),
        'v2-mulai-format-salah' => fn() => wa_case('wabot_v2.php',
            wa_vars('PIMPINAN', 'DISPOSISI 1234', null, ['action' => 'INVALID_START']), $ready(), [
                'minta format benar' => ['has', 'Format belum tepat'],
                'sesi tidak dibuat' => ['nosessins'],
            ]),
        'v2-mulai-role-pegawai-ditolak' => fn() => wa_case('wabot_v2.php',
            wa_vars('STAFF', 'DISPOSISI 12', null, ['action' => 'START', 'agenda' => 12]), $ready(), [
                'diabaikan senyap' => ['out', 'ignored'],
                'sesi tidak dibuat' => ['nosessins'],
            ]),

        // --- Fase 2: sesi AKSI TAHAP (Kasubag/Sekretaris/Panitera) -------------
        // P1 (revisi kedua): balasan ANGKA sesi aksi tahap DINONAKTIFKAN secara
        // default (angka hanya mengenai tugas terdepan antrean, tidak terikat
        // agenda). Kontrak lama "Pilihan *9* tidak ada" diganti balasan
        // penjelasan + arahan ke kata kunci + nomor agenda.
        'v2-stage-pilihan-salah' => fn() => wa_case('wabot_v2.php',
            wa_vars('KEPALA_SUB_UMUM', '9'), $stageSes([$stageTask()]), [
                'balasan angka dinonaktifkan (P1)' => ['has', 'dinonaktifkan'],
                'diarahkan ke kata kunci + agenda' => ['has', 'kata kunci'],
                'antrean tidak diubah' => ['nosessins'],
            ]),
        'v2-stage-menu-ulang' => fn() => wa_case('wabot_v2.php',
            wa_vars('KEPALA_SUB_UMUM', 'MENU'), $stageSes([$stageTask()]), [
                'menu dikirim ulang' => ['has', 'TINDAK LANJUT SURAT'],
                'menu tanpa instruksi balas-angka (P1)' => ['nothas', 'balas nomornya'],
                'agenda surat disebut utk perintah kata kunci' => ['has', 'AGD/'],
            ]),
        'v2-stage-keputusan-wajib-rute' => fn() => wa_case('wabot_v2.php',
            wa_vars('SEKRETARIS', '1'), $stageSes([$stageTask(true)]), [
                'balasan angka dinonaktifkan (P1)' => ['has', 'dinonaktifkan'],
                'BANTUAN disebut' => ['has', 'BANTUAN'],
            ]),
        'v2-stage-batal-tutup-sesi' => fn() => wa_case('wabot_v2.php',
            wa_vars('KEPALA_SUB_UMUM', 'BATAL'), $stageSes([$stageTask()]), [
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 2],
                'dijelaskan tugas tetap menunggu' => ['has', 'tetap menunggu'],
            ]),
        'v2-stage-antrean-kosong' => fn() => wa_case('wabot_v2.php',
            wa_vars('KEPALA_SUB_UMUM', '1'), $stageSes([]), [
                'dijelaskan tidak ada tugas' => ['has', 'Tidak ada tugas surat'],
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 2],
            ]),
        'v2-stage-role-lain-diabaikan' => fn() => wa_case('wabot_v2.php',
            wa_vars('STAFF', '1'), $stageSes([$stageTask()]), [
                'role pegawai tidak memakai menu tahap' => ['out', 'ignored'],
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 2],
            ]),
    ];
}


/** Fixture sesi LEADER aktif + surat + pegawai tujuan (Siti di nomor 1). */
function wa_leader_fix(array $extra = []): array
{
    return wa_base_fix(array_merge($extra, [
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at > now()",
            [wa_session('LEADER', wa_leader_ctx())]),
        wa_fix('from incoming_letters where id = ?', [wa_letter()]),
        wa_fix('from users where id = ?', [['id' => 'u-siti', 'name' => 'Siti Staf', 'role' => 'STAFF',
            'waNumber' => '6281110000002', 'isActive' => 1, 'supervisorId' => 'u-lead']]),
    ]));
}

function wa_leader_stale_fix(array $extra = []): array
{
    return wa_base_fix(array_merge($extra, [
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at > now()", []),
        wa_fix("from wa_sessions where user_id = ? and kind <> 'closed' and expires_at <= now()",
            [wa_session('LEADER', wa_leader_ctx(), false)]),
    ]));
}

function wa_cases_leader(): array
{
    $p = fn(string $msg, bool $grup = false) => wa_vars('PIMPINAN', $msg, null, null, $grup);

    return [
        'v2-pemilihan-pegawai' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('1 Mohon hadir rapat'), wa_leader_fix(), [
                'disposisi dibuat' => ['saw', 'insert into dispositions', 5],
                'penerima benar' => ['param', 'insert into dispositions', 3, 'u-siti'],
                'instruksi terkirim' => ['param', 'insert into dispositions', 4, 'Mohon hadir'],
                'notifikasi in-app' => ['saw', 'insert into notifications'],
                'grup diumumkan' => ['call', 'group', 'DIDISPOSISIKAN'],
                'dm menu ke pegawai' => ['call', 'dm', '6281110000002'],
                'sesi pimpinan ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
                'konfirmasi ke pimpinan' => ['has', 'DISPOSISI DIBUAT'],
                'balasan ke chat pribadi' => ['personal', 'DISPOSISI DIBUAT'],
                'dicatat di activity log' => ['log', 'WABOT_DISPOSITION_CREATE'],
            ]);
        },
        'v2-pemilihan-tanpa-instruksi' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('1'), wa_leader_fix(), [
                'instruksi baku dipakai' => ['param', 'insert into dispositions', 4, 'Segera ditindaklanjuti'],
            ]);
        },
        'v2-pemilihan-di-luar-rentang' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('9'), wa_leader_fix(), [
                'petunjuk nomor' => ['has', 'pilih nomor 1-2'],
                'tidak ada disposisi' => ['notsaw', 'insert into dispositions'],
                'sesi masih terbuka' => ['notsaw', "update wa_sessions set kind = 'closed'"],
            ]);
        },
        'v2-pemilihan-target-hilang' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('1'), wa_leader_fix([wa_fix('from users where id = ?', [])]), [
                'dijelaskan tidak tersedia' => ['has', 'tidak lagi tersedia'],
                'tidak ada disposisi' => ['notsaw', 'insert into dispositions'],
            ]);
        },


        'v2-pemilihan-surat-hilang' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('1'),
                wa_leader_fix([wa_fix('from incoming_letters where id = ?', [])]), [
                    'dijelaskan surat hilang' => ['has', 'tidak ditemukan'],
                    'tidak ada disposisi' => ['notsaw', 'insert into dispositions'],
                    'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
                ]);
        },
        'v2-remenu-pimpinan' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('MENU'), wa_leader_fix(), [
                'menu diulang dari snapshot' => ['has', 'Pilih pegawai tujuan'],
                'penomoran sama' => ['has', '1. Siti Staf'],
                'tidak ada penulisan' => ['writes', 0],
            ]);
        },
        'v2-batal-pimpinan-di-grup' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('BATAL', true), wa_leader_fix(), [
                'balasan batal versi grup' => ['has', 'Sesi WhatsApp dibatalkan'],
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
            ]);
        },
        'v2-sesi-pimpinan-role-pegawai' => function () {
            return wa_case('wabot_v2.php', wa_vars('STAFF', '1'), wa_leader_fix(), [
                'sesi ditutup tanpa balasan' => ['saw', "update wa_sessions set kind = 'closed'", 1],
                'diabaikan' => ['out', 'ignored'],
                'tidak ada disposisi' => ['notsaw', 'insert into dispositions'],
            ]);
        },
        'v2-pimpinan-kedaluwarsa' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('2'), wa_leader_stale_fix(), [
                'diberi tahu sesi berakhir' => ['has', 'telah berakhir'],
                'sesi ditutup' => ['saw', "update wa_sessions set kind = 'closed'", 1],
            ]);
        },
        'v2-pimpinan-kedaluwarsa-pulihkan-pegawai' => function () use ($p) {
            return wa_case('wabot_v2.php', $p('2'), wa_leader_stale_fix([
                wa_fix("where d.to_user_id = ? and d.status in ('pending', 'proses')",
                    [wa_disp('PENDING', null, 'dsp-9', 'u-lead')]),
            ]), [
                'diberi tahu sesi berakhir' => ['has', 'telah berakhir'],
                'sesi pegawai dipulihkan' => ['saw', 'insert into wa_sessions', 3],
            ]);
        },
    ];
}


// ------------------------------------------------------------------ main ----

function wa_all_cases(): array
{
    return array_merge(wa_cases_v1(), wa_cases_employee(), wa_cases_start(), wa_cases_leader(),
        wa_cases_dispatcher());
}

$ALL = wa_all_cases();
if (!isset($ALL[$case])) {
    echo 'FAIL kasus tidak dikenal: ' . $case . "\n";
    exit(1);
}
$ALL[$case]();
echo "PASS kasus tuntas\n";


/** Skenario payload Fonnte (webhook) utk menguji dispatcher asli. */
function wa_hook(string $role, string $message, bool $grup = false, string $inbox = 'inbox-9'): array
{
    $wa = wa_actor($role)['waNumber'];
    return [
        'sender' => $grup ? '120363@g.us' : $wa . '@s.whatsapp.net',
        'member' => $wa . '@s.whatsapp.net',
        'message' => $message,
        'inboxid' => $inbox,
    ];
}

function wa_dcase(array $payload, array $fix, array $checks, ?array $settings = null): array
{
    $r = wa_run_dispatcher($payload, $fix, $settings);
    wa_eval($r, $checks);
    return $r;
}

function wa_cases_dispatcher(): array
{
    $listFix = fn() => wa_base_fix([wa_fix("where d.to_user_id = ? and d.status in", [wa_disp()])]);
    $startFix = fn() => wa_base_fix([
        wa_fix('from incoming_letters', [wa_letter()]),
        wa_fix('from users where is_active = 1 and id <> ?', [
            ['id' => 'u-siti', 'name' => 'Siti Staf', 'role' => 'STAFF'],
        ]),
    ]);

    return [
        'dispatch-daftar-pribadi' => fn() => wa_dcase(wa_hook('PIMPINAN', 'DISPOSISI'), $listFix(), [
            'mempunya satu balasan' => ['sends', 1],
            'daftar disposisi' => ['has', 'DAFTAR DISPOSISI'],
            'ke chat pribadi' => ['personal', 'DAFTAR DISPOSISI'],
            'pesan masuk dicatat' => ['log', 'WABOT_INBOX'],
        ]),
        'dispatch-grup-asing-diomit' => fn() => wa_dcase(
            ['sender' => '999@g.us', 'member' => '6281110000001@s.whatsapp.net',
                'message' => 'DISPOSISI', 'inboxid' => 'x'], wa_base_fix(), [
            'diabaikan' => ['out', 'ignored'],
            'tanpa balasan' => ['sends', 0],
            'tanpa penulisan' => ['writes', 0],
        ]),
        'dispatch-nomor-tak-terdaftar' => fn() => wa_dcase(
            ['sender' => '628999000111@s.whatsapp.net', 'member' => '', 'message' => 'DISPOSISI',
                'inboxid' => ''], wa_base_fix(), [
            'diabaikan' => ['out', 'ignored'],
            'tanpa balasan' => ['sends', 0],
        ]),
        'dispatch-inbox-duplikat' => fn() => wa_dcase(wa_hook('PIMPINAN', 'DISPOSISI'),
            wa_base_fix([wa_fix("from activity_logs where action = 'wabot_inbox'", [['id' => 'ada']])]), [
            'status duplicate' => ['out', 'duplicate'],
            'tanpa balasan' => ['sends', 0],
            'tanpa penulisan' => ['writes', 0],
        ]),
        'dispatch-kata-kunci-grup-kurang' => fn() => wa_dcase(wa_hook('PIMPINAN', 'DISPOSISI', true),
            $listFix(), [
            'diabaikan' => ['out', 'ignored'],
            'tanpa balasan' => ['sends', 0],
        ], ['isEnabled' => 1, 'fonnteToken' => 'tok', 'groupTarget' => '120363@g.us',
            'waGroupMarker' => 'SIPENCAT']),
        'dispatch-mulai-sesi-dari-grup' => fn() => wa_dcase(wa_hook('PIMPINAN', 'DISPOSISI 12', true),
            $startFix(), [
            'sesi pimpinan dibuat' => ['saw', 'insert into wa_sessions', 3],
            'menu ke chat pribadi' => ['personal', 'BUAT DISPOSISI'],
            'bukan balasan v1' => ['nothas', 'Perintah belum lengkap'],
        ]),
        'dispatch-pegawai-membalas-sesi' => fn() => wa_dcase(wa_hook('STAFF', '1'),
            wa_emp_fix(wa_emp_ctx(), wa_disp('PENDING', null, 'dsp-1', 'u-siti')), [
            'status ditulis walau bukan admin' => ['saw', 'update dispositions set status = ?', 3],
            'balasan ke pegawai' => ['personal', 'STATUS: PROSES'],
        ]),
        'dispatch-pegawai-mulai-sesi-ditolak' => fn() => wa_dcase(wa_hook('STAFF', 'DISPOSISI 12', true),
            $startFix(), [
            'v1 menolak role non-pimpinan' => ['has', 'tidak berwenang'],
            'arahkan ke menu pribadi' => ['has', '2 [catatan]'],
            'sesi tidak dibuat' => ['nosessins'],
        ]),
    ];
}
