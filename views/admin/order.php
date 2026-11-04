<p><a href="/admin/pedidos">← Histórico</a></p>
<h1>Pedido #<?= (int)$o['id'] ?> <span class="pill"><?= e(Orders::statusLabel($o['status'], $o['type'])) ?></span></h1>
<div class="two-col">
<section class="panel"><h2>Cliente e entrega</h2>
  <p><b><?= e($o['customer_name']) ?></b> · <a href="tel:<?= e($o['customer_phone']) ?>"><?= e($o['customer_phone']) ?></a></p>
  <p><?= $o['type'] === 'delivery' ? 'Entrega' : ($o['type'] === 'dine_in' ? 'Salão — mesa ' . (int)$o['table_number'] : 'Retirada no balcão') ?> · recebido em <?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?><?= $o['estimate_minutes'] ? ' · estimativa ' . (int)$o['estimate_minutes'] . ' min' : '' ?></p>
  <?php if ($o['type'] === 'delivery'): ?><p><?= e($o['addr_street']) ?>, <?= e($o['addr_number']) ?><?= $o['addr_complement'] ? ' – ' . e($o['addr_complement']) : '' ?><br><?= e($o['zone_name']) ?><?= $o['addr_reference'] ? '<br>Ref.: ' . e($o['addr_reference']) : '' ?></p><?php endif; ?>
  <?php if ($o['notes']): ?><p class="obs">Obs.: <?= e($o['notes']) ?></p><?php endif; ?>
  <?php if ($o['cancel_reason']): ?><p class="err">Cancelado: <?= e($o['cancel_reason']) ?></p><?php endif; ?>
  <h2>Itens (preços da data do pedido)</h2>
  <?php foreach ($o['items'] as $i): ?><div class="li"><b><?= (int)$i['qty'] ?>× <?= e($i['name']) ?><?= $i['size_name'] ? ' (' . e($i['size_name']) . ')' : '' ?></b><span><?= e(money((int)$i['total_cents'])) ?></span>
    <?php foreach ($i['options'] as $x): ?><small><?= $x['group_name'] === 'Retirar' ? '−' : '+' ?> <?= e($x['option_name']) ?> <?= $x['price_cents'] ? e(money((int)$x['price_cents'])) : '' ?></small><?php endforeach; ?><?php if ($i['notes']): ?><small class="obs">Obs.: <?= e($i['notes']) ?></small><?php endif; ?></div><?php endforeach; ?>
  <p class="r">Subtotal <?= e(money((int)$o['subtotal_cents'])) ?> · Entrega <?= e(money((int)$o['delivery_fee_cents'])) ?> · Desconto <?= e(money((int)$o['discount_cents'])) ?><br><b>Total <?= e(money((int)$o['total_cents'])) ?></b></p>
  <p>Pagamento: <?= e($o['payment_label']) ?> — <b><?= e(Orders::PAY_STATUS[$o['payment_status']]) ?></b><?= $o['change_for_cents'] ? ' · troco p/ ' . e(money((int)$o['change_for_cents'])) : '' ?></p>
</section>
<section class="panel"><h2>Histórico de alterações</h2>
  <ol class="ev"><?php foreach ($o['events'] as $e): ?><li><time><?= e(date('d/m H:i:s', strtotime($e['created_at']))) ?></time> <?= e(match ($e['kind']) { 'created' => 'Pedido recebido', 'status' => 'Status: ' . (Orders::STATUS[$e['from_value']] ?? '') . ' → ' . (Orders::STATUS[$e['to_value']] ?? ''), 'payment' => 'Pagamento: ' . (Orders::PAY_STATUS[$e['from_value']] ?? '') . ' → ' . (Orders::PAY_STATUS[$e['to_value']] ?? ''), 'address' => 'Endereço alterado', 'items' => 'Itens alterados', default => 'Impressão' }) ?>
    <small><?= e($e['user_name'] ?? 'cliente/sistema') ?><?= $e['note'] ? ' — ' . e($e['note']) : '' ?></small></li><?php endforeach; ?></ol>
  <?php if (Auth::can('print')): ?><h2>Impressão</h2>
  <p><?php foreach (array_keys(Printing::sectors()) as $s): ?><a class="b ghost sm" target="_blank" href="/admin/pedido/<?= (int)$o['id'] ?>/comanda?sector=<?= e($s) ?>&paper=80">Ver comanda <?= e($s) ?> (80mm)</a> <a class="b ghost sm" target="_blank" href="/admin/pedido/<?= (int)$o['id'] ?>/comanda?sector=<?= e($s) ?>&paper=58">58mm</a><br><?php endforeach; ?></p>
  <p><button class="b" data-order="<?= (int)$o['id'] ?>" data-reprint="">Reimprimir todos os setores (fila)</button></p>
  <?php if ($jobs): ?><table><thead><tr><th>Setor</th><th>Tipo</th><th>Estado</th><th>Tent.</th><th></th></tr></thead><tbody><?php foreach ($jobs as $j): ?><tr><td><?= e(Printing::SECTORS[$j['sector']] ?? ($j['sector'] === 'sem_destino' ? 'SEM DESTINO' : $j['sector'])) ?></td><td><?= $j['kind'] === 'reprint' ? 'reimpressão' : 'original' ?></td><td><?= e(Printing::STATUS[$j['status']]) ?><?= $j['last_error'] ? '<br><small class="err">' . e($j['last_error']) . '</small>' : '' ?></td><td><?= (int)$j['attempts'] ?></td>
    <td><?php if (in_array($j['status'], ['failed', 'uncertain'], true)): ?><button class="b sm ghost" data-retry="<?= (int)$j['id'] ?>">Reenviar</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; endif; ?>
</section></div>
