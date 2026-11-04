<?php
/**
 * Teste HTTP da impressão por estações (QZ) contra servidor de TESTE (banco TEMPORÁRIO, porta 8097). Não usa produção nem o banco de desenvolvimento.
 *   $env:DB_SQLITE_PATH="$env:TEMP\qz-e2e.sqlite"; $env:APP_URL="http://127.0.0.1:8097"
 *   php tests/e2e/qz-http.php seed ; php -S 127.0.0.1:8097 -t public public/index.php (outro terminal) ; php tests/e2e/qz-http.php run
 * Precisa de storage/qz (php bin/qz-dev-cert.php). Não envia nada a impressora alguma: valida roteamento, permissões, CSP, fila e assinatura.
 */
require __DIR__ . '/../../src/bootstrap.php';
$path = getenv('DB_SQLITE_PATH'); if (!$path || $path === ':memory:' || str_contains($path, 'bianca.sqlite')) exit("Defina DB_SQLITE_PATH para um arquivo temporário.\n");
$B = 'http://127.0.0.1:8097';
$pw = ['owner' => 'Dono' . 'Teste12345', 'mgr' => 'Gerente' . 'Teste12345', 'wtr' => 'Garcom' . 'Teste12345'];
if (($argv[1] ?? '') === 'seed') {
    @unlink($path); Db::reset(); Db::migrate(); require __DIR__ . '/../../database/seed_menu.php';
    foreach ([['Dono', 'dono@teste.local', 'owner', $pw['owner']], ['Gerente', 'gerente@teste.local', 'manager', $pw['mgr']], ['Garcom', 'garcom@teste.local', 'waiter', $pw['wtr']]] as [$n, $e, $r, $p])
        Db::insert('users', ['name' => $n, 'email' => $e, 'password_hash' => password_hash($p, PASSWORD_DEFAULT), 'role' => $r, 'active' => 1, 'created_at' => now()]);
    Settings::set('schedule', json_encode(array_fill(0, 7, [['00:00', '23:59']])));
    exit("seed ok\n");
}
$pass = 0; $fail = 0;
function chk(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ok   ' : '  FAIL ') . $n . ($ok ? '' : " -> $d") . "\n"; }
final class C {
    public string $jar; public string $csrf = '';
    function __construct(string $n) { $this->jar = sys_get_temp_dir() . "/bianca-qzjar-$n.txt"; @unlink($this->jar); }
    function req(string $m, string $p, $body = null, bool $json = false, bool $noCsrf = false): array {
        global $B; $ch = curl_init($B . $p); $hd = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $m]);
        if ($this->csrf && !$noCsrf) $hd[] = 'X-CSRF-Token: ' . $this->csrf;
        if ($body !== null) { if ($json) { $hd[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); } else { if ($this->csrf && !$noCsrf) $body['_csrf'] = $this->csrf; curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body)); } }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hd);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $head = substr((string)$r, 0, $hs); $bd = substr((string)$r, $hs);
        if (preg_match('/data-csrf="([^"]+)"/', $bd, $c) || preg_match('/name="_csrf" value="([^"]+)"/', $bd, $c)) $this->csrf = $c[1];
        return ['code' => $code, 'head' => $head, 'body' => $bd, 'json' => json_decode($bd, true)];
    }
    function login(string $e, string $p): array { $this->req('GET', '/equipe'); return $this->req('POST', '/equipe', ['email' => $e, 'password' => $p]); }
}
$o = new C('o'); $m = new C('m'); $w = new C('w'); $anon = new C('a');
chk('dono entra', $o->login('dono@teste.local', $pw['owner'])['code'] === 302);
chk('gerente entra', $m->login('gerente@teste.local', $pw['mgr'])['code'] === 302);
chk('garçom entra', $w->login('garcom@teste.local', $pw['wtr'])['code'] === 302);
$o->req('GET', '/admin/estacoes'); $m->req('GET', '/admin/estacoes');

// permissões
chk('anônimo não lê o certificado (401)', $anon->req('GET', '/admin/api/qz/certificate')['code'] === 401 || $anon->req('GET', '/admin/api/qz/certificate')['code'] === 302 || $anon->req('GET', '/admin/api/qz/certificate')['code'] === 401);
chk('anônimo não assina (401)', in_array($anon->req('POST', '/admin/api/estacao/1/sign', ['message' => '{}'], true, true)['code'], [401, 419], true));
chk('garçom não abre estações', in_array($w->req('GET', '/admin/estacoes')['code'], [302, 403], true));
$w->req('GET', '/garcom');
chk('garçom não assina', in_array($w->req('POST', '/admin/api/estacao/1/sign', ['message' => '{}'], true)['code'], [401, 403], true));
chk('gerente (impressão) abre a tela', $m->req('GET', '/admin/estacoes')['code'] === 200);
chk('gerente NÃO cria estação', $m->req('POST', '/admin/estacoes', ['name' => 'Invasora'])['code'] === 403);
chk('POST sem CSRF é recusado (419)', $o->req('POST', '/admin/estacoes', ['name' => 'Sem csrf'], false, true)['code'] === 419);

// dono cria e mapeia
$r = $o->req('POST', '/admin/estacoes', ['name' => 'Caixa e2e']); chk('dono cria estação', $r['code'] === 302, (string)$r['code']);
$sid = (int)Db::val("SELECT id FROM print_stations WHERE name = 'Caixa e2e'"); chk('estação gravada', $sid > 0);
chk('gerente NÃO mapeia', $m->req('POST', "/admin/estacoes/$sid/mapa", ['map' => ['cozinha' => ['printer' => 'X']]])['code'] === 403);
chk('gerente NÃO desativa', $m->req('POST', "/admin/estacoes/$sid/ativa", ['on' => 0])['code'] === 403);
chk('gerente NÃO pede teste físico', $m->req('POST', "/admin/estacoes/$sid/teste", ['sector' => 'cozinha'])['code'] === 403);
$st = $o->req('GET', "/admin/estacao/$sid"); chk('página da estação (dono)', $st['code'] === 200 && str_contains($st['body'], 'qz-station.js'));
chk('CSP da estação libera só o QZ local', str_contains($st['head'], 'connect-src') && str_contains($st['head'], 'wss://localhost:8181') && !str_contains($st['head'], 'unsafe-eval'));
chk('CSP das outras telas não libera websocket', !str_contains($o->req('GET', '/admin/estacoes')['head'], 'wss://localhost'));
$hb = $o->req('POST', "/admin/api/estacao/$sid/heartbeat", ['qz' => true, 'version' => '2.2.4', 'printers' => ['Cozinha-USB', 'Bar-USB', 'Balcao-LAN']], true); chk('heartbeat registra impressoras', $hb['code'] === 200, $hb['body']);
$o->req('POST', "/admin/estacoes/$sid/mapa", ['map' => ['cozinha' => ['printer' => 'Cozinha-USB', 'cols' => 48, 'charset' => 'ascii', 'cut' => 1], 'copa' => ['printer' => 'Bar-USB', 'cols' => 48, 'charset' => 'ascii', 'cut' => 1], 'balcao' => ['printer' => 'Balcao-LAN', 'cols' => 48, 'charset' => 'ascii', 'cut' => 1]]]);
chk('mapa salvo (3 destinos)', (int)Db::val('SELECT COUNT(*) FROM print_station_map WHERE station_id = ?', [$sid]) === 3);
$o->req('POST', "/admin/estacoes/$sid/mapa", ['map' => ['copa' => ['printer' => 'Inventada']]]); chk('impressora desconhecida recusada', Db::val("SELECT printer_name FROM print_station_map WHERE station_id = ? AND sector = 'copa'", [$sid]) === 'Bar-USB');

// pedido público → fila → reserva → envio → assinatura
$pid = fn(string $n) => (int)Db::val('SELECT id FROM products WHERE name = ?', [$n]); $psz = (int)Db::val('SELECT ps.id FROM product_sizes ps WHERE ps.product_id = ? LIMIT 1', [$pid('Calabresa')]);
$ch = curl_init($B . '/api/orders'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Idempotency-Key: ' . bin2hex(random_bytes(12))],
    CURLOPT_POSTFIELDS => json_encode(['type' => 'pickup', 'name' => 'Teste QZ', 'phone' => '(31) 99999-0000', 'payment' => 'cash', 'items' => [['product_id' => $pid('Calabresa'), 'size_id' => $psz, 'qty' => 1], ['product_id' => $pid('Coca-Cola Lata'), 'qty' => 1]]])]);
