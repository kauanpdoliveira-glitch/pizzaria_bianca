# Validação — QZ Tray, destinos de impressão e preparo para Vercel (04/11/2026)

Ponto de recuperação: tag `pre-qz-tray` (commit `5ac5f04`) e cópia `D:\Pizzaria_Bianca-recuperacao-20261104-1622`.

## Classificação das pendências anteriores
| Pendência | Classificação | Situação |
|---|---|---|
| gzip/Brotli não verificados em hospedagem real | **validação externa** | segue aberta; só mede-se na hospedagem/URL real |
| Medições só locais | **validação externa** | idem |
| MySQL não testado | **validação externa** (precisa servidor) | segue aberta; migrações 006/007 escritas para ambos os bancos, só SQLite executado |
| Concorrência só em SQLite/mesmo PC | **validação externa** | agora há reserva atômica por estação, testada em SQLite (um trabalho nunca vai a duas estações); MySQL usa transação + `UPDATE … WHERE status='queued'` (não executado) |
| Teclado virtual/toque/leitor de tela em aparelhos reais | **validação externa** | segue aberta |
| Cobertura incompleta de build por telas/perfis | **defeito reproduzível → corrigido em parte** | rotas/telas novas do painel não entram no build (a tela da estação usa JS fonte, sem minificação); `build-assets --check` OK |
| Impressão física não integrada | **configuração ausente + equipamento** | integrada por software (QZ); falta equipamento/certificado da loja |
| Vercel: runtime/DB/uploads/sessões/env indefinidos | **configuração ausente** | runtime e abordagem definidos, sessões/uploads no banco implementados; **falta login do dono e MySQL externo** (`docs/VERCEL.md`) |

## O que foi implementado (código)
- Destinos estáveis `cozinha`/`copa`/`balcao` por produto/categoria; migração 006 (categorias legadas: `balcao`→`copa`); pendência visível "Sem destino"; sem comanda vazia.
- Fila persistente com `job_key` único (idempotência), reserva temporária por estação, `payload_hash`, estados honestos, reimpressão/reenvio auditados, comanda de teste marcada.
- `PrintFormatter`: ESC/POS por perfil (colunas, charset ascii/cp860/cp850/cp1252, corte), remoção de caracteres de controle (sem injeção de comandos), sem logotipo.
- `PrintStation` + tela **Estações** (somente proprietário configura) + página da estação com `qz-tray.js` oficial 2.3.0 (cópia sem alteração, proveniência e hash em `public/assets/vendor/`), reconexão com backoff exponencial + jitter e guarda de instância única.
- `QzSigner`: signer **validador** (chaves só no servidor), anti-replay, limite de taxa, auditoria; CSP `connect-src` apenas na página da estação.
- Hospedagem: `SESSION_DRIVER=db`, `UPLOAD_DRIVER=db`, `APP_ENV=staging` (faixa, noindex, impressão física desligada), `vercel.json`/`api/index.php` (não validados).

## Evidência (o que de fato foi executado)
| Nível | Evidência |
|---|---|
| **Software (unidade)** | `php tests/run.php`: **78 aprovados, 0 falhas** (66 anteriores + 12 novos: roteamento, sem destino, sanitização ESC/POS, duas estações/reserva, ciclo e incerto, assinatura aceita/recusada, mapa e permissões, comanda de teste, sessões no banco, imagens no banco, ambiente de testes) |
| **HTTP real local (banco temporário, porta 8097)** | `tests/e2e/qz-http.php`: **35 aprovados, 0 falhas** — login por perfil, anônimo/garçom/gerente bloqueados, gerente não cria/mapeia/desativa/testa, CSRF, CSP só na estação, pedido público → 3 trabalhos, reserva, "enviando", assinatura confere com o certificado (`openssl_verify` SHA-512), repetição/adulteração/chamada arbitrária recusadas, chave privada nunca servida. Repetido com `SESSION_DRIVER=db` (35/35) |
| **Navegador + dublê do QZ (porta 8096)** | página da estação no navegador do app com `tests/e2e/qz-stub.js`: conectou, listou 3 impressoras, assinou via servidor, imprimiu 3 trabalhos (cozinha 326 B, copa 321 B, balcão 587 B) e marcou "Enviado ao subsistema"; com falha simulada no `print()` os 3 viraram **incertos**, 1 tentativa, **sem reenvio automático** |
| **QZ Tray real** | **NÃO validado**: não há QZ instalado neste computador. O contrato de assinatura foi implementado a partir do código oficial do `qz-tray.js` (hash SHA-256 do JSON `{call,params,timestamp}` assinado com SHA-512), mas a aceitação pelo QZ Tray real não foi observada |
| **Impressão física** | **NÃO validada** (nenhuma impressora usada, sem comanda de teste impressa) |
| **Operação silenciosa** | **NÃO validada** (exige certificado confiável pelo QZ) |
| **Vercel / MySQL** | **NÃO executados** |

