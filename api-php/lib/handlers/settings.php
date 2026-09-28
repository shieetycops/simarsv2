<?php
// /api/settings — replikasi backend/routes/settings.ts.
// GET publik (tanpa auth), PUT hanya ADMIN.
// Sub-resurs /settings/workflow = konfigurasi aturan disposisi (P1/K3/K6/K7),
// DEFAULT SEMENTARA - menunggu review pimpinan; hanya ADMIN.

// GET /settings — publik
if ($method === 'GET' && ($segments[1] ?? '') === '') {
    $s = Db::one("SELECT * FROM app_settings WHERE id = 'app_settings'");
    if (!$s) {
        Db::q("INSERT INTO app_settings (id) VALUES ('app_settings')");
        $s = Db::one("SELECT * FROM app_settings WHERE id = 'app_settings'");
    }
    echo json_encode($s);
    return;
}

// GET /settings/workflow — konfigurasi aturan disposisi (ADMIN).
if ($method === 'GET' && ($segments[1] ?? '') === 'workflow') {
    $user = Auth::requireAuth(Db::$pdo);
    Auth::requireRole($user, ['ADMIN']);
    echo json_encode(WorkflowConfig::all());
    return;
}

// PUT /settings/workflow — ubah aturan disposisi (ADMIN).
// Body: { tolakReasonMin?: int, waArahanRahasiaBlocked?: bool,
//         lembar1Wajib?: bool, waStageNumberReply?: bool }
if ($method === 'PUT' && ($segments[1] ?? '') === 'workflow') {
    $user = Auth::requireAuth(Db::$pdo);
    Auth::requireRole($user, ['ADMIN']);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    Db::q("INSERT IGNORE INTO workflow_settings (id) VALUES ('wf_settings')");
    $set = []; $vals = [];
    if (array_key_exists('tolakReasonMin', $b)) {
        $n = (int) $b['tolakReasonMin'];
        if ($n < 1 || $n > 500) {
            http_response_code(422);
            echo json_encode(['message' => 'tolakReasonMin harus 1-500.']);
            return;
        }
        $set[] = 'tolak_reason_min = ?'; $vals[] = $n;
    }
    foreach ([
        'waArahanRahasiaBlocked' => 'wa_arahan_rahasia_blocked',
        'lembar1Wajib'           => 'lembar1_wajib',
        'waStageNumberReply'     => 'wa_stage_number_reply',
    ] as $f => $c) {
        if (array_key_exists($f, $b)) {
            $set[] = "$c = ?"; $vals[] = !empty($b[$f]) ? 1 : 0;
        }
    }
    if ($set) {
        Db::q("UPDATE workflow_settings SET " . implode(', ', $set) . " WHERE id = 'wf_settings'", $vals);
        WorkflowConfig::reset();
    }
    logActivity((string) $user['id'], 'WORKFLOW_SETTINGS_UPDATE', 'WORKFLOW_SETTINGS', 'wf_settings',
        'Aturan disposisi diubah: ' . json_encode($b));
    echo json_encode(WorkflowConfig::all());
    return;
}

// PUT /settings — ADMIN
if ($method === 'PUT' && ($segments[1] ?? '') === '') {
    $user = Auth::requireAuth(Db::$pdo);
    Auth::requireRole($user, ['ADMIN']);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $map = ['name' => 'name', 'shortName' => 'short_name', 'address' => 'address',
        'phone' => 'phone', 'email' => 'email', 'logoUrl' => 'logo_url'];
    // Pastikan baris ada dulu.
    Db::q("INSERT IGNORE INTO app_settings (id) VALUES ('app_settings')");
    $set = []; $vals = [];
    foreach ($map as $f => $c) {
        if (array_key_exists($f, $b)) { $set[] = "$c = ?"; $vals[] = $b[$f]; }
    }
    if ($set) {
        Db::q("UPDATE app_settings SET " . implode(', ', $set) . " WHERE id = 'app_settings'", $vals);
    }
    echo json_encode(Db::one("SELECT * FROM app_settings WHERE id = 'app_settings'"));
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
