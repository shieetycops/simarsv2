<?php
// Pemeriksaan mandiri kompatibilitas PHP — TANPA PHPUnit/Composer/MySQL.
// Jalankan: php api-php/tests/run_php_compat_check.php
// Exit code 0 = semua pass; 1 = ada kegagalan.
//
// Latar belakang: hosting produksi bisa masih PHP 7.4. Di sana
//   - match()          -> sintaks PHP 8 => Parse error (fatal, situs blank)
//   - str_contains()   -> fungsi PHP 8  => Call to undefined function
//   - str_starts_with(), str_ends_with() sama.
// Skrip ini menjaga dua hal:
//   1) lib/ tidak lagi memakai sintaks yang TIDAK bisa di-polyfill (match, ?->,
//      union type, dst) — kalau muncul lagi, deploy ke PHP 7.4 langsung mati.
//   2) polyfill di lib/compat.php benar-benar tersedia dan hasilnya identik
//      dengan fungsi bawaan PHP 8.

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

// Buang komentar & string literal supaya kata seperti "match" yang cuma muncul
// di penjelasan tidak dianggap sebagai sintaks.
function kodeSaja(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$libDir = __DIR__ . '/../lib';

// ---------- 1) sintaks PHP 8 yang tidak bisa di-polyfill ----------
// [label, regex]
$terlarang = [
    'match expression'      => '/(?<![a-zA-Z_$])match\s*\(/',
    'nullsafe operator ?->' => '/\?->/',
    'atribut #[...]'        => '/#\[/',
    'readonly'              => '/\breadonly\b/',
    'enum'                  => '/\benum\s+[A-Za-z_]/',
    'tipe mixed'            => '/(?<![$\w>])mixed\s*[\$\)\{;,=]/',
    'union type (param)'    => '/\|\s*[A-Za-z_\\\\]+\s+\$/',
    'union type (return)'   => '/\)\s*:\s*[A-Za-z_\\\\]+\|[A-Za-z_\\\\]+/',
    'array_is_list()'       => '/\barray_is_list\s*\(/',
    'return type never'     => '/\)\s*:\s*never\b/',
    'first-class callable'  => '/[A-Za-z_\\\\]+\s*\(\s*\.\.\.\s*\)/',
    'named argument'        => '/[A-Za-z_][A-Za-z0-9_]*\s*\(\s*[A-Za-z_][A-Za-z0-9_]*\s*:\s*(?!:)/',
    'constructor promotion' => '/function\s+__construct\s*\([^)]*\b(public|private|protected|readonly)\b/',
];

$berkas = [__DIR__ . '/../bootstrap.php', __DIR__ . '/../index.php'];
foreach (glob($libDir . '/*.php') as $f) $berkas[] = $f;
foreach (glob($libDir . '/handlers/*.php') as $f) $berkas[] = $f;

$scan = 0;
foreach ($berkas as $f) {
    $kode = kodeSaja((string) file_get_contents($f));
    $nama = str_replace('\\', '/', substr($f, strlen(dirname($libDir)) + 1));
    foreach ($terlarang as $label => $re) {
        if (preg_match($re, $kode, $m)) {
            $scan++;
            check("sintaks PHP 8 terlarang di $nama: $label", $m[0], '(tidak boleh ada)');
        }
    }
}
check('jumlah berkas produksi yang dipindai', count($berkas) >= 20, true);
check('tidak ada sintaks PHP 8 terlarang', $scan, 0);

// ---------- 2) polyfill tersedia & ter-require lebih dulu ----------
$compatPath = $libDir . '/compat.php';
check('lib/compat.php ada', is_file($compatPath), true);

$compat = (string) file_get_contents($compatPath);
check('compat.php menjaga 3 fungsi dengan function_exists', substr_count($compat, 'if (!function_exists('), 3);

$boot = (string) file_get_contents(__DIR__ . '/../bootstrap.php');
$posRequire = strpos($boot, "require_once __DIR__ . '/lib/compat.php'");
$posGlob    = strpos($boot, "glob(__DIR__ . '/lib/*.php')");
check('bootstrap.php me-require compat.php', $posRequire !== false, true);
check('compat.php di-require SEBELUM glob(lib/*.php)', $posRequire !== false && $posGlob !== false && $posRequire < $posGlob, true);

// ---------- 3) perilaku polyfill identik dengan bawaan PHP 8 ----------
// Badan fungsi diambil apa adanya dari compat.php, lalu dijalankan dengan nama
// p_* supaya tidak bentrok dengan fungsi bawaan. Jadi yang diuji benar-benar
// kode yang dipakai di hosting PHP 7.4, bukan salinan terpisah.
preg_match_all(
    '/function\s+(str_contains|str_starts_with|str_ends_with)\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\n    \}/s',
    $compat,
    $m,
    PREG_SET_ORDER
);
check('3 badan polyfill berhasil diekstrak', count($m), 3);

$namaPolyfill = [];
foreach ($m as $f) {
    $asli = $f[1];
    $namaPolyfill[$asli] = 'p_' . $asli;
    eval('function p_' . $asli . '(string $haystack, string $needle): bool {' . $f[2] . '}');
}
check('polyfill str_contains terdefinisi', function_exists('p_str_contains'), true);
check('polyfill str_starts_with terdefinisi', function_exists('p_str_starts_with'), true);
check('polyfill str_ends_with terdefinisi', function_exists('p_str_ends_with'), true);

// Kasus batas: needle kosong, needle lebih panjang, beda huruf besar/kecil,
// string "0" (jebakan loose comparison), dan UTF-8.
$kasus = [
    ['foobar', 'bar'], ['foobar', 'foo'], ['foobar', ''], ['', ''], ['', 'x'],
    ['abc', 'abc'], ['abc', 'abcd'], ['abc', 'ABC'], ['abc', 'c'], ['abc', 'b'],
    ['0', '0'], ['a0', '0'], ['10', '0'], ['héllo', 'é'], ['a.b', '.'],
    ['x', ' '], ['  x', ' '], ['abc', 'ab'], ['abc', 'bc'], ['abc', 'ac'],
];

$beda = 0;
foreach ($kasus as [$h, $n]) {
    foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
        $p = $namaPolyfill[$fn];
        $hasilPolyfill = $p($h, $n);
        $hasilBawaan   = $fn($h, $n);
        if ($hasilPolyfill !== $hasilBawaan) {
            $beda++;
            check("polyfill $fn('$h','$n') == bawaan", $hasilPolyfill, $hasilBawaan);
        }
    }
}
check('jumlah perbandingan polyfill vs bawaan', count($kasus) * 3, 60);
check('tidak ada perbedaan hasil polyfill vs bawaan', $beda, 0);

