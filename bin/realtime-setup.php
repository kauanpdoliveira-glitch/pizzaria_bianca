<?php
declare(strict_types=1);
/**
 * Prepara o PostgreSQL (Supabase) para o canal PRIVADO de avisos do painel. Idempotente; só mexe em políticas de realtime.messages.
 *  - garante RLS ligada em realtime.messages;
 *  - cria a política que deixa APENAS o usuário técnico do painel LER o tópico "orders-ping" (broadcast) — nenhuma tabela do app é exposta;
 *  - confere que todas as tabelas de public têm RLS ligada (sem políticas = anon/authenticated não leem nada).
 * Credenciais do banco por variáveis DB_* (nunca em argumentos). Uso: php -d extension=pdo_pgsql bin/realtime-setup.php [--check]
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$p = new PDO('pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'postgres') . ';sslmode=require;connect_timeout=10', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = in_array('--check', $argv, true);
$email = 'realtime@pizzariabianca.vercel.app'; $name = 'bianca_orders_ping_read';
$off = $p->query("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity")->fetchAll(PDO::FETCH_COLUMN);
echo $off ? 'ATENÇÃO: tabelas sem RLS: ' . implode(', ', $off) . "\n" : "ok: todas as tabelas de public têm RLS ligada\n";
if ($off && !$check) foreach ($off as $t) { $p->exec('ALTER TABLE public."' . $t . '" ENABLE ROW LEVEL SECURITY'); echo "RLS ligada em $t\n"; }
$has = (int)$p->query("select count(*) from pg_policies where schemaname = 'realtime' and tablename = 'messages' and policyname = '$name'")->fetchColumn();
echo $has ? "ok: política $name já existe\n" : "política $name ausente\n";
if (!$has && !$check) {
    $p->exec("CREATE POLICY $name ON realtime.messages FOR SELECT TO authenticated USING (realtime.messages.extension = 'broadcast' AND (SELECT realtime.topic()) = 'orders-ping' AND (SELECT auth.jwt() ->> 'email') = '$email')");
    echo "política $name criada\n";
}
echo "políticas em realtime.messages: " . implode(', ', $p->query("select policyname from pg_policies where schemaname = 'realtime' and tablename = 'messages'")->fetchAll(PDO::FETCH_COLUMN)) . "\n";
