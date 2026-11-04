<?php
declare(strict_types=1);

/**
 * Layouts de comandas: estrutura de dados VALIDADA (nunca HTML, JavaScript ou comandos brutos do usuário).
 * - Um layout por destino e versão; versões ativadas/arquivadas são IMUTÁVEIS (editar cria um novo rascunho).
 * - O trabalho guarda o layout_id com que nasceu; reimpressão reusa o mesmo layout. Mudar o layout não altera trabalhos já criados.
 * - Campos essenciais (número, modalidade, itens com quantidades e observações de preparo) não podem ser ocultados.
 * - Marcas de TESTE, REIMPRESSÃO, ALTERADO e CANCELADO são inseridas pelo sistema e não são editáveis.
 */
final class PrintLayout
{
    public const SIZES = ['normal' => 'Normal', 'large' => 'Grande', 'xl' => 'Extra (só HTML; em térmica vira Grande)'];
    public const ALIGNS = ['left' => 'Esquerda', 'center' => 'Centro', 'right' => 'Direita'];
    public const SEPARATORS = ['-' => 'Traço ( ---- )', '=' => 'Igual ( ==== )', '*' => 'Asterisco ( **** )', '.' => 'Pontos ( .... )', 'none' => 'Sem separador'];
    public const SPACINGS = ['compact' => 'Compacto (sem separadores)', 'normal' => 'Normal', 'airy' => 'Arejado (linha em branco entre blocos)'];
    public const SCENARIOS = ['simple' => 'Pedido simples', 'long' => 'Pedido extenso', 'half' => 'Pizza de dois sabores', 'notes' => 'Observações longas', 'delivery' => 'Entrega', 'pickup' => 'Retirada', 'table' => 'Mesa/comanda'];

    /** Blocos por destino: id => [rótulo, obrigatório, só aplicável a (balcão/entrega...)] */
    public static function catalog(string $sector): array
    {
        $c = [
            'order_number' => ['Número do pedido (ou mesa)', true],
            'datetime' => ['Data e horário', false],
            'modality' => ['Modalidade (entrega/retirada/salão)', true],
        ];
        if ($sector === 'balcao') $c += ['customer_name' => ['Nome do cliente', false], 'customer_phone' => ['Telefone', false], 'address' => ['Endereço (só entrega)', false], 'reference' => ['Referência (só entrega)', false]];
        $c += ['items' => ['Itens, tamanhos, sabores, adicionais, retiradas e observações de cada item', true]];
        if ($sector !== 'copa') $c += ['order_notes' => ['Observações do pedido', false]];
        if ($sector === 'balcao') $c += ['totals' => ['Subtotal, taxa, desconto e total', false], 'payment' => ['Forma e situação do pagamento', false], 'change' => ['Troco', false]];
        return $c;
    }

    private static function blockDefaults(string $id): array
    {
        return match ($id) {
            'order_number' => ['align' => 'center', 'size' => 'large', 'bold' => false],
            'datetime' => ['align' => 'center', 'size' => 'normal', 'bold' => false],
            'modality' => ['align' => 'center', 'size' => 'large', 'bold' => true],
            'items' => ['align' => 'left', 'size' => 'normal', 'bold' => true],
            'totals' => ['align' => 'center', 'size' => 'large', 'bold' => true],
            'payment' => ['align' => 'left', 'size' => 'large', 'bold' => true],
            'order_notes' => ['align' => 'left', 'size' => 'normal', 'bold' => true],
            default => ['align' => 'left', 'size' => 'normal', 'bold' => false],
        };
    }

    public static function defaults(string $sector): array
    {
        $blocks = [];
        foreach (self::catalog($sector) as $id => [$label, $req]) {
            $b = ['id' => $id, 'show' => true] + self::blockDefaults($id);
            if ($id === 'items') $b += ['options' => true, 'prices' => $sector === 'balcao'];
            $blocks[] = $b;
        }
        return ['page' => ['separator' => '-', 'spacing' => 'normal', 'cols' => null, 'margin' => null],
            'header' => ['brand' => true, 'brand_text' => 'PIZZARIA BIANCA', 'subtitle' => true, 'logo' => false],
            'footer' => ['text' => ''], 'blocks' => $blocks];
    }

