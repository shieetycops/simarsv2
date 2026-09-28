<?php
// Uji patch revisi kedua alur disposisi SOP/AS/04 (P1-P8) — T7/T9/T12/T14/T16
// versi IN-PROCESS: handler kata kunci WhatsApp ASLI (wabot_keyword.php)
// dijalankan dengan stub pengirim Fonnte (Whatsapp::$sender) terhadap basis
// data uji TERPISAH. TIDAK ADA WhatsApp nyata yang dikirim.
//
//   php api-php/tests/run_sop_patch_check.php     (DB uji default: simars_v2_test2)
//   $env:SIMARS_PATCH_TEST_DB='nama_lain'; php api-php/tests/run_sop_patch_check.php
//
// Skrip ini MEMBUAT ULANG basis data ujinya (DROP + schema.sql + migrasi) —
// jangan diarahkan ke basis data produksi.

$config = require __DIR__ . '/../config.php';
$testDb = getenv('SIMARS_PATCH_TEST_DB') ?: 'simars_v2_test2';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS: $name\n"; }
    else { $fail++; echo "FAIL: $name" . ($extra !== '' ? " -> $extra" : '') . "\n"; }
}
function checkSame(string $name, $expect, $actual): void
{
    check($name, $expect === $actual, 'diharapkan ' . var_export($expect, true) . ', dapat ' . var_export($actual, true));
}

