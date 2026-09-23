<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Wabot.php';

final class WabotTest extends TestCase
{
    /** Tabel perintah WA: [teks, hasil parser]. */
    public function testParseCommandTable(): void
    {
        $LIST = ['action' => 'LIST'];
        $HELP = ['action' => 'HELP'];
        $INVALID = ['action' => 'INVALID'];
        $cases = [
            ['DISPOSISI', $LIST],
            ['*DISPOSISI*', $LIST],
            ['DISPOSISI DAFTAR', $LIST],
            ['DISPOSISI BANTUAN', $HELP],
            ['DISPOSISI ?', $HELP],
            ['DISPOSISI 2 SELESAI', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
            ['*DISPOSISI* 1 SELESAI', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 1, 'notes' => null]],
            ['DISPOSISI 12 PROSES', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 12, 'notes' => null]],
            ['DISPOSISI 1 belum selesai', ['action' => 'UPDATE', 'status' => 'PROSES', 'ordinal' => 1, 'notes' => null]],
            ['DISPOSISI 2 selesai. sudah diarsipkan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => 'sudah diarsipkan']],
            ['DISPOSISI SELESAI 2', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 2, 'notes' => null]],
            ['DISPOSISI selesaikan 3 tolong diarsipkan', ['action' => 'UPDATE', 'status' => 'SELESAI', 'ordinal' => 3, 'notes' => 'tolong diarsipkan']],
            ['DISPOSISI 2', $INVALID],
            ['DISPOSISI 0 SELESAI', $INVALID],
            ['DISPOSISI SELESAI', $INVALID],
            ['DISPOSISI 2 BAHAS', $INVALID],
            ['', null],
            [null, null],
            ['tolong disposisi surat ini', null],
            ['DISPOSISI XYZ', null],
            ['selesai 2', null],
            ['DISPOSISINYA KERJAKAN', null],
        ];
        foreach ($cases as [$text, $expect]) {
            $this->assertSame($expect, Wabot::parseCommand($text), 'teks: ' . var_export($text, true));
        }
    }

    /** Kebijakan: hanya PIMPINAN/ADMIN. */
    public function testRoleGate(): void
    {
        $this->assertTrue(Wabot::isAuthorizedRole('PIMPINAN'));
        $this->assertTrue(Wabot::isAuthorizedRole('ADMIN'));
        $this->assertFalse(Wabot::isAuthorizedRole('STAFF'));
        $this->assertFalse(Wabot::isAuthorizedRole('WAKIL_KETUA'));
        $this->assertFalse(Wabot::isAuthorizedRole(null));
    }

    /** Grup vs pribadi sesuai kontrak webhook Fonnte. */
    public function testIdentifySender(): void
    {
        $this->assertNull(Wabot::identifySender('', '62812'));
        $this->assertSame(
            ['isGroup' => false, 'actor' => '62812@s.whatsapp.net', 'chatTarget' => '62812@s.whatsapp.net'],
            Wabot::identifySender('62812@s.whatsapp.net', '62812@s.whatsapp.net')
        );
        $this->assertSame(
            ['isGroup' => true, 'actor' => '62812@s.whatsapp.net', 'chatTarget' => '1203630123456789@g.us'],
            Wabot::identifySender('1203630123456789@g.us', '62812@s.whatsapp.net')
        );
    }

    /** 08xx -> 62xx, dst (port Whatsapp::normalizePhone). */
    public function testNumberNormalization(): void
    {
        $this->assertSame('628123456789', Wabot::digitsOf('628123456789@s.whatsapp.net'));
        $this->assertNull(Wabot::digitsOf(''));
        $this->assertSame('6281234567890', Wabot::normalizeNumber('081234567890'));
        $this->assertSame('6281234567890', Wabot::normalizeNumber('+62 812-3456-7890'));
        $this->assertSame('6281234567890', Wabot::normalizeNumber('6281234567890'));
        $this->assertNull(Wabot::normalizeNumber(null));
    }

    /** Pesan balasan memuat informasi kunci. */
    public function testMessageBuilders(): void
    {
        $help = Wabot::buildHelpText(['label' => 'nomor ini'], 'Andi');
        $this->assertStringContainsString('Andi', $help);
        $this->assertStringContainsString('DISPOSISI 1 SELESAI', $help);
        $this->assertStringContainsString('dari nomor ini', $help);

        $items = [
            ['letterSubject' => 'Undangan Rapat', 'status' => 'PENDING', 'deadline' => '2026-09-10 00:00:00', 'instruction' => 'Segera hadir'],
            ['letterSubject' => 'Laporan Bulanan', 'status' => 'PROSES', 'deadline' => null, 'instruction' => ''],
        ];
        $list = Wabot::buildListText($items, 'Andi');
        $this->assertStringContainsString('1. Undangan Rapat', $list);
        $this->assertStringContainsString('2. Laporan Bulanan', $list);
        $this->assertStringContainsString('10 September 2026', $list);
        $this->assertStringContainsString('Tidak ada', Wabot::buildListText([], 'Andi'));

        $conf = Wabot::buildConfirmation(['letterSubject' => 'Undangan Rapat'], 'SELESAI', 'sudah diarsipkan');
        $this->assertStringContainsString('*SELESAI*', $conf);
        $this->assertStringContainsString('sudah diarsipkan', $conf);
        $this->assertStringContainsString('nomor 3', Wabot::buildNotFoundText(3, 2));
        $this->assertStringContainsString('BANTUAN', Wabot::buildInvalidText());
        $this->assertStringContainsString('STAFF', Wabot::buildDeniedText('Andi', 'STAFF'));
    }

    /** Util kecil. */
    public function testTruncateAndDate(): void
    {
        $this->assertSame('abcde…', Wabot::truncate('abcdefghij', 5));
        $this->assertSame('abc', Wabot::truncate('abc', 5));
        $this->assertSame('-', Wabot::truncate('', 5));
        $this->assertSame('9 September 2026', Wabot::formatDate('2026-09-09 00:00:00'));
        $this->assertSame('—', Wabot::formatDate(null));
    }

