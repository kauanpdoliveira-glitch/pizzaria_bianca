<?php
declare(strict_types=1);
/** Cria/remove um esquema DESCARTÁVEL (bianca_test_*) no PostgreSQL para rodar a suíte sem tocar em dados reais.
 *  php tests/pg/schema.php create|drop bianca_test_xxxx   (credenciais por variáveis DB_*; nunca em argumentos) */
[$_, $act, $schema] = $argv + [null, null, null];
if (!in_array($act, ['create', 'drop'], true) || !preg_match('/^bianca_test_[a-z0-9]+$/', (string)$schema)) exit("Uso: schema.php create|drop bianca_test_xxxx\n");
$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'postgres') . ';sslmode=require;connect_timeout=10';
$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($act === 'create') { $pdo->exec("CREATE SCHEMA \"$schema\""); echo "esquema $schema criado (PostgreSQL " . $pdo->query('SHOW server_version')->fetchColumn() . ")\n"; }
else { $pdo->exec("DROP SCHEMA IF EXISTS \"$schema\" CASCADE"); echo "esquema $schema removido\n"; }
