<?php
// POST /api/auth/login, POST /api/auth/logout
// Router menyediakan $segments, $method, Db::$pdo. AuthException ditangkap router.

$action = $segments[1] ?? '';
// bootstrap.php sudah memuat config.php ke scope ini; require ulang hanya sebagai jaring pengaman.
$config = $config ?? require dirname(__DIR__, 2) . '/config.php';

if ($method === 'POST' && $action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    echo json_encode(Auth::login(
        Db::$pdo,
        $body['username'] ?? '',
        $body['password'] ?? '',
        $body['turnstile_token'] ?? null,
        $config
    ));
    return;
}

if ($method === 'POST' && $action === 'logout') {
    $parts = explode(' ', trim($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (count($parts) === 2) {
        Auth::logout(Db::$pdo, $parts[1]);
    }
    echo json_encode(['message' => 'Berhasil keluar.']);
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
