'use strict';
/* Novo pedido (balcão ou telefone) — etapas guiadas: categorias · montagem · resumo.
   Só escolhe. Preço, disponibilidade, regra de meia pizza e limites são SEMPRE do servidor (/api/quote e POST /admin/api/orders). */
window.BiancaNewOrder = (() => {
  const { h, fmt } = BiancaPicker;
  const ic = n => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', 'ic'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false'); const u = document.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + n); s.append(u); return s; };
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(16).slice(2)) + 'adm';
  const clone = o => JSON.parse(JSON.stringify(o, (k, v) => v instanceof Set ? { __set: [...v] } : v), (k, v) => v && v.__set ? new Set(v.__set) : v);
  let M = null, cats = [], byId = {}, cfg = {}, dlg = null, root = null, S = null, onDone = null, tab = 'menu';

  const kidsOf = id => cats.filter(c => c.parent_id === id);
  const rootsList = () => cats.filter(c => !c.parent_id && (c.products.length || kidsOf(c.id).some(k => k.products.length)));
  const rootOf = c => c.parent_id ? byId[c.parent_id] || c : c;
  const pizzaCats = r => cats.filter(c => c.is_pizza && rootOf(c).id === r.id);
  const isPizzaRoot = r => pizzaCats(r).length > 0;
  const photo = (p, cls) => p.image ? h('img', { src: p.image, alt: '', loading: 'lazy', class: cls || 'no-ph' }) : null;
  const sizeOf = (p, name) => (p.sizes || []).find(s => s.name === name && s.available);
  const optPrice = (o, size) => (o.size_prices && size && o.size_prices[size] != null) ? o.size_prices[size] : o.price_cents;
  const optVisible = (o, size) => o.available && !(o.size_restricted && size && !(o.size_prices && o.size_prices[size] != null));

  function fresh() { return { key: newKey(), items: [], draft: null, edIdx: null, rootId: null, step: 1, msg: '', err: '', busy: false, lines: null, quoteSeq: 0, summaryOpen: false, type: 'pickup' }; }

  async function open(opts) {
    cfg = opts; onDone = opts.onDone; dlg = document.getElementById('new-dlg');
    S = fresh(); render(); if (!dlg.open) dlg.showModal();
    try { M = await BiancaPicker.loadMenu(); } catch { S.err = 'Não foi possível carregar o cardápio. Feche e tente de novo.'; render(); return; }
    cats = M.categories; byId = Object.fromEntries(cats.map(c => [c.id, c]));
    const r = rootsList()[0]; S.rootId = r ? r.id : null; render();
  }
  const dirty = () => S && (S.items.length || S.draft);
  function close(force) { if (!force && dirty() && !confirm('Há itens ou uma montagem em andamento neste pedido. Fechar e descartar?')) return; dlg.close(); }

  // ---------- servidor: prévia de preços ----------
  let rq; const requote = () => { clearTimeout(rq); rq = setTimeout(doQuote, 150); };
  async function doQuote() {
    const seq = ++S.quoteSeq; if (!S.items.length) { S.lines = null; S.err = ''; drawSummary(); return; }
    const f = form();
    try {
      const r = await api('/api/quote', { items: S.items.map(i => i.payload), type: f.type, zone_id: f.zone_id || null });
      if (seq !== S.quoteSeq) return; S.lines = r; S.err = ''; drawSummary();
    } catch (e) { if (seq === S.quoteSeq) { S.lines = null; S.err = e.message; drawSummary(); } }
  }
  async function api(url, body, extra) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf, ...(extra || {}) }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(d.error || 'Erro ' + r.status); e.status = r.status; throw e; } return d;
  }

  // ---------- pizza: montagem em 3 etapas ----------
  const pool = r => pizzaCats(r).flatMap(c => c.products.map(p => ({ p, c })));
  const maxFlavors = r => (pizzaCats(r)[0] && pizzaCats(r)[0].max_flavors) || M.max_flavors || 2;
  function sizeNames(r) { const seen = new Map(); pool(r).forEach(({ p }) => (p.sizes || []).filter(s => s.available).forEach(s => { const cur = seen.get(s.name) || { name: s.name, detail: s.detail, min: s.price_cents, n: 0 }; cur.min = Math.min(cur.min, s.price_cents); cur.n++; if (!cur.detail && s.detail) cur.detail = s.detail; seen.set(s.name, cur); })); return [...seen.values()].sort((a, b) => a.min - b.min); }
  function startPizza(r) { S.draft = { kind: 'pizza', rootId: r.id, size: null, sel: [], rem: new Set(), opts: new Set(), notes: '', qty: 1 }; S.step = 1; S.msg = ''; }
  const dmain = d => { const hit = pool(byId[d.rootId]).find(x => x.p.id === d.sel[0]); return hit ? hit.p : null; };
  const dpool = d => pool(byId[d.rootId]);
  function pizzaPayload(d) {
    const main = dmain(d); const sz = sizeOf(main, d.size);
    return { product_id: main.id, size_id: sz ? sz.id : null, flavors: d.sel.length > 1 ? [...d.sel] : [], options: [...d.opts], removals: [...d.rem], qty: d.qty, notes: d.notes.trim() };
  }
  function chooseSize(d, name) {
    d.size = name; const lost = [];
    d.sel = d.sel.filter(id => { const p = dpool(d).find(x => x.p.id === id).p; if (sizeOf(p, name)) return true; lost.push(p.name); return false; });
    d.rem = new Set([...d.rem].filter(rid => d.sel.some(id => dpool(d).find(x => x.p.id === id).p.ingredients.some(i => i.id === rid))));
    d.opts = new Set();
    S.msg = lost.length ? `O tamanho ${name} não existe para: ${lost.join(', ')}. Escolha os sabores novamente.` : '';
  }

  // ---------- interface ----------
  const form = () => { const f = dlg.querySelector('#no-form'); const g = n => f && f.elements[n] ? f.elements[n].value : ''; return { type: f ? (f.elements.type.value || 'pickup') : 'pickup', zone_id: g('zone_id'), name: g('name').trim(), phone: g('phone').trim(), street: g('street').trim(), number: g('number').trim(), complement: g('complement').trim(), payment: g('payment'), change_for: g('change_for').trim(), notes: g('notes').trim() }; };

  function render() {
    const keep = dlg.querySelector('#no-form') ? form() : null;
    dlg.replaceChildren(h('div', { class: 'no-wrap', 'data-summary': S.summaryOpen ? 'open' : 'closed' },
      h('header', { class: 'no-head' }, h('img', { src: '/assets/brand/logo-bianca-360.png', alt: 'Bianca Pizzaria', class: 'no-logo' }), h('div', { class: 'no-ttl' }, h('h2', { id: 'no-title' }, 'Novo pedido'), h('p', {}, 'Balcão ou telefone')),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Fechar', onclick: () => close(false) }, ic('x'))),
      h('div', { class: 'no-body' }, catsPane(), h('section', { class: 'no-mid', id: 'no-mid', 'aria-label': 'Montagem' }), summaryPane(keep)),
      h('button', { class: 'b no-sumbtn', type: 'button', onclick: () => { S.summaryOpen = !S.summaryOpen; dlg.querySelector('.no-wrap').dataset.summary = S.summaryOpen ? 'open' : 'closed'; } }, `Resumo do pedido (${S.items.length})`)));
    dlg.setAttribute('aria-labelledby', 'no-title'); drawMid(); drawSummary();
    const f = dlg.querySelector('#no-form'); if (keep) for (const k of ['name', 'phone', 'street', 'number', 'complement', 'change_for', 'notes']) f.elements[k].value = keep[k]; if (keep) { f.elements.zone_id.value = keep.zone_id; f.elements.payment.value = keep.payment; f.elements.type.value = keep.type; }
    sync();
  }
  function catsPane() {
    const rs = M ? rootsList() : [];
    return h('nav', { class: 'no-cats', 'aria-label': 'Categorias' }, h('h3', {}, 'Categorias'), ...rs.map(r => h('button', { class: 'no-cat', type: 'button', 'aria-current': S.rootId === r.id ? 'true' : null, onclick: () => pickRoot(r.id) }, ic(isPizzaRoot(r) ? 'menu' : 'note'), h('span', {}, r.name))));
  }
  function pickRoot(id) {
    if (S.draft && S.draft.rootId !== id && !(S.draft.sel && S.draft.sel.length === 0 && !S.draft.size) && !confirm('Trocar de categoria descarta a montagem em andamento. Continuar?')) return;
    S.rootId = id; S.draft = null; S.edIdx = null; S.msg = ''; const r = byId[id]; if (isPizzaRoot(r)) startPizza(r); render();
  }
  function drawMid() {
    const mid = dlg.querySelector('#no-mid'); if (!mid) return; if (!M) { mid.replaceChildren(h('p', { class: 'muted pad' }, S.err || 'Carregando cardápio…')); return; }
    const r = byId[S.rootId]; if (!r) { mid.replaceChildren(h('p', { class: 'muted pad' }, 'Nenhuma categoria disponível no cardápio.')); return; }
    if (isPizzaRoot(r)) { if (!S.draft || S.draft.kind !== 'pizza' || S.draft.rootId !== r.id) startPizza(r); mid.replaceChildren(...pizzaView(r)); }
    else mid.replaceChildren(...directView(r));
  }

  // etapas da pizza
  function stepper(d) {
    const ok = [true, !!d.size, !!d.size && d.sel.length > 0]; const names = ['Tamanho', 'Sabores', 'Personalizar'];
    return h('ol', { class: 'no-steps' }, names.map((n, i) => h('li', { class: S.step === i + 1 ? 'on' : (S.step > i + 1 ? 'done' : '') }, h('button', { type: 'button', disabled: !ok[i] || null, 'aria-current': S.step === i + 1 ? 'step' : null, onclick: () => { S.step = i + 1; drawMid(); } }, h('span', { class: 'n' }, String(i + 1)), h('span', {}, n)))));
  }
  function pizzaView(r) {
    const d = S.draft, max = maxFlavors(r), out = [stepper(d)];
    if (S.msg) out.push(h('p', { class: 'banner bad no-msg', role: 'alert' }, S.msg));
    if (S.step === 1) {
      const sz = sizeNames(r); out.push(h('h3', { class: 'no-q' }, 'Qual o tamanho da pizza?'));
      if (!sz.length) out.push(h('p', { class: 'muted' }, 'Nenhum tamanho disponível.'));
      out.push(h('div', { class: 'no-sizes', role: 'radiogroup', 'aria-label': 'Tamanho' }, sz.map(s => h('label', { class: 'no-size' + (d.size === s.name ? ' sel' : '') }, h('input', { type: 'radio', name: 'size', value: s.name, checked: d.size === s.name || null, onchange: () => { chooseSize(d, s.name); drawMid(); drawSummary(); } }), photo(pool(r).find(x => sizeOf(x.p, s.name)).p, 'no-sz-img'), h('span', { class: 'no-sz-t' }, h('b', {}, s.name), s.detail ? h('small', {}, s.detail) : null, h('small', {}, 'a partir de ' + fmt(s.min)))))));
      out.push(h('div', { class: 'no-foot' }, h('span', { class: 'muted' }, `Na próxima etapa, escolha de 1 a ${max} sabor${max > 1 ? 'es' : ''}.`), h('button', { class: 'b', type: 'button', disabled: !d.size || null, onclick: () => { S.step = 2; S.msg = ''; drawMid(); } }, 'Escolher sabores →')));
    } else if (S.step === 2) {
      const list = dpool(d).filter(x => sizeOf(x.p, d.size)); const subs = [...new Set(list.map(x => x.c.id))];
      out.push(h('h3', { class: 'no-q' }, 'Escolha os sabores'), h('p', { class: 'no-count' + (d.sel.length ? ' on' : ''), 'aria-live': 'polite' }, d.sel.length ? `${d.sel.length} de ${max} selecionado${d.sel.length > 1 ? 's' : ''}` : `Obrigatório: escolha de 1 a ${max} sabor${max > 1 ? 'es' : ''}`));
      if (subs.length > 1) subs.forEach(id => out.push(h('h4', { class: 'no-sub' }, byId[id].name), flavorGrid(d, list.filter(x => x.c.id === id), max)));
      else out.push(flavorGrid(d, list, max));
      out.push(h('div', { class: 'no-foot' }, h('button', { class: 'b ghost', type: 'button', onclick: () => { S.step = 1; drawMid(); } }, '← Tamanho'), h('button', { class: 'b', type: 'button', disabled: !d.sel.length || null, onclick: () => { S.step = 3; S.msg = ''; drawMid(); } }, 'Personalizar →')));
    } else out.push(...customizeView(r, d));
    return out;
  }
  function flavorGrid(d, list, max) {
    return h('div', { class: 'no-flavors' }, list.map(({ p }) => { const on = d.sel.includes(p.id), blocked = !on && d.sel.length >= max, sz = sizeOf(p, d.size);
      return h('label', { class: 'no-fl' + (on ? ' sel' : '') + (blocked ? ' off' : '') }, h('input', { type: 'checkbox', checked: on || null, disabled: (blocked || !p.available) || null, onchange: e => {
        if (e.target.checked) { if (d.sel.length >= max) { e.target.checked = false; S.msg = `Máximo de ${max} sabor${max > 1 ? 'es' : ''} por pizza.`; drawMid(); return; } d.sel.push(p.id); } else { d.sel = d.sel.filter(x => x !== p.id); d.rem = new Set([...d.rem].filter(rid => d.sel.some(id => dpool(d).find(x => x.p.id === id).p.ingredients.some(i => i.id === rid)))); }
        S.msg = ''; drawMid(); drawSummary(); } }), photo(p, 'no-fl-img'), h('span', { class: 'no-fl-t' }, h('b', {}, p.name), p.description ? h('small', {}, p.description) : null, h('small', { class: 'pr' }, sz ? fmt(sz.price_cents) : ''), blocked ? h('small', { class: 'why' }, `Máximo de ${max} atingido`) : null));
    }));
  }
  function customizeView(r, d) {
    const main = dmain(d), out = [h('h3', { class: 'no-q' }, 'Personalize a pizza'), h('p', { class: 'no-recap' }, `${d.size} · ${d.sel.map(id => dpool(d).find(x => x.p.id === id).p.name).join(' + ')}`)];
    if (S.err) out.push(h('p', { class: 'banner bad', role: 'alert' }, S.err));
    d.sel.forEach(id => { const { p, c } = dpool(d).find(x => x.p.id === id); const rm = (p.ingredients || []).filter(i => i.removable);
      if (c.allow_removals && rm.length) out.push(h('fieldset', { class: 'no-fs' }, h('legend', {}, 'Retirar ingredientes' + (d.sel.length > 1 ? ' — ' + p.name : '')), rm.map(i => h('label', { class: 'opt' }, h('input', { type: 'checkbox', checked: d.rem.has(i.id) || null, onchange: e => { e.target.checked ? d.rem.add(i.id) : d.rem.delete(i.id); preview(); } }), h('span', {}, 'Sem ' + i.name))))); });
    main.groups.forEach(g => { const single = g.max === 1, opts = g.options.filter(o => optVisible(o, d.size)); if (!opts.length) return;
      out.push(h('fieldset', { class: 'no-fs' }, h('legend', {}, g.name + (g.min ? ' (obrigatório)' : '') + (g.max > 1 ? ` · até ${g.max}` : '')),
        single && !g.min ? h('label', { class: 'opt' }, h('input', { type: 'radio', name: 'g' + g.id, checked: !opts.some(o => d.opts.has(o.id)) || null, onchange: () => { opts.forEach(o => d.opts.delete(o.id)); preview(); } }), h('span', {}, /^borda/i.test(g.name) ? 'Sem borda recheada' : 'Sem ' + g.name.toLowerCase())) : null,
        opts.map(o => h('label', { class: 'opt' }, h('input', { type: single ? 'radio' : 'checkbox', name: 'g' + g.id, checked: d.opts.has(o.id) || null, onchange: e => { if (single) { opts.forEach(x => d.opts.delete(x.id)); d.opts.add(o.id); } else if (e.target.checked) { const n = opts.filter(x => d.opts.has(x.id)).length; if (g.max && n >= g.max) { e.target.checked = false; S.err = `Máximo de ${g.max} em "${g.name}".`; drawMid(); return; } d.opts.add(o.id); } else d.opts.delete(o.id); S.err = ''; preview(); } }), h('span', {}, o.name), optPrice(o, d.size) ? h('b', { class: 'add' }, '+ ' + fmt(optPrice(o, d.size))) : null)))); });
    const note = h('textarea', { rows: 2, maxlength: 200, placeholder: 'Ex.: ponto da massa, observações gerais…', 'aria-label': 'Observações', oninput: e => { d.notes = e.target.value; } }); note.value = d.notes;
    const q = qtyCtl(d.qty, v => { d.qty = v; preview(); });
    out.push(h('label', { class: 'no-lbl' }, 'Observações', note), h('div', { class: 'no-foot' }, h('button', { class: 'b ghost', type: 'button', onclick: () => { S.step = 2; drawMid(); } }, '← Sabores'), q, h('button', { class: 'b', type: 'button', onclick: () => addPizza(r, d) }, S.edIdx != null ? 'Salvar alterações' : 'Adicionar ao pedido')));
    queueMicrotask(preview); return out;
  }
  const qtyCtl = (v, on) => { const o = h('output', { 'aria-live': 'polite' }, String(v)); let cur = v; return h('span', { class: 'qty' }, h('button', { type: 'button', 'aria-label': 'Diminuir quantidade', onclick: () => { cur = Math.max(1, cur - 1); o.textContent = cur; on(cur); } }, '−'), o, h('button', { type: 'button', 'aria-label': 'Aumentar quantidade', onclick: () => { cur = Math.min(50, cur + 1); o.textContent = cur; on(cur); } }, '+')); };
  // prévia do preço do que está em montagem (servidor); NÃO entra no total do pedido
  let pq; function preview() { clearTimeout(pq); pq = setTimeout(async () => { const d = S.draft; if (!d || !d.size || !d.sel || !d.sel.length) { S.draftLine = null; drawSummary(); return; } const my = d; try { const r = await api('/api/quote', { items: [pizzaPayload(d)], type: 'pickup' }); if (S.draft === my) { S.draftLine = r.lines[0]; S.err = ''; drawSummary(); } } catch (e) { if (S.draft === my) { S.draftLine = null; S.err = e.message; drawMid(); drawSummary(); } } }, 150); }
  function addPizza(r, d) {
    if (!d.size || !d.sel.length) { S.err = 'Escolha o tamanho e ao menos um sabor.'; return drawMid(); }
    for (const g of dmain(d).groups) { const n = g.options.filter(o => d.opts.has(o.id)).length; if (n < g.min) { S.err = `Escolha ao menos ${g.min} em "${g.name}".`; return drawMid(); } }
    commit({ spec: clone(d), payload: pizzaPayload(d) }); S.draft = null; S.err = ''; startPizza(r); render();
  }
  function commit(item) { if (S.edIdx != null) { S.items[S.edIdx] = item; S.edIdx = null; } else { const sig = JSON.stringify({ ...item.payload, qty: 0 }); const same = S.items.find(i => JSON.stringify({ ...i.payload, qty: 0 }) === sig); if (same) { same.payload.qty = Math.min(50, same.payload.qty + item.payload.qty); if (same.spec) same.spec.qty = same.payload.qty; } else S.items.push(item); } S.draftLine = null; requote(); }

  // ---------- demais categorias: seleção direta ----------
  function directView(r) {
    const groups = [r, ...kidsOf(r.id)].filter(c => c.products.length), out = [];
    if (S.err) out.push(h('p', { class: 'banner bad', role: 'alert' }, S.err));
    out.push(h('h3', { class: 'no-q' }, r.name));
    groups.forEach(c => { if (groups.length > 1) out.push(h('h4', { class: 'no-sub' }, c.name)); out.push(h('div', { class: 'no-flavors direct' }, c.products.map(p => directCard(p, c)))); });
    if (S.draft && S.draft.kind === 'simple') out.push(configPanel(S.draft));
    return out;
  }
  function needsConfig(p) { return (p.sizes || []).filter(s => s.available).length > 1 || p.groups.some(g => g.min > 0 || g.options.some(o => o.available)); }
  function directCard(p, c) {
    const from = p.sizes && p.sizes.length ? Math.min(...p.sizes.filter(s => s.available).map(s => s.price_cents)) : p.price_cents, qty = { v: 1 };
    const cfgd = needsConfig(p);
    return h('div', { class: 'no-fl card' + (p.available ? '' : ' off') }, photo(p, 'no-fl-img'), h('span', { class: 'no-fl-t' }, h('b', {}, p.name), p.description ? h('small', {}, p.description) : null, h('small', { class: 'pr' }, (p.sizes && p.sizes.length > 1 ? 'a partir de ' : '') + fmt(from)), !p.available ? h('small', { class: 'why' }, 'Indisponível') : null),
      p.available ? h('span', { class: 'no-card-act' }, cfgd ? null : qtyCtl(1, v => { qty.v = v; }), h('button', { class: 'b sm', type: 'button', onclick: () => cfgd ? openSimple(p, c) : addSimple(p, qty.v) }, cfgd ? 'Escolher' : 'Adicionar')) : null);
  }
  const firstSize = p => (p.sizes || []).find(s => s.available);
  function openSimple(p, c, preset) { S.draft = { kind: 'simple', pid: p.id, size_id: preset ? preset.size_id : (p.sizes.filter(s => s.available).length === 1 ? firstSize(p).id : null), opts: new Set(preset ? preset.opts : []), notes: preset ? preset.notes : '', qty: preset ? preset.qty : 1 }; S.err = ''; drawMid(); }
  function simplePayload(d) { return { product_id: d.pid, size_id: d.size_id, flavors: [], options: [...d.opts], removals: [], qty: d.qty, notes: d.notes.trim() }; }
  function addSimple(p, qty) { commit({ spec: { kind: 'simple', pid: p.id, size_id: firstSize(p) ? firstSize(p).id : null, opts: new Set(), notes: '', qty }, payload: { product_id: p.id, size_id: firstSize(p) ? firstSize(p).id : null, flavors: [], options: [], removals: [], qty, notes: '' } }); drawSummary(); toastNo(`${p.name} adicionado`); }
  function findProduct(id) { for (const c of cats) { const p = c.products.find(x => x.id === id); if (p) return { p, c }; } return null; }
  function configPanel(d) {
    const { p } = findProduct(d.pid), sz = (p.sizes || []).find(s => s.id === d.size_id), sizes = (p.sizes || []).filter(s => s.available);
    const body = [h('h4', { class: 'no-sub' }, p.name)];
    if (S.err) body.push(h('p', { class: 'banner bad', role: 'alert' }, S.err));
    if (sizes.length) body.push(h('fieldset', { class: 'no-fs' }, h('legend', {}, 'Tamanho (obrigatório)'), sizes.map(s => h('label', { class: 'opt' }, h('input', { type: 'radio', name: 'sz', checked: d.size_id === s.id || null, onchange: () => { d.size_id = s.id; d.opts = new Set(); drawMid(); } }), h('span', {}, s.name), h('b', { class: 'add' }, fmt(s.price_cents))))));
    p.groups.forEach(g => { const single = g.max === 1, opts = g.options.filter(o => optVisible(o, sz && sz.name)); if (!opts.length) return;
      body.push(h('fieldset', { class: 'no-fs' }, h('legend', {}, g.name + (g.min ? ' (obrigatório)' : ' (opcional)') + (g.max > 1 ? ` · até ${g.max}` : '')), opts.map(o => h('label', { class: 'opt' }, h('input', { type: single ? 'radio' : 'checkbox', name: 'g' + g.id, checked: d.opts.has(o.id) || null, onchange: e => { if (single) { opts.forEach(x => d.opts.delete(x.id)); d.opts.add(o.id); } else if (e.target.checked) { const n = opts.filter(x => d.opts.has(x.id)).length; if (g.max && n >= g.max) { e.target.checked = false; S.err = `Máximo de ${g.max} em "${g.name}".`; drawMid(); return; } d.opts.add(o.id); } else d.opts.delete(o.id); } }), h('span', {}, o.name), optPrice(o, sz && sz.name) ? h('b', { class: 'add' }, '+ ' + fmt(optPrice(o, sz && sz.name))) : null)))); });
    const note = h('input', { maxlength: 200, placeholder: 'Observação do item', 'aria-label': 'Observação do item', oninput: e => { d.notes = e.target.value; } }); note.value = d.notes;
    body.push(h('div', { class: 'no-foot' }, h('button', { class: 'b ghost', type: 'button', onclick: () => { S.draft = null; S.edIdx = null; S.err = ''; drawMid(); } }, 'Cancelar'), note, qtyCtl(d.qty, v => { d.qty = v; }), h('button', { class: 'b', type: 'button', onclick: () => {
      if (sizes.length && !d.size_id) { S.err = 'Escolha o tamanho.'; return drawMid(); } for (const g of p.groups) { const n = g.options.filter(o => d.opts.has(o.id)).length; if (n < g.min) { S.err = `Escolha ao menos ${g.min} em "${g.name}".`; return drawMid(); } }
      commit({ spec: clone(d), payload: simplePayload(d) }); S.draft = null; S.err = ''; render(); } }, S.edIdx != null ? 'Salvar alterações' : 'Adicionar ao pedido')));
    return h('section', { class: 'no-config', 'aria-label': 'Escolhas do produto' }, body);
  }

  // ---------- resumo ----------
  function summaryPane(keep) {
    const zones = cfg.zones || [], pays = cfg.pays || {};
    return h('aside', { class: 'no-sum', 'aria-label': 'Resumo do pedido' }, h('h3', {}, 'Resumo do pedido'),
      h('form', { id: 'no-form', novalidate: true, onsubmit: submit, onchange: e => { sync(); if (e.target.name === 'type' || e.target.name === 'zone_id') requote(); } },
        h('div', { id: 'no-draft', class: 'no-draftbox', hidden: true }), h('div', { id: 'no-items', class: 'no-items' }), h('div', { id: 'no-tots', class: 'tots', 'aria-live': 'polite' }),
        h('fieldset', { class: 'no-fs plain' }, h('legend', {}, 'Forma de atendimento'), h('div', { class: 'seg' }, h('label', {}, h('input', { type: 'radio', name: 'type', value: 'pickup', checked: true }), h('span', {}, 'Retirada / balcão')), h('label', {}, h('input', { type: 'radio', name: 'type', value: 'delivery' }), h('span', {}, 'Entrega')))),
        h('label', {}, 'Nome (opcional — em branco = "Balcão")', h('input', { name: 'name', maxlength: 120, autocomplete: 'off' })), h('label', {}, 'Telefone com DDD (opcional)', h('input', { name: 'phone', type: 'tel', inputmode: 'tel', autocomplete: 'off' })),
        h('div', { id: 'no-addr', hidden: true }, h('label', {}, 'Bairro atendido', h('select', { name: 'zone_id' }, h('option', { value: '' }, 'Selecione'), zones.map(z => h('option', { value: z.id }, `${z.name} — taxa ${fmt(z.fee_cents)}`)))), h('div', { class: 'inline' }, h('label', {}, 'Rua', h('input', { name: 'street', maxlength: 160 })), h('label', { class: 'xs' }, 'Número', h('input', { name: 'number', maxlength: 20 }))), h('label', {}, 'Complemento / referência', h('input', { name: 'complement', maxlength: 120 })), !zones.length ? h('p', { class: 'note' }, 'Nenhum bairro cadastrado: cadastre em Configurações para usar entrega.') : null),
        h('label', {}, 'Forma de pagamento', h('select', { name: 'payment' }, h('option', { value: '' }, 'Selecione'), Object.entries(pays).map(([k, p]) => h('option', { value: k }, p.label)))), h('label', { id: 'no-change', hidden: true }, 'Troco para (opcional)', h('input', { name: 'change_for', inputmode: 'decimal' })),
        h('label', {}, 'Observações do pedido', h('textarea', { name: 'notes', rows: 2, maxlength: 300 })),
        h('p', { id: 'no-err', class: 'banner bad', role: 'alert', hidden: true }), h('p', { class: 'muted small' }, 'O pagamento fica "pendente" até a equipe conferir; escolher a forma não marca como recebido. Este cadastro ignora o horário e a pausa da loja. O servidor revalida preços e disponibilidade ao registrar.'),
        h('button', { class: 'b block', type: 'submit', id: 'no-submit' }, 'Registrar pedido')));
  }
  function sync() { const f = dlg.querySelector('#no-form'); if (!f) return; const del = f.elements.type.value === 'delivery'; dlg.querySelector('#no-addr').hidden = !del; dlg.querySelector('#no-change').hidden = f.elements.payment.value !== 'cash'; }
  function drawSummary() {
    const box = dlg.querySelector('#no-items'); if (!box) return; const L = S.lines && S.lines.lines;
    const dr = dlg.querySelector('#no-draft'), d = S.draft;
    if (d && d.kind === 'pizza' && (d.size || d.sel.length)) { const names = d.sel.map(id => dpool(d).find(x => x.p.id === id).p.name); dr.hidden = false; dr.replaceChildren(...[h('b', {}, 'Pizza em montagem'), h('small', {}, [d.size || 'Tamanho a escolher', names.length ? names.join(' + ') : 'Sabores a escolher'].join(' · ')), S.draftLine && d.sel.length ? h('small', { class: 'muted' }, 'Prévia (ainda não está no total): ' + fmt(S.draftLine.total_cents)) : null].filter(Boolean)); } else dr.hidden = true;
    if (!S.items.length) box.replaceChildren(h('p', { class: 'muted' }, 'Nenhum item adicionado ao pedido.'));
    else box.replaceChildren(...S.items.map((it, i) => { const l = L && L[i];
      return h('div', { class: 'no-it' }, h('span', { class: 'grow' }, h('b', {}, l ? `${l.name}${l.size_name ? ' (' + l.size_name + ')' : ''}` : '…'), l && l.flavors && l.flavors.length && !/½/.test(l.name) ? h('small', {}, l.flavors.map(n => '½ ' + n).join(' + ')) : null,l && l.options.length ? h('small', {}, l.options.map(o => o.name + (o.price_cents ? ` (+${fmt(o.price_cents)})` : '')).join(', ')) : null, it.payload.notes ? h('small', { class: 'obs' }, 'Obs.: ' + it.payload.notes) : null),
        h('b', {}, l ? fmt(l.total_cents) : ''), h('span', { class: 'no-it-act' }, qtyCtl(it.payload.qty, v => { it.payload.qty = v; if (it.spec) it.spec.qty = v; requote(); }), h('button', { class: 'b ghost sm', type: 'button', onclick: () => editItem(i) }, 'Editar'), h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Remover item ' + (i + 1), onclick: () => { S.items.splice(i, 1); if (S.edIdx === i) { S.edIdx = null; S.draft = null; } requote(); render(); } }, ic('x')))); }));
    const tots = dlg.querySelector('#no-tots'), r = S.lines; const f = form();
    if (!r) { tots.replaceChildren(); } else {
      const extras = r.lines.reduce((s, l) => s + l.options.reduce((a, o) => a + (o.price_cents || 0), 0) * l.qty, 0);
      tots.replaceChildren(...[h('div', { class: 'row' }, h('span', {}, 'Subtotal'), h('span', {}, fmt(r.subtotal_cents))), extras ? h('div', { class: 'row muted' }, h('span', {}, 'Adicionais (já no subtotal)'), h('span', {}, fmt(extras))) : null,
        f.type === 'delivery' ? h('div', { class: 'row' }, h('span', {}, 'Entrega'), h('span', {}, f.zone_id ? fmt(r.delivery_fee_cents) : 'escolha o bairro')) : null, h('div', { class: 'row total' }, h('span', {}, 'Total'), h('b', {}, fmt(r.total_cents)))].filter(Boolean));
    }
    const sb = dlg.querySelector('.no-sumbtn'); if (sb) sb.textContent = `Resumo do pedido (${S.items.length})`;
    const e = dlg.querySelector('#no-err'); if (e && S.items.length && S.err) { e.textContent = S.err; e.hidden = false; }
  }
  function editItem(i) {
    const it = S.items[i]; if (!it.spec) return; if (S.draft && !confirm('Substituir a montagem em andamento pela edição deste item?')) return;
    S.draft = clone(it.spec); S.edIdx = i; S.err = '';
    if (S.draft.kind === 'pizza') { S.rootId = S.draft.rootId; S.step = 3; } else { const f = findProduct(S.draft.pid); S.rootId = rootOf(f.c).id; }
    render();
  }
  const toastNo = m => { const t = dlg.querySelector('.no-toast') || dlg.querySelector('.no-wrap').appendChild(h('div', { class: 'no-toast', role: 'status' })); t.textContent = m; t.classList.add('show'); clearTimeout(toastNo.t); toastNo.t = setTimeout(() => t.classList.remove('show'), 1800); };

  async function submit(ev) {
    ev.preventDefault(); if (S.busy) return; const err = dlg.querySelector('#no-err'); const fail = m => { err.textContent = m; err.hidden = false; }; err.hidden = true;
    if (S.draft && (S.draft.sel && S.draft.sel.length || S.draft.kind === 'simple') && !confirm('Há uma montagem em andamento que NÃO está no pedido. Registrar mesmo assim, sem ela?')) return;
    if (!S.items.length) return fail('Adicione ao menos um item.'); const f = form(); if (!f.payment) return fail('Escolha a forma de pagamento.');
    if (f.type === 'delivery' && (!f.zone_id || !f.street || !f.number)) return fail('Para entrega, informe bairro, rua e número.');
    S.busy = true; const btn = dlg.querySelector('#no-submit'); btn.disabled = true; btn.textContent = 'Registrando…';
    try {
      const r = await api('/admin/api/orders', { type: f.type, name: f.name, phone: f.phone, zone_id: f.zone_id || null, street: f.street, number: f.number, complement: f.complement, payment: f.payment, change_for: f.change_for, notes: f.notes, items: S.items.map(i => i.payload) }, { 'Idempotency-Key': S.key });
      S.items = []; S.draft = null; close(true); if (onDone) onDone(r);
    } catch (e) { fail(e.status ? e.message : 'Sem conexão. Nada foi perdido: toque em "Registrar pedido" de novo — não haverá duplicidade.'); }
    finally { S.busy = false; const b = dlg.querySelector('#no-submit'); if (b) { b.disabled = false; b.textContent = 'Registrar pedido'; } }
  }
  window.addEventListener('beforeunload', e => { if (dlg && dlg.open && dirty()) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && dlg && dlg.open) { e.preventDefault(); close(false); } });
  return { open };
})();