// ---------- 1) Siapkan basis data uji (pola run_fase1_check) ----------
try {
    $root = new PDO("mysql:host={$config['db_host']};charset=utf8mb4", $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$testDb`");
    $root->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    echo 'SKIP: MySQL lokal tidak dapat disiapkan: ' . $e->getMessage() . "\n";
    exit(0);
}
$pdo = new PDO("mysql:host={$config['db_host']};dbname=$testDb;charset=utf8mb4", $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);

function runSqlFile(PDO $pdo, string $path): int
{
    $sql = '';
    foreach (file($path) as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--')) continue;
        $sql .= $line;
    }
    $skipped = 0;
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $sth = $pdo->query($stmt);
            if ($sth !== false) $sth->closeCursor();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $already = str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate column name')
                || str_contains($msg, "Can't DROP");
            if (!$already) {
                throw new RuntimeException(basename($path) . ': ' . $msg . ' | ' . substr($stmt, 0, 120));
            }
            $skipped++;
        }
    }
    return $skipped;
}

require_once __DIR__ . '/../lib/compat.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/V2Workflow.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Wabot.php';
require_once __DIR__ . '/../lib/Whatsapp.php';
require_once __DIR__ . '/../lib/LetterTransition.php';
require_once __DIR__ . '/../lib/WaStageNotifier.php';

Db::$pdo = $pdo;

runSqlFile($pdo, __DIR__ . '/../schema.sql');
$migrations = glob(__DIR__ . '/../migrations/*.sql');
sort($migrations);
usort($migrations, fn($a, $b) =>
    [str_contains(basename($a), '_seed_'), basename($a)] <=> [str_contains(basename($b), '_seed_'), basename($b)]);
foreach ($migrations as $mig) {
    runSqlFile($pdo, $mig);
}
echo '-- basis data uji ' . $testDb . ' siap (schema.sql + ' . count($migrations) . " migrasi)\n";

// ---------- 2) Stub Fonnte + balasan bot ----------
// Stub pengirim: uji ini TIDAK boleh mengirim WhatsApp nyata.
$sent = [];
Whatsapp::$sender = function (string $token, string $target, string $message) use (&$sent): void {
    $sent[] = ['target' => $target, 'message' => $message];
};
// wabotReply() aslinya didefinisikan di handlers/wabot.php (jalur HTTP);
// di sini diganti stub yang MENANGKAP balasan bot, bukan mengirim.
$replies = [];
$allReplies = [];
function wabotReply(string $text, array $settings, string $inboxId, string $replyTarget): void
{
    global $replies, $allReplies;
    $replies[] = $text;
    $allReplies[] = $text;
}

$settings = Db::one("SELECT * FROM whatsapp_settings WHERE id = 'wa_settings'");
if (!$settings) {
    Db::q("INSERT INTO whatsapp_settings (id, group_target, fonnte_token, is_enabled)
        VALUES ('wa_settings', 'GRP', 'TOKEN-UJI', 1)");
    $settings = Db::one("SELECT * FROM whatsapp_settings WHERE id = 'wa_settings'");
}

/**
 * Jalankan handler kata kunci WA ASLI untuk sebuah pesan.
 * Mirip dispatcher wabot.php: parseKeywordCommand() -> require wabot_keyword.php.
 * Return daftar balasan bot yang ditangkap stub.
 */
function kw(string $message, array $actorUser): array
{
    global $replies, $settings;
    $replies = [];
    $keyword = Wabot::parseKeywordCommand($message);
    if ($keyword === null) {
        $replies[] = '(BUKAN-KATA-KUNCI)';
        return $replies;
    }
    $inboxId = 'uji';
    $replyTarget = '628129999999';
    $actor = $actorUser;
    $actorUserId = (string) $actorUser['id'];
    require __DIR__ . '/../lib/handlers/wabot_keyword.php';
    return $replies;
}

// ---------- 3) Data uji ----------
$mkUser = function (string $id, string $name, string $role, ?string $wa, ?string $sup) use ($pdo): void {
    $pdo->prepare("INSERT INTO users (id, username, password, name, role, is_active, wa_number, supervisor_id)
                   VALUES (?, ?, 'x', ?, ?, 1, ?, ?)")->execute([$id, $id, $name, $role, $wa, $sup]);
};
$mkUser('u_admin', 'Admin Uji', 'ADMIN', null, null);
$mkUser('u_sekre', 'Sari Sekretaris', 'SEKRETARIS', '08111000004', null);
$mkUser('u_kasubag', 'Budi Kasubag', 'KEPALA_SUB_UMUM', '08111000003', 'u_sekre');
$mkUser('u_staff', 'Siti Pelaksana', 'STAFF', '08111000002', 'u_kasubag');
$mkUser('u_pimpin', 'Pak Ketua', 'PIMPINAN', '08111000006', null);
$mkUser('u_arsip', 'Ayu Arsiparis', 'ARSIPARIS', '08111000005', null);

$mkLetter = function (string $id, string $agenda, string $stage, string $level = 'BIASA',
    ?string $assignee = null, ?string $unit = null, string $subject = 'Surat uji patch') use ($pdo): void {
    $pdo->prepare("INSERT INTO incoming_letters (id, agenda_number, letter_number, letter_date, sender, subject,
        classification, security_level, received_date, current_stage, assignee_user_id, unit_tujuan, created_at)
        VALUES (?, ?, '001/X/2026', NOW(), 'Dinas Uji', ?, 'Biasa', ?, NOW(), ?, ?, ?, NOW())")
        ->execute([$id, $agenda, $subject, $level, $stage, $assignee, $unit]);
};

$staff   = Db::one("SELECT id, name, role FROM users WHERE id = 'u_staff'");
$kasubag = Db::one("SELECT id, name, role FROM users WHERE id = 'u_kasubag'");
$sekre   = Db::one("SELECT id, name, role FROM users WHERE id = 'u_sekre'");
$pimpin  = Db::one("SELECT id, name, role FROM users WHERE id = 'u_pimpin'");
$arsip   = Db::one("SELECT id, name, role FROM users WHERE id = 'u_arsip'");
$adminU  = Db::one("SELECT id, name, role FROM users WHERE id = 'u_admin'");
$stageOf = fn(string $id): string => strtoupper(trim((string)
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = ?", [$id])['s']));

echo "\n== T7: satu pegawai, DUA surat aktif ==\n";
$mkLetter('L7A', 'AGD/2026/901', 'DITERUSKAN_KE_PELAKSANA', 'BIASA', 'u_staff', 'KASUBAG_UMUM');
$mkLetter('L7B', 'AGD/2026/902', 'DITERUSKAN_KE_PELAKSANA', 'BIASA', 'u_staff', 'KASUBAG_UMUM');
$rep = kw('PROSES AGD/2026/901 siap dikerjakan hari ini', $staff);
check('T7 - perintah agenda A hanya menyentuh surat A',
    $stageOf('L7A') === 'DALAM_TINDAK_LANJUT' && $stageOf('L7B') === 'DITERUSKAN_KE_PELAKSANA',
    'A=' . $stageOf('L7A') . ' B=' . $stageOf('L7B'));
check('T7 - balasan PROSES sukses', str_contains((string) ($rep[0] ?? ''), 'TINDAKAN TERSIMPAN'), (string) ($rep[0] ?? ''));
$rep = kw('PROSES', $staff);
check('T7 - perintah tanpa agenda ditolak', str_contains((string) ($rep[0] ?? ''), 'butuh nomor agenda'), (string) ($rep[0] ?? ''));
$rep = kw('PROSES AGD/2026/999', $staff);
check('T7 - agenda tidak ditemukan ditolak', str_contains((string) ($rep[0] ?? ''), 'tidak ditemukan'), (string) ($rep[0] ?? ''));
$rep = kw('PROSES AGD/2026/902', $sekre);
check('T7 - agenda bukan tugas pengirim (Sekretaris) ditolak',
    str_contains((string) ($rep[0] ?? ''), 'bukan tugas Anda'), (string) ($rep[0] ?? ''));
check('T7 - surat B tidak berubah oleh perintah orang lain', $stageOf('L7B') === 'DITERUSKAN_KE_PELAKSANA');
// P1: angka menu aksi tahap dinonaktifkan secara default (tidak terikat agenda).
check('T7/P1 - balasan angka menu nonaktif default',
    !WorkflowConfig::waStageNumberReply() && str_contains(Wabot::buildStageNumberDisabledText(), 'dinonaktifkan'));
check('T7/P1 - menu tahap tanpa instruksi balas-angka',
    !str_contains(Wabot::buildStageTaskMenu([
        'letterId' => 'x', 'agenda' => 'AGD/2026/901', 'subject' => 'S', 'stage' => 'MENUNGGU_PENGARSIPAN',
        'sensitive' => false,
        'options' => [['n' => 1, 'toStage' => 'DIARSIPKAN', 'label' => 'Arsipkan', 'requiresRoute' => false]],
    ], 'Uji'), 'balas nomornya'));

echo "\n== T12: ARAHAN #KODE_UNIT (penanda eksplisit) ==\n";
$mkLetter('L12A', 'AGD/2026/912', 'MENUNGGU_KEBIJAKAN_PIMPINAN');
$rep = kw('ARAHAN AGD/2026/912 setujui dan proses #KASUBAG_UMU', $pimpin);
$l12 = Db::one("SELECT arahan_pimpinan, unit_tujuan, current_stage FROM incoming_letters WHERE id = 'L12A'");
check('T12 - typo penanda unit -> balasan error',
    str_contains((string) ($rep[0] ?? ''), 'tidak dikenal'), (string) ($rep[0] ?? ''));
check('T12 - typo unit: arahan/unit/tahap tidak berubah',
    $l12['arahanPimpinan'] === null && $l12['unitTujuan'] === null && $l12['currentStage'] === 'MENUNGGU_KEBIJAKAN_PIMPINAN',
    json_encode($l12));
$rep = kw('ARAHAN AGD/2026/912 proses sesuai jadwal KASUBAG_UMUM', $pimpin);
$l12 = Db::one("SELECT arahan_pimpinan, unit_tujuan, current_stage FROM incoming_letters WHERE id = 'L12A'");
check('T12 - kata akhir mirip unit TIDAK dipotong/ditetapkan diam-diam',
    $l12['arahanPimpinan'] === 'proses sesuai jadwal KASUBAG UMUM' && $l12['unitTujuan'] === null,
    json_encode($l12));
check('T12 - arahan tanpa penanda tetap tersimpan dan surat kembali ke Sekretaris',
    $l12['currentStage'] === 'DITERUSKAN_KE_SEKRETARIS_PANITERA', (string) $l12['currentStage']);
check('T12 - balasan menyebut Sekretaris memilih unit saat TERUSKAN',
    str_contains((string) ($rep[0] ?? ''), 'Tanpa penanda'), (string) ($rep[0] ?? ''));
$mkLetter('L12B', 'AGD/2026/913', 'MENUNGGU_KEBIJAKAN_PIMPINAN');
$rep = kw('ARAHAN AGD/2026/913 setujui dan teruskan #PANMUD_HUKUM', $pimpin);
$l12b = Db::one("SELECT arahan_pimpinan, unit_tujuan, current_stage FROM incoming_letters WHERE id = 'L12B'");
check('T12 - penanda sah: unit terbaca & tersimpan',
    $l12b['unitTujuan'] === 'PANMUD_HUKUM' && $l12b['arahanPimpinan'] === 'setujui dan teruskan'
        && $l12b['currentStage'] === 'DITERUSKAN_KE_SEKRETARIS_PANITERA',
    json_encode($l12b));
check('T12 - balasan menyebut unit terbaca', str_contains((string) ($rep[0] ?? ''), 'PANMUD_HUKUM'), (string) ($rep[0] ?? ''));

echo "\n== T14: TOLAK via WA (K3) ==\n";
$mkLetter('L14A', 'AGD/2026/914', 'DALAM_TINDAK_LANJUT', 'BIASA', 'u_staff', 'KASUBAG_UMUM');
$rep = kw('TOLAK AGD/2026/914', $staff);
check('T14 - TOLAK tanpa alasan ditolak', str_contains((string) ($rep[0] ?? ''), 'wajib menyertakan alasan'), (string) ($rep[0] ?? ''));
$rep = kw('TOLAK AGD/2026/914 pendek', $staff);
check('T14 - TOLAK alasan < 10 karakter ditolak', str_contains((string) ($rep[0] ?? ''), 'minimal 10'), (string) ($rep[0] ?? ''));
$rep = kw('TOLAK AGD/2026/914 mohon dicek ulang, berkas kurang lengkap', $staff);
check('T14 - pegawai (assignee) TOLAK via WA -> kembali satu langkah',
    $stageOf('L14A') === 'DITERUSKAN_KE_PELAKSANA' && str_contains((string) ($rep[0] ?? ''), 'TINDAKAN TERSIMPAN'),
    'stage=' . $stageOf('L14A') . ' reply=' . (string) ($rep[0] ?? ''));
checkSame('T14 - log STAGE_TOLAK tercatat via WA', 1,
    (int) Db::one("SELECT COUNT(*) c FROM letter_control_logs WHERE incoming_letter_id = 'L14A' AND action = 'STAGE_TOLAK'")['c']);
$rep = kw('TOLAK AGD/2026/914 Ketua tidak punya tombol tolak', $pimpin);
check('T14 - Ketua/WK tidak punya TOLAK (Q1 final)',
    str_contains((string) ($rep[0] ?? ''), 'tidak memiliki TOLAK'), (string) ($rep[0] ?? ''));

echo "\n== T9: RAHASIA — payload Fonnte dipindai di SEMUA tahap (K6) ==\n";
$mkLetter('L9', 'AGD/2026/920', 'DISORTIR', 'RAHASIA', null, null, 'Hasil pemeriksaan rahasia uji');
$sent = []; // isolasi capture mulai bagian ini
$allReplies = [];
$letter9 = fn() => Db::one("SELECT * FROM incoming_letters WHERE id = 'L9'");
LetterTransition::apply($adminU, $letter9(), 'MENUNGGU_PENGARAHAN', ['notes' => 'uji']);
LetterTransition::apply($adminU, $letter9(), 'DIBACA_PENGARAH', ['notes' => 'uji']);
LetterTransition::saveRekomendasi($kasubag, $letter9(), 'KEBIJAKAN', 'Perlu arahan pimpinan, rekomendasi uji.');
LetterTransition::apply($sekre, $letter9(), 'MENUNGGU_DISPOSISI', ['notes' => 'uji']);
LetterTransition::decideRoute($sekre, $letter9(), 'KEBIJAKAN', ['notes' => 'uji']);
LetterTransition::apply($sekre, $letter9(), 'DIDISPOSISIKAN', ['route' => 'KEBIJAKAN', 'notes' => 'uji']);
LetterTransition::apply($sekre, $letter9(), 'DITERUSKAN_KE_SEKRETARIS_PANITERA', ['notes' => 'uji']);
LetterTransition::apply($sekre, $letter9(), 'MENUNGGU_KEBIJAKAN_PIMPINAN', ['notes' => 'uji']);
check('T9 - surat RAHASIA sampai meja pimpinan', $stageOf('L9') === 'MENUNGGU_KEBIJAKAN_PIMPINAN', $stageOf('L9'));
check('T9 - payload notifikasi terkirim ke pemegang tahap (stub)', count($sent) > 0, count($sent) . ' pesan');

// K6: ARAHAN via WA untuk RAHASIA ditolak, isi arahan tidak masuk percakapan.
$arahanRahasia = 'ARAHANRAHASIA jangan pernah kirim ini ke WA';
$rep = kw('ARAHAN AGD/2026/920 ' . $arahanRahasia, $pimpin);
check('T9/K6 - ARAHAN RAHASIA via WA ditolak',
    str_contains((string) ($rep[0] ?? ''), 'hanya lewat aplikasi web'), (string) ($rep[0] ?? ''));
$l9 = $letter9();
check('T9/K6 - arahan RAHASIA tidak tersimpan via WA',
    $l9['arahanPimpinan'] === null && $stageOf('L9') === 'MENUNGGU_KEBIJAKAN_PIMPINAN', json_encode($l9));

// Pindai SEMUA payload stub + SEMUA balasan bot sepanjang bagian ini.
$leakSubject = false; $leakArahan = false;
foreach ($sent as $s) {
    if (str_contains($s['message'], 'Hasil pemeriksaan')) $leakSubject = true;
    if (str_contains($s['message'], $arahanRahasia)) $leakArahan = true;
}
foreach ($allReplies as $r) {
    if (str_contains($r, 'Hasil pemeriksaan')) $leakSubject = true;
    if (str_contains($r, $arahanRahasia)) $leakArahan = true;
}
check('T9 - perihal RAHASIA TIDAK pernah masuk payload Fonnte', !$leakSubject, json_encode($sent));
check('T9 - isi arahan RAHASIA TIDAK pernah masuk percakapan WA', !$leakArahan);
$msgsRahasia = array_values(array_filter($sent, fn($s) => str_contains($s['message'], 'AGD/2026/920')));
$badRahasia = array_filter($msgsRahasia,
    fn($s) => !str_contains($s['message'], 'RAHASIA') || str_contains($s['message'], 'Hasil pemeriksaan'));
check('T9 - setiap pesan RAHASIA berlabel RAHASIA & tanpa perihal',
    count($msgsRahasia) > 0 && !$badRahasia, count($msgsRahasia) . ' pesan');
check('T9 - ada pesan yang menyebut tindak lanjut lewat web',
    array_filter($msgsRahasia, fn($s) => str_contains($s['message'], 'web')) !== []);

echo "\n== T16: lembar 1 (K7) + toggle (K6/K7) ==\n";
$mkLetter('L16A', 'AGD/2026/930', 'MENUNGGU_PENGARSIPAN');
$r16 = LetterTransition::apply($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16A'"), 'DIARSIPKAN', [
    'notes' => 'uji', 'source' => LetterTransition::SOURCE_WEB]);
check('T16/K7 - arsip tanpa lembar 1 TIDAK diblokir (default mati)',
    !empty($r16['ok']) && !empty($r16['warning']) && $stageOf('L16A') === 'DIARSIPKAN', json_encode($r16));
checkSame('T16/K7 - peringatan ARSIP_TANPA_LEMBAR1 tercatat di log', 1,
    (int) Db::one("SELECT COUNT(*) c FROM letter_control_logs WHERE incoming_letter_id = 'L16A' AND action = 'ARSIP_TANPA_LEMBAR1'")['c']);

$mkLetter('L16B', 'AGD/2026/931', 'MENUNGGU_PENGARSIPAN');
$r16 = LetterTransition::recordLembar1($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16B'"),
    (string) $staff['id'], (string) $arsip['id']);
check('T16/K7 - serah-terima lembar 1 tercatat', !empty($r16['ok']), json_encode($r16));
$l16b = Db::one("SELECT lembar1_diserahkan_oleh, lembar1_diterima_oleh, lembar1_diserahkan_at FROM incoming_letters WHERE id = 'L16B'");
check('T16/K7 - penyerah & penerima & waktu terisi',
    $l16b['lembar1DiserahkanOleh'] === 'u_staff' && $l16b['lembar1DiterimaOleh'] === 'u_arsip' && !empty($l16b['lembar1DiserahkanAt']),
    json_encode($l16b));
checkSame('T16/K7 - log LEMBAR1_SERAH_TERIMA', 1,
    (int) Db::one("SELECT COUNT(*) c FROM letter_control_logs WHERE incoming_letter_id = 'L16B' AND action = 'LEMBAR1_SERAH_TERIMA'")['c']);
$r16 = LetterTransition::apply($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16B'"), 'DIARSIPKAN', [
    'notes' => 'uji', 'source' => LetterTransition::SOURCE_WEB]);
check('T16/K7 - arsip DENGAN lembar 1: tanpa peringatan',
    !empty($r16['ok']) && empty($r16['warning']), json_encode($r16));

// Toggle lembar1_wajib aktif -> DIARSIPKAN diblokir sebelum lembar 1 tercatat.
Db::q("UPDATE workflow_settings SET lembar1_wajib = 1 WHERE id = 'wf_settings'");
WorkflowConfig::reset();
$mkLetter('L16C', 'AGD/2026/932', 'MENUNGGU_PENGARSIPAN');
$r16 = LetterTransition::apply($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16C'"), 'DIARSIPKAN', [
    'notes' => 'uji', 'source' => LetterTransition::SOURCE_WEB]);
check('T16/K7 - toggle wajib aktif: arsip tanpa lembar 1 DITOLAK',
    empty($r16['ok']) && $r16['code'] === 'LEMBAR1_REQUIRED' && $stageOf('L16C') === 'MENUNGGU_PENGARSIPAN', json_encode($r16));
LetterTransition::recordLembar1($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16C'"),
    (string) $staff['id'], (string) $arsip['id']);
$r16 = LetterTransition::apply($arsip, Db::one("SELECT * FROM incoming_letters WHERE id = 'L16C'"), 'DIARSIPKAN', [
    'notes' => 'uji', 'source' => LetterTransition::SOURCE_WEB]);
check('T16/K7 - setelah lembar 1 dicatat, arsip lolos (toggle aktif)', !empty($r16['ok']), json_encode($r16));
Db::q("UPDATE workflow_settings SET lembar1_wajib = 0 WHERE id = 'wf_settings'");
WorkflowConfig::reset();

// K6 toggle: matikan blokir -> ARAHAN RAHASIA diproses, isi tetap tidak bocor.
$mkLetter('L16D', 'AGD/2026/933', 'MENUNGGU_KEBIJAKAN_PIMPINAN', 'RAHASIA', null, null, 'Rahasia uji toggle');
Db::q("UPDATE workflow_settings SET wa_arahan_rahasia_blocked = 0 WHERE id = 'wf_settings'");
WorkflowConfig::reset();
$sent = []; $allReplies = [];
$rep = kw('ARAHAN AGD/2026/933 proses sesuai ketentuan kerahasiaan', $pimpin);
check('T16/K6 - toggle K6 mati: ARAHAN RAHASIA diproses via WA',
    $stageOf('L16D') === 'DITERUSKAN_KE_SEKRETARIS_PANITERA'
        && str_contains((string) ($rep[0] ?? ''), 'TINDAKAN TERSIMPAN'),
    'stage=' . $stageOf('L16D') . ' reply=' . (string) ($rep[0] ?? ''));
$leakToggle = false;
foreach ($sent as $s) { if (str_contains($s['message'], 'Rahasia uji toggle')) $leakToggle = true; }
foreach ($allReplies as $r) { if (str_contains($r, 'Rahasia uji toggle')) $leakToggle = true; }
check('T16/K6 - meski toggle mati, perihal RAHASIA tetap tidak bocor ke WA', !$leakToggle, json_encode($sent));
Db::q("UPDATE workflow_settings SET wa_arahan_rahasia_blocked = 1 WHERE id = 'wf_settings'");
WorkflowConfig::reset();

echo "\n== SOP patch check: $pass PASS, $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
