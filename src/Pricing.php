<?php
declare(strict_types=1);

/** Regras de preço e validação do carrinho. Nunca confia em preços vindos do cliente. */
final class Pricing
{
    /**
     * Cardápio público. Produtos "ocultos" não saem daqui (e também não podem ser comprados por chamada direta).
     * status: active = disponível · out = em falta (aparece, não pode ser comprado).
     */
    public static function menu(): array
    {
        $cats = Db::all('SELECT * FROM categories WHERE active = 1 ORDER BY sort, id');
        $prods = Db::all('SELECT * FROM products WHERE hidden = 0 ORDER BY sort, id');
        $sizes = Db::all('SELECT * FROM product_sizes ORDER BY sort, id');
        $groups = Db::all('SELECT * FROM option_groups ORDER BY sort, id');
        $opts = Db::all('SELECT * FROM options ORDER BY sort, id');
        $ings = Db::all('SELECT * FROM product_ingredients ORDER BY sort, id');
        $sp = [];
        foreach (Db::all('SELECT * FROM option_size_prices') as $r) $sp[$r['option_id']][$r['size_name']] = (int)$r['price_cents'];
        $optsBy = [];
        foreach ($opts as $o) $optsBy[$o['group_id']][] = [
            'id' => (int)$o['id'], 'name' => $o['name'], 'description' => $o['description'], 'price_cents' => (int)$o['price_cents'],
            'size_prices' => (object)($sp[$o['id']] ?? []), 'size_restricted' => (bool)$o['size_restricted'], 'available' => (bool)$o['available'],
        ];
        $groupOut = fn(array $g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'min' => (int)$g['min_sel'], 'max' => (int)$g['max_sel'], 'options' => $optsBy[$g['id']] ?? []];
        $sizesBy = []; foreach ($sizes as $s) $sizesBy[$s['product_id']][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'detail' => $s['detail'], 'price_cents' => (int)$s['price_cents'], 'available' => (bool)$s['available']];
        $ingBy = []; foreach ($ings as $i) $ingBy[$i['product_id']][] = ['id' => (int)$i['id'], 'name' => $i['name'], 'removable' => (bool)$i['removable']];
        $parentOf = array_column($cats, 'parent_id', 'id');
        $out = [];
        foreach ($cats as $c) {
            // grupos da categoria valem também para suas subcategorias
            $ids = array_filter([(int)$c['id'], (int)($c['parent_id'] ?? 0)]);
            $cg = array_values(array_map($groupOut, array_filter($groups, fn($g) => $g['category_id'] !== null && in_array((int)$g['category_id'], $ids, true))));
            $items = [];
            foreach ($prods as $p) {
                if ((int)$p['category_id'] !== (int)$c['id']) continue;
                $pg = array_values(array_map($groupOut, array_filter($groups, fn($g) => (int)$g['product_id'] === (int)$p['id'])));
                $items[] = [
                    'id' => (int)$p['id'], 'name' => $p['name'], 'description' => $p['description'], 'image' => $p['image'], 'badge' => $p['badge'],
                    'status' => $p['available'] ? 'active' : 'out', 'available' => (bool)$p['available'], 'price_cents' => (int)$p['base_price_cents'],
                    'sizes' => $sizesBy[$p['id']] ?? [], 'groups' => array_merge($cg, $pg), 'ingredients' => $ingBy[$p['id']] ?? [],
                ];
            }
            $out[] = ['id' => (int)$c['id'], 'parent_id' => $c['parent_id'] !== null ? (int)$c['parent_id'] : null, 'name' => $c['name'], 'is_pizza' => (bool)$c['is_pizza'],
                'max_flavors' => self::maxFlavors($c), 'allow_removals' => (bool)$c['allow_removals'], 'products' => $items];
        }
        return ['categories' => $out, 'half_rule' => Settings::get('half_rule'), 'max_flavors' => (int)Settings::get('max_flavors')];
    }

