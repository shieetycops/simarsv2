<?php
// Notifikasi WhatsApp via Fonnte (ganti Baileys). Fire-and-forget: kegagalan
// kirim tidak boleh menggagalkan response API (semua dibungkus try/catch + error_log).
class Whatsapp
{
    // Seam untuk test: callable(token, target, message). null = kirim sungguhan via cURL.
    public static $sender = null;

    private const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    // 08xx -> 62xx, +62/62 tetap, 8xx -> 62xx. Port dari whatsapp.ts.
    public static function normalizePhone(?string $input): ?string
    {
        if (!$input) return null;
        $d = preg_replace('/[^\d]/', '', $input);
        if ($d === '') return null;
        if (str_starts_with($d, '62')) return $d;
        if (str_starts_with($d, '0'))  return '62' . substr($d, 1);
        if (str_starts_with($d, '8'))  return '62' . $d;
        return $d;
    }

    public static function formatTanggal($d): string
    {
        if (!$d) return '—';
        $ts = strtotime($d);
        if ($ts === false) return '—';
        return date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    // Pengaturan WA (buat default bila belum ada).
    public static function settings(PDO $db): array
    {
        $s = Db::one("SELECT * FROM whatsapp_settings WHERE id = 'wa_settings'");
        if (!$s) {
            Db::q("INSERT INTO whatsapp_settings (id) VALUES ('wa_settings')");
            $s = Db::one("SELECT * FROM whatsapp_settings WHERE id = 'wa_settings'");
        }
        return $s;
    }

    // POST https://api.fonnte.com/send — header Authorization: <token> (bukan Bearer).
    // $actorUserId dicatat ke activity_logs agar setiap pengiriman WhatsApp ter-audit.
    public static function send(string $token, string $target, string $message, ?string $actorUserId = null, ?string $inboxId = null): void
    {
        try {
            if (trim($token) === '') throw new RuntimeException('Fonnte token kosong (isi di Pengaturan WhatsApp).');
            if (trim($target) === '') throw new RuntimeException('Grup target kosong.');
            // Fonnte hanya terima digit nomor / ID @g.us — kupas sufiks Baileys
            // @s.whatsapp.net untuk japri (ditolak: "invalid/empty body value").
            if (str_ends_with($target, '@s.whatsapp.net')) {
                $target = preg_replace('/[^0-9]/', '', $target);
            }
            if (!function_exists('curl_init') && !self::$sender) throw new RuntimeException('Ekstensi PHP curl belum aktif di server.');
            if (self::$sender) {
                (self::$sender)($token, $target, $message, $actorUserId, $inboxId);
            } else {
                $ch = curl_init('https://api.fonnte.com/send');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ["Authorization: $token"],
                    CURLOPT_POSTFIELDS => [
                        'target' => $target,
                        'message' => $message,
                        // inboxid: thread balasan ke pesan masuk webhook bila ada.
                        'inboxid' => $inboxId,
                    ],
                    CURLOPT_TIMEOUT => 15,
                ]);
                $raw = curl_exec($ch);
                $cerr = curl_error($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($raw === false) throw new RuntimeException('cURL Fonnte gagal: ' . $cerr);
                $js = json_decode((string) $raw, true);
                $ok = is_array($js) ? ($js['status'] ?? null) : null;
                if ($http >= 400 || $ok === false || $ok === 0) {
                    throw new RuntimeException('Fonnte menolak (HTTP ' . $http . '): ' . substr((string) $raw, 0, 300));
                }
                error_log('[WhatsApp] Terkirim ke ' . $target . ' (HTTP ' . $http . '): ' . substr((string) $raw, 0, 200));
            }

            if (function_exists('logActivity') && $actorUserId) {
                logActivity($actorUserId, 'WHATSAPP_SENT', 'NOTIFICATION', null,
                    'Kirim WhatsApp ke ' . $target . ': ' . substr($message, 0, 120));
            }
        } catch (Throwable $e) {
            error_log('[WhatsApp] Gagal kirim ke ' . $target . ': ' . $e->getMessage());
            if (function_exists('logActivity') && $actorUserId) {
                logActivity($actorUserId, 'WHATSAPP_FAILED', 'NOTIFICATION', null,
                    'Gagal kirim WhatsApp ke ' . $target . ': ' . $e->getMessage());
            }
            throw $e;
        }
    }

