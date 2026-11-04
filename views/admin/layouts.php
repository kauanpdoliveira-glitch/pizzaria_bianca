<style>
.lay-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem}
@media(min-width:980px){.lay-grid{grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);align-items:start}.lay-prev{position:sticky;top:.5rem}}
.lay-tabs a{margin-right:.4rem}.lay-tabs a[aria-current=page]{font-weight:700;text-decoration:underline}
#blocks{list-style:none;margin:0;padding:0;display:grid;gap:.45rem}
#blocks li{border:1px solid var(--line,#ddd);border-radius:10px;padding:.5rem .6rem;background:#fff;display:flex;flex-wrap:wrap;gap:.5rem .8rem;align-items:center}
#blocks li.drag-over{outline:2px dashed #b3261e}#blocks li[hidden]{display:none}
#blocks .grip{cursor:grab;user-select:none;font-size:1.2rem;line-height:1}
#blocks .lbl{flex:1 1 14rem;font-weight:600}
.lay-grid>*{min-width:0}#pv-box{overflow-x:auto;max-width:100%}.lay-paper{max-width:none;background:#fff;color:#000;border:1px solid #bbb;box-shadow:0 1px 6px rgba(0,0,0,.15);padding:10px;margin:0 auto;font:10pt/1.25 "Courier New",monospace;overflow:auto}
.lay-paper div{white-space:pre-wrap;word-break:break-word}
.lay-row{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:end}
</style>
<h1>Layouts de comandas</h1>
<p class="lay-tabs"><a href="/admin/config/impressao">← Impressão</a> · <?php foreach (Printing::SECTORS as $k => $label): ?><a href="?sector=<?= e($k) ?>"<?= $k === $sector ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></p>
<section class="panel"><p class="muted small">Cada layout é uma <b>versão</b>: salvar cria/atualiza um <b>rascunho</b>; só <b>Ativar</b> o coloca em uso (vale para os próximos pedidos). Salvar e visualizar <b>nunca imprimem</b>. Trabalhos já criados e reimpressões mantêm o layout com que nasceram. O sistema sempre acrescenta as marcas de <b>TESTE, REIMPRESSÃO, ALTERADO e CANCELADO</b>, e não deixa ocultar número do pedido, modalidade e itens (com quantidades, personalizações e observações de preparo). Destino de cada produto continua definido em Configurações → Impressão.</p>
<p>Em uso agora: <b><?= $activeRow ? e($activeRow['name']) . ' (v' . (int)$activeRow['version'] . ')' : 'Modelo padrão do sistema' ?></b>.
<?php if ($sel): ?> Editando: <b><?= e($sel['name']) ?></b> (v<?= (int)$sel['version'] ?>, <?= ['draft' => 'rascunho', 'active' => 'ATIVO', 'archived' => 'arquivado'][$sel['status']] ?>)<?= $sel['status'] !== 'draft' ? ' — ao salvar, vira um <b>novo rascunho</b>; esta versão não muda.' : '' ?><?php else: ?> Editando um <b>novo rascunho</b> a partir de “<?= e($baseName) ?>”.<?php endif; ?></p></section>
<div class="lay-grid">
<form method="post" action="/admin/impressao/layout" id="layform" class="panel" data-sector="<?= e($sector) ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="sector" value="<?= e($sector) ?>"><input type="hidden" name="id" value="<?= $sel ? (int)$sel['id'] : 0 ?>">
<p class="lay-row"><label>Modelo inicial<select name="preset"><?php foreach (PrintLayout::presets($sector) as $k => [$pl]): ?><option value="<?= e($k) ?>"><?= e($pl) ?></option><?php endforeach; ?></select></label><button class="b ghost" name="act" value="preset" formnovalidate>Criar rascunho a partir do modelo</button></p>
<label>Nome do layout<input name="name" maxlength="80" required value="<?= e($sel['name'] ?? ($baseName === 'Modelo padrão' ? 'Meu layout' : $baseName . ' (novo)')) ?>"></label>
<h2>Página</h2>
<div class="lay-row"><label>Separador<select name="page[separator]"><?php foreach (PrintLayout::SEPARATORS as $k => $l): ?><option value="<?= e($k) ?>"<?= $lay['page']['separator'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Espaçamento<select name="page[spacing]"><?php foreach (PrintLayout::SPACINGS as $k => $l): ?><option value="<?= e($k) ?>"<?= $lay['page']['spacing'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Largura útil (colunas; vazio = da impressora)<input type="number" min="24" max="100" name="page[cols]" value="<?= e((string)($lay['page']['cols'] ?? '')) ?>" style="width:6rem"></label>
<label>Margem esquerda (vazio = da impressora)<input type="number" min="0" max="10" name="page[margin]" value="<?= e((string)($lay['page']['margin'] ?? '')) ?>" style="width:6rem"></label></div>
<h2>Cabeçalho e rodapé</h2>
<div class="lay-row"><label class="ck"><input type="checkbox" name="header[brand]" value="1"<?= $lay['header']['brand'] ? ' checked' : '' ?>> Nome da pizzaria</label><label>Texto<input name="header[brand_text]" maxlength="40" value="<?= e($lay['header']['brand_text']) ?>"></label>
<label class="ck"><input type="checkbox" name="header[subtitle]" value="1"<?= $lay['header']['subtitle'] ? ' checked' : '' ?>> Destino (ex.: COZINHA)</label>
<label class="ck"><input type="checkbox" name="header[logo]" value="1"<?= $lay['header']['logo'] ? ' checked' : '' ?><?= $logoOk ? '' : ' disabled' ?>> Logo (só modo HTML, sobre fundo preto; <b>não testada</b> no QZ real)</label></div>
<label>Rodapé (opcional, até 120 caracteres)<input name="footer_text" maxlength="120" value="<?= e($lay['footer']['text']) ?>" placeholder="ex.: Obrigado pela preferência!"></label>
<h2>Blocos (arraste ou use ↑ ↓)</h2>
<ul id="blocks">
<?php foreach ($lay['blocks'] as $i => $b): [$label, $req] = $catalog[$b['id']]; ?>
<li data-id="<?= e($b['id']) ?>" draggable="true"><span class="grip" aria-hidden="true" title="Arrastar">⋮⋮</span><span class="lbl"><?= e($label) ?><?= $req ? ' <small class="muted">(obrigatório)</small>' : '' ?></span>
<input type="hidden" data-f="id" value="<?= e($b['id']) ?>">
<?php if ($req): ?><input type="hidden" data-f="show" value="1"><?php else: ?><label class="ck"><input type="checkbox" data-f="show" value="1"<?= $b['show'] ? ' checked' : '' ?>> Mostrar</label><?php endif; ?>
<label>Alinhar<select data-f="align"><?php foreach (PrintLayout::ALIGNS as $k => $l): ?><option value="<?= e($k) ?>"<?= $b['align'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Texto<select data-f="size"><?php foreach (PrintLayout::SIZES as $k => $l): ?><option value="<?= e($k) ?>"<?= $b['size'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="ck"><input type="checkbox" data-f="bold" value="1"<?= $b['bold'] ? ' checked' : '' ?>> Negrito</label>
<?php if ($b['id'] === 'items'): if ($sector === 'balcao'): ?><label class="ck"><input type="checkbox" data-f="options" value="1"<?= !empty($b['options']) ? ' checked' : '' ?>> Personalizações</label><label class="ck"><input type="checkbox" data-f="prices" value="1"<?= !empty($b['prices']) ? ' checked' : '' ?>> Valores por item</label><?php else: ?><small class="muted">Personalizações e retiradas sempre aparecem.</small><?php endif; endif; ?>
<span><button type="button" class="b sm ghost" data-move="-1" aria-label="Mover <?= e($label) ?> para cima">↑</button> <button type="button" class="b sm ghost" data-move="1" aria-label="Mover <?= e($label) ?> para baixo">↓</button></span></li>
<?php endforeach; ?>
</ul>
<p class="lay-row" style="margin-top:1rem"><button class="b" name="act" value="save">Salvar rascunho</button>
<button class="b" name="act" value="activate" data-confirm="Ativar este layout? Ele passa a valer para os PRÓXIMOS pedidos deste destino. Nada é impresso agora.">Salvar e ativar</button>
<button class="b ghost" name="act" value="duplicate"<?= $sel ? '' : ' disabled' ?>>Duplicar</button>
<button class="b ghost" name="act" value="rename"<?= $sel ? '' : ' disabled' ?>>Renomear</button>
<button class="b ghost" name="act" value="reset" data-confirm="Criar um rascunho com o modelo padrão? A versão ativa não muda.">Restaurar modelo padrão</button></p>
<p class="lay-row"><label>Cenário do teste<select name="scenario"><?php foreach (PrintLayout::SCENARIOS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
<button class="b ghost" name="act" value="test"<?= $sel ? '' : ' disabled' ?> data-confirm="Enfileirar UMA página de TESTE (dados fictícios, NÃO PRODUZIR) deste layout salvo? Sai pela estação aberta, na impressora do destino.">Imprimir teste (1 via)</button></p>
<p class="muted small">Teste imprime a versão <b>salva</b>. Salve o rascunho antes. A impressão só acontece com uma estação aberta e uma impressora mapeada para o destino.</p>
</form>
<aside class="panel lay-prev" aria-label="Prévia"><h2>Prévia</h2>
<div class="lay-row"><label>Cenário<select id="pv-scenario"><?php foreach (PrintLayout::SCENARIOS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Perfil<select id="pv-profile"><option value="html:210">HTML — A4 (Epson doméstica)</option><option value="html:80">HTML — 80 mm</option><option value="html:58">HTML — 58 mm</option><option value="escpos:80">Térmica ESC/POS — 80 mm</option><option value="escpos:58">Térmica ESC/POS — 58 mm</option></select></label>
<label class="ck"><input type="checkbox" id="pv-reprint"> Ver como REIMPRESSÃO</label></div>
<div id="pv-warn" class="errmsg" role="status"></div><div id="pv-box" aria-live="polite"></div><p id="pv-note" class="muted small"></p></aside>
</div>
<section class="panel"><h2>Histórico de versões</h2><div class="tw"><table><thead><tr><th>v</th><th>Nome</th><th>Estado</th><th>Criado</th><th>Ativado</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['version'] ?></td><td><?= e($r['name']) ?><?= $r['note'] ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td><td><?= ['draft' => 'rascunho', 'active' => '<b>ATIVO</b>', 'archived' => 'arquivado'][$r['status']] ?></td>
<td><?= e((string)$r['created_by']) ?><br><small><?= e(date('d/m H:i', strtotime($r['created_at']))) ?></small></td><td><?= $r['activated_at'] ? e((string)$r['activated_by']) . '<br><small>' . e(date('d/m H:i', strtotime($r['activated_at']))) . '</small>' : '—' ?></td>
<td><a class="b sm ghost" href="?sector=<?= e($sector) ?>&id=<?= (int)$r['id'] ?>">Abrir</a>
<?php if ($r['status'] !== 'active'): ?><form method="post" action="/admin/impressao/layout" style="display:inline" data-confirm="<?= $r['status'] === 'archived' ? 'Restaurar esta versão como nova versão ativa?' : 'Ativar este rascunho?' ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="sector" value="<?= e($sector) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="restore"><button class="b sm ghost"><?= $r['status'] === 'archived' ? 'Restaurar' : 'Ativar' ?></button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">Nenhuma versão salva: o modelo padrão do sistema está em uso.</td></tr><?php endif; ?></tbody></table></div></section>
<script src="<?= View::asset('assets/layout-editor.js') ?>" defer></script>
