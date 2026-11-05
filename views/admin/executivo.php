<?php
$c = $x['cur']; $p = $x['prev']; $ch = $c['channels'];
$delta = function (int $a, int $b): string { if ($b === 0) return $a === 0 ? '—' : 'novo'; $d = ($a - $b) / $b * 100; return ($d >= 0 ? '+' : '') . number_format($d, 1, ',', '') . '%'; };
$h = $x['health'];
$ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$chanTotal = max(1, (int)$ch['delivery']['total'] + (int)$ch['pickup']['total'] + (int)$ch['salon']['total']);
$pct = fn(int $v) => number_format($v / $chanTotal * 100, 1, ',', '') . '%';
$alerts = [];
if ($h['late_orders']) $alerts[] = ['clock', $h['late_orders'] . ' pedido(s) em atraso', 'Passaram da estimativa de atendimento.', 'bad', '/admin'];
if ($h['unaccepted']) $alerts[] = ['bell', $h['unaccepted'] . ' pedido(s) novo(s) sem aceite há +10 min', 'Confira a gestão de pedidos.', 'bad', '/admin'];
if ($h['print_problems']) $alerts[] = ['print', $h['print_problems'] . ' impressão(ões) com falha ou resultado incerto', 'Revise a fila em Impressão.', 'bad', '/admin/impressao'];
if ($h['print_stuck']) $alerts[] = ['print', $h['print_stuck'] . ' impressão(ões) na fila há +5 min', 'A estação pode estar fechada ou parada.', 'warn', '/admin/impressao'];
if ($h['agent']['state'] !== 'online') $alerts[] = ['print', $h['agent']['label'], 'As comandas ficam na fila até a estação conectar.', 'warn', '/admin/impressao'];
if ($x['unavailable']) $alerts[] = ['menu', count($x['unavailable']) . ' item(ns)/categoria(s) indisponível(is)', implode(', ', array_slice(array_map(fn($u) => $u['name'], $x['unavailable']), 0, 4)), 'warn', '/admin/cardapio'];
?>
<form class="fbar" method="get"><label>De<input type="date" name="from" value="<?= e($from) ?>"></label><label>Até<input type="date" name="to" value="<?= e($to) ?>"></label><button class="b"><?= $ic('refresh') ?> Aplicar</button>
  <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap"><a class="b ghost sm" href="/admin/executivo.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar comandas do salão (CSV)</a><a class="b ghost sm" href="/admin/relatorios.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Exportar pedidos (CSV)</a></span></form>
<div class="info-box"><?= $ic('alert') ?><span>Comparação com o período equivalente anterior (<?= e(date('d/m', strtotime($x['prev_from']))) ?> a <?= e(date('d/m', strtotime($x['prev_to']))) ?>). Faturamento, recebimento e lucro são medidas diferentes: <b>lucro, margem e CMV não são exibidos porque não há custos cadastrados</b>. Critérios no fim da página.</span></div>

<div class="kpi-row">
  <div class="kpi"><span class="kic"><?= $ic('reports') ?></span><div><span>Faturamento (período)</span><b><?= e(money($c['revenue'])) ?></b><small>vs. anterior: <?= e($delta($c['revenue'], $p['revenue'])) ?></small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('check') ?></span><div><span>Recebido (confirmado)</span><b><?= e(money($x['received'])) ?></b><small>pedidos + recebimentos de comanda confirmados</small></div></div>
  <div class="kpi hl"><span class="kic"><?= $ic('clock') ?></span><div><span>Pendente de recebimento</span><b><?= e(money($x['pending'])) ?></b><small>pedidos sem conferência + saldo de comandas</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('store') ?></span><div><span>Consumo em aberto no salão</span><b><?= e(money($x['open_consumption'])) ?></b><small>ainda não é venda</small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('orders') ?></span><div><span>Pedidos (entrega + retirada)</span><b><?= (int)$c['orders'] ?></b><small>vs. anterior: <?= e($delta($c['orders'], $p['orders'])) ?></small></div></div>
  <div class="kpi"><span class="kic"><?= $ic('note') ?></span><div><span>Comandas fechadas (salão)</span><b><?= (int)$c['tabs'] ?></b><small>vs. anterior: <?= e($delta($c['tabs'], $p['tabs'])) ?></small></div></div>
</div>

