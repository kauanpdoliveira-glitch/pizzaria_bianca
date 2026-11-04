<?php
/**
 * Prova de concorrência REAL no nível do banco: vários PROCESSOS PHP independentes (sessões/usuários distintos) disparam operações
 * ao mesmo tempo (barreira de tempo) contra a MESMA cópia do banco SQLite, usando as mesmas funções que as rotas HTTP chamam.
 * LIMITES: é o mesmo computador e o mesmo disco; não equivale a aparelhos, redes e latências reais. Em MySQL o isolamento é outro (FOR UPDATE).
 * Uso: DB_SQLITE_PATH=<cópia> php tests/e2e/concurrency-db.php
 */
ob_implicit_flush(true);
if (!getenv('DB_SQLITE_PATH') || str_contains((string)getenv('DB_SQLITE_PATH'), 'bianca.sqlite')) exit("Defina DB_SQLITE_PATH para uma CÓPIA do banco (nunca o original).\n");
require __DIR__ . '/../../src/bootstrap.php';
Db::migrate();
$owner = Db::one("SELECT * FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$sfx = bin2hex(random_bytes(3)); $U = [];
foreach (['m1' => 'manager', 'm2' => 'manager', 'w1' => 'waiter', 'w2' => 'waiter'] as $k => $role) $U[$k] = Team::create(['name' => "Conc $k", 'email' => "conc.$k.$sfx@teste.local", 'password' => 'Conc' . bin2hex(random_bytes(6)), 'role' => $role], $owner);
$rows = []; $pass = 0; $fail = 0;
function chk(string $n, bool $ok, string $d = ''): void { global $rows, $pass, $fail; $ok ? $pass++ : $fail++; $rows[] = [$ok ? 'APROVADO' : 'FALHOU', $n, $d]; echo ($ok ? 'APROVADO ' : 'FALHOU   ') . "$n  [$d]\n"; }
/** @param array<int,array> $jobs cada job: ['op'=>..., 'user'=>id, ...] */
function run(array $jobs): array
{
    $at = microtime(true) + 2.5; $procs = [];
    foreach ($jobs as $j) {
        $j['at'] = $at; $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', __DIR__ . '/concurrency-worker.php', json_encode($j)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) { $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); $out[] = json_decode($o, true) ?? ['ok' => false, 'erro' => 'sem saída: ' . substr($o, 0, 80)]; }
    return $out;
}
$k = fn() => bin2hex(random_bytes(12));
$freeTable = function () { foreach (Db::all('SELECT id FROM dining_tables WHERE active = 1 ORDER BY id') as $t) if (!Db::val("SELECT COUNT(*) FROM visits WHERE table_id = ? AND status IN ('active','awaiting_cashier')", [$t['id']])) return (int)$t['id']; throw new Exception('sem mesa livre'); };
$locked = fn(array $res) => count(array_filter($res, fn($r) => str_contains(json_encode($r), 'locked') || str_contains(json_encode($r), 'busy')));

// S1: dois garçons abrem a MESMA mesa livre ao mesmo tempo, sem escolha explícita
$t = $freeTable(); $r = run([['op' => 'open', 'user' => $U['w1'], 'key' => $k(), 'in' => ['table_id' => $t, 'name' => 'Grupo 1', 'people' => 2]], ['op' => 'open', 'user' => $U['w2'], 'key' => $k(), 'in' => ['table_id' => $t, 'name' => 'Grupo 2', 'people' => 3]]]);
$okc = count(array_filter($r, fn($x) => $x['ok'] ?? false)); $c409 = count(array_filter($r, fn($x) => ($x['http'] ?? 0) === 409));
chk('S1 mesa livre aberta por 2 garçons ao mesmo tempo: 1 vence, o outro recebe 409 (escolha explícita)', $okc === 1 && $c409 === 1 && (int)Db::val("SELECT COUNT(*) FROM visits WHERE table_id = ? AND status = 'active'", [$t]) === 1, json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : $x['erro']), $r)) . ' travado=' . $locked($r));

// S2: mesmo número físico de comanda em duas mesas ao mesmo tempo
$free = array_values(array_filter(array_column(Db::all("SELECT id FROM dining_tables WHERE active = 1 ORDER BY id"), 'id'), fn($id) => !Db::val("SELECT COUNT(*) FROM visits WHERE table_id = ? AND status IN ('active','awaiting_cashier')", [$id]))); [$t1, $t2] = [(int)$free[0], (int)$free[1]]; $num = 'C' . strtoupper(substr($sfx, 0, 4));
$r = run([['op' => 'open', 'user' => $U['w1'], 'key' => $k(), 'in' => ['table_id' => $t1, 'name' => 'A', 'people' => 1, 'physical_number' => $num]], ['op' => 'open', 'user' => $U['w2'], 'key' => $k(), 'in' => ['table_id' => $t2, 'name' => 'B', 'people' => 1, 'physical_number' => $num]]]);
chk('S2 mesmo nº de comanda física em 2 mesas simultâneas: só 1 abre, outro 409', count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 1 && count(array_filter($r, fn($x) => ($x['http'] ?? 0) === 409)) === 1 && (int)Db::val('SELECT COUNT(*) FROM tabs WHERE open_key = ?', [$num]) === 1, json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));
$tabsAntes = (int)Db::val('SELECT COUNT(*) FROM tabs');

