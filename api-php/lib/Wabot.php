<?php
// Wabot — bot WhatsApp disposisi SIMARS. Kelas MURNI (tanpa DB/IO): parser
// perintah, kebijakan akses, dan pembangun pesan balasan. Diuji oleh
// tests/WabotTest.php (PHPUnit) & tests/run_wa_bot_check.php (runner mandiri).
//
// Perintah yang dikenali (tidak peka huruf besar/kecil; formatting WA * _ ` ~ dibuang):
//   DISPOSISI                        -> daftar disposisi aktif  (action LIST)
//   DISPOSISI BANTUAN | HELP         -> bantuan                 (action HELP)
//   DISPOSISI <n> PROSES [catatan]   -> update status           (action UPDATE)
//   DISPOSISI <n> SELESAI [catatan]  -> update status           (action UPDATE)
//   DISPOSISI SELESAI <n> [catatan]  -> urutan status dulu, juga diterima
// Kata status PROSES: proses, diproses, "di proses", tindaklanjuti,
// "tindak lanjuti", kerjakan, lanjutkan, "belum selesai", "belum dikerjakan", belum.
// Kata status SELESAI: selesai, selesaikan, "sudah selesai", "sudah dikerjakan",
// "sudah diarsipkan", done, beres, kelar, tuntaskan.
// Pesan yang TIDAK berawalan "DISPOSISI" atau bukan berbentuk perintah -> null
// (handler mengabaikannya senyap, bot tidak ikut bicara di percakapan biasa).
class Wabot
{
    // Role yang boleh memakai bot (keputusan produk: PIMPINAN + ADMIN).
    public const ALLOWED_ROLES = ['PIMPINAN', 'ADMIN'];
// Durasi sesi WA v2 (revisi 2026-09-09): pimpinan sering sibuk -> 60 MENIT
// dari perintah mulai (tetap, tidak bergeser). Sesi pegawai 7 HARI dan
// diperpanjang otomatis setiap balasan valid; ditutup permanen saat SELESAI.
public const LEADER_SESSION_MINUTES = 60;
public const EMPLOYEE_SESSION_DAYS = 7;
// Batas daftar pegawai bernomor pada menu disposisi (DM pimpinan & StartSession).
public const MENU_TARGET_LIMIT = 25;

    private const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private const PROSES_PHRASES = [
        'sedang diproses', 'sedang proses', 'tindak lanjuti', 'tindaklanjuti',
        'belum selesai', 'belum dikerjakan', 'di proses', 'diproses', 'kerjakan',
        'lanjutkan', 'proses', 'belum', 'lanjut',
    ];

    private const SELESAI_PHRASES = [
        'sudah dikerjakan', 'sudah diarsipkan', 'sudah selesai', 'telah selesai',
        'selesaikan', 'selesai', 'tuntaskan', 'kelar', 'beres', 'done',
    ];

    // Kebijakan: hanya PIMPINAN/ADMIN aktif yang boleh memakai bot.
    public static function isAuthorizedRole(?string $role): bool
    {
        return $role !== null && in_array($role, self::ALLOWED_ROLES, true);
    }

    // Hanya digit dari JID/nomor ("62812..@s.whatsapp.net" / "120363..@g.us" / "+62 812..").
    public static function digitsOf(?string $jid): ?string
    {
        $jid = trim((string) $jid);
        if ($jid === '') return null;
        $d = preg_replace('/[^0-9]/', '', $jid);
        return $d === '' ? null : $d;
    }

    // 08xx -> 62xx, +62/62 tetap, 8xx -> 62xx. Port Whatsapp::normalizePhone —
    // diduplikasi agar kelas ini mandiri & mudah dites tanpa kelas lain.
    public static function normalizeNumber(?string $input): ?string
    {
        if (!$input) return null;
        $d = self::digitsOf($input);
        if ($d === null) return null;
        if (str_starts_with($d, '62')) return $d;
        if (str_starts_with($d, '0')) return '62' . substr($d, 1);
        if (str_starts_with($d, '8')) return '62' . $d;
        return $d;
    }

