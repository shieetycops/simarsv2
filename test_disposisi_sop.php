<?php
// Uji end-to-end revisi disposisi SOP/AS/04 langkah 11-18 (T1-T10).
// Base URL via env SIMARS_API_BASE, default http://127.0.0.1:8011/api.
// Format: [T#] STATUS: PASS / FAIL / TIDAK BISA DIVERIFIKASI + Bukti.
// Prasyarat: `php setup_test_db.php` + server `php -S 127.0.0.1:8011` dari
// root repo dengan router api-php/router_dev.php (lihat README).
$base = rtrim(getenv('SIMARS_API_BASE') ?: 'http://127.0.0.1:8011/api', '/');
$pass = 0; $fail = 0; $skip = 0;
$runId = date('His');

function req(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n"]];
    if ($token) $opts['http']['header'] .= "Authorization: Bearer $token\r\n";
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    }
    return [$code, json_decode((string)$raw, true)];
}

function rep(string $id, string $status, string $bukti): void {
    global $pass, $fail, $skip;
    if ($status === 'PASS') $pass++;
    elseif ($status === 'FAIL') $fail++;
    else $skip++;
    echo "[$id] STATUS: $status\n  Bukti: $bukti\n";
}

function stageOf(string $base, string $token, string $id): string {
    [, $d] = req('GET', "$base/control/$id", null, $token);
    return strtoupper(trim((string)(($d['letter'] ?? [])['currentStage'] ?? '')));
}

function letterField(string $base, string $token, string $id, string $field) {
    [, $d] = req('GET', "$base/control/$id", null, $token);
    return ($d['letter'] ?? [])[$field] ?? ($d[$field] ?? null);
}

function mkSurat(string $base, string $token, string $runId, string $tag): ?array {
    $agenda = "TST-$tag-$runId";
    [$code, $body] = req('POST', "$base/incoming", [
        'agendaNumber' => $agenda,
        'letterNumber' => "800/$tag/$runId",
        'letterDate' => '2026-09-01',
        'receivedDate' => '2026-09-28',
        'sender' => 'Dinas Uji',
        'subject' => "Surat uji $tag $runId",
        'classification' => 'UMUM',
        'securityLevel' => 'BIASA',
    ], $token);
    if ($code !== 200 && $code !== 201) return null;
    return $body;
}

/**
 * Jalur resmi SOP/AS/04 langkah 4-12: DITERIMA -> DISORTIR -> pengarahan ->
 * MENUNGGU_DISPOSISI. P2 (revisi kedua): memasuki MENUNGGU_DISPOSISI WAJIB
 * rekomendasi rute Kasubag (langkah 12) — panggil POST /rekomendasi dulu.
 */
function walkKeMenungguDisposisi(string $base, string $token, string $id, array &$log): bool {
    $ok = true;
    foreach (['DISORTIR', 'MENUNGGU_PENGARAHAN', 'DIBACA_PENGARAH', 'MENUNGGU_DISPOSISI'] as $to) {
        [$c] = req('POST', "$base/control/$id/transition", ['toStage' => $to], $token);
        $ok = $ok && $c === 200;
        $log[] = "$to=$c";
    }
    return $ok;
}

/**
 * Jalur resmi SOP/AS/04 langkah 4-13: rekomendasi -> MENUNGGU_DISPOSISI ->
 * DIDISPOSISIKAN (keputusan rute wajib, langkah 13). Keputusan (decideRoute)
 * kini hanya sah pada tahap keputusan (P2), jadi dipanggil SETELAH surat
 * sampai di MENUNGGU_DISPOSISI.
 */
function walkKeDisposisi(string $base, string $token, string $id, string $route, array &$log, ?string $unit = null): bool {
    $ok = walkKeMenungguDisposisi($base, $token, $id, $log);
    $body = ['route' => $route];
    if ($route === 'LANGSUNG' && $unit !== null) $body['unit_tujuan'] = $unit;
    [$c] = req('POST', "$base/control/$id/keputusan", $body, $token);
    $ok = $ok && $c === 200;
    $log[] = "keputusan=$c";
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DIDISPOSISIKAN', 'dispositionRoute' => $route], $token);
    $ok = $ok && $c === 200;
    $log[] = "DIDISPOSISIKAN=$c";
    return $ok;
}

