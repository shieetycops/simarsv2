<?php
// Uji integrasi FASE 0-3 (WA + Buku Kendali) terhadap MySQL LOKAL.
//
// Kenapa bukan test_e2e.php: yang diuji di sini adalah LAPISAN DATA (bukan HTTP):
// jembatan laporan disposisi -> tahap surat (DispositionBridge), satu pintu
// perpindahan tahap (LetterTransition), notifikasi/menu WhatsApp per tahap
// (WaStageNotifier), dan hak penarikan kembali Kasubag.
//
// Basis data TERPISAH supaya data pengembangan tidak tersentuh:
//   php api-php/tests/run_fase1_check.php            (pakai simars_v2_test)
//   $env:SIMARS_TEST_DB='nama_lain'; php api-php/tests/run_fase1_check.php
//
// Skrip ini MEMBUAT ULANG basis data uji (DROP + schema.sql + semua migrasi),
// jadi jangan diarahkan ke basis data produksi.
$config = require __DIR__ . '/../config.php';
$testDb = getenv('SIMARS_TEST_DB') ?: 'simars_v2_test';

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

// ---------- 1) Siapkan basis data uji ----------
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
    // Migrasi di repo ini memakai PREPARE/EXECUTE/DEALLOCATE (untuk mengecek
    // keberadaan kolom di information_schema). Tanpa buffered query, rangkaian
    // itu gagal "Cannot execute queries while other unbuffered queries are active".
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);

