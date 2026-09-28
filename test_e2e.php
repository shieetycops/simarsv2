<?php
// Uji end-to-end API SIMARS v2 terhadap server lokal (simars_v2).
// Base URL bisa diarahkan ke server lain lewat env var, mis.
//   $env:SIMARS_API_BASE='http://127.0.0.1:8012/api'; php test_e2e.php
// berguna untuk memastikan uji benar-benar menembak kode di folder repo ini
// (server yang sudah jalan di port lain bisa saja menyajikan salinan lain).
$base = rtrim(getenv('SIMARS_API_BASE') ?: 'http://127.0.0.1:8011/api', '/');
$pass = 0; $fail = 0;
// Nomor agenda unik per run supaya skrip bisa diulang tanpa collision.
$runId = date('His');

function req(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n"]];
    if ($token) $opts['http']['header'] .= "Authorization: Bearer $token\r\n";
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1]; }
    return [$code, json_decode((string)$raw, true)];
}
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS: $name\n"; }
    else { $fail++; echo "FAIL: $name $extra\n"; }
}
// Ada tidaknya berkas lampiran di disk. clearstatcache() WAJIB: proses CLI ini
// sudah pernah memeriksa path yang sama, dan PHP menyimpan hasil stat-nya
// sehingga berkas yang sudah dihapus server tetap terlihat "ada".
function fileOnDisk(string $path): bool {
    clearstatcache(true, $path);
    return is_file($path);
}
// Ambil URL mentah (bukan JSON API), mis. lampiran di /uploads/... Dipakai untuk
// memastikan tautan "Lihat lampiran scan" tidak menembak berkas yang tak tersaji.
function fetchRaw(string $url): array {
    $opts = ['http' => ['method' => 'GET', 'ignore_errors' => true]];
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0; $type = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        if (preg_match('#^Content-Type:\s*([^;\s]+)#i', $h, $m)) $type = strtolower($m[1]);
    }
    return [$code, $type, (string)$raw];
}
// Multipart request (unggah/ganti lampiran). Dipakai uji fitur upload yang
// WAJIB tetap ada di halaman Surat Masuk v2 dan dialog koreksi Buku Kendali.
// $files = ['file' => ['name' => 'scan.pdf', 'content' => '...', 'type' => 'application/pdf']]
function reqMulti(string $method, string $url, array $fields, array $files, ?string $token = null): array {
    $boundary = '----SIMARS' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    foreach ($files as $k => $f) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"{$f['name']}\"\r\n"
               . "Content-Type: {$f['type']}\r\n\r\n{$f['content']}\r\n";
    }
    $body .= "--$boundary--\r\n";
    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n",
    ]];
    if ($token) $opts['http']['header'] .= "Authorization: Bearer $token\r\n";
    $opts['http']['content'] = $body;
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1]; }
    return [$code, json_decode((string)$raw, true)];
}

// 1. Login semua role
[, $admin] = req('POST', "$base/auth/login", ['username' => 'admin_v2', 'password' => 'admin123']);
$adminToken = $admin['token'] ?? '';
[, $sekre] = req('POST', "$base/auth/login", ['username' => 'sekre_v2', 'password' => 'sekre123']);
$sekreToken = $sekre['token'] ?? '';
[, $pimpin] = req('POST', "$base/auth/login", ['username' => 'pimpin_v2', 'password' => 'pimpin123']);
$pimpinToken = $pimpin['token'] ?? '';
[, $staf] = req('POST', "$base/auth/login", ['username' => 'staf_v2', 'password' => 'staf123']);
$stafToken = $staf['token'] ?? '';
// ARSIPARIS (Q2 revisi SOP/AS/04): satu-satunya role pencatat surat yang boleh
// menandai DIARSIPKAN. Tokennya dipakai untuk menguji penutupan siklus langkah 17-18.
[, $arsip] = req('POST', "$base/auth/login", ['username' => 'arsip_v2', 'password' => 'arsip123']);
$arsipToken = $arsip['token'] ?? '';
check('login admin/sekre/pimpin/staf', $adminToken && $sekreToken && $pimpinToken && $stafToken);
check('login arsiparis', (bool) $arsipToken);

// 2. Kontrak workflow & klasifikasi
[$code, $stages] = req('GET', "$base/control/stages", null, $adminToken);
check('GET stages 17 tahap', $code === 200 && count($stages['stages']) === 17);
[$code, $cls] = req('GET', "$base/control/classifications", null, $adminToken);
$officialCount = count(array_filter($cls['classifications'], fn($c) => $c['validationStatus'] === 'OFFICIAL'));
check('GET classifications (>=24 OFFICIAL)', $code === 200 && $officialCount >= 24, "got $officialCount");

