<?php
// wabot_v2.php — jalur sesi WhatsApp v2, di-require dari wabot.php (dispatcher).
//
// Variabel yang disediakan dispatcher: $settings, $ident, $message, $inboxId,
// $actor, $actorUserId, $replyTarget, $cmd, $start.
//
// Prioritas pemrosesan di berkas ini:
// 1) Mulai sesi pimpinan: "DISPOSISI <nomor agenda>" / "DISPOSISI <kata kunci>"
// — hanya PIMPINAN/ADMIN (aksi administratif).
// 2) Balasan sesi LEADER aktif: pilih pegawai tujuan dari menu bernomor.
// 3) Balasan sesi EMPLOYEE aktif: lapor status (1 = PROSES, 2 [catatan] = SELESAI).
// 4) Sesi kedaluwarsa: satu kali peringatan bila pesannya berbentuk balasan
// sesi; selain itu ditutup senyap.
//
// Semua balasan dikirim ke CHAT PRIBADI pengirim ($replyTarget). Grup hanya
// menerima pengumuman resmi saat disposisi berhasil dibuat.
//
// Catatan desain: wa_sessions ber-PK user_id => satu sesi per user. Saat
// pimpinan memulai sesi "buat disposisi", sesi status pegawainya ditimpa;
// sesi pegawai itu dipulihkan otomatis ketika sesi pimpinan berakhir
// (disposisi dibuat / dibatalkan) selama masih ada tugas atas namanya.

// ---------- 1) Mulai sesi pimpinan ----------
if ($start !== null && Wabot::isAuthorizedRole($actor['role'])) {
    wabotV2StartSession($start, $actor, $actorUserId, $settings, $inboxId, $replyTarget);
    return;
}

