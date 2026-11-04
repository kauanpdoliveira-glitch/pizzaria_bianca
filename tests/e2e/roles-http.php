<?php
/** Teste HTTP por perfil contra servidor de TESTE (cópia do banco de desenvolvimento, porta 8099). Não toca na produção nem no banco original. */
ob_implicit_flush(true); register_shutdown_function('out');
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/cdp.php';
$B = 'http://127.0.0.1:8099';
$pass = 0; $fail = 0; $rows = [];
function chk(string $perfil, string $nome, bool $ok, string $det = ''): void { global $pass, $fail, $rows; $ok ? $pass++ : $fail++; $rows[] = [$ok ? 'APROVADO' : 'FALHOU', $perfil, $nome, $det]; }
final class Cli {
    public string $jar; public string $csrf = '';
    function __construct(string $n) { $this->jar = sys_get_temp_dir() . "/bianca-jar-$n.txt"; @unlink($this->jar); }
    function req(string $m, string $path, $body = null, array $h = [], bool $json = false): array {
        global $B; $ch = curl_init($B . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_FOLLOWLOCATION => 0, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $m]);
        $hd = $h; if ($this->csrf) $hd[] = 'X-CSRF-Token: ' . $this->csrf;
        if ($body !== null) { if ($json) { $hd[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); } else { if (is_array($body) && $this->csrf) $body['_csrf'] = $this->csrf; curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body)); } }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hd);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $head = substr((string)$r, 0, $hs); $bd = substr((string)$r, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $l);
        if (preg_match('/data-csrf="([^"]+)"/', $bd, $c)) $this->csrf = $c[1];
        return ['code' => $code, 'loc' => $l[1] ?? '', 'body' => $bd, 'json' => json_decode($bd, true)];
    }
    function login(string $email, string $pw): array { $this->req('GET', '/equipe'); return $this->req('POST', '/equipe', ['email' => $email, 'password' => $pw]); }
    function page(string $path): array { $r = $this->req('GET', $path); return $r; }
    function api(string $m, string $path, $body = null, string $key = ''): array { return $this->req($m, $path, $body, $key ? ["Idempotency-Key: $key"] : [], true); }
}
[$ownerEmail, $ownerPw] = e2e_owner();
$sfx = bin2hex(random_bytes(3)); $mgrEmail = "gerente.$sfx@teste.local"; $wtrEmail = "garcom.$sfx@teste.local";
$mgrPw = 'Gerente' . bin2hex(random_bytes(5)); $wtrPw = 'Garcom' . bin2hex(random_bytes(5));

// ---------- Proprietário ----------
$o = new Cli('owner');
$r = $o->login($ownerEmail, $ownerPw); chk('proprietário', 'login e redirecionamento', $r['code'] === 302 && $r['loc'] === '/admin', "{$r['code']} {$r['loc']}");
$r = $o->page('/admin/usuarios'); chk('proprietário', 'abre cadastro de funcionários', $r['code'] === 200);
foreach (['/admin/executivo', '/admin/config', '/admin/acessos', '/admin/caixa', '/admin/salao', '/admin/cardapio'] as $p) { $r = $o->page($p); chk('proprietário', "acessa $p", $r['code'] === 200, (string)$r['code']); }
$r = $o->req('POST', '/admin/usuarios', ['action' => 'create', 'name' => 'Gerente Teste', 'email' => $mgrEmail, 'password' => $mgrPw, 'role' => 'manager', 'perms' => array_fill_keys(Auth::ROLE_DEFAULTS['manager'], 'on')]); chk('proprietário', 'cria gerente', $r['code'] === 302, (string)$r['code']);
$r = $o->req('POST', '/admin/usuarios', ['action' => 'create', 'name' => 'Garcom Teste', 'email' => $wtrEmail, 'password' => $wtrPw, 'role' => 'waiter', 'perms' => array_fill_keys(Auth::ROLE_DEFAULTS['waiter'], 'on')]); chk('proprietário', 'cria garçom', $r['code'] === 302, (string)$r['code']);
$r = $o->req('POST', '/admin/usuarios', ['action' => 'create', 'name' => 'Outro Dono', 'email' => "dono.$sfx@teste.local", 'password' => 'Dono' . bin2hex(random_bytes(5)), 'role' => 'owner']); chk('proprietário', 'não cria segundo proprietário', $r['code'] >= 400, (string)$r['code']);
$mid = (int)Db_val("SELECT id FROM users WHERE email='$mgrEmail'"); $wid = (int)Db_val("SELECT id FROM users WHERE email='$wtrEmail'");

