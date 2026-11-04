<h1>Histórico de pedidos</h1>
<form class="filters" method="get">
  <label>De<input type="date" name="from" value="<?= e($f['from']) ?>"></label><label>Até<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label>Status<select name="status"><option value="">Todos</option><?php foreach (Orders::STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
  <label>Nº, nome ou telefone<input name="q" value="<?= e($f['q']) ?>"></label><button class="b">Filtrar</button>
</form>
<?php if (!$orders): ?><p class="muted">Nenhum pedido encontrado neste filtro.</p><?php else: ?>
<div class="tw"><table><thead><tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Tipo</th><th>Status</th><th>Pagamento</th><th class="r">Total</th></tr></thead><tbody>
<?php foreach ($orders as $o): ?><tr><td><a href="/admin/pedido/<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td><td><?= e(date('d/m H:i', strtotime($o['created_at']))) ?></td><td><?= e($o['customer_name']) ?></td>
<td><?= $o['type'] === 'delivery' ? 'Entrega' : 'Retirada' ?></td><td><?= e(Orders::statusLabel($o['status'], $o['type'])) ?></td><td><?= e($o['payment_label']) ?> · <?= e(Orders::PAY_STATUS[$o['payment_status']]) ?></td><td class="r"><?= e(money((int)$o['total_cents'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