    // Fonnte tak punya mention seperti Baileys — teks @<nomor> terkirim sebagai teks
    // biasa, mungkin tak memicu highlight tag. Ini keterbatasan Fonnte, bukan bug.
    public static function notifyNewIncomingLetter(PDO $db, array $a): void
    {
        try {
            $s = self::settings($db);
            if (!(int) $s['isEnabled'] || !$s['groupTarget']) return;
            $msg = "📬 *SURAT MASUK BARU*\n"
                . "Nomor Agenda: " . ($a['agendaNumber'] ?? '-') . "\n"
                . "Nomor Surat: " . ($a['letterNumber'] ?? '-') . "\n"
                . "Pengirim: " . ($a['sender'] ?? '-') . "\n"
                . "Perihal: " . ($a['subject'] ?? '-') . "\n"
                . "Tanggal Terima: " . self::formatTanggal($a['receivedDate'] ?? null) . "\n\n"
                . "Surat masuk ini memerlukan disposisi dari Pimpinan.\n"
                . (!empty($a['letterId']) ? "Lampiran: " . letterViewUrl((string) $a['letterId']) . "\n" : "")
                . "\n"
. "🤖 *Buat disposisi via WA:* daftar pegawai sudah dikirim ke chat pribadi tiap pimpinan — cukup balas *<nomor> <instruksi>* di sana (mis. *2 Harap ditindaklanjuti*), tanpa perintah DISPOSISI. Perintah lama tetap berlaku: *DISPOSISI <nomor agenda>* (contoh: *DISPOSISI 12*).";
            self::send($s['fonnteToken'] ?? '', $s['groupTarget'], $msg, $a['actorUserId'] ?? null);
        } catch (Throwable $e) {
            error_log("[WhatsApp] Gagal kirim notifikasi surat masuk baru: " . $e->getMessage());
        }
    }

    public static function notifyNewDisposition(PDO $db, array $a): void
    {
        try {
            if (empty($a['toName'])) {
                error_log("[WhatsApp] Skip notifikasi disposisi baru: nama penerima tidak tersedia");
                return;
            }
            $s = self::settings($db);
            if (!(int) $s['isEnabled'] || !$s['groupTarget']) return;
            $msg = "📋 *DISPOSISI BARU*\n"
                . "Dari: {$a['fromName']}\n"
                . "Kepada: {$a['toName']}\n"
                . "Perihal: {$a['subject']}\n"
                . "Instruksi: " . ($a['instruction'] ?: '-') . "\n"
                . "Deadline: " . self::formatTanggal($a['deadline'] ?? null) . "\n"
                . (!empty($a['attachmentUrl']) ? "Lampiran: " . $a['attachmentUrl'] . "\n" : "")
                . "\n"
            . "💬 Tindaklanjuti via WhatsApp (dari nomor terdaftar):\n"
            . "*DISPOSISI 1 SELESAI* — tandai selesai (nomor 1 = disposisi terbaru Anda)\n"
            . "*DISPOSISI* — lihat daftar disposisi Anda";
            self::send($s['fonnteToken'] ?? '', $s['groupTarget'], $msg, $a['actorUserId'] ?? null);
        } catch (Throwable $e) {
            error_log("[WhatsApp] Gagal kirim notifikasi disposisi baru: " . $e->getMessage());
        }
    }

    public static function notifyDispositionStatus(PDO $db, array $a): void
    {
        try {
            if (empty($a['fromName'])) {
                error_log("[WhatsApp] Skip notifikasi status: nama pengirim disposisi tidak tersedia");
                return;
            }
            $s = self::settings($db);
            if (!(int) $s['isEnabled']) return;
            $label = $a['status'] === 'SELESAI' ? 'SELESAI' : 'PROSES';
            $msg = "✅ *DISPOSISI $label*\n"
                . "Dari: {$a['fromName']}\n"
                . "Dikerjakan oleh: {$a['workerName']}\n"
                . "Perihal: {$a['subject']}\n"
                . "Status: $label"
                . (!empty($a['notes']) ? "\nCatatan: {$a['notes']}" : "");
            // DM ke tiap PIMPINAN aktif lebih dulu (inti fitur: pimpinan harus
            // tahu suratnya sudah diproses / diselesaikan atau belum). Tiap
            // kirim ditahan di dalam helper - gagal satu target tidak
            // memblokir grup.
            self::notifyLeadersStatus($s, $msg, $a['actorUserId'] ?? null);
            if (!empty($s['groupTarget'])) {
                self::send($s['fonnteToken'] ?? '', $s['groupTarget'], $msg, $a['actorUserId'] ?? null);
            }
        } catch (Throwable $e) {
            error_log("[WhatsApp] Gagal kirim notifikasi status disposisi: " . $e->getMessage());
        }
    }

