<h1>Configurações → Impressão</h1>
<section class="panel"><h2>Como o pedido chega à impressora</h2>
<ol>
<li>O cliente abre o site pelo telefone (qualquer rede) e faz o pedido.</li>
<li>O servidor grava o pedido <b>e</b> os trabalhos de impressão (um por destino, sem comanda vazia).</li>
<li>No computador da loja, a página <b>“Abrir como estação”</b> (logada, com <b>Iniciar processamento</b> confirmado) busca os trabalhos.</li>
<li>O navegador desse computador envia ao <b>QZ Tray local</b>, que encaminha à fila de impressão do Windows.</li>
</ol>
<p class="note"><span>O telefone nunca fala com o QZ nem com a impressora, e o QZ nunca é exposto à internet. O computador precisa estar <b>ligado, com internet, com o QZ Tray aberto e com a página da estação aberta e iniciada</b>. Com a aba fechada <b>nada imprime</b> (os trabalhos ficam na fila). Se a impressão falhar, o pedido continua registrado.</span></p>
<p>Assinatura/confiança do QZ: <b><?= $ov['qz']['configured'] ? 'chave e certificado configurados no servidor' : 'sem certificado próprio — modo de teste com confirmação manual no QZ (não é impressão silenciosa)' ?></b>. <?= e($ov['qz']['reason'] !== 'ok' ? $ov['qz']['reason'] : '') ?> Documentação oficial: <a href="https://qz.io/docs/signing" target="_blank" rel="noopener">qz.io/docs/signing</a>. Passo a passo: <code>docs/IMPRESSAO.md</code>.</p>
<p class="note"><span>“Enviado ao subsistema de impressão” significa que o QZ aceitou o trabalho; não prova papel impresso. Resultado incerto nunca é reenviado sozinho. Falta de papel/tampa aberta só seria informada se o equipamento fornecesse esse estado (não implementado).</span></p></section>
<?php if ($ov['pendencias']): ?><section class="panel"><h2>Pendências</h2><ul><?php foreach ($ov['pendencias'] as $p): ?><li class="errmsg"><?= e($p['msg']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<datalist id="epson-models"><option value="TM-T20"><option value="TM-T20II"><option value="TM-T20III"><option value="TM-T88V"><option value="TM-T88VI"><option value="TM-T88VII"><option value="TM-m30"><option value="TM-m30II"><option value="TM-U220"><option value="L3150 (doméstica)"><option value="L3250 (doméstica)"></datalist>
<?php foreach ($ov['stations'] as $s): ?>
<section class="panel"><h2><?= e($s['name']) ?> <small class="muted">#<?= (int)$s['id'] ?> · <code><?= e((string)$s['code']) ?></code></small></h2>
<ul class="kv">
<li>Backend: <b><?= $s['online'] ? 'comunicando' : 'sem contato recente' ?></b> · QZ: <b><?= $s['qz_online'] ? 'conectado' . ($s['qz_version'] ? ' (' . e($s['qz_version']) . ')' : '') : 'desconectado' ?></b> · Última comunicação: <b><?= $s['last_seen'] ? e(date('d/m/Y H:i:s', strtotime($s['last_seen']))) : 'nunca' ?></b><?= $s['last_user'] ? ' por ' . e($s['last_user']) : '' ?></li>
<li>Estado: <b><?= !$s['active'] ? 'desativada' : ($s['paused'] ? 'PAUSADA' : ($s['armed'] ? 'processando pedidos (iniciado por ' . e((string)$s['armed_by']) . ')' : 'parada — aguardando “Iniciar processamento”')) ?></b><?= $s['tabs'] > 1 ? ' · <span class="errmsg">' . (int)$s['tabs'] . ' abas abertas</span>' : '' ?> · Em andamento: <?= (int)$s['queue'] ?></li>
<li>Destinos atendidos: <b><?= e(implode(', ', array_map(fn($k) => Printing::SECTORS[$k] ?? $k, array_keys(array_filter($s['map'], fn($m) => (int)$m['active'] === 1)))) ?: 'nenhum') ?></b> · <a href="/admin/estacao/<?= (int)$s['id'] ?>">Abrir como estação</a></li>
</ul>
<?php if ($canConfig): ?>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/editar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Nome<input name="name" value="<?= e($s['name']) ?>" maxlength="80" required></label> <label>Identificador<input name="code" value="<?= e((string)$s['code']) ?>" maxlength="40" required></label> <button class="b ghost">Renomear</button></form>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/mapa"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<?php foreach (Printing::SECTORS as $k => $label): $m = $s['map'][$k] ?? null; $n = "map[$k]"; ?>
<fieldset class="panel"><legend><?= e($label) ?></legend>
<label>Fila instalada no Windows (listada pelo QZ)<select name="<?= $n ?>[printer]"><option value="">— nenhuma —</option><?php $found = false; foreach ($s['printers'] as $p): $sel = $m && $m['printer_name'] === $p; $found = $found || $sel; ?><option<?= $sel ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; if ($m && !$found): ?><option selected><?= e($m['printer_name']) ?></option><?php endif; ?></select></label>
<label>Nome de identificação<input name="<?= $n ?>[label]" value="<?= e((string)($m['label'] ?? '')) ?>" maxlength="80" placeholder="ex.: Epson da cozinha"></label>
<label>Fabricante<input name="<?= $n ?>[manufacturer]" value="<?= e((string)($m['manufacturer'] ?? 'Epson')) ?>" maxlength="40"></label>
<label>Modelo<input name="<?= $n ?>[model]" list="epson-models" value="<?= e((string)($m['model'] ?? '')) ?>" maxlength="60"></label>
<label>Conexão<select name="<?= $n ?>[connection]"><option value="usb"<?= ($m['connection'] ?? 'usb') === 'usb' ? ' selected' : '' ?>>USB</option><option value="network"<?= ($m['connection'] ?? '') === 'network' ? ' selected' : '' ?>>Rede (instalada como fila no Windows)</option></select></label>
<label>Modo de impressão<select name="<?= $n ?>[mode]"><?php foreach (PrintStation::MODES as $mk => $ml): ?><option value="<?= e($mk) ?>"<?= ($m['mode'] ?? 'escpos') === $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?></select></label>
<label>Papel<select name="<?= $n ?>[paper]"><?php foreach (PrintStation::PAPERS as $pk => $pl): ?><option value="<?= (int)$pk ?>"<?= (int)($m['paper'] ?? 80) === $pk ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select></label>
<label>Largura útil (colunas; 58 mm ≈ 32, 80 mm ≈ 48)<input type="number" min="24" max="100" name="<?= $n ?>[cols]" value="<?= (int)($m['cols'] ?? 48) ?>"></label>
<label>Margem esquerda (espaços)<input type="number" min="0" max="10" name="<?= $n ?>[margin]" value="<?= (int)($m['margin'] ?? 0) ?>"></label>
<label>Codificação (ESC/POS)<select name="<?= $n ?>[charset]"><?php foreach (PrintStation::CHARSETS as $cs): ?><option<?= ($m['charset'] ?? 'ascii') === $cs ? ' selected' : '' ?>><?= e($cs) ?></option><?php endforeach; ?></select></label>
<label>Vias<input type="number" min="1" max="5" name="<?= $n ?>[copies]" value="<?= (int)($m['copies'] ?? 1) ?>"></label>
<label class="ck"><input type="checkbox" name="<?= $n ?>[cut]" value="1"<?= !$m || $m['cut'] ? ' checked' : '' ?>> Cortar o papel (marque só se o modelo tiver guilhotina; ignorado no modo HTML)</label>
<input type="hidden" name="<?= $n ?>[active]" value="0"><label class="ck"><input type="checkbox" name="<?= $n ?>[active]" value="1"<?= !$m || $m['active'] ? ' checked' : '' ?>> Ativa</label>
<label>Observações<input name="<?= $n ?>[notes]" value="<?= e((string)($m['notes'] ?? '')) ?>" maxlength="200" placeholder="ex.: IP 192.168.0.50 (informativo)"></label>
</fieldset>
<?php endforeach; ?>
<p class="note"><span>Para testes em casa, a mesma fila pode ser escolhida nos três destinos (cada comanda sai identificada com o setor e as vias esperadas). Epson doméstica (jato de tinta) não aceita ESC/POS: use <b>modo HTML</b>. Corte, largura e ESC/POS só se confirmam na térmica correta. A compatibilidade de cada modelo precisa ser testada; marca Epson não garante ESC/POS.</span></p>
<button class="b">Salvar configuração</button></form>
<?php foreach ($s['map'] as $k => $m): ?><form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/teste" data-confirm="Isto enfileira UMA página de TESTE (NÃO PRODUZIR) para <?= e(Printing::SECTORS[$k] ?? $k) ?>, com <?= (int)$m['copies'] ?> via(s). Continuar?" style="display:inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="sector" value="<?= e($k) ?>"><button class="b ghost">Imprimir teste — <?= e(Printing::SECTORS[$k] ?? $k) ?></button></form> <?php endforeach; ?>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/ativa" style="display:inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="<?= $s['paused'] ? 'resume' : 'pause' ?>"><button class="b ghost"><?= $s['paused'] ? 'Retomar processamento' : 'Pausar processamento' ?></button></form>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/ativa" style="display:inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="on" value="<?= $s['active'] ? 0 : 1 ?>"><button class="b ghost"><?= $s['active'] ? 'Desativar estação' : 'Reativar estação' ?></button></form>
<?php else: ?><p class="note"><span>Você pode ver o estado, mas só o proprietário (ou quem ele autorizar) altera as impressoras.</span></p><?php endif; ?></section>
<?php endforeach; ?>
<?php if (!$ov['stations']): ?><section class="panel"><p class="muted">Nenhuma estação cadastrada.</p></section><?php endif; ?>
<?php if ($canConfig): ?><section class="panel"><h2>Nova estação</h2><form method="post" action="/admin/config/impressao"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Nome (ex.: Computador de casa — teste)<input name="name" maxlength="80" required></label> <label>Identificador (opcional)<input name="code" maxlength="40" placeholder="gerado do nome"></label> <button class="b">Criar estação</button></form></section><?php endif; ?>
<section class="panel"><h2>Fila e erros</h2><div class="tw"><table><thead><tr><th>#</th><th>Pedido</th><th>Destino</th><th>Tipo</th><th>Estado</th><th>Estação</th><th>Tent.</th><th>Criado</th><th></th></tr></thead><tbody>
<?php foreach ($ov['jobs'] as $j): ?><tr><td><?= (int)$j['id'] ?></td><td><?= $j['order_id'] ? '<a href="/admin/pedido/' . (int)$j['order_id'] . '">#' . (int)$j['order_id'] . '</a>' : '—' ?></td><td><?= e(Printing::SECTORS[$j['sector']] ?? ($j['sector'] === 'sem_destino' ? 'SEM DESTINO' : $j['sector'])) ?></td><td><?= ['reprint' => 'reimpressão', 'test' => 'TESTE', 'update' => 'alteração', 'cancel' => 'cancelamento'][$j['kind']] ?? 'original' ?></td>
<td class="st-<?= e($j['status']) ?>"><?= e(Printing::STATUS[$j['status']] ?? $j['status']) ?><?= $j['last_error'] ? '<br><small>' . e($j['last_error']) . '</small>' : '' ?></td><td><?= e((string)$j['station_name']) ?></td><td><?= (int)$j['attempts'] ?></td><td><?= e(date('d/m H:i', strtotime($j['created_at']))) ?></td>
<td><?php if (Auth::can('print') && in_array($j['status'], ['failed', 'uncertain'], true)): ?><button class="b sm ghost" data-retry="<?= (int)$j['id'] ?>">Reenviar</button><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$ov['jobs']): ?><tr><td colspan="9" class="muted">Nenhum trabalho ainda.</td></tr><?php endif; ?></tbody></table></div></section>