// S3: clique repetido: 6 requisições simultâneas com a MESMA chave de idempotência
$t = $freeTable(); $key = $k(); $r = run(array_fill(0, 6, ['op' => 'open', 'user' => $U['w1'], 'key' => $key, 'in' => ['table_id' => $t, 'name' => 'Duplo clique', 'people' => 2]]));
$ids = array_unique(array_map(fn($x) => $x['r']['tab_id'] ?? 0, array_filter($r, fn($x) => $x['ok'] ?? false)));
chk('S3 6 envios simultâneos com a mesma chave: 1 comanda só e TODOS recebem a mesma resposta (sem 409 espúrio)', count($ids) === 1 && count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 6 && (int)Db::val('SELECT COUNT(*) FROM tabs WHERE idempotency_key = ?', [$key]) === 1, 'ok=' . count(array_filter($r, fn($x) => $x['ok'] ?? false)) . ' erros=' . json_encode(array_values(array_filter(array_map(fn($x) => $x['ok'] ? null : ($x['http'] ?? $x['erro']), $r)))));
$tabA = (int)current($ids);

// S3c: cliente clica duas vezes em 'Enviar pedido' (checkout): 6 envios simultâneos com a mesma chave
$ck = $k(); $cin = ['type' => 'pickup', 'name' => 'Duplo clique', 'phone' => '(31) 97777-0000', 'payment' => 'cash', 'items' => [['product_id' => (int)Db::val("SELECT id FROM products WHERE name = 'Calabresa'"), 'size_id' => (int)Db::val("SELECT ps.id FROM product_sizes ps JOIN products p ON p.id = ps.product_id WHERE p.name = 'Calabresa' AND ps.name = 'Grande'"), 'qty' => 1]]];
Settings::set('schedule', json_encode(array_fill(0, 7, [['00:00', '23:59']])));
$r = run(array_fill(0, 6, ['op' => 'create', 'user' => $U['m1'], 'key' => $ck, 'in' => $cin]));
$ids3 = array_unique(array_map(fn($x) => $x['r']['id'] ?? 0, array_filter($r, fn($x) => $x['ok'] ?? false)));
chk('S3c checkout: 6 envios simultâneos com a mesma chave = 1 pedido, todos recebem o mesmo número', count($ids3) === 1 && count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 6 && (int)Db::val('SELECT COUNT(*) FROM orders WHERE idempotency_key = ?', [$ck]) === 1, 'ok=' . count(array_filter($r, fn($x) => $x['ok'] ?? false)) . ' erros=' . json_encode(array_values(array_filter(array_map(fn($x) => $x['ok'] ? null : ($x['http'] ?? $x['erro']), $r)))));
// S4: dois operadores atualizam o MESMO pedido (mesma versão lida)
$cust = ['type' => 'pickup', 'name' => 'Cliente Conc', 'phone' => '(31) 98888-0000', 'payment' => 'cash', 'items' => [['product_id' => (int)Db::val("SELECT id FROM products WHERE name = 'Calabresa'"), 'size_id' => (int)Db::val("SELECT ps.id FROM product_sizes ps JOIN products p ON p.id = ps.product_id WHERE p.name = 'Calabresa' AND ps.name = 'Grande'"), 'qty' => 1]]];
Settings::set('schedule', json_encode(array_fill(0, 7, [['00:00', '23:59']])));
$o = Orders::create($cust, $k()); $oid = (int)$o['id']; $ver = (int)Db::val('SELECT version FROM orders WHERE id = ?', [$oid]);
$r = run([['op' => 'transition', 'user' => $U['m1'], 'id' => $oid, 'to' => 'accepted', 'version' => $ver], ['op' => 'transition', 'user' => $U['m2'], 'id' => $oid, 'to' => 'accepted', 'version' => $ver]]);
chk('S4 dois gerentes aceitam o mesmo pedido (mesma versão): 1 grava, o outro recebe 409', count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 1 && count(array_filter($r, fn($x) => ($x['http'] ?? 0) === 409)) === 1 && Db::val('SELECT status FROM orders WHERE id = ?', [$oid]) === 'accepted', json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));
$ver = (int)Db::val('SELECT version FROM orders WHERE id = ?', [$oid]);
// S5: status diferentes ao mesmo tempo (preparo x cancelamento)
$r = run([['op' => 'transition', 'user' => $U['m1'], 'id' => $oid, 'to' => 'preparing', 'version' => $ver], ['op' => 'transition', 'user' => $U['m2'], 'id' => $oid, 'to' => 'cancelled', 'version' => $ver, 'reason' => 'teste de concorrência']]);
$final = Db::val('SELECT status FROM orders WHERE id = ?', [$oid]);
chk('S5 "preparo" x "cancelar" simultâneos: exatamente 1 vence e o estado final é coerente', count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 1 && in_array($final, ['preparing', 'cancelled'], true), "final=$final " . json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));

