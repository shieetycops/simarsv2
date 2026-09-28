<?php
// /api/control — Buku Kendali dan transisi workflow SIMARS v2.
$user = Auth::requireAuth(Db::$pdo);
$id = $segments[1] ?? '';
$sub = $segments[2] ?? '';

// GET /control/stages — kontrak workflow untuk UI.
if ($method === 'GET' && $id === 'stages') {
    echo json_encode([
        'stages' => V2Workflow::STAGES,
        'securityLevels' => V2Workflow::SECURITY_LEVELS,
        // Tahap yang datanya masih boleh dikoreksi/dihapus (AS-9). Dikirim agar
        // UI tidak menebak sendiri kapan tombol Edit/Hapus boleh tampil.
        'correctableStages' => V2Workflow::CORRECTABLE_STAGES,
    ]);
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
            -- Kolom berikut dipakai layar Buku Kendali: filter tanggal terima,
            -- dialog koreksi (hasil porting dari menu Surat Masuk lama), dan
            -- penanda ada/tidaknya lampiran.
            l.letter_date AS letterDate, l.received_date AS receivedDate, l.classification,
            l.nature, l.source_channel AS sourceChannel, l.description,
            l.file_path AS filePath, l.canonical_file_name AS canonicalFileName,
            l.disposition_route AS dispositionRoute,
            -- Fase 0: pelaksana surat (kolom Pelaksana di Buku Kendali). Nama
            -- diambil sekalian supaya UI tidak perlu query user satu per satu.
            l.assignee_user_id AS assigneeUserId, au.name AS assigneeName,
            (SELECT COUNT(*) FROM letter_control_logs c WHERE c.incoming_letter_id = l.id) AS controlLogCount
        FROM incoming_letters l
        LEFT JOIN users au ON au.id = l.assignee_user_id
        ORDER BY l.created_at DESC");
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
    // Sumber tunggal kebenaran: legalitas state machine + role + rute keputusan.
    // UI merender tombol persis dari daftar ini, jadi yang tampil == yang lolos
    // validasi server (lihat POST /control/:id/transition).
    $routes = V2Workflow::allowedTransitionsFor($user, $from, $letter['dispositionRoute'] ?? null);
    // Fase 1: pelaksana surat + hak penarikan kembali (rollback) Kasubag Umum.
    // Dihitung server supaya tombol di UI tidak pernah menyimpang dari validasi
    // POST /control/:id/reopen.
    $assignee = !empty($letter['assigneeUserId'])
        ? Db::one('SELECT id, name, role FROM users WHERE id = ?', [(string) $letter['assigneeUserId']])
        : null;

    // K3 (revisi kedua): rencana TOLAK/RECALL/koreksi ADMIN dihitung SERVER,
    // termasuk baris log kendali terakhir (syarat "penerima belum bertindak"
    // untuk RECALL). UI hanya merender apa yang diizinkan rencana ini.
    $lastLog = Db::one("SELECT actor_user_id, from_stage, to_stage FROM letter_control_logs
        WHERE incoming_letter_id = ? ORDER BY created_at DESC, id DESC LIMIT 1", [$id]);
    $rejectPlan = V2Workflow::planReject($user, $letter, $lastLog);
    $rejectTo = $rejectPlan['ok'] ? (string) $rejectPlan['to'] : V2Workflow::REOPEN_TARGET_STAGE;
    $rejectLabel = V2Workflow::reopenLabel();
    if ($rejectPlan['ok'] && $rejectPlan['mode'] === 'TOLAK') {
        $rejectLabel = 'Tolak & kembalikan satu langkah ke ' . V2Workflow::stageLabel($rejectTo);
    } elseif ($rejectPlan['ok'] && $rejectPlan['mode'] === 'RECALL') {
        $rejectLabel = 'Tarik kembali ke ' . V2Workflow::stageLabel($rejectTo) . ' (penerima belum bertindak)';
    }

    // K7: info serah-terima lembar 1 disposisi (penyerah, penerima, waktu).
    $lembar1Name = function (?string $uid): ?string {
        if (!$uid) return null;
        $u = Db::one('SELECT name FROM users WHERE id = ?', [(string) $uid]);
        return $u ? (string) $u['name'] : null;
    };

    echo json_encode([
        'letter' => $letter,
        'logs' => $logs,
        'checks' => $checks,
        'allowedNextStages' => array_column($routes, 'toStage'),
        'allowedTransitions' => $routes,
        'stageLabel' => V2Workflow::stageLabel($from),
        // Pemegang surat pada tahap sekarang (dipakai UI untuk menjelaskan
        // "siapa yang sedang menunggu" dan oleh notifikasi WA per tahap).
        'stageOwnerRoles' => V2Workflow::stageOwners($from),
        'assignee' => $assignee,
        // K3: tombol tolak/tarik tampil hanya bila ada rencana sah untuk user ini.
        'canReopen' => (bool) $rejectPlan['ok'],
        'rejectMode' => $rejectPlan['mode'],
        'rejectTarget' => $rejectTo,
        'rejectTargetLabel' => V2Workflow::stageLabel($rejectTo),
        'rejectLabel' => $rejectLabel,
        'rejectMessage' => $rejectPlan['message'],
        'reopenTarget' => V2Workflow::REOPEN_TARGET_STAGE,
        'reopenTargetLabel' => V2Workflow::stageLabel(V2Workflow::REOPEN_TARGET_STAGE),
        'reopenLabel' => V2Workflow::reopenLabel(),
        'reopenReasonMin' => WorkflowConfig::tolakReasonMin(),
        // K7: serah-terima lembar 1 disposisi + siapa yang boleh mencatat.
        'lembar1' => [
            'diserahkanOleh' => $letter['lembar1DiserahkanOleh'] ?? null,
            'diserahkanOlehName' => $lembar1Name($letter['lembar1DiserahkanOleh'] ?? null),
            'diterimaOleh' => $letter['lembar1DiterimaOleh'] ?? null,
            'diterimaOlehName' => $lembar1Name($letter['lembar1DiterimaOleh'] ?? null),
            'diserahkanAt' => $letter['lembar1DiserahkanAt'] ?? null,
        ],
        'canRecordLembar1' => in_array($user['role'], ['ADMIN', 'ARSIPARIS', 'SEKRETARIS', 'PANITERA'], true)
            || V2Workflow::isUnitHead((string) ($user['role'] ?? '')),
        'lembar1Wajib' => WorkflowConfig::lembar1Wajib(),
        'dispositionRoute' => $letter['dispositionRoute'] ?? null,
        'dispositionRoutes' => V2Workflow::DISPOSITION_ROUTES,
        'dispositionRouteLabels' => V2Workflow::DISPOSITION_ROUTE_LABELS,
        'rekomendasiRoute' => $letter['rekomendasiRoute'] ?? null,
        'rekomendasiNotes' => $letter['rekomendasiNotes'] ?? null,
        'arahanPimpinan' => $letter['arahanPimpinan'] ?? null,
        'unitTujuan' => $letter['unitTujuan'] ?? null,
        'unitTujuanList' => V2Workflow::UNIT_TUJUAN_LIST,
        'canGiveRekomendasi' => V2Workflow::canGiveRekomendasi($user['role'] ?? ''),
        'canSetFinalRoute' => V2Workflow::canSetFinalRoute($user['role'] ?? ''),
        'canMarkArchived' => V2Workflow::canMarkArchived($user['role'] ?? ''),
        'isUnitHead' => V2Workflow::isUnitHead($user['role'] ?? ''),
        'ratificationNotice' => V2Workflow::RATIFICATION_NOTICE,
        // Kewenangan koreksi dihitung server, bukan ditebak UI: tombol Edit/Hapus
        // hanya boleh tampil persis saat PUT/DELETE di /api/incoming akan lolos
        // (role + tahap + belum ada disposisi). Lihat letterAllowsCorrection().
        'canCorrectStage' => V2Workflow::stageAllowsCorrection($from),
        'correctableStages' => V2Workflow::CORRECTABLE_STAGES,
        'hasDispositions' => letterHasDispositions($id),
        'canEdit' => in_array($user['role'], ['ADMIN', 'SEKRETARIS'], true) && letterAllowsCorrection($from, $id),
        'canDelete' => $user['role'] === 'ADMIN' && letterAllowsCorrection($from, $id),
    ]);
    return;
}


// POST /control/:id/rekomendasi — Kasubag beri REKOMENDASI rute (langkah 12).
// Body: { rekomendasiRoute: KEBIJAKAN|LANGSUNG, notes: string(min 10) }
if ($method === 'POST' && $sub === 'rekomendasi') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $rRoute = strtoupper(trim((string)($body['rekomendasiRoute'] ?? $body['route'] ?? '')));
    $rNotes = trim((string)($body['notes'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $res = LetterTransition::saveRekomendasi($user, $letter, $rRoute, $rNotes);
    if (!$res['ok']) {
        $code = $res['code'] === 'ROLE_DENIED' ? 403 : 422;
        http_response_code($code);
        echo json_encode(['message' => $res['message'], 'code' => $res['code']]);
        return;
    }
    echo json_encode(['success' => true, 'rekomendasiRoute' => $res['route'], 'message' => $res['message']]);
    return;
}

// POST /control/:id/keputusan — Sekretaris tetapkan RUTE FINAL (langkah 13).
// Body: { route: KEBIJAKAN|LANGSUNG, unit_tujuan?: string, notes?: string }
if ($method === 'POST' && $sub === 'keputusan') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $fRoute = strtoupper(trim((string)($body['route'] ?? $body['dispositionRoute'] ?? '')));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $res = LetterTransition::decideRoute($user, $letter, $fRoute, [
        'unit_tujuan' => $body['unit_tujuan'] ?? $body['unitTujuan'] ?? null,
        'notes' => trim((string)($body['notes'] ?? '')),
    ]);
    if (!$res['ok']) {
        $code = $res['code'] === 'ROLE_DENIED' ? 403 : 422;
        http_response_code($code);
        $payload = ['message' => $res['message'], 'code' => $res['code']];
        if ($res['code'] === 'UNIT_TUJUAN_REQUIRED' || $res['code'] === 'UNIT_TUJUAN_INVALID') {
            $payload['field'] = 'unit_tujuan';
            $payload['allowedUnits'] = V2Workflow::UNIT_TUJUAN_LIST;
        }
        echo json_encode($payload);
        return;
    }
    echo json_encode(['success' => true, 'dispositionRoute' => $res['route'], 'message' => $res['message']]);
    return;
}

// POST /control/:id/arahan — Ketua/WK isi ARAHAN (langkah 14, Q1: final).
// Body: { arahan: string(min 10), unit_tujuan?: string }
if ($method === 'POST' && $sub === 'arahan') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $arahan = trim((string)($body['arahan'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $res = LetterTransition::apply($user, $letter, 'DITERUSKAN_KE_SEKRETARIS_PANITERA', [
        'notes' => 'Arahan pimpinan: ' . $arahan,
        'arahan' => $arahan,
        'unit_tujuan' => $body['unit_tujuan'] ?? $body['unitTujuan'] ?? null,
        'source' => LetterTransition::SOURCE_WEB,
    ]);
    if (!$res['ok']) {
        $code = $res['code'] === 'ROLE_DENIED' ? 403 : 422;
        http_response_code($code);
        echo json_encode(['message' => $res['message'], 'code' => $res['code']]);
        return;
    }
    echo json_encode(['success' => true, 'stage' => $res['stage'], 'message' => 'Arahan tersimpan. Surat kembali ke Sekretaris untuk dilaksanakan.']);
    return;
}

// POST /control/:id/tunjuk — kepala unit tunjuk pegawai (langkah 17).
// Body: { pegawai_id: string }
if ($method === 'POST' && $sub === 'tunjuk') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $pegawaiId = trim((string)($body['pegawai_id'] ?? $body['pegawaiId'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    if (!V2Workflow::isUnitHead((string) ($user['role'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['message' => 'Hanya kepala unit (Kasubag/Panmud) yang boleh menunjuk pegawai (langkah 17).']);
        return;
    }
    if ($pegawaiId === '') {
        http_response_code(422);
        echo json_encode(['message' => 'pegawai_id wajib diisi.', 'field' => 'pegawai_id']);
        return;
    }
    $pegawai = Db::one('SELECT id, name, role, is_active FROM users WHERE id = ?', [$pegawaiId]);
    if (!$pegawai || !(int) ($pegawai['isActive'] ?? $pegawai['is_active'] ?? 0)) {
        http_response_code(422);
        echo json_encode(['message' => 'Pegawai tidak ditemukan atau nonaktif.']);
        return;
    }
    $from = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
    if ($from !== 'DITERUSKAN_KE_PELAKSANA') {
        http_response_code(422);
        echo json_encode(['message' => 'Penunjukan hanya pada tahap DITERUSKAN_KE_PELAKSANA.', 'fromStage' => $from]);
        return;
    }
    Db::q('UPDATE incoming_letters SET assignee_user_id = ?, assignee_set_at = NOW() WHERE id = ?', [$pegawaiId, $id]);
    Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [Db::generateId(), $id, $user['id'], $from, $from, 'TUNJUK_PEGAWAI', 'Menunjuk pelaksana: ' . ($pegawai['name'] ?? $pegawaiId)]);
    logActivity($user['id'], 'TUNJUK_PEGAWAI', 'INCOMING_LETTER', $id, "Menunjuk {$pegawaiId}");
    $letter['currentStage'] = $from;
    WaStageNotifier::afterTransition($letter, $from, $from, $user, ['source' => LetterTransition::SOURCE_WEB, 'notes' => 'Penunjukan pelaksana']);
    echo json_encode(['success' => true, 'assigneeUserId' => $pegawaiId, 'message' => 'Pegawai ditunjuk.']);
    return;
}

// POST /control/:id/arsip — Arsiparis tandai DIARSIPKAN (langkah 17-18, Q2).
// K7: bila serah-terima lembar 1 belum tercatat -> peringatan (default tidak
// memblokir); blokir hanya bila toggle workflow_settings.lembar1_wajib aktif.
if ($method === 'POST' && $sub === 'arsip') {
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    $res = LetterTransition::apply($user, $letter, 'DIARSIPKAN', [
        'notes' => 'Diarsipkan oleh pencatat surat.',
        'source' => LetterTransition::SOURCE_WEB,
    ]);
    if (!$res['ok']) {
        $code = $res['code'] === 'ROLE_DENIED' ? 403 : 422;
        http_response_code($code);
        $payload = ['message' => $res['message'], 'code' => $res['code']];
        if ($res['code'] === 'LEMBAR1_REQUIRED') {
            $payload['hint'] = 'Catat serah-terima lembar 1 lewat POST /control/:id/lembar1 atau matikan toggle "wajib" di Pengaturan.';
        }
        echo json_encode($payload);
        return;
    }
    echo json_encode([
        'success' => true, 'stage' => $res['stage'], 'message' => 'Surat diarsipkan.',
        'warning' => $res['warning'] ?? null,
    ]);
    return;
}

// POST /control/:id/lembar1 — catat serah-terima LEMBAR 1 disposisi (K7,
// SOP/AS/04 langkah 17: penyerah, penerima, waktu). Sekali catat, tak bisa
// ditimpa. Body: { penyerah_id?: string (default = pengirim), penerima_id: string }
if ($method === 'POST' && $sub === 'lembar1') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $penyerahId = trim((string)($body['penyerah_id'] ?? $body['penyerahId'] ?? ''));
    $penerimaId = trim((string)($body['penerima_id'] ?? $body['penerimaId'] ?? ''));
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);
    if (!(in_array($user['role'], ['ADMIN', 'ARSIPARIS', 'SEKRETARIS', 'PANITERA'], true)
        || V2Workflow::isUnitHead((string) ($user['role'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['message' => 'Hanya Arsiparis/petugas persuratan/kepala unit yang boleh mencatat serah-terima lembar 1.']);
        return;
    }
    $res = LetterTransition::recordLembar1($user, $letter, $penyerahId, $penerimaId);
    if (!$res['ok']) {
        http_response_code(422);
        echo json_encode(['message' => $res['message'], 'code' => $res['code']]);
        return;
    }
    echo json_encode(['success' => true, 'message' => $res['message']]);
    return;
}

// POST /control/:id/transition — pindah tahap dengan validasi state + role.
if ($method === 'POST' && $sub === 'transition') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $to = strtoupper(trim((string)($body['toStage'] ?? '')));
    $notes = trim((string)($body['notes'] ?? ''));
    $routeRaw = (array_key_exists('dispositionRoute', $body) && $body['dispositionRoute'] !== null)
        ? strtoupper(trim((string) $body['dispositionRoute']))
        : null;
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

    // --- Keputusan inti SOP/AS/04 langkah 13: Sekretaris/Panitera ---
    // Wajib memilih rute KEBIJAKAN (naik ke Ketua/WK) atau LANGSUNG (ke unit
    // pelaksana). Tanpa pilihan ini, surat bisa tampak "sudah didisposisikan"
    // tanpa jejak siapa yang memutuskan perlu-tidaknya kebijakan pimpinan.
    //
    // Dikunci pada MASUKNYA DIDISPOSISIKAN (bukan hanya dari MENUNGGU_DISPOSISI)
    // supaya jalur pintas TERREGISTRASI -> DIDISPOSISIKAN tidak bisa melewati
    // keputusan yang diwajibkan SOP langkah 12-13.
    $isDecision = ($to === 'DIDISPOSISIKAN' && $from !== 'DIDISPOSISIKAN');
    if ($isDecision) {
        [$routeOk, $routeErr] = V2Workflow::validateDispositionRoute($routeRaw);
        if (!$routeOk) {
            http_response_code(422);
            echo json_encode([
                'message' => $routeErr,
                'field' => 'dispositionRoute',
                'allowedRoutes' => V2Workflow::DISPOSITION_ROUTES,
            ]);
            return;
        }
    }

    // Rute keputusan mengunci percabangan TEPAT SESUDAH keputusan: surat rute
    // LANGSUNG tidak boleh belakangan naik ke pimpinan, dan surat rute KEBIJAKAN
    // tidak boleh dilompatkan langsung ke pelaksana. Setelah surat meninggalkan
    // DIDISPOSISIKAN, rute menjadi catatan historis dan state machine normal
    // berlaku kembali (kalau dikunci selamanya, surat rute LANGSUNG akan macet
    // di DITERUSKAN_KE_PELAKSANA dan tidak bisa maju ke DALAM_TINDAK_LANJUT).
    $storedRoute = strtoupper(trim((string) ($letter['dispositionRoute'] ?? '')));
    $effectiveRoute = $isDecision ? $routeRaw : ($storedRoute !== '' ? $storedRoute : null);
    if (!$isDecision && $from === 'DIDISPOSISIKAN' && $effectiveRoute !== null) {
        $lock = V2Workflow::stagesAllowedByRoute($effectiveRoute);
        if ($lock !== [] && !in_array($to, $lock, true)) {
            http_response_code(422);
            echo json_encode([
                'message' => 'Surat ini sudah diputuskan "' . V2Workflow::routeLabel($effectiveRoute)
                    . '". Transisi ke ' . $to . ' tidak sesuai rute keputusan tersebut.',
                'dispositionRoute' => $effectiveRoute,
                'allowedByRoute' => $lock,
            ]);
            return;
        }
    }

    // Catatan log mencatat keputusan secara eksplisit (audit trail KMA 131 BAB IV).
    $logNotes = $isDecision
        ? trim('Keputusan Sekretaris/Panitera: ' . V2Workflow::routeLabel($routeRaw) . '.' . ($notes !== '' ? ' ' . $notes : ''))
        : $notes;

    // Satu pintu penerapan tahap: jalur web memakai kelas yang SAMA dengan jalur
    // WhatsApp dan auto-advance laporan pelaksana (LetterTransition), sehingga
    // validasi ulang, jejak letter_control_logs, dan notifikasi per tahap selalu
    // ikut. Kalau validasi di atas lolos tetapi kelas ini menolak (mis. rute
    // berubah karena request bersamaan), penolakannya dipetakan apa adanya.
    $res = LetterTransition::apply($user, $letter, $to, [
        'notes'  => $logNotes,
        'route'  => $routeRaw,
        'source' => LetterTransition::SOURCE_WEB,
        'unit_tujuan' => $body['unit_tujuan'] ?? $body['unitTujuan'] ?? null,
        'arahan' => $body['arahan'] ?? null,
    ]);
    if (!$res['ok']) {
        if ($res['code'] === 'ROLE_DENIED') {
            http_response_code(403);
            echo json_encode(['message' => $res['message'], 'fromStage' => $from, 'toStage' => $to]);
            return;
        }
        $payload = ['message' => $res['message'], 'fromStage' => $from, 'toStage' => $to, 'code' => $res['code']];
        if ($res['code'] === 'ROUTE_REQUIRED') {
            $payload['field'] = 'dispositionRoute';
            $payload['allowedRoutes'] = V2Workflow::DISPOSITION_ROUTES;
        }
        if (in_array($res['code'], ['UNIT_TUJUAN_REQUIRED', 'UNIT_TUJUAN_INVALID'], true)) {
            $payload['field'] = 'unit_tujuan';
            $payload['allowedUnits'] = V2Workflow::UNIT_TUJUAN_LIST;
        }
        if ($res['code'] === 'ARAHAN_REQUIRED') {
            $payload['field'] = 'arahan';
        }
        if ($res['code'] === 'DIRECT_ASSIGN_DENIED') {
            $payload['hint'] = 'Tunjuk pegawai hanya via POST /control/:id/tunjuk oleh kepala unit.';
        }
        if ($res['code'] === 'REKOMENDASI_REQUIRED') {
            $payload['hint'] = 'Simpan rekomendasi rute Kasubag Umum dulu lewat POST /control/:id/rekomendasi (SOP/AS/04 langkah 12).';
        }
        if ($res['code'] === 'LEMBAR1_REQUIRED') {
            $payload['hint'] = 'Catat serah-terima lembar 1 lewat POST /control/:id/lembar1.';
        }
        if ($res['code'] === 'STAGE_NOT_READY') {
            $payload['allowedStages'] = V2Workflow::DECISION_ALLOWED_STAGES;
        }
        if ($res['code'] === 'ROUTE_LOCKED') {
            $payload['dispositionRoute'] = $effectiveRoute;
            $payload['allowedByRoute'] = V2Workflow::stagesAllowedByRoute($effectiveRoute);
        }
        http_response_code(422);
        echo json_encode($payload);
        return;
    }
    echo json_encode([
        'success' => true,
        'stage' => $res['stage'],
        'stageLabel' => V2Workflow::stageLabel($res['stage']),
        'dispositionRoute' => $res['route'] ?? $effectiveRoute,
    ]);
    return;
}

// POST /control/:id/reopen — tarik kembali surat ke meja Kasubag Umum.
// Keputusan #1 (Fase 1): Kasubag Umum memegang rantai pelaksanaan, jadi ia boleh
// menarik surat kembali bila disposisi ke pelaksana keliru. Alasan WAJIB diisi
// dan dicatat sebagai action STAGE_REOPEN supaya gerak mundur tidak pernah
// tersamar sebagai kemajuan tahap (audit KMA 131 BAB IV).
if ($method === 'POST' && $sub === 'reopen') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $reason = (string) ($body['reason'] ?? '');
    $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$id]);
    if (!$letter) { http_response_code(404); echo json_encode(['message' => 'Surat tidak ditemukan']); return; }
    $guardLetterAccess($letter);

    $res = LetterTransition::reopen($user, $letter, $reason);
    if (!$res['ok']) {
        http_response_code($res['code'] === 'REOPEN_DENIED' ? 403 : 422);
        echo json_encode([
            'message' => $res['message'],
            'code' => $res['code'],
            'fromStage' => $res['from'],
            'reopenTarget' => V2Workflow::REOPEN_TARGET_STAGE,
            'reasonMin' => V2Workflow::REOPEN_REASON_MIN,
        ]);
        return;
    }
    echo json_encode([
        'success' => true,
        'stage' => $res['stage'],
        'stageLabel' => V2Workflow::stageLabel($res['stage']),
        'mode' => $res['mode'] ?? null,
        'message' => $res['message'],
    ]);
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
    if (($letter['addressStatus'] ?? '') !== 'PERLU_VERIFIKASI') {
        // Perbandingan negatif dipakai supaya data lama yang masih memakai
        // ejaan status sebelum migrasi 2026_09_25 (ALAMAT_SESAI/
        // ALAMAT_TIDAK_SESAI) tetap terkunci, tidak bisa diverifikasi dua kali.
        http_response_code(422); echo json_encode(['message' => 'Alamat surat ini sudah diverifikasi sebelumnya.']); return;
    }
    $status = $correct ? 'ALAMAT_SESUAI' : 'ALAMAT_TIDAK_SESUAI';
    $detail = ($correct ? 'Alamat sesuai.' : 'Alamat TIDAK sesuai.') . ($notes !== '' ? ' ' . $notes : '');
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