// ---------- 4) match() diganti if-chain dengan semantik sama ----------
require_once $libDir . '/Disposition.php';
check('status SELESAI', Disposition::statusNotificationTitle('SELESAI'), 'Disposisi Selesai');
check('status PROSES', Disposition::statusNotificationTitle('PROSES'), 'Disposisi Sedang Diproses');
check('status PENDING -> null', Disposition::statusNotificationTitle('PENDING'), null);
check('status kosong -> null', Disposition::statusNotificationTitle(''), null);
check('status "0" -> null, bukan cocok longgar', Disposition::statusNotificationTitle('0'), null);
check('status huruf kecil tidak cocok', Disposition::statusNotificationTitle('selesai'), null);

// ---------- 5) bersih dari peringatan PHP 8.2/8.3/8.4 ----------
// Properti dinamis diusang di 8.2, parameter nullable implisit di 8.4,
// konstanta kelas bertipe baru ada di 8.3. Ketiganya cuma peringatan (bukan
// fatal), tapi memenuhi log produksi dan menyulitkan pencarian masalah asli.
$temuanBaru = 0;
foreach ($berkas as $f) {
    $kode = kodeSaja((string) file_get_contents($f));
    $nama = str_replace('\\', '/', substr($f, strlen(dirname($libDir)) + 1));

    // Properti & metode yang benar-benar dideklarasikan di berkas ini.
    preg_match_all('/(?:public|private|protected)\s+(?:static\s+)?(?:readonly\s+)?(?:\??[A-Za-z_\\\\]+(?:\s*\|\s*[A-Za-z_\\\\]+)*\s+)?\$([A-Za-z_]\w*)/', $kode, $m1);
    preg_match_all('/function\s+([A-Za-z_]\w*)\s*\(/', $kode, $m2);
    $dideklarasi = array_merge($m1[1], $m2[1]);

    // $this->xxx yang tidak pernah dideklarasikan = properti dinamis.
    preg_match_all('/\$this->\{?\$?([A-Za-z_]\w*)/', $kode, $m3);
    $asing = array_values(array_diff(array_unique($m3[1]), $dideklarasi));
    if ($asing) {
        $temuanBaru++;
        check("properti dinamis (usang di 8.2) di $nama", implode(',', $asing), '(tidak boleh ada)');
    }

    // Parameter nullable implisit: "Type $x = null" tanpa tanda '?'.
    if (preg_match_all('/function\s+\w+\s*\(([^)]*)\)/', $kode, $mm)) {
        foreach ($mm[1] as $params) {
            if (preg_match_all('/(?<!\?)\b([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_]\w*)\s*=\s*null/', $params, $p)) {
                $temuanBaru++;
                check("nullable implisit (usang di 8.4) di $nama", implode(',', $p[2]), '(tidak boleh ada)');
            }
        }
    }
}
check('bersih dari peringatan PHP 8.2/8.3/8.4', $temuanBaru, 0);

// ---------- 6) skrip diagnostik mandiri juga harus jalan di PHP 7.4 ----------
// api/tests/check_*.php dan api/tests/run_*.php dijalankan LANGSUNG di hosting
// (tanpa PHPUnit), jadi sintaks PHP 8 di sana = Parse error saat dipakai
// menelusuri masalah. Berkas *Test.php dikecualikan: itu PHPUnit, tidak pernah
// dijalankan di hosting.
$skrip = [];
foreach (glob(__DIR__ . '/*.php') as $f) {
    if (substr(basename($f), -8) === 'Test.php') continue;
    $skrip[] = $f;
}

$scanSkrip = 0;
foreach ($skrip as $f) {
    $kode = kodeSaja((string) file_get_contents($f));
    $nama = 'tests/' . basename($f);
    foreach ($terlarang as $label => $re) {
        if (preg_match($re, $kode, $m)) {
            $scanSkrip++;
            check("sintaks PHP 8 terlarang di $nama: $label", $m[0], '(tidak boleh ada)');
        }
    }
}
check('jumlah skrip diagnostik mandiri yang dipindai', count($skrip) >= 8, true);
check('tidak ada sintaks PHP 8 di skrip diagnostik', $scanSkrip, 0);

// ---------- ringkasan ----------
echo "PHP compat check: $pass pass, $fail fail\n";
if ($fail > 0) {
    echo "\nGAGAL:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
exit(0);


