<?php
// /api/outgoing — replikasi backend/routes/outgoing.ts.
$user = Auth::requireAuth(Db::$pdo);
$id   = $segments[1] ?? '';

// GET /outgoing/next-agenda — SARAN nomor agenda berikutnya (bukan wajib dipakai)
if ($method === 'GET' && $id === 'next-agenda') {
    $prefix = 'SK/' . date('Y') . '/';
    $latest = Db::one(
        "SELECT agenda_number FROM outgoing_letters WHERE agenda_number LIKE ? ORDER BY agenda_number DESC LIMIT 1",
        ["$prefix%"]
    );
    $next = 1;
    if ($latest && $latest['agendaNumber']) {
        $seq = (int) (explode('/', $latest['agendaNumber'])[2] ?? 0);
        if ($seq > 0) $next = $seq + 1;
    }
    echo json_encode(['agendaNumber' => $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT)]);
    return;
}

// GET /outgoing/next-letter — SARAN nomor surat berikutnya (unit + tanggal).
// Mendukung mode sisipan (?after=5 -> 5.a, 5.b, ...). Read-only, semua user login.
if ($method === 'GET' && $id === 'next-letter') {
    $unit  = $_GET['unit'] ?? '';
    $date  = $_GET['date'] ?? date('Y-m-d');
    $after = (isset($_GET['after']) && $_GET['after'] !== '') ? (int) $_GET['after'] : null;
    $kode  = $_GET['kode'] ?? null;
    try {
        echo json_encode(OutgoingNumbering::next(Db::$pdo, $unit, $date, $after, $kode));
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['message' => $e->getMessage()]);
    }
    return;
}

// ---- Slot nomor ("Ambil Nomor") — reservasi nomor untuk semua user login ----
if ($id === 'slots') {
    $slotId = $segments[2] ?? '';

    // GET /outgoing/slots?unit=&year= — daftar reservasi.
    if ($method === 'GET' && $slotId === '') {
        $where = ['1=1']; $p = [];
        $unit = $_GET['unit'] ?? '';
        $year = $_GET['year'] ?? '';
        if ($unit !== '') { $where[] = 'issuing_unit = ?'; $p[] = $unit; }
        if ($year !== '') { $where[] = 'YEAR(letter_date) = ?'; $p[] = (int) $year; }
        echo json_encode(Db::all(
            'SELECT * FROM outgoing_number_slots WHERE ' . implode(' AND ', $where)
            . ' ORDER BY letter_date DESC, sequence DESC, reserved_at DESC',
            $p
        ));
        return;
    }

    // POST /outgoing/slots — ambil/reservasi nomor (semua user login).
    if ($method === 'POST' && $slotId === '') {
        $b     = json_decode(file_get_contents('php://input'), true) ?: [];
        $unit  = $b['unit'] ?? '';
        $date  = $b['date'] ?? date('Y-m-d');
        $after = (isset($b['after']) && $b['after'] !== '') ? (int) $b['after'] : null;
        $kode  = $b['kode'] ?? null;
        try {
            $n = OutgoingNumbering::next(Db::$pdo, $unit, $date, $after, $kode);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['message' => $e->getMessage()]);
            return;
        }
        // Simpan dengan retry bila kena duplikat (dua orang ambil bersamaan).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $newId = Db::generateId();
            try {
                Db::q(
                    "INSERT INTO outgoing_number_slots
                        (id, issuing_unit, letter_date, sequence, suffix, kode, letter_number, status, reserved_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'DIPESAN', ?)",
                    [$newId, $unit, date('Y-m-d', strtotime($date)), $n['sequence'], $n['suffix'],
                     $kode, $n['letterNumber'], $user['id']]
                );
                logActivity($user['id'], 'CREATE', 'OUTGOING_NUMBER_SLOT', $newId,
                    "Reservasi nomor surat keluar: {$n['letterNumber']} ({$unit})");
                http_response_code(201);
                echo json_encode(Db::one('SELECT * FROM outgoing_number_slots WHERE id = ?', [$newId]));
                return;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                // Nomor bentrok (balapan) — hitung ulang lalu coba lagi.
                $n = OutgoingNumbering::next(Db::$pdo, $unit, $date, $after, $kode);
            }
        }
        http_response_code(409);
        echo json_encode(['message' => 'Nomor sudah diambil pihak lain, silakan coba lagi.']);
        return;
    }

    // PUT /outgoing/slots/:id — ubah status (BATAL / TERBIT).
    if ($method === 'PUT' && $slotId !== '') {
        $b      = json_decode(file_get_contents('php://input'), true) ?: [];
        $status = strtoupper((string) ($b['status'] ?? ''));
        if (!in_array($status, ['BATAL', 'TERBIT'], true)) {
            http_response_code(400);
            echo json_encode(['message' => 'Status harus BATAL atau TERBIT.']);
            return;
        }
        $slot = Db::one('SELECT * FROM outgoing_number_slots WHERE id = ?', [$slotId]);
        if (!$slot) {
            http_response_code(404);
            echo json_encode(['message' => 'Slot tidak ditemukan.']);
            return;
        }
        if ($status === 'TERBIT' && !in_array($user['role'], ['ADMIN', 'SEKRETARIS'], true)) {
            http_response_code(403);
            echo json_encode(['message' => 'Hanya admin/sekretaris yang bisa menandai nomor terbit.']);
            return;
        }
        if ($status === 'BATAL'
            && !in_array($user['role'], ['ADMIN', 'SEKRETARIS'], true)
            && $slot['reservedBy'] !== $user['id']) {
            http_response_code(403);
            echo json_encode(['message' => 'Hanya pemilik slot, admin, atau sekretaris yang bisa membatalkan.']);
            return;
        }
        Db::q('UPDATE outgoing_number_slots SET status = ? WHERE id = ?', [$status, $slotId]);
        logActivity($user['id'], 'UPDATE', 'OUTGOING_NUMBER_SLOT', $slotId,
            "Ubah status slot menjadi {$status}: {$slot['letterNumber']}");
        echo json_encode(Db::one('SELECT * FROM outgoing_number_slots WHERE id = ?', [$slotId]));
        return;
    }

    http_response_code(404);
    echo json_encode(['message' => 'Not found']);
    return;
}

