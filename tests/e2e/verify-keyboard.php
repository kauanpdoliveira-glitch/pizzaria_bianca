<?php
/** Teclado, foco, retorno do foco em janelas, ampliação (texto e página) — Chrome real (headless) contra o servidor de teste. */
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
$c = new Cdp(Cdp::pageWs(9333));
$c->call('Page.enable'); $c->call('Runtime.enable');
$c->call('Network.clearBrowserCookies');
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$B = 'http://127.0.0.1:8099'; $res = [];
$key = function (string $k, string $code, int $vk, $mods = 0) use ($c) { foreach (['keyDown', 'keyUp'] as $t) $c->call('Input.dispatchKeyEvent', ['type' => $t, 'key' => $k, 'code' => $code, 'windowsVirtualKeyCode' => $vk, 'modifiers' => $mods ?: 0] + ($k === 'Enter' && $t === 'keyDown' ? ['text' => "\r"] : [])); };
$set = fn(int $w, int $h = 900, float $dsf = 1.0) => $c->call('Emulation.setDeviceMetricsOverride', ['width' => $w, 'height' => $h, 'deviceScaleFactor' => $dsf, 'mobile' => $w < 700]);
$ring = "(()=>{const e=document.activeElement; if(!e||e===document.body) return null; const s=getComputedStyle(e); const vis=(s.outlineStyle!=='none'&&parseFloat(s.outlineWidth)>0)||(s.boxShadow&&s.boxShadow!=='none'); const r=e.getBoundingClientRect(); const top=document.querySelector('.top'); const covered=top&&!top.contains(e)&&getComputedStyle(top).position==='sticky'&&r.top<top.getBoundingClientRect().bottom-1&&r.bottom>0; return {tag:e.tagName+'.'+String(e.className).slice(0,18)+(e.textContent?' \"'+e.textContent.trim().slice(0,16)+'\"':''), ring:!!vis, covered:!!covered, inView:r.bottom>0&&r.top<innerHeight}})()";

// 1) Tab pela landing (desktop)
$set(1440); $c->navigate("$B/", 0.4); $c->pump(1.0);
$seq = []; for ($i = 0; $i < 16; $i++) { $key('Tab', 'Tab', 9); $c->pump(0.12); $seq[] = $c->js("JSON.stringify($ring)"); }
$seq = array_map(fn($s) => json_decode($s, true), $seq);
$noRing = array_values(array_filter($seq, fn($x) => $x && !$x['ring'])); $cov = array_values(array_filter($seq, fn($x) => $x && $x['covered']));
$res[] = ['teste' => 'Tab na landing (16 paradas)', 'sem_indicador_de_foco' => array_column($noRing, 'tag'), 'coberto_pelo_cabecalho' => array_column($cov, 'tag'), 'primeiras' => array_slice(array_column($seq, 'tag'), 0, 6)];

// 2) Tab até um elemento abaixo da dobra (item pré-escondido) -> deve aparecer e estar visível
$c->navigate("$B/", 0.4); $c->pump(0.8);
$vis = $c->js("(async()=>{const l=[...document.querySelectorAll('.foot a[href^=tel]')][0]; l.focus(); await new Promise(r=>setTimeout(r,700)); const p=l.closest('.mo-pre'); const o=getComputedStyle(l.closest('.foot-grid > div')).opacity; return JSON.stringify({opacity:o, preClass:!!p, inView:l.getBoundingClientRect().bottom<=innerHeight && l.getBoundingClientRect().top>=0, notUnderHeader:l.getBoundingClientRect().top>document.querySelector('.top').getBoundingClientRect().bottom})})()");
$res[] = ['teste' => 'foco por teclado em item do rodapé (pré-escondido) revela e fica visível', 'resultado' => json_decode($vis, true)];

// 3) Menu móvel por teclado: Enter abre, Esc fecha e devolve o foco
$set(390, 800); $c->navigate("$B/", 0.4); $c->pump(1.0);
$c->js("document.querySelector('.mnav summary').focus()"); $key('Enter', 'Enter', 13); $c->pump(0.5);
$open = $c->js('document.querySelector(".mnav").open'); $key('Escape', 'Escape', 27); $c->pump(0.5);
$res[] = ['teste' => 'menu móvel: Enter abre, Esc fecha, foco volta ao botão', 'abriu' => $open, 'fechou' => !$c->js('document.querySelector(".mnav").open'), 'foco_no_botao' => $c->js("document.activeElement===document.querySelector('.mnav summary')")];

// 4) Janela de montagem: abrir por teclado, Esc, retorno do foco
$set(1440); $c->navigate("$B/cardapio", 0.4); $c->pump(1.5);
$c->js("(()=>{const b=[...document.querySelectorAll('h3')].find(h=>h.innerText.trim()==='Margherita').closest('button'); window.__op=b; b.focus();})()"); $key('Enter', 'Enter', 13); $c->pump(0.8);
$dlg = json_decode($c->js("JSON.stringify({open:!!document.querySelector('dialog.pz[open]'), focusInside:!!document.activeElement.closest('dialog'), role:document.querySelector('dialog.pz').getAttribute('aria-labelledby')})"), true);
$key('Escape', 'Escape', 27); $c->pump(0.6);
$res[] = ['teste' => 'montagem da pizza: Enter abre, foco entra, Esc fecha, foco volta ao cartão', 'abriu' => $dlg['open'], 'foco_dentro' => $dlg['focusInside'], 'fechou' => !$c->js("!!document.querySelector('dialog.pz[open]')"), 'foco_volta_ao_cartao' => $c->js('document.activeElement===window.__op')];

// 5) Tab dentro do diálogo não escapa para a página atrás
$c->js("window.__op.click()"); $c->pump(0.6); $out = 0; for ($i = 0; $i < 30; $i++) { $key('Tab', 'Tab', 9); $c->pump(0.05); if (!$c->js("document.activeElement===document.body||!!document.activeElement.closest('dialog')")) $out++; }
$res[] = ['teste' => 'Tab ×30 dentro da montagem: foco nunca vai para o conteúdo atrás da janela (passar pelo <body>/barra do navegador é esperado)', 'vezes_no_conteudo_atras' => $out];
$key('Escape', 'Escape', 27); $c->pump(0.4);

// 6) Ampliação: texto 200% e página 200% (viewport equivalente 720 px) — landing, cardápio, checkout
foreach (['/', '/cardapio', '/checkout'] as $p) {
    $set(390, 800); $c->navigate("$B$p", 0.4); $c->pump(1.0);
    $c->js("(()=>{const s=document.createElement('style'); s.id='zoomtxt'; s.textContent='html{font-size:200%!important}'; document.head.append(s)})()"); $c->pump(0.5);
    $t = json_decode($c->js("JSON.stringify({overflowX:document.documentElement.scrollWidth-document.documentElement.clientWidth, headerH:Math.round(document.querySelector('.top').getBoundingClientRect().height), vh:innerHeight, h1Visible:(()=>{const h=document.querySelector('h1'); return h?h.getBoundingClientRect().width<=innerWidth:null})()})"), true);
    $res[] = ['teste' => "texto 200% em 390 px: $p", 'resultado' => $t];
    $set(720, 450, 2.0); $c->navigate("$B$p", 0.4); $c->pump(1.0);
    $res[] = ['teste' => "página 200% (720 px efetivos) em $p", 'overflowX' => $c->js('document.documentElement.scrollWidth-document.documentElement.clientWidth')];
}
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
