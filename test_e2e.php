<?php
// Uji end-to-end API SIMARS v2 terhadap server lokal (simars_v2).
$base = 'http://127.0.0.1:8011/api';
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

// 1. Login semua role
[, $admin] = req('POST', "$base/auth/login", ['username' => 'admin_v2', 'password' => 'admin123']);
$adminToken = $admin['token'] ?? '';
[, $sekre] = req('POST', "$base/auth/login", ['username' => 'sekre_v2', 'password' => 'sekre123']);
$sekreToken = $sekre['token'] ?? '';
[, $pimpin] = req('POST', "$base/auth/login", ['username' => 'pimpin_v2', 'password' => 'pimpin123']);
$pimpinToken = $pimpin['token'] ?? '';
[, $staf] = req('POST', "$base/auth/login", ['username' => 'staf_v2', 'password' => 'staf123']);
$stafToken = $staf['token'] ?? '';
check('login admin/sekre/pimpin/staf', $adminToken && $sekreToken && $pimpinToken && $stafToken);

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
check('verifikasi alamat SESAI', $code === 200 && ($r['addressStatus'] ?? '') === 'ALAMAT_SESAI');
[$code] = req('POST', "$base/control/$id1/address-verification", ['correct' => true], $sekreToken);
check('verifikasi alamat kedua kali 422', $code === 422);
[$code, $r] = req('POST', "$base/control/$id1/completeness", ['checks' => ['addressCorrect' => true, 'numberPresent' => true, 'datePresent' => true, 'subjectPresent' => true, 'attachmentComplete' => false, 'signaturePresent' => true]], $sekreToken);
check('checklist tidak lengkap -> TIDAK_LENGKAP', $code === 200 && ($r['completenessStatus'] ?? '') === 'TIDAK_LENGKAP');
[$code, $r] = req('POST', "$base/control/$id1/completeness", ['checks' => ['addressCorrect' => true, 'numberPresent' => true, 'datePresent' => true, 'subjectPresent' => true, 'attachmentComplete' => true, 'signaturePresent' => true], 'notes' => 'Lampiran dilengkapi'], $sekreToken);
check('checklist lengkap -> LENGKAP', $code === 200 && ($r['completenessStatus'] ?? '') === 'LENGKAP');

// 8. Happy path transisi 17 tahap dengan pembatasan role
$t = function (string $id, string $to, string $token, int $expect, string $name) use ($base) {
    [$code, $r] = req('POST', "$base/control/$id/transition", ['toStage' => $to], $token);
    check($name, $code === $expect, "(got $code " . json_encode($r) . ")");
    return $r;
};
$t($id1, 'VERIFIKASI_ALAMAT', $sekreToken, 200, 'DITERIMA -> VERIFIKASI_ALAMAT (sekre)');
$t($id1, 'DISORTIR', $sekreToken, 200, 'VERIFIKASI_ALAMAT -> DISORTIR (sekre)');
$t($id1, 'MENUNGGU_PENGARAHAN', $sekreToken, 200, 'DISORTIR -> MENUNGGU_PENGARAHAN (sekre)');
$t($id1, 'DIBACA_PENGARAH', $sekreToken, 403, 'sekre dilarang membaca pengarahan');
$t($id1, 'DIBACA_PENGARAH', $pimpinToken, 200, 'MENUNGGU_PENGARAHAN -> DIBACA_PENGARAH (pimpin)');
$t($id1, 'TERREGISTRASI', $sekreToken, 200, 'DIBACA_PENGARAH -> TERREGISTRASI (sekre)');
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('registered_at terisi setelah TERREGISTRASI', !empty($d['letter']['registeredAt']));
$t($id1, 'MENUNGGU_DISPOSISI', $sekreToken, 200, 'TERREGISTRASI -> MENUNGGU_DISPOSISI (sekre)');
$t($id1, 'DIDISPOSISIKAN', $sekreToken, 403, 'sekre dilarang mendisposisikan');
$t($id1, 'DIDISPOSISIKAN', $pimpinToken, 200, 'MENUNGGU_DISPOSISI -> DIDISPOSISIKAN (pimpin)');
$t($id1, 'DITERUSKAN_KE_PELAKSANA', $sekreToken, 200, 'DIDISPOSISIKAN -> KE PELAKSANA (sekre)');
$t($id1, 'DALAM_TINDAK_LANJUT', $stafToken, 200, 'KE PELAKSANA -> DALAM_TINDAK_LANJUT (staf)');
$t($id1, 'SELESAI_DITINDAKLANJUTI', $stafToken, 200, 'DALAM_TINDAK_LANJUT -> SELESAI (staf)');
$t($id1, 'MENUNGGU_PENGARSIPAN', $stafToken, 403, 'staf dilarang lanjut ke pengarsipan');
$t($id1, 'MENUNGGU_PENGARSIPAN', $sekreToken, 200, 'SELESAI -> MENUNGGU_PENGARSIPAN (sekre)');
[$code, $r] = req('POST', "$base/control/$id1/transition", ['toStage' => 'DIARSIPKAN'], $sekreToken);
check('MENUNGGU_PENGARSIPAN -> DIARSIPKAN (sekre)', $code === 200);
[$code, $d] = req('GET', "$base/control/$id1", null, $sekreToken);
check('arsip final: stage DIARSIPKAN + archived_at', ($d['letter']['currentStage'] ?? '') === 'DIARSIPKAN' && !empty($d['letter']['archivedAt'] ?? null));
check('log kendali >= 15 entri (12 transisi + verifikasi alamat + 2 checklist)', count($d['logs'] ?? []) >= 15, 'got ' . count($d['logs'] ?? []));

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

echo "\nHASIL: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);

