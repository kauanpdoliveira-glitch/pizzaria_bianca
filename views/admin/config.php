<?php $ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$st = Settings::storeStatus(); $sch = Settings::json('schedule'); $tz = date_default_timezone_get();
$sw = fn(string $name, string $label, string $hint, bool $on, string $icon) => '<div class="set-row"><span class="kic" style="display:grid;place-items:center;width:40px;height:40px;border-radius:50%;background:var(--bg)">' . $ic($icon) . '</span><span class="grow"><b>' . e($label) . '</b><small>' . e($hint) . '</small></span><input class="sw" type="checkbox" role="switch" name="' . e($name) . '" value="1" ' . ($on ? 'checked' : '') . ' aria-label="' . e($label) . '"></div>'; ?>
<nav class="tabs" role="tablist" aria-label="Seções das configurações" id="cfg-tabs">
  <button type="button" role="tab" class="on" data-tab="loja"><?= $ic('store') ?>Loja</button><button type="button" role="tab" data-tab="horarios"><?= $ic('clock') ?>Horários</button><button type="button" role="tab" data-tab="entrega"><?= $ic('truck') ?>Entrega</button><button type="button" role="tab" data-tab="pagamentos"><?= $ic('card') ?>Pagamentos</button><button type="button" role="tab" data-tab="salao"><?= $ic('menu') ?>Salão e impressão</button></nav>
