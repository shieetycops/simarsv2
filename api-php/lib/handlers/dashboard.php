<?php
// /api/dashboard — replikasi backend/routes/dashboard.ts.
$user = Auth::requireAuth(Db::$pdo);
$id   = $segments[1] ?? '';
$sub  = $segments[2] ?? '';

// GET /dashboard/stats
if ($method === 'GET' && $id === 'stats') {
    $today = date('Y-m-d 00:00:00');
    $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $c = fn($sql, $p = []) => (int) Db::q($sql, $p)->fetch()['c'];

    $resp = [
        'incoming' => $c("SELECT COUNT(*) c FROM incoming_letters"),
        'outgoing' => $c("SELECT COUNT(*) c FROM outgoing_letters"),
        'incomingToday' => $c("SELECT COUNT(*) c FROM incoming_letters WHERE created_at >= ? AND created_at < ?", [$today, $tomorrow]),
        'outgoingToday' => $c("SELECT COUNT(*) c FROM outgoing_letters WHERE created_at >= ? AND created_at < ?", [$today, $tomorrow]),
        'pending' => $c("SELECT COUNT(*) c FROM dispositions WHERE status = 'PENDING'"),
        'inProcess' => $c("SELECT COUNT(*) c FROM dispositions WHERE status = 'PROSES'"),
        'completed' => $c("SELECT COUNT(*) c FROM dispositions WHERE status = 'SELESAI'"),
        // Surat masuk yang belum didisposisi — mirror computeLetterStatus():
        // arsip lama tanpa disposisi yang tersimpan SELESAI tidak dihitung.
        'notDisposed' => $c("SELECT COUNT(*) c FROM incoming_letters l
            WHERE l.status != 'SELESAI' AND NOT EXISTS
            (SELECT 1 FROM dispositions d WHERE d.incoming_letter_id = l.id)"),
    ];

    if (in_array($user['role'], ['ADMIN', 'PIMPINAN'], true)) {
        $rows = Db::q("SELECT u.id, u.name, d.status FROM users u
            LEFT JOIN dispositions d ON d.to_user_id = u.id")->fetchAll();
        $byUser = [];
        foreach ($rows as $r) {
            if (!isset($byUser[$r['id']])) $byUser[$r['id']] = ['name' => $r['name'], 'statuses' => []];
            if ($r['status'] !== null) $byUser[$r['id']]['statuses'][] = $r['status'];
        }
        $resp['userStats'] = Dashboard::userStats(array_values($byUser));
    }
    echo json_encode($resp);
    return;
}

// GET /dashboard/chart — 6 bulan terakhir
if ($method === 'GET' && $id === 'chart') {
    $names = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $start = date('Y-m-01 00:00:00', strtotime($i ? "first day of -$i month" : "first day of this month"));
        $end   = date('Y-m-01 00:00:00', strtotime("first day of +1 month", strtotime($start)));
        $masuk  = (int) Db::q("SELECT COUNT(*) c FROM incoming_letters WHERE created_at >= ? AND created_at < ?", [$start, $end])->fetch()['c'];
        $keluar = (int) Db::q("SELECT COUNT(*) c FROM outgoing_letters WHERE created_at >= ? AND created_at < ?", [$start, $end])->fetch()['c'];
        $months[] = ['month' => $names[(int) date('n', strtotime($start)) - 1], 'year' => (int) date('Y', strtotime($start)), 'masuk' => $masuk, 'keluar' => $keluar];
    }
    echo json_encode($months);
    return;
}

// GET /dashboard/activity — log aktivitas (maks 200) + user
if ($method === 'GET' && $id === 'activity') {
    [$w, $p] = dateWhere($_GET['startDate'] ?? null, $_GET['endDate'] ?? null, 'a.created_at');
    $logs = Db::all("SELECT a.*, u.name AS user_name, u.role AS user_role
        FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
        $w ORDER BY a.created_at DESC LIMIT 200", $p);
    foreach ($logs as &$l) {
        $l['user'] = ['name' => $l['userName'] ?? null, 'role' => $l['userRole'] ?? null];
        unset($l['userName'], $l['userRole']);
    }
    echo json_encode($logs);
    return;
}

// GET /dashboard/my-pending — disposisi yang masih PENDING/PROSES milik user login
if ($method === 'GET' && $id === 'my-pending') {
    $rows = Db::all(
        "SELECT d.*, l.subject AS letter_subject, l.sender AS letter_sender,
                l.letter_number AS letter_number
         FROM dispositions d
         JOIN incoming_letters l ON l.id = d.incoming_letter_id
         WHERE d.to_user_id = ? AND d.status IN ('PENDING', 'PROSES')
         ORDER BY d.deadline IS NULL, d.deadline ASC, d.created_at DESC
         LIMIT 10",
        [$user['id']]
    );
    echo json_encode($rows);
    return;
}

// notifications
if ($id === 'notifications') {
    // GET /dashboard/notifications — 20 terbaru milik user
    if ($method === 'GET' && $sub === '') {
        echo json_encode(Db::all("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20", [$user['id']]));
        return;
    }
    // PATCH /dashboard/notifications/read-all
    if ($method === 'PATCH' && $sub === 'read-all') {
        Db::q("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$user['id']]);
        echo json_encode(['success' => true]);
        return;
    }
    // PATCH /dashboard/notifications/:id/read
    if ($method === 'PATCH' && ($segments[3] ?? '') === 'read') {
        Db::q("UPDATE notifications SET is_read = 1 WHERE id = ?", [$sub]);
        echo json_encode(['success' => true]);
        return;
    }
}

// GET /dashboard/deadline-check — buat notifikasi deadline terlewat (dedup 24 jam)
if ($method === 'GET' && $id === 'deadline-check') {
    $now = date('Y-m-d 23:59:59');
    $overdue = Db::all("SELECT d.*, tu.id AS tu_id, tu.name AS tu_name,
            il.subject AS il_subject, il.letter_number AS il_number
        FROM dispositions d
        JOIN users tu ON tu.id = d.to_user_id
        JOIN incoming_letters il ON il.id = d.incoming_letter_id
        WHERE d.status != 'SELESAI' AND d.deadline <= ?", [$now]);
    $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $created = 0;
    foreach ($overdue as $d) {
        $msg = "Disposisi \"{$d['ilSubject']}\" ({$d['ilNumber']}) telah melewati batas waktu.";
        $existing = Db::one("SELECT id FROM notifications WHERE user_id = ? AND message LIKE ? AND created_at >= ? LIMIT 1",
            [$d['tuId'], '%' . $d['ilNumber'] . '%', $since]);
        if (!$existing) {
            Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, 'Deadline Disposisi Terlewat', ?, '/disposisi')",
                [Db::generateId(), $d['tuId'], $msg]);
            $created++;
        }
    }
    echo json_encode(['checked' => count($overdue), 'created' => $created]);
    return;
}

// GET /dashboard/archives — gabungan surat masuk + keluar (search)
if ($method === 'GET' && $id === 'archives') {
    $s = $_GET['search'] ?? '';
    $inW = ''; $inP = []; $outW = ''; $outP = [];
    if ($s !== '') {
        $like = "%$s%";
        $inW = "WHERE subject LIKE ? OR letter_number LIKE ? OR sender LIKE ? OR agenda_number LIKE ?";
        $inP = [$like, $like, $like, $like];
        $outW = "WHERE subject LIKE ? OR letter_number LIKE ? OR destination LIKE ?";
        $outP = [$like, $like, $like];
    }
    $incoming = Db::all("SELECT id, agenda_number, letter_number, subject, sender, file_path, created_at, letter_date FROM incoming_letters $inW ORDER BY created_at DESC", $inP);
    $outgoing = Db::all("SELECT id, letter_number, subject, destination, file_path, created_at, letter_date FROM outgoing_letters $outW ORDER BY created_at DESC", $outP);
    $archives = [];
    foreach ($incoming as $l) { $l['type'] = 'masuk'; $l['origin'] = $l['sender']; $archives[] = $l; }
    foreach ($outgoing as $l) { $l['type'] = 'keluar'; $l['origin'] = $l['destination']; $archives[] = $l; }
    usort($archives, fn($a, $b) => strtotime($b['createdAt']) <=> strtotime($a['createdAt']));
    echo json_encode($archives);
    return;
}

// POST /dashboard/backup — dump SQL murni PHP (tanpa mysqldump/shell_exec)
if ($method === 'POST' && $id === 'backup') {
    // Hanya ADMIN. Dump ini berisi SELURUH isi database (termasuk hash password
    // user), jadi tidak boleh bisa dipicu oleh STAFF/PIMPINAN.
    Auth::requireRole($user, ['ADMIN']);
    try {
        $dir = dirname(__DIR__, 2) . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        // Dump ini berisi SELURUH isi tabel (termasuk hash password), jadi
        // folder-nya wajib tidak bisa diunduh lewat HTTP. backups/ ada di
        // .gitignore, sehingga backups/.htaccess bisa saja tidak ikut ter-deploy
        // -> tulis ulang bila belum ada (best-effort).
        $htaccess = "$dir/.htaccess";
        if (!is_file($htaccess)) {
            // Require all denied (Apache 2.4) + fallback Apache 2.2.
            @file_put_contents($htaccess,
                "# Hasil dump database berisi SELURUH isi tabel (termasuk hash password).\n"
                . "# Tidak boleh bisa diunduh lewat HTTP - hanya lewat panel hosting/FTP.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        $ts = date('Ymd_His');
        $filename = "backup_$ts.sql";
        $out = fopen("$dir/$filename", 'w');
        if ($out === false) {
            throw new RuntimeException("Tidak bisa menulis $dir/$filename");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
        $tables = Db::q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            foreach (Db::q("SELECT * FROM `$t`")->fetchAll() as $row) {
                $cols = implode('`, `', array_keys($row));
                $vals = implode(', ', array_map(fn($v) => $v === null ? 'NULL' : Db::$pdo->quote($v), array_values($row)));
                fwrite($out, "INSERT INTO `$t` (`$cols`) VALUES ($vals);\n");
            }
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
        // Retensi: sisakan 10 backup terbaru supaya disk hosting tidak penuh.
        $old = glob("$dir/backup_*.sql") ?: [];
        usort($old, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($old, 10) as $stale) {
            @unlink($stale);
        }
        echo json_encode(['success' => true, 'filename' => $filename]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal membuat backup database.']);
    }
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
