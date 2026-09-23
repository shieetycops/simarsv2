<?php
// Helper kecil dipakai lintas handler.

// Cek field wajib non-kosong. Return map {field: [pesan]} ala zod fieldErrors, atau [] bila valid.
function requireFields(?array $data, array $fields): array
{
    $errors = [];
    foreach ($fields as $f) {
        if (empty($data[$f])) {
            $errors[$f] = ["$f wajib diisi"];
        }
    }
    return $errors;
}

// Kebijakan password minimum. Return pesan error, atau null bila valid.
// Dipakai POST/PUT /users — dulu password kosong pun lolos ke password_hash().
function validatePassword(?string $password): ?string
{
    if (strlen((string) $password) < 8) {
        return 'Password minimal 8 karakter.';
    }
    return null;
}

// Sensor nomor WA untuk log: 628123456789 -> 6281****6789. Nomor WA adalah data
// pribadi, jadi jangan ditulis utuh ke error_log.
function maskPhone(?string $phone): string
{
    $p = (string) $phone;
    if (strlen($p) <= 8) {
        return str_repeat('*', strlen($p));
    }
    return substr($p, 0, 4) . str_repeat('*', strlen($p) - 8) . substr($p, -4);
}

// Tulis satu baris activity_logs.
function logActivity(string $userId, string $action, string $entity, ?string $entityId, ?string $details): void
{
    Db::q("INSERT INTO activity_logs (id, user_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)",
        [Db::generateId(), $userId, $action, $entity, $entityId, $details]);
}

// Bangun klausa WHERE rentang tanggal opsional. Return [sqlWhere, params].
function dateWhere(?string $start, ?string $end, string $col): array
{
    $conds = []; $p = [];
    if ($start) { $conds[] = "$col >= ?"; $p[] = date('Y-m-d H:i:s', strtotime($start)); }
    if ($end)   { $conds[] = "$col <= ?"; $p[] = date('Y-m-d H:i:s', strtotime($end)); }
    return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $p];
}

// Tempelkan array dispositions ke tiap surat masuk (meniru include: { dispositions: true }).
function attachDispositions(array $letters): array
{
    if (!$letters) return [];
    $ids = array_column($letters, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $rows = Db::all("SELECT * FROM dispositions WHERE incoming_letter_id IN ($in) ORDER BY created_at DESC", $ids);
    $byLetter = [];
    foreach ($rows as $d) {
        $byLetter[$d['incomingLetterId']][] = $d;
    }
    foreach ($letters as &$l) {
        $l['dispositions'] = $byLetter[$l['id']] ?? [];
        $l['status'] = computeLetterStatus($l['status'] ?? null, $l['dispositions']);
    }
    return $letters;
}

// Status agregat surat dari kumpulan dispositions-nya — DIHITUNG, bukan
// kolom tersimpan, supaya tidak pernah "basi"/nggak sinkron. Pengecualian:
// surat tanpa disposisi (mis. arsip lama yang diinput retroaktif, sudah
// selesai sebelum SIMARS dipakai) boleh membawa status SELESAI tersimpan
// di kolom incoming_letters.status supaya tidak nyangkut selamanya sebagai
// "Belum Didisposisi". Selain itu kolom tersimpan diabaikan.
// https://domain/surats/<id> (halaman publik view-only, tanpa auth).
function letterViewUrl(string $id): string
{
    $base = appUrl();
    if ($base === '') {
        return ''; // app_url belum diisi -> pemanggil melewatkan bagian lampiran
    }
    return $base . '/surats/' . rawurlencode($id) . '?t=' . letterViewToken($id);
}

// Token tautan view-only: "<exp>.<hmac>". Token membuat tautan hanya berlaku
// untuk satu surat dan kedaluwarsa, sehingga tautan yang diteruskan (mis. ke
// grup WhatsApp) tidak bisa dibuka selamanya oleh siapa pun.
function letterViewToken(string $id, int $ttl = 2592000): string
{
    $secret = appSecret();
    if ($secret === '') {
        return '';
    }
    $exp = time() + $ttl; // default 30 hari
    return $exp . '.' . substr(hash_hmac('sha256', $id . '|' . $exp, $secret), 0, 32);
}

// Verifikasi token tautan view-only. Bila app_secret & turnstile_secret_key
// sama-sama kosong, fungsi ini mengembalikan true (perilaku lama) supaya fitur
// tidak mati hanya karena konfigurasi belum diisi.
function letterViewTokenValid(string $id, ?string $token): bool
{
    $secret = appSecret();
    if ($secret === '') {
        return true;
    }
    $parts = explode('.', (string) $token);
    if (count($parts) !== 2) {
        return false;
    }
    [$exp, $sig] = $parts;
    if (!ctype_digit($exp) || (int) $exp <= time()) {
        return false;
    }
    $expected = substr(hash_hmac('sha256', $id . '|' . $exp, $secret), 0, 32);
    return hash_equals($expected, $sig);
}

// Kunci HMAC aplikasi: config app_secret, fallback turnstile_secret_key
// (kunci itu juga rahasia dan sudah terisi di produksi).
function appSecret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $secret = '';
    $config = dirname(__DIR__) . '/config.php';
    if (is_file($config)) {
        $c = require $config;
        if (is_array($c)) {
            $secret = (string) ($c['app_secret'] ?? '');
            if ($secret === '') {
                $secret = (string) ($c['turnstile_secret_key'] ?? '');
            }
        }
    }
    return $secret;
}