<form method="post" id="cfg-form">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="split">
  <div>
    <section class="card" data-pane="loja"><div class="card-h"><h2>Dados da loja</h2><p>Informações exibidas no site e nos canais de venda.</p></div>
      <label>Telefone<input name="store_phone" value="<?= e($s['store_phone']) ?>"></label><label>Endereço<input name="store_address" value="<?= e($s['store_address']) ?>"></label>
      <label>WhatsApp (opcional)<input name="store_whatsapp" value="<?= e($s['store_whatsapp']) ?>"></label><label>Instagram<input name="instagram_url" value="<?= e($s['instagram_url']) ?>"></label><label>Link de canais (Linktree)<input name="links_url" value="<?= e($s['links_url']) ?>"></label></section>
    <section class="card" data-pane="loja"><div class="card-h"><h2>Funcionamento da loja</h2><p>Controle o recebimento de pedidos e as formas de atendimento.</p></div>
      <?= $sw('orders_open', 'Receber pedidos', 'Desmarque para pausar tudo imediatamente.', $s['orders_open'] === '1', 'store') ?>
      <?= $sw('auto_accept', 'Aceitar pedidos automaticamente', 'O servidor aceita o pedido válido do site assim que ele é gravado, mesmo com o painel fechado; o pagamento continua pendente até a conferência.', $s['auto_accept'] === '1', 'check') ?>
      <?= $sw('delivery_enabled', 'Entrega', 'Receba pedidos para entrega.', $s['delivery_enabled'] === '1', 'truck') ?>
      <?= $sw('pickup_enabled', 'Retirada', 'Permita que o cliente retire o pedido na loja.', $s['pickup_enabled'] === '1', 'orders') ?>
      <div class="inline"><label>Estimativa entrega (min)<input type="number" name="estimate_delivery_min" value="<?= e($s['estimate_delivery_min']) ?>" class="xs"></label><label>Estimativa retirada (min)<input type="number" name="estimate_pickup_min" value="<?= e($s['estimate_pickup_min']) ?>" class="xs"></label></div></section>
    <section class="card" data-pane="loja"><div class="card-h"><h2>Pizzas</h2></div>
      <label>Preço de meia a meia<select name="half_rule"><option value="average" <?= $s['half_rule'] === 'average' ? 'selected' : '' ?>>Valor médio dos sabores (PDF: “será cobrado o valor médio”)</option><option value="highest" <?= $s['half_rule'] === 'highest' ? 'selected' : '' ?>>Maior valor</option></select></label>
      <label>Máximo de sabores por pizza<input type="number" min="1" max="4" name="max_flavors" value="<?= e($s['max_flavors']) ?>" class="xs"></label></section>

    <section class="card" data-pane="horarios" hidden><div class="card-h"><h2>Horário de pedidos</h2><p>Valores iniciais vieram do perfil do Instagram (18h–23h). Confirme e ajuste.</p></div>
      <?php foreach (['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'] as $d => $n): $sl = $sch[(string)$d][0] ?? ['18:00', '23:00']; ?>
      <div class="set-row"><span class="grow"><label class="ck w8"><input type="checkbox" class="sw" role="switch" name="day_on[<?= $d ?>]" value="1" <?= !empty($sch[(string)$d]) ? 'checked' : '' ?>> <b><?= $n ?></b></label></span><input type="time" name="open[<?= $d ?>]" value="<?= e($sl[0]) ?>" aria-label="Abre — <?= $n ?>" style="width:auto"> <span>às</span> <input type="time" name="close[<?= $d ?>]" value="<?= e($sl[1]) ?>" aria-label="Fecha — <?= $n ?>" style="width:auto"></div><?php endforeach; ?></section>

    <section class="card" data-pane="entrega" hidden><div class="card-h"><h2>Bairros e taxas de entrega</h2><p>Não há área padrão: cadastre os bairros atendidos. Sem bairros ativos, a entrega não funciona.</p></div>
      <?php $zs = $zones; $zs[] = ['id' => 0, 'name' => '', 'fee_cents' => 0, 'min_order_cents' => 0, 'active' => 1]; $zs[] = $zs[count($zs) - 1]; $zs[] = $zs[count($zs) - 1];
      foreach ($zs as $i => $z): ?><div class="inline"><input type="hidden" name="z_id[<?= $i ?>]" value="<?= (int)$z['id'] ?>"><label>Bairro<input name="z_name[<?= $i ?>]" value="<?= e($z['name']) ?>"></label>
        <label>Taxa (R$)<input name="z_fee[<?= $i ?>]" value="<?= e(number_format($z['fee_cents'] / 100, 2, ',', '')) ?>" class="xs"></label><label>Pedido mín. (R$)<input name="z_min[<?= $i ?>]" value="<?= e(number_format($z['min_order_cents'] / 100, 2, ',', '')) ?>" class="xs"></label>
        <label class="ck"><input type="checkbox" class="sw" role="switch" name="z_on[<?= $i ?>]" value="1" <?= $z['active'] ? 'checked' : '' ?>> Atende</label></div><?php endforeach; ?></section>

    <section class="card" data-pane="pagamentos" hidden><div class="card-h"><h2>Formas de pagamento</h2><p>Todos são conferidos manualmente pela equipe; nenhum é confirmado automaticamente. Não há pagamento online integrado.</p></div>
      <?php foreach (Settings::json('payment_methods') as $k => $p): ?><div class="set-row"><input class="sw" type="checkbox" role="switch" name="pay_on[<?= e($k) ?>]" value="1" <?= $p['on'] ? 'checked' : '' ?> aria-label="Ativar <?= e($p['label']) ?>"><span class="grow"><input name="pay_label[<?= e($k) ?>]" value="<?= e($p['label']) ?>" aria-label="Rótulo de <?= e($k) ?>" style="margin:0"></span></div><?php endforeach; ?>
      <label>Instruções de PIX mostradas ao cliente (ex.: chave) — deixe em branco se não usar<textarea name="pix_instructions" rows="2" maxlength="400"><?= e($s['pix_instructions']) ?></textarea></label></section>

    <section class="card" data-pane="salao" hidden><div class="card-h"><h2>Salão</h2></div>
      <label>Quantidade de mesas (1 a N; reduzir só desativa as maiores, sem apagar histórico)<input type="number" min="0" max="200" name="table_count" value="<?= e($s['table_count']) ?>" class="xs"></label>
      <label>Taxa de serviço (%) — em branco = sem taxa; o caixa pode remover por comanda, com motivo<input name="service_fee_percent" value="<?= e($s['service_fee_percent']) ?>" class="xs" inputmode="decimal"></label>
      <?= $sw('allow_independent_visits', 'Atendimento independente na mesa ocupada', 'Permite um atendimento independente numa mesa que já está ocupada (o garçom sempre escolhe de forma explícita).', $s['allow_independent_visits'] === '1', 'store') ?></section>
    <section class="card" data-pane="salao" hidden><div class="card-h"><h2>Setores de impressão</h2><p>Destinos, vias, impressão automática e setor por categoria ficam em <b>Configurações → Impressão</b>.</p></div>
      <?php if (Auth::can('print') || Auth::can('print_config')): ?><p><a class="b ghost" href="/admin/config/impressao"><?= $ic('print') ?> Impressoras e estações (QZ Tray) →</a></p><?php endif; ?></section>
  </div>
  <aside class="card" aria-label="Status da loja"><div class="card-h"><h2>Status da loja</h2><p>Como a loja está configurada agora.</p></div>
    <p class="info-box" style="background:<?= $st['open'] ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $st['open'] ? 'var(--ok)' : 'var(--bad)' ?>"><?= $ic($st['open'] ? 'check' : 'x') ?><span><b><?= $st['open'] ? 'Loja aberta' : 'Loja fechada' ?></b><br><?= $st['open'] ? 'Recebendo pedidos' : e($st['reason']) ?></span></p>
    <h3>Atendimentos ativos</h3><ul class="kv-list"><li><span>Entrega</span><span class="pill-st <?= $s['delivery_enabled'] === '1' ? 'ok' : 'mute' ?>"><?= $s['delivery_enabled'] === '1' ? 'Ativo' : 'Desativado' ?></span></li><li><span>Retirada</span><span class="pill-st <?= $s['pickup_enabled'] === '1' ? 'ok' : 'mute' ?>"><?= $s['pickup_enabled'] === '1' ? 'Ativo' : 'Desativado' ?></span></li><li><span>Salão</span><span class="pill-st <?= (int)$s['table_count'] > 0 ? 'ok' : 'mute' ?>"><?= (int)$s['table_count'] > 0 ? 'Ativo (' . (int)$s['table_count'] . ' mesas)' : 'Sem mesas' ?></span></li><li><span>Aceite automático</span><span class="pill-st <?= $s['auto_accept'] === '1' ? 'ok' : 'mute' ?>"><?= $s['auto_accept'] === '1' ? 'Ligado' : 'Desligado' ?></span></li></ul>
    <h3 style="margin-top:14px">Fuso horário</h3><p><?= e($tz) ?></p></aside>
  </div>
  <div class="actions" style="position:sticky;bottom:0;background:linear-gradient(#fff0,var(--bg) 40%);padding:12px 0"><a class="b ghost" href="/admin/config">Cancelar</a><button class="b"><?= $ic('check') ?> Salvar configurações</button></div>
</form>