// 3. Registrasi surat BIASA dengan kode resmi HK1.1.2 (body = JSON data langsung)
[$code, $l1] = req('POST', "$base/incoming", [
    'agendaNumber' => "AGD/2026/9$runId", 'letterNumber' => '001/TEST/IX/2026', 'letterDate' => '2026-09-20',
    'receivedDate' => '2026-09-23', 'sender' => 'Dinas Uji', 'subject' => 'Surat uji BIASA',
    'classification' => 'DINAS', 'securityLevel' => 'BIASA', 'archiveCode' => 'HK1.1.2',
], $sekreToken);
check('registrasi BIASA -> 201 DITERIMA', $code === 201 && $l1['currentStage'] === 'DITERIMA', json_encode($l1));
check('kode arsip HK1.1.2 -> OFFICIAL', ($l1['archiveCodeStatus'] ?? '') === 'OFFICIAL');
$id1 = $l1['id'] ?? '';

// 4. Registrasi surat RAHASIA
[$code, $l2] = req('POST', "$base/incoming", [
    'agendaNumber' => "AGD/2026/8$runId", 'letterNumber' => '002/RAHASIA/IX/2026', 'letterDate' => '2026-09-21',
    'receivedDate' => '2026-09-23', 'sender' => 'Kejaksaan Uji', 'subject' => 'Surat uji RAHASIA',
    'classification' => 'DINAS', 'securityLevel' => 'RAHASIA', 'archiveCode' => 'KU',
], $adminToken);
check('registrasi RAHASIA -> 201', $code === 201 && ($l2['securityLevel'] ?? '') === 'RAHASIA');
$id2 = $l2['id'] ?? '';

// 5. Kode arsip tidak valid ditolak
[$code, $bad] = req('POST', "$base/incoming", [
    'agendaNumber' => "AGD/2026/7$runId", 'letterNumber' => '003/BAD/IX/2026', 'letterDate' => '2026-09-20',
    'receivedDate' => '2026-09-23', 'sender' => 'X', 'subject' => 'Y', 'classification' => 'DINAS',
    'securityLevel' => 'BIASA', 'archiveCode' => 'ZZ9',
], $sekreToken);
check('kode arsip ZZ9 ditolak 400', $code === 400 && isset($bad['errors']['archiveCode']), json_encode($bad));

// 6. Transisi ilegal DITERIMA -> DIARSIPKAN
[$code] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIARSIPKAN'], $sekreToken);
check('transisi ilegal 422', $code === 422);

// 7. Verifikasi alamat + checklist kelengkapan
[$code, $r] = req('POST', "$base/control/$id1/address-verification", ['correct' => true, 'notes' => 'Alamat kantor sesuai'], $sekreToken);
check('verifikasi alamat sesuai', $code === 200 && ($r['addressStatus'] ?? '') === 'ALAMAT_SESUAI');
[$code] = req('POST', "$base/control/$id1/address-verification", ['correct' => true], $sekreToken);
check('verifikasi alamat kedua kali 422', $code === 422);
[$code, $r] = req('POST', "$base/control/$id1/completeness", ['checks' => ['addressCorrect' => true, 'numberPresent' => true, 'datePresent' => true, 'subjectPresent' => true, 'attachmentComplete' => false, 'signaturePresent' => true]], $sekreToken);
check('checklist tidak lengkap -> TIDAK_LENGKAP', $code === 200 && ($r['completenessStatus'] ?? '') === 'TIDAK_LENGKAP');
[$code, $r] = req('POST', "$base/control/$id1/completeness", ['checks' => ['addressCorrect' => true, 'numberPresent' => true, 'datePresent' => true, 'subjectPresent' => true, 'attachmentComplete' => true, 'signaturePresent' => true], 'notes' => 'Lampiran dilengkapi'], $sekreToken);
check('checklist lengkap -> LENGKAP', $code === 200 && ($r['completenessStatus'] ?? '') === 'LENGKAP');

