<?php
// Diagnostik kredensial database — TANPA PHPUnit/Composer.
// Jalankan di server: php api/tests/check_db.php
// Berguna saat API membalas 500 / "Koneksi database gagal" atau login selalu
// gagal karena user tidak ada. Exit code 0 = siap dipakai; 1 = ada masalah.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Skrip ini hanya untuk CLI.\n";
    exit(1);
}

$fail = 0;

function line(string $label, string $value): void
{
    printf("%-28s: %s\n", $label, $value);
}

// Terjemahkan pesan error MySQL -> langkah perbaikan konkret. Tanpa ini, pesan
// seperti "Access denied" ambigu: bisa password salah, bisa user belum diberi
// akses ke database. Padahal perbaikannya di menu cPanel yang berbeda.
function hint(string $message): void
{
    // 'could not find driver' BUKAN soal kredensial: ekstensi pdo_mysql belum
    // aktif, jadi user/password belum sempat diuji. Dicek lebih dulu karena
    // kodenya 0 dan tidak akan cocok dengan tabel kode di bawah.
    if (stripos($message, 'could not find driver') !== false) {
        echo "        Penyebab : Ekstensi pdo_mysql TIDAK AKTIF. Kredensial belum diuji.\n";
        echo "        Perbaikan: cPanel -> Select PHP Version -> Extensions -> centang pdo_mysql,\n";
        echo "                   lalu ulangi skrip ini. Ini BUKAN masalah db_user/db_pass.\n";
        return;
    }

    // 'using password: NO' = password tidak terkirim sama sekali (db_pass kosong
    // atau tanda kutipnya rusak). Dicek SEBELUM tabel kode: kalau tidak, entri
    // '1045' akan menang duluan dan menyarankan "Change Password" yang keliru.
    if (strpos($message, 'using password: NO') !== false) {
        echo "        Penyebab : Password TIDAK terkirim — db_pass di config.php kosong\n";
        echo "                   atau tanda kutipnya rusak.\n";
        echo "        Perbaikan: isi db_pass, pastikan dibungkus tanda kutip tunggal.\n";
        return;
    }

    $map = [
        '1045' => [
            'Password atau nama user salah, ATAU user belum dibuat.',
            'cPanel -> MySQL Databases -> Current Users -> Change Password.',
        ],
        '1044' => [
            'User & password BENAR, tapi user belum diberi akses ke database ini.',
            'cPanel -> MySQL Databases -> "Add User To Database" -> pilih user + db -> ALL PRIVILEGES -> Make Changes.',
        ],
        '1049' => [
            'Database dengan nama itu tidak ada.',
            'Lihat "Database yang terlihat" di Uji 3, lalu samakan db_name dengan nama di situ.',
        ],
        '2002' => [
            'db_host tidak bisa dihubungi.',
            'Di cPanel hampir selalu "localhost". Kalau sudah localhost, hubungi hosting.',
        ],
        '2005' => [
            'Nama host MySQL tidak dikenal.',
            'Ganti db_host menjadi "localhost".',
        ],
        '2054' => [
            'Plugin autentikasi tidak cocok.',
            'Set ulang password user di cPanel -> MySQL Databases -> Change Password.',
        ],
    ];

    foreach ($map as $code => [$penyebab, $perbaikan]) {
        if (strpos($message, '[' . $code . ']') !== false || strpos($message, $code) !== false) {
            echo "        Penyebab : $penyebab\n";
            echo "        Perbaikan: $perbaikan\n";
            return;
        }
    }

    echo "        Perbaikan: lihat cPanel -> MySQL Databases (nama DB, user, dan\n";
    echo "                   apakah user sudah di-Add User To Database).\n";
}

echo "=== Diagnostik Database ===\n";

line('PHP version', PHP_VERSION);
line('Ekstensi pdo_mysql', extension_loaded('pdo_mysql') ? 'aktif' : 'TIDAK AKTIF');
if (!extension_loaded('pdo_mysql')) {
    echo "\n[GAGAL] Ekstensi pdo_mysql tidak aktif. Aktifkan lewat cPanel -> Select PHP Version.\n";
    exit(1);
}

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    echo "\n[GAGAL] api/config.php tidak ditemukan. Salin dari api/config.example.php.\n";
    exit(1);
}

