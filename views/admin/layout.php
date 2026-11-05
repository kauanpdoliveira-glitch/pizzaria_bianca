<?php
// Somente módulos que existem; cada um aparece só para quem tem a permissão (o servidor também valida).
$nav = ['board' => ['/admin', 'Gestão de pedidos', 'orders', 'orders'], 'floor' => ['/admin/salao', 'Mesas e comandas', 'floor', 'store'], 'cash' => ['/admin/caixa', 'Caixa', 'cashier', 'card'], 'executive' => ['/admin/executivo', 'Visão executiva', 'executive', 'reports'], 'reports' => ['/admin/relatorios', 'Vendas', 'reports', 'reports'], 'history' => ['/admin/pedidos', 'Histórico', 'orders', 'history'],
        'customers' => ['/admin/clientes', 'Clientes', 'customers', 'customers'], 'menu' => ['/admin/cardapio', 'Cardápio', 'menu', 'menu'], 'print' => ['/admin/impressao', 'Impressão', 'print', 'print'],
        'config' => ['/admin/config', 'Configurações', 'settings', 'config'], 'users' => ['/admin/usuarios', 'Usuários e permissões', 'users', 'users'], 'access' => ['/admin/acessos', 'Auditoria de acessos', 'audit', 'history']];
Auth::start(); $flash = $_SESSION['flash'] ?? null; $flashErr = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash'], $_SESSION['flash_err']);
$isBoard = $page === 'admin/board';
$agentCls = ['online' => 'ok', 'offline' => 'bad', 'never' => 'warn'][$agent['state']];
$heading = $isBoard ? 'Gestão de pedidos' : $title;
$ic = fn(string $n, string $cls = 'ic') => '<svg class="' . $cls . '" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><?php require APP_ROOT . '/views/_icons_head.php'; ?>
<title><?= e($title) ?> — Painel Bianca</title><link rel="stylesheet" href="<?= View::asset('assets/brand.css') ?>"><link rel="stylesheet" href="<?= View::asset('assets/admin.css') ?>"></head>
<body data-csrf="<?= e($csrf) ?>" data-page="<?= e($page) ?>" data-can-print="<?= Auth::can('print') ? '1' : '0' ?>" data-can-customers="<?= Auth::can('customers') ? '1' : '0' ?>">
<?php require APP_ROOT . '/views/admin/icons.php'; ?>
<div class="shell">
<aside class="side" id="side" aria-label="Menu do painel">
  <a class="side-brand brand-band" href="/admin" aria-label="Bianca Pizzaria — início do painel"><?= View::brand('side') ?></a>
  <div class="side-store"><?= $ic('store') ?><span>Bianca Pizzaria</span></div>
  <p class="store-pill <?= $storeNow['open'] ? 'ok' : 'bad' ?>" id="store-pill" role="status"><span class="dot"></span><span id="store-text"><?= $storeNow['open'] ? 'Loja aberta' : e($storeNow['reason'] ?: 'Loja fechada') ?></span></p>
  <nav class="side-nav"><?php foreach ($nav as $k => [$href, $label, $perm, $icon]) if (Auth::can($perm)): ?>
    <a href="<?= $href ?>" <?= $activeNav === $k ? 'aria-current="page"' : '' ?>><?= $ic($icon) ?><span><?= $label ?></span></a><?php endif; ?></nav>
</aside>
<div class="scrim" id="scrim" hidden></div>
<div class="main">
  <header class="hdr">
    <button class="icon-btn only-narrow" id="side-toggle" type="button" aria-controls="side" aria-expanded="false" aria-label="Abrir menu"><?= $ic('burger') ?></button>
