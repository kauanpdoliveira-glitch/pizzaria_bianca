<?php
/** Confere que cada clipe abre, tem duração, reproduz (currentTime avança) e tem movimento real (diferença entre quadros amostrados). */
ob_implicit_flush(true);
require __DIR__ . '/cdp.php';
$c = new Cdp(Cdp::pageWs(9333)); $c->call('Page.enable'); $c->call('Runtime.enable');
$dir = $argv[1] ?? (__DIR__ . '/demo'); $out = [];
foreach (glob("$dir/*.{mp4,webm}", GLOB_BRACE) as $f) {
    $c->navigate('http://127.0.0.1:8097/', 0.6); $name = rawurlencode(basename($f));
    $r = $c->js(str_replace('__NAME__', $name, <<<'JS'
(async () => { const NAME = "__NAME__";
  const blob = await (await fetch('/' + NAME)).blob(); const v = document.createElement('video'); v.src = URL.createObjectURL(blob); document.body.append(v);
  v.muted = true; await new Promise(r => v.readyState >= 1 ? r() : v.addEventListener('loadedmetadata', r, {once: true}));
  let dur = v.duration;
  if (!isFinite(dur)) { v.currentTime = 1e6; await new Promise(r => v.addEventListener('seeked', r, {once: true})); dur = v.duration; if (!isFinite(dur)) dur = v.currentTime; v.currentTime = 0; await new Promise(r => v.addEventListener('seeked', r, {once: true})); }
  const out = {w: v.videoWidth, h: v.videoHeight, dur: +dur.toFixed(2)};
  // reprodução real: currentTime precisa avançar
  await v.play().catch(e => out.playErro = String(e)); const t0 = v.currentTime; await new Promise(r => setTimeout(r, 1500)); out.avancou_em_1_5s = +(v.currentTime - t0).toFixed(2); v.pause();
  // movimento real: amostra 14 pontos, compara pixels entre amostras vizinhas
  const cv = document.createElement('canvas'); cv.width = 160; cv.height = Math.round(160 * v.videoHeight / v.videoWidth); const cx = cv.getContext('2d', {willReadFrequently: true});
  const frames = [];
  for (let i = 0; i < 14; i++) { v.currentTime = Math.min(dur - 0.05, (dur * i) / 13); await new Promise(r => v.addEventListener('seeked', r, {once: true})); cx.drawImage(v, 0, 0, cv.width, cv.height); frames.push(cx.getImageData(0, 0, cv.width, cv.height).data.slice()); }
  const diffs = []; for (let i = 1; i < frames.length; i++) { let s = 0; const a = frames[i - 1], b = frames[i]; for (let k = 0; k < a.length; k += 4) s += Math.abs(a[k] - b[k]) + Math.abs(a[k + 1] - b[k + 1]) + Math.abs(a[k + 2] - b[k + 2]); diffs.push(+(s / (a.length / 4) / 3).toFixed(1)); }
  out.diferenca_media_entre_amostras = diffs; out.amostras_distintas = diffs.filter(d => d > 1).length;
  let blank = 0; for (const f of frames) { let m = 0; for (let k = 0; k < f.length; k += 4) m += f[k] + f[k + 1] + f[k + 2]; if (m / (f.length / 4) / 3 < 3) blank++; } out.quadros_pretos = blank;
  return JSON.stringify(out);
})()
JS), 90);
    $out[basename($f)] = json_decode($r, true) + ['bytes' => filesize($f)];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
