<?php
// Diagnostik lingkungan PHP — mencari sebab "could not find driver".
//
// Cara utama (CLI):
//     php api/tests/check_php.php
//
// Kalau tidak ada akses CLI: unggah berkas ini ke public_html/simars/
// (DI LUAR folder api/, sebab api/.htaccess memblokir tests/), buka di browser,
// lalu HAPUS kembali setelah selesai.
//
// "could not find driver" = PDO tidak menemukan driver mysql, artinya ekstensi
// pdo_mysql tidak dimuat oleh PHP yang sedang melayani request ini. Skrip ini
// menunjukkan versi PHP mana yang melayani, ekstensi apa saja yang dimuat, dan
// dari php.ini mana — supaya ketahuan kenapa pdo_mysql tidak ada.
//
// Sebab tersering: ekstensi di cPanel diatur PER VERSI PHP, dan pdo_mysql
// diaktifkan di versi yang berbeda dari yang dipakai domain ini.

function baris(string $label, string $nilai): void
{
    printf("  %-26s: %s\n", $label, $nilai);
}

function yaTidak(bool $v): string
{
    return $v ? 'YA' : 'TIDAK';
}

$versiCpanel = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

echo "=== PHP yang melayani request ini ===\n";
baris('Versi PHP', PHP_VERSION);
baris('Versi untuk cPanel', $versiCpanel);
baris('SAPI', PHP_SAPI);
baris('php.ini dipakai', (string) (php_ini_loaded_file() ?: '(tidak ada)'));
baris('php.ini tambahan', (string) (php_ini_scanned_files() ?: '(tidak ada)'));
baris('extension_dir', (string) ini_get('extension_dir'));
baris('Arsitektur', PHP_INT_SIZE === 8 ? '64-bit' : '32-bit');

echo "\n=== Ekstensi yang dibutuhkan ===\n";
foreach (['pdo', 'pdo_mysql', 'mysqlnd', 'mysqli'] as $ext) {
    baris($ext, yaTidak(extension_loaded($ext)));
}

echo "\n=== Driver PDO yang terdaftar ===\n";
$drivers = [];
if (class_exists('PDO')) {
    $drivers = PDO::getAvailableDrivers();
    echo '  ' . ($drivers ? implode(', ', $drivers) : '(kosong)') . "\n";
    baris('"mysql" tersedia', yaTidak(in_array('mysql', $drivers, true)));
} else {
    echo "  Kelas PDO tidak ada sama sekali — ekstensi 'pdo' belum dimuat.\n";
}

echo "\n=== Ekstensi termuat terkait mysql/pdo ===\n";
$terkait = array_values(array_filter(get_loaded_extensions(), function ($e) {
    return stripos($e, 'mysql') !== false || stripos($e, 'pdo') !== false;
}));
echo '  ' . ($terkait ? implode(', ', $terkait) : '(tidak ada satu pun)') . "\n";

echo "\n=== Pembatasan yang bisa memblokir ===\n";
baris('disable_functions', (string) (ini_get('disable_functions') ?: '(kosong)'));
baris('disable_classes', (string) (ini_get('disable_classes') ?: '(kosong)'));

echo "\n=== Kesimpulan ===\n";
if (in_array('mysql', $drivers, true)) {
    echo "  pdo_mysql AKTIF di PHP " . PHP_VERSION . ".\n";
    echo "  Kalau API masih bilang \"could not find driver\", pastikan bootstrap.php\n";
    echo "  yang baru benar-benar sudah terunggah (bukan versi lama).\n";
    exit(0);
}

if (!extension_loaded('pdo')) {
    echo "  Ekstensi 'pdo' belum dimuat. Aktifkan 'pdo' lebih dulu, baru 'pdo_mysql'.\n";
} elseif (!extension_loaded('pdo_mysql')) {
    echo "  Ekstensi 'pdo_mysql' TIDAK dimuat oleh PHP " . PHP_VERSION . ".\n";
    echo "  Ini yang membuat PDO bilang \"could not find driver\".\n";
    echo "\n  Sebab tersering: di cPanel, ekstensi diatur PER VERSI PHP. Kalau Anda\n";
    echo "  mengaktifkan pdo_mysql di versi lain, PHP " . PHP_VERSION . " tidak ikut.\n";
    if (!extension_loaded('mysqlnd')) {
        echo "\n  Catatan: 'mysqlnd' juga belum dimuat. pdo_mysql dibangun di atas\n";
        echo "  mysqlnd — kalau mysqlnd dimatikan, pdo_mysql gagal dimuat diam-diam\n";
        echo "  tanpa pesan apa pun. Aktifkan mysqlnd juga.\n";
    }
}

echo "\n  Langkah:\n";
echo "  1. cPanel -> Select PHP Version -> pastikan dropdown versi di ATAS halaman\n";
echo "     menunjukkan " . $versiCpanel . " (versi yang sama dengan baris di atas).\n";
echo "  2. Tab Extensions -> centang pdo_mysql, pdo, mysqlnd -> Save.\n";
echo "  3. Jalankan ulang skrip ini. Baris '\"mysql\" tersedia' harus jadi YA.\n";
echo "  4. Kalau cPanel Anda hanya punya MultiPHP Manager (tanpa tab Extensions),\n";
echo "     ekstensi TIDAK bisa diaktifkan sendiri — hubungi hosting dan minta\n";
echo "     pdo_mysql + mysqlnd diaktifkan untuk PHP " . $versiCpanel . ".\n";
exit(1);