    /** Modelos iniciais (estruturas válidas; o proprietário parte de um deles e edita). */
    public static function presets(string $sector): array
    {
        $std = self::defaults($sector);
        $mini = $std; $mini['page']['spacing'] = 'airy';
        foreach ($mini['blocks'] as &$b) {
            if ($b['id'] === 'order_number') { $b['size'] = 'xl'; $b['bold'] = true; }
            if ($b['id'] === 'datetime') $b['bold'] = false;
            if ($b['id'] === 'items') { $b['bold'] = false; $b['size'] = $sector === 'balcao' ? 'normal' : 'large'; }
            if ($b['id'] === 'totals') { $b['size'] = 'large'; }
        }
        unset($b);
        $prod = $mini; $prod['page']['separator'] = '='; $prod['header']['subtitle'] = true;
        foreach ($prod['blocks'] as &$b) { if ($b['id'] === 'items') { $b['bold'] = true; $b['size'] = 'large'; } if ($b['id'] === 'order_notes') { $b['size'] = 'large'; } }
        unset($b);
        $p = ['padrao' => ['Padrão do sistema', $std], 'minimalista' => ['Minimalista (arejado, número grande)', $mini]];
        if ($sector !== 'balcao') $p['producao'] = ['Produção em destaque (itens grandes)', $prod];
        return $p;
    }
    private static function bad(string $m): never { throw new HttpError(422, 'Layout inválido: ' . $m); }

    /** Valida e normaliza (lista branca). Qualquer chave desconhecida é descartada; valores fora da lista são recusados. */
    public static function validate(string $sector, array $in): array
    {
        if (!isset(Printing::SECTORS[$sector])) self::bad('destino desconhecido');
        $cat = self::catalog($sector); $def = self::defaults($sector);
        $page = (array)($in['page'] ?? []); $hd = (array)($in['header'] ?? []); $ft = (array)($in['footer'] ?? []);
        $sep = (string)($page['separator'] ?? '-'); if (!isset(self::SEPARATORS[$sep])) self::bad('separador');
        $sp = (string)($page['spacing'] ?? 'normal'); if (!isset(self::SPACINGS[$sp])) self::bad('espaçamento');
        $num = function ($v, int $lo, int $hi, string $nm) { if ($v === null || $v === '') return null; if (!is_numeric($v) || (int)$v != $v || (int)$v < $lo || (int)$v > $hi) self::bad("$nm deve ser um inteiro de $lo a $hi (ou vazio para usar o da impressora)"); return (int)$v; };
        $text = fn($v, int $max, string $nm) => mb_strlen($t = trim(PrintFormatter::clean((string)$v))) > $max ? self::bad("$nm passa de $max caracteres") : $t;
        $out = ['page' => ['separator' => $sep, 'spacing' => $sp, 'cols' => $num($page['cols'] ?? null, 24, 100, 'Largura útil'), 'margin' => $num($page['margin'] ?? null, 0, 10, 'Margem')],
            'header' => ['brand' => !empty($hd['brand']), 'brand_text' => $text($hd['brand_text'] ?? $def['header']['brand_text'], 40, 'Nome no cabeçalho'), 'subtitle' => !empty($hd['subtitle']), 'logo' => !empty($hd['logo'])],
            'footer' => ['text' => $text($ft['text'] ?? '', 120, 'Rodapé')], 'blocks' => []];
        if ($out['header']['brand'] && $out['header']['brand_text'] === '') self::bad('informe o nome do cabeçalho ou desligue-o');
        $seen = [];
        foreach ((array)($in['blocks'] ?? []) as $b) {
            $b = (array)$b; $id = (string)($b['id'] ?? '');
            if (!isset($cat[$id])) self::bad('bloco desconhecido "' . mb_substr($id, 0, 30) . '"');
            if (isset($seen[$id])) self::bad('bloco repetido ' . $id);
            $seen[$id] = 1;
            $d = self::blockDefaults($id);
            $al = (string)($b['align'] ?? $d['align']); $sz = (string)($b['size'] ?? $d['size']);
            if (!isset(self::ALIGNS[$al])) self::bad('alinhamento'); if (!isset(self::SIZES[$sz])) self::bad('tamanho do texto');
            $row = ['id' => $id, 'show' => $cat[$id][1] ? true : !empty($b['show']), 'align' => $al, 'size' => $sz, 'bold' => !empty($b['bold'])];
            if ($id === 'items') $row += ['options' => $sector === 'balcao' ? !empty($b['options']) : true, 'prices' => $sector === 'balcao' && !empty($b['prices'])];   // produção sempre vê personalizações e retiradas
            $out['blocks'][] = $row;
        }
        foreach ($cat as $id => [$label, $req]) if (!isset($seen[$id])) self::bad("falta o bloco \"$label\"");
        return $out;
    }

