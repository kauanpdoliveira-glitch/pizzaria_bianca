<?php
declare(strict_types=1);
/**
 * Carga do cardápio REAL, transcrito do PDF "Cardápio Pizzaria Bianca - Desde 1977".
 * Só roda se não houver categorias. Fora do escopo desta carga (adicionar pelo painel): doses, drinks, bombonière.
 * ATENÇÃO: SALAMINHO Média está R$ 94,90 no PDF (parece erro de digitação); mantido como no PDF até confirmação.
 */
require_once __DIR__ . '/../src/bootstrap.php';
Db::migrate();
if (Db::val('SELECT COUNT(*) FROM categories') > 0) { echo "Cardápio já existe; nada a fazer.\n"; return; }

$c = fn($v) => (int)round($v * 100);
$cat = function (string $name, int $sort, bool $pizza = false) { return Db::insert('categories', ['name' => $name, 'sort' => $sort, 'is_pizza' => $pizza ? 1 : 0, 'active' => 1]); };
$prod = function (int $cid, string $name, ?string $desc, int $price, ?string $img = null, ?string $badge = null, int $sort = 0) {
    return Db::insert('products', ['category_id' => $cid, 'name' => $name, 'description' => $desc, 'base_price_cents' => $price, 'image' => $img ? "/assets/img/$img.jpg" : null, 'badge' => $badge, 'sort' => $sort, 'available' => 1]);
};
$sizes = function (int $pid, array $rows) use ($c) { foreach ($rows as $i => [$n, $d, $p]) Db::insert('product_sizes', ['product_id' => $pid, 'name' => $n, 'detail' => $d, 'price_cents' => $c($p), 'sort' => $i, 'available' => 1]); };

