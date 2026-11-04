<?php /** @var string $content */
$path = strtok($_SERVER['REQUEST_URI'], '?');
$cur = fn(string $p) => $path === $p ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description ?? '') ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif; ?>
<meta name="theme-color" content="#c4121f">
<?php require APP_ROOT . '/views/_icons_head.php'; ?>
<meta property="og:title" content="<?= e($title) ?>"><meta property="og:description" content="<?= e($description ?? '') ?>"><meta property="og:type" content="website"><meta property="og:locale" content="pt_BR"><meta property="og:image" content="<?= e(url('/assets/brand/logo-bianca-sobre-vermelho-1200x630.png')) ?>">
<link rel="canonical" href="<?= e(url($path)) ?>">
<link rel="preload" href="/assets/fonts/oswald-700.woff" as="font" type="font/woff" crossorigin>
<link rel="stylesheet" href="<?= View::asset('assets/brand.css') ?>"><link rel="stylesheet" href="<?= View::asset('assets/site.css') ?>"><?= View::shopCss($page) ?>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Restaurant', 'name' => 'Pizzaria Bianca', 'foundingDate' => '1977',
    'logo' => url('/assets/brand/logo-bianca-sobre-vermelho-1200x630.png'), 'telephone' => '+55 31 3398-5252', 'servesCuisine' => 'Pizza',
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Rua Cristiano Machado, 116', 'addressLocality' => 'Contagem', 'addressRegion' => 'MG', 'addressCountry' => 'BR'],
    'sameAs' => [$store['instagram_url']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body data-page="<?= e($page) ?>">
<a class="skip" href="#main">Pular para o conteúdo</a>
<header class="top">
  <a class="brand" href="/" aria-label="Pizzaria Bianca, início"><?= View::brand('header') ?></a>
  <nav aria-label="Principal" class="nav">
    <a href="/"<?= $cur('/') ?>>Início</a><a href="/cardapio"<?= $cur('/cardapio') ?>>Cardápio</a><a href="/historia"<?= $cur('/historia') ?>>Nossa história</a><a href="#contato">Contato</a>
    <a class="btn btn-light btn-sm" href="/cardapio">Fazer pedido</a>
    <details class="mnav"><summary aria-label="Abrir menu">Menu ☰</summary><div><a href="/">Início</a><a href="/cardapio">Cardápio</a><a href="/historia">Nossa história</a><a href="#contato">Contato</a><a href="/equipe">Área da equipe</a></div></details>
  </nav>
</header>
<main id="main"><?= $content ?></main>
<footer class="foot" id="contato">
  <div class="wrap foot-grid">
    <div><?= View::brand('footer', true) ?><p class="muted">Em Contagem/MG desde 1977.</p></div>
    <div><h2 class="h-sm">Onde estamos</h2><p><?= e($store['store_address']) ?></p><p><a href="tel:+553133985252"><?= e($store['store_phone']) ?></a></p></div>
    <div><h2 class="h-sm">Horário de pedidos</h2><?php $same = []; foreach (Settings::json('schedule') as $d => $slots) { if ($slots) { $same[$slots[0][0] . '–' . $slots[0][1]][] = (int)$d; } } ?>
      <?php foreach ($same as $hr => $days): $n = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']; ?>
        <p><?= count($days) === 7 ? 'Todos os dias' : e(implode(', ', array_map(fn($d) => $n[$d], $days))) ?>: <?= e(str_replace(':00', 'h', $hr)) ?></p>
      <?php endforeach; ?></div>
    <div><h2 class="h-sm">Siga a Bianca</h2><p><a href="/equipe">Área da equipe</a></p><p><a href="<?= e($store['instagram_url']) ?>" rel="noopener" target="_blank">Instagram @biancapizzaria</a></p>
      <?php if ($store['links_url']): ?><p><a href="<?= e($store['links_url']) ?>" rel="noopener" target="_blank">Mais canais (Linktree)</a></p><?php endif; ?></div>
  </div>
</footer>
<?php if (View::isShop($page)): ?><div id="toast" class="toast" role="status" aria-live="polite"></div><?php endif; ?>
<script src="<?= View::asset('assets/motion.js') ?>" defer></script>
<?php if (View::isShop($page)): ?><script src="<?= View::asset('assets/app.js') ?>" defer></script><?php endif; ?>
</body>
</html>

