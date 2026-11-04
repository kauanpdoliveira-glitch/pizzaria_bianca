<h1>Salão — mesas e atendimentos</h1>
<p class="note">Estado de cada mesa considerando <b>todos</b> os atendimentos e comandas vinculados. Uma mesa pode estar "Em atendimento" e ao mesmo tempo ter fechamentos pendentes de grupos anteriores.</p>
<?php
$free = count(array_filter($tables, fn($t) => $t['state'] === 'free')); $busy = count(array_filter($tables, fn($t) => $t['state'] === 'busy')); $wait = count(array_filter($tables, fn($t) => $t['state'] === 'awaiting'));
$people = array_sum(array_column($tables, 'people'));
?>
<div class="kpis"><div><span>Livres</span><b><?= $free ?></b></div><div><span>Em atendimento</span><b><?= $busy ?></b></div><div><span>Aguardando caixa</span><b><?= $wait ?></b></div><div><span>Pessoas em atendimento</span><b><?= (int)$people ?></b><small>soma por atendimento; comandas do mesmo grupo não duplicam</small></div></div>
<?php if ($mesa && $tableRow): ?>
<section class="panel"><h2>Mesa <?= (int)$tableRow['number'] ?> — atendimentos atuais e anteriores</h2>
<?php foreach ($history as $v): ?><div class="li"><b>Atendimento #<?= (int)$v['id'] ?> · <?= e($v['name']) ?> · <?= (int)$v['people'] ?> pessoa(s)</b><span><?= e(Floor::VISIT[$v['status']]) ?> · <?= e(date('d/m H:i', strtotime($v['opened_at']))) ?><?= $v['closed_at'] ? ' → ' . e(date('H:i', strtotime($v['closed_at']))) : '' ?></span>
  <?php foreach ($v['tabs'] as $t): ?><small>Comanda <a href="/admin/comanda/<?= (int)$t['id'] ?>"><?= e($t['physical_number'] ?: '#' . $t['id']) ?></a> · <?= e($t['name']) ?> · <?= e(Floor::TAB[$t['status']]) ?> · <?= e(money((int)$t['total'])) ?></small><?php endforeach; ?></div><?php endforeach; ?>
<?php if (!$history): ?><p class="muted">Sem atendimentos nesta mesa.</p><?php endif; ?></section>
<?php endif; ?>
<div class="tw"><table><thead><tr><th>Mesa</th><th>Estado</th><th>Atendimentos ativos</th><th>Fechamentos pendentes</th><th></th></tr></thead><tbody>
<?php foreach ($tables as $t): $act = array_filter($t['visits'], fn($v) => $v['status'] === 'active'); $pen = array_filter($t['visits'], fn($v) => $v['status'] === 'awaiting_cashier'); ?>
<tr><td><b><?= (int)$t['number'] ?></b></td><td><?= e(['free' => 'Livre', 'busy' => 'Em atendimento', 'awaiting' => 'Aguardando caixa'][$t['state']]) ?></td>
<td><?php foreach ($act as $v): ?><div><?= e($v['name']) ?> (<?= (int)$v['people'] ?>) <?php foreach ($v['tabs'] as $x): ?><a href="/admin/comanda/<?= (int)$x['id'] ?>">[<?= e($x['physical_number'] ?: '#' . $x['id']) ?>]</a> <?php endforeach; ?></div><?php endforeach; ?></td>
<td><?php foreach ($pen as $v): ?><div><?= e($v['name']) ?> <?php foreach ($v['tabs'] as $x): ?><a href="/admin/comanda/<?= (int)$x['id'] ?>">[<?= e($x['physical_number'] ?: '#' . $x['id']) ?>]</a> <?php endforeach; ?></div><?php endforeach; ?></td>
<td><a class="b sm ghost" href="/admin/salao?mesa=<?= (int)$t['id'] ?>">Histórico da mesa</a></td></tr><?php endforeach; ?>
<?php if (!$tables): ?><tr><td colspan="5" class="muted">Nenhuma mesa cadastrada. O proprietário define a quantidade em Configurações.</td></tr><?php endif; ?></tbody></table></div>
<p><a class="b ghost" href="/garcom">Abrir área do garçom</a> <?php if (Auth::can('cashier')): ?><a class="b" href="/admin/caixa">Ir ao Caixa</a><?php endif; ?></p>
