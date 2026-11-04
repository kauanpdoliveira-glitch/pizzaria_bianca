# Marca (logo)

## Verificação do arquivo recebido (`brand/original/logo-bianca-branca-original.webp`, 1536×1024)
- **Transparência real:** 81% dos pixels totalmente transparentes, 15% parcialmente (bordas suavizadas), 4% opacos. Não há fundo preto embutido (o preto era só da prévia).
- **Sobre vermelho (#c4121f) e escuro:** letras brancas nítidas, sem halo, mancha ou serrilhado visível; faixas verde e vermelha íntegras. **Sobre branco:** o lettering some (é branco por desenho; só as faixas aparecem) — por isso interfaces brancas usam suporte vermelho.
- **Achados técnicos (inofensivos):** margens transparentes grandes (193 px acima e 156 px abaixo do desenho) e cor "fantasma" sob pixels totalmente transparentes. Nada disso afeta o traço.
- **Ajustes aplicados (seguros, sem tocar nos traços):** recorte das margens transparentes (com 10 px de respiro), remoção da cor sob transparência total e reamostragem em alfa premultiplicado (sem franja escura nas bordas). Proporção preservada (2,147:1). Reprodutível: `php bin/build-brand.php`.
- **Preservados:** o original e a logo escura anterior (150 px) ficam em `brand/original/` (fora do docroot).

## Derivados (`public/assets/brand/`)
`logo-bianca-360|600|1200.png` e `.webp` (lossless) · `logo-bianca-sobre-vermelho-1200x630.png` (og:image e dados estruturados, que aparecem sobre fundos claros).

## Uso
- Componente: `View::brand($contexto)` + `public/assets/brand.css` (largura responsiva, `width/height` reservados, `object-fit: contain`, `srcset`).
- Sempre **sobre vermelho** (`.brand-band` ou fundo vermelho): cabeçalho/rodapé do site, faixa no topo do menu lateral do painel, cabeçalho da área do garçom, topo do login. Sem contorno, brilho ou sombra. Largura mínima recomendada: ~100 px (abaixo disso "Desde 1977" perde legibilidade).
- O site foi o único lugar com a logo no tamanho do cabeçalho vermelho; o painel branco continua branco (só a faixa da marca é vermelha).

## Pendências da marca
- **Comandas térmicas e documentos em papel branco:** continuam só texto (sem logo). Imprimir a marca exige uma **versão escura/monocromática**; a logo escura anterior tem só 150 px. Peça ao designer um SVG/PNG escuro de alta resolução.
- **Favicon/ícone de app:** gerados com a logo **completa** sobre o vermelho da marca (`favicon.ico` 32/48 px, `apple-touch-icon` 180, `icon-192/512`). Em 16–48 px "Desde 1977" fica pequeno (limitação do tamanho, não do arquivo); a partir de 180 px é legível. Reprodutível por `php bin/build-brand.php`.
