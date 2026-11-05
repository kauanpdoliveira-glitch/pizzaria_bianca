'use strict';
/* Filtros simples de tabela (busca + chips de estado) usados pelas telas do painel. Só mostra/oculta linhas já carregadas. */
(() => {
  const apply = t => {
    const q = (t.dataset.q || '').toLowerCase(), v = t.dataset.v || ''; let shown = 0;
    t.querySelectorAll('tbody tr[data-state]').forEach(r => { const ok = (!v || r.dataset.state === v) && (!q || r.textContent.toLowerCase().includes(q)); r.hidden = !ok; if (ok) shown++; });
    let e = t.parentElement.querySelector('.no-rows'); const any = t.querySelector('tbody tr[data-state]');
    if (any && !shown) { if (!e) { e = document.createElement('p'); e.className = 'empty no-rows'; e.textContent = 'Nenhum resultado para este filtro.'; t.parentElement.append(e); } } else if (e) e.remove();
  };
  document.querySelectorAll('input[data-filter]').forEach(i => { const t = document.querySelector(i.dataset.filter); if (t) i.addEventListener('input', () => { t.dataset.q = i.value; apply(t); }); });
  document.querySelectorAll('[data-chip]').forEach(b => { const t = document.querySelector(b.dataset.chip); if (!t) return; b.setAttribute('aria-pressed', b.classList.contains('on')); b.addEventListener('click', () => { document.querySelectorAll('[data-chip="' + b.dataset.chip + '"]').forEach(x => { x.classList.remove('on'); x.setAttribute('aria-pressed', 'false'); }); b.classList.add('on'); b.setAttribute('aria-pressed', 'true'); t.dataset.v = b.dataset.val; apply(t); }); });
})();

/* Abas de painel (Configurações): mostra um grupo [data-pane] por vez; sem JavaScript, todos ficam visíveis. */
(() => {
  const tabs = document.querySelectorAll('#cfg-tabs [data-tab]'), panes = document.querySelectorAll('[data-pane]'); if (!tabs.length) return;
  const show = n => { tabs.forEach(t => { const on = t.dataset.tab === n; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); }); panes.forEach(p => { p.hidden = p.dataset.pane !== n; }); try { sessionStorage.setItem('cfgTab', n); } catch (e) { /* sem memória */ } };
  tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab)));
  let s = null; try { s = sessionStorage.getItem('cfgTab'); } catch (e) { /* ok */ }
  show(s && document.querySelector('[data-tab="' + s + '"]') ? s : 'loja');
})();
