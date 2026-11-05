<?php
declare(strict_types=1);
/**
 * Verificador de ambiente e banco (rode após o deploy e após cada atualização): php bin/check.php
 * Confere versão/extensões do PHP, pastas graváveis, .env, conexão, migrações, tabelas e colunas esperadas, e o proprietário.
 * Não altera nada. Sai com código 1 se algo essencial falhar.
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';

$fail = 0;
$say = function (bool $ok, string $msg, bool $essential = true) use (&$fail) { echo ($ok ? '  ok   ' : ($essential ? '  FALHA ' : '  aviso ')) . $msg . "\n"; if (!$ok && $essential) $fail++; };

echo "PHP\n";
$say(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP ' . PHP_VERSION . ' (precisa ≥ 8.2)');
foreach (['pdo', 'mbstring', 'gd', 'fileinfo', 'openssl', 'json'] as $x) $say(extension_loaded($x), "extensão $x");
$drv = env('DB_DRIVER', 'sqlite');
$say(extension_loaded(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'][$drv] ?? 'pdo_sqlite'), 'driver PDO ' . $drv);

echo "Configuração\n";
$say(is_file(APP_ROOT . '/.env'), '.env presente');
$say(env('APP_ENV') === 'production', 'APP_ENV=production (atual: ' . env('APP_ENV', 'vazio') . ')', false);
$say(str_starts_with((string)env('APP_URL'), 'https://'), 'APP_URL com https (atual: ' . env('APP_URL', 'vazio') . ')', false);
foreach (['storage', 'storage/logs', 'public/uploads'] as $d) { @mkdir(APP_ROOT . "/$d", 0775, true); $say(is_writable(APP_ROOT . "/$d"), "pasta gravável: $d"); }

$md = env('MAIL_DRIVER', 'log');
$say($md !== 'log' || env('APP_ENV') !== 'production', 'e-mail (recuperação de senha): MAIL_DRIVER=' . $md . ($md === 'log' ? ' — em produção os e-mails NÃO seriam enviados; use mail ou smtp' : ''), false);
$say(is_file(APP_ROOT . '/public/favicon.ico'), 'favicon.ico presente', false);

echo "Banco\n";
try {
    $pdo = Db::pdo(); $say(true, 'conexão (' . Db::$driver . ')');
    if (Db::$driver === 'pgsql') $say(true, 'servidor ' . Db::val('SHOW server_version') . ' (PostgreSQL, TLS ' . env('DB_SSLMODE', 'require') . ')');
    if (Db::$driver === 'mysql') {
        $say(true, 'servidor ' . Db::val('SELECT VERSION()'));
        $cs = Db::val('SELECT @@character_set_database'); $say(str_starts_with((string)$cs, 'utf8'), "charset do banco: $cs", false);
    }
    $applied = array_column(Db::all('SELECT name FROM schema_migrations'), 'name');
    $files = array_map('basename', glob(APP_ROOT . '/migrations/*.sql'));
    $missing = array_diff($files, $applied);
    $say(!$missing, $missing ? 'migrações pendentes: ' . implode(', ', $missing) . ' (rode php bin/migrate.php)' : 'migrações aplicadas: ' . count($applied));
    $expected = ['users' => ['role', 'session_version', 'perms'], 'orders' => ['origin', 'tab_id', 'version'], 'tabs' => ['open_key', 'idempotency_key'], 'visits' => ['idempotency_key'], 'tab_payments' => ['status'], 'cash_shifts' => ['open_key'], 'categories' => ['sector'], 'print_jobs' => ['uuid'], 'dining_tables' => ['number']];
    foreach ($expected as $t => $cols) {
        try { $row = Db::one("SELECT * FROM $t LIMIT 1"); $have = $row ? array_keys($row) : (Db::$driver === 'pgsql' ? array_column(Db::all('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$t]), 'column_name') : array_column(Db::all(Db::$driver === 'mysql' ? "SHOW COLUMNS FROM $t" : "PRAGMA table_info($t)"), Db::$driver === 'mysql' ? 'Field' : 'name')); }
        catch (Throwable $e) { $say(false, "tabela $t ausente"); continue; }
        $say(!array_diff($cols, $have), "tabela $t" . (array_diff($cols, $have) ? ' sem colunas: ' . implode(',', array_diff($cols, $have)) : ''));
    }
    $owners = (int)Db::val("SELECT COUNT(*) FROM users WHERE role = 'owner' AND active = 1");
    $say($owners >= 1, "proprietário ativo: $owners (crie com php bin/create-admin.php email \"Nome\")");
    $say((int)Db::val('SELECT COUNT(*) FROM categories') > 0, 'cardápio carregado', false);
    $say((int)Db::val('SELECT COUNT(*) FROM dining_tables WHERE active = 1') > 0, 'mesas do salão cadastradas (Configurações → Salão)', false);
    $say((int)Db::val('SELECT COUNT(*) FROM delivery_zones WHERE active = 1') > 0, 'bairros de entrega cadastrados', false);
} catch (Throwable $e) { $say(false, 'banco: ' . $e->getMessage()); }

echo $fail ? "\n$fail verificação(ões) essencial(is) falharam.\n" : "\nAmbiente OK.\n";
exit($fail ? 1 : 0);