$ord = json_decode((string)curl_exec($ch), true); curl_close($ch);
chk('pedido público criado', !empty($ord['id']), json_encode($ord));
$secs = array_column(Db::all('SELECT sector FROM print_jobs WHERE order_id = ? ORDER BY sector', [$ord['id'] ?? 0]), 'sector'); chk('fila: balcão, copa e cozinha', $secs === ['balcao', 'copa', 'cozinha'], json_encode($secs));
chk('estação parada não recebe pedidos', (function($r){ return $r['code'] === 200 && array_key_exists('job', $r['json']) && $r['json']['job'] === null; })($o->req('POST', "/admin/api/estacao/$sid/claim", [], true)));
$o->req('POST', "/admin/api/estacao/$sid/control", ['action' => 'arm'], true);
$cl = $o->req('POST', "/admin/api/estacao/$sid/claim", [], true); $job = $cl['json']['job'] ?? null; chk('estação reserva um trabalho', $cl['code'] === 200 && $job && $job['hash'] === hash('sha256', base64_decode($job['payload'])), $cl['body']);
$cert = $o->req('GET', '/admin/api/qz/certificate'); chk('certificado público disponível; chave NUNCA', $cert['code'] === 200 && str_contains($cert['body'], 'BEGIN CERTIFICATE') && !str_contains($cert['body'], 'PRIVATE'));
$ts = (int)(microtime(true) * 1000);
$mk = fn(string $pr, string $b64) => json_encode(['call' => 'print', 'params' => ['printer' => ['name' => $pr], 'options' => ['bounds' => null, 'colorType' => 'color', 'copies' => 1, 'density' => 0, 'duplex' => false, 'fallbackDensity' => null, 'interpolation' => 'bicubic', 'jobName' => null, 'legacy' => false, 'margins' => 0, 'orientation' => null, 'paperThickness' => null, 'printerTray' => null, 'rasterize' => false, 'rotation' => 0, 'scaleContent' => true, 'size' => null, 'units' => 'in', 'forceRaw' => false, 'encoding' => null, 'spool' => null], 'data' => [['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => $b64]]], 'timestamp' => (int)(microtime(true) * 1000)]);
$good = $mk($job['printer'], $job['payload']);
chk('antes do "enviando" a assinatura de print é recusada', $o->req('POST', "/admin/api/estacao/$sid/sign", ['message' => $good], true)['code'] === 403);
chk('"enviando" confirma a reserva', $o->req('POST', "/admin/api/estacao/$sid/sending/{$job['uuid']}", [], true)['code'] === 200);
$good = $mk($job['printer'], $job['payload']);
$sg = $o->req('POST', "/admin/api/estacao/$sid/sign", ['message' => $good], true); chk('assina o envio validado', $sg['code'] === 200 && !empty($sg['json']['signature']), $sg['body']);
$x = openssl_x509_read($cert['body']); chk('assinatura confere com o certificado público', openssl_verify(hash('sha256', $good), base64_decode((string)($sg['json']['signature'] ?? '')), $x, OPENSSL_ALGO_SHA512) === 1);
chk('repetição recusada (409)', $o->req('POST', "/admin/api/estacao/$sid/sign", ['message' => $good], true)['code'] === 409);
chk('conteúdo adulterado recusado', $o->req('POST', "/admin/api/estacao/$sid/sign", ['message' => $mk($job['printer'], base64_encode("\x1B@FRAUDE"))], true)['code'] === 403);
chk('chamada arbitrária recusada', $o->req('POST', "/admin/api/estacao/$sid/sign", ['message' => json_encode(['call' => 'file.read', 'params' => ['path' => 'C:/Windows/win.ini'], 'timestamp' => $ts])], true)['code'] === 403);
$rs = $o->req('POST', "/admin/api/estacao/$sid/result/{$job['uuid']}", ['ok' => true, 'sent' => true], true); chk('resultado: enviado ao subsistema', ($rs['json']['status'] ?? '') === 'printed', $rs['body']);
chk('páginas não expõem chave privada', !str_contains($st['body'], 'PRIVATE KEY') && $anon->req('GET', '/storage/qz/private-key.pem')['code'] === 404 && $anon->req('GET', '/storage/qz/digital-certificate.txt')['code'] === 404);
chk('log de assinaturas guarda só digest/estação/usuário', (int)Db::val('SELECT COUNT(*) FROM print_sign_log WHERE ok = 1') >= 1 && !Db::val("SELECT 1 FROM print_sign_log WHERE reason LIKE '%PRIVATE%'"));
// autorização explícita do proprietário: gerente passa a configurar (sessão é renovada pelo session_version)
$pdo = new PDO('sqlite:' . $path); $pdo->exec("UPDATE users SET perms = '[\"orders\",\"print\",\"print_config\"]', session_version = session_version + 1 WHERE email = 'gerente@teste.local'");
$m2 = new C('m2'); $m2->login('gerente@teste.local', $pw['mgr']); $m2->req('GET', '/admin/config/impressao');
chk('gerente autorizado abre Configurações → Impressão', $m2->req('GET', '/admin/config/impressao')['code'] === 200);
chk('gerente autorizado cria estação', $m2->req('POST', '/admin/config/impressao', ['name' => 'Do gerente', 'code' => 'do-gerente'])['code'] === 302 && (int)Db::val("SELECT COUNT(*) FROM print_stations WHERE code = 'do-gerente'") === 1);
chk('gerente antigo (sem autorização) continua barrado', $m->req('POST', '/admin/config/impressao', ['name' => 'Outra'])['code'] === 403 || $m->req('POST', '/admin/config/impressao', ['name' => 'Outra'])['code'] === 302);
// ---- editor de layouts: permissões no backend, prévia sem efeitos, rascunho/ativação ----
$LP = '/admin/config/impressao/layouts?sector=cozinha';
chk('layouts: dono abre o editor', $o->req('GET', $LP)['code'] === 200);
chk('layouts: gerente autorizado abre', $m2->req('GET', $LP)['code'] === 200);
chk('layouts: gerente SEM autorização barrado', in_array($m->req('GET', $LP)['code'], [403, 302], true));
chk('layouts: garçom barrado', in_array($w->req('GET', $LP)['code'], [302, 403], true));
chk('layouts: anônimo não gera prévia', in_array($anon->req('POST', '/admin/api/layout/preview', ['sector' => 'cozinha'], true, true)['code'], [401, 419], true));
chk('layouts: garçom não gera prévia', in_array($w->req('POST', '/admin/api/layout/preview', ['sector' => 'cozinha'], true)['code'], [401, 403], true));
$lay = ['blocks' => array_map(fn($id) => ['id' => $id, 'show' => true, 'align' => 'left', 'size' => 'normal', 'bold' => true], ['order_number', 'datetime', 'modality', 'items', 'order_notes']), 'page' => ['separator' => '-', 'spacing' => 'normal'], 'header' => ['brand' => true, 'brand_text' => '<script>alert(1)</script>', 'subtitle' => true], 'footer' => ['text' => 'ok']];
$jobsBefore = (int)Db::val('SELECT COUNT(*) FROM print_jobs');
$pv = $o->req('POST', '/admin/api/layout/preview', ['sector' => 'cozinha', 'layout' => $lay, 'scenario' => 'half', 'mode' => 'escpos', 'paper' => 80], true);
chk('layouts: prévia devolve HTML escapado e perfil', $pv['code'] === 200 && str_contains($pv['json']['html'], '&lt;script&gt;') && !str_contains($pv['json']['html'], '<script>') && str_contains($pv['json']['note'], 'ESC/POS'), $pv['body']);
chk('layouts: prévia não cria trabalhos', (int)Db::val('SELECT COUNT(*) FROM print_jobs') === $jobsBefore);
$bad = $lay; $bad['blocks'][] = ['id' => 'script', 'show' => true];
chk('layouts: bloco desconhecido recusado (422)', $o->req('POST', '/admin/api/layout/preview', ['sector' => 'cozinha', 'layout' => $bad], true)['code'] === 422);
$form = ['sector' => 'cozinha', 'act' => 'save', 'id' => 0, 'name' => 'E2E rascunho', 'page' => ['separator' => '=', 'spacing' => 'airy'], 'header' => ['brand' => '1', 'brand_text' => 'BIANCA', 'subtitle' => '1'], 'footer_text' => 'e2e'];
foreach (['order_number', 'datetime', 'modality', 'items', 'order_notes'] as $i => $id) $form['blocks'][$i] = ['id' => $id, 'show' => '1', 'align' => 'left', 'size' => 'normal', 'bold' => '1'];
$o->req('GET', $LP);
chk('layouts: dono salva rascunho (não ativa, não imprime)', $o->req('POST', '/admin/impressao/layout', $form)['code'] === 302 && Db::val("SELECT status FROM print_layouts WHERE name = 'E2E rascunho'") === 'draft' && (int)Db::val('SELECT COUNT(*) FROM print_jobs') === $jobsBefore);
$lid = (int)Db::val("SELECT id FROM print_layouts WHERE name = 'E2E rascunho'");
chk('layouts: gerente sem autorização não salva nem ativa', in_array($m->req('POST', '/admin/impressao/layout', ['sector' => 'cozinha', 'act' => 'restore', 'id' => $lid])['code'], [403, 302], true) && Db::val('SELECT status FROM print_layouts WHERE id = ' . $lid) === 'draft');
chk('layouts: dono ativa', $o->req('POST', '/admin/impressao/layout', ['sector' => 'cozinha', 'act' => 'restore', 'id' => $lid])['code'] === 302 && Db::val('SELECT status FROM print_layouts WHERE id = ' . $lid) === 'active');
// ---- convites e conta da estação (identidade própria, mínimo privilégio) ----
$md = getenv('MAIL_LOG_DIR') ?: ''; $lastMail = function () use ($md) { $f = glob("$md/*.eml"); sort($f); return $f ? file_get_contents(end($f)) : ''; };
$hz = $anon->req('GET', '/saude'); chk('saúde: /saude responde sem dados sensíveis', $hz['code'] === 200 && ($hz['json']['ok'] ?? false) === true && !str_contains($hz['body'], 'sqlite') && !str_contains($hz['body'], 'password'));
chk('convite: token inválido mostra erro', str_contains($anon->req('GET', '/equipe/convite?token=' . str_repeat('a', 64))['body'], 'inválido'));
$o->req('GET', '/admin/usuarios');
chk('convite: gerente e garçom não convidam (403)', $m2->req('POST', '/admin/usuarios', ['action' => 'invite', 'name' => 'X', 'email' => 'x@teste.local', 'role' => 'waiter'])['code'] === 403 && $w->req('POST', '/admin/usuarios', ['action' => 'invite', 'name' => 'X', 'email' => 'x@teste.local', 'role' => 'waiter'])['code'] === 403);
chk('convite: dono convida a conta da estação', $o->req('POST', '/admin/usuarios', ['action' => 'invite', 'name' => 'PC da loja', 'email' => 'estacao@teste.local', 'role' => 'station'])['code'] === 302 && (int)Db::val("SELECT active FROM users WHERE email = 'estacao@teste.local'") === 0);
$eml = $lastMail(); preg_match('#/equipe/convite\?token=([a-f0-9]{64})#', $eml, $mm); $itok = $mm[1] ?? '';
chk('convite: e-mail com link do APP_URL e token de 64 hex', $itok !== '' && str_contains($eml, 'http://127.0.0.1:8097/equipe/convite'), substr($eml, 0, 200));
$st1 = $anon->req('GET', '/equipe/convite?token=' . $itok); chk('convite: página pede a senha própria', $st1['code'] === 200 && str_contains($st1['body'], 'Definir senha'));
chk('convite: senha fraca recusada', $anon->req('POST', '/equipe/convite', ['token' => $itok, 'password' => 'abc', 'password2' => 'abc'], false, true)['code'] === 422);
chk('convite: ativa com senha própria', str_contains($anon->req('POST', '/equipe/convite', ['token' => $itok, 'password' => 'EstacaoSenha2026', 'password2' => 'EstacaoSenha2026'], false, true)['body'], 'acesso ativado') || (int)Db::val("SELECT active FROM users WHERE email = 'estacao@teste.local'") === 1);
chk('convite: segundo uso do link é recusado', $anon->req('POST', '/equipe/convite', ['token' => $itok, 'password' => 'OutraSenha2026', 'password2' => 'OutraSenha2026'], false, true)['code'] === 422);
$sc = new C('st'); $r = $sc->login('estacao@teste.local', 'EstacaoSenha2026'); $loc = preg_match('/Location:\s*(\S+)/i', $r['head'], $lm) ? $lm[1] : ''; chk('estação: login e destino /admin/estacao', $r['code'] === 302 && str_ends_with($loc, '/admin/estacao'), $loc);
$sc->req('GET', '/admin/estacao'); chk('estação: abre a página da estação', $sc->req('GET', "/admin/estacao/$sid")['code'] === 200);
chk('estação: heartbeat e certificado permitidos', $sc->req('POST', "/admin/api/estacao/$sid/heartbeat", ['qz' => false], true)['code'] === 200 && $sc->req('GET', '/admin/api/qz/certificate')['code'] === 200);
foreach (['/admin', '/admin/usuarios', '/admin/config/impressao', '/admin/config/impressao/layouts', '/admin/caixa', '/admin/config'] as $p) chk("estação: sem acesso a $p", in_array($sc->req('GET', $p)['code'], [302, 403], true));
chk('estação: não cria/ativa nada (403)', $sc->req('POST', '/admin/estacoes', ['name' => 'X'])['code'] === 403 && in_array($sc->req('POST', '/admin/usuarios', ['action' => 'toggle', 'id' => 1])['code'], [403, 302], true));
$stid = (int)Db::val("SELECT id FROM users WHERE email = 'estacao@teste.local'");
chk('estação: dono desativa e o acesso cai', $o->req('POST', '/admin/usuarios', ['action' => 'toggle', 'id' => $stid])['code'] === 302 && in_array($sc->req('POST', "/admin/api/estacao/$sid/heartbeat", ['qz' => false], true)['code'], [401, 403], true));
echo "\nResultado: $pass passou, $fail falhou\n"; exit($fail ? 1 : 0);
