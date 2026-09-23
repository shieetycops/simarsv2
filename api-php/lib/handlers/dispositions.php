<?php
// /api/dispositions — replikasi backend/routes/disposition.ts.
$user = Auth::requireAuth(Db::$pdo);
$id   = $segments[1] ?? '';
$sub  = $segments[2] ?? '';

// GET /dispositions — filter per role, include letter + fromUser/toUser
if ($method === 'GET' && $id === '') {
    $role = $user['role'];
    if ($role === 'ADMIN') {
        $rows = Db::all("SELECT * FROM dispositions ORDER BY created_at DESC");
    } else {
        // PIMPINAN & lainnya: yang dikirim ATAU diterima user ini.
        $rows = Db::all("SELECT * FROM dispositions WHERE from_user_id = ? OR to_user_id = ? ORDER BY created_at DESC",
            [$user['id'], $user['id']]);
    }
    echo json_encode(nestDispositions($rows));
    return;
}

// POST /dispositions — buat disposisi (validasi hierarki + notifikasi)
if ($method === 'POST' && $id === '') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $errors = requireFields($b, ['incomingLetterId', 'toUserId', 'instruction']);
    if ($errors) {
        http_response_code(400);
        echo json_encode(['message' => 'Data tidak valid', 'errors' => $errors]);
        return;
    }
    try {
        $fromUser = Db::one("SELECT * FROM users WHERE id = ?", [$user['id']]);
        $toUser   = Db::one("SELECT * FROM users WHERE id = ?", [$b['toUserId']]);
        if (!$fromUser || !$toUser) {
            http_response_code(404);
            echo json_encode(['message' => 'User tidak ditemukan']);
            return;
        }
        if (!Disposition::canDispose($fromUser['role'], $toUser['supervisorId'] ?? null, $fromUser['id'])) {
            http_response_code(403);
            echo json_encode(['message' => 'Anda tidak memiliki wewenang untuk disposisi ke pengguna ini']);
            return;
        }

        $newId = Db::generateId();
        Db::q("INSERT INTO dispositions (id, incoming_letter_id, from_user_id, to_user_id, instruction, status, deadline)
               VALUES (?, ?, ?, ?, ?, ?, ?)", [
            $newId, $b['incomingLetterId'], $user['id'], $b['toUserId'], $b['instruction'],
            $b['status'] ?? 'PENDING',
            !empty($b['deadline']) ? date('Y-m-d H:i:s', strtotime($b['deadline'])) : null,
        ]);

        // Notifikasi penerima + konfirmasi pengirim (momen sama seperti versi Express).
        notify($b['toUserId'], 'Disposisi Baru',
            'Anda menerima instruksi disposisi baru: ' . substr($b['instruction'], 0, 50) . '...');
        notify($user['id'], 'Disposisi Terkirim',
            "Disposisi berhasil diteruskan kepada {$toUser['name']}: " . substr($b['instruction'], 0, 40) . '...');

        $letter = Db::one("SELECT subject, id FROM incoming_letters WHERE id = ?", [$b['incomingLetterId']]);
        $attachmentUrl = $letter ? letterViewUrl($letter['id']) : '';
        Whatsapp::notifyNewDisposition(Db::$pdo, [
            'fromName' => $fromUser['name'],
            'toName' => $toUser['name'] ?? null,
            'subject' => $letter['subject'] ?? '-',
            'instruction' => $b['instruction'],
            'deadline' => $b['deadline'] ?? null,
            'attachmentUrl' => $attachmentUrl,
            'actorUserId' => $user['id'],
        ]);

        
 // Bot WA v2: DM menu status (1=PROSES, 2 [catatan]=SELESAI) ke nomor WA
 // pegawai penerima. Menu dimundurkan bila pegawai masih memegang sesi
 // "buat disposisi" pimpinan (hindari salah-tekan nomor pegawai vs menu).
 if (!empty($toUser['waNumber'])) {
 $leaderActive = Db::one(
 "SELECT kind FROM wa_sessions WHERE user_id = ? AND kind = 'LEADER' AND expires_at > NOW()",
 [$b['toUserId']]);
 Whatsapp::notifyDispositionAssignedDm(Db::$pdo, [
 'toUserId' => $b['toUserId'],
 'waTarget' => Wabot::normalizeNumber($toUser['waNumber']),
 'dispositionId' => $newId,
 'subject' => $letter['subject'] ?? '-',
 'instruction' => $b['instruction'],
 'deadline' => !empty($b['deadline']) ? date('Y-m-d H:i:s', strtotime($b['deadline'])) : null,
            'attachmentUrl' => $attachmentUrl,
 'withMenu' => !$leaderActive,
 'actorUserId' => $user['id'],
 ]);
 }

        http_response_code(201);
        echo json_encode(Db::one("SELECT * FROM dispositions WHERE id = ?", [$newId]));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal membuat disposisi.']);
    }
    return;
}

