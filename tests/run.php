<?php
declare(strict_types=1);
/** Execução: php tests/run.php   (usa SQLite em memória; não toca em dados reais) */
putenv('DB_DRIVER=sqlite'); $_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_SQLITE_PATH'] = ':memory:';
require __DIR__ . '/../src/bootstrap.php';
// proteção: a suíte grava dados; nunca pode rodar contra um banco de arquivo/MySQL (mesmo que o .env aponte para um)
if (env('DB_DRIVER') !== 'sqlite' || env('DB_SQLITE_PATH') !== ':memory:') exit("Recusado: os testes só rodam em SQLite em memória (o .env não pode sobrescrever DB_SQLITE_PATH).\n");

$pass = 0; $fail = 0;
function t(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "  ok   $name\n"; }
    catch (Throwable $e) { $fail++; echo "  FAIL $name -> " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
function eq($a, $b, string $m = ''): void { if ($a !== $b) throw new Exception(($m ? "$m: " : '') . 'esperado ' . var_export($b, true) . ', obtido ' . var_export($a, true)); }
function throws(callable $f, int $status): void
{
    try { $f(); } catch (HttpError $e) { eq($e->status, $status, 'status HTTP: ' . $e->getMessage()); return; }
    throw new Exception('esperava HttpError ' . $status);
}

Db::migrate();
require __DIR__ . '/../database/seed_menu.php';
Db::exec("INSERT INTO delivery_zones (name, fee_cents, min_order_cents, active) VALUES ('Centro', 800, 3000, 1), ('Inativo', 0, 0, 0)");
Settings::set('schedule', json_encode(array_fill(0, 7, [['00:00', '23:59']])));
$u = ['id' => 1, 'name' => 'Teste'];
Db::insert('users', ['name' => 'Teste', 'email' => 't@t.com', 'password_hash' => 'x', 'role' => 'manager', 'active' => 1, 'created_at' => now()]);

$pid = fn(string $n) => (int)Db::val('SELECT id FROM products WHERE name = ?', [$n]);
$sid = fn(string $p, string $s) => (int)Db::val('SELECT ps.id FROM product_sizes ps JOIN products p ON p.id=ps.product_id WHERE p.name=? AND ps.name=?', [$p, $s]);
$order = fn(array $items, array $x = []) => $x + ['type' => 'pickup', 'name' => 'Maria', 'phone' => '(31) 99999-0000', 'payment' => 'cash', 'items' => $items];
$key = fn() => bin2hex(random_bytes(12));

echo "Preço\n";
t('pizza inteira usa preço do tamanho', function () use ($pid, $sid) {
    $q = Pricing::quote([['product_id' => $pid('Calabresa'), 'size_id' => $sid('Calabresa', 'Grande'), 'qty' => 2]]);
    eq($q['subtotal'], 2 * 9990);
});
t('meia a meia = média (arredonda p/ cima)', function () use ($pid, $sid) {
    $q = Pricing::quote([['product_id' => $pid('Calabresa'), 'flavors' => [$pid('Calabresa'), $pid('Portuguesa')], 'size_id' => $sid('Calabresa', 'Média'), 'qty' => 1]]);
    eq($q['subtotal'], (6990 + 9490 + 1) >> 1 === 8240 ? 8240 : 8240); // (69,90+94,90)/2 = 82,40
    eq($q['lines'][0]['name'], '½ Calabresa + ½ Portuguesa');
});
t('meia a meia com regra "maior valor"', function () use ($pid, $sid) {
    Settings::set('half_rule', 'highest');
    $q = Pricing::quote([['product_id' => $pid('Calabresa'), 'flavors' => [$pid('Calabresa'), $pid('Portuguesa')], 'size_id' => $sid('Calabresa', 'Média'), 'qty' => 1]]);
    Settings::set('half_rule', 'average');
    eq($q['subtotal'], 9490);
});
t('mais sabores que o máximo é recusado', function () use ($pid, $sid) {
    throws(fn() => Pricing::quote([['product_id' => $pid('Calabresa'), 'flavors' => [$pid('Calabresa'), $pid('Portuguesa'), $pid('Mussarela')], 'size_id' => $sid('Calabresa', 'Média'), 'qty' => 1]]), 422);
});
t('borda tem preço por tamanho', function () use ($pid, $sid) {
    $o = (int)Db::val("SELECT id FROM options WHERE name LIKE 'Borda%'");
    $q = Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Brotinho'), 'options' => [$o], 'qty' => 1]]);
    eq($q['subtotal'], 5490 + 1790);
    $q = Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Gigante'), 'options' => [$o], 'qty' => 1]]);
    eq($q['subtotal'], 11990 + 2490);
});
t('limite máximo de opções (grupo Acréscimos máx 2)', function () use ($pid) {
    $ids = array_column(Db::all("SELECT id FROM options WHERE name LIKE 'Acréscimo de%'"), 'id');
    Db::exec("UPDATE option_groups SET max_sel = 1 WHERE name = 'Acréscimos'");
    throws(fn() => Pricing::quote([['product_id' => $pid('Batata Frita'), 'options' => array_map('intval', $ids), 'qty' => 1]]), 422);
});
t('mínimo obrigatório de opções', function () use ($pid) {
    Db::exec("UPDATE option_groups SET min_sel = 1 WHERE name = 'Acréscimos'");
    throws(fn() => Pricing::quote([['product_id' => $pid('Batata Frita'), 'qty' => 1]]), 422);
    Db::exec("UPDATE option_groups SET min_sel = 0, max_sel = 2 WHERE name = 'Acréscimos'");
});
t('opção de outro produto é recusada', function () use ($pid) {
    $o = (int)Db::val("SELECT id FROM options WHERE name = 'Catupiry Original'");
    throws(fn() => Pricing::quote([['product_id' => $pid('Batata Frita'), 'options' => [$o], 'qty' => 1]]), 422);
});
t('produto indisponível é recusado', function () use ($pid) {
    Db::exec('UPDATE products SET available = 0 WHERE id = ?', [$pid('Café Expresso')]);
    throws(fn() => Pricing::quote([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), 422);
    Db::exec('UPDATE products SET available = 1 WHERE id = ?', [$pid('Café Expresso')]);
});
t('preço enviado pelo cliente é ignorado', function () use ($pid) {
    $q = Pricing::quote([['product_id' => $pid('Café Expresso'), 'qty' => 1, 'price_cents' => 1, 'unit_price_cents' => 1]]);
    eq($q['subtotal'], 790);
});

echo "Pedido\n";
t('cria pedido, grava cópia e total com taxa', function () use ($order, $key, $pid) {
    $r = Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1]], ['type' => 'delivery', 'zone_id' => 1, 'street' => 'Rua A', 'number' => '10']), $key());
    $o = Orders::get($r['id']);
    eq((int)$o['total_cents'], 3490 + 800);
    eq($o['status'], 'received');
    eq(strlen($r['token']), 32);
});
t('mesma chave de idempotência não duplica', function () use ($order, $key, $pid) {
    $k = $key(); $items = [['product_id' => $pid('Batata Frita'), 'qty' => 1]];
    $a = Orders::create($order($items), $k); $b = Orders::create($order($items), $k);
    eq($b['duplicate'], true); eq($b['id'], $a['id']);
});
t('pedido antigo mantém preço após reajuste do cardápio', function () use ($order, $key, $pid) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    Db::exec('UPDATE products SET base_price_cents = 9999, name = ? WHERE id = ?', ['Café Duplo', $pid('Café Expresso')]);
    $o = Orders::get($r['id']);
    eq((int)$o['items'][0]['unit_price_cents'], 790); eq($o['items'][0]['name'], 'Café Expresso');
    Db::exec('UPDATE products SET base_price_cents = 790, name = ? WHERE id = ?', ['Café Expresso', $pid('Café Duplo')]);
});
t('entrega exige bairro ativo e pedido mínimo', function () use ($order, $key, $pid) {
    $it = [['product_id' => $pid('Café Expresso'), 'qty' => 1]];
    throws(fn() => Orders::create($order($it, ['type' => 'delivery', 'zone_id' => 2, 'street' => 'R', 'number' => '1']), $key()), 422);
    throws(fn() => Orders::create($order($it, ['type' => 'delivery', 'zone_id' => 1, 'street' => 'R', 'number' => '1']), $key()), 422);
});
t('loja pausada ou fora do horário recusa pedido', function () use ($order, $key, $pid) {
    $it = [['product_id' => $pid('Café Expresso'), 'qty' => 1]];
    Settings::set('orders_open', '0');
    throws(fn() => Orders::create($order($it), $key()), 409);
    Settings::set('orders_open', '1');
});
t('forma de pagamento desativada é recusada', function () use ($order, $key, $pid) {
    $m = Settings::json('payment_methods'); $m['pix']['on'] = 0; Settings::set('payment_methods', json_encode($m));
    throws(fn() => Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]], ['payment' => 'pix']), $key()), 422);
    $m['pix']['on'] = 1; Settings::set('payment_methods', json_encode($m));
});
t('cliente é deduplicado por telefone', function () {
    eq((int)Db::val('SELECT COUNT(*) FROM customers WHERE phone = ?', ['31999990000']), 1);
});
t('visão pública não expõe telefone/endereço/outros clientes', function () use ($order, $key, $pid) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    $json = json_encode(Orders::publicView(Orders::byToken($r['token'])));
    foreach (['31999990000', 'Maria', 'customer', 'phone'] as $bad) if (str_contains($json, $bad)) throw new Exception("vazou $bad");
    eq(Orders::byToken('0000'), null); eq(Orders::byToken(str_repeat('a', 32)), null);
});

echo "Pedido manual (balcão/telefone)\n";
t('manual: ignora loja fechada, sem nome/telefone, registra operador', function () use ($order, $key, $pid, $u) {
    Settings::set('orders_open', '0');
    $items = [['product_id' => $pid('Café Expresso'), 'qty' => 1]];
    throws(fn() => Orders::create($order($items), $key()), 409); // o pedido público continua bloqueado
    $r = Orders::create(['type' => 'pickup', 'payment' => 'cash', 'items' => $items], $k = $key(), $u);
    Settings::set('orders_open', '1');
    $o = Orders::get($r['id']);
    eq($o['customer_name'], 'Balcão'); eq($o['customer_phone'], ''); eq($o['events'][0]['user_name'], 'Teste');
    eq((int)$o['total_cents'], 790);
    eq(Orders::create(['type' => 'pickup', 'payment' => 'cash', 'items' => $items], $k, $u)['duplicate'], true); // idempotente também
    $r2 = Orders::create(['type' => 'pickup', 'name' => 'Seu José', 'payment' => 'cash', 'items' => $items], $key(), $u);
    eq(Orders::get($r2['id'])['customer_name'], 'Seu José');
    eq((int)Db::val("SELECT COUNT(*) FROM customers WHERE phone = 'balcao'"), 1); // um único cadastro "Balcão"
});
t('manual: preço continua sendo do servidor e telefone inválido é recusado', function () use ($key, $pid, $u) {
    throws(fn() => Orders::create(['type' => 'pickup', 'payment' => 'cash', 'phone' => '123', 'items' => [['product_id' => $pid('Café Expresso'), 'qty' => 1]]], $key(), $u), 422);
    throws(fn() => Orders::create(['type' => 'delivery', 'payment' => 'cash', 'items' => [['product_id' => $pid('Café Expresso'), 'qty' => 1]]], $key(), $u), 422); // entrega exige bairro
});

echo "Status\n";
t('fluxo completo e histórico com operador', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key()); $id = $r['id']; $v = 1;
    foreach (['accepted', 'preparing', 'ready', 'completed'] as $s) $v = (int)Orders::transition($id, $s, $v, $u)['version'];
    $ev = Orders::get($id)['events'];
    eq(end($ev)['user_name'], 'Teste'); eq(count($ev), 5);
});
t('transição inválida é recusada', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    throws(fn() => Orders::transition($r['id'], 'completed', 1, $u), 422);
});
t('conflito de versão entre dois operadores (409)', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    Orders::transition($r['id'], 'accepted', 1, $u);
    throws(fn() => Orders::transition($r['id'], 'cancelled', 1, $u, 'motivo'), 409);
});
t('cancelamento exige motivo e é terminal', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    throws(fn() => Orders::transition($r['id'], 'cancelled', 1, $u, ''), 422);
    $o = Orders::transition($r['id'], 'cancelled', 1, $u, 'Cliente desistiu');
    eq($o['cancel_reason'], 'Cliente desistiu');
    throws(fn() => Orders::transition($r['id'], 'accepted', 2, $u), 422);
});
t('pagamento é independente do status e nunca automático', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]], ['payment' => 'pix']), $key());
    eq(Orders::get($r['id'])['payment_status'], 'pending');
    $o = Orders::setPayment($r['id'], 'confirmed', 1, $u);
    eq($o['payment_status'], 'confirmed'); eq($o['status'], 'received');
});

echo "Endereço\n";
t('editar endereço: só entrega, antes de pronto, com histórico e versão', function () use ($order, $key, $pid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1]], ['type' => 'delivery', 'zone_id' => 1, 'street' => 'Rua A', 'number' => '10']), $key());
    $o = Orders::updateAddress($r['id'], ['street' => 'Rua B', 'number' => '22', 'complement' => 'ap 3'], 1, $u);
    eq($o['addr_street'], 'Rua B'); eq((int)$o['version'], 2); eq((int)$o['total_cents'], 4290); // total/taxa intactos
    eq(end($o['events'])['kind'], 'address');
    throws(fn() => Orders::updateAddress($r['id'], ['street' => 'Rua C', 'number' => '1'], 1, $u), 409); // versão velha
    throws(fn() => Orders::updateAddress($r['id'], ['street' => '', 'number' => '1'], 2, $u), 422);
    $p = Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1]]), $key());
    throws(fn() => Orders::updateAddress($p['id'], ['street' => 'X', 'number' => '1'], 1, $u), 422); // retirada não tem endereço
    $v = 2; foreach (['accepted', 'preparing', 'ready'] as $s) $v = (int)Orders::transition($r['id'], $s, $v, $u)['version'];
    throws(fn() => Orders::updateAddress($r['id'], ['street' => 'Rua D', 'number' => '5'], $v, $u), 422); // já saiu
});

echo "Edição de itens\n";
t('itens: qtd, remover, adicionar (preço do servidor) e total recalculado com histórico', function () use ($order, $key, $pid, $sid, $u) {
    $r = Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1], ['product_id' => $pid('Café Expresso'), 'qty' => 1]], ['type' => 'delivery', 'zone_id' => 1, 'street' => 'R', 'number' => '1']), $key());
    $o = Orders::get($r['id']); [$a, $b] = [$o['items'][0]['id'], $o['items'][1]['id']];
    $o = Orders::editItems($r['id'], [['op' => 'qty', 'item_id' => $a, 'qty' => 3], ['op' => 'notes', 'item_id' => $b, 'notes' => 'sem açúcar']], 1, $u);
    eq((int)$o['subtotal_cents'], 3 * 3490 + 790); eq((int)$o['total_cents'], 3 * 3490 + 790 + 800); eq((int)$o['version'], 2);
    // preço enviado pelo cliente é ignorado ao adicionar
    Db::exec('UPDATE products SET base_price_cents = 9999 WHERE id = ?', [$pid('Café Expresso')]);
    $o = Orders::editItems($r['id'], [['op' => 'add', 'item' => ['product_id' => $pid('Café Expresso'), 'qty' => 1, 'price_cents' => 1]], ['op' => 'remove', 'item_id' => $a]], 2, $u);
    eq((int)$o['subtotal_cents'], 790 + 9999); // item antigo manteve R$ 7,90; o novo usa o cardápio atual
    Db::exec('UPDATE products SET base_price_cents = 790 WHERE id = ?', [$pid('Café Expresso')]);
    eq(end($o['events'])['kind'], 'items');
});
t('itens: regras (último item, pronto, pagamento confirmado, versão, item alheio)', function () use ($order, $key, $pid, $u) {
    $mk = fn() => Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1]]), $key())['id'];
    $id = $mk(); $it = Orders::get($id)['items'][0]['id'];
    throws(fn() => Orders::editItems($id, [['op' => 'remove', 'item_id' => $it]], 1, $u), 422);
    throws(fn() => Orders::editItems($id, [['op' => 'qty', 'item_id' => $it, 'qty' => 2]], 9, $u), 409);
    throws(fn() => Orders::editItems($id, [['op' => 'qty', 'item_id' => 999999, 'qty' => 2]], 1, $u), 422);
    throws(fn() => Orders::editItems($id, [['op' => 'qty', 'item_id' => $it, 'qty' => 0]], 1, $u), 422);
    Orders::setPayment($id, 'confirmed', 1, $u);
    throws(fn() => Orders::editItems($id, [['op' => 'qty', 'item_id' => $it, 'qty' => 2]], 2, $u), 422);
    $id2 = $mk(); $v = 1; foreach (['accepted', 'preparing', 'ready'] as $s) $v = (int)Orders::transition($id2, $s, $v, $u)['version'];
    throws(fn() => Orders::editItems($id2, [['op' => 'qty', 'item_id' => Orders::get($id2)['items'][0]['id'], 'qty' => 2]], $v, $u), 422);
    // troco menor que o novo total é removido
    $id3 = Orders::create($order([['product_id' => $pid('Batata Frita'), 'qty' => 1]], ['change_for' => '50,00']), $key())['id'];
    $o = Orders::editItems($id3, [['op' => 'qty', 'item_id' => Orders::get($id3)['items'][0]['id'], 'qty' => 2]], 1, $u);
    eq($o['change_for_cents'], null);
});

echo "Relatórios\n";
t('critérios: cancelados fora do válido; pago x pendente', function () use ($order, $key, $pid, $u) {
    Db::exec('DELETE FROM orders'); Db::exec('DELETE FROM customers');
    $mk = fn($n, $pay = 'cash') => Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => $n]], ['payment' => $pay]), $key())['id'];
    $a = $mk(1); $b = $mk(2, 'pix'); $c = $mk(3);
    Orders::setPayment($b, 'confirmed', 1, $u);
    Orders::transition($c, 'cancelled', 1, $u, 'teste');
    $s = Reports::summary(date('Y-m-d'), date('Y-m-d'));
    eq($s['sum']['valid_count'], 2); eq($s['sum']['valid_total'], 790 * 3);
    eq($s['sum']['paid_total'], 790 * 2); eq($s['sum']['pending_total'], 790);
    eq($s['sum']['cancelled_count'], 1); eq($s['sum']['cancelled_total'], 790 * 3);
    eq($s['sum']['avg_ticket'], (int)round(790 * 3 / 2));
    eq($s['top'][0]['qty'] + 0, 3);
    $csv = Reports::csv(date('Y-m-d'), date('Y-m-d'));
    eq(substr_count($csv, "\n"), 4);
});

echo "Impressão\n";
t('pedido gera trabalhos por setor na fila', function () use ($order, $key, $pid) {
    Db::exec('DELETE FROM print_jobs');
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    eq((int)Db::val('SELECT COUNT(*) FROM print_jobs WHERE order_id = ? AND status = ?', [$r['id'], 'queued']), 2);
});
t('cozinha sem valores, balcão com valores', function () use ($order, $key, $pid) {
    $r = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    $o = Orders::get($r['id']);
    $k = implode("\n", array_column(Printing::lines($o, ['paper' => 80, 'sector' => 'cozinha', 'kind' => 'original']), 't'));
    $b = implode("\n", array_column(Printing::lines($o, ['paper' => 58, 'sector' => 'balcao', 'kind' => 'original']), 't'));
    if (str_contains($k, 'TOTAL')) throw new Exception('cozinha mostra valores');
    if (!str_contains($b, 'TOTAL')) throw new Exception('balcão sem total');
    foreach (Printing::lines($o, ['paper' => 58, 'sector' => 'balcao', 'kind' => 'original']) as $l) if (strlen($l['t']) > 32 && empty($l['big'])) throw new Exception('linha excede 32 colunas: ' . $l['t']);
});
t('ciclo: claim → falha segura → retentativa → falha definitiva', function () {
    Db::exec('DELETE FROM print_jobs WHERE id NOT IN (SELECT MIN(id) FROM print_jobs)');
    Db::exec("UPDATE print_jobs SET status = 'queued', attempts = 0");
    $j = Printing::claim(); eq($j['attempt'], 1);
    eq(Printing::result($j['uuid'], false, false, 'sem papel')['status'], 'queued');
    $j = Printing::claim(); Printing::result($j['uuid'], false, false, 'x');
    $j = Printing::claim(); eq(Printing::result($j['uuid'], false, false, 'x')['status'], 'failed');
});
t('erro após envio de bytes vira "incerta" (risco de duplicar)', function () {
    Db::exec("UPDATE print_jobs SET status = 'queued', attempts = 0 WHERE id = (SELECT MIN(id) FROM print_jobs)");
    $j = Printing::claim();
    eq(Printing::result($j['uuid'], false, true, 'timeout')['status'], 'uncertain');
});
t('job abandonado pelo agente vira "incerta"', function () {
    Db::exec("UPDATE print_jobs SET status = 'printing', claimed_at = '2000-01-01 00:00:00' WHERE id = (SELECT MIN(id) FROM print_jobs)");
    Printing::claim();
    eq(Db::val('SELECT status FROM print_jobs WHERE id = (SELECT MIN(id) FROM print_jobs)'), 'uncertain');
});
t('reimpressão gera trabalho identificado e evento auditado', function () use ($u) {
    $oid = (int)Db::val('SELECT MIN(order_id) FROM print_jobs');
    $ids = Printing::reprint($oid, 'balcao', $u);
    eq(Db::val('SELECT kind FROM print_jobs WHERE id = ?', [$ids[0]]), 'reprint');
    $txt = Printing::lines(Orders::get($oid), Db::one('SELECT * FROM print_jobs WHERE id = ?', [$ids[0]]));
    if (!str_contains(implode("\n", array_column($txt, 't')), 'REIMPRESSAO')) throw new Exception('sem marca de reimpressão');
});
t('ESC/POS contém init, corte e só ASCII', function () {
    $b = Printing::escpos([['t' => Printing::ascii('Pão à moda ½')]]);
    if (!str_starts_with($b, "\x1B@") || !str_contains($b, "\x1DV")) throw new Exception('comandos ausentes');
    if (preg_match('/[\x80-\xFF]/', $b)) throw new Exception('bytes não ASCII');
});

echo "Equipe e permissões\n";
$loginAs = function (int $id) { Auth::reset(); $_SESSION['uid'] = $id; $_SESSION['sv'] = (int)Db::val('SELECT session_version FROM users WHERE id = ?', [$id]); };
$owner = ['id' => Db::insert('users', ['name' => 'Dono', 'email' => 'dono@t.com', 'password_hash' => 'x', 'role' => 'owner', 'active' => 1, 'created_at' => now()]), 'name' => 'Dono', 'role' => 'owner', 'perms' => null];
t('somente o proprietário cadastra; só gerente/garçom; sem outro proprietário', function () use ($owner) {
    $m = Team::create(['name' => 'Gina', 'email' => 'gina@t.com', 'password' => 'senhaforte123', 'role' => 'manager'], $owner);
    $w = Team::create(['name' => 'Gui', 'email' => 'gui@t.com', 'password' => 'senhaforte123', 'role' => 'waiter'], $owner);
    $mu = Db::one('SELECT * FROM users WHERE id = ?', [$m]);
    throws(fn() => Team::create(['name' => 'X', 'email' => 'x@t.com', 'password' => 'senhaforte123', 'role' => 'waiter'], $mu), 403);
    throws(fn() => Team::setActive($w, false, $mu), 403);
    throws(fn() => Team::create(['name' => 'Y', 'email' => 'y@t.com', 'password' => 'senhaforte123', 'role' => 'owner'], $owner), 422);
    eq(Db::val('SELECT role FROM users WHERE id = ?', [$w]), 'waiter');
});
t('matriz: garçom nunca recebe caixa/usuários, nem por permissão forçada; gerente nunca recebe usuários', function () use ($owner) {
    $w = (int)Db::val("SELECT id FROM users WHERE email = 'gui@t.com'");
    Db::exec("UPDATE users SET perms = ? WHERE id = ?", [json_encode(['floor', 'cashier', 'users', 'discount', 'report_payment']), $w]);
    $p = Auth::permsOf(Db::one('SELECT * FROM users WHERE id = ?', [$w]));
    sort($p); eq($p, ['floor', 'report_payment']);
    $m = Auth::permsOf(Db::one("SELECT * FROM users WHERE email = 'gina@t.com'"));
    foreach (['users', 'audit', 'settings', 'executive'] as $x) if (in_array($x, $m, true)) throw new Exception("gerente com $x");
    if (!in_array('cashier', $m, true)) throw new Exception('gerente sem caixa');
    foreach (Auth::OWNER_ONLY as $x) if (!in_array($x, Auth::permsOf($owner), true)) throw new Exception("dono sem $x");
});
t('sessão: desativar, trocar perfil/permissões ou senha derruba o acesso', function () use ($owner, $loginAs) {
    $w = (int)Db::val("SELECT id FROM users WHERE email = 'gui@t.com'");
    $loginAs($w); if (!Auth::user()) throw new Exception('deveria estar logado');
    Team::update($w, ['role' => 'waiter', 'perms' => ['floor']], $owner);
    Auth::reset(); if (Auth::user()) throw new Exception('permissão alterada deveria derrubar a sessão');
    $loginAs($w); Team::setActive($w, false, $owner); Auth::reset(); if (Auth::user()) throw new Exception('desativado ainda logado');
    Team::setActive($w, true, $owner); $loginAs($w); Team::setPassword($w, 'outrasenha123', $owner); Auth::reset(); if (Auth::user()) throw new Exception('troca de senha deveria derrubar');
    $loginAs($w); Team::revoke($w, $owner); Auth::reset(); if (Auth::user()) throw new Exception('revogar deveria derrubar');
    unset($_SESSION['uid'], $_SESSION['sv']); Auth::reset();
});
t('destino pós-login conforme o cadastro', function () use ($loginAs) {
    $loginAs((int)Db::val("SELECT id FROM users WHERE email = 'dono@t.com'")); eq(Auth::home(), '/admin');
    $loginAs((int)Db::val("SELECT id FROM users WHERE email = 'gina@t.com'")); eq(Auth::home(), '/admin');
    $loginAs((int)Db::val("SELECT id FROM users WHERE email = 'gui@t.com'")); eq(Auth::home(), '/garcom');
    unset($_SESSION['uid'], $_SESSION['sv']); Auth::reset();
});

echo "Salão: mesas, atendimentos, comandas\n";
$mkUser = fn(string $email, string $role, ?array $perms = null) => (function () use ($email, $role, $perms) {
    $id = Db::insert('users', ['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password_hash' => 'x', 'role' => $role, 'perms' => $perms === null ? null : json_encode($perms), 'active' => 1, 'created_at' => now()]);
    return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
})();
$wA = $mkUser('garcom.a@t.com', 'waiter'); $wB = $mkUser('garcom.b@t.com', 'waiter'); $mgr = $mkUser('caixa@t.com', 'manager');
Floor::syncTables(8);
$tbl = fn(int $n) => (int)Db::val('SELECT id FROM dining_tables WHERE number = ?', [$n]);
$fk = fn() => bin2hex(random_bytes(12));
$item = fn(string $name, int $qty = 1, ?string $size = null) => ['product_id' => (int)Db::val('SELECT id FROM products WHERE name = ?', [$name]), 'size_id' => $size ? (int)Db::val('SELECT ps.id FROM product_sizes ps JOIN products p ON p.id = ps.product_id WHERE p.name = ? AND ps.name = ?', [$name, $size]) : null, 'qty' => $qty];

