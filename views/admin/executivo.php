<?php
$c = $x['cur']; $p = $x['prev']; $ch = $c['channels'];
$delta = function (int $a, int $b): string { if ($b === 0) return $a === 0 ? '—' : 'novo'; $d = ($a - $b) / $b * 100; return ($d >= 0 ? '+' : '') . number_format($d, 1, ',', '') . '%'; };
$h = $x['health'];
?>
<div class="bar"><h1>Visão executiva</h1><a class="b ghost" href="/admin/executivo.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar comandas do salão (CSV)</a><a class="b ghost" href="/admin/relatorios.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar pedidos (CSV)</a></div>
<form class="filters" method="get"><label>De<input type="date" name="from" value="<?= e($from) ?>"></label><label>Até<input type="date" name="to" value="<?= e($to) ?>"></label><button class="b">Aplicar</button></form>
<p class="note">Comparação com o período equivalente anterior (<?= e(date('d/m', strtotime($x['prev_from']))) ?> a <?= e(date('d/m', strtotime($x['prev_to']))) ?>). Faturamento, recebimento e lucro são medidas diferentes: <b>lucro, margem e CMV não são exibidos porque não há custos cadastrados</b>. Critérios no fim da página.</p>

<div class="kpis">
  <div><span>Faturamento (período)</span><b><?= e(money($c['revenue'])) ?></b><small>vs. anterior: <?= e($delta($c['revenue'], $p['revenue'])) ?></small></div>
  <div><span>Recebido (confirmado)</span><b><?= e(money($x['received'])) ?></b><small>pedidos + recebimentos de comanda confirmados</small></div>
  <div><span>Pendente de recebimento</span><b><?= e(money($x['pending'])) ?></b><small>pedidos sem conferência + saldo de comandas</small></div>
  <div><span>Consumo em aberto no salão</span><b><?= e(money($x['open_consumption'])) ?></b><small>ainda não é venda</small></div>
  <div><span>Pedidos (entrega + retirada)</span><b><?= (int)$c['orders'] ?></b><small>vs. anterior: <?= e($delta($c['orders'], $p['orders'])) ?></small></div>
  <div><span>Comandas fechadas (salão)</span><b><?= (int)$c['tabs'] ?></b><small>vs. anterior: <?= e($delta($c['tabs'], $p['tabs'])) ?></small></div>
</div>

<section class="panel"><h2>Vendas por canal</h2><div class="tw"><table><thead><tr><th>Canal</th><th class="r">Pedidos/comandas</th><th class="r">Vendas</th><th class="r">Ticket médio</th><th class="r">Descontos</th><th class="r">Pagos (conferidos)</th><th class="r">Pendentes</th></tr></thead><tbody>
<?php foreach (['delivery', 'pickup'] as $k): $r = $ch[$k]; ?><tr><td><?= e($r['label']) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money($r['total'])) ?></td><td class="r"><?= e(money($r['n'] ? (int)round($r['total'] / $r['n']) : 0)) ?></td><td class="r"><?= e(money($r['disc'])) ?></td><td class="r"><?= e(money($r['paid'])) ?></td><td class="r"><?= e(money($r['pending'])) ?></td></tr><?php endforeach; $s = $ch['salon']; ?>
<tr><td><?= e($s['label']) ?></td><td class="r"><?= (int)$s['n'] ?></td><td class="r"><?= e(money($s['total'])) ?></td><td class="r"><?= e(money($s['n'] ? (int)round($s['total'] / $s['n']) : 0)) ?></td><td class="r"><?= e(money($s['disc'])) ?></td><td class="r" colspan="2">ver recebimentos por forma abaixo</td></tr>
</tbody></table></div><p class="muted small">Salão: taxa de serviço (<?= e(money($s['fee'])) ?>) não entra em vendas. Atendimentos abertos no período: <?= (int)$s['visits'] ?> · pessoas: <?= (int)$s['people'] ?>.</p></section>

<div class="two-col">
<section class="panel"><h2>Operação do salão agora</h2>
  <div class="li"><span>Mesas livres</span><b><?= (int)$x['salon_now']['free'] ?></b></div><div class="li"><span>Mesas em atendimento</span><b><?= (int)$x['salon_now']['busy'] ?></b></div><div class="li"><span>Mesas aguardando caixa (sem grupo ativo)</span><b><?= (int)$x['salon_now']['awaiting'] ?></b></div>
  <div class="li"><span>Pessoas em atendimento</span><b><?= (int)$x['salon_now']['people'] ?></b></div><div class="li"><span>Comandas abertas</span><b><?= (int)$x['salon_now']['open_tabs'] ?></b></div><div class="li"><span>Contas aguardando o caixa</span><b><?= (int)$x['salon_now']['awaiting_tabs'] ?></b></div><div class="li"><span>Recebimentos informados a conferir</span><b><?= (int)$x['salon_now']['pending_payments'] ?></b></div>
  <div class="li"><span>Tempo médio de atendimento (fechados no período)</span><b><?= $x['avg_visit_min'] === null ? 'sem dados' : (int)$x['avg_visit_min'] . ' min' ?></b></div>
  <p><a class="b sm ghost" href="/admin/salao">Mesas e comandas</a> <a class="b sm ghost" href="/admin/caixa">Caixa</a></p></section>
