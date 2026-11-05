# Responsividade — 05/11/2026 (commit 8850064)
Medição: menor distância do texto até a borda (px), página sem `overflow-x:hidden` global. Emulação do navegador do app (não é aparelho real).

| Largura | Antes (Vercel) | Depois (Vercel) |
|---|---|---|
| 360 | 14–16 | 20 (/, /cardapio, /checkout, /historia) |
| 320 | 15 | 20 (sem rolagem horizontal) |
| 430 | — | 24 (local) |
| 768 / 1440 | — | 40 (local) |
| 180 (zoom 200% de 360) | página 222 px (cabeçalho vazava) | 180 (local) |

Capturas: `antes-360/` e `depois-360/` (landing, cardápio, história).
Não testado: aparelho real, Safari/Firefox, 390/1024/1920, celular na horizontal, teclado virtual, ordem do Tab, montador de pizza.
