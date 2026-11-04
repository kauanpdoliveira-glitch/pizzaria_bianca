<?php
declare(strict_types=1);
/**
 * Ingredientes das pizzas, transcritos das DESCRIÇÕES do cardápio em PDF (nada inventado).
 * Idempotente: só cadastra para pizzas que ainda não têm ingredientes (não sobrescreve o que o proprietário editou).
 * Regra padrão: ingrediente retirável, exceto quando o sabor tem um único ingrediente (retirar tudo não faz sentido).
 * Quem decide o que pode ser retirado é o proprietário, no catálogo.
 */
require_once __DIR__ . '/../src/bootstrap.php';
Db::migrate();
$map = [
    'À Moda' => ['calabresa defumada', 'presunto', 'azeitona', 'pimentão', 'cebola'],
    'À Moda Especial' => ['salaminho', 'presunto', 'azeitona', 'pimentão', 'cebola'],
    'Aliche Nacional' => ['aliche'], 'Atum' => ['atum'],
    'Audácia' => ['frango desfiado', 'cebola', 'Catupiry Original', 'batata palha'],
    'Calabresa' => ['calabresa'], 'Carne Seca com Catupiry' => ['carne seca', 'Catupiry Original', 'cebola'],
    'Frango à Bolonhesa' => ['frango desfiado', 'molho à bolonhesa'], 'Frango com Catupiry' => ['frango desfiado', 'Catupiry Original'],
    'Lombinho com Champignon e Tomate' => ['lombinho defumado', 'champignon', 'tomate'], 'Lombinho com Catupiry e Milho' => ['lombinho canadense', 'Catupiry Original', 'milho'],
    'Lombo Defumado' => ['lombo defumado'], 'Margherita' => ['tomate', 'manjericão'], 'Mussarela' => ['mussarela'],
    'Palmito à Bolonhesa' => ['palmito', 'molho à bolonhesa'], 'Portuguesa' => ['presunto', 'calabresa defumada', 'bacon', 'ovos', 'tomate', 'azeitona', 'pimentão', 'cebola'],
    'Presunto' => ['presunto'], 'Presunto com Catupiry' => ['presunto', 'Catupiry Original'], 'Presunto, Tomate, Bacon e Milho' => ['presunto', 'tomate', 'bacon', 'milho'],
    'Quatro Queijos' => ['mussarela', 'gorgonzola', 'provolone', 'Catupiry Original', 'manjericão seco'], 'Salaminho' => ['salaminho'],
    'Tropical' => ['presunto', 'bacon', 'pêssego', 'abacaxi', 'figo', 'ameixa'],
    'Vegetariana' => ['tomate', 'pimentão verde', 'cebola', 'azeitona', 'seleta de legumes', 'palmito'],
];
$n = 0;
foreach ($map as $prod => $ings) {
    $pid = Db::val("SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id WHERE p.name = ? AND c.is_pizza = 1", [$prod]);
    if (!$pid || Db::val('SELECT 1 FROM product_ingredients WHERE product_id = ?', [$pid])) continue;
    foreach ($ings as $i => $ing) Db::insert('product_ingredients', ['product_id' => $pid, 'name' => $ing, 'removable' => count($ings) > 1 ? 1 : 0, 'sort' => $i]);
    $n++;
}
echo "Ingredientes cadastrados para $n pizza(s).\n";