<div class="split">
<section class="card tbl-card"><div class="card-h"><h2>Vendas por canal</h2><p>Participação no faturamento do período.</p></div><div class="tw"><table><thead><tr><th>Canal</th><th class="r">Pedidos/comandas</th><th class="r">Vendas</th><th class="r">% das vendas</th><th class="r">Ticket médio</th><th class="r">Descontos</th><th class="r">Pagos (conferidos)</th><th class="r">Pendentes</th></tr></thead><tbody>
<?php foreach (['delivery', 'pickup'] as $k): $r = $ch[$k]; ?><tr><td><b><?= e($r['label']) ?></b></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money($r['total'])) ?></td><td class="r"><?= $pct((int)$r['total']) ?></td><td class="r"><?= e(money($r['n'] ? (int)round($r['total'] / $r['n']) : 0)) ?></td><td class="r"><?= e(money($r['disc'])) ?></td><td class="r"><?= e(money($r['paid'])) ?></td><td class="r"><?= e(money($r['pending'])) ?></td></tr><?php endforeach; $s = $ch['salon']; ?>
<tr><td><b><?= e($s['label']) ?></b></td><td class="r"><?= (int)$s['n'] ?></td><td class="r"><?= e(money($s['total'])) ?></td><td class="r"><?= $pct((int)$s['total']) ?></td><td class="r"><?= e(money($s['n'] ? (int)round($s['total'] / $s['n']) : 0)) ?></td><td class="r"><?= e(money($s['disc'])) ?></td><td class="r" colspan="2">ver recebimentos por forma abaixo</td></tr>
</tbody></table></div><p class="muted small" style="padding:0 16px 12px">Salão: taxa de serviço (<?= e(money($s['fee'])) ?>) não entra em vendas. Atendimentos abertos no período: <?= (int)$s['visits'] ?> · pessoas: <?= (int)$s['people'] ?>.</p></section>
<section class="card"><div class="card-h"><h2>Alertas operacionais</h2><p>Situações que exigem atenção na operação.</p></div>
<?php if (!$alerts): ?><div class="empty"><?= $ic('check') ?><p>Nenhum alerta no momento.</p></div><?php else: foreach ($alerts as [$icn, $t, $d, $sev, $href]): ?>
  <a class="li" style="text-decoration:none;color:inherit;align-items:center;gap:10px" href="<?= $href ?>"><span class="kic" style="display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:var(--bg)"><?= $ic($icn) ?></span><span style="flex:1"><b><?= e($t) ?></b><small style="display:block;color:var(--muted)"><?= e($d) ?></small></span><span class="pill-st <?= $sev ?>"><?= $sev === 'bad' ? 'Ação necessária' : 'Atenção' ?></span></a>
<?php endforeach; endif; ?></section>
</div>

<div class="two-col">
<section class="card"><h2>Operação do salão agora</h2>
  <ul class="kv-list"><li><span>Mesas livres</span><b><?= (int)$x['salon_now']['free'] ?></b></li><li><span>Mesas em atendimento</span><b><?= (int)$x['salon_now']['busy'] ?></b></li><li><span>Mesas aguardando caixa (sem grupo ativo)</span><b><?= (int)$x['salon_now']['awaiting'] ?></b></li>
  <li><span>Pessoas em atendimento</span><b><?= (int)$x['salon_now']['people'] ?></b></li><li><span>Comandas abertas</span><b><?= (int)$x['salon_now']['open_tabs'] ?></b></li><li><span>Contas aguardando o caixa</span><b><?= (int)$x['salon_now']['awaiting_tabs'] ?></b></li><li><span>Recebimentos informados a conferir</span><b><?= (int)$x['salon_now']['pending_payments'] ?></b></li>
  <li><span>Tempo médio de atendimento (fechados no período)</span><b><?= $x['avg_visit_min'] === null ? 'sem dados' : (int)$x['avg_visit_min'] . ' min' ?></b></li></ul>
  <p><a class="b sm ghost" href="/admin/salao">Mesas e comandas</a> <a class="b sm ghost" href="/admin/caixa">Caixa</a></p></section>
