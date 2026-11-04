# iFood como fonte complementar do catálogo

Consulta de **04/11/2026**, página pública `ifood.com.br/delivery/contagem-mg/pizzaria-bianca---desde-1977-centro/77cf5197-…`, aberta no navegador do próprio usuário (ele concluiu a verificação anti-bot). Sem login, sem endereço informado, sem endpoints privados. A loja estava **fechada** (abre às 18:00): os cartões apareciam como "Fechado", mas as janelas de opções abriam normalmente.

Dados observados: `data/ifood/ifood-2026-11-04.json`. Fotos originais (quarentena, fora do site): `storage/ifood/original/`. Importador: `php bin/import-ifood.php` (simulação por padrão).

## O que o iFood é aqui
Um **canal** com preços, combos e promoções próprios. Nada do iFood altera o preço do site. Tudo vira *observação* (`channel_observations`: origem, URL, data, situação) e aparece no painel, no produto, como "Preço observado em outro canal".

## Cobertura da consulta
| Área | Situação |
|---|---|
| Destaques, Seleções Especiais (combos/% Off) | **lidos**; registrados como canal exclusivo, **não importados** |
| Pizzas (22 sabores × 4 tamanhos; borda; 2 sabores) | **lidos** (Gigante/Grande/Média/Brotinho 1 sabor; Gigante/Grande/Média 2 sabores) |
| Pratos (filé mignon, frango, tilápia) com P/M/G | **lidos**, incluindo "Empanado/Grelhado" |
| Lasanha e Canelone, Entradas e Porções, Sobremesas, Bebidas | **lidos** (cartões; janelas de opções abertas em todos os itens com opções e por amostragem — bebidas e porções simples — nos demais, que não apresentaram grupos) |
| Taxa, tempo, promoções por endereço, avaliações/selos | **não acessível / não copiado** (sem endereço; propositalmente) |
| Itens que não aparecem no iFood (ex.: Lombo Defumado) | "não encontrado" ≠ deixou de existir; nada foi desativado |

## Regras do iFood x regras do site
- **Sabores:** no iFood, 1 e 2 sabores são cartões separados; em 2 sabores cada sabor é cotado pela **metade** do preço inteiro (soma das metades). O site mantém 1 a 2 sabores numa montagem só e **valor médio** (PDF). Mantido, sem substituição.
- **Borda:** só a de Catupiry Original (Gigante 26,90 · Grande 24,90 · Média 21,90 · Brotinho 19,90 no iFood; site 24,90/21,90/19,90/17,90). Nenhuma outra borda existe no iFood.
- **Retirada de ingredientes:** o iFood só oferece comentário livre; a configuração por sabor do site continua valendo.
- **Bebidas "para acompanhar"** nas janelas de pizza/prato são *upsell* do iFood (com outro código de loja): não importadas, e suas fotos não são da Bianca.

## Resultado da importação (gravada)
- **160 observações** de preço/opção/combo registradas (idempotente: reexecutar atualiza, não duplica).
- **3 rascunhos OCULTOS** criados (sem equivalente por volume/composição, valor de referência do iFood, *não vendáveis*): Batata Frita com Mussarela - 500g (R$ 58,90), Água Mineral Com Gás e Sem Gás São Lourenço 510ml (R$ 8,90). Para ativar, defina o preço próprio.
- **Preços do site: nenhum alterado.** 109 comparações: o iFood é em geral ~23% mais alto (mediana); só Salaminho Gigante é igual. Margherita/Mussarela/Presunto Gigante +4,2%.
- **Fotos:** 19 produtos receberam foto da própria Bianca no iFood (17 pizzas, Filé à Bianca Especial, Filé à Francesa) + 1 foto no rascunho oculto. Foto cadastrada manualmente **nunca** é trocada. Derivados reencodados (≤1200 px) em `public/uploads/ifood/`; origem e data em `channel_observations` (`kind=image`).
  - ✅ **Uso autorizado** pelo usuário em 04/11/2026 (confirmação em conversa, em nome da Bianca; vale só para estas fotos da própria Bianca). Convém guardar essa autorização por escrito. Para desfazer: `php bin/import-ifood.php --unlink-photos`.
  - Mesma foto = mesmo sabor (a imagem vem da própria linha do sabor no iFood). Marca-d'água/identidade do iFood: não apareceu nas 3 imagens inspecionadas visualmente (as outras 17 não foram abertas uma a uma); nenhum elemento do iFood foi copiado nem removido.

## Equivalentes (resolvido em 04/11/2026)
**Confirmados e ligados** (mesma composição, gramagem ou unidades nas descrições; `IfoodImport::CONFIRMED`): as 3 parmegianas, Medalhão à Piamontese/Piamontesa, Filé de Tilápia Empanado (prato e porção de 500 g = Iscas), Batata Frita 500 g, Bolinho (15), os 2 pastéis, Filé Mignon/Frango com Fritas 650 g, Torta Holandesa e 11 bebidas por marca e tamanho (premissa: "lata" = 350 ml). Com isso, +3 fotos ligadas (Parmegiana de Filé Mignon, Parmegiana de Filé de Frango, Torta Holandesa): **22 produtos do cardápio com foto da Bianca** vinda do iFood (mais 1 no rascunho oculto); 40 itens seguem sem foto.
**Seguem como "provável" por ambiguidade real:** Pudim (mini x individual; descrições diferentes), Coca-Cola Sem Açúcar 350 ml x Coca-Cola Zero Lata, Suco Del Valle (sabor Pêssego no iFood; o site não diz o sabor).

## Decisões: o que foi resolvido e o que só o dono pode informar
Resolvido sem inventar dado (padrão seguro, reversível):
- **Preços do site x iFood:** mantidos os do site. O iFood é só referência (ver painel).
- **Rascunhos ocultos:** permanecem ocultos; ativar exige o preço próprio (não deduzi um valor).
- **Combos do iFood:** não criados (dependem de preço e composição próprios).
- **Autorização das fotos:** confirmada.
- **Lombo Defumado:** continua no site.
Dependem de informação que não existe nas fontes (o sistema segue como está, sem comprar item sem preço):
1. **Lasanha/Canelone:** porção do site (iFood: serve 3 por R$ 119,90/124,90; site R$ 59,90/64,90).
2. **Filé mignon à parmegiana — Empanado ou Grelhado:** a opção existe fora do iFood?
3. **Acréscimos:** Catupiry nas massas (iFood +19,90; site 12,90); batata (iFood mussarela +14,00 / +bacon +19,00; site 9,90 cada).
4. **Digitação:** Média Tropical 118,90 e Vegetariana 94,90 no iFood; Salaminho Média 94,90 no PDF.
5. **Ativar os 3 rascunhos** (qual preço próprio) e **criar combos** (quais).
6. Os 3 equivalentes ambíguos acima.
## Segurança do processo
Simulação por padrão · `--apply` grava só observações e rascunhos ocultos · `--photos` liga fotos apenas a produto exato **sem foto** · reexecutável · histórico de pedidos intocado · backup antes (`php bin/backup.php`).

