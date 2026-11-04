/* DUBLÊ do qz-tray.js para testar a página da estação SEM QZ Tray instalado (nunca usado em produção).
 * Reproduz o contrato que a estação usa: hash do JSON {call,params,timestamp} → pedido de assinatura → print().
 * Resultado em window.__qzStub (chamadas, assinaturas) para a verificação. Falha controlável por localStorage.stubFail = 'print' | 'connect'. */
(function () {
  var S = window.__qzStub = { prints: [], signs: [], connects: 0, listeners: 0 };
  var hasher = null, certFactory = null, sigFactory = null, active = false, closed = null;
  function sign(msgObj) {
    var json = JSON.stringify(msgObj);
    return Promise.resolve(hasher(json)).then(function (hex) {
      return new Promise(function (res, rej) { var f = sigFactory(hex); f(res, rej); }).then(function (sig) { S.signs.push({ call: msgObj.call, sig: sig, hash: hex, json: json }); return sig; });
    });
  }
  window.qz = {
    api: { setSha256Type: function (f) { hasher = f; }, getVersion: function () { return Promise.resolve('2.2.4-stub'); } },
    security: { setCertificatePromise: function (f) { certFactory = f; }, setSignaturePromise: function (f) { sigFactory = f; }, setSignatureAlgorithm: function () {} },
    websocket: {
      connect: function () { S.connects++; if (localStorage.stubFail === 'connect') return Promise.reject(new Error('stub: QZ fora do ar')); active = true; return new Promise(function (r) { certFactory(function (c) { S.cert = c; r(); }); }); },
      isActive: function () { return active; },
      setClosedCallbacks: function (f) { S.listeners++; closed = f; }
    },
    printers: { find: function () { return sign({ call: 'printers.find', params: {}, timestamp: Date.now() }).then(function () { return ['Cozinha-USB', 'Bar-USB', 'Balcao-LAN']; }); } },
    configs: { create: function (name) { return { getPrinter: function () { return { name: name }; }, getOptions: function () { return { copies: 1 }; } }; } },
    print: function (cfg, data) {
      var msg = { call: 'print', params: { printer: cfg.getPrinter(), options: cfg.getOptions(), data: data }, timestamp: Date.now() };
      return sign(msg).then(function () {
        if (localStorage.stubFail === 'print') return Promise.reject(new Error('stub: spooler recusou'));
        S.prints.push({ printer: cfg.getPrinter().name, bytes: atob(data[0].data).length });
      });
    }
  };
})();
