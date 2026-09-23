<?php
// Pemeriksaan mandiri Auth: token opak (disimpan sebagai hash), user nonaktif,
// pembatasan brute force login, kebijakan password, dan token tautan HMAC.
// Jalankan: php api-php/tests/run_auth_check.php
// Exit code 0 = semua pass; 1 = ada kegagalan.
// TANPA PHPUnit/Composer/MySQL: memakai SQLite in-memory.

// Db palsu: Auth hanya memanggil Db::generateId() saat mencatat kegagalan login.
class Db
{
    public static int $seq = 0;
    public static function generateId(): string
    {
        return 'la' . (++self::$seq);
    }
}

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

// Skrip ini ikut di-deploy; batasi ke CLI agar tidak bisa dipicu lewat browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Skrip ini hanya untuk CLI.\n";
    exit(1);
}

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

function checkTrue(string $label, bool $actual): void
{
    check($label, $actual, true);
}

// Skema minimum. login_attempts mengikuti migrations/2026_09_21_*.sql;
// created_at sengaja TANPA default supaya terbukti Auth mengisinya sendiri.
function authTestDb(bool $withLoginAttempts = true): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("CREATE TABLE users (id TEXT PRIMARY KEY, username TEXT, password TEXT,
        name TEXT, role TEXT, avatar TEXT, is_active INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE auth_tokens (token TEXT PRIMARY KEY, user_id TEXT, expires_at TEXT)");
    if ($withLoginAttempts) {
        $db->exec("CREATE TABLE login_attempts (id TEXT PRIMARY KEY, username TEXT,
            ip_address TEXT, success INTEGER DEFAULT 0, created_at TEXT)");
    }
    return $db;
}

function addUser(PDO $db, string $id, string $username, string $password, int $active = 1, string $role = 'STAFF'): void
{
    $db->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?, NULL, ?)")
       ->execute([$id, $username, password_hash($password, PASSWORD_BCRYPT), $username, $role, $active]);
}

// Turnstile sengaja dimatikan: yang diuji di sini bukan gerbang Turnstile.
function attemptLogin(PDO $db, string $username, string $password): array
{
    try {
        $res = Auth::login($db, $username, $password, null, ['turnstile_enabled' => false]);
        return ['status' => 200, 'token' => $res['token'], 'message' => ''];
    } catch (AuthException $e) {
        return ['status' => $e->status, 'token' => null, 'message' => $e->getMessage()];
    }
}

