# Relatório de validação — animações, interfaces, perfis e desempenho

Data: 04/11/2026 (UTC−03:00, Brasília). Ambiente: Windows 10 Pro, PHP 8.3.36, SQLite (desenvolvimento), Chrome 156 (headless, perfil descartável) via DevTools Protocol, mais o painel embutido do app e o Chrome do usuário para inspeção visual. **Nada foi publicado em produção; nenhuma cobrança, mensagem a cliente ou impressão real.** Servidores de teste em outras portas, com **cópia** do banco.

Legenda: **Aprovado** (executado e passou) · **Falhou→corrigido** · **Simulado** (emulação, não o ambiente real) · **Não executado** · **Bloqueado** (precisa de recurso externo).

## 1. Condições de movimento — o que é real e o que é emulado
| Condição | Natureza | Resultado |
|---|---|---|
| Preferência **natural** do ambiente | **Real**: `prefers-reduced-motion: reduce` é o que o Windows/Chrome informam aqui (efeitos de animação desligados no sistema). Confirmado no Chrome headless, no painel embutido e no Chrome do usuário | Aprovado: sem animações, nada escondido, conteúdo imediato, CTA funciona |
| `no-preference` | **Simulado** (`Emulation.setEmulatedMedia`); o sistema real não oferece essa condição neste PC | Aprovado: hero anima (`mo-up`), 20 blocos revelados ao rolar, nenhum fica escondido, nenhuma animação contínua após 5 s, clique no CTA durante a transição navega |
| `reduce` | **Simulado** (mesmo mecanismo) | Aprovado: igual à natural |
| Falha do script (`app.js` bloqueado) | Simulado | Aprovado: nada com opacidade < 1, CTA visível |
| JavaScript desativado | Simulado | Aprovado: conteúdo visível |
| Impressão | Simulado (`media=print`) | **Falhou→corrigido**: 20 blocos ficavam escondidos; regra `@media print` |
| Rolagem lenta/rápida e retorno ao topo | Real (rolagem programática no Chrome) | Aprovado: 0 blocos escondidos |
| Navegação repetida (7 trocas `/`↔`/historia`↔`/cardapio`) | Real | Aprovado: 1 `app.js` por página; mesmas animações a cada visita (sem acúmulo) |
| Cache de voltar/avançar (bfcache) | — | **Não executado**: tratado em código (`pagehide` revela o que faltava), sem teste automatizado |

Evidência: `docs/validacao/motion-resultado.json`, `tests/e2e/verify-motion.php`.

## 2. Defeitos encontrados nesta etapa (e correção)
| # | Defeito (reproduzido) | Origem | Correção | Reteste |
|---|---|---|---|---|
| 1 | Acompanhamento do pedido **não atualizava**: `ReferenceError: fill is not defined` | Regressão da entrega anterior (helper local) | `fill` movido para o escopo do módulo | texto do pedido #16 renderiza; 0 exceções |
| 2 | Impressão deixava 20 blocos invisíveis | Efeito de revelação | `@media print{.mo-pre{opacity:1!important…}}` | `hidden: 0` |
| 3 | **CLS 0,54** no cardápio (rodapé "pulava" quando o menu era montado) | Pré-existente (menu montado por JS) | `.menu-wrap{min-height:100vh}` | CLS 0,02 |
| 4 | Rolagem horizontal de 9 px em ≤ 1024 px (home/história) | Meu: deslocamento lateral e zoom das revelações | `main{overflow-x:clip}` + deslocamento menor | 0 px em 69/69 combinações |
| 5 | **LCP 1,2 s → 2,4 s** em rede lenta | Meu: texto do hero começava com `opacity:0` | Entrada só com `transform` (texto legível desde o 1º quadro) | LCP 1,2 s |
| 6 | Duas faces de fonte (86 KB extra) disputando banda | Pré-existente | Uma face (`600 700` → Oswald Bold) | −1 requisição, −86 KB |
| 7 | **Garçom abria `/admin/salao`** (200) e, por código, também `/admin/comanda/N` e a ação `/admin/salao/acao` | Pré-existente: exigia só `floor` | `requireFloorAdmin()` (exige `orders` ou `cashier`) | 403 em `/admin/salao` e `/admin/salao/acao` (testados por HTTP); `/admin/comanda/N` usa a mesma proteção (**não testada diretamente**) |
| 8 | Duas `<h1>` em quase todas as telas admin | Pré-existente | título do cabeçalho vira `<p>` quando a página traz a sua | `h1=1` |
| 9 | Telas de login/recuperação sem `<main>` | Pré-existente | `<main>` em `admin/bare.php` | ok |
| 10 | Alvos de toque < 44 px (nav em tablet, botões `.sm`, links de comanda, rótulos de permissão, botões ▲▼) | Pré-existente | regras para `max-width:800px`/`pointer:coarse` | 0 achados < 32 px |
| 11 | BOM UTF-8 inserido por mim em `layout.php`, `site.css` e docs | Meu | removido | verificação de BOM limpa |
| 12 | Menu móvel abria vazio | Entrega anterior (corrigido antes) | — | — |
| 13 | **A suíte de testes podia rodar contra o banco indicado no `.env`** (o `.env` sobrescrevia o `:memory:` que os testes definem) — achado ao verificar a restauração do backup (64/66 em vez de 66/66 numa cópia com `DB_SQLITE_PATH` no `.env`) | Pré-existente | `.env` passa a ter **menor** prioridade que variáveis de ambiente/valores já definidos; `tests/run.php` **recusa** rodar fora de SQLite em memória | 66/66 também com `.env` apontando para arquivo |

