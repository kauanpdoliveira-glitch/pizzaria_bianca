<?php
declare(strict_types=1);
/**
 * Compara o banco LOCAL (SQLite) com o PostgreSQL (Supabase) depois da migração: contagens por tabela, totais financeiros, contas (hashes iguais,
 * sem imprimir hashes), estados da fila e sequências. Somente leitura. Credenciais do PostgreSQL por variáveis DB_* (nunca em argumentos).
 *   php -d extension=pdo_pgsql bin/validar-migracao.php caminho\do\banco.sqlite
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$sqlite = $argv[1] ?? ''; if (!is_file($sqlite)) exit("Uso: validar-migracao.php banco.sqlite\n");
$a = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$b = new PDO('pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'postgres') . ';sslmode=require;connect_timeout=10', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$skip = ['schema_migrations', 'login_attempts', 'rate_limits', 'password_resets', 'app_sessions'];
$bad = 0; $ok = function (string $n, bool $c, string $d = '') use (&$bad) { if (!$c) $bad++; echo ($c ? '  ok   ' : '  FALHA ') . $n . ($d !== '' ? " — $d" : '') . "\n"; };
$tables = array_column($a->query("select name from sqlite_master where type='table' and name not like 'sqlite_%' order by 1")->fetchAll(), 'name');
$tot = 0;
foreach ($tables as $t) { if (in_array($t, $skip, true)) continue; $x = (int)$a->query("select count(*) from $t")->fetchColumn(); $y = (int)$b->query("select count(*) from $t")->fetchColumn(); $tot += $y; if ($x !== $y) $ok("contagem $t", false, "local $x, supabase $y"); }
$ok('contagens iguais em todas as tabelas migradas', $bad === 0, "$tot linhas no Supabase");
foreach (['orders' => 'total_cents', 'orders ' => 'subtotal_cents', 'order_items' => 'total_cents', 'tab_payments' => 'amount_cents'] as $t => $c) { $t = trim($t); $x = (int)$a->query("select coalesce(sum($c),0) from $t")->fetchColumn(); $y = (int)$b->query("select coalesce(sum($c),0) from $t")->fetchColumn(); $ok("soma $t.$c", $x === $y, "$x centavos"); }
$ua = $a->query('select id,email,role,active,password_hash,activated_at,perms from users order by id')->fetchAll(); $ub = $b->query('select id,email,role,active,password_hash,activated_at,perms from users order by id')->fetchAll();
$same = count($ua) === count($ub); foreach ($ua as $i => $r) foreach ($r as $k => $v) if (($ub[$i][$k] ?? null) != $v) $same = false;
$ok('usuários idênticos (ids, e-mails, perfis, ativação, permissões e hashes de senha)', $same, count($ua) . ' contas');
$ow = $b->query("select email, active from users where role='owner' and email='kauango.dev@gmail.com'")->fetch(); $ok('proprietário kauango.dev@gmail.com presente e ativo', $ow && (int)$ow['active'] === 1);
$ja = $a->query('select status,count(*) n from print_jobs group by 1 order by 1')->fetchAll(PDO::FETCH_KEY_PAIR); $jb = $b->query('select status,count(*) n from print_jobs group by 1 order by 1')->fetchAll(PDO::FETCH_KEY_PAIR);
$ok('estados da fila preservados (nada virou pendente)', $ja == $jb, json_encode($jb));
$ok('nenhum trabalho pendente/reservado/enviando no Supabase', (int)$b->query("select count(*) from print_jobs where status in ('queued','reserved','sending','printing')")->fetchColumn() === 0);
$ma = $a->query('select name from schema_migrations order by 1')->fetchAll(PDO::FETCH_COLUMN); $mb = $b->query('select name from schema_migrations order by 1')->fetchAll(PDO::FETCH_COLUMN); $ok('migrações versionadas aplicadas', $ma == $mb, count($mb) . ' arquivos');
foreach (['orders', 'order_items', 'print_jobs', 'users', 'products', 'print_layouts', 'tabs', 'visits', 'customers', 'audit_log', 'print_stations'] as $t) { $m = (int)$b->query("select coalesce(max(id),0) from $t")->fetchColumn(); $seq = $b->query("select pg_get_serial_sequence('$t','id')")->fetchColumn(); $s = $seq ? (int)$b->query("select last_value from $seq")->fetchColumn() : -1; $ok("sequência de $t ($seq) ≥ maior id", $s >= $m, "max id $m, sequência $s"); }
$fk = (int)$b->query("select count(*) from orders o left join order_items i on i.order_id=o.id where false")->fetchColumn(); $orf = (int)$b->query('select count(*) from order_items i left join orders o on o.id=i.order_id where o.id is null')->fetchColumn(); $ok('itens sem pedido (órfãos)', $orf === 0);
echo $bad ? "\n$bad verificação(ões) FALHARAM.\n" : "\nMigração validada.\n"; exit($bad ? 1 : 0);