// GET /outgoing
if ($method === 'GET' && $id === '') {
    echo json_encode(Db::all("SELECT * FROM outgoing_letters ORDER BY created_at DESC"));
    return;
}

// POST /outgoing (ADMIN/SEKRETARIS) — buat surat + file opsional
if ($method === 'POST' && $id === '') {
    Auth::requireRole($user, ['ADMIN', 'SEKRETARIS']);
    $data = isset($_POST['data']) ? json_decode($_POST['data'], true) : ($_POST ?: json_decode(file_get_contents('php://input'), true));
    $errors = requireFields($data, ['agendaNumber', 'letterNumber', 'letterDate', 'destination', 'subject', 'signer', 'classification', 'issuingUnit']);
    if ($errors) {
        http_response_code(400);
        echo json_encode(['message' => 'Data tidak valid', 'errors' => $errors]);
        return;
    }
    try {
        $newId = Db::generateId();
        $path  = Upload::save($_FILES['file'] ?? null);
        Db::q("INSERT INTO outgoing_letters
            (id, agenda_number, letter_number, letter_date, destination, subject, signer, classification, issuing_unit, nature, description, file_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $newId, $data['agendaNumber'], $data['letterNumber'],
            date('Y-m-d H:i:s', strtotime($data['letterDate'])),
            $data['destination'], $data['subject'], $data['signer'],
            $data['classification'], $data['issuingUnit'], $data['nature'] ?? 'BIASA', $data['description'] ?? null, $path,
        ]);
        logActivity($user['id'], 'CREATE', 'OUTGOING_LETTER', $newId, "Menginput surat keluar: {$data['letterNumber']}");
        http_response_code(201);
        echo json_encode(Db::one("SELECT * FROM outgoing_letters WHERE id = ?", [$newId]));
    } catch (AuthException $e) {
        throw $e;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['message' => 'Nomor agenda sudah digunakan, silakan gunakan nomor lain.']);
            return;
        }
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menyimpan surat keluar.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menyimpan surat keluar.']);
    }
    return;
}

// PUT /outgoing/:id (ADMIN/SEKRETARIS)
if ($method === 'PUT' && $id !== '') {
    Auth::requireRole($user, ['ADMIN', 'SEKRETARIS']);
    $b = isset($_POST['data']) ? json_decode($_POST['data'], true) : ($_POST ?: json_decode(file_get_contents('php://input'), true)) ?? [];
    $map = ['agendaNumber' => 'agenda_number', 'letterNumber' => 'letter_number', 'destination' => 'destination',
        'subject' => 'subject', 'signer' => 'signer', 'classification' => 'classification',
        'issuingUnit' => 'issuing_unit', 'nature' => 'nature', 'description' => 'description'];
    $set = [];
    $vals = [];
    foreach ($map as $f => $c) {
        if (array_key_exists($f, $b)) { $set[] = "$c = ?"; $vals[] = $b[$f]; }
    }
    if (!empty($b['letterDate'])) { $set[] = "letter_date = ?"; $vals[] = date('Y-m-d H:i:s', strtotime($b['letterDate'])); }
    try {
        // Ganti file lampiran kalau ada file baru terkirim
        if (isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $old     = Db::one("SELECT file_path FROM outgoing_letters WHERE id = ?", [$id]);
            $newPath = Upload::save($_FILES['file']);
            $oldPath = $old['filePath'] ?? null;
            if ($oldPath && file_exists(dirname(__DIR__, 3) . $oldPath)) {
                @unlink(dirname(__DIR__, 3) . $oldPath);
            }
            $set[]  = "file_path = ?";
            $vals[] = $newPath;
        }
        if ($set) { $vals[] = $id; Db::q("UPDATE outgoing_letters SET " . implode(', ', $set) . " WHERE id = ?", $vals); }
        echo json_encode(Db::one("SELECT * FROM outgoing_letters WHERE id = ?", [$id]));
    } catch (AuthException $e) {
        throw $e; // error upload (400) ditangani router
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['message' => 'Nomor agenda sudah digunakan, silakan gunakan nomor lain.']);
            return;
        }
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui surat.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui surat.']);
    }
    return;
}

// DELETE /outgoing/:id (ADMIN)
if ($method === 'DELETE' && $id !== '') {
    Auth::requireRole($user, ['ADMIN']);
    try {
        Db::q("DELETE FROM outgoing_letters WHERE id = ?", [$id]);
        echo json_encode(['message' => 'Surat berhasil dihapus.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menghapus surat.']);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
