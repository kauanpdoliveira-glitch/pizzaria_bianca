# Roteiro curto de validação externa (o que o ambiente de desenvolvimento NÃO consegue testar)

Registre **Aprovado / Falhou / Não executado** por item, com aparelho, sistema e navegador. Não marque aprovado o que não foi usado de fato.

## 1. Celular real (Android e iPhone) — toque e teclado virtual
1. Abrir o site no Wi-Fi e em 4G. Cardápio → pizza → tamanho → 2 sabores → borda → **Adicionar** → sacola.
2. **Checkout:** tocar em *Nome*, *Telefone*, *Rua*, *Observações*: o campo ativo e o botão **Enviar pedido** não podem ficar escondidos pelo teclado; rolar a página com o teclado aberto.
3. Alvos de toque confortáveis (44 px): menu, abas do cardápio, quantidades (+/−), fechar a janela.
4. Girar o aparelho com a montagem da pizza aberta; ampliar o texto do sistema em 200%.
5. **Garçom (`/garcom`):** abrir mesa (nome e pessoas), lançar itens, pedir a conta — com o teclado numérico/texto aberto.
## 2. Leitor de tela (NVDA + Firefox/Chrome; VoiceOver iOS; TalkBack Android)
Ler: landing (títulos, botões), montagem da pizza (anúncio de tamanho/sabores/máximo de 2), checkout (**mensagens de erro** de cada campo), acompanhamento (atualização do status), login da equipe e `/garcom` (nome dos botões ▲▼ e do menu). Anotar o que é lido sem sentido ou omitido.
## 3. Dois aparelhos/redes reais ao mesmo tempo
Dois celulares em redes diferentes: (a) abrir a **mesma mesa**; (b) **mesma comanda física**; (c) dois operadores alterando o **mesmo pedido** (aceitar × cancelar); (d) pagamento duplicado; (e) fechar o atendimento antigo enquanto o garçom abre outro grupo. Esperado: um vence, o outro recebe aviso claro; mesa continua ocupada pelo atendimento novo.
## 4. Serviços externos
MySQL real (migrações em banco **vazio** primeiro), e-mail (`MAIL_DRIVER`), cabeçalhos de compressão/cache na hospedagem, impressora (solução "QZ3" a confirmar) — somente com autorização e sem impressão de pedidos reais.
