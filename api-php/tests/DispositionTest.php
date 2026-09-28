<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Disposition.php';
require_once __DIR__ . '/../lib/Wabot.php';
require_once __DIR__ . '/../lib/Whatsapp.php';
require_once __DIR__ . '/WaStub.php';

final class DispositionTest extends TestCase
{
    use WaStub;

    /** Setiap Whatsapp::$sender dipanggil dicatat di sini. */
    private array $sends = [];

    /**
     * Stub Fonnte dipasang ulang sebelum setiap uji: properti statis bisa sudah
     * dinullkan uji lain, dan uji ini TIDAK boleh menembak WA sungguhan.
     */
    protected function setUp(): void
    {
        $this->stubWhatsapp();
    }

    /** Aturan hierarki: siapa boleh disposisi ke siapa. */
    public function testHierarchyTable(): void
    {
        // [fromRole, toSupervisorId, fromId, bolehDiizinkan]
        $cases = [
            ['ADMIN', null, 'u1', true], // admin bebas
            ['PIMPINAN', 'x', 'u1', true], // pimpinan bebas
            ['STAFF', 'u1', 'u1', true], // penerima bawahan langsung
            ['STAFF', 'u2', 'u1', false], // bukan bawahan
            ['STAFF', null, 'u1', false], // penerima tak punya atasan
            ['SEKRETARIS', 'u1', 'u1', true],
            ['SEKRETARIS', 'u9', 'u1', false],
        ];
        foreach ($cases as [$role, $sup, $from, $expect]) {
            $this->assertSame($expect, Disposition::canDispose($role, $sup, $from),
                "role=$role sup=" . var_export($sup, true) . " from=$from");
        }
    }

    /** Transisi status: notifikasi mana yang dibuat + apakah kirim WA. */
    public function testStatusTransitionTable(): void
    {
        // [status, expectedTitle|null, kirimWA]
        $cases = [
            ['PENDING', null, false],
            ['PROSES', 'Disposisi Sedang Diproses', true],
            ['SELESAI', 'Disposisi Selesai', true],
        ];
        foreach ($cases as [$status, $title, $wa]) {
            $this->assertSame($title, Disposition::statusNotificationTitle($status), "title for $status");
            $this->assertSame($wa, Disposition::notifiesWhatsapp($status), "wa for $status");
        }
    }