**Incidente de processo (declarado):** durante a correção 10 um erro de caminho em um comando do PowerShell **sobrescreveu `site.css`, `admin.css` e `garcom.css` com 1 byte**. Restaurei `admin.css`/`garcom.css` da cópia de recuperação feita no início da tarefa (idênticas ao estado anterior) e reconstruí `site.css` (base anterior + blocos de movimento + todas as correções acima). Conferi chaves balanceadas, 66 testes, varredura de acessibilidade e a medição de desempenho **depois** da restauração.

## 3. Perfis (HTTP, servidor de teste 8099, banco copiado) — 68/68 aprovados
Contas criadas pela rota oficial `/admin/usuarios` (permissões padrão do perfil). Resultado completo: `tests/e2e/roles-http-resultado-2026-11-04.txt`.

| Perfil | Verificado | Resultado |
|---|---|---|
| Proprietário | login → `/admin`; cadastro de funcionários; criar gerente e garçom; **não** cria segundo proprietário; telas exclusivas (executivo, config, acessos); desativar garçom derruba a sessão e impede novo login | Aprovado |
| Gerente/caixa | login → `/admin`; caixa/salão/cardápio/pedidos 200; usuários/executivo/config/acessos **403**; criar/desativar funcionário e **alterar o próprio cargo → 403** (por POST direto); cargo no banco inalterado; confirma recebimento informado pelo garçom, registra o restante, fecha a comanda (conferido no **banco**) | Aprovado |
| Garçom | login → `/garcom`; abrir mesa sem nome/pessoas → 422; abre com nome+pessoas; **mesma chave não duplica**; vincula comanda física; lança itens; reenvio da mesma rodada não duplica; só itens novos; pede a conta; **lançar após pedir conta → 422**; informa recebimento (pendente); `confirm_payment`, `close_tab`, `register_payment`, `discount`, criar usuário e telas administrativas → **403**, e o banco **não mudou** | Aprovado |
| **Cenário crítico** | A aguardando fechamento; garçom abre comanda independente B na mesma mesa (visita diferente); itens de B; gerente fecha A; **mesa continua "ocupada" pelo atendimento B**; B segue aberta e A sai da lista; totais/pagamentos de B inalterados e os de A ficaram em A; fechar A de novo não corrompe | Aprovado |
| Concorrência entre dois aparelhos reais | — | **Não executado** (só requisições sequenciais e idempotência) |