// 8. Happy path transisi 17 tahap dengan pembatasan role
$t = function (string $id, string $to, string $token, int $expect, string $name, array $extra = []) use ($base) {
    [$code, $r] = req('POST', "$base/control/$id/transition", array_merge(['toStage' => $to], $extra), $token);
    check($name, $code === $expect, "(got $code " . json_encode($r) . ")");
    return $r;
};
$t($id1, 'VERIFIKASI_ALAMAT', $sekreToken, 200, 'DITERIMA -> VERIFIKASI_ALAMAT (sekre)');
$t($id1, 'DISORTIR', $sekreToken, 200, 'VERIFIKASI_ALAMAT -> DISORTIR (sekre)');
$t($id1, 'MENUNGGU_PENGARAHAN', $sekreToken, 200, 'DISORTIR -> MENUNGGU_PENGARAHAN (sekre)');
$t($id1, 'DIBACA_PENGARAH', $sekreToken, 200, 'MENUNGGU_PENGARAHAN -> DIBACA_PENGARAH (sekre/pengarah surat, SOP langkah 4-7)');
$t($id1, 'DIBACA_PENGARAH', $pimpinToken, 403, 'pimpin dilarang menandai pengarahan (bukan pengarah surat)');
$t($id1, 'TERREGISTRASI', $sekreToken, 200, 'DIBACA_PENGARAH -> TERREGISTRASI (sekre)');
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('registered_at terisi setelah TERREGISTRASI', !empty($d['letter']['registeredAt']));
// P2 (revisi kedua): maju ke MENUNGGU_DISPOSISI kini WAJIB rekomendasi rute
// Kasubag (SOP/AS/04 langkah 12). Sekretaris boleh memberi rekomendasi
// (REKOMENDASI_ROLES), jadi token sekre dipakai untuk uji ini.
[$code, $rRek] = req('POST', "$base/control/$id1/rekomendasi",
    ['rekomendasiRoute' => 'LANGSUNG', 'notes' => 'Rekomendasi uji e2e: langsung ke unit.'], $sekreToken);
check('rekomendasi rute tersimpan sebelum MENUNGGU_DISPOSISI', $code === 200 && ($rRek['rekomendasiRoute'] ?? '') === 'LANGSUNG', json_encode($rRek));
$t($id1, 'MENUNGGU_DISPOSISI', $sekreToken, 200, 'TERREGISTRASI -> MENUNGGU_DISPOSISI (sekre, dengan rekomendasi)');

// --- SOP/AS/04 langkah 13: keputusan disposisi ada di Sekretaris/Panitera ---
// Pimpinan bukan pemutus "perlu kebijakan pimpinan atau langsung", jadi 403.
$t($id1, 'DIDISPOSISIKAN', $pimpinToken, 403, 'pimpin dilarang memutuskan disposisi (SOP langkah 13)');
// Rute keputusan kosong -> 422, dan field-nya harus jelas supaya UI bisa menunjuk input.
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIDISPOSISIKAN'], $sekreToken);
check('disposisi tanpa rute ditolak 422', $code === 422 && ($r['field'] ?? '') === 'dispositionRoute', json_encode($r));
// Rute di luar daftar sah -> 422.
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIDISPOSISIKAN', 'dispositionRoute' => 'NGAWUR'], $sekreToken);
check('rute keputusan tak dikenal ditolak 422', $code === 422, json_encode($r));
// Percobaan gagal tidak boleh mengubah state (tidak ada partial write).
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('tahap & rute tidak berubah setelah penolakan', ($d['letter']['currentStage'] ?? '') === 'MENUNGGU_DISPOSISI' && ($d['letter']['dispositionRoute'] ?? null) === null);
// UI mengambil tombol dari server: tombol masuk-DIDISPOSISIKAN wajib bertanda requiresRoute.
$dec = array_values(array_filter($d['allowedTransitions'] ?? [], fn($x) => !empty($x['requiresRoute'])));
check('allowedTransitions menandai tombol butuh rute', count($dec) === 1 && ($dec[0]['toStage'] ?? '') === 'DIDISPOSISIKAN', json_encode($d['allowedTransitions'] ?? []));