// S6: pagamentos concorrentes na mesma comanda (valor = total, chaves diferentes) e fechamentos simultâneos
$itens = [['product_id' => (int)Db::val("SELECT id FROM products WHERE name = 'Calabresa'"), 'size_id' => (int)Db::val("SELECT ps.id FROM product_sizes ps JOIN products p ON p.id = ps.product_id WHERE p.name = 'Calabresa' AND ps.name = 'Grande'"), 'qty' => 1]];
$rk = $k(); $r = run(array_fill(0, 6, ['op' => 'round', 'user' => $U['w1'], 'tab' => $tabA, 'key' => $rk, 'items' => $itens])); $orders = array_unique(array_map(fn($x) => $x['r']['order_id'] ?? 0, array_filter($r, fn($x) => $x['ok'] ?? false)));
chk('S3b 6 envios simultâneos da MESMA rodada (mesma chave): 1 pedido de produção só, todos recebem a mesma resposta', count($orders) === 1 && count(array_filter($r, fn($x) => $x['ok'] ?? false)) === 6 && (int)Db::val('SELECT COUNT(*) FROM orders WHERE idempotency_key = ?', [$rk]) === 1, 'ok=' . count(array_filter($r, fn($x) => $x['ok'] ?? false)) . ' erros=' . json_encode(array_values(array_filter(array_map(fn($x) => $x['ok'] ? null : ($x['http'] ?? $x['erro']), $r)))));
$r = run([['op' => 'round', 'user' => $U['w1'], 'tab' => $tabA, 'key' => $k(), 'items' => $itens]]); $tot = Floor::totals($tabA); $total = (int)($tot['total'] ?? 0);
run([['op' => 'bill', 'user' => $U['w1'], 'tab' => $tabA]]);
$r = run([['op' => 'pay', 'user' => $U['m1'], 'tab' => $tabA, 'amount' => $total, 'key' => $k()], ['op' => 'pay', 'user' => $U['m2'], 'tab' => $tabA, 'amount' => $total, 'key' => $k()]]);
$conf = (int)Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM tab_payments WHERE tab_id = ? AND status = 'confirmed'", [$tabA]);
chk('S6 dois caixas registram o pagamento TOTAL ao mesmo tempo: o sistema não deve aceitar recebimento acima da conta', $conf <= $total, "total=$total confirmado=$conf respostas=" . json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));
$r = run([['op' => 'close', 'user' => $U['m1'], 'tab' => $tabA], ['op' => 'close', 'user' => $U['m2'], 'tab' => $tabA]]);
chk('S6b fechamento simultâneo da mesma comanda: termina fechada, sem erro de banco', Db::val('SELECT status FROM tabs WHERE id = ?', [$tabA]) === 'closed' && !count(array_filter($r, fn($x) => isset($x['erro']))), json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));

// S7: fechar o atendimento antigo enquanto o garçom abre outro grupo na mesma mesa
$t = $freeTable(); $r0 = run([['op' => 'open', 'user' => $U['w1'], 'key' => $k(), 'in' => ['table_id' => $t, 'name' => 'Antigo', 'people' => 2]]]); $oldTab = (int)$r0[0]['r']['tab_id'];
run([['op' => 'round', 'user' => $U['w1'], 'tab' => $oldTab, 'key' => $k(), 'items' => $itens]]); run([['op' => 'bill', 'user' => $U['w1'], 'tab' => $oldTab]]); $tt = Floor::totals($oldTab);
run([['op' => 'pay', 'user' => $U['m1'], 'tab' => $oldTab, 'amount' => (int)$tt['total'], 'key' => $k()]]);
$r = run([['op' => 'close', 'user' => $U['m1'], 'tab' => $oldTab], ['op' => 'open', 'user' => $U['w2'], 'key' => $k(), 'in' => ['table_id' => $t, 'name' => 'Novo grupo', 'people' => 4]]]);
$newTab = (int)Db::val("SELECT id FROM tabs WHERE table_id = ? AND name = 'Novo grupo'", [$t]);
chk('S7 fechar o antigo e abrir o novo ao mesmo tempo: antigo fecha, novo fica ativo e a mesa continua ocupada', Db::val('SELECT status FROM tabs WHERE id = ?', [$oldTab]) === 'closed' && $newTab > 0 && Db::val('SELECT status FROM tabs WHERE id = ?', [$newTab]) === 'open' && (int)Db::val("SELECT COUNT(*) FROM visits WHERE table_id = ? AND status = 'active'", [$t]) === 1, json_encode(array_map(fn($x) => $x['http'] ?? ($x['ok'] ? 'ok' : ($x['erro'] ?? '?')), $r)));
echo "\nTotal: $pass aprovados, $fail falharam\n";