    /** Completa um layout salvo com padrões (tolerante a versões antigas). */
    private static function hydrate(string $sector, ?string $json): array
    {
        $d = json_decode((string)$json, true);
        try { return self::validate($sector, is_array($d) ? $d : []); } catch (HttpError $e) { return self::defaults($sector); }
    }

    // ---------- persistência e versões ----------
    public static function rows(string $sector): array { return Db::all('SELECT id, sector, name, status, version, parent_id, note, created_by, created_at, activated_by, activated_at FROM print_layouts WHERE sector = ? ORDER BY id DESC', [$sector]); }
    public static function get(int $id): ?array { return Db::one('SELECT * FROM print_layouts WHERE id = ?', [$id]); }
    public static function active(string $sector): ?array { return Db::one("SELECT * FROM print_layouts WHERE sector = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$sector]); }
    public static function activeId(string $sector): ?int { $a = self::active($sector); return $a ? (int)$a['id'] : null; }
    public static function dataOf(?array $row, string $sector): array { return $row ? self::hydrate($sector, $row['data_json']) : self::defaults($sector); }

    private static function nextVersion(string $sector): int { return 1 + (int)Db::val('SELECT COALESCE(MAX(version), 0) FROM print_layouts WHERE sector = ?', [$sector]); }
    private static function cleanName(string $n): string { $n = trim(PrintFormatter::clean($n)); if ($n === '' || mb_strlen($n) > 80) self::bad('nome de 1 a 80 caracteres'); return $n; }

    /** Salva como RASCUNHO. Rascunho existente é atualizado; versão ativa/arquivada nunca é alterada (vira novo rascunho). */
    public static function saveDraft(?int $id, string $sector, string $name, array $data, array $user): int
    {
        $clean = self::validate($sector, $data); $name = self::cleanName($name);
        $row = $id ? self::get($id) : null;
        if ($row && $row['sector'] !== $sector) throw new HttpError(422, 'Layout de outro destino.');
        if ($row && $row['status'] === 'draft') { Db::update('print_layouts', (int)$row['id'], ['name' => $name, 'data_json' => json_encode($clean, JSON_UNESCAPED_UNICODE)]); Auth::audit('print.layout.draft', "layout #{$row['id']} ($sector)"); return (int)$row['id']; }
        $nid = Db::insert('print_layouts', ['sector' => $sector, 'name' => $name, 'status' => 'draft', 'version' => self::nextVersion($sector), 'data_json' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'parent_id' => $row['id'] ?? null, 'created_by' => $user['name'], 'created_at' => now()]);
        Auth::audit('print.layout.draft', "layout #$nid ($sector)");
        return $nid;
    }

    /** Ativação explícita: o rascunho vira ativo; o ativo anterior é arquivado (continua vinculado aos trabalhos que já o usaram). */
    public static function activate(int $id, array $user): int
    {
        $r = self::get($id);
        if (!$r) throw new HttpError(404, 'Layout não encontrado.');
        return Db::tx(function () use ($r, $user) {
            $sector = $r['sector'];
            if ($r['status'] === 'archived') {   // restaurar: copia a versão antiga como nova versão ativa (a antiga permanece intacta)
                $nid = Db::insert('print_layouts', ['sector' => $sector, 'name' => $r['name'], 'status' => 'draft', 'version' => self::nextVersion($sector), 'data_json' => $r['data_json'], 'parent_id' => $r['id'], 'note' => 'Restaurada da versão ' . $r['version'], 'created_by' => $user['name'], 'created_at' => now()]);
                $r = self::get($nid);
            } elseif ($r['status'] !== 'draft') throw new HttpError(422, 'Esta versão já está ativa.');
            Db::exec("UPDATE print_layouts SET status = 'archived' WHERE sector = ? AND status = 'active'", [$sector]);
            Db::exec("UPDATE print_layouts SET status = 'active', activated_by = ?, activated_at = ? WHERE id = ?", [$user['name'], now(), $r['id']]);
            Auth::audit('print.layout.activate', "layout #{$r['id']} v{$r['version']} ($sector)");
            return (int)$r['id'];
        });
    }

    public static function duplicate(int $id, array $user): int
    {
        $r = self::get($id); if (!$r) throw new HttpError(404, 'Layout não encontrado.');
        return self::saveDraft(null, $r['sector'], mb_substr($r['name'], 0, 70) . ' (cópia)', self::hydrate($r['sector'], $r['data_json']), $user);
    }
    public static function rename(int $id, string $name): void
    {
        if (!self::get($id)) throw new HttpError(404, 'Layout não encontrado.');
        Db::update('print_layouts', $id, ['name' => self::cleanName($name)]);
    }
    /** Cria um rascunho com o modelo padrão (não ativa). */
    public static function resetDefault(string $sector, array $user): int { return self::saveDraft(null, $sector, 'Modelo padrão', self::defaults($sector), $user); }

    // ---------- dados fictícios para prévia e teste ----------
    public static function fakeOrder(string $scenario, string $sector): array
    {
        $it = fn(string $name, int $q, ?string $size, string $sec, int $cents, array $opts = [], ?string $notes = null) => ['product_id' => 0, '_sector' => $sec, 'qty' => $q, 'name' => $name, 'size_name' => $size, 'options' => array_map(fn($o) => ['group_name' => $o[0], 'option_name' => $o[1], 'price_cents' => $o[2] ?? 0], $opts), 'notes' => $notes, 'total_cents' => $cents * $q];
        $items = [$it('Calabresa', 1, 'Grande', 'cozinha', 8990, [['Borda', 'Catupiry', 1290], ['Retirar', 'sem cebola']]), $it('Coca-Cola Lata', 2, null, 'copa', 790)];
        $type = 'pickup'; $notes = null; $pay = ['label' => 'Dinheiro na entrega/retirada', 'status' => 'pending', 'change' => 20000]; $table = null;
        if ($scenario === 'half') $items = [$it('½ Calabresa + ½ Portuguesa', 1, 'Grande', 'cozinha', 9490, [['Retirar', 'sem cebola — ½ Portuguesa'], ['Retirar', 'sem azeitona — ½ Calabresa']], 'cortar em 12 pedaços'), $it('Guaraná 2 Litros', 1, null, 'copa', 1990)];
        if ($scenario === 'long') { $items = []; foreach ([['Margherita', 'Média', 'cozinha', 6490], ['Portuguesa', 'Grande', 'cozinha', 8990], ['Batata Frita com Mussarela - 500g', null, 'cozinha', 4990], ['Suco de Laranja Natural - Jarra 750 ml', null, 'copa', 2190], ['Heineken 600 ml', null, 'copa', 1990], ['Água sem Gás 500 ml', null, 'copa', 690], ['Mini Pudim Artesanal', null, 'cozinha', 1990], ['Coca-Cola 2 Litros', null, 'copa', 1990]] as $i => $x) $items[] = $it($x[0], 1 + $i % 2, $x[1], $x[2], $x[3]); }
        if ($scenario === 'notes') { $items[0]['notes'] = 'Metade sem queijo, massa bem fininha e bem passada, entregar cortada em 16 pedaços pequenos para criança; sem orégano.'; $notes = 'Cliente alérgico a amendoim: confirmar ingredientes. Tocar a campainha duas vezes e ligar ao chegar.'; }
        if ($scenario === 'delivery') $type = 'delivery';
        if ($scenario === 'table') { $type = 'dine_in'; $table = 7; }
        return ['id' => 1234, 'type' => $type, 'table_number' => $table, 'tab_id' => null, 'visit_id' => 55, 'events' => [['user_name' => 'Garçom Exemplo']], 'created_at' => date('Y-m-d H:i:s'),
            'customer_name' => $type === 'dine_in' ? 'Mesa da Ana' : 'Cliente Exemplo', 'customer_phone' => '31999990000', 'addr_street' => 'Rua das Flores', 'addr_number' => '123', 'addr_complement' => 'apto 4', 'addr_reference' => 'portão azul', 'zone_name' => 'Centro',
            'items' => $items, 'notes' => $scenario === 'long' ? 'Sem pressa; entregar junto.' : $notes, 'subtotal_cents' => array_sum(array_column($items, 'total_cents')),
            'delivery_fee_cents' => $type === 'delivery' ? 500 : 0, 'discount_cents' => 0, 'total_cents' => array_sum(array_column($items, 'total_cents')) + ($type === 'delivery' ? 500 : 0),
            'payment_label' => $pay['label'], 'payment_status' => $pay['status'], 'change_for_cents' => $type === 'delivery' ? $pay['change'] : null];
    }

    // ---------- renderização ----------
    /** @return list<array{t:string,a:string,size:string,b:bool,big:bool,c:bool,logo?:bool}> */
    public static function render(array $o, array $job, array $layout, array $profile): array
    {
        $w = max(20, (int)($profile['cols'] ?? 48));
        $sector = $job['sector']; $kind = $job['kind'] ?? 'original'; $dine = $o['type'] === 'dine_in';
        $sep = $layout['page']['separator']; $spacing = $layout['page']['spacing'];
        $L = [];
        $emit = function (string $t, array $f = []) use (&$L, $w) {
            $size = $f['size'] ?? 'normal'; $big = $size !== 'normal'; $a = $f['a'] ?? 'left';
            foreach (PrintFormatter::wrap(PrintFormatter::clean($t), $big ? intdiv($w, 2) : $w) as $part) $L[] = ['t' => $part, 'a' => $a, 'size' => $size, 'b' => !empty($f['b']), 'big' => $big, 'c' => $a === 'center'];
        };
        $rule = function () use (&$L, $sep, $spacing, $emit, $w) { if ($sep !== 'none' && $spacing !== 'compact' && $L && !str_starts_with($L[count($L) - 1]['t'], $sep === ' ' ? "\x00" : $sep . $sep)) $emit(str_repeat($sep, $w)); };
        $hd = $layout['header'];
        if (!empty($hd['logo'])) $L[] = ['t' => '', 'a' => 'center', 'size' => 'normal', 'b' => false, 'big' => false, 'c' => true, 'logo' => true];
        if ($hd['brand']) $emit($hd['brand_text'], ['a' => 'center', 'b' => true]);
        if ($hd['subtitle']) $emit(Printing::title($job), ['a' => 'center', 'b' => true]);
        // marcas obrigatórias, controladas pelo sistema (não editáveis)
        if ($kind === 'test' || env('APP_ENV') === 'staging') $emit('TESTE — NÃO PRODUZIR', ['a' => 'center', 'b' => true, 'size' => 'large']);
        if ($kind === 'update') $emit('*** PEDIDO ALTERADO — esta comanda substitui a anterior ***', ['a' => 'center', 'b' => true]);
        if ($kind === 'cancel') $emit('*** PEDIDO CANCELADO — NÃO PRODUZIR ***', ['a' => 'center', 'b' => true, 'size' => 'large']);
        if ($kind === 'reprint') { $emit('*** REIMPRESSÃO ' . date('d/m/Y H:i') . ' ***', ['a' => 'center', 'b' => true, 'size' => 'large']); $emit('Operador: ' . ($job['created_by'] ?? '-') . ' | Motivo: ' . ($job['reason'] ?? '-'), ['b' => true]); }
        $first = true;
        foreach ($layout['blocks'] as $b) {
            if (empty($b['show'])) continue;
            $st = ['a' => $b['align'], 'size' => $b['size'], 'b' => !empty($b['bold'])];
            $before = count($L);
            switch ($b['id']) {
                case 'order_number':
                    $emit($dine ? 'MESA ' . $o['table_number'] : 'PEDIDO #' . $o['id'], $st);
                    if ($dine) $emit('Pedido #' . $o['id'], ['a' => $b['align']]);
                    break;
                case 'datetime': $emit(date('d/m/Y H:i', strtotime((string)$o['created_at'])), $st); break;
                case 'modality':
                    $rule();
                    if ($dine) {
                        $tab = !empty($o['tab_id']) ? Db::one('SELECT physical_number FROM tabs WHERE id = ?', [$o['tab_id']]) : null;
                        $emit('SALÃO - MESA ' . $o['table_number'], $st);
                        $emit('Comanda: ' . ($tab['physical_number'] ?? 'sem nº físico') . ' (atend. #' . $o['visit_id'] . ')');
                        $emit('Cliente: ' . $o['customer_name']);
                        $emit('Garçom: ' . (($o['events'][0]['user_name'] ?? '') ?: '-'));
                    } else $emit($o['type'] === 'delivery' ? 'ENTREGA' : 'RETIRADA NO BALCÃO', $st);
                    break;
                case 'customer_name': if (!$dine) $emit('Cliente: ' . $o['customer_name'], $st); break;
                case 'customer_phone':
                    if (!$dine && ($o['customer_phone'] ?? '') !== '') { $d = preg_replace('/\D+/', '', (string)$o['customer_phone']); $emit('Tel: ' . (strlen($d) === 11 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)) : (strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)) : $o['customer_phone'])), $st); }
                    break;
                case 'address':
                    if ($o['type'] === 'delivery') { $emit('End: ' . $o['addr_street'] . ', ' . $o['addr_number'] . ($o['addr_complement'] ? ' - ' . $o['addr_complement'] : ''), $st); $emit('Bairro: ' . $o['zone_name'], $st); }
                    break;
                case 'reference': if ($o['type'] === 'delivery' && !empty($o['addr_reference'])) $emit('Ref: ' . $o['addr_reference'], $st); break;
                case 'items':
                    $rule();
                    foreach ($o['items'] as $it) {
                        $isec = $it['_sector'] ?? Printing::itemSector((int)$it['product_id']);
                        if ($sector !== 'balcao' && $isec !== $sector) continue;
                        $emit($it['qty'] . 'x ' . $it['name'] . ($it['size_name'] ? ' (' . $it['size_name'] . ')' : ''), $st);
                        if (!empty($b['options'])) foreach ($it['options'] as $op) $emit(($op['group_name'] === 'Retirar' ? '  - ' : '  + ') . $op['option_name'] . (!empty($b['prices']) && $op['price_cents'] ? ' ' . money((int)$op['price_cents']) : ''), ['b' => $st['b'], 'a' => 'left']);
                        if (!empty($it['notes'])) $emit($sector === 'balcao' ? '  OBS: ' . $it['notes'] : '  >>> OBSERVAÇÃO: ' . $it['notes'], ['b' => true, 'a' => 'left', 'size' => $sector === 'balcao' ? 'normal' : 'large']);
                        if (!empty($b['prices'])) $emit(PrintFormatter::pad(money((int)$it['total_cents']), $w), ['a' => 'left']);
                    }
                    break;
                case 'order_notes':
                    if (!empty($o['notes']) && $sector !== 'copa') { $rule(); $emit(($sector === 'cozinha' ? '>>> OBS DO PEDIDO: ' : 'OBS DO PEDIDO: ') . $o['notes'], $st); }
                    break;
                case 'totals':
                    $rule();
                    $row = fn($a, $c) => $emit($a . PrintFormatter::pad($c, $w - mb_strlen($a)), ['a' => 'left']);
                    $row('Subtotal', money((int)$o['subtotal_cents']));
                    if ($o['delivery_fee_cents']) $row('Taxa entrega', money((int)$o['delivery_fee_cents']));
                    if ($o['discount_cents']) $row('Desconto', '-' . money((int)$o['discount_cents']));
                    $emit('TOTAL ' . money((int)$o['total_cents']), $st);
                    break;
                case 'payment':
                    $emit('Forma de pagamento: ' . $o['payment_label'], ['b' => true, 'a' => 'left']);
                    $emit($o['payment_status'] === 'confirmed' ? 'PAGAMENTO CONFIRMADO' : ($o['payment_status'] === 'refunded' ? 'PAGAMENTO ESTORNADO' : 'PAGAMENTO PENDENTE'), $st);
                    if ($o['payment_status'] === 'pending') $emit('(ainda não recebido)', ['b' => true, 'a' => 'left']);
                    break;
                case 'change': if (!empty($o['change_for_cents'])) $emit('Troco para: ' . money((int)$o['change_for_cents']), $st); break;
            }
            if ($spacing === 'airy' && count($L) > $before) $emit('');
        }
        if ($layout['footer']['text'] !== '') { $rule(); $emit($layout['footer']['text'], ['a' => 'center']); }
        $emit('');
        return $L;
    }
}
