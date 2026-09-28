<?php
// /api/incoming — replikasi backend/routes/incoming.ts.
// GET /incoming/:id publik (view-only, link WA) dicek SEBELUM auth.
// Sisanya (list, next-agenda, POST, PUT, DELETE) wajib auth.
$id   = $segments[1] ?? '';

// GET /incoming/:id — publik (tanpa auth), dipakai link view-only dari WA
// (helpers::letterViewUrl => {appUrl}/surats/{id}?t=<token>).
// Token HMAC wajib: tanpa itu, siapa pun yang meneruskan URL-nya bisa membaca
// isi surat beserta instruksi disposisi internal dan nama pegawai.
if ($method === 'GET' && $id !== '' && $id !== 'next-agenda') {
    $allowed = letterViewTokenValid($id, $_GET['t'] ?? null);
    if (!$allowed) {
        // Pengguna yang sudah login (mis. membuka arsip dari dalam aplikasi)
        // tetap boleh membaca tanpa token.
        try {
            Auth::requireAuth(Db::$pdo);
            $allowed = true;
        } catch (Throwable $e) {
            $allowed = false;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['message' => 'Tautan tidak valid atau sudah kedaluwarsa.']);
        return;
    }

    $row = Db::one("SELECT * FROM incoming_letters WHERE id = ?", [$id]);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['message' => 'Surat tidak ditemukan.']);
        return;
    }
    // Surat RAHASIA/SANGAT_RAHASIA tidak boleh dibuka lewat tautan publik;
    // hanya pengguna login dengan role berwenang (V2Workflow) yang boleh.
    $security = strtoupper((string)($row['securityLevel'] ?? 'BIASA'));
    if (in_array($security, V2Workflow::RAHASIA_LEVELS, true)) {
        try {
            $viewer = Auth::requireAuth(Db::$pdo);
        } catch (Throwable $e) {
            $viewer = null;
        }
        if (!V2Workflow::userCanAccessLetter($viewer, $security)) {
            http_response_code(403);
            echo json_encode(['message' => 'Surat dengan level keamanan ini tidak dapat diakses.']);
            return;
        }
    }
    $row['dispositions'] = Db::all(
        "SELECT d.id, d.instruction, d.status, d.deadline, d.notes,
                fu.name AS from_name, tu.name AS to_name
         FROM dispositions d
         LEFT JOIN users fu ON fu.id = d.from_user_id
         LEFT JOIN users tu ON tu.id = d.to_user_id
         WHERE d.incoming_letter_id = ?
         ORDER BY d.created_at DESC",
        [$id]
    );
    echo json_encode($row);
    return;
}

// Auth wajib untuk semua endpoint lainnya.
$user = Auth::requireAuth(Db::$pdo);

// GET /incoming/next-agenda — nomor agenda berikutnya AGD/<tahun>/<seq>
if ($method === 'GET' && $id === 'next-agenda') {
    $year   = date('Y');
    $prefix = "AGD/$year/";
    $latest = Db::one(
        "SELECT agenda_number FROM incoming_letters WHERE agenda_number LIKE ? ORDER BY agenda_number DESC LIMIT 1",
        ["$prefix%"]
    );
    $next = 1;
    if ($latest) {
        $seq = (int) (explode('/', $latest['agendaNumber'])[2] ?? 0);
        if ($seq > 0) $next = $seq + 1;
    }
    echo json_encode(['agendaNumber' => $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT)]);
    return;
}

// GET /incoming — semua surat + dispositions (surat rahasia difilter per role)
if ($method === 'GET' && $id === '') {
    $letters = Db::all("SELECT * FROM incoming_letters ORDER BY created_at DESC");
    $letters = array_values(array_filter($letters, fn($l) => V2Workflow::userCanAccessLetter($user, (string)($l['securityLevel'] ?? 'BIASA'))));
    echo json_encode(attachDispositions($letters));
    return;
}

