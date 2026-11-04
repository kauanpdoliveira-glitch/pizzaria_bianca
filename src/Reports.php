<?php
declare(strict_types=1);

/**
 * Relatórios operacionais (NÃO são contabilidade fiscal nem conciliação bancária).
 * Critérios — período por data de criação do pedido:
 *  - Pedidos válidos: todos exceto cancelados. "Valor dos pedidos" = soma de total dos válidos.
 *  - Concluídos: status "completed".
 *  - Pagamento confirmado: payment_status = confirmed em pedido não cancelado (conferido pela equipe).
 *  - Pendente: payment_status = pending em pedido não cancelado.
 *  - Cancelados: listados à parte; nunca entram em valores válidos.
 */
final class Reports
{
    public static function summary(string $from, string $to): array
    {
        $a = $from . ' 00:00:00'; $b = $to . ' 23:59:59';
        $sum = Db::one("SELECT
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END),0) AS valid_count,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cents ELSE 0 END),0) AS valid_total,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN subtotal_cents ELSE 0 END),0) AS subtotal,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN delivery_fee_cents ELSE 0 END),0) AS fees,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN discount_cents ELSE 0 END),0) AS discounts,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END),0) AS completed_count,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_cents ELSE 0 END),0) AS completed_total,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' AND payment_status = 'confirmed' THEN total_cents ELSE 0 END),0) AS paid_total,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' AND payment_status = 'pending' THEN total_cents ELSE 0 END),0) AS pending_total,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END),0) AS cancelled_count,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN total_cents ELSE 0 END),0) AS cancelled_total
            FROM orders WHERE origin <> 'salao' AND created_at BETWEEN ? AND ?", [$a, $b]);
        $sum = array_map('intval', $sum);
        $sum['avg_ticket'] = $sum['valid_count'] ? (int)round($sum['valid_total'] / $sum['valid_count']) : 0;

        $byPay = Db::all("SELECT payment_label AS label, COUNT(*) AS n, SUM(total_cents) AS total,
            SUM(CASE WHEN payment_status = 'confirmed' THEN total_cents ELSE 0 END) AS paid,
            SUM(CASE WHEN payment_status = 'pending' THEN total_cents ELSE 0 END) AS pending
            FROM orders WHERE origin <> 'salao' AND status <> 'cancelled' AND created_at BETWEEN ? AND ? GROUP BY payment_label ORDER BY total DESC", [$a, $b]);
        $top = Db::all("SELECT i.name, i.size_name, SUM(i.qty) AS qty, SUM(i.total_cents) AS total
            FROM order_items i JOIN orders o ON o.id = i.order_id
            WHERE o.status <> 'cancelled' AND o.created_at BETWEEN ? AND ? GROUP BY i.name, i.size_name ORDER BY qty DESC, total DESC LIMIT 15", [$a, $b]);
        $byDay = Db::all("SELECT SUBSTR(created_at,1,10) AS day, COUNT(*) AS n, SUM(total_cents) AS total
            FROM orders WHERE origin <> 'salao' AND status <> 'cancelled' AND created_at BETWEEN ? AND ? GROUP BY SUBSTR(created_at,1,10) ORDER BY day", [$a, $b]);
        return ['sum' => $sum, 'by_payment' => $byPay, 'top' => $top, 'by_day' => $byDay];
    }

    public static function csv(string $from, string $to): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['Pedido', 'Data', 'Cliente', 'Canal', 'Status', 'Pagamento', 'Status pagamento', 'Subtotal', 'Taxa entrega', 'Desconto', 'Total'], ';');
        $rows = Db::all("SELECT * FROM orders WHERE origin <> 'salao' AND created_at BETWEEN ? AND ? ORDER BY id", [$from . ' 00:00:00', $to . ' 23:59:59']);
        $c = fn(int $v) => number_format($v / 100, 2, ',', '');
        foreach ($rows as $o) {
            // proteção contra injeção de fórmula em planilhas
            $name = preg_match('/^[=+\-@]/', $o['customer_name']) ? "'" . $o['customer_name'] : $o['customer_name'];
            fputcsv($f, [$o['id'], $o['created_at'], $name, $o['type'] === 'delivery' ? 'Entrega' : 'Retirada', Orders::STATUS[$o['status']], $o['payment_label'],
                Orders::PAY_STATUS[$o['payment_status']], $c((int)$o['subtotal_cents']), $c((int)$o['delivery_fee_cents']), $c((int)$o['discount_cents']), $c((int)$o['total_cents'])], ';');
        }
        rewind($f);
        return stream_get_contents($f);
    }

    // ====================== Visão executiva (proprietário) ======================
    /**
     * Critérios:
     *  - Delivery e retirada: pedidos (origin ≠ salão) não cancelados, por data de criação. "Faturamento" = total dos pedidos.
     *  - Salão: a venda é a COMANDA fechada no período (itens − desconto; taxa de serviço separada). Os pedidos de produção
     *    do salão (origin = salão) nunca entram como venda: seriam contados duas vezes. Consumo em aberto é mostrado à parte.
     *  - Recebido = pagamentos CONFIRMADOS (pedidos com payment_status confirmed + recebimentos de comanda confirmados no período, menos estornos registrados).
     *  - Pendente = pedidos válidos sem pagamento conferido + saldo das comandas abertas/aguardando.
     *  - Lucro/margem/CMV: indisponíveis (não há custos cadastrados).
     */
    public static function executive(string $from, string $to): array
    {
        $a = $from . ' 00:00:00'; $b = $to . ' 23:59:59';
        $d = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pf = date('Y-m-d', strtotime($from . " -$d days")); $pt = date('Y-m-d', strtotime($from . ' -1 day'));
        $period = function (string $x, string $y): array {
            $ch = [];
            foreach (['delivery' => 'Entrega', 'pickup' => 'Retirada'] as $k => $lbl) {
                $r = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents),0) AS total, COALESCE(SUM(CASE WHEN payment_status='confirmed' THEN total_cents ELSE 0 END),0) AS paid,
                    COALESCE(SUM(CASE WHEN payment_status='pending' THEN total_cents ELSE 0 END),0) AS pending, COALESCE(SUM(discount_cents),0) AS disc
                    FROM orders WHERE origin <> 'salao' AND type = ? AND status <> 'cancelled' AND created_at BETWEEN ? AND ?", [$k, $x . ' 00:00:00', $y . ' 23:59:59']);
                $ch[$k] = ['label' => $lbl] + array_map('intval', $r);
            }
            $t = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(items_cents),0) AS items, COALESCE(SUM(fee_cents),0) AS fee, COALESCE(SUM(discount_cents),0) AS disc, COALESCE(SUM(total_cents),0) AS total
                FROM tabs WHERE status = 'closed' AND closed_at BETWEEN ? AND ?", [$x . ' 00:00:00', $y . ' 23:59:59']);
            $v = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(people),0) AS people FROM visits WHERE opened_at BETWEEN ? AND ?", [$x . ' 00:00:00', $y . ' 23:59:59']);
            $ch['salon'] = ['label' => 'Salão (comandas fechadas)', 'n' => (int)$t['n'], 'items' => (int)$t['items'], 'fee' => (int)$t['fee'], 'disc' => (int)$t['disc'], 'total' => (int)$t['items'] - (int)$t['disc'], 'visits' => (int)$v['n'], 'people' => (int)$v['people']];
            $rev = $ch['delivery']['total'] + $ch['pickup']['total'] + $ch['salon']['total'];
            return ['channels' => $ch, 'revenue' => $rev, 'orders' => $ch['delivery']['n'] + $ch['pickup']['n'], 'tabs' => (int)$t['n']];
        };
        $cur = $period($from, $to); $prev = $period($pf, $pt);
        $paidOrders = (int)Db::val("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE origin <> 'salao' AND status <> 'cancelled' AND payment_status = 'confirmed' AND created_at BETWEEN ? AND ?", [$a, $b]);
        $paidTabs = (int)Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM tab_payments WHERE status = 'confirmed' AND confirmed_at BETWEEN ? AND ?", [$a, $b]);
        $pendOrders = (int)Db::val("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE origin <> 'salao' AND status <> 'cancelled' AND payment_status = 'pending' AND created_at BETWEEN ? AND ?", [$a, $b]);
        $openTabs = Floor::openTabs(); $openBalance = 0; $openConsumption = 0;
        foreach ($openTabs as $t) { $tt = Floor::totals((int)$t['id']); $openBalance += max(0, $tt['pending']); $openConsumption += $tt['total']; }
        $byPay = [];
        foreach (Db::all("SELECT payment_label AS m, COUNT(*) AS n, SUM(total_cents) AS total FROM orders WHERE origin <> 'salao' AND status <> 'cancelled' AND payment_status = 'confirmed' AND created_at BETWEEN ? AND ? GROUP BY payment_label", [$a, $b]) as $r) $byPay[$r['m']] = ['n' => (int)$r['n'], 'total' => (int)$r['total']];
        $pm = Settings::json('payment_methods');
        foreach (Db::all("SELECT method, COUNT(*) AS n, SUM(amount_cents) AS total FROM tab_payments WHERE status = 'confirmed' AND confirmed_at BETWEEN ? AND ? GROUP BY method", [$a, $b]) as $r) {
            $lbl = ($pm[$r['method']]['label'] ?? $r['method']) . ' (salão)'; $byPay[$lbl] = ['n' => (int)$r['n'], 'total' => (int)$r['total']];
        }
        $cancel = Db::all("SELECT COALESCE(cancel_reason,'(sem motivo)') AS reason, COUNT(*) AS n, SUM(total_cents) AS total FROM orders WHERE status = 'cancelled' AND created_at BETWEEN ? AND ? GROUP BY cancel_reason ORDER BY n DESC LIMIT 10", [$a, $b]);
        $discounts = Db::all("SELECT discount_reason AS reason, COUNT(*) AS n, SUM(discount_cents) AS total FROM tabs WHERE discount_cents > 0 AND opened_at BETWEEN ? AND ? GROUP BY discount_reason ORDER BY total DESC LIMIT 10", [$a, $b]);
        $dur = Db::all("SELECT opened_at, closed_at FROM visits WHERE status = 'closed' AND closed_at BETWEEN ? AND ?", [$a, $b]); $mins = array_map(fn($v) => (strtotime($v['closed_at']) - strtotime($v['opened_at'])) / 60, $dur);
        $top = Db::all("SELECT i.name, i.size_name, SUM(i.qty) AS qty, SUM(i.total_cents) AS total FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status <> 'cancelled' AND o.created_at BETWEEN ? AND ? GROUP BY i.name, i.size_name ORDER BY qty DESC, total DESC LIMIT 10", [$a, $b]);
        $tables = Floor::tables();
        $now = now();
        // atraso calculado em PHP: mesmo resultado em SQLite e MySQL (sem funções de data específicas de cada banco)
        $late = 0;
        foreach (Db::all("SELECT created_at, estimate_minutes FROM orders WHERE status IN ('received','accepted','preparing') AND estimate_minutes IS NOT NULL") as $o) {
            if (strtotime($now) - strtotime($o['created_at']) > (int)$o['estimate_minutes'] * 60) $late++;
        }
        $backups = glob(APP_ROOT . '/storage/backups/*.json') ?: []; usort($backups, fn($x, $y) => filemtime($y) <=> filemtime($x));
        return [
            'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'cur' => $cur, 'prev' => $prev,
            'received' => $paidOrders + $paidTabs, 'pending' => $pendOrders + $openBalance, 'open_consumption' => $openConsumption, 'by_payment' => $byPay,
            'cancel' => $cancel, 'discounts' => $discounts, 'top' => $top,
            'unavailable' => Db::all("SELECT p.name, c.name AS cat FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.available = 0 OR c.active = 0 ORDER BY c.name, p.name"),
            'salon_now' => ['free' => count(array_filter($tables, fn($t) => $t['state'] === 'free')), 'busy' => count(array_filter($tables, fn($t) => $t['state'] === 'busy')), 'awaiting' => count(array_filter($tables, fn($t) => $t['state'] === 'awaiting')),
                'people' => array_sum(array_column($tables, 'people')), 'open_tabs' => count(array_filter($openTabs, fn($t) => $t['status'] === 'open')), 'awaiting_tabs' => count(array_filter($openTabs, fn($t) => $t['status'] === 'awaiting_cashier')),
                'pending_payments' => (int)Db::val("SELECT COUNT(*) FROM tab_payments WHERE status = 'reported'")],
            'avg_visit_min' => $mins ? (int)round(array_sum($mins) / count($mins)) : null,
            'health' => ['late_orders' => $late, 'print_problems' => (int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE status IN ('failed','uncertain')"),
                'print_stuck' => (int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE status = 'queued' AND created_at < ?", [date('Y-m-d H:i:s', time() - 300)]), 'agent' => Printing::agentState(),
                'unaccepted' => (int)Db::val("SELECT COUNT(*) FROM orders WHERE status = 'received' AND created_at < ?", [date('Y-m-d H:i:s', time() - 600)]),
                'last_backup' => $backups ? date('Y-m-d H:i:s', filemtime($backups[0])) : null, 'shift_open' => Cash::currentId() !== null],
        ];
    }

    /** CSV das comandas do salão (por data de abertura). */
    public static function csvTabs(string $from, string $to): string
    {
        $f = fopen('php://temp', 'r+'); fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['Comanda (id)', 'Mesa', 'Atendimento', 'Nº físico', 'Cliente', 'Pessoas', 'Abertura', 'Fechamento', 'Status', 'Consumo', 'Taxa de serviço', 'Desconto', 'Total', 'Recebido confirmado'], ';');
        $c = fn(int $v) => number_format($v / 100, 2, ',', '');
        foreach (Db::all('SELECT t.*, tb.number AS tn FROM tabs t JOIN dining_tables tb ON tb.id = t.table_id WHERE t.opened_at BETWEEN ? AND ? ORDER BY t.id', [$from . ' 00:00:00', $to . ' 23:59:59']) as $t) {
            $tt = Floor::totals((int)$t['id']);
            $name = preg_match('/^[=+\-@]/', $t['name']) ? "'" . $t['name'] : $t['name'];
            fputcsv($f, [$t['id'], $t['tn'], $t['visit_id'], $t['physical_number'], $name, $t['people'], $t['opened_at'], $t['closed_at'], Floor::TAB[$t['status']], $c($tt['items']), $c($tt['fee']), $c($tt['discount']), $c($tt['total']), $c($tt['confirmed'])], ';');
        }
        rewind($f); return stream_get_contents($f);
    }
}