// Rute LANGSUNG: boleh masuk DIDISPOSISIKAN, tersimpan, lalu mengunci cabang.
$t($id1, 'DIDISPOSISIKAN', $sekreToken, 200, 'MENUNGGU_DISPOSISI -> DIDISPOSISIKAN (sekre, rute LANGSUNG)', ['dispositionRoute' => 'LANGSUNG']);
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('rute keputusan tersimpan = LANGSUNG', ($d['letter']['dispositionRoute'] ?? '') === 'LANGSUNG');
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'MENUNGGU_KEBIJAKAN_PIMPINAN'], $sekreToken);
check('rute LANGSUNG dikunci: ke pimpinan ditolak 422', $code === 422 && ($r['dispositionRoute'] ?? '') === 'LANGSUNG', json_encode($r));
// Unit tujuan WAJIB (revisi SOP/AS/04 poin 3): tanpa unit / unit tak dikenal
// ditolak 422 + field unit_tujuan supaya UI bisa menandai inputnya.
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DITERUSKAN_KE_PELAKSANA'], $sekreToken);
check('teruskan tanpa unit_tujuan ditolak 422', $code === 422 && ($r['code'] ?? '') === 'UNIT_TUJUAN_REQUIRED' && ($r['field'] ?? '') === 'unit_tujuan', json_encode($r));
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DITERUSKAN_KE_PELAKSANA', 'unit_tujuan' => 'NGAWUR'], $sekreToken);
check('unit_tujuan tak dikenal ditolak 422', $code === 422 && ($r['code'] ?? '') === 'UNIT_TUJUAN_INVALID' && ($r['field'] ?? '') === 'unit_tujuan', json_encode($r));
$t($id1, 'DITERUSKAN_KE_PELAKSANA', $sekreToken, 200, 'DIDISPOSISIKAN -> KE PELAKSANA (sekre, unit wajib)', ['unit_tujuan' => 'KASUBAG_UMUM']);
// Rute hanya mengunci percabangan sesudah keputusan; setelah surat keluar dari
// DIDISPOSISIKAN, tahap lanjutan harus kembali normal (regresi: sebelumnya rute
// LANGSUNG memblokir DALAM_TINDAK_LANJUT sehingga surat macet).
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
$afterRoute = array_column($d['allowedTransitions'] ?? [], 'toStage');
$afterRouteAny = [];
foreach (['ADMIN' => $adminToken, 'SEKRETARIS' => $sekreToken, 'STAFF' => $stafToken] as $roleToken) {
    [$c, $dr] = req('GET', "$base/control/$id1", null, $roleToken);
    $afterRouteAny = array_merge($afterRouteAny, array_column($dr['allowedTransitions'] ?? [], 'toStage'));
}
check('rute tidak lagi mengunci setelah keluar DIDISPOSISIKAN',
    in_array('DALAM_TINDAK_LANJUT', array_unique($afterRouteAny), true), json_encode(array_values(array_unique($afterRouteAny))));
$t($id1, 'DALAM_TINDAK_LANJUT', $stafToken, 200, 'KE PELAKSANA -> DALAM_TINDAK_LANJUT (staf)');
$t($id1, 'SELESAI_DITINDAKLANJUTI', $stafToken, 200, 'DALAM_TINDAK_LANJUT -> SELESAI (staf)');
$t($id1, 'MENUNGGU_PENGARSIPAN', $stafToken, 403, 'staf (pegawai penindak lanjut) dilarang menandai pengarsipan');
$t($id1, 'MENUNGGU_PENGARSIPAN', $sekreToken, 200, 'SELESAI -> MENUNGGU_PENGARSIPAN (persuratan)');
// Q2 locked: yang menandai DIARSIPKAN = ARSIPARIS, bukan Sekretaris/pegawai.
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIARSIPKAN'], $sekreToken);
check('Sekretaris dilarang menandai DIARSIPKAN (khusus Arsiparis)', $code === 403, json_encode($r));
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIARSIPKAN'], $stafToken);
check('pegawai dilarang menandai DIARSIPKAN (khusus Arsiparis)', $code === 403, json_encode($r));
$t($id1, 'DIARSIPKAN', $arsipToken, 200, 'MENUNGGU_PENGARSIPAN -> DIARSIPKAN (arsiparis)');
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('arsip final: stage DIARSIPKAN + archived_at', ($d['letter']['currentStage'] ?? '') === 'DIARSIPKAN' && !empty($d['letter']['archivedAt'] ?? null));
check('log kendali >= 12 entri riwayat', count($d['logs'] ?? []) >= 12, 'got ' . count($d['logs'] ?? []));

// 8b. Rute KEBIJAKAN + jalur pintas TERREGISTRASI -> DIDISPOSISIKAN (surat RAHASIA #2)
$t($id2, 'VERIFIKASI_ALAMAT', $sekreToken, 200, 'RAHASIA: DITERIMA -> VERIFIKASI_ALAMAT (sekre)');
$t($id2, 'DISORTIR', $sekreToken, 200, 'RAHASIA: VERIFIKASI_ALAMAT -> DISORTIR (sekre)');
$t($id2, 'MENUNGGU_PENGARAHAN', $sekreToken, 200, 'RAHASIA: DISORTIR -> MENUNGGU_PENGARAHAN (sekre)');
$t($id2, 'DIBACA_PENGARAH', $sekreToken, 200, 'RAHASIA: pengarahan dipegang Kasubag/Sekretaris (SOP langkah 4-7)');
$t($id2, 'TERREGISTRASI', $sekreToken, 200, 'RAHASIA: DIBACA_PENGARAH -> TERREGISTRASI (sekre)');
// Jalur pintas tidak boleh melewati keputusan: masuk DIDISPOSISIKAN selalu butuh rute.
[$code, $r] = req('POST', "$base/control/$id2/transition", ['toStage' => 'DIDISPOSISIKAN'], $sekreToken);
check('pintas TERREGISTRASI -> DIDISPOSISIKAN tanpa rute ditolak 422', $code === 422, json_encode($r));
// P2: RAHASIA juga wajib rekomendasi sebelum MENUNGGU_DISPOSISI.
[$code, $rRek2] = req('POST', "$base/control/$id2/rekomendasi",
    ['rekomendasiRoute' => 'KEBIJAKAN', 'notes' => 'Rekomendasi uji e2e: perlu arahan pimpinan.'], $sekreToken);