// POST /incoming (ADMIN/SEKRETARIS) — buat surat + file opsional
if ($method === 'POST' && $id === '') {
    Auth::requireRole($user, ['ADMIN', 'SEKRETARIS']);
    $data = isset($_POST['data']) ? json_decode($_POST['data'], true) : ($_POST ?: json_decode(file_get_contents('php://input'), true));
    $errors = requireFields($data, ['agendaNumber', 'letterNumber', 'letterDate', 'receivedDate', 'sender', 'subject', 'classification']);
    if (!empty($data['securityLevel']) && !in_array(strtoupper((string)$data['securityLevel']), V2Workflow::SECURITY_LEVELS, true)) {
        $errors['securityLevel'] = ['Level keamanan tidak valid.'];
    }
    [$codeOk, $codeErr] = V2Workflow::validateArchiveCode($data['archiveCode'] ?? null);
    if (!$codeOk) { $errors['archiveCode'] = [$codeErr]; }
    if ($errors) {
        http_response_code(400);
        echo json_encode(['message' => 'Data tidak valid', 'errors' => $errors]);
        return;
    }
    try {
        $newId = Db::generateId();
        $path  = Upload::save($_FILES['file'] ?? null);
        $security = strtoupper((string)($data['securityLevel'] ?? 'BIASA'));
        $category = strtoupper((string)($data['letterCategory'] ?? 'DINAS'));
        $stage = 'DITERIMA';
        // Status kode arsip: OFFICIAL bila terdaftar di master (SK 627/2023),
        // PENDING_VALIDATION bila format sah primer resmi tapi belum ada di master.
        $archiveCode = strtoupper(trim((string)($data['archiveCode'] ?? '')));
        $archiveCodeStatus = null;
        if ($archiveCode !== '') {
            $cls = Db::one('SELECT validation_status AS validationStatus FROM archive_classifications WHERE code = ?', [$archiveCode]);
            $archiveCodeStatus = $cls ? $cls['validationStatus'] : 'PENDING_VALIDATION';
        }
        Db::q("INSERT INTO incoming_letters
            (id, agenda_number, letter_number, letter_date, received_date, sender, subject, classification, nature, description, file_path,
             source_channel, letter_category, document_type, security_level, urgency_level, archive_code, archive_code_status, address_status,
             completeness_status, current_stage, canonical_file_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $newId, $data['agendaNumber'], $data['letterNumber'],
            date('Y-m-d H:i:s', strtotime($data['letterDate'])),
            date('Y-m-d H:i:s', strtotime($data['receivedDate'])),
            $data['sender'], $data['subject'], $data['classification'],
            $data['nature'] ?? 'BIASA', $data['description'] ?? null, $path,
            strtoupper((string)($data['sourceChannel'] ?? 'LAINNYA')), $category,
            strtoupper((string)($data['documentType'] ?? 'SURAT_DINAS')), $security,
            strtoupper((string)($data['urgencyLevel'] ?? 'NORMAL')), $archiveCode ?: null, $archiveCodeStatus,
            'PERLU_VERIFIKASI', 'BELUM_DIPERIKSA', $stage, $data['canonicalFileName'] ?? null,
        ]);

        // Notifikasi otomatis ke seluruh PIMPINAN agar segera didisposisi.
        $leaders = Db::all("SELECT id, name FROM users WHERE role = 'PIMPINAN' AND is_active = 1");
        $shortSubject = substr($data['subject'], 0, 100);
        foreach ($leaders as $leader) {
            Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)", [
                Db::generateId(),
                $leader['id'],
                'Surat Masuk Baru',
                "Surat masuk dari {$data['sender']} perihal \"{$shortSubject}\" memerlukan disposisi.",
                '/v2/buku-kendali',
            ]);
        }
        if ($security === 'BIASA' || $security === 'TERBATAS') Whatsapp::notifyNewIncomingLetter(Db::$pdo, [
            'agendaNumber' => $data['agendaNumber'],
            'letterNumber' => $data['letterNumber'],
            'sender'       => $data['sender'],
            'subject'      => $data['subject'],
            'receivedDate' => $data['receivedDate'],
            'actorUserId'  => $user['id'],
        ]);

        // DM langsung ke nomor WA tiap PIMPINAN aktif + sesi LEADER otomatis,
        // lampiran view-only (helpers::letterViewUrl) bila file sudah terupload.
        $letterRow = Db::one("SELECT * FROM incoming_letters WHERE id = ?", [$newId]);
        if ($security === 'BIASA' || $security === 'TERBATAS') Whatsapp::notifyLeaderDm(Db::$pdo, [
            'letterId'      => $newId,
            'agendaNumber'  => $data['agendaNumber'],
            'letterNumber'  => $data['letterNumber'],
            'sender'        => $data['sender'],
            'subject'       => $data['subject'],
            'receivedDate'  => $data['receivedDate'],
            'attachmentUrl' => $letterRow ? letterViewUrl($newId) : '',
            'actorUserId'   => $user['id'],
        ]);

        logActivity($user['id'], 'CREATE', 'INCOMING_LETTER', $newId, "Menginput surat masuk: {$data['letterNumber']}");
        http_response_code(201);
        echo json_encode(Db::one("SELECT * FROM incoming_letters WHERE id = ?", [$newId]));
    } catch (AuthException $e) {
        throw $e; // error upload (400) ditangani router
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menyimpan surat masuk.']);
    }
    return;
}

