<?php
// /api/settings — replikasi backend/routes/settings.ts.
// GET publik (tanpa auth), PUT hanya ADMIN.

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
