<?php
declare(strict_types=1);

/**
 * Importação SEGURA e REPETÍVEL do que foi observado no cardápio público do iFood (data/ifood/*.json).
 *
 * Regras:
 *  - Preços do iFood são do CANAL iFood: viram "observações" (channel_observations); NUNCA mudam preços do site.
 *  - Só casa produto por nome normalizado EXATO (categoria/tamanho conferidos). Nome parecido = "provável", fica pendente.
 *  - Produto novo (sem equivalente) entra OCULTO, como rascunho, com o valor de referência.
 *  - Foto só entra em produto SEM foto; foto cadastrada à mão nunca é trocada. Requer $opt['photos'] = true.
 *  - Sem $opt['apply'] nada é gravado (simulação / relatório de diferenças).
 *  - Reexecutar não duplica produtos, observações nem imagens.
 */
final class IfoodImport
{
    private const SOURCE = 'ifood';
    /** Nomes do iFood sem igualdade exata, mas com equivalente PROVÁVEL no catálogo: só relatório, nada é ligado automaticamente. */
    public const CANDIDATES = [
        'Filé Mignon à Parmegiana' => 'Parmegiana de Filé Mignon', 'Filé de Frango à Parmegiana' => 'Parmegiana de Filé de Frango',
        'Filé de Tilápia a Parmegiana' => 'Parmegiana de Filé de Tilápia', 'Medalhão à Piamontese' => 'Medalhão à Piamontesa',
        'Filé de Tilápia Empanado - Prato' => 'Filé de Tilápia Empanado', 'Batata Frita - 500g' => 'Batata Frita',
        'Bolinho de Bacalhau do Porto (15 unidades)' => 'Bolinho de Bacalhau - 15 unid.', 'Filé de Tilápia Empanado - Porção de 500g' => 'Iscas de Tilápia Empanadas',
        'Pastel de Angu com Carne Seca e Requeijão (10 unidades)' => 'Pastel de Angu com Carne Seca - 10 unid.', 'Pastel de Aipim com Carne Moída (10 unidades)' => 'Pastel de Aipim com Carne Moída - 10 unid.',
        'Filé Mignon com Fritas - 650g' => 'Filé Mignon com Fritas', 'Filé de Frango com Fritas - 650g' => 'Filé de Frango com Fritas',
        'Pudim Artesanal - Individual' => 'Mini Pudim Artesanal', 'Torta Holandesa - Individual' => 'Torta Holandesa',
        'Água Tônica Antarctica Lata 350ml' => 'Água Tônica Lata', 'Coca-Cola Lata 350ml' => 'Coca-Cola Lata', 'Coca-Cola Sem Açúcar 350ml' => 'Coca-Cola Zero Lata',
        'Coca-Cola Zero Garrafa 2l' => 'Coca-Cola Zero 2 Litros', 'Fanta Laranja Lata 350ml' => 'Fanta Laranja Lata', 'Refr Guaraná Antarctica Zero 2l' => 'Guaraná Zero 2 Litros',
        'Refrigerante Coca-Cola Original 2l' => 'Coca-Cola 2 Litros', 'Refrigerante Guaraná Antarctica 2l' => 'Guaraná 2 Litros', 'Refrigerante Guaraná Antarctica Lata 350ml' => 'Guaraná Lata',
        'Refrigerante Guaraná Antarctica sem Açúcar Lata 350ml' => 'Guaraná Zero Lata', 'Refrigerante Limoneto H2oh 500ml' => 'H2Oh! Limoneto 500 ml', 'Suco Del Valle Lata - Pêssego 290ml' => 'Suco Del Valle - Lata',
    ];
    /**
     * Equivalentes CONFIRMADOS (04/11/2026, por composição/gramagem/unidades idênticas nas descrições; bebidas por marca e tamanho padrão —
     * "lata" = 350 ml é premissa). Ficam de fora, por ambiguidade: Coca-Cola Sem Açúcar x Zero Lata, Suco Del Valle (sabor) e Pudim (mini x individual).
     */
    public const CONFIRMED = [
        'Filé Mignon à Parmegiana', 'Filé de Frango à Parmegiana', 'Filé de Tilápia a Parmegiana', 'Medalhão à Piamontese', 'Filé de Tilápia Empanado - Prato',
        'Batata Frita - 500g', 'Bolinho de Bacalhau do Porto (15 unidades)', 'Filé de Tilápia Empanado - Porção de 500g',
        'Pastel de Angu com Carne Seca e Requeijão (10 unidades)', 'Pastel de Aipim com Carne Moída (10 unidades)', 'Filé Mignon com Fritas - 650g', 'Filé de Frango com Fritas - 650g',
        'Torta Holandesa - Individual', 'Água Tônica Antarctica Lata 350ml', 'Coca-Cola Lata 350ml', 'Coca-Cola Zero Garrafa 2l', 'Fanta Laranja Lata 350ml',
        'Refr Guaraná Antarctica Zero 2l', 'Refrigerante Coca-Cola Original 2l', 'Refrigerante Guaraná Antarctica 2l', 'Refrigerante Guaraná Antarctica Lata 350ml',
        'Refrigerante Guaraná Antarctica sem Açúcar Lata 350ml', 'Refrigerante Limoneto H2oh 500ml',
    ];
    /** Itens sem equivalente (volume/marca/composição distintos): entram como rascunho OCULTO. nome => categoria. */
    public const DRAFTS = [
        'Batata Frita com Mussarela - 500g' => 'Entradas',
        'Água Mineral Com Gás São Lourenço 510ml' => 'Bebidas',
        'Água Mineral Sem Gás São Lourenço 510ml' => 'Bebidas',
    ];

    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c']);
        return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** @return array<string,mixed> relatório */
    public static function run(array $data, array $opt = []): array
    {
        $apply = !empty($opt['apply']);
        $photos = !empty($opt['photos']);
        $origDir = $opt['originals_dir'] ?? (APP_ROOT . '/storage/ifood/original');
        $upDir = $opt['uploads_dir'] ?? (APP_ROOT . '/public/uploads');
        $meta = $data['meta'];
        $when = $meta['consulted_at'] . ' 00:00:00';
        $rep = ['apply' => $apply, 'photos' => $photos, 'matched' => 0, 'obs_new' => 0, 'obs_updated' => 0, 'drafts_new' => 0, 'drafts_existing' => 0,
            'photos_linked' => [], 'photos_pending' => [], 'photos_skipped_has_image' => [], 'candidates' => [], 'unmatched' => [], 'price_deltas' => [], 'size_missing' => [], 'drafts' => []];

        // índice do catálogo por nome normalizado
        $idx = [];
        foreach (Db::all('SELECT p.id, p.name, p.image, p.category_id, c.is_pizza FROM products p LEFT JOIN categories c ON c.id = p.category_id') as $p) $idx[self::norm($p['name'])][] = $p;
        $find = function (string $name, ?bool $pizza = null) use ($idx): ?array {
            $c = $idx[self::norm($name)] ?? [];
            if ($pizza !== null) $c = array_values(array_filter($c, fn($p) => (bool)$p['is_pizza'] === $pizza));
            if (count($c) === 1) return $c[0];
            if ($pizza === false && in_array($name, self::CONFIRMED, true) && isset(self::CANDIDATES[$name])) { $c = $idx[self::norm(self::CANDIDATES[$name])] ?? []; return count($c) === 1 ? $c[0] : null; }
            return null;
        };

        $obs = function (string $key, string $kind, ?int $pid, ?string $size, string $name, ?int $cents, string $status, ?string $note, ?string $img = null) use (&$rep, $apply, $meta, $when) {
            $row = Db::one('SELECT id, product_id, status FROM channel_observations WHERE source = ? AND source_key = ?', [self::SOURCE, $key]);
            if (!$apply) { $row ? $rep['obs_updated']++ : $rep['obs_new']++; return; }
            if ($row && $row['status'] === 'rascunho') { $status = 'rascunho'; $note = null; } // rascunho oculto continua rascunho até decisão comercial
            $d = ['kind' => $kind, 'size_name' => $size, 'name' => $name, 'observed_cents' => $cents, 'status' => $status, 'note' => $note, 'source_url' => $meta['source_url'], 'image_origin' => $img, 'observed_at' => $when];
            if ($row) { if (!$row['product_id'] && $pid) $d['product_id'] = $pid; Db::update('channel_observations', (int)$row['id'], $d); $rep['obs_updated']++; }
            else { Db::insert('channel_observations', $d + ['source' => self::SOURCE, 'source_key' => $key, 'product_id' => $pid]); $rep['obs_new']++; }
        };
        $delta = function (string $label, int $own, int $obs) use (&$rep) { $rep['price_deltas'][] = ['label' => $label, 'own' => $own, 'ifood' => $obs]; };
        $ownSize = fn(int $pid, string $size) => Db::val('SELECT price_cents FROM product_sizes WHERE product_id = ? AND name = ?', [$pid, $size]);
        $cents = fn(float $v) => (int)round($v * 100);

        // ---- Pizzas: preço observado por sabor/tamanho (1 sabor) ----
        $cols = $data['pizza_flavor_columns'];
        $quarantine = [];
        foreach ($data['pizza_flavors'] as $f) {
            $p = $find($f['name'], true);
            if (!$p) { $rep['unmatched'][] = $f['name']; continue; }
            $rep['matched']++;
            foreach ($cols as $i => $size) {
                $o = $cents($f['p'][$i]) + 1; // iFood: preço-base R$ 0,01 + valor exibido
                $own = $ownSize((int)$p['id'], $size);
                if ($own === null) { $rep['size_missing'][] = $f['name'] . ' / ' . $size; $obs("price:" . self::norm($f['name']) . ":$size", 'price', (int)$p['id'], $size, $f['name'], $o, 'incompleto', 'tamanho inexistente no site'); continue; }
                $delta($f['name'] . ' ' . $size, (int)$own, $o);
                $obs("price:" . self::norm($f['name']) . ":$size", 'price', (int)$p['id'], $size, $f['name'], $o, isset($f['note']) ? 'incompleto' : 'verificado', $f['note'] ?? null);
            }
            $quarantine[] = [$p, $f['img'], $f['name']];
        }
        // ---- Pratos (P/M/G) ----
        foreach ($data['dishes'] as $d) {
            $p = $find($d['name'], false);
            $sizes = ['P' => $cents($d['p']), 'M' => $cents($d['p'] + $d['m_add']), 'G' => $cents($d['p'] + $d['g_add'])];
            if (!$p) { self::pending($rep, $d['name'], $find); foreach ($sizes as $s => $v) $obs("price:" . self::norm($d['name']) . ":$s", 'price', null, $s, $d['name'], $v, 'pendente', self::candNote($d['name'])); continue; }
            $rep['matched']++;
            foreach ($sizes as $s => $v) { $own = $ownSize((int)$p['id'], $s); if ($own !== null) $delta($d['name'] . ' ' . $s, (int)$own, $v); $obs("price:" . self::norm($d['name']) . ":$s", 'price', (int)$p['id'], $s, $d['name'], $v, 'verificado', $d['extra_group'] ?? null); }
            if (!empty($d['img'])) $quarantine[] = [$p, $d['img'], $d['name']];
        }
        // ---- Massas, porções, sobremesas, bebidas (preço único) ----
        foreach (['massas', 'porcoes', 'sobremesas', 'bebidas'] as $grp) foreach ($data[$grp] as $it) {
            $name = $it['name']; $key = "price:" . self::norm($name);
            $p = $find($name, false);
            if ($p) {
                $rep['matched']++; $own = (int)Db::val('SELECT base_price_cents FROM products WHERE id = ?', [$p['id']]);
                $delta($name, $own, $cents($it['p']));
                $obs($key, 'price', (int)$p['id'], null, $name, $cents($it['p']), 'verificado', $it['note'] ?? null);
                if (!empty($it['img'])) $quarantine[] = [$p, $it['img'], $name];
            } elseif (isset(self::DRAFTS[$name])) {
                self::draft($rep, $apply, $name, self::DRAFTS[$name], $cents($it['p']), $it, $obs, $key);
            } else {
                self::pending($rep, $name, $find);
                $obs($key, 'price', null, null, $name, $cents($it['p']), 'pendente', self::candNote($name) . ($it['note'] ?? ''));
            }
        }
        // ---- Borda (opção) ----
        foreach ($meta ? $data['pizza_rules_ifood']['borda'] : [] as $k => $v) {
            if (!in_array($k, ['Gigante', 'Grande', 'Média', 'Brotinho'], true)) continue;
            $own = Db::val("SELECT osp.price_cents FROM option_size_prices osp JOIN options o ON o.id = osp.option_id WHERE o.name LIKE 'Borda de Catupiry%' AND osp.size_name = ?", [$k]);
            if ($own !== null) $delta("Borda de Catupiry Original $k", (int)$own, $cents((float)$v));
            $obs("option:borda-catupiry:$k", 'option', null, $k, 'Borda de Catupiry Original', $cents((float)$v), 'verificado', 'única borda encontrada no iFood');
        }
        foreach (['Empanado ou Grelhado (Filé Mignon à Parmegiana): grupo obrigatório sem acréscimo — não existe no site; decisão pendente.'] as $n) $obs('option:mignon-modo', 'option', null, null, 'Filé mignon: Empanado/Grelhado', 0, 'pendente', $n);
        foreach (['Cobertura Catupiry Original' => 19.90] as $n => $v) $obs('option:massas-catupiry', 'option', null, null, "$n (Lasanha/Canelone)", $cents($v), 'verificado', 'no site o acréscimo equivalente tem preço próprio');
        foreach (['Adicionar mussarela' => 14.00, 'Adicionar mussarela e bacon' => 19.00] as $n => $v) $obs('option:batata-' . self::norm($n), 'option', null, null, "$n (Batata Frita)", $cents($v), 'verificado', 'no site: acréscimos separados de mussarela/bacon com preço próprio');
        // ---- Combos: só registrados como canal exclusivo ----
        foreach ($data['combos_ifood_only'] as $c) $obs('combo:' . self::norm($c['name']), 'combo', null, null, $c['name'], $cents($c['p']), 'pendente', 'promoção/combo do iFood; não importado; decisão comercial pendente');

        // ---- Fotos: somente produto exato e SEM foto; mantém foto manual ----
        foreach ($quarantine as [$p, $file, $name]) {
            $cur = Db::val('SELECT image FROM products WHERE id = ?', [$p['id']]);
            if ($cur) { $rep['photos_skipped_has_image'][] = $name; continue; }
            $src = rtrim($origDir, '/\\') . '/' . $file;
            if (!is_file($src)) { $rep['photos_pending'][] = "$name (arquivo original ausente)"; continue; }
            if (!$photos) { $rep['photos_pending'][] = "$name ← $file"; continue; }
            $rel = '/uploads/ifood/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';
            if ($apply) {
                $dst = rtrim($upDir, '/\\') . '/ifood/' . basename($rel);
                if (!is_file($dst)) { if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true); self::derive($src, $dst); }
                Db::update('products', (int)$p['id'], ['image' => $rel]);
                $obs('image:' . pathinfo($file, PATHINFO_FILENAME), 'image', (int)$p['id'], null, $name, null, 'verificado', 'foto do cardápio da própria Bianca no iFood (autorização de uso a confirmar antes de publicar)', $meta['image_base'] . $file);
            }
            $rep['photos_linked'][] = "$name ← $rel";
        }
        return $rep;
    }

    private static function pending(array &$rep, string $name, callable $find): void
    {
        $c = self::CANDIDATES[$name] ?? null;
        if ($c) $rep['candidates'][$name] = $c; else $rep['unmatched'][] = $name;
    }
    private static function candNote(string $name): string { return isset(self::CANDIDATES[$name]) ? 'provável equivalente: ' . self::CANDIDATES[$name] . ' (nome diferente; não ligado automaticamente). ' : 'sem equivalente no catálogo. '; }

    private static function draft(array &$rep, bool $apply, string $name, string $category, int $cents, array $it, callable $obs, string $key): void
    {
        $existing = Db::one('SELECT product_id FROM channel_observations WHERE source = ? AND source_key = ?', [self::SOURCE, $key]);
        if ($existing && $existing['product_id']) { $rep['drafts_existing']++; $obs($key, 'price', (int)$existing['product_id'], null, $name, $cents, 'rascunho', 'rascunho oculto; defina o preço próprio'); return; }
        $cat = Db::one('SELECT id FROM categories WHERE name = ? AND parent_id IS NULL', [$category]);
        $rep['drafts'][] = "$name → $category (oculto, referência R$ " . number_format($cents / 100, 2, ',', '.') . ')';
        $rep['drafts_new']++;
        if (!$apply || !$cat) return;
        $pid = Db::insert('products', ['category_id' => (int)$cat['id'], 'name' => $name, 'description' => $it['note'] ?? null, 'base_price_cents' => $cents, 'available' => 1, 'hidden' => 1, 'badge' => null, 'sort' => 900]);
        $obs($key, 'price', (int)$pid, null, $name, $cents, 'rascunho', 'rascunho oculto criado da observação do iFood; o valor é de REFERÊNCIA: defina o preço próprio antes de ativar');
    }

    /** Reencoda (descarta metadados), no máximo 1200 px de largura. */
    private static function derive(string $src, string $dst): void
    {
        $im = @imagecreatefromjpeg($src) ?: @imagecreatefrompng($src) ?: @imagecreatefromwebp($src);
        if (!$im) throw new RuntimeException("imagem inválida: $src");
        if (imagesx($im) > 1200) $im = imagescale($im, 1200, -1);
        imagejpeg($im, $dst, 82);
    }

    /** Desfaz só as fotos vindas daqui (útil se a autorização de uso não for confirmada). */
    public static function unlinkPhotos(string $uploadsDir): int
    {
        $n = Db::exec("UPDATE products SET image = NULL WHERE image LIKE '/uploads/ifood/%'");
        Db::exec("DELETE FROM channel_observations WHERE source = 'ifood' AND kind = 'image'");
        foreach (glob(rtrim($uploadsDir, '/\\') . '/ifood/*.jpg') ?: [] as $f) @unlink($f);
        return (int)$n;
    }

    public static function format(array $r): string
    {
        $m = fn($c) => number_format($c / 100, 2, ',', '.');
        $o = [($r['apply'] ? 'GRAVADO' : 'SIMULAÇÃO (nada foi gravado)') . ($r['photos'] ? ' · fotos habilitadas' : ' · fotos NÃO habilitadas')];
        $o[] = "Produtos com equivalente exato: {$r['matched']} · observações novas: {$r['obs_new']}, atualizadas: {$r['obs_updated']}";
        $o[] = "Rascunhos ocultos novos: {$r['drafts_new']} (já existentes: {$r['drafts_existing']})"; foreach ($r['drafts'] as $d) $o[] = "  + $d";
        $o[] = 'Fotos ' . ($r['apply'] ? 'ligadas' : 'a ligar') . ': ' . count($r['photos_linked']) . ' · pendentes: ' . count($r['photos_pending']) . ' · produto já tinha foto (mantida): ' . count($r['photos_skipped_has_image']);
        foreach ($r['photos_pending'] as $x) $o[] = "  ? $x";
        if ($r['candidates']) { $o[] = 'Equivalentes PROVÁVEIS (nome diferente; nada ligado):'; foreach ($r['candidates'] as $k => $v) $o[] = "  ~ $k  ≈  $v"; }
        if ($r['unmatched']) { $o[] = 'Sem equivalente: ' . implode('; ', $r['unmatched']); }
        if ($r['size_missing']) $o[] = 'Tamanho ausente no site: ' . implode('; ', $r['size_missing']);
        $d = $r['price_deltas'];
        if ($d) {
            $pct = array_map(fn($x) => $x['own'] > 0 ? ($x['ifood'] - $x['own']) / $x['own'] * 100 : 0, $d);
            sort($pct);
            $o[] = sprintf('Preços comparados: %d · iFood x site: mediana %+.1f%% (mín %+.1f%%, máx %+.1f%%) — preços do site NÃO foram alterados.', count($d), $pct[intdiv(count($pct), 2)], $pct[0], end($pct));
            $below = array_filter($d, fn($x) => $x['ifood'] < $x['own']);
            foreach ($below as $x) $o[] = '  iFood MENOR que o site: ' . $x['label'] . ' (site R$ ' . $m($x['own']) . ' · iFood R$ ' . $m($x['ifood']) . ')';
        }
        return implode("\n", $o) . "\n";
    }
}
