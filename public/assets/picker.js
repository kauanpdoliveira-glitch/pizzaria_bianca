'use strict';
/* Seletor de produtos compartilhado (Novo pedido, Editar itens e Salão). Só escolhe; preço e validação são do servidor. */
window.BiancaPicker = (() => {
  const fmt = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const h = (tag, attrs = {}, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) { if (v == null || v === false) continue; if (k === 'class') el.className = v; else if (k.startsWith('on')) el.addEventListener(k.slice(2), v); else el.setAttribute(k, v === true ? '' : v); }
    for (const k of kids.flat()) if (k != null && k !== false) el.append(k.nodeType ? k : document.createTextNode(k));
    return el;
  };
  const plus = () => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', 'ic'); s.setAttribute('aria-hidden', 'true'); const u = document.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-plus'); s.append(u); return s; };
  let MENU = null;
  const loadMenu = async () => MENU || (MENU = await (await fetch('/api/menu')).json());

  /** host: elemento onde montar; onAdd(item): recebe {product_id,size_id,flavors,options,qty,notes}; label: texto do botão. */
  function make(host, onAdd, label = 'Adicionar ao pedido') {
    const cat = h('select', { 'aria-label': 'Categoria' }), prod = h('select', { 'aria-label': 'Produto' }), cfg = h('div', { class: 'nd-cfg', hidden: true });
    host.replaceChildren(h('div', { class: 'nd-pick' }, h('label', {}, 'Categoria', cat), h('label', {}, 'Produto', prod)), cfg);
    const fill = () => {
      const c = MENU.categories.find(x => String(x.id) === cat.value); cfg.hidden = true; if (!c) return;
      prod.replaceChildren(h('option', { value: '' }, 'Selecione o produto'), ...c.products.map(p => h('option', { value: p.id, disabled: !p.available || null }, p.name + (p.available ? '' : ' (indisponível)'))));
    };
    const configure = () => {
      const c = MENU.categories.find(x => String(x.id) === cat.value), p = c && c.products.find(x => String(x.id) === prod.value);
      if (!p) { cfg.hidden = true; return; }
      const st = { opts: new Set() };
      const size = p.sizes.length ? h('select', { 'aria-label': 'Tamanho' }, p.sizes.filter(s => s.available).map(s => h('option', { value: s.id }, `${s.name} — ${fmt(s.price_cents)}`))) : null;
      const flav = c.is_pizza && MENU.max_flavors > 1 ? h('select', { 'aria-label': 'Segundo sabor (meia a meia)' }, h('option', { value: '' }, 'Pizza inteira (1 sabor)'), c.products.filter(x => x.id !== p.id && x.available).map(x => h('option', { value: x.id }, 'Meia a meia com ' + x.name))) : null;
      const groups = p.groups.map(g => h('fieldset', {}, h('legend', {}, `${g.name} ${g.min ? '(obrigatório)' : '(opcional)'}${g.max > 1 ? ' · até ' + g.max : ''}`), g.options.map(o => h('label', { class: 'opt' },
        h('input', { type: g.max === 1 ? 'radio' : 'checkbox', name: 'g' + g.id, disabled: !o.available || null, onclick: ev => { if (g.max === 1) { const was = st.opts.has(o.id); g.options.forEach(x => st.opts.delete(x.id)); if (!(was && !g.min)) st.opts.add(o.id); else ev.target.checked = false; } else if (ev.target.checked) st.opts.add(o.id); else st.opts.delete(o.id); } }),
        o.name + (o.price_cents ? ' · ' + fmt(o.price_cents) : '')))));
      const qty = h('input', { type: 'number', min: 1, max: 50, value: 1, 'aria-label': 'Quantidade', class: 'xs' }), note = h('input', { maxlength: 200, placeholder: 'Observação do item (ex.: sem cebola)', 'aria-label': 'Observação do item' });
      cfg.replaceChildren(h('strong', {}, p.name), size, flav, ...groups, h('div', { class: 'inline' }, h('label', {}, 'Qtd.', qty), h('label', { class: 'grow' }, 'Observação', note)),
        h('button', { class: 'b sm', type: 'button', onclick: () => {
          for (const g of p.groups) { const n = g.options.filter(o => st.opts.has(o.id)).length; if (n < g.min) { alert(`Escolha ao menos ${g.min} em "${g.name}".`); return; } }
          onAdd({ product_id: p.id, size_id: size ? +size.value : null, flavors: flav && flav.value ? [p.id, +flav.value] : [], options: [...st.opts], qty: Math.max(1, +qty.value || 1), notes: note.value.trim() });
          cfg.hidden = true; prod.value = '';
        } }, plus(), label));
      cfg.hidden = false;
    };
    cat.addEventListener('change', fill); prod.addEventListener('change', configure);
    return { load() { cat.replaceChildren(...MENU.categories.filter(c => c.products.length).map(c => h('option', { value: c.id }, c.name))); fill(); } };
  }
  return { make, loadMenu, fmt, h };
})();