$config = require $configPath;

// Nilai ditampilkan sebagian saja; password hanya status terisi/kosong.
$pass = (string) ($config['db_pass'] ?? '');
line('db_host', (string) ($config['db_host'] ?? 'KOSONG'));
line('db_name', (string) ($config['db_name'] ?? 'KOSONG'));
line('db_user', (string) ($config['db_user'] ?? 'KOSONG'));
line('db_pass', $pass === '' ? 'KOSONG' : 'terisi (' . strlen($pass) . ' char)');

// Kredensial lokal (root tanpa password) hampir pasti salah di shared hosting.
// Hanya peringatan — verdict tetap ditentukan hasil uji koneksi di bawah.
if (($config['db_user'] ?? '') === 'root' && $pass === '') {
    echo "\n[PERINGATAN] db_user 'root' + password kosong = kredensial lokal.\n";
    echo "             Di shared hosting pakai user/db berprefix akun cPanel, mis. k9030796_simars.\n";
}

$pdo = null;

// Uji 1: persis seperti bootstrap.php (host + dbname).
echo "\n--- Uji 1: koneksi lengkap (host + dbname) ---\n";
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "[OK] Koneksi database berhasil.\n";
} catch (PDOException $e) {
    echo '[GAGAL] ' . $e->getMessage() . "\n";
    hint($e->getMessage());
    $fail++;
}

// Uji 2 & 3 hanya jalan bila Uji 1 gagal. Tujuannya memisahkan dua masalah yang
// pesan errornya sama-sama "Access denied" tapi perbaikannya beda di cPanel:
// password salah, versus user belum di-Add User To Database.
if ($pdo === null) {
    echo "\n--- Uji 2: koneksi tanpa dbname (uji user + password saja) ---\n";
    try {
        $probe = new PDO(
            "mysql:host={$config['db_host']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "[OK] Nama user & password BENAR.\n";
        echo "     Berarti masalahnya di db_name, atau user belum diberi akses ke database itu.\n";

        echo "\n--- Uji 3: database yang terlihat oleh user ini ---\n";
        $rows = $probe->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $db) {
            echo '  ' . $db . ($db === (string) ($config['db_name'] ?? '') ? '   <-- cocok dengan db_name' : '') . "\n";
        }
        if (!in_array((string) ($config['db_name'] ?? ''), $rows, true)) {
            echo "\n[GAGAL] db_name '{$config['db_name']}' tidak ada di daftar di atas.\n";
            echo "        Samakan db_name dengan salah satu nama di atas, atau minta hosting membuatnya.\n";
        } else {
            echo "\n[GAGAL] Database ada dan terlihat, tapi koneksi ditolak.\n";
            echo "        cPanel -> MySQL Databases -> \"Add User To Database\" ->\n";
            echo "        pilih '{$config['db_user']}' + '{$config['db_name']}' -> ALL PRIVILEGES -> Make Changes.\n";
        }
    } catch (PDOException $e) {
        echo '[GAGAL] ' . $e->getMessage() . "\n";
        hint($e->getMessage());
        echo "        Uji 2 gagal = user/password/host yang salah, bukan soal izin database.\n";
    }
    exit(1);
}

$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
line('Jumlah tabel', (string) $tables);
if ($tables === 0) {
    echo "\n[GAGAL] Database kosong. Impor api/schema.sql lebih dulu.\n";
    exit(1);
}

$hasUsers = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
if ($hasUsers === 0) {
    echo "\n[GAGAL] Tabel 'users' tidak ada. Impor api/schema.sql lebih dulu.\n";
    exit(1);
}

$total  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$active = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
line('User total', (string) $total);
line('User aktif', (string) $active);
if ($active === 0) {
    echo "\n[GAGAL] Tidak ada user aktif — login pasti ditolak.\n";
    $fail++;
}

echo "\n" . ($fail === 0 ? "SEMUA OK\n" : "ADA $fail MASALAH\n");
exit($fail === 0 ? 0 : 1);