<section class="panel"><h2>Saúde da operação</h2>
  <div class="li"><span>Pedidos atrasados (passaram da estimativa)</span><b class="<?= $h['late_orders'] ? 'err' : '' ?>"><?= (int)$h['late_orders'] ?></b></div>
  <div class="li"><span>Pedidos novos sem aceite há +10 min</span><b class="<?= $h['unaccepted'] ? 'err' : '' ?>"><?= (int)$h['unaccepted'] ?></b></div>
  <div class="li"><span>Impressões com falha/incertas</span><b class="<?= $h['print_problems'] ? 'err' : '' ?>"><?= (int)$h['print_problems'] ?></b></div>
  <div class="li"><span>Impressões na fila há +5 min</span><b class="<?= $h['print_stuck'] ? 'err' : '' ?>"><?= (int)$h['print_stuck'] ?></b></div>
  <div class="li"><span>Agente de impressão</span><b><?= e($h['agent']['label']) ?></b></div>
  <div class="li"><span>Último backup (arquivo em storage/backups)</span><b><?= $h['last_backup'] ? e(date('d/m/Y H:i', strtotime($h['last_backup']))) : 'nenhum encontrado' ?></b></div>
  <div class="li"><span>Turno de caixa</span><b><?= $h['shift_open'] ? 'aberto' : 'fechado' ?></b></div>
  <p class="muted small">Perda de conexão dos tablets/painel é indicada em cada tela; o servidor não a registra.</p></section></div>

<div class="two-col">
<section class="panel"><h2>Recebimentos confirmados por forma</h2><div class="tw"><table><thead><tr><th>Forma</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['by_payment'] as $m => $r): ?><tr><td><?= e($m) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money($r['total'])) ?></td></tr><?php endforeach; if (!$x['by_payment']): ?><tr><td colspan="3" class="muted">Sem recebimentos confirmados no período.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>Mais vendidos (todos os canais)</h2><div class="tw"><table><thead><tr><th>Produto</th><th class="r">Qtd.</th><th class="r">Receita</th></tr></thead><tbody>
<?php foreach ($x['top'] as $t): ?><tr><td><?= e($t['name']) ?><?= $t['size_name'] ? ' (' . e($t['size_name']) . ')' : '' ?></td><td class="r"><?= (int)$t['qty'] ?></td><td class="r"><?= e(money((int)$t['total'])) ?></td></tr><?php endforeach; if (!$x['top']): ?><tr><td colspan="3" class="muted">Sem vendas.</td></tr><?php endif; ?></tbody></table></div></section></div>

<div class="two-col">
<section class="panel"><h2>Cancelamentos (pedidos, inclusive rodadas do salão)</h2><div class="tw"><table><thead><tr><th>Motivo</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['cancel'] as $r): ?><tr><td><?= e($r['reason']) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money((int)$r['total'])) ?></td></tr><?php endforeach; if (!$x['cancel']) : ?><tr><td colspan="3" class="muted">Nenhum cancelamento.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>Descontos no salão (por motivo)</h2><div class="tw"><table><thead><tr><th>Motivo</th><th class="r">Comandas</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['discounts'] as $r): ?><tr><td><?= e($r['reason']) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money((int)$r['total'])) ?></td></tr><?php endforeach; if (!$x['discounts']) : ?><tr><td colspan="3" class="muted">Nenhum desconto.</td></tr><?php endif; ?></tbody></table></div></section></div>

<section class="panel"><h2>Itens indisponíveis / categorias pausadas</h2>
<?php if (!$x['unavailable']): ?><p class="muted">Tudo disponível.</p><?php else: ?><p><?php foreach ($x['unavailable'] as $u): ?><span class="pill"><?= e($u['name']) ?> <small class="muted">(<?= e($u['cat']) ?>)</small></span> <?php endforeach; ?></p><?php endif; ?></section>

<section class="panel"><h2>Critérios dos indicadores</h2><ul>
  <li><b>Entrega/retirada:</b> pedidos não cancelados, por data de criação; "vendas" = total do pedido (inclui taxa de entrega); pago = pagamento conferido pela equipe.</li>
  <li><b>Salão:</b> a venda é a <b>comanda fechada</b> no período (itens − desconto). Os pedidos de produção do salão <b>não</b> entram como venda (evita contar duas vezes); taxa de serviço aparece à parte.</li>
  <li><b>Recebido:</b> pagamentos confirmados (nunca "informados"); estornos registrados saem do total. <b>Pendente:</b> pedidos sem conferência + saldo das comandas abertas.</li>
  <li><b>Faturamento ≠ recebimento ≠ lucro.</b> Não há custos: sem lucro/margem/CMV. Não é contabilidade nem conciliação bancária.</li>
  <li>Vendas atribuídas a garçom não são medida de produtividade (mesas têm vários atendentes); a Auditoria de acessos mostra apenas registros de ações.</li>
</ul></section>