// ---- Pizzas ----
$pz = $cat('Pizzas', 1, true);
$S = [['Brotinho', '20 cm · 2 fatias'], ['Média', '25 cm · 4 fatias'], ['Grande', '30 cm · 6 fatias'], ['Gigante', '35 cm · 8 fatias']];
$pizzas = [
 ['À Moda', 'Um dos sabores tradicionais da casa! Feita com calabresa defumada, presunto, azeitona, pimentão e cebola. Forrada de mussarela.', [59.90, 79.90, 109.90, 129.90], 'pizza-a-moda', 'Entre as 5 mais pedidas'],
 ['À Moda Especial', 'Combinação de sabores: salaminho, presunto, azeitona, pimentão e cebola frescos. Forrada de mussarela.', [64.90, 84.90, 119.90, 139.90]],
 ['Aliche Nacional', 'Também conhecido como anchova. O aliche é muito consumido em países europeus, possui um toque salgado que eleva sabores. Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90]],
 ['Atum', 'A parte mais nobre do atum, sendo ele sólido e em pedaços. Forrada de mussarela.', [64.90, 84.90, 114.90, 144.90]],
 ['Audácia', 'A combinação de frango desfiado com tempero feito na Bianca, rodelas de cebola fresca, Catupiry Original e batata palha especial. Sabor e crocância! Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90]],
 ['Calabresa', 'Clássica, generosa e direta ao ponto: bastante linguiça calabresa fatiada. Forrada de mussarela.', [54.90, 69.90, 99.90, 119.90], 'pizza-calabresa', 'Entre as 5 mais pedidas'],
 ['Carne Seca com Catupiry', 'Carne seca desfiada e preparada na Bianca. Catupiry Original e cebola fresca. Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90]],
 ['Frango à Bolonhesa', 'Frango desfiado com tempero feito na Bianca, encoberto com o molho a bolonhesa, elaborado com receita exclusiva da casa. Forrada com mussarela.', [59.90, 79.90, 109.90, 129.90]],
 ['Frango com Catupiry', 'Clássica! Frango desfiado com tempero feito na Bianca, coberto por Catupiry Original. Forrada de mussarela.', [64.90, 89.90, 119.90, 139.90]],
 ['Lombinho com Champignon e Tomate', 'Cortes do lombinho defumado com champignon (também conhecido como Cogumelo Paris) e generosas rodelas de tomate italiano. Forrada de mussarela.', [59.90, 79.90, 109.90, 129.90]],
 ['Lombinho com Catupiry e Milho', 'Rodelas de lombinho canadense com Catupiry Original e milho. Forrada de mussarela.', [64.90, 84.90, 119.90, 139.90]],
 ['Lombo Defumado', 'Saborosas fatias de lombo defumado. Forrada de mussarela.', [54.90, 69.90, 99.90, 119.90]],
 ['Margherita', 'Tomate italiano e manjericão fresco: uma combinação clássica e aromática. Forrada de mussarela.', [54.90, 69.90, 99.90, 119.90]],
 ['Mussarela', 'O básico cativante! Bastante mussarela pura.', [54.90, 69.90, 99.90, 119.90]],
 ['Palmito à Bolonhesa', 'Generosos pedaços de palmito macios e frescos, cobertos com molho a bolonhesa, feito na casa. Forrada com mussarela.', [64.90, 84.90, 109.90, 129.90], 'pizza-palmito-bolonhesa'],
 ['Portuguesa', 'Uma combinação clássica e generosa: presunto, calabresa defumada, bacon, ovos, tomate, azeitona, pimentão e cebola. Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90]],
 ['Presunto', 'Presunto tradicional. Forrada de mussarela.', [54.90, 69.90, 99.90, 119.90]],
 ['Presunto com Catupiry', 'Presunto coberto por Catupiry Original. Forrada de mussarela.', [59.90, 79.90, 109.90, 129.90]],
 ['Presunto, Tomate, Bacon e Milho', 'Combinação equilibrada de presunto, rodelas de tomate italiano, bacon e milho. Forrada de mussarela.', [59.90, 79.90, 109.90, 129.90], 'pizza-presunto-tomate-bacon-milho'],
 ['Quatro Queijos', 'Composição equilibrada de mussarela, gorgonzola, provolone e Catupiry Original, com uma pitada de manjericão seco. Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90], 'pizza-quatro-queijos'],
 ['Salaminho', 'Generosas rodelas do apetitoso salaminho. Forrada de mussarela.', [54.90, 94.90, 129.90, 149.90]],
 ['Tropical', 'Sabor autoral da Bianca. Combinação agridoce e refrescante, feita por: presunto, bacon e frutas em calda: pêssego, abacaxi, figo e ameixa. Forrada de mussarela.', [69.90, 94.90, 129.90, 149.90]],
 ['Vegetariana', 'Leve e saborosa. Composta por tomate italiano, pimentão verde, cebola, azeitona, seleta de legumes e palmito. Forrada de mussarela.', [59.90, 79.90, 109.90, 129.90]],
];
foreach ($pizzas as $i => $p) {
    $id = $prod($pz, $p[0], $p[1], 0, $p[3] ?? null, $p[4] ?? null, $i);
    $sizes($id, array_map(fn($k) => [$S[$k][0], $S[$k][1], $p[2][$k]], array_keys($S)));
}
$g = Db::insert('option_groups', ['category_id' => $pz, 'name' => 'Borda', 'min_sel' => 0, 'max_sel' => 1, 'sort' => 0]);
$bo = Db::insert('options', ['group_id' => $g, 'name' => 'Borda de Catupiry Original', 'price_cents' => $c(17.90), 'sort' => 0, 'available' => 1]);
foreach (['Brotinho' => 17.90, 'Média' => 19.90, 'Grande' => 21.90, 'Gigante' => 24.90] as $n => $v) Db::insert('option_size_prices', ['option_id' => $bo, 'size_name' => $n, 'price_cents' => $c($v)]);

// ---- Entradas ----
$en = $cat('Entradas', 2);
$e = [
 ['Batata Frita', 'Porção de 500g.', 34.90, 'batata-frita'],
 ['Filé Mignon com Fritas', 'Porção de 650g, sendo 400g de filé mignon e 250g de batata.', 99.90, 'file-mignon-fritas'],
 ['Filé Mignon', 'Porção de 500g de filé mignon, servido sem acompanhamento.', 119.90],
 ['Filé de Frango com Fritas', 'Porção de 650g, sendo 400g de filé de frango e 250g de batata.', 49.90, 'file-frango-fritas'],
 ['Filé de Frango', 'Porção de 500g de filé de frango, servido sem acompanhamento.', 44.90],
 ['Iscas de Tilápia Empanadas', 'Acompanha molho tártaro feito na casa. Porção de 500g.', 74.90, 'iscas-tilapia'],
 ['Bolinho de Bacalhau - 15 unid.', 'Bolinho de bacalhau crocante por fora e macio por dentro.', 64.90, 'bolinho-bacalhau'],
 ['Pastel de Aipim com Carne Moída - 10 unid.', 'A saborosa união entre a cremosidade do aipim (mandioca) e a suculência da carne moída.', 44.90, 'pastel-aipim'],
 ['Pastel de Angu com Carne Seca - 10 unid.', 'Sabores tradicionais que ao serem combinados com a cremosidade do requeijão de forno, realçam a culinária brasileira.', 44.90, 'pastel-angu'],
];
foreach ($e as $i => $r) $prod($en, $r[0], $r[1], $c($r[2]), $r[3] ?? null, null, $i);
$gb = Db::insert('option_groups', ['product_id' => Db::val("SELECT id FROM products WHERE name = 'Batata Frita'"), 'name' => 'Acréscimos', 'min_sel' => 0, 'max_sel' => 2, 'sort' => 0]);
Db::insert('options', ['group_id' => $gb, 'name' => 'Acréscimo de mussarela', 'price_cents' => $c(9.90), 'sort' => 0, 'available' => 1]);
Db::insert('options', ['group_id' => $gb, 'name' => 'Acréscimo de bacon', 'price_cents' => $c(9.90), 'sort' => 1, 'available' => 1]);

// ---- Pratos principais (P / M / G) ----
$pr = $cat('Pratos Principais', 3);
$PS = [['P', '1 pessoa · 150g de proteína'], ['M', '2 pessoas · 250g de proteína'], ['G', '3 pessoas · 500g de proteína']];
$pratos = [
 ['Parmegiana de Filé Mignon', 'Filé mignon à milanesa, molho a bolonhesa feito na Bianca, presunto, mussarela, purê de batata e arroz branco.', [74.90, 144.90, 229.90]],
 ['Parmegiana de Filé de Frango', 'Filé de peito de frango à milanesa, molho ao sugo feito na Bianca, mussarela, purê de batata e arroz branco.', [54.90, 99.90, 149.90]],
 ['Parmegiana de Filé de Tilápia', 'Filé de tilápia empanado, molho ao sugo feito na Bianca, mussarela, purê de batata e arroz branco.', [59.90, 109.90, 169.90]],
 ['Filé à Bianca Especial', 'Filé mignon grelhado acompanhado de arroz com alho frito, batatas fritas, vinagrete fresco e farofa de ovos temperada.', [67.90, 119.90, 189.90]],
 ['Medalhão à Piamontesa', 'Filé mignon ao tornedor (corte alto) com bacon, molho madeira da Bianca, purê de batata e arroz à piamontesa (arroz branco, creme de leite, mussarela e champignon). Molho madeira: 48 horas de preparo.', [84.90, 144.90, 239.90]],
 ['Filé ao Molho Madeira', 'Filé mignon grelhado, mergulhado no molho madeira com champignon, feito na Bianca (48 horas de preparo). Acompanhado de arroz branco, purê de batata e batata frita.', [59.90, 114.90, 176.90]],
 ['Filé na Manteiga', 'Simples e atemporal. Filé mignon grelhado, acompanhado do saboroso arroz sujo (arroz branco passado no caldo do filé) e batata frita.', [54.90, 99.90, 169.90]],
 ['Filé à Francesa', 'Filé mignon grelhado, acompanhado de arroz branco, batata palha especial, ervilha e fatias de presunto.', [64.90, 119.90, 182.90]],
 ['Peito de Frango Grelhado', 'Filé de peito de frango grelhado acompanhado de arroz branco, seleta de legumes passada na manteiga e batata frita.', [34.90, 69.90, 99.90], 'peito-frango'],
 ['Filé de Tilápia Empanado', 'Filé de tilápia empanado, acompanhado de arroz com passas, molho tártaro feito pela casa e purê de batata.', [49.90, 89.90, 119.90], 'file-tilapia'],
];
foreach ($pratos as $i => $p) { $id = $prod($pr, $p[0], $p[1], 0, $p[3] ?? null, null, $i); $sizes($id, array_map(fn($k) => [$PS[$k][0], $PS[$k][1], $p[2][$k]], array_keys($PS))); }

// ---- Massas ----
$ma = $cat('Massas', 4);
foreach ([['Lasanha à Bolonhesa', 'Massa macia intercalada de mussarela, presunto e o clássico molho à bolonhesa, feito na casa.', 59.90, 'lasanha'], ['Canelone', 'Massa cilíndrica recheada de frango, mussarela, presunto e coberta pelo clássico molho à bolonhesa da casa.', 64.90, 'canelone']] as $i => $r) {
    $id = $prod($ma, $r[0], $r[1] . ' Porção individual.', $c($r[2]), $r[3], null, $i);
    $g = Db::insert('option_groups', ['product_id' => $id, 'name' => 'Acréscimo', 'min_sel' => 0, 'max_sel' => 1, 'sort' => 0]);
    Db::insert('options', ['group_id' => $g, 'name' => 'Catupiry Original', 'price_cents' => $c(12.90), 'sort' => 0, 'available' => 1]);
}

// ---- Guarnições, sobremesas, bebidas ----
$gu = $cat('Guarnições', 5);
foreach ([['Arroz Branco', null, 9.90], ['Feijão', null, 9.90], ['Arroz à Piamontesa', 'Uma fusão de arroz branco, creme de leite, mussarela e champignon (cogumelo Paris).', 29.90]] as $i => $r) $prod($gu, $r[0], $r[1], $c($r[2]), null, null, $i);
$so = $cat('Sobremesas', 6);
foreach ([['Mini Pudim Artesanal', 'Feito na Bianca somente com a gema do ovo e ingredientes Itambé, com calda de caramelo feita no ponto.', 19.90, 'pudim'], ['Torta Holandesa', 'Biscoito na base, com creme suave e aerado com toque de manteiga e baunilha no meio, coberto por chocolate meio amargo cremoso. Preparada pela parceira Tia Eliana.', 29.90], ['Café Expresso', null, 7.90]] as $i => $r) $prod($so, $r[0], $r[1], $c($r[2]), $r[3] ?? null, null, $i);
$be = $cat('Bebidas', 7);
$drinks = [
 ['Heineken 600 ml', 19.90], ['Original 600 ml', 18.90], ['Spaten 600 ml', 16.90], ['Brahma 600 ml', 13.90], ['Heineken 473 ml', 11.90], ['Brahma 473 ml', 8.90], ['Heineken Zero Long Neck', 12.90],
 ['Suco de Laranja Natural - Copo 300 ml', 9.90], ['Suco de Laranja Natural - Jarra 750 ml', 21.90], ['Limonada Suíça - Copo 300 ml', 9.90], ['Suco Del Valle - Lata', 8.90],
 ['Coca-Cola Lata', 7.90], ['Coca-Cola Zero Lata', 7.90], ['Guaraná Lata', 7.90], ['Guaraná Zero Lata', 7.90], ['Fanta Laranja Lata', 7.90], ['Água Tônica Lata', 7.90],
 ['Coca-Cola 2 Litros', 19.90], ['Coca-Cola Zero 2 Litros', 19.90], ['Guaraná 2 Litros', 19.90], ['Guaraná Zero 2 Litros', 19.90],
 ['H2Oh! Limoneto 500 ml', 9.90], ['Água sem Gás 500 ml', 6.90], ['Água com Gás 500 ml', 6.90], ['Red Bull 250 ml', 14.90],
 ['Vinho Hormiga Negra (Malbec, seco)', 49.90], ['Vinho Chac Chac (Cabernet Franc, seco)', 79.90], ['Vinho Cordero con Piel de Lobo (Malbec, seco)', 99.90], ['Vinho La Viña (tinto suave)', 49.90],
];
foreach ($drinks as $i => $r) $prod($be, $r[0], null, $c($r[1]), null, null, $i);

// imagem de cada categoria sem foto própria fica sem imagem (placeholder no front)
echo "Cardápio importado do PDF.\n";
// destinos de impressão (códigos estáveis): bebidas vão à copa/bar; sobremesas ficam SEM destino até o proprietário definir (pendência visível); o resto vai à cozinha
Db::exec("UPDATE categories SET sector = CASE WHEN name = 'Bebidas' THEN 'copa' ELSE 'cozinha' END WHERE sector IS NULL AND name NOT LIKE '%obremesa%'");
require __DIR__ . "/seed_ingredients.php";
