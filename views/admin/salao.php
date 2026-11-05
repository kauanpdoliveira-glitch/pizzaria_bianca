<?php
$free = count(array_filter($tables, fn($t) => $t['state'] === 'free')); $busy = count(array_filter($tables, fn($t) => $t['state'] === 'busy')); $wait = count(array_filter($tables, fn($t) => $t['state'] === 'awaiting'));
$people = array_sum(array_column($tables, 'people'));
$ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$stLabel = ['free' => ['Livre', 'ok'], 'busy' => ['Em atendimento', 'info'], 'awaiting' => ['Aguardando caixa', 'warn']];
?>
<nav class="tabs" aria-label="Seções do salão"><a href="/admin/salao" aria-current="page"><?= $ic('store') ?>Mesas</a><?php if (Auth::can('cashier')): ?><a href="/admin/caixa"><?= $ic('note') ?>Comandas e caixa</a><?php endif; ?></nav>
<div class="kpi-row">
  <div class="kpi"><span class="kic"><?= $ic('check') ?></span><div><span>Livres</span><b><?= $free ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('user') ?></span><div><span>Em atendimento</span><b><?= $busy ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('card') ?></span><div><span>Aguardando caixa</span><b><?= $wait ?></b></div></div>
  <div class="kpi"><span class="kic"><?= $ic('customers') ?></span><div><span>Pessoas em atendimento</span><b><?= (int)$people ?></b><small>soma por atendimento; comandas do mesmo grupo não duplicam</small></div></div>
</div>
<div class="split">
<div>
  <div class="fbar" role="search"><label class="grow">Buscar mesa ou cliente<span class="search" style="position:relative"><?= $ic('search') ?><input type="search" data-filter="#tbl-mesas" placeholder="Buscar mesa ou cliente…" aria-label="Buscar mesa ou cliente"></span></label>
    <div class="fchips" role="group" aria-label="Filtrar por estado"><button type="button" class="fchip on" data-chip="#tbl-mesas" data-val="">Todas</button><button type="button" class="fchip" data-chip="#tbl-mesas" data-val="free">Livres</button><button type="button" class="fchip" data-chip="#tbl-mesas" data-val="busy">Em atendimento</button><button type="button" class="fchip" data-chip="#tbl-mesas" data-val="awaiting">Aguardando caixa</button></div></div>
<?php if ($mesa && $tableRow): ?>
  <section class="card"><div class="card-h"><h2>Mesa <?= (int)$tableRow['number'] ?> — atendimentos atuais e anteriores</h2><a class="b ghost sm" href="/admin/salao">Fechar histórico</a></div>
  <?php foreach ($history as $v): ?><div class="li"><b>Atendimento #<?= (int)$v['id'] ?> · <?= e($v['name']) ?> · <?= (int)$v['people'] ?> pessoa(s)</b><span><?= e(Floor::VISIT[$v['status']]) ?> · <?= e(date('d/m H:i', strtotime($v['opened_at']))) ?><?= $v['closed_at'] ? ' → ' . e(date('H:i', strtotime($v['closed_at']))) : '' ?></span>
    <?php foreach ($v['tabs'] as $t): ?><small>Comanda <a href="/admin/comanda/<?= (int)$t['id'] ?>"><?= e($t['physical_number'] ?: '#' . $t['id']) ?></a> · <?= e($t['name']) ?> · <?= e(Floor::TAB[$t['status']]) ?> · <?= e(money((int)$t['total'])) ?></small><?php endforeach; ?></div><?php endforeach; ?>
  <?php if (!$history): ?><p class="muted">Sem atendimentos nesta mesa.</p><?php endif; ?></section>
<?php endif; ?>
  <div class="card tbl-card"><div class="tw"><table id="tbl-mesas"><thead><tr><th>Mesa</th><th>Cliente(s)</th><th>Estado</th><th>Fechamentos pendentes</th><th><span class="sr">Ações</span></th></tr></thead><tbody>
<?php foreach ($tables as $t): $act = array_filter($t['visits'], fn($v) => $v['status'] === 'active'); $pen = array_filter($t['visits'], fn($v) => $v['status'] === 'awaiting_cashier'); [$lbl, $cls] = $stLabel[$t['state']]; ?>
  <tr data-state="<?= e($t['state']) ?>"><td><b><?= $ic('store') ?> Mesa <?= str_pad((string)(int)$t['number'], 2, '0', STR_PAD_LEFT) ?></b></td>
  <td><?php foreach ($act as $v): ?><div><?= e($v['name']) ?> <span class="muted small">(<?= (int)$v['people'] ?> pessoa<?= (int)$v['people'] === 1 ? '' : 's' ?>)</span> <?php foreach ($v['tabs'] as $x): ?><a href="/admin/comanda/<?= (int)$x['id'] ?>">[<?= e($x['physical_number'] ?: '#' . $x['id']) ?>]</a> <?php endforeach; ?></div><?php endforeach; ?><?php if (!$act): ?><span class="muted">—</span><?php endif; ?></td>
  <td><span class="pill-st <?= $cls ?>"><?= e($lbl) ?></span></td>
  <td><?php foreach ($pen as $v): ?><div><?= e($v['name']) ?> <?php foreach ($v['tabs'] as $x): ?><a href="/admin/comanda/<?= (int)$x['id'] ?>">[<?= e($x['physical_number'] ?: '#' . $x['id']) ?>]</a> <?php endforeach; ?></div><?php endforeach; ?><?php if (!$pen): ?><span class="muted">—</span><?php endif; ?></td>
  <td><a class="b sm ghost" href="/admin/salao?mesa=<?= (int)$t['id'] ?>">Histórico da mesa</a></td></tr><?php endforeach; ?>
<?php if (!$tables): ?><tr><td colspan="5"><div class="empty"><?= $ic('store') ?><p>Nenhuma mesa cadastrada. O proprietário define a quantidade em Configurações.</p></div></td></tr><?php endif; ?></tbody></table></div></div>
</div>
<aside class="card" aria-label="Atendimento no salão"><div class="card-h"><h2>Atendimento no salão</h2></div>
  <p>A abertura de mesas, as comandas e os pedidos de rodada são feitos pela <b>área do garçom</b>. Aqui você acompanha o estado de cada mesa.</p>
  <p><a class="b block" href="/garcom"><?= $ic('store') ?> Abrir área do garçom</a></p>
  <?php if (Auth::can('cashier')): ?><p><a class="b ghost block" href="/admin/caixa"><?= $ic('card') ?> Ir ao Caixa</a></p><?php endif; ?>
  <p class="info-box"><?= $ic('alert') ?><span>Estado de cada mesa considerando <b>todos</b> os atendimentos e comandas vinculados. Uma mesa pode estar “Em atendimento” e ter fechamentos pendentes de grupos anteriores, <b>sem unir as contas</b>.</span></p>
</aside>
</div>
