<?php
// Pemeriksaan mandiri validasi Turnstile pada login — TANPA PHPUnit/Composer/MySQL.
// Jalankan: php api-php/tests/run_turnstile_check.php
// Exit code 0 = semua pass; 1 = ada kegagalan.
// Semua kasus di bawah tidak menyentuh jaringan: jalur yang diuji hanya yang
// berhenti sebelum request ke Cloudflare.

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

// str_contains() baru ada di PHP 8; hosting bisa masih 7.4.
function memuat(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

// SQLite in-memory: cukup tabel users, tanpa server MySQL.
function turnstileTestDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("CREATE TABLE users (id TEXT PRIMARY KEY, username TEXT, password TEXT,
        name TEXT, role TEXT, avatar TEXT, is_active INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE auth_tokens (token TEXT PRIMARY KEY, user_id TEXT, expires_at TEXT)");
    $hash = password_hash('rahasia', PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO users VALUES ('u1','budi',?,'Budi','STAFF',NULL,1)")->execute([$hash]);
    return $db;
}

// Jalankan Auth::login dan tangkap AuthException -> ['status'=>int, 'message'=>string].
function attemptLogin(array $config, ?string $token = 'tok'): array
{
    try {
        Auth::login(turnstileTestDb(), 'budi', 'rahasia', $token, $config);
        return ['status' => 200, 'message' => 'login berhasil'];
    } catch (AuthException $e) {
        return ['status' => $e->status, 'message' => $e->getMessage()];
    }
}

// ---------- turnstileParse: tabel kasus ----------
$parseCases = [
    // [label, raw JSON, success, error]
    ['sukses', '{"success":true}', true, ''],
    ['gagal satu kode', '{"success":false,"error-codes":["invalid-input-response"]}', false, 'invalid-input-response'],
    ['gagal dua kode', '{"success":false,"error-codes":["a","b"]}', false, 'a, b'],
    ['gagal tanpa kode', '{"success":false}', false, ''],
    ['sukses string bukan bool', '{"success":"true"}', false, ''],
    ['bukan json', 'bukan-json', false, 'respons bukan JSON: bukan-json'],
    ['json array', '[]', false, ''],
];

foreach ($parseCases as [$label, $raw, $expectedSuccess, $expectedError]) {
    $res = turnstileParse($raw);
    check("turnstileParse [$label] success", $res['success'], $expectedSuccess);
    check("turnstileParse [$label] error", $res['error'], $expectedError);
}

// ---------- turnstileSiteverify: penjaga input (tanpa jaringan) ----------
$guardCases = [
    ['secret kosong', '', 'tok'],
    ['token kosong', 'sec', ''],
    ['keduanya kosong', '', ''],
];

foreach ($guardCases as [$label, $secret, $token]) {
    $res = turnstileSiteverify($secret, $token);
    check("turnstileSiteverify [$label] success", $res['success'], false);
    check("turnstileSiteverify [$label] error", $res['error'], 'secret key atau token kosong');
}

check('verifyTurnstile [secret kosong]', verifyTurnstile('', 'tok'), false);
check('verifyTurnstile [token kosong]', verifyTurnstile('sec', ''), false);

// ---------- Auth::login: gerbang Turnstile ----------
$enabled = ['turnstile_enabled' => true, 'turnstile_secret_key' => 'dummy-secret'];

$r = attemptLogin(['turnstile_enabled' => true, 'turnstile_secret_key' => '']);
check('login [secret kosong] status', $r['status'], 500);
checkTrue('login [secret kosong] pesan', memuat($r['message'], 'secret key kosong'));

$r = attemptLogin($enabled, '');
check('login [token kosong] status', $r['status'], 400);
check('login [token kosong] pesan', $r['message'], 'Verifikasi keamanan gagal. Silakan coba lagi.');

$r = attemptLogin($enabled + ['turnstile_debug' => true], '');
check('login [debug] status', $r['status'], 400);
checkTrue('login [debug] pesan memuat detail', memuat($r['message'], '[secret key atau token kosong]'));

// Turnstile mati -> kredensial benar tetap bisa login (tanpa jaringan).
$r = attemptLogin(['turnstile_enabled' => false]);
check('login [turnstile mati] status', $r['status'], 200);

// Pemanggil lama yang tidak mengirim config tetap kompatibel.
$r = attemptLogin([], null);
check('login [tanpa config] status', $r['status'], 200);

// Password salah tetap ditolak walau Turnstile mati.
try {
    Auth::login(turnstileTestDb(), 'budi', 'salah', null, ['turnstile_enabled' => false]);
    check('login [password salah] status', 200, 401);
} catch (AuthException $e) {
    check('login [password salah] status', $e->status, 401);
}

// ---------- ringkasan ----------
echo "Turnstile check: $pass pass, $fail fail\n";
foreach ($failures as $f) {
    echo "  FAIL: $f\n";
}
exit($fail === 0 ? 0 : 1);