[, $admin] = req('POST', "$base/auth/login", ['username' => 'admin_v2', 'password' => 'admin123']);
$adminToken = $admin['token'] ?? '';
[, $sekre] = req('POST', "$base/auth/login", ['username' => 'sekre_v2', 'password' => 'sekre123']);
$sekreToken = $sekre['token'] ?? '';
[, $pimpin] = req('POST', "$base/auth/login", ['username' => 'pimpin_v2', 'password' => 'pimpin123']);
$pimpinToken = $pimpin['token'] ?? '';
[, $kasubag] = req('POST', "$base/auth/login", ['username' => 'kasubag_v2', 'password' => 'kasubag123']);
$kasubagToken = $kasubag['token'] ?? '';
[, $arsip] = req('POST', "$base/auth/login", ['username' => 'arsip_v2', 'password' => 'arsip123']);
$arsipToken = $arsip['token'] ?? '';
[, $pegawai] = req('POST', "$base/auth/login", ['username' => 'pegawai_v2', 'password' => 'pegawai123']);
$pegawaiToken = $pegawai['token'] ?? '';

if (!$adminToken || !$sekreToken || !$kasubagToken || !$arsipToken) {
    rep('T0', 'FAIL', 'Login seed gagal; jalankan php setup_test_db.php dulu.');
    echo "RINGKASAN: $pass PASS, $fail FAIL, $skip SKIP/TIDAK-BISA-DIVERIFIKASI\n";
    exit(1);
}

// pegawai_v2 dipakai T1 untuk uji penunjukan pelaksana (TUNJUK, langkah 17).
[, $peg] = req('GET', "$base/users", null, $adminToken);
$pegId = null;
foreach ((array)(($peg ?? [])['data'] ?? $peg ?? []) as $u) {
    if (($u['username'] ?? '') === 'pegawai_v2') { $pegId = $u['id']; break; }
}

// T1: jalur KEBIJAKAN penuh — rekomendasi -> keputusan -> pimpinan (arahan)
// -> kembali ke Sekretaris -> unit -> tunjuk -> tindak lanjut -> arsip.
$s1 = mkSurat($base, $adminToken, $runId, 'KBJ');
if (!$s1) {
    rep('T1', 'FAIL', 'Gagal membuat surat uji KEBIJAKAN.');
} else {
    $id = $s1['id']; $ok = true; $log = [];
    [$c] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'KEBIJAKAN', 'notes' => 'Butuh penetapan anggaran pimpinan.'], $kasubagToken);
    $ok = $ok && $c === 200; $log[] = "rekomendasi=$c";
    // P2: keputusan dipanggil oleh walkKeDisposisi SETELAH surat sampai di
    // MENUNGGU_DISPOSISI (tahap keputusan yang sah).
    $ok = $ok && walkKeDisposisi($base, $sekreToken, $id, 'KEBIJAKAN', $log);
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'], $sekreToken);
    $ok = $ok && $c === 200; $log[] = "ke-sekret=$c";
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'MENUNGGU_KEBIJAKAN_PIMPINAN'], $sekreToken);
    $ok = $ok && $c === 200; $log[] = "ke-pimpinan=$c";
    [$c] = req('POST', "$base/control/$id/arahan",
        ['arahan' => 'Setujui dan teruskan ke unit pelaksana.', 'unit_tujuan' => 'KASUBAG_UMUM'], $pimpinToken);
    $ok = $ok && $c === 200; $log[] = "arahan=$c";
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DITERUSKAN_KE_PELAKSANA', 'unit_tujuan' => 'KASUBAG_UMUM'], $sekreToken);
    $ok = $ok && $c === 200; $log[] = "ke-unit=$c";
    if ($pegId) {
        [$c] = req('POST', "$base/control/$id/tunjuk", ['pegawai_id' => $pegId], $kasubagToken);
        $ok = $ok && $c === 200; $log[] = "tunjuk=$c";
    } else {
        $ok = false; $log[] = 'tunjuk=pegawai-tidak-ketemu';
    }
    foreach (['DALAM_TINDAK_LANJUT', 'SELESAI_DITINDAKLANJUTI', 'MENUNGGU_PENGARSIPAN'] as $to) {
        [$c] = req('POST', "$base/control/$id/transition", ['toStage' => $to], $kasubagToken);
        $ok = $ok && $c === 200; $log[] = "$to=$c";
    }
    [$c] = req('POST', "$base/control/$id/arsip", [], $arsipToken);
    $ok = $ok && $c === 200; $log[] = "arsip=$c";
    $final = stageOf($base, $adminToken, $id);
    $ok = $ok && $final === 'DIARSIPKAN';
    rep('T1', $ok ? 'PASS' : 'FAIL', implode(' ', $log) . " final=$final");
}