// ---------- Gerente/caixa ----------
$m = new Cli('mgr');
$r = $m->login($mgrEmail, $mgrPw); chk('gerente', 'login e redirecionamento', $r['code'] === 302 && $r['loc'] === '/admin', "{$r['code']} {$r['loc']}");
$m->page('/admin');
foreach (['/admin/caixa', '/admin/salao', '/admin/cardapio', '/admin/pedidos'] as $p) { $r = $m->page($p); chk('gerente', "acesso operacional $p", $r['code'] === 200, (string)$r['code']); }
foreach (['/admin/usuarios', '/admin/executivo', '/admin/config', '/admin/acessos'] as $p) { $r = $m->page($p); chk('gerente', "bloqueado $p (tela)", in_array($r['code'], [403, 302], true), (string)$r['code']); }
$r = $m->req('POST', '/admin/usuarios', ['action' => 'create', 'name' => 'X', 'email' => "x.$sfx@teste.local", 'password' => 'Senha' . bin2hex(random_bytes(5)), 'role' => 'waiter']); chk('gerente', 'bloqueado: criar funcionário pela API/POST', $r['code'] === 403, (string)$r['code']);
$r = $m->req('POST', '/admin/usuarios', ['action' => 'update', 'id' => $mid, 'name' => 'Gerente Teste', 'role' => 'owner']); chk('gerente', 'não altera o próprio cargo', $r['code'] === 403, (string)$r['code']);
$r = $m->req('POST', '/admin/usuarios', ['action' => 'toggle', 'id' => $wid]); chk('gerente', 'não desativa funcionário', $r['code'] === 403, (string)$r['code']);
chk('gerente', 'cargo no banco continua gerente', Db_val("SELECT role FROM users WHERE id=$mid") === 'manager');

