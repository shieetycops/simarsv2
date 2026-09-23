<?php
// Pemeriksaan mandiri bot WA disposisi — TANPA PHPUnit/Composer/DB.
// Jalankan: php api-php/tests/run_wa_bot_check.php
// Exit code 0 = semua pass; 1 = ada kegagalan.

require_once __DIR__ . '/../lib/Wabot.php';

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, $actual, $expected): void
{
    global $pass, $fail, $failures;
    if ($actual === $expected) {
        $pass++;
        return;
    }
    $fail++;
    $failures[] = $label
        . "\n    diharapkan: " . var_export($expected, true)
        . "\n    aktual    : " . var_export($actual, true);
}

// ---------- parseCommand: tabel kasus ----------
$LIST = ['action' => 'LIST'];
$HELP = ['action' => 'HELP'];
$INVALID = ['action' => 'INVALID'];
$cases = [
    // daftar & bantuan
    ['DISPOSISI', $LIST],
    ['disposisi', $LIST],
    ['  DISPOSISI  ', $LIST],
    ['*DISPOSISI*', $LIST],
    ['DISPOSISI DAFTAR', $LIST],
    ['disposisi cek', $LIST],
    ['DISPOSISI BANTUAN', $HELP],
    ['disposisi help', $HELP],
    ['DISPOSISI PANDUAN', $HELP],
    ['DISPOSISI ?', $HELP],
    // pola A: DISPOSISI <n> <status> [catatan]
    ['DISPOSISI 2 SELESAI', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
    ['disposisi 2 selesai', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
    ['*DISPOSISI* 1 SELESAI', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 1, 'notes' => null]],
    ['DISPOSISI 12 PROSES', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 12, 'notes' => null]],
    ['DISPOSISI 1 TINDAKLANJUTI segera', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 1, 'notes' => 'segera']],
    ['DISPOSISI 3 tindak lanjuti', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 3, 'notes' => null]],
    ['DISPOSISI 1 belum selesai', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 1, 'notes' => null]],
    ['DISPOSISI 5 belum', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 5, 'notes' => null]],
    ['DISPOSISI 2 sudah dikerjakan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
    ['DISPOSISI 2 selesai. sudah diarsipkan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => 'sudah diarsipkan']],
    ['DISPOSISI 5 beres', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 5, 'notes' => null]],
    ['DISPOSISI 4 done', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 4, 'notes' => null]],
    ['DISPOSISI: 2 SELESAI catatan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => 'catatan']],
    // pola B: DISPOSISI <status> <n> [catatan]
    ['DISPOSISI SELESAI 2', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
    ['DISPOSISI selesaikan 3 tolong diarsipkan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 3, 'notes' => 'tolong diarsipkan']],
    ['DISPOSISI PROSES 7', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 7, 'notes' => null]],
    // perintah tidak lengkap -> INVALID
    ['DISPOSISI 2', $INVALID],
    ['DISPOSISI 0 SELESAI', $INVALID],
    ['DISPOSISI 99', $INVALID],
    ['DISPOSISI SELESAI', $INVALID],
    ['DISPOSISI 2 BAHAS', $INVALID],
    // bukan perintah -> null (abaikan senyap)
    ['', null],
    [null, null],
    ['tolong disposisi surat ini', null],
    ['DISPOSISI XYZ', null],
    ['selesai 2', null],
    ['DISPOSISINYA KERJAKAN', null],
    ['DISPOSISIKAN SEGERA', null],
];
foreach ($cases as [$text, $expect]) {
    check('parse ' . var_export($text, true), Wabot::parseCommand($text), $expect);
}

// ---------- kebijakan role ----------
foreach ([['PIMPINAN', true], ['ADMIN', true], ['STAFF', false], ['WAKIL_KETUA', false], [null, false], ['', false]] as [$role, $expect]) {
    check('role ' . var_export($role, true), Wabot::isAuthorizedRole($role), $expect);
}

// ---------- identifikasi pengirim ----------
check('sender kosong', Wabot::identifySender('', '62812'), null);
check('chat pribadi', Wabot::identifySender('62812@s.whatsapp.net', '62812@s.whatsapp.net'), [
    'isGroup' => false,
    'actor' => '62812@s.whatsapp.net',
    'chatTarget' => '62812@s.whatsapp.net',
]);
check('grup', Wabot::identifySender('1203630123456789@g.us', '62812@s.whatsapp.net'), [
    'isGroup' => true,
    'actor' => '62812@s.whatsapp.net',
    'chatTarget' => '1203630123456789@g.us',
]);
check('grup tanpa @g.us (member beda)', Wabot::identifySender('62811', '62812'), [
    'isGroup' => true,
    'actor' => '62812',
    'chatTarget' => '62811',
]);

// ---------- normalisasi nomor ----------
check('digits jid user', Wabot::digitsOf('628123456789@s.whatsapp.net'), '628123456789');
check('digits jid grup', Wabot::digitsOf('1203630123456789@g.us'), '1203630123456789');
check('digits kosong', Wabot::digitsOf(''), null);
check('norm 08xx', Wabot::normalizeNumber('081234567890'), '6281234567890');
check('norm +62', Wabot::normalizeNumber('+62 812-3456-7890'), '6281234567890');
check('norm 8xx', Wabot::normalizeNumber('81234567890'), '6281234567890');
check('norm 62xx', Wabot::normalizeNumber('6281234567890'), '6281234567890');
check('norm kosong', Wabot::normalizeNumber(''), null);
check('norm null', Wabot::normalizeNumber(null), null);

// ---------- pembangun pesan ----------
$help = Wabot::buildHelpText(['label' => 'nomor ini'], 'Andi');
check('help sapa nama', str_contains($help, 'Andi'), true);
check('help contoh', str_contains($help, 'DISPOSISI 1 SELESAI'), true);
check('help konteks asal', str_contains($help, 'dari nomor ini'), true);
check('list kosong', str_contains(Wabot::buildListText([], 'Andi'), 'Tidak ada'), true);
$items = [
    ['letterSubject' => 'Undangan Rapat', 'status' => 'PENDING', 'deadline' => '2026-09-10 00:00:00', 'instruction' => 'Segera hadir'],
    ['letterSubject' => 'Laporan Bulanan', 'status' => 'PROSES', 'deadline' => null, 'instruction' => ''],
];
$list = Wabot::buildListText($items, 'Andi');
check('list item 1', str_contains($list, '1. Undangan Rapat'), true);
check('list item 2', str_contains($list, '2. Laporan Bulanan'), true);
check('list status', str_contains($list, 'PENDING'), true);
check('list deadline', str_contains($list, '10 September 2026'), true);
$conf = Wabot::buildConfirmation(['letterSubject' => 'Undangan Rapat'], 'SELESAI', 'sudah diarsipkan');
check('konfirmasi status', str_contains($conf, '*SELESAI*'), true);
check('konfirmasi catatan', str_contains($conf, 'sudah diarsipkan'), true);
check('sudah berstatus', str_contains(Wabot::buildAlreadyText(['letterSubject' => 'X', 'status' => 'PROSES'], 'PROSES'), 'PROSES'), true);
check('tidak ketemu', str_contains(Wabot::buildNotFoundText(3, 2), 'nomor 3'), true);
check('tidak ketemu kosong', str_contains(Wabot::buildNotFoundText(1, 0), 'Tidak ada disposisi aktif'), true);
check('invalid', str_contains(Wabot::buildInvalidText(), 'BANTUAN'), true);
check('ditolak', str_contains(Wabot::buildDeniedText('Andi', 'STAFF'), 'STAFF'), true);

// ---------- v2: mulai sesi & balasan sesi ----------
check('konstanta sesi pimpinan = 60 menit (revisi)', Wabot::LEADER_SESSION_MINUTES, 60);
check('konstanta sesi pegawai = 7 hari', Wabot::EMPLOYEE_SESSION_DAYS, 7);
$gs = [
    // mulai sesi
    ['DISPOSISI 12', ['action' => 'START', 'agenda' => 12]],
    ['disposisi 012', ['action' => 'START', 'agenda' => 12]],
    ['*DISPOSISI* 12', ['action' => 'START', 'agenda' => 12]],
    ['DISPOSISI: 12', ['action' => 'START', 'agenda' => 12]],
    ['DISPOSISI 1234', ['action' => 'INVALID_START']],
    ['DISPOSISI 12abc', ['action' => 'INVALID_START']],
    ['DISPOSISI undangan rapat', ['action' => 'KEYWORD', 'keyword' => 'undangan rapat']],
    // tetap jalur v1 (bukan mulai-sesi)
    ['DISPOSISI', null],
    ['DISPOSISI 2 SELESAI', null],
    ['DISPOSISI 2 selesai. sudah diarsipkan', null],
    ['DISPOSISI BANTUAN', null],
    ['DISPOSISI DAFTAR', null],
    ['DISPOSISI PROSES 7', null],
    ['DISPOSISI selesaikan 3 tolong', null],
    ['tolong disposisi 12', null],
    ['', null],
];
foreach ($gs as [$text, $expect]) {
    check('parseGroupStart: ' . var_export($text, true), Wabot::parseGroupStart($text), $expect);
}
$sr = [
    ['1', ['type' => 'CHOICE', 'choice' => 1, 'notes' => null]],
    ['2 sudah diarsipkan', ['type' => 'CHOICE', 'choice' => 2, 'notes' => 'sudah diarsipkan']],
    ['*2*', ['type' => 'CHOICE', 'choice' => 2, 'notes' => null]],
    ['MENU', ['type' => 'REMENU']],
    ['ulang', ['type' => 'REMENU']],
    ['BATAL', ['type' => 'CANCEL']],
    ['cancel', ['type' => 'CANCEL']],
    ['apa kabar', ['type' => 'UNKNOWN']],
    ['', ['type' => 'IGNORE']],
    [null, ['type' => 'IGNORE']],
];
foreach ($sr as [$text, $expect]) {
    check('parseSessionReply: ' . var_export($text, true), Wabot::parseSessionReply($text), $expect);
}
$menu = Wabot::buildTargetMenu('012/UND/2026', 'Undangan Rapat', [['name' => 'Budi', 'role' => 'STAFF'], ['name' => 'Sari']]);
check('menu memuat agenda', str_contains($menu, 'SURAT 012/UND/2026'), true);
check('menu memuat 60 menit', str_contains($menu, '60 menit'), true);
check('menu memuat pegawai', str_contains($menu, '1. Budi') && str_contains($menu, '2. Sari'), true);
$dm = Wabot::buildEmployeeTaskMenu(['subject' => 'Undangan', 'instruction' => 'Hadir', 'deadline' => null], true);
check('dm pegawai menu 1/2', str_contains($dm, '1') && str_contains($dm, '2 [catatan]'), true);
check('dm pegawai tanpa menu mengarah BATAL', str_contains(Wabot::buildEmployeeTaskMenu(['subject' => 'X'], false), 'BATAL'), true);
check('konfirmasi dibuat', str_contains(Wabot::buildDispositionCreatedText('012', 'Undangan', 'Budi', 'Hadir'), 'DISPOSISI DIBUAT'), true);
check('pengumuman grup', str_contains(Wabot::buildGroupNoticeText('Ketua', 'Budi', 'Undangan'), 'didisposisikan'), true);
check('progres PROSES', str_contains(Wabot::buildStatusProgressText('PROSES', null, 'X'), 'PROSES'), true);
check('progres SELESAI', str_contains(Wabot::buildStatusProgressText('SELESAI', 'ok', 'X'), 'SELESAI'), true);
check('sesi ditutup (batal)', str_contains(Wabot::buildSessionClosedText(true, false), 'dibatalkan'), true);
check('sesi ditutup (selesai)', str_contains(Wabot::buildSessionClosedText(false, true), 'ditutup'), true);
check('teks expired', str_contains(Wabot::buildExpiredText(), 'berakhir'), true);
check('teks balasan tak dikenal', str_contains(Wabot::buildUnknownReplyText(), 'MENU'), true);
check('teks start tak valid', str_contains(Wabot::buildInvalidStartText(), 'nomor agenda'), true);
check('teks agenda tak ketemu', str_contains(Wabot::buildAgendaNotFoundText('12'), '12'), true);
check('teks ambigu', str_contains(Wabot::buildAmbiguousText('rapat', 3), '3'), true);
check('teks pilihan tak ada', str_contains(Wabot::buildInvalidChoiceText(5, 2), '1-2'), true);
check('teks tanpa kandidat pegawai', str_contains(Wabot::buildNoTargetText(), 'aplikasi web'), true);
check('teks target tak tersedia', str_contains(Wabot::buildTargetUnavailableText('Budi'), 'Budi'), true);
check('petunjuk menu utk role non-v1', str_contains(Wabot::buildEmployeeMenuHintText(), '2 [catatan]'), true);

check('newExpiry pimpinan ~60 menit', abs(strtotime(Wabot::newExpiry(true)) - time() - 3600) < 5, true);
check('newExpiry pegawai ~7 hari', abs(strtotime(Wabot::newExpiry(false)) - time() - 7 * 86400) < 5, true);
check('isExpired null', Wabot::isExpired(null), true);
check('isExpired masa lalu', Wabot::isExpired('2020-01-01 00:00:00'), true);
check('isExpired masa depan', Wabot::isExpired('2999-01-01 00:00:00'), false);
check('cleanText buang formatting', Wabot::cleanText('*DISPOSISI* 12'), 'DISPOSISI 12');

// ---------- util ----------
check('truncate memotong', Wabot::truncate('abcdefghij', 5), 'abcde…');
check('truncate pas', Wabot::truncate('abc', 5), 'abc');
check('truncate kosong', Wabot::truncate('', 5), '-');
check('truncate null', Wabot::truncate(null, 5), '-');
check('formatDate', Wabot::formatDate('2026-09-09 00:00:00'), '9 September 2026');
check('formatDate null', Wabot::formatDate(null), '—');

echo "Wabot: {$pass} PASS, {$fail} FAIL\n";
foreach ($failures as $f) {
    echo "  [GAGAL] {$f}\n";
}
exit($fail === 0 ? 0 : 1);
