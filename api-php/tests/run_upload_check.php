<?php
// Pemeriksaan mandiri validasi upload (anti-RCE) — TANPA PHPUnit/Composer/MySQL.
// Jalankan: php api-php/tests/run_upload_check.php
// Exit code 0 = semua pass; 1 = ada kegagalan.
//
// Inti yang dijaga: tipe file ditentukan dari ISI (magic bytes), BUKAN dari
// $file['type'] kiriman klien, dan ekstensi hasil simpan diambil dari tipe
// terdeteksi — BUKAN dari nama kiriman. Kalau tidak, "shell.php" yang menyamar
// sebagai application/pdf akan tersimpan sebagai .php di folder yang disajikan
// web server => eksekusi kode dari jarak jauh.

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Upload.php';

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

// Tulis berkas sementara berisi byte tertentu, kembalikan path-nya.
function tmpUpload(string $bytes): string
{
    $p = (string) tempnam(sys_get_temp_dir(), 'simars_up_');
    file_put_contents($p, $bytes);
    return $p;
}

// Magic bytes asli tiap tipe yang didukung.
$PDF = "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n";
$OLE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 24); // Word 97-2003
$ZIP = "PK\x03\x04" . str_repeat("\x00", 24);                       // OOXML/docx
$JPG = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24);
$PNG = "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 24);
$PHP = "<?php system(\$_GET['c']); ?>";
$GIF = "GIF89a" . str_repeat("\x00", 24);
$SVG = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

// ---------- canonicalExtension: nama kiriman vs isi file ----------
// [label, nama kiriman, isi file, ekstensi kanonik yang diharapkan (null = tolak)]
$cases = [
    // --- sah: ekstensi nama cocok dengan isi ---
    ['pdf biasa',              'surat.pdf',   $PDF, 'pdf'],
    ['pdf huruf besar',        'surat.PDF',   $PDF, 'pdf'],
    ['doc OLE2',               'surat.doc',   $OLE, 'doc'],
    ['docx OOXML',             'surat.docx',  $ZIP, 'docx'],
    ['jpg',                    'foto.jpg',    $JPG, 'jpg'],
    ['jpeg -> kanonik jpg',    'foto.jpeg',   $JPG, 'jpg'],
    ['png',                    'foto.png',    $PNG, 'png'],
    ['nama berekstensi ganda', 'a.php.pdf',   $PDF, 'pdf'], // disimpan sebagai .pdf

    // --- serangan: ekstensi nama != isi file ---
    ['php isi php',            'shell.php',   $PHP, null],
    ['php isi pdf',            'shell.php',   $PDF, null],
    ['phtml isi pdf',          'shell.phtml', $PDF, null],
    ['php5 isi png',           'shell.php5',  $PNG, null],
    ['phar isi pdf',           'shell.phar',  $PDF, null],
    ['htaccess',               '.htaccess',   $PDF, null],
    ['jpg isi php',            'shell.jpg',   $PHP, null],
    ['pdf isi php',            'shell.pdf',   $PHP, null],
    ['docx isi php',           'shell.docx',  $PHP, null],
    ['php ganda',              'x.php.php',   $PHP, null],

    // --- isi tidak dikenali / tidak cocok ---
    ['pdf isi gif',            'surat.pdf',   $GIF, null],
    ['png isi pdf',            'surat.png',   $PDF, null],
    ['docx isi pdf',           'surat.docx',  $PDF, null],
    ['doc isi docx',           'surat.doc',   $ZIP, null],
    ['svg',                    'gambar.svg',  $SVG, null],
    ['tanpa ekstensi',         'surat',       $PDF, null],
    ['ekstensi kosong',        'surat.',      $PDF, null],
    ['isi kosong',             'surat.pdf',   '',   null],
    ['isi terlalu pendek',     'surat.pdf',   '%PD', null],
    ['pdf bukan di awal',      'surat.pdf',   'xx%PDF-1.7', null],
];

