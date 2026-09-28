<?php
// /api/wabot — webhook Fonnte (PUBLIK, tanpa JWT): autentikasi = nomor pengirim.
// Lapisan keamanan:
// 1) Pesan grup hanya diproses bila grupnya = group_target tersimpan; bila
// wa_group_marker diisi, teks pesan juga harus mengandung kata kunci itu.
// 2) Chat pribadi hanya diproses bila nomor pengirim = users.wa_number milik
// akun aktif (semua role — pegawai melaporkan status via sesi v2; perintah
// administratif tetap dibatasi PIMPINAN/ADMIN).
// 3) Hanya PENERIMA disposisi (to_user_id) yang boleh memprogres status —
// aturan identik dgn PATCH /dispositions/:id/status di web.
// Prioritas pemrosesan v2: (a) mulai sesi pimpinan "DISPOSISI <agenda>" bila
// pengirim PIMPINAN/ADMIN; (b) perintah v1 DISPOSISI ... (daftar/bantuan/update);
// (c) balasan sesi aktif (pegawai maupun pimpinan).
// Konfigurasi Fonnte: Device -> Edit -> Webhook URL = https://<domain>/api/wabot,
// Auto Read = ON (webhook tidak jalan tanpa itu).
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    return;
}

// Kunci rahasia webhook (opsional, tapi sangat disarankan). Bila diisi di
// config.php, URL webhook Fonnte harus memuat ?k=<nilai itu>. Tanpa ini,
// siapa pun yang tahu nomor WA terdaftar bisa mengirim payload palsu dengan
// field "sender" dipalsukan dan menyamar sebagai pegawai.
$wabotSecret = (string) ($config['wabot_secret'] ?? '');
if ($wabotSecret !== '' && !hash_equals($wabotSecret, (string) ($_GET['k'] ?? ''))) {
    error_log('[Wabot] Webhook ditolak: kunci rahasia tidak cocok.');
    http_response_code(403);
    echo json_encode(['message' => 'Forbidden']);
    return;
}

ignore_user_abort(true);

// Payload Fonnte berbentuk JSON; fallback form-encoded untuk antisipasi.
$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b) || !$b) $b = $_POST;

$sender = trim((string) ($b['sender'] ?? ''));
$member = trim((string) ($b['member'] ?? ''));
$message = trim((string) ($b['message'] ?? ''));
$inboxId = trim((string) ($b['inboxid'] ?? ''));

try {
    if ($sender === '' || $message === '') {
        echo json_encode(['status' => 'ignored']);
        return;
    }

    $settings = Whatsapp::settings(Db::$pdo);
    $ident = Wabot::identifySender($sender, $member);
    // Label untuk teks bantuan: "kirim DISPOSISI dari grup ini / nomor ini".
    $ident['label'] = $ident['isGroup'] ? 'grup ini' : 'nomor ini';

    // 1) Whitelist grup (+ kata kunci opsional).
    if ($ident['isGroup']) {
        $groupDigits = Wabot::digitsOf($settings['groupTarget'] ?? null);
        if ($groupDigits === null || Wabot::digitsOf($sender) !== $groupDigits) {
            error_log('[Wabot] Grup tidak cocok, abaikan: sender=' . maskPhone($sender));
            echo json_encode(['status' => 'ignored']);
            return;
        }
        $marker = trim((string) ($settings['waGroupMarker'] ?? ''));
        if ($marker !== '' && stripos($message, $marker) === false) {
            echo json_encode(['status' => 'ignored']);
            return;
        }
    }

    // 2) Cari user dari nomor pelaku (member utk grup, sender utk chat pribadi).
    $norm = Wabot::normalizeNumber($ident['actor']);
    $actor = null;
    if ($norm !== null) {
        foreach (Db::all("SELECT id, name, role, wa_number, is_active FROM users WHERE wa_number IS NOT NULL AND wa_number <> ''") as $u) {
            if (Wabot::normalizeNumber($u['waNumber']) === $norm) {
                $actor = $u;
                break;
            }
        }
    }
    if (!$actor || !(int) $actor['isActive']) {
        // Nomor tak dikenal / akun nonaktif -> abaikan senyap.
        error_log('[Wabot] Pesan dari nomor tak dikenal/nonaktif: ' . maskPhone($ident['actor']));
        echo json_encode(['status' => 'ignored']);
        return;
    }
    $actorUserId = $actor['id'];

    // 3) Idempotensi: inboxid yang sama hanya diproses sekali (dipindah ke depan
    // agar balasan sesi tidak dobel saat Fonnte mengulang webhook).
    // Fonnte mengirim inboxid 0 (tanpa inbox aktif) di SEMUA pesan asli -> '0'
    // bukan kunci idempotensi; hanya string unik non-nol yang dipakai.
    $logInboxId = ($inboxId !== '' && $inboxId !== '0' && strlen($inboxId) <= 36) ? $inboxId : null;
    if ($logInboxId !== null
        && Db::one("SELECT id FROM activity_logs WHERE action = 'WABOT_INBOX' AND entity_id = ?", [$logInboxId])) {
        echo json_encode(['status' => 'duplicate']);
        return;
    }
    logActivity($actor['id'], 'WABOT_INBOX', 'WHATSAPP', $logInboxId,
        substr('[' . $ident['actor'] . '] ' . $message, 0, 180));

    // 4) Target balasan: bot SELALU menjawab ke chat pribadi pengirim (menu
    // disposisi berisi data yang tidak pantas tampil di grup).
    $replyTarget = Wabot::digitsOf($ident['actor']) . '@s.whatsapp.net';
    if ($replyTarget === '@s.whatsapp.net') $replyTarget = $sender;

    // 5) Prioritas parser (Fix 2 — kata kunci + agenda, tanpa angka dipakai ulang):
    // (a) perintah kata kunci SOP/AS/04: TERIMA/TOLAK/KEBIJAKAN/LANGSUNG/ARAHAN/
    //     TERUSKAN/TUNJUK/PROSES/SELESAI/ARSIP <agenda> [isian] — semua role yang
    //     nomornya terdaftar; otorisasi per kata kunci diperiksa di handler.
    // (b) v1 ("DISPOSISI", "DISPOSISI 2 SELESAI") dan v2 mulai sesi ("DISPOSISI 12").
    // Teks seperti "DISPOSISI 12" bersayap: v1 membacanya INVALID, v2 sebagai
    // mulai sesi. Bagi PIMPINAN/ADMIN mulai sesi menang; role lain tetap v1.
    $keyword = Wabot::parseKeywordCommand($message);
    if ($keyword !== null) {
        require __DIR__ . '/wabot_keyword.php';
        return;
    }
    $cmd = Wabot::parseCommand($message);
    $start = Wabot::parseGroupStart($message);
    if ($start !== null && Wabot::isAuthorizedRole($actor['role'])) {
        require __DIR__ . '/wabot_v2.php';
        return;
    }
    if ($cmd !== null) {
        require __DIR__ . '/wabot_v1.php';
        return;
    }

    // 6) Balasan sesi (menu pegawai / menu pimpinan). Semua role yang memegang
    // sesi boleh membalas; aksi mulai sesi pimpinan sudah diverifikasi di atas
    // dan diperiksa ulang di dalam wabot_v2.php.
    require __DIR__ . '/wabot_v2.php';
    return;
} catch (Throwable $e) {
    error_log('[Wabot] ' . $e->getMessage());
    echo json_encode(['status' => 'error']);
}

