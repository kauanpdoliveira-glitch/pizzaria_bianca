<?php
declare(strict_types=1);
/** Restaura um backup JSON em banco VAZIO ou substitui o conteúdo atual (peça --force). Uso: php bin/restore.php arquivo.json [--force] */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
$file = $argv[1] ?? null;
if (!$file || !is_file($file)) exit("Uso: php bin/restore.php arquivo.json [--force]\n");
$d = json_decode(file_get_contents($file), true);
if (!isset($d['tables'])) exit("Arquivo inválido.\n");
Db::migrate();
if (Db::val('SELECT COUNT(*) FROM orders') > 0 && !in_array('--force', $argv, true)) exit("O banco já tem pedidos. Use --force para SUBSTITUIR tudo.\n");
$pdo = Db::pdo();
Db::$driver === 'mysql' ? $pdo->exec('SET FOREIGN_KEY_CHECKS=0') : $pdo->exec('PRAGMA foreign_keys=OFF');
$pdo->beginTransaction();
try {
    foreach (array_reverse(array_keys($d['tables'])) as $t) $pdo->exec("DELETE FROM $t");
    foreach ($d['tables'] as $t => $rows) foreach ($rows as $r) { foreach ($r as $k => $v) if (is_array($v) && isset($v['__b64'])) $r[$k] = base64_decode($v['__b64']); Db::insert($t, $r); }
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); exit('Falhou e nada foi alterado: ' . $e->getMessage() . "\n"); }
Db::$driver === 'mysql' ? $pdo->exec('SET FOREIGN_KEY_CHECKS=1') : $pdo->exec('PRAGMA foreign_keys=ON');
echo 'Restaurado: ' . array_sum(array_map('count', $d['tables'])) . " linhas.\n";