check('RAHASIA: rekomendasi rute tersimpan', $code === 200 && ($rRek2['rekomendasiRoute'] ?? '') === 'KEBIJAKAN', json_encode($rRek2));
$t($id2, 'MENUNGGU_DISPOSISI', $sekreToken, 200, 'RAHASIA: TERREGISTRASI -> MENUNGGU_DISPOSISI (sekre, dengan rekomendasi)');
// Rute KEBIJAKAN: naik ke pimpinan, dan cabang ke pelaksana dikunci sampai pimpinan memutuskan.
$t($id2, 'DIDISPOSISIKAN', $sekreToken, 200, 'RAHASIA: MENUNGGU_DISPOSISI -> DIDISPOSISIKAN (sekre, rute KEBIJAKAN)', ['dispositionRoute' => 'KEBIJAKAN']);
[$code, $d2] = req('GET', "$base/control/$id2", null, $sekreToken);
check('rute keputusan tersimpan = KEBIJAKAN', ($d2['letter']['dispositionRoute'] ?? '') === 'KEBIJAKAN');
[$code, $r] = req('POST', "$base/control/$id2/transition", ['toStage' => 'DITERUSKAN_KE_PELAKSANA'], $sekreToken);
check('rute KEBIJAKAN dikunci: langsung ke pelaksana ditolak 422', $code === 422 && in_array('MENUNGGU_KEBIJAKAN_PIMPINAN', $r['allowedByRoute'] ?? [], true), json_encode($r));
[$code, $d2] = req('GET', "$base/control/$id2", null, $sekreToken);
$d2Stages = array_column($d2['allowedTransitions'] ?? [], 'toStage');
check('UI surat rute KEBIJAKAN menawarkan naik ke pimpinan (bukan pintas ke pelaksana)',
    in_array('MENUNGGU_KEBIJAKAN_PIMPINAN', $d2Stages, true) && !in_array('DITERUSKAN_KE_PELAKSANA', $d2Stages, true),
    json_encode($d2Stages));
$t($id2, 'MENUNGGU_KEBIJAKAN_PIMPINAN', $sekreToken, 200, 'RAHASIA: DIDISPOSISIKAN -> MENUNGGU_KEBIJAKAN_PIMPINAN (sekre)');

// 9. Keamanan surat rahasia


[$code] = req('GET', "$base/control/$id2", null, $stafToken);
check('staf dilarang lihat detail RAHASIA (403)', $code === 403);
[$code, $list] = req('GET', "$base/control", null, $stafToken);
check('daftar Buku Kendali staf tanpa RAHASIA', !in_array($id2, array_column($list, 'id'), true) && in_array($id1, array_column($list, 'id'), true));
[$code, $list2] = req('GET', "$base/incoming", null, $stafToken);
check('daftar surat staf tanpa RAHASIA', !in_array($id2, array_column($list2, 'id'), true));
[$code] = req('GET', "$base/incoming/$id2");
check('detail publik RAHASIA tanpa token 403', $code === 403);
[$code, $list3] = req('GET', "$base/control", null, $adminToken);
check('admin melihat semua termasuk RAHASIA', in_array($id2, array_column($list3, 'id'), true));
[$code, $allowed] = req('GET', "$base/control/$id1", null, $stafToken);
check('detail BIASA staf 200 + allowedNextStages array', $code === 200 && is_array($allowed['allowedNextStages']));

// 10. Koreksi & hapus surat di Buku Kendali. Fitur ini asalnya hanya ada di
// menu "Surat Masuk" lama; sekarang /surat-masuk mengarah ke /v2/buku-kendali,
// jadi kemampuan Edit/Hapus + unggah lampiran pindah ke sana.
// Surat uji baru: $id1/$id2 sudah terkunci (DIARSIPKAN & MENUNGGU_KEBIJAKAN_PIMPINAN).
[$code, $l3] = req('POST', "$base/incoming", [
    'agendaNumber' => "AGD/2026/6$runId", 'letterNumber' => '004/KOREKSI/IX/2026', 'letterDate' => '2026-09-22',
    'receivedDate' => '2026-09-24', 'sender' => 'Dinas Koreksi', 'subject' => 'Surat uji koreksi',
    'classification' => 'DINAS', 'securityLevel' => 'BIASA', 'urgencyLevel' => 'NORMAL',
    'sourceChannel' => 'POS', 'letterCategory' => 'DINAS', 'documentType' => 'SURAT_DINAS',
    'archiveCode' => 'HK1.1.2',
], $sekreToken);
check('surat uji koreksi dibuat (DITERIMA)', $code === 201 && ($l3['currentStage'] ?? '') === 'DITERIMA', json_encode($l3));
$id3 = $l3['id'] ?? '';

