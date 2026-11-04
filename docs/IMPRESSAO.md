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

### Configurar — Configurações → Impressão (`/admin/config/impressao`)
Quem configura: o **proprietário** e, somente se ele marcar a permissão **“Configurar impressoras e estações”** (`print_config`) no cadastro do gerente, esse gerente. Garçom nunca. Operar a estação (abrir a página, iniciar/parar, reenviar) exige apenas `print`.
1. **Nova estação** (nome e identificador próprio, ex.: `loja-caixa`). O identificador é único; a impressora é sempre associada a **(estação, destino)** — o mesmo nome de fila em outro computador não é o mesmo equipamento.
2. No computador, abra **Abrir como estação**, clique **Conectar ao QZ** e **Atualizar impressoras**: as filas do Windows são **listadas pelo QZ** (não precisa digitar).
3. Para cada destino (Cozinha, Copa/Bar, Balcão/Delivery) preencha: fila, nome de identificação, fabricante/modelo, conexão (USB ou rede **instalada como fila no Windows**), **modo** (Térmica ESC/POS ou Página HTML), papel (58, 80 mm ou A4 só em HTML), largura útil (colunas), margem, codificação, vias, corte (somente se houver guilhotina; ignorado em HTML), ativa/inativa. **Salvar configuração.**
4. **Imprimir teste — destino** enfileira **uma** página “TESTE — NÃO PRODUZIR” (com destino e vias esperadas). O clique autoriza só aquele teste; a estação não precisa estar processando pedidos.
5. **Iniciar processamento** (na página da estação) é a confirmação de que computador e impressoras estão prontos; mostra a quantidade de pendentes e de páginas e permite **descartar pedidos antigos** (> 10 min). Ao recarregar a página a estação volta a ficar **parada**. O administrador pode **Pausar** a qualquer momento.
6. Duas abas da mesma estação: a reserva atômica impede duplicar; o painel avisa “N abas abertas”.

Comunicação direta por IP/porta (sem fila do Windows) **não está implementada**; o campo Observações guarda o IP só como informação.

