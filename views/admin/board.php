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
<dialog id="new-dlg" class="no-dlg" aria-label="Novo pedido" data-zones="<?= e(json_encode(array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['name'], 'fee_cents' => (int)$z['fee_cents']], $zones), JSON_UNESCAPED_UNICODE)) ?>" data-pays="<?= e(json_encode(array_map(fn($p) => ['label' => $p['label']], array_filter($pays, fn($p) => !empty($p['on']))), JSON_UNESCAPED_UNICODE)) ?>"></dialog>
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