    // Pisahkan konteks percakapan sesuai perilaku webhook Fonnte:
    // - Grup  : sender = ID grup (@g.us), member = pengirim sebenarnya.
    // - Pribadi: sender = nomor pengirim (member sama / kosong).
    // Return [isGroup, actor, chatTarget] atau null bila sender kosong.
    public static function identifySender(?string $sender, ?string $member): ?array
    {
        $sender = trim((string) $sender);
        $member = trim((string) $member);
        if ($sender === '') return null;
        $isGroup = str_contains(strtolower($sender), '@g.us')
            || ($member !== '' && $member !== $sender);
        return [
            'isGroup' => $isGroup,
            'actor' => $isGroup ? $member : $sender,
            'chatTarget' => $sender,
        ];
    }

    // Parser perintah. Return:
    //   ['action'=>'LIST'] | ['action'=>'HELP'] | ['action'=>'INVALID']
    //   ['action'=>'UPDATE', 'status'=>'PROSES'|'SELESAI', 'ordinal'=>int>=1, 'notes'=>?string]
    //   null = bukan perintah bot (abaikan senyap).
    // 'INVALID' hanya untuk pesan yang JELAS berbentuk perintah tapi tidak lengkap
    // (mis. "DISPOSISI 2" tanpa kata status), supaya bot bisa mengarahkan pengguna
    // tanpa ikut bicara pada kalimat biasa yang mengandung kata "disposisi".
    public static function parseCommand(?string $text): ?array
    {
        if ($text === null) return null;

        // Buang formatting WhatsApp + samakan whitespace (NBSP/ZWSP byte-level,
        // tanpa mbstring supaya bisa jalan di PHP polos).
        $t = str_replace(['*', '_', '`', '~', "\xC2\xA0", "\xE2\x80\x8B"], ' ', $text);
        $t = preg_replace('/\s+/', ' ', trim($t));
        if ($t === '' || $t === null) return null;

        if (preg_match('/^disposisi\b/i', $t) !== 1) return null;
        $body = trim((string) preg_replace('/^disposisi\b[\s:,\-]*/i', '', $t));
        if ($body === '') return ['action' => 'LIST'];

        $lower = strtolower($body);
        if (in_array($lower, ['bantuan', 'help', 'panduan', '?'], true)) return ['action' => 'HELP'];
        if (in_array($lower, ['daftar', 'list', 'cek'], true)) return ['action' => 'LIST'];

        $tokens = explode(' ', $lower);

        // Pola A: "DISPOSISI <n> <status> [catatan]"
        if (ctype_digit($tokens[0])) {
            $ordinal = (int) $tokens[0];
            if ($ordinal < 1) return ['action' => 'INVALID'];
            [$status, $rest] = self::matchStatus(trim(implode(' ', array_slice($tokens, 1))));
            if ($status === null) return ['action' => 'INVALID'];
            return ['action' => 'UPDATE', 'status' => $status, 'ordinal' => $ordinal,
                'notes' => self::cleanNotes($rest)];
        }

        // Pola B: "DISPOSISI <status> <n> [catatan]"
        [$status, $rest] = self::matchStatus($lower);
        if ($status === null) return null; // kalimat biasa — abaikan senyap
        $rest = ltrim($rest, " \t:,.\-");
        if (preg_match('/^(\d{1,3})\b/', $rest, $m) !== 1) return ['action' => 'INVALID'];
        $notes = self::cleanNotes(substr($rest, strlen($m[1])));
        return ['action' => 'UPDATE', 'status' => $status, 'ordinal' => (int) $m[1], 'notes' => $notes];
    }