// Kirim balasan bot via API Fonnte (pola resmi "webhook reply message":
// POST api.fonnte.com/send dgn target=asal pesan + inboxid utk threading).
function wabotSend(string $target, string $text, array $settings, string $inboxId): void
{
    // Balasan bot dikirim sebagai pesan baru TANPA inboxid: Fonnte menolak
    // pengiriman bila inboxid tidak valid (fitur inbox nonaktif di device,
    // id kedaluwarsa >3 hari, atau id dari chat lain) — sedangkan pesan grup
    // tanpa inboxid selalu terkirim. Threading dikorbankan demi keterkiriman.
    try {
        Whatsapp::send($settings['fonnteToken'] ?? '', $target, $text, null, null);
    } catch (Throwable $e) {
        error_log('[Wabot] Gagal balas WA: ' . $e->getMessage());
    }
}

// Kirim ke chat pribadi pengirim + jawab webhook (pola fire-and-forget v1).
function wabotReply(string $text, array $settings, string $inboxId, string $target): void
{
    echo json_encode(['status' => 'ok']);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    wabotSend($target, $text, $settings, $inboxId);
}

// Tutup sesi EMPLOYEE secara permanen (dipakai jalur v1 saat disposisi
// SELESAI dan jalur v2 saat pegawai membatalkan/menyelesaikan lewat menu).
// Baris dibiarkan dengan kind='CLOSED' untuk audit (lihat migrasi wa_sessions).
// $dispositionId diisi bila pemanggil hanya ingin menutup sesi yang menunjuk
// disposisi itu (jalur v1: tugas lain masih harus punya menu status).
function wabotCloseEmployeeSession(string $userId, ?string $dispositionId = null): void
{
    try {
        if ($dispositionId === null) {
            Db::q("UPDATE wa_sessions SET kind = 'CLOSED', step = 'DONE', expires_at = NOW(), updated_at = NOW()
                    WHERE user_id = ? AND kind = 'EMPLOYEE'", [$userId]);
            return;
        }
        Db::q("UPDATE wa_sessions SET kind = 'CLOSED', step = 'DONE', expires_at = NOW(), updated_at = NOW()
                WHERE user_id = ? AND kind = 'EMPLOYEE' AND context LIKE ?",
            [$userId, '%"dispositionId":"' . $dispositionId . '"%']);
    } catch (Throwable $e) {
        error_log('[Wabot] Gagal menutup sesi pegawai: ' . $e->getMessage());
    }
}
