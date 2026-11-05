<?php require_once __DIR__ . '/_forms.php'; $back = '/admin/caixa';
$ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>'; ?>
<div class="info-box"><?= $ic('alert') ?><span>Conferência de recebimentos do <b>salão</b> e fechamento de contas. Pagamentos de entrega/retirada continuam sendo conferidos na gestão de pedidos e <b>não</b> entram no valor esperado do turno.</span></div>

<?php if (!$sid): ?>
<section class="card"><div class="card-h"><h2><span class="pill-st bad">Caixa fechado</span></h2><p>Nenhum turno aberto. Recebimentos confirmados agora não ficam vinculados a nenhum turno.</p></div>
  <?php af($csrf, $back, 'shift_open', [], 'Abrir caixa', 'b', null, ['amount' => 'Valor de abertura (R$)']); ?></section>
<?php else: $s = $sum['shift']; ?>
<div class="card-h" style="margin-bottom:12px"><span class="pill-st ok"><?= $ic('check') ?> Caixa aberto</span><span class="muted">Turno #<?= (int)$s['id'] ?> aberto por <?= e($s['opened_by_name']) ?> em <?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></span>
  <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap"><a class="b ghost sm" href="#mov-form">Suprimento / sangria</a><a class="b sm" href="#fechar-caixa">Fechar caixa</a></span></div>
<div class="kpi-row">
  <div class="kpi"><span class="kic"><?= $ic('card') ?></span><div><span>Abertura</span><b><?= e(money((int)$s['opening_cents'])) ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('check') ?></span><div><span>Dinheiro recebido (confirmado)</span><b><?= e(money($sum['cash_in'])) ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('plus') ?></span><div><span>Suprimentos</span><b><?= e(money($sum['supplies'])) ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('x') ?></span><div><span>Sangrias</span><b><?= e(money($sum['withdrawals'])) ?></b></div></div>
  <div class="kpi hl"><span class="kic"><?= $ic('reports') ?></span><div><span>Dinheiro esperado em caixa</span><b><?= e(money($sum['expected'])) ?></b></div></div>
</div>
<?php endif; ?>