t('abrir mesa: valida nome/pessoas, grava operador e é idempotente', function () use ($wA, $tbl, $fk) {
    throws(fn() => Floor::open(['table_id' => $tbl(1), 'name' => '', 'people' => 2], $wA, $fk()), 422);
    throws(fn() => Floor::open(['table_id' => $tbl(1), 'name' => 'Ana', 'people' => 0], $wA, $fk()), 422);
    throws(fn() => Floor::open(['table_id' => 9999, 'name' => 'Ana', 'people' => 2], $wA, $fk()), 422);
    $k = $fk(); $r = Floor::open(['table_id' => $tbl(1), 'name' => 'Ana', 'people' => 4], $wA, $k);
    $again = Floor::open(['table_id' => $tbl(1), 'name' => 'Ana', 'people' => 4], $wA, $k);
    eq($again['duplicate'], true); eq($again['tab_id'], $r['tab_id']);
    eq((int)Db::val('SELECT COUNT(*) FROM visits WHERE table_id = ?', [$tbl(1)]), 1);
    eq(Db::val('SELECT opened_by_name FROM visits WHERE id = ?', [$r['visit_id']]), 'Garcom.a');
});
t('mesa ativa: sem escolha explícita é recusado; adicionar ao grupo ou atendimento independente', function () use ($wA, $wB, $tbl, $fk) {
    throws(fn() => Floor::open(['table_id' => $tbl(1), 'name' => 'Bia', 'people' => 2, 'physical_number' => '010'], $wB, $fk()), 409); // abrir por cima, outra pessoa, sem escolher
    $vid = (int)Db::val('SELECT id FROM visits WHERE table_id = ?', [$tbl(1)]);
    $r = Floor::open(['table_id' => $tbl(1), 'name' => 'Bia', 'people' => 2, 'physical_number' => '010', 'mode' => 'add_to_visit', 'visit_id' => $vid], $wB, $fk());
    eq($r['visit_id'], $vid); // comanda no mesmo atendimento
    $r2 = Floor::open(['table_id' => $tbl(1), 'name' => 'Caio', 'people' => 3, 'physical_number' => '011', 'mode' => 'new_visit'], $wB, $fk());
    if ($r2['visit_id'] === $vid) throw new Exception('independente deveria criar atendimento novo');
    throws(fn() => Floor::open(['table_id' => $tbl(1), 'name' => 'X', 'people' => 1, 'mode' => 'add_to_visit', 'visit_id' => 987654], $wB, $fk()), 422);
    Settings::set('allow_independent_visits', '0');
    throws(fn() => Floor::open(['table_id' => $tbl(1), 'name' => 'Y', 'people' => 1, 'mode' => 'new_visit'], $wB, $fk()), 422);
    Settings::set('allow_independent_visits', '1');
});
t('comanda física aberta não é reutilizada; após fechar pode ser reutilizada com histórico preservado', function () use ($wA, $tbl, $fk, $mgr, $item) {
    throws(fn() => Floor::open(['table_id' => $tbl(2), 'name' => 'Dora', 'people' => 2, 'physical_number' => '010'], $wA, $fk()), 409);
    $r = Floor::open(['table_id' => $tbl(2), 'name' => 'Dora', 'people' => 2, 'physical_number' => '020'], $wA, $fk());
    throws(fn() => Floor::open(['table_id' => $tbl(3), 'name' => 'Edu', 'people' => 1, 'physical_number' => '020'], $wA, $fk()), 409);
    Floor::closeTab($r['tab_id'], $mgr); // sem consumo: total 0 → fecha
    $r2 = Floor::open(['table_id' => $tbl(3), 'name' => 'Edu', 'people' => 1, 'physical_number' => '020'], $wA, $fk());
    if ($r2['tab_id'] === $r['tab_id']) throw new Exception('mesmo registro reutilizado');
    eq((int)Db::val("SELECT COUNT(*) FROM tabs WHERE physical_number = '020'"), 2); // histórico por ids internos
});
t('mesa 04: atendimento anterior aguardando caixa + novo grupo com comanda 027 = contas independentes', function () use ($wA, $wB, $tbl, $fk, $item, $mgr) {
    $a = Floor::open(['table_id' => $tbl(4), 'name' => 'Bruno', 'people' => 2, 'physical_number' => '015'], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Calabresa', 1, 'Grande')], $wA, $fk());
    Floor::requestBill($a['tab_id'], $wA);
    $before = Floor::totals($a['tab_id']);
    $state = array_values(array_filter(Floor::tables(), fn($x) => $x['number'] === 4))[0];
    eq($state['state'], 'awaiting'); eq($state['pending_count'], 1);
    $b = Floor::open(['table_id' => $tbl(4), 'name' => 'Grupo Novo', 'people' => 5, 'physical_number' => '027'], $wB, $fk());
    eq(count($b['pending']), 1); // o sistema mostra a pendência anterior
    if ($b['visit_id'] === $a['visit_id'] || $b['tab_id'] === $a['tab_id']) throw new Exception('deveria ser independente');
    Floor::sendRound($b['tab_id'], [$item('Mussarela', 1, 'Média'), $item('Coca-Cola Lata', 2)], $wB, $fk());
    $after = Floor::totals($a['tab_id']);
    eq($after, $before); // a conta antiga não recebeu itens da nova
    eq(Floor::totals($b['tab_id'])['items'], 6990 + 2 * 790);
    $state = array_values(array_filter(Floor::tables(), fn($x) => $x['number'] === 4))[0];
    eq($state['state'], 'busy'); eq($state['pending_count'], 1); eq($state['people'], 5); // Em atendimento · 1 fechamento pendente
    // pagar e fechar a conta ANTIGA não libera a mesa ocupada pelo novo grupo
    Floor::registerPayment($a['tab_id'], $before['total'], 'pix', $mgr, $fk());
    Floor::closeTab($a['tab_id'], $mgr);
    $state = array_values(array_filter(Floor::tables(), fn($x) => $x['number'] === 4))[0];
    eq($state['state'], 'busy'); eq($state['pending_count'], 0);
    eq(Db::val('SELECT status FROM tabs WHERE id = ?', [$b['tab_id']]), 'open');
    eq(Floor::totals($b['tab_id'])['confirmed'], 0); // pagamentos da antiga não entram na nova
});
t('rodadas: só itens novos viram produção; setor correto; reenvio com a mesma chave não duplica', function () use ($wA, $tbl, $fk, $item) {
    Db::exec('DELETE FROM print_jobs');
    $a = Floor::open(['table_id' => $tbl(5), 'name' => 'Fabi', 'people' => 2], $wA, $fk());
    $k1 = $fk(); $r1 = Floor::sendRound($a['tab_id'], [$item('Calabresa', 1, 'Média')], $wA, $k1);
    eq(Floor::sendRound($a['tab_id'], [$item('Calabresa', 1, 'Média')], $wA, $k1)['duplicate'], true);
    $r2 = Floor::sendRound($a['tab_id'], [$item('Coca-Cola Lata', 2)], $wA, $fk());
    eq((int)Db::val('SELECT COUNT(*) FROM orders WHERE tab_id = ?', [$a['tab_id']]), 2);
    eq((int)Db::val('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$r2['order_id']]), 1); // só o item novo
    eq(Db::all('SELECT sector FROM print_jobs WHERE order_id = ?', [$r1['order_id']]), [['sector' => 'cozinha']]);
    eq(Db::all('SELECT sector FROM print_jobs WHERE order_id = ?', [$r2['order_id']]), [['sector' => 'copa']]); // bebida: copa/bar
    $o = Orders::get($r1['order_id']);
    eq($o['origin'], 'salao'); eq($o['type'], 'dine_in'); eq((int)$o['table_number'], 5); eq($o['events'][0]['user_name'], 'Garcom.a');
    $txt = implode("\n", array_column(Printing::lines($o, ['paper' => 58, 'sector' => 'cozinha', 'kind' => 'original']), 't'));
    foreach (['MESA 5', 'Fabi', 'Garcom.a', 'Calabresa'] as $x) if (!str_contains($txt, $x)) throw new Exception("comanda sem $x");
    if (str_contains($txt, 'R$') || str_contains($txt, 'Coca')) throw new Exception('comanda da cozinha com valor/bebida');
    // fechada: não aceita novos itens
    Floor::requestBill($a['tab_id'], $wA);
    throws(fn() => Floor::sendRound($a['tab_id'], [$item('Atum', 1, 'Média')], $wA, $fk()), 422);
});
t('cancelar rodada avisa o setor (impressão "cancel") e itens enviados só mudam por regra com histórico', function () use ($wA, $tbl, $fk, $item, $mgr) {
    $a = Floor::open(['table_id' => $tbl(6), 'name' => 'Gil', 'people' => 1], $wA, $fk());
    $r = Floor::sendRound($a['tab_id'], [$item('Atum', 1, 'Média')], $wA, $fk());
    Orders::transition($r['order_id'], 'cancelled', 1, $mgr, 'cliente desistiu');
    eq((int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE order_id = ? AND kind = 'cancel'", [$r['order_id']]), 1);
    eq(Floor::totals($a['tab_id'])['items'], 0); // não conta no consumo
    $r2 = Floor::sendRound($a['tab_id'], [$item('Atum', 1, 'Média')], $wA, $fk());
    $o = Orders::get($r2['order_id']);
    Orders::editItems($r2['order_id'], [['op' => 'qty', 'item_id' => $o['items'][0]['id'], 'qty' => 2]], 1, $mgr);
    eq((int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE order_id = ? AND kind = 'update'", [$r2['order_id']]), 1);
    throws(fn() => Orders::setPayment($r2['order_id'], 'confirmed', 2, $mgr), 422); // pagamento do salão é na comanda
});

echo "Salão: recebimentos, caixa e permissões\n";
t('garçom informa recebimento (pendente), não confirma nem fecha; gerente confere e fecha', function () use ($wA, $mgr, $tbl, $fk, $item) {
    $a = Floor::open(['table_id' => $tbl(7), 'name' => 'Hugo', 'people' => 2], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Calabresa', 1, 'Grande')], $wA, $fk()); // 99,90
    Floor::requestBill($a['tab_id'], $wA);
    $p = Floor::reportPayment($a['tab_id'], 5000, 'cash', $wA, $fk());
    $tot = Floor::totals($a['tab_id']); eq($tot['reported'], 5000); eq($tot['confirmed'], 0); eq($tot['pending'], 9990);
    throws(fn() => Floor::confirmPayment($p['payment_id'], $wA), 403);
    throws(fn() => Floor::closeTab($a['tab_id'], $wA), 403);
    throws(fn() => Floor::registerPayment($a['tab_id'], 100, 'cash', $wA, $fk()), 403);
    throws(fn() => Floor::applyDiscount($a['tab_id'], 100, 'cortesia', $wA), 403);
    throws(fn() => Floor::reportPayment($a['tab_id'], 99999, 'cash', $wA, $fk()), 422); // excede o saldo
    throws(fn() => Floor::closeTab($a['tab_id'], $mgr), 422); // há informado não conferido e saldo
    Floor::confirmPayment($p['payment_id'], $mgr);
    $k = $fk(); $p2 = Floor::reportPayment($a['tab_id'], 4990, 'pix', $wA, $k);
    eq(Floor::reportPayment($a['tab_id'], 4990, 'pix', $wA, $k)['duplicate'], true); // reenvio não duplica
    throws(fn() => Floor::closeTab($a['tab_id'], $mgr), 422);
    Floor::confirmPayment($p2['payment_id'], $mgr);
    Floor::closeTab($a['tab_id'], $mgr);
    eq(Db::val('SELECT status FROM tabs WHERE id = ?', [$a['tab_id']]), 'closed'); eq(Db::val('SELECT status FROM visits WHERE id = ?', [$a['visit_id']]), 'closed');
    eq(Floor::totals($a['tab_id'])['total'], 9990);
});
t('taxa de serviço só existe se configurada; remover/desconto exigem permissão e motivo', function () use ($wA, $mgr, $tbl, $fk, $item) {
    $a = Floor::open(['table_id' => $tbl(8), 'name' => 'Ivo', 'people' => 2], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Atum', 1, 'Média')], $wA, $fk()); // 84,90
    eq(Floor::totals($a['tab_id'])['fee'], 0);
    Settings::set('service_fee_percent', '10');
    eq(Floor::totals($a['tab_id'])['fee'], 849); eq(Floor::totals($a['tab_id'])['total'], 8490 + 849);
    throws(fn() => Floor::setServiceFee($a['tab_id'], true, 'cliente pediu', $wA), 403);
    throws(fn() => Floor::setServiceFee($a['tab_id'], true, '', $mgr), 422);
    Floor::setServiceFee($a['tab_id'], true, 'cliente pediu', $mgr); eq(Floor::totals($a['tab_id'])['fee'], 0);
    throws(fn() => Floor::applyDiscount($a['tab_id'], 500, '', $mgr), 422);
    Floor::applyDiscount($a['tab_id'], 490, 'cortesia da casa', $mgr); eq(Floor::totals($a['tab_id'])['total'], 8000);
    Settings::set('service_fee_percent', '');
});
t('estorno é só registro; reabrir exige motivo; caixa: turno, sangria, esperado × contado', function () use ($wA, $mgr, $tbl, $fk, $item) {
    $tab = (int)Db::val("SELECT id FROM tabs WHERE name = 'Hugo'");
    $pays = Db::all("SELECT id FROM tab_payments WHERE tab_id = ? ORDER BY id", [$tab]);
    throws(fn() => Floor::reversePayment($pays[0]['id'], 'x', $mgr), 422); // comanda fechada
    throws(fn() => Floor::reopenTab($tab, '', $mgr), 422);
    Floor::reopenTab($tab, 'cliente contestou a conta', $mgr);
    Floor::reversePayment($pays[0]['id'], 'recebido a mais', $mgr);
    eq(Db::val('SELECT status FROM tab_payments WHERE id = ?', [$pays[0]['id']]), 'reversed');
    eq(Floor::totals($tab)['confirmed'], 4990); eq(Db::val('SELECT status FROM tabs WHERE id = ?', [$tab]), 'awaiting_cashier');
    throws(fn() => Cash::open(1000, $wA), 403);
    $sid = Cash::open(10000, $mgr);
    throws(fn() => Cash::open(1000, $mgr), 409); // um turno por vez
    $p = Floor::registerPayment($tab, 5000, 'cash', $mgr, $fk()); // 50,00 em dinheiro confirmado no turno
    Cash::move('withdraw', 2000, 'depósito no cofre', $mgr); Cash::move('supply', 500, 'troco', $mgr);
    throws(fn() => Cash::move('withdraw', 100, '', $mgr), 422);
    $sum = Cash::summary($sid); eq($sum['expected'], 10000 + 5000 + 500 - 2000);
    throws(fn() => Cash::close(13400, '', $mgr), 422); // diferença exige observação
    $r = Cash::close(13400, 'faltou 1,00 de troco', $mgr); eq($r['diff'], -100);
    eq(Cash::currentId(), null);
});
t('pagamento informado pendente impede fechar o turno', function () use ($wA, $mgr, $tbl, $fk, $item) {
    $a = Floor::open(['table_id' => $tbl(1), 'name' => 'Joel', 'people' => 1, 'physical_number' => '031', 'mode' => 'new_visit'], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Café Expresso', 1)], $wA, $fk());
    Cash::open(0, $mgr); Floor::reportPayment($a['tab_id'], 790, 'cash', $wA, $fk());
    throws(fn() => Cash::close(0, '', $mgr), 422);
    $pid = (int)Db::val("SELECT id FROM tab_payments WHERE tab_id = ?", [$a['tab_id']]);
    Floor::rejectPayment($pid, 'cliente pagou no cartão', $mgr);
    Cash::close(0, '', $mgr);
});

echo "Catálogo e montagem da pizza\n";
$ing = fn(string $prod, string $name) => (int)Db::val('SELECT i.id FROM product_ingredients i JOIN products p ON p.id = i.product_id WHERE p.name = ? AND i.name = ?', [$prod, $name]);
t('sabores: 1 aceito, 2 aceitos, 3 e 0 rejeitados (servidor)', function () use ($pid, $sid) {
    $base = ['product_id' => $pid('Calabresa'), 'size_id' => $sid('Calabresa', 'Média'), 'qty' => 1];
    eq(Pricing::quote([$base])['subtotal'], 6990);
    eq(Pricing::quote([$base + ['flavors' => [$pid('Calabresa'), $pid('Portuguesa')]]])['subtotal'], 8240);
    throws(fn() => Pricing::quote([$base + ['flavors' => [$pid('Calabresa'), $pid('Portuguesa'), $pid('Mussarela')]]]), 422);
    throws(fn() => Pricing::quote([['product_id' => 0, 'size_id' => 1, 'qty' => 1]]), 422);
    throws(fn() => Pricing::quote([['product_id' => $pid('Calabresa'), 'qty' => 1]]), 422); // tamanho obrigatório
});
t('retirada de ingrediente fica no sabor certo (nunca nas duas metades) e chega ao pedido', function () use ($pid, $sid, $ing, $order, $key) {
    $item = ['product_id' => $pid('À Moda'), 'size_id' => $sid('À Moda', 'Grande'), 'qty' => 1, 'flavors' => [$pid('À Moda'), $pid('Calabresa')], 'removals' => [$ing('À Moda', 'cebola')]];
    $l = Pricing::quote([$item])['lines'][0];
    eq($l['options'][0]['name'], 'sem cebola — ½ À Moda'); eq($l['options'][0]['group'], 'Retirar'); eq($l['options'][0]['price_cents'], 0);
    throws(fn() => Pricing::quote([['removals' => [$ing('Portuguesa', 'ovos')]] + $item]), 422); // ingrediente de sabor não escolhido
    throws(fn() => Pricing::quote([['removals' => [$ing('Calabresa', 'calabresa')]] + $item]), 422); // sabor de ingrediente único: não removível
    $r = Orders::create($order([$item]), $key()); $o = Orders::get($r['id']);
    eq($o['items'][0]['options'][0]['option_name'], 'sem cebola — ½ À Moda');
    $txt = implode("\n", array_column(Printing::lines($o, ['paper' => 58, 'sector' => 'cozinha', 'kind' => 'original']), 't'));
    if (!str_contains($txt, 'sem cebola') || !str_contains($txt, '- ')) throw new Exception('retirada não chegou à comanda: ' . $txt);
});
t('oculto não é comprado nem por chamada direta e some do cardápio; em falta aparece mas não vende', function () use ($pid, $sid) {
    $id = $pid('Mussarela'); $item = ['product_id' => $id, 'size_id' => $sid('Mussarela', 'Média'), 'qty' => 1];
    Db::exec('UPDATE products SET hidden = 1 WHERE id = ?', [$id]);
    throws(fn() => Pricing::quote([$item]), 422);
    $names = array_merge(...array_map(fn($c) => array_column($c['products'], 'name'), Pricing::menu()['categories']));
    if (in_array('Mussarela', $names, true)) throw new Exception('oculto apareceu no cardápio');
    Db::exec('UPDATE products SET hidden = 0, available = 0 WHERE id = ?', [$id]);
    $m = array_values(array_filter(array_merge(...array_column(Pricing::menu()['categories'], 'products')), fn($p) => $p['name'] === 'Mussarela'))[0];
    eq($m['status'], 'out'); throws(fn() => Pricing::quote([$item]), 422);
    Db::exec('UPDATE products SET available = 1 WHERE id = ?', [$id]);
});
t('borda restrita a tamanhos e preço por tamanho; subcategorias combinam só dentro do mesmo grupo', function () use ($pid, $sid) {
    $o = (int)Db::val("SELECT id FROM options WHERE name LIKE 'Borda%'");
    Db::exec('UPDATE options SET size_restricted = 1 WHERE id = ?', [$o]); Db::exec("DELETE FROM option_size_prices WHERE option_id = ? AND size_name = 'Brotinho'", [$o]);
    throws(fn() => Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Brotinho'), 'options' => [$o], 'qty' => 1]]), 422);
    eq(Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Média'), 'options' => [$o], 'qty' => 1]])['subtotal'], 6990 + 1990);
    Db::exec('UPDATE options SET size_restricted = 0 WHERE id = ?', [$o]); Db::insert('option_size_prices', ['option_id' => $o, 'size_name' => 'Brotinho', 'price_cents' => 1790]);
    $root = (int)Db::val('SELECT category_id FROM products WHERE id = ?', [$pid('Mussarela')]);
    $sub = Db::insert('categories', ['name' => 'Pizzas doces', 'sort' => 2, 'is_pizza' => 1, 'active' => 1, 'parent_id' => $root, 'sector' => 'cozinha']);
    $doce = Db::insert('products', ['category_id' => $sub, 'name' => 'Chocolate (teste)', 'base_price_cents' => 0, 'available' => 1, 'sort' => 1]);
    Db::insert('product_sizes', ['product_id' => $doce, 'name' => 'Média', 'price_cents' => 5000, 'sort' => 1, 'available' => 1]);
    eq(Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Média'), 'flavors' => [$pid('Mussarela'), $doce], 'qty' => 1]])['subtotal'], (int)ceil((6990 + 5000) / 2));
    $outra = Db::insert('categories', ['name' => 'Outra raiz', 'sort' => 9, 'is_pizza' => 1, 'active' => 1, 'sector' => 'cozinha']);
    $p2 = Db::insert('products', ['category_id' => $outra, 'name' => 'Sabor de outra raiz', 'base_price_cents' => 3000, 'available' => 1, 'sort' => 1]);
    throws(fn() => Pricing::quote([['product_id' => $pid('Mussarela'), 'size_id' => $sid('Mussarela', 'Média'), 'flavors' => [$pid('Mussarela'), $p2], 'qty' => 1]]), 422);
    Db::exec('DELETE FROM products WHERE id IN (?, ?)', [$doce, $p2]); Db::exec('DELETE FROM categories WHERE id IN (?, ?)', [$sub, $outra]);
});
t('ingredientes do PDF cadastrados sem duplicar ao reexecutar', function () {
    $antes = (int)Db::val("SELECT COUNT(*) FROM product_ingredients"); if ($antes < 60) throw new Exception("poucos ingredientes: $antes");
    ob_start(); require __DIR__ . '/../database/seed_ingredients.php'; ob_end_clean();
    eq((int)Db::val("SELECT COUNT(*) FROM product_ingredients"), $antes);
});

echo "Recuperação de senha\n";
t('recuperação: e-mail inexistente/inativo não gera link; ativo gera e envia; nada é revelado', function () use ($mgr) {
    $_ENV['APP_URL'] = 'https://exemplo.test'; $_ENV['MAIL_DRIVER'] = 'log';
    $mailDir = APP_ROOT . '/storage/mail'; $before = is_dir($mailDir) ? count(glob("$mailDir/*.eml")) : 0;
    PasswordReset::request('naoexiste@x.com', '1.1.1.1');
    eq((int)Db::val('SELECT COUNT(*) FROM password_resets'), 0);
    Db::exec('UPDATE users SET active = 0 WHERE id = ?', [$mgr['id']]); PasswordReset::request($mgr['email'], '1.1.1.2');
    eq((int)Db::val('SELECT COUNT(*) FROM password_resets'), 0);
    Db::exec('UPDATE users SET active = 1 WHERE id = ?', [$mgr['id']]); PasswordReset::request($mgr['email'], '1.1.1.3');
    eq((int)Db::val('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$mgr['id']]), 1);
    eq(count(glob("$mailDir/*.eml")), $before + 1);
    $eml = file_get_contents(end(glob("$mailDir/*.eml"))); if (!preg_match('#https://exemplo.test/equipe/redefinir\?token=([a-f0-9]{64})#', $eml, $m)) throw new Exception('link ausente no e-mail');
    if (Db::val('SELECT token_hash FROM password_resets WHERE user_id = ?', [$mgr['id']]) === $m[1]) throw new Exception('token guardado em claro');
    $GLOBALS['__tok'] = $m[1];
});
t('redefinir: uso único, expira, troca só a senha (cargo/permissões intactos) e derruba sessões', function () use ($mgr, $loginAs) {
    $tok = $GLOBALS['__tok']; $before = Db::one('SELECT role, perms, active FROM users WHERE id = ?', [$mgr['id']]);
    $loginAs((int)$mgr['id']); if (!Auth::user()) throw new Exception('login de teste falhou');
    throws(fn() => PasswordReset::reset($tok, 'curta1'), 422); // senha fraca não consome o link
    PasswordReset::reset($tok, 'NovaSenha12345');
    if (!password_verify('NovaSenha12345', Db::val('SELECT password_hash FROM users WHERE id = ?', [$mgr['id']]))) throw new Exception('senha não trocou');
    eq(Db::one('SELECT role, perms, active FROM users WHERE id = ?', [$mgr['id']]), $before);
    Auth::reset(); if (Auth::user()) throw new Exception('sessão antiga continuou válida');
    throws(fn() => PasswordReset::reset($tok, 'OutraSenha12345'), 410); // já usado
    eq(PasswordReset::valid(str_repeat('a', 64)), null); eq(PasswordReset::valid('xyz'), null);
});
t('redefinir: link expirado ou de conta desativada depois do pedido é recusado e não reativa', function () use ($mgr) {
    PasswordReset::request($mgr['email'], '2.2.2.2'); $eml = file_get_contents(end(glob(APP_ROOT . '/storage/mail/*.eml'))); preg_match('/token=([a-f0-9]{64})/', $eml, $m);
    Db::exec("UPDATE password_resets SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = ?", [hash('sha256', $m[1])]);
    throws(fn() => PasswordReset::reset($m[1], 'NovaSenha12345'), 410);
    PasswordReset::request($mgr['email'], '2.2.2.3'); $eml = file_get_contents(end(glob(APP_ROOT . '/storage/mail/*.eml'))); preg_match('/token=([a-f0-9]{64})/', $eml, $m2);
    Db::exec('UPDATE users SET active = 0 WHERE id = ?', [$mgr['id']]);
    throws(fn() => PasswordReset::reset($m2[1], 'NovaSenha12345'), 410);
    eq((int)Db::val('SELECT active FROM users WHERE id = ?', [$mgr['id']]), 0); // continua bloqueada
    Db::exec('UPDATE users SET active = 1 WHERE id = ?', [$mgr['id']]);
});

echo "Relatórios do salão\n";
t('rodadas de produção do salão não viram venda; a comanda fechada é a venda (sem dupla contagem)', function () use ($wA, $mgr, $tbl, $fk, $item, $order, $key, $pid) {
    Db::exec('DELETE FROM tab_payments'); Db::exec('DELETE FROM orders'); Db::exec('DELETE FROM tabs'); Db::exec('DELETE FROM visits');
    $web = Orders::create($order([['product_id' => $pid('Café Expresso'), 'qty' => 1]]), $key());
    $a = Floor::open(['table_id' => $tbl(1), 'name' => 'Rel', 'people' => 2], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Calabresa', 1, 'Média')], $wA, $fk());
    Floor::sendRound($a['tab_id'], [$item('Coca-Cola Lata', 1)], $wA, $fk());
    Floor::applyDiscount($a['tab_id'], 500, 'cortesia', $mgr);
    Floor::registerPayment($a['tab_id'], 6990 + 790 - 500, 'pix', $mgr, $fk());
    Floor::closeTab($a['tab_id'], $mgr);
    $d = date('Y-m-d');
    $s = Reports::summary($d, $d);
    eq($s['sum']['valid_count'], 1); eq($s['sum']['valid_total'], 790); // só o pedido web
    $x = Reports::executive($d, $d);
    eq($x['cur']['channels']['salon']['total'], 6990 + 790 - 500); eq($x['cur']['channels']['salon']['n'], 1);
    eq($x['cur']['revenue'], 790 + 7280); // web + comanda (cada venda uma vez)
    eq($x['received'], 7280); // pedido web ainda sem pagamento conferido
    eq($x['pending'], 790);
    eq($x['cur']['channels']['salon']['disc'], 500);
    $csv = Reports::csv($d, $d); eq(substr_count($csv, "\n"), 2); // cabeçalho + 1 pedido web (produção do salão fora)
    $tabs = Reports::csvTabs($d, $d); eq(substr_count($tabs, "\n"), 2);
});

echo "Segurança\n";
t('estado do agente: nunca → online → offline', function () {
    Settings::set('print_agent_seen', '');
    eq(Printing::agentState()['state'], 'never');
    Settings::set('print_agent_seen', now());
    eq(Printing::agentState()['state'], 'online');
    Settings::set('print_agent_seen', date('Y-m-d H:i:s', time() - 300));
    $a = Printing::agentState(); eq($a['state'], 'offline');
    eq($a['label'], 'Agente de impressão offline');
});
t('senha fraca é recusada', function () { if (Auth::validatePassword('abc123') === null) throw new Exception('aceitou'); if (Auth::validatePassword('senhaforte123') !== null) throw new Exception('recusou boa'); });
t('token do agente: só hash é guardado', function () {
    $tok = Printing::newAgentToken();
    eq(Settings::get('print_agent_hash'), hash('sha256', $tok));
    if (str_contains(Settings::get('print_agent_hash'), $tok)) throw new Exception('token em claro');
});

echo "iFood (observações, rascunhos, fotos)\n";
$ifd = json_decode((string)file_get_contents(__DIR__ . '/../data/ifood/ifood-2026-11-04.json'), true);
$ifTmp = sys_get_temp_dir() . '/bianca-ifood-test-' . bin2hex(random_bytes(4)); @mkdir("$ifTmp/orig", 0775, true);
$ifImgs = array_merge(array_column($ifd['pizza_flavors'], 'img'), array_filter(array_column($ifd['dishes'], 'img')), array_filter(array_column($ifd['porcoes'], 'img')), array_filter(array_column($ifd['sobremesas'], 'img')));
foreach ($ifImgs as $f) { $im = imagecreatetruecolor(1400, 1000); imagejpeg($im, "$ifTmp/orig/$f", 80); }
$ifOpt = ['originals_dir' => "$ifTmp/orig", 'uploads_dir' => "$ifTmp/up"];
t('simulação não grava nada', function () use ($ifd, $ifOpt) {
    $antes = [Db::val('SELECT COUNT(*) FROM products'), Db::val('SELECT COUNT(*) FROM channel_observations')];
    $r = IfoodImport::run($ifd, $ifOpt);
    eq([Db::val('SELECT COUNT(*) FROM products'), Db::val('SELECT COUNT(*) FROM channel_observations')], $antes);
    if ($r['obs_new'] < 100 || $r['drafts_new'] !== 3) throw new Exception('relatório inesperado: ' . json_encode([$r['obs_new'], $r['drafts_new']]));
});
t('importar não altera preços nem produtos publicados; reexecutar não duplica', function () use ($ifd, $ifOpt, $pid) {
    $precos = Db::all('SELECT id, price_cents FROM product_sizes ORDER BY id'); $base = Db::all('SELECT id, name, base_price_cents, hidden FROM products WHERE hidden = 0 ORDER BY id');
    IfoodImport::run($ifd, $ifOpt + ['apply' => true]);
    $n1 = [Db::val('SELECT COUNT(*) FROM products'), Db::val('SELECT COUNT(*) FROM channel_observations')];
    IfoodImport::run($ifd, $ifOpt + ['apply' => true]);
    eq([Db::val('SELECT COUNT(*) FROM products'), Db::val('SELECT COUNT(*) FROM channel_observations')], $n1, 'reexecução duplicou');
    eq(Db::all('SELECT id, price_cents FROM product_sizes ORDER BY id'), $precos, 'preço de tamanho mudou');
    eq(Db::all('SELECT id, name, base_price_cents, hidden FROM products WHERE hidden = 0 ORDER BY id'), $base, 'produto publicado mudou');
    eq((int)Db::val("SELECT COUNT(*) FROM products WHERE hidden = 1 AND sort = 900"), 3);
    eq((int)Db::val("SELECT COUNT(*) FROM channel_observations WHERE status = 'rascunho'"), 3, 'rascunho perdeu a situação');
    // iFood soma R$ 0,01 ao valor exibido: Calabresa Grande 124,89 -> 124,90
    eq((int)Db::val("SELECT observed_cents FROM channel_observations WHERE source_key = 'price:calabresa:Grande'"), 12490);
    eq((int)Db::val('SELECT base_price_cents FROM products WHERE id = ?', [$pid('Calabresa')]), 0, 'preço próprio da pizza intacto');
});
t('rascunho oculto do iFood não pode ser comprado (nem por chamada direta)', function () {
    $id = (int)Db::val("SELECT id FROM products WHERE name = 'Batata Frita com Mussarela - 500g'");
    throws(fn() => Pricing::quote([['product_id' => $id, 'qty' => 1]]), 422);
    foreach (Pricing::menu()['categories'] ?? [] as $c) foreach ($c['products'] ?? [] as $p) if ((int)$p['id'] === $id) throw new Exception('rascunho apareceu no cardápio');
});
t('fotos: sem autorização ficam pendentes; com ela só entram em produto sem foto; foto manual é preservada', function () use ($ifd, $ifOpt, $pid, $ifTmp) {
    $r = IfoodImport::run($ifd, $ifOpt + ['apply' => true]);
    eq($r['photos_linked'], [], 'ligou foto sem autorização'); if (count($r['photos_pending']) < 15) throw new Exception('pendências esperadas');
    Db::exec("UPDATE products SET image = '/uploads/manual.jpg' WHERE name = 'Atum'");
    $r = IfoodImport::run($ifd, $ifOpt + ['apply' => true, 'photos' => true]);
    eq(Db::val("SELECT image FROM products WHERE name = 'Atum'"), '/uploads/manual.jpg', 'trocou foto manual');
    eq(Db::val("SELECT image FROM products WHERE name = 'Margherita'"), '/uploads/ifood/202403011722_F86H_i.jpg');
    eq(Db::val("SELECT image FROM products WHERE name = 'Calabresa'"), '/assets/img/pizza-calabresa.jpg', 'trocou foto do PDF');
    if (!is_file("$ifTmp/up/ifood/202403011722_F86H_i.jpg")) throw new Exception('derivado não gerado');
    $n = (int)Db::val("SELECT COUNT(*) FROM channel_observations WHERE kind = 'image'");
    IfoodImport::run($ifd, $ifOpt + ['apply' => true, 'photos' => true]);
    eq((int)Db::val("SELECT COUNT(*) FROM channel_observations WHERE kind = 'image'"), $n, 'duplicou registro de imagem');
    if (IfoodImport::unlinkPhotos($ifOpt['uploads_dir']) < 10) throw new Exception('unlink');
    eq(Db::val("SELECT image FROM products WHERE name = 'Margherita'"), null);
    eq(Db::val("SELECT image FROM products WHERE name = 'Atum'"), '/uploads/manual.jpg', 'unlink apagou foto manual');
});

echo "Impressão por destino e QZ Tray\n";
// par de chaves temporário (nunca o de desenvolvimento): chave só em arquivo temporário fora do projeto
$qzTmp = sys_get_temp_dir() . '/bianca-qz-test-' . bin2hex(random_bytes(4)); @mkdir($qzTmp, 0700, true);
file_put_contents("$qzTmp/c.cnf", "[req]\ndistinguished_name=dn\n[dn]\n");
$qzCfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha512', 'config' => "$qzTmp/c.cnf"];
$qzPk = openssl_pkey_new($qzCfg); $qzX = openssl_csr_sign(openssl_csr_new(['commonName' => 'teste'], $qzPk, $qzCfg), null, $qzPk, 30, $qzCfg);
openssl_x509_export($qzX, $qzCert); openssl_pkey_export($qzPk, $qzKey, null, $qzCfg);
file_put_contents("$qzTmp/key.pem", $qzKey); file_put_contents("$qzTmp/cert.txt", $qzCert);
$_ENV['QZ_PRIVATE_KEY_PATH'] = "$qzTmp/key.pem"; $_ENV['QZ_CERT_PATH'] = "$qzTmp/cert.txt";
$mix = fn() => [['product_id' => $pid('Calabresa'), 'size_id' => $sid('Calabresa', 'Média'), 'qty' => 1], ['product_id' => $pid('Coca-Cola Lata'), 'qty' => 1]];
$secOf = fn(int $oid) => array_column(Db::all('SELECT sector FROM print_jobs WHERE order_id = ? ORDER BY sector', [$oid]), 'sector');
$mkStation = function (string $name, array $sectors) use ($u) {
    $id = PrintStation::create($name, $u);
    PrintStation::heartbeat($id, ['qz' => true, 'version' => '2.2.4', 'printers' => ['Cozinha-USB', 'Bar-USB', 'Balcao-LAN']], $u);
    $rows = []; foreach ($sectors as $s => $p) $rows[$s] = ['printer' => $p, 'cols' => 48, 'charset' => 'ascii', 'cut' => 1];
    PrintStation::saveMap($id, $rows, $u); PrintStation::control($id, 'arm', $u);
    return $id;
};
t('roteamento: pizza → cozinha, bebida → copa, resumo → balcão; sem comanda de setor vazio', function () use ($order, $key, $mix, $secOf, $pid) {
    Db::exec('DELETE FROM print_jobs');
    $r = Orders::create($order($mix()), $key());
    eq($secOf($r['id']), ['balcao', 'copa', 'cozinha']);
    $r2 = Orders::create($order([['product_id' => $pid('Coca-Cola Lata'), 'qty' => 2]]), $key());
    eq($secOf($r2['id']), ['balcao', 'copa']); // sem item de cozinha: nada na cozinha
    $k = implode("\n", array_column(Printing::lines(Orders::get($r['id']), Db::one("SELECT * FROM print_jobs WHERE order_id = ? AND sector = 'cozinha'", [$r['id']])), 't'));
    if (!str_contains($k, 'Calabresa') || str_contains($k, 'Coca')) throw new Exception('cozinha deve ter só a pizza');
    $c = implode("\n", array_column(Printing::lines(Orders::get($r['id']), Db::one("SELECT * FROM print_jobs WHERE order_id = ? AND sector = 'copa'", [$r['id']])), 't'));
    if (!str_contains($c, 'Coca') || str_contains($c, 'Calabresa')) throw new Exception('copa deve ter só a bebida');
});
t('produto/categoria sem destino vira pendência visível; sobrescrita por produto vale; reenvio idempotente', function () use ($order, $key, $pid, $secOf) {
    Db::exec('DELETE FROM print_jobs');
    Db::exec("UPDATE categories SET sector = NULL WHERE name = 'Bebidas'"); Printing::forgetCache();
    $r = Orders::create($order([['product_id' => $pid('Coca-Cola Lata'), 'qty' => 1]]), $key());
    eq($secOf($r['id']), ['balcao', 'sem_destino']);
    eq(Db::val("SELECT status FROM print_jobs WHERE order_id = ? AND sector = 'sem_destino'", [$r['id']]), 'unrouted');
    if (!str_contains(json_encode(Printing::pendencias()), 'sem_destino')) throw new Exception('pendência invisível');
    Db::exec("UPDATE products SET print_sector = 'cozinha' WHERE name = 'Coca-Cola Lata'"); Printing::forgetCache();
    $r = Orders::create($order([['product_id' => $pid('Coca-Cola Lata'), 'qty' => 1]]), $key());
    eq($secOf($r['id']), ['balcao', 'cozinha']);
    $n = (int)Db::val('SELECT COUNT(*) FROM print_jobs');
    Printing::enqueueOrder($r['id'], 'original', null); eq((int)Db::val('SELECT COUNT(*) FROM print_jobs'), $n, 'duplicou pelo job_key');
    Db::exec("UPDATE products SET print_sector = NULL WHERE name = 'Coca-Cola Lata'"); Db::exec("UPDATE categories SET sector = 'copa' WHERE name = 'Bebidas'"); Printing::forgetCache();
});
t('texto de clientes nunca injeta comandos ESC/POS', function () {
    $evil = "Ze\x1B@\x1DV\x01\x1B!\x00 \u{2028}fim";
    $c = PrintFormatter::clean($evil);
    if (preg_match('/[\x00-\x1F\x7F]/', $c) || str_contains($c, "\u{2028}")) throw new Exception('controle passou: ' . bin2hex($c));
    $b = PrintFormatter::escpos([['t' => $evil], ['t' => 'Pão à moda ½ “x”']], ['charset' => 'cp860', 'cut' => true]);
    eq(substr_count($b, "\x1B@"), 1); eq(substr_count($b, "\x1DV"), 1); // só o init e o corte do formatador
    if (!str_contains($b, "\x84")) throw new Exception('cp860 sem ã'); // ã = 0x84
    $a = PrintFormatter::escpos([['t' => 'Pão ½']]); if (preg_match('/[\x80-\xFF]/', $a)) throw new Exception('ascii com bytes altos');
});
t('estação: duas estações no mesmo destino nunca pegam o mesmo trabalho; reserva expira e volta à fila', function () use ($order, $key, $pid, $mkStation) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations');
    $a = $mkStation('Caixa', ['cozinha' => 'Cozinha-USB']); $b = $mkStation('Reserva', ['cozinha' => 'Cozinha-USB']);
    Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => (int)Db::val("SELECT ps.id FROM product_sizes ps WHERE ps.product_id = ? LIMIT 1", [$pid('Calabresa')]), 'qty' => 1]]), $key());
    $j1 = PrintStation::claim($a); $j2 = PrintStation::claim($b);
    if (!$j1 || $j2) throw new Exception('mesmo trabalho entregue a duas estações');
    eq(PrintStation::claim($a)['uuid'], $j1['uuid']); // reentrante: mesma reserva, mesmos bytes
    Db::exec("UPDATE print_jobs SET reserved_until = '2000-01-01 00:00:00'");
    $j3 = PrintStation::claim($b); eq($j3['uuid'], $j1['uuid']);
    throws(fn() => PrintStation::markSending($a, $j1['uuid']), 409); // a estação antiga perdeu a reserva
});
t('estação: ciclo reservado → enviando → enviado; falha antes do envio volta à fila; após envio vira incerto e não é reenviado', function () use ($order, $key, $pid, $mkStation) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations');
    $a = $mkStation('Caixa', ['cozinha' => 'Cozinha-USB']);
    $sz = (int)Db::val("SELECT ps.id FROM product_sizes ps WHERE ps.product_id = ? LIMIT 1", [$pid('Calabresa')]);
    foreach ([1, 2, 3] as $_) Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => $sz, 'qty' => 1]]), $key());
    $j = PrintStation::claim($a); PrintStation::markSending($a, $j['uuid']);
    eq(PrintStation::result($a, $j['uuid'], true, true, '')['status'], 'printed');
    $j = PrintStation::claim($a); // falha antes de enviar: impressora sumiu
    eq(PrintStation::result($a, $j['uuid'], false, false, 'Impressora não encontrada')['status'], 'queued');
    $j2 = PrintStation::claim($a); eq($j2['uuid'], $j['uuid']); eq($j2['attempt'], 2);
    PrintStation::markSending($a, $j2['uuid']);
    eq(PrintStation::result($a, $j2['uuid'], false, true, 'timeout')['status'], 'uncertain');
    $j3 = PrintStation::claim($a); // o incerto NÃO volta sozinho: o próximo é outro pedido
    if ($j3['uuid'] === $j2['uuid']) throw new Exception('reenviou resultado incerto');
    eq(Db::val('SELECT status FROM print_jobs WHERE uuid = ?', [$j2['uuid']]), 'uncertain');
    // envio sem confirmação por muito tempo => incerto (nunca automático para fila)
    PrintStation::markSending($a, $j3['uuid']); Db::exec("UPDATE print_jobs SET claimed_at = '2000-01-01 00:00:00' WHERE uuid = ?", [$j3['uuid']]); Printing::sweep();
    eq(Db::val('SELECT status FROM print_jobs WHERE uuid = ?', [$j3['uuid']]), 'uncertain');
    // reimpressão explícita e auditada de incerto
    Printing::retry((int)Db::val('SELECT id FROM print_jobs WHERE uuid = ?', [$j3['uuid']]), ['id' => 1, 'name' => 'Teste']);
    eq(Db::val('SELECT status FROM print_jobs WHERE uuid = ?', [$j3['uuid']]), 'queued');
});
t('assinatura QZ: só o envio reservado/validado é assinado; repetição, outra impressora, outro conteúdo e outras chamadas são recusados', function () use ($order, $key, $pid, $mkStation, $u) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations'); Db::exec('DELETE FROM print_sign_log');
    $a = $mkStation('Caixa', ['cozinha' => 'Cozinha-USB', 'copa' => 'Bar-USB']); $st = PrintStation::get($a);
    $sz = (int)Db::val("SELECT ps.id FROM product_sizes ps WHERE ps.product_id = ? LIMIT 1", [$pid('Calabresa')]);
    Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => $sz, 'qty' => 1]]), $key());
    $msg = fn(string $call, array $params, ?int $ts = null) => json_encode(['call' => $call, 'params' => $params, 'timestamp' => $ts ?? (int)(microtime(true) * 1000)]);
    $pm = fn(string $printer, string $b64) => ['printer' => ['name' => $printer], 'options' => ['copies' => 1], 'data' => [['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => $b64]]];
    $j = PrintStation::claim($a);
    // reservado, ainda não "enviando": nada de print assinado
    throws(fn() => QzSigner::sign($st, $u, $msg('print', $pm('Cozinha-USB', $j['payload']))), 403);
    PrintStation::markSending($a, $j['uuid']);
    $good = $msg('print', $pm('Cozinha-USB', $j['payload']));
    $r = QzSigner::sign($st, $u, $good);
    eq(openssl_verify(hash('sha256', $good), base64_decode($r['signature']), $qzX0 = openssl_x509_read((string)QzSigner::certificate()), OPENSSL_ALGO_SHA512), 1, 'assinatura não confere com o certificado');
    throws(fn() => QzSigner::sign($st, $u, $good), 409);                                                        // repetição
    throws(fn() => QzSigner::sign($st, $u, $msg('print', $pm('Bar-USB', $j['payload']))), 403);               // outra impressora
    throws(fn() => QzSigner::sign($st, $u, $msg('print', $pm('Cozinha-USB', base64_encode("\x1B@LIXO")))), 403); // outro conteúdo
    throws(fn() => QzSigner::sign($st, $u, $msg('print', $pm('Cozinha-USB', $j['payload']), (int)(microtime(true) * 1000) - 600000)), 403); // timestamp velho
    throws(fn() => QzSigner::sign($st, $u, $msg('file.read', ['path' => 'C:/x'])), 403);                          // chamada fora da lista
    throws(fn() => QzSigner::sign($st, $u, $msg('print', ['printer' => 'Cozinha-USB', 'data' => [['type' => 'pixel', 'data' => 'x']], 'extra' => 1])), 403);
    throws(fn() => QzSigner::sign($st, $u, 'não é json'), 403);
    eq(QzSigner::sign($st, $u, $msg('printers.find', []))['signature'] !== '', true);
    throws(fn() => QzSigner::sign($st, $u, $msg('printers.find', ['query' => str_repeat('x', 500)])), 403);
    $pr = $msg('print', $pm('Cozinha-USB', $j['payload']));
    PrintStation::setActive($a, false);
    throws(fn() => QzSigner::sign(PrintStation::get($a), $u, $pr), 403);                                        // estação desativada
    if ((int)Db::val("SELECT COUNT(*) FROM print_sign_log WHERE ok = 0") < 5) throw new Exception('recusas não registradas');
    eq((int)Db::val("SELECT COUNT(*) FROM print_sign_log WHERE ok = 1 AND call_name = 'print'"), 1);
});
t('assinatura: sem chave configurada o servidor recusa (modo manual do QZ) e nunca expõe a chave', function () use ($u, $mkStation) {
    $old = $_ENV['QZ_PRIVATE_KEY_PATH']; $_ENV['QZ_PRIVATE_KEY_PATH'] = '/inexistente/key.pem';
    $a = $mkStation('Sem chave', ['cozinha' => 'Cozinha-USB']);
    throws(fn() => QzSigner::sign(PrintStation::get($a), $u, json_encode(['call' => 'printers.find', 'params' => [], 'timestamp' => (int)(microtime(true) * 1000)])), 503);
    eq(QzSigner::status()['configured'], false);
    $_ENV['QZ_PRIVATE_KEY_PATH'] = $old;
    eq(QzSigner::status()['configured'], true);
    if (str_contains(json_encode(QzSigner::status()), 'PRIVATE')) throw new Exception('status vaza chave');
});
t('mapa de impressoras: só impressoras que a estação informou; só o proprietário configura (permissão settings)', function () use ($mkStation, $u) {
    $a = $mkStation('Mapa', ['cozinha' => 'Cozinha-USB']);
    throws(fn() => PrintStation::saveMap($a, ['copa' => ['printer' => 'Impressora-Inventada']], $u), 422);
    foreach (['manager', 'waiter'] as $role) if (in_array('settings', Auth::permsOf(['role' => $role, 'perms' => null]), true)) throw new Exception("$role pode configurar");
    if (!in_array('settings', Auth::permsOf(['role' => 'owner', 'perms' => null]), true)) throw new Exception('proprietário sem settings');
});
t('comanda de teste é marcada e não tem pedido', function () use ($u) {
    $id = Printing::enqueueTest('copa', $u); $j = Db::one('SELECT * FROM print_jobs WHERE id = ?', [$id]);
    eq($j['order_id'], null); eq($j['kind'], 'test');
    $txt = implode("\n", array_column(Printing::build($j)['lines'], 't'));
    if (!str_contains($txt, 'TESTE - NAO PRODUZIR')) throw new Exception('sem marca de teste');
});

echo "Configuração flexível de impressoras\n";
$realOpts = fn(array $over = []) => $over + ['bounds' => null, 'colorType' => 'color', 'copies' => 1, 'density' => 0, 'duplex' => false, 'fallbackDensity' => null, 'interpolation' => 'bicubic', 'jobName' => null, 'legacy' => false, 'margins' => 0, 'orientation' => null, 'paperThickness' => null, 'printerTray' => null, 'rasterize' => false, 'rotation' => 0, 'scaleContent' => true, 'size' => null, 'units' => 'in', 'forceRaw' => false, 'encoding' => null, 'spool' => null];
$qzMsg = fn(string $printer, array $data, array $opts) => json_encode(['call' => 'print', 'params' => ['printer' => ['name' => $printer], 'options' => $opts, 'data' => $data], 'timestamp' => (int)(microtime(true) * 1000)]);
t('assinatura aceita as opções REAIS do QZ (todos os padrões) e recusa opção perigosa', function () use ($order, $key, $pid, $u, $realOpts, $qzMsg) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations'); Db::exec('DELETE FROM print_sign_log');
    $a = PrintStation::create('Real', $u); PrintStation::heartbeat($a, ['qz' => true, 'printers' => ['Epson-USB']], $u);
    PrintStation::saveMap($a, ['cozinha' => ['printer' => 'Epson-USB', 'mode' => 'escpos', 'paper' => 80]], $u); PrintStation::control($a, 'arm', $u);
    Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => (int)Db::val('SELECT id FROM product_sizes WHERE product_id = ? LIMIT 1', [$pid('Calabresa')]), 'qty' => 1]]), $key());
    $j = PrintStation::claim($a); PrintStation::markSending($a, $j['uuid']); $st = PrintStation::get($a);
    $d = [['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => $j['payload']]];
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('Epson-USB', $d, $realOpts(['forceRaw' => true]))), 403);        // pular o driver: recusado
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('Epson-USB', $d, $realOpts(['spool' => ['size' => 1]]))), 403);
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('Epson-USB', $d, $realOpts(['copies' => 9]))), 403);
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('Epson-USB', $d, $realOpts(['inventada' => 1]))), 403);
    eq(QzSigner::sign($st, $u, $qzMsg('Epson-USB', $d, $realOpts()))['signature'] !== '', true);
});
t('modo HTML (impressora comum): página escapada, opções esperadas, assinatura só para pixel/html com o mesmo conteúdo', function () use ($order, $key, $pid, $u, $realOpts, $qzMsg) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations');
    $a = PrintStation::create('Casa', $u); PrintStation::heartbeat($a, ['qz' => true, 'printers' => ['EPSON L3150']], $u);
    $cfg = ['printer' => 'EPSON L3150', 'mode' => 'html', 'paper' => 210, 'cut' => 1, 'copies' => 2, 'manufacturer' => 'Epson', 'model' => 'L3150 (doméstica)'];
    PrintStation::saveMap($a, ['cozinha' => $cfg, 'copa' => $cfg, 'balcao' => $cfg], $u); PrintStation::control($a, 'arm', $u);
    $m = PrintStation::map($a); eq((int)$m['cozinha']['cut'], 0); eq((int)$m['cozinha']['paper'], 210); eq(count($m), 3);   // mesma fila nos 3 destinos; corte ignorado em HTML
    Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => (int)Db::val('SELECT id FROM product_sizes WHERE product_id = ? LIMIT 1', [$pid('Calabresa')]), 'qty' => 1]], ['name' => '<script>alert(1)</script>']), $key());
    $j = PrintStation::claim($a); eq($j['mode'], 'html'); eq($j['options'], ['units' => 'mm', 'margins' => 3, 'copies' => 2, 'scaleContent' => true]);
    if (str_contains($j['payload'], '<script>') || !str_contains($j['payload'], 'Calabresa')) throw new Exception('html não escapado ou incompleto');
    PrintStation::markSending($a, $j['uuid']); $st = PrintStation::get($a);
    $d = [['type' => 'pixel', 'format' => 'html', 'flavor' => 'plain', 'data' => $j['payload']]];
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('EPSON L3150', [['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => base64_encode($j['payload'])]], $realOpts($j['options']))), 403);
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('EPSON L3150', [['type' => 'pixel', 'format' => 'html', 'flavor' => 'plain', 'data' => $j['payload'] . '<p>x</p>']], $realOpts($j['options']))), 403);
    throws(fn() => QzSigner::sign($st, $u, $qzMsg('EPSON L3150', $d, $realOpts(['units' => 'mm', 'margins' => 3, 'copies' => 50, 'scaleContent' => true]))), 403);
    eq(QzSigner::sign($st, $u, $qzMsg('EPSON L3150', $d, $realOpts($j['options'])))['signature'] !== '', true);
});
t('estação parada só imprime TESTE; armar libera pedidos; pausa bloqueia; pendentes antigos podem ser descartados', function () use ($order, $key, $pid, $u) {
    Db::exec('DELETE FROM print_jobs'); Db::exec('DELETE FROM print_stations');
    $a = PrintStation::create('Parada', $u); PrintStation::heartbeat($a, ['qz' => true, 'printers' => ['Q1']], $u);
    PrintStation::saveMap($a, ['cozinha' => ['printer' => 'Q1', 'copies' => 2]], $u);
    $sz = (int)Db::val('SELECT id FROM product_sizes WHERE product_id = ? LIMIT 1', [$pid('Calabresa')]);
    foreach ([1, 2] as $_) Orders::create($order([['product_id' => $pid('Calabresa'), 'size_id' => $sz, 'qty' => 1]]), $key());
    eq(PrintStation::claim($a), null);                                            // não armada: pedidos não saem
    Printing::enqueueTest('cozinha', $u); $t = PrintStation::claim($a); eq($t['kind'], 'test');   // teste autorizado pelo clique
    Db::exec("UPDATE print_jobs SET status = 'printed' WHERE kind = 'test'");
    Db::exec("UPDATE print_jobs SET created_at = '2000-01-01 00:00:00' WHERE id = (SELECT MIN(id) FROM print_jobs)");
    $pv = PrintStation::armPreview($a); eq($pv['pending'], 2); eq($pv['old'], 1); eq($pv['pages'], 4);   // 2 pedidos × 2 vias
    $r = PrintStation::control($a, 'arm', $u, true); eq($r['dropped'], 1);
    PrintStation::control($a, 'pause', $u); eq(PrintStation::claim($a), null); PrintStation::control($a, 'resume', $u);
    if (!PrintStation::claim($a)) throw new Exception('armada e ativa deveria entregar');
    PrintStation::control($a, 'disarm', $u); eq((int)PrintStation::get($a)['armed'], 0);
    throws(fn() => PrintStation::control($a, 'inventada', $u), 422);
});
t('cadastro de impressoras: vias repetem o ESC/POS, margem, validações, identificador único e abas', function () use ($u) {
    Db::exec('DELETE FROM print_stations');
    $a = PrintStation::create('Loja 1', $u, 'Loja Principal'); eq(PrintStation::get($a)['code'], 'loja-principal');
    throws(fn() => PrintStation::create('Outra', $u, 'loja-principal'), 422);
    eq(PrintStation::get(PrintStation::create('X', $u, 'x y z!'))['code'], 'x-y-z');   // identificador é normalizado
    PrintStation::heartbeat($a, ['qz' => true, 'printers' => ['TM-T20'], 'tab' => 'aaa111'], $u); $h = PrintStation::heartbeat($a, ['qz' => true, 'tab' => 'bbb222'], $u); eq($h['tabs'], 2);
    PrintStation::saveMap($a, ['balcao' => ['printer' => 'TM-T20', 'paper' => 210, 'mode' => 'escpos', 'copies' => 9, 'margin' => 99, 'cols' => 5]], $u);
    $m = PrintStation::map($a)['balcao']; eq((int)$m['paper'], 80); eq((int)$m['copies'], 5); eq((int)$m['margin'], 10); eq((int)$m['cols'], 48);   // A4 só em HTML; limites; colunas padrão
    $b = PrintFormatter::escpos([['t' => 'x']], ['copies' => 3, 'margin' => 2, 'cut' => true]);
    eq(substr_count($b, "\x1B@"), 3); eq(substr_count($b, "\x1DV"), 3); if (!str_contains($b, '  x')) throw new Exception('margem ausente');
    throws(fn() => PrintStation::saveMap($a, ['cozinha' => ['printer' => 'Fila-Inexistente']], $u), 422);
});
t('permissão print_config: só proprietário; gerente só se autorizado explicitamente', function () {
    if (in_array('print_config', Auth::permsOf(['role' => 'manager', 'perms' => null]), true)) throw new Exception('gerente sem autorização já configura');
    if (!in_array('print_config', Auth::permsOf(['role' => 'manager', 'perms' => json_encode(['print', 'print_config'])]), true)) throw new Exception('autorização explícita ignorada');
    if (in_array('print_config', Auth::permsOf(['role' => 'waiter', 'perms' => json_encode(['print_config'])]), true)) throw new Exception('garçom não pode receber');
    if (!in_array('print_config', Auth::permsOf(['role' => 'owner', 'perms' => null]), true)) throw new Exception('proprietário sem permissão');
});

