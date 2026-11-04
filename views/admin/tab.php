<?php require_once __DIR__ . '/_forms.php'; $back = '/admin/comanda/' . (int)$t['id']; $tt = $t['totals']; $closed = $t['status'] === 'closed'; ?>
<p><a href="/admin/salao?mesa=<?= (int)$t['table_id'] ?>">← Mesa <?= (int)$t['table_number'] ?></a> · <a href="/admin/caixa">Caixa</a></p>
<h1>Comanda <?= e($t['physical_number'] ?: '(sem nº físico)') ?> <span class="pill"><?= e(Floor::TAB[$t['status']]) ?></span></h1>
<p class="muted">Mesa <?= (int)$t['table_number'] ?> · <?= e($t['name']) ?> · <?= (int)$t['people'] ?> pessoa(s) · atendimento #<?= (int)$t['visit_id'] ?> (<?= e(Floor::VISIT[$t['visit']['status']]) ?>) · aberta às <?= e(date('d/m H:i', strtotime($t['opened_at']))) ?> por <?= e($t['opened_by_name']) ?></p>
<div class="two-col">
<section class="panel"><h2>Conta</h2>
  <div class="li"><span>Consumo</span><b><?= e(money($tt['items'])) ?></b></div>
  <?php if ($tt['fee'] || $t['fee_removed']): ?><div class="li"><span>Taxa de serviço <?= (float)Settings::get('service_fee_percent') ? '(' . e(Settings::get('service_fee_percent')) . '%)' : '' ?><?= $t['fee_removed'] ? ' — removida: ' . e($t['fee_reason']) : '' ?></span><b><?= e(money($tt['fee'])) ?></b></div><?php endif; ?>
  <?php if ($tt['discount']): ?><div class="li"><span>Desconto — <?= e($t['discount_reason']) ?></span><b>− <?= e(money($tt['discount'])) ?></b></div><?php endif; ?>
  <div class="li"><b>Total</b><b><?= e(money($tt['total'])) ?></b></div>
  <div class="li"><span>Confirmado</span><b><?= e(money($tt['confirmed'])) ?></b></div><div class="li"><span>Informado (aguarda conferência)</span><b><?= e(money($tt['reported'])) ?></b></div><div class="li"><span>Saldo a receber</span><b class="<?= $tt['pending'] > 0 ? 'err' : '' ?>"><?= e(money($tt['pending'])) ?></b></div>
  <?php if (!$closed): ?>
    <h3>Ajustes</h3>
    <?php if (Auth::can('discount')) {
        af($csrf, $back, 'discount', ['tab_id' => $t['id']], 'Aplicar desconto', 'b sm ghost', null, ['amount' => 'Valor do desconto (R$)', 'reason' => 'Motivo']);
        if ($t['fee_removed']) { af($csrf, $back, 'fee', ['tab_id' => $t['id']], 'Restabelecer taxa de serviço', 'b sm ghost'); }
        elseif ($tt['fee']) { af($csrf, $back, 'fee', ['tab_id' => $t['id'], 'remove' => 1], 'Remover taxa de serviço', 'b sm ghost', null, ['reason' => 'Motivo']); }
    } else { ?><p class="muted small">Seu perfil não aplica descontos nem remove taxa.</p><?php } ?>
    <?php if ($t['status'] === 'open' && Auth::can('floor')) af($csrf, $back, 'request_bill', ['tab_id' => $t['id']], 'Solicitar conta (impede novos itens)', 'b sm ghost'); ?>
  <?php endif; ?>
  <?php if (Auth::can('cashier')): ?><h3>Fechamento</h3>
    <?php if ($closed): af($csrf, $back, 'reopen_tab', ['tab_id' => $t['id']], 'Reabrir comanda', 'b sm danger-o', null, ['reason' => 'Motivo da reabertura']); ?><p class="muted small">Fechada por <?= e($t['closed_by_name']) ?> em <?= e(date('d/m H:i', strtotime($t['closed_at']))) ?>.</p>
    <?php else: af($csrf, $back, 'close_tab', ['tab_id' => $t['id']], 'Fechar comanda', 'b', 'Fechar esta comanda? Exige total confirmado e nenhum recebimento pendente de conferência.'); ?><p class="muted small">O fechamento só é aceito com o total integralmente confirmado.</p><?php endif; ?>
  <?php endif; ?>
