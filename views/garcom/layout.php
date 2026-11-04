<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta name="theme-color" content="#c4121f"><?php require APP_ROOT . '/views/_icons_head.php'; ?><title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= View::asset('assets/brand.css') ?>"><link rel="stylesheet" href="<?= View::asset('assets/admin.css') ?>"><link rel="stylesheet" href="<?= View::asset('assets/garcom.css') ?>"></head>
<body class="gw" data-csrf="<?= e($csrf) ?>" data-can-report="<?= $canReport ? '1' : '0' ?>">
<?php require APP_ROOT . '/views/admin/icons.php'; ?>
<header class="ghdr">
  <a class="glogo" href="/garcom" aria-label="Bianca Pizzaria — início"><?= View::brand('garcom') ?></a>
  <div class="gwho"><?php if ($admin): ?><a class="small" href="/admin">Painel</a><?php endif; ?>
    <details class="user"><summary class="gu"><svg class="ic" aria-hidden="true"><use href="#i-user"/></svg><span><?= e($user['name']) ?></span></summary>
      <div class="user-pop"><p><b><?= e($user['name']) ?></b><br><span class="muted"><?= e(Auth::ROLES[$user['role']] ?? '') ?></span></p>
        <form method="post" action="/equipe/sair"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="b ghost sm block">Sair</button></form></div></details></div>
</header>
<p id="gstale" class="banner bad" role="alert" hidden><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><span>Sem conexão. Os dados podem estar desatualizados e nada novo é confirmado até reconectar.</span></p>
<main id="app" class="gmain"><p class="muted" style="padding:20px">Carregando…</p></main>
<div id="gtoast" class="atoast" role="status"></div>
<script src="<?= View::asset('assets/picker.js') ?>" defer></script>
<script src="<?= View::asset('assets/garcom.js') ?>" defer></script>
</body></html>
