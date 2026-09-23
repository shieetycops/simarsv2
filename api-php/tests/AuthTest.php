<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Auth.php';

final class AuthTest extends TestCase
{
    // SQLite in-memory: cukup users + auth_tokens, tanpa server MySQL.
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE users (id TEXT PRIMARY KEY, username TEXT, password TEXT,
            name TEXT, role TEXT, avatar TEXT, is_active INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE auth_tokens (token TEXT PRIMARY KEY, user_id TEXT, expires_at TEXT)");
        // Tabel dari migrations/2026_09_21_add_login_attempts.sql: Auth::login
        // mencatat kegagalan login di sini. Tanpa tabel ini jalur pembatasan
        // brute force dilewati diam-diam, jadi fixture harus memuatnya agar
        // tesnya benar-benar menguji kode produksi.
        $db->exec("CREATE TABLE login_attempts (id TEXT PRIMARY KEY, username TEXT,
            ip_address TEXT, success INTEGER DEFAULT 0, created_at TEXT)");
        $db->exec("INSERT INTO users VALUES ('u1','budi','x','Budi','STAFF',NULL,1)");
        return $db;
    }

    private function insertToken(PDO $db, string $token, int $offsetSeconds): void
    {
        // Auth menyimpan HASH token, jadi fixture juga harus menyimpan hash-nya.
        $db->prepare("INSERT INTO auth_tokens VALUES (?, 'u1', ?)")
           ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + $offsetSeconds)]);
    }

    /** requireRole: role vs allowed-roles, lolos/ditolak. */
    public function testRequireRoleTable(): void
    {
        $cases = [
            // [userRole, allowedRoles, bolehLolos]
            ['ADMIN',      ['ADMIN'],            true],
            ['ADMIN',      ['ADMIN', 'STAFF'],   true],
            ['STAFF',      ['ADMIN', 'STAFF'],   true],
            ['STAFF',      ['ADMIN'],            false],
            ['STAFF',      [],                   false],
            ['PIMPINAN',   ['ADMIN', 'STAFF'],   false],
        ];

        foreach ($cases as [$role, $allowed, $shouldPass]) {
            $label = "$role vs " . json_encode($allowed);
            if ($shouldPass) {
                Auth::requireRole(['role' => $role], $allowed);
                $this->addToAssertionCount(1); // tidak melempar = lolos
            } else {
                try {
                    Auth::requireRole(['role' => $role], $allowed);
                    $this->fail("Harus ditolak: $label");
                } catch (AuthException $e) {
                    $this->assertSame(403, $e->status, $label);
                    $this->assertSame("Anda tidak memiliki izin untuk melakukan aksi ini.", $e->getMessage());
                }
            }
        }
    }

    public function testValidToken(): void
    {
        $db = $this->db();
        $this->insertToken($db, 'goodtoken', 3600);
        $user = Auth::requireAuth($db, 'Bearer goodtoken');
        $this->assertSame('u1', $user['id']);
        $this->assertSame('budi', $user['username']);
        $this->assertArrayNotHasKey('expires_at', $user);
    }

    public function testExpiredToken(): void
    {
        $db = $this->db();
        $this->insertToken($db, 'oldtoken', -10);
        $this->assertUnauthorized($db, 'Bearer oldtoken');
    }

    public function testMissingToken(): void
    {
        $this->assertUnauthorized($this->db(), '');
    }

    public function testBadFormatToken(): void
    {
        $db = $this->db();
        $this->insertToken($db, 'goodtoken', 3600);
        // Tanpa skema "Bearer" = ditolak walau token ada di DB.
        $this->assertUnauthorized($db, 'goodtoken');
        $this->assertUnauthorized($db, 'Token goodtoken');
    }

    private function assertUnauthorized(PDO $db, string $header): void
    {
        try {
            Auth::requireAuth($db, $header);
            $this->fail("Harus 401 untuk header: '$header'");
        } catch (AuthException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame("Sesi anda telah berakhir, silahkan login kembali.", $e->getMessage());
        }
    }
}