// ---------- 2) & 3) Balasan sesi aktif ----------
$session = Db::one("SELECT kind, step, context, expires_at FROM wa_sessions
                    WHERE user_id = ? AND kind <> 'CLOSED' AND expires_at > NOW()", [$actorUserId]);
$sctx = $session ? (json_decode((string) $session['context'], true) ?: []) : [];

if ($session && $session['kind'] === 'EMPLOYEE') {
    wabotV2EmployeeReply($sctx, $actor, $actorUserId, $message, $settings, $inboxId, $replyTarget);
    return;
}

if ($session && $session['kind'] === 'LEADER') {
    if (Wabot::isAuthorizedRole($actor['role'])) {
        wabotV2LeaderReply($sctx, $actor, $actorUserId, $message, $settings, $inboxId, $replyTarget,
            (bool) $ident['isGroup']);
        return;
    }
    // Role berubah/diturunkan di tengah sesi -> sesi ditutup tanpa balasan.
    wabotV2EndSession($actorUserId);
    echo json_encode(['status' => 'ignored']);
    return;
}

// ---------- 4) Sesi kedaluwarsa ----------
$stale = Db::one("SELECT kind, context FROM wa_sessions
                  WHERE user_id = ? AND kind <> 'CLOSED' AND expires_at <= NOW()", [$actorUserId]);
if ($stale) {
    $replyType = Wabot::parseSessionReply($message)['type'];
    $looksLikeReply = in_array($replyType, ['CHOICE', 'REMENU', 'CANCEL'], true);
    if ($looksLikeReply && $stale['kind'] === 'EMPLOYEE') {
        // Sesi pegawai cuma lewat batas 7 hari, tapi tugasnya masih PENDING/PROSES:
        // perpanjang sesi lalu layani balasannya seperti biasa.
        if (wabotV2RestoreEmployeeSession($actorUserId, $stale['context'])) {
            $fresh = Db::one("SELECT context FROM wa_sessions
                              WHERE user_id = ? AND kind = 'EMPLOYEE' AND expires_at > NOW()", [$actorUserId]);
            wabotV2EmployeeReply($fresh ? (json_decode((string) $fresh['context'], true) ?: []) : [],
                $actor, $actorUserId, $message, $settings, $inboxId, $replyTarget);
            return;
        }
    }
    wabotV2EndSession($actorUserId);
    if ($looksLikeReply) {
        // Pegawai tanpa tugas aktif lagi -> jelaskan kosong (saran "DISPOSISI
        // <n> SELESAI" pada teks kedaluwarsa khusus pimpinan/admin).
        wabotReply($stale['kind'] === 'EMPLOYEE'
            ? Wabot::buildNotFoundText(1, 0)
            : Wabot::buildExpiredText(), $settings, $inboxId, $replyTarget);
        return;
    }
}

echo json_encode(['status' => 'ignored']);
return;

// ---------- Pembantu sesi EMPLOYEE ----------

// Balasan menu status pegawai: 1 = PROSES, 2 [catatan] = SELESAI.
function wabotV2EmployeeReply(array $ctx, array $actor, string $actorUserId, string $message,
    array $settings, string $inboxId, string $replyTarget): void
{
    $reply = Wabot::parseSessionReply($message);

    if ($reply['type'] === 'IGNORE') {
        echo json_encode(['status' => 'ignored']);
        return;
    }
    if ($reply['type'] === 'CANCEL') {
        wabotV2CloseSession($actorUserId);
        wabotReply(Wabot::buildSessionClosedText(true, false), $settings, $inboxId, $replyTarget);
        return;
    }

    // Disposisi yang dipegang sesi dibaca ulang dari DB (bukan snapshot) supaya
    // status terbaru selalu dipakai; sesi menunjuk tepat satu tugas.
    $disp = null;
    if (!empty($ctx['dispositionId'])) {
        $disp = Db::one("SELECT d.*, il.subject AS letter_subject, il.agenda_number, fu.name AS from_name
                         FROM dispositions d
                         LEFT JOIN incoming_letters il ON il.id = d.incoming_letter_id
                         LEFT JOIN users fu ON fu.id = d.from_user_id
                         WHERE d.id = ? AND d.to_user_id = ?",
            [$ctx['dispositionId'], $actorUserId]);
    }
    if (!$disp) {
        // Tugas di sesi hilang (dihapus/dipindahkan) -> sesi ditutup; DM
        // disposisi berikutnya akan membuat sesi baru.
        wabotV2CloseSession($actorUserId);
        wabotReply(Wabot::buildNotFoundText(1, 0), $settings, $inboxId, $replyTarget);
        return;
    }

    $subject = (string) ($disp['letterSubject'] ?? ($ctx['subject'] ?? '-'));

    if ($disp['status'] === 'SELESAI') {
        wabotV2CloseSession($actorUserId);
        wabotReply(Wabot::buildAlreadyText($disp, 'SELESAI'), $settings, $inboxId, $replyTarget);
        return;
    }

    if ($reply['type'] === 'REMENU') {
        wabotV2TouchEmployee($actorUserId);
        wabotReply(Wabot::buildEmployeeTaskMenu([
            'subject' => $subject,
            'instruction' => $disp['instruction'] ?? '',
            'deadline' => $disp['deadline'] ?? null,
            'attachmentUrl' => !empty($disp['incomingLetterId']) ? letterViewUrl((string)$disp['incomingLetterId']) : '',
        ], true), $settings, $inboxId, $replyTarget);
        return;
    }

    if ($reply['type'] !== 'CHOICE') {
        wabotV2TouchEmployee($actorUserId);
        wabotReply(Wabot::buildUnknownReplyText(), $settings, $inboxId, $replyTarget);
        return;
    }

    // 1 = PROSES, 2 [catatan] = SELESAI — hanya dua angka ini yang ada di menu.
    if ($reply['choice'] === 1) {
        if ($disp['status'] === 'PROSES') {
            wabotV2TouchEmployee($actorUserId);
            wabotReply(Wabot::buildAlreadyText($disp, 'PROSES'), $settings, $inboxId, $replyTarget);
            return;
        }
        wabotV2ApplyStatus($disp, 'PROSES', $reply['notes'], $actor);
        wabotV2TouchEmployee($actorUserId);
        wabotReply(Wabot::buildStatusProgressText('PROSES', $reply['notes'], $subject),
            $settings, $inboxId, $replyTarget);
        return;
    }

    if ($reply['choice'] === 2) {
        wabotV2ApplyStatus($disp, 'SELESAI', $reply['notes'], $actor);
        // SELESAI -> sesi ditutup permanen; disposisi berikutnya mengirim DM
        // menu baru (dan sesi ini tidak boleh menelan angka berikutnya).
        wabotV2CloseSession($actorUserId);
        wabotReply(Wabot::buildStatusProgressText('SELESAI', $reply['notes'], $subject),
            $settings, $inboxId, $replyTarget);
        return;
    }

    wabotV2TouchEmployee($actorUserId);
    wabotReply(Wabot::buildInvalidChoiceText((int) $reply['choice'], 2), $settings, $inboxId, $replyTarget);
}

// Perubahan status dari sesi pegawai — aturan identik dengan jalur v1 & PATCH web:
// catatan WA di-append, notifikasi in-app ke pemberi, pengumuman grup via WA.
function wabotV2ApplyStatus(array $disp, string $status, ?string $notes, array $actor): void
{
    $notesFull = ($notes !== null && $notes !== '')
        ? trim(($disp['notes'] ? $disp['notes'] . "\n" : '') . $notes)
        : ($disp['notes'] ?? null);

    Db::q("UPDATE dispositions SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?",
        [$status, $notesFull, $disp['id']]);

    $title = Disposition::statusNotificationTitle($status);
    if ($title && !empty($disp['fromUserId'])) {
        $subj = substr((string) ($disp['letterSubject'] ?? '-'), 0, 60);
        $suffix = $notes ? ' — "' . substr($notes, 0, 50) . '"' : '';
        $verb = $status === 'SELESAI' ? 'telah menyelesaikan tugas' : 'sedang menindaklanjuti';
        Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, '/disposisi')",
            [Db::generateId(), $disp['fromUserId'], $title, "{$actor['name']} $verb: $subj$suffix"]);
    }

    logActivity($actor['id'], 'WABOT_DISPOSITION_UPDATE', 'DISPOSITION', $disp['id'],
        'Update via WhatsApp (sesi pegawai): ' . $disp['status'] . ' -> ' . $status
        . ($notes ? '; catatan: ' . substr($notes, 0, 80) : ''));

    if (Disposition::notifiesWhatsapp($status)) {
        Whatsapp::notifyDispositionStatus(Db::$pdo, [
            'fromName' => $disp['fromName'] ?? null,
            'workerName' => $actor['name'],
            'subject' => $disp['letterSubject'] ?? '-',
            'status' => $status,
            'notes' => $notesFull,
            'actorUserId' => $actor['id'],
        ]);
    }
}

// Sesi pegawai 7 hari: setiap balasan valid menggeser batas waktunya.
function wabotV2TouchEmployee(string $userId): void
{
    Db::q("UPDATE wa_sessions SET expires_at = ? WHERE user_id = ? AND kind = 'EMPLOYEE'",
        [Wabot::newExpiry(false), $userId]);
}

// Tutup baris sesi (kind=CLOSED dipertahankan utk audit — lihat migrasi).
function wabotV2CloseSession(string $userId): void
{
    Db::q("UPDATE wa_sessions SET kind = 'CLOSED', step = 'DONE', expires_at = NOW(), updated_at = NOW()
            WHERE user_id = ? AND kind <> 'CLOSED'", [$userId]);
}

// Akhiri sesi pimpinan: tutup + kembalikan sesi status pegawai bila masih ada
// tugas PENDING/PROSES atas namanya (ia kehilangan sesi itu saat mulai sesi).
function wabotV2EndSession(string $userId): void
{
    wabotV2CloseSession($userId);
    wabotV2RestoreEmployeeSession($userId);
}

// Pulihkan/siapkan sesi EMPLOYEE dari disposisi aktif terbaru user.
// $staleContext dipakai saat sesi lama baru saja kedaluwarsa: selama disposisi
// yang sama masih PENDING/PROSES, balasan angka user masih untuk tugas itu.
// Return true bila baris sesi (baru/diperbarui) berhasil ditulis.
function wabotV2RestoreEmployeeSession(string $userId, ?string $staleContext = null): bool
{
    $oldId = null;
    if ($staleContext) {
        $old = json_decode((string) $staleContext, true) ?: [];
        $oldId = $old['dispositionId'] ?? null;
    }

    $disp = null;
    if ($oldId) {
        $disp = Db::one("SELECT d.id, d.instruction, d.deadline, il.subject AS letter_subject
                         FROM dispositions d
                         LEFT JOIN incoming_letters il ON il.id = d.incoming_letter_id
                         WHERE d.id = ? AND d.to_user_id = ? AND d.status IN ('PENDING', 'PROSES')",
            [$oldId, $userId]);
    }
    if (!$disp) {
        $disp = Db::one("SELECT d.id, d.instruction, d.deadline, il.subject AS letter_subject
                         FROM dispositions d
                         LEFT JOIN incoming_letters il ON il.id = d.incoming_letter_id
                         WHERE d.to_user_id = ? AND d.status IN ('PENDING', 'PROSES')
                         ORDER BY d.created_at DESC, d.id DESC LIMIT 1", [$userId]);
    }
    if (!$disp) return false;

    $ctx = json_encode([
        'dispositionId' => $disp['id'],
        'subject' => $disp['letterSubject'] ?? '-',
        'instruction' => $disp['instruction'] ?? '',
        'deadline' => $disp['deadline'],
        'withMenu' => 1,
    ], JSON_UNESCAPED_UNICODE);
    Db::q("INSERT INTO wa_sessions (user_id, kind, step, context, expires_at)
            VALUES (?, 'EMPLOYEE', 'REPORT', ?, ?)
            ON DUPLICATE KEY UPDATE kind = 'EMPLOYEE', step = 'REPORT',
              context = VALUES(context), expires_at = VALUES(expires_at)",
        [$userId, $ctx, Wabot::newExpiry(false)]);
    return true;
}

// ---------- Pembantu sesi LEADER ----------

// Balasan menu "pilih pegawai tujuan" (nomor [instruksi]) dari sesi pimpinan.
function wabotV2LeaderReply(array $ctx, array $actor, string $actorUserId, string $message,
    array $settings, string $inboxId, string $replyTarget, bool $isGroup): void
{
    $reply = Wabot::parseSessionReply($message);
    // Menu dibangun dari snapshot di context => penomoran tidak berubah walau
    // data pengguna diedit admin di tengah sesi.
    $users = array_values(array_filter((array) ($ctx['users'] ?? []),
        fn($u) => is_array($u) && !empty($u['id'])));
    $total = count($users);

    if ($reply['type'] === 'IGNORE') {
        echo json_encode(['status' => 'ignored']);
        return;
    }
    if ($reply['type'] === 'CANCEL') {
        wabotV2EndSession($actorUserId);
        wabotReply(Wabot::buildSessionClosedText(true, $isGroup), $settings, $inboxId, $replyTarget);
        return;
    }
    if ($total === 0) {
        // Snapshot pegawai hilang -> sesi tidak bisa dipakai lagi.
        wabotV2EndSession($actorUserId);
        wabotReply(Wabot::buildNoTargetText(), $settings, $inboxId, $replyTarget);
        return;
    }
    if ($reply['type'] === 'REMENU') {
        wabotReply(Wabot::buildTargetMenu((string) ($ctx['agenda'] ?? '-'),
            (string) ($ctx['subject'] ?? '-'), $users), $settings, $inboxId, $replyTarget);
        return;
    }
    if ($reply['type'] !== 'CHOICE') {
        wabotReply(Wabot::buildUnknownReplyText(), $settings, $inboxId, $replyTarget);
        return;
    }
    if ($reply['choice'] < 1 || $reply['choice'] > $total) {
        wabotReply(Wabot::buildInvalidChoiceText((int) $reply['choice'], $total),
            $settings, $inboxId, $replyTarget);
        return;
    }

    $picked = $users[$reply['choice'] - 1];
    $to = Db::one("SELECT id, name, role, wa_number, supervisor_id, is_active FROM users WHERE id = ?",
        [$picked['id']]);
    if (!$to || !(int) $to['isActive']) {
        wabotReply(Wabot::buildTargetUnavailableText((string) ($picked['name'] ?? '-')),
            $settings, $inboxId, $replyTarget);
        return;
    }
    if (!Disposition::canDispose($actor['role'], $to['supervisorId'] ?? null, $actor['id'])) {
        wabotReply(Wabot::buildDeniedText($actor['name'], (string) $actor['role']),
            $settings, $inboxId, $replyTarget);
        return;
    }

    $letter = Db::one("SELECT id, agenda_number, subject FROM incoming_letters WHERE id = ?",
        [(string) ($ctx['letterId'] ?? '')]);
    if (!$letter) {
        wabotV2EndSession($actorUserId);
        wabotReply(Wabot::buildAgendaNotFoundText((string) ($ctx['agenda'] ?? '-')),
            $settings, $inboxId, $replyTarget);
        return;
    }

    // Instruksi opsional: "2 Harap hadir rapat". Default dipakai agar baris
    // dispositions.instruction tetap terisi seperti pada form web.
    $instruction = trim((string) ($reply['notes'] ?? ''));
    if ($instruction === '') $instruction = 'Segera ditindaklanjuti.';

    $newId = Db::generateId();
    Db::q("INSERT INTO dispositions (id, incoming_letter_id, from_user_id, to_user_id, instruction, status, deadline)
            VALUES (?, ?, ?, ?, ?, 'PENDING', NULL)",
        [$newId, $letter['id'], $actor['id'], $to['id'], $instruction]);

    // Notifikasi in-app (momen sama dengan POST /dispositions pada web).
    Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, '/disposisi')",
        [Db::generateId(), $to['id'], 'Disposisi Baru',
            'Anda menerima instruksi disposisi baru: ' . substr($instruction, 0, 50) . '...']);
    Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, '/disposisi')",
        [Db::generateId(), $actor['id'], 'Disposisi Terkirim',
            "Disposisi berhasil diteruskan kepada {$to['name']}: " . substr($instruction, 0, 40) . '...']);

    logActivity($actor['id'], 'WABOT_DISPOSITION_CREATE', 'DISPOSITION', $newId,
        'Disposisi via WhatsApp: agenda ' . $letter['agendaNumber'] . ' ke ' . $to['name']);

    // Pengumuman resmi ke grup + DM menu status ke pegawai penerima.
    Whatsapp::notifyGroup(Db::$pdo,
        Wabot::buildGroupNoticeText($actor['name'], $to['name'], (string) $letter['subject']),
        $actor['id']);

    if (!empty($to['waNumber'])) {
        $targetLeaderActive = Db::one("SELECT kind FROM wa_sessions
                                        WHERE user_id = ? AND kind = 'LEADER' AND expires_at > NOW()",
            [$to['id']]);
        Whatsapp::notifyDispositionAssignedDm(Db::$pdo, [
            'toUserId' => $to['id'],
            'waTarget' => Wabot::normalizeNumber($to['waNumber']),
            'dispositionId' => $newId,
            'subject' => $letter['subject'],
            'instruction' => $instruction,
            'deadline' => null,
            'attachmentUrl' => letterViewUrl($letter['id']),
            'withMenu' => !$targetLeaderActive,
            'actorUserId' => $actor['id'],
        ]);
    }

    wabotV2EndSession($actorUserId);
    wabotReply(Wabot::buildDispositionCreatedText((string) $letter['agendaNumber'],
        (string) $letter['subject'], $to['name'], $instruction), $settings, $inboxId, $replyTarget);
}

