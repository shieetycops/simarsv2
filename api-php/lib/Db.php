<?php
// Wrapper PDO minimal + generator ID + helper query & konversi key.
class Db
{
    public static PDO $pdo;

    // ID acak 32-char hex, muat di VARCHAR(36).
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    // Prepared query pendek.
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    // Ambil banyak baris sebagai camelCase (kontrak JSON = sama seperti Prisma).
    public static function all(string $sql, array $params = []): array
    {
        return array_map([self::class, 'camel'], self::q($sql, $params)->fetchAll());
    }

    // Ambil satu baris camelCase, atau null.
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row ? self::camel($row) : null;
    }

    // snake_case -> camelCase rekursif (kolom DB -> field JSON frontend).
    public static function camel($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            $nk = is_string($k)
                ? preg_replace_callback('/_([a-z])/', fn($m) => strtoupper($m[1]), $k)
                : $k;
            $out[$nk] = is_array($v) ? self::camel($v) : $v;
        }
        return $out;
    }
}
