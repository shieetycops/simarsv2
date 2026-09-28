<?php
// Validasi + simpan file upload. Meniru middleware/upload.ts (multer):
// tipe pdf/doc/docx/jpeg/png, maks 10MB, nama file-<ts>-<rand>.ext, folder uploads/.
//
// KEAMANAN: tipe file ditentukan dari magic bytes isi file, BUKAN dari
// $file['type'] (MIME kiriman klien yang bisa dipalsukan), dan ekstensi hasil
// simpan diambil dari tipe terdeteksi — BUKAN dari nama kiriman. Kalau ekstensi
// diambil dari nama kiriman, "shell.php" yang menyamar sebagai application/pdf
// akan tersimpan sebagai .php di folder yang disajikan web server => eksekusi
// kode dari jarak jauh (RCE).
class Upload
{
    private const MSG = "Format file tidak didukung. Gunakan PDF, DOCX, atau Gambar.";

    // Tipe hasil deteksi magic bytes => daftar ekstensi kiriman yang sah.
    // Elemen pertama tiap daftar dipakai sebagai ekstensi kanonik saat menyimpan.
    private const SIGNATURES = [
        'pdf'  => ['pdf'],
        'doc'  => ['doc'],
        'docx' => ['docx'],
        'jpg'  => ['jpg', 'jpeg'],
        'png'  => ['png'],
    ];

    private const MAX = 10 * 1024 * 1024;

    // $file = entri $_FILES['file']. Return path publik "/uploads/xxx" atau null bila tak ada file.
    // Gagal validasi = lempar HttpException(400) dengan pesan sama seperti versi Express.
    public static function save(?array $file): ?string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        // Kode error selain "tidak ada file" (melebihi upload_max_filesize,
        // post_max_size, upload terputus, dsb) juga ditolak sebagai 400.
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new AuthException(400, self::MSG);
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new AuthException(400, self::MSG);
        }
        if (($file['size'] ?? 0) > self::MAX) {
            throw new AuthException(400, self::MSG);
        }

        // Ekstensi kanonik hasil deteksi; null = tipe isi file tidak cocok
        // dengan ekstensi namanya (mis. "shell.php" yang isinya PDF).
        $canonical = self::canonicalExtension(
            (string) ($file['name'] ?? ''),
            (string) $file['tmp_name']
        );
        if ($canonical === null) {
            throw new AuthException(400, self::MSG);
        }

        $dir = dirname(__DIR__, 2) . '/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AuthException(500, "Folder uploads tidak bisa dibuat.");
        }
        self::hardenUploadsDir($dir);
        // Ekstensi kanonik dari tipe terdeteksi, bukan dari nama kiriman.
        $name = 'file-' . (int) (microtime(true) * 1000) . '-' . random_int(0, 1_000_000_000)
              . '.' . $canonical;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new AuthException(500, "Gagal menyimpan file yang diupload.");
        }
        return "/uploads/$name";
    }

    // Hapus berkas lampiran (bukan upload baru). Dipakai saat surat dihapus atau
    // lampirannya diganti, supaya folder uploads/ tidak menumpuk berkas yatim.
    //
    // KEAMANAN: hanya menerima pola "/uploads/<nama berkas>" buatan save().
    // Tanpa penjagaan ini, nilai kolom file_path yang bisa saja diisi klien
    // ("/uploads/../../config.php") akan membuat unlink() menghapus berkas di
    // luar folder uploads/.
    public static function delete(?string $publicPath): void
    {
        if (!$publicPath || !preg_match('#^/uploads/[A-Za-z0-9._-]+$#', $publicPath)) {
            return;
        }
        $file = dirname(__DIR__, 2) . $publicPath;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // Ekstensi kanonik yang boleh dipakai menyimpan file ini, atau null bila
    // tipe ISI file tidak cocok dengan ekstensi NAMA kiriman. Dipisah dari
    // save() supaya logika penentu ekstensi bisa diuji tanpa HTTP upload.
    public static function canonicalExtension(string $fileName, string $tmpPath): ?string
    {
        // Ekstensi dari nama kiriman hanya dipakai untuk MENCOCOKKAN tipe
        // terdeteksi — bukan untuk menentukan nama file hasil. Jadi "x.php"
        // (yang bukan ekstensi sah untuk tipe apa pun) langsung ditolak.
        $ext  = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $type = self::detect($tmpPath);
        if ($type === null || !in_array($ext, self::SIGNATURES[$type], true)) {
            return null;
        }
        return self::SIGNATURES[$type][0];
    }

    // Pastikan uploads/.htaccess ada. DEPLOY.md meminta folder uploads/ lama
    // di-upload manual ke document root, jadi berkas pengaman itu bisa saja
    // tidak ikut ter-upload — padahal tanpa itu lampiran .php yang lolos
    // validasi akan dieksekusi web server. Best-effort: gagal tulis diabaikan.
    private static function hardenUploadsDir(string $dir): void
    {
        $htaccess = "$dir/.htaccess";
        if (is_file($htaccess)) {
            return;
        }
        @file_put_contents($htaccess, self::UPLOADS_HTACCESS);
    }

    private const UPLOADS_HTACCESS = "# Lampiran surat hanya boleh DIBACA, tidak boleh DIEKSEKUSI.\n"
        . "# Tanpa berkas ini, lampiran ber-ekstensi .php yang lolos validasi akan\n"
        . "# dijalankan oleh web server => eksekusi kode dari jarak jauh (RCE).\n"
        . "Options -ExecCGI -Indexes\n"
        . "\n"
        . "<FilesMatch \"\\.(php|phtml|php[0-9]?|phps|pht|phar|cgi|pl|py|rb|asp|aspx|jsp|jspx|sh|bash|shtml|htaccess)$\">\n"
        . "    <IfModule mod_authz_core.c>\n"
        . "        Require all denied\n"
        . "    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n"
        . "        Order allow,deny\n"
        . "        Deny from all\n"
        . "    </IfModule>\n"
        . "</FilesMatch>\n"
        . "\n"
        . "# Matikan interpreter PHP di folder ini bila server memakai mod_php.\n"
        . "<IfModule mod_php.c>\n"
        . "    php_flag engine off\n"
        . "</IfModule>\n"
        . "<IfModule mod_php7.c>\n"
        . "    php_flag engine off\n"
        . "</IfModule>\n"
        . "<IfModule mod_php5.c>\n"
        . "    php_flag engine off\n"
        . "</IfModule>\n";


    // Kenali tipe dari magic bytes. Null = tidak dikenali => ditolak.
    // Sengaja tidak memakai finfo/fileinfo: ekstensi itu tidak selalu aktif di
    // hosting berbagi, dan magic bytes sudah cukup untuk 5 tipe yang didukung.
    private static function detect(string $tmp): ?string
    {
        $fh = @fopen($tmp, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 8);
        fclose($fh);

        if (strncmp($head, '%PDF-', 5) === 0) {
            return 'pdf';
        }
        if (strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
            return 'doc'; // kontainer OLE2 (Word 97-2003)
        }
        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            return 'docx'; // kontainer ZIP/OOXML
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'png';
        }
        return null;
    }
}
