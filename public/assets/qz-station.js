/* Estação de impressão: conecta ao QZ Tray local, busca trabalhos reservados no servidor e envia à impressora mapeada.
 * - Sem chave privada aqui: a assinatura é pedida ao servidor (que valida o pedido e só então assina).
 * - Uma instância por página (guarda global), reconexão com backoff exponencial + jitter, sem ouvintes duplicados.
 * - Só consome pedidos depois que o operador CONFIRMA ("Iniciar processamento"); ao recarregar a página volta a ficar parada.
 * - Estado honesto: "ok" só quando o QZ aceitou o envio; erro depois do print() vira resultado incerto (nunca reenvio automático). */
(function () {
  'use strict';
  var root = document.getElementById('qzst');
  if (!root || window.__biancaStation) return;
  window.__biancaStation = true;
  var SID = root.dataset.station, CSRF = root.dataset.csrf, ACTIVE = root.dataset.active === '1';
  var TAB = Math.random().toString(36).slice(2, 12);
  var $ = function (id) { return document.getElementById(id); };
  var logEl = $('st-log');
  var state = { connecting: false, busy: false, attempts: 0, printers: [], plain: Object.create(null), armed: false, paused: root.dataset.paused === '1' };

  function log(msg) {
    var li = document.createElement('li'); li.textContent = new Date().toLocaleTimeString('pt-BR') + ' — ' + msg;
    logEl.insertBefore(li, logEl.firstChild); while (logEl.children.length > 40) logEl.removeChild(logEl.lastChild);
  }
  function api(path, body, method) {
    return fetch('/admin/api/estacao/' + SID + path, { method: method || 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error(j.error || ('HTTP ' + r.status)); e.status = r.status; throw e; } return j; }); });
  }
  function delay(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function backoff(n) { var base = Math.min(60000, 1000 * Math.pow(2, Math.min(n, 6))); return base / 2 + Math.random() * base / 2; }
  function render() {
    $('st-armed').textContent = state.armed ? 'PROCESSANDO pedidos' : 'parado (aguardando confirmação)';
    $('st-arm').hidden = state.armed; $('st-disarm').hidden = !state.armed;
  }

  // ---- segurança QZ: certificado público e assinatura validada no servidor ----
  // Modo MANUAL (sem certificado/chave no servidor): NÃO registra certificado nem assinatura; o QZ Tray trata o site como não confiável e pede a confirmação do operador.
  // Só com par válido configurado no servidor (data-signing=1) o navegador pede a assinatura ao servidor.
  if (document.getElementById('qzst').dataset.signing === '1') {
    qz.security.setCertificatePromise(function (resolve) {
      fetch('/admin/api/qz/certificate', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }).then(resolve, function () { resolve(''); });
    });
    qz.security.setSignatureAlgorithm('SHA512');
    // o qz-tray.js assina o SHA-256 do JSON; guardamos o JSON original para o servidor validar e calcular o mesmo hash
    qz.api.setSha256Type(function (data) {
      return crypto.subtle.digest('SHA-256', new TextEncoder().encode(data)).then(function (buf) {
        var hex = Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        state.plain[hex] = data; return hex;
      });
    });
    qz.security.setSignaturePromise(function (toSign) {
      return function (resolve, reject) {
        var message = state.plain[toSign]; delete state.plain[toSign];
        if (!message) { reject(new Error('Conteúdo a assinar desconhecido.')); return; }
        api('/sign', { message: message }).then(function (j) { resolve(j.signature); }, function (e) { log('Assinatura recusada: ' + e.message); reject(e); });
      };
    });
  }

  function setQz(ok, extra) { $('st-qz').textContent = ok ? 'conectado' : 'desconectado'; $('st-qzver').textContent = extra || ''; }

  function connect() {
    if (state.connecting || qz.websocket.isActive()) return Promise.resolve();
    state.connecting = true;
    return qz.websocket.connect({ retries: 0, delay: 0 }).then(function () {
      state.attempts = 0; return qz.api.getVersion().catch(function () { return ''; });
    }).then(function (v) { state.version = v; setQz(true, v ? 'v' + v : ''); log('QZ Tray conectado. Se aparecer uma janela do QZ pedindo permissão, a confirmação é manual (modo de teste).'); return listPrinters(); })
      .catch(function (e) { state.attempts++; setQz(false); log('QZ indisponível (' + (e && e.message ? e.message : e) + '); nova tentativa em breve.'); })
      .then(function () { state.connecting = false; });
  }
  function listPrinters() {
    return qz.printers.find().then(function (l) { state.printers = [].concat(l || []); $('st-printers').textContent = state.printers.length ? state.printers.join(' · ') : 'nenhuma'; return beat(); })
      .catch(function (e) { log('Não foi possível listar impressoras: ' + (e && e.message ? e.message : e)); });
  }
  function beat() {
    return api('/heartbeat', { qz: qz.websocket.isActive(), version: state.version || '', printers: state.printers, tab: TAB }).then(function (j) {
      $('st-srv').textContent = 'conectado'; $('st-tabs').textContent = j.tabs > 1 ? j.tabs + ' abas desta estação abertas (seguro: a reserva evita duplicar; feche as extras)' : '1 aba';
      state.paused = !!j.paused; $('st-paused').textContent = state.paused ? 'PAUSADA pelo administrador' : 'ativa';
    }, function (e) { $('st-srv').textContent = 'erro: ' + e.message; });
  }

  function printData(job) {
    return job.mode === 'html' ? [{ type: 'pixel', format: 'html', flavor: 'plain', data: job.payload }] : [{ type: 'raw', format: 'command', flavor: 'base64', data: job.payload }];
  }
  function runJob(job) {
    state.busy = true; $('st-job').textContent = job.kind + ' · ' + job.sector + ' → ' + job.printer + ' (' + job.mode + ')';
    var done = function () { state.busy = false; $('st-job').textContent = 'nenhum'; };
    if (state.printers.length && state.printers.indexOf(job.printer) < 0) {   // antes de enviar: nada foi mandado, o servidor recoloca na fila
      log('Fila "' + job.printer + '" não encontrada neste computador.');
      return api('/result/' + job.uuid, { ok: false, sent: false, error: 'Fila de impressão não encontrada na estação' }).catch(function () {}).then(function () { done(); return delay(5000); });
    }
    return api('/sending/' + job.uuid).then(function () {
      var cfg = qz.configs.create(job.printer, job.options || {});
      return qz.print(cfg, printData(job)).then(function () {
        log('Enviado ao subsistema de impressão (' + job.sector + '). Isto não prova que o papel saiu.');
        return api('/result/' + job.uuid, { ok: true, sent: true });
      }, function (e) {
        var m = e && e.message ? e.message : String(e);
        log('Falha no envio: ' + m + ' — resultado incerto; confira o papel.');
        return api('/result/' + job.uuid, { ok: false, sent: true, error: m });   // depois do print(): incerto, nunca reenvio automático
      });
    }, function (e) { log('Reserva não confirmada: ' + e.message); return delay(3000); }).catch(function (e) { log('Erro: ' + e.message); }).then(done);
  }

  function loop() {
    var next = 3000, p = Promise.resolve();
    if (ACTIVE && !state.busy) {
      if (!qz.websocket.isActive()) { p = connect(); next = backoff(state.attempts); }
      else p = api('/claim').then(function (r) { return r.job ? runJob(r.job) : null; }).catch(function (e) { log('Servidor: ' + e.message); if (e.status === 401 || e.status === 419) { state.stopped = true; log('Sessão expirada: entre de novo e reabra a estação.'); } next = backoff(++state.attempts); });
    }
    p.then(function () { if (!state.stopped) setTimeout(loop, next); });
  }

  // ---- armar/desarmar: confirmação explícita do operador ----
  $('st-arm').addEventListener('click', function () {
    api('/arm-preview').then(function (pv) {
      var txt = 'Confirme antes de iniciar:\n• O computador está ligado, com o QZ Tray aberto e as impressoras ligadas, com papel.\n• Pedidos pendentes agora: ' + pv.pending + ' (≈ ' + pv.pages + ' página(s)).';
      var drop = false;
      if (pv.old > 0) { drop = !window.confirm(txt + '\n• Há ' + pv.old + ' pendente(s) com mais de ' + pv.stale_minutes + ' min.\n\nImprimir TAMBÉM os antigos?  OK = imprimir tudo · Cancelar = descartar os antigos e iniciar.'); }
      else if (!window.confirm(txt + '\n\nIniciar o processamento?')) return;
      return api('/control', { action: 'arm', discard_old: drop }).then(function (j) { state.armed = true; render(); log('Processamento iniciado por você.' + (j.dropped ? ' Descartados ' + j.dropped + ' antigos.' : '')); });
    }).catch(function (e) { log('Não foi possível iniciar: ' + e.message); });
  });
  $('st-disarm').addEventListener('click', function () { api('/control', { action: 'disarm' }).then(function () { state.armed = false; render(); log('Processamento parado.'); }); });
  $('st-refresh').addEventListener('click', function () { if (qz.websocket.isActive()) listPrinters(); else connect(); });
  $('st-connect').addEventListener('click', function () { state.attempts = 0; connect(); });

  qz.websocket.setClosedCallbacks(function () { setQz(false); log('QZ Tray desconectou.'); });
  setInterval(function () { if (ACTIVE) beat(); }, 15000);
  if (!ACTIVE) log('Estação desativada.');
  // recarregar a página sempre volta a "parado": ninguém começa a imprimir sem confirmar de novo
  api('/control', { action: 'disarm' }).catch(function () {}).then(function () { render(); loop(); });
})();
