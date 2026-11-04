# Teste doméstico guiado — pedido do telefone até a impressora de casa

**Objetivo:** conferir o fluxo completo com a impressora de casa. Isto **não** valida as térmicas da loja.
Antes de tudo: há **uma ou mais páginas impressas**; use papel comum e tenha a impressora ligada e visível.

## Preparação (computador, Windows 10 ou 11)
1. Baixe o **QZ Tray** só do distribuidor oficial (https://qz.io/download) e abra-o (ícone na bandeja). Requisitos de sistema e versões: consulte a página oficial; a documentação consultada lista QZ 2.0–2.2 como compatíveis com a biblioteca de página e não detalha versões de Windows — **confirme na página de download**.
2. Em *Configurações → Impressoras e scanners* do Windows, confira que a impressora aparece e imprime uma página de teste do próprio Windows. Anote o nome da fila.
3. Abra a plataforma publicada (ou o endereço combinado do ambiente de testes) e entre como proprietário.
4. **Configurações → Impressão → Nova estação** (ex.: “Casa — teste”). Abra **Abrir como estação**.
5. **Conectar ao QZ**. Se o QZ mostrar uma janela de permissão, clique *Allow* (é esperado nesta fase; **não** é impressão silenciosa). **Atualizar impressoras**.
6. Volte a **Configurações → Impressão**, escolha a fila nos três destinos (a mesma fila serve), **modo**:
   - Epson doméstica de jato de tinta ou qualquer impressora comum → **Página HTML**, papel **A4** (ou 80 mm se a impressora aceitar).
   - Térmica Epson TM com ESC/POS → **Térmica ESC/POS**, 80 ou 58 mm, codificação `ascii`, corte só se houver guilhotina.
   Salve.
7. Clique **Imprimir teste — Cozinha**: sai **uma** página “TESTE — NÃO PRODUZIR”. Confira texto e acentos. *(Classificação: valida conexão e fluxo; corte/largura/ESC/POS ficam pendentes para a térmica.)*

## Pedido do telefone
8. No telefone (qualquer rede), abra o endereço publicado e faça um pedido fictício (ex.: 1 pizza + 1 refrigerante; nome “Teste”).
9. No computador, na página da estação, clique **Iniciar processamento**; leia a janela (pendentes e páginas) e confirme. Pedido misto = 3 páginas (Balcão, Cozinha, Copa).
10. Confira: o pedido aparece no **Gestor de pedidos**; a fila mostra “Enviado ao subsistema de impressão”; e a página saiu na impressora. **Esse estado não prova papel impresso — a saída física é o que você vê.**

## Variações (faça uma por vez, com poucas páginas)
- **Só bebidas:** saem Copa e Balcão (cozinha não imprime).
- **QZ fechado e reaberto:** feche o QZ; a página mostra “QZ desconectado” e volta sozinha ao reabrir; trabalhos aguardam na fila.
- **Recarregar a página:** a estação volta a “parada” — é preciso **Iniciar processamento** de novo (proposital).
- **Duas abas do gestor / duas abas da estação:** o painel avisa; nada sai duplicado.
- **Nova rodada na mesma comanda** (garçom): só os itens novos geram comanda.
- **Falha parcial:** desligue a impressora/pause a fila do Windows durante um pedido: o destino que falhar fica “Falhou” ou “Resultado incerto” sem afetar os outros nem o pedido. Use **Reenviar** só depois de conferir o papel.

## O que registrar
Pedido salvo (sim/não) · trabalhos criados por destino · estado final de cada trabalho · saída física (sim/não, por destino) · houve janela de confirmação do QZ (sim/não) · o que não funcionou.

## Como o ambiente de teste é aberto para o telefone (túnel temporário)
O app roda **no seu computador** e um túnel gratuito da Cloudflare (*Quick Tunnel*, `cloudflared`) dá ao telefone uma URL `https://….trycloudflare.com` enquanto a janela ficar aberta. Não é hospedagem: se o PC desligar, a URL deixa de funcionar e muda na próxima execução. Quick Tunnels são para testes (sem garantia de disponibilidade).

```powershell
powershell -ExecutionPolicy Bypass -File bin\teste-tunel.ps1
```
O script usa banco próprio (`storage\teste-tunel.sqlite`, fora do Git), mostra **uma vez** a senha do proprietário de teste, abre a loja 24 h **só nesse banco**, liga a faixa "AMBIENTE DE TESTES" + `noindex` e `PRINT_ENABLED=1` (impressão física habilitada de propósito). Requer PHP 8.2+ no PATH e o `cloudflared.exe` oficial (https://github.com/cloudflare/cloudflared/releases; confira o SHA-256 da versão). **Faça o login no painel pelo computador** (`http://127.0.0.1:8080/equipe`) e deixe o telefone só para o pedido.
Limites: todos os acessos chegam ao app como `127.0.0.1` (o limite de tentativas de login é compartilhado); a URL é pública enquanto estiver aberta — só dados fictícios.
