<?php
declare(strict_types=1);

/**
 * Salão. Quatro conceitos separados:
 *  - mesa (dining_tables): lugar físico, permanente;
 *  - atendimento (visits): um grupo/visita, com id próprio;
 *  - comanda (tabs): conta vinculada a um atendimento e a uma mesa (número físico opcional, único entre as ABERTAS);
 *  - rodada de produção: pedido (orders, origin 'salao', type 'dine_in') com os itens enviados à cozinha/bar.
 * Pagamentos (tab_payments) são separados de preparo e de ocupação. Nada é vinculado "à mesa" apenas.
 * Cada método recebe o usuário (linha do banco) e confere a permissão — além da checagem nas rotas.
 */
final class Floor
{
    public const VISIT = ['active' => 'Em atendimento', 'awaiting_cashier' => 'Aguardando caixa', 'closed' => 'Encerrado'];
    public const TAB = ['open' => 'Aberta', 'awaiting_cashier' => 'Aguardando caixa', 'closed' => 'Fechada'];
    public const PAY = ['reported' => 'Informado (aguarda conferência)', 'confirmed' => 'Confirmado', 'rejected' => 'Recusado', 'reversed' => 'Estorno registrado'];

    public static function need(array $user, string $perm): void
    {
        if (!in_array($perm, Auth::permsOf($user), true)) throw new HttpError(403, 'Sem permissão para esta ação.');
    }

    private static function log(?int $tab, ?int $visit, ?int $table, array $user, string $kind, string $note = ''): void
    {
        Db::insert('floor_events', ['tab_id' => $tab, 'visit_id' => $visit, 'table_id' => $table, 'user_id' => $user['id'] ?? null, 'user_name' => $user['name'] ?? null, 'kind' => $kind, 'note' => mb_substr($note, 0, 400), 'created_at' => now()]);
    }

    /** Sincroniza a quantidade de mesas (ativa 1..N; as demais ficam inativas, sem apagar histórico). */
    public static function syncTables(int $n): void
    {
        $n = max(0, min(200, $n));
        for ($i = 1; $i <= $n; $i++) {
            if (!Db::val('SELECT 1 FROM dining_tables WHERE number = ?', [$i])) Db::insert('dining_tables', ['number' => $i, 'active' => 1]);
        }
        Db::exec('UPDATE dining_tables SET active = CASE WHEN number <= ? THEN 1 ELSE 0 END', [$n]);
    }

    // ---------- valores ----------
    /** @return array{items:int,fee:int,discount:int,total:int,confirmed:int,reported:int,pending:int,balance:int} */
    public static function totals(int $tabId): array
    {
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
        $pay = Db::one("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount_cents END),0) AS c, COALESCE(SUM(CASE WHEN status='reported' THEN amount_cents END),0) AS r FROM tab_payments WHERE tab_id = ?", [$tabId]);
        if ($t['status'] === 'closed' && $t['total_cents'] !== null) {
            $items = (int)$t['items_cents']; $fee = (int)$t['fee_cents']; $total = (int)$t['total_cents'];
        } else {
            $items = (int)Db::val("SELECT COALESCE(SUM(i.total_cents),0) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.tab_id = ? AND o.status <> 'cancelled'", [$tabId]);
            $pct = (float)str_replace(',', '.', Settings::get('service_fee_percent'));
            $fee = ($t['fee_removed'] || $pct <= 0) ? 0 : (int)round($items * $pct / 100);
            $total = max(0, $items + $fee - (int)$t['discount_cents']);
        }
        $c = (int)$pay['c']; $r = (int)$pay['r'];
        return ['items' => $items, 'fee' => $fee, 'discount' => (int)$t['discount_cents'], 'total' => $total, 'confirmed' => $c, 'reported' => $r, 'pending' => $total - $c, 'balance' => $total - $c - $r];
    }