<section class="card"><h2>Saúde da operação</h2>
  <ul class="kv-list"><li><span>Pedidos atrasados (passaram da estimativa)</span><b class="<?= $h['late_orders'] ? 'err' : '' ?>"><?= (int)$h['late_orders'] ?></b></li>
  <li><span>Pedidos novos sem aceite há +10 min</span><b class="<?= $h['unaccepted'] ? 'err' : '' ?>"><?= (int)$h['unaccepted'] ?></b></li>
  <li><span>Impressões com falha/incertas</span><b class="<?= $h['print_problems'] ? 'err' : '' ?>"><?= (int)$h['print_problems'] ?></b></li>
  <li><span>Impressões na fila há +5 min</span><b class="<?= $h['print_stuck'] ? 'err' : '' ?>"><?= (int)$h['print_stuck'] ?></b></li>
  <li><span>Agente de impressão</span><b><?= e($h['agent']['label']) ?></b></li>
  <li><span>Último backup (arquivo em storage/backups)</span><b><?= $h['last_backup'] ? e(date('d/m/Y H:i', strtotime($h['last_backup']))) : 'nenhum encontrado' ?></b></li>
  <li><span>Turno de caixa</span><b><?= $h['shift_open'] ? 'aberto' : 'fechado' ?></b></li></ul>
  <p class="muted small">Perda de conexão dos tablets/painel é indicada em cada tela; o servidor não a registra.</p></section></div>

<div class="two-col">
<section class="card tbl-card"><div class="card-h"><h2>Recebimentos confirmados por forma</h2></div><div class="tw"><table><thead><tr><th>Forma</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['by_payment'] as $m => $r): ?><tr><td><?= e($m) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money($r['total'])) ?></td></tr><?php endforeach; if (!$x['by_payment']): ?><tr><td colspan="3"><div class="empty"><p>Sem recebimentos confirmados no período.</p></div></td></tr><?php endif; ?></tbody></table></div></section>
<section class="card tbl-card"><div class="card-h"><h2>Mais vendidos</h2><p>Itens com maior quantidade vendida no período (todos os canais).</p></div><div class="tw"><table><thead><tr><th>#</th><th>Produto</th><th class="r">Qtd.</th><th class="r">Receita</th></tr></thead><tbody>
<?php foreach ($x['top'] as $i => $t): ?><tr><td><?= $i + 1 ?></td><td><?= e($t['name']) ?><?= $t['size_name'] ? ' (' . e($t['size_name']) . ')' : '' ?></td><td class="r"><?= (int)$t['qty'] ?></td><td class="r"><?= e(money((int)$t['total'])) ?></td></tr><?php endforeach; if (!$x['top']): ?><tr><td colspan="4"><div class="empty"><p>Sem vendas no período.</p></div></td></tr><?php endif; ?></tbody></table></div></section></div>

<div class="two-col">
<section class="card tbl-card"><div class="card-h"><h2>Cancelamentos (pedidos, inclusive rodadas do salão)</h2></div><div class="tw"><table><thead><tr><th>Motivo</th><th class="r">Qtd.</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['cancel'] as $r): ?><tr><td><?= e($r['reason']) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money((int)$r['total'])) ?></td></tr><?php endforeach; if (!$x['cancel']) : ?><tr><td colspan="3"><div class="empty"><p>Nenhum cancelamento.</p></div></td></tr><?php endif; ?></tbody></table></div></section>
<section class="card tbl-card"><div class="card-h"><h2>Descontos no salão (por motivo)</h2></div><div class="tw"><table><thead><tr><th>Motivo</th><th class="r">Comandas</th><th class="r">Valor</th></tr></thead><tbody>
<?php foreach ($x['discounts'] as $r): ?><tr><td><?= e($r['reason']) ?></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= e(money((int)$r['total'])) ?></td></tr><?php endforeach; if (!$x['discounts']) : ?><tr><td colspan="3"><div class="empty"><p>Nenhum desconto.</p></div></td></tr><?php endif; ?></tbody></table></div></section></div>

<section class="card"><h2>Critérios dos indicadores</h2><ul>
  <li><b>Entrega/retirada:</b> pedidos não cancelados, por data de criação; "vendas" = total do pedido (inclui taxa de entrega); pago = pagamento conferido pela equipe.</li>
  <li><b>Salão:</b> a venda é a <b>comanda fechada</b> no período (itens − desconto). Os pedidos de produção do salão <b>não</b> entram como venda (evita contar duas vezes); taxa de serviço aparece à parte.</li>
  <li><b>Recebido:</b> pagamentos confirmados (nunca "informados"); estornos registrados saem do total. <b>Pendente:</b> pedidos sem conferência + saldo das comandas abertas.</li>
  <li><b>Faturamento ≠ recebimento ≠ lucro.</b> Não há custos: sem lucro/margem/CMV. Não é contabilidade nem conciliação bancária.</li>
  <li>Vendas atribuídas a garçom não são medida de produtividade (mesas têm vários atendentes); a Auditoria de acessos mostra apenas registros de ações.</li>
</ul></section>
