'use strict';
(() => {
  const { h, fmt } = BiancaPicker;
  const $ = (s, r = document) => r.querySelector(s);
  const csrf = document.body.dataset.csrf;
  const icon = (n, cls = 'ic') => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', cls); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false'); const u = document.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + n); s.append(u); return s; };
  const call = async (url, method = 'GET', body, extra = {}) => {
    const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...extra }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
    if (r.status === 401) { location.href = '/equipe'; throw new Error('Sessão expirada'); }
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw Object.assign(new Error(j.error || 'Erro no servidor'), { status: r.status, data: j });
    return j;
  };
  const toast = m => { const t = $('#gtoast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 4000); };
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(16).slice(2)) + 'grc';
  const pad = n => String(n).padStart(2, '0');
  const parseMoney = s => { s = String(s).replace(/[^\d,.-]/g, ''); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); return Math.round(parseFloat(s) * 100) || 0; };

  const S = { data: null, fails: 0, tab: 'tables', q: '', detail: null, draft: null, update: null, busy: false };
  const app = $('#app'), stale = () => S.fails >= 2;
  const put = (...n) => app.replaceChildren(...n.flat().filter(x => x != null && x !== false)); // ignora null/false (condicionais)
  const route = () => { const m = location.hash.match(/^#\/([a-z]+)(?:\/(\d+))?/); return m ? [m[1], +m[2] || 0] : ['list', 0]; };
  const go = p => { location.hash = p; };
  const tableById = id => S.data && S.data.tables.find(t => t.id === id);

  const STATE = { free: ['Livre', 's-free', 'check'], busy: ['Em atendimento', 's-busy', 'user'], awaiting: ['Aguardando caixa', 's-wait', 'clock'] };
  const stChip = t => { const [txt, cls, ic] = STATE[t.state]; return h('span', { class: 'chip ' + cls }, icon(ic), txt + (t.state === 'busy' && t.pending_count ? ` · ${t.pending_count} fechamento${t.pending_count > 1 ? 's' : ''} pendente${t.pending_count > 1 ? 's' : ''}` : '')); };
  const tabChip = t => t.status === 'open' ? h('span', { class: 'chip s-busy' }, icon('user'), 'Em atendimento') : h('span', { class: 'chip s-wait' }, icon('clock'), 'Aguardando caixa');
  const ORD = { received: 'Enviado à cozinha', accepted: 'Aceito', preparing: 'Em preparo', ready: 'Pronto para servir', completed: 'Entregue', cancelled: 'Cancelado' };

  // ---------- lista: Mesas e Comandas ----------
  function listScreen() {
    const q = h('input', { type: 'search', placeholder: 'Buscar mesa, cliente ou comanda', 'aria-label': 'Buscar mesa, cliente ou comanda', value: S.q, autocomplete: 'off' });
    const counts = h('div', { class: 'gcount', 'aria-label': 'Resumo das mesas' }), listEl = h('div', { class: 'grow-list' });
    const tabs = h('div', { class: 'gtabs', role: 'tablist' }, ['tables', 'tabs'].map(k => h('button', { type: 'button', role: 'tab', 'aria-selected': S.tab === k, onclick: () => { S.tab = k; listScreen(); } }, icon(k === 'tables' ? 'store' : 'orders'), k === 'tables' ? 'Mesas' : 'Comandas')));
    put(h('h1', {}, 'Mesas e comandas'), h('label', { class: 'gsearch' }, icon('search'), h('span', { class: 'sr' }, 'Buscar'), q), tabs, counts, listEl);
    let t; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { S.q = q.value; S.update(); }, 120); });
    S.update = () => {
      const d = S.data; if (!d) return;
      const free = d.tables.filter(t => t.state === 'free').length, busy = d.tables.filter(t => t.state === 'busy').length, wait = d.tables.filter(t => t.state === 'awaiting').length, pend = d.tables.reduce((a, t) => a + t.pending_count, 0);
      counts.replaceChildren(h('div', {}, h('span', { class: 'dotl dot-free' }), 'Livre', h('b', {}, String(free))), h('div', {}, h('span', { class: 'dotl dot-busy' }), 'Em atendimento', h('b', {}, String(busy))), h('div', {}, h('span', { class: 'dotl dot-wait' }), 'Aguardando caixa', h('b', {}, String(wait)),
        pend - wait > 0 ? h('small', { class: 'muted' }, `+${pend - wait} pendente(s) em mesas ocupadas`) : null));
      const needle = S.q.trim().toLowerCase();
      if (S.tab === 'tables') {
        const rows = d.tables.filter(t => !needle || String(t.number).includes(needle.replace(/^mesa\s*/, '')) || t.visits.some(v => v.name.toLowerCase().includes(needle) || v.tabs.some(x => (x.physical_number || '').toLowerCase().includes(needle) || x.name.toLowerCase().includes(needle))));
        listEl.replaceChildren(...(rows.length ? rows.map(t => {
          const act = t.visits.filter(v => v.status === 'active'), pen = t.visits.filter(v => v.status === 'awaiting_cashier');
          const shown = act.length ? act : pen;
          const name = t.state === 'free' ? 'Sem atendimento' : shown[0].name + (shown.length > 1 ? ` +${shown.length - 1}` : '');
          const sub = t.state === 'free' ? '—' : act.length ? `${t.people} ${t.people === 1 ? 'pessoa' : 'pessoas'}` : 'fechamento pendente';
          return h('button', { type: 'button', class: 'trow ' + t.state, onclick: () => go('#/mesa/' + t.id) }, h('span', { class: 'tnum' }, 'Mesa', h('b', {}, pad(t.number))),
            h('span', { class: 'tmid' }, h('span', { class: 'nm' }, name), h('span', { class: 'sub' }, sub), h('span', { class: 'chipline' }, stChip(t))), h('span', { class: 'tend' }, icon('back', 'ic flip')));
        }) : [h('p', { class: 'muted' }, d.tables.length ? 'Nenhuma mesa encontrada.' : 'Nenhuma mesa cadastrada. Peça ao proprietário para definir a quantidade de mesas.')]));
      } else {
        const rows = d.tabs.filter(t => !needle || t.name.toLowerCase().includes(needle) || (t.physical_number || '').toLowerCase().includes(needle) || String(t.table_number) === needle.replace(/^mesa\s*/, ''));
        listEl.replaceChildren(...(rows.length ? rows.map(t => h('button', { type: 'button', class: 'trow ' + (t.status === 'open' ? 'busy' : 'awaiting'), onclick: () => go('#/comanda/' + t.id) },
          h('span', { class: 'tnum' }, 'Comanda', h('b', {}, t.physical_number || '—')), h('span', { class: 'tmid' }, h('span', { class: 'nm' }, t.name), h('span', { class: 'sub' }, `Mesa ${pad(t.table_number)} · ${t.people} ${t.people === 1 ? 'pessoa' : 'pessoas'} · ${fmt(t.total)}`), h('span', { class: 'chipline' }, tabChip(t))),
          h('span', { class: 'tend' }, icon('back', 'ic flip')))) : [h('p', { class: 'muted' }, 'Nenhuma comanda aberta.')]));
      }
    };
    S.update();
  }

  // ---------- mesa ----------
  function tableScreen(id) {
    const t = tableById(id); S.update = null;
    if (!t) { put(backBtn('#/'), h('p', { class: 'muted' }, 'Mesa não encontrada.')); return; }
    const act = t.visits.filter(v => v.status === 'active'), pen = t.visits.filter(v => v.status === 'awaiting_cashier');
    const tabRow = x => h('button', { type: 'button', class: 'trow ' + (x.status === 'open' ? 'busy' : 'awaiting'), onclick: () => go('#/comanda/' + x.id) }, h('span', { class: 'tnum' }, 'Comanda', h('b', {}, x.physical_number || '—')),
      h('span', { class: 'tmid' }, h('span', { class: 'nm' }, x.name), h('span', { class: 'sub' }, `${x.people} ${x.people === 1 ? 'pessoa' : 'pessoas'} · ${fmt(x.total)}`), h('span', { class: 'chipline' }, tabChip(x))), h('span', { class: 'tend' }, icon('back', 'ic flip')));
    put(backBtn('#/'), h('h1', {}, 'Mesa ' + pad(t.number)), h('p', {}, stChip(t)),
      ...act.map(v => h('section', { class: 'gcard' }, h('h2', {}, `Atendimento #${v.id} · ${v.name}`), h('p', { class: 'muted small' }, `${v.people} ${v.people === 1 ? 'pessoa' : 'pessoas'} · aberto às ${v.opened_at.slice(11, 16)}`), h('div', { class: 'grow-list' }, v.tabs.map(tabRow)))),
      ...pen.map(v => h('section', { class: 'gcard warn' }, h('h2', {}, icon('clock'), ` Fechamento pendente · ${v.name}`), h('p', { class: 'muted small' }, 'Atendimento anterior aguardando conferência e fechamento pelo caixa. Fica intacto e separado de qualquer nova comanda.'), h('div', { class: 'grow-list' }, v.tabs.map(tabRow)))),
      !act.length && !pen.length ? h('p', { class: 'muted' }, 'Mesa livre, sem atendimento.') : null,
      h('div', { class: 'gbtns' }, !act.length ? h('button', { class: 'b gbtn', type: 'button', onclick: () => go('#/abrir/' + id) }, 'Abrir mesa') : null, h('button', { class: (act.length ? 'b' : 'b ghost') + ' gbtn', type: 'button', onclick: () => go('#/nova/' + id) }, icon('plus'), 'Nova comanda')));
  }
  const backBtn = to => h('button', { class: 'gback', type: 'button', onclick: () => go(to) }, icon('back'), 'Voltar');

  // ---------- abrir mesa / nova comanda ----------
  function openScreen(tableId, nova) {
    S.update = null;
    const st = S.openForm && S.openForm.nova === nova && S.openForm.tableId === tableId ? S.openForm : (S.openForm = { nova, tableId, key: newKey(), people: 2 });
    const tables = S.data.tables;
    const msel = nova ? h('select', { id: 'f-table' }, h('option', { value: '' }, 'Selecione a mesa'), tables.map(t => h('option', { value: t.id, selected: t.id === (st.tableId) || null }, 'Mesa ' + pad(t.number)))) : null;
    const name = h('input', { id: 'f-name', maxlength: 120, autocomplete: 'off', value: st.name || '' }), phys = h('input', { id: 'f-phys', maxlength: 20, autocomplete: 'off', value: st.phys || '', inputmode: 'text', placeholder: nova ? 'Ex.: 027' : 'Opcional' });
    const out = h('output', {}, String(st.people));
    const info = h('div', {}), err = h('p', { class: 'banner bad', role: 'alert', hidden: true }), btn = h('button', { class: 'b gbtn', type: 'submit' }, nova ? 'Abrir comanda' : 'Abrir mesa');
    const cur = () => tableById(+(msel ? msel.value : tableId) || 0);
    const drawInfo = () => {
      const t = cur(); if (!t) { info.replaceChildren(); return; }
      const act = t.visits.filter(v => v.status === 'active'), pen = t.visits.filter(v => v.status === 'awaiting_cashier'); const kids = [];
      if (pen.length) kids.push(h('div', { class: 'gcard warn' }, h('strong', {}, icon('alert'), ` Mesa aguardando fechamento pelo caixa (${pen.map(v => v.name).join(', ')})`), h('p', { class: 'muted small' }, 'Nova comanda separada do atendimento anterior. Consumos e pagamentos anteriores permanecem intactos.')));
      if (act.length) {
        kids.push(h('div', { class: 'gcard info' }, h('strong', {}, 'Esta mesa já está em atendimento. Escolha uma opção:'),
          ...act.map(v => pickOpt('add:' + v.id, `Adicionar uma comanda ao grupo atual de ${v.name} (atendimento #${v.id})`)),
          S.data.independent ? pickOpt('new', 'Abrir um atendimento independente (outro grupo na mesma mesa)') : h('p', { class: 'muted small' }, 'Atendimento independente desativado pelo proprietário.')));
      }
      info.replaceChildren(...kids);
    };
    const pickOpt = (val, text) => h('label', { class: 'pick' + (st.mode === val ? ' on' : '') }, h('input', { type: 'radio', name: 'mode', value: val, checked: st.mode === val || null, onchange: () => { st.mode = val; drawInfo(); } }), h('span', {}, text));
    if (msel) msel.addEventListener('change', () => { st.tableId = +msel.value || 0; st.mode = null; drawInfo(); });
    const form = h('form', { class: 'gform', novalidate: true },
      msel ? h('label', {}, 'Mesa', msel) : null,
      nova ? h('label', {}, 'Número da comanda física', phys) : null,
      h('label', {}, 'Nome do cliente', name),
      h('label', {}, 'Pessoas', h('div', { class: 'step' }, h('button', { type: 'button', 'aria-label': 'Menos uma pessoa', onclick: () => { st.people = Math.max(1, st.people - 1); out.textContent = st.people; } }, '−'), out,
        h('button', { type: 'button', 'aria-label': 'Mais uma pessoa', onclick: () => { st.people = Math.min(50, st.people + 1); out.textContent = st.people; } }, '+'))),
      !nova ? h('label', {}, 'Comanda física (opcional)', phys) : null, info, err, btn,
      h('p', { class: 'gnote' }, icon('alert'), 'Fechamento de mesa pelo caixa ou gerente.'));
    put(backBtn(tableId ? '#/mesa/' + tableId : '#/'), h('h1', {}, nova ? 'Nova comanda' : 'Abrir mesa ' + pad(tableById(tableId).number)), form);
    drawInfo();
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); if (S.busy) return; err.hidden = true; st.name = name.value; st.phys = phys.value;
      const t = cur(); if (!t) return fail('Selecione a mesa.'); if (!name.value.trim()) return fail('Informe o nome do cliente.'); if (nova && !phys.value.trim()) return fail('Informe o número da comanda física.');
      const hasActive = t.visits.some(v => v.status === 'active'); if (hasActive && !st.mode) return fail('Escolha entre adicionar ao grupo atual ou abrir atendimento independente.');
      if (stale()) return fail('Sem conexão: nada foi enviado.');
      const body = { table_id: t.id, name: name.value.trim(), people: st.people, physical_number: phys.value.trim() };
      if (hasActive) { if (st.mode === 'new') body.mode = 'new_visit'; else { body.mode = 'add_to_visit'; body.visit_id = +st.mode.split(':')[1]; } }
      S.busy = true; btn.disabled = true; btn.textContent = 'Abrindo…';
      try { const r = await call('/garcom/api/open', 'POST', body, { 'Idempotency-Key': st.key }); S.openForm = null; await refresh(); toast(nova ? 'Comanda aberta.' : 'Mesa aberta.'); go('#/comanda/' + r.tab_id); }
      catch (e) { fail(e.status ? e.message : 'Sem conexão. Nada foi confirmado — toque novamente para reenviar (sem duplicar).'); }
      finally { S.busy = false; btn.disabled = false; btn.textContent = nova ? 'Abrir comanda' : 'Abrir mesa'; }
    });
    function fail(m) { err.textContent = m; err.hidden = false; err.scrollIntoView({ block: 'center' }); }
  }

  // ---------- comanda ----------
  async function tabScreen(id) {
    S.update = null;
    if (!S.detail || S.detail.id !== id) { put(backBtn('#/'), h('p', { class: 'muted' }, 'Carregando comanda…')); }
    try { S.detail = (await call('/garcom/api/tab/' + id)).tab; } catch (e) { put(backBtn('#/'), h('p', { class: 'banner bad' }, e.message)); return; }
    drawTab();
  }
  function drawTab() {
    const t = S.detail, tt = t.totals, canR = document.body.dataset.canReport === '1' && t.status !== 'closed', open = t.status === 'open', off = stale();
    const kv = (a, b, cls = '') => h('div', { class: 'kv ' + cls }, h('span', {}, a), h('span', {}, b));
    put(backBtn('#/mesa/' + t.table_id), h('h1', {}, `Comanda ${t.physical_number || '(sem nº físico)'}`),
      h('p', {}, tabChip(t.status === 'closed' ? { status: 'closed' } : t), ` Mesa ${pad(t.table_number)} · ${t.name} · ${t.people} ${t.people === 1 ? 'pessoa' : 'pessoas'} · atendimento #${t.visit_id}`),
      t.status === 'closed' ? h('p', { class: 'banner info' }, 'Comanda fechada pelo caixa.') : null,
      h('section', { class: 'gcard' }, h('h2', {}, 'Conta'), kv('Consumo', fmt(tt.items)), tt.fee ? kv('Taxa de serviço', fmt(tt.fee)) : null, tt.discount ? kv('Desconto', '− ' + fmt(tt.discount)) : null, kv('Total', fmt(tt.total), 'total'),
        kv('Recebimento confirmado', fmt(tt.confirmed)), tt.reported ? kv('Informado (aguarda conferência)', fmt(tt.reported)) : null, kv('Saldo pendente', fmt(tt.pending))),
      h('section', { class: 'gcard' }, h('h2', {}, 'Pedidos enviados'), ...(t.rounds.length ? t.rounds.map(r => h('div', { class: 'rnd' }, h('div', { class: 'kv' }, h('b', {}, `Rodada #${r.id} · ${r.created_at.slice(11, 16)}`), h('span', { class: 'chip ' + (r.status === 'ready' || r.status === 'completed' ? 's-free' : r.status === 'cancelled' ? 'st-cancel' : 's-wait') }, ORD[r.status] || r.status)),
        ...r.items.flatMap(i => [h('div', { class: 'it' }, h('span', {}, `${i.qty} × ${i.name}${i.size_name ? ' (' + i.size_name + ')' : ''}`), h('span', {}, fmt(i.total_cents))), ...(i.options || []).map(o => h('small', {}, (o.group_name === 'Retirar' ? '− ' : '+ ') + o.option_name)), i.notes ? h('small', {}, 'Obs.: ' + i.notes) : null]))) : [h('p', { class: 'muted' }, 'Nenhum item lançado ainda.')])),
      t.payments.length ? h('section', { class: 'gcard' }, h('h2', {}, 'Recebimentos'), ...t.payments.map(p => h('div', { class: 'pyl' }, h('span', {}, `${fmt(p.amount_cents)} · ${p.method}`), h('span', { class: 'muted' }, ({ reported: 'informado', confirmed: 'confirmado', rejected: 'recusado', reversed: 'estorno registrado' })[p.status])))) : null,
      open ? h('div', { class: 'gbtns' }, h('button', { class: 'b gbtn', type: 'button', disabled: off || null, onclick: () => go('#/lancar/' + t.id) }, icon('plus'), 'Lançar itens'), h('button', { class: 'b ghost gbtn', type: 'button', disabled: off || null, onclick: () => billAction(t) }, 'Solicitar conta')) : null,
      !t.physical_number && t.status !== 'closed' ? h('button', { class: 'b ghost gbtn', type: 'button', disabled: off || null, onclick: () => linkAction(t) }, 'Vincular comanda física') : null,
      canR ? h('button', { class: 'b ghost gbtn', type: 'button', disabled: off || null, onclick: () => go('#/receber/' + t.id) }, 'Informar recebimento ao caixa') : null,
      t.status === 'awaiting_cashier' ? h('p', { class: 'gnote' }, icon('clock'), 'Conta solicitada. A conferência e o fechamento são feitos pelo caixa ou gerente.') : null);
  }
  async function billAction(t) {
    if (!confirm('Solicitar a conta ao caixa? Depois disso não será possível lançar novos itens nesta comanda.')) return;
    try { await call(`/garcom/api/tab/${t.id}/bill`, 'POST'); toast('Conta solicitada ao caixa.'); await refresh(); tabScreen(t.id); } catch (e) { toast(e.message); }
  }
  async function linkAction(t) {
    const n = prompt('Número da comanda física:'); if (!n) return;
    try { await call(`/garcom/api/tab/${t.id}/link`, 'POST', { number: n }); toast('Comanda vinculada.'); tabScreen(t.id); } catch (e) { toast(e.message); }
  }

  // ---------- lançar itens (rodada) ----------
  async function roundScreen(id) {
    S.update = null;
    if (!S.draft || S.draft.tab !== id) S.draft = { tab: id, items: [], key: newKey(), notes: '', quote: null, review: false };
    const D = S.draft, host = h('div'), listEl = h('div', { class: 'draft' }), err = h('p', { class: 'banner bad', role: 'alert', hidden: true });
    const notes = h('input', { maxlength: 300, placeholder: 'Observação para a cozinha (opcional)', 'aria-label': 'Observação do pedido', value: D.notes, oninput: e => { D.notes = e.target.value; } });
    const send = h('button', { class: 'b gbtn', type: 'button', onclick: submit }, 'Enviar para a cozinha/bar');
    const reviewBtn = h('button', { class: 'b ghost gbtn', type: 'button', onclick: quote }, 'Revisar pedido');
    put(backBtn('#/comanda/' + id), h('h1', {}, 'Lançar itens'), host, listEl, h('label', { class: 'gform' }, notes), err, reviewBtn, send);
    send.hidden = !D.review;
    try { await BiancaPicker.loadMenu(); } catch { err.textContent = 'Não foi possível carregar o cardápio.'; err.hidden = false; return; }
    const picker = BiancaPicker.make(host, item => { D.items.push(item); D.quote = null; D.review = false; send.hidden = true; draw(); }, 'Adicionar à rodada'); picker.load();
    function draw() {
      const L = D.quote && D.quote.lines;
      listEl.replaceChildren(h('strong', {}, `Itens desta rodada (${D.items.length})`), ...(D.items.length ? D.items.map((it, i) => { const l = L && L[i]; return h('div', { class: 'nd-it' }, h('span', { class: 'grow' }, l ? `${l.qty} × ${l.name}${l.size_name ? ' (' + l.size_name + ')' : ''}` : `Item ${i + 1} · qtd ${it.qty}`, l && l.options.length ? h('small', {}, '+ ' + l.options.map(o => o.name).join(', ')) : null, it.notes ? h('small', {}, 'Obs.: ' + it.notes) : null),
        h('b', {}, l ? fmt(l.total_cents) : ''), h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Remover item ' + (i + 1), onclick: () => { D.items.splice(i, 1); D.quote = null; D.review = false; send.hidden = true; draw(); } }, icon('x'))); }) : [h('p', { class: 'muted small' }, 'Adicione itens acima. Só os itens desta rodada serão enviados — o que já foi enviado antes não é reenviado.')]),
        D.quote ? h('div', { class: 'kv total' }, h('span', {}, 'Total desta rodada'), h('b', {}, fmt(D.quote.subtotal_cents))) : null);
      reviewBtn.disabled = !D.items.length;
    }
    async function quote() {
      err.hidden = true;
      try { D.quote = await call('/api/quote', 'POST', { items: D.items }); D.review = true; send.hidden = false; draw(); } catch (e) { err.textContent = e.message; err.hidden = false; }
    }
    async function submit() {
      if (S.busy) return; if (stale()) { err.textContent = 'Sem conexão: nada foi enviado.'; err.hidden = false; return; }
      S.busy = true; send.disabled = true; send.textContent = 'Enviando…'; err.hidden = true;
      try { await call(`/garcom/api/tab/${id}/round`, 'POST', { items: D.items, notes: D.notes }, { 'Idempotency-Key': D.key }); S.draft = null; toast('Pedido enviado à cozinha/bar.'); go('#/comanda/' + id); }
      catch (e) { err.textContent = e.status ? e.message : 'Sem conexão. Nada foi confirmado — toque de novo para reenviar (sem duplicar).'; err.hidden = false; }
      finally { S.busy = false; send.disabled = false; send.textContent = 'Enviar para a cozinha/bar'; }
    }
    draw();
  }

  // ---------- informar recebimento ----------
  function payScreen(id) {
    S.update = null;
    const st = S.pay && S.pay.tab === id ? S.pay : (S.pay = { tab: id, key: newKey() });
    const amount = h('input', { inputmode: 'decimal', placeholder: 'Ex.: 50,00', 'aria-label': 'Valor recebido', value: st.amount || '' });
    const method = h('select', { 'aria-label': 'Forma de pagamento' }, h('option', { value: '' }, 'Selecione'), Object.entries(S.data.payments).map(([k, l]) => h('option', { value: k }, l)));
    const err = h('p', { class: 'banner bad', role: 'alert', hidden: true }), btn = h('button', { class: 'b gbtn', type: 'submit' }, 'Informar ao caixa');
    const form = h('form', { class: 'gform', novalidate: true }, h('p', { class: 'gcard info' }, 'Isto apenas avisa o caixa. O recebimento só vale depois que o caixa/gerente confere e confirma; a conta não é encerrada por aqui.'),
      h('label', {}, 'Valor recebido (R$)', amount), h('label', {}, 'Forma de pagamento', method), err, btn);
    put(backBtn('#/comanda/' + id), h('h1', {}, 'Informar recebimento'), form);
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); if (S.busy) return; st.amount = amount.value; const c = parseMoney(amount.value);
      if (c < 1) { err.textContent = 'Informe um valor maior que zero.'; err.hidden = false; return; } if (!method.value) { err.textContent = 'Escolha a forma de pagamento.'; err.hidden = false; return; }
      if (stale()) { err.textContent = 'Sem conexão: nada foi enviado.'; err.hidden = false; return; }
      S.busy = true; btn.disabled = true; err.hidden = true;
      try { await call(`/garcom/api/tab/${id}/payment`, 'POST', { amount_cents: c, method: method.value }, { 'Idempotency-Key': st.key }); S.pay = null; toast('Recebimento informado. Aguardando conferência do caixa.'); go('#/comanda/' + id); }
      catch (e) { err.textContent = e.status ? e.message : 'Sem conexão. Nada foi confirmado — toque de novo para reenviar (sem duplicar).'; err.hidden = false; }
      finally { S.busy = false; btn.disabled = false; }
    });
  }

  // ---------- roteamento e atualização ----------
  function show() {
    if (!S.data) return;
    const [r, id] = route();
    if (r === 'mesa') tableScreen(id); else if (r === 'abrir') openScreen(id, false); else if (r === 'nova') openScreen(id, true);
    else if (r === 'comanda') tabScreen(id); else if (r === 'lancar') roundScreen(id); else if (r === 'receber') payScreen(id); else listScreen();
  }
  async function refresh() {
    try { S.data = await call('/garcom/api/state'); S.fails = 0; $('#gstale').hidden = true; }
    catch (e) { S.fails++; if (stale()) $('#gstale').hidden = false; return false; }
    return true;
  }
  async function tick() {
    const ok = await refresh(); const [r, id] = route();
    if (ok) { if (r === 'list' && S.update) S.update(); else if (r === 'mesa') tableScreen(id); else if (r === 'comanda' && !S.busy) { try { S.detail = (await call('/garcom/api/tab/' + id)).tab; drawTab(); } catch { /* mantém */ } } }
    else if (r === 'comanda' && S.detail) drawTab(); // reflete botões desativados
  }
  window.addEventListener('hashchange', show);
  refresh().then(() => { if (!S.data) put(h('p', { class: 'banner bad' }, 'Não foi possível carregar. Verifique a conexão e recarregue.')); else show(); });
  setInterval(tick, 8000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
})();
