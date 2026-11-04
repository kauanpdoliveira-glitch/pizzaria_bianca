<?php
/**
 * SOMENTE PARA MEDIÇÃO LOCAL: roteador do servidor embutido do PHP que SIMULA compressão HTTP (gzip) para HTML/CSS/JS/TTF,
 * já que o servidor embutido não comprime. Não comprova que a Hostinger/Apache entregará comprimido (isso se verifica por cabeçalhos no servidor real).
 * Uso: php -S 127.0.0.1:8096 -t public tests/e2e/gzip-router.php
 */
$root = $_SERVER['DOCUMENT_ROOT']; $path = urldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); $f = $root . $path;
if ($path !== '/' && is_file($f)) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'ttf' => 'font/ttf', 'svg' => 'image/svg+xml', 'json' => 'application/json'];
    if (isset($types[$ext]) && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
        header('Content-Type: ' . $types[$ext]); header('Content-Encoding: gzip'); header('Vary: Accept-Encoding');
        echo gzencode((string)file_get_contents($f), 6); return true;
    }
    return false; // imagens e woff (já comprimidos) seguem como estão
}
if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) { ob_start(function ($b) { header('Content-Encoding: gzip'); header('Vary: Accept-Encoding'); return gzencode($b, 6); }); }
$_SERVER['SCRIPT_NAME'] = '/index.php'; require $root . '/index.php';