// 10a. Detail Buku Kendali mengirim kewenangan koreksi + tanggal terima.
[$code, $d3] = req('GET', "$base/control/$id3", null, $adminToken);
check('detail: canEdit & canDelete true (ADMIN, tahap DITERIMA)',
    $code === 200 && ($d3['canEdit'] ?? false) === true && ($d3['canDelete'] ?? false) === true,
    json_encode([$d3['canEdit'] ?? null, $d3['canDelete'] ?? null]));
check('detail: hasDispositions false', ($d3['hasDispositions'] ?? null) === false);
check('detail: correctableStages dikirim server', in_array('DITERIMA', $d3['correctableStages'] ?? [], true));
check('detail: receivedDate & letterDate terisi', !empty($d3['letter']['receivedDate']) && !empty($d3['letter']['letterDate']));
[$code, $d3s] = req('GET', "$base/control/$id3", null, $sekreToken);
check('detail: SEKRETARIS boleh koreksi, tidak boleh hapus',
    ($d3s['canEdit'] ?? false) === true && ($d3s['canDelete'] ?? false) === false);

// 10b. Daftar Buku Kendali memuat kolom untuk filter tanggal, ikon lampiran, dan ekspor CSV.
[$code, $rows] = req('GET', "$base/control", null, $adminToken);
$row3 = null;
foreach ($rows as $r) { if (($r['id'] ?? '') === $id3) { $row3 = $r; } }
check('daftar Buku Kendali memuat receivedDate', $row3 && !empty($row3['receivedDate']), json_encode($row3['receivedDate'] ?? null));
check('daftar Buku Kendali memuat letterDate', $row3 && array_key_exists('letterDate', $row3));
check('daftar Buku Kendali memuat filePath', $row3 && array_key_exists('filePath', $row3));

// 10c. PUT koreksi field v2 + status kode arsip dihitung ulang.
[$code, $put] = req('PUT', "$base/incoming/$id3", [
    'securityLevel' => 'TERBATAS', 'urgencyLevel' => 'PENTING', 'sourceChannel' => 'EMAIL',
    'documentType' => 'NOTA_DINAS', 'letterCategory' => 'PRIBADI', 'classification' => 'PRIBADI',
    'archiveCode' => 'KU1.1.1', 'subject' => 'Surat uji koreksi (diperbarui)', 'description' => 'catatan koreksi',
], $sekreToken);
check('PUT koreksi oleh SEKRETARIS -> 200', $code === 200, json_encode($put));
check('PUT mengubah securityLevel -> TERBATAS', ($put['securityLevel'] ?? '') === 'TERBATAS');
check('PUT mengubah urgensi/sumber/jenis naskah',
    ($put['urgencyLevel'] ?? '') === 'PENTING' && ($put['sourceChannel'] ?? '') === 'EMAIL' && ($put['documentType'] ?? '') === 'NOTA_DINAS');
check('PUT mengubah jenis surat + classification', ($put['letterCategory'] ?? '') === 'PRIBADI' && ($put['classification'] ?? '') === 'PRIBADI');
check('PUT menghitung ulang archiveCodeStatus',
    in_array($put['archiveCodeStatus'] ?? null, ['OFFICIAL', 'PENDING_VALIDATION'], true), json_encode($put['archiveCodeStatus'] ?? null));

// 10d. Perubahan benar-benar tersimpan, bukan cuma di respons.
[$code, $again] = req('GET', "$base/incoming/$id3", null, $adminToken);
check('perubahan tersimpan: securityLevel + subject',
    ($again['securityLevel'] ?? '') === 'TERBATAS' && ($again['subject'] ?? '') === 'Surat uji koreksi (diperbarui)',
    json_encode([$again['securityLevel'] ?? null, $again['subject'] ?? null]));

// 10e. Nilai enum di luar daftar resmi ditolak 400 + menunjuk field-nya.
[$code, $bad] = req('PUT', "$base/incoming/$id3", ['securityLevel' => 'PENTING'], $adminToken);
check('PUT securityLevel ngawur -> 400 + errors.securityLevel', $code === 400 && isset($bad['errors']['securityLevel']), json_encode($bad));
[$code, $bad2] = req('PUT', "$base/incoming/$id3", ['archiveCode' => 'ZZ9.9'], $adminToken);
check('PUT kode arsip tanpa primer resmi -> 400', $code === 400 && isset($bad2['errors']['archiveCode']), json_encode($bad2));

