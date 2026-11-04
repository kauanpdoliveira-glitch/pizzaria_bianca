<?php
/** Varredura responsiva/acessibilidade com Chrome real (headless) contra o servidor de TESTE (porta 8099, cópia do banco). */
ob_implicit_flush(true);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/cdp.php';
$c = new Cdp(Cdp::pageWs(9333));
$c->call('Page.enable'); $c->call('Runtime.enable'); $c->call('Network.enable'); $c->call('Log.enable');
$c->call('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
$B = 'http://127.0.0.1:8099';
$pdo = new PDO('sqlite:' . getenv('DB_SQLITE_PATH'));
// contas de teste pelo mecanismo existente (Team::create com o proprietário como ator)
$owner = Db::one("SELECT * FROM users WHERE email = ? AND role = 'owner'", [e2e_owner()[0]]);
$sfx = bin2hex(random_bytes(3)); $pw = ['manager' => 'Gerente' . bin2hex(random_bytes(5)), 'waiter' => 'Garcom' . bin2hex(random_bytes(5))]; $em = ['manager' => "ui.gerente.$sfx@teste.local", 'waiter' => "ui.garcom.$sfx@teste.local"];
foreach (['manager', 'waiter'] as $r) Team::create(['name' => "UI $r", 'email' => $em[$r], 'password' => $pw[$r], 'role' => $r], $owner);
[$ownerEmail, $ownerPw] = e2e_owner();
$cred = ['owner' => [$owner['email'], $ownerPw], 'manager' => [$em['manager'], $pw['manager']], 'waiter' => [$em['waiter'], $pw['waiter']]];
$token = $pdo->query('SELECT public_token FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();

$audit = <<<'JS'
(() => {
  const vis = e => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
  const name = e => (e.getAttribute('aria-label') || (e.getAttribute('aria-labelledby') && [...e.getAttribute('aria-labelledby').split(' ')].map(i => document.getElementById(i)?.textContent || '').join(' ')) || e.textContent || e.value || e.title || e.querySelector('img[alt]')?.alt || (e.id && document.querySelector(`label[for="${CSS.escape(e.id)}"]`)?.textContent) || e.closest('label')?.textContent || e.placeholder || '').trim();
  const out = {};
  const de = document.documentElement;
  out.overflowX = de.scrollWidth - de.clientWidth;
  out.lang = de.lang; out.h1 = document.querySelectorAll('h1').length; out.main = document.querySelectorAll('main,[role=main]').length;
  out.imgNoAlt = [...document.querySelectorAll('img')].filter(i => !i.hasAttribute('alt')).length;
  out.unnamed = [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea,[role=button],summary')].filter(e => vis(e) && !name(e)).map(e => e.tagName + '.' + String(e.className).slice(0, 24) + (e.type ? ':' + e.type : '')).slice(0, 6);
  out.inputsNoLabel = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')].filter(e => vis(e) && !(e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.title || (e.id && document.querySelector(`label[for="${CSS.escape(e.id)}"]`)) || e.closest('label'))).map(e => (e.name || e.id || e.type)).slice(0, 6);
  out.small = innerWidth < 800 ? [...document.querySelectorAll('a[href],button,input[type=checkbox],input[type=radio],select,summary')].filter(e => { if (!vis(e)) return false; const r = e.getBoundingClientRect(); if (e.closest('p,li') && e.tagName === 'A' && !e.className) return false; if ((e.type === 'checkbox' || e.type === 'radio') && e.closest('label')) { const lr = e.closest('label').getBoundingClientRect(); return Math.min(lr.width, lr.height) < 32; } return Math.min(r.width, r.height) < 32; }).map(e => (e.className || e.tagName).toString().slice(0, 20) + ' ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height)).slice(0, 5) : [];
  const fixed = [...document.querySelectorAll('*')].filter(e => { const p = getComputedStyle(e).position; return (p === 'fixed' || p === 'sticky') && vis(e); }).map(e => ({ n: (e.className || e.tagName).toString().slice(0, 18), h: Math.round(e.getBoundingClientRect().height), top: Math.round(e.getBoundingClientRect().top) }));
  out.fixedBars = fixed.slice(0, 4);
  return out;
})()
JS;
$run = function (string $path, int $w, string $label) use ($c, $B, $audit) {
    $c->call('Emulation.setDeviceMetricsOverride', ['width' => $w, 'height' => $w < 700 ? 800 : 900, 'deviceScaleFactor' => 1, 'mobile' => $w < 700]);
    $c->events = []; $c->navigate($B . $path, 0.4); $c->pump(1.4);
    $r = $c->js("JSON.stringify($audit)"); $a = json_decode($r, true);
    $errs = 0; foreach ($c->events as $e) { if ($e['method'] === 'Log.entryAdded' && ($e['params']['entry']['level'] ?? '') === 'error') $errs++; if ($e['method'] === 'Runtime.exceptionThrown') $errs++; }
    $a['consoleErrors'] = $errs; $a['rota'] = $path; $a['w'] = $w; $a['papel'] = $label;
    return $a;
};
$login = function (string $role) use ($c, $B, $cred) {
    $c->call('Network.clearBrowserCookies'); $c->call('Emulation.setDeviceMetricsOverride', ['width' => 1440, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
    $c->navigate("$B/equipe", 0.4); $c->pump(0.8);
    $c->js("(()=>{const f=document.querySelector('form'); f.querySelector('[name=email]').value=" . json_encode($cred[$role][0]) . "; f.querySelector('[name=password]').value=" . json_encode($cred[$role][1]) . "; f.requestSubmit();})()");
    $c->pump(1.5); return $c->js('location.pathname');
};
$rows = []; $fmt = function (array $a) { $p = []; if ($a['overflowX'] > 1) $p[] = "rolagem-x +{$a['overflowX']}"; if ($a['imgNoAlt']) $p[] = "{$a['imgNoAlt']} img sem alt"; if ($a['unnamed']) $p[] = 'sem nome: ' . implode('|', $a['unnamed']); if ($a['inputsNoLabel']) $p[] = 'campo sem rótulo: ' . implode('|', $a['inputsNoLabel']); if ($a['small']) $p[] = 'alvo<32px: ' . implode('|', $a['small']); if ($a['consoleErrors']) $p[] = "console {$a['consoleErrors']} erro(s)"; if (!$a['lang']) $p[] = 'sem lang'; if ($a['h1'] !== 1) $p[] = "h1={$a['h1']}"; if ($a['main'] < 1) $p[] = 'sem main'; return $p; };
$report = function (array $a) use (&$rows, $fmt) { $p = $fmt($a); $rows[] = [$p ? 'ACHADO' : 'OK', $a['papel'], $a['rota'], $a['w'], implode('; ', $p), $a['fixedBars']]; echo ($p ? 'ACHADO ' : 'OK     ') . str_pad($a['papel'], 8) . str_pad($a['rota'], 28) . $a['w'] . ($p ? '  ' . implode('; ', $p) : '') . "\n"; };

// público
$c->call('Network.clearBrowserCookies');
$pub = ['/', '/historia', '/cardapio', '/checkout', '/equipe', '/equipe/recuperar'] + ($token ? [6 => "/pedido/$token"] : []);
foreach ([1440, 1024, 768, 390, 360] as $w) foreach ($pub as $p) $report($run($p, $w, 'público'));
// proprietário
echo 'login owner -> ' . $login('owner') . "\n";
$adm = ['/admin', '/admin/pedidos', '/admin/cardapio', '/admin/cardapio/produto/13', '/admin/cardapio/grupos/1', '/admin/salao', '/admin/caixa', '/admin/clientes', '/admin/relatorios', '/admin/executivo', '/admin/config', '/admin/usuarios', '/admin/acessos', '/admin/impressao', '/garcom'];
foreach ($adm as $p) $report($run($p, 1440, 'dono'));
foreach ([390, 768] as $w) foreach (['/admin', '/admin/cardapio', '/admin/salao', '/admin/caixa', '/admin/usuarios'] as $p) $report($run($p, $w, 'dono'));
// gerente
echo 'login manager -> ' . $login('manager') . "\n";
foreach (['/admin', '/admin/caixa', '/admin/salao', '/admin/cardapio'] as $p) $report($run($p, 1440, 'gerente'));
$report($run('/admin/caixa', 390, 'gerente'));
// garçom
echo 'login waiter -> ' . $login('waiter') . "\n";
foreach ([1440, 768, 390, 360] as $w) $report($run('/garcom', $w, 'garçom'));
file_put_contents(__DIR__ . '/a11y-resultado.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "FIM\n";