function attemptAuth(PDO $db, string $header): array
{
    try {
        return ['status' => 200, 'user' => Auth::requireAuth($db, $header)];
    } catch (AuthException $e) {
        return ['status' => $e->status, 'user' => null];
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---------- token: yang disimpan adalah hash, bukan token mentah ----------
$db = authTestDb();
addUser($db, 'u1', 'budi', 'rahasia123');
$r = attemptLogin($db, 'budi', 'rahasia123');
check('login sukses', $r['status'], 200);
checkTrue('token berbentuk 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', (string) $r['token']));
$stored = (string) $db->query("SELECT token FROM auth_tokens")->fetchColumn();
check('yang tersimpan = sha256(token)', $stored, hash('sha256', (string) $r['token']));
checkTrue('token mentah TIDAK tersimpan', $stored !== $r['token']);

$a = attemptAuth($db, 'Bearer ' . $r['token']);
check('requireAuth token sah', $a['status'], 200);
check('requireAuth id user', $a['user']['id'] ?? null, 'u1');
check('requireAuth tidak membocorkan expires_at', array_key_exists('expires_at', (array) $a['user']), false);
check('requireAuth tanpa skema Bearer -> 401', attemptAuth($db, (string) $r['token'])['status'], 401);
check('requireAuth header kosong -> 401', attemptAuth($db, '')['status'], 401);

// logout menghapus baris berdasarkan HASH token.
Auth::logout($db, (string) $r['token']);
check('logout menghapus token', (int) $db->query("SELECT COUNT(*) FROM auth_tokens")->fetchColumn(), 0);
check('token setelah logout -> 401', attemptAuth($db, 'Bearer ' . $r['token'])['status'], 401);

// ---------- token kedaluwarsa ----------
$db = authTestDb();
addUser($db, 'u2', 'budi', 'rahasia123');
$db->prepare("INSERT INTO auth_tokens VALUES (?, 'u2', ?)")
   ->execute([hash('sha256', 'expiredtoken'), date('Y-m-d H:i:s', time() - 10)]);
check('token kedaluwarsa -> 401', attemptAuth($db, 'Bearer expiredtoken')['status'], 401);

// ---------- user dinonaktifkan langsung kehilangan akses ----------
$db = authTestDb();
addUser($db, 'u3', 'siti', 'rahasia123');
$r = attemptLogin($db, 'siti', 'rahasia123');
check('login siti', $r['status'], 200);
check('token siti masih sah', attemptAuth($db, 'Bearer ' . $r['token'])['status'], 200);
$db->exec("UPDATE users SET is_active = 0 WHERE id = 'u3'");
check('user dinonaktifkan -> 401 walau token belum kedaluwarsa',
    attemptAuth($db, 'Bearer ' . $r['token'])['status'], 401);
check('token tidak dihapus, hanya tidak berlaku',
    (int) $db->query("SELECT COUNT(*) FROM auth_tokens")->fetchColumn(), 1);

// ---------- pesan 401 seragam (tidak bisa dipakai menebak username) ----------
$db = authTestDb();
addUser($db, 'u4', 'budi', 'rahasia123');
addUser($db, 'u5', 'nonaktif', 'rahasia123', 0);
$msgs = [];
foreach ([['tidakada', 'x'], ['budi', 'salah'], ['nonaktif', 'rahasia123']] as [$u, $p]) {
    $msgs[] = attemptLogin($db, $u, $p)['message'];
}
check('pesan 401 seragam untuk 3 penyebab', count(array_unique($msgs)), 1);
check('pesan 401 = "Username atau password salah."', $msgs[0], 'Username atau password salah.');
check('login user nonaktif -> 401', attemptLogin($db, 'nonaktif', 'rahasia123')['status'], 401);

// ---------- pembatasan brute force: 5 gagal / 15 menit per username+IP ----------
$db = authTestDb();
addUser($db, 'u6', 'budi', 'rahasia123');
for ($i = 1; $i <= 5; $i++) {
    check("percobaan gagal #$i -> 401", attemptLogin($db, 'budi', 'salah')['status'], 401);
}
check('percobaan ke-6 -> 429', attemptLogin($db, 'budi', 'salah')['status'], 429);
check('password BENAR saat terkunci -> 429', attemptLogin($db, 'budi', 'rahasia123')['status'], 429);
check('username lain tidak ikut terkunci', attemptLogin($db, 'tidakada', 'x')['status'], 401);

// Username yang tidak ada juga dihitung, jadi tidak bisa dipakai membedakan akun.
$db = authTestDb();
for ($i = 1; $i <= 5; $i++) { attemptLogin($db, 'hantu', 'x'); }
check('username tidak ada ikut dibatasi', attemptLogin($db, 'hantu', 'x')['status'], 429);

// Login berhasil mereset penghitung username+IP itu.
$db = authTestDb();
addUser($db, 'u7', 'budi', 'rahasia123');
for ($i = 1; $i <= 4; $i++) { attemptLogin($db, 'budi', 'salah'); }
check('gagal 4x lalu sukses -> 200', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);
check('penghitung gagal direset',
    (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE success = 0")->fetchColumn(), 0);
check('kegagalan yang tercatat punya created_at',
    (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE created_at IS NULL")->fetchColumn(), 0);

// Kegagalan di luar jendela 15 menit tidak lagi dihitung.
$db = authTestDb();
addUser($db, 'u8', 'budi', 'rahasia123');
for ($i = 1; $i <= 5; $i++) {
    $db->prepare("INSERT INTO login_attempts VALUES (?, 'budi', '203.0.113.7', 0, ?)")
       ->execute(['old' . $i, date('Y-m-d H:i:s', time() - 16 * 60)]);
}
check('kegagalan >15 menit lalu -> tidak terkunci', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);

// IP berbeda tidak saling mengunci.
$db = authTestDb();
addUser($db, 'u9', 'budi', 'rahasia123');
for ($i = 1; $i <= 5; $i++) { attemptLogin($db, 'budi', 'salah'); }
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check('IP lain tidak terkunci', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---------- tabel login_attempts belum ada (migration belum dijalankan) ----------
$db = authTestDb(false);
addUser($db, 'u10', 'budi', 'rahasia123');
check('tanpa tabel login_attempts -> login tetap 200', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);
for ($i = 1; $i <= 6; $i++) { attemptLogin($db, 'budi', 'salah'); }
check('tanpa tabel login_attempts -> tidak ada 429 blokir', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);

// ---------- kebijakan password ----------
$pwCases = [
    ['', 'Password minimal 8 karakter.'],
    ['1234567', 'Password minimal 8 karakter.'],
    ['12345678', null],
    ['rahasia123', null],
    [str_repeat('a', 200), null],
];
foreach ($pwCases as [$pw, $expected]) {
    check('validatePassword [' . strlen($pw) . ' karakter]', validatePassword($pw), $expected);
}
check('validatePassword [null]', validatePassword(null), 'Password minimal 8 karakter.');

// ---------- hash bcrypt cost lama di-upgrade diam-diam saat login ----------
$db = authTestDb();
$db->prepare("INSERT INTO users VALUES ('u11', 'budi', ?, 'Budi', 'STAFF', NULL, 1)")
   ->execute([password_hash('rahasia123', PASSWORD_BCRYPT, ['cost' => 4])]);
$before = (string) $db->query("SELECT password FROM users WHERE id = 'u11'")->fetchColumn();
checkTrue('hash cost lama memang perlu rehash', password_needs_rehash($before, PASSWORD_BCRYPT));
check('login dengan hash cost lama', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);
$after = (string) $db->query("SELECT password FROM users WHERE id = 'u11'")->fetchColumn();
checkTrue('hash diganti hash baru', $after !== $before);
checkTrue('password tetap bisa diverifikasi', password_verify('rahasia123', $after));
checkTrue('hash baru tidak perlu rehash lagi', !password_needs_rehash($after, PASSWORD_BCRYPT));

// ---------- token kedaluwarsa dipangkas saat login berhasil ----------
$db = authTestDb();
addUser($db, 'u12', 'budi', 'rahasia123');
$db->prepare("INSERT INTO auth_tokens VALUES (?, 'u12', ?)")
   ->execute([hash('sha256', 'basi'), date('Y-m-d H:i:s', time() - 60)]);
$db->prepare("INSERT INTO auth_tokens VALUES (?, 'u12', ?)")
   ->execute([hash('sha256', 'masihhidup'), date('Y-m-d H:i:s', time() + 3600)]);
check('login sukses (prune)', attemptLogin($db, 'budi', 'rahasia123')['status'], 200);
check('token kedaluwarsa dipangkas',
    (int) $db->query("SELECT COUNT(*) FROM auth_tokens WHERE token = '" . hash('sha256', 'basi') . "'")->fetchColumn(), 0);
check('token yang masih hidup dipertahankan',
    (int) $db->query("SELECT COUNT(*) FROM auth_tokens WHERE token = '" . hash('sha256', 'masihhidup') . "'")->fetchColumn(), 1);

// ---------- token tautan lampiran (HMAC, view-only) ----------
if (appSecret() === '') {
    echo "SKIP cek token tautan: app_secret / turnstile_secret_key belum diisi di config.php\n";
} else {
    $t = letterViewToken('surat-1');
    checkTrue('letterViewToken berformat <exp>.<sig>', (bool) preg_match('/^\d+\.[0-9a-f]{32}$/', $t));
    check('token berlaku untuk surat-nya', letterViewTokenValid('surat-1', $t), true);
    check('token TIDAK berlaku untuk surat lain', letterViewTokenValid('surat-2', $t), false);
    check('token kosong ditolak', letterViewTokenValid('surat-1', null), false);
    check('token tanpa titik ditolak', letterViewTokenValid('surat-1', 'ngawur'), false);
    check('token kedaluwarsa ditolak', letterViewTokenValid('surat-1', (time() - 10) . '.deadbeef'), false);
    check('ttl negatif ditolak', letterViewTokenValid('surat-1', letterViewToken('surat-1', -10)), false);
    check('signature salah ditolak',
        letterViewTokenValid('surat-1', (time() + 60) . '.' . str_repeat('a', 32)), false);
}

// ---------- ringkasan ----------
echo "Auth check: $pass pass, $fail fail\n";
foreach ($failures as $f) {
    echo "  FAIL: $f\n";
}
exit($fail === 0 ? 0 : 1);

