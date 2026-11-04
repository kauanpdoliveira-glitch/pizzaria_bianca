<?php
/** Grava clipes de demonstração (screencast real do Chrome) e codifica em MP4/WebM com MediaRecorder. Servidor de TESTE 8099. */
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
$B = 'http://127.0.0.1:8099'; $OUT = $argv[1] ?? (__DIR__ . '/demo'); @mkdir($OUT, 0775, true);
$c = new Cdp(Cdp::pageWs(9333));
$c->call('Page.enable'); $c->call('Runtime.enable'); $c->call('Network.enable');
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$frames = [];
$c->handler = function ($m, $cdp) use (&$frames) {
    if ($m['method'] === 'Page.screencastFrame') { $frames[] = [$m['params']['metadata']['timestamp'], $m['params']['data']]; $cdp->fire('Page.screencastFrameAck', ['sessionId' => $m['params']['sessionId']]); }
};
$js = fn($e, $t = 30) => $c->js($e, $t);
$smooth = fn(int $to, int $ms) => $js("new Promise(res=>{const y0=scrollY,d=$to-y0,t0=performance.now(); const ease=t=>t<.5?2*t*t:1-Math.pow(-2*t+2,2)/2; (function f(n){const k=Math.min(1,(n-t0)/$ms); scrollTo(0,y0+d*ease(k)); k<1?requestAnimationFrame(f):res(1)})(t0)})", 20);
$click = function (float $x, float $y) use ($c) { foreach (['mouseMoved', 'mousePressed', 'mouseReleased'] as $t) $c->call('Input.dispatchMouseEvent', ['type' => $t, 'x' => $x, 'y' => $y, 'button' => $t === 'mouseMoved' ? 'none' : 'left', 'clickCount' => 1]); };
$center = function (string $sel) use ($js) { fwrite(STDERR, "centro: ".substr($sel,0,90)."\n"); $p = json_decode($js("JSON.stringify((()=>{const e=$sel; e.scrollIntoView({block:'center'}); const r=e.getBoundingClientRect(); return {x:r.left+r.width/2,y:r.top+r.height/2}})())"), true); return $p; };
$start = function (int $w, int $h) use ($c, &$frames) { $frames = []; $c->call('Emulation.setDeviceMetricsOverride', ['width' => $w, 'height' => $h, 'deviceScaleFactor' => 1, 'mobile' => $w < 700, 'screenWidth' => $w, 'screenHeight' => $h]); $c->call('Page.startScreencast', ['format' => 'jpeg', 'quality' => 72, 'maxWidth' => $w, 'maxHeight' => $h, 'everyNthFrame' => 1]); };
$stop = function () use ($c) { $c->call('Page.stopScreencast'); $c->pump(0.3); };

// ---------- Clipe 1: landing (desktop 1280x720) ----------
$c->call('Network.setCacheDisabled', ['cacheDisabled' => true]);
$c->navigate("$B/historia", 0.8); // página neutra antes de começar
$start(1280, 720);
$c->pump(0.4);
$c->call('Page.navigate', ['url' => "$B/"]); $c->pump(2.6);                         // carregamento inicial + entrada do hero
$smooth(430, 1500); $c->pump(0.5);                                                   // foto do hero e curva
$smooth(900, 1400); $c->pump(0.9);                                                   // categorias
$p = $center("document.querySelector('.cats .tile:nth-child(3)')"); $c->call('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $p['x'], 'y' => $p['y']]); $c->pump(1.2); // hover
$c->call('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => 5, 'y' => 5]);
$y = (int)$js("Math.round(document.querySelector('.facts').getBoundingClientRect().top + scrollY - 300)"); $smooth($y, 1300); $c->pump(1.0);   // números
$y = (int)$js("Math.round(document.querySelector('.cta-band').getBoundingClientRect().top + scrollY - 120)"); $smooth($y, 1300); $c->pump(1.2); // faixa vermelha
$y = (int)$js("Math.round(document.querySelector('#hist').getBoundingClientRect().top + scrollY - 160)"); $smooth($y, 1300); $c->pump(1.4);       // história
$smooth((int)$js('document.documentElement.scrollHeight') - 900, 1500); $c->pump(1.0);                                                         // rodapé
$smooth(0, 1800); $c->pump(0.8);                                                     // retorno ao topo
$p = $center("document.querySelector('.hero .btn-light')"); $click($p['x'], $p['y']); $c->pump(1.8); // CTA -> cardápio
$stop(); $clip1 = $frames;

// ---------- Clipe 2: compra (cardápio -> pizza -> sacola) ----------
$start(1280, 720); $c->pump(0.4);
$c->navigate("$B/cardapio", 0.5); $js("new Promise(async r=>{for(let i=0;i<50;i++){ if([...document.querySelectorAll('h3')].some(h=>h.innerText.trim()==='Margherita')) return r(1); await new Promise(x=>setTimeout(x,200)) } r(0)})"); $c->pump(1.0);
$p = $center("[...document.querySelectorAll('h3')].find(h=>h.innerText.trim()==='Margherita').closest('button')"); $c->pump(0.4); $click($p['x'], $p['y']); $c->pump(1.0);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] button.sz')].find(l=>/Grande/.test(l.innerText)); e.click(); return 1})()"); $c->pump(0.7);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Continuar/.test(b.innerText)); e.click(); return 1})()"); $c->pump(0.9);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] label')].find(l=>/Calabresa/.test(l.innerText)); e.click(); return 1})()"); $c->pump(0.8);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Continuar/.test(b.innerText)); e.click(); return 1})()"); $c->pump(1.0);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] label')].find(l=>/Borda de Catupiry/.test(l.innerText)); e.click(); return 1})()"); $c->pump(0.8);
$js("(()=>{const e=[...document.querySelectorAll('dialog.pz[open] .pz-foot button')].find(b=>/Adicionar/.test(b.innerText)); e.click(); return 1})()"); $c->pump(1.8);
$stop(); $clip2 = $frames;