/** v2: mulai sesi "buat disposisi" & balasan sesi bernomor. */
public function testGroupStartAndSessionReply(): void
{
$this->assertSame(['action' => 'START', 'agenda' => 12], Wabot::parseGroupStart('DISPOSISI 12'));
$this->assertSame(['action' => 'START', 'agenda' => 12], Wabot::parseGroupStart('disposisi 012'));
$this->assertSame(['action' => 'INVALID_START'], Wabot::parseGroupStart('DISPOSISI 1234'));
$this->assertSame(
['action' => 'KEYWORD', 'keyword' => 'undangan rapat'],
Wabot::parseGroupStart('DISPOSISI undangan rapat')
);
// Jalur v1 tetap di luar mulai-sesi.
$this->assertNull(Wabot::parseGroupStart('DISPOSISI'));
$this->assertNull(Wabot::parseGroupStart('DISPOSISI 2 SELESAI'));
$this->assertNull(Wabot::parseGroupStart('DISPOSISI BANTUAN'));
$this->assertNull(Wabot::parseGroupStart('DISPOSISI DAFTAR'));
$this->assertNull(Wabot::parseGroupStart('DISPOSISI selesaikan 3 tolong'));
$this->assertNull(Wabot::parseGroupStart('tolong disposisi 12'));

$this->assertSame(['type' => 'CHOICE', 'choice' => 2, 'notes' => 'sudah diarsipkan'], Wabot::parseSessionReply('2 sudah diarsipkan'));
$this->assertSame(['type' => 'CHOICE', 'choice' => 1, 'notes' => null], Wabot::parseSessionReply('1'));
$this->assertSame(['type' => 'REMENU'], Wabot::parseSessionReply('MENU'));
$this->assertSame(['type' => 'CANCEL'], Wabot::parseSessionReply('BATAL'));
$this->assertSame(['type' => 'UNKNOWN'], Wabot::parseSessionReply('apa kabar'));
$this->assertSame(['type' => 'IGNORE'], Wabot::parseSessionReply(''));
}

/** v2: durasi sesi (revisi 60 menit) & pembangun pesan sesi. */
public function testSessionConstantsAndBuilders(): void
{
$this->assertSame(60, Wabot::LEADER_SESSION_MINUTES);
$this->assertSame(7, Wabot::EMPLOYEE_SESSION_DAYS);

$menu = Wabot::buildTargetMenu('012/UND/2026', 'Undangan Rapat', [['name' => 'Budi', 'role' => 'STAFF']]);
$this->assertStringContainsString('SURAT 012/UND/2026', $menu);
$this->assertStringContainsString('60 menit', $menu);
$this->assertStringContainsString('1. Budi', $menu);

$this->assertStringContainsString('1-2', Wabot::buildInvalidChoiceText(5, 2));
$this->assertStringContainsString('nomor agenda', Wabot::buildInvalidStartText());
$this->assertStringContainsString('BATAL', Wabot::buildEmployeeTaskMenu(['subject' => 'X'], false));
$this->assertStringContainsString('aplikasi web', Wabot::buildNoTargetText());
$this->assertStringContainsString('Budi', Wabot::buildTargetUnavailableText('Budi'));
$this->assertStringContainsString('SELESAI', Wabot::buildEmployeeMenuHintText());

$this->assertStringContainsString('DISPOSISI DIBUAT', Wabot::buildDispositionCreatedText('012', 'Undangan', 'Budi', 'Hadir'));
$this->assertStringContainsString('didisposisikan', Wabot::buildGroupNoticeText('Ketua', 'Budi', 'Undangan'));
$this->assertStringContainsString('berakhir', Wabot::buildExpiredText());
}
}
