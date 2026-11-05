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

// Hospedagem serverless (Vercel) SEM banco externo configurado: resposta honesta em vez de erro em branco.
// SQLite em disco efêmero nunca é usado como banco definitivo; configure DB_DRIVER=mysql + DB_* (ver docs/VERCEL.md).
if (env('VERCEL') && env('DB_DRIVER', 'sqlite') !== 'mysql') {
    http_response_code(503); header('Retry-After: 3600'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
    if (str_starts_with((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/saude')) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['ok' => false, 'reason' => 'db_not_configured'])); }
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Homologação — Pizzaria Bianca</title><body style="font:16px system-ui;max-width:34rem;margin:12vh auto;padding:0 1rem"><h1>HOMOLOGAÇÃO — testes da Bianca</h1><p>Este ambiente ainda não tem o <b>banco de dados externo</b> configurado, então não recebe pedidos nem mostra o cardápio.</p><p>Nenhum dado foi perdido. O ambiente de testes em uso é informado pela equipe.</p></body></html>');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (is_https()) header('Strict-Transport-Security: max-age=31536000');
if (env('APP_ENV') === 'staging') header('X-Robots-Tag: noindex, nofollow');

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
    try { View::render('error', ['code' => 500, 'msg' => 'Algo deu errado. Tente novamente em instantes.', 'title' => 'Erro', 'store' => Settings::all()]); }
    catch (Throwable $e2) { header('Content-Type: text/plain; charset=utf-8'); echo 'Erro interno. Tente novamente em instantes.'; }   // nem a página de erro depende do banco
}