## 4. Responsividade e acessibilidade
**Automatizado (Chrome real, 69 combinações rota×largura×perfil): 69/69 sem achados** — sem rolagem horizontal, imagens com `alt`, controles com nome, campos rotulados, 1 `h1`, `main`, `lang`, alvos ≥ 32 px (44 px nos controles principais), 0 erros de console. Larguras: **1440 px em todas as telas acessíveis** (público: início, história, cardápio, checkout, acompanhamento, login, recuperação; proprietário: pedidos, histórico, catálogo, produto, grupos, salão, caixa, clientes, relatórios, executivo, config, usuários, acessos, impressão, garçom; gerente: pedidos, caixa, salão, catálogo) e 360/390/768/1024 px nos fluxos públicos e 390/768 nos principais do painel; garçom em 1440/768/390/360.

**Teclado e foco (aprovados):** Tab pela landing (16 paradas) com indicador de foco em todas; item pré-escondido do rodapé aparece e fica visível ao receber foco (fora do cabeçalho fixo); menu móvel abre com Enter, fecha com Esc e devolve o foco; montagem da pizza abre com Enter, foco entra, Esc fecha e o foco **volta ao cartão**; 30 Tabs dentro da janela: 0 vezes no conteúdo atrás. **Ampliação:** texto 200% em 390 px e página 200% (720 px efetivos) em início/cardápio/checkout: 0 px de rolagem horizontal, cabeçalho 76 px (< 10% da altura).

**Não executado / bloqueado:**
| Item | Situação | Roteiro externo |
|---|---|---|
| Teclado virtual (iOS/Android) | Não executado (emulação de viewport não equivale) | No celular: abrir checkout, tocar em Nome/Telefone/Endereço e conferir se o campo ativo e o botão "Enviar pedido" não ficam sob o teclado; repetir na área do garçom (Nome/Pessoas) |
| Toque em aparelho real | Não executado | Percorrer cardápio → montagem → sacola → checkout; conferir alvos, rolagem do diálogo e a barra da sacola |
| Leitor de tela (NVDA/VoiceOver/TalkBack) | Não executado (auditoria automática ≠ leitor) | Landing, montagem da pizza, checkout (erros de campo) e `/garcom`: conferir ordem de leitura, anúncio de erros (`role=alert`), nome dos botões ▲▼ e do menu |
| Dependência exclusiva de hover | Conferida em código: efeitos de hover são só aprimoramento (`@media (hover:hover)`); nenhuma informação essencial depende de hover | — |
| Retorno do foco em *todos* os diálogos do painel | Só o da montagem foi testado; os demais usam `<dialog>` nativo | Abrir/fechar cada diálogo do painel por teclado |

## 5. Desempenho (medições locais, não representam produção)
Condições: mesmo Chrome 156 headless, janela 1440×900, `no-preference`, **cache frio** (`setCacheDisabled`+`clearBrowserCache`) e **quente** (carga de aquecimento) separados, **3 execuções, mediana**, dois perfis: *sem limite* e *lenta* (CPU 4×, 1,6 Mbps, 150 ms). Servidor PHP embutido, HTTP/1.1 **sem compressão**. Comparação com a **"versão sem movimento (reconstruída)"** = cópia do projeto atual sem a camada de movimento e com as duas faces de fonte; **não é a versão histórica byte a byte**, mas é equivalente funcional. Dados: `docs/validacao/perf-resultado-final.json`.

| Perfil | Página | Cache | FCP (ms) sem mov. → atual | LCP (ms) | CLS |
|---|---|---|---|---|---|
| sem limite | início | frio | 120 → 84 | 120 → 108 | 0,0213 → **0** |
| sem limite | início | quente | 92 → 112 | 112 → 112 | 0,0213 → **0** |
| sem limite | cardápio | frio | 88 → 100 | 104 → 100 | **0,5575 → 0,02** |
| sem limite | cardápio | quente | 100 → 96 | 100 → 96 | **0,5431 → 0,02** |
| lenta | início | frio | 1356 → 1772 | 1356 → 1772 | 0,0063 → 0,0186 |
| lenta | início | quente | 1384 → 1576 | 1384 → 1576 | 0,0063 → 0,0189 |
| lenta | cardápio | frio | 1464 → 1960 | 2332 → 2072 | 0,5437 → **0,0213** |
| lenta | cardápio | quente | 1572 → 1972 | 2156 → 2800 | 0,5426 → **0,0211** |

