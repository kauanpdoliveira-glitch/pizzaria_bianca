# Catálogo, montagem da pizza e fotos

## Para o proprietário/gerente (Painel → Cardápio = "Catálogo")
- **Abas:** *Produtos* (lista por categoria, com foto, preço, status, busca, **Reordenar** por ▲▼), *Complementos* (bordas e adicionais da categoria — preço por tamanho, grupos obrigatórios/opcionais, limites, "só em alguns tamanhos") e *Opções* (regras da categoria: subcategoria de…, máximo de sabores, retirada de ingredientes, setor cozinha/bar, visibilidade, "montagem de pizza").
- **Status do produto:** **Ativo** (vende) · **Em falta** (aparece no cardápio, mas não pode ser comprado) · **Oculto** (não aparece e não pode ser comprado nem por chamada direta à API; o histórico de vendas é preservado — não existe "excluir").
- **Ingredientes e retiradas** (no produto): um por linha; `; fixo` impede a retirada. Só o que está cadastrado pode ser retirado, e só do sabor a que pertence. Os ingredientes das 23 pizzas foram **transcritos das descrições do PDF** (sem inventar); revise-os. Sabores de ingrediente único vêm como "fixo".
- **Subcategorias:** uma por categoria principal (ex.: "Pizzas salgadas"/"Pizzas doces" sob "Pizzas"). Sabores só se combinam dentro da mesma categoria principal; complementos da categoria principal valem para as subcategorias. O PDF não traz pizzas doces: nada foi inventado — crie a subcategoria e os produtos quando quiser.
- **Fotos:** envie pela tela do produto (JPG/PNG/WebP até 4 MB; é re-codificada e fica em `public/uploads/`, que precisa ser **persistente** na hospedagem). 62 itens ainda sem foto real: `docs/FOTOS-PENDENTES.md`. Enquanto não há foto, o site mostra uma **ilustração neutra** (não é foto do produto).
- **Importar o PDF de novo não duplica nem sobrescreve:** a carga só roda com o banco vazio; ingredientes só entram em pizzas que ainda não têm.

## Regras da pizza (servidor e tela usam a mesma regra)
1. Tamanho obrigatório. 2. De 1 a 2 sabores (máximo por categoria ou a regra geral); 3 ou 0 são rejeitados; a tela bloqueia o 3º com mensagem. 3. Preço de 2 sabores: **valor médio** (arredondado ao centavo para cima) — está no PDF ("será cobrado o valor médio") e pode ser trocado por "maior valor" em Configurações; o mesmo valor aparece no cardápio, sacola, checkout, pedido, gestor e comanda. 4. Mudar o tamanho revalida sabores (o que não existe no tamanho sai da seleção, com aviso). 5. Bordas: só as cadastradas; hoje **somente Catupiry Original** (única do PDF), com preço por tamanho. Cream cheese, cheddar e artesanais **não estão no cardápio real** e não foram criadas — cadastre em Complementos quando existirem. 6. Retirada por sabor: "sem cebola — ½ À Moda"; observações gerais são separadas. Retirar um ingrediente não garante ausência de alérgenos (aviso na tela).
- A sacola permite **Editar** a pizza (reabre a montagem com as escolhas) e avisa no checkout se algum **preço mudou** desde que o item entrou (exige "Estou ciente").

## Itens fictícios
Nenhum produto, preço ou borda fictício foi cadastrado no catálogo real. Para demonstração visual existem apenas as ilustrações neutras no lugar das fotos. (Não há como gerar fotografias reais de produtos; peça as fotos ao fotógrafo/loja.)
