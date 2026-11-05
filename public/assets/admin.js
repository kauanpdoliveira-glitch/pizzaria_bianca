'use strict';
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const csrf = document.body.dataset.csrf;
  const canPrint = document.body.dataset.canPrint === '1', canCustomers = document.body.dataset.canCustomers === '1';
  const fmt = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const h = (tag, attrs = {}, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) { if (v == null || v === false) continue; if (k === 'class') el.className = v; else if (k.startsWith('on')) el.addEventListener(k.slice(2), v); else el.setAttribute(k, v === true ? '' : v); }
    for (const k of kids.flat()) if (k != null && k !== false) el.append(k.nodeType ? k : document.createTextNode(k));
    return el;
  };
  const icon = (n, cls = 'ic') => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', cls); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false'); const u = document.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + n); s.append(u); return s; };
  const call = async (url, method = 'GET', body, extra = {}) => {
    const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...extra }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
    if (r.status === 401) { location.href = '/equipe'; throw new Error('Sessão expirada'); }
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw Object.assign(new Error(j.error || 'Erro no servidor'), { status: r.status, data: j });
    return j;
  };

  // ---------- menu lateral recolhível (telas estreitas) ----------
  const side = $('#side'), tog = $('#side-toggle'), scrim = $('#scrim');
  const setSide = open => { side.classList.toggle('open', open); scrim.hidden = !open; tog.setAttribute('aria-expanded', open); tog.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu'); };
  tog.addEventListener('click', () => setSide(!side.classList.contains('open')));
  scrim.addEventListener('click', () => setSide(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setSide(false); });

  // confirmações em formulários e ações de impressão nas telas de detalhe/lista de impressão
  document.addEventListener('change', e => { if (e.target.matches('select[data-autosubmit]')) e.target.form.requestSubmit(); });
  const ps = document.getElementById('psearch'); if (ps) ps.addEventListener('input', () => { const t = ps.value.trim().toLowerCase(); document.querySelectorAll('#plist .prow').forEach(r => { r.hidden = !!t && !r.dataset.name.includes(t); }); });
  document.addEventListener('submit', e => { const m = e.target.dataset.confirm; if (m && !confirm(m)) e.preventDefault(); });
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-reprint],[data-retry]'); if (!b) return;
    try {
      if (b.dataset.reprint !== undefined) { const reason = (prompt('REIMPRESSÃO — informe o motivo (obrigatório; fica registrado com seu nome):') || '').trim(); if (reason.length < 3) return; await call(`/admin/api/orders/${b.dataset.order}/reprint`, 'POST', { sector: b.dataset.reprint, reason }); }
      else { if (!confirm('Reenviar este trabalho? Se ele pode ter saído no papel, a comanda sairá duplicada.')) return; await call(`/admin/api/print/${b.dataset.retry}/retry`, 'POST'); }
      location.reload();
    } catch (err) { alert(err.message); }
  });

  if (document.body.dataset.page !== 'admin/board') return;

  // ================= Gestão de pedidos =================
  const FILTERS = [
    ['all', 'Todos ativos', o => !isDone(o)],
    ['new', 'Novos', o => o.status === 'received'],
    ['prep', 'Em preparo', o => ['accepted', 'preparing'].includes(o.status)],
    ['ready', 'Prontos', o => o.status === 'ready' && o.type !== 'delivery'],
    ['delivery', 'Em entrega', o => o.status === 'ready' && o.type === 'delivery'],
    ['done', 'Concluídos e cancelados hoje', isDone],
  ];
  const NEXT = { received: ['accepted', 'Aceitar pedido'], accepted: ['preparing', 'Iniciar preparo'], preparing: ['ready', 'Marcar pronto'], ready: ['completed', 'Concluir pedido'] };
  const JOB = { queued: 'Na fila', printing: 'Imprimindo', printed: 'Impressa (confirmada pelo agente)', failed: 'Falhou', uncertain: 'Resultado incerto' };
  function isDone(o) { return o.status === 'completed' || o.status === 'cancelled'; }

  const S = { orders: [], map: new Map(), sel: null, filter: 'all', q: '', agent: null, store: null, serverNow: 0, polledAt: 0, fails: 0, lastOk: 0, known: null, fresh: new Map(), busy: new Set(), msg: null, loadErr: null, fetched: null, sound: false, pollSeq: 0, loadSeq: 0 };
  const board = $('#board'), listEl = $('#list'), detailEl = $('#detail');
  const parse = s => new Date(s.replace(' ', 'T')).getTime();
  let ctx = null;

  // preferências de visualização (apenas neste navegador)
  try { const p = JSON.parse(localStorage.getItem('bianca_view') || '{}'); if (p.density) board.dataset.density = p.density; if (p.cols) board.dataset.cols = p.cols; } catch { /* ok */ }
  for (const k of ['density', 'cols']) { const r = document.querySelector(`input[name=${k}][value="${board.dataset[k]}"]`); if (r) r.checked = true; }
  document.querySelectorAll('#viewopt input').forEach(i => i.addEventListener('change', () => {
    board.dataset[i.name] = i.value; try { localStorage.setItem('bianca_view', JSON.stringify({ density: board.dataset.density, cols: board.dataset.cols })); } catch { /* ok */ }
  }));

  const statusInfo = o => ({
    received: ['Novo', 'new', 'bell'], accepted: ['Aceito', 'prep', 'check'], preparing: ['Em preparo', 'prep', 'clock'],
    ready: o.type === 'delivery' ? ['Em entrega', 'deliv', 'truck'] : ['Pronto', 'ready', 'check'], completed: ['Concluído', 'done', 'check'], cancelled: ['Cancelado', 'cancel', 'x'],
  }[o.status]);
  const chip = (cls, ic, text) => h('span', { class: 'chip ' + cls }, icon(ic), text);
  const statusChip = o => { const [t, c, i] = statusInfo(o); return chip('st-' + c, i, t); };
  const payChip = o => o.origin === 'salao' ? chip('typ', 'card', 'Conta na comanda') : o.payment_status === 'confirmed' ? chip('pay-ok', 'check', 'Pago') : o.payment_status === 'refunded' ? chip('pay-ref', 'refresh', 'Estornado') : chip('pay-pend', 'clock', 'Pagamento pendente');
  const typeChip = o => o.type === 'dine_in' ? chip('typ', 'store', 'Salão · Mesa ' + o.table_number) : chip('typ', o.type === 'delivery' ? 'truck' : 'store', o.type === 'delivery' ? 'Entrega' : 'Retirada');
  const nowMs = () => S.serverNow + (Date.now() - S.polledAt);
  const since = ts => { const m = Math.max(0, Math.floor((nowMs() - parse(ts)) / 60000)); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min'; };
  const elapsed = o => since(o.created_at); // tempo desde o RECEBIMENTO
  // início da etapa atual = último evento de status que levou ao status atual (ou o recebimento)
  const stageSince = o => { const e = (o.events || []).filter(x => x.kind === 'status' && x.to_value === o.status).pop(); return e ? e.created_at : o.created_at; };
  // previsão de atendimento = recebimento + estimativa definida (não é tempo decorrido)
  const forecast = o => o.estimate_minutes ? parse(o.created_at) + o.estimate_minutes * 60000 : null;
  const hhmm = ms => new Date(ms).toTimeString().slice(0, 5);
  const stale = () => S.fails >= 2 || (S.lastOk && Date.now() - S.lastOk > 15000);

  // ---------- filtros, busca e ordem ----------
  function matches(o) {
    const q = S.q.trim().toLowerCase().replace(/^#/, ''); if (!q) return true;
    return String(o.id) === q || o.customer_name.toLowerCase().includes(q) || o.customer_phone.includes(q.replace(/\D/g, '') || '\u0000');
  }
  // Ordem explícita e estável: ativos do mais antigo ao mais novo (quem espera há mais tempo primeiro); concluídos/cancelados do mais recente ao mais antigo. Desempate por número.
  const order = (a, b, desc) => (parse(a.created_at) - parse(b.created_at) || a.id - b.id) * (desc ? -1 : 1);
  const visible = () => {
    const f = FILTERS.find(x => x[0] === S.filter);
    return S.orders.filter(matches).filter(f[2]).sort((a, b) => order(a, b, S.filter === 'done'));
  };

  function renderFilters() {
    const base = S.orders.filter(matches);
    $('#filters').replaceChildren(...FILTERS.map(([k, label, fn]) => {
      const n = base.filter(fn).length;
      return h('button', { type: 'button', class: 'fchip f-' + k + (S.filter === k ? ' on' : ''), 'aria-pressed': S.filter === k, onclick: () => { S.filter = k; render(); } }, label, h('b', {}, String(n)));
    }));
    const list = visible();
    $('#hint').textContent = S.filter === 'done' ? 'Ordem: mais recentes primeiro. Para pedidos antigos, use Histórico.' : 'Ordem: mais antigos primeiro (quem espera há mais tempo aparece antes).';
    return list;
  }

  function row(o) {
    const sel = S.sel === o.id, fresh = S.fresh.has(o.id);
    return h('button', { type: 'button', class: 'ord' + (sel ? ' sel' : '') + (fresh ? ' fresh' : '') + (o.status === 'cancelled' ? ' cancelled' : ''), 'data-id': o.id, 'aria-current': sel ? 'true' : null, onclick: () => select(o.id) },
      h('span', { class: 'avatar' }, icon('user')),
      h('span', { class: 'ob' },
        h('span', { class: 'l1' }, h('b', { class: 'nm' }, o.customer_name), h('span', { class: 'num' }, '#' + o.id), h('span', { class: 'tm' }, isDone(o) ? o.created_at.slice(11, 16) : elapsed(o))),
        h('span', { class: 'l2' }, h('span', { class: 'tot' }, fmt(+o.total_cents)), typeChip(o)),
        h('span', { class: 'l3' }, statusChip(o), payChip(o))));
  }

  function renderList(list) {
    const fid = document.activeElement && document.activeElement.dataset && document.activeElement.dataset.id, top = listEl.parentElement.scrollTop;
    if (!list.length) {
      const msg = S.q ? 'Nenhum pedido encontrado para essa busca neste filtro.' : S.filter === 'done' ? 'Nenhum pedido concluído ou cancelado hoje.' : 'Nenhum pedido neste filtro.';
      listEl.replaceChildren(h('div', { class: 'empty' }, icon('orders', 'ic big'), h('p', {}, msg), S.q ? h('a', { class: 'b ghost sm', href: '/admin/pedidos?q=' + encodeURIComponent(S.q) }, 'Buscar em todo o histórico') : null));
    } else listEl.replaceChildren(...list.map(row));
    listEl.parentElement.scrollTop = top;
    if (fid) { const b = listEl.querySelector(`[data-id="${fid}"]`); if (b) b.focus({ preventScroll: true }); }
  }

  // ---------- detalhe ----------
  const detailSig = o => JSON.stringify([o, S.busy.size, S.msg, stale(), S.agent && S.agent.state, isDone(o) ? "" : elapsed(o)]);
  let lastDetailSig = '';
  function currentOrder() { return S.sel == null ? null : (S.map.get(S.sel) || S.fetched && S.fetched.id === S.sel && S.fetched || null); }

  function renderDetail(force) {
    board.classList.toggle('show-detail', S.sel != null);
    if (S.sel == null) { lastDetailSig = ''; detailEl.replaceChildren(h('div', { class: 'empty' }, icon('orders', 'ic big'), h('p', {}, 'Selecione um pedido para ver os detalhes.'))); return; }
    const o = currentOrder();
    if (!o) {
      lastDetailSig = '';
      if (S.loadErr && S.loadErr.id === S.sel) { detailEl.replaceChildren(backBar(), h('div', { class: 'empty' }, icon('alert', 'ic big'), h('p', {}, S.loadErr.msg), h('button', { class: 'b ghost sm', type: 'button', onclick: () => loadOne(S.sel) }, 'Tentar novamente'), ' ', h('button', { class: 'b ghost sm', type: 'button', onclick: () => select(null) }, 'Fechar'))); }
      else detailEl.replaceChildren(backBar(), h('div', { class: 'empty' }, h('p', {}, 'Carregando pedido…')));
      return;
    }
    const sig = detailSig(o); if (!force && sig === lastDetailSig) return; lastDetailSig = sig;
    const busyNow = S.busy.size > 0, off = busyNow || stale();
    const next = NEXT[o.status];
    const del = o.type === 'delivery';
    const nextLabel = next && ({ received: 'Aceitar pedido', accepted: 'Iniciar preparo', preparing: del ? 'Encaminhar para entrega' : 'Marcar pronto para retirada', ready: del ? 'Confirmar entrega concluída' : o.type === 'dine_in' ? 'Marcar entregue na mesa' : 'Confirmar retirada' }[o.status]);
    const est = o.status === 'received' ? h('label', { class: 'est' }, 'Estimativa (min)', h('input', { id: 'est', type: 'number', min: 5, max: 600, value: o.estimate_minutes || '' })) : null;
    const jobs = (o.jobs || []).slice().reverse();
    const agentNote = S.agent && S.agent.state !== 'online' ? h('p', { class: 'note' }, icon('alert'), S.agent.label + ': as comandas ficam na fila até o agente conectar.') : null;
    const ev = (o.events || []).slice().reverse();
    detailEl.replaceChildren(
      backBar(),
      h('div', { class: 'dbody' },
        h('h2', { class: 'dtitle' }, `#${o.id} — ${o.customer_name}`),
        h('div', { class: 'chips' }, typeChip(o), statusChip(o), payChip(o)),
        S.msg ? h('div', { class: 'banner bad', role: 'alert' }, icon('alert'), h('span', {}, S.msg), h('button', { class: 'b ghost sm', type: 'button', onclick: () => { S.msg = null; poll(); } }, 'Atualizar pedido')) : null,
        !isDone(o) ? h('p', { class: 'stage' }, icon('clock'), `${statusInfo(o)[0]} há ${since(stageSince(o))}`) : null,
        h('dl', { class: 'meta' },
          h('div', {}, h('dt', {}, 'Recebido'), h('dd', {}, `${o.created_at.slice(8, 10)}/${o.created_at.slice(5, 7)} às ${o.created_at.slice(11, 16)}`, isDone(o) ? '' : ` (há ${since(o.created_at)})`)),
          !isDone(o) && forecast(o) ? h('div', {}, h('dt', {}, 'Previsão de atendimento'), h('dd', { class: forecast(o) < nowMs() ? 'err' : '' }, hhmm(forecast(o)), forecast(o) < nowMs() ? ' (atrasado)' : '')) : null,
          h('div', {}, h('dt', {}, 'Origem'), h('dd', {}, o.origin === 'salao' ? `Salão — mesa ${o.table_number}, comanda ${o.tab_physical || '#' + o.tab_id}, atendimento #${o.visit_id}, garçom ${o.events && o.events[0] ? o.events[0].user_name : '-'}` : o.events && o.events[0] && o.events[0].user_name ? 'Cadastro manual (' + o.events[0].user_name + ')' : 'Site')),
          h('div', {}, h('dt', {}, 'Contato'), h('dd', {}, o.customer_phone ? h('a', { href: 'tel:' + o.customer_phone }, o.customer_phone) : 'sem telefone')),
          o.customer_phone && o.customer_orders ? h('div', {}, h('dt', {}, 'Cliente'), h('dd', {}, canCustomers ? h('a', { href: '/admin/clientes?id=' + o.customer_id }, o.customer_orders + (o.customer_orders === 1 ? ' pedido' : ' pedidos')) : o.customer_orders + (o.customer_orders === 1 ? ' pedido' : ' pedidos'))) : null),
        o.type === 'delivery' ? h('div', { class: 'blk' }, h('h3', {}, 'Entrega'), h('p', {}, `${o.addr_street}, ${o.addr_number}${o.addr_complement ? ' – ' + o.addr_complement : ''}`), h('p', {}, o.zone_name), o.addr_reference ? h('p', { class: 'muted' }, 'Ref.: ' + o.addr_reference) : null,
          ['received', 'accepted', 'preparing'].includes(o.status) ? h('button', { class: 'b ghost sm', type: 'button', disabled: off || null, onclick: () => editAddr(o) }, 'Editar endereço') : null) : null,
        o.cancel_reason ? h('p', { class: 'banner bad' }, icon('x'), 'Cancelado: ' + o.cancel_reason) : null,
        h('div', { class: 'blk' }, h('h3', {}, 'Itens'),
          ['received', 'accepted', 'preparing'].includes(o.status) ? (o.payment_status === 'pending' ? h('button', { class: 'b ghost sm', type: 'button', disabled: off || null, onclick: () => editItems(o) }, 'Editar itens') : h('p', { class: 'muted small' }, 'Itens bloqueados: o pagamento já foi conferido/estornado.')) : null,
          ...o.items.map(i => h('div', { class: 'it' }, h('div', { class: 'row' }, h('span', {}, `${i.qty} × ${i.name}${i.size_name ? ' (' + i.size_name + ')' : ''}`), h('span', {}, fmt(+i.total_cents))),
            ...i.options.map(x => h('small', { class: x.group_name === 'Retirar' ? 'rem' : '' }, (x.group_name === 'Retirar' ? '− ' : '+ ') + x.option_name + (+x.price_cents ? ' · ' + fmt(+x.price_cents) : ''))), i.notes ? h('small', { class: 'obs' }, icon('note'), i.notes) : null))),
        h('div', { class: 'tots' }, h('div', { class: 'row' }, h('span', {}, 'Subtotal'), h('span', {}, fmt(+o.subtotal_cents))),
          +o.discount_cents ? h('div', { class: 'row' }, h('span', {}, 'Desconto'), h('span', {}, '− ' + fmt(+o.discount_cents))) : null,
          h('div', { class: 'row' }, h('span', {}, 'Entrega'), h('span', {}, fmt(+o.delivery_fee_cents))), h('div', { class: 'row total' }, h('span', {}, 'Total'), h('b', {}, fmt(+o.total_cents)))),
        o.notes ? h('div', { class: 'blk' }, h('h3', {}, 'Observação do pedido'), h('p', { class: 'obsbox' }, icon('note'), o.notes)) : null,
        o.origin === 'salao' ? h('div', { class: 'blk' }, h('h3', {}, 'Pagamento'), h('p', {}, 'Pedido de produção do salão: o consumo e o pagamento são tratados na comanda (Caixa), não aqui.'), h('a', { class: 'lnk', href: '/admin/comanda/' + o.tab_id }, 'Abrir a comanda')) : h('div', { class: 'blk' }, h('h3', {}, 'Pagamento'), h('p', {}, o.payment_label, o.change_for_cents ? ` · troco para ${fmt(+o.change_for_cents)}` : ''), h('p', { class: 'muted small' }, 'O pagamento é conferido pela equipe e é independente do preparo.'),
          o.payment_status === 'pending' && o.status !== 'cancelled' ? h('button', { class: 'b ghost sm', type: 'button', disabled: off || null, onclick: () => act(o, 'pay') }, icon('check'), 'Confirmar pagamento') : null),
        canPrint ? h('div', { class: 'blk' }, h('h3', {}, 'Impressão'), agentNote,
          jobs.length ? h('ul', { class: 'jobs' }, jobs.map(j => h('li', { class: 'job j-' + j.status }, h('span', {}, `${j.sector}${j.kind === 'reprint' ? ' (reimpressão)' : ''}: `, h('b', {}, JOB[j.status] || j.status), h('small', { class: 'jm' }, ` · ${j.created_at.slice(11, 16)}${j.created_by ? ' · ' + j.created_by : ''}`)), j.last_error ? h('small', {}, j.last_error) : null,
            ['failed', 'uncertain'].includes(j.status) ? h('button', { class: 'b ghost sm', type: 'button', disabled: off || null, onclick: () => retry(j) }, j.status === 'uncertain' ? 'Reenviar (pode duplicar)' : 'Reenviar') : null)))
            : h('p', { class: 'muted small' }, 'Nenhum trabalho na fila para este pedido.'),
          h('a', { class: 'lnk', target: '_blank', rel: 'noopener', href: `/admin/pedido/${o.id}/comanda?sector=balcao&paper=80` }, 'Abrir comanda no navegador (não confirma impressão física)')) : null,
        h('details', { class: 'blk hist' }, h('summary', {}, 'Histórico de alterações'), h('ol', { class: 'ev' }, ev.map(e => h('li', {}, h('time', {}, e.created_at.slice(5, 16).replace('-', '/')), ' ', evText(e), h('small', {}, (e.user_name || 'cliente/sistema') + (e.note ? ' — ' + e.note : '')))))),
        !isDone(o) ? h('p', {}, h('button', { class: 'b danger-o sm', type: 'button', disabled: off || null, onclick: () => cancel(o) }, 'Cancelar pedido')) : null),
      h('footer', { class: 'dfoot' }, est,
        canPrint ? h('button', { class: 'b ghost', type: 'button', disabled: off || null, onclick: () => act(o, 'print') }, icon('print'), 'Imprimir') : null,
        next ? h('button', { class: 'b', type: 'button', disabled: off || null, onclick: () => act(o, 'next') }, icon('check'), nextLabel) : h('span', { class: 'muted' }, isDone(o) ? 'Pedido finalizado' : '')));
  }
  const evText = e => ({ created: 'Pedido recebido', status: `Status: ${stName(e.from_value)} → ${stName(e.to_value)}`, payment: `Pagamento: ${e.from_value} → ${e.to_value}`, reprint: 'Impressão', address: 'Endereço alterado', items: 'Itens alterados' }[e.kind] || e.kind);
  const stName = s => ({ received: 'Recebido', accepted: 'Aceito', preparing: 'Em preparo', ready: 'Pronto', completed: 'Concluído', cancelled: 'Cancelado' }[s] || s);
  const backBar = () => h('div', { class: 'backbar only-narrow' }, h('button', { class: 'b ghost sm', type: 'button', onclick: () => select(null) }, icon('back'), 'Voltar à lista'));

  // ---------- ações (um clique por vez; o servidor decide) ----------
  async function act(o, kind) {
    const key = kind + o.id; if (S.busy.has(key)) return;
    if (kind === 'print' && !confirm('Enviar este pedido à fila de impressão? A impressão só é confirmada quando o agente informar.')) return;
    if (kind === 'pay' && !confirm('Confirmar que o pagamento deste pedido foi conferido pela equipe?')) return;
    S.busy.add(key); S.msg = null; renderDetail();
    try {
      let r;
      if (kind === 'next') r = await call(`/admin/api/orders/${o.id}/status`, 'POST', { to: NEXT[o.status][0], version: +o.version, estimate: $('#est') && $('#est').value ? +$('#est').value : undefined });
      else if (kind === 'pay') r = await call(`/admin/api/orders/${o.id}/payment`, 'POST', { to: 'confirmed', version: +o.version });
      else { const reason = (prompt('REIMPRESSÃO de todos os destinos — informe o motivo (obrigatório):') || '').trim(); if (reason.length < 3) return; const j = await call(`/admin/api/orders/${o.id}/reprint`, 'POST', { reason }); toast(`${j.jobs.length} trabalho(s) enviado(s) à fila. Aguardando o agente.`); }
      if (r) merge(r.order);
    } catch (e) {
      S.msg = e.status === 409 ? e.message : 'Não foi possível concluir a ação: ' + e.message;
      if (e.data && e.data.order) merge(e.data.order);
    } finally { S.busy.delete(key); await poll(); render(true); }
  }
  async function retry(j) {
    if (!confirm('Reenviar este trabalho? Se ele pode ter saído no papel, a comanda sairá duplicada.')) return;
    try { await call(`/admin/api/print/${j.id}/retry`, 'POST'); await poll(); } catch (e) { S.msg = e.message; render(true); }
  }
  function cancel(o) {
    const d = $('#cancel-dlg'), inp = $('#cancel-reason'); inp.value = ''; d.showModal(); inp.focus();
    d.onclose = async () => {
      if (d.returnValue !== 'yes') return;
      const key = 'cancel' + o.id; if (S.busy.has(key)) return; S.busy.add(key); S.msg = null; renderDetail();
      try { const r = await call(`/admin/api/orders/${o.id}/status`, 'POST', { to: 'cancelled', version: +o.version, reason: inp.value }); merge(r.order); }
      catch (e) { S.msg = e.message; if (e.data && e.data.order) merge(e.data.order); }
      finally { S.busy.delete(key); await poll(); render(true); }
    };
  }
  function merge(order) { const old = S.map.get(order.id); order.jobs = order.jobs || (old && old.jobs) || []; const i = S.orders.findIndex(x => x.id === order.id); if (i >= 0) S.orders[i] = order; else S.orders.push(order); S.map.set(order.id, order); if (S.fetched && S.fetched.id === order.id) S.fetched = order; }

  function toast(m) { let t = $('#atoast'); if (!t) { t = h('div', { id: 'atoast', class: 'atoast', role: 'status' }); document.body.append(t); } t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 4000); }

  // ---------- editar endereço (antes de sair para entrega; gera histórico) ----------
  const ad = $('#addr-dlg'), adf = $('#addr-form'); let adOrder = null, adBusy = false;
  function editAddr(o) {
    adOrder = o; const f = adf.elements; f.street.value = o.addr_street || ''; f.number.value = o.addr_number || ''; f.complement.value = o.addr_complement || ''; f.reference.value = o.addr_reference || '';
    $('#addr-err').hidden = true; $('#addr-title').textContent = `Endereço do pedido #${o.id}`; ad.showModal(); f.street.focus();
  }
  $('#addr-cancel').addEventListener('click', () => ad.close());
  adf.addEventListener('submit', async ev => {
    ev.preventDefault(); if (adBusy) return; const f = adf.elements, err = $('#addr-err'); adBusy = true; err.hidden = true;
    try {
      const cur = S.map.get(adOrder.id) || adOrder;
      const r = await call(`/admin/api/orders/${adOrder.id}/address`, 'POST', { street: f.street.value, number: f.number.value, complement: f.complement.value, reference: f.reference.value, version: +cur.version });
      merge(r.order); ad.close(); toast('Endereço atualizado e registrado no histórico. Reimprima a comanda para refletir a mudança.'); render(true);
    } catch (e) { err.textContent = e.message; err.hidden = false; if (e.data && e.data.order) { merge(e.data.order); adOrder = e.data.order; render(true); } }
    finally { adBusy = false; }
  });

  // ---------- seleção ----------
  function select(id) {
    S.sel = id; S.msg = null; S.loadErr = null;
    history.replaceState(null, '', id == null ? location.pathname : '#' + id);
    render(true);
    if (id != null && !S.map.has(id)) loadOne(id);
    if (id == null) { const b = listEl.querySelector('.ord'); void b; }
  }
  async function loadOne(id) {
    S.loadErr = null; renderDetail(true); const my = ++S.loadSeq;
    try { const r = await call('/admin/api/orders/' + id); if (S.sel === id && my === S.loadSeq) { S.fetched = r.order; } }
    catch (e) { if (S.sel === id && my === S.loadSeq) S.loadErr = { id, msg: e.status === 404 ? 'Pedido indisponível: não foi encontrado.' : 'Não foi possível carregar o pedido (' + e.message + ').' }; }
    renderDetail(true);
  }

  // ---------- renderização geral ----------
  function render(forceDetail) {
    const list = renderFilters(); renderList(list); renderDetail(forceDetail); renderLeave(list);
    const nNew = S.orders.filter(o => o.status === 'received' || S.fresh.has(o.id)).length, bn = $('#bell-n');
    bn.hidden = nNew === 0; bn.textContent = nNew;
    $('#sound').setAttribute('aria-label', (S.sound ? 'Alerta sonoro ativo' : 'Alerta sonoro desativado. Ativar') + (nNew ? `. ${nNew} pedidos novos` : ''));
    board.classList.toggle('stale', stale());
  }

  // Se o pedido aberto deixou de pertencer à lista (mudou de status ou não casa com a busca), explica e oferece o filtro certo; o detalhe continua aberto.
  function renderLeave(list) {
    const el = $('#leave'), o = currentOrder();
    if (!o || list.some(x => x.id === o.id)) { el.hidden = true; return; }
    const fl = FILTERS.find(f => f[0] === S.filter), target = FILTERS.find(f => f[0] !== 'all' && f[2](o));
    const why = !matches(o) ? 'não corresponde à busca atual' : `agora está em «${statusInfo(o)[0]}» e não pertence ao filtro «${fl[1]}»`;
    el.querySelector('span').textContent = `O pedido #${o.id} ${why}. Ele continua aberto nos detalhes.`;
    const b = el.querySelector('button'); b.hidden = !matches(o) || !target; b.textContent = target ? `Mostrar em «${target[1]}»` : ''; b.onclick = () => { S.filter = target[0]; render(); };
    el.hidden = false;
  }

  // ---------- alerta sonoro (o navegador exige um clique inicial; a aba precisa ficar aberta) ----------
  const LS = { get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sem armazenamento: segue sem memória */ } } };
  S.volume = Math.min(1, Math.max(0.05, Number(LS.get('bianca.vol', 0.7)) || 0.7)); S.wantSound = LS.get('bianca.sound', false) === true;
  const audioState = () => !ctx ? (S.wantSound ? 'blocked' : 'off') : (ctx.state === 'running' ? (S.sound ? 'on' : 'off') : 'blocked');
  function bell(vol) {
    if (!ctx || ctx.state !== 'running') return false;
    const v = (vol == null ? S.volume : vol);
    [0, 0.9].forEach(t0 => [[1, 1], [2.76, 0.45], [5.4, 0.2]].forEach(([m, a]) => {
      const o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime + t0;
      o.type = 'sine'; o.frequency.value = 1046.5 * m; g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.35 * a * v, t + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t + 1.5);
      o.connect(g); g.connect(ctx.destination); o.start(t); o.stop(t + 1.6);
    }));
    return true;
  }
  const beep = () => S.sound && bell();
  const bar = h('div', { class: 'soundbar', role: 'group', 'aria-label': 'Som dos pedidos' });
  const sbTxt = h('span', { class: 'sb-txt', 'aria-live': 'polite' });
  const sbOn = h('button', { class: 'b sm', type: 'button' }, 'Ativar som dos pedidos');
  const sbTest = h('button', { class: 'b ghost sm', type: 'button' }, 'Testar som');
  const sbVol = h('input', { type: 'range', min: '5', max: '100', value: String(Math.round(S.volume * 100)), 'aria-label': 'Volume do alerta' });
  bar.append(sbOn, sbTest, h('label', { class: 'sb-vol' }, 'Volume ', sbVol), sbTxt);
  board.parentNode.insertBefore(bar, board);
  function drawSound() {
    const st = audioState();
    sbTxt.textContent = st === 'on' ? 'Som ativado. Mantenha esta aba aberta; com o navegador fechado ou o computador suspenso não há som.' : st === 'blocked' ? 'Som bloqueado pelo navegador: clique em “Ativar som dos pedidos”.' : 'Som desativado.';
    sbOn.textContent = st === 'on' ? 'Desativar som' : 'Ativar som dos pedidos'; sbOn.hidden = false;
    const ic = $('#sound'); ic.setAttribute('aria-pressed', st === 'on'); ic.querySelector('use').setAttribute('href', st === 'on' ? '#i-bell' : '#i-bell-off');
  }
  async function enableSound() {
    ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
    try { await ctx.resume(); } catch (e) { /* fica bloqueado */ }
    S.sound = ctx.state === 'running'; S.wantSound = S.sound; LS.set('bianca.sound', S.sound); if (S.sound) bell(); drawSound(); render();
  }
  function toggleSound() { if (S.sound) { S.sound = false; S.wantSound = false; LS.set('bianca.sound', false); drawSound(); render(); } else enableSound(); }
  sbOn.addEventListener('click', toggleSound); $('#sound').addEventListener('click', toggleSound);
  sbTest.addEventListener('click', async () => { if (!S.sound) await enableSound(); else bell(); });
  sbVol.addEventListener('input', () => { S.volume = sbVol.value / 100; LS.set('bianca.vol', S.volume); });
  sbVol.addEventListener('change', () => bell());
  document.addEventListener('pointerdown', () => { if (S.wantSound && !S.sound) enableSound(); }, { once: false, passive: true }); // 1º clique em qualquer lugar reativa o que o operador já tinha ligado
  drawSound();
  // notificação sem repetir: memória compartilhada entre abas (localStorage) + aviso de pedidos perdidos numa desconexão
  const seenIds = () => new Set(LS.get('bianca.notified', []));
  function claim(ids) { const s = seenIds(); const mine = ids.filter(i => !s.has(i)); if (!mine.length) return []; mine.forEach(i => s.add(i)); LS.set('bianca.notified', [...s].slice(-400)); return mine; }
  function notifyNew(orders, first) {
    const cand = orders.filter(o => o.origin !== 'manual' && (o.status === 'received' || o.status === 'accepted')).map(o => o.id);
    if (first && LS.get('bianca.notified', null) === null) { claim(orders.map(o => o.id)); return; } // primeira vez neste navegador: não toca o histórico
    const mine = claim(cand); if (!mine.length) return;
    mine.forEach(id => { S.fresh.set(id, Date.now()); setTimeout(() => { S.fresh.delete(id); render(); }, 8000); });
    beep();
    S.msg = first && mine.length ? `${mine.length} pedido(s) chegaram enquanto este painel estava fechado ou desconectado.` : S.msg;
    document.title = `(${mine.length}) Novo pedido — Painel Bianca`; setTimeout(() => document.title = 'Pedidos — Painel Bianca', 8000);
  }
  // ---------- busca com debounce ----------
  let qt; $('#q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.q = e.target.value; render(); }, 150); });

  // ---------- novo pedido (balcão/telefone) ----------
  // Preço, disponibilidade e regras são sempre calculados pelo servidor (/api/quote e POST /admin/api/orders); aqui só se escolhem os itens.
  const ND = { menu: null, items: [], key: null, busy: false, quoteSeq: 0, last: null };
  const nd = $('#new-dlg'), ndf = $('#nd-form');
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(16).slice(2)) + 'adm';
  const ndErr = m => { const e = $('#nd-err'); e.textContent = m || ''; e.hidden = !m; };

  // ---------- seletor de produtos (arquivo picker.js, compartilhado com o salão) ----------
  let MENU = null;
  const loadMenu = async () => (MENU = await BiancaPicker.loadMenu());
  const makePicker = (host, onAdd) => { const p = BiancaPicker.make(host, onAdd); return { load() { p.load(); } }; };
  ND.picker = makePicker($('#nd-picker'), item => { ND.items.push(item); renderItems(); requote(); });

  async function openNew() {
    ND.items = []; ND.key = newKey(); ndf.reset(); ndErr(''); syncNd();
    nd.showModal();
    try { await loadMenu(); } catch { ndErr('Não foi possível carregar o cardápio. Feche e tente de novo.'); return; }
    ND.picker.load(); renderItems();
  }
  function renderItems() {
    const box = $('#nd-items'), L = ND.last && ND.last.lines;
    if (!ND.items.length) { box.replaceChildren(h('p', { class: 'muted' }, 'Nenhum item adicionado.')); return; }
    box.replaceChildren(...ND.items.map((it, i) => { const l = L && L[i];
      return h('div', { class: 'nd-it' }, h('span', { class: 'grow' }, l ? `${l.qty} × ${l.name}${l.size_name ? ' (' + l.size_name + ')' : ''}` : `${it.qty} × …`, l && l.options.length ? h('small', {}, '+ ' + l.options.map(o => o.name).join(', ')) : null, it.notes ? h('small', { class: 'obs' }, 'Obs.: ' + it.notes) : null),
        h('b', {}, l ? fmt(l.total_cents) : ''), h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Remover item ' + (i + 1), onclick: () => { ND.items.splice(i, 1); renderItems(); requote(); } }, icon('x'))); }));
  }
  let rq; function requote() { clearTimeout(rq); rq = setTimeout(doQuote, 200); }
  async function doQuote() {
    const seq = ++ND.quoteSeq, tots = $('#nd-tots');
    if (!ND.items.length) { ND.last = null; tots.replaceChildren(); return; }
    try {
      const r = await call('/api/quote', 'POST', { items: ND.items, type: ndf.elements.type.value, zone_id: ndf.elements.zone_id.value || null });
      if (seq !== ND.quoteSeq) return; ND.last = r; ndErr(''); renderItems();
      tots.replaceChildren(...[h('div', { class: 'row' }, h('span', {}, 'Subtotal'), h('span', {}, fmt(r.subtotal_cents))),
        ndf.elements.type.value === 'delivery' ? h('div', { class: 'row' }, h('span', {}, 'Entrega'), h('span', {}, ndf.elements.zone_id.value ? fmt(r.delivery_fee_cents) : 'escolha o bairro')) : null,
        h('div', { class: 'row total' }, h('span', {}, 'Total'), h('b', {}, fmt(r.total_cents)))].filter(Boolean));
    } catch (e) { if (seq === ND.quoteSeq) { ND.last = null; ndErr(e.message); } }
  }
  function syncNd() { const del = ndf.elements.type.value === 'delivery'; $('#nd-addr').hidden = !del; $('#nd-change').hidden = ndf.elements.payment.value !== 'cash'; }
  $('#new-btn').addEventListener('click', openNew);
  $('#nd-close').addEventListener('click', () => nd.close()); $('#nd-cancel').addEventListener('click', () => nd.close());
  ndf.addEventListener('change', e => { if (e.target.closest('#nd-picker')) return; syncNd(); requote(); });
  ndf.addEventListener('submit', async ev => {
    ev.preventDefault(); if (ND.busy) return; ndErr('');
    if (!ND.items.length) return ndErr('Adicione ao menos um item.');
    const f = ndf.elements; if (!f.payment.value) return ndErr('Escolha a forma de pagamento.');
    if (f.type.value === 'delivery' && (!f.zone_id.value || !f.street.value.trim() || !f.number.value.trim())) return ndErr('Para entrega, informe bairro, rua e número.');
    ND.busy = true; const btn = $('#nd-submit'); btn.disabled = true; btn.textContent = 'Registrando…';
    try {
      const r = await call('/admin/api/orders', 'POST', { type: f.type.value, name: f.name.value.trim(), phone: f.phone.value.trim(), zone_id: f.zone_id.value || null, street: f.street.value.trim(), number: f.number.value.trim(),
        complement: f.complement.value.trim(), payment: f.payment.value, change_for: f.change_for.value.trim(), notes: f.notes.value.trim(), items: ND.items }, { 'Idempotency-Key': ND.key });
      nd.close(); await poll(); select(r.id); toast(`Pedido #${r.id} registrado e enviado à fila de impressão.`);
    } catch (e) { ndErr(e.status ? e.message : 'Sem conexão. Nada foi perdido: toque em "Registrar pedido" de novo — não haverá duplicidade.'); }
    finally { ND.busy = false; btn.disabled = false; btn.textContent = 'Registrar pedido'; }
  });

  // ---------- editar itens (antes de pronto, com pagamento pendente; o servidor recalcula e registra no histórico) ----------
  const ei = $('#items-dlg'), eif = $('#items-form'), EI = { o: null, rows: [], added: [], quoted: null, busy: false, seq: 0 };
  const eiErr = m => { const e = $('#ei-err'); e.textContent = m || ''; e.hidden = !m; };
  EI.picker = makePicker($('#ei-picker'), item => { EI.added.push(item); drawEI(); eiQuote(); });
  function eiRows(o) { EI.rows = o.items.map(i => ({ id: i.id, name: i.name, size: i.size_name, unit: +i.unit_price_cents, qty: +i.qty, qty0: +i.qty, notes: i.notes || '', notes0: i.notes || '', removed: false, opts: i.options.map(x => x.option_name) })); EI.added = []; EI.quoted = null; }
  async function editItems(o) {
    EI.o = o; eiRows(o); eiErr(''); $('#ei-title').textContent = `Editar itens do pedido #${o.id}`; ei.showModal();
    try { await loadMenu(); EI.picker.load(); } catch { eiErr('Não foi possível carregar o cardápio para adicionar itens.'); }
    drawEI();
  }
  function drawEI() {
    const o = EI.o, list = $('#ei-list');
    list.replaceChildren(...EI.rows.map(r => h('div', { class: 'nd-it' + (r.removed ? ' gone' : '') },
      h('div', { class: 'grow' }, h('b', {}, r.name + (r.size ? ` (${r.size})` : '')), r.opts.length ? h('small', {}, '+ ' + r.opts.join(', ')) : null, h('small', {}, `${fmt(r.unit)} cada · preço da compra`),
        h('input', { value: r.notes, maxlength: 200, placeholder: 'Observação do item', 'aria-label': 'Observação de ' + r.name, disabled: r.removed || null, oninput: e => { r.notes = e.target.value; } })),
      h('span', { class: 'qty' }, h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Diminuir ' + r.name, disabled: r.removed || r.qty <= 1 || null, onclick: () => { r.qty--; drawEI(); } }, '−'), h('output', {}, String(r.qty)),
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Aumentar ' + r.name, disabled: r.removed || r.qty >= 50 || null, onclick: () => { r.qty++; drawEI(); } }, '+')),
      h('b', { class: 'ln' }, fmt(r.unit * r.qty)),
      h('button', { type: 'button', class: 'b ghost sm', onclick: () => { r.removed = !r.removed; drawEI(); } }, r.removed ? 'Desfazer' : 'Remover'))));
    const L = EI.quoted && EI.quoted.lines;
    $('#ei-added').replaceChildren(...EI.added.map((it, i) => { const l = L && L[i]; return h('div', { class: 'nd-it' }, h('span', { class: 'grow' }, h('b', {}, '+ ' + (l ? `${l.qty} × ${l.name}${l.size_name ? ' (' + l.size_name + ')' : ''}` : 'novo item')), l && l.options.length ? h('small', {}, l.options.map(x => x.name).join(', ')) : null, h('small', {}, 'preço do cardápio de hoje')), h('b', { class: 'ln' }, l ? fmt(l.total_cents) : ''),
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Remover item adicionado', onclick: () => { EI.added.splice(i, 1); EI.quoted = null; drawEI(); eiQuote(); } }, icon('x'))); }));
    const sub = EI.rows.filter(r => !r.removed).reduce((a, r) => a + r.unit * r.qty, 0) + (L ? L.reduce((a, l) => a + l.total_cents, 0) : 0);
    const total = sub + +o.delivery_fee_cents - +o.discount_cents;
    $('#ei-tots').replaceChildren(h('div', { class: 'row' }, h('span', {}, 'Total atual'), h('span', {}, fmt(+o.total_cents))), h('div', { class: 'row total' }, h('span', {}, 'Novo total (o servidor confirma ao salvar)'), h('b', {}, fmt(total))));
  }
  let eq_; function eiQuote() { clearTimeout(eq_); eq_ = setTimeout(async () => {
    const seq = ++EI.seq; if (!EI.added.length) { EI.quoted = null; drawEI(); return; }
    try { const r = await call('/api/quote', 'POST', { items: EI.added }); if (seq === EI.seq) { EI.quoted = r; eiErr(''); drawEI(); } } catch (e) { if (seq === EI.seq) eiErr(e.message); }
  }, 200); }
  $('#ei-close').addEventListener('click', () => ei.close()); $('#ei-cancel').addEventListener('click', () => ei.close());
  eif.addEventListener('submit', async ev => {
    ev.preventDefault(); if (EI.busy) return; eiErr('');
    const ops = [];
    for (const r of EI.rows) { if (r.removed) ops.push({ op: 'remove', item_id: r.id }); else { if (r.qty !== r.qty0) ops.push({ op: 'qty', item_id: r.id, qty: r.qty }); if (r.notes.trim() !== r.notes0) ops.push({ op: 'notes', item_id: r.id, notes: r.notes }); } }
    EI.added.forEach(item => ops.push({ op: 'add', item }));
    if (!ops.length) return eiErr('Nenhuma alteração para salvar.');
    if (EI.rows.every(r => r.removed) && !EI.added.length) return eiErr('O pedido precisa ter ao menos um item. Para desistir, use "Cancelar pedido".');
    EI.busy = true; const btn = $('#ei-save'); btn.disabled = true; btn.textContent = 'Salvando…';
    try {
      const cur = S.map.get(EI.o.id) || EI.o;
      const r = await call(`/admin/api/orders/${EI.o.id}/items`, 'POST', { version: +cur.version, ops });
      merge(r.order); ei.close(); toast('Itens atualizados e registrados no histórico. Reimprima a comanda para refletir a mudança.'); render(true);
    } catch (e) {
      eiErr(e.message + (e.status === 409 ? ' Os itens abaixo foram recarregados — revise e refaça.' : ''));
      if (e.data && e.data.order) { merge(e.data.order); EI.o = e.data.order; eiRows(e.data.order); drawEI(); render(true); }
    } finally { EI.busy = false; btn.disabled = false; btn.textContent = 'Salvar alterações'; }
  });

  // ---------- polling ----------
  async function poll() {
    const my = ++S.pollSeq;
    try {
      const d = await call('/admin/api/orders');
      if (my !== S.pollSeq) return; // chegou uma resposta mais nova: descarta esta
      S.fails = 0; S.lastOk = Date.now(); S.serverNow = parse(d.now); S.polledAt = Date.now(); S.store = d.store; S.agent = d.agent;
      const ids = new Set(d.orders.map(o => o.id));
      notifyNew(d.orders, !S.known);
      S.known = ids; S.orders = d.orders; S.map = new Map(d.orders.map(o => [o.id, o]));
      if (S.fetched && S.map.has(S.fetched.id)) S.fetched = null;
      const c = $('#conn'); c.textContent = 'Atualizado às ' + d.now.slice(11, 16); c.className = 'conn on'; $('#stale').hidden = true;
      const sp = $('#store-pill'); sp.className = 'store-pill ' + (d.store.open ? 'ok' : 'bad'); $('#store-text').textContent = d.store.open ? 'Loja aberta' : (d.store.reason || 'Loja fechada');
      const ai = $('#agent-ic'); ai.className = 'icon-btn ' + ({ online: 'ok', offline: 'bad', never: 'warn' }[d.agent.state]);
      const lbl = d.agent.label + (d.agent.pending ? ` · ${d.agent.pending} na fila` : '') + (d.agent.problems ? ` · ${d.agent.problems} com problema` : ''); ai.title = lbl; ai.setAttribute('aria-label', lbl); $('#agent-txt').textContent = lbl;
    } catch (e) {
      if (my !== S.pollSeq) return;
      S.fails++;
      if (stale()) { const c = $('#conn'); c.textContent = 'Sem conexão'; c.className = 'conn off'; $('#stale').hidden = false; }
    }
    render();
  }
  $('#retry').addEventListener('click', poll);
  window.addEventListener('hashchange', () => { const id = parseInt(location.hash.slice(1), 10); if (id && id !== S.sel) select(id); });

  const initial = parseInt(location.hash.slice(1), 10); if (initial) S.sel = initial;
  render(true); poll().then(() => { if (S.sel != null && !S.map.has(S.sel)) loadOne(S.sel); });
  setInterval(poll, 5000); setInterval(() => render(), 30000); // 30 s: só atualiza "há X min"
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