// Mulai/ganti sesi pimpinan: "DISPOSISI <nomor agenda>" | "DISPOSISI <kata kunci>".
function wabotV2StartSession(array $start, array $actor, string $actorUserId, array $settings,
    string $inboxId, string $replyTarget): void
{
    if (($start['action'] ?? '') === 'INVALID_START') {
        wabotReply(Wabot::buildInvalidStartText(), $settings, $inboxId, $replyTarget);
        return;
    }

    $limit = Wabot::MENU_TARGET_LIMIT;
    if (($start['action'] ?? '') === 'KEYWORD') {
        $kw = trim((string) ($start['keyword'] ?? ''));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw) . '%';
        $rows = Db::all("SELECT id, agenda_number, subject FROM incoming_letters
                            WHERE subject LIKE ? OR agenda_number LIKE ?
                            ORDER BY received_date DESC LIMIT 5", [$like, $like]);
        if (!$rows) {
            wabotReply(Wabot::buildAgendaNotFoundText($kw), $settings, $inboxId, $replyTarget);
            return;
        }
        if (count($rows) > 1) {
            wabotReply(Wabot::buildAmbiguousText($kw, count($rows)), $settings, $inboxId, $replyTarget);
            return;
        }
        $letter = $rows[0];
    } else {
        $agendaNo = (int) ($start['agenda'] ?? 0);
        $letter = wabotV2FindLetter($agendaNo);
        if (!$letter) {
            wabotReply(Wabot::buildAgendaNotFoundText((string) $agendaNo), $settings, $inboxId, $replyTarget);
            return;
        }
    }

    // Kandidat = pegawai aktif bernomor WA (sama dgn /users/subordinates untuk
    // pimpinan), diurutkan nama supaya penomoran menu stabil.
    $users = Db::all("SELECT id, name, role FROM users
                        WHERE is_active = 1 AND id <> ?
                          AND wa_number IS NOT NULL AND wa_number <> ''
                        ORDER BY name ASC LIMIT " . (int) ($limit + 1), [$actorUserId]);
    if (!$users) {
        wabotReply(Wabot::buildNoTargetText(), $settings, $inboxId, $replyTarget);
        return;
    }
    $overflow = count($users) > $limit;
    if ($overflow) $users = array_slice($users, 0, $limit);

    $context = json_encode([
        'letterId' => $letter['id'],
        'agenda' => $letter['agendaNumber'],
        'subject' => $letter['subject'],
        'users' => $users,
    ], JSON_UNESCAPED_UNICODE);

    // Satu sesi per user (PK user_id): sesi lama ditimpa. Masa berlaku 60 menit
    // terhitung sejak perintah mulai — tidak bergeser saat MENU diulang.
    Db::q("INSERT INTO wa_sessions (user_id, kind, step, context, expires_at)
            VALUES (?, 'LEADER', 'PICK_TARGET', ?, ?)
            ON DUPLICATE KEY UPDATE kind = 'LEADER', step = 'PICK_TARGET',
              context = VALUES(context), expires_at = VALUES(expires_at)",
        [$actorUserId, $context, Wabot::newExpiry(true)]);

    logActivity($actorUserId, 'WABOT_SESSION_START', 'DISPOSITION', $letter['id'],
        'Mulai sesi disposisi via WhatsApp untuk agenda ' . $letter['agendaNumber']);

    $menu = Wabot::buildTargetMenu((string) $letter['agendaNumber'], (string) $letter['subject'], $users);
    if ($overflow) {
        $menu .= "\n\n_Daftar dibatasi {$limit} pegawai teratas; pilih lewat aplikasi web bila tujuan tidak ada._";
    }
    wabotReply($menu, $settings, $inboxId, $replyTarget);
}

// "DISPOSISI 12" -> surat beragenda AGD/<tahun>/012 (format nomor agenda web).
// Dicoba dengan padding 0/00/000; surat terbaru menang bila tahun berbeda.
function wabotV2FindLetter(int $agendaNo): ?array
{
    if ($agendaNo < 1) return null;
    $like = [];
    $params = [];
    foreach ([(string) $agendaNo, '0' . $agendaNo, '00' . $agendaNo, '000' . $agendaNo] as $cand) {
        $like[] = 'agenda_number LIKE ?';
        $params[] = '%/' . $cand;
    }
    $params[] = (string) $agendaNo;
    $row = Db::one("SELECT id, agenda_number, subject FROM incoming_letters
                        WHERE " . implode(' OR ', $like) . " OR agenda_number = ?
                        ORDER BY received_date DESC LIMIT 1", $params);
    return $row ?: null;
}



