<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Whatsapp.php';

final class IncomingLetterNotificationTest extends TestCase
{
    private function setupDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE users (id VARCHAR(36) PRIMARY KEY, name VARCHAR(255), role VARCHAR(50), is_active INTEGER DEFAULT 1, wa_number VARCHAR(50), supervisor_id VARCHAR(36))");
        $db->exec("CREATE TABLE notifications (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36), title VARCHAR(255), message TEXT, link VARCHAR(255), is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE whatsapp_settings (id TEXT PRIMARY KEY, group_target TEXT, fonnte_token TEXT, is_enabled INTEGER)");
        $db->exec("CREATE TABLE activity_logs (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36), action VARCHAR(100), entity VARCHAR(100), entity_id VARCHAR(36), details TEXT, ip_address VARCHAR(64), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->prepare("INSERT INTO whatsapp_settings VALUES ('wa_settings', 'GRP', 'TOKEN', 1)")->execute();
        Db::$pdo = $db;
        return $db;
    }

    private function notifyLeaders(array $letter): void
    {
        $leaders = Db::all("SELECT id FROM users WHERE role = 'PIMPINAN' AND is_active = 1");
        $shortSubject = substr($letter['subject'], 0, 100);
        foreach ($leaders as $leader) {
            Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)", [
                Db::generateId(), $leader['id'], 'Surat Masuk Baru',
                "Surat masuk dari {$letter['sender']} perihal \"{$shortSubject}\" memerlukan disposisi.", '/surat-masuk'
            ]);
        }
        Whatsapp::notifyNewIncomingLetter(Db::$pdo, array_merge($letter, ['actorUserId' => 'admin1']));
    }

    public function testCreatesNotificationsForActiveLeaders(): void
    {
        $db = $this->setupDb();
        $db->prepare("INSERT INTO users VALUES ('u1','Ketua','PIMPINAN',1,null,null)")->execute();
        $db->prepare("INSERT INTO users VALUES ('u2','Wakil','PIMPINAN',1,null,null)")->execute();
        $db->prepare("INSERT INTO users VALUES ('u3','Staff','STAFF',1,null,null)")->execute();
        $this->notifyLeaders(['agendaNumber'=>'AGD/2026/001','letterNumber'=>'10/T/2026','sender'=>'PTA','subject'=>'Undangan','receivedDate'=>'2026-07-26']);
        $rows = Db::all("SELECT * FROM notifications ORDER BY user_id");
        $this->assertCount(2, $rows);
        $this->assertSame('u1', $rows[0]['userId']);
        $this->assertSame('Surat Masuk Baru', $rows[0]['title']);
        $this->assertSame('/surat-masuk', $rows[0]['link']);
        $this->assertStringContainsString('Undangan', $rows[0]['message']);
    }

    public function testInactiveLeadersAreExcluded(): void
    {
        $db = $this->setupDb();
        $db->prepare("INSERT INTO users VALUES ('u1','Ketua Aktif','PIMPINAN',1,null,null)")->execute();
        $db->prepare("INSERT INTO users VALUES ('u2','Ketua Nonaktif','PIMPINAN',0,null,null)")->execute();
        $this->notifyLeaders(['agendaNumber'=>'AGD/2026/002','letterNumber'=>'11/T/2026','sender'=>'PA','subject'=>'Data','receivedDate'=>'2026-07-26']);
        $rows = Db::all("SELECT * FROM notifications");
        $this->assertCount(1, $rows);
        $this->assertSame('u1', $rows[0]['userId']);
    }

    public function testFonnteNewIncomingLetterPayload(): void
    {
        $this->setupDb();
        $db = Db::$pdo;
        $db->prepare("INSERT INTO users VALUES ('u1','Ketua','PIMPINAN',1,null,null)")->execute();
        $captured = null;
        Whatsapp::$sender = function ($token, $target, $message) use (&$captured) {
            $captured = compact('token', 'target', 'message');
        };
        $this->notifyLeaders(['agendaNumber'=>'AGD/2026/003','letterNumber'=>'12/T/2026','sender'=>'Kemenag','subject'=>'Edaran','receivedDate'=>'2026-07-26']);
        Whatsapp::$sender = null;
        $this->assertNotNull($captured);
        $this->assertSame('TOKEN', $captured['token']);
        $this->assertSame('GRP', $captured['target']);
        $this->assertStringContainsString('SURAT MASUK BARU', $captured['message']);
        $this->assertStringContainsString('Nomor Agenda: AGD/2026/003', $captured['message']);
        $this->assertStringContainsString('Pengirim: Kemenag', $captured['message']);

        $logs = Db::all("SELECT * FROM activity_logs WHERE action = 'WHATSAPP_SENT' AND user_id = 'admin1'");
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Kirim WhatsApp ke GRP', $logs[0]['details']);
    }

    public function testDisabledWhatsappSkipsSend(): void
    {
        $db = $this->setupDb();
        $db->prepare("UPDATE whatsapp_settings SET is_enabled = 0 WHERE id = 'wa_settings'")->execute();
        $db->prepare("INSERT INTO users VALUES ('u1','Ketua','PIMPINAN',1,null,null)")->execute();
        $called = false;
        Whatsapp::$sender = function () use (&$called) { $called = true; };
        $this->notifyLeaders(['agendaNumber'=>'AGD/2026/004','letterNumber'=>'13/T/2026','sender'=>'BKN','subject'=>'Kepegawaian','receivedDate'=>'2026-07-26']);
        Whatsapp::$sender = null;
        $this->assertFalse($called);
        $logs = Db::all("SELECT * FROM activity_logs WHERE action = 'WHATSAPP_SENT' AND user_id = 'admin1'");
        $this->assertCount(0, $logs);
    }
}