    private static function syncVisit(int $visitId): void
    {
        $c = Db::one("SELECT SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS o, SUM(CASE WHEN status = 'awaiting_cashier' THEN 1 ELSE 0 END) AS a FROM tabs WHERE visit_id = ?", [$visitId]);
        $st = (int)$c['o'] > 0 ? 'active' : ((int)$c['a'] > 0 ? 'awaiting_cashier' : 'closed');
        Db::exec('UPDATE visits SET status = ?, closed_at = ? WHERE id = ?', [$st, $st === 'closed' ? now() : null, $visitId]);
    }

    // ---------- mesas ----------
    /** Estado de cada mesa considerando TODOS os atendimentos/comandas vinculados (nunca só o último). */
    public static function tables(): array
    {
        $tables = Db::all('SELECT * FROM dining_tables WHERE active = 1 ORDER BY number');
        $visits = Db::all("SELECT * FROM visits WHERE status IN ('active','awaiting_cashier') ORDER BY id");
        $tabs = Db::all("SELECT * FROM tabs WHERE status IN ('open','awaiting_cashier') ORDER BY id");
        $tabsBy = [];
        foreach ($tabs as $t) { $t['total'] = self::totals((int)$t['id'])['total']; $tabsBy[$t['visit_id']][] = $t; }
        $vBy = [];
        foreach ($visits as $v) { $v['tabs'] = $tabsBy[$v['id']] ?? []; $vBy[$v['table_id']][] = $v; }
        $out = [];
        foreach ($tables as $tb) {
            $vs = $vBy[$tb['id']] ?? [];
            $active = array_values(array_filter($vs, fn($v) => $v['status'] === 'active'));
            $pending = array_values(array_filter($vs, fn($v) => $v['status'] === 'awaiting_cashier'));
            $out[] = ['id' => (int)$tb['id'], 'number' => (int)$tb['number'], 'state' => $active ? 'busy' : ($pending ? 'awaiting' : 'free'),
                'people' => array_sum(array_map(fn($v) => (int)$v['people'], $active)), 'pending_count' => count($pending), 'visits' => $vs];
        }
        return $out;
    }