// PUT /incoming/:id (ADMIN/SEKRETARIS) — koreksi data surat + ganti lampiran.
//
// Dibatasi tahap (V2Workflow::CORRECTABLE_STAGES + belum ada disposisi):
// sesudah ada keputusan disposisi, salinan instruksi sudah beredar ke
// pimpinan/pelaksana sehingga mengubah record surat membuat riwayat mereka
// tidak lagi cocok. Role juga dijaga: SEKRETARIS yang tidak berwenang atas
// surat RAHASIA tidak boleh mengubahnya (403), bukan cuma tidak melihat.
//
// CATATAN PENTING (kenapa ada jalur POST + _method=PUT):
// PHP HANYA memparsing body multipart pada request POST. Untuk PUT, $_POST dan
// $_FILES selalu kosong dan php://input berisi multipart mentah. Akibatnya
// unggahan lewat "multipart PUT" (dipakai UI lama saat menekan Edit + ganti
// lampiran) tidak pernah benar-benar masuk: server hanya melihat body yang
// tidak terbaca dan tetap menjawab 200 seolah berhasil. Handler ini karena itu
// menerima dua bentuk:
//   1. PUT + JSON body       -> koreksi data (tanpa lampiran)
//   2. POST /incoming/:id    -> multipart dengan field _method=PUT (data+lampiran)
// Jalur 2 tidak bertabrakan dengan rute lain: POST /incoming hanya berarti
// registrasi baru saat id kosong.
$isUpdate = $method === 'PUT'
    || ($method === 'POST' && $id !== '' && strtoupper((string) ($_POST['_method'] ?? '')) === 'PUT');
