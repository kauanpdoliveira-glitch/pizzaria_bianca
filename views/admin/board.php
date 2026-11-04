<div class="board" id="board" data-density="comfortable" data-cols="2">
  <section class="toolbar" aria-label="Busca e filtros">
    <div class="tb-row">
      <label class="search"><svg class="ic" aria-hidden="true"><use href="#i-search"/></svg><span class="sr">Buscar cliente ou pedido</span><input id="q" type="search" placeholder="Buscar cliente ou pedido" autocomplete="off"></label>
      <button class="b" id="new-btn" type="button"><svg class="ic" aria-hidden="true"><use href="#i-plus"/></svg>Novo pedido</button>
      <details class="viewopt" id="viewopt"><summary class="b ghost"><svg class="ic" aria-hidden="true"><use href="#i-view"/></svg>Ajustar visualização</summary>
        <div class="pop"><fieldset><legend>Densidade</legend>
          <label class="opt"><input type="radio" name="density" value="comfortable" checked> Confortável</label><label class="opt"><input type="radio" name="density" value="compact"> Compacta</label></fieldset>
          <fieldset><legend>Colunas (telas largas)</legend>
          <label class="opt"><input type="radio" name="cols" value="2" checked> Duas colunas</label><label class="opt"><input type="radio" name="cols" value="1"> Uma coluna</label></fieldset></div></details>
      <span id="conn" class="conn off" role="status">Conectando…</span>
    </div>
    <div class="fchips" id="filters" role="group" aria-label="Filtrar pedidos por status"></div>
    <p class="hint" id="hint"></p>
  </section>
  <p id="stale" class="banner bad" role="alert" hidden><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><span>Sem conexão com o servidor. Os dados podem estar desatualizados — tentando reconectar.</span> <button class="b ghost sm" id="retry" type="button">Tentar agora</button></p>
  <p id="leave" class="banner info" role="status" hidden><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><span></span><button class="b ghost sm" type="button"></button></p>
  <div class="panes" id="panes">
    <section class="listwrap" aria-label="Lista de pedidos"><div id="list" class="list"></div></section>
    <aside class="detail" id="detail" aria-label="Detalhes do pedido" aria-live="polite"></aside>
  </div>
</div>
<dialog id="new-dlg" class="wide" aria-labelledby="nd-title">
  <form id="nd-form" novalidate>
    <div class="nd-head"><h2 id="nd-title">Novo pedido (balcão ou telefone)</h2><button class="icon-btn" type="button" id="nd-close" aria-label="Fechar"><svg class="ic" aria-hidden="true"><use href="#i-x"/></svg></button></div>
    <div class="nd-grid">
      <section class="nd-col" aria-label="Itens">
        <h3>Itens</h3>
        <div id="nd-picker"></div>
        <div id="nd-items" class="nd-items"><p class="muted">Nenhum item adicionado.</p></div>
      </section>
      <section class="nd-col" aria-label="Cliente e pagamento">
        <h3>Atendimento</h3>
        <div class="seg"><label><input type="radio" name="type" value="pickup" checked><span>Retirada / balcão</span></label><label><input type="radio" name="type" value="delivery"><span>Entrega</span></label></div>
        <label>Nome (opcional — em branco = "Balcão")<input name="name" maxlength="120" autocomplete="off"></label>
        <label>Telefone com DDD (opcional)<input name="phone" type="tel" inputmode="tel" autocomplete="off"></label>
        <div id="nd-addr" hidden>
          <label>Bairro atendido<select name="zone_id"><option value="">Selecione</option><?php foreach ($zones as $z): ?><option value="<?= (int)$z['id'] ?>"><?= e($z['name']) ?> — taxa <?= e(money((int)$z['fee_cents'])) ?></option><?php endforeach; ?></select></label>
          <div class="inline"><label>Rua<input name="street" maxlength="160"></label><label class="xs">Número<input name="number" maxlength="20"></label></div>
          <label>Complemento / referência<input name="complement" maxlength="120"></label>
          <?php if (!$zones): ?><p class="note">Nenhum bairro cadastrado: cadastre em Configurações para usar entrega.</p><?php endif; ?>
        </div>
        <label>Forma de pagamento<select name="payment"><option value="">Selecione</option><?php foreach ($pays as $k => $p): ?><option value="<?= e($k) ?>"><?= e($p['label']) ?></option><?php endforeach; ?></select></label>
        <label id="nd-change" hidden>Troco para (opcional)<input name="change_for" inputmode="decimal"></label>
        <label>Observações do pedido<textarea name="notes" rows="2" maxlength="300"></textarea></label>
        <div class="tots" id="nd-tots" aria-live="polite"></div>
        <p class="muted small">O pagamento fica "pendente" até a equipe conferir. Este cadastro ignora o horário e a pausa da loja.</p>
      </section>
    </div>
    <p id="nd-err" class="banner bad" role="alert" hidden></p>
    <div class="actions"><button class="b ghost" type="button" id="nd-cancel">Cancelar</button><button class="b" type="submit" id="nd-submit">Registrar pedido</button></div>
  </form>
</dialog>
<dialog id="items-dlg" class="wide" aria-labelledby="ei-title">
  <form id="items-form" novalidate>
    <div class="nd-head"><h2 id="ei-title">Editar itens</h2><button class="icon-btn" type="button" id="ei-close" aria-label="Fechar"><svg class="ic" aria-hidden="true"><use href="#i-x"/></svg></button></div>
    <p class="muted small">Itens que já estavam no pedido mantêm o preço da compra. Itens novos usam o cardápio de hoje. Só é possível antes de o pedido ficar pronto e com o pagamento pendente. Bairro e taxa não mudam. Tudo fica no histórico.</p>
    <div class="nd-grid">
      <section class="nd-col" aria-label="Itens do pedido"><h3>Itens do pedido</h3><div id="ei-list" class="nd-items"></div><h3>Itens adicionados</h3><div id="ei-added" class="nd-items"></div></section>
      <section class="nd-col" aria-label="Adicionar item"><h3>Adicionar item</h3><div id="ei-picker"></div><div class="tots" id="ei-tots" aria-live="polite"></div></section>
    </div>
    <p id="ei-err" class="banner bad" role="alert" hidden></p>
    <div class="actions"><button class="b ghost" type="button" id="ei-cancel">Cancelar</button><button class="b" type="submit" id="ei-save">Salvar alterações</button></div>
  </form>
</dialog>
<dialog id="addr-dlg" aria-labelledby="addr-title">
  <form id="addr-form" novalidate>
    <h2 id="addr-title">Endereço do pedido</h2>
    <p class="muted small">Só rua, número, complemento e referência. Bairro, taxa e total não mudam. A alteração fica no histórico; reimprima a comanda depois.</p>
    <label>Rua<input name="street" maxlength="160" required></label>
    <label>Número<input name="number" maxlength="20" required></label>
    <label>Complemento<input name="complement" maxlength="120"></label>
    <label>Ponto de referência<input name="reference" maxlength="160"></label>
    <p id="addr-err" class="banner bad" role="alert" hidden></p>
    <div class="actions"><button class="b ghost" type="button" id="addr-cancel">Cancelar</button><button class="b" type="submit">Salvar endereço</button></div>
  </form>
</dialog>
<dialog id="cancel-dlg"><form method="dialog"><h2>Cancelar pedido</h2><label>Motivo (obrigatório)<input id="cancel-reason" maxlength="300" required></label>
  <div class="actions"><button class="b ghost" value="no">Voltar</button><button class="b danger" id="cancel-ok" value="yes">Cancelar pedido</button></div></form></dialog>
