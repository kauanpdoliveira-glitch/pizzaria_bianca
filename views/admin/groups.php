<p><a href="/admin/cardapio">← Cardápio</a></p>
<h1><?= e($title) ?></h1>
<p class="muted">Grupos de adicionais valem para todos os produtos da categoria <b><?= e($c['name']) ?></b>. Adicionais específicos de um único produto não são editados aqui.</p>
<form method="post" class="stack">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <?php $gs = $groups; $gs[] = ['id' => 0, 'name' => '', 'min_sel' => 0, 'max_sel' => 1, 'text' => '']; foreach ($gs as $i => $g): ?>
  <section class="panel"><h2><?= $g['id'] ? e($g['name']) : 'Novo grupo' ?></h2><input type="hidden" name="g_id[<?= $i ?>]" value="<?= (int)$g['id'] ?>">
    <div class="inline"><label>Nome do grupo<input name="g_name[<?= $i ?>]" value="<?= e($g['name']) ?>" placeholder="Ex.: Borda"></label>
    <label>Mínimo<input type="number" min="0" name="g_min[<?= $i ?>]" value="<?= (int)$g['min_sel'] ?>" class="xs"></label><label>Máximo<input type="number" min="1" name="g_max[<?= $i ?>]" value="<?= (int)$g['max_sel'] ?>" class="xs"></label></div>
    <label>Opções — uma por linha: <code>Nome ; preço ; Tamanho=preço, Tamanho=preço ; SO_TAMANHOS ; INDISPONIVEL ; desc=descrição</code> (tudo após o preço é opcional; SO_TAMANHOS = só vale nos tamanhos listados)
      <textarea name="g_opts[<?= $i ?>]" rows="4"><?= e($g['text']) ?></textarea></label>
    <p class="muted small">Para remover um grupo, apague seu nome e salve.</p></section>
  <?php endforeach; ?>
  <button class="b">Salvar adicionais</button>
</form>
