/* Movimento do site público (todas as páginas). Sem biblioteca; falha com segurança: nada fica escondido se este arquivo não carregar. */
/* Movimento do site público. Sem biblioteca: IntersectionObserver + um rAF leve para a foto do hero.
   Falha com segurança: se algo der errado, nada fica escondido (o estado "pré" é removido). */
(() => {
  'use strict';
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const cleanups = [];

  // ---- cabeçalho: sombra ao rolar (1 listener passivo, 1 leitura por frame) ----
  const top = document.querySelector('.top');
  if (top) {
    let tick = false;
    const onScroll = () => { if (tick) return; tick = true; requestAnimationFrame(() => { top.classList.toggle('is-stuck', scrollY > 8); tick = false; }); };
    addEventListener('scroll', onScroll, { passive: true }); onScroll();
    cleanups.push(() => removeEventListener('scroll', onScroll));
  }

  // ---- menu móvel: fecha com transição, por Esc, clique fora ou ao escolher um link ----
  const mnav = document.querySelector('.mnav');
  if (mnav) {
    const close = () => {
      if (!mnav.open) return;
      if (reduce.matches) { mnav.open = false; return; }
      mnav.classList.add('is-closing');
      setTimeout(() => { mnav.open = false; mnav.classList.remove('is-closing'); }, 150);
    };
    mnav.querySelector('summary').addEventListener('click', e => { if (mnav.open) { e.preventDefault(); close(); } });
    mnav.addEventListener('click', e => { if (e.target.closest('a')) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && mnav.open) { close(); mnav.querySelector('summary').focus(); } });
    document.addEventListener('click', e => { if (mnav.open && !mnav.contains(e.target)) close(); });
  }

  if (reduce.matches || !('IntersectionObserver' in window)) return; // conteúdo já visível; sem efeitos decorativos

  // ---- revelação ao rolar: só o que está abaixo da primeira dobra, uma vez ----
  const groups = [
    ['.sec-title', 0], ['.cats .tile', 1], ['.facts > div', 1], ['.cta-band .copy', 0], ['.cta-band img', 0, 'mo-zoom'],
    ['.split .prose', 0, 'mo-side-l'], ['.split .photo', 0, 'mo-side-r'], ['.split.rev .prose', 0, 'mo-side-r'], ['.split.rev .photo', 0, 'mo-side-l'],
    ['.timeline li', 1], ['.sec > .wrap > .prose, .wrap.sec > .prose', 0], ['.foot-grid > div', 1],
  ];
  const vh = innerHeight, seen = new Set(), targets = [], plan = [];
  // fase de leitura (todas as medições juntas, sem escrever no DOM entre elas: evita recalcular o layout a cada elemento)
  for (const [sel, stagger, kind] of groups) {
    $$(sel).forEach((el, i) => {
      if (seen.has(el)) return; seen.add(el);
      if (el.getBoundingClientRect().top >= vh * 0.92) plan.push([el, stagger, kind, i]); // já visível: nunca esconder
    });
  }
  // fase de escrita
  for (const [el, stagger, kind, i] of plan) {
    if (kind) el.classList.add(kind);
    if (stagger) el.style.setProperty('--d', Math.min(i % 4, 3) * 70 + 'ms'); // sem atrasos cumulativos longos
    el.classList.add('mo-pre'); targets.push(el);
  }
  const revealAll = () => targets.forEach(t => t.classList.add('mo-in'));
  if (targets.length) {
    const io = new IntersectionObserver(es => {
      for (const e of es) if (e.isIntersecting) { e.target.classList.add('mo-in'); io.unobserve(e.target); }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    targets.forEach(t => io.observe(t));
    cleanups.push(() => io.disconnect());
    // foco por teclado em item ainda escondido: revela na hora
    const onFocus = e => { const t = e.target.closest && e.target.closest('.mo-pre'); if (t) { t.classList.add('mo-in'); io.unobserve(t); } };
    document.addEventListener('focusin', onFocus); cleanups.push(() => document.removeEventListener('focusin', onFocus));
    // se o usuário ativar "reduzir movimento" com a página aberta: mostra tudo e para os efeitos
    const onPref = () => { if (reduce.matches) { revealAll(); io.disconnect(); } };
    if (reduce.addEventListener) { reduce.addEventListener('change', onPref); cleanups.push(() => reduce.removeEventListener('change', onPref)); }
    // rede de segurança: se por algum motivo o observador não disparar, nada fica escondido
    setTimeout(() => targets.forEach(t => { if (!t.classList.contains('mo-in')) { const r = t.getBoundingClientRect(); if (r.top < innerHeight && r.bottom > 0) t.classList.add('mo-in'); } }), 1800);
  }

  // ---- paralaxe leve da foto do hero: só desktop, só enquanto o hero está na tela ----
  const hero = document.querySelector('.hero-pizza');
  if (hero && matchMedia('(min-width: 900px)').matches) {
    let vis = false, raf = 0;
    const upd = () => { raf = 0; const y = Math.min(scrollY, 520); hero.style.setProperty('--py', (-y * 0.045).toFixed(1) + 'px'); };
    const onS = () => { if (vis && !raf) raf = requestAnimationFrame(upd); };
    const hio = new IntersectionObserver(es => { vis = es[0].isIntersecting; if (vis) onS(); });
    hio.observe(hero); addEventListener('scroll', onS, { passive: true });
    cleanups.push(() => { hio.disconnect(); removeEventListener('scroll', onS); cancelAnimationFrame(raf); });
  }

  // ao sair da página: libera observadores/listeners. Se a página for guardada no cache de voltar/avançar (bfcache),
  // revela o que faltava, pois os observadores já terão sido desligados.
  addEventListener('pagehide', e => { if (e.persisted) revealAll(); cleanups.forEach(f => f()); }, { once: true });
})();
