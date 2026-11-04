<section class="wrap narrow">
  <h1>Finalizar pedido</h1>
  <p class="status-pill <?= $status['open'] ? 'on' : 'off' ?>"><span class="dot"></span><?= $status['open'] ? 'Recebendo pedidos' : e($status['reason']) ?></p>
  <div id="empty" hidden class="empty"><p>Sua sacola está vazia.</p><a class="btn" href="/cardapio">Ver cardápio</a></div>
  <form id="co-form" novalidate hidden>
    <fieldset><legend>Como prefere receber?</legend>
      <div class="seg">
        <?php if ($store['delivery_enabled'] === '1'): ?><label><input type="radio" name="type" value="delivery" checked><span>Entrega</span></label><?php endif; ?>
        <?php if ($store['pickup_enabled'] === '1'): ?><label><input type="radio" name="type" value="pickup" <?= $store['delivery_enabled'] === '1' ? '' : 'checked' ?>><span>Retirada no balcão</span></label><?php endif; ?>
      </div>
    </fieldset>
    <fieldset><legend>Seus dados</legend>
      <label class="f">Nome<input name="name" autocomplete="name" required maxlength="120"></label>
      <label class="f">Telefone com DDD<input name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="(31) 90000-0000"></label>
    </fieldset>
    <fieldset id="addr"><legend>Endereço de entrega</legend>
      <?php if (!$zones): ?><p class="alert">Entrega indisponível: nenhum bairro atendido foi cadastrado. Escolha retirada.</p><?php endif; ?>
      <label class="f">Bairro atendido<select name="zone_id"><option value="">Selecione</option>
        <?php foreach ($zones as $z): ?><option value="<?= (int)$z['id'] ?>" data-fee="<?= (int)$z['fee_cents'] ?>" data-min="<?= (int)$z['min_order_cents'] ?>"><?= e($z['name']) ?> — taxa <?= e(money((int)$z['fee_cents'])) ?></option><?php endforeach; ?></select></label>
      <div class="two"><label class="f">Rua<input name="street" autocomplete="address-line1" maxlength="160"></label><label class="f">Número<input name="number" maxlength="20"></label></div>
      <label class="f">Complemento (opcional)<input name="complement" maxlength="120"></label>
      <label class="f">Ponto de referência (opcional)<input name="reference" maxlength="160"></label>
    </fieldset>
    <fieldset><legend>Pagamento</legend>
      <p class="muted small">O pagamento é feito na entrega ou retirada. A equipe confirma o recebimento — enviar o pedido não confirma pagamento.</p>
      <?php foreach ($pays as $k => $p): ?><label class="opt"><input type="radio" name="payment" value="<?= e($k) ?>"><span><?= e($p['label']) ?></span></label><?php endforeach; ?>
      <?php if ($store['pix_instructions']): ?><p class="muted small" id="pix-note" hidden><?= e($store['pix_instructions']) ?></p><?php endif; ?>
      <label class="f" id="change" hidden>Troco para quanto? (opcional)<input name="change_for" inputmode="decimal" placeholder="Ex.: 100,00"></label>
    </fieldset>
    <label class="f">Observações do pedido (opcional)<textarea name="notes" rows="2" maxlength="300"></textarea></label>
    <div id="price-note" class="alert" role="alert" hidden><b>Atenção: alguns preços mudaram.</b><ul id="price-list"></ul><label class="opt"><input type="checkbox" id="price-ack"><span>Estou ciente e quero continuar com os valores atuais</span></label></div>
    <section class="summary" aria-live="polite"><h2 class="h-sm">Resumo</h2><div id="summary"></div></section>
    <p id="form-error" class="alert" role="alert" hidden></p>
    <button class="btn block" id="submit" type="submit">Enviar pedido</button>
    <p class="muted small">Seus dados são usados só para preparar e entregar este pedido. Seu carrinho fica salvo apenas neste aparelho.</p>
  </form>
</section>
