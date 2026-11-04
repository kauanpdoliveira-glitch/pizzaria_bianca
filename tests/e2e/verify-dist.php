<?php
/** Equivalência fonte x build minificado: estilos computados idênticos, 0 erros de console e fluxos de JS funcionando. */
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
if (!@file_get_contents('http://127.0.0.1:9333/json/version')) Cdp::launch(9333, sys_get_temp_dir() . '\\bianca-chrome-prof2', ['--window-size=1440,900']);
$c = new Cdp(Cdp::pageWs(9333)); $c->call('Page.enable'); $c->call('Runtime.enable'); $c->call('Network.enable'); $c->call('Log.enable');
$SRC = 'http://127.0.0.1:8098'; $DIST = 'http://127.0.0.1:8099';
$fp = <<<'JS'
(() => { const P=['display','position','width','height','margin','padding','color','backgroundColor','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','border','borderRadius','opacity','transform','boxShadow','zIndex','overflow','gridTemplateColumns','flexDirection','justifyContent','alignItems','gap','textAlign','textTransform','objectFit','visibility','top','left','right','bottom'];
 const out=[]; document.querySelectorAll('body *').forEach((e,i)=>{ const s=getComputedStyle(e); out.push(e.tagName+'.'+String(e.className).slice(0,30)+'|'+P.map(p=>s[p]).join('|')); });
 let h=0; const str=out.join('\n'); for(let i=0;i<str.length;i++){ h=(h*31+str.charCodeAt(i))|0 } return JSON.stringify({n:out.length,h:h,first:out}); })()
