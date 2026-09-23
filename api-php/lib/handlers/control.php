<?php
// /api/control — Buku Kendali dan transisi workflow SIMARS v2.
$user = Auth::requireAuth(Db::$pdo);
$id = $segments[1] ?? '';
$sub = $segments[2] ?? '';

// GET /control/stages — kontrak workflow untuk UI.
if ($method === 'GET' && $id === 'stages') {
    echo json_encode(['stages' => V2Workflow::STAGES, 'securityLevels' => V2Workflow::SECURITY_LEVELS]);
    return;
}

// GET /control/classifications — master kode arsip dari tabel + 13 primer resmi.
if ($method === 'GET' && $id === 'classifications') {
    $rows = Db::all("SELECT code, name, primary_code AS primaryCode, security_level AS securityLevel,
        minimum_role AS minimumRole, validation_status AS validationStatus
        FROM archive_classifications WHERE is_active = 1 ORDER BY code");
    echo json_encode(['primaries' => V2Workflow::ARCHIVE_PRIMARIES, 'classifications' => $rows]);
    return;
}

// Surat rahasia hanya boleh diakses role yang berwenang.
$guardLetterAccess = function (array $letter) use ($user): void {
    if (!V2Workflow::userCanAccessLetter($user, (string)($letter['securityLevel'] ?? 'BIASA'))) {
        throw new AuthException(403, 'Anda tidak berwenang mengakses surat dengan level keamanan ini.');
    }
};

// GET /control — daftar Buku Kendali (hanya surat yang boleh dilihat user).
if ($method === 'GET' && $id === '') {
    $rows = Db::all("SELECT l.id, l.agenda_number AS agendaNumber, l.letter_number AS letterNumber,
            l.sender, l.subject, l.security_level AS securityLevel, l.urgency_level AS urgencyLevel,
            l.archive_code AS archiveCode, l.archive_code_status AS archiveCodeStatus,
            l.address_status AS addressStatus, l.completeness_status AS completenessStatus,
            l.current_stage AS currentStage, l.letter_category AS letterCategory,
            l.document_type AS documentType, l.created_at AS createdAt,
            (SELECT COUNT(*) FROM letter_control_logs c WHERE c.incoming_letter_id = l.id) AS controlLogCount
        FROM incoming_letters l ORDER BY l.created_at DESC");
    echo json_encode(array_values(array_filter($rows, fn($r) => V2Workflow::userCanAccessLetter($user, (string)($r['securityLevel'] ?? 'BIASA')))));
    return;
}

if ($id === '') { http_response_code(404); echo json_encode(['message' => 'Not found']); return; }

// GET /control/:id — detail surat + riwayat kendali + transisi yang diizinkan.
if ($method === 'GET' && $sub === '') {
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $logs = Db::all('SELECT c.*, u.name AS actorName, u.role AS actorRole FROM letter_control_logs c
        LEFT JOIN users u ON u.id = c.actor_user_id WHERE c.incoming_letter_id = ? ORDER BY c.created_at ASC', [$id]);
    $checks = Db::all('SELECT k.*, u.name AS checkedByName FROM letter_completeness_checks k
        LEFT JOIN users u ON u.id = k.checked_by WHERE k.incoming_letter_id = ? ORDER BY k.created_at DESC', [$id]);
    $from = (string)$letter['currentStage'];
    $allowed = array_values(array_filter(V2Workflow::STAGES, fn($s) => V2Workflow::canTransition($from, $s)
        && in_array($user['role'], V2Workflow::allowedRolesForTransition($from, $s), true)));
    echo json_encode(['letter' => $letter, 'logs' => $logs, 'checks' => $checks, 'allowedNextStages' => $allowed]);
    return;
}


// POST /control/:id/transition — pindah tahap dengan validasi state + role.
if ($method === 'POST' && $sub === 'transition') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $to = strtoupper(trim((string)($body['toStage'] ?? '')));
    $notes = trim((string)($body['notes'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $from = (string)$letter['currentStage'];
    if (!V2Workflow::canTransition($from, $to)) {
        http_response_code(422); echo json_encode(['message' => 'Transisi workflow tidak diizinkan', 'fromStage' => $from, 'toStage' => $to]); return;
    }
    if (!in_array($user['role'], V2Workflow::allowedRolesForTransition($from, $to), true)) {
        http_response_code(403); echo json_encode(['message' => 'Role Anda tidak berwenang untuk transisi ini.']); return;
    }
    Db::$pdo->beginTransaction();
    try {
        Db::q("UPDATE incoming_letters SET current_stage = ?,
            registered_at = CASE WHEN ? = 'TERREGISTRASI' THEN COALESCE(registered_at, NOW()) ELSE registered_at END,
            archived_at = CASE WHEN ? = 'DIARSIPKAN' THEN NOW() ELSE archived_at END,
            archived_by = CASE WHEN ? = 'DIARSIPKAN' THEN ? ELSE archived_by END
            WHERE id = ?", [$to, $to, $to, $to, $user['id'], $id]);
        Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [Db::generateId(), $id, $user['id'], $from, $to, 'TRANSITION', $notes ?: null]);
        Db::$pdo->commit();
    } catch (Throwable $e) { Db::$pdo->rollBack(); throw $e; }
    logActivity($user['id'], 'TRANSITION', 'INCOMING_LETTER', $id, "$from -> $to");
    echo json_encode(['success' => true, 'stage' => $to]);
    return;
}


// POST /control/:id/address-verification — catat hasil verifikasi alamat.
if ($method === 'POST' && $sub === 'address-verification') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $correct = (bool)($body['correct'] ?? false);
    $notes = trim((string)($body['notes'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    if (!in_array($user['role'], ['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'], true)) {
        http_response_code(403); echo json_encode(['message' => 'Hanya petugas persuratan yang boleh memverifikasi alamat.']); return;
    }
    if (in_array($letter['addressStatus'] ?? '', ['ALAMAT_SESAI', 'ALAMAT_TIDAK_SESAI'], true)) {
        http_response_code(422); echo json_encode(['message' => 'Alamat surat ini sudah diverifikasi sebelumnya.']); return;
    }
    $status = $correct ? 'ALAMAT_SESAI' : 'ALAMAT_TIDAK_SESAI';
    $detail = ($correct ? 'Alamat sesai.' : 'Alamat TIDAK sesai.') . ($notes !== '' ? ' ' . $notes : '');
    Db::q('UPDATE incoming_letters SET address_status = ?, address_checked_by = ?, address_checked_at = NOW(), address_notes = ? WHERE id = ?',
        [$status, $user['id'], $notes ?: null, $id]);
    Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)', [Db::generateId(), $id, $user['id'], $letter['currentStage'], $letter['currentStage'], 'ADDRESS_VERIFICATION', $detail]);
    logActivity($user['id'], 'VERIFY_ADDRESS', 'INCOMING_LETTER', $id, $status);
    echo json_encode(['success' => true, 'addressStatus' => $status]);
    return;
}

// POST /control/:id/completeness — simpan checklist kelengkapan naskah.
if ($method === 'POST' && $sub === 'completeness') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $c = $body['checks'] ?? [];
    $keys = ['addressCorrect', 'numberPresent', 'datePresent', 'subjectPresent', 'attachmentComplete', 'signaturePresent'];
    $vals = [];
    foreach ($keys as $k) { $vals[$k] = (bool)($c[$k] ?? false); }
    $notes = trim((string)($body['notes'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    if (!in_array($user['role'], ['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'], true)) {
        http_response_code(403); echo json_encode(['message' => 'Hanya petugas persuratan yang boleh memeriksa kelengkapan.']); return;
    }
    $complete = !in_array(false, $vals, true);
    $status = $complete ? 'LENGKAP' : 'TIDAK_LENGKAP';
    Db::$pdo->beginTransaction();
    try {
        Db::q('INSERT INTO letter_completeness_checks (id, incoming_letter_id, checked_by, address_correct, number_present,
            date_present, subject_present, attachment_complete, signature_present, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [Db::generateId(), $id, $user['id'],
            (int)$vals['addressCorrect'], (int)$vals['numberPresent'], (int)$vals['datePresent'],
            (int)$vals['subjectPresent'], (int)$vals['attachmentComplete'], (int)$vals['signaturePresent'], $notes ?: null]);
        Db::q('UPDATE incoming_letters SET completeness_status = ?, completeness_checked_by = ?, completeness_checked_at = NOW(), completeness_notes = ? WHERE id = ?',
            [$status, $user['id'], $notes ?: null, $id]);
        Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [Db::generateId(), $id, $user['id'], $letter['currentStage'], $letter['currentStage'], 'COMPLETENESS_CHECK', "Hasil: $status." . ($notes !== '' ? ' ' . $notes : '')]);
        Db::$pdo->commit();
    } catch (Throwable $e) { Db::$pdo->rollBack(); throw $e; }
    logActivity($user['id'], 'CHECK_COMPLETENESS', 'INCOMING_LETTER', $id, $status);
    echo json_encode(['success' => true, 'completenessStatus' => $status]);
    return;
}

http_response_code(404); echo json_encode(['message' => 'Not found']);