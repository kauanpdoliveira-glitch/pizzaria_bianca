# Registro de fontes (conteúdo e imagens)

PDF = "Cardápio Pizzaria Bianca - Desde 1977" (12 páginas). Instagram = bio de https://www.instagram.com/biancapizzaria/ (única parte acessível sem login).

## Fatos históricos (PDF, "Nossa história", p. 2–3) → `/historia` e home
| Informação | Fonte |
|---|---|
| Fundação em 1977, Contagem; Carlos Camargos (Bonitão) e Nadiorlando Veronez; Praça Tiradentes, ao lado do Mercadão da Carne e da Loteria | PDF p.2 |
| Origem do nome Bianca (filha de um amigo) | PDF p.2 |
| Vicente Faria convidado, acordo de dedicação exclusiva à Empresa São Gonçalo (fundada por José Faria da Rocha); indicou Luiz da Cunha (Luizinho) | PDF p.2 |
| 1995: sede atual, tele-entrega (sem internet, rotas em mapas); desnível interno por limitações financeiras | PDF p.2 |
| 2004 Vicente sócio efetivo; ~2010 Luizinho sai; filiais Padre Eustáquio (2004) e Av. Madri/Eldorado (2007) até 2013 | PDF p.2 |
| 2015 estacionamento; 2017 jornada digital (Gabriel); +100.000 no Instagram; pandemia (mar/2020, 3 anos só delivery e balcão); fev/2024 Gabriel sócio | PDF p.3 |
| Molho madeira: 48 h de preparo; molhos/temperos "feitos na casa"; pudim só com gema e ingredientes Itambé | PDF p.7–9 (cardápio) |

Não usado/não afirmado: tipo de forno, método da massa, prêmios, avaliações, depoimentos.

## Informações operacionais (sujeitas a mudança — vêm do banco/painel, não do código)
| Informação | Origem | Observação |
|---|---|---|
| Endereço Rua Cristiano Machado, 116 – Centro – Contagem/MG; tel. (31) 3398-5252 | PDF (última página) | confirmar se é a versão vigente |
| Horário 18h–23h todos os dias | bio do Instagram | **o PDF não traz horário**; valor inicial editável em Configurações — confirmar |
| Pagamentos (dinheiro, PIX, débito/crédito, Ticket Restaurante, Alelo) | PDF | PIX/vale conferidos manualmente |
| Preços e pratos | PDF, importados em `database/seed_menu.php` | Salaminho Média R$ 94,90 parece erro — confirmar |
| iFood / Instagram 125 mil | bio do Instagram | PDF fala "mais de 100.000" (compatível) |

## Imagens (extraídas do PDF, recodificadas/redimensionadas; originais em D:\Dowload)
| Arquivo | Origem | Uso |
|---|---|---|
| `hero-pizza*.jpg`, `hero.jpg` | PDF p.1 (foto da capa; pizza na frigideira), recorte circular | hero da home |
| `kombi.jpg` | PDF p.2 | home e história (legenda só descreve o visível: Kombi com selo 100K) |
| `casa.jpg` | PDF p.3 | história, sem identificar pessoas (**confirmar quem aparece e a autoria**) |
| `pizza-a-moda`, `pizza-calabresa`, `pizza-palmito-bolonhesa`, `pizza-presunto-tomate-bacon-milho`, `pizza-quatro-queijos` | PDF p.6–7, identificadas pelas legendas da página | cada uma só no sabor correspondente |
| `batata-frita`, `file-mignon-fritas`, `file-frango-fritas`, `iscas-tilapia`, `bolinho-bacalhau`, `pastel-aipim`, `pastel-angu` | PDF p.4–5 (página "Entradas") | idem |
| `peito-frango`, `file-tilapia`, `lasanha`, `canelone`, `pudim` | PDF p.8–10 | idem |
Sem foto confiável: demais pratos principais, guarnições, bebidas, pizzas sem imagem — ficam sem imagem (placeholder), nunca com foto de outro produto. Fotos do perfil do Instagram **não** foram usadas (autoria de terceiros não verificada).

## Referência visual
A imagem "01 — Clássica" orientou cores, composição e tipografia. Textos, fotos e o lettering do logotipo dela **não** foram usados. Logotipo oficial atual: versão branca transparente (ver `docs/MARCA.md`), usada sempre sobre o vermelho da marca. Fonte Oswald (Google Fonts, licença OFL) hospedada em `public/assets/fonts/`.
