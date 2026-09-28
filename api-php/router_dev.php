<?php
// Router untuk server pengembangan/uji: php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php
// Meneruskan semua permintaan ke front controller (tidak ada file statis di api-php).

// Lampiran surat hidup di uploads/ pada ROOT repo, yaitu di luar document root
// server pengembangan (api-php/). Tanpa cabang ini, tautan "Lihat lampiran scan"
// di Buku Kendali selalu gagal walau berkasnya ada. Di produksi uploads/ berada
// di document root, jadi tidak perlu perlakuan khusus.
$devPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/uploads/([A-Za-z0-9._-]+)$#', $devPath, $m)) {
    // Pola nama berkas dibatasi sama seperti Upload::delete() supaya ".." dan
    // nama berkas aneh tidak bisa dipakai membaca berkas di luar uploads/.
    $file = dirname(__DIR__) . '/uploads/' . $m[1];
    if (is_file($file)) {
        $mime = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        header('Content-Type: ' . ($mime[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        return;
    }
    http_response_code(404);
    echo json_encode(['message' => 'Lampiran tidak ditemukan']);
    return;
}

require __DIR__ . '/index.php';
