/* Editor de layouts de comandas: reordenar blocos (arrastar e botões ↑ ↓), prévia ao vivo (só dados fictícios; nunca imprime).
 * O servidor valida tudo (lista branca); aqui só se monta a estrutura de dados. Sem HTML/JS/comandos do usuário. */
(function () {
  'use strict';
  var form = document.getElementById('layform'); if (!form) return;
  var list = document.getElementById('blocks'), CSRF = form.querySelector('[name=_csrf]').value, sector = form.dataset.sector;
  var box = document.getElementById('pv-box'), note = document.getElementById('pv-note'), warn = document.getElementById('pv-warn'), timer = null, drag = null;

  function field(li, f) { return li.querySelector('[data-f="' + f + '"]'); }
  function val(el) { return !el ? false : (el.type === 'checkbox' ? el.checked : el.value); }
  function renumber() {   // nomes blocks[i][campo] na ordem atual do DOM (formulário clássico)
    [].forEach.call(list.children, function (li, i) { [].forEach.call(li.querySelectorAll('[data-f]'), function (el) { el.name = 'blocks[' + i + '][' + el.dataset.f + ']'; }); });
  }
  function collect() {
    var q = function (n) { var e = form.querySelector('[name="' + n + '"]'); return e; };
    var blocks = [].map.call(list.children, function (li) { return { id: val(field(li, 'id')), show: !!val(field(li, 'show')), align: val(field(li, 'align')), size: val(field(li, 'size')), bold: !!val(field(li, 'bold')), options: !!val(field(li, 'options')), prices: !!val(field(li, 'prices')) }; });
    return { page: { separator: q('page[separator]').value, spacing: q('page[spacing]').value, cols: q('page[cols]').value, margin: q('page[margin]').value },
      header: { brand: q('header[brand]').checked, brand_text: q('header[brand_text]').value, subtitle: q('header[subtitle]').checked, logo: q('header[logo]').checked }, footer: { text: q('footer_text').value }, blocks: blocks };
  }
  function preview() {
    var p = document.getElementById('pv-profile').value.split(':');
    fetch('/admin/api/layout/preview', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ sector: sector, layout: collect(), scenario: document.getElementById('pv-scenario').value, mode: p[0], paper: +p[1], reprint: document.getElementById('pv-reprint').checked }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) { warn.textContent = x.j.error || 'Não foi possível gerar a prévia.'; return; }
        warn.textContent = x.j.warn || ''; box.innerHTML = x.j.html; note.textContent = x.j.note;   // HTML vem do servidor com todo texto escapado
      }).catch(function () { warn.textContent = 'Falha de rede na prévia.'; });
  }
  function later() { clearTimeout(timer); timer = setTimeout(preview, 250); }
  function move(li, d) { var s = d < 0 ? li.previousElementSibling : li.nextElementSibling; if (!s) return; if (d < 0) list.insertBefore(li, s); else list.insertBefore(s, li); renumber(); later(); li.querySelector('[data-move="' + d + '"]').focus(); }

  list.addEventListener('click', function (e) { var b = e.target.closest('[data-move]'); if (b) move(b.closest('li'), +b.dataset.move); });
  list.addEventListener('dragstart', function (e) { drag = e.target.closest('li'); if (drag) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', drag.dataset.id); } });
  list.addEventListener('dragover', function (e) { var t = e.target.closest('li'); if (!drag || !t || t === drag) return; e.preventDefault(); [].forEach.call(list.children, function (x) { x.classList.remove('drag-over'); }); t.classList.add('drag-over'); });
  list.addEventListener('drop', function (e) { var t = e.target.closest('li'); e.preventDefault(); if (!drag || !t || t === drag) return; var after = drag.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING; list.insertBefore(drag, after ? t.nextSibling : t); renumber(); later(); });
  list.addEventListener('dragend', function () { drag = null; [].forEach.call(list.children, function (x) { x.classList.remove('drag-over'); }); });
  form.addEventListener('change', later); form.addEventListener('input', later);
  ['pv-scenario', 'pv-profile', 'pv-reprint'].forEach(function (id) { document.getElementById(id).addEventListener('change', preview); });
  form.addEventListener('submit', renumber);
  renumber(); preview();
})();
