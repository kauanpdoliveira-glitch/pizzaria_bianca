<?php
declare(strict_types=1);

/**
 * Fila de impressão persistente + geração de comandas (ESC/POS e HTML).
 * Estados: queued → printing → printed | failed | uncertain (resultado incerto: risco de duplicar).
 */
final class Printing
{
    public const STATUS = ['queued' => 'Na fila', 'printing' => 'Imprimindo', 'printed' => 'Impressa', 'failed' => 'Falhou', 'uncertain' => 'Incerta'];
    private const STALE_SECONDS = 120;

    public static function enqueueOrder(int $orderId, string $kind, ?array $user, ?array $onlySectors = null): array
    {
        $ids = [];
        $dine = Db::val('SELECT type FROM orders WHERE id = ?', [$orderId]) === 'dine_in' ? self::itemSectors($orderId) : null; // salão: só os setores que têm itens
        foreach (Settings::json('print_sectors') as $sector => $cfg) {
            if (empty($cfg['on']) || ($onlySectors && !in_array($sector, $onlySectors, true))) continue;
            if ($dine !== null && !in_array($sector, $dine, true)) continue;
            $ids[] = Db::insert('print_jobs', [
                'uuid' => self::uuid(), 'order_id' => $orderId, 'sector' => $sector, 'paper' => (int)($cfg['paper'] ?? 80) === 58 ? 58 : 80,
                'kind' => $kind, 'status' => 'queued', 'created_by' => $user['name'] ?? 'sistema', 'created_at' => now(),
            ]);
        }
        return $ids;
    }

    /** Setores de produção dos itens de um pedido do salão (categoria.sector; vazio = cozinha). */
    public static function itemSectors(int $orderId): array
    {
        return array_column(Db::all("SELECT DISTINCT COALESCE(c.sector, 'cozinha') AS s FROM order_items i LEFT JOIN products p ON p.id = i.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE i.order_id = ?", [$orderId]), 's');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---------- agente (ponte local) ----------
    public static function authAgent(): void
    {
        $h = Settings::get('print_agent_hash');
        $tok = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' || $tok === '' || !hash_equals($h, hash('sha256', $tok))) json_out(['error' => 'Token inválido'], 401);
        Settings::set('print_agent_seen', now());
    }

    /** Estado do agente a partir do último contato: online (<60 s), offline ou never. Também conta fila e problemas. */
    public static function agentState(): array
    {
        $seen = Settings::get('print_agent_seen');
        $age = $seen === '' ? null : time() - strtotime($seen);
        $state = $age === null ? 'never' : ($age <= 60 ? 'online' : 'offline');
        $c = Db::one("SELECT SUM(CASE WHEN status IN ('queued','printing') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status IN ('failed','uncertain') THEN 1 ELSE 0 END) AS problems FROM print_jobs");
        return ['state' => $state, 'seen' => $seen ?: null, 'pending' => (int)($c['pending'] ?? 0), 'problems' => (int)($c['problems'] ?? 0),
            'label' => ['online' => 'Agente de impressão online', 'offline' => 'Agente de impressão offline', 'never' => 'Agente de impressão nunca conectou'][$state]];
    }

    public static function newAgentToken(): string
    {
        $t = bin2hex(random_bytes(24));
        Settings::set('print_agent_hash', hash('sha256', $t));
        return $t;
    }

    /** Entrega o próximo trabalho ao agente (um por vez). Trabalhos "printing" abandonados viram "uncertain". */
    public static function claim(): ?array
    {
        Db::exec("UPDATE print_jobs SET status = 'uncertain', last_error = 'Agente não confirmou o resultado; verifique a impressora antes de reimprimir.', finished_at = ? WHERE status = 'printing' AND claimed_at < ?", [now(), date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]);
        return Db::txImmediate(function () {
            $j = Db::one("SELECT * FROM print_jobs WHERE status = 'queued' ORDER BY id LIMIT 1");
            if (!$j) return null;
            $n = Db::exec("UPDATE print_jobs SET status = 'printing', attempts = attempts + 1, claimed_at = ? WHERE id = ? AND status = 'queued'", [now(), $j['id']]);
            if ($n !== 1) return null;
            $o = Orders::get((int)$j['order_id']);
            return ['uuid' => $j['uuid'], 'sector' => $j['sector'], 'paper' => (int)$j['paper'], 'attempt' => (int)$j['attempts'] + 1,
                    'escpos_b64' => base64_encode(self::escpos(self::lines($o, $j))), 'text' => implode("\n", array_column(self::lines($o, $j), 't'))];
        });
    }

    /**
     * @param bool $sent true se bytes podem ter chegado à impressora (resultado incerto em caso de erro)
     */
    public static function result(string $uuid, bool $ok, bool $sent, string $error = ''): array
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE uuid = ?', [$uuid]);
        if (!$j) throw new HttpError(404, 'Trabalho não encontrado.');
        if ($j['status'] !== 'printing') return ['status' => $j['status']];
        $error = mb_substr($error, 0, 300);
        if ($ok) { $st = 'printed'; $error = null; }
        elseif ($sent) $st = 'uncertain';
        else $st = (int)$j['attempts'] >= (int)Settings::get('print_max_attempts') ? 'failed' : 'queued';
        Db::exec('UPDATE print_jobs SET status = ?, last_error = ?, finished_at = ? WHERE id = ?', [$st, $error, $st === 'queued' ? null : now(), $j['id']]);
        return ['status' => $st];
    }