</section>
<section class="panel"><h2>Recebimentos</h2>
  <?php foreach ($t['payments'] as $p): ?><div class="li"><b><?= e(money((int)$p['amount_cents'])) ?> · <?= e(Settings::json('payment_methods')[$p['method']]['label'] ?? $p['method']) ?></b><span><?= e(Floor::PAY[$p['status']]) ?></span>
    <small>informado por <?= e($p['reported_by_name']) ?> às <?= e(date('H:i', strtotime($p['reported_at']))) ?><?= $p['confirmed_by_name'] ? ' · confirmado por ' . e($p['confirmed_by_name']) : '' ?><?= $p['note'] ? ' — ' . e($p['note']) : '' ?></small>
    <?php if (Auth::can('cashier') && !$closed): if ($p['status'] === 'reported') { af($csrf, $back, 'confirm_payment', ['id' => $p['id']], 'Confirmar', 'b sm'); af($csrf, $back, 'reject_payment', ['id' => $p['id']], 'Recusar', 'b sm danger-o', null, ['reason' => 'Motivo']); }
      elseif ($p['status'] === 'confirmed') af($csrf, $back, 'reverse_payment', ['id' => $p['id']], 'Registrar estorno', 'b sm danger-o', null, ['reason' => 'Motivo']); endif; ?></div><?php endforeach; ?>
  <?php if (!$t['payments']): ?><p class="muted">Nenhum recebimento.</p><?php endif; ?>
  <?php if (Auth::can('cashier') && !$closed): ?><h3>Registrar recebimento (já confirmado)</h3>
    <form method="post" action="/admin/salao/acao" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="register_payment"><input type="hidden" name="tab_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="key" value="<?= e(bin2hex(random_bytes(12))) ?>">
      <label>Valor (R$)<input name="amount" inputmode="decimal" required></label><label>Forma<select name="method"><?php foreach ($pays as $k => $pm): ?><option value="<?= e($k) ?>"><?= e($pm['label']) ?></option><?php endforeach; ?></select></label><button class="b sm">Registrar</button></form>
    <p class="muted small">Estorno é um registro no sistema; a devolução efetiva ao cliente é feita fora dele.</p><?php endif; ?>
</section></div>
<section class="panel"><h2>Pedidos enviados à produção</h2>
  <?php foreach ($t['rounds'] as $r): ?><div class="li"><b>Rodada #<?= (int)$r['id'] ?> · <?= e(date('H:i', strtotime($r['created_at']))) ?></b><span><?= e(Orders::STATUS[$r['status']]) ?> · <a href="/admin#<?= (int)$r['id'] ?>">ver no gestor</a></span>
    <?php foreach ($r['items'] as $i): ?><small><?= (int)$i['qty'] ?>× <?= e($i['name']) ?><?= $i['size_name'] ? ' (' . e($i['size_name']) . ')' : '' ?> — <?= e(money((int)$i['total_cents'])) ?><?= $i['notes'] ? ' · obs: ' . e($i['notes']) : '' ?></small><?php endforeach; ?></div><?php endforeach; ?>
  <?php if (!$t['rounds']): ?><p class="muted">Sem itens.</p><?php endif; ?>
</section>
<section class="panel"><h2>Histórico</h2><ol class="ev"><?php foreach ($t['events'] as $e): ?><li><time><?= e(date('d/m H:i:s', strtotime($e['created_at']))) ?></time> <?= e($e['kind']) ?><small><?= e($e['user_name'] ?? '—') ?><?= $e['note'] ? ' — ' . e($e['note']) : '' ?></small></li><?php endforeach; ?></ol></section>
