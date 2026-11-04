<?php
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
$c = new Cdp(Cdp::pageWs(9333));
$c->call('Page.enable'); $c->call('Runtime.enable'); $c->call('Network.enable');
$c->call('Emulation.setDeviceMetricsOverride', ['width' => 1440, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
$B = 'http://127.0.0.1:8080';
$res = [];
$sleepJs = fn($ms) => "new Promise(r=>setTimeout(r,$ms))";
$scroll = "(async()=>{ const sleep=ms=>new Promise(r=>setTimeout(r,ms)); const H=document.documentElement.scrollHeight; for(let y=0;y<=H;y+=240){ scrollTo(0,y); await sleep(90);} await sleep(900); return {pre:document.querySelectorAll('.mo-pre:not(.mo-in)').length, inn:document.querySelectorAll('.mo-in').length, hidden:[...document.querySelectorAll('.mo-pre')].filter(e=>getComputedStyle(e).opacity<0.99).length}; })()";
$conds = ['A natural (sem emulacao)' => null, 'B emulado no-preference' => 'no-preference', 'C emulado reduce' => 'reduce'];
foreach ($conds as $name => $val) {
    $c->call('Emulation.setEmulatedMedia', ['features' => $val ? [['name' => 'prefers-reduced-motion', 'value' => $val]] : []]);
    $c->call('Network.setCacheDisabled', ['cacheDisabled' => true]);
    $c->navigate("$B/", 0.6);
    $r = ['cond' => $name];
    $r['mq_reduce'] = $c->js("matchMedia('(prefers-reduced-motion: reduce)').matches");
    $r['h1_anim'] = $c->js("getComputedStyle(document.querySelector('.hero h1')).animationName");
    $r['h1_opacity_t0.6s'] = $c->js("getComputedStyle(document.querySelector('.hero h1')).opacity");
    $r['pre_on_load'] = $c->js("document.querySelectorAll('.mo-pre').length");
    $r['scroll_slow'] = $c->js($scroll, 60);
    $r['scroll_back_top'] = $c->js("(async()=>{scrollTo(0,0); await {$sleepJs(600)}; return {y:scrollY, pre:document.querySelectorAll('.mo-pre:not(.mo-in)').length}})()");
    $r['anims_running_after_5s'] = $c->js("(async()=>{await {$sleepJs(5000)}; return document.getAnimations().filter(a=>a.playState==='running').length})()");
    $r['overflow_x'] = $c->js("document.documentElement.scrollWidth-document.documentElement.clientWidth");
    // clicar no CTA durante a animacao de entrada
    $c->navigate("$B/", 0.1);
    $c->js("document.querySelector('.hero .btn-light').click()"); $c->pump(1.2);
    $r['cta_click_in_transition_goes_to'] = $c->js('location.pathname');
    $res[] = $r;
}
// falha do script de animacao: bloqueia motion.js
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$c->call('Network.setBlockedURLs', ['urls' => ['*app.js*']]);
$c->navigate("$B/", 1.2);
$res[] = ['cond' => 'D app.js (inclui o movimento) bloqueado: falha de script', 'pre' => $c->js("document.querySelectorAll('.mo-pre').length"), 'tiles_opacity' => $c->js("[...document.querySelectorAll('.tile')].map(t=>getComputedStyle(t).opacity).join(',')"), 'facts_opacity' => $c->js("[...document.querySelectorAll('.facts div')].map(t=>getComputedStyle(t).opacity).join(',')"), 'menu_cta' => $c->js("document.querySelector('.hero .btn-light').getBoundingClientRect().top>0")];
$c->call('Network.setBlockedURLs', ['urls' => []]);
$c->call('Emulation.setScriptExecutionDisabled', ['value' => true]);
$c->navigate("$B/", 1.0);
$res[] = ['cond' => 'E JavaScript desativado', 'pre' => 'n/a', 'facts_opacity' => $c->js("[...document.querySelectorAll('.facts div')].map(t=>getComputedStyle(t).opacity).join(',')", 5) ?? 'n/a'];
$c->call('Emulation.setScriptExecutionDisabled', ['value' => false]);
// navegacao repetida: sem acumular
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$tot = [];
foreach (['/', '/historia', '/', '/cardapio', '/', '/historia'] as $p) { $c->navigate($B . $p, 0.8); $tot[] = $p . ':' . $c->js("document.querySelectorAll('script[src*=app\\\\.js]').length") . ' app.js, ' . $c->js("document.getAnimations().length") . ' anims'; }
$res[] = ['cond' => 'F navegacao repetida', 'passos' => $tot];
// impressao: nada escondido
$c->call('Emulation.setEmulatedMedia', ['media' => 'print', 'features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$c->navigate("$B/", 1.0);
$res[] = ['cond' => 'G media print', 'hidden' => $c->js("[...document.querySelectorAll('.mo-pre')].filter(e=>getComputedStyle(e).opacity<0.99).length")];
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";