    /** Reimpressão manual auditada. */
    public static function reprint(int $orderId, ?string $sector, array $user): array
    {
        $ids = self::enqueueOrder($orderId, 'reprint', $user, $sector ? [$sector] : null);
        if (!$ids) throw new HttpError(422, 'Nenhum setor de impressão ativo.');
        Orders::event($orderId, $user, 'reprint', null, null, 'Reimpressão' . ($sector ? " ($sector)" : ''));
        Auth::audit('print.reprint', "pedido #$orderId " . ($sector ?? 'todos'));
        return $ids;
    }

    public static function retry(int $jobId, array $user): void
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE id = ?', [$jobId]);
        if (!$j || !in_array($j['status'], ['failed', 'uncertain'], true)) throw new HttpError(422, 'Só trabalhos com falha ou incertos podem ser reenviados.');
        Db::exec("UPDATE print_jobs SET status = 'queued', attempts = 0, last_error = NULL, finished_at = NULL WHERE id = ?", [$jobId]);
        Orders::event((int)$j['order_id'], $user, 'reprint', null, null, "Reenvio do trabalho #$jobId ({$j['status']})");
        Auth::audit('print.retry', "job #$jobId");
    }

    // ---------- conteúdo ----------
    /** @return list<array{t:string,b?:bool,big?:bool,c?:bool}> */
    public static function lines(array $o, array $job): array
    {
        if ($o['type'] === 'dine_in') return self::linesDineIn($o, $job);
        $w = (int)$job['paper'] === 58 ? 32 : 48;
        $cfg = Settings::json('print_sectors')[$job['sector']] ?? [];
        $values = !empty($cfg['values']);
        $L = [];
        $add = function (string $t, array $f = []) use (&$L, $w) {
            $max = !empty($f['big']) ? intdiv($w, 2) : $w; // fonte dupla ocupa 2 colunas por caractere
            foreach (explode("\n", wordwrap(self::ascii($t), $max, "\n", true)) as $part) $L[] = ['t' => $part] + $f;
        };
        $rule = fn() => $add(str_repeat('-', $w));
        $add('PIZZARIA BIANCA', ['c' => true, 'b' => true]);
        $add(strtoupper($job['sector']) . ($job['kind'] === 'reprint' ? ' - REIMPRESSAO' : ''), ['c' => true, 'b' => true]);
        $add('PEDIDO #' . $o['id'], ['c' => true, 'big' => true]);
        $add(date('d/m/Y H:i', strtotime($o['created_at'])), ['c' => true]);
        if ($job['kind'] === 'reprint') $add('*** REIMPRESSAO ' . date('d/m H:i') . ' ***', ['c' => true, 'b' => true]);
        $rule();
        $add($o['type'] === 'delivery' ? 'ENTREGA' : 'RETIRADA NO BALCAO', ['b' => true, 'big' => true]);
        $add('Cliente: ' . $o['customer_name']);
        if ($o['customer_phone'] !== '') $add('Tel: ' . $o['customer_phone']);
        if ($o['type'] === 'delivery' && ($values || $job['sector'] !== 'cozinha')) {
            $add('End: ' . $o['addr_street'] . ', ' . $o['addr_number'] . ($o['addr_complement'] ? ' - ' . $o['addr_complement'] : ''));
            $add('Bairro: ' . $o['zone_name']);
            if ($o['addr_reference']) $add('Ref: ' . $o['addr_reference']);
        }
        $rule();
        foreach ($o['items'] as $it) {
            $add($it['qty'] . 'x ' . $it['name'] . ($it['size_name'] ? ' (' . $it['size_name'] . ')' : ''), ['b' => true]);
            foreach ($it['options'] as $op) $add(($op['group_name'] === 'Retirar' ? '  - ' : '  + ') . $op['option_name'] . ($values && $op['price_cents'] ? ' ' . money((int)$op['price_cents']) : ''), ['b' => true]);
            if ($it['notes']) $add('  OBS: ' . $it['notes'], ['b' => true]);
            if ($values) $add(str_pad(money((int)$it['total_cents']), $w, ' ', STR_PAD_LEFT));
        }
        if ($o['notes']) { $rule(); $add('OBS DO PEDIDO: ' . $o['notes'], ['b' => true]); }
        if ($values) {
            $rule();
            $row = fn($a, $b) => $add($a . str_pad($b, $w - strlen(self::ascii($a)), ' ', STR_PAD_LEFT));
            $row('Subtotal', money((int)$o['subtotal_cents']));
            if ($o['delivery_fee_cents']) $row('Taxa entrega', money((int)$o['delivery_fee_cents']));
            if ($o['discount_cents']) $row('Desconto', '-' . money((int)$o['discount_cents']));
            $add(str_pad('TOTAL ' . money((int)$o['total_cents']), intdiv($w, 2), ' ', STR_PAD_LEFT), ['b' => true, 'big' => true]);
            $add('Pagto: ' . $o['payment_label'] . ' [' . strtoupper(Orders::PAY_STATUS[$o['payment_status']] ?? '') . ']', ['b' => true]);
            if ($o['change_for_cents']) $add('Troco para: ' . money((int)$o['change_for_cents']));
        }
        $add('');
        return $L;
    }

    /** Comanda de produção do salão: mesa, comanda, garçom e SOMENTE os itens do setor. Sem valores, endereço ou pagamento. */
    private static function linesDineIn(array $o, array $job): array
    {
        $w = (int)$job['paper'] === 58 ? 32 : 48; $L = [];
        $add = function (string $t, array $f = []) use (&$L, $w) {
            $max = !empty($f['big']) ? intdiv($w, 2) : $w;
            foreach (explode("\n", wordwrap(self::ascii($t), $max, "\n", true)) as $part) $L[] = ['t' => $part] + $f;
        };
        $tab = $o['tab_id'] ? Db::one('SELECT physical_number FROM tabs WHERE id = ?', [$o['tab_id']]) : null;
        $kind = ['reprint' => 'REIMPRESSAO', 'update' => 'PEDIDO ALTERADO', 'cancel' => 'PEDIDO CANCELADO'][$job['kind']] ?? null;
        $add('PIZZARIA BIANCA', ['c' => true, 'b' => true]);
        $add(strtoupper($job['sector']) . ($kind ? ' - ' . $kind : ''), ['c' => true, 'b' => true]);
        if ($job['kind'] === 'cancel') $add('*** NAO PRODUZIR ***', ['c' => true, 'b' => true, 'big' => true]);
        $add('PEDIDO #' . $o['id'], ['c' => true, 'big' => true]);
        $add(date('d/m/Y H:i', strtotime($o['created_at'])), ['c' => true]);
        $add(str_repeat('-', $w));
        $add('SALAO - MESA ' . $o['table_number'], ['b' => true, 'big' => true]);
        $add('Comanda: ' . ($tab['physical_number'] ?? 'sem no fisico') . ' (atend. #' . $o['visit_id'] . ')');
        $add('Cliente: ' . $o['customer_name']);
        $add('Garcom: ' . (($o['events'][0]['user_name'] ?? '') ?: '-'));
        $add(str_repeat('-', $w));
        foreach ($o['items'] as $it) {
            $sec = Db::val("SELECT COALESCE(c.sector, 'cozinha') FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?", [$it['product_id']]) ?: 'cozinha';
            if ($sec !== $job['sector']) continue;
            $add($it['qty'] . 'x ' . $it['name'] . ($it['size_name'] ? ' (' . $it['size_name'] . ')' : ''), ['b' => true]);
            foreach ($it['options'] as $op) $add(($op['group_name'] === 'Retirar' ? '  - ' : '  + ') . $op['option_name'], ['b' => true]);
            if ($it['notes']) $add('  OBS: ' . $it['notes'], ['b' => true]);
        }
        if ($o['notes']) { $add(str_repeat('-', $w)); $add('OBS: ' . $o['notes'], ['b' => true]); }
        $add('');
        return $L;
    }

    public static function escpos(array $lines): string
    {
        $o = "\x1B@\x1Bt\x10"; // init + codepage (ASCII seguro)
        foreach ($lines as $l) {
            $o .= "\x1Ba" . (!empty($l['c']) ? "\x01" : "\x00");
            $o .= "\x1BE" . (!empty($l['b']) ? "\x01" : "\x00");
            $o .= "\x1D!" . (!empty($l['big']) ? "\x11" : "\x00");
            $o .= $l['t'] . "\n";
        }
        return $o . "\x1D!\x00\x1BE\x00\x1Ba\x00\n\n\n\x1DV\x42\x03"; // corte parcial
    }

    public static function ascii(string $s): string
    {
        return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c',
            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','½'=>'1/2','–'=>'-','—'=>'-','’'=>"'",'“'=>'"','”'=>'"']);
    }
}
