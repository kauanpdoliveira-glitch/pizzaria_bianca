<?php
$q = fn(array $x = []) => '/admin/cardapio?' . http_build_query(array_filter(['aba' => $aba, 'cat' => $sel['id'] ?? null] + $x, fn($v) => $v !== null && $v !== ''));
$statusOf = fn(array $p) => $p['hidden'] ? 'hidden' : ($p['available'] ? 'active' : 'out');
$STATUS = ['active' => ['Ativo', 'cs-on'], 'out' => ['Em falta', 'cs-out'], 'hidden' => ['Oculto', 'cs-hid']];
$move = function (string $kind, int $id, string $dir, string $label) use ($csrf) { ?>
  <form method="post" action="/admin/cardapio/mover" class="mv"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><button class="icon-btn sm" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"><?= $dir === 'up' ? '▲' : '▼' ?></button></form>
<?php };
$reorder = !empty($_GET['ordenar']);
?>
<nav class="tabs-a" aria-label="Seções do catálogo">
  <?php foreach (['produtos' => 'Produtos', 'complementos' => 'Complementos', 'opcoes' => 'Opções'] as $k => $lbl): ?><a href="/admin/cardapio?aba=<?= $k ?><?= $sel ? '&cat=' . (int)$sel['id'] : '' ?>" <?= $aba === $k ? 'aria-current="page"' : '' ?>><?= $lbl ?></a><?php endforeach; ?>
