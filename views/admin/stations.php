<h1>Estações de impressão (QZ Tray)</h1>
<section class="panel"><h2>Como funciona</h2>
<p>Uma <b>estação</b> é o navegador do computador da loja (logado com perfil autorizado) com o <b>QZ Tray</b> instalado nele. Pedidos entram numa fila no servidor; só a estação reserva e envia. Celulares de clientes e garçons nunca imprimem direto. Instruções e requisitos: <code>docs/IMPRESSAO.md</code>.</p>
<p>Assinatura QZ: <b><?= $ov['qz']['configured'] ? 'configurada' : 'não configurada' ?></b> — <?= e($ov['qz']['reason']) ?><?= $ov['qz']['configured'] ? ' Certificado: ' . e((string)($ov['qz']['subject'] ?? '')) . ' (validade ' . e((string)$ov['qz']['expires']) . ').' : '' ?></p>
<p class="note">“Enviado ao subsistema de impressão” significa que o QZ/spooler aceitou o trabalho; não prova que o papel saiu. Resultado incerto nunca é reenviado sozinho.</p></section>
<?php if ($ov['pendencias']): ?><section class="panel"><h2>Pendências de impressão</h2><ul><?php foreach ($ov['pendencias'] as $p): ?><li class="errmsg"><?= e($p['msg']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php foreach ($ov['stations'] as $s): ?>
<section class="panel"><h2><?= e($s['name']) ?> <small class="muted">#<?= (int)$s['id'] ?> · <?= $s['active'] ? ($s['online'] ? 'online' : 'offline') : 'desativada' ?></small></h2>
<p>Último contato: <b><?= $s['last_seen'] ? e(date('d/m/Y H:i:s', strtotime($s['last_seen']))) : 'nunca' ?></b><?= $s['last_user'] ? ' por ' . e($s['last_user']) : '' ?> · QZ <?= $s['qz_connected'] ? 'conectado' . ($s['qz_version'] ? ' (' . e($s['qz_version']) . ')' : '') : 'desconectado' ?> · <a href="/admin/estacao/<?= (int)$s['id'] ?>">Abrir como estação</a></p>
<?php if ($canConfig): ?>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/mapa"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<div class="tw"><table><thead><tr><th>Destino</th><th>Impressora (nome no sistema)</th><th>Colunas</th><th>Charset</th><th>Corte</th></tr></thead><tbody>
<?php foreach (Printing::SECTORS as $k => $label): $m = $s['map'][$k] ?? null; ?>
<tr><td><?= e($label) ?></td>
<td><select name="map[<?= e($k) ?>][printer]" aria-label="Impressora para <?= e($label) ?>"><option value="">— sem impressora —</option><?php foreach ($s['printers'] as $p): ?><option<?= $m && $m['printer_name'] === $p ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></td>
<td><input type="number" min="24" max="80" name="map[<?= e($k) ?>][cols]" value="<?= (int)($m['cols'] ?? 48) ?>" aria-label="Colunas"></td>
<td><select name="map[<?= e($k) ?>][charset]" aria-label="Charset"><?php foreach (PrintStation::CHARSETS as $cs): ?><option<?= ($m['charset'] ?? 'ascii') === $cs ? ' selected' : '' ?>><?= e($cs) ?></option><?php endforeach; ?></select></td>
<td><input type="checkbox" name="map[<?= e($k) ?>][cut]" value="1"<?= !$m || $m['cut'] ? ' checked' : '' ?> aria-label="Corte automático"></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="note">As impressoras só aparecem depois que a estação abre e o QZ as lista. Charset padrão <b>ascii</b> (sem acentos) é o mais seguro; mude só depois de testar no papel com o seu modelo.</p>
<button class="b">Salvar mapeamento</button></form>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/ativa" style="display:inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="on" value="<?= $s['active'] ? 0 : 1 ?>"><button class="b ghost"><?= $s['active'] ? 'Desativar estação' : 'Reativar estação' ?></button></form>
<form method="post" action="/admin/estacoes/<?= (int)$s['id'] ?>/teste" data-confirm="Isto imprime uma comanda de TESTE na impressora física mapeada. Continuar?" style="display:inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><select name="sector" aria-label="Destino do teste"><?php foreach (Printing::SECTORS as $k => $label): if (isset($s['map'][$k])): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endif; endforeach; ?></select> <button class="b ghost">Imprimir teste (NÃO PRODUZIR)</button></form>
<?php endif; ?></section>
<?php endforeach; ?>
<?php if (!$ov['stations']): ?><section class="panel"><p class="muted">Nenhuma estação cadastrada.</p></section><?php endif; ?>
<?php if ($canConfig): ?><section class="panel"><h2>Nova estação</h2><form method="post" action="/admin/estacoes"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Nome (ex.: Caixa — PC da loja)<input name="name" maxlength="80" required></label> <button class="b">Criar estação</button></form></section><?php else: ?><p class="note">Somente o proprietário cria estações e mapeia impressoras.</p><?php endif; ?>
