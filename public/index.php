<?php
declare(strict_types=1);

// Em hospedagem com public_html, mantenha a pasta da aplicação fora dela e ajuste o caminho (ver docs/DEPLOY-HOSTMIA.md).
$root = is_file(__DIR__ . '/../src/bootstrap.php') ? __DIR__ . '/..' : __DIR__ . '/../bianca-app';
require $root . '/src/bootstrap.php';

// Servidor embutido do PHP: serve arquivos estáticos diretamente
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && !str_ends_with($f, '.php')) return false;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (is_https()) header('Strict-Transport-Security: max-age=31536000');

try {
    App::run();
} catch (HttpError $e) {
    $api = str_contains($_SERVER['REQUEST_URI'], '/api/');
    if ($api) json_out(['error' => $e->getMessage()] + $e->extra, $e->status);
    http_response_code($e->status);
    View::render('error', ['code' => $e->status, 'msg' => $e->getMessage(), 'title' => 'Ops', 'store' => Settings::all()]);
} catch (Throwable $e) {
    app_log('ERRO ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $api = str_contains($_SERVER['REQUEST_URI'], '/api/');
    if ($api) json_out(['error' => 'Erro interno. Tente novamente.'], 500);
    http_response_code(500);
    View::render('error', ['code' => 500, 'msg' => 'Algo deu errado. Tente novamente em instantes.', 'title' => 'Erro', 'store' => Settings::all()]);
}
