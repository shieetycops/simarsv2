<?php
// Bagian v1 bot WA — di-require dari wabot.php saat Wabot::parseCommand() cocok.
// Mencakup: daftar disposisi, bantuan, dan update status perintah cepat
// DISPOSISI <n> PROSES/SELESAI [catatan]. Jawaban selalu ke chat pribadi.

// Kebijakan v1 (dipertahankan dari behavior lama, lihat Wabot::ALLOWED_ROLES):
// perintah TEKS "DISPOSISI ..." khusus PIMPINAN/ADMIN. Pegawai tetap melapor
// status lewat menu sesi v2 di chat pribadi (1 = PROSES, 2 [catatan] = SELESAI),
// dan semua pembaruan status tetap dibatasi kepemilikan disposisi (to_user_id).
if (!Wabot::isAuthorizedRole($actor['role'])) {
    // Fase 2 (keputusan #2 = full WA): pemegang surat di meja Kasubag/Sekretaris/
    // Panitera BUKAN pemakai perintah teks v1, tetapi bila ia sedang memegang
    // antrean tugas tahap, perintah "DISPOSISI"/"DISPOSISI BANTUAN" diarahkan ke
    // menunya sendiri — bukan ditolak mentah seperti dulu.
    $stageRow = Db::one("SELECT context FROM wa_sessions
                         WHERE user_id = ? AND kind IN ('VERIFIKASI', 'DEKISION', 'ARCHIVE') AND expires_at > NOW()",
        [$actor['id']]);
    if ($stageRow) {
        $sctx = json_decode((string) $stageRow['context'], true) ?: [];
        $queue = array_values(array_filter((array) ($sctx['queue'] ?? []), 'is_array'));
        if ($queue) {
            wabotReply(Wabot::buildStageTaskMenu($queue[0], (string) $actor['name'], Wabot::pendingTaskCount($queue)),
                $settings, $inboxId, $replyTarget);
            return;
        }
    }
    wabotReply(Wabot::buildDeniedText($actor['name'], (string) $actor['role'])
        . Wabot::buildEmployeeMenuHintText(), $settings, $inboxId, $replyTarget);
    return;
}

if ($cmd['action'] === 'HELP') {
    wabotReply(Wabot::buildHelpText($ident, $actor['name']), $settings, $inboxId, $replyTarget);
    return;
}
if ($cmd['action'] === 'INVALID') {
    wabotReply(Wabot::buildInvalidText(), $settings, $inboxId, $replyTarget);
    return;
}

// Disposisi aktif milik penerima (sumber tunggal utk LIST & UPDATE):
// created_at DESC = penomoran 1..N, konsisten dgn notifikasi & daftar.
$items = Db::all(
    "SELECT d.*, il.subject AS letter_subject, fu.name AS from_name
FROM dispositions d
LEFT JOIN incoming_letters il ON il.id = d.incoming_letter_id
LEFT JOIN users fu ON fu.id = d.from_user_id
WHERE d.to_user_id = ? AND d.status IN ('PENDING', 'PROSES')
ORDER BY d.created_at DESC, d.id DESC",
    [$actor['id']]
);

if ($cmd['action'] === 'LIST') {
    wabotReply(Wabot::buildListText($items, $actor['name']), $settings, $inboxId, $replyTarget);
    return;
}

// UPDATE status.
$ordinal = $cmd['ordinal'];
if ($ordinal < 1 || $ordinal > count($items)) {
    wabotReply(Wabot::buildNotFoundText($ordinal, count($items)), $settings, $inboxId, $replyTarget);
    return;
}
$disp = $items[$ordinal - 1];
$status = $cmd['status'];

if ($disp['status'] === $status) {
    wabotReply(Wabot::buildAlreadyText($disp, $status), $settings, $inboxId, $replyTarget);
    return;
}

// Catatan WA di-APPEND ke catatan lama (beda dgn web yang replace):
// update via WA lazim bertahap & tanpa konteks form.
$notes = $cmd['notes'] !== null
    ? trim(($disp['notes'] ? $disp['notes'] . "\n" : '') . $cmd['notes'])
    : $disp['notes'];

Db::q("UPDATE dispositions SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?",
    [$status, $notes, $disp['id']]);

// Notifikasi in-app ke pemberi disposisi (momen sama dgn PATCH web).
$title = Disposition::statusNotificationTitle($status);
if ($title && !empty($disp['fromUserId'])) {
    $subj = substr((string) ($disp['letterSubject'] ?? '-'), 0, 60);
    $suffix = $cmd['notes'] ? ' — "' . substr($cmd['notes'], 0, 50) . '"' : '';
    $verb = $status === 'SELESAI' ? 'telah menyelesaikan tugas' : 'sedang menindaklanjuti';
    Db::q("INSERT INTO notifications (id, user_id, title, message, link) VALUES (?, ?, ?, ?, '/disposisi')",
        [Db::generateId(), $disp['fromUserId'], $title, "{$actor['name']} $verb: $subj$suffix"]);
}

logActivity($actor['id'], 'WABOT_DISPOSITION_UPDATE', 'DISPOSITION', $disp['id'],
    'Update via WhatsApp: ' . $disp['status'] . ' -> ' . $status
    . ($cmd['notes'] ? '; catatan: ' . substr($cmd['notes'], 0, 80) : ''));

// Notifikasi WA grup ke pemberi disposisi (aturan sama dgn PATCH web).
if (Disposition::notifiesWhatsapp($status)) {
    Whatsapp::notifyDispositionStatus(Db::$pdo, [
        'fromName' => $disp['fromName'] ?? null,
        'workerName' => $actor['name'],
        'subject' => $disp['letterSubject'] ?? '-',
        'status' => $status,
        'notes' => $notes,
        'actorUserId' => $actor['id'],
    ]);
}

// Fase 1: perintah teks "DISPOSISI <n> PROSES/SELESAI" juga menggerakkan TAHAP
// SURAT di Buku Kendali (dulu hanya status disposisi yang berubah, sehingga
// surat tampak macet di DITERUSKAN_KE_PELAKSANA). Kegagalan perpindahan tahap
// tidak boleh menggagalkan laporan yang sudah tersimpan.
try {
    DispositionBridge::applyStatus((string) $disp['id'], $status, $actor);
} catch (Throwable $e) {
    error_log('[Wabot] gagal memajukan tahap surat: ' . $e->getMessage());
}

// Tugas selesai lewat perintah lama: sesi status pegawai utk disposisi ini
// ditutup (tugas lainnya tetap punya menu), lalu balas konfirmasi.
if ($status === 'SELESAI') wabotCloseEmployeeSession($actor['id'], $disp['id']);

// Balas konfirmasi ke chat pribadi pengirim.
$disp['status'] = $status;
wabotReply(Wabot::buildConfirmation($disp, $status, $cmd['notes']), $settings, $inboxId, $replyTarget);
