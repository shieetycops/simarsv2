<?php
// Diagnostik Cloudflare Turnstile — TANPA PHPUnit/Composer/DB.
// Jalankan di server: php api/tests/check_turnstile.php
// Berguna untuk memastikan cURL/SSL aktif dan secret key benar saat login
// selalu gagal dengan "Verifikasi keamanan gagal".
// Exit code 0 = siap dipakai; 1 = ada masalah.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Skrip ini hanya untuk CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../lib/helpers.php';

$config = require __DIR__ . '/../config.php';
$fail = 0;

function line(string $label, string $value): void
{
    printf("%-28s: %s\n", $label, $value);
}

echo "=== Diagnostik Turnstile ===\n";

line('PHP version', PHP_VERSION);
line('Ekstensi cURL', function_exists('curl_init') ? 'aktif' : 'TIDAK AKTIF');
line('allow_url_fopen', ini_get('allow_url_fopen') ? 'aktif' : 'mati');
line('OpenSSL', extension_loaded('openssl') ? 'aktif' : 'TIDAK AKTIF');
line('turnstile_enabled', var_export($config['turnstile_enabled'] ?? null, true));

$secret = (string) ($config['turnstile_secret_key'] ?? '');
line('Secret key', $secret === '' ? 'KOSONG' : substr($secret, 0, 12) . '... (' . strlen($secret) . ' char)');

if ($secret === '') {
    echo "\n[GAGAL] turnstile_secret_key belum diisi di config.php.\n";
    exit(1);
}
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    echo "\n[GAGAL] Tidak ada jalur HTTP keluar: aktifkan cURL atau allow_url_fopen.\n";
    $fail++;
}

// Token dummy: Cloudflare pasti menolak, tapi balasan JSON-nya membuktikan
// koneksi + SSL + parsing berjalan. error-codes "invalid-input-response"
// berarti jalur komunikasi sehat.
echo "\n--- Uji koneksi ke challenges.cloudflare.com ---\n";
$result = turnstileSiteverify($secret, 'dummy-token-diagnostik');

if ($result['error'] === 'invalid-input-response') {
    echo "[OK] Koneksi ke Cloudflare sehat (token dummy ditolak seperti seharusnya).\n";
} elseif ($result['error'] !== '') {
    echo "[GAGAL] " . $result['error'] . "\n";
    $fail++;
} else {
    echo "[?] Balasan tak terduga (success=" . var_export($result['success'], true) . ").\n";
    $fail++;
}

echo "\n" . ($fail === 0 ? "SEMUA OK\n" : "ADA $fail MASALAH\n");
exit($fail === 0 ? 0 : 1);