    // ---------- abrir mesa / nova comanda ----------
    /**
     * in: table_id, name, people, physical_number?, mode? ('new_visit'|'add_to_visit'), visit_id?
     * Mesa com atendimento ATIVO exige escolher o modo explicitamente (nada é decidido em silêncio).
     * Atendimentos aguardando caixa permanecem intactos.
     */
    public static function open(array $in, array $user, string $key): array
    {
        self::need($user, 'floor');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key)) throw new HttpError(422, 'Requisição inválida.');
        if ($d = Db::one('SELECT id, visit_id, table_id FROM tabs WHERE idempotency_key = ?', [$key])) return ['tab_id' => (int)$d['id'], 'visit_id' => (int)$d['visit_id'], 'duplicate' => true];
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        $people = (int)($in['people'] ?? 0);
        if ($name === '') throw new HttpError(422, 'Informe o nome do cliente.');
        if ($people < 1 || $people > 50) throw new HttpError(422, 'Informe de 1 a 50 pessoas.');
        $phys = strtoupper(trim((string)($in['physical_number'] ?? '')));
        if ($phys !== '' && !preg_match('/^[A-Z0-9-]{1,20}$/', $phys)) throw new HttpError(422, 'Número da comanda: use letras, números ou hífen (até 20).');
        $mode = (string)($in['mode'] ?? '');

        try {
            return Db::txImmediate(function () use ($in, $user, $key, $name, $people, $phys, $mode) {
                // reenvio/clique repetido: reconferir a chave DENTRO da transação (com o bloqueio já obtido). Sem isto, um 2º envio simultâneo
                // via a mesa "já em atendimento" e recebia 409 em vez do resultado da 1ª tentativa.
                if ($d = Db::one('SELECT id, visit_id FROM tabs WHERE idempotency_key = ?', [$key])) return ['tab_id' => (int)$d['id'], 'visit_id' => (int)$d['visit_id'], 'duplicate' => true];
                $tb = Db::one('SELECT * FROM dining_tables WHERE id = ? AND active = 1' . (Db::$driver !== 'sqlite' ? ' FOR UPDATE' : ''), [(int)($in['table_id'] ?? 0)]);
                if (!$tb) throw new HttpError(422, 'Mesa inválida.');
                $active = Db::all("SELECT * FROM visits WHERE table_id = ? AND status = 'active' ORDER BY id", [$tb['id']]);
                $pending = Db::all("SELECT id, name FROM visits WHERE table_id = ? AND status = 'awaiting_cashier' ORDER BY id", [$tb['id']]);
                $visitId = null;
                if ($active) {
                    if ($mode === 'add_to_visit') {
                        $v = array_values(array_filter($active, fn($x) => (int)$x['id'] === (int)($in['visit_id'] ?? 0)))[0] ?? null;
                        if (!$v) throw new HttpError(422, 'Escolha o atendimento ativo ao qual a comanda será adicionada.');
                        $visitId = (int)$v['id'];
                    } elseif ($mode === 'new_visit') {
                        if (Settings::get('allow_independent_visits') !== '1') throw new HttpError(422, 'A mesa está em atendimento. Atendimentos independentes na mesma mesa estão desativados: adicione a comanda ao atendimento atual.');
                    } else {
                        throw new HttpError(409, 'Esta mesa já está em atendimento. Escolha: adicionar uma comanda ao grupo atual ou abrir um atendimento independente.', ['visits' => array_map(fn($v) => ['id' => (int)$v['id'], 'name' => $v['name']], $active)]);
                    }
                }
                if ($visitId === null) $visitId = Db::insert('visits', ['table_id' => $tb['id'], 'name' => $name, 'people' => $people, 'status' => 'active', 'opened_by' => $user['id'], 'opened_by_name' => $user['name'], 'opened_at' => now(), 'idempotency_key' => $key]);
                $tabId = Db::insert('tabs', ['visit_id' => $visitId, 'table_id' => $tb['id'], 'physical_number' => $phys ?: null, 'open_key' => $phys ?: null, 'name' => $name, 'people' => $people,
                    'status' => 'open', 'opened_by' => $user['id'], 'opened_by_name' => $user['name'], 'opened_at' => now(), 'idempotency_key' => $key]);
                self::log($tabId, $visitId, (int)$tb['id'], $user, 'open', ($phys ? "comanda $phys · " : '') . "$name · $people pessoa(s)" . ($pending ? ' · ' . count($pending) . ' fechamento(s) pendente(s) na mesa' : ''));
                return ['tab_id' => $tabId, 'visit_id' => $visitId, 'duplicate' => false, 'pending' => $pending];
            });
        } catch (PDOException $e) {
            if ($d = Db::one('SELECT id, visit_id FROM tabs WHERE idempotency_key = ?', [$key])) return ['tab_id' => (int)$d['id'], 'visit_id' => (int)$d['visit_id'], 'duplicate' => true];
            if ($phys !== '' && ($o = Db::one('SELECT t.visit_id, tb.number FROM tabs t JOIN dining_tables tb ON tb.id = t.table_id WHERE t.open_key = ?', [$phys]))) {
                throw new HttpError(409, "A comanda $phys já está aberta (mesa {$o['number']}, atendimento #{$o['visit_id']}). Feche-a antes de reutilizar o número.");
            }
            throw $e;
        }
    }

    public static function linkPhysical(int $tabId, string $number, array $user): void
    {
        self::need($user, 'floor');
        $number = strtoupper(trim($number));
        if (!preg_match('/^[A-Z0-9-]{1,20}$/', $number)) throw new HttpError(422, 'Número da comanda inválido.');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t || !in_array($t['status'], ['open', 'awaiting_cashier'], true)) throw new HttpError(422, 'Comanda não encontrada ou já fechada.');
        try { Db::exec('UPDATE tabs SET physical_number = ?, open_key = ?, version = version + 1 WHERE id = ?', [$number, $number, $tabId]); }
        catch (PDOException $e) { throw new HttpError(409, "A comanda $number já está aberta em outro atendimento."); }
        self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'link', "comanda física $number");
    }

    // ---------- rodadas de produção ----------
    /** Envia SOMENTE os itens informados nesta rodada (um pedido de produção novo). Reenviar a mesma chave não duplica. */
    public static function sendRound(int $tabId, array $items, array $user, string $key, string $notes = ''): array
    {
        self::need($user, 'floor');
        if ($d = Db::one('SELECT id FROM orders WHERE idempotency_key = ?', [$key])) return ['order_id' => (int)$d['id'], 'duplicate' => true];
        $t = Db::one('SELECT t.*, tb.number AS table_number FROM tabs t JOIN dining_tables tb ON tb.id = t.table_id WHERE t.id = ?', [$tabId]);
        if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
        if ($t['status'] !== 'open') throw new HttpError(422, 'Esta comanda não aceita novos itens (conta já solicitada ou fechada).');
        $q = Pricing::quote($items);
        try {
            $dup = false;
            // txImmediate: duplo toque/reenvio simultâneo espera o bloqueio de escrita (em vez de "database is locked") e a chave é reconferida já dentro da transação
            $oid = Db::txImmediate(function () use ($t, $q, $user, $key, $notes, &$dup) {
                if ($d = Db::one('SELECT id FROM orders WHERE idempotency_key = ?', [$key])) { $dup = true; return (int)$d['id']; }
                $cust = Db::one("SELECT id FROM customers WHERE phone = 'salao'");
                $cid = $cust ? (int)$cust['id'] : Db::insert('customers', ['name' => 'Salão', 'phone' => 'salao', 'created_at' => now()]);
                $oid = Orders::persist([
                    'public_token' => bin2hex(random_bytes(16)), 'idempotency_key' => $key, 'customer_id' => $cid, 'customer_name' => $t['name'], 'customer_phone' => '',
                    'type' => 'dine_in', 'subtotal_cents' => $q['subtotal'], 'delivery_fee_cents' => 0, 'discount_cents' => 0, 'total_cents' => $q['subtotal'],
                    'payment_method' => 'tab', 'payment_label' => 'Conta da comanda', 'payment_status' => 'pending', 'status' => 'received', 'version' => 1,
                    'notes' => mb_substr(trim($notes), 0, 300) ?: null, 'origin' => 'salao', 'visit_id' => $t['visit_id'], 'tab_id' => $t['id'], 'table_number' => $t['table_number'],
                    'created_at' => now(), 'updated_at' => now(),
                ], $q['lines'], "Rodada lançada no salão — mesa {$t['table_number']}", $user);
                self::log((int)$t['id'], (int)$t['visit_id'], (int)$t['table_id'], $user, 'round', "pedido #$oid · " . count($q['lines']) . ' item(ns)');
                return $oid;
            });
        } catch (PDOException $e) {
            if ($d = Db::one('SELECT id FROM orders WHERE idempotency_key = ?', [$key])) return ['order_id' => (int)$d['id'], 'duplicate' => true];
            throw $e;
        }
        return ['order_id' => $oid, 'duplicate' => $dup];
    }

    public static function requestBill(int $tabId, array $user): void
    {
        self::need($user, 'floor');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
        if ($t['status'] === 'awaiting_cashier') return; // idempotente
        if ($t['status'] !== 'open') throw new HttpError(422, 'Comanda já fechada.');
        Db::txImmediate(function () use ($t, $tabId, $user) {
            Db::exec("UPDATE tabs SET status = 'awaiting_cashier', bill_requested_at = ?, version = version + 1 WHERE id = ? AND status = 'open'", [now(), $tabId]);
            self::syncVisit((int)$t['visit_id']);
            self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'bill', 'conta solicitada ao caixa');
        });
    }

    // ---------- recebimentos ----------
    private static function payMethod(string $m): string
    {
        $pm = Settings::json('payment_methods')[$m] ?? null;
        if (!$pm || empty($pm['on'])) throw new HttpError(422, 'Forma de pagamento inválida ou desativada.');
        return $m;
    }

    /** Garçom informa um recebimento: fica PENDENTE de conferência; não encerra nada. */
    public static function reportPayment(int $tabId, int $amount, string $method, array $user, string $key, string $note = ''): array
    {
        self::need($user, 'report_payment');
        return self::addPayment($tabId, $amount, $method, $user, $key, $note, false);
    }

    /** Caixa registra e já confirma um recebimento. */
    public static function registerPayment(int $tabId, int $amount, string $method, array $user, string $key, string $note = ''): array
    {
        self::need($user, 'cashier');
        return self::addPayment($tabId, $amount, $method, $user, $key, $note, true);
    }

    private static function addPayment(int $tabId, int $amount, string $method, array $user, string $key, string $note, bool $confirm): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key)) throw new HttpError(422, 'Requisição inválida.');
        if ($d = Db::one('SELECT id FROM tab_payments WHERE idempotency_key = ?', [$key])) return ['payment_id' => (int)$d['id'], 'duplicate' => true];
        self::payMethod($method);
        if ($amount < 1) throw new HttpError(422, 'Informe um valor maior que zero.');
        try {
            $id = Db::txImmediate(function () use ($tabId, $amount, $method, $user, $key, $note, $confirm) {
                $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
                if (!$t || !in_array($t['status'], ['open', 'awaiting_cashier'], true)) throw new HttpError(422, 'Comanda não encontrada ou já fechada.');
                $tot = self::totals($tabId);
                if ($amount > $tot['balance']) throw new HttpError(422, 'O valor excede o saldo da comanda (' . money(max(0, $tot['balance'])) . ').');
                $id = Db::insert('tab_payments', ['tab_id' => $tabId, 'visit_id' => $t['visit_id'], 'amount_cents' => $amount, 'method' => $method, 'status' => $confirm ? 'confirmed' : 'reported',
                    'reported_by' => $user['id'], 'reported_by_name' => $user['name'], 'reported_at' => now(), 'confirmed_by' => $confirm ? $user['id'] : null, 'confirmed_by_name' => $confirm ? $user['name'] : null,
                    'confirmed_at' => $confirm ? now() : null, 'note' => mb_substr($note, 0, 300) ?: null, 'shift_id' => $confirm ? Cash::currentId() : null, 'idempotency_key' => $key]);
                self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, $confirm ? 'pay_registered' : 'pay_reported', money($amount) . " ($method)");
                return $id;
            });
        } catch (PDOException $e) {
            if ($d = Db::one('SELECT id FROM tab_payments WHERE idempotency_key = ?', [$key])) return ['payment_id' => (int)$d['id'], 'duplicate' => true];
            throw $e;
        }
        return ['payment_id' => $id, 'duplicate' => false];
    }

    public static function confirmPayment(int $paymentId, array $user): void
    {
        self::need($user, 'cashier');
        $p = Db::one('SELECT * FROM tab_payments WHERE id = ?', [$paymentId]);
        if (!$p) throw new HttpError(404, 'Recebimento não encontrado.');
        if ($p['status'] === 'confirmed') return;
        if ($p['status'] !== 'reported') throw new HttpError(422, 'Só recebimentos informados podem ser confirmados.');
        $n = Db::exec("UPDATE tab_payments SET status = 'confirmed', confirmed_by = ?, confirmed_by_name = ?, confirmed_at = ?, shift_id = ? WHERE id = ? AND status = 'reported'", [$user['id'], $user['name'], now(), Cash::currentId(), $paymentId]);
        if ($n === 1) self::log((int)$p['tab_id'], (int)$p['visit_id'], null, $user, 'pay_confirmed', money((int)$p['amount_cents']) . " ({$p['method']})");
    }

    public static function rejectPayment(int $paymentId, string $reason, array $user): void
    {
        self::need($user, 'cashier');
        if (mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo.');
        $p = Db::one('SELECT * FROM tab_payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'reported') throw new HttpError(422, 'Só recebimentos informados podem ser recusados.');
        Db::exec("UPDATE tab_payments SET status = 'rejected', note = ? WHERE id = ? AND status = 'reported'", [mb_substr(trim($reason), 0, 300), $paymentId]);
        self::log((int)$p['tab_id'], (int)$p['visit_id'], null, $user, 'pay_rejected', money((int)$p['amount_cents']) . ' — ' . $reason);
    }

    /** Registra estorno NO SISTEMA. Não indica devolução efetiva de dinheiro/cartão — isso é feito fora. */
    public static function reversePayment(int $paymentId, string $reason, array $user): void
    {
        self::need($user, 'cashier');
        if (mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo.');
        $p = Db::one('SELECT * FROM tab_payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'confirmed') throw new HttpError(422, 'Só recebimentos confirmados podem ter estorno registrado.');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$p['tab_id']]);
        if ($t['status'] === 'closed') throw new HttpError(422, 'Reabra a comanda antes de registrar o estorno.');
        Db::exec("UPDATE tab_payments SET status = 'reversed', note = ? WHERE id = ? AND status = 'confirmed'", [mb_substr(trim($reason), 0, 300), $paymentId]);
        self::log((int)$p['tab_id'], (int)$p['visit_id'], null, $user, 'pay_reversed', money((int)$p['amount_cents']) . ' — ' . $reason);
    }

    // ---------- ajustes e fechamento ----------
    public static function applyDiscount(int $tabId, int $cents, string $reason, array $user): void
    {
        self::need($user, 'discount');
        if (mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo do desconto.');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t || !in_array($t['status'], ['open', 'awaiting_cashier'], true)) throw new HttpError(422, 'Comanda não encontrada ou já fechada.');
        $tot = self::totals($tabId);
        if ($cents < 0 || $cents > $tot['items'] + $tot['fee']) throw new HttpError(422, 'Desconto inválido.');
        if ($tot['items'] + $tot['fee'] - $cents < $tot['confirmed'] + $tot['reported']) throw new HttpError(422, 'O desconto deixaria a conta abaixo do que já foi recebido/informado.');
        Db::exec('UPDATE tabs SET discount_cents = ?, discount_reason = ?, version = version + 1 WHERE id = ?', [$cents, mb_substr(trim($reason), 0, 300), $tabId]);
        self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'discount', money($cents) . ' — ' . $reason);
    }

    public static function setServiceFee(int $tabId, bool $removed, string $reason, array $user): void
    {
        self::need($user, 'discount');
        if ($removed && mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo para remover a taxa de serviço.');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t || !in_array($t['status'], ['open', 'awaiting_cashier'], true)) throw new HttpError(422, 'Comanda não encontrada ou já fechada.');
        Db::exec('UPDATE tabs SET fee_removed = ?, fee_reason = ?, version = version + 1 WHERE id = ?', [$removed ? 1 : 0, $removed ? mb_substr(trim($reason), 0, 300) : null, $tabId]);
        $tot = self::totals($tabId);
        if ($tot['total'] < $tot['confirmed'] + $tot['reported']) { Db::exec('UPDATE tabs SET fee_removed = ?, fee_reason = ? WHERE id = ?', [$t['fee_removed'], $t['fee_reason'], $tabId]); throw new HttpError(422, 'Isso deixaria a conta abaixo do que já foi recebido/informado.'); }
        self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'fee', $removed ? 'taxa de serviço removida — ' . $reason : 'taxa de serviço restabelecida');
    }

    /** Fecha a comanda: exige total CONFIRMADO integralmente e nenhum recebimento pendente de conferência. */
    public static function closeTab(int $tabId, array $user): void
    {
        self::need($user, 'cashier');
        Db::txImmediate(function () use ($tabId, $user) {
            $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
            if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
            if ($t['status'] === 'closed') return;
            $tot = self::totals($tabId);
            if ($tot['reported'] > 0) throw new HttpError(422, 'Há recebimento informado aguardando conferência. Confirme ou recuse antes de fechar.');
            if ($tot['confirmed'] !== $tot['total']) throw new HttpError(422, 'Saldo pendente de ' . money($tot['total'] - $tot['confirmed']) . '. Só é possível fechar com o total confirmado.');
            $open = (int)Db::val("SELECT COUNT(*) FROM orders WHERE tab_id = ? AND status IN ('received','accepted','preparing')", [$tabId]);
            Db::exec("UPDATE tabs SET status = 'closed', open_key = NULL, items_cents = ?, fee_cents = ?, total_cents = ?, closed_by = ?, closed_by_name = ?, closed_at = ?, version = version + 1 WHERE id = ?",
                [$tot['items'], $tot['fee'], $tot['total'], $user['id'], $user['name'], now(), $tabId]);
            self::syncVisit((int)$t['visit_id']);
            self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'close', 'total ' . money($tot['total']) . ($open ? " ($open pedido(s) de produção ainda em andamento)" : ''));
        });
    }

    public static function reopenTab(int $tabId, string $reason, array $user): void
    {
        self::need($user, 'cashier');
        if (mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo da reabertura.');
        $t = Db::one('SELECT * FROM tabs WHERE id = ?', [$tabId]);
        if (!$t || $t['status'] !== 'closed') throw new HttpError(422, 'Só comandas fechadas podem ser reabertas.');
        try { Db::exec("UPDATE tabs SET status = 'awaiting_cashier', open_key = ?, closed_at = NULL, closed_by = NULL, closed_by_name = NULL, items_cents = NULL, fee_cents = NULL, total_cents = NULL, version = version + 1 WHERE id = ?", [$t['physical_number'], $tabId]); }
        catch (PDOException $e) { throw new HttpError(409, "O número de comanda {$t['physical_number']} já foi reutilizado por outra comanda aberta."); }
        self::syncVisit((int)$t['visit_id']);
        self::log($tabId, (int)$t['visit_id'], (int)$t['table_id'], $user, 'reopen', $reason);
    }

    // ---------- consulta ----------
    public static function tabDetail(int $tabId): ?array
    {
        $t = Db::one('SELECT t.*, tb.number AS table_number FROM tabs t JOIN dining_tables tb ON tb.id = t.table_id WHERE t.id = ?', [$tabId]);
        if (!$t) return null;
        $t['totals'] = self::totals($tabId);
        $t['visit'] = Db::one('SELECT id, name, people, status FROM visits WHERE id = ?', [$t['visit_id']]);
        $t['rounds'] = Db::all("SELECT o.id, o.status, o.created_at, o.notes FROM orders o WHERE o.tab_id = ? ORDER BY o.id", [$tabId]);
        foreach ($t['rounds'] as &$r) { $r['items'] = Db::all('SELECT i.id, i.name, i.size_name, i.qty, i.total_cents, i.notes FROM order_items i WHERE i.order_id = ? ORDER BY i.id', [$r['id']]); foreach ($r['items'] as &$it) $it['options'] = Db::all('SELECT group_name, option_name FROM order_item_options WHERE item_id = ? ORDER BY id', [$it['id']]); unset($it); }
        $t['payments'] = Db::all('SELECT * FROM tab_payments WHERE tab_id = ? ORDER BY id', [$tabId]);
        $t['events'] = Db::all('SELECT * FROM floor_events WHERE tab_id = ? ORDER BY id DESC LIMIT 60', [$tabId]);
        return $t;
    }

    /** Lista de comandas abertas/aguardando (aba "Comandas"). */
    public static function openTabs(): array
    {
        $rows = Db::all("SELECT t.id, t.visit_id, t.physical_number, t.name, t.people, t.status, tb.number AS table_number FROM tabs t JOIN dining_tables tb ON tb.id = t.table_id WHERE t.status IN ('open','awaiting_cashier') ORDER BY t.id DESC");
        foreach ($rows as &$r) $r['total'] = self::totals((int)$r['id'])['total'];
        return $rows;
    }
}