    private function waDb(int $enabled = 1): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE whatsapp_settings (id TEXT PRIMARY KEY, group_target TEXT, fonnte_token TEXT, is_enabled INTEGER)");
        $db->exec("CREATE TABLE activity_logs (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36), action VARCHAR(100), entity VARCHAR(100), entity_id VARCHAR(36), details TEXT, ip_address VARCHAR(64), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        // Tabel users dipakai DM terusan status ke pimpinan (notifyLeadersStatus).
        $db->exec("CREATE TABLE users (id TEXT PRIMARY KEY, name TEXT, role TEXT, wa_number TEXT, is_active INTEGER)");
        $db->prepare("INSERT INTO whatsapp_settings VALUES ('wa_settings', 'GRP', 'TOKEN', ?)")->execute([$enabled]);
        $ins = $db->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?)");
        $ins->execute(['lead1', 'Pimpinan Satu', 'PIMPINAN', '08111', 1]);
        $ins->execute(['lead2', 'Pimpinan Dua', 'PIMPINAN', '08222', 1]);
        $ins->execute(['leadNoNum', 'Pimpinan Tanpa Nomor', 'PIMPINAN', null, 1]);
        $ins->execute(['leadOff', 'Pimpinan Nonaktif', 'PIMPINAN', '08333', 0]);
        $ins->execute(['emp1', 'Pegawai Satu', 'STAFF', '08444', 1]);
        Db::$pdo = $db;
        return $db;
    }

    /** Pasang stub pengirim Fonnte — semua panggilan tercatat di $this->sends. */
    private function captureSender(): void
    {
        $this->sends = [];
        Whatsapp::$sender = function ($token, $target, $message) {
            $this->sends[] = compact('token', 'target', 'message');
        };
    }

    /** Fonnte distub — assert payload (token/target/message) benar, tanpa hit API. */
    public function testFonnteNewDispositionPayload(): void
    {
        $this->waDb();
        $this->captureSender();
        Whatsapp::notifyNewDisposition(Db::$pdo, [
            'fromName' => 'Budi',
            'toName' => 'Andi',
            'subject' => 'Surat Uji',
            'instruction' => 'Segera tindaklanjuti',
            'deadline' => null,
            'actorUserId' => 'actor1',
        ]);
        $this->stubWhatsapp();

        $this->assertCount(1, $this->sends, 'disposisi baru: hanya pengumuman grup');
        $captured = $this->sends[0];
        $this->assertSame('TOKEN', $captured['token']);
        $this->assertSame('GRP', $captured['target']);
        $this->assertStringContainsString('DISPOSISI BARU', $captured['message']);
        $this->assertStringContainsString('Kepada: Andi', $captured['message']);
        $this->assertStringContainsString('Dari: Budi', $captured['message']);

        $logs = Db::all("SELECT * FROM activity_logs WHERE action = 'WHATSAPP_SENT' AND user_id = 'actor1'");
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Kirim WhatsApp ke GRP', $logs[0]['details']);
    }

    /** Status PROSES/SELESAI: DM tiap PIMPINAN aktif bernomor WA + grup terakhir. */
    public function testFonnteStatusPayload(): void
    {
        $this->waDb();
        $this->captureSender();
        Whatsapp::notifyDispositionStatus(Db::$pdo, [
            'fromName' => 'Budi',
            'workerName' => 'Siti',
            'subject' => 'Surat Uji',
            'status' => 'SELESAI',
            'notes' => 'beres',
            'actorUserId' => 'actor2',
        ]);
        $this->stubWhatsapp();

        // leadNoNum (tanpa nomor) + leadOff (nonaktif) tersaring query; grup terakhir.
        $targets = array_column($this->sends, 'target');
        $this->assertSame(['628111', '628222', 'GRP'], $targets);
        foreach ($this->sends as $s) {
            $this->assertStringContainsString('DISPOSISI SELESAI', $s['message']);
            $this->assertStringContainsString('Dari: Budi', $s['message']);
            $this->assertStringContainsString('Dikerjakan oleh: Siti', $s['message']);
            $this->assertStringContainsString('Catatan: beres', $s['message']);
        }

        $logs = Db::all("SELECT * FROM activity_logs WHERE action = 'WHATSAPP_SENT' AND user_id = 'actor2'");
        $this->assertCount(3, $logs);
    }

    /** Pimpinan yang mengubah status tidak dikirimi DM atas aksinya sendiri. */
    public function testStatusDmSkipsActorLeader(): void
    {
        $this->waDb();
        $this->captureSender();
        Whatsapp::notifyDispositionStatus(Db::$pdo, [
            'fromName' => 'Budi',
            'workerName' => 'Pimpinan Satu',
            'subject' => 'Surat Uji',
            'status' => 'PROSES',
            'notes' => null,
            'actorUserId' => 'lead1',
        ]);
        $this->stubWhatsapp();

        $targets = array_column($this->sends, 'target');
        $this->assertSame(['628222', 'GRP'], $targets);
    }

    /** isEnabled=0 -> tidak mengirim apa pun. */
    public function testDisabledSkipsSend(): void
    {
        $this->waDb(0);
        $this->captureSender();
        Whatsapp::notifyNewDisposition(Db::$pdo, [
            'fromName' => 'Budi', 'toName' => 'Andi', 'subject' => 'x', 'instruction' => 'y', 'deadline' => null, 'actorUserId' => 'actor3',
        ]);
        Whatsapp::notifyDispositionStatus(Db::$pdo, [
            'fromName' => 'Budi', 'workerName' => 'Siti', 'subject' => 'x', 'status' => 'SELESAI', 'notes' => null, 'actorUserId' => 'actor3',
        ]);
        $this->stubWhatsapp();
        $this->assertSame([], $this->sends, 'notifikasi nonaktif tak boleh kirim');
        $logs = Db::all("SELECT * FROM activity_logs WHERE action = 'WHATSAPP_SENT' AND user_id = 'actor3'");
        $this->assertCount(0, $logs);
    }

}
