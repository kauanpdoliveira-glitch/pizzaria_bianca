<p><a href="/admin/cardapio?cat=<?= (int)$p['category_id'] ?>">← Catálogo</a></p>
<h1><?= e($title) ?></h1>
<form method="post" enctype="multipart/form-data" class="panel stack">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Nome<input name="name" value="<?= e($p['name']) ?>" required maxlength="160"></label>
  <div class="inline"><label>Categoria<select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$p['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= $c['parent_id'] ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Status<select name="status"><option value="active" <?= !$p['hidden'] && $p['available'] ? 'selected' : '' ?>>Ativo — disponível para compra</option><option value="out" <?= !$p['hidden'] && !$p['available'] ? 'selected' : '' ?>>Em falta — aparece, mas não vende</option><option value="hidden" <?= $p['hidden'] ? 'selected' : '' ?>>Oculto — não aparece nem vende</option></select></label></div>
  <label>Descrição<textarea name="description" rows="3" maxlength="1000"><?= e($p['description']) ?></textarea></label>
  <label>Preço único (R$) — usado só se não houver tamanhos<input name="price" inputmode="decimal" value="<?= e(number_format($p['base_price_cents'] / 100, 2, ',', '')) ?>"></label>
  <label>Selo (opcional, ex.: Entre as 5 mais pedidas)<input name="badge" value="<?= e($p['badge']) ?>" maxlength="80"></label>
  <label>Foto (JPG/PNG/WebP até 4 MB)<?php if ($p['image']): ?> <img src="<?= e($p['image']) ?>" alt="Foto atual" class="thumb"><?php else: ?><span class="note">Sem foto cadastrada: o cardápio mostra uma ilustração neutra. Envie a foto real deste produto.</span><?php endif; ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
  <?php if (!empty($obs)): ?><div class="panel"><h2>Preço observado em outro canal</h2>
    <p class="muted small">Referência do que foi visto no cardápio público do iFood em <?= e(substr((string)$obs[0]['observed_at'], 0, 10)) ?>. <strong>Não é o preço do site</strong> e não foi aplicado: o preço próprio é o que você define abaixo.<?= $obs[0]['status'] === 'rascunho' ? ' Este produto é um <strong>rascunho oculto</strong>: defina o preço próprio antes de ativá-lo.' : '' ?></p>
    <ul><?php foreach ($obs as $o): ?><li><?= e($o['size_name'] ?: 'Preço') ?>: R$ <?= e(number_format((int)$o['observed_cents'] / 100, 2, ',', '.')) ?><?= $o['note'] ? ' <span class="muted small">— ' . e($o['note']) . '</span>' : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <h2>Tamanhos e preços</h2><p class="muted small">Deixe em branco se o produto tem preço único. Para pizzas, use os mesmos nomes de tamanho em todos os sabores (necessário para 2 sabores). Um sabor/tamanho "indisponível" fica em falta só naquele tamanho.</p>
  <?php $rows = $sizes; for ($i = 0; $i < 3; $i++) $rows[] = ['id' => 0, 'name' => '', 'detail' => '', 'price_cents' => 0, 'available' => 1]; ?>
  <div class="tw"><table><thead><tr><th>Nome</th><th>Detalhe</th><th>Preço (R$)</th><th>Disp.</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $s): ?><tr><td><input type="hidden" name="size_id[<?= $i ?>]" value="<?= (int)$s['id'] ?>"><input name="size_name[<?= $i ?>]" value="<?= e($s['name']) ?>" aria-label="Nome do tamanho"></td>
    <td><input name="size_detail[<?= $i ?>]" value="<?= e($s['detail']) ?>" aria-label="Detalhe"></td><td><input name="size_price[<?= $i ?>]" inputmode="decimal" value="<?= $s['name'] !== '' ? e(number_format($s['price_cents'] / 100, 2, ',', '')) : '' ?>" class="sm-in" aria-label="Preço"></td>
    <td><input type="checkbox" name="size_on[<?= $i ?>]" value="1" <?= $s['available'] ? 'checked' : '' ?> aria-label="Disponível"></td></tr><?php endforeach; ?></tbody></table></div>
  <h2>Ingredientes e retiradas permitidas</h2>
  <label>Um ingrediente por linha. Acrescente <code>; fixo</code> para impedir a retirada (ex.: <code>mussarela ; fixo</code>). O cliente só pode retirar os que aparecem aqui — e só do sabor a que pertencem.
    <textarea name="ingredients" rows="6" placeholder="calabresa&#10;cebola&#10;azeitona"><?= e(implode("\n", array_map(fn($i) => $i['name'] . ($i['removable'] ? '' : ' ; fixo'), $ings))) ?></textarea></label>
  <button class="b">Salvar produto</button>
</form>
