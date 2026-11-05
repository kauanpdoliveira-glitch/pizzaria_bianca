'use strict';
/* Cliente mínimo do Supabase Realtime (protocolo Phoenix) para o canal PRIVADO de avisos "houve alteração".
   Não recebe dados de pedidos: cada aviso só dispara uma consulta à API autenticada do painel. Credenciais vêm de /admin/api/realtime
   (token curto de um usuário técnico; a chave publicável é pública por desenho; nenhuma chave secreta chega ao navegador). */
window.BiancaRealtime = (() => {
  let ws = null, hb = null, rt = null, refreshT = null, ref = 0, fails = 0, stopped = true, cfg = null, joined = false, cb = {};
  const send = m => { if (ws && ws.readyState === 1) ws.send(JSON.stringify(m)); };
  const state = s => { try { cb.onState && cb.onState(s); } catch (e) { /* ignora */ } };
  async function creds() {
    const r = await fetch('/admin/api/realtime', { credentials: 'same-origin', cache: 'no-store' });
    if (!r.ok) throw new Error('credenciais ' + r.status); const d = await r.json(); if (!d.enabled) return null; return d;
  }
  function clearTimers() { clearInterval(hb); clearTimeout(refreshT); hb = refreshT = null; }
  function scheduleRefresh() {
    clearTimeout(refreshT); const ms = Math.max(30000, (cfg.exp * 1000 - Date.now()) - 300000); // renova 5 min antes de vencer
    refreshT = setTimeout(async () => { try { const d = await creds(); if (d) { cfg = d; send({ topic: 'realtime:' + cfg.topic, event: 'access_token', payload: { access_token: d.token }, ref: String(++ref) }); scheduleRefresh(); } } catch (e) { if (ws) ws.close(); } }, ms);
  }
  function reconnect() { clearTimers(); joined = false; state('down'); if (stopped) return; const d = Math.min(30000, 1000 * 2 ** Math.min(fails++, 5)) + Math.random() * 500; clearTimeout(rt); rt = setTimeout(connect, d); }
  async function connect() {
    if (stopped) return;
    try { cfg = await creds(); } catch (e) { return reconnect(); }
    if (!cfg) { state('off'); return; } // não configurado: só polling
    try { ws = new WebSocket(cfg.url + '?apikey=' + encodeURIComponent(cfg.key) + '&vsn=1.0.0'); } catch (e) { return reconnect(); }
    ws.onopen = () => {
      send({ topic: 'realtime:' + cfg.topic, event: 'phx_join', payload: { config: { broadcast: { ack: false, self: false }, presence: { key: '' }, postgres_changes: [], private: true }, access_token: cfg.token }, ref: String(++ref), join_ref: '1' });
      hb = setInterval(() => send({ topic: 'phoenix', event: 'heartbeat', payload: {}, ref: String(++ref) }), 25000);
    };
    ws.onmessage = ev => {
      let m; try { m = JSON.parse(ev.data); } catch (e) { return; }
      if (m.event === 'phx_reply' && m.topic === 'realtime:' + cfg.topic) {
        if (m.payload && m.payload.status === 'ok') { joined = true; fails = 0; state('up'); scheduleRefresh(); if (cb.onResync) cb.onResync(); } // reconectou: busca o que ficou para trás
        else { joined = false; state('denied'); try { ws.close(); } catch (e) { /* ok */ } }
      } else if (m.event === 'broadcast' && m.payload && m.payload.event === 'changed') { if (cb.onPing) cb.onPing(); }
      else if (m.event === 'phx_error' || m.event === 'phx_close') { try { ws.close(); } catch (e) { /* ok */ } }
    };
    ws.onclose = () => { ws = null; reconnect(); };
    ws.onerror = () => { /* onclose cuida da reconexão */ };
  }
  function start(callbacks) { cb = callbacks || {}; if (!stopped) return; stopped = false; fails = 0; connect(); }
  function stop() { stopped = true; clearTimers(); clearTimeout(rt); joined = false; if (ws) { try { ws.close(); } catch (e) { /* ok */ } ws = null; } state('off'); }
  window.addEventListener('pagehide', stop);
  return { start, stop, isUp: () => joined };
})();