// ---------- Garçom ----------
$w = new Cli('wtr');
$r = $w->login($wtrEmail, $wtrPw); chk('garçom', 'login e redirecionamento para /garcom', $r['code'] === 302 && $r['loc'] === '/garcom', "{$r['code']} {$r['loc']}");
$r = $w->page('/garcom'); chk('garçom', 'abre a área do garçom', $r['code'] === 200);
foreach (['/admin', '/admin/caixa', '/admin/usuarios', '/admin/cardapio', '/admin/executivo', '/admin/config', '/admin/api/orders', '/admin/salao', '/admin/pedidos'] as $p) { $r = $w->req('GET', $p); chk('garçom', "bloqueado $p", in_array($r['code'], [403, 302], true), (string)$r['code']); }
$st = $w->api('GET', '/garcom/api/state'); chk('garçom', 'estado das mesas', $st['code'] === 200 && !empty($st['json']['tables']));
$table = null; foreach ($st['json']['tables'] as $t) if ($t['state'] === 'free') { $table = $t; break; }
chk('garçom', 'há mesa livre para o teste', (bool)$table);
$menu = json_decode(file_get_contents("$B/api/menu"), true); $cal = null; $gr = null;
foreach ($menu['categories'] as $c) foreach ($c['products'] as $p) if ($p['name'] === 'Calabresa') { $cal = $p; foreach ($p['sizes'] as $s) if ($s['name'] === 'Grande') $gr = $s; }
$item = fn(int $q) => ['product_id' => $cal['id'], 'size_id' => $gr['id'], 'qty' => $q];
$k = fn() => bin2hex(random_bytes(12));
$kA = $k();
$r = $w->api('POST', '/garcom/api/open', ['table_id' => $table['id'], 'name' => '', 'people' => 4], $k()); chk('garçom', 'abrir mesa sem nome é recusado (422)', $r['code'] === 422, (string)$r['code']);
$r = $w->api('POST', '/garcom/api/open', ['table_id' => $table['id'], 'name' => 'Família Silva', 'people' => 0], $k()); chk('garçom', 'abrir mesa sem pessoas é recusado (422)', $r['code'] === 422, (string)$r['code']);
$r = $w->api('POST', '/garcom/api/open', ['table_id' => $table['id'], 'name' => 'Família Silva', 'people' => 4], $kA); $A = $r['json']['tab_id'] ?? 0; $vA = $r['json']['visit_id'] ?? 0;
chk('garçom', 'abre mesa com nome e pessoas', $r['code'] === 201 && $A > 0, (string)$r['code']);
$r2 = $w->api('POST', '/garcom/api/open', ['table_id' => $table['id'], 'name' => 'Família Silva', 'people' => 4], $kA); chk('garçom', 'clique repetido (mesma chave) não duplica', ($r2['json']['tab_id'] ?? 0) === $A && ($r2['json']['duplicate'] ?? false) === true, "{$r2['code']}");
$numA = 'T' . strtoupper(substr($sfx, 0, 3)) . '1'; $numB = 'T' . strtoupper(substr($sfx, 0, 3)) . '2';
$r = $w->api('POST', "/garcom/api/tab/$A/link", ['number' => $numA]); chk('garçom', 'vincula comanda física', $r['code'] === 200, (string)$r['code']);
$kR = $k(); $r = $w->api('POST', "/garcom/api/tab/$A/round", ['items' => [$item(2)]], $kR); $ordA = $r['json']['order_id'] ?? 0; chk('garçom', 'lança itens e envia rodada', $r['code'] === 201 && $ordA > 0, (string)$r['code']);
$r = $w->api('POST', "/garcom/api/tab/$A/round", ['items' => [$item(2)]], $kR); chk('garçom', 'reenvio com a mesma chave não duplica o pedido', ($r['json']['order_id'] ?? 0) === $ordA, (string)$r['code']);
$r = $w->api('POST', "/garcom/api/tab/$A/round", ['items' => [$item(1)]], $k()); chk('garçom', 'nova rodada só com os itens novos', $r['code'] === 201, (string)$r['code']);
$r = $w->api('POST', "/garcom/api/tab/$A/bill"); chk('garçom', 'solicita a conta ao caixa', $r['code'] === 200, (string)$r['code']);
$r = $w->api('POST', "/garcom/api/tab/$A/round", ['items' => [$item(1)]], $k()); chk('garçom', 'conta pedida: lançar item é recusado (sem sucesso falso)', $r['code'] === 422, (string)$r['code']);
$tabA = $w->api('GET', "/garcom/api/tab/$A")['json']['tab'] ?? [];
$totalA = (int)($tabA['totals']['total'] ?? $tabA['total'] ?? 0);
$method = 'cash';
$r = $w->api('POST', "/garcom/api/tab/$A/payment", ['amount_cents' => 1000, 'method' => $method], $k()); chk('garçom', 'informa recebimento (fica pendente)', in_array($r['code'], [200, 201], true), $r['code'] . ' ' . ($r['json']['error'] ?? '') . " método=$method");
$antes = [Db_val("SELECT status FROM tabs WHERE id=$A"), Db_val("SELECT COUNT(*) FROM tab_payments WHERE status='confirmed'"), Db_val("SELECT COUNT(*) FROM tabs WHERE status='closed'")];
foreach ([['confirm_payment', ['id' => (int)Db_val("SELECT id FROM tab_payments WHERE tab_id=$A ORDER BY id LIMIT 1")]], ['close_tab', ['tab_id' => $A]], ['register_payment', ['tab_id' => $A, 'amount' => '10', 'method' => $method, 'key' => $k() . 'xx']], ['discount', ['tab_id' => $A, 'amount' => '5', 'reason' => 'x']]] as [$act, $pl]) {
    $r = $w->req('POST', '/admin/salao/acao', $pl + ['action' => $act]); chk('garçom', "bloqueado: $act pela API (302 = erro com aviso; conferido no banco abaixo)", in_array($r['code'], [403, 302], true), (string)$r['code']);
}
chk('garçom', 'tentativas administrativas não alteraram nada no banco', [Db_val("SELECT status FROM tabs WHERE id=$A"), Db_val("SELECT COUNT(*) FROM tab_payments WHERE status='confirmed'"), Db_val("SELECT COUNT(*) FROM tabs WHERE status='closed'")] === $antes && Db_val("SELECT COUNT(*) FROM tabs WHERE id=$A AND discount_cents>0") == 0, json_encode($antes));
$r = $w->req('POST', '/admin/usuarios', ['action' => 'create', 'name' => 'Y', 'email' => "y.$sfx@teste.local", 'password' => 'Senha' . bin2hex(random_bytes(5)), 'role' => 'manager']); chk('garçom', 'bloqueado: criar funcionário pela API', in_array($r['code'], [403, 302], true), (string)$r['code']);