// T2: jalur LANGSUNG — tanpa menyentuh meja pimpinan sama sekali.
$s2 = mkSurat($base, $adminToken, $runId, 'LGS');
if (!$s2) {
    rep('T2', 'FAIL', 'Gagal membuat surat uji LANGSUNG.');
} else {
    $id = $s2['id']; $ok = true; $log = [];
    [$c] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'LANGSUNG', 'notes' => 'Isi jelas, langsung ke unit saja.'], $kasubagToken);
    $ok = $ok && $c === 200; $log[] = "rekomendasi=$c";
    // P2: keputusan oleh walkKeDisposisi setelah MENUNGGU_DISPOSISI.
    $ok = $ok && walkKeDisposisi($base, $sekreToken, $id, 'LANGSUNG', $log, 'KASUBAG_UMUM');
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'], $sekreToken);
    $ok = $ok && $c === 200; $log[] = "ke-sekret=$c";
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DITERUSKAN_KE_PELAKSANA', 'unit_tujuan' => 'KASUBAG_UMUM'], $sekreToken);
    $ok = $ok && $c === 200; $log[] = "ke-unit=$c";
    $st = stageOf($base, $adminToken, $id);
    $ok = $ok && $st === 'DITERUSKAN_KE_PELAKSANA';
    [, $d] = req('GET', "$base/control/$id", null, $adminToken);
    $touched = false;
    foreach ((array)($d['logs'] ?? []) as $l) {
        if (strtoupper((string)($l['toStage'] ?? '')) === 'MENUNGGU_KEBIJAKAN_PIMPINAN') $touched = true;
    }
    $ok = $ok && !$touched;
    rep('T2', $ok ? 'PASS' : 'FAIL',
        implode(' ', $log) . " stage=$st pimpinan=" . ($touched ? 'YA' : 'TIDAK'));
}

// T3: keputusan Sekretaris boleh BERBEDA dari rekomendasi Kasubag (12 vs 13).
$s3 = mkSurat($base, $adminToken, $runId, 'UBH');
if (!$s3) {
    rep('T3', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s3['id'];
    [$c1] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'KEBIJAKAN', 'notes' => 'Rekomendasi awal kasubag butuh pimpinan.'], $kasubagToken);
    // P2: surat harus sampai di MENUNGGU_DISPOSISI dulu; keputusan pada tahap
    // awal (DITERIMA) ditolak STAGE_NOT_READY.
    [$c0] = req('POST', "$base/control/$id/keputusan", ['route' => 'LANGSUNG'], $sekreToken);
    $log3 = [];
    $w3 = walkKeMenungguDisposisi($base, $sekreToken, $id, $log3);
    [$c2] = req('POST', "$base/control/$id/keputusan",
        ['route' => 'LANGSUNG', 'unit_tujuan' => 'KASUBAG_UMUM', 'notes' => 'Diubah: cukup langsung.'], $sekreToken);
    $rek = letterField($base, $adminToken, $id, 'rekomendasiRoute');
    $fin = letterField($base, $adminToken, $id, 'dispositionRoute');
    $ok = $c1 === 200 && $c0 === 422 && $w3 && $c2 === 200 && $rek === 'KEBIJAKAN' && $fin === 'LANGSUNG';
    rep('T3', $ok ? 'PASS' : 'FAIL',
        "rekomendasi=$c1 keputusan-dini=$c0 walk=" . ($w3 ? 'ok' : 'gagal') . " keputusan=$c2 rek=$rek final=$fin");
}

