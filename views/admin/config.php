<h1>Configurações</h1>
<form method="post" class="stack">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <section class="panel"><h2>Funcionamento</h2>
    <label class="ck"><input type="checkbox" name="orders_open" value="1" <?= $s['orders_open'] === '1' ? 'checked' : '' ?>> <b>Receber pedidos</b> (desmarque para pausar tudo imediatamente)</label>
    <label class="ck"><input type="checkbox" name="delivery_enabled" value="1" <?= $s['delivery_enabled'] === '1' ? 'checked' : '' ?>> Entrega</label>
    <label class="ck"><input type="checkbox" name="pickup_enabled" value="1" <?= $s['pickup_enabled'] === '1' ? 'checked' : '' ?>> Retirada no balcão</label>
    <div class="inline"><label>Estimativa entrega (min)<input type="number" name="estimate_delivery_min" value="<?= e($s['estimate_delivery_min']) ?>" class="xs"></label><label>Estimativa retirada (min)<input type="number" name="estimate_pickup_min" value="<?= e($s['estimate_pickup_min']) ?>" class="xs"></label></div>
    <h3>Horário de pedidos</h3><p class="muted small">Valores iniciais vieram do perfil do Instagram (18h–23h). Confirme e ajuste.</p>
    <?php $sch = Settings::json('schedule'); foreach (['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'] as $d => $n): $sl = $sch[(string)$d][0] ?? ['18:00', '23:00']; ?>
      <div class="inline"><label class="ck w8"><input type="checkbox" name="day_on[<?= $d ?>]" value="1" <?= !empty($sch[(string)$d]) ? 'checked' : '' ?>> <?= $n ?></label><input type="time" name="open[<?= $d ?>]" value="<?= e($sl[0]) ?>" aria-label="Abre"> às <input type="time" name="close[<?= $d ?>]" value="<?= e($sl[1]) ?>" aria-label="Fecha"></div><?php endforeach; ?></section>
  <section class="panel"><h2>Contato</h2>
    <label>Telefone<input name="store_phone" value="<?= e($s['store_phone']) ?>"></label><label>Endereço<input name="store_address" value="<?= e($s['store_address']) ?>"></label>
    <label>WhatsApp (opcional)<input name="store_whatsapp" value="<?= e($s['store_whatsapp']) ?>"></label><label>Instagram<input name="instagram_url" value="<?= e($s['instagram_url']) ?>"></label><label>Link de canais (Linktree)<input name="links_url" value="<?= e($s['links_url']) ?>"></label></section>
  <section class="panel"><h2>Pizzas</h2>
    <label>Preço de meia a meia<select name="half_rule"><option value="average" <?= $s['half_rule'] === 'average' ? 'selected' : '' ?>>Valor médio dos sabores (PDF: “será cobrado o valor médio”)</option><option value="highest" <?= $s['half_rule'] === 'highest' ? 'selected' : '' ?>>Maior valor</option></select></label>
    <label>Máximo de sabores por pizza<input type="number" min="1" max="4" name="max_flavors" value="<?= e($s['max_flavors']) ?>" class="xs"></label></section>
  <section class="panel"><h2>Salão</h2>
    <label>Quantidade de mesas (1 a N; reduzir só desativa as maiores, sem apagar histórico)<input type="number" min="0" max="200" name="table_count" value="<?= e($s['table_count']) ?>" class="xs"></label>
    <label>Taxa de serviço (%) — em branco = sem taxa; o caixa pode remover por comanda, com motivo<input name="service_fee_percent" value="<?= e($s['service_fee_percent']) ?>" class="xs" inputmode="decimal"></label>
    <label class="ck"><input type="checkbox" name="allow_independent_visits" value="1" <?= $s['allow_independent_visits'] === '1' ? 'checked' : '' ?>> Permitir atendimento independente numa mesa que já está ocupada (o garçom sempre escolhe de forma explícita)</label></section>
  <section class="panel"><h2>Formas de pagamento</h2><p class="muted small">Todos são conferidos manualmente pela equipe; nenhum é confirmado automaticamente. Não há pagamento online integrado.</p>
    <?php foreach (Settings::json('payment_methods') as $k => $p): ?><div class="inline"><label class="ck"><input type="checkbox" name="pay_on[<?= e($k) ?>]" value="1" <?= $p['on'] ? 'checked' : '' ?>> Ativo</label><input name="pay_label[<?= e($k) ?>]" value="<?= e($p['label']) ?>" aria-label="Rótulo de <?= e($k) ?>"></div><?php endforeach; ?>
    <label>Instruções de PIX mostradas ao cliente (ex.: chave) — deixe em branco se não usar<textarea name="pix_instructions" rows="2" maxlength="400"><?= e($s['pix_instructions']) ?></textarea></label></section>
  <section class="panel"><h2>Setores de impressão</h2>
    <?php foreach (Printing::sectors() as $k => $p): ?><div class="inline"><label class="ck w8"><input type="checkbox" name="ps_on[<?= e($k) ?>]" value="1" <?= $p['on'] ? 'checked' : '' ?>> <?= e(Printing::SECTORS[$k] ?? ucfirst($k)) ?></label>
      <label>Papel<select name="ps_paper[<?= e($k) ?>]"><option value="80" <?= $p['paper'] == 80 ? 'selected' : '' ?>>80 mm</option><option value="58" <?= $p['paper'] == 58 ? 'selected' : '' ?>>58 mm</option></select></label>
      <label class="ck"><input type="checkbox" name="ps_values[<?= e($k) ?>]" value="1" <?= $p['values'] ? 'checked' : '' ?>> Mostrar valores/endereço/pagamento</label></div><?php endforeach; ?></section>
  <section class="panel"><h2>Entrega: bairros e taxas</h2><p class="muted small">Não há área padrão: cadastre os bairros atendidos. Sem bairros ativos, a entrega não funciona.</p>
    <?php $zs = $zones; $zs[] = ['id' => 0, 'name' => '', 'fee_cents' => 0, 'min_order_cents' => 0, 'active' => 1]; $zs[] = $zs[count($zs) - 1]; $zs[] = $zs[count($zs) - 1];
    foreach ($zs as $i => $z): ?><div class="inline"><input type="hidden" name="z_id[<?= $i ?>]" value="<?= (int)$z['id'] ?>"><label>Bairro<input name="z_name[<?= $i ?>]" value="<?= e($z['name']) ?>"></label>
      <label>Taxa (R$)<input name="z_fee[<?= $i ?>]" value="<?= e(number_format($z['fee_cents'] / 100, 2, ',', '')) ?>" class="xs"></label><label>Pedido mín. (R$)<input name="z_min[<?= $i ?>]" value="<?= e(number_format($z['min_order_cents'] / 100, 2, ',', '')) ?>" class="xs"></label>
      <label class="ck"><input type="checkbox" name="z_on[<?= $i ?>]" value="1" <?= $z['active'] ? 'checked' : '' ?>> Atende</label></div><?php endforeach; ?></section>
  <button class="b">Salvar configurações</button>
</form>
