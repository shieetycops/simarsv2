<?php
// /api/reports — replikasi backend/routes/report.ts. Role ADMIN/PIMPINAN/SEKRETARIS.
$user = Auth::requireAuth(Db::$pdo);
Auth::requireRole($user, ['ADMIN', 'PIMPINAN', 'SEKRETARIS']);
$id     = $segments[1] ?? '';
$userId = $_GET['userId'] ?? null;

try {
    // GET /reports/incoming — surat masuk + dispositions, filter tanggal + userId (penerima disposisi)
    if ($method === 'GET' && $id === 'incoming') {
        [$w, $p] = dateWhere($_GET['startDate'] ?? null, $_GET['endDate'] ?? null, 'created_at');
        if ($userId && $userId !== 'all') {
            $w = ($w ? "$w AND " : 'WHERE ') . "id IN (SELECT incoming_letter_id FROM dispositions WHERE to_user_id = ?)";
            $p[] = $userId;
        }
        reportEnumFilters($w, $p);
 echo json_encode(attachDispositions(Db::all("SELECT * FROM incoming_letters $w", $p)));
        return;
    }

    // GET /reports/outgoing — surat keluar, filter tanggal
    if ($method === 'GET' && $id === 'outgoing') {
        [$w, $p] = dateWhere($_GET['startDate'] ?? null, $_GET['endDate'] ?? null, 'created_at');
        reportEnumFilters($w, $p);
 echo json_encode(Db::all("SELECT * FROM outgoing_letters $w", $p));
        return;
    }

    // GET /reports/dispositions — disposisi + incomingLetter + toUser{name}, filter tanggal + userId
    if ($method === 'GET' && $id === 'dispositions') {
        [$w, $p] = dateWhere($_GET['startDate'] ?? null, $_GET['endDate'] ?? null, 'd.created_at');
        if ($userId && $userId !== 'all') {
            $w = ($w ? "$w AND " : 'WHERE ') . "d.to_user_id = ?";
            $p[] = $userId;
        }
        reportLetterEnumFilters($w, $p);
 $rows = Db::all("SELECT d.* FROM dispositions d $w", $p);
        $letterIds = array_values(array_unique(array_column($rows, 'incomingLetterId')));
        $userIds   = array_values(array_unique(array_column($rows, 'toUserId')));
        $letters = []; $users = [];
        if ($letterIds) {
            $in = implode(',', array_fill(0, count($letterIds), '?'));
            foreach (Db::all("SELECT * FROM incoming_letters WHERE id IN ($in)", $letterIds) as $l) $letters[$l['id']] = $l;
        }
        if ($userIds) {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            foreach (Db::all("SELECT id, name FROM users WHERE id IN ($in)", $userIds) as $u) $users[$u['id']] = $u;
        }
        foreach ($rows as &$d) {
            $d['incomingLetter'] = $letters[$d['incomingLetterId']] ?? null;
            $d['toUser'] = ['name' => $users[$d['toUserId']]['name'] ?? null];
        }
        echo json_encode($rows);
        return;
    }

    http_response_code(404);
    echo json_encode(['message' => 'Not found']);
} catch (AuthException $e) {
    throw $e;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memproses laporan.']);
}

// Filter enum sifat/klasifikasi pada tabel surat itu sendiri (incoming/
// outgoing). Nama kolom di-whitelist dari konstan — input user hanya jadi
// parameter SQL, tidak pernah masuk teks query. $_GET['nature']/
// ['classification'] kosong atau 'all' = tidak difilter.
function reportEnumFilters(string &$w, array &$p): void
{
 foreach (['nature', 'classification'] as $f) {
 $v = $_GET[$f] ?? null;
 if ($v && $v !== 'all') {
 $w = ($w ? "$w AND " : 'WHERE ') . "$f = ?";
 $p[] = $v;
 }
 }
}

// Varian utk tabel dispositions: sifat/klasifikasi bukan kolom disposisi,
// jadi disaring via subselect ke surat asalnya — dibutuhkan agar laporan
// disposisi bisa dicetak per-sifat/per-klasifikasi juga.
function reportLetterEnumFilters(string &$w, array &$p): void
{
 foreach (['nature', 'classification'] as $f) {
 $v = $_GET[$f] ?? null;
 if ($v && $v !== 'all') {
 $w = ($w ? "$w AND " : 'WHERE ') . "d.incoming_letter_id IN (SELECT id FROM incoming_letters WHERE $f = ?)";
 $p[] = $v;
 }
 }
}