JS;
$errs = function () use ($c) { $n = 0; $list = []; foreach ($c->events as $e) { if (($e['method'] === 'Log.entryAdded' && ($e['params']['entry']['level'] ?? '') === 'error') || $e['method'] === 'Runtime.exceptionThrown') { $n++; $list[] = substr(json_encode($e['params']['entry']['text'] ?? ($e['params']['exceptionDetails']['exception']['description'] ?? '?'), JSON_UNESCAPED_UNICODE), 0, 160); } } return [$n, $list]; };
$token = (new PDO('sqlite:' . (getenv('E2E_DB') ?: sys_get_temp_dir() . '/bianca-test.sqlite')))->query('SELECT public_token FROM orders ORDER BY id ASC LIMIT 1')->fetchColumn();
$routes = ['/', '/historia', '/cardapio', '/checkout', "/pedido/$token", '/rota-que-nao-existe'];
$res = []; $ok = true;
foreach ([1440, 390] as $w) {
    $c->call('Emulation.setDeviceMetricsOverride', ['width' => $w, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => $w < 700]);
    $c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'reduce']]]);
    foreach ($routes as $r) {
        $h = [];
        foreach (['fonte' => $SRC, 'dist' => $DIST] as $k => $base) { $c->events = []; $c->navigate($base . $r, 0.5); $c->pump(2.0); $d = json_decode($c->js($fp), true); [$ne, $le] = $errs(); $h[$k] = [$d, $ne, $le]; }
        $same = $h['fonte'][0]['h'] === $h['dist'][0]['h'] && $h['fonte'][0]['n'] === $h['dist'][0]['n'];
        $diff = []; if (!$same) foreach ($h['fonte'][0]['first'] as $i => $line) if (($h['dist'][0]['first'][$i] ?? null) !== $line) { $A = explode('|', $line); $Bd = explode('|', (string)($h['dist'][0]['first'][$i] ?? '')); $props = []; foreach ($A as $k => $v) if (($Bd[$k] ?? null) !== $v) $props[] = $k . ':' . $v . '=>' . ($Bd[$k] ?? ''); $diff[] = $A[0] . ' ' . implode(' ', array_slice($props, 0, 3)); if (count($diff) >= 6) break; }
        $row = ['w' => $w, 'rota' => preg_replace('/[a-f0-9]{32}/', '<token>', $r), 'elementos' => $h['dist'][0]['n'], 'estilos_iguais' => $same, 'erros_console_fonte' => $h['fonte'][1], 'erros_console_dist' => $h['dist'][1]]; if ($diff) $row['diferencas'] = $diff; if ($h['dist'][2]) $row['erros'] = $h['dist'][2];
        $res[] = $row; if (!$same || $h['dist'][1]) $ok = false;
    }
}
// no-preference (animações ligadas): entrada do hero e revelações no build minificado
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]); $c->call('Emulation.setDeviceMetricsOverride', ['width' => 1440, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
$c->events = []; $c->navigate("$DIST/", 0.5); $c->pump(1.0);
$mot = json_decode($c->js("(async()=>{ const sleep=ms=>new Promise(r=>setTimeout(r,ms)); const pre=document.querySelectorAll('.mo-pre').length; const an=getComputedStyle(document.querySelector('.hero h1')).animationName; for(let y=0;y<=document.documentElement.scrollHeight;y+=240){scrollTo(0,y); await sleep(80)} await sleep(800); return JSON.stringify({pre_ao_carregar:pre, animacao_h1:an, ainda_escondidos:document.querySelectorAll('.mo-pre:not(.mo-in)').length, revelados:document.querySelectorAll('.mo-in').length}) })()"), true);
$res[] = ['teste' => 'movimento no build minificado (no-preference emulado)'] + $mot + ['erros_console' => $errs()[0]];
// fluxo de compra no build minificado
$c->call('Emulation.setDeviceMetricsOverride', ['width' => 1280, 'height' => 720, 'deviceScaleFactor' => 1, 'mobile' => false]);
$c->events = []; $c->navigate("$DIST/cardapio", 0.5); $c->js("localStorage.clear(); 1"); $c->navigate("$DIST/cardapio", 0.5);
$c->js("new Promise(async r=>{for(let i=0;i<50;i++){ if([...document.querySelectorAll('h3')].some(h=>h.innerText.trim()==='Margherita')) return r(1); await new Promise(x=>setTimeout(x,200)) } r(0)})");
$st = fn() => $c->js("JSON.stringify({step:document.querySelector('dialog.pz[open] h3.stp')?.innerText.replace(/\\n/g,' '), sel:document.querySelector('dialog.pz[open] .pz-body')?.innerText.match(/\\d de \\d selecionados/)?.[0]})");
$c->js("[...document.querySelectorAll('h3')].find(h=>h.innerText.trim()==='Margherita').closest('button').click()"); $c->pump(0.6);
$c->js("[...document.querySelectorAll('dialog.pz[open] button.sz')].find(l=>/Grande/.test(l.innerText)).click()"); $c->pump(0.3);
$c->js("[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Continuar/.test(b.innerText)).click()"); $c->pump(0.5); $s2 = $st();
$c->js("[...document.querySelectorAll('dialog.pz[open] label')].find(l=>/Calabresa/.test(l.innerText)).click()"); $c->pump(0.4); $s2b = $st();
$c->js("[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Continuar/.test(b.innerText)).click()"); $c->pump(0.5);
$c->js("[...document.querySelectorAll('dialog.pz[open] label')].find(l=>/Borda de Catupiry/.test(l.innerText)).click()"); $c->pump(0.3);
$c->js("[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Adicionar/.test(b.innerText)).click()"); $c->pump(0.8);
$cart = $c->js("JSON.stringify({count:document.querySelector('#cart-count')?.innerText, total:document.querySelector('#cart-total')?.innerText, toast:document.querySelector('#toast')?.innerText})");
$c->navigate("$DIST/checkout", 0.5); $c->pump(1.0);
$c->js("(()=>{const f=document.querySelector('form'); f.querySelector('input[value=pickup]').click(); const set=(n,v)=>{const e=f.querySelector('[name='+n+']'); e.value=v; e.dispatchEvent(new Event('input',{bubbles:true}))}; set('name','Teste Minificado'); set('phone','(31) 99999-0001'); f.querySelector('input[name=payment][value=cash]').click(); const cb=f.querySelector('input[type=checkbox]'); if(cb&&!cb.checked) cb.click(); 1})()"); $c->pump(0.3);
$c->js("(()=>{const f=document.querySelector('form'); const b=[...f.querySelectorAll('button')].find(b=>b.type==='submit'||/Enviar|Finalizar|Confirmar/.test(b.innerText)); b.click(); 1})()"); $c->pump(3.0);
$track = $c->js("JSON.stringify({url:location.pathname.replace(/[a-f0-9]{32}/,'<token>'), texto:document.querySelector('main')?.innerText.replace(/\\n+/g,' | ').slice(0,200)})");
$res[] = ['teste' => 'compra completa no build minificado', 'etapa2' => $s2, 'etapa2_com_2_sabores' => $s2b, 'sacola' => json_decode($cart, true), 'acompanhamento' => json_decode($track, true), 'erros_console' => $errs()[0]];
// painéis (JS minificado do admin/garçom) como proprietário
[$ownerEmail, $pw] = e2e_owner();
$c->call('Network.clearBrowserCookies'); $c->navigate("$DIST/equipe", 0.4); $c->pump(0.6);
$c->js("(()=>{const f=document.querySelector('form'); f.querySelector('[name=email]').value=" . json_encode($ownerEmail) . "; f.querySelector('[name=password]').value=" . json_encode($pw) . "; f.requestSubmit();})()"); $c->pump(1.6);
foreach (['/admin' => ".ord, .board, #board", '/admin/cardapio' => 'table, .plist, .cat-col', '/admin/salao' => 'main h1', '/admin/caixa' => 'main h1', '/admin/usuarios' => 'form', '/garcom' => '.trow, .gtabs'] as $p => $sel) {
    $c->events = []; $c->navigate($DIST . $p, 0.5); $c->pump(2.5);
    $found = (int)$c->js("document.querySelectorAll(" . json_encode($sel) . ").length"); $js = $c->js("[...document.scripts].map(s=>s.src.split('/').pop().split('?')[0]).filter(Boolean).join(',')");
    $res[] = ['painel' => $p, 'elementos_esperados' => $found, 'scripts' => $js, 'erros_console' => $errs()[0]]; if (!$found || $errs()[0]) $ok = false;
}
file_put_contents(__DIR__ . '/dist-equivalencia.json', json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
foreach ($res as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
echo $ok ? "TUDO OK\n" : "HA ACHADOS\n";
