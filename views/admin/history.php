<?php $ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$stCls = ['received' => 'bad', 'accepted' => 'warn', 'preparing' => 'warn', 'ready' => 'info', 'completed' => 'ok', 'cancelled' => 'mute']; ?>
<form class="fbar" method="get" role="search">
  <label class="grow">Nº, nome ou telefone<span class="search" style="position:relative"><?= $ic('search') ?><input name="q" value="<?= e($f['q']) ?>" placeholder="Buscar por nº do pedido ou cliente"></span></label>
  <label>De<input type="date" name="from" value="<?= e($f['from']) ?>"></label><label>Até<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label>Status<select name="status"><option value="">Todos</option><?php foreach (Orders::STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
  <button class="b"><?= $ic('search') ?> Filtrar</button></form>
<?php if (!$orders): ?><div class="card"><div class="empty"><?= $ic('history') ?><p>Nenhum pedido encontrado neste filtro.</p></div></div><?php else: ?>
<div class="card tbl-card"><div class="tw"><table><thead><tr><th>Nº do pedido</th><th>Data e hora</th><th>Cliente</th><th>Canal</th><th>Status</th><th>Pagamento</th><th class="r">Total</th></tr></thead><tbody>
<?php foreach ($orders as $o): ?><tr><td><a href="/admin/pedido/<?= (int)$o['id'] ?>"><b>#<?= (int)$o['id'] ?></b></a></td><td><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td><td><?= e($o['customer_name']) ?></td>
<td><?= $ic($o['type'] === 'delivery' ? 'truck' : 'store') ?> <?= $o['type'] === 'delivery' ? 'Entrega' : ($o['type'] === 'dine_in' ? 'Salão' : 'Retirada') ?></td><td><span class="pill-st <?= $stCls[$o['status']] ?? 'mute' ?>"><?= e(Orders::statusLabel($o['status'], $o['type'])) ?></span></td><td><?= e($o['payment_label']) ?> · <span class="pill-st <?= $o['payment_status'] === 'confirmed' ? 'ok' : ($o['payment_status'] === 'refunded' ? 'mute' : 'warn') ?>"><?= e(Orders::PAY_STATUS[$o['payment_status']]) ?></span></td><td class="r"><b><?= e(money((int)$o['total_cents'])) ?></b></td></tr><?php endforeach; ?>
</tbody></table></div><p class="muted small" style="padding:10px 16px">Exibindo <?= count($orders) ?> pedido(s). Abra um pedido para ver a linha do tempo, os itens e reimprimir.</p></div><?php endif; ?>