    // Cocokkan kata status di AWAL $s; frasa terpanjang menang ("belum selesai"
    // dipilih sebelum "selesai"). Return [status|null, sisa-teks].
    private static function matchStatus(string $s): array
    {
        $s = ltrim($s, " \t:,.\-");
        $cands = [];
        foreach (self::SELESAI_PHRASES as $p) $cands[$p] = 'SELESAI';
        foreach (self::PROSES_PHRASES as $p) $cands[$p] = 'PROSES';
        uksort($cands, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($cands as $phrase => $status) {
            if (str_starts_with($s, $phrase)) {
                return [$status, trim(substr($s, strlen($phrase)))];
            }
        }
        return [null, $s];
    }

    private static function cleanNotes(?string $s): ?string
    {
        $s = trim((string) $s, " \t:,.\-");
        return $s === '' ? null : $s;
    }

    // ---------- Pembangun pesan balasan ----------

    public static function buildHelpText(array $sender, string $name): string
    {
        return "🤖 *BOT DISPOSISI SIMARS*\n"
            . "Halo {$name}, perintah yang tersedia:\n"
            . "Kirim *DISPOSISI* dari " . ($sender['label'] ?? (($sender['isGroup'] ?? false) ? 'grup ini' : 'nomor ini')) . ":\n\n"
            . "• *DISPOSISI* — daftar disposisi Anda yang belum selesai\n"
            . "• *DISPOSISI BANTUAN* — tampilkan bantuan ini\n"
            . "• *DISPOSISI <nomor> PROSES* — tandai sedang dikerjakan\n"
            . "• *DISPOSISI <nomor> SELESAI [catatan]* — tandai selesai\n\n"
            . "Contoh: *DISPOSISI 1 SELESAI* Surat telah diarsipkan\n\n"
            . "Catatan: hanya disposisi yang ditujukan kepada Anda (status "
            . "PENDING/PROSES) yang dapat diperbarui, dari nomor WhatsApp terdaftar.";
    }

    // $items = baris disposisi (camelCase dari Db::all) SUDAH terurut terbaru dulu.
    public static function buildListText(array $items, string $name): string
    {
        if (!$items) {
            return "📋 *DAFTAR DISPOSISI — {$name}*\n\n"
                . "Tidak ada disposisi yang perlu Anda tindaklanjuti. 🎉";
        }
        $out = "📋 *DAFTAR DISPOSISI — {$name}* (terbaru di atas)\n";
        foreach (array_values($items) as $i => $d) {
            $no = $i + 1;
            $out .= "\n{$no}. " . self::truncate($d['letterSubject'] ?? '-', 60)
                . "\n    Status: {$d['status']} • Batas: " . self::formatDate($d['deadline'] ?? null)
                . (!empty($d['instruction']) ? "\n    Instruksi: " . self::truncate($d['instruction'], 60) : '');
        }
        $out .= "\n\nBalas: *DISPOSISI <nomor> SELESAI* untuk menyelesaikan.";
        return $out;
    }

    public static function buildConfirmation(array $d, string $status, ?string $notes): string
    {
        $emoji = $status === 'SELESAI' ? '✅' : '⏳';
        $out = "{$emoji} *STATUS DISPOSISI DIPERBARUI*\n"
            . "Perihal: " . self::truncate($d['letterSubject'] ?? '-', 60) . "\n"
            . "Status baru: *{$status}*";
        if ($notes !== null && $notes !== '') $out .= "\nCatatan: " . self::truncate($notes, 80);
        return $out . "\n\nPemberi disposisi telah menerima notifikasi.";
    }

    public static function buildAlreadyText(array $d, string $status): string
    {
        return "⚠️ Disposisi ini *{$d['status']}*\nPerihal: " . self::truncate($d['letterSubject'] ?? '-', 60)
            . "\n\nStatus sudah *{$status}*, tidak ada perubahan.\nKetik *DISPOSISI* untuk melihat daftar.";
    }

    public static function buildNotFoundText(int $ordinal, int $total): string
    {
        if ($total === 0) {
            return "⚠️ Tidak ada disposisi aktif atas nama Anda saat ini.\nKetik *DISPOSISI* nanti untuk cek daftar.";
        }
        return "⚠️ Disposisi nomor {$ordinal} tidak ada. Daftar Anda memuat *{$total}* disposisi aktif.\n"
            . "Ketik *DISPOSISI* untuk melihat daftar bernomor.";
    }

    public static function buildInvalidText(): string
    {
        return "⚠️ Perintah belum lengkap. Contoh yang benar: *DISPOSISI 1 SELESAI*\n"
            . "Ketik *DISPOSISI BANTUAN* untuk bantuan lengkap.";
    }

    public static function buildDeniedText(string $name, string $role): string
    {
        return "⛔ Maaf {$name}, akun dengan jabatan *{$role}* tidak berwenang memakai bot disposisi.\n"
            . "Hubungi Admin bila ini keliru.";
    }

    // Perintah v1 (teks) khusus PIMPINAN/ADMIN; pegawai melapor status lewat
    // menu sesi v2 di chat pribadinya (1 = PROSES, 2 [catatan] = SELESAI).
    public static function buildEmployeeMenuHintText(): string
    {
        return "\n\nLaporkan status tugas Anda lewat menu di chat pribadi: balas *1* untuk PROSES "
            . "atau *2 [catatan]* untuk SELESAI.";
    }

    // ---------- util kecil (mandiri, tanpa ekstensi mbstring) ----------

    public static function truncate(?string $s, int $max): string
    {
        $s = trim((string) $s);
        if ($s === '') return '-';
        if (strlen($s) <= $max) return $s;
        $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
        return rtrim($cut) . '…';
    }

    public static function formatDate(?string $d): string
    {
        if (!$d) return '—';
        $ts = strtotime($d);
        if ($ts === false) return '—';
        return date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

 // ---------- v2: sesi "buat disposisi" via WhatsApp ----------

 // Normalisasi teks WA: buang formatting * _ ` ~, NBSP/ZWSP, rapikan spasi.
 public static function cleanText(?string $text): string
 {
 if ($text === null) return '';
 $t = str_replace(['*', '_', '`', '~', "\xC2\xA0", "\xE2\x80\x8B"], ' ', $text);
 return trim((string) preg_replace('/\s+/', ' ', $t));
 }

 // Timestamp kadaluarsa sesi baru (string utk kolom DATETIME).
 public static function newExpiry(bool $isLeader): string
 {
 $sec = $isLeader ? self::LEADER_SESSION_MINUTES * 60 : self::EMPLOYEE_SESSION_DAYS * 86400;
 return date('Y-m-d H:i:s', time() + $sec);
 }

 // Sesi sudah lewat? (null/kosong = kedaluarsa)
 public static function isExpired(?string $expiresAt): bool
 {
 if ($expiresAt === null || $expiresAt === '') return true;
 $ts = strtotime($expiresAt);
 return $ts === false || $ts <= time();
 }

 // Parser perintah mulai sesi "DISPOSISI <nomor agenda>" (grup atau Japri).
 // Return: ['action'=>'START','agenda'=>int] | ['action'=>'KEYWORD','keyword'=>string]
 // | ['action'=>'INVALID_START'] | null.
 // null = bukan mulai-sesi: "DISPOSISI" polos (v1: daftar) dan "DISPOSISI
 // 2 SELESAI" (v1: update status) TETAP diproses parser v1.
 public static function parseGroupStart(?string $text): ?array
 {
 $t = self::cleanText($text);
 if ($t === '') return null;
 if (preg_match('/^disposisi\b(.*)$/is', $t, $m) !== 1) return null;
 $body = ltrim(trim($m[1]), " \t:,-");
 if ($body === '') return null; // DISPOSISI polos -> v1 daftar
 if (preg_match('/^\d/', $body) === 1) {
 if (preg_match('/^(\d{1,3})(?!\d)(?:\s+(.*))?$/', $body, $m) === 1) {
 $rest = trim((string) ($m[2] ?? ''));
 if ($rest !== '' && self::looksLikeStatusText($rest)) return null; // v1 update
 return ['action' => 'START', 'agenda' => (int) $m[1]];
 }
 return ['action' => 'INVALID_START']; // 1234 / 12abc
 }
 if (in_array(strtolower($body), ['bantuan', 'help', 'panduan', '?', 'daftar', 'list', 'cek'], true)) {
    return null; // perintah v1 (bantuan/daftar) — bukan mulai-sesi
}
if (strlen($body) >= 3) {
    if (self::looksLikeStatusText($body)) return null; // v1 pola B: DISPOSISI <status> <n>
    return ['action' => 'KEYWORD', 'keyword' => $body];
}
 return ['action' => 'INVALID_START']; // terlalu pendek utk kata kunci
 }

 // Apakah teks mengawali kata status v1 (proses/selesai/dst)?
 private static function looksLikeStatusText(string $s): bool
 {
 $s = strtolower(ltrim($s, " \t:,-"));
 foreach (self::PROSES_PHRASES as $p) if (str_starts_with($s, $p)) return true;
 foreach (self::SELESAI_PHRASES as $p) if (str_starts_with($s, $p)) return true;
 return false;
 }

 // Parser balasan sesi aktif. Return:
 // ['type'=>'CHOICE','choice'=>int>=1,'notes'=>?string] | REMENU | CANCEL
 // | UNKNOWN | IGNORE. (Perintah v1 "DISPOSISI ..." TIDAK dikenali di sini —
 // handler memprioritaskan jalur v1 bila parseCommand() cocok.)
 public static function parseSessionReply(?string $text): array
 {
 $t = self::cleanText($text);
 if ($t === '') return ['type' => 'IGNORE'];
 $u = strtoupper($t);
 if ($u === 'BATAL' || $u === 'CANCEL') return ['type' => 'CANCEL'];
 if ($u === 'MENU' || $u === 'ULANG' || $u === 'DAFTAR' || $u === 'LIST') return ['type' => 'REMENU'];
 if (preg_match('/^(\d{1,3})(?:\s+(.*))?$/', $t, $m) === 1) {
 return ['type' => 'CHOICE', 'choice' => (int) $m[1], 'notes' => self::cleanNotes($m[2] ?? null)];
 }
 return ['type' => 'UNKNOWN'];
 }

 // Menu pilih penerima disposisi (dikirim JAPRI ke pimpinan).
 public static function buildTargetMenu(string $agenda, string $subject, array $users): string
 {
 $out = "\xF0\x9F\xA4\x96 *BUAT DISPOSISI - SURAT {$agenda}*\n"
 . 'Perihal: ' . self::truncate($subject, 70) . "\n\n"
 . 'Pilih pegawai tujuan (balas nomor):';
 foreach (array_values($users) as $i => $u) {
 $out .= "\n" . ($i + 1) . '. ' . $u['name'] . (!empty($u['role']) ? " ({$u['role']})" : '');
 }
 $out .= "\n\nBalas *<nomor>* saja, atau *<nomor> <instruksi>* - contoh: *2 Harap hadir rapat koordinasi*.\n"
 . "*MENU* tampilkan ulang | *BATAL* batalkan sesi.\n"
 . 'Sesi berlaku ' . self::LEADER_SESSION_MINUTES . ' menit.';
 return $out;
 }

 public static function buildInvalidChoiceText(int $choice, int $total): string
 {
 return "\xE2\x9A\xA0\xEF\xB8\x8F Pilihan *{$choice}* tidak ada - pilih nomor 1-{$total}.\n"
 . "Balas *MENU* untuk menampilkan ulang daftar, atau *BATAL*.";
 }

 public static function buildInvalidStartText(): string
 {
 return "\xE2\x9A\xA0\xEF\xB8\x8F Format belum tepat. Kirim: *DISPOSISI <nomor agenda>*\n"
 . 'Contoh: *DISPOSISI 12* - bot akan mengirim menu pegawai ke chat pribadi Anda.';
 }

 public static function buildAgendaNotFoundText(string $ref): string
 {
 return "\xE2\x9A\xA0\xEF\xB8\x8F Surat dengan agenda/perihal *{$ref}* tidak ditemukan.\n"
 . 'Cek nomor agenda pada notifikasi surat masuk, lalu kirim *DISPOSISI <nomor agenda>*.';
 }

 public static function buildAmbiguousText(string $keyword, int $count): string
 {
 return "\xF0\x9F\xA4\x94 Ada *{$count}* surat yang cocok dengan \"{$keyword}\".\n"
 . 'Gunakan nomor agenda, contoh: *DISPOSISI 12*.';
 }

 // DM ke PIMPINAN: surat masuk baru + menu "BUAT DISPOSISI" DIGABUNG satu
 // pesan, supaya pimpinan cukup balas "<nomor> <instruksi>" tanpa perintah
 // DISPOSISI lagi. Sesi LEADER PICK_TARGET (snapshot users sama) dibuat oleh
 // Whatsapp::ensureLeaderSession. Bila $a['users'] kosong (sesi tidak jadi
 // dibuat — mis. pimpinan memegang sesi pegawai atau tak ada kandidat), teks
 // fallback ke perintah DISPOSISI lama.
 public static function buildLeaderIncomingLetterDm(array $a): string
 {
 $users = array_values(array_filter((array) ($a['users'] ?? []),
 fn($u) => is_array($u) && !empty($u['id'])));
 $agenda = (string) ($a['agendaNumber'] ?? '-');
 $out = "\xF0\x9F\x93\xAA *SURAT MASUK BARU*\n"
 . "Agenda: " . ($a['agendaNumber'] ?? '-') . "\n"
 . "Perihal: " . self::truncate((string) ($a['subject'] ?? '-'), 80) . "\n";
 if (!empty($a['sender'])) {
 $out .= "Dari: " . self::truncate((string) $a['sender'], 60) . "\n";
 }
 if (!empty($a['attachmentUrl'])) {
 $out .= "Lampiran: " . $a['attachmentUrl'] . "\n";
 }
 if ($users) {
 $out .= "\n\xE2\x9C\xB0 *BUAT DISPOSISI* - cukup balas dari chat ini, TANPA perintah DISPOSISI:\n"
 . "Balas *<nomor> <instruksi>* - contoh: *2 Harap hadir rapat koordinasi*.\n\n"
 . "Pegawai tujuan (balas nomor):";
 foreach ($users as $i => $u) {
 $out .= "\n" . ($i + 1) . '. ' . $u['name'] . (!empty($u['role']) ? " ({$u['role']})" : '');
 }
 if (!empty($a['overflow'])) {
 $out .= "\n\n_Daftar dibatasi " . self::MENU_TARGET_LIMIT . " pegawai teratas; pilih lewat aplikasi web bila tujuan tidak ada._";
 }
 $out .= "\n\n*MENU* tampilkan ulang daftar | *BATAL* batalkan sesi | ganti surat: *DISPOSISI <nomor agenda>*.\n"
 . 'Sesi berlaku ' . self::LEADER_SESSION_MINUTES . ' menit sejak surat ini datang.';
 return $out;
 }
 $out .= "Buat disposisi: balas *DISPOSISI <nomor agenda>* di chat ini atau grup.\n"
 . "Contoh: *DISPOSISI " . (string) ($a['agendaNumber'] ?? '<nomor>') . "*";
 return $out;
 }

 // DM ke pegawai penerima disposisi + menu status. $withMenu=false bila
 // pegawai masih memegang sesi pimpinan (hindari salah-tekan angka).
 public static function buildEmployeeTaskMenu(array $d, bool $withMenu = true): string
 {
 $out = "\xF0\x9F\xA4\x96 *DISPOSISI BARU UNTUK ANDA*\n"
 . 'Perihal: ' . self::truncate((string) ($d['subject'] ?? '-'), 70) . "\n"
 . 'Instruksi: ' . self::truncate(($d['instruction'] ?? '') !== '' ? (string) $d['instruction'] : '-', 100) . "\n"
 . 'Batas: ' . self::formatDate($d['deadline'] ?? null) . "\n";
 if (!empty($d['attachmentUrl'])) {
 $out .= "Lampiran: " . $d['attachmentUrl'] . "\n";
 }
 if ($withMenu) {
 $out .= "\nBalas dari nomor ini:\n"
 . "*1* - tandai PROSES (sedang dikerjakan)\n"
 . "*2 [catatan]* - tandai SELESAI - contoh: *2 Surat telah diarsipkan*\n\n"
 . 'Sesi aktif ' . self::EMPLOYEE_SESSION_DAYS . " hari (otomatis diperpanjang saat Anda membalas) dan ditutup otomatis setelah SELESAI.";
 } else {
 $out .= "\nSelesaikan dulu sesi disposisi yang sedang berjalan (balas *BATAL*) agar menu status cepat aktif.\n"
 . 'Perintah lama tetap bisa dipakai: *DISPOSISI <nomor> SELESAI*.';
 }
 return $out;
 }

 // Konfirmasi JAPRI ke pimpinan setelah 1 disposisi dibuat.
 public static function buildDispositionCreatedText(string $agenda, string $subject, string $toName, string $instruction): string
 {
 return "\xE2\x9C\x85 *DISPOSISI DIBUAT*\n"
 . "Surat: " . self::truncate($subject, 70) . "\n"
 . "Agenda: {$agenda}\n"
 . "Kepada: {$toName}\n"
 . 'Instruksi: ' . self::truncate($instruction !== '' ? $instruction : '-', 100) . "\n\n"
 . 'Pegawai menerima WA pengingat untuk memperbarui status (1 = PROSES, 2 [catatan] = SELESAI).';
 }

 // Pengumuman ke GRUP (sesuai desain: grup hanya menerima konfirmasi resmi).
 public static function buildGroupNoticeText(string $leaderName, string $toName, string $subject): string
 {
 return "\xE2\x9C\x85 *SURAT TELAH DIDISPOSISIKAN*\n"
 . 'Surat ' . self::truncate($subject, 70) . " telah didisposisikan oleh {$leaderName} kepada {$toName}. Silakan ditindaklanjuti.";
 }

 // Konfirmasi progres utk pegawai (sesi EMPLOYEE). PROSES: ingatkan sintaks tutup.
 public static function buildStatusProgressText(string $status, ?string $notes, string $subject): string
 {
 $out = $status === 'SELESAI'
 ? "\xE2\x9C\x85 *STATUS: SELESAI* - terima kasih!\nPerihal: " . self::truncate($subject, 70)
 : "\xE2\x8F\xB3 *STATUS: PROSES* - tersimpan.\nPerihal: " . self::truncate($subject, 70);
 if ($notes !== null && $notes !== '') $out .= "\nCatatan: " . self::truncate($notes, 80);
 if ($status !== 'SELESAI') {
 $out .= "\n\nSaat sudah selesai, balas *2 [catatan]* - contoh: *2 Surat telah diarsipkan*.";
 }
 return $out;
 }

 public static function buildSessionClosedText(bool $cancelled, bool $fromGroup): string
 {
 if ($cancelled) {
 return $fromGroup
 ? "\xF0\x9F\x9A\xAB Sesi WhatsApp dibatalkan.\nUntuk membuat disposisi baru, kirim *DISPOSISI <nomor agenda>* di grup."
 : "\xF0\x9F\x9A\xAB Sesi dibatalkan.\nKetik *DISPOSISI* untuk melihat daftar disposisi Anda.";
 }
 return "\xE2\x9C\x85 Tugas disposisi selesai & sesi WhatsApp Anda ditutup.\nKetik *DISPOSISI* untuk melihat daftar disposisi Anda.";
 }

 public static function buildExpiredText(): string
 {
 return "\xE2\x8C\x9B Sesi WhatsApp Anda telah berakhir.\n"
 . "Mulai lagi: kirim *DISPOSISI <nomor agenda>* di grup.\n"
 . 'Perintah cepat tetap tersedia, contoh: *DISPOSISI 1 SELESAI*';
 }

 public static function buildUnknownReplyText(): string
 {
 return "\xE2\x9A\xA0\xEF\xB8\x8F Balasan tidak dikenali.\nBalas *MENU* untuk menampilkan ulang menu, atau *BATAL* untuk membatalkan sesi.";
 }

// Tidak ada kandidat pegawai utk menu (tidak ada user aktif ber-nomor WA).
public static function buildNoTargetText(): string
{
    return "\xE2\x9A\xA0\xEF\xB8\x8F Tidak ada pegawai aktif dengan nomor WhatsApp terdaftar.\n"
        . 'Silakan buat disposisi melalui aplikasi web.';
}

// Pegawai tujuan tidak tersedia saat sesi (dinonaktifkan/dihapus).
public static function buildTargetUnavailableText(string $name): string
{
    return "\xE2\x9A\xA0\xEF\xB8\x8F Pegawai tujuan ({$name}) tidak lagi tersedia.\n"
        . "Balas *MENU* untuk menampilkan ulang daftar, atau *BATAL*.";
}
}
