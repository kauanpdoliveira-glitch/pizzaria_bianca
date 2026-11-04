<?php require_once __DIR__ . '/_forms.php'; $back = '/admin/caixa'; ?>
<h1>Caixa</h1>
<p class="note">Conferência de recebimentos do <b>salão</b> e fechamento de contas. Pagamentos de entrega/retirada continuam sendo conferidos no gestor de pedidos e <b>não</b> entram no valor esperado do turno.</p>

<section class="panel"><h2>Turno</h2>
<?php if (!$sid): ?>
  <p class="err">Nenhum turno aberto. Recebimentos confirmados agora não ficam vinculados a nenhum turno.</p>
  <?php af($csrf, $back, 'shift_open', [], 'Abrir turno', 'b', null, ['amount' => 'Valor de abertura (R$)']); ?>
<?php else: $s = $sum['shift']; ?>
  <p>Turno #<?= (int)$s['id'] ?> aberto por <?= e($s['opened_by_name']) ?> em <?= e(date('d/m H:i', strtotime($s['opened_at']))) ?>.</p>
  <div class="kpis"><div><span>Abertura</span><b><?= e(money((int)$s['opening_cents'])) ?></b></div><div><span>Dinheiro recebido (confirmado)</span><b><?= e(money($sum['cash_in'])) ?></b></div>
    <div><span>Suprimentos</span><b><?= e(money($sum['supplies'])) ?></b></div><div><span>Sangrias</span><b><?= e(money($sum['withdrawals'])) ?></b></div><div><span>Dinheiro esperado em caixa</span><b><?= e(money($sum['expected'])) ?></b></div></div>
  <?php if ($sum['by_method']): ?><div class="tw"><table><thead><tr><th>Forma</th><th class="r">Recebimentos</th><th class="r">Total confirmado</th></tr></thead><tbody><?php foreach ($sum['by_method'] as $m): ?><tr><td><?= e(($pays[$m['method']]['label'] ?? $m['method'])) ?></td><td class="r"><?= (int)$m['n'] ?></td><td class="r"><?= e(money((int)$m['total'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <div class="inline"><?php af($csrf, $back, 'shift_move', ['kind' => 'supply'], 'Registrar suprimento', 'b sm ghost', null, ['amount' => 'Valor (R$)', 'reason' => 'Motivo']);
    af($csrf, $back, 'shift_move', ['kind' => 'withdraw'], 'Registrar sangria', 'b sm ghost', null, ['amount' => 'Valor (R$)', 'reason' => 'Motivo']); ?></div>
  <?php if ($sum['moves']): ?><ul class="ev"><?php foreach ($sum['moves'] as $mv): ?><li><?= $mv['kind'] === 'supply' ? 'Suprimento' : 'Sangria' ?> <?= e(money((int)$mv['amount_cents'])) ?> — <?= e($mv['reason']) ?><small><?= e($mv['by_name']) ?> · <?= e(date('H:i', strtotime($mv['created_at']))) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
  <h3>Fechar turno</h3><?php af($csrf, $back, 'shift_close', [], 'Fechar turno', 'b sm', 'Fechar o turno? Informe o dinheiro contado em caixa.', ['counted' => 'Dinheiro contado (R$)']); ?>
  <form method="post" action="/admin/salao/acao" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="shift_close">
    <label>Dinheiro contado (R$)<input name="counted" inputmode="decimal" required></label><label>Observação (obrigatória se houver diferença)<input name="note"></label><button class="b sm">Fechar turno</button></form>
<?php endif; ?></section>

<section class="panel"><h2>Recebimentos informados aguardando conferência (<?= count($reported) ?>)</h2>
<?php if (!$reported): ?><p class="muted">Nada pendente.</p><?php else: ?><div class="tw"><table><thead><tr><th>Comanda</th><th>Valor</th><th>Forma</th><th>Informado por</th><th></th></tr></thead><tbody>
<?php foreach ($reported as $p): ?><tr><td><a href="/admin/comanda/<?= (int)$p['tab_id'] ?>">Mesa <?= (int)$p['table_number'] ?> · <?= e($p['physical_number'] ?: '#' . $p['tab_id']) ?> · <?= e($p['tab_name']) ?></a></td><td><?= e(money((int)$p['amount_cents'])) ?></td><td><?= e($pays[$p['method']]['label'] ?? $p['method']) ?></td><td><?= e($p['reported_by_name']) ?> · <?= e(date('H:i', strtotime($p['reported_at']))) ?></td>
<td class="act"><?php af($csrf, $back, 'confirm_payment', ['id' => $p['id']], 'Confirmar', 'b sm'); af($csrf, $back, 'reject_payment', ['id' => $p['id']], 'Recusar', 'b sm danger-o', null, ['reason' => 'Motivo']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><h2>Contas aguardando o caixa (<?= count($awaiting) ?>)</h2>
<?php if (!$awaiting): ?><p class="muted">Nenhuma conta aguardando fechamento.</p><?php else: ?><div class="tw"><table><thead><tr><th>Mesa</th><th>Comanda</th><th>Cliente</th><th class="r">Total</th><th></th></tr></thead><tbody>
<?php foreach ($awaiting as $t): ?><tr><td><?= (int)$t['table_number'] ?></td><td><?= e($t['physical_number'] ?: '#' . $t['id']) ?></td><td><?= e($t['name']) ?> <span class="muted small">(atend. #<?= (int)$t['visit_id'] ?>)</span></td><td class="r"><?= e(money((int)$t['total'])) ?></td><td><a class="b sm" href="/admin/comanda/<?= (int)$t['id'] ?>">Conferir e fechar</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><h2>Comandas abertas (<?= count($open) ?>)</h2>
<?php if (!$open): ?><p class="muted">Nenhuma.</p><?php else: ?><div class="tw"><table><tbody><?php foreach ($open as $t): ?><tr><td>Mesa <?= (int)$t['table_number'] ?></td><td><?= e($t['physical_number'] ?: '#' . $t['id']) ?></td><td><?= e($t['name']) ?></td><td class="r"><?= e(money((int)$t['total'])) ?></td><td><a class="b sm ghost" href="/admin/comanda/<?= (int)$t['id'] ?>">Abrir</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><h2>Últimos turnos fechados</h2><div class="tw"><table><thead><tr><th>Turno</th><th>Abertura → fechamento</th><th class="r">Esperado</th><th class="r">Contado</th><th class="r">Diferença</th><th>Responsável / obs.</th></tr></thead><tbody>
<?php foreach ($shifts as $s): ?><tr><td>#<?= (int)$s['id'] ?></td><td><?= e(date('d/m H:i', strtotime($s['opened_at']))) ?> → <?= e(date('d/m H:i', strtotime($s['closed_at']))) ?></td><td class="r"><?= e(money((int)$s['expected_cents'])) ?></td><td class="r"><?= e(money((int)$s['counted_cents'])) ?></td><td class="r <?= (int)$s['diff_cents'] ? 'err' : '' ?>"><?= e(money((int)$s['diff_cents'])) ?></td><td><?= e($s['closed_by_name']) ?><?= $s['close_note'] ? ' — ' . e($s['close_note']) : '' ?></td></tr><?php endforeach; ?>
<?php if (!$shifts): ?><tr><td colspan="6" class="muted">Nenhum turno fechado ainda.</td></tr><?php endif; ?></tbody></table></div></section>