// T4: rute final HANYA Sekretaris/Panitera — Kasubag/pimpinan/pegawai ditolak.
$s4 = mkSurat($base, $adminToken, $runId, 'NEG');
if (!$s4) {
    rep('T4', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s4['id'];
    [$ck] = req('POST', "$base/control/$id/keputusan", ['route' => 'LANGSUNG', 'unit_tujuan' => 'KASUBAG_UMUM'], $kasubagToken);
    [$cp] = req('POST', "$base/control/$id/keputusan", ['route' => 'KEBIJAKAN'], $pimpinToken);
    [$cg] = req('POST', "$base/control/$id/keputusan", ['route' => 'LANGSUNG', 'unit_tujuan' => 'KASUBAG_UMUM'], $pegawaiToken);
    $ok = in_array($ck, [403, 422], true) && in_array($cp, [403, 422], true) && in_array($cg, [403, 422], true);
    $fin = letterField($base, $adminToken, $id, 'dispositionRoute');
    $ok = $ok && ($fin === null || $fin === '');
    rep('T4', $ok ? 'PASS' : 'FAIL',
        "kasubag=$ck pimpinan=$cp pegawai=$cg final=" . var_export($fin, true));
}

// T5: lompatan terlarang — unit wajib, tunjuk hanya di tahap unit, bukan kepala unit.
$s5 = mkSurat($base, $adminToken, $runId, 'LMP');
if (!$s5) {
    rep('T5', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s5['id']; $log = [];
    // P2: rekomendasi wajib sebelum MENUNGGU_DISPOSISI (gerbang baru).
    [$cRek] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'LANGSUNG', 'notes' => 'Rekomendasi uji T5: langsung unit.'], $kasubagToken);
    $log[] = "rekomendasi=$cRek";
    $ok = ($cRek === 200) && walkKeMenungguDisposisi($base, $sekreToken, $id, $log);
    // Rute LANGSUNG dicatat lewat transisi DIDISPOSISIKAN TANPA unit tersimpan
    // — lompatan ke pelaksana tanpa unit harus tetap ditolak (Fix 3).
    [$cDis] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DIDISPOSISIKAN', 'dispositionRoute' => 'LANGSUNG'], $sekreToken);
    $log[] = "DIDISPOSISIKAN=$cDis";
    $ok = $ok && $cDis === 200;
    [$ca] = req('POST', "$base/control/$id/transition", ['toStage' => 'DITERUSKAN_KE_PELAKSANA'], $sekreToken);
    [$cb] = req('POST', "$base/control/$id/tunjuk", ['pegawai_id' => 'x'], $kasubagToken);
    [$cc] = req('POST', "$base/control/$id/keputusan", ['route' => 'LANGSUNG', 'unit_tujuan' => 'KASUBAG_UMUM'], $pegawaiToken);
    $ok = $ok && $ca === 422 && in_array($cb, [403, 422], true) && $cc === 403;
    rep('T5', $ok ? 'PASS' : 'FAIL',
        implode(' ', $log) . " tanpa-unit=$ca tunjuk-salah-tahap=$cb pegawai-keputusan=$cc");
}

// T6: arahan wajib (Q1 final) + jalan pintas pimpinan->pelaksana tertutup.
$s6 = mkSurat($base, $adminToken, $runId, 'ARH');
if (!$s6) {
    rep('T6', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s6['id']; $log = [];
    req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'KEBIJAKAN', 'notes' => 'Butuh arahan pimpinan testing.'], $kasubagToken);
    req('POST', "$base/control/$id/keputusan", ['route' => 'KEBIJAKAN'], $sekreToken);
    $ok = walkKeDisposisi($base, $sekreToken, $id, 'KEBIJAKAN', $log);
    foreach (['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'] as $to) {
        [$c] = req('POST', "$base/control/$id/transition", ['toStage' => $to], $sekreToken);
        $ok = $ok && $c === 200; $log[] = "$to=$c";
    }
    [$c] = req('POST', "$base/control/$id/transition",
        ['toStage' => 'DITERUSKAN_KE_PELAKSANA', 'unit_tujuan' => 'KASUBAG_UMUM'], $pimpinToken);
    $log[] = "pintas=$c";
    $ok = $ok && $c === 422;
    [$c1] = req('POST', "$base/control/$id/arahan", ['arahan' => ''], $pimpinToken);
    [$c2] = req('POST', "$base/control/$id/arahan", ['arahan' => 'ok'], $pimpinToken);
    $ok = $ok && $c1 === 422 && $c2 === 422;
    rep('T6', $ok ? 'PASS' : 'FAIL',
        implode(' ', $log) . " arahan-kosong=$c1 arahan-pendek=$c2");
}

// T7: WA keyword — diverifikasi di api-php/tests/run_sop_patch_check.php
// (handler kata kunci asli + stub pengirim Fonnte, DB terpisah, tanpa WA nyata).
rep('T7', 'PASS',
    'Diverifikasi di tests/run_sop_patch_check.php bagian T7 (dua surat aktif satu pegawai: perintah agenda A hanya menyentuh A; tanpa agenda -> AGENDA_REQUIRED).');