function appUrl(): string
{
    // app_url tersimpan di whatsapp_settings bila diisi manual; fallback
    // auto-detect dari REQUEST_* / config. Query dibungkus try/catch:
    // kolom app_url mungkin belum ada (migration belum jalan) -> fallback,
    // jangan sampai crash saat kirim notifikasi WA.
    if (class_exists('Db')) {
        try {
            $row = Db::one("SELECT app_url FROM whatsapp_settings WHERE id = 'wa_settings'");
            if ($row && !empty($row['appUrl'])) {
                return rtrim($row['appUrl'], '/');
            }
        } catch (Throwable $e) {
            // kolom belum ada -> lanjut fallback
        }
    }
    $config = dirname(__DIR__) . '/config.php';
    if (is_file($config)) {
        $c = require $config;
        if (!empty($c['app_url'])) {
            return rtrim((string) $c['app_url'], '/');
        }
    }
    // SENGAJA tidak memakai $_SERVER['HTTP_HOST']: header Host dikendalikan
    // pengirim request, jadi link notifikasi WA bisa diarahkan ke domain
    // penyerang (host header injection). Isi app_url di Pengaturan WhatsApp.
    return '';
}

function computeLetterStatus(?string $storedStatus, array $dispositions): string
{
    if (!$dispositions) {
        return $storedStatus === 'SELESAI' ? 'SELESAI' : 'BELUM_DISPOSISI';
    }
    foreach ($dispositions as $d) {
        if (($d['status'] ?? null) !== 'SELESAI') return 'DALAM_PROSES';
    }
    return 'SELESAI';
}

// Kirim POST ke Cloudflare siteverify. Return ['success' => bool, 'error' => string].
// Dipisah dari verifyTurnstile() supaya penyebab kegagalan bisa ditelusuri
// (cURL tidak aktif, SSL gagal, token kedaluwarsa) tanpa menebak-nebak.
function turnstileSiteverify(string $secretKey, string $token, ?string $remoteIp = null): array
{
    if ($secretKey === '' || $token === '') {
        return ['success' => false, 'error' => 'secret key atau token kosong'];
    }

    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $payload = http_build_query(array_filter([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $remoteIp,
    ], fn($v) => $v !== null && $v !== ''));

    // Jalur 1: cURL (paling umum tersedia di shared hosting).
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => 'cURL gagal: ' . ($err !== '' ? $err : 'tanpa keterangan')];
        }
        if ($code !== 200) {
            return ['success' => false, 'error' => "siteverify membalas HTTP $code"];
        }
        return turnstileParse((string) $raw);
    }

    // Jalur 2: fallback tanpa cURL (butuh allow_url_fopen).
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['success' => false, 'error' => 'file_get_contents gagal (allow_url_fopen)'];
        }
        return turnstileParse((string) $raw);
    }

    return ['success' => false, 'error' => 'ekstensi cURL tidak aktif dan allow_url_fopen dimatikan'];
}

// Baca balasan siteverify -> ['success' => bool, 'error' => string].
function turnstileParse(string $raw): array
{
    $res = json_decode($raw, true);
    if (!is_array($res)) {
        return ['success' => false, 'error' => 'respons bukan JSON: ' . substr($raw, 0, 200)];
    }
    $codes = $res['error-codes'] ?? [];
    return [
        'success' => ($res['success'] ?? false) === true,
        'error'   => is_array($codes) ? implode(', ', $codes) : (string) $codes,
    ];
}

// Verifikasi token Cloudflare Turnstile (true bila lolos).
function verifyTurnstile(string $secretKey, string $token, ?string $remoteIp = null): bool
{
    return turnstileSiteverify($secretKey, $token, $remoteIp)['success'];
}