// PATCH /dispositions/:id/status — ubah status + notifikasi
if ($method === 'PATCH' && $id !== '' && $sub === 'status') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = $b['status'] ?? '';
    $notes  = $b['notes'] ?? null;
    $combined = !empty($b['result']) ? "$notes\n\n[Hasil]: {$b['result']}" : $notes;

    // Ambil disposisi dulu buat cek kepemilikan: HANYA PENERIMA (to_user_id)
    // yang boleh memprogres status. PENGIRIM (from_user_id) — walau dia
    // pimpinan yang ngasih tugasnya — tidak boleh progres disposisinya sendiri.
    $disp = Db::one("SELECT d.*, il.subject AS letter_subject,
            fu.name AS from_name, fu.wa_number AS from_wa,
            tu.name AS to_name
        FROM dispositions d
        JOIN incoming_letters il ON il.id = d.incoming_letter_id
        JOIN users fu ON fu.id = d.from_user_id
        JOIN users tu ON tu.id = d.to_user_id
        WHERE d.id = ?", [$id]);
    if (!$disp) {
        http_response_code(404);
        echo json_encode(['message' => 'Disposisi tidak ditemukan.']);
        return;
    }
    if ($disp['toUserId'] !== $user['id']) {
        http_response_code(403);
        echo json_encode(['message' => 'Anda bukan penerima disposisi ini, tidak dapat memprogres tindak lanjut.']);
        return;
    }

    try {
        Db::q("UPDATE dispositions SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?", [$status, $combined, $id]);
        // Sync nilai di memory supaya response betul (status & notes baru).
        $disp['status'] = $status;
        $disp['notes']  = $combined;

        // Notifikasi pengirim per status (title beda-beda, momen sama seperti Express).
        $title = Disposition::statusNotificationTitle($status);
        if ($title && $disp['fromUserId']) {
            $subj = substr($disp['letterSubject'], 0, 60);
            $suffix = $notes ? ' — "' . substr($notes, 0, 50) . '"' : '';
            $verb = $status === 'SELESAI' ? 'telah menyelesaikan tugas' : 'sedang menindaklanjuti';
            notify($disp['fromUserId'], $title, "{$disp['toName']} $verb: $subj$suffix");
        }

        if (Disposition::notifiesWhatsapp($status)) {
            Whatsapp::notifyDispositionStatus(Db::$pdo, [
                'fromName' => $disp['fromName'] ?? null,
                'workerName' => $disp['toName'],
                'subject' => $disp['letterSubject'],
                'status' => $status,
                'notes' => $combined,
                'actorUserId' => $user['id'],
            ]);
        }

        // Bentuk response = disposition + include (incomingLetter{subject}, fromUser, toUser).
        $disp['incomingLetter'] = ['subject' => $disp['letterSubject']];
        $disp['fromUser'] = ['id' => $disp['fromUserId'], 'name' => $disp['fromName'], 'waNumber' => $disp['fromWa']];
        $disp['toUser']   = ['id' => $disp['toUserId'], 'name' => $disp['toName']];
        unset($disp['letterSubject'], $disp['fromName'], $disp['fromWa'], $disp['toName']);
        echo json_encode($disp);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui status disposisi.']);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);

// Buat satu notifikasi (link tetap /disposisi seperti versi Express).
function notify(string $userId, string $title, string $message): void
{
    Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, '/disposisi')",
        [Db::generateId(), $userId, $title, $message]);
}

// Tempel incomingLetter (full) + fromUser/toUser {id,name,role} ke tiap disposisi.
function nestDispositions(array $rows): array
{
    if (!$rows) return [];
    $letterIds = array_values(array_unique(array_column($rows, 'incomingLetterId')));
    $userIds   = array_values(array_unique(array_merge(array_column($rows, 'fromUserId'), array_column($rows, 'toUserId'))));

    $letters = [];
    if ($letterIds) {
        $in = implode(',', array_fill(0, count($letterIds), '?'));
        foreach (Db::all("SELECT * FROM incoming_letters WHERE id IN ($in)", $letterIds) as $l) {
            $letters[$l['id']] = $l;
        }
    }
    $users = [];
    if ($userIds) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        foreach (Db::all("SELECT id, name, role FROM users WHERE id IN ($in)", $userIds) as $u) {
            $users[$u['id']] = $u;
        }
    }
    foreach ($rows as &$d) {
        $d['incomingLetter'] = $letters[$d['incomingLetterId']] ?? null;
        $d['fromUser'] = $users[$d['fromUserId']] ?? null;
        $d['toUser']   = $users[$d['toUserId']] ?? null;
    }
    return $rows;
}
