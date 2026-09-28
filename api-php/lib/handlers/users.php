<?php
// /api/users — replikasi backend/routes/user.ts. Semua butuh auth.
$user = Auth::requireAuth(Db::$pdo);
$id   = $segments[1] ?? '';
$sub  = $segments[2] ?? '';
$db   = Db::$pdo;

// GET /users/list — user aktif ringkas (semua role)
if ($method === 'GET' && $id === 'list') {
    echo json_encode(Db::all("SELECT id, name, role FROM users WHERE is_active = 1"));
    return;
}

// GET /users/subordinates — bawahan (ADMIN/PIMPINAN = semua kecuali diri)
if ($method === 'GET' && $id === 'subordinates') {
    if (in_array($user['role'], ['ADMIN', 'PIMPINAN'], true)) {
        echo json_encode(Db::all(
            "SELECT id, name, role, username FROM users WHERE is_active = 1 AND id != ?",
            [$user['id']]
        ));
    } else {
        echo json_encode(Db::all(
            "SELECT id, name, role, username FROM users WHERE supervisor_id = ? AND is_active = 1",
            [$user['id']]
        ));
    }
    return;
}

// GET /users/:id/delete-check — precheck hapus permanen (ADMIN)
if ($method === 'GET' && $id !== '' && $sub === 'delete-check') {
    Auth::requireRole($user, ['ADMIN']);
    $target = Db::one("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['message' => 'Pengguna tidak ditemukan.']);
        return;
    }
    $allowHard = getenv('ALLOW_HARD_DELETE_WITH_HISTORY') === 'true';
    $dispositionCount = (int) Db::q("SELECT COUNT(*) c FROM dispositions WHERE from_user_id = ? OR to_user_id = ?", [$id, $id])->fetch()['c'];
    $notificationCount = (int) Db::q("SELECT COUNT(*) c FROM notifications WHERE user_id = ?", [$id])->fetch()['c'];
    $activityLogCount = (int) Db::q("SELECT COUNT(*) c FROM activity_logs WHERE user_id = ?", [$id])->fetch()['c'];
    $subordinateCount = (int) Db::q("SELECT COUNT(*) c FROM users WHERE supervisor_id = ?", [$id])->fetch()['c'];
    $activeAdminCount = (int) Db::q("SELECT COUNT(*) c FROM users WHERE role = 'ADMIN' AND is_active = 1")->fetch()['c'];

    $blocking = [];
    if ($dispositionCount > 0) $blocking[] = "$dispositionCount riwayat disposisi surat";
    if ($notificationCount > 0) $blocking[] = "$notificationCount notifikasi";
    if ($activityLogCount > 0) $blocking[] = "$activityLogCount log aktivitas";

    $isSelf = $user['id'] === $id;
    $isLastAdmin = $target['role'] === 'ADMIN' && (int) $target['isActive'] === 1 && $activeAdminCount <= 1;
    $hasHistory = count($blocking) > 0;

    $resp = [
        'canDelete' => $allowHard
            ? (!$isSelf && !$isLastAdmin)
            : (count($blocking) === 0 && !$isSelf && !$isLastAdmin),
        'isSelf' => $isSelf,
        'isLastAdmin' => $isLastAdmin,
        'blockingReasons' => $blocking,
        'subordinateCount' => $subordinateCount,
    ];
    if ($allowHard) {
        $resp['willCascadeDelete'] = $hasHistory;
        $resp['cascadeCounts'] = compact('dispositionCount', 'notificationCount', 'activityLogCount');
    }
    echo json_encode($resp);
    return;
}

