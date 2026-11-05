<?php
declare(strict_types=1);

final class Orders
{
    public const STATUS = ['received' => 'Recebido', 'accepted' => 'Aceito', 'preparing' => 'Em preparo', 'ready' => 'Pronto', 'completed' => 'Concluído', 'cancelled' => 'Cancelado'];
    /** Transições válidas (validadas no servidor). */
    public const NEXT = [
        'received' => ['accepted', 'cancelled'],
        'accepted' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [], 'cancelled' => [],
    ];
    public const PAY_STATUS = ['pending' => 'Pendente', 'confirmed' => 'Confirmado', 'refunded' => 'Estornado'];

    public static function statusLabel(string $s, string $type = 'pickup'): string
    {
        if ($s === 'ready') return $type === 'delivery' ? 'Saiu para entrega' : ($type === 'dine_in' ? 'Pronto para servir' : 'Pronto para retirada');
        return self::STATUS[$s] ?? $s;
    }

    /** Cria o pedido de forma idempotente. Retorna ['token'=>, 'id'=>, 'duplicate'=>bool]. */
    /** $manualBy: operador do painel (pedido de balcão/telefone) — dispensa horário da loja, nome e telefone. */
    public static function create(array $in, string $idemKey, ?array $manualBy = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $idemKey)) throw new HttpError(422, 'Requisição inválida.');
        if ($dup = Db::one('SELECT id, public_token FROM orders WHERE idempotency_key = ?', [$idemKey])) {
            return ['id' => (int)$dup['id'], 'token' => $dup['public_token'], 'duplicate' => true];
        }
        $st = $manualBy ? ['open' => true] : Settings::storeStatus();
        if (!$st['open']) throw new HttpError(409, $st['reason'] ?: 'Loja fechada.');

        $type = $in['type'] ?? '';
        if (!in_array($type, ['delivery', 'pickup'], true) || Settings::get($type . '_enabled') !== '1') throw new HttpError(422, 'Escolha entrega ou retirada.');
        $name = trim((string)($in['name'] ?? ''));
        $phone = normalize_phone((string)($in['phone'] ?? ''));
        if ($manualBy) {
            if ($name === '') $name = 'Balcão';
            if (mb_strlen($name) > 120) throw new HttpError(422, 'Nome muito longo.');
            if ($phone !== '' && !preg_match('/^\d{10,11}$/', $phone)) throw new HttpError(422, 'Telefone inválido (use DDD) ou deixe em branco.');
        } else {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new HttpError(422, 'Informe seu nome.');
            if (!preg_match('/^\d{10,11}$/', $phone)) throw new HttpError(422, 'Informe um telefone com DDD.');
        }

        $zone = null; $addr = ['street' => null, 'number' => null, 'complement' => null, 'reference' => null];
        if ($type === 'delivery') {
            $zone = Db::one('SELECT * FROM delivery_zones WHERE id = ? AND active = 1', [(int)($in['zone_id'] ?? 0)]);
            if (!$zone) throw new HttpError(422, 'Escolha um bairro atendido.');
            $addr['street'] = trim((string)($in['street'] ?? ''));
            $addr['number'] = trim((string)($in['number'] ?? ''));
            if ($addr['street'] === '' || $addr['number'] === '') throw new HttpError(422, 'Informe rua e número.');
            $addr['complement'] = mb_substr(trim((string)($in['complement'] ?? '')), 0, 120) ?: null;
            $addr['reference'] = mb_substr(trim((string)($in['reference'] ?? '')), 0, 160) ?: null;
            $addr['street'] = mb_substr($addr['street'], 0, 160); $addr['number'] = mb_substr($addr['number'], 0, 20);
        }

        $pay = Settings::json('payment_methods')[$in['payment'] ?? ''] ?? null;
        if (!$pay || empty($pay['on'])) throw new HttpError(422, 'Escolha a forma de pagamento.');

        $q = Pricing::quote($in['items'] ?? []);
        $fee = $zone ? (int)$zone['fee_cents'] : 0;
        if ($zone && $q['subtotal'] < (int)$zone['min_order_cents']) throw new HttpError(422, 'Pedido mínimo para ' . $zone['name'] . ': ' . money((int)$zone['min_order_cents']));
        $total = $q['subtotal'] + $fee;
        $change = null;
        if (($in['payment'] ?? '') === 'cash' && !empty($in['change_for'])) {
            $change = parse_money((string)$in['change_for']);
            if ($change < $total) throw new HttpError(422, 'O troco deve ser para um valor maior que o total.');
        }

        try {
            $res = Db::txImmediate(function () use ($in, $idemKey, $name, $phone, $type, $zone, $addr, $pay, $q, $fee, $total, $change, $manualBy) {
                // sem telefone (balcão): cadastro único "Balcão"; o nome digitado fica só no pedido
                $key = $phone === '' ? 'balcao' : $phone;
                $cust = Db::one('SELECT id FROM customers WHERE phone = ?', [$key]);
                $cid = $cust ? (int)$cust['id'] : Db::insert('customers', ['name' => $phone === '' ? 'Balcão' : $name, 'phone' => $key, 'created_at' => now()]);
                if ($phone !== '') Db::exec('UPDATE customers SET last_order_at = ?, name = ? WHERE id = ?', [now(), $name, $cid]);
                else Db::exec('UPDATE customers SET last_order_at = ? WHERE id = ?', [now(), $cid]);
                $est = (int)Settings::get($type === 'delivery' ? 'estimate_delivery_min' : 'estimate_pickup_min');
                $token = bin2hex(random_bytes(16));
                $oid = self::persist([
                    'public_token' => $token, 'idempotency_key' => $idemKey, 'customer_id' => $cid, 'customer_name' => $name, 'customer_phone' => $phone,
                    'type' => $type, 'zone_id' => $zone['id'] ?? null, 'zone_name' => $zone['name'] ?? null,
                    'addr_street' => $addr['street'], 'addr_number' => $addr['number'], 'addr_complement' => $addr['complement'], 'addr_reference' => $addr['reference'],
                    'subtotal_cents' => $q['subtotal'], 'delivery_fee_cents' => $fee, 'discount_cents' => 0, 'total_cents' => $total,
                    'payment_method' => $in['payment'], 'payment_label' => $pay['label'], 'payment_status' => 'pending', 'change_for_cents' => $change,
                    'status' => 'received', 'version' => 1, 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 300) ?: null,
                    'estimate_minutes' => $est ?: null, 'origin' => $manualBy ? 'manual' : 'web', 'created_at' => now(), 'updated_at' => now(),
                ], $q['lines'], $manualBy ? 'Pedido cadastrado manualmente (balcão/telefone)' : 'Pedido recebido pelo site', $manualBy);
                return ['id' => $oid, 'token' => $token, 'duplicate' => false];
            });
        } catch (PDOException $ex) {
            // corrida de cliques: a restrição UNIQUE da chave de idempotência barra o segundo pedido
            if ($dup = Db::one('SELECT id, public_token FROM orders WHERE idempotency_key = ?', [$idemKey])) {
                return ['id' => (int)$dup['id'], 'token' => $dup['public_token'], 'duplicate' => true];
            }
            throw $ex;
        }
        app_log("order #{$res['id']} created");
        return $res;
    }

    /**
     * Grava pedido + itens + opções + evento de criação + fila de impressão. Chamar dentro de uma transação.
     * Usado pelo pedido do site/manual e pelas rodadas de produção do salão (mesmo fluxo de gestor, cozinha e impressão).
     */
    public static function persist(array $row, array $lines, string $eventNote, ?array $by): int
    {
        $oid = Db::insert('orders', $row);
        foreach ($lines as $l) {
            $iid = Db::insert('order_items', [
                'order_id' => $oid, 'product_id' => $l['product_id'], 'name' => $l['name'], 'size_name' => $l['size_name'], 'qty' => $l['qty'],
                'unit_price_cents' => $l['unit_price_cents'], 'total_cents' => $l['total_cents'], 'notes' => $l['notes'],
            ]);
            foreach ($l['options'] as $o) Db::insert('order_item_options', ['item_id' => $iid, 'group_name' => $o['group'], 'option_name' => $o['name'], 'price_cents' => $o['price_cents']]);
        }
        Db::insert('order_events', ['order_id' => $oid, 'user_id' => $by['id'] ?? null, 'user_name' => $by['name'] ?? null, 'kind' => 'created', 'to_value' => 'received', 'note' => $eventNote, 'created_at' => now()]);
        Printing::enqueueOrder($oid, 'original', $by);
        return $oid;
    }

    public static function get(int $id): ?array
    {
        $o = Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
        return $o ? self::hydrate($o) : null;
    }
    public static function byToken(string $t): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $t)) return null;
        $o = Db::one('SELECT * FROM orders WHERE public_token = ?', [$t]);
        return $o ? self::hydrate($o) : null;
    }
    private static function hydrate(array $o): array
    {
        $items = Db::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
        foreach ($items as &$it) $it['options'] = Db::all('SELECT group_name, option_name, price_cents FROM order_item_options WHERE item_id = ? ORDER BY id', [$it['id']]);
        $o['items'] = $items;
        $o['events'] = Db::all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id', [$o['id']]);
        return $o;
    }

    /** Visão pública: apenas o necessário para acompanhamento, sem dados de outros clientes. */
    public static function publicView(array $o): array
    {
        return [
            'id' => (int)$o['id'], 'status' => $o['status'], 'status_label' => self::statusLabel($o['status'], $o['type']), 'type' => $o['type'],
            'created_at' => $o['created_at'], 'estimate_minutes' => $o['estimate_minutes'] ? (int)$o['estimate_minutes'] : null,
            'payment_label' => $o['payment_label'], 'payment_status' => $o['payment_status'], 'payment_status_label' => self::PAY_STATUS[$o['payment_status']] ?? '',
            'total_cents' => (int)$o['total_cents'], 'subtotal_cents' => (int)$o['subtotal_cents'], 'delivery_fee_cents' => (int)$o['delivery_fee_cents'],
            'cancel_reason' => $o['status'] === 'cancelled' ? $o['cancel_reason'] : null,
            'items' => array_map(fn($i) => [
                'name' => $i['name'], 'size_name' => $i['size_name'], 'qty' => (int)$i['qty'], 'total_cents' => (int)$i['total_cents'],
                'options' => array_map(fn($x) => $x['option_name'], $i['options']), 'notes' => $i['notes'],
            ], $o['items']),
            'timeline' => array_values(array_map(fn($e) => ['at' => $e['created_at'], 'label' => self::statusLabel($e['to_value'], $o['type'])],
                array_filter($o['events'], fn($e) => $e['kind'] === 'created' || $e['kind'] === 'status'))),
        ];
    }

    /** @param array{from?:string,to?:string,status?:string,q?:string,active?:bool} $f */
    public static function list(array $f, int $limit = 200): array
    {
        $w = []; $p = [];
        if (!empty($f['active'])) $w[] = "status NOT IN ('completed','cancelled')";
        if (!empty($f['status'])) { $w[] = 'status = ?'; $p[] = $f['status']; }
        if (!empty($f['from'])) { $w[] = 'created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $w[] = 'created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $q = ltrim(trim($f['q']), '#');
            $w[] = '(id = ? OR customer_name ' . Db::like() . ' ? OR customer_phone ' . Db::like() . ' ?)';
            $p[] = ctype_digit($q) ? (int)$q : 0; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . normalize_phone($f['q']) . '%';
        }
        $sql = 'SELECT * FROM orders' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit;
        $rows = Db::all($sql, $p);
        return array_map(fn($o) => self::hydrate($o), $rows);
    }

    /**
     * Muda o status com bloqueio otimista: $version é a versão que o operador viu.
     * @throws HttpError 409 se outro operador alterou antes; 422 se transição inválida.
     */
    public static function transition(int $id, string $to, int $version, array $user, ?string $reason = null, ?int $estimate = null): array
    {
        $o = Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        if ((int)$o['version'] !== $version) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);
        if (!in_array($to, self::NEXT[$o['status']] ?? [], true)) throw new HttpError(422, 'Transição inválida: ' . self::STATUS[$o['status']] . ' → ' . (self::STATUS[$to] ?? $to));
        $reason = $reason !== null ? trim($reason) : null;
        if ($to === 'cancelled' && (!$reason || mb_strlen($reason) < 3)) throw new HttpError(422, 'Informe o motivo do cancelamento.');
        if ($to === 'cancelled' && $o['tab_id'] && Db::val('SELECT status FROM tabs WHERE id = ?', [$o['tab_id']]) === 'closed') throw new HttpError(422, 'A comanda já foi fechada: reabra-a no Caixa antes de cancelar esta rodada.');
        $set = ['status' => $to, 'version' => $version + 1, 'updated_at' => now()];
        if ($to === 'cancelled') $set['cancel_reason'] = mb_substr($reason, 0, 300);
        if ($estimate !== null && $estimate > 0 && $estimate <= 600) $set['estimate_minutes'] = $estimate;
        $cols = implode(',', array_map(fn($c) => "$c = ?", array_keys($set)));
        $n = Db::exec("UPDATE orders SET $cols WHERE id = ? AND version = ?", [...array_values($set), $id, $version]);
        if ($n !== 1) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);
        self::event($id, $user, 'status', $o['status'], $to, $reason);
        if ($to === 'cancelled') Printing::onCancel($id, $user); // descarta o que não saiu; avisa explicitamente os destinos que já receberam o pedido
        return self::get($id);
    }

    /**
     * Corrige rua/número/complemento/referência de um pedido de ENTREGA antes de sair para entrega.
     * Não muda bairro (a taxa e o total ficam como foram cobrados). Gera histórico e exige a versão vista pelo operador.
     */
    public static function updateAddress(int $id, array $in, int $version, array $user): array
    {
        $o = Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        if ($o['type'] !== 'delivery') throw new HttpError(422, 'Só pedidos de entrega têm endereço.');
        if (!in_array($o['status'], ['received', 'accepted', 'preparing'], true)) throw new HttpError(422, 'O endereço só pode ser alterado antes de o pedido ficar pronto/sair para entrega.');
        if ((int)$o['version'] !== $version) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);
        $street = mb_substr(trim((string)($in['street'] ?? '')), 0, 160); $number = mb_substr(trim((string)($in['number'] ?? '')), 0, 20);
        if ($street === '' || $number === '') throw new HttpError(422, 'Informe rua e número.');
        $comp = mb_substr(trim((string)($in['complement'] ?? '')), 0, 120) ?: null; $ref = mb_substr(trim((string)($in['reference'] ?? '')), 0, 160) ?: null;
        $old = "{$o['addr_street']}, {$o['addr_number']}";
        $n = Db::exec('UPDATE orders SET addr_street = ?, addr_number = ?, addr_complement = ?, addr_reference = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
            [$street, $number, $comp, $ref, $version + 1, now(), $id, $version]);
        if ($n !== 1) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);
        self::event($id, $user, 'address', null, null, "Endereço: $old → $street, $number");
        return self::get($id);
    }

    /**
     * Edita os itens de um pedido antes de ficar pronto, enquanto o pagamento está pendente.
     * Operações: {op:qty,item_id,qty} · {op:remove,item_id} · {op:notes,item_id,notes} · {op:add,item:{...mesmo formato do carrinho}}.
     * Itens não tocados mantêm o preço registrado; itens novos são precificados pelo servidor com o cardápio atual.
     * O bairro/taxa não muda; o total é recalculado; tudo fica no histórico.
     */
    public static function editItems(int $id, array $ops, int $version, array $user): array
    {
        if (!$ops || count($ops) > 30) throw new HttpError(422, 'Nenhuma alteração informada.');
        $o = Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        if (!in_array($o['status'], ['received', 'accepted', 'preparing'], true)) throw new HttpError(422, 'Os itens só podem ser alterados antes de o pedido ficar pronto.');
        if ($o['payment_status'] !== 'pending') throw new HttpError(422, 'O pagamento já foi conferido/estornado: ajuste o pagamento antes de alterar itens.');
        if ($o['tab_id'] && Db::val('SELECT status FROM tabs WHERE id = ?', [$o['tab_id']]) !== 'open') throw new HttpError(422, 'A conta desta comanda já foi solicitada/fechada; reabra-a no Caixa para alterar itens.');
        if ((int)$o['version'] !== $version) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);

        $log = [];
        try {
            Db::txImmediate(function () use ($id, $ops, $o, $version, $user, &$log) {
                foreach ($ops as $op) {
                    $kind = $op['op'] ?? '';
                    if ($kind === 'add') {
                        $q = Pricing::quote([$op['item'] ?? []]); $l = $q['lines'][0];
                        $iid = Db::insert('order_items', ['order_id' => $id, 'product_id' => $l['product_id'], 'name' => $l['name'], 'size_name' => $l['size_name'], 'qty' => $l['qty'],
                            'unit_price_cents' => $l['unit_price_cents'], 'total_cents' => $l['total_cents'], 'notes' => $l['notes']]);
                        foreach ($l['options'] as $x) Db::insert('order_item_options', ['item_id' => $iid, 'group_name' => $x['group'], 'option_name' => $x['name'], 'price_cents' => $x['price_cents']]);
                        $log[] = "+{$l['qty']}× {$l['name']}";
                        continue;
                    }
                    $it = Db::one('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [(int)($op['item_id'] ?? 0), $id]);
                    if (!$it) throw new HttpError(422, 'Item não pertence a este pedido.');
                    if ($kind === 'remove') { Db::exec('DELETE FROM order_items WHERE id = ?', [$it['id']]); $log[] = "−{$it['qty']}× {$it['name']}"; }
                    elseif ($kind === 'qty') {
                        $qty = (int)($op['qty'] ?? 0);
                        if ($qty < 1 || $qty > 50) throw new HttpError(422, 'Quantidade inválida.');
                        if ($qty !== (int)$it['qty']) { Db::exec('UPDATE order_items SET qty = ?, total_cents = ? WHERE id = ?', [$qty, $qty * (int)$it['unit_price_cents'], $it['id']]); $log[] = "{$it['name']}: qtd {$it['qty']}→$qty"; }
                    } elseif ($kind === 'notes') {
                        $n = mb_substr(trim((string)($op['notes'] ?? '')), 0, 200) ?: null;
                        if ($n !== $it['notes']) { Db::exec('UPDATE order_items SET notes = ? WHERE id = ?', [$n, $it['id']]); $log[] = "obs. de {$it['name']}"; }
                    } else throw new HttpError(422, 'Operação inválida.');
                }
                if (!$log) throw new HttpError(422, 'Nada mudou.');
                $n = (int)Db::val('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$id]);
                if ($n < 1) throw new HttpError(422, 'O pedido precisa ter ao menos um item. Para desistir, cancele o pedido.');
                $sub = (int)Db::val('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id = ?', [$id]);
                $total = $sub + (int)$o['delivery_fee_cents'] - (int)$o['discount_cents'];
                $change = $o['change_for_cents'] !== null && (int)$o['change_for_cents'] < $total ? null : $o['change_for_cents'];
                $upd = Db::exec('UPDATE orders SET subtotal_cents = ?, total_cents = ?, change_for_cents = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
                    [$sub, $total, $change, $version + 1, now(), $id, $version]);
                if ($upd !== 1) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa. Atualizamos a tela.', ['order' => self::get($id)]);
                $log[] = 'total ' . money((int)$o['total_cents']) . ' → ' . money($total) . ($change !== $o['change_for_cents'] ? ' (troco removido: ficou menor que o total)' : '');
            });
        } catch (HttpError $e) { throw $e; }
        self::event($id, $user, 'items', null, null, implode('; ', $log));
        Printing::enqueueOrder($id, 'update', $user); // comunica a alteração aos destinos (comanda marcada PEDIDO ALTERADO; nunca como pedido novo)
        return self::get($id);
    }

    public static function setPayment(int $id, string $to, int $version, array $user): array
    {
        if (!isset(self::PAY_STATUS[$to])) throw new HttpError(422, 'Status de pagamento inválido.');
        $o = Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        if ($o['origin'] === 'salao') throw new HttpError(422, 'Pedido do salão: o pagamento é tratado na comanda (Caixa), não no pedido de produção.');
        if ((int)$o['version'] !== $version) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa.', ['order' => self::get($id)]);
        if ($o['status'] === 'cancelled' && $to === 'confirmed') throw new HttpError(422, 'Pedido cancelado não pode ter pagamento confirmado.');
        $n = Db::exec('UPDATE orders SET payment_status = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?', [$to, $version + 1, now(), $id, $version]);
        if ($n !== 1) throw new HttpError(409, 'Este pedido foi alterado por outra pessoa.', ['order' => self::get($id)]);
        self::event($id, $user, 'payment', $o['payment_status'], $to, 'Conferência manual pela equipe');
        return self::get($id);
    }

    public static function event(int $id, ?array $user, string $kind, ?string $from, ?string $to, ?string $note): void
    {
        Db::insert('order_events', ['order_id' => $id, 'user_id' => $user['id'] ?? null, 'user_name' => $user['name'] ?? null, 'kind' => $kind, 'from_value' => $from, 'to_value' => $to, 'note' => $note ? mb_substr($note, 0, 300) : null, 'created_at' => now()]);
    }
}