// 10f. Role & batas tahap.
[$code] = req('PUT', "$base/incoming/$id3", ['subject' => 'x'], $stafToken);
check('STAFF PUT koreksi -> 403', $code === 403);
[$code] = req('PUT', "$base/incoming/$id3", ['subject' => 'x'], $pimpinToken);
check('PIMPINAN PUT koreksi -> 403', $code === 403);
[$code] = req('DELETE', "$base/incoming/$id3", null, $sekreToken);
check('SEKRETARIS DELETE -> 403 (hanya ADMIN)', $code === 403);
[$code, $lock1] = req('PUT', "$base/incoming/$id1", ['subject' => 'x'], $adminToken);
check('PUT surat DIARSIPKAN -> 422 (tahap terkunci)', $code === 422, json_encode($lock1));
check('pesan 422 menyebut tahap yang masih boleh dikoreksi', str_contains((string)($lock1['message'] ?? ''), 'DITERIMA'));
[$code, $lock2] = req('DELETE', "$base/incoming/$id2", null, $adminToken);
check('DELETE surat yang sudah lewat keputusan disposisi -> 422', $code === 422, json_encode($lock2));

// 10g. Unggah lampiran (WAJIB tetap ada di halaman Surat Masuk v2 + dialog
// koreksi Buku Kendali): POST multipart, ganti berkas, lalu hapus surat.
$pdf1 = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF\n";
$pdf2 = "%PDF-1.4\n2 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF\n";
// file_path berisi "/uploads/<berkas>" relatif terhadap ROOT repo (Upload::save
// menulis ke dirname(__DIR__, 2) dari api-php/lib/Upload.php = root repo).
$uploadsDir = __DIR__;
[$code, $up] = reqMulti('POST', "$base/incoming", ['data' => json_encode([
    'agendaNumber' => "AGD/2026/4$runId", 'letterNumber' => '005/UPLOAD/IX/2026', 'letterDate' => '2026-09-22',
    'receivedDate' => '2026-09-24', 'sender' => 'Pengirim Unggah', 'subject' => 'Surat uji unggah lampiran',
    'classification' => 'DINAS', 'securityLevel' => 'BIASA', 'archiveCode' => 'HK1.1.2',
])], ['file' => ['name' => 'scan.pdf', 'content' => $pdf1, 'type' => 'application/pdf']], $sekreToken);
$id4 = $up['id'] ?? '';
$pathA = (string) ($up['filePath'] ?? '');
check('POST multipart + lampiran -> 201 + filePath /uploads/', $code === 201 && str_starts_with($pathA, '/uploads/'), json_encode($up));
check('berkas lampiran tersimpan di root repo/uploads', $pathA !== '' && fileOnDisk($uploadsDir . $pathA), $uploadsDir . $pathA);

// Tautan "Lihat lampiran scan" di Buku Kendali harus benar-benar bisa dibuka.
// Di server pengembangan uploads/ berada di luar document root api-php/, jadi
// api-php/router_dev.php yang menyajikannya (di produksi uploads/ satu document
// root dengan dist/, tanpa bantuan router).
$origin = preg_replace('#/api$#', '', $base);
[$upCode, $upType, $upBody] = fetchRaw($origin . $pathA);
check('lampiran bisa diambil lewat HTTP (200 + application/pdf)', $upCode === 200 && $upType === 'application/pdf' && str_starts_with($upBody, '%PDF'), "$upCode $upType");
[$travCode] = fetchRaw($origin . '/uploads/..%2fconfig.php');
check('permintaan berkas di luar uploads/ ditolak 404', $travCode === 404, (string)$travCode);

// Lampiran yang isinya bukan PDF (walau bernama .php) ditolak deteksi magic bytes.
// Dikirim sebagai POST + _method=PUT karena PHP tidak memparsing multipart pada
// request PUT (sama seperti UI: lihat handlers/incoming.php).
[$code, $badFile] = reqMulti('POST', "$base/incoming/$id4", ['_method' => 'PUT', 'data' => json_encode(['subject' => 'Surat uji unggah lampiran'])],
    ['file' => ['name' => 'jahat.php', 'content' => "<?php echo 'x';", 'type' => 'application/pdf']], $adminToken);
check('lampiran .php berisi kode -> 400', $code === 400, json_encode($badFile));

// Ganti lampiran: berkas lama wajib dibersihkan dari disk.
[$code, $up2] = reqMulti('POST', "$base/incoming/$id4", ['_method' => 'PUT', 'data' => json_encode(['securityLevel' => 'TERBATAS', 'archiveCode' => 'HK1.1.2'])],
    ['file' => ['name' => 'scan2.pdf', 'content' => $pdf2, 'type' => 'application/pdf']], $adminToken);