echo "Hospedagem sem disco persistente\n";
t('sessões no banco: gravar, ler, expirar e destruir', function () {
    $h = new DbSessions();
    $h->write('abc123', 'uid|i:7;'); eq($h->read('abc123'), 'uid|i:7;');
    $h->write('abc123', 'uid|i:8;'); eq($h->read('abc123'), 'uid|i:8;'); eq((int)Db::val("SELECT COUNT(*) FROM app_sessions WHERE id = 'abc123'"), 1);
    eq($h->read('inexistente'), '');
    Db::exec("UPDATE app_sessions SET updated_at = 1 WHERE id = 'abc123'"); $h->gc(3600); eq($h->read('abc123'), '');
    $h->write('x1', 'a'); $h->destroy('x1'); eq($h->read('x1'), '');
});
t('imagens no banco: grava, devolve só nomes válidos e o mesmo conteúdo', function () {
    $_ENV['UPLOAD_DRIVER'] = 'db';
    $im = imagecreatetruecolor(40, 30); ob_start(); imagejpeg($im, null, 80); $jpeg = (string)ob_get_clean();
    $path = Uploads::put($jpeg); if (!preg_match('#^/uploads/[a-f0-9]{16}\.jpg$#', $path)) throw new Exception("caminho: $path");
    $f = Uploads::get(basename($path)); eq($f['data'], $jpeg); eq($f['mime'], 'image/jpeg');
    eq(Uploads::get('../../.env'), null); eq(Uploads::get('0000000000000000.jpg'), null);
    unset($_ENV['UPLOAD_DRIVER']);
});
t('ambiente de testes: faixa, noindex e impressão física desligada por padrão', function () {
    $h = View::stagingMark('<html><head><title>x</title></head><body class="a"><main>oi</main></body></html>');
    if (!str_contains($h, 'AMBIENTE DE TESTES') || !str_contains($h, 'noindex')) throw new Exception('sem faixa/noindex');
    eq(substr_count($h, 'AMBIENTE DE TESTES'), 1);
    $_ENV['APP_ENV'] = 'staging'; eq(Printing::testEnvBlocked(), true); eq(Printing::claim(), null);
    $_ENV['PRINT_ENABLED'] = '1'; eq(Printing::testEnvBlocked(), false);
    unset($_ENV['APP_ENV'], $_ENV['PRINT_ENABLED']); eq(Printing::testEnvBlocked(), false);
});

echo "\nResultado: $pass passou, $fail falhou\n";
exit($fail ? 1 : 0);
