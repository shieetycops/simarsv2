<?php
// Auth: bearer token opak di tabel auth_tokens (disimpan sebagai hash SHA-256).
// Gagal = lempar AuthException (bukan exit) supaya bisa dites & ditangkap
// terpusat di router.

class AuthException extends Exception
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        $this->status = $status;
        parent::__construct($message);
    }
}

class Auth
{
    // Verifikasi kredensial + Turnstile, buat token 24 jam, return {token, user{...}}.
    public static function login(PDO $db, string $username, string $password, ?string $turnstileToken = null, ?array $config = null): array
    {
        // Validasi Turnstile jika diaktifkan
        if ($config && ($config['turnstile_enabled'] ?? false)) {
            if (!function_exists('turnstileSiteverify')) {
                throw new AuthException(500, "File api/lib/helpers.php belum diperbarui (fungsi turnstileSiteverify tidak ditemukan).");
            }
            $secretKey = (string) ($config['turnstile_secret_key'] ?? '');
            if ($secretKey === '') {
                throw new AuthException(500, "Konfigurasi Turnstile tidak lengkap (secret key kosong).");
            }
            $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $result = turnstileSiteverify($secretKey, (string) ($turnstileToken ?? ''), $remoteIp);
            if (!$result['success']) {
                $msg = "Verifikasi keamanan gagal. Silakan coba lagi.";
                // turnstile_debug => true di config.php menampilkan penyebab teknisnya.
                if ($config['turnstile_debug'] ?? false) {
                    $msg .= ' [' . ($result['error'] !== '' ? $result['error'] : 'tanpa detail') . ']';
                }
                throw new AuthException(400, $msg);
            }
        }

        // Batasi percobaan login (anti brute force) SEBELUM menyentuh password.
        self::guardLoginAttempts($db, $username);

        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Pesan SENGAJA sama untuk "akun tidak ada", "akun nonaktif", dan
        // "password salah" supaya tidak bisa dipakai menebak username terdaftar.
        $invalid = new AuthException(401, "Username atau password salah.");
        if (!$user || (int) $user['is_active'] === 0) {
            self::recordLoginAttempt($db, $username, false);
            throw $invalid;
        }
        if (!password_verify($password, $user['password'])) {
            self::recordLoginAttempt($db, $username, false);
            throw $invalid;
        }

        // Hash bcrypt dengan cost lama di-upgrade diam-diam saat login berhasil.
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
            try {
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")
                   ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
            } catch (Throwable $e) {
                // Gagal upgrade bukan alasan menolak login.
            }
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 86400); // +24 jam, dihitung di PHP (portabel)
        // Yang disimpan adalah HASH token. Kalau isi tabel auth_tokens bocor
        // (mis. lewat dump database), nilainya tidak bisa dipakai sebagai sesi.
        $db->prepare("INSERT INTO auth_tokens (token, user_id, expires_at) VALUES (?, ?, ?)")
           ->execute([self::hashToken($token), $user['id'], $expires]);

        self::recordLoginAttempt($db, $username, true);
        self::pruneExpiredTokens($db);

        return [
            'token' => $token,
            'user'  => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'name'     => $user['name'],
                'role'     => $user['role'],
                'avatar'   => $user['avatar'],
            ],
        ];
    }

    public static function logout(PDO $db, string $token): void
    {
        $db->prepare("DELETE FROM auth_tokens WHERE token = ?")->execute([self::hashToken($token)]);
    }

    // Baca Bearer token, join users, cek belum kedaluwarsa. Gagal = 401.
    // $authHeader bisa disuntik untuk test; null = ambil dari $_SERVER.
    public static function requireAuth(PDO $db, ?string $authHeader = null): array
    {
        if ($authHeader === null) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }
        $parts = explode(' ', trim($authHeader));
        $token = (count($parts) === 2 && strtolower($parts[0]) === 'bearer') ? $parts[1] : '';

        $expired = new AuthException(401, "Sesi anda telah berakhir, silahkan login kembali.");
        if ($token === '') {
            throw $expired;
        }

        // u.is_active = 1: user yang dinonaktifkan langsung kehilangan akses,
        // tidak perlu menunggu tokennya kedaluwarsa.
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.name, u.role, u.avatar, t.expires_at
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND u.is_active = 1"
        );
        $stmt->execute([self::hashToken($token)]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at']) <= time()) {
            throw $expired;
        }
        unset($row['expires_at']);
        return $row;
    }

    // ---------- token & pembatasan login ----------

    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 menit

    // Token disimpan sebagai hash SHA-256 (64 hex char, cocok dengan VARCHAR(64)).
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '-'), 0, 64);
    }

    // Lempar 429 bila kegagalan login untuk username+IP ini sudah menumpuk.
    // Tabel login_attempts dibuat oleh migrations/2026_09_21_add_login_attempts.sql;
    // bila belum ada, pembatasan dilewati supaya login tidak ikut rusak.
    private static function guardLoginAttempts(PDO $db, string $username): void
    {
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) c FROM login_attempts
                 WHERE username = ? AND ip_address = ? AND success = 0 AND created_at > ?"
            );
            $stmt->execute([
                $username,
                self::clientIp(),
                date('Y-m-d H:i:s', time() - self::LOGIN_WINDOW_SECONDS),
            ]);
            $failed = (int) ($stmt->fetch()['c'] ?? 0);
        } catch (Throwable $e) {
            return; // tabel belum ada -> jangan blokir login
        }
        if ($failed >= self::MAX_LOGIN_ATTEMPTS) {
            throw new AuthException(429, "Terlalu banyak percobaan login. Silakan coba lagi dalam 15 menit.");
        }
    }

    // Catat kegagalan; login berhasil mereset penghitung username+IP ini.
    // Best-effort: kegagalan mencatat tidak boleh menggagalkan proses login.
    private static function recordLoginAttempt(PDO $db, string $username, bool $success): void
    {
        try {
            if ($success) {
                $db->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0")
                   ->execute([$username, self::clientIp()]);
                return;
            }
            // created_at diisi dari jam PHP (bukan DEFAULT CURRENT_TIMESTAMP)
            // supaya penulisan dan pembacaan jendela waktu memakai jam yang sama.
            $db->prepare("INSERT INTO login_attempts (id, username, ip_address, success, created_at) VALUES (?, ?, ?, 0, ?)")
               ->execute([Db::generateId(), $username, self::clientIp(), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // sengaja diabaikan
        }
    }

    // Buang token kedaluwarsa supaya tabel tidak tumbuh tanpa batas.
    private static function pruneExpiredTokens(PDO $db): void
    {
        try {
            $db->prepare("DELETE FROM auth_tokens WHERE expires_at IS NOT NULL AND expires_at < ?")
               ->execute([date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // sengaja diabaikan
        }
    }

    public static function requireRole(array $currentUser, array $roles): void
    {
        if (!in_array($currentUser['role'] ?? null, $roles, true)) {
            throw new AuthException(403, "Anda tidak memiliki izin untuk melakukan aksi ini.");
        }
    }
}
