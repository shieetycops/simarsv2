<?php
// Front controller. Segmen pertama setelah /api = resource, dispatch ke
// lib/handlers/<resource>.php. Handler pakai $segments, $method, Db::$pdo.
require __DIR__ . '/bootstrap.php';

$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path     = preg_replace('#^/api#', '', rtrim($path, '/'));
$segments = array_values(array_filter(explode('/', $path), 'strlen'));
$method   = $_SERVER['REQUEST_METHOD'];
$resource = $segments[0] ?? '';

$handler = __DIR__ . "/lib/handlers/$resource.php";

try {
    if ($resource === '' || !is_file($handler)) {
        http_response_code(404);
        echo json_encode(['message' => 'Not found']);
        return;
    }
    require $handler;
} catch (AuthException $e) {
    http_response_code($e->status);
    echo json_encode(['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[SIMARS] ' . $method . ' ' . $path . ' -> ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    $message = 'Internal server error';
    // 'debug' => true di config.php menyertakan detail error agar mudah dilacak.
    // WAJIB dimatikan lagi setelah troubleshooting selesai.
    if (!empty($config['debug'])) {
        $message .= ': ' . get_class($e) . ' - ' . $e->getMessage()
                  . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    echo json_encode(['message' => $message]);
}