// ---------- Cenário crítico ----------
$kB = $k(); $r = $w->api('POST', '/garcom/api/open', ['table_id' => $table['id'], 'name' => 'Grupo B', 'people' => 2, 'physical_number' => $numB], $kB);
$B_ = $r['json']['tab_id'] ?? 0; $vB = $r['json']['visit_id'] ?? 0;
chk('crítico', 'atendimento antigo aguarda o caixa e o garçom cria comanda independente (outro grupo, mesma mesa)', $r['code'] === 201 && $B_ > 0 && $vB !== $vA && Db_val("SELECT status FROM tabs WHERE id=$A") === 'awaiting_cashier', "A=tab $A/visita $vA aguardando · B=tab $B_/visita $vB (HTTP {$r['code']})");
$r = $w->api('POST', "/garcom/api/tab/$B_/round", ['items' => [$item(1)]], $k()); chk('crítico', 'garçom lança itens no grupo B', $r['code'] === 201, (string)$r['code']);
$tabB1 = $w->api('GET', "/garcom/api/tab/$B_")['json']['tab'] ?? [];
$totB1 = json_encode($tabB1['totals'] ?? $tabB1);
$r = $m->api('GET', "/garcom/api/tab/$A"); chk('gerente', 'vê a comanda A (aguardando fechamento)', $r['code'] === 200, (string)$r['code']);
// gerente confere o pagamento pendente e registra o restante, depois fecha A
$payId = Db_val("SELECT id FROM tab_payments WHERE tab_id=$A AND status='reported' ORDER BY id LIMIT 1");
$r = $m->req('POST', '/admin/salao/acao', ['action' => 'confirm_payment', 'id' => $payId]); chk('gerente', 'confirma recebimento informado pelo garçom (conferido no banco)', Db_val("SELECT status FROM tab_payments WHERE id=$payId") === 'confirmed', "HTTP {$r['code']} pagamento #$payId");
$tabA = $m->api('GET', "/garcom/api/tab/$A")['json']['tab'] ?? [];
$tt = $tabA['totals'] ?? []; $pend = (int)($tt['pending'] ?? 0);
$r = $m->req('POST', '/admin/salao/acao', ['action' => 'register_payment', 'tab_id' => $A, 'amount' => number_format($pend / 100, 2, ',', ''), 'method' => $method, 'key' => $k() . 'ab']); chk('gerente', 'registra o restante e confirma (conferido no banco)', Db_val("SELECT COUNT(*) FROM tab_payments WHERE tab_id=$A AND status='confirmed'") >= 2, "pendente era $pend centavos");
$r = $m->req('POST', '/admin/salao/acao', ['action' => 'close_tab', 'tab_id' => $A]); chk('gerente', 'fecha o atendimento antigo (comanda A) (conferido no banco)', Db_val("SELECT status FROM tabs WHERE id=$A") === 'closed', 'status='.Db_val("SELECT status FROM tabs WHERE id=$A"));
$stateAfter = $w->api('GET', '/garcom/api/state')['json'];
$mesa = null; foreach ($stateAfter['tables'] as $t) if ((int)$t['id'] === (int)$table['id']) $mesa = $t;
chk('crítico', 'a mesa continua indicando o novo atendimento ativo', $mesa && $mesa['state'] !== 'free', json_encode(['state' => $mesa['state'] ?? null]));
$tabs = $stateAfter['tabs']; $idsAbertas = array_map(fn($t) => (int)$t['id'], $tabs);
chk('crítico', 'comanda B segue aberta e A saiu da lista de abertas', in_array($B_, $idsAbertas, true) && !in_array($A, $idsAbertas, true), json_encode($idsAbertas));
$tabB2 = $w->api('GET', "/garcom/api/tab/$B_")['json']['tab'] ?? [];
chk('crítico', 'itens/pagamentos de B não mudaram com o fechamento de A', json_encode($tabB2['totals'] ?? $tabB2) === $totB1 && Db_val("SELECT COUNT(*) FROM tab_payments WHERE tab_id=$B_") == 0, 'B totals iguais, 0 pagamentos em B');
chk('crítico', 'pagamentos de A ficaram em A', Db_val("SELECT COUNT(*) FROM tab_payments WHERE tab_id=$A") >= 2, '');
// concorrência simples: dois fechamentos
$r1 = $m->req('POST', '/admin/salao/acao', ['action' => 'close_tab', 'tab_id' => $A]); chk('gerente', 'fechar de novo a mesma comanda não corrompe (idempotente ou recusado)', in_array($r1['code'], [302, 422, 409], true), (string)$r1['code']);
// desativar garçom derruba o acesso
$r = $o->req('POST', '/admin/usuarios', ['action' => 'toggle', 'id' => $wid]); chk('proprietário', 'desativa o garçom', $r['code'] === 302);
$r = $w->api('GET', '/garcom/api/state'); chk('proprietário', 'sessão do garçom desativado cai (401/403/redirect)', in_array($r['code'], [401, 403, 302], true), (string)$r['code']);
$w2 = new Cli('wtr2'); $r = $w2->login($wtrEmail, $wtrPw); chk('proprietário', 'garçom desativado não entra', $r['code'] === 401, (string)$r['code']);

function Db_val(string $sql) { $p = new PDO('sqlite:' . getenv('DB_SQLITE_PATH')); return $p->query($sql)->fetchColumn(); }
function out() { global $rows, $pass, $fail; foreach ($rows as $x) echo str_pad($x[0], 9) . ' | ' . str_pad($x[1], 12) . ' | ' . $x[2] . ($x[0] === 'FALHOU' || $x[3] !== '' ? '  [' . $x[3] . ']' : '') . "\n";
echo "\nTotal: $pass aprovados, $fail falharam\n"; }





