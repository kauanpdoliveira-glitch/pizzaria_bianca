# Sistema de movimento

Código: regras CSS no **fim de `public/assets/site.css`** (bloco "Movimento"), lógica no **fim de `public/assets/app.js`** (IIFE "Movimento do site"); blocos curtos "Movimento funcional" no fim de `garcom.css` e `admin.css`. Sem biblioteca. (Antes havia `motion.css`/`motion.js`; foram unidos para reduzir requisições — ver validação.)

**Tokens** (`:root`): `--t-micro` 160 ms · `--t-ui` 240 ms · `--t-in` 520 ms · `--dist` 18 px · `--ease` cubic-bezier(.22,.61,.36,1). Só `transform` e `opacity`.

## Onde há movimento
| Onde | Efeito | Como |
|---|---|---|
| Hero | texto/botões/selo sobem 12 px em sequência (60 ms de passo, 480 ms); a foto assenta; "Fazer pedido" dá 2 pulsos | só CSS. **O texto nunca começa com opacidade 0**: medido, a opacidade inicial 0 atrasou o LCP de 1,2 s para 2,4 s em rede lenta |
| Hero (≥ 900 px) | foto sobe até 24 px ao rolar | 1 rAF por frame, só com o hero visível |
| Seções | títulos, categorias, números, faixa vermelha, texto/foto e rodapé aparecem **uma vez** ao entrar | IntersectionObserver; só o que está abaixo da 1ª dobra é escondido, e só por JS |
| História | foto/texto entram por lados opostos; cada data da linha do tempo "acende" | idem |
| Categorias/fotos | aproximação de 5% no hover/foco (só mouse) | `@media (hover:hover)` |
| Cabeçalho/menu móvel | sombra ao rolar; menu abre/fecha com transição; Esc, clique fora e escolha de link fecham | |
| Compra | janela desliza; etapas da pizza trocam em 200 ms; contador da sacola "pulsa" ao adicionar | |
| Gestão / garçom | transições curtas, resposta imediata ao toque; destaque de pedido novo já existente; nada reordena sozinho | |

## Regras de segurança do efeito
- `prefers-reduced-motion: reduce`: sem animações decorativas nem paralaxe; o JS de revelação nem inicia; nada fica escondido. Se o usuário ativar a preferência com a página aberta, tudo é revelado.
- Falha/ausência do script: nada fica escondido (o estado `.mo-pre` só existe depois de o JS aplicá-lo; rede de segurança de 1,8 s; foco por teclado revela na hora).
- Impressão (`@media print`): nada escondido. Cache de voltar/avançar (bfcache): o que faltava é revelado ao sair da página.
- Leitura de layout em lote (sem escrever entre medições) e `pagehide` remove observadores/listeners.
- Sucesso só depois da resposta do servidor: nenhum efeito foi ligado a "pedido enviado"/"pago".

## Correções visuais/estabilidade feitas junto
Menu móvel que abria vazio · base cortada da foto circular · buraco na última linha da grade de categorias · deslocamento de layout no cardápio (CLS 0,54 → 0,02) · rolagem horizontal criada pelas revelações · alvos de toque < 44 px · acompanhamento do pedido que não atualizava (`fill is not defined`). Detalhes e evidências: `docs/VALIDACAO-2026-11-04.md`.