    /** Máximo de sabores da categoria (regra própria ou a global). */
    public static function maxFlavors(array $cat): int
    {
        return max(1, (int)($cat['max_flavors'] ?? 0) ?: (int)Settings::get('max_flavors'));
    }

    /** Categoria "raiz": sabores só podem ser combinados dentro do mesmo grupo (ex.: salgadas e doces sob "Pizzas"). */
    private static function root(array $cat): int { return (int)($cat['parent_id'] ?? 0) ?: (int)$cat['id']; }

    /** Preço de meia a meia: 'average' (média, arredondada para cima ao centavo) ou 'highest'. */
    public static function combine(array $prices, string $rule): int
    {
        if (count($prices) === 1) return $prices[0];
        return $rule === 'highest' ? max($prices) : (int)ceil(array_sum($prices) / count($prices));
    }

    /**
     * Valida e precifica as linhas.
     * Item: product_id, size_id, flavors[] (ids, inclui o principal), options[] (ids), removals[] (ids de ingredientes), qty, notes.
     * @return array{lines:list<array>, subtotal:int}
     */
    public static function quote(array $items): array
    {
        if (!$items) throw new HttpError(422, 'Seu carrinho está vazio.');
        if (count($items) > 40) throw new HttpError(422, 'Itens demais no carrinho.');
        $rule = Settings::get('half_rule');
        $lines = []; $subtotal = 0;
        foreach ($items as $it) {
            $qty = (int)($it['qty'] ?? 0);
            if ($qty < 1 || $qty > 50) throw new HttpError(422, 'Quantidade inválida.');
            $pid = (int)($it['product_id'] ?? 0);
            $flavorIds = array_values(array_unique(array_map('intval', $it['flavors'] ?? []))) ?: [$pid];
            $flavorIds = [$pid, ...array_values(array_diff($flavorIds, [$pid]))]; // principal primeiro: define o tamanho
            $main = self::product($pid);
            $cat = Db::one('SELECT * FROM categories WHERE id = ?', [$main['category_id']]);
            if (!$cat || !$cat['active']) throw new HttpError(422, "\"{$main['name']}\" não está disponível.");
            if (count($flavorIds) > 1 && !$cat['is_pizza']) throw new HttpError(422, 'Este produto não aceita mais de um sabor.');
            if (count($flavorIds) > self::maxFlavors($cat)) throw new HttpError(422, 'Escolha no máximo ' . self::maxFlavors($cat) . ' sabores.');

            $sizeName = null; $prices = []; $names = [];
            foreach ($flavorIds as $fid) {
                $p = self::product($fid);
                $pc = Db::one('SELECT * FROM categories WHERE id = ?', [$p['category_id']]);
                if (!$pc || !$pc['active'] || self::root($pc) !== self::root($cat)) throw new HttpError(422, 'Sabores de grupos diferentes não podem ser combinados.');
                $names[] = $p['name'];
                $sizes = Db::all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort, id', [$fid]);
                if ($sizes) {
                    if ($fid === $pid) {
                        $sel = array_values(array_filter($sizes, fn($s) => (int)$s['id'] === (int)($it['size_id'] ?? 0)))[0] ?? null;
                        if (!$sel) throw new HttpError(422, "Escolha o tamanho de \"{$p['name']}\".");
                        $sizeName = $sel['name'];
                    }
                    $sel = array_values(array_filter($sizes, fn($s) => $s['name'] === $sizeName))[0] ?? null;
                    if (!$sel) throw new HttpError(422, "\"{$p['name']}\" não existe no tamanho $sizeName.");
                    $prices[] = (int)$sel['price_cents']; $selAvail = (bool)$sel['available'];
                } else { $prices[] = (int)$p['base_price_cents']; $selAvail = true; }
                // oculto = não pode ser comprado nem por chamada direta; em falta = idem, com mensagem própria
                if ($p['hidden']) throw new HttpError(422, "\"{$p['name']}\" não está disponível.");
                if (!$p['available'] || !$selAvail) throw new HttpError(422, "\"{$p['name']}\" está em falta" . ($sizeName ? " no tamanho $sizeName" : '') . '.');
            }
            $unit = self::combine($prices, $rule);

            // adicionais / bordas (grupos do produto, da categoria e da categoria-mãe)
            $catIds = array_filter([(int)$main['category_id'], (int)($cat['parent_id'] ?? 0)]);
            $ph = implode(',', array_fill(0, count($catIds), '?'));
            $groups = Db::all("SELECT * FROM option_groups WHERE product_id = ? OR category_id IN ($ph) ORDER BY sort, id", [$pid, ...$catIds]);
            $gids = array_map(fn($g) => (int)$g['id'], $groups);
            $optRows = [];
            foreach (array_values(array_unique(array_map('intval', $it['options'] ?? []))) as $oid) {
                $o = Db::one('SELECT o.*, g.name AS gname, g.id AS gid FROM options o JOIN option_groups g ON g.id = o.group_id WHERE o.id = ?', [$oid]);
                if (!$o || !in_array((int)$o['gid'], $gids, true)) throw new HttpError(422, 'Opção inválida.');
                if (!$o['available']) throw new HttpError(422, "\"{$o['name']}\" está indisponível.");
                $ov = $sizeName !== null ? Db::val('SELECT price_cents FROM option_size_prices WHERE option_id = ? AND size_name = ?', [$oid, $sizeName]) : null;
                if ($o['size_restricted'] && $ov === null) throw new HttpError(422, "\"{$o['name']}\" não está disponível no tamanho $sizeName.");
                $optRows[] = ['gid' => (int)$o['gid'], 'group' => $o['gname'], 'name' => $o['name'], 'price' => $ov !== null ? (int)$ov : (int)$o['price_cents']];
            }
            foreach ($groups as $g) {
                $n = count(array_filter($optRows, fn($r) => $r['gid'] === (int)$g['id']));
                if ($n < (int)$g['min_sel']) throw new HttpError(422, "Escolha ao menos {$g['min_sel']} em \"{$g['name']}\".");
                if ($n > (int)$g['max_sel']) throw new HttpError(422, "Máximo de {$g['max_sel']} em \"{$g['name']}\".");
            }
            $unit += array_sum(array_column($optRows, 'price'));

            // retirada de ingredientes: cada uma pertence a UM sabor (nunca vale automaticamente para as duas metades)
            $removeRows = [];
            foreach (array_values(array_unique(array_map('intval', $it['removals'] ?? []))) as $rid) {
                $ing = Db::one('SELECT i.*, p.name AS pname FROM product_ingredients i JOIN products p ON p.id = i.product_id WHERE i.id = ?', [$rid]);
                if (!$ing || !in_array((int)$ing['product_id'], $flavorIds, true)) throw new HttpError(422, 'Ingrediente não pertence aos sabores escolhidos.');
                if (!$ing['removable'] || !$cat['allow_removals']) throw new HttpError(422, "\"{$ing['name']}\" não pode ser retirado.");
                $removeRows[] = ['group' => 'Retirar', 'name' => 'sem ' . $ing['name'] . (count($flavorIds) > 1 ? ' — ½ ' . $ing['pname'] : ''), 'price_cents' => 0];
            }
            $label = count($names) > 1 ? implode(' + ', array_map(fn($n) => '½ ' . $n, $names)) : $names[0];
            $note = trim((string)($it['notes'] ?? ''));
            $lines[] = [
                'product_id' => $pid, 'name' => $label, 'size_name' => $sizeName, 'qty' => $qty,
                'unit_price_cents' => $unit, 'total_cents' => $unit * $qty, 'notes' => $note === '' ? null : mb_substr($note, 0, 200),
                'options' => array_merge(array_map(fn($r) => ['group' => $r['group'], 'name' => $r['name'], 'price_cents' => $r['price']], $optRows), $removeRows),
                'flavors' => count($names) > 1 ? $names : [],
            ];
            $subtotal += $unit * $qty;
        }
        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    private static function product(int $id): array
    {
        $p = Db::one('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p || $p['hidden']) throw new HttpError(422, 'Produto não disponível.');
        return $p;
    }
}