// Jalankan berkas SQL per pernyataan: komentar "--" dan baris kosong dibuang.
//
// Toleransi wajib: schema.sql di repo ini sudah memuat sebagian kolom yang juga
// ditambahkan migrasi v2 (mis. agenda_number), sehingga ADD COLUMN bisa gagal
// "Duplicate column name". Untuk basis data uji yang baru dibuat, galat
// "sudah ada" itu justru berarti tujuannya sudah tercapai -> dilewati.
// Galat lain (salah sintaks, referensi tabel hilang) tetap dilempar.
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
            // query()+closeCursor (bukan exec()): sebagian migrasi memakai
            // SET @var := (SELECT ...) diikuti PREPARE/EXECUTE, dan PDO::exec
            // tidak menutup result set sehingga DEALLOCATE gagal dengan
            // "Cannot execute queries while other unbuffered queries are active".
            $sth = $pdo->query($stmt);
            if ($sth !== false) $sth->closeCursor();
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $alreadyExists = str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate column name')
                || str_contains($msg, "Can't DROP");
            if (!$alreadyExists) {
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
require_once __DIR__ . '/../lib/DispositionBridge.php';

// Stub pengirim Fonnte: uji ini TIDAK boleh mengirim WhatsApp sungguhan.
$sent = [];
Whatsapp::$sender = function (string $token, string $target, string $message) use (&$sent): void {
    $sent[] = ['target' => $target, 'message' => $message];
};

Db::$pdo = $pdo;

runSqlFile($pdo, __DIR__ . '/../schema.sql');
$migrations = glob(__DIR__ . '/../migrations/*.sql');
sort($migrations);
// 2026_09_23_v2_seed_classifications.sql MENGISI tabel archive_classifications
// yang justru DIBUAT oleh 2026_09_23_v2_workflow.sql, tetapi urutan abjad
// menaruh 'seed' lebih dulu. Jadi seluruh migrasi non-seed dijalankan lebih dulu
// (urutan abjad), lalu migrasi seed.
usort($migrations, fn($a, $b) =>
    [str_contains(basename($a), '_seed_'), basename($a)] <=> [str_contains(basename($b), '_seed_'), basename($b)]);
foreach ($migrations as $mig) {
    runSqlFile($pdo, $mig);
}
echo '-- basis data uji ' . $testDb . ' siap (schema.sql + ' . count($migrations) . " migrasi)\n";

// ---------- 2) Data uji ----------
$mkUser = function (string $id, string $name, string $role, ?string $wa, ?string $sup) use ($pdo): void {
    $pdo->prepare("INSERT INTO users (id, username, password, name, role, is_active, wa_number, supervisor_id)
                   VALUES (?, ?, 'x', ?, ?, 1, ?, ?)")->execute([$id, $id, $name, $role, $wa, $sup]);
};
// Urutan penting: kolom supervisor_id ber-FOREIGN KEY ke users(id), jadi atasan
// harus dibuat lebih dulu (sekre -> kasubag -> staff).
$mkUser('u_admin', 'Admin SIMARS', 'ADMIN', '08111000001', null);
$mkUser('u_sekre', 'Sari Sekretaris', 'SEKRETARIS', '08111000004', null);
$mkUser('u_kasubag', 'Budi Kasubag', 'KEPALA_SUB_UMUM', '08111000003', 'u_sekre');
$mkUser('u_staff', 'Siti Pelaksana', 'STAFF', '08111000002', 'u_kasubag');
$mkUser('u_panitera', 'Panitera Tanpa Nomor', 'PANITERA', null, null);
// P8 (revisi kedua): Arsiparis kini pemilik MENUNGGU_PENGARSIPAN — beri nomor
// WA supaya notifikasi kepadanya bisa diverifikasi di bagian 8.
$mkUser('u_arsip', 'Ayu Arsiparis', 'ARSIPARIS', '08111000005', null);

$pdo->exec("INSERT INTO incoming_letters (id, agenda_number, letter_number, letter_date, sender, subject,
        classification, security_level, received_date, current_stage, created_at)
    VALUES ('L1', 'AGD/2026/001', '001/X/2026', NOW(), 'Dinas Pendidikan', 'Undangan rapat koordinasi',
        'Biasa', 'BIASA', NOW(), 'DITERUSKAN_KE_PELAKSANA', NOW())");
$pdo->exec("INSERT INTO incoming_letters (id, agenda_number, letter_number, letter_date, sender, subject,
        classification, security_level, received_date, current_stage, created_at)
    VALUES ('L2', 'AGD/2026/002', '002/X/2026', NOW(), 'Inspektorat', 'Hasil pemeriksaan',
        'Rahasia', 'RAHASIA', NOW(), 'DITERUSKAN_KE_PELAKSANA', NOW())");

// Disposisi Kasubag -> pelaksana untuk L1.
$pdo->exec("INSERT INTO dispositions (id, incoming_letter_id, from_user_id, to_user_id, instruction, status, created_at, updated_at)
    VALUES ('D1', 'L1', 'u_kasubag', 'u_staff', 'Segera ditindaklanjuti.', 'PENDING', NOW(), NOW())");

// WhatsApp "aktif" + token dummy: tanpa ini Whatsapp::send berhenti lebih dulu
// ("Fonnte token kosong") dan stub pengirim tidak pernah dipanggil.
$pdo->exec("INSERT INTO whatsapp_settings (id, group_target, fonnte_token, is_enabled)
    VALUES ('wa_settings', 'GRP', 'TOKEN-UJI', 1)
    ON DUPLICATE KEY UPDATE group_target = VALUES(group_target), fonnte_token = VALUES(fonnte_token), is_enabled = 1");

// ---------- 3) Fase 0: pelaksana surat ----------
DispositionBridge::assignLetter('L1', 'u_staff');
$row = Db::one("SELECT assignee_user_id AS a, assignee_set_at AS t FROM incoming_letters WHERE id = 'L1'");
checkSame('Fase 0 - assignee surat tercatat', 'u_staff', $row['a']);
check('Fase 0 - waktu penunjukan terisi', !empty($row['t']));

// ---------- 4) Fase 1: laporan PROSES -> DALAM_TINDAK_LANJUT ----------
$sent = [];
$staff = Db::one("SELECT id, name, role FROM users WHERE id = 'u_staff'");
$res = DispositionBridge::applyStatus('D1', 'PROSES', $staff);
check('Fase 1 - laporan PROSES maju satu tahap', !empty($res['applied']), json_encode($res));
checkSame('Fase 1 - tahap baru DALAM_TINDAK_LANJUT', 'DALAM_TINDAK_LANJUT', $res['stage']);
checkSame('Fase 1 - tahap tersimpan di DB', 'DALAM_TINDAK_LANJUT',
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = 'L1'")['s']);
checkSame('Fase 1 - log kendali AUTO_STAGE', 'AUTO_STAGE',
    Db::one("SELECT action FROM letter_control_logs WHERE incoming_letter_id = 'L1' ORDER BY created_at DESC, id DESC LIMIT 1")['action']);
checkSame('Fase 1 - PROSES tidak mengirim WA (tahap pelaksana = pemegangnya sendiri)', 0, count($sent));

// Idempoten: laporan PROSES kedua tidak menaikkan tahap lagi.
$again = DispositionBridge::applyStatus('D1', 'PROSES', $staff);
check('Fase 1 - laporan PROSES kedua tidak menaikkan tahap', empty($again['applied']));
checkSame('Fase 1 - alasan idempoten', 'SUDAH_PADA_TAHAP', $again['reason']);

// ---------- 5) Fase 1: laporan SELESAI -> SELESAI_DITINDAKLANJUTI ----------
$sent = [];
$res = DispositionBridge::applyStatus('D1', 'SELESAI', $staff);
check('Fase 1 - laporan SELESAI maju ke tahap akhir pelaksanaan', !empty($res['applied']), json_encode($res));
checkSame('Fase 1 - tahap SELESAI_DITINDAKLANJUTI', 'SELESAI_DITINDAKLANJUTI', $res['stage']);

// Tiap penerima menerima DUA pesan: pemberitahuan tahap + menu aksi bernomor.
$targets = array_values(array_unique(array_column($sent, 'target')));
sort($targets);
checkSame('Fase 2 - sasaran notifikasi = Sekretaris & Kasubag ber-nomor WA',
    ['628111000003', '628111000004'], $targets);
checkSame('Fase 2 - tiap penerima dapat pemberitahuan + menu', 4, count($sent));
$notice = '';
foreach ($sent as $s) {
    if ($s['target'] === '628111000004' && str_contains($s['message'], 'SURAT MASUK')) $notice = $s['message'];
}
check('Fase 2 - pesan memuat perihal surat', str_contains($notice, 'Undangan rapat koordinasi'), $notice);
$menu = '';
foreach ($sent as $s) {
    if ($s['target'] === '628111000004' && str_contains($s['message'], 'TINDAK LANJUT SURAT')) $menu = $s['message'];
}
check('Fase 2 - menu aksi memuat perihal + agenda', str_contains($menu, 'Undangan rapat koordinasi')
    && str_contains($menu, 'AGD/2026/001'), $menu);

// ---------- 6) Fase 2: menu aksi arsip untuk Sekretaris ----------
$ses = Db::one("SELECT kind, step, context FROM wa_sessions WHERE user_id = 'u_sekre'");
check('Fase 2 - sesi aksi ARCHIVE dibuat untuk Sekretaris', ($ses['kind'] ?? '') === 'ARCHIVE', json_encode($ses));
$sctx = json_decode((string) ($ses['context'] ?? ''), true) ?: [];
$head = $sctx['queue'][0] ?? [];
checkSame('Fase 2 - antrean memuat surat yang benar', 'L1', $head['letterId'] ?? null);
checkSame('Fase 2 - pilihan pertama = MENUNGGU_PENGARSIPAN', 'MENUNGGU_PENGARSIPAN',
    $head['options'][0]['toStage'] ?? null);


// ---------- 7) K3 (DEFAULT SEMENTARA - menunggu review pimpinan) ----------
// TOLAK satu langkah oleh pemegang, RECALL oleh pengirim sebelum penerima
// bertindak, koreksi ADMIN. Menggantikan aturan lama "Kasubag Umum menarik
// surat dari rantai pelaksanaan" (docs/CATATAN_ASUMSI_REVISI.md bag. 10).
$kasubag = Db::one("SELECT id, name, role FROM users WHERE id = 'u_kasubag'");
$sekre   = Db::one("SELECT id, name, role FROM users WHERE id = 'u_sekre'");
$staffU  = Db::one("SELECT id, name, role FROM users WHERE id = 'u_staff'");
$adminU  = Db::one("SELECT id, name, role FROM users WHERE id = 'u_admin'");
$letter  = Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'");
$reason  = 'Salah unit pelaksana, harusnya Subbag Kepegawaian';

// 7a. Kasubag TIDAK lagi bisa menarik surat yang sudah diputuskan Sekretaris
//     (di SELESAI_DITINDAKLANJUTI ia bukan pemegang/pengirim kaki itu).
$deniedK = LetterTransition::reopen($kasubag, $letter, 'Kasubag mencoba menarik surat ini');
check('K3 - Kasubag tidak bisa menarik surat yang sudah diputuskan Sekretaris',
    empty($deniedK['ok']) && $deniedK['code'] === 'REOPEN_DENIED', json_encode($deniedK));
$deniedS = LetterTransition::reopen($sekre, $letter, 'Sekretaris mencoba menarik surat ini');
check('K3 - Sekretaris tidak bisa menarik surat di SELESAI_DITINDAKLANJUTI',
    empty($deniedS['ok']) && $deniedS['code'] === 'REOPEN_DENIED', json_encode($deniedS));

// 7b. ADMIN boleh mengoreksi kasus khusus (tercatat, action STAGE_REOPEN).
$ok = LetterTransition::reopen($adminU, $letter, 'Koreksi kasus khusus: perlu dikerjakan ulang');
check('K3 - ADMIN boleh mengoreksi kasus khusus', !empty($ok['ok']) && $ok['mode'] === 'ADMIN_REOPEN', json_encode($ok));
checkSame('K3 - target koreksi ADMIN = meja Kasubag', 'DITERUSKAN_KE_KASUBAG',
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = 'L1'")['s']);
checkSame('K3 - log koreksi tercatat STAGE_REOPEN', 1,
    (int) Db::one("SELECT COUNT(*) c FROM letter_control_logs WHERE incoming_letter_id = 'L1' AND action = 'STAGE_REOPEN'")['c']);

// 7c. RECALL: Kasubag (pengirim) menarik kiriman ke Sekretaris sebelum
//     Sekretaris bertindak.
LetterTransition::apply($kasubag, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'DITERUSKAN_KE_SEKRETARIS_PANITERA', ['notes' => 'Diteruskan (uji K3)']);
$ok = LetterTransition::reopen($kasubag, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'Recall uji: perlu dilengkapi lampiran');
check('K3 - RECALL oleh pengirim diterima selama penerima belum bertindak',
    !empty($ok['ok']) && $ok['mode'] === 'RECALL', json_encode($ok));
checkSame('K3 - target recall = meja Kasubag', 'DITERUSKAN_KE_KASUBAG',
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = 'L1'")['s']);
checkSame('K3 - log recall tercatat STAGE_RECALL', 1,
    (int) Db::one("SELECT COUNT(*) c FROM letter_control_logs WHERE incoming_letter_id = 'L1' AND action = 'STAGE_RECALL'")['c']);

// 7d. RECALL ditolak setelah penerima bertindak (kasubag TUNJUK pegawai).
LetterTransition::apply($kasubag, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'DITERUSKAN_KE_SEKRETARIS_PANITERA', ['notes' => 'Diteruskan ulang (uji K3)']);
LetterTransition::apply($sekre, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'DITERUSKAN_KE_PELAKSANA', ['notes' => 'LANGSUNG (uji K3)', 'route' => 'LANGSUNG', 'unit_tujuan' => 'KASUBAG_UMUM']);
Db::q('UPDATE incoming_letters SET assignee_user_id = ?, assignee_set_at = NOW() WHERE id = ?', ['u_staff', 'L1']);
Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)',
    [Db::generateId(), 'L1', 'u_kasubag', 'DITERUSKAN_KE_PELAKSANA', 'DITERUSKAN_KE_PELAKSANA', 'TUNJUK_PEGAWAI', 'Uji K3: tunjuk Siti']);
$deniedR = LetterTransition::reopen($sekre, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'Sekretaris mencoba recall setelah kepala unit tunjuk pegawai');
check('K3 - RECALL ditolak setelah penerima bertindak (TUNJUK)',
    empty($deniedR['ok']) && $deniedR['code'] === 'REOPEN_DENIED', json_encode($deniedR));

// 7e. TOLAK pegawai (assignee) satu langkah ke kepala unit; alasan wajib.
LetterTransition::apply($staffU, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"),
    'DALAM_TINDAK_LANJUT', ['notes' => 'PROSES (uji K3)', 'action' => LetterTransition::ACTION_AUTO,
        'source' => LetterTransition::SOURCE_AUTO]);
$bad = LetterTransition::reopen($staffU, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"), 'salah');
check('K3 - alasan TOLAK terlalu pendek ditolak', empty($bad['ok']) && $bad['code'] === 'REOPEN_REASON', json_encode($bad));
$ok = LetterTransition::reopen($staffU, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"), $reason);
check('K3 - pegawai (assignee) bisa TOLAK satu langkah', !empty($ok['ok']) && $ok['mode'] === 'TOLAK', json_encode($ok));
checkSame('K3 - TOLAK pegawai -> meja unit (DITERUSKAN_KE_PELAKSANA)', 'DITERUSKAN_KE_PELAKSANA',
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = 'L1'")['s']);
$tolakLog = Db::one("SELECT actor_user_id, from_stage, to_stage, notes FROM letter_control_logs
    WHERE incoming_letter_id = 'L1' AND action = 'STAGE_TOLAK'");
check('K3 - log TOLAK memuat pelaku + tahap asal/tujuan + alasan',
    $tolakLog['actorUserId'] === 'u_staff'
        && $tolakLog['fromStage'] === 'DALAM_TINDAK_LANJUT'
        && $tolakLog['toStage'] === 'DITERUSKAN_KE_PELAKSANA'
        && str_contains((string) $tolakLog['notes'], 'Subbag Kepegawaian'),
    json_encode($tolakLog));

// 7f. TOLAK kepala unit -> Sekretaris/Panitera, lalu Sekretaris -> Kasubag.
$ok = LetterTransition::reopen($kasubag, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"), $reason);
check('K3 - kepala unit bisa TOLAK ke Sekretaris/Panitera', !empty($ok['ok']) && $ok['mode'] === 'TOLAK', json_encode($ok));
$ok = LetterTransition::reopen($sekre, Db::one("SELECT * FROM incoming_letters WHERE id = 'L1'"), $reason);
check('K3 - Sekretaris bisa TOLAK ke Kasubag Umum', !empty($ok['ok']) && $ok['mode'] === 'TOLAK', json_encode($ok));
checkSame('K3 - hasil akhir TOLAK berantai = meja Kasubag', 'DITERUSKAN_KE_KASUBAG',
    Db::one("SELECT current_stage AS s FROM incoming_letters WHERE id = 'L1'")['s']);

// Setelah ditolak/ditarik, laporan pelaksana TIDAK boleh memajukan tahap lagi.
$afterReopen = DispositionBridge::applyStatus('D1', 'SELESAI', $staff);
check('K3 - laporan setelah penarikan tidak memajukan tahap', empty($afterReopen['applied']));
checkSame('K3 - alasan penolakan', 'BUKAN_TAHAP_PELAKSANAAN', $afterReopen['reason']);

// ---------- 8) Fase 2: isi surat RAHASIA tidak bocor ke WhatsApp ----------
$pdo->exec("UPDATE incoming_letters SET current_stage = 'MENUNGGU_PENGARSIPAN' WHERE id = 'L2'");
$sent = [];
WaStageNotifier::afterTransition(
    Db::one("SELECT * FROM incoming_letters WHERE id = 'L2'"),
    'SELESAI_DITINDAKLANJUTI', 'MENUNGGU_PENGARSIPAN', $kasubag, ['source' => 'AUTO']);
$leaked = false; $blocked = false;
foreach ($sent as $s) {
    if (str_contains($s['message'], 'Hasil pemeriksaan')) $leaked = true;
    if (str_contains($s['message'], 'RAHASIA')) $blocked = true;
}
check('Fase 2 - perihal surat RAHASIA TIDAK dikirim ke WhatsApp', !$leaked);
check('Fase 2 - notifikasi menyebut isi hanya dibuka di web', $blocked, json_encode($sent));

// P8 (revisi kedua): MENUNGGU_PENGARSIPAN kini juga milik ARSIPARIS — ia
// menerima WA + menu aksi arsip; role lain (Sekretaris) tidak kehilangan.
$waArsip = (string) Wabot::normalizeNumber('08111000005');
$targets8 = array_values(array_unique(array_column($sent, 'target')));
check('P8 - Arsiparis menerima WA saat surat masuk MENUNGGU_PENGARSIPAN',
    in_array($waArsip, $targets8, true), json_encode($targets8));
check('P8 - Sekretaris tetap menerima notifikasi (tidak hilang)',
    in_array('628111000004', $targets8, true), json_encode($targets8));
$sesA = Db::one("SELECT kind, step, context FROM wa_sessions WHERE user_id = 'u_arsip'");
check('P8 - sesi aksi ARCHIVE dibuat untuk Arsiparis', ($sesA['kind'] ?? '') === 'ARCHIVE', json_encode($sesA));
$sctxA = json_decode((string) ($sesA['context'] ?? ''), true) ?: [];
$headA = $sctxA['queue'][0] ?? [];
checkSame('P8 - antrean Arsiparis memuat surat yang benar', 'L2', $headA['letterId'] ?? null);
checkSame('P8 - pilihan pertama Arsiparis = DIARSIPKAN', 'DIARSIPKAN',
    $headA['options'][0]['toStage'] ?? null);
$menuA = '';
foreach ($sent as $s) {
    if ($s['target'] === $waArsip && str_contains($s['message'], 'TINDAK LANJUT SURAT')) $menuA = $s['message'];
}
check('P8 - menu aksi Arsiparis tanpa perihal RAHASIA',
    $menuA !== '' && !str_contains($menuA, 'Hasil pemeriksaan') && str_contains($menuA, 'RAHASIA'), $menuA);

// ---------- 9) Fase 3: kandidat menu WA = bawahan langsung ----------
$candKasubag = waDispositionCandidates('u_kasubag', 'KEPALA_SUB_UMUM', 25);
checkSame('Fase 3 - daftar menu Kasubag = bawahannya saja', ['Siti Pelaksana'], array_column($candKasubag, 'name'));
$candAdmin = waDispositionCandidates('u_admin', 'ADMIN', 25);
// P8: user uji kini 4 orang bernomor WA (Arsiparis ditambahkan).
check('Fase 3 - daftar menu ADMIN = semua pegawai bernomor WA', count($candAdmin) === 4, json_encode($candAdmin));
$candSekre = waDispositionCandidates('u_sekre', 'SEKRETARIS', 25);
checkSame('Fase 3 - daftar menu Sekretaris = bawahan langsungnya', ['Budi Kasubag'], array_column($candSekre, 'name'));
// Role hierarki TANPA bawahan terpetakan: daftar KOSONG (bukan semua pegawai,
// karena pemilihannya nanti pasti ditolak Disposition::canDispose).
$candPanitera = waDispositionCandidates('u_panitera', 'PANITERA', 25);
checkSame('Fase 3 - role hierarki tanpa bawahan -> daftar kosong', [], array_column($candPanitera, 'name'));

echo "\n== Fase 1 check: $pass PASS, $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);

