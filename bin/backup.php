<?php
declare(strict_types=1);
/**
 * Backup lógico (JSON, independente do banco). Uso: php bin/backup.php [pasta-de-saída]
 * Inclui dados de clientes (pessoais): guarde fora da pasta pública, com acesso restrito, e apague cópias antigas (veja docs/BACKUP-LGPD.md).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
$dir = $argv[1] ?? APP_ROOT . '/storage/backups';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$tables = Db::$driver === 'mysql' ? array_map('current', Db::all('SHOW TABLES')) : array_column(Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
$tables = array_values(array_diff($tables, ['schema_migrations', 'login_attempts', 'rate_limits', 'password_resets'])); // efêmero ou sensível (tokens)
$data = ['version' => 1, 'created_at' => now()];
foreach ($tables as $t) $data['tables'][$t] = Db::all("SELECT * FROM $t");
$file = $dir . '/bianca-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
@chmod($file, 0600);
echo "Backup salvo em $file (" . round(filesize($file) / 1024) . " KB). Copie também public/uploads/.\n";
