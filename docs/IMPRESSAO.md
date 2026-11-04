# Impressão de comandas

**Estado:** geração, fila e comunicação testadas por software (modo arquivo). **Impressão física NÃO foi validada** — faltam modelo da impressora, tipo de conexão e sistema operacional do computador da loja. Preencha `PROGRESSO.md` quando souber.

## Duas formas (capacidades diferentes)
1. **Pelo navegador** (qualquer impressora): em *Detalhes do pedido* → *Ver comanda (58/80 mm)* → **Imprimir**. Abre a janela de impressão — **não é silencioso** nem automático.
2. **Automática (agente local):** cada pedido novo gera trabalhos por setor (cozinha/balcão) na fila. Um programa no computador da loja busca a fila e imprime em ESC/POS. É uma conexão **de saída** da loja para o site; a impressora nunca fica exposta na internet.

## Instalar o agente (computador da loja)
1. Instale o PHP 8.2+ (Windows: baixe o zip em php.net, extraia, e use `php.exe`).
2. Copie a pasta `bin/` e `agent.ini.example` → `agent.ini`.
3. No painel: **Impressão → Gerar token do agente** (aparece uma vez) → cole em `agent.ini`.
4. Configure `mode`/`target`:
   - Impressora de rede: `mode = tcp`, `target = IP:9100`
   - USB no Windows: compartilhe a impressora (Painel de Controle → Compartilhar), `mode = share`, `target = \\localhost\NomeCompartilhado`
5. Rode: `php bin/print-agent.php agent.ini` (para iniciar com o Windows, crie uma tarefa agendada "ao fazer logon").
6. No painel, o selo **Impressão: agente online** confirma a conexão.

## Estados dos trabalhos
`Na fila → Imprimindo → Impressa`. Falha antes de enviar: tenta de novo (até 3). Erro **depois** de enviar bytes, ou agente que some por 2 min: **Incerta** — verifique o papel; **Reenviar** pode duplicar. Reimpressões ficam registradas (quem/quando) e saem com "REIMPRESSAO".

## Diagnóstico
- *Agente nunca conectou:* token errado ou URL errada em `agent.ini`; o terminal do agente mostra "401".
- *Fila cresce, agente online:* impressora desligada/IP errado — veja a mensagem de erro na tela Impressão.
- *Acentos:* a comanda sai sem acentos (ASCII) de propósito, para funcionar em qualquer modelo.
- *Largura:* 58 mm = 32 colunas, 80 mm = 48. Ajuste em Configurações → Setores.

## Estações QZ Tray (navegador da loja) — implementado, validado só por software
O usuário confirmou que a solução é o **QZ Tray** (https://qz.io). A integração segue este desenho:

```
cliente/garçom (celular) → servidor → fila persistente (print_jobs)
                                         ↑ reserva atômica (uma estação por trabalho)
PC da loja: navegador logado em /admin/estacao/N  ←→  QZ Tray local (wss://localhost:8181…)  →  impressora
```
Celulares de clientes e garçons **nunca** imprimem direto. Só uma **estação** (navegador autorizado + QZ Tray no mesmo computador) busca trabalhos.

### Destinos
Três destinos lógicos com códigos estáveis: `cozinha`, `copa` (Copa/Bar) e `balcao` (Balcão/Delivery). O destino do item vem do **produto** (`print_sector`) ou da **categoria** (`sector`). Cozinha e copa só recebem os **seus** itens (nunca comanda vazia). O resumo do balcão sai para retirada/entrega. Item sem destino gera **pendência visível** (estado "Sem destino") — nunca é descartado em silêncio.

### Estados (honestos)
`Pendente → Reservado → Enviando → Enviado ao subsistema de impressão` · `Falhou` · `Resultado incerto`. "Enviado ao subsistema" = o QZ/spooler **aceitou** o trabalho; não prova papel na mão de ninguém. Falha **antes** do envio volta à fila (até 3 tentativas); erro **depois** do envio ou reserva sem confirmação = **incerto**, que **nunca** é reenviado sozinho: o operador confere o papel e usa **Reenviar** (auditado). Reservas expiram em 90 s e voltam à fila.

### Configurar (somente o proprietário: permissão `settings`)
1. **Impressão → Estações → Nova estação** (ex.: "Caixa — PC da loja").
2. No computador da loja, instale o QZ Tray, faça login no painel e abra **Abrir como estação**. A página lista as impressoras que o sistema operacional expõe.
3. Em **Estações**, escolha a impressora de cada destino, as colunas (48 = 80 mm; 32 = 58 mm), o charset (`ascii` é o padrão seguro; `cp860` para acentos **só depois de testar no papel**) e o corte.
4. **Imprimir teste (NÃO PRODUZIR)** envia uma comanda de teste marcada. Só faça com a impressora ligada e a loja ciente.

### Assinatura do QZ (certificado)
- A **chave privada fica só no servidor** (`QZ_PRIVATE_KEY_PATH` fora de `public/`, ou `QZ_PRIVATE_KEY` em variável de ambiente). O certificado público é lido de `QZ_CERT_PATH`/`QZ_CERT` e entregue em `/admin/api/qz/certificate` a usuários autenticados. Nada disso vai para o JavaScript, o Git, logs ou ZIP.
- O servidor é um **signer validador**, não assina qualquer coisa: só `printers.find` e `print`; `print` só para o trabalho em estado "Enviando" **desta estação**, na impressora **mapeada**, com bytes cujo SHA-256 é o registrado na reserva; timestamp ±90 s; mensagem idêntica nunca é assinada duas vezes; máx. 120 assinaturas/min por estação; tudo é registrado em `print_sign_log`.
- **Desenvolvimento:** `php bin/qz-dev-cert.php` cria um par **autoassinado** em `storage/qz/` (ignorado pelo Git). O QZ só confia nele se você o importar manualmente no *Site Manager* do QZ — apenas para teste.
- **Produção / impressão silenciosa:** exige um certificado que o **QZ Tray já confie** (certificado emitido pelo portal do QZ, ou o *override* instalado e mantido pela própria loja no computador). **Isto não é fornecido aqui**: depende de contratação/licença (possível custo) e de instalação no computador da loja. Sem isso o QZ mostra uma janela de confirmação a cada impressão (funciona, mas não é silencioso). Não há atalho de "ignorar certificado" nem desativação de TLS.
- Se a chave/certificado não estiverem configurados, o servidor recusa assinar (HTTP 503) e a estação opera em modo manual do QZ.

### Pendências externas (dependem da loja)
Sistema operacional do computador; modelos das 3 impressoras e conexão (USB/rede); nomes das filas no sistema; largura do papel; charset que o modelo aceita; certificado/licença do QZ para impressão silenciosa; autorização para um **teste físico** (comanda "TESTE — NÃO PRODUZIR"). **Nenhuma impressora física foi usada**: o que foi comprovado está em `docs/VALIDACAO-2026-11-04-qz-vercel.md`.

### Diagnóstico da estação
- *QZ desconectado:* confirme o ícone do QZ na bandeja; a página reconecta sozinha (espera crescente com variação). A porta é local (8181/8182 e alternativas); a CSP libera só essas na página da estação.
- *Impressora não aparece:* ela precisa estar instalada no sistema operacional; clique em **Atualizar impressoras**.
- *"Assinatura recusada":* leia o motivo no registro da página e em `print_sign_log` (impressora diferente, conteúdo diferente, repetição, fora da janela).
- *Nada imprime em ambiente de testes:* `APP_ENV=staging` desliga a impressão física, a menos que `PRINT_ENABLED=1`.