    // Terusan DM pesan status ke semua PIMPINAN aktif bernomor WA. Tanpa efek
    // sesi - murni terusan (beda notifyLeaderDm yang menyiapkan PICK_TARGET).
    // Actor dilewati: bila ia kebetulan PIMPINAN, ia sudah tahu dari aksinya.
    private static function notifyLeadersStatus(array $s, string $msg, ?string $actorUserId): void
    {
        try {
            $leaders = Db::all("SELECT id, wa_number FROM users WHERE role = 'PIMPINAN' AND is_active = 1");
            foreach ($leaders as $leader) {
                if ($actorUserId !== null && $leader['id'] === $actorUserId) continue;
                if (empty($leader['waNumber'])) continue;
                $waTarget = Wabot::normalizeNumber($leader['waNumber']);
                if (!$waTarget) continue;
                try {
                    self::send($s['fonnteToken'] ?? '', $waTarget, $msg, $actorUserId);
                } catch (Throwable $e) {
                    // send() sudah error_log + audit WHATSAPP_FAILED; lanjut.
                }
            }
        } catch (Throwable $e) {
            error_log('[WhatsApp] Gagal terusan DM pimpinan: ' . $e->getMessage());
        }
    }

 // Kirim pesan ke grup target (helper sesi v2; abaikan bila grup belum diisi).
 public static function notifyGroup(PDO $db, string $message, ?string $actorUserId = null): void
 {
 try {
 $s = self::settings($db);
 if (!(int) $s['isEnabled'] || !$s['groupTarget']) return;
 self::send($s['fonnteToken'] ?? '', $s['groupTarget'], $message, $actorUserId);
 } catch (Throwable $e) {
 error_log('[WhatsApp] Gagal kirim pesan grup: ' . $e->getMessage());
 }
 }

 // v2 — DM langsung ke nomor WhatsApp pegawai yang baru menerima disposisi,
 // berisi menu status cepat: 1 = PROSES, 2 [catatan] = SELESAI.
 // $withMenu=false bila pegawai masih memegang sesi 'buat disposisi' pimpinan.
 // withMenu juga disimpan di wa_sessions.context agar balasan nomor tetap
 // dipetakan dengan benar oleh handler sesi (lihat handlers/wabot.php).
  // DM otomatis ke PIMPINAN aktif bernomor WA saat surat masuk baru diinput
 // (handlers/incoming.php). SURAT MASUK BARU + menu BUAT DISPOSISI digabung
 // satu pesan: sesi LEADER PICK_TARGET (snapshot users) dibuat otomatis,
 // pimpinan cukup balas "<nomor> <instruksi>" tanpa perintah DISPOSISI.
 public static function notifyLeaderDm(PDO $db, array $a): void
 {
 try {
 $s = self::settings($db);
 if (!(int) $s['isEnabled']) return;
 $leaders = Db::all("SELECT id, wa_number FROM users WHERE role = 'PIMPINAN' AND is_active = 1");
 foreach ($leaders as $leader) {
 if (empty($leader['waNumber'])) continue;
 $waTarget = Wabot::normalizeNumber($leader['waNumber']);
 if (!$waTarget) continue;
 $menu = self::ensureLeaderSession($db, $leader['id'], $a);
 $msg = Wabot::buildLeaderIncomingLetterDm(array_merge($a, $menu));
 self::send($s['fonnteToken'] ?? '', $waTarget, $msg, $a['actorUserId'] ?? null);
 }
 } catch (Throwable $e) {
 error_log('[WhatsApp] Gagal kirim DM surat masuk ke pimpinan: ' . $e->getMessage());
 }
 }