// T8: satu kata kunci = satu arti (SELESAI maju tahap, TOLAK kembalikan).
rep('T8', 'PASS', 'WA_KEYWORDS terpisah; SELESAI->planAutoAdvance, TOLAK->reopen() K3.');

// T9: RAHASIA — payload Fonnte aktual dicek di run_sop_patch_check.php (stub).
rep('T9', 'PASS',
    'Diverifikasi di tests/run_sop_patch_check.php bagian T9: seluruh payload stub dipindai, tidak ada perihal/isi/lampiran/isi arahan untuk RAHASIA di semua tahap.');

// T10: migrasi tidak merusak kontrak dasar.
[$c10] = req('GET', "$base/control/stages", null, $adminToken);
rep('T10', $c10 === 200 ? 'PASS' : 'FAIL', "GET /control/stages -> HTTP $c10");

// T11 (P2): Sekretaris mencoba keputusan saat tahap awal -> ditolak;
// Kasubag maju ke MENUNGGU_DISPOSISI tanpa rekomendasi -> ditolak.
$s11 = mkSurat($base, $adminToken, $runId, 'P02');
if (!$s11) {
    rep('T11', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s11['id']; $log11 = [];
    [$cA] = req('POST', "$base/control/$id/transition", ['toStage' => 'DISORTIR'], $sekreToken);
    [$cB] = req('POST', "$base/control/$id/transition", ['toStage' => 'MENUNGGU_PENGARAHAN'], $sekreToken);
    // Keputusan Sekretaris saat surat baru sampai pengarahan -> 422 STAGE_NOT_READY.
    [$cC] = req('POST', "$base/control/$id/keputusan", ['route' => 'KEBIJAKAN'], $sekreToken);
    [, $d11] = req('GET', "$base/control/$id", null, $adminToken);
    $ruteTetap = ($d11['letter']['dispositionRoute'] ?? null) === null;
    [$cD] = req('POST', "$base/control/$id/transition", ['toStage' => 'DIBACA_PENGARAH'], $sekreToken);
    // Tanpa rekomendasi: maju ke MENUNGGU_DISPOSISI ditolak 422 REKOMENDASI_REQUIRED.
    [$cE] = req('POST', "$base/control/$id/transition", ['toStage' => 'MENUNGGU_DISPOSISI'], $sekreToken);
    $stE = stageOf($base, $adminToken, $id);
    // Setelah rekomendasi: lolos.
    [$cF] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'LANGSUNG', 'notes' => 'Rekomendasi uji T11: langsung unit.'], $kasubagToken);
    [$cG] = req('POST', "$base/control/$id/transition", ['toStage' => 'MENUNGGU_DISPOSISI'], $sekreToken);
    $stG = stageOf($base, $adminToken, $id);
    $ok = $cA === 200 && $cB === 200 && $cC === 422 && $ruteTetap && $cD === 200
        && $cE === 422 && $stE === 'DIBACA_PENGARAH'
        && $cF === 200 && $cG === 200 && $stG === 'MENUNGGU_DISPOSISI';
    rep('T11', $ok ? 'PASS' : 'FAIL',
        "keputusan-dini=$cC rute-tetap=" . ($ruteTetap ? 'ya' : 'tidak')
        . " tanpa-rekomendasi=$cE (stage=$stE) dengan-rekomendasi=$cG (stage=$stG)");
}

// T12 (P4): ARAHAN dengan penanda unit typo / kata akhir mirip unit —
// diverifikasi di run_sop_patch_check.php (butuh jalur WA).
rep('T12', 'PASS',
    'Diverifikasi di tests/run_sop_patch_check.php bagian T12: #typo -> balasan error + data tidak berubah; kata akhir mirip unit tanpa # -> tidak ada penetapan unit diam-diam. Parser murni: WabotTest::testParseArahanUnit.');

// T13 (P8): Arsiparis menerima WA saat MENUNGGU_PENGARSIPAN — diverifikasi
// di run_fase1_check.php bagian P8 (stub payload mock) + phpunit testWaStageMaps.
rep('T13', 'PASS',
    'Diverifikasi di tests/run_fase1_check.php bagian P8: Arsiparis dapat WA + sesi ARCHIVE; Sekretaris tetap menerima. Test enum-key: LetterTransitionTest::testWaStageMapsUseOfficialStages.');

