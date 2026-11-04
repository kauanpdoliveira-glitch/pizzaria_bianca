<?php $s = $r['sum']; ?>
<div class="bar"><h1>Vendas e faturamento operacional</h1><a class="b ghost" href="/admin/relatorios.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar CSV</a></div>
<form class="filters" method="get"><label>De<input type="date" name="from" value="<?= e($from) ?>"></label><label>Até<input type="date" name="to" value="<?= e($to) ?>"></label><button class="b">Aplicar</button></form>
<p class="note">Relatório operacional — <b>não é contabilidade fiscal, nota fiscal nem conciliação bancária</b>. Período pela data de criação do pedido.</p>
<div class="kpis">
  <div><span>Pedidos válidos</span><b><?= (int)$s['valid_count'] ?></b><small>todos exceto cancelados</small></div>
  <div><span>Valor dos pedidos</span><b><?= e(money($s['valid_total'])) ?></b><small>soma do total dos válidos</small></div>
  <div><span>Ticket médio</span><b><?= e(money($s['avg_ticket'])) ?></b><small>valor ÷ pedidos válidos</small></div>
  <div><span>Concluídos</span><b><?= (int)$s['completed_count'] ?> · <?= e(money($s['completed_total'])) ?></b><small>status Concluído</small></div>
  <div><span>Pagamentos confirmados</span><b><?= e(money($s['paid_total'])) ?></b><small>conferidos pela equipe</small></div>
  <div><span>Valores pendentes</span><b><?= e(money($s['pending_total'])) ?></b><small>pedidos válidos sem pagamento conferido</small></div>
  <div><span>Taxas de entrega</span><b><?= e(money($s['fees'])) ?></b><small>incluídas no valor dos pedidos</small></div>
  <div><span>Descontos</span><b><?= e(money($s['discounts'])) ?></b><small>já abatidos do total</small></div>
  <div class="bad"><span>Cancelados</span><b><?= (int)$s['cancelled_count'] ?> · <?= e(money($s['cancelled_total'])) ?></b><small>fora de todos os valores acima</small></div>
</div>
<div class="two-col">
<section class="panel"><h2>Por forma de pagamento</h2><div class="tw"><table><thead><tr><th>Forma</th><th class="r">Pedidos</th><th class="r">Valor</th><th class="r">Confirmado</th><th class="r">Pendente</th></tr></thead><tbody>
<?php foreach ($r['by_payment'] as $p): ?><tr><td><?= e($p['label']) ?></td><td class="r"><?= (int)$p['n'] ?></td><td class="r"><?= e(money((int)$p['total'])) ?></td><td class="r"><?= e(money((int)$p['paid'])) ?></td><td class="r"><?= e(money((int)$p['pending'])) ?></td></tr><?php endforeach; ?>
<?php if (!$r['by_payment']): ?><tr><td colspan="5" class="muted">Sem pedidos no período.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>Mais vendidos</h2><div class="tw"><table><thead><tr><th>Produto</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($r['top'] as $t): ?><tr><td><?= e($t['name']) ?><?= $t['size_name'] ? ' (' . e($t['size_name']) . ')' : '' ?></td><td class="r"><?= (int)$t['qty'] ?></td><td class="r"><?= e(money((int)$t['total'])) ?></td></tr><?php endforeach; ?>
<?php if (!$r['top']): ?><tr><td colspan="3" class="muted">Sem dados.</td></tr><?php endif; ?></tbody></table></div></section></div>
<section class="panel"><h2>Por dia</h2><div class="tw"><table><thead><tr><th>Dia</th><th class="r">Pedidos</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($r['by_day'] as $d): ?><tr><td><?= e(date('d/m/Y', strtotime($d['day']))) ?></td><td class="r"><?= (int)$d['n'] ?></td><td class="r"><?= e(money((int)$d['total'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