if ($isUpdate && $id !== '') {
    Auth::requireRole($user, ['ADMIN', 'SEKRETARIS']);
    $b = isset($_POST['data']) ? json_decode($_POST['data'], true) : ($_POST ?: json_decode(file_get_contents('php://input'), true)) ?? [];
    unset($b['_method']);
    $existing = Db::one("SELECT * FROM incoming_letters WHERE id = ?", [$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['message' => 'Surat tidak ditemukan.']);
        return;
    }
    if (!V2Workflow::userCanAccessLetter($user, (string) ($existing['securityLevel'] ?? 'BIASA'))) {
        http_response_code(403);
        echo json_encode(['message' => 'Anda tidak berwenang mengubah surat dengan level keamanan ini.']);
        return;
    }
    $stageNow = (string) ($existing['currentStage'] ?? '');
    if (!letterAllowsCorrection($stageNow, $id)) {
        http_response_code(422);
        echo json_encode(['message' => correctionBlockedReason($stageNow, $id), 'currentStage' => $stageNow]);
        return;
    }

    // Kolom v2 bertipe VARCHAR: tanpa validasi ini, nilai di luar daftar resmi
    // tersimpan apa adanya (mis. security_level='PENTING').
    $errors = [];
    $enumFields = [
        'securityLevel'  => [V2Workflow::SECURITY_LEVELS, 'Level keamanan tidak valid.'],
        'urgencyLevel'   => [V2Workflow::URGENCY_LEVELS, 'Tingkat urgensi tidak valid.'],
        'sourceChannel'  => [V2Workflow::SOURCE_CHANNELS, 'Sumber penerimaan tidak valid.'],
        'documentType'   => [V2Workflow::DOCUMENT_TYPES, 'Jenis naskah tidak valid.'],
        'letterCategory' => [V2Workflow::LETTER_CATEGORIES, 'Jenis surat (Dinas/Pribadi) tidak valid.'],
    ];
    foreach ($enumFields as $field => [$allowed, $msg]) {
        if (!array_key_exists($field, $b)) continue;
        if (!in_array(strtoupper(trim((string) $b[$field])), $allowed, true)) {
            $errors[$field] = [$msg];
        }
    }
    $newArchiveCode = null;
    if (array_key_exists('archiveCode', $b)) {
        [$codeOk, $codeErr] = V2Workflow::validateArchiveCode($b['archiveCode']);
        if (!$codeOk) { $errors['archiveCode'] = [$codeErr]; }
        $newArchiveCode = strtoupper(trim((string) $b['archiveCode']));
    }
    if ($errors) {
        http_response_code(400);
        echo json_encode(['message' => 'Data tidak valid', 'errors' => $errors]);
        return;
    }

    $map = ['agendaNumber' => 'agenda_number', 'letterNumber' => 'letter_number', 'sender' => 'sender',
        'subject' => 'subject', 'classification' => 'classification', 'nature' => 'nature', 'description' => 'description'];
    $set = [];
    $vals = [];
    foreach ($map as $f => $c) {
        if (array_key_exists($f, $b)) { $set[] = "$c = ?"; $vals[] = $b[$f]; }
    }
    foreach (['letterDate' => 'letter_date', 'receivedDate' => 'received_date'] as $f => $c) {
        if (!empty($b[$f])) { $set[] = "$c = ?"; $vals[] = date('Y-m-d H:i:s', strtotime($b[$f])); }
    }
    // Kolom v2: hanya berubah kalau dikirim, jadi update sebagian tetap sah.
    foreach ([
        'sourceChannel'     => 'source_channel',
        'letterCategory'    => 'letter_category',
        'documentType'      => 'document_type',
        'securityLevel'     => 'security_level',
        'urgencyLevel'      => 'urgency_level',
        'canonicalFileName' => 'canonical_file_name',
    ] as $f => $c) {
        if (array_key_exists($f, $b)) { $set[] = "$c = ?"; $vals[] = strtoupper(trim((string) $b[$f])); }
    }
    if (array_key_exists('archiveCode', $b)) {
        // Status kode arsip dihitung ulang persis seperti POST: OFFICIAL bila
        // terdaftar di master SK 627/2023, PENDING_VALIDATION bila formatnya sah
        // tetapi belum terdaftar, null bila dikosongkan.
        $archiveStatus = null;
        if ($newArchiveCode !== '') {
            $cls = Db::one('SELECT validation_status AS validationStatus FROM archive_classifications WHERE code = ?', [$newArchiveCode]);
            $archiveStatus = $cls ? $cls['validationStatus'] : 'PENDING_VALIDATION';
        }
        $set[] = 'archive_code = ?';        $vals[] = $newArchiveCode ?: null;
        $set[] = 'archive_code_status = ?'; $vals[] = $archiveStatus;
    }
    try {
        // Ganti file lampiran kalau ada file baru terkirim. Berkas lama dihapus
        // lewat Upload::delete() supaya aturan folder berkas dan penjagaan pola
        // path (/uploads/<nama aman>) hanya hidup di satu tempat.
        $newPath = null;
        if (isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newPath = Upload::save($_FILES['file']);
            $set[]  = "file_path = ?";
            $vals[] = $newPath;
        }
        if ($set) { $vals[] = $id; Db::q("UPDATE incoming_letters SET " . implode(', ', $set) . " WHERE id = ?", $vals); }
        if ($newPath) { Upload::delete($existing['filePath'] ?? null); }
        logActivity($user['id'], 'UPDATE', 'INCOMING_LETTER', $id,
            "Memperbarui data surat masuk: {$existing['agendaNumber']}");
        echo json_encode(Db::one("SELECT * FROM incoming_letters WHERE id = ?", [$id]));
    } catch (AuthException $e) {
        throw $e; // error upload (400) ditangani router
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui surat.']);
    }
    return;
}

// DELETE /incoming/:id (ADMIN) — hapus surat yang masih boleh dikoreksi.
//
// Guard tahap: surat yang sudah didisposisikan tidak boleh dihapus karena
// instruksi & tanda terima orang lain akan menjadi riwayat yatim.
// Log aktivitas ditulis SEBELUM penghapusan: baris letter_control_logs dan
// letter_completeness_checks ikut terhapus oleh ON DELETE CASCADE, jadi
// activity_logs satu-satunya jejak audit yang tersisa.
if ($method === 'DELETE' && $id !== '') {
    Auth::requireRole($user, ['ADMIN']);
    $existing = Db::one("SELECT id, agenda_number AS agendaNumber, letter_number AS letterNumber,
        current_stage AS currentStage, file_path AS filePath
        FROM incoming_letters WHERE id = ?", [$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['message' => 'Surat tidak ditemukan.']);
        return;
    }
    if (!letterAllowsCorrection($existing['currentStage'] ?? null, $id)) {
        http_response_code(422);
        echo json_encode(['message' => correctionBlockedReason($existing['currentStage'] ?? null, $id), 'currentStage' => $existing['currentStage']]);
        return;
    }
    try {
        logActivity($user['id'], 'DELETE', 'INCOMING_LETTER', $id,
            "Menghapus surat masuk: {$existing['agendaNumber']} / {$existing['letterNumber']}");
        Db::q("DELETE FROM incoming_letters WHERE id = ?", [$id]);
        Upload::delete($existing['filePath'] ?? null);
        echo json_encode(['message' => 'Surat berhasil dihapus.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menghapus surat.']);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
