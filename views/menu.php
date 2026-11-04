<section class="menu-banner" aria-label="Destaque do cardápio">
  <img src="/assets/img/pizza-calabresa.jpg" alt="Pizza de calabresa servida na frigideira de ferro" width="900" height="600" fetchpriority="high">
  <div class="wrap menu-banner-in"><h1>Cardápio</h1>
    <p class="status-pill <?= $status['open'] ? 'on' : 'off' ?>"><span class="dot"></span><?= $status['open'] ? 'Recebendo pedidos' : e($status['reason']) ?></p></div>
</section>
<div class="tabsbar"><div class="wrap"><div id="tabs" class="tabs" role="tablist" aria-label="Categorias do cardápio"></div></div></div>
<div class="wrap menu-wrap">
  <div id="subchips" class="subchips" role="group" aria-label="Tipos" hidden></div>
  <label class="msearch"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg><span class="sr">Buscar no cardápio</span><input id="msearch" type="search" placeholder="Buscar no cardápio" autocomplete="off"></label>
  <p class="muted small hint">Pizza com 2 sabores: cobrança pelo <span id="halfrule">valor médio</span> dos sabores no tamanho escolhido.</p>
  <div id="menu" aria-live="polite"><p class="muted state">Carregando cardápio…</p></div>
</div>

<div id="cartbar" class="cartbar" hidden><button class="btn" id="open-cart" type="button"><span id="cart-count">0</span> itens · <span id="cart-total">R$ 0,00</span> — Ver sacola</button></div>

<dialog id="prod-dialog" class="sheet" aria-labelledby="pd-title"><form method="dialog" id="pd-form"></form></dialog>
<dialog id="pizza-dialog" class="sheet pz" aria-labelledby="pz-title"></dialog>
<dialog id="cart-dialog" class="sheet" aria-labelledby="cd-title">
  <div class="sheet-in"><div class="sheet-head"><h2 id="cd-title">Sua sacola</h2><button class="x" type="button" data-close aria-label="Fechar">×</button></div>
  <div id="cart-lines"></div>
  <div class="sheet-foot"><p class="row"><span>Subtotal</span><b id="cart-sub">R$ 0,00</b></p><p class="muted small">Taxa de entrega e total final são confirmados no próximo passo.</p>
  <a class="btn block" id="go-checkout" href="/checkout">Continuar para entrega e pagamento</a></div></div>
</dialog>