Resposta à interação (abrir a montagem da pizza, maior evento): **40 ms → 40 ms** (mediana de 3). Requisições: 15→14 (início), 28→27 (cardápio).

**Leitura honesta:** sem limite não há diferença relevante, e o CLS do cardápio melhorou muito. No perfil **lento** há **regressão residual de FCP/LCP no início (+0,2 a +0,4 s) e de FCP no cardápio (+0,4 a +0,5 s; o LCP do cardápio quente piorou de 2,16 para 2,80 s)**: o bloco de movimento acrescenta ~6 KB de CSS e ~5 KB de JS na rota crítica, sem compressão e disputando banda. Já eliminei as causas medidas (opacidade inicial 0, 2ª fonte, 2ª folha de estilos, leitura de layout em laço). Mitigação não aplicada: ativar gzip/brotli no servidor (esperado ~70% menos bytes; **não verificado** na HostMia) e/ou minificar. O CLS do início no perfil lento (0,019) é maior que o da versão sem movimento (0,006), mas fica abaixo de 0,1.
Nota de método: tentativas de isolar causas interceptando respostas (Fetch) **não** reproduzem custo de bytes (a resposta interceptada não passa pela limitação de rede); valem só para efeito de regras CSS.

## 6. Demonstração em vídeo
`docs/demonstracao/` (MP4, H.264, gravados do Chrome real com *screencast* e codificados; **verificados**: abrem, duração, `currentTime` avança, movimento real entre amostras, sem quadros pretos). Condição de movimento: **`no-preference` emulada** (o sistema real está em "reduzir").
| Arquivo | Duração | Quadros capturados | Conteúdo |
|---|---|---|---|
| `bianca-demo-landing.mp4` (1280×720) | 25,9 s | 714 | carregamento, hero e CTA, rolagem por categorias/números/faixa/história/rodapé, hover em categoria, volta ao topo, clique no CTA |
| `bianca-demo-compra.mp4` (1280×720) | 11,5 s | 89 | cardápio → pizza → tamanho → 2 sabores → borda → adicionar à sacola |
| `bianca-demo-celular-menu.mp4` (390×844) | 13,2 s | 506 | carregamento no celular, menu aberto/fechado com Esc, rolagem |
O GIF anterior (4 quadros) foi **descartado** como evidência. Capturas estáticas: `docs/evidencias/` (12 imagens: desktop, celular, menu, montagem, catálogo, pedidos, salão).

## 7. Suíte automática e build
`php tests/run.php`: **66 aprovados, 0 falhas**. (também numa cópia restaurada do ZIP com `.env` apontando para um banco em arquivo). `php -l` em `src/ views/ bin/ tests/`: sem erros. `php bin/check.php`: Ambiente OK. **Não há build/lockfile** (PHP puro, sem Composer/npm).

## 8. Matriz resumida
| Tela/fluxo | Perfil | Condição | Resultado | Evidência | Correção | Pendência externa |
|---|---|---|---|---|---|---|
| Landing, história | público | natural/emulado/reduce | Aprovado | motion-resultado.json, vídeos | #2,#4,#5 | — |
| Cardápio + montagem | público | 1440→360, teclado | Aprovado | a11y, keyboard, vídeo compra | #3 | teclado virtual, toque real |
| Carrinho/checkout/pedido | público | pedido #16 de teste | Aprovado | comanda e acompanhamento | #1 | — |
| Login/recuperação | equipe | 1440→360 | Aprovado | a11y | #9 | — |
| Painel (pedidos, catálogo, config…) | proprietário | 1440, 768, 390 | Aprovado | a11y-resultado-final | #8,#10 | — |
| Cadastro de funcionários | proprietário/gerente | HTTP | Aprovado | roles-http | — | — |
| Caixa/fechamento | gerente | HTTP+banco | Aprovado | roles-http | — | concorrência real |
| Salão/comandas | garçom/gerente | HTTP, cenário crítico | Aprovado | roles-http | #7 | impressora real |
| Impressão física, pagamentos reais, MySQL/HostMia | — | — | **Bloqueado** | — | — | equipamento/serviços |
