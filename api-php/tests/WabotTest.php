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

    /** Fase 2: role yang boleh bertindak dari menu tahap WA. */
    public function testStageActorRoles(): void
    {
        // P8 (revisi kedua): ARSIPARIS kini pemegang MENUNGGU_PENGARSIPAN,
        // jadi ia ikut aktor sesi aksi tahap (ARCHIVE).
        foreach (['ADMIN', 'KEPALA_SUB_UMUM', 'SEKRETARIS', 'PANITERA', 'ARSIPARIS'] as $boleh) {
            $this->assertTrue(Wabot::isStageActorRole($boleh), "role $boleh harus boleh bertindak dari WA");
        }
        // Pimpinan hanya DIBERI TAHU surat yang butuh kebijakannya; perintah
        // "teruskan" tetap milik Sekretaris/Panitera (SOP/AS/04 langkah 15-16).
        foreach (['PIMPINAN', 'WAKIL_KETUA', 'STAFF', 'KEPALA_SUB_PTIP', '', null] as $tidak) {
            $this->assertFalse(Wabot::isStageActorRole($tidak), 'role ' . var_export($tidak, true) . ' bukan aktor tahap');
        }
        // Perintah teks v1 TIDAK diperluas: daftar lama harus tetap utuh.
        $this->assertSame(['PIMPINAN', 'ADMIN'], Wabot::ALLOWED_ROLES);
    }

    /** Fase 2: sesi aksi tahap berlaku 72 jam (Wabot::STAGE_SESSION_HOURS). */
    public function testStageSessionExpiry(): void
    {
        $this->assertSame(72, Wabot::STAGE_SESSION_HOURS);
        $expiry = Wabot::newStageExpiry();
        $this->assertFalse(Wabot::isExpired($expiry));
        $delta = strtotime($expiry) - time();
        $this->assertGreaterThan(72 * 3600 - 60, $delta);
        $this->assertLessThanOrEqual(72 * 3600, $delta);
    }

    /** Fase 2: rute keputusan boleh ditulis menyatu dengan nomor pilihan. */
    public function testParseRouteChoice(): void
    {
        $this->assertSame(['KEBIJAKAN', null], Wabot::parseRouteChoice('KEBIJAKAN'));
        $this->assertSame(['LANGSUNG', 'untuk Subbag Kepegawaian'], Wabot::parseRouteChoice('langsung untuk Subbag Kepegawaian'));
        // Kata pertama bukan rute -> seluruhnya catatan biasa.
        $this->assertSame([null, 'Surat untuk Subbag Kepegawaian'], Wabot::parseRouteChoice('Surat untuk Subbag Kepegawaian'));
        $this->assertSame([null, null], Wabot::parseRouteChoice('   '));
        $this->assertSame([null, null], Wabot::parseRouteChoice(null));
    }

    /**
     * P4 (revisi kedua): penanda unit eksplisit #KODE_UNIT pada ARAHAN.
     * Menggantikan tebakan "kata terakhir mirip kode unit".
     */
    public function testParseArahanUnit(): void
    {
        // Penanda sah -> unit terbaca, token dibuang dari isi. (cleanText membuang
        // '_' penanda italic WA, kode unit digabung ulang otomatis.)
        $this->assertSame(['KASUBAG_UMUM', 'setujui dan proses sesuai jadwal', null],
            Wabot::parseArahanUnit('setujui dan proses sesuai jadwal #KASUBAG_UMUM'));
        $this->assertSame(['PANMUD_PERMOHONAN', 'setujui', null],
            Wabot::parseArahanUnit('setujui #PANMUD_PERMOHONAN'));
        // Tanpa penanda -> unit null, teks utuh (tidak ada penetapan diam-diam).
        $this->assertSame([null, 'setujui dan proses ke KASUBAG UMUM segera', null],
            Wabot::parseArahanUnit('setujui dan proses ke KASUBAG_UMUM segera'));
        // Typo kode unit -> error, data tidak diubah.
        $this->assertSame([null, 'setujui #KASUBAG UMU', 'UNIT_INVALID'],
            Wabot::parseArahanUnit('setujui #KASUBAG_UMU'));
        // Dua penanda -> error.
        $this->assertSame([null, 'a #KASUBAG UMUM b #PANMUD HUKUM', 'UNIT_INVALID'],
            Wabot::parseArahanUnit('a #KASUBAG_UMUM b #PANMUD_HUKUM'));
        // Penanda kosong "#" -> kandidat kosong = tidak dikenal -> error.
        $this->assertSame([null, 'setujui #', 'UNIT_INVALID'], Wabot::parseArahanUnit('setujui #'));
        $this->assertSame([null, '', null], Wabot::parseArahanUnit('   '));
    }

    /** P4: susun ulang kode unit dari token (underscore dibuang cleanText). */
    public function testResolveUnitFromTokens(): void
    {
        $this->assertSame(['KASUBAG_UMUM', 2], Wabot::resolveUnitFromTokens(['KASUBAG', 'UMUM', 'untuk', 'kepegawaian']));
        $this->assertSame(['KASUBAG_KEPEGAWAIAN', 2], Wabot::resolveUnitFromTokens(['KASUBAG', 'KEPEGAWAIAN']));
        $this->assertSame(['PANMUD_HUKUM', 2], Wabot::resolveUnitFromTokens(['PANMUD', 'HUKUM']));
        $this->assertSame([null, 0], Wabot::resolveUnitFromTokens(['mohon', 'segera']));
        $this->assertSame([null, 0], Wabot::resolveUnitFromTokens(['KASUBAG', 'UMU']));
    }

    /** Fase 2: notifikasi tahap — perihal surat RAHASIA tidak boleh bocor. */
    public function testStageNoticeText(): void
    {
        $biasa = Wabot::buildStageNoticeText([
            'name' => 'Budi', 'agendaNumber' => 'AGD/2026/012', 'subject' => 'Undangan Rapat Koordinasi',
            'toStage' => 'DITERUSKAN_KE_KASUBAG', 'actorName' => 'Sari Sekretaris',
            'sensitive' => false, 'withMenu' => true,
        ]);
        $this->assertStringContainsString('SURAT MASUK KE MEJA', $biasa);
        $this->assertStringContainsString('Di Kasubag Umum', $biasa);
        $this->assertStringContainsString('AGD/2026/012', $biasa);
        $this->assertStringContainsString('Undangan Rapat Koordinasi', $biasa);
        $this->assertStringContainsString('Sari Sekretaris', $biasa);
        $this->assertStringContainsString('Menu tindakan dikirim', $biasa);

        $rahasia = Wabot::buildStageNoticeText([
            'agendaNumber' => 'AGD/2026/013', 'subject' => 'Hasil Pemeriksaan Inspektorat',
            'toStage' => 'MENUNGGU_PENGARSIPAN', 'sensitive' => true, 'withMenu' => false,
        ]);
        $this->assertStringNotContainsString('Hasil Pemeriksaan Inspektorat', $rahasia);
        $this->assertStringContainsString('RAHASIA', $rahasia);
        $this->assertStringContainsString('aplikasi web', $rahasia);

        $tarik = Wabot::buildStageNoticeText(['toStage' => 'DITERUSKAN_KE_KASUBAG', 'reopened' => true]);
        $this->assertStringContainsString('DITARIK KEMBALI', $tarik);
    }

    /** Fix 2: perintah kata kunci WA — selalu pakai agenda, satu arti. */
    public function testParseKeywordCommand(): void
    {
        $this->assertSame(
            ['keyword' => 'PROSES', 'agenda' => 'AGD/2026/001', 'rest' => 'siap dikerjakan', 'error' => null],
            Wabot::parseKeywordCommand('PROSES AGD/2026/001 siap dikerjakan'));
        $this->assertSame(
            ['keyword' => 'SELESAI', 'agenda' => 'AGD/2026/001', 'rest' => '', 'error' => null],
            Wabot::parseKeywordCommand('selesai AGD/2026/001'));
        $this->assertSame(
            ['keyword' => 'TOLAK', 'agenda' => 'AGD/2026/001', 'rest' => 'alamat tidak sesuai', 'error' => null],
            Wabot::parseKeywordCommand('*TOLAK* AGD/2026/001 alamat tidak sesuai'));
        // Tanpa agenda -> error AGENDA_REQUIRED, bukan null (tetap perintah kata kunci).
        $r = Wabot::parseKeywordCommand('PROSES');
        $this->assertSame('PROSES', $r['keyword']);
        $this->assertSame('AGENDA_REQUIRED', $r['error']);
        // Bukan kata kunci -> null.
        $this->assertNull(Wabot::parseKeywordCommand('halo apa kabar'));
        $this->assertNull(Wabot::parseKeywordCommand('DISPOSISI 12'));
        $this->assertNull(Wabot::parseKeywordCommand(null));
    }

    /** Fix 2: teks bantuan & error kata kunci menyebut agenda + unit sah. */
    public function testKeywordHelpAndError(): void
    {
        $help = Wabot::buildKeywordHelpText();
        foreach (['TERIMA', 'TOLAK', 'KEBIJAKAN', 'LANGSUNG', 'ARAHAN', 'TERUSKAN', 'TUNJUK', 'PROSES', 'SELESAI', 'ARSIP'] as $k) {
            $this->assertStringContainsString($k, $help, "bantuan memuat $k");
        }
        $this->assertStringContainsString('KASUBAG_UMUM', $help);
        $this->assertStringContainsString('tidak diubah', Wabot::buildKeywordErrorText('AGENDA_NOT_YOURS', 'AGD/1'));
        $this->assertStringContainsString('tidak diubah', Wabot::buildKeywordErrorText('AGENDA_REQUIRED'));
    }

    /** Fase 2: menu aksi bernomor + konfirmasi hasil aksi. */
    public function testStageTaskMenuAndApplied(): void
    {
        $task = [
            'letterId' => 'ltr-1', 'agenda' => 'AGD/2026/012', 'subject' => 'Undangan Rapat Koordinasi',
            'stage' => 'DITERUSKAN_KE_KASUBAG', 'sensitive' => false,
            'options' => [
                ['n' => 1, 'toStage' => 'DITERUSKAN_KE_PELAKSANA', 'label' => 'Teruskan ke pelaksana', 'requiresRoute' => false],
                ['n' => 2, 'toStage' => 'MENUNGGU_KEBIJAKAN_PIMPINAN', 'label' => 'Naikkan ke pimpinan', 'requiresRoute' => false],
            ],
        ];
        $menu = Wabot::buildStageTaskMenu($task, 'Budi Kasubag', 2);
        $this->assertStringContainsString('TINDAK LANJUT SURAT', $menu);
        $this->assertStringContainsString('Undangan Rapat Koordinasi', $menu);
        $this->assertStringContainsString('Di Kasubag Umum', $menu);
        // P1 (revisi kedua): default balasan angka NONAKTIF (angka tidak terikat
        // agenda) -> menu tanpa "balas nomornya", opsi tampil sebagai daftar.
        $this->assertStringContainsString('Teruskan ke pelaksana', $menu);
        $this->assertStringContainsString('Naikkan ke pimpinan', $menu);
        $this->assertStringNotContainsString('balas nomornya', $menu, 'P1: angka menu nonaktif secara default');
        $this->assertStringContainsString('kata kunci', $menu);
        $this->assertStringContainsString('AGD/2026/012', $menu);
        $this->assertStringContainsString('2 tugas lain', $menu);
        $this->assertStringNotContainsString('KEPUTUSAN', $menu, 'menu tanpa rute tak menyebut keputusan');

        // Pilihan yang wajib rute harus menjelaskan cara menulisnya.
        $task['options'][1]['requiresRoute'] = true;
        $menuRute = Wabot::buildStageTaskMenu($task, 'Sari Sekretaris');
        $this->assertStringContainsString('KEPUTUSAN', $menuRute);
        $this->assertStringContainsString('KEBIJAKAN', $menuRute);

        // P1: teks penolakan balasan angka tersedia.
        $this->assertStringContainsString('dinonaktifkan', Wabot::buildStageNumberDisabledText());
        $this->assertFalse(Wabot::stageNumberReplyEnabled());

        $applied = Wabot::buildStageTaskApplied($task, 'DITERUSKAN_KE_PELAKSANA', 'KEBIJAKAN', false);
        $this->assertStringContainsString('TINDAKAN TERSIMPAN', $applied);
        $this->assertStringContainsString('Diteruskan ke pelaksana', $applied);
        $this->assertStringContainsString('Perlu kebijakan pimpinan', $applied);
        $this->assertStringContainsString('notifikasi WhatsApp', $applied);
        $this->assertStringContainsString('Ada tugas berikutnya',
            Wabot::buildStageTaskApplied($task, 'DIARSIPKAN', null, true));
        $this->assertStringContainsString('Masih ada *2* tugas lain',
            Wabot::buildStageTaskApplied($task, 'DIARSIPKAN', null, false, 2));

        // T9 (revisi kedua): perihal RAHASIA/SANGAT_RAHASIA tidak ikut balasan
        // bot — hanya agenda yang disebut (KMA 131 BAB V).
        $taskRahasia = array_merge($task, ['sensitive' => true]);
        $appliedR = Wabot::buildStageTaskApplied($taskRahasia, 'MENUNGGU_PENGARSIPAN', null, false);
        $this->assertStringNotContainsString('Undangan Rapat Koordinasi', $appliedR);
        $this->assertStringContainsString('[RAHASIA] agenda AGD/2026/012', $appliedR);
        $menuR = Wabot::buildStageTaskMenu($taskRahasia, 'Sari Sekretaris');
        $this->assertStringNotContainsString('Undangan Rapat Koordinasi', $menuR);
        $this->assertStringContainsString('[RAHASIA] agenda AGD/2026/012', $menuR);

        $this->assertStringContainsString('MENU', Wabot::buildStageTaskFailed('Tahap sudah berubah.'));
        $this->assertStringContainsString('Tidak ada tugas surat', Wabot::buildNoStageTaskText('Budi'));
        $this->assertStringContainsString('Atasan Langsung', Wabot::buildNoSubordinateTargetText());
        $this->assertSame(2, Wabot::pendingTaskCount([['a' => 1], ['b' => 2], ['c' => 3]]));
        $this->assertSame(0, Wabot::pendingTaskCount([]));
    }

}