<?php
    // telas principais: o título vem do cabeçalho padronizado; a h1 própria da view (se houver) é removida para não duplicar
    if (in_array($page, ['admin/salao', 'admin/caixa', 'admin/executivo', 'admin/reports', 'admin/history', 'admin/customers', 'admin/menu', 'admin/stations', 'admin/print', 'admin/config', 'admin/users', 'admin/access'], true)) $content = preg_replace('#<h1[^>]*>.*?</h1>#su', '', (string)$content, 1);
    $tag = str_contains((string)$content, '<h1') ? 'p' : 'h1'; /* uma só h1 por tela */
    $subs = ['board' => '', 'floor' => 'Acompanhe as mesas, os atendimentos e as comandas do salão.', 'cash' => 'Turno, recebimentos e conferência do caixa.', 'executive' => 'Panorama da operação da Bianca Pizzaria.', 'reports' => 'Vendas do período, por canal e forma de pagamento.', 'history' => 'Arquivo de pedidos da loja, com busca, filtros e detalhes.', 'customers' => 'Gerencie os clientes da sua pizzaria.', 'menu' => 'Cadastre e organize os produtos do cardápio, com categorias, complementos e opções.', 'print' => 'Configure impressoras, filas e layouts de impressão da sua pizzaria.', 'config' => 'Ajuste as informações e regras da sua loja.', 'users' => 'Gerencie a equipe, convide novos usuários e defina as permissões de acesso.', 'access' => 'Acompanhe os acessos ao sistema, as sessões e os eventos de segurança.'];
    $navIcon = $nav[$activeNav][3] ?? 'store'; ?>
    <div class="hdr-spacer"></div>
    <div class="hdr-actions">
      <a class="icon-btn <?= $agentCls ?>" id="agent-ic" href="/admin/impressao" aria-label="<?= e($agent['label']) ?>" title="<?= e($agent['label']) ?>"><?= $ic('print') ?><span class="sr" id="agent-txt"><?= e($agent['label']) ?></span></a>
      <?php if ($isBoard): ?>
        <button class="icon-btn" id="sound" type="button" aria-pressed="false" aria-label="Alerta sonoro desativado. Ativar" title="Alerta sonoro"><?= $ic('bell-off') ?><span class="badge" id="bell-n" hidden>0</span></button>
      <?php else: ?>
        <a class="icon-btn" href="/admin" aria-label="<?= $newCount ?> pedidos novos" title="Pedidos novos"><?= $ic('bell') ?><?php if ($newCount): ?><span class="badge"><?= $newCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <details class="user"><summary class="user-chip" aria-label="Conta: <?= e($user['name']) ?>"><span class="avatar-sm"><?= $ic('user') ?></span><span class="uc-name"><?= e(Auth::ROLES[$user['role']] ?? $user['name']) ?></span><?= $ic('back', 'ic chev') ?></summary>
        <div class="user-pop"><p><b><?= e($user['name']) ?></b><br><span class="muted"><?= e(Auth::ROLES[$user['role']] ?? '') ?></span></p>
          <form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="b ghost sm block">Sair</button></form></div></details>
    </div>
  </header>
  <div class="pg-head"><<?= $tag ?> class="pg-ttl"><?= $ic($navIcon, 'ic pg-ic') ?><?= e($heading) ?></<?= $tag ?>><?php if (($subs[$activeNav] ?? '') !== ''): ?><p class="pg-sub"><?= e($subs[$activeNav]) ?></p><?php endif; ?></div>
  <main class="content <?= $isBoard ? 'content-board' : '' ?>" id="content"><?php if ($flash): ?><p class="okmsg" role="status"><?= e($flash) ?></p><?php endif; ?><?php if ($flashErr): ?><p class="err" role="alert"><?= e($flashErr) ?></p><?php endif; ?><?= $content ?></main>
</div></div>
<script src="<?= View::asset('assets/picker.js') ?>" defer></script>
<script src="<?= View::asset('assets/ui.js') ?>" defer></script>
<script src="<?= View::asset('assets/neworder.js') ?>" defer></script>
<script src="<?= View::asset('assets/admin.js') ?>" defer></script>
</body></html>