// DELETE /users/:id/permanent — hard delete (ADMIN)
if ($method === 'DELETE' && $id !== '' && $sub === 'permanent') {
    Auth::requireRole($user, ['ADMIN']);
    if ($user['id'] === $id) {
        http_response_code(400);
        echo json_encode(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.']);
        return;
    }
    $target = Db::one("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['message' => 'Pengguna tidak ditemukan.']);
        return;
    }
    if ($target['role'] === 'ADMIN' && (int) $target['isActive'] === 1) {
        $activeAdminCount = (int) Db::q("SELECT COUNT(*) c FROM users WHERE role = 'ADMIN' AND is_active = 1")->fetch()['c'];
        if ($activeAdminCount <= 1) {
            http_response_code(400);
            echo json_encode(['message' => 'Tidak dapat menghapus admin aktif terakhir dalam sistem.']);
            return;
        }
    }
    $counts = Db::q("SELECT
        (SELECT COUNT(*) FROM dispositions WHERE from_user_id = ? OR to_user_id = ?) d,
        (SELECT COUNT(*) FROM notifications WHERE user_id = ?) n,
        (SELECT COUNT(*) FROM activity_logs WHERE user_id = ?) a", [$id, $id, $id, $id])->fetch();

    if (getenv('ALLOW_HARD_DELETE_WITH_HISTORY') === 'true') {
        $db->beginTransaction();
        Db::q("DELETE FROM dispositions WHERE from_user_id = ? OR to_user_id = ?", [$id, $id]);
        Db::q("DELETE FROM notifications WHERE user_id = ?", [$id]);
        Db::q("DELETE FROM activity_logs WHERE user_id = ?", [$id]);
        Db::q("DELETE FROM users WHERE id = ?", [$id]);
        $db->commit();
        echo json_encode(['message' => 'Pengguna beserta seluruh riwayat terkait berhasil dihapus permanen.']);
        return;
    }
    if ($counts['d'] > 0 || $counts['n'] > 0 || $counts['a'] > 0) {
        http_response_code(409);
        echo json_encode(['message' => 'Pengguna ini memiliki riwayat data (disposisi/notifikasi/log aktivitas) yang harus dipertahankan untuk keperluan audit. Gunakan opsi Nonaktifkan sebagai gantinya.']);
        return;
    }
    Db::q("DELETE FROM users WHERE id = ?", [$id]);
    echo json_encode(['message' => 'Pengguna berhasil dihapus permanen.']);
    return;
}

// PATCH /users/:id/activate (ADMIN)
if ($method === 'PATCH' && $id !== '' && $sub === 'activate') {
    Auth::requireRole($user, ['ADMIN']);
    try {
        Db::q("UPDATE users SET is_active = 1 WHERE id = ?", [$id]);
        echo json_encode(['message' => 'User berhasil diaktifkan.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal mengaktifkan user.']);
    }
    return;
}

// GET /users (ADMIN) — daftar lengkap
if ($method === 'GET' && $id === '') {
    Auth::requireRole($user, ['ADMIN']);
    echo json_encode(Db::all(
        "SELECT id, username, name, role, is_active, wa_number, supervisor_id, created_at FROM users ORDER BY created_at DESC"
    ));
    return;
}

// POST /users (ADMIN) — buat user
if ($method === 'POST' && $id === '') {
    Auth::requireRole($user, ['ADMIN']);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $waErr = validateWa($b['waNumber'] ?? null);
    if ($waErr) {
        http_response_code(400);
        echo json_encode(['message' => $waErr]);
        return;
    }
    // Tolak username duplikat dengan pesan jelas (dulu 500 generik dari constraint).
    if (trim((string) ($b['username'] ?? '')) === '') {
        http_response_code(400);
        echo json_encode(['message' => 'Username wajib diisi.']);
        return;
    }
    if (Db::one("SELECT id FROM users WHERE username = ?", [$b['username']])) {
        http_response_code(400);
        echo json_encode(['message' => 'Username sudah dipakai.']);
        return;
    }
    $pwErr = validatePassword($b['password'] ?? '');
    if ($pwErr) {
        http_response_code(400);
        echo json_encode(['message' => $pwErr]);
        return;
    }
    // Atasan langsung (Fase 0): dasar wewenang disposisi hierarkis.
    [$supOk, $supErr, $supId] = resolveSupervisorId($b['supervisorId'] ?? null, '');
    if (!$supOk) {
        http_response_code(400);
        echo json_encode(['message' => $supErr, 'field' => 'supervisorId']);
        return;
    }
    try {
        $newId = Db::generateId();
        Db::q("INSERT INTO users (id, username, password, name, role, is_active, wa_number, supervisor_id)
               VALUES (?, ?, ?, ?, ?, 1, ?, ?)", [
            $newId,
            $b['username'] ?? '',
            password_hash($b['password'] ?? '', PASSWORD_BCRYPT),
            $b['name'] ?? '',
            $b['role'] ?? 'STAFF',
            !empty($b['waNumber']) ? Whatsapp::normalizePhone($b['waNumber']) : null,
            $supId,
        ]);
        http_response_code(201);
        echo json_encode(['id' => $newId, 'username' => $b['username'] ?? '', 'name' => $b['name'] ?? '', 'role' => $b['role'] ?? 'STAFF']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal membuat user baru.']);
    }
    return;
}

// PUT /users/:id (ADMIN) — update
if ($method === 'PUT' && $id !== '' && $sub === '') {
    Auth::requireRole($user, ['ADMIN']);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    if (array_key_exists('waNumber', $b)) {
        $waErr = validateWa($b['waNumber']);
        if ($waErr) {
            http_response_code(400);
            echo json_encode(['message' => $waErr]);
            return;
        }
    }
    // Kebijakan password juga berlaku saat reset password lewat PUT.
    if (array_key_exists('password', $b) && (string) $b['password'] !== '') {
        $pwErr = validatePassword($b['password']);
        if ($pwErr) {
            http_response_code(400);
            echo json_encode(['message' => $pwErr]);
            return;
        }
    }
    // Tolak username duplikat dengan pesan jelas (dulu 500 generik dari constraint).
    if (array_key_exists('username', $b) && (string) $b['username'] !== '') {
        if (Db::one("SELECT id FROM users WHERE username = ? AND id != ?", [$b['username'], $id])) {
            http_response_code(400);
            echo json_encode(['message' => 'Username sudah dipakai.']);
            return;
        }
    }
    // Whitelist kolom yang boleh diubah (hindari SQL injection nama kolom).
    $map = ['username' => 'username', 'name' => 'name', 'role' => 'role', 'avatar' => 'avatar', 'isActive' => 'is_active'];
    $set = [];
    $vals = [];
    foreach ($map as $field => $col) {
        if (array_key_exists($field, $b)) {
            $set[] = "$col = ?";
            $vals[] = $field === 'isActive' ? (int) (bool) $b[$field] : $b[$field];
        }
    }
    if (array_key_exists('waNumber', $b)) {
        $set[] = "wa_number = ?";
        $vals[] = !empty($b['waNumber']) ? Whatsapp::normalizePhone($b['waNumber']) : null;
    }
    // Atasan langsung (Fase 0): boleh dikosongkan (null) untuk melepas hierarki.
    if (array_key_exists('supervisorId', $b)) {
        [$supOk, $supErr, $supId] = resolveSupervisorId($b['supervisorId'], $id);
        if (!$supOk) {
            http_response_code(400);
            echo json_encode(['message' => $supErr, 'field' => 'supervisorId']);
            return;
        }
        $set[] = "supervisor_id = ?";
        $vals[] = $supId;
    }
    if (!empty($b['password'])) {
        $set[] = "password = ?";
        $vals[] = password_hash($b['password'], PASSWORD_BCRYPT);
    }
    try {
        if ($set) {
            $vals[] = $id;
            Db::q("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?", $vals);
        }
        // ponytail: tak kembalikan hash password (versi Express bocorkan; ini lebih aman).
        echo json_encode(Db::one("SELECT id, username, name, role, avatar, is_active, wa_number, supervisor_id, created_at, updated_at FROM users WHERE id = ?", [$id]));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui user.']);
    }
    return;
}

// DELETE /users/:id (ADMIN) — nonaktifkan (soft delete)
if ($method === 'DELETE' && $id !== '' && $sub === '') {
    Auth::requireRole($user, ['ADMIN']);
    try {
        Db::q("UPDATE users SET is_active = 0 WHERE id = ?", [$id]);
        echo json_encode(['message' => 'User dinonaktifkan.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menghapus user.']);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);

// waNumber opsional; jika ada, minimal 9 digit angka. Return pesan error atau null.
function validateWa($wa): ?string
{
    if ($wa === null || $wa === '') return null;
    return strlen(preg_replace('/[^0-9]/', '', (string) $wa)) >= 9
        ? null
        : "Nomor WhatsApp minimal 9 digit angka";
}

// Atasan langsung (users.supervisor_id) — dasar wewenang disposisi hierarkis
// (Disposition::canDispose) dan sasaran menu WhatsApp pegawai.
//
// Fase 0: kolomnya sudah ada di skema sejak awal, tetapi TIDAK pernah bisa diisi
// lewat aplikasi (tidak ada di whitelist POST/PUT), sehingga Kasubag Umum &
// Sekretaris selalu ditolak server saat mendisposisi ke bawahannya.
//
// Return [ok, errorMessage, ?string nilaiSiapSimpan]. Kosong = lepas hierarki.
function resolveSupervisorId($value, string $selfId): array
{
    if ($value === null || trim((string) $value) === '') return [true, null, null];
    $supId = trim((string) $value);
    if ($supId === $selfId) return [false, 'Atasan langsung tidak boleh diri sendiri.', null];
    $sup = Db::one("SELECT id, is_active FROM users WHERE id = ?", [$supId]);
    if (!$sup) return [false, 'Atasan langsung tidak ditemukan.', null];
    if (!(int) $sup['isActive']) return [false, 'Atasan langsung harus pengguna yang masih aktif.', null];
    return [true, null, $supId];
}
