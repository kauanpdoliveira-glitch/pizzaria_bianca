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

## Integração futura: solução "QZ3" (a confirmar)
O usuário informou que pretende integrar uma solução de impressão chamada **"QZ3"**. **Não se presume** produto, versão, forma de instalação (aplicativo local, serviço, navegador) nem protocolo. Antes de implementar: confirmar o nome e a versão exatos, como ele recebe trabalhos (WebSocket local, HTTP, driver), requisitos de certificado/assinatura e o modelo da impressora. Pontos de integração existentes e estáveis: tabela `print_jobs` (conteúdo ESC/POS por setor), API do agente (`POST /api/print/claim`, `POST /api/print/{uuid}/result`, token Bearer) e a tela **Impressão** do painel. Nenhuma impressora real foi conectada.
