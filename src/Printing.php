<?php
declare(strict_types=1);

/**
 * Fila de impressão persistente + roteamento por destino + geração de comandas.
 * Criada na MESMA transação do pedido (Orders::persist): se a impressão estiver indisponível, o pedido continua gravado.
 * Destinos lógicos (códigos estáveis): cozinha, copa, balcao. Categoria/produto sem destino => pendência visível ("sem_destino"), nunca descarte silencioso.
 * Estados: queued (pendente) → reserved (reservado por uma estação) → sending (enviando) → printed (enviado ao subsistema de impressão) | failed | uncertain.
 *          unrouted = item sem destino definido (não consumível; exige correção do cadastro).
 * "printed" significa que o subsistema de impressão (QZ Tray/spooler/agente) aceitou o trabalho; NÃO prova papel na mão do cliente.
 */
final class Printing
{
    public const STATUS = ['held' => 'Retido (pausado pelo operador)', 'queued' => 'Pendente', 'reserved' => 'Reservado', 'sending' => 'Enviando', 'printing' => 'Enviando', 'printed' => 'Enviado ao subsistema de impressão',
        'failed' => 'Falhou', 'uncertain' => 'Resultado incerto', 'unrouted' => 'Sem destino'];
    public const SECTORS = ['cozinha' => 'Cozinha', 'copa' => 'Copa/Bar', 'balcao' => 'Balcão/Delivery'];
    private const STALE_SECONDS = 120;
    public const RESERVE_SECONDS = 90;
    private static array $secCache = [];

    /** Configuração por destino: ligado, impressão automática, vias (inteiro ≥ 1), papel e se mostra valores. Balcão sempre mostra valores. */
    public static function sectors(): array
    {
        $d = ['cozinha' => ['on' => 1, 'auto' => 1, 'copies' => 1, 'paper' => 80, 'values' => 0], 'copa' => ['on' => 1, 'auto' => 1, 'copies' => 1, 'paper' => 80, 'values' => 0], 'balcao' => ['on' => 1, 'auto' => 1, 'copies' => 1, 'paper' => 80, 'values' => 1]];
        $s = Settings::json('print_sectors') ?: [];
        foreach ($d as $k => $v) { $s[$k] = ($s[$k] ?? []) + $v; $s[$k]['copies'] = max(1, (int)$s[$k]['copies']); }
        $s['balcao']['values'] = 1;
        return $s;
    }

    /** Salva a configuração dos destinos. Vias: inteiro positivo; para suspender um destino use o controle "ligado". */
    public static function saveSectors(array $in): void
    {
        $cur = self::sectors();
        foreach (self::SECTORS as $k => $_) {
            $row = $in[$k] ?? [];
            $on = !empty($row['on']);
            $c = $row['copies'] ?? 1;
            if (!is_numeric($c) || (int)$c != $c || (int)$c < 1 || (int)$c > 5) { if ($on) throw new HttpError(422, 'Vias de ' . self::SECTORS[$k] . ': informe um número inteiro de 1 a 5 (para suspender o destino, desligue-o).'); $c = $cur[$k]['copies']; }
            $cur[$k] = ['on' => $on ? 1 : 0, 'auto' => !empty($row['auto']) ? 1 : 0, 'copies' => (int)$c, 'paper' => (int)($row['paper'] ?? 80) === 58 ? 58 : 80, 'values' => $k === 'balcao' ? 1 : 0];
        }
        Settings::set('print_sectors', json_encode($cur));
        Auth::audit('print.sectors', 'destinos atualizados');
    }
    /** Destino de um produto: produto.print_sector, senão categoria.sector. null = sem destino (pendência). Usa o ID, nunca o nome. */
    public static function itemSector(int $productId): ?string
    {
        if (!array_key_exists($productId, self::$secCache)) {
            $s = Db::val('SELECT COALESCE(p.print_sector, c.sector) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$productId]);
            $s = $s === 'balcao' ? 'copa' : $s; // legado: o antigo "balcao" de categoria era o bar
            self::$secCache[$productId] = in_array($s, ['cozinha', 'copa'], true) ? $s : null;
        }
        return self::$secCache[$productId];
    }
    public static function forgetCache(): void { self::$secCache = []; }

    /** @return array<string,list<array>> itens do pedido agrupados por destino; 'sem_destino' para os não roteados */
    public static function groupItems(array $items): array
    {
        $g = [];
        foreach ($items as $it) $g[self::itemSector((int)$it['product_id']) ?? 'sem_destino'][] = $it;
        return $g;
    }

    /**
     * ÚNICO ponto que cria os trabalhos de um pedido (chamado por Orders::persist na MESMA transação que grava o pedido).
     * Balcão recebe TODOS os itens; cozinha/copa só os seus; nunca comanda vazia. A configuração do destino (vias, papel, auto)
     * é COPIADA para o trabalho (snap_json): mudar a configuração depois não altera trabalhos já criados.
     * @param string $origin evento de origem: order.created | order.updated | order.cancelled | reprint | manual
     */
    public static function enqueueOrder(int $orderId, string $kind, ?array $user, ?array $onlySectors = null, ?string $reason = null): array
    {
        $o = Db::one('SELECT id, type, version FROM orders WHERE id = ?', [$orderId]);
        if (!$o) return [];
        $items = Db::all('SELECT id, product_id, name FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        $groups = self::groupItems($items); $cfg = self::sectors(); $ids = [];
        $origin = ['original' => 'order.created', 'update' => 'order.updated', 'cancel' => 'order.cancelled', 'reprint' => 'reprint'][$kind] ?? $kind;
        $want = [];
        foreach (['cozinha', 'copa'] as $s) if (!empty($groups[$s]) && !empty($cfg[$s]['on'])) $want[] = $s;   // nunca gera comanda de setor sem itens
        if ($o['type'] !== 'dine_in' && !empty($cfg['balcao']['on']) && $items) $want[] = 'balcao';              // salão: o fechamento é do caixa; delivery/retirada: comanda completa
        foreach ($want as $sector) {
            if ($onlySectors && !in_array($sector, $onlySectors, true)) continue;
            $key = $kind === 'original' ? "o$orderId:$sector:original" : (in_array($kind, ['cancel', 'update'], true) ? "o$orderId:$sector:$kind:v{$o['version']}" : "o$orderId:$sector:$kind:" . bin2hex(random_bytes(6)));
            $c = $cfg[$sector]; $paper = (int)$c['paper'] === 58 ? 58 : 80;
            $f = ['order_id' => $orderId, 'sector' => $sector, 'paper' => $paper, 'kind' => $kind, 'job_key' => $key, 'version' => (int)$o['version'], 'copies' => (int)$c['copies'], 'origin' => $origin,
                'reason' => $reason !== null ? mb_substr($reason, 0, 200) : null, 'snap_json' => json_encode(['copies' => (int)$c['copies'], 'paper' => $paper, 'auto' => (int)$c['auto'], 'values' => (int)$c['values'], 'sector' => $sector], JSON_UNESCAPED_UNICODE)];
            if (empty($c['auto']) && in_array($kind, ['original', 'update', 'cancel'], true)) { $f['status'] = 'held'; $f['last_error'] = 'Impressão automática desligada para este destino; libere para imprimir.'; }
            if ($id = self::insertJob($f, $user)) $ids[] = $id;
        }
        if (!empty($groups['sem_destino']) && in_array($kind, ['original', 'update'], true) && (!$onlySectors || in_array('sem_destino', $onlySectors, true))) {
            $nomes = implode('; ', array_slice(array_unique(array_column($groups['sem_destino'], 'name')), 0, 6));
            if ($id = self::insertJob(['order_id' => $orderId, 'sector' => 'sem_destino', 'paper' => 80, 'kind' => $kind, 'status' => 'unrouted', 'job_key' => "o$orderId:sem_destino:$kind:v{$o['version']}", 'origin' => $origin,
                'last_error' => mb_substr("Sem destino de impressão (defina o setor da categoria/produto): $nomes", 0, 300), 'version' => (int)$o['version']], $user)) $ids[] = $id;
        }
        return $ids;
    }

    /** Pedido cancelado: o que ainda NÃO foi enviado à produção é descartado (com motivo); o que já foi recebe um aviso explícito de cancelamento. */
    public static function onCancel(int $orderId, ?array $user): void
    {
        Db::exec("UPDATE print_jobs SET status = 'failed', last_error = 'Pedido cancelado antes da impressão.', finished_at = ? WHERE order_id = ? AND kind IN ('original','update') AND status IN ('queued','held','unrouted')", [now(), $orderId]);
        $sent = array_column(Db::all("SELECT DISTINCT sector FROM print_jobs WHERE order_id = ? AND kind IN ('original','update','reprint') AND status IN ('reserved','sending','printed','uncertain')", [$orderId]), 'sector');
        if ($sent) self::enqueueOrder($orderId, 'cancel', $user, $sent);
    }
    /** Insere um trabalho de forma idempotente (job_key único). Retorna o id, ou null se já existia. */
    private static function insertJob(array $f, ?array $user): ?int
    {
        if (!empty($f['job_key']) && Db::val('SELECT 1 FROM print_jobs WHERE job_key = ?', [$f['job_key']])) return null;
        try {
            return Db::insert('print_jobs', $f + ['uuid' => self::uuid(), 'status' => 'queued', 'created_by' => $user['name'] ?? 'sistema', 'created_at' => now()]);
        } catch (PDOException $e) {
            if (!empty($f['job_key']) && Db::val('SELECT 1 FROM print_jobs WHERE job_key = ?', [$f['job_key']])) return null;
            throw $e;
        }
    }

    /** Comanda de teste, claramente marcada. Não tem pedido; nunca vira produção. */
    public static function enqueueTest(string $sector, array $user): int
    {
        if (!isset(self::SECTORS[$sector])) throw new HttpError(422, 'Destino inválido.');
        $id = (int)self::insertJob(['order_id' => null, 'sector' => $sector, 'paper' => 80, 'kind' => 'test', 'origin' => 'test', 'job_key' => "test:$sector:" . bin2hex(random_bytes(6))], $user);
        Auth::audit('print.test', "destino $sector");
        return $id;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---------- agente (ponte local por token; legado, continua funcionando) ----------
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
        $c = Db::one("SELECT SUM(CASE WHEN status IN ('queued','reserved','sending','printing') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status IN ('failed','uncertain','unrouted') THEN 1 ELSE 0 END) AS problems FROM print_jobs");
        return ['state' => $state, 'seen' => $seen ?: null, 'pending' => (int)($c['pending'] ?? 0), 'problems' => (int)($c['problems'] ?? 0),
            'label' => ['online' => 'Agente de impressão online', 'offline' => 'Agente de impressão offline', 'never' => 'Agente de impressão nunca conectou'][$state]];
    }

    public static function newAgentToken(): string
    {
        $t = bin2hex(random_bytes(24));
        Settings::set('print_agent_hash', hash('sha256', $t));
        return $t;
    }

    /** Libera reservas vencidas (nada foi enviado: volta à fila) e marca como incertos os envios sem confirmação. */
    public static function sweep(): void
    {
        $now = now();
        Db::exec("UPDATE print_jobs SET status = 'queued', station_id = NULL, reserved_until = NULL WHERE status = 'reserved' AND reserved_until < ?", [$now]);
        Db::exec("UPDATE print_jobs SET status = 'uncertain', last_error = 'Não houve confirmação do resultado; verifique a impressora antes de reimprimir.', finished_at = ? WHERE status IN ('printing','sending') AND claimed_at < ?", [$now, date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]);
    }

    /** Conteúdo do trabalho: linhas (texto) e bytes ESC/POS para o perfil. Salvo no job só o hash. */
    public static function build(array $job, ?array $profile = null): array
    {
        $profile = ($profile ?? []) + ['cols' => (int)($job['paper'] ?? 80) === 58 ? 32 : 48, 'charset' => 'ascii', 'cut' => true, 'mode' => 'escpos', 'margin' => 0, 'copies' => 1, 'paper' => (int)($job['paper'] ?? 80)];
        $wrap = ['cols' => max(20, (int)$profile['cols'] - (int)$profile['margin'])] + $profile;   // largura útil = colunas − margem
        $lines = $job['order_id'] ? self::lines((array)Orders::get((int)$job['order_id']), $job, $wrap) : self::testLines($job, $wrap);
        if ($profile['mode'] === 'html') return ['lines' => $lines, 'bytes' => PrintFormatter::html($lines, $profile), 'mode' => 'html'];
        return ['lines' => $lines, 'bytes' => PrintFormatter::escpos($lines, $profile), 'mode' => 'escpos'];
    }

    /** Retém os trabalhos pendentes (não apaga, não marca como impresso): nenhuma estação os reserva até a liberação explícita. */
    public static function holdQueued(array $user): int
    {
        $n = Db::exec("UPDATE print_jobs SET status = 'held', last_error = 'Retido pelo operador; libere explicitamente para imprimir.' WHERE status = 'queued' AND station_id IS NULL");
        Auth::audit('print.hold', "$n trabalho(s) retido(s)");
        return $n;
    }

    /** Libera UM trabalho retido de volta à fila (decisão explícita e auditada). */
    public static function release(int $jobId, array $user): void
    {
        $n = Db::exec("UPDATE print_jobs SET status = 'queued', last_error = NULL WHERE id = ? AND status = 'held'", [$jobId]);
        if ($n !== 1) throw new HttpError(422, 'Só trabalhos retidos podem ser liberados.');
        Auth::audit('print.release', "job #$jobId");
    }

    /** Ambiente de testes (APP_ENV=staging): nenhuma impressão física sai, a menos que PRINT_ENABLED=1 seja definido de propósito. */
    public static function testEnvBlocked(): bool { return env('APP_ENV') === 'staging' && env('PRINT_ENABLED') !== '1'; }

    /** Entrega o próximo trabalho ao agente por token (um por vez). */
    public static function claim(): ?array
    {
        if (self::testEnvBlocked()) return null;
        self::sweep();
        return Db::txImmediate(function () {
            $j = Db::one("SELECT * FROM print_jobs WHERE status = 'queued' AND sector <> 'sem_destino' ORDER BY id LIMIT 1");
            if (!$j) return null;
            $n = Db::exec("UPDATE print_jobs SET status = 'printing', attempts = attempts + 1, claimed_at = ? WHERE id = ? AND status = 'queued'", [now(), $j['id']]);
            if ($n !== 1) return null;
            $b = self::build($j);
            return ['uuid' => $j['uuid'], 'sector' => $j['sector'], 'paper' => (int)$j['paper'], 'attempt' => (int)$j['attempts'] + 1,
                    'escpos_b64' => base64_encode($b['bytes']), 'text' => implode("\n", array_column($b['lines'], 't'))];
        });
    }

    /** @param bool $sent true se bytes podem ter chegado à impressora (resultado incerto em caso de erro) */
    public static function result(string $uuid, bool $ok, bool $sent, string $error = ''): array
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE uuid = ?', [$uuid]);
        if (!$j) throw new HttpError(404, 'Trabalho não encontrado.');
        if (!in_array($j['status'], ['printing', 'sending'], true)) return ['status' => $j['status']];
        return self::finish($j, $ok, $sent, $error);
    }

    /** Transição final comum ao agente e às estações. */
    public static function finish(array $j, bool $ok, bool $sent, string $error): array
    {
        $error = mb_substr(PrintFormatter::clean($error), 0, 300);
        if ($ok) { $st = 'printed'; $error = null; }
        elseif ($sent) $st = 'uncertain';
        else $st = (int)$j['attempts'] >= (int)Settings::get('print_max_attempts') ? 'failed' : 'queued';
        Db::exec('UPDATE print_jobs SET status = ?, last_error = ?, finished_at = ?, station_id = ?, reserved_until = NULL WHERE id = ? AND status IN (\'printing\',\'sending\',\'reserved\')',
            [$st, $error, $st === 'queued' ? null : now(), $st === 'queued' ? null : $j['station_id'], $j['id']]);
        return ['status' => $st];
    }

    /** Reimpressão manual auditada (explícita, identificada e registrada). */
    public static function reprint(int $orderId, ?string $sector, array $user, string $reason = ''): array
    {
        $reason = trim(PrintFormatter::clean($reason));
        if (mb_strlen($reason) < 3) throw new HttpError(422, 'Informe o motivo da reimpressão (mínimo 3 caracteres).');
        $ids = self::enqueueOrder($orderId, 'reprint', $user, $sector ? [$sector] : null, $reason);
        if (!$ids) throw new HttpError(422, 'Nenhum setor de impressão com itens para este pedido.');
        Orders::event($orderId, $user, 'reprint', null, null, 'Reimpressão' . ($sector ? " ($sector)" : '') . ' — motivo: ' . $reason);
        Auth::audit('print.reprint', "pedido #$orderId " . ($sector ?? 'todos'));
        return $ids;
    }

    /** Reenvio de trabalho com falha ou resultado incerto: ação humana (o operador conferiu o papel). */
    public static function retry(int $jobId, array $user): void
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE id = ?', [$jobId]);
        if (!$j || !in_array($j['status'], ['failed', 'uncertain'], true)) throw new HttpError(422, 'Só trabalhos com falha ou incertos podem ser reenviados.');
        Db::exec("UPDATE print_jobs SET status = 'queued', attempts = 0, last_error = NULL, finished_at = NULL, station_id = NULL, reserved_until = NULL WHERE id = ?", [$jobId]);
        if ($j['order_id']) Orders::event((int)$j['order_id'], $user, 'reprint', null, null, "Reenvio do trabalho #$jobId ({$j['status']})");
        Auth::audit('print.retry', "job #$jobId");
    }

    /** Pendências visíveis: itens sem destino e destinos com trabalho na fila mas sem impressora mapeada em estação ativa. */
    public static function pendencias(): array
    {
        $p = [];
        foreach (Db::all("SELECT id, order_id, last_error FROM print_jobs WHERE status = 'unrouted' ORDER BY id DESC LIMIT 20") as $j) $p[] = ['tipo' => 'sem_destino', 'job' => (int)$j['id'], 'pedido' => (int)$j['order_id'], 'msg' => $j['last_error']];
        foreach (Db::all("SELECT sector, COUNT(*) n FROM print_jobs WHERE status = 'queued' AND sector <> 'sem_destino' GROUP BY sector") as $r) {
            if (!Db::val('SELECT 1 FROM print_station_map m JOIN print_stations s ON s.id = m.station_id WHERE s.active = 1 AND m.active = 1 AND m.sector = ?', [$r['sector']])) $p[] = ['tipo' => 'sem_impressora', 'setor' => $r['sector'], 'n' => (int)$r['n'], 'msg' => 'Há ' . $r['n'] . ' trabalho(s) para ' . (self::SECTORS[$r['sector']] ?? $r['sector']) . ' e nenhuma impressora mapeada em estação ativa.'];
        }
        foreach (Db::all('SELECT c.name, COUNT(p.id) n FROM categories c JOIN products p ON p.category_id = c.id AND p.hidden = 0 AND p.print_sector IS NULL WHERE c.sector IS NULL AND c.active = 1 GROUP BY c.id, c.name') as $r)
            $p[] = ['tipo' => 'categoria_sem_destino', 'msg' => 'Categoria "' . $r['name'] . '" sem destino de impressão (' . $r['n'] . ' produto(s)): defina Cozinha ou Copa/Bar em Configurações → Impressão. Enquanto isso o item só sai na comanda do balcão.'];
        return $p;
    }

    // ---------- conteúdo ----------
    private static function title(array $job): string
    {
        $k = ['reprint' => 'REIMPRESSAO', 'update' => 'PEDIDO ALTERADO', 'cancel' => 'PEDIDO CANCELADO', 'test' => 'TESTE'][$job['kind'] ?? 'original'] ?? null;
        return mb_strtoupper(self::SECTORS[$job['sector']] ?? $job['sector']) . ($k ? ' - ' . $k : '');
    }

    /** @return list<array{t:string,b?:bool,big?:bool,c?:bool}> texto em UTF-8, já limpo; o charset é aplicado na saída ESC/POS */
    public static function lines(array $o, array $job, ?array $profile = null): array
    {
        $w = (int)($profile['cols'] ?? ((int)($job['paper'] ?? 80) === 58 ? 32 : 48));
        $sector = $job['sector']; $kind = $job['kind'] ?? 'original'; $dine = $o['type'] === 'dine_in';
        $values = $sector === 'balcao';   // o balcão sempre leva a comanda completa com valores e pagamento
        $L = [];
        $add = function (string $t, array $f = []) use (&$L, $w) {
            foreach (PrintFormatter::wrap(PrintFormatter::clean($t), !empty($f['big']) ? intdiv($w, 2) : $w) as $part) $L[] = ['t' => $part] + $f;
        };
        $rule = fn() => $add(str_repeat('-', $w));
        $add('PIZZARIA BIANCA', ['c' => true, 'b' => true]);
        $add(self::title($job), ['c' => true, 'b' => true]);
        if (env('APP_ENV') === 'staging') $add('TESTE — NÃO PRODUZIR', ['c' => true, 'b' => true, 'big' => true]);   // ambiente de testes: toda folha é marcada
        if ($kind === 'update') $add('*** PEDIDO ALTERADO — esta comanda substitui a anterior ***', ['c' => true, 'b' => true]);
        if ($kind === 'cancel') $add('*** NAO PRODUZIR ***', ['c' => true, 'b' => true, 'big' => true]);
        $add($dine ? 'MESA ' . $o['table_number'] : 'PEDIDO #' . $o['id'], ['c' => true, 'big' => true]);
        if ($dine) $add('Pedido #' . $o['id'], ['c' => true]);
        $add(date('d/m/Y H:i', strtotime($o['created_at'])), ['c' => true]);
        if ($kind === 'reprint') { $add('*** REIMPRESSÃO ' . date('d/m/Y H:i') . ' ***', ['c' => true, 'b' => true, 'big' => true]); $add('Operador: ' . ($job['created_by'] ?? '-') . ' | Motivo: ' . ($job['reason'] ?? '-'), ['b' => true]); }
        $rule();
        if ($dine) {
            $tab = $o['tab_id'] ? Db::one('SELECT physical_number FROM tabs WHERE id = ?', [$o['tab_id']]) : null;
            $add('SALAO - MESA ' . $o['table_number'], ['b' => true, 'big' => true]);
            $add('Comanda: ' . ($tab['physical_number'] ?? 'sem no fisico') . ' (atend. #' . $o['visit_id'] . ')');
            $add('Cliente: ' . $o['customer_name']);
            $add('Garcom: ' . (($o['events'][0]['user_name'] ?? '') ?: '-'));
        } else {
            $add($o['type'] === 'delivery' ? 'ENTREGA' : 'RETIRADA NO BALCÃO', ['b' => true, 'big' => true, 'c' => true]);
            if ($sector === 'balcao') {
                $add('Cliente: ' . $o['customer_name']);
                if ($o['customer_phone'] !== '') { $d = preg_replace('/\D+/', '', (string)$o['customer_phone']); $add('Tel: ' . (strlen($d) === 11 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)) : (strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)) : $o['customer_phone']))); }
                if ($o['type'] === 'delivery') {   // endereço só quando necessário à entrega
                    $add('End: ' . $o['addr_street'] . ', ' . $o['addr_number'] . ($o['addr_complement'] ? ' - ' . $o['addr_complement'] : ''));
                    $add('Bairro: ' . $o['zone_name']);
                    if ($o['addr_reference']) $add('Ref: ' . $o['addr_reference']);
                }
            }
        }
        $rule();
        foreach ($o['items'] as $it) {
            if ($sector !== 'balcao' && self::itemSector((int)$it['product_id']) !== $sector) continue;
            $add($it['qty'] . 'x ' . $it['name'] . ($it['size_name'] ? ' (' . $it['size_name'] . ')' : ''), ['b' => true]);
            foreach ($it['options'] as $op) $add(($op['group_name'] === 'Retirar' ? '  - ' : '  + ') . $op['option_name'] . ($values && $op['price_cents'] ? ' ' . money((int)$op['price_cents']) : ''), ['b' => true]);
            if ($it['notes']) $add($sector === 'balcao' ? '  OBS: ' . $it['notes'] : '  >>> OBSERVAÇÃO: ' . $it['notes'], ['b' => true] + ($sector === 'balcao' ? [] : ['big' => true]));
            if ($values) $add(PrintFormatter::pad(money((int)$it['total_cents']), $w));
        }
        if ($o['notes'] && $sector !== 'copa') { $rule(); $add(($sector === 'cozinha' ? '>>> OBS DO PEDIDO: ' : 'OBS DO PEDIDO: ') . $o['notes'], ['b' => true]); }
        if ($values) {
            $rule();
            $row = fn($a, $b) => $add($a . PrintFormatter::pad($b, $w - mb_strlen($a)));
            $row('Subtotal', money((int)$o['subtotal_cents']));
            if ($o['delivery_fee_cents']) $row('Taxa entrega', money((int)$o['delivery_fee_cents']));
            if ($o['discount_cents']) $row('Desconto', '-' . money((int)$o['discount_cents']));
            $add('TOTAL ' . money((int)$o['total_cents']), ['b' => true, 'big' => true, 'c' => true]);
            $add('Forma de pagamento: ' . $o['payment_label'], ['b' => true]);
            $add($o['payment_status'] === 'confirmed' ? 'PAGAMENTO CONFIRMADO' : ($o['payment_status'] === 'refunded' ? 'PAGAMENTO ESTORNADO' : 'PAGAMENTO PENDENTE'), ['b' => true, 'big' => true]);
            if ($o['payment_status'] === 'pending') $add('(ainda não recebido)', ['b' => true]);
            if ($o['change_for_cents']) $add('Troco para: ' . money((int)$o['change_for_cents']));
        }
        $add('');
        return $L;
    }

    private static function testLines(array $job, array $profile): array
    {
        $w = (int)$profile['cols']; $L = [];
        $add = function (string $t, array $f = []) use (&$L, $w) { foreach (PrintFormatter::wrap(PrintFormatter::clean($t), !empty($f['big']) ? intdiv($w, 2) : $w) as $p) $L[] = ['t' => $p] + $f; };
        $add('BIANCA PIZZARIA — TESTE — NÃO PRODUZIR', ['c' => true, 'b' => true, 'big' => true]);
        $add(str_repeat('-', $w));
        $add('ID do teste: ' . strtoupper(substr(str_replace('-', '', (string)($job['uuid'] ?? '')), 0, 12)), ['b' => true]);
        $add('Data/hora: ' . date('d/m/Y H:i:s'));
        $add('Estação: ' . ($profile['station'] ?? '—'));
        $add('Impressora: ' . ($profile['printer'] ?? '—'));
        $add('Destino: ' . (self::SECTORS[$job['sector']] ?? $job['sector']) . ' | Vias esperadas: ' . max(1, (int)($profile['copies'] ?? 1)));
        $add('Modo: ' . ($profile['mode'] ?? 'escpos') . ' | Colunas: ' . $w . ' | Charset: ' . $profile['charset']);
        $add(str_repeat('-', $w));
        $add('Pizza, balcão, observações, açúcar', ['b' => true]);
        $add('');
        return $L;
    }

    /** Compatível com o uso anterior: bytes ESC/POS com perfil padrão (ASCII, corte parcial). */
    public static function escpos(array $lines, array $profile = []): string { return PrintFormatter::escpos($lines, $profile); }
    public static function ascii(string $s): string { return PrintFormatter::ascii($s); }
}
