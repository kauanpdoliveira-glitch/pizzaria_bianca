<?php
declare(strict_types=1);
/** Restaura um backup JSON em banco VAZIO ou substitui o conteúdo atual (peça --force). Uso: php bin/restore.php arquivo.json [--force]
 *  Funciona entre SQLite, MySQL e PostgreSQL (imagens em base64; sequências do PostgreSQL são acertadas no fim). */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
$file = $argv[1] ?? null;
if (!$file || !is_file($file)) exit("Uso: php bin/restore.php arquivo.json [--force]\n");
$d = json_decode(file_get_contents($file), true);
if (!isset($d['tables'])) exit("Arquivo inválido.\n");
Db::migrate();
if (Db::val('SELECT COUNT(*) FROM orders') > 0 && !in_array('--force', $argv, true)) exit("O banco já tem pedidos. Use --force para SUBSTITUIR tudo.\n");
$pdo = Db::pdo(); $drv = Db::$driver;
$tables = array_keys($d['tables']);
if ($drv === 'pgsql') {   // sem desligar FKs: ordena pais antes dos filhos
    $edges = Db::all("SELECT cl.relname AS child, cf.relname AS parent FROM pg_constraint c JOIN pg_class cl ON cl.oid = c.conrelid JOIN pg_class cf ON cf.oid = c.confrelid WHERE c.contype = 'f' AND cl.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())");
    $order = []; $seen = [];
    $visit = function (string $t) use (&$visit, &$order, &$seen, $edges, $tables) { if (isset($seen[$t])) return; $seen[$t] = 1; foreach ($edges as $e) if ($e['child'] === $t && $e['parent'] !== $t && in_array($e['parent'], $tables, true)) $visit($e['parent']); $order[] = $t; };
    foreach ($tables as $t) $visit($t);
    $tables = $order;
} elseif ($drv === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); else $pdo->exec('PRAGMA foreign_keys=OFF');
$pdo->beginTransaction();
try {
    foreach (array_reverse($tables) as $t) $pdo->exec("DELETE FROM $t");
    foreach ($tables as $t) foreach ($d['tables'][$t] as $r) {
        foreach ($r as $k => $v) if (is_array($v) && isset($v['__b64'])) $r[$k] = ($drv === 'pgsql') ? $v['__b64'] : base64_decode($v['__b64']);   // PostgreSQL guarda imagens como base64 (TEXT)
        if ($drv === 'pgsql' && $t === 'uploaded_files' && isset($r['data']) && is_string($r['data'])) { /* já é base64 (de PostgreSQL) ou veio do marcador acima */ }
        Db::insert($t, $r);
    }
    if ($drv === 'pgsql') foreach ($tables as $t) if ((bool)Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'id' AND column_default IS NOT NULL", [$t]))
        Db::val("SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM $t), 1), (SELECT MAX(id) FROM $t) IS NOT NULL)", [$t]);   // novas linhas não colidem com ids restaurados
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); exit('Falhou e nada foi alterado: ' . $e->getMessage() . "\n"); }
if ($drv === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); elseif ($drv === 'sqlite') $pdo->exec('PRAGMA foreign_keys=ON');
echo 'Restaurado: ' . array_sum(array_map('count', $d['tables'])) . " linhas.\n";
