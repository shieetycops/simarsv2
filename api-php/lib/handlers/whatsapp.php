<?php
// /api/whatsapp — settings (Fonnte) + kirim pesan tes. Semua ADMIN.
// Endpoint lama /status (QR) & /groups dihapus (tak ada di Fonnte).
$user = Auth::requireAuth(Db::$pdo);
Auth::requireRole($user, ['ADMIN']);
$id = $segments[1] ?? '';

// GET /whatsapp/settings
if ($method === 'GET' && $id === 'settings') {
    echo json_encode(Whatsapp::settings(Db::$pdo));
    return;
}

// PUT /whatsapp/settings — {fonnteToken, groupTarget, waGroupMarker, appUrl, isEnabled}
if ($method === 'PUT' && $id === 'settings') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    Db::q("INSERT INTO whatsapp_settings (id, group_target, fonnte_token, wa_group_marker, app_url, is_enabled)
        VALUES ('wa_settings', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE group_target = VALUES(group_target),
        fonnte_token = VALUES(fonnte_token), wa_group_marker = VALUES(wa_group_marker),
        app_url = VALUES(app_url),
        is_enabled = VALUES(is_enabled)", [
        $b['groupTarget'] ?? null,
        $b['fonnteToken'] ?? null,
        $b['waGroupMarker'] ?? null,
        $b['appUrl'] ?? null,
        (int) (bool) ($b['isEnabled'] ?? false),
    ]);
    echo json_encode(Whatsapp::settings(Db::$pdo));
    return;
}

// POST /whatsapp/test — kirim pesan tes ke grup target
if ($method === 'POST' && $id === 'test') {
    $s = Whatsapp::settings(Db::$pdo);
    if (!$s['groupTarget']) {
        echo json_encode(['success' => false, 'message' => 'Grup target belum diatur.']);
        return;
    }
    try {
        Whatsapp::send($s['fonnteToken'] ?? '', $s['groupTarget'],
            "🔔 Pesan tes dari SIMARS. Jika Anda menerima ini, konfigurasi Fonnte sudah benar.", $user['id']);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        error_log('[WhatsApp] Pesan tes gagal: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan tes: ' . $e->getMessage()]);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
