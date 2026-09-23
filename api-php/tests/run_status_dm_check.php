<?php
// tests/run_status_dm_check.php — cekWhatsapp::notifyDispositionStatus:
// pesan DISPOSISI PROSES/SELESAI kini (a) DM ke tiap PIMPINAN aktif bernomor
// WA, (b) tetap diumumkan ke grup. Jalankan: php tests/run_status_dm_check.php
//
// Whatsapp ASLI dijalankan; Db dipalsukan (fixture per pola SQL) supaya tidak
// butuh MySQL/sqlite; kirim Fonnte distub lewat Whatsapp::$sender.

$root = dirname(__DIR__);
require_once $root . '/lib/Wabot.php';

class Db
{
    public static array $settings = [];
    public static array $leaders = [];

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $s = strtolower(preg_replace('/\s+/', ' ', trim($sql)));
        if (str_contains($s, 'whatsapp_settings')) return [self::$settings];
        if (str_contains($s, "from users where role = 'pimpinan'")) return self::$leaders;
        return [];
    }

    public static function q(string $sql, array $params = []): object
    {
        return new class {
            public function fetch(): ?array { return null; }
        };
    }

    public static function generateId(): string
    {
        return 'gen';
    }
}

require_once $root . '/lib/Whatsapp.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

// Stub pengirim: catat [token, target, message]; bisa diset meledak per target.
$sends = [];
$boomTarget = null;
Whatsapp::$sender = function ($token, $target, $message) use (&$sends, &$boomTarget) {
    if ($boomTarget !== null && $target === $boomTarget) {
        throw new RuntimeException('stub gagal: ' . $target);
    }
    $sends[] = ['token' => $token, 'target' => $target, 'message' => $message];
};

$pdo = (new ReflectionClass('PDO'))->newInstanceWithoutConstructor();

$enabled = ['id' => 'wa_settings', 'isEnabled' => 1, 'fonnteToken' => 'tok', 'groupTarget' => '120363@g.us'];
$leaders = [
    ['id' => 'lead1', 'waNumber' => '08111000'],   // -> 628111000
    ['id' => 'lead2', 'waNumber' => '+62 822-2000'], // -> 628222000
    ['id' => 'lead3', 'waNumber' => null],          // tanpa nomor: lewati
];

function statusArgs(string $status, string $actor, ?string $notes = null): array
{
    return [
        'fromName' => 'Budi',
        'workerName' => 'Siti',
        'subject' => 'Surat Uji',
        'status' => $status,
        'notes' => $notes,
        'actorUserId' => $actor,
    ];
}

// --- Kasus 1: pegawai update PROSES -> 2 DM pimpinan + 1 grup, DM lebih dulu.
Db::$settings = $enabled;
Db::$leaders = $leaders;
$sends = [];
Whatsapp::notifyDispositionStatus($pdo, statusArgs('PROSES', 'emp1'));
check('kirim 3x (2 DM pimpinan + grup)', count($sends) === 3);
check('DM pimpinan #1 = 628111000', ($sends[0]['target'] ?? '') === '628111000');
check('DM pimpinan #2 = 628222000', ($sends[1]['target'] ?? '') === '628222000');
check('grup dikirim terakhir', ($sends[2]['target'] ?? '') === '120363@g.us');
check('isi DM: label PROSES', str_contains($sends[0]['message'], 'DISPOSISI PROSES'));
check('isi DM: worker + perihal', str_contains($sends[0]['message'], 'Dikerjakan oleh: Siti')
    && str_contains($sends[0]['message'], 'Perihal: Surat Uji'));
check('pesan DM identik dgn grup', $sends[0]['message'] === $sends[2]['message']);

// --- Kasus 2: actor sendiri pimpinan -> DM ke dirinya dilewati.
$sends = [];
Whatsapp::notifyDispositionStatus($pdo, statusArgs('PROSES', 'lead1'));
check('actor pimpinan tak dapat DM sendiri', count($sends) === 2
    && $sends[0]['target'] === '628222000' && $sends[1]['target'] === '120363@g.us');

// --- Kasus 3: grup kosong -> DM pimpinan TETAP jalan (dulu: langsung return).
Db::$settings = array_merge($enabled, ['groupTarget' => '']);
$sends = [];
Whatsapp::notifyDispositionStatus($pdo, statusArgs('SELESAI', 'emp1', 'beres'));
check('grup kosong: 2 DM tetap terkirim', count($sends) === 2
    && $sends[0]['target'] === '628111000' && $sends[1]['target'] === '628222000');
check('SELESAI + catatan ikut ke DM', str_contains($sends[0]['message'], 'DISPOSISI SELESAI')
    && str_contains($sends[0]['message'], 'Catatan: beres'));
Db::$settings = $enabled;

// --- Kasus 4: WA nonaktif -> tidak ada apa pun.
Db::$settings = array_merge($enabled, ['isEnabled' => 0]);
$sends = [];
Whatsapp::notifyDispositionStatus($pdo, statusArgs('SELESAI', 'emp1'));
check('isEnabled=0 -> nol kirim', $sends === []);
Db::$settings = $enabled;

// --- Kasus 5: DM pertama meledak -> DM kedua + grup tetap jalan (containment).
$sends = [];
$boomTarget = '628111000';
Whatsapp::notifyDispositionStatus($pdo, statusArgs('PROSES', 'emp1'));
$boomTarget = null;
check('gagal 1 DM tidak blokir sisanya', count($sends) === 2
    && $sends[0]['target'] === '628222000' && $sends[1]['target'] === '120363@g.us');

// --- Kasus 6: fromName kosong -> skip total (perilaku lama dipertahankan).
$sends = [];
Whatsapp::notifyDispositionStatus($pdo, ['workerName' => 'Siti', 'subject' => 'x', 'status' => 'PROSES']);
check('fromName kosong -> nol kirim', $sends === []);

echo "\n" . ($fail === 0
    ? "SEMUA LULUS ($pass cek)\n"
    : "$fail GAGAL dari " . ($pass + $fail) . " cek\n");
exit($fail === 0 ? 0 : 1);