## Lacunas conhecidas e riscos
- O QZ 2.3.0 vendorizado é mais novo que as docs consultadas (até 2.2): comportamento de assinatura pode diferir → validar no QZ real.
- O charset `cp860` e os códigos de página (`ESC t n`) variam por fabricante: **testar no papel** antes de ligar.
- Sessões/imagens no banco reduzem o desempenho em comparação a arquivo; aceitável para teste.
- A reserva de 90 s pode expirar se o QZ demorar a abrir; o trabalho volta à fila (nada foi enviado) — comportamento testado.

## Rodada 2 — impressoras configuráveis pelo painel
**Defeito encontrado e corrigido:** o validador de assinatura recusaria impressões reais, porque o `qz-tray.js` envia em `options` **todos** os padrões (`colorType`, `density`, `margins`, `units`, `forceRaw`…), não só `copies`. Agora cada opção deve ser o padrão da biblioteca ou o valor definido pelo servidor para aquela impressora (cópias/margens no modo HTML); `forceRaw`, `spool`, cópias acima do esperado e chaves desconhecidas são recusados. O dublê de teste passou a reproduzir o objeto real.
**Implementado:** `print_config` (proprietário; gerente só com autorização explícita), cadastro por impressora (fila, fabricante/modelo, USB/rede, modo ESC/POS ou HTML, papel 58/80/A4, colunas, margem, codificação, vias, corte, ativa), estação com identificador, pausa e **armação** (só imprime pedidos após confirmação do operador; recarregar a página desarma; descarte de pendentes antigos), aviso de várias abas, modo HTML (`pixel/html`) para impressoras sem ESC/POS, tela “Fila e erros”.
**Evidência:** `php tests/run.php` **83 aprovados, 0 falhas**; `tests/e2e/qz-http.php` **39 aprovados, 0 falhas** (inclui estação parada, gerente autorizado × não autorizado, opções reais do QZ); navegador + dublê do QZ: estação armada, fila inexistente → falha **antes** do envio (3 tentativas, sem papel), falha de `print()` → incerto, pedido misto em modo HTML → 3 envios `pixel` assinados via servidor (margem 3 mm, vias 1). **Não validado:** QZ real, qualquer impressora física, impressão silenciosa, aceitação do HTML pelo QZ real, telefone na URL publicada (nada publicado).
**Vercel:** ver `docs/VERCEL.md` — o plano gratuito é restrito a uso não comercial; decisão pendente.

## Rodada 3 — QZ real + Epson L3210 (04/11/2026, PC DESKTOP-F330LU5, Windows 10 Pro)
- QZ Tray 2.3.0 (instalador oficial, SHA-256 e assinatura conferidos) instalado pelo usuário; conexão em `ws://localhost:8182`; **autorização manual** do QZ (*Allow*) — **não** é impressão silenciosa; sem certificado confiável.
- Filas listadas pelo QZ: XPS Document Writer, OneNote, Microsoft Print to PDF, **EPSON L3210 Series** (USB001, driver Epson ESC/P-R), Fax. Impressora doméstica de jato de tinta: **modo HTML, A4** (sem ESC/POS, sem corte).
- Um único diagnóstico "TESTE — NÃO PRODUZIR" (job #4) pelo mesmo caminho da aplicação: criado → reservado → `print()` aceito pelo QZ (1 tentativa) → "Enviado ao subsistema". **Saída física confirmada pelo usuário: saiu e está legível.**
- Os 3 trabalhos do pedido #1 permaneceram **retidos** e não foram enviados.
- **Não validado:** ESC/POS e corte em térmica; larguras 58/80 mm; impressão silenciosa; pedido do telefone até o papel; Vercel (plano Hobby inelegível para uso comercial; sem projeto criado).