foreach ($cases as [$label, $name, $bytes, $expected]) {
    $tmp = tmpUpload($bytes);
    check("canonicalExtension [$label]", Upload::canonicalExtension($name, $tmp), $expected);
    @unlink($tmp);
}

// ---------- save(): penjaga sebelum berkas disimpan ----------
// Catatan: jalur "berhasil menyimpan" tidak bisa diuji di CLI karena
// is_uploaded_file() hanya true untuk upload HTTP asli. Yang diuji di sini
// adalah seluruh jalur penolakan — termasuk yang paling penting: MIME palsu
// dari klien, dan tmp_name yang bukan hasil upload.
function attemptSave(?array $file): array
{
    try {
        return ['status' => 200, 'path' => Upload::save($file), 'message' => ''];
    } catch (AuthException $e) {
        return ['status' => $e->status, 'path' => null, 'message' => $e->getMessage()];
    }
}

$MSG = "Format file tidak didukung. Gunakan PDF, DOCX, atau Gambar.";

// Lampiran opsional: tidak ada file sama sekali bukan error.
$r = attemptSave(null);
check('save [null] status', $r['status'], 200);
check('save [null] path', $r['path'], null);

$r = attemptSave(['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'tmp_name' => '', 'size' => 0]);
check('save [tanpa file] status', $r['status'], 200);
check('save [tanpa file] path', $r['path'], null);

// Kode error lain (melebihi upload_max_filesize, terputus, dsb) -> 400.
foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR] as $err) {
    $r = attemptSave(['error' => $err, 'name' => 'surat.pdf', 'tmp_name' => '', 'size' => 10]);
    check("save [error=$err] status", $r['status'], 400);
}

$pdfPath = tmpUpload($PDF);
$phpPath = tmpUpload($PHP);

// tmp_name bukan hasil upload HTTP (mis. diarahkan ke berkas lain di server).
$r = attemptSave([
    'error' => UPLOAD_ERR_OK, 'name' => 'surat.pdf',
    'tmp_name' => $pdfPath, 'size' => strlen($PDF),
]);
check('save [tmp_name bukan upload] status', $r['status'], 400);

// Melebihi 10MB -> 400, dicek sebelum isi dibaca.
$r = attemptSave([
    'error' => UPLOAD_ERR_OK, 'name' => 'surat.pdf', 'tmp_name' => $pdfPath,
    'size' => 10 * 1024 * 1024 + 1,
]);
check('save [>10MB] status', $r['status'], 400);

// MIME kiriman klien dipalsukan jadi application/pdf + nama .php -> tetap 400,
// dan pesannya seragam (tidak membocorkan alasan sebenarnya).
$r = attemptSave([
    'error' => UPLOAD_ERR_OK, 'name' => 'shell.php', 'type' => 'application/pdf',
    'tmp_name' => $phpPath, 'size' => strlen($PHP),
]);
check('save [shell.php + MIME palsu] status', $r['status'], 400);
check('save [shell.php + MIME palsu] pesan seragam', $r['message'], $MSG);

// Nama .php walau isinya PDF asli -> tetap 400.
$r = attemptSave([
    'error' => UPLOAD_ERR_OK, 'name' => 'shell.php', 'type' => 'application/pdf',
    'tmp_name' => $pdfPath, 'size' => strlen($PDF),
]);
check('save [shell.php + isi PDF] status', $r['status'], 400);

// Nama .pdf dengan isi PHP -> tetap 400 (MIME tidak pernah dipercaya).
$r = attemptSave([
    'error' => UPLOAD_ERR_OK, 'name' => 'surat.pdf', 'type' => 'application/pdf',
    'tmp_name' => $phpPath, 'size' => strlen($PHP),
]);
check('save [surat.pdf + isi PHP] status', $r['status'], 400);

@unlink($pdfPath);
@unlink($phpPath);

// ---------- ringkasan ----------
echo "Upload check: $pass pass, $fail fail\n";
foreach ($failures as $f) {
    echo "  FAIL: $f\n";
}
exit($fail === 0 ? 0 : 1);
