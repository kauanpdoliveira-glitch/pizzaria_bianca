<?php
/** Medições comparáveis: mesmo Chrome (headless), mesma janela 1440x900, mesmo perfil de rede/CPU, cache frio e quente separados, 3 execuções, mediana. */
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
$c = new Cdp(Cdp::pageWs(9333));
$c->call('Page.enable'); $c->call('Runtime.enable'); $c->call('Network.enable');
$c->call('Emulation.setDeviceMetricsOverride', ['width' => 1440, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$c->call('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
window.__m = {cls:0, lcp:0, lt:0, ltn:0, ev:[]};
try{ new PerformanceObserver(l=>{for(const e of l.getEntries()) if(!e.hadRecentInput) window.__m.cls+=e.value}).observe({type:'layout-shift',buffered:true}); }catch(e){}
try{ new PerformanceObserver(l=>{for(const e of l.getEntries()) window.__m.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true}); }catch(e){}
try{ new PerformanceObserver(l=>{for(const e of l.getEntries()){window.__m.lt+=Math.max(0,e.duration-50);window.__m.ltn++}}).observe({type:'longtask',buffered:true}); }catch(e){}
try{ new PerformanceObserver(l=>{for(const e of l.getEntries()) window.__m.ev.push(Math.round(e.duration))}).observe({type:'event',durationThreshold:16,buffered:true}); }catch(e){}
JS]);
$median = function (array $a) { sort($a); $n = count($a); return $n ? ($n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2) : null; };
$collect = function (string $url) use ($c) {
    $c->navigate($url, 0.5); $c->pump(getenv('PERF_SETTLE') ? (float)getenv('PERF_SETTLE') : 2.5);
    return json_decode($c->js("JSON.stringify((()=>{const n=performance.getEntriesByType('navigation')[0]; const fcp=performance.getEntriesByName('first-contentful-paint')[0]; const res=performance.getEntriesByType('resource'); return {dcl:n.domContentLoadedEventEnd, load:n.loadEventEnd, fcp:fcp?fcp.startTime:0, lcp:window.__m.lcp, cls:window.__m.cls, tbt:window.__m.lt, reqs:res.length+1, kb:(n.encodedBodySize+res.reduce((a,r)=>a+r.encodedBodySize,0))/1024}})())"), true);
};
$profiles = ['sem_limite' => [1, null], 'lenta(4G ruim: CPU 4x, 1,6 Mbps, 150 ms)' => [4, [150, 1.6 * 1024 * 1024 / 8]]];
$variants = ($e = getenv('PERF_VARIANTS')) ? json_decode($e, true) : ['A' => 'http://127.0.0.1:8098', 'B' => 'http://127.0.0.1:8099']; // rótulo => URL base
$out = [];
foreach ($profiles as $pn => [$cpu, $net]) {
    if (getenv('PERF_PROFILES') && !str_contains($pn, getenv('PERF_PROFILES'))) continue;
    $c->call('Emulation.setCPUThrottlingRate', ['rate' => $cpu]);
    $c->call('Network.emulateNetworkConditions', $net ? ['offline' => false, 'latency' => $net[0], 'downloadThroughput' => $net[1], 'uploadThroughput' => 96000] : ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
    foreach (array_filter(['/' => 'home', '/cardapio' => 'cardapio'], fn($n) => !getenv('PERF_PAGES') || str_contains(getenv('PERF_PAGES'), $n)) as $path => $pg) foreach (['frio', 'quente'] as $cache) foreach ($variants as $vn => $base) {
        $runs = [];
        if ($cache === 'quente') { $c->call('Network.setCacheDisabled', ['cacheDisabled' => false]); $collect($base . $path); } // aquece
        for ($i = 0; $i < 3; $i++) {
            if ($cache === 'frio') { $c->call('Network.clearBrowserCache'); $c->call('Network.setCacheDisabled', ['cacheDisabled' => true]); } else $c->call('Network.setCacheDisabled', ['cacheDisabled' => false]);
            $runs[] = $collect($base . $path);
        }
        $row = []; foreach (['dcl', 'fcp', 'lcp', 'load', 'cls', 'tbt', 'reqs', 'kb'] as $k) $row[$k] = round($median(array_column($runs, $k)), $k === 'cls' ? 4 : 0);
        $out[] = ['perfil' => $pn, 'pagina' => $pg, 'cache' => $cache, 'versao' => $vn] + $row;
        echo json_encode(end($out), JSON_UNESCAPED_UNICODE) . "\n";
    }
}
// resposta a interacao: abrir a montagem de pizza (cardapio, cache quente, sem limite)
$c->call('Emulation.setCPUThrottlingRate', ['rate' => 1]); $c->call('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
foreach ($variants as $vn => $base) {
    $ds = [];
    for ($i = 0; $i < 3; $i++) {
        $c->navigate($base . '/cardapio', 0.5); $c->pump(1.5);
        $c->js("(()=>{const h=[...document.querySelectorAll('h3')].find(h=>h.innerText.trim()==='Calabresa'); h.closest('button').scrollIntoView({block:'center'}); })()"); $c->pump(0.5);
        $pos = json_decode($c->js("JSON.stringify((()=>{const h=[...document.querySelectorAll('h3')].find(h=>h.innerText.trim()==='Calabresa'); const r=h.closest('button').getBoundingClientRect(); return {x:r.left+r.width/2,y:r.top+r.height/2}})())"), true);
        $c->call('Input.dispatchMouseEvent', ['type' => 'mousePressed', 'x' => $pos['x'], 'y' => $pos['y'], 'button' => 'left', 'clickCount' => 1]);
        $c->call('Input.dispatchMouseEvent', ['type' => 'mouseReleased', 'x' => $pos['x'], 'y' => $pos['y'], 'button' => 'left', 'clickCount' => 1]);
        $c->pump(1.0);
        $ds[] = (int)max($c->js("Math.max(0,...window.__m.ev,0)"), 0);
    }
    $out[] = ['interacao' => 'abrir montagem da pizza (maior evento, ms)', 'versao' => $vn, 'execucoes' => $ds, 'mediana' => $median($ds)];
    echo json_encode(end($out), JSON_UNESCAPED_UNICODE) . "\n";
}
file_put_contents(getenv('PERF_OUT') ?: (__DIR__ . '/perf-resultado.json'), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "FIM\n";