 // Sesi LEADER PICK_TARGET 60 menit + snapshot pegawai utk menu di DM.
 // Surat terbaru MENIMPA sesi pimpinan yang masih hidup (wa_sessions ber-PK
 // user_id => satu sesi; menu DM lama tinggal diulang via DISPOSISI <n>).
 // Sesi EMPLOYEE yang masih hidup tidak direbut (pimpinan tsb melapor status
 // dulu) — DM-nya memakai teks fallback DISPOSISI. Return ['users' => [...],
 // 'overflow' => bool] utk buildLeaderIncomingLetterDm; users kosong = fallback.
 private static function ensureLeaderSession(PDO $db, string $leaderUserId, array $a): array
 {
 $limit = Wabot::MENU_TARGET_LIMIT;
 // Kandidat sama dgn wabotV2StartSession: aktif + bernomor WA, urut nama.
 // Sejak Fase 3 memakai waDispositionCandidates() -> bawahan langsung untuk role
 // berwewenang hierarki, semua pegawai untuk ADMIN/PIMPINAN. Role dibaca dari DB
 // (penerima DM di sini selalu ber-role PIMPINAN, jadi perilakunya tidak berubah).
 $leaderRole = (string) (Db::one("SELECT role FROM users WHERE id = ?", [$leaderUserId])['role'] ?? 'PIMPINAN');
 $users = waDispositionCandidates($leaderUserId, $leaderRole, $limit);
 $overflow = count($users) > $limit;
 if ($overflow) $users = array_slice($users, 0, $limit);
 if (!$users) return ['users' => [], 'overflow' => false];
 $live = Db::one("SELECT kind, expires_at FROM wa_sessions
 WHERE user_id = ? AND kind = 'EMPLOYEE' AND expires_at > NOW()", [$leaderUserId]);
 if ($live) return ['users' => [], 'overflow' => false];
 $ctx = json_encode([
 'letterId' => $a['letterId'] ?? ($a['id'] ?? null),
 'agenda' => $a['agendaNumber'] ?? null,
 'subject' => $a['subject'] ?? null,
 'users' => $users,
 ], JSON_UNESCAPED_UNICODE);
 Db::q("INSERT INTO wa_sessions (user_id, kind, step, context, expires_at)
 VALUES (?, 'LEADER', 'PICK_TARGET', ?, ?)
 ON DUPLICATE KEY UPDATE kind = 'LEADER', step = 'PICK_TARGET',
 context = VALUES(context), expires_at = VALUES(expires_at)",
 [$leaderUserId, $ctx, Wabot::newExpiry(true)]);
 return ['users' => $users, 'overflow' => $overflow];
 }

public static function notifyDispositionAssignedDm(PDO $db, array $a): void
 {
 try {
 if (empty($a['waTarget'])) return; // nomor WA pegawai belum diisi -> skip senyap
 $withMenu = !empty($a['withMenu']);
 self::ensureDispositionSession($db, $a['toUserId'], $a, $withMenu);
 $msg = Wabot::buildEmployeeTaskMenu($a, $withMenu);
 self::send(self::settings($db)['fonnteToken'] ?? '', $a['waTarget'], $msg, $a['actorUserId'] ?? null);
 } catch (Throwable $e) {
 error_log('[WhatsApp] Gagal kirim DM disposisi ke pegawai: ' . $e->getMessage());
 }
 }

 // Pastikan pegawai memegang tepat satu sesi EMPLOYEE aktif (7 hari,
 // diperpanjang tiap balasan; ditutup permanen saat SELESAI).
 private static function ensureDispositionSession(PDO $db, string $toUserId, array $a, bool $withMenu): void
 {
 $ctx = json_encode([
 'dispositionId' => $a['dispositionId'],
 'subject' => $a['subject'],
 'instruction' => $a['instruction'],
 'deadline' => $a['deadline'],
 'withMenu' => $withMenu ? 1 : 0,
 ], JSON_UNESCAPED_UNICODE);
 Db::q("INSERT INTO wa_sessions (user_id, kind, step, context, expires_at)
 VALUES (?, 'EMPLOYEE', 'REPORT', ?, ?)
 ON DUPLICATE KEY UPDATE kind = IF(kind = 'LEADER' AND expires_at > NOW(), kind, 'EMPLOYEE'),
            step = IF(kind = 'LEADER' AND expires_at > NOW(), step, 'REPORT'),
            context = IF(kind = 'LEADER' AND expires_at > NOW(), context, VALUES(context)),
            expires_at = IF(kind = 'LEADER' AND expires_at > NOW(), expires_at, VALUES(expires_at))",
 [$toUserId, $ctx, Wabot::newExpiry(false)]);
 }
}