### Perfis (modo)
- **Térmica ESC/POS:** comandos brutos (base64). Só para impressoras que aceitam ESC/POS (série Epson TM é típica, mas **cada modelo precisa ser testado**; marca não garante compatibilidade). Codificação `ascii` é o padrão seguro; `cp860` para acentos só depois de testar no papel.
- **Página HTML:** envia uma página pelo QZ (impressão por driver, `pixel/html`). É o caminho para Epson doméstica (jato de tinta) e outras sem ESC/POS. **Nunca** se mandam comandos brutos a uma impressora configurada em HTML. Esse teste valida **conexão e fluxo**; corte, largura de bobina e ESC/POS seguem pendentes até testar na térmica correta.
### Assinatura do QZ (certificado)
- A **chave privada fica só no servidor** (`QZ_PRIVATE_KEY_PATH` fora de `public/`, ou `QZ_PRIVATE_KEY` em variável de ambiente). O certificado público é lido de `QZ_CERT_PATH`/`QZ_CERT` e entregue em `/admin/api/qz/certificate` a usuários autenticados. Nada disso vai para o JavaScript, o Git, logs ou ZIP.
- O servidor é um **signer validador**, não assina qualquer coisa: só `printers.find` e `print`; `print` só para o trabalho em estado "Enviando" **desta estação**, na impressora **mapeada**, com bytes cujo SHA-256 é o registrado na reserva; timestamp ±90 s; mensagem idêntica nunca é assinada duas vezes; máx. 120 assinaturas/min por estação; tudo é registrado em `print_sign_log`.
- **Fase de testes sem certificado próprio (a situação atual):** o QZ Tray abre uma **janela de confirmação própria** a cada operação protegida; clicar *Allow* é a **autorização manual esperada**. Isto **não é impressão silenciosa** e o sistema nunca a registra como tal. Nada é desativado (nem validação de certificado, nem segurança do navegador).
- **Certificado de demonstração do QZ (opcional, só testes):** no QZ Tray, *Advanced → Site Manager → “+”* gera um certificado de demonstração que **só vale no computador onde foi gerado** (documentação oficial: https://qz.io/docs/signing). Serve para exercitar o fluxo de assinatura; não é para a loja.
- **Silencioso (futuro):** segundo a documentação oficial, exige (1) um certificado `digital-certificate.txt` (x509) **em que o QZ Tray confie** e publicamente acessível, (2) a chave `private-key.pem` em **PKCS#8 2048 bits**, e (3) as duas funções `setCertificatePromise` e `setSignaturePromise` definidas (já implementadas aqui). O arquivo **`override.crt`** (pasta de instalação do QZ desde 2.1; ou `-DtrustedRootCert`; ou `authcert.override` em `qz-tray.properties`) apenas informa **qual certificado raiz o QZ deve tratar como confiável**: **não** assina nada e **não** torna o ambiente confiável por si só — é preciso que o certificado do servidor encadeie nessa raiz e que a chave correspondente esteja no servidor. Obter/gerar essa cadeia (portal do QZ ou autoridade própria) é decisão e possível custo da loja; **nada foi contratado**.
- `php bin/qz-dev-cert.php` cria um par **autoassinado de desenvolvimento** em `storage/qz/` (ignorado pelo Git). O QZ só confia nele se você o configurar manualmente; use apenas em testes locais. Quando ativado, a estação mostra “ativa (silencioso só se o QZ confiar no certificado)”.- Se a chave/certificado não estiverem configurados, o servidor recusa assinar (HTTP 503) e a estação opera em modo manual do QZ.

### Pendências externas (dependem da loja)
Sistema operacional do computador; modelos das 3 impressoras e conexão (USB/rede); nomes das filas no sistema; largura do papel; charset que o modelo aceita; certificado/licença do QZ para impressão silenciosa; autorização para um **teste físico** (comanda "TESTE — NÃO PRODUZIR"). **Nenhuma impressora física foi usada**: o que foi comprovado está em `docs/VALIDACAO-2026-11-04-qz-vercel.md`.

### Diagnóstico da estação
- *QZ desconectado:* confirme o ícone do QZ na bandeja; a página reconecta sozinha (espera crescente com variação). A porta é local (8181/8182 e alternativas); a CSP libera só essas na página da estação.
- *Impressora não aparece:* ela precisa estar instalada no sistema operacional; clique em **Atualizar impressoras**.
- *"Assinatura recusada":* leia o motivo no registro da página e em `print_sign_log` (impressora diferente, conteúdo diferente, repetição, fora da janela).
- *Nada imprime em ambiente de testes:* `APP_ENV=staging` desliga a impressão física, a menos que `PRINT_ENABLED=1`.
## Regras de distribuição das comandas (entrega e retirada)
- **Balcão:** comanda **completa** — número, data/hora, ENTREGA ou RETIRADA em destaque, nome e telefone, endereço/bairro/referência **só na entrega**, todos os itens com tamanho, sabores, bordas, adicionais e retiradas (meia a meia indica o sabor), observações, subtotal, desconto, taxa, total, forma de pagamento e **PAGAMENTO PENDENTE/CONFIRMADO/ESTORNADO** (forma escolhida ≠ pagamento recebido), troco.
- **Cozinha:** só os itens do destino, sem valores, endereço ou telefone; observações em destaque (`>>> OBSERVAÇÃO`).
- **Copa/Bar:** só os itens do destino (bebidas), sem dados pessoais.
- **Setor por categoria** e **exceção por produto** (a do produto vence). **Sem destino definido** (ex.: Sobremesas): o item sai só no balcão e aparece como **pendência**; nada é presumido nem descartado.
- **Nunca comanda vazia.** Exemplos (1 via): pizza+refri → balcão completo, cozinha pizza, copa refri · só pizza → balcão + cozinha · só refri → balcão + copa · pizza+porção+refri → balcão completo, cozinha pizza e porção, copa refri.
- **Vias** (1–5, número inteiro) são configuradas **por destino** (Configurações → Impressão → Destinos) e aplicadas **uma única vez**: copiadas para o trabalho no nascimento (`copies`, `snap_json`) e executadas pela estação (ESC/POS repete os bytes; HTML usa a opção `copies` do QZ — sem multiplicar trabalhos). Balcão 2 + cozinha 1 + copa 1 = 4 comandas. Vias ≠ páginas: uma comanda longa pode ocupar mais de uma página. Mudar a configuração depois **não** altera trabalhos já criados. Para suspender um destino: **Ligado** desmarcado; **Impressão automática** desmarcada retém os trabalhos até liberação.
- **Evento de impressão:** a criação de um pedido **válido** pelo site (`Orders::persist`, na **mesma transação** que grava o pedido) é o único evento que gera os trabalhos (`job_key` único: clique repetido, atualizar página, 2 abas ou reconexão não geram outra via). Status, pagamento e abrir o painel **não** imprimem. Não existe pagamento online neste sistema; a regra atual é imprimir ao receber (a aceitação pela loja não bloqueia a impressão). **Cancelamento:** o que ainda não saiu é descartado; o que já foi enviado recebe um aviso **PEDIDO CANCELADO — NÃO PRODUZIR**. **Alteração de itens** gera comanda marcada **PEDIDO ALTERADO**.
- **Reimpressão** por destino exige **motivo** e registra operador e data/hora; a folha sai com **REIMPRESSÃO**, operador e motivo.
- **Ambiente de testes** (`APP_ENV=staging`): toda folha traz **TESTE — NÃO PRODUZIR**.
