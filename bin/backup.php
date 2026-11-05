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
Db::pdo();
$tables = Db::$driver === 'mysql' ? array_map('current', Db::all('SHOW TABLES')) : (Db::$driver === 'pgsql' ? array_column(Db::all("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"), 'name') : array_column(Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name'));
$tables = array_values(array_diff($tables, ['schema_migrations', 'login_attempts', 'rate_limits', 'password_resets', 'app_sessions'])); // efêmero ou sensível (tokens)
$data = ['version' => 1, 'created_at' => now()];
foreach ($tables as $t) {   // colunas binárias (imagens no banco) vão em base64 para o JSON não quebrar
    $rows = Db::all("SELECT * FROM $t");
    foreach ($rows as &$r) foreach ($r as $k => &$v) if (is_resource($v)) $v = ['__b64' => base64_encode(stream_get_contents($v))]; elseif (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $v = ['__b64' => base64_encode($v)];
    unset($r, $v); $data['tables'][$t] = $rows;
}
$file = $dir . '/bianca-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
@chmod($file, 0600);
echo "Backup salvo em $file (" . round(filesize($file) / 1024) . " KB). Copie também public/uploads/.\n";