<div class="split">
<div>
<?php if ($sid): ?>
  <?php if ($sum['by_method']): ?><section class="card tbl-card"><div class="card-h"><h2>Recebimentos por forma de pagamento</h2></div><div class="tw"><table><thead><tr><th>Forma</th><th class="r">Recebimentos</th><th class="r">Total confirmado</th></tr></thead><tbody><?php foreach ($sum['by_method'] as $m): ?><tr><td><?= e(($pays[$m['method']]['label'] ?? $m['method'])) ?></td><td class="r"><?= (int)$m['n'] ?></td><td class="r"><b><?= e(money((int)$m['total'])) ?></b></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
  <section class="card tbl-card"><div class="card-h"><h2>Movimentações do caixa</h2></div>
  <?php if ($sum['moves']): ?><div class="tw"><table><thead><tr><th>Hora</th><th>Descrição</th><th class="r">Valor</th><th>Operador</th></tr></thead><tbody><?php foreach ($sum['moves'] as $mv): ?><tr><td><?= e(date('H:i', strtotime($mv['created_at']))) ?></td><td><?= $mv['kind'] === 'supply' ? 'Suprimento' : 'Sangria' ?> — <?= e($mv['reason']) ?></td><td class="r"><b><?= $mv['kind'] === 'supply' ? '' : '− ' ?><?= e(money((int)$mv['amount_cents'])) ?></b></td><td><?= e($mv['by_name']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><div class="empty"><?= $ic('card') ?><p>Nenhuma movimentação neste turno.</p></div><?php endif; ?></section>
  <section class="card" id="mov-form"><div class="card-h"><h2>Suprimento e sangria</h2></div><div class="inline"><?php af($csrf, $back, 'shift_move', ['kind' => 'supply'], 'Registrar suprimento', 'b sm ghost', null, ['amount' => 'Valor (R$)', 'reason' => 'Motivo']);
    af($csrf, $back, 'shift_move', ['kind' => 'withdraw'], 'Registrar sangria', 'b sm ghost', null, ['amount' => 'Valor (R$)', 'reason' => 'Motivo']); ?></div></section>
  <section class="card" id="fechar-caixa"><div class="card-h"><h2>Fechar caixa</h2><p>Informe o dinheiro contado. Se houver diferença, a observação é obrigatória.</p></div>
    <form method="post" action="/admin/salao/acao" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="shift_close">
    <label>Dinheiro contado (R$)<input name="counted" inputmode="decimal" required></label><label>Observação<input name="note"></label><button class="b"><?= $ic('check') ?> Fechar caixa</button></form></section>
<?php endif; ?>
  <section class="card tbl-card"><div class="card-h"><h2>Contas aguardando o caixa (<?= count($awaiting) ?>)</h2></div>
  <?php if (!$awaiting): ?><div class="empty"><p>Nenhuma conta aguardando fechamento.</p></div><?php else: ?><div class="tw"><table><thead><tr><th>Mesa</th><th>Comanda</th><th>Cliente</th><th class="r">Total</th><th></th></tr></thead><tbody>
  <?php foreach ($awaiting as $t): ?><tr><td><?= (int)$t['table_number'] ?></td><td><?= e($t['physical_number'] ?: '#' . $t['id']) ?></td><td><?= e($t['name']) ?> <span class="muted small">(atend. #<?= (int)$t['visit_id'] ?>)</span></td><td class="r"><b><?= e(money((int)$t['total'])) ?></b></td><td><a class="b sm" href="/admin/comanda/<?= (int)$t['id'] ?>">Conferir e fechar</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <section class="card tbl-card"><div class="card-h"><h2>Comandas abertas (<?= count($open) ?>)</h2></div>
  <?php if (!$open): ?><div class="empty"><p>Nenhuma comanda aberta.</p></div><?php else: ?><div class="tw"><table><tbody><?php foreach ($open as $t): ?><tr><td>Mesa <?= (int)$t['table_number'] ?></td><td><?= e($t['physical_number'] ?: '#' . $t['id']) ?></td><td><?= e($t['name']) ?></td><td class="r"><b><?= e(money((int)$t['total'])) ?></b></td><td><a class="b sm ghost" href="/admin/comanda/<?= (int)$t['id'] ?>">Abrir</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <section class="card tbl-card"><div class="card-h"><h2>Últimos turnos fechados</h2></div><div class="tw"><table><thead><tr><th>Turno</th><th>Abertura → fechamento</th><th class="r">Esperado</th><th class="r">Contado</th><th class="r">Diferença</th><th>Responsável / obs.</th></tr></thead><tbody>
  <?php foreach ($shifts as $s2): ?><tr><td>#<?= (int)$s2['id'] ?></td><td><?= e(date('d/m H:i', strtotime($s2['opened_at']))) ?> → <?= e(date('d/m H:i', strtotime($s2['closed_at']))) ?></td><td class="r"><?= e(money((int)$s2['expected_cents'])) ?></td><td class="r"><?= e(money((int)$s2['counted_cents'])) ?></td><td class="r <?= (int)$s2['diff_cents'] ? 'err' : '' ?>"><?= e(money((int)$s2['diff_cents'])) ?></td><td><?= e($s2['closed_by_name']) ?><?= $s2['close_note'] ? ' — ' . e($s2['close_note']) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$shifts): ?><tr><td colspan="6"><div class="empty"><p>Nenhum turno fechado ainda.</p></div></td></tr><?php endif; ?></tbody></table></div></section>
</div>
<aside class="card" aria-label="Conferir recebimento"><div class="card-h"><h2>Conferir recebimento</h2><p>Recebimentos informados pelos garçons aguardando conferência (<?= count($reported) ?>).</p></div>
<?php if (!$reported): ?><div class="empty"><?= $ic('check') ?><p>Nada pendente.</p></div><?php else: foreach ($reported as $p): ?>
  <div class="li" style="display:block"><a href="/admin/comanda/<?= (int)$p['tab_id'] ?>"><b>Mesa <?= (int)$p['table_number'] ?> · <?= e($p['physical_number'] ?: '#' . $p['tab_id']) ?> · <?= e($p['tab_name']) ?></b></a>
    <div class="kv-list"><li><span>Valor</span><b><?= e(money((int)$p['amount_cents'])) ?></b></li><li><span>Forma</span><?= e($pays[$p['method']]['label'] ?? $p['method']) ?></li><li><span>Informado por</span><?= e($p['reported_by_name']) ?> · <?= e(date('H:i', strtotime($p['reported_at']))) ?></li></div>
    <div class="act"><?php af($csrf, $back, 'confirm_payment', ['id' => $p['id']], 'Confirmar recebimento', 'b sm'); af($csrf, $back, 'reject_payment', ['id' => $p['id']], 'Recusar', 'b sm danger-o', null, ['reason' => 'Motivo']); ?></div></div>
<?php endforeach; endif; ?></aside>
</div>
