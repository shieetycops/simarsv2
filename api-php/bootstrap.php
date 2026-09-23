<?php
// Koneksi DB, header, autoload lib/. Di-require oleh index.php.

$configPath = __DIR__ . '/config.php';

// config.php hilang -> balas JSON yang jelas, bukan fatal error berbadan kosong.
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'api/config.php tidak ditemukan. Salin api/config.example.php lalu isi kredensial hosting.']);
    exit;
}

$config = require $configPath;

if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'api/config.php harus mengembalikan array konfigurasi.']);
    exit;
}

// Polyfill PHP 8 (str_contains/str_starts_with/str_ends_with) untuk hosting yang
// masih PHP 7.4. Wajib di-require paling awal: lib/ dan handler mana pun memakainya.
require_once __DIR__ . '/lib/compat.php';

// Autoload semua lib/ (Db, Auth, dst) sebelum dipakai.
foreach (glob(__DIR__ . '/lib/*.php') as $file) {
    require_once $file;
}

// CORS + JSON.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($config['cors_origin'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Header keamanan dasar. API ini hanya mengembalikan JSON (tidak di-embed di
// iframe, tidak memuat resource eksternal), jadi semuanya bisa dikunci ketat.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// HSTS hanya saat request memang sudah HTTPS (jangan dikirim di http://localhost).
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Preflight selesai di sini.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Koneksi PDO. Dibungkus try/catch: kegagalan DB wajib jadi JSON 500 yang bisa
// dibaca frontend, bukan PHP Fatal error berbadan kosong — penyebab login
// tampak "500 tanpa pesan" dan sulit dilacak. Password tidak pernah ditampilkan.
try {
    Db::$pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    $message = 'Koneksi database gagal. Periksa db_host/db_name/db_user/db_pass di api/config.php.';
    // 'could not find driver' (kode 0) BUKAN soal kredensial: ekstensi pdo_mysql
    // belum aktif, jadi user/password belum sempat diuji sama sekali. Pesan
    // generik di atas menyesatkan untuk kasus ini, jadi diganti penyebab aslinya.
    if (stripos($e->getMessage(), 'could not find driver') !== false) {
        $message = 'Ekstensi pdo_mysql tidak aktif di server, jadi koneksi database belum '
                 . 'bisa dibuat. Aktifkan lewat cPanel -> Select PHP Version -> Extensions '
                 . '-> centang pdo_mysql. Ini BUKAN masalah db_user/db_pass.';
    }
    // 'debug' => true di config.php menyertakan detail driver (tanpa password).
    if (!empty($config['debug'])) {
        $message .= ' [' . $e->getCode() . '] ' . $e->getMessage()
                  . ' (user: ' . $config['db_user'] . ', db: ' . $config['db_name']
                  . ', host: ' . $config['db_host'] . ')';
    }
    echo json_encode(['message' => $message]);
    exit;
}
