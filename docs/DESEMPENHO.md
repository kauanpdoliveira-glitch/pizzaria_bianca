# Desempenho — diagnóstico, otimizações e medições (04/11/2026)

## Diagnóstico
A regressão relatada (perfil lento: home +0,2–0,4 s, cardápio +0,4–0,5 s) **não era só "mais CSS/JS"**. Medido por inspeção de recursos e por experimentos controlados:
| Causa | Evidência | Estado |
|---|---|---|
| Texto do hero começava com `opacity:0` | LCP 1,2 s → 2,4 s (experimento com respostas interceptadas) | corrigido antes (entrada só com `transform`) |
| A **home carregava o CSS e o JS da loja** (cardápio, montagem, checkout, ~13 KB de CSS e ~35 KB de JS que ela não usa) | `site.css` 28 KB + `app.js` 40 KB em toda página | **corrigido**: CSS dividido em *core* / *shop*; `app.js` só nas páginas da loja; movimento em `motion.js` (3,5 KB min) |
| CSS/JS **sem minificação** | 28 KB → 13,3 KB (core) | **corrigido** (`bin/build-assets.php`) |
| **Fonte** TTF de 86 KB (2 arquivos antes; 1 agora) | 86,4 KB | **WOFF 44,7 KB** (`bin/build-fonts.php`), TTF como alternativa |
| **Imagem principal** de 233 KB | maior recurso da home | variante **640 px de 107 KB** no `srcset` (`bin/build-images.php`) |
| Sem compressão HTTP | o servidor embutido do PHP não comprime | regras `mod_deflate/mod_brotli/mod_expires` em `public/.htaccess` — **entrega real não verificada** (precisa de cabeçalhos no servidor da Hostinger) |
Descartadas como causa: leitura de layout em laço (corrigida, sem efeito mensurável) e animações (só `transform`/`opacity`, 0 animações rodando após 5 s). Experimentos que **interceptam** a resposta não reproduzem custo de bytes (a resposta interceptada não passa pela limitação de rede) — valem só para efeito de regras CSS.

## Build de produção
`php bin/build-assets.php` gera `public/assets/dist/` (minificadores próprios, conservadores; sem Node). Em `APP_ENV=production` (ou `ASSETS_MIN=1`) o servidor usa `dist/`; em desenvolvimento, as fontes legíveis. `php bin/build-assets.php --check` falha se `dist/` estiver desatualizado. **Minificação ≠ compressão HTTP.**
| Arquivo | Fonte | Minificado | gzip -9 |
|---|---|---|---|
| `site.min.css` (core, todas as páginas) | 28.041 B | 13.288 B | 4.122 B |
| `shop.min.css` (cardápio/checkout/acompanhamento) | — | 12.972 B | 3.503 B |
| `app.min.js` (só páginas da loja) | 35.108 B | 28.141 B | 9.690 B |
| `motion.min.js` (todas) | 5.587 B | 3.504 B | 1.360 B |
| `admin.min.js` / `garcom.min.js` | 40.238 / 25.293 B | 32.650 / 21.152 B | 11.111 / 6.917 B |
**Verificação de que a minificação não quebrou nada** (Chrome real, fonte × minificado, 1440 e 390 px, 6 rotas): **estilos computados idênticos em todos os elementos** (12/12 combinações), 0 erros de console, compra completa funcionando (2 sabores + borda → pedido #19 → acompanhamento), movimento (20 blocos revelados) e os painéis admin/garçom sem erros de JavaScript (`docs/validacao/dist-equivalencia.json`). Limite: a equivalência de estilo/JS foi medida nessas rotas e fluxos, não em todas as telas do painel.

## Medições comparáveis
Chrome 156 headless, janela 1440×900 (DPR 1), `prefers-reduced-motion: no-preference` (emulado), **CPU 4×**, rede **1,6 Mbps** de descida com **150 ms de latência adicional por requisição**, 3 execuções por cenário (**mediana**), 8 s de espera após o carregamento para capturar o LCP de imagens. **Antes** = o ZIP entregue às 14:03 (extraído e servido tal qual); **Depois** = versão atual com build de produção. Cache **frio** = cache desativado e limpo; **quente** = carga de aquecimento. O servidor embutido do PHP não envia cabeçalhos de cache: "quente" ≈ "frio" para estáticos nesta medição.
### Sem compressão (servidor embutido do PHP)
| Página | Cache | DCL antes→depois (ms) | FCP | LCP | KB transferidos | CLS |
|---|---|---|---|---|---|---|
| Início | frio | 1273 → **670** | 1248 → **804** | 5016 → **2952** | 935 → **720** | 0,0184 → 0,0184 |
| Início | quente | 1279 → **698** | 1236 → **832** | 4984 → **2932** | 934 → **719** | 0,0185 → 0,0184 |
| Cardápio | frio | 2968 → **2435** | 2808 → **1312** | 3760 → **3080** | 1358 → **1307** | 0,0236 → 0,0213 |
| Cardápio | quente | 1737 → **1542** | 1572 → **1116** | 2520 → **2032** | 1347 → **1307** | 0,0236 → 0,0213 |
### Com gzip **simulado** (roteador de teste `tests/e2e/gzip-router.php`; **não** prova a Hostinger)
| Página | Cache | DCL antes→depois | FCP | LCP | KB |
|---|---|---|---|---|---|
| Início | frio | 721 → **502** | 760 → **620** | 4560 → **3020** | 843 → **704** |
| Início | quente | 732 → **522** | 768 → **636** | 4584 → **3036** | 842 → **703** |
| Cardápio | frio | 1257 → 1355 | 1276 → **1140** | 2892 → **3660 (pior)** | 1230 → 1228 |
| Cardápio | quente | 3136 → **1645** | 3076 → **1464** | 5324 → **3232** | 1220 → 1245 |
Resposta a interação (abrir a montagem da pizza, maior evento, sem limite): 40–48 ms antes e 40 ms depois.
**Leitura honesta:** na home a regressão foi **revertida e superada** (FCP −35% e LCP −41% sem compressão; −18% e −34% com gzip simulado). No cardápio o FCP melhorou bastante, mas o **LCP varia muito** entre execuções (imagem de produto/banner vs texto) e **no cenário gzip frio piorou de 2,9 para 3,7 s** na mediana de 3 execuções — não corrigido; o cardápio ainda carrega ~1,3 MB (fotos de produtos) e é candidato a otimização de imagens (WebP/tamanhos) numa próxima etapa. Medições locais não comprovam o desempenho de usuários reais nem da Hostinger.
Dados brutos: `docs/validacao/perf3-*.json`.

## O que falta verificar na hospedagem real
Cabeçalhos `Content-Encoding: br|gzip` e `Cache-Control/Expires` nos CSS/JS/fontes; HTTP/2; versão do PHP e OPcache; latência real do MySQL.