$pathB = (string) ($up2['filePath'] ?? '');
check('PUT ganti lampiran -> 200 + filePath baru', $code === 200 && $pathB !== '' && $pathB !== $pathA, json_encode($up2));
check('berkas lampiran pengganti ada di disk', $pathB !== '' && fileOnDisk($uploadsDir . $pathB), $pathB);
check('berkas lampiran lama dihapus dari disk', $pathA !== '' && !fileOnDisk($uploadsDir . $pathA), $pathA);

// 10h. Hapus surat yang masih boleh dikoreksi: lampiran ikut terhapus, record 404.
[$code] = req('DELETE', "$base/incoming/$id4", null, $sekreToken);
check('SEKRETARIS DELETE surat berlampiran -> 403', $code === 403);
[$code, $delOk] = req('DELETE', "$base/incoming/$id4", null, $adminToken);
check('DELETE surat berlampiran oleh ADMIN -> 200', $code === 200, json_encode($delOk));
check('berkas lampiran ikut terhapus saat surat dihapus', $pathB !== '' && !fileOnDisk($uploadsDir . $pathB), $pathB);
[$code] = req('GET', "$base/incoming/$id4", null, $adminToken);
check('surat yang dihapus -> 404', $code === 404);

// 10i. Aturan "surat sudah beredar": ada disposisi -> terkunci walau tahapnya
// masih boleh dikoreksi. Surat uji ini SENGAJA dibiarkan di database uji
// (tidak ada endpoint penghapus disposisi), sebagai bukti guard-nya bekerja.
[$code, $users] = req('GET', "$base/users/list", null, $adminToken);
$toUserId = '';
if (is_array($users)) { foreach ($users as $u) { if (!empty($u['id'])) { $toUserId = $u['id']; break; } } }
check('daftar pengguna untuk uji disposisi tersedia', $toUserId !== '', json_encode(array_slice((array) $users, 0, 1)));
[$code, $l5] = req('POST', "$base/incoming", [
    'agendaNumber' => "AGD/2026/3$runId", 'letterNumber' => '006/BERDISPOSISI/IX/2026', 'letterDate' => '2026-09-22',
    'receivedDate' => '2026-09-24', 'sender' => 'Dinas Disposisi', 'subject' => 'Surat uji aturan disposisi',
    'classification' => 'DINAS', 'securityLevel' => 'BIASA', 'archiveCode' => 'HK1.1.2',
], $sekreToken);
$id5 = $l5['id'] ?? '';
check('surat uji aturan disposisi dibuat (DITERIMA)', $code === 201 && $id5 !== '', json_encode($l5));
[$code, $disp] = req('POST', "$base/dispositions", [
    'incomingLetterId' => $id5, 'toUserId' => $toUserId, 'instruction' => 'Uji: instruksi agar surat terkunci',
], $adminToken);
check('buat disposisi untuk surat uji -> 201', $code === 201 && !empty($disp['id']), json_encode($disp));
[$code, $d5] = req('GET', "$base/control/$id5", null, $adminToken);
check('detail: hasDispositions true + canEdit & canDelete false',
    ($d5['hasDispositions'] ?? null) === true && ($d5['canEdit'] ?? true) === false && ($d5['canDelete'] ?? true) === false,
    json_encode([$d5['hasDispositions'] ?? null, $d5['canEdit'] ?? null, $d5['canDelete'] ?? null]));
check('detail: canCorrectStage tetap true (tahap masih boleh dikoreksi)', ($d5['canCorrectStage'] ?? null) === true);
[$code, $lockDisp] = req('PUT', "$base/incoming/$id5", ['subject' => 'x'], $adminToken);
check('PUT surat berdisposisi -> 422 walau tahap DITERIMA', $code === 422, json_encode($lockDisp));
check('pesan 422 menyebut disposisi', str_contains((string) ($lockDisp['message'] ?? ''), 'disposisi'));
[$code, $lockDel] = req('DELETE', "$base/incoming/$id5", null, $adminToken);
check('DELETE surat berdisposisi -> 422', $code === 422, json_encode($lockDel));

// 10j. Hapus surat uji koreksi (tahap DITERIMA, tanpa disposisi) -> 200 lalu 404.
[$code] = req('DELETE', "$base/incoming/$id3", null, $adminToken);
check('DELETE surat tahap DITERIMA oleh ADMIN -> 200', $code === 200);
[$code] = req('GET', "$base/incoming/$id3", null, $adminToken);
check('surat uji koreksi sudah terhapus -> 404', $code === 404);

echo "\nHASIL: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);