</nav>
<div class="catalog">
  <aside class="cat-col" aria-label="Categorias">
    <div class="cat-head"><h2>Categorias</h2><a class="icon-btn add" href="/admin/cardapio?aba=opcoes&nova=1" aria-label="Nova categoria" title="Nova categoria"><svg class="ic" aria-hidden="true"><use href="#i-plus"/></svg></a></div>
    <ul class="cat-list">
      <?php foreach ($cats as $c): $on = $sel && (int)$sel['id'] === (int)$c['id']; ?>
        <li class="<?= $c['parent_id'] ? 'sub' : '' ?><?= $on ? ' on' : '' ?>"><a href="<?= e($q(['cat' => $c['id']])) ?>" <?= $on ? 'aria-current="true"' : '' ?>><?= e($c['name']) ?> <small><?= (int)$c['n'] ?></small><?= !$c['active'] ? ' <span class="cs cs-hid">oculta</span>' : '' ?></a>
          <?php if ($on && $reorder === false && $aba === 'opcoes') {} ?>
          <span class="mvs"><?php $move('category', (int)$c['id'], 'up', 'Subir ' . $c['name']); $move('category', (int)$c['id'], 'down', 'Descer ' . $c['name']); ?></span></li>
      <?php endforeach; ?>
    </ul>
  </aside>
  <section class="cat-main">
  <?php if ($aba === 'opcoes'): ?>
    <?php $c = $newCat ? ['id' => 0, 'name' => '', 'parent_id' => null, 'active' => 1, 'is_pizza' => 0, 'max_flavors' => null, 'allow_removals' => 1, 'sector' => 'cozinha'] : $sel; ?>
    <?php if (!$c): ?><p class="muted">Selecione uma categoria ou crie uma nova.</p><?php else: ?>
    <h2><?= $newCat ? 'Nova categoria' : e($c['name']) ?> — regras e opções</h2>
    <form method="post" action="/admin/cardapio/categoria" class="stack panel">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <div class="inline"><label>Nome<input name="name" value="<?= e($c['name']) ?>" required></label>
        <label>Subcategoria de<select name="parent_id"><option value="">— nenhuma (categoria principal) —</option><?php foreach ($cats as $x) if (!$x['parent_id'] && (int)$x['id'] !== (int)$c['id']): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$c['parent_id'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endif; ?></select></label>
        <label>Destino de impressão (cozinha ou copa/bar)<select name="sector"><option value="cozinha" <?= ($c['sector'] ?? 'cozinha') !== 'copa' && ($c['sector'] ?? '') !== 'balcao' ? 'selected' : '' ?>>Cozinha</option><option value="copa" <?= in_array($c['sector'] ?? '', ['copa', 'balcao'], true) ? 'selected' : '' ?>>Copa/Bar</option></select></label></div>
      <label class="ck"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> Categoria visível no cardápio público</label>
      <label class="ck"><input type="checkbox" name="is_pizza" value="1" <?= $c['is_pizza'] ? 'checked' : '' ?>> Montagem de pizza (tamanho → sabores → personalização)</label>
      <div class="inline"><label>Máximo de sabores (em branco = regra geral: <?= e(Settings::get('max_flavors')) ?>)<input type="number" min="1" max="4" name="max_flavors" value="<?= e((string)($c['max_flavors'] ?? '')) ?>" class="xs"></label></div>
      <label class="ck"><input type="checkbox" name="allow_removals" value="1" <?= $c['allow_removals'] ? 'checked' : '' ?>> Permitir retirar ingredientes (os ingredientes de cada sabor são cadastrados no produto)</label>
      <p class="muted small">Sabores só se combinam dentro da mesma categoria principal (ex.: "Pizzas salgadas" e "Pizzas doces" sob "Pizzas"). Preço de 2 sabores: regra geral em Configurações (<?= Settings::get('half_rule') === 'highest' ? 'maior valor' : 'valor médio' ?>).</p>
      <button class="b">Salvar categoria</button>
    </form>
    <?php endif; ?>
  <?php elseif (!$sel): ?><p class="muted">Nenhuma categoria. Crie uma em <a href="/admin/cardapio?aba=opcoes&nova=1">Opções</a>.</p>
  <?php elseif ($aba === 'complementos'): ?>
    <div class="bar"><h2><?= e($sel['name']) ?> — complementos</h2><a class="b" href="/admin/cardapio/grupos/<?= (int)$sel['id'] ?>">Editar complementos</a></div>
    <p class="muted small">Bordas e adicionais desta categoria (valem também para as subcategorias). Preço por tamanho, grupos obrigatórios/opcionais e limites ficam no editor.</p>
    <?php foreach ($groups as $g): ?><section class="panel"><h3><?= e($g['name']) ?> · <?= $g['min_sel'] ? 'obrigatório' : 'opcional' ?> · escolher de <?= (int)$g['min_sel'] ?> a <?= (int)$g['max_sel'] ?></h3>
      <div class="tw"><table><tbody><?php foreach ($g['options'] as $o): ?><tr><td><?= e($o['name']) ?><?= $o['description'] ? ' <small class="muted">· ' . e($o['description']) . '</small>' : '' ?></td><td class="r"><?= e(money((int)$o['price_cents'])) ?></td><td><?= $o['available'] ? '<span class="cs cs-on">Ativo</span>' : '<span class="cs cs-out">Em falta</span>' ?><?= $o['size_restricted'] ? ' <span class="cs cs-hid">só alguns tamanhos</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endforeach; ?>
    <?php if (!$groups): ?><p class="muted">Nenhum complemento nesta categoria.</p><?php endif; ?>
  <?php else: ?>
    <div class="bar"><h2><?= e($sel['name']) ?></h2>
      <label class="msearch-a"><span class="sr">Buscar produto</span><input id="psearch" type="search" placeholder="Buscar produto" autocomplete="off"></label>
      <a class="b" href="/admin/cardapio/produto/novo?cat=<?= (int)$sel['id'] ?>"><svg class="ic" aria-hidden="true"><use href="#i-plus"/></svg>Novo produto</a></div>
    <div class="inline"><a class="b ghost sm" href="/admin/cardapio?aba=opcoes&cat=<?= (int)$sel['id'] ?>">Editar categoria</a>
      <a class="b ghost sm" href="<?= e($q(['ordenar' => $reorder ? null : 1])) ?>"><?= $reorder ? 'Concluir reordenação' : 'Reordenar' ?></a></div>
    <ul class="plist" id="plist">
    <?php foreach ($prods as $p): $st = $statusOf($p); ?>
      <li class="prow" data-name="<?= e(mb_strtolower($p['name'])) ?>">
        <span class="pthumb"><?php if ($p['image']): ?><img src="<?= e($p['image']) ?>" alt="" width="64" height="48" loading="lazy"><?php else: ?><span class="nophoto" title="Sem foto cadastrada">sem foto</span><?php endif; ?></span>
        <span class="pinfo"><a href="/admin/cardapio/produto/<?= (int)$p['id'] ?>"><b><?= e($p['name']) ?></b></a><small><?= $p['n_sizes'] ? 'a partir de ' . e(money((int)$p['min_size'])) . ' · ' . (int)$p['n_sizes'] . ' tamanhos' : e(money((int)$p['base_price_cents'])) ?></small></span>
        <?php if ($reorder): ?><span class="mvs"><?php $move('product', (int)$p['id'], 'up', 'Subir ' . $p['name']); $move('product', (int)$p['id'], 'down', 'Descer ' . $p['name']); ?></span><?php endif; ?>
        <form method="post" action="/admin/cardapio/status" class="pst"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <label class="sr" for="st<?= (int)$p['id'] ?>">Status de <?= e($p['name']) ?></label>
          <select id="st<?= (int)$p['id'] ?>" name="status" class="cs <?= $STATUS[$st][1] ?>" data-autosubmit><?php foreach ($STATUS as $k => [$lbl]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select><noscript><button class="b sm">Salvar</button></noscript></form>
        <a class="icon-btn" href="/admin/cardapio/produto/<?= (int)$p['id'] ?>" aria-label="Editar <?= e($p['name']) ?>" title="Editar">⋮</a>
      </li>
    <?php endforeach; ?>
    <?php if (!$prods): ?><li class="muted pad">Nenhum produto nesta categoria.</li><?php endif; ?>
    </ul>
    <p class="muted small"><b>Ativo</b>: vende. <b>Em falta</b>: aparece no cardápio, mas não pode ser comprado. <b>Oculto</b>: não aparece nem pode ser comprado (histórico de vendas preservado).</p>
    <div class="rules" aria-label="Regras da categoria"><b>Regras da categoria</b>
      <span>Sabores: mínimo 1 · máximo <?= $sel['is_pizza'] ? (int)($sel['max_flavors'] ?: Settings::get('max_flavors')) : 1 ?></span>
      <span>Tamanhos: <?= $sizesUsed ? e(implode(', ', $sizesUsed)) : 'preço único' ?></span>
      <span><?= count($groups) ? 'Complementos: ' . count($groups) . ' grupo(s)' : 'Sem complementos' ?></span>
      <span>Retirada de ingredientes: <?= $sel['allow_removals'] ? 'permitida' : 'desativada' ?></span></div>
  <?php endif; ?>
  </section>
</div>
