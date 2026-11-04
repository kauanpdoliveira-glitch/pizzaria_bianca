<?php
/** Roteador SÓ DE TESTE: serve o dublê do QZ no lugar do qz-tray.js; todo o resto vai para a aplicação.
 *  php -S 127.0.0.1:8096 -t public tests/e2e/qz-stub-router.php */
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/assets/vendor/qz-tray.js') { header('Content-Type: application/javascript'); readfile(__DIR__ . '/qz-stub.js'); return true; }
$f = __DIR__ . '/../../public' . $p;
if (is_file($f) && !str_ends_with($f, '.php')) return false;   // arquivos estáticos direto do servidor embutido
require __DIR__ . '/../../public/index.php';