// ---------- Clipe 3: celular 390x844 (menu móvel e rolagem) ----------
$c->navigate("$B/historia", 0.5);
$start(390, 844); $c->pump(0.4);
$c->call('Page.navigate', ['url' => "$B/"]); $c->pump(2.4);
$p = $center("document.querySelector('.mnav summary')"); $click($p['x'], $p['y']); $c->pump(1.4);          // abre
$c->call('Input.dispatchKeyEvent', ['type' => 'keyDown', 'key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27]); $c->call('Input.dispatchKeyEvent', ['type' => 'keyUp', 'key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27]); $c->pump(1.0); // fecha
$smooth(700, 1400); $c->pump(0.8); $smooth(1500, 1400); $c->pump(0.8); $smooth(2600, 1500); $c->pump(0.9);
$stop(); $clip3 = $frames;

// ---------- codificação ----------
$c->call('Target.createTarget', ['url' => 'about:blank']); usleep(600000);
$enc = null; foreach (json_decode(Cdp::httpGet(9333, '/json/list'), true) as $t) if ($t['type'] === 'page' && $t['url'] === 'about:blank') $enc = new Cdp($t['webSocketDebuggerUrl']);
if (!$enc) throw new RuntimeException('sem aba de codificação');
$enc->call('Runtime.enable');
$sup = $enc->js("JSON.stringify({mp4:MediaRecorder.isTypeSupported('video/mp4;codecs=avc1.42E01E'),mp4b:MediaRecorder.isTypeSupported('video/mp4'),webm:MediaRecorder.isTypeSupported('video/webm;codecs=vp9'),vp8:MediaRecorder.isTypeSupported('video/webm;codecs=vp8')})");
echo "suporte: $sup\n"; $s = json_decode($sup, true);
$mime = $s['mp4'] ? 'video/mp4;codecs=avc1.42E01E' : ($s['webm'] ? 'video/webm;codecs=vp9' : 'video/webm;codecs=vp8'); $ext = str_starts_with($mime, 'video/mp4') ? 'mp4' : 'webm';
foreach ([['landing', 1280, 720, $clip1], ['compra', 1280, 720, $clip2], ['celular-menu', 390, 844, $clip3]] as [$name, $w, $h, $fr]) {
    if (count($fr) < 20) { echo "$name: poucos quadros (" . count($fr) . ")\n"; continue; }
    $enc->js("window.__f=[]; window.__w=$w; window.__h=$h; 1");
    foreach (array_chunk($fr, 12) as $ch) { $enc->js('window.__f.push(...' . json_encode($ch) . '); 1', 60); }
    $t0 = $fr[0][0]; $dur = round(end($fr)[0] - $t0, 2);
    echo "$name: " . count($fr) . " quadros, $dur s de captura\n";
    $enc->js(<<<JS
window.__done=false; window.__blob=null;
(async()=>{ const cv=document.createElement('canvas'); cv.width=window.__w; cv.height=window.__h; const cx=cv.getContext('2d'); document.body.append(cv);
 const st=cv.captureStream(30); const rec=new MediaRecorder(st,{mimeType:'$mime',videoBitsPerSecond:3500000}); const ch=[]; rec.ondataavailable=e=>{if(e.data.size)ch.push(e.data)};
 rec.onstop=()=>{window.__blob=new Blob(ch,{type:'$mime'}); window.__done=true}; rec.start(250);
 const f=window.__f, t0=f[0][0], p0=performance.now();
 for(const [ts,b64] of f){ const wait=(ts-t0)*1000-(performance.now()-p0); if(wait>0) await new Promise(r=>setTimeout(r,wait)); const im=new Image(); im.src='data:image/jpeg;base64,'+b64; await im.decode(); cx.drawImage(im,0,0,cv.width,cv.height); }
 await new Promise(r=>setTimeout(r,900)); rec.stop(); })(); 1
JS);
    for ($i = 0; $i < 120 && !$enc->js('window.__done'); $i++) $enc->pump(1);
    $size = (int)$enc->js('window.__blob.size'); echo "  codificado: $size bytes\n";
    $enc->js("(async()=>{ window.__b64=await new Promise(r=>{const fr=new FileReader(); fr.onload=()=>r(fr.result.split(',')[1]); fr.readAsDataURL(window.__blob)}); return window.__b64.length })()", 120);
    $len = (int)$enc->js('window.__b64.length'); $out = '';
    for ($o = 0; $o < $len; $o += 700000) $out .= $enc->js("window.__b64.slice($o," . ($o + 700000) . ')', 60);
    $file = "$OUT/bianca-demo-$name.$ext"; file_put_contents($file, base64_decode($out)); echo "  salvo: $file (" . filesize($file) . " bytes)\n";
}
echo "FIM\n";
