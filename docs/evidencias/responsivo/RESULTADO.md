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

## Rodada 2 (commit 7e77bed, Vercel, emulação do navegador do app)
| Largura | Resultado |
|---|---|
| 390 | `/`, `/cardapio`, `/checkout`, `/historia`: respiro 21 px, sem rolagem horizontal |
| 740×360 (horizontal) | respiro 40 px, sem rolagem; cabeçalho 76 px |
| 1024 / 1920 | respiro 40 px, sem rolagem (`/`, `/cardapio`, `/checkout`) |
| 640×450 (200% de 1280) | respiro 35 px, sem rolagem (4 páginas) |
| 180 (200% de 360) | respiro 18 px, sem rolagem (4 páginas) |
| 320 | montador de pizza: etapas 2 e 3 com 20 px, sem rolagem; Escape fecha o modal |

Teclado real (Tab/Enter/Escape): pular-link → logo → CTA → Menu com foco azul de 3 px; Enter abre o menu; Tab cai em "Início" (44 px); Escape fecha e devolve o foco ao "Menu".
Capturas "depois" da landing de cima a baixo em `depois-360/landing-pagina-*.jpg` e `landing-rodape.jpg`.
Pendente: aparelho real, Safari/Firefox, teclado virtual real. Observação de conteúdo: o rodapé mostra "Horário de pedidos: todos os dias 00h–23h59" (valor vindo das configurações; confirmar se é o desejado).
