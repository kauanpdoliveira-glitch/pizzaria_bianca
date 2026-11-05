<?php $s = $r['sum'];
$ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$days = array_values($r['by_day']); $max = max(1, ...array_map(fn($d) => (int)$d['total'], $days ?: [['total' => 0]]));
$payTotal = max(1, array_sum(array_map(fn($p) => (int)$p['total'], $r['by_payment'])));
?>
<form class="fbar" method="get"><label>De<input type="date" name="from" value="<?= e($from) ?>"></label><label>Até<input type="date" name="to" value="<?= e($to) ?>"></label><button class="b"><?= $ic('refresh') ?> Aplicar</button>
  <a class="b ghost sm" style="margin-left:auto" href="/admin/relatorios.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar CSV</a></form>
<div class="info-box"><?= $ic('alert') ?><span>Relatório operacional — <b>não é contabilidade fiscal, nota fiscal nem conciliação bancária</b>. Período pela data de criação do pedido.</span></div>
<div class="kpi-row">
  <div class="kpi"><span class="kic"><?= $ic('orders') ?></span><div><span>Pedidos válidos</span><b><?= (int)$s['valid_count'] ?></b><small>todos exceto cancelados</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('reports') ?></span><div><span>Valor dos pedidos</span><b><?= e(money($s['valid_total'])) ?></b><small>soma do total dos válidos</small></div></div>
  <div class="kpi hl"><span class="kic"><?= $ic('card') ?></span><div><span>Ticket médio</span><b><?= e(money($s['avg_ticket'])) ?></b><small>valor ÷ pedidos válidos</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('check') ?></span><div><span>Concluídos</span><b><?= (int)$s['completed_count'] ?> · <?= e(money($s['completed_total'])) ?></b><small>status Concluído</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('check') ?></span><div><span>Pagamentos confirmados</span><b><?= e(money($s['paid_total'])) ?></b><small>conferidos pela equipe</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('clock') ?></span><div><span>Valores pendentes</span><b><?= e(money($s['pending_total'])) ?></b><small>válidos sem pagamento conferido</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('truck') ?></span><div><span>Taxas de entrega</span><b><?= e(money($s['fees'])) ?></b><small>incluídas no valor dos pedidos</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('note') ?></span><div><span>Descontos</span><b><?= e(money($s['discounts'])) ?></b><small>já abatidos do total</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('x') ?></span><div><span>Cancelados</span><b><?= (int)$s['cancelled_count'] ?> · <?= e(money($s['cancelled_total'])) ?></b><small>fora de todos os valores acima</small></div></div>
</div>
<div class="split">
<section class="card"><div class="card-h"><h2>Vendas por dia</h2><p>Valor dos pedidos válidos no período.</p></div>
<?php if (!$days): ?><div class="empty"><?= $ic('reports') ?><p>Sem pedidos no período.</p></div><?php else: ?>
  <div class="tw"><table><thead><tr><th>Dia</th><th class="r">Pedidos</th><th class="r">Valor</th><th style="width:40%"><span class="sr">Proporção</span></th></tr></thead><tbody>
  <?php foreach ($days as $d): $w = (int)round((int)$d['total'] / $max * 100); ?><tr><td><?= e(date('d/m/Y', strtotime($d['day']))) ?></td><td class="r"><?= (int)$d['n'] ?></td><td class="r"><b><?= e(money((int)$d['total'])) ?></b></td><td><span style="display:block;height:10px;border-radius:6px;background:var(--red);width:<?= $w ?>%;min-width:3px" role="img" aria-label="<?= $w ?>% do maior dia"></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<aside class="card"><div class="card-h"><h2>Pagamentos</h2><p>Por forma de pagamento, no período.</p></div>
<?php if (!$r['by_payment']): ?><div class="empty"><p>Sem pedidos no período.</p></div><?php else: ?><ul class="kv-list">
  <?php foreach ($r['by_payment'] as $p): ?><li><span><b style="color:var(--ink)"><?= e($p['label']) ?></b><br><small><?= number_format((int)$p['total'] / $payTotal * 100, 1, ',', '') ?>% das vendas · <?= (int)$p['n'] ?> pedido(s) · confirmado <?= e(money((int)$p['paid'])) ?> · pendente <?= e(money((int)$p['pending'])) ?></small></span><b><?= e(money((int)$p['total'])) ?></b></li><?php endforeach; ?>
  <li><b>Total</b><b style="color:var(--red)"><?= e(money($payTotal === 1 && !$r['by_payment'] ? 0 : array_sum(array_map(fn($p) => (int)$p['total'], $r['by_payment'])))) ?></b></li></ul><?php endif; ?></aside>
</div>
<section class="card tbl-card" style="margin-top:16px"><div class="card-h"><h2>Mais vendidos</h2></div><div class="tw"><table><thead><tr><th>#</th><th>Produto</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($r['top'] as $i => $t): ?><tr><td><?= $i + 1 ?></td><td><?= e($t['name']) ?><?= $t['size_name'] ? ' (' . e($t['size_name']) . ')' : '' ?></td><td class="r"><?= (int)$t['qty'] ?></td><td class="r"><?= e(money((int)$t['total'])) ?></td></tr><?php endforeach; ?>
<?php if (!$r['top']): ?><tr><td colspan="4"><div class="empty"><p>Sem dados no período.</p></div></td></tr><?php endif; ?></tbody></table></div></section>