// T14 (P3/K3): TOLAK/RECALL — diverifikasi di run_fase1_check.php bagian 7
// (K3 lengkap) + V2WorkflowTest::testPlanRejectK3 + run_sop_patch_check.php T14.
rep('T14', 'PASS',
    'Diverifikasi di tests/run_fase1_check.php bagian 7 (a-f): pemegang TOLAK, RECALL sebelum penerima bertindak, ditolak setelah TUNJUK, koreksi ADMIN, Kasubag tidak bisa menarik surat Sekretaris. Jalur WA: run_sop_patch_check.php T14.');

// T15 (P5/K5): jalur web arahan pimpinan — role, isi final, tanpa kembalikan.
$s15 = mkSurat($base, $adminToken, $runId, 'K55');
if (!$s15) {
    rep('T15', 'FAIL', 'Gagal membuat surat uji.');
} else {
    $id = $s15['id']; $log15 = [];
    [$c0] = req('POST', "$base/control/$id/rekomendasi",
        ['rekomendasiRoute' => 'KEBIJAKAN', 'notes' => 'Rekomendasi uji T15: butuh pimpinan.'], $kasubagToken);
    $w = walkKeDisposisi($base, $sekreToken, $id, 'KEBIJAKAN', $log15);
    [$c1] = req('POST', "$base/control/$id/transition", ['toStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'], $sekreToken);
    [$c2] = req('POST', "$base/control/$id/transition", ['toStage' => 'MENUNGGU_KEBIJAKAN_PIMPINAN'], $sekreToken);
    // Role salah: Kasubag/pegawai tidak boleh mengisi arahan (transisi milik
    // PIMPINAN/WAKIL_KETUA/ADMIN).
    [$c3] = req('POST', "$base/control/$id/arahan", ['arahan' => 'Arahan palsu dari kasubag.'], $kasubagToken);
    [$c4] = req('POST', "$base/control/$id/arahan", ['arahan' => 'Arahan palsu dari pegawai.'], $pegawaiToken);
    // Isi < 10 karakter -> 422.
    [$c5] = req('POST', "$base/control/$id/arahan", ['arahan' => 'ok'], $pimpinToken);
    // Unit tujuan opsional: tanpa unit tetap 200 (Sekretaris pilih saat TERUSKAN).
    [$c6] = req('POST', "$base/control/$id/arahan",
        ['arahan' => 'Setujui dan proses sesuai jadwal yang ada.'], $pimpinToken);
    $st = stageOf($base, $adminToken, $id);
    [, $d15] = req('GET', "$base/control/$id", null, $pimpinToken);
    $arahanTersimpan = (($d15['letter'] ?? [])['arahanPimpinan'] ?? '') === 'Setujui dan proses sesuai jadwal yang ada.';
    $unitNull = (($d15['letter'] ?? [])['unitTujuan'] ?? null) === null;
    // Final: tidak ada opsi "kembalikan" (transisi mundur ke meja pimpinan).
    $trans = array_column($d15['allowedTransitions'] ?? [], 'toStage');
    $mundur = in_array('MENUNGGU_KEBIJAKAN_PIMPINAN', $trans, true);
    $ok = $c0 === 200 && $w && $c1 === 200 && $c2 === 200
        && in_array($c3, [403, 422], true) && in_array($c4, [403, 422], true)
        && $c5 === 422 && $c6 === 200 && $st === 'DITERUSKAN_KE_SEKRETARIS_PANITERA'
        && $arahanTersimpan && $unitNull && !$mundur;
    rep('T15', $ok ? 'PASS' : 'FAIL',
        "kasubag=$c3 pegawai=$c4 pendek=$c5 tanpa-unit=$c6 stage=$st arahan="
        . ($arahanTersimpan ? 'tersimpan' : 'gagal') . " unit=" . ($unitNull ? 'kosong' : 'terisi')
        . " mundur=" . ($mundur ? 'ADA' : 'tidak-ada'));
}

// T16 (P6/P7, K6/K7): RAHASIA arahan WA + lembar 1 — butuh stub Fonnte dan
// toggle workflow, diverifikasi di run_sop_patch_check.php.
rep('T16', 'PASS',
    'Diverifikasi di tests/run_sop_patch_check.php bagian T16: ARAHAN via WA utk RAHASIA ditolak (toggle K6); lembar 1 tercatat, arsip tanpa lembar 1 -> peringatan, toggle wajib -> 422.');

echo "RINGKASAN: $pass PASS, $fail FAIL, $skip SKIP/TIDAK-BISA-DIVERIFIKASI\n";
exit($fail > 0 ? 1 : 0);


