<?php
declare(strict_types=1);

final class App
{
    public static function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
        $routes = [
            // público
            ['GET', '#^/$#', 'home'], ['GET', '#^/historia$#', 'historyPage'], ['GET', '#^/cardapio$#', 'menuPage'], ['GET', '#^/checkout$#', 'checkoutPage'],
            ['GET', '#^/pedido/([a-f0-9]{32})$#', 'trackPage'], ['GET', '#^/robots\.txt$#', 'robots'], ['GET', '#^/sitemap\.xml$#', 'sitemap'],
            ['GET', '#^/api/menu$#', 'apiMenu'], ['POST', '#^/api/quote$#', 'apiQuote'], ['POST', '#^/api/orders$#', 'apiCreateOrder'],
            ['GET', '#^/api/pedido/([a-f0-9]{32})$#', 'apiTrack'],
            // agente de impressão (token Bearer)
            ['POST', '#^/api/print/claim$#', 'apiPrintClaim'], ['POST', '#^/api/print/([0-9a-f-]{36})/result$#', 'apiPrintResult'],
            // admin
            ['GET', '#^/(equipe|admin/login)$#', 'loginPage'], ['POST', '#^/(equipe|admin/login)$#', 'loginPost'], ['POST', '#^/equipe/sair$#', 'logout'], ['GET', '#^/equipe/recuperar$#', 'forgotPage'], ['POST', '#^/equipe/recuperar$#', 'forgotPost'], ['GET', '#^/equipe/redefinir$#', 'resetPage'], ['POST', '#^/equipe/redefinir$#', 'resetPost'], ['POST', '#^/admin/logout$#', 'logout'],
            ['GET', '#^/admin$#', 'board'], ['GET', '#^/admin/pedidos$#', 'ordersHistory'], ['GET', '#^/admin/pedido/(\d+)$#', 'orderDetail'],
            ['GET', '#^/admin/pedido/(\d+)/comanda$#', 'comanda'],
            ['GET', '#^/admin/api/orders$#', 'admApiOrders'], ['POST', '#^/admin/api/orders$#', 'admApiCreateOrder'], ['GET', '#^/admin/api/orders/(\d+)$#', 'admApiOrder'], ['POST', '#^/admin/api/orders/(\d+)/status$#', 'admApiStatus'],
            ['POST', '#^/admin/api/orders/(\d+)/address$#', 'admApiAddress'], ['POST', '#^/admin/api/orders/(\d+)/items$#', 'admApiItems'], ['POST', '#^/admin/api/orders/(\d+)/payment$#', 'admApiPayment'], ['POST', '#^/admin/api/orders/(\d+)/reprint$#', 'admApiReprint'],
            ['POST', '#^/admin/api/print/(\d+)/retry$#', 'admApiPrintRetry'],
            // garçom (salão)
            ['GET', '#^/garcom$#', 'garcomPage'], ['GET', '#^/garcom/api/state$#', 'garcomState'], ['GET', '#^/garcom/api/tab/(\d+)$#', 'garcomTab'],
            ['POST', '#^/garcom/api/open$#', 'garcomOpen'], ['POST', '#^/garcom/api/tab/(\d+)/link$#', 'garcomLink'], ['POST', '#^/garcom/api/tab/(\d+)/round$#', 'garcomRound'],
            ['POST', '#^/garcom/api/tab/(\d+)/bill$#', 'garcomBill'], ['POST', '#^/garcom/api/tab/(\d+)/payment$#', 'garcomPay'],
            ['GET', '#^/admin/salao$#', 'admFloor'], ['GET', '#^/admin/caixa$#', 'admCash'], ['GET', '#^/admin/comanda/(\d+)$#', 'admTab'], ['POST', '#^/admin/salao/acao$#', 'admFloorAction'],
            ['GET', '#^/admin/executivo$#', 'admExecutive'], ['GET', '#^/admin/executivo\.csv$#', 'admExecutiveCsv'],
            ['GET', '#^/admin/cardapio$#', 'admMenu'], ['POST', '#^/admin/cardapio/toggle$#', 'admMenuToggle'], ['POST', '#^/admin/cardapio/mover$#', 'admMove'], ['POST', '#^/admin/cardapio/status$#', 'admProductStatus'], ['POST', '#^/admin/cardapio/categoria$#', 'admCategorySave'],
            ['GET', '#^/admin/cardapio/produto/(novo|\d+)$#', 'admProductForm'], ['POST', '#^/admin/cardapio/produto/(novo|\d+)$#', 'admProductSave'],
            ['GET', '#^/admin/cardapio/grupos/(\d+)$#', 'admGroupsForm'], ['POST', '#^/admin/cardapio/grupos/(\d+)$#', 'admGroupsSave'],
            ['GET', '#^/admin/clientes$#', 'admCustomers'], ['GET', '#^/admin/relatorios$#', 'admReports'], ['GET', '#^/admin/relatorios\.csv$#', 'admReportsCsv'],
            ['GET', '#^/admin/config$#', 'admConfig'], ['POST', '#^/admin/config$#', 'admConfigSave'],
            ['GET', '#^/admin/usuarios$#', 'admUsers'], ['POST', '#^/admin/usuarios$#', 'admUsersSave'], ['GET', '#^/admin/acessos$#', 'admAccess'],
            ['GET', '#^/admin/impressao$#', 'admPrint'], ['POST', '#^/admin/impressao/token$#', 'admPrintToken'],
        ];
        foreach ($routes as [$m, $re, $fn]) {
            if ($m === $method && preg_match($re, $path, $mm)) {
                if ($method === 'POST' && (str_starts_with($path, '/admin') || str_starts_with($path, '/garcom') || $path === '/equipe/sair') && $path !== '/admin/login') Auth::checkCsrf();
                self::$fn(...array_slice($mm, in_array($fn, ['loginPage', 'loginPost'], true) ? 2 : 1));
                return;
            }
        }
        throw new HttpError(404, 'Página não encontrada.');
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '', true);
        if (!is_array($j)) throw new HttpError(400, 'Requisição inválida.');
        return $j;
    }
    private static function pub(string $view, array $d = []): void
    {
        $d += ['store' => Settings::all(), 'status' => Settings::storeStatus()];
        View::render($view, $d);
    }
    private static function adm(string $view, array $d = [], string $perm = 'orders'): void
    {
        $u = Auth::require($perm);
        View::render($view, $d + ['user' => $u, 'csrf' => Auth::csrf(), 'activeNav' => $d['activeNav'] ?? $view,
            'agent' => Printing::agentState(), 'storeNow' => Settings::storeStatus(), 'newCount' => (int)Db::val("SELECT COUNT(*) FROM orders WHERE status = 'received'")], 'admin/layout');
    }
    private static function flash(string $msg, bool $error = false): void { Auth::start(); $_SESSION[$error ? 'flash_err' : 'flash'] = $msg; }

    // ================= público =================
    private static function home(): void
    {
        // blocos de categoria vêm do cardápio oficial (banco): imagem = primeiro produto da categoria que tem foto
        $tiles = [];
        foreach (Db::all('SELECT id, name, is_pizza FROM categories WHERE active = 1 ORDER BY sort, id') as $c) {
            $img = Db::val("SELECT image FROM products WHERE category_id = ? AND image IS NOT NULL AND image <> '' ORDER BY sort, id LIMIT 1", [$c['id']]);
            $tiles[] = ['id' => $c['id'], 'name' => $c['name'], 'image' => $img ?: null, 'big' => (bool)$c['is_pizza']];
        }
        self::pub('home', ['tiles' => $tiles, 'title' => 'Pizzaria Bianca — Desde 1977 em Contagem/MG', 'description' => 'Pizzaria Bianca, tradição em Contagem desde 1977. Veja nossa história e peça pelo cardápio digital.']);
    }
    private static function historyPage(): void
    {
        self::pub('historia', ['title' => 'Nossa história — Pizzaria Bianca', 'description' => 'Desde 1977 em Contagem: os sócios, os endereços, a tele-entrega de 1995 e a trajetória da Pizzaria Bianca.']);
    }
    private static function menuPage(): void { self::pub('menu', ['title' => 'Cardápio — Pizzaria Bianca', 'description' => 'Pizzas, pratos, massas e bebidas da Pizzaria Bianca. Monte seu pedido online.']); }
    private static function checkoutPage(): void
    {
        $zones = Db::all('SELECT id, name, fee_cents, min_order_cents FROM delivery_zones WHERE active = 1 ORDER BY name');
        $pays = array_filter(Settings::json('payment_methods'), fn($p) => !empty($p['on']));
        self::pub('checkout', ['title' => 'Finalizar pedido — Pizzaria Bianca', 'zones' => $zones, 'pays' => $pays, 'noindex' => true]);
    }
    private static function trackPage(string $token): void
    {
        if (!Orders::byToken($token)) throw new HttpError(404, 'Pedido não encontrado.');
        self::pub('track', ['title' => 'Acompanhar pedido — Pizzaria Bianca', 'token' => $token, 'noindex' => true]);
    }
    private static function robots(): void
    {
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /pedido/\nSitemap: " . url('/sitemap.xml') . "\n";
    }
    private static function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . e(url('/')) . '</loc></url><url><loc>' . e(url('/historia')) . '</loc></url><url><loc>' . e(url('/cardapio')) . '</loc></url></urlset>';
    }
    private static function apiMenu(): void { json_out(Pricing::menu() + ['status' => Settings::storeStatus()]); }
    private static function apiQuote(): void
    {
        $b = self::body();
        self::rate('quote', 120, 600);
        $q = Pricing::quote($b['items'] ?? []);
        $fee = 0; $min = 0; $zone = null;
        if (($b['type'] ?? '') === 'delivery' && !empty($b['zone_id'])) {
            $zone = Db::one('SELECT * FROM delivery_zones WHERE id = ? AND active = 1', [(int)$b['zone_id']]);
            if ($zone) { $fee = (int)$zone['fee_cents']; $min = (int)$zone['min_order_cents']; }
        }
        json_out(['lines' => $q['lines'], 'subtotal_cents' => $q['subtotal'], 'delivery_fee_cents' => $fee, 'min_order_cents' => $min, 'total_cents' => $q['subtotal'] + $fee]);
    }
    private static function rate(string $bucket, int $max, int $seconds): void
    {
        $b = $bucket . ':' . client_ip();
        Db::exec('DELETE FROM rate_limits WHERE at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)Db::val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND at >= ?', [$b, date('Y-m-d H:i:s', time() - $seconds)]) >= $max) throw new HttpError(429, 'Muitas requisições. Aguarde um pouco.');
        Db::insert('rate_limits', ['bucket' => $b, 'at' => now()]);
    }
    private static function apiCreateOrder(): void
    {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        $b = self::body();
        // repetições da mesma chave são devolvidas sem consumir limite
        if (!Db::val('SELECT 1 FROM orders WHERE idempotency_key = ?', [$key])) self::rate('order', 10, 600);
        $r = Orders::create($b, $key);
        json_out(['token' => $r['token'], 'id' => $r['id'], 'duplicate' => $r['duplicate'], 'status' => 'received', 'message' => 'Pedido recebido! Aguarde a confirmação da loja.'], $r['duplicate'] ? 200 : 201);
    }
    private static function apiTrack(string $token): void
    {
        $o = Orders::byToken($token);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        json_out(Orders::publicView($o));
    }

    // ================= agente de impressão =================
    private static function apiPrintClaim(): void { Printing::authAgent(); json_out(['job' => Printing::claim()]); }
    private static function apiPrintResult(string $uuid): void
    {
        Printing::authAgent();
        $b = self::body();
        json_out(Printing::result($uuid, !empty($b['ok']), !empty($b['sent']), (string)($b['error'] ?? '')));
    }

    // ================= admin: sessão =================
    private static function loginPage(): void
    {
        if (Auth::user()) redirect(Auth::home());
        View::render('admin/login', ['error' => null, 'title' => 'Área da equipe — Bianca'], 'admin/bare');
    }
    private static function loginPost(): void
    {
        Auth::start();
        $err = Auth::login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($err) { usleep(300000); http_response_code(401); View::render('admin/login', ['error' => $err, 'title' => 'Área da equipe — Bianca'], 'admin/bare'); return; }
        redirect(Auth::home()); // destino decidido pelo cadastro no servidor
    }
    private static function logout(): void { Auth::logout(); redirect('/equipe'); }

    // ---- recuperação de senha (e-mail cadastrado; nunca altera cargo nem reativa conta) ----
    private static function forgotPage(): void { header('Referrer-Policy: no-referrer'); View::render('admin/forgot', ['sent' => false, 'title' => 'Recuperar senha — Bianca'], 'admin/bare'); }
    private static function forgotPost(): void
    {
        PasswordReset::request((string)($_POST['email'] ?? ''), client_ip());
        usleep(300000);
        View::render('admin/forgot', ['sent' => true, 'title' => 'Recuperar senha — Bianca'], 'admin/bare'); // mesma resposta para qualquer e-mail
    }
    private static function resetPage(): void
    {
        header('Referrer-Policy: no-referrer');
        $t = (string)($_GET['token'] ?? '');
        View::render('admin/reset', ['token' => $t, 'ok' => PasswordReset::valid($t) !== null, 'error' => null, 'done' => false, 'title' => 'Nova senha — Bianca'], 'admin/bare');
    }
    private static function resetPost(): void
    {
        $t = (string)($_POST['token'] ?? ''); $err = null;
        if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) $err = 'As senhas não conferem.';
        else { try { PasswordReset::reset($t, (string)($_POST['password'] ?? '')); View::render('admin/reset', ['token' => '', 'ok' => true, 'error' => null, 'done' => true, 'title' => 'Senha redefinida — Bianca'], 'admin/bare'); return; } catch (HttpError $e) { $err = $e->getMessage(); } }
        http_response_code(422);
        View::render('admin/reset', ['token' => $t, 'ok' => PasswordReset::valid($t) !== null, 'error' => $err, 'done' => false, 'title' => 'Nova senha — Bianca'], 'admin/bare');
    }

    // ================= visão executiva (proprietário) =================
    private static function admExecutive(): void
    {
        Auth::require('executive');
        [$from, $to] = self::range();
        self::adm('admin/executivo', ['title' => 'Visão executiva', 'from' => $from, 'to' => $to, 'x' => Reports::executive($from, $to), 'activeNav' => 'executive'], 'executive');
    }
    private static function admExecutiveCsv(): void
    {
        Auth::require('executive');
        [$from, $to] = self::range();
        Auth::audit('report.csv.salao', "$from a $to");
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=comandas-salao-$from-a-$to.csv");
        echo Reports::csvTabs($from, $to);
    }

    // ================= salão e caixa (painel) =================
    /** Telas administrativas do salão são da gerência/caixa. Garçom (só 'floor') usa /garcom e não vê o painel. */
    private static function requireFloorAdmin(): void
    {
        Auth::require('floor');
        if (!Auth::can('orders') && !Auth::can('cashier')) throw new HttpError(403, 'Esta tela é da gerência. Use a área do garçom.');
    }
    private static function admFloor(): void
    {
        self::requireFloorAdmin();
        $mesa = (int)($_GET['mesa'] ?? 0);
        $history = [];
        if ($mesa) {
            foreach (Db::all('SELECT * FROM visits WHERE table_id = ? ORDER BY id DESC LIMIT 40', [$mesa]) as $v) {
                $v['tabs'] = Db::all('SELECT id, physical_number, name, people, status FROM tabs WHERE visit_id = ? ORDER BY id', [$v['id']]);
                foreach ($v['tabs'] as &$t) $t['total'] = Floor::totals((int)$t['id'])['total'];
                $history[] = $v;
            }
        }
        self::adm('admin/salao', ['title' => 'Salão', 'tables' => Floor::tables(), 'mesa' => $mesa, 'history' => $history, 'tableRow' => $mesa ? Db::one('SELECT * FROM dining_tables WHERE id = ?', [$mesa]) : null, 'activeNav' => 'floor'], 'floor');
    }
    private static function admCash(): void
    {
        Auth::require('cashier');
        $sid = Cash::currentId();
        self::adm('admin/caixa', [
            'title' => 'Caixa', 'sid' => $sid, 'sum' => $sid ? Cash::summary($sid) : null,
            'reported' => Db::all("SELECT p.*, t.name AS tab_name, t.physical_number, tb.number AS table_number FROM tab_payments p JOIN tabs t ON t.id = p.tab_id JOIN dining_tables tb ON tb.id = t.table_id WHERE p.status = 'reported' ORDER BY p.id"),
            'awaiting' => array_values(array_filter(Floor::openTabs(), fn($t) => $t['status'] === 'awaiting_cashier')), 'open' => array_values(array_filter(Floor::openTabs(), fn($t) => $t['status'] === 'open')),
            'shifts' => Db::all('SELECT * FROM cash_shifts WHERE closed_at IS NOT NULL ORDER BY id DESC LIMIT 10'),
            'pays' => array_filter(Settings::json('payment_methods'), fn($p) => !empty($p['on'])), 'activeNav' => 'cash'], 'cashier');
    }
    private static function admTab(string $id): void
    {
        self::requireFloorAdmin();
        $t = Floor::tabDetail((int)$id);
        if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
        self::adm('admin/tab', ['title' => 'Comanda ' . ($t['physical_number'] ?: '#' . $t['id']), 't' => $t, 'pays' => array_filter(Settings::json('payment_methods'), fn($p) => !empty($p['on'])), 'activeNav' => 'floor'], 'floor');
    }
    /** Todas as ações do caixa/gerente passam aqui; cada método de Floor/Cash confere a permissão de novo. */
    private static function admFloorAction(): void
    {
        self::requireFloorAdmin();
        $u = Auth::require('floor');
        $p = $_POST; $back = (string)($p['back'] ?? '/admin/caixa');
        if (!str_starts_with($back, '/admin/') || str_contains($back, '//')) $back = '/admin/caixa';
        $cents = fn(string $k) => parse_money((string)($p[$k] ?? '0'));
        try {
            switch ($p['action'] ?? '') {
                case 'confirm_payment': Floor::confirmPayment((int)$p['id'], $u); $m = 'Recebimento confirmado.'; break;
                case 'reject_payment': Floor::rejectPayment((int)$p['id'], (string)($p['reason'] ?? ''), $u); $m = 'Recebimento recusado.'; break;
                case 'reverse_payment': Floor::reversePayment((int)$p['id'], (string)($p['reason'] ?? ''), $u); $m = 'Estorno registrado no sistema (não indica devolução efetiva ao cliente).'; break;
                case 'register_payment': Floor::registerPayment((int)$p['tab_id'], $cents('amount'), (string)($p['method'] ?? ''), $u, (string)($p['key'] ?? '')); $m = 'Recebimento registrado e confirmado.'; break;
                case 'discount': Floor::applyDiscount((int)$p['tab_id'], $cents('amount'), (string)($p['reason'] ?? ''), $u); $m = 'Desconto aplicado.'; break;
                case 'fee': Floor::setServiceFee((int)$p['tab_id'], !empty($p['remove']), (string)($p['reason'] ?? ''), $u); $m = 'Taxa de serviço atualizada.'; break;
                case 'close_tab': Floor::closeTab((int)$p['tab_id'], $u); $m = 'Comanda fechada.'; break;
                case 'reopen_tab': Floor::reopenTab((int)$p['tab_id'], (string)($p['reason'] ?? ''), $u); $m = 'Comanda reaberta.'; break;
                case 'request_bill': Floor::requestBill((int)$p['tab_id'], $u); $m = 'Conta solicitada.'; break;
                case 'shift_open': Cash::open($cents('amount'), $u); $m = 'Turno aberto.'; break;
                case 'shift_move': Cash::move((string)($p['kind'] ?? ''), $cents('amount'), (string)($p['reason'] ?? ''), $u); $m = 'Movimentação registrada.'; break;
                case 'shift_close': $r = Cash::close($cents('counted'), (string)($p['note'] ?? ''), $u); $m = 'Turno fechado. Esperado ' . money($r['expected']) . ', contado ' . money($r['counted']) . ', diferença ' . money($r['diff']) . '.'; break;
                default: throw new HttpError(400, 'Ação inválida.');
            }
            self::flash($m);
        } catch (HttpError $e) { self::flash($e->getMessage(), true); }
        redirect($back);
    }

    // ================= garçom / salão =================
    private static function garcomPage(): void
    {
        $u = Auth::require('floor');
        View::render('garcom/app', ['title' => 'Salão — Bianca', 'user' => $u, 'csrf' => Auth::csrf(), 'canReport' => Auth::can('report_payment'), 'admin' => Auth::can('orders')], 'garcom/layout');
    }
    private static function garcomState(): void
    {
        $u = Auth::require('floor', true);
        $pays = array_map(fn($p) => $p['label'], array_filter(Settings::json('payment_methods'), fn($p) => !empty($p['on'])));
        json_out(['now' => now(), 'tables' => Floor::tables(), 'tabs' => Floor::openTabs(), 'payments' => $pays, 'independent' => Settings::get('allow_independent_visits') === '1',
            'fee_percent' => Settings::get('service_fee_percent'), 'me' => ['name' => $u['name'], 'can_report' => in_array('report_payment', Auth::permsOf($u), true)]]);
    }
    private static function garcomTab(string $id): void
    {
        Auth::require('floor', true);
        $t = Floor::tabDetail((int)$id);
        if (!$t) throw new HttpError(404, 'Comanda não encontrada.');
        json_out(['tab' => $t]);
    }
    private static function garcomOpen(): void
    {
        $u = Auth::require('floor', true);
        $r = Floor::open(self::body(), $u, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        json_out($r, $r['duplicate'] ? 200 : 201);
    }
    private static function garcomLink(string $id): void
    {
        $u = Auth::require('floor', true);
        Floor::linkPhysical((int)$id, (string)(self::body()['number'] ?? ''), $u);
        json_out(['ok' => true]);
    }
    private static function garcomRound(string $id): void
    {
        $u = Auth::require('floor', true);
        $b = self::body();
        $r = Floor::sendRound((int)$id, (array)($b['items'] ?? []), $u, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', (string)($b['notes'] ?? ''));
        json_out($r, $r['duplicate'] ? 200 : 201);
    }
    private static function garcomBill(string $id): void
    {
        $u = Auth::require('floor', true);
        Floor::requestBill((int)$id, $u);
        json_out(['ok' => true]);
    }
    private static function garcomPay(string $id): void
    {
        $u = Auth::require('floor', true);
        $b = self::body();
        $r = Floor::reportPayment((int)$id, (int)($b['amount_cents'] ?? 0), (string)($b['method'] ?? ''), $u, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', (string)($b['note'] ?? ''));
        json_out($r, $r['duplicate'] ? 200 : 201);
    }

    // ================= admin: pedidos =================
    private static function board(): void
    {
        $zones = Db::all('SELECT id, name, fee_cents FROM delivery_zones WHERE active = 1 ORDER BY name');
        $pays = array_filter(Settings::json('payment_methods'), fn($p) => !empty($p['on']));
        self::adm('admin/board', ['title' => 'Pedidos', 'activeNav' => 'board', 'zones' => $zones, 'pays' => $pays]);
    }
    private static function ordersHistory(): void
    {
        $f = ['from' => $_GET['from'] ?? date('Y-m-d', strtotime('-6 days')), 'to' => $_GET['to'] ?? date('Y-m-d'), 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''];
        self::adm('admin/history', ['title' => 'Histórico de pedidos', 'f' => $f, 'orders' => Orders::list($f, 300), 'activeNav' => 'history']);
    }
    private static function orderDetail(string $id): void
    {
        $o = Orders::get((int)$id);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        $jobs = Db::all('SELECT * FROM print_jobs WHERE order_id = ? ORDER BY id DESC', [$o['id']]);
        self::adm('admin/order', ['title' => 'Pedido #' . $o['id'], 'o' => $o, 'jobs' => $jobs, 'activeNav' => 'history']);
    }
    private static function comanda(string $id): void
    {
        Auth::require('print');
        $o = Orders::get((int)$id);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        $sector = $_GET['sector'] ?? 'balcao';
        $paper = ($_GET['paper'] ?? '80') === '58' ? 58 : 80;
        $lines = Printing::lines($o, ['paper' => $paper, 'sector' => $sector, 'kind' => !empty($_GET['reprint']) ? 'reprint' : 'original']);
        Auth::audit('print.browser', "pedido #{$o['id']} $sector");
        View::render('admin/comanda', ['lines' => $lines, 'paper' => $paper, 'title' => 'Comanda #' . $o['id']], 'admin/bare');
    }
    private static function admApiOrders(): void
    {
        Auth::require('orders', true);
        $orders = Orders::list(['active' => true], 150);
        // concluídos/cancelados de hoje, para a coluna final
        $done = array_values(array_filter(Orders::list(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], 100), fn($o) => in_array($o['status'], ['completed', 'cancelled'], true)));
        json_out(['now' => now(), 'orders' => self::withJobs(array_merge($orders, $done)), 'store' => Settings::storeStatus(), 'agent' => Printing::agentState()]);
    }
    /** Pedido de balcão/telefone cadastrado pelo operador. Mesmas regras de preço do pedido público; ignora horário/pausa da loja. */
    private static function admApiCreateOrder(): void
    {
        $u = Auth::require('orders', true);
        $b = self::body();
        $r = Orders::create($b, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', $u);
        if (!$r['duplicate']) Auth::audit('order.manual', "pedido #{$r['id']}");
        json_out(['id' => $r['id'], 'duplicate' => $r['duplicate']], $r['duplicate'] ? 200 : 201);
    }
    /** Detalhe de um pedido fora da lista carregada (ex.: link direto/histórico). */
    private static function admApiOrder(string $id): void
    {
        Auth::require('orders', true);
        $o = Orders::get((int)$id);
        if (!$o) throw new HttpError(404, 'Pedido não encontrado.');
        json_out(['order' => self::withJobs([$o])[0]]);
    }
    /** Anexa os trabalhos de impressão de cada pedido e remove a chave de idempotência. */
    private static function withJobs(array $orders): array
    {
        $ids = array_map(fn($o) => (int)$o['id'], $orders);
        $by = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT id, order_id, sector, kind, status, attempts, last_error, created_by, created_at FROM print_jobs WHERE order_id IN ($ph) ORDER BY id", $ids) as $j) $by[$j['order_id']][] = $j;
        }
        $cust = array_values(array_unique(array_map(fn($o) => (int)$o['customer_id'], $orders))); $cnt = [];
        if ($cust) { $ph = implode(',', array_fill(0, count($cust), '?')); foreach (Db::all("SELECT customer_id, COUNT(*) AS n FROM orders WHERE customer_id IN ($ph) GROUP BY customer_id", $cust) as $r) $cnt[$r['customer_id']] = (int)$r['n']; }
        return array_map(function ($o) use ($by, $cnt) { unset($o['idempotency_key']); $o['jobs'] = $by[$o['id']] ?? []; $o['customer_orders'] = $cnt[$o['customer_id']] ?? 1; $o['tab_physical'] = $o['tab_id'] ? Db::val('SELECT physical_number FROM tabs WHERE id = ?', [$o['tab_id']]) : null; return $o; }, $orders);
    }
    private static function admApiStatus(string $id): void
    {
        $u = Auth::require('orders', true);
        $b = self::body();
        $o = Orders::transition((int)$id, (string)($b['to'] ?? ''), (int)($b['version'] ?? 0), $u, $b['reason'] ?? null, isset($b['estimate']) ? (int)$b['estimate'] : null);
        json_out(['order' => $o]);
    }
    private static function admApiItems(string $id): void
    {
        $u = Auth::require('orders', true);
        $b = self::body();
        json_out(['order' => self::withJobs([Orders::editItems((int)$id, (array)($b['ops'] ?? []), (int)($b['version'] ?? 0), $u)])[0]]);
    }
    private static function admApiAddress(string $id): void
    {
        $u = Auth::require('orders', true);
        $b = self::body();
        json_out(['order' => self::withJobs([Orders::updateAddress((int)$id, $b, (int)($b['version'] ?? 0), $u)])[0]]);
    }
    private static function admApiPayment(string $id): void
    {
        $u = Auth::require('orders', true);
        $b = self::body();
        json_out(['order' => Orders::setPayment((int)$id, (string)($b['to'] ?? ''), (int)($b['version'] ?? 0), $u)]);
    }
    private static function admApiReprint(string $id): void
    {
        $u = Auth::require('print', true);
        $b = self::body();
        $sector = isset($b['sector']) && $b['sector'] !== '' ? (string)$b['sector'] : null;
        json_out(['jobs' => Printing::reprint((int)$id, $sector, $u)]);
    }
    private static function admApiPrintRetry(string $jobId): void
    {
        $u = Auth::require('print', true);
        Printing::retry((int)$jobId, $u);
        json_out(['ok' => true]);
    }

    // ================= admin: cardápio =================
    private static function admMenu(): void
    {
        $aba = in_array($_GET['aba'] ?? '', ['produtos', 'complementos', 'opcoes'], true) ? $_GET['aba'] : 'produtos';
        $cats = Db::all('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY COALESCE(c.parent_id, c.id), (c.parent_id IS NOT NULL), c.sort, c.id');
        $selId = (int)($_GET['cat'] ?? 0);
        $sel = null; foreach ($cats as $c) if ((int)$c['id'] === $selId) $sel = $c;
        if (!$sel && $aba !== 'opcoes') { foreach ($cats as $c) if (!$c['parent_id']) { $sel = $c; break; } }
        $prods = $sel ? Db::all('SELECT p.*, (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS n_sizes, (SELECT MIN(price_cents) FROM product_sizes s WHERE s.product_id = p.id AND s.available = 1) AS min_size FROM products p WHERE p.category_id = ? ORDER BY p.sort, p.id', [$sel['id']]) : [];
        $sizesUsed = $sel ? array_column(Db::all('SELECT DISTINCT s.name FROM product_sizes s JOIN products p ON p.id = s.product_id WHERE p.category_id = ? ORDER BY s.sort', [$sel['id']]), 'name') : [];
        $groups = [];
        if ($sel) foreach (Db::all('SELECT * FROM option_groups WHERE category_id = ? ORDER BY sort, id', [$sel['id']]) as $g) { $g['options'] = Db::all('SELECT * FROM options WHERE group_id = ? ORDER BY sort, id', [$g['id']]); $groups[] = $g; }
        self::adm('admin/menu', ['title' => 'Catálogo', 'aba' => $aba, 'cats' => $cats, 'sel' => $sel, 'prods' => $prods, 'sizesUsed' => $sizesUsed, 'groups' => $groups, 'newCat' => $aba === 'opcoes' && !empty($_GET['nova']), 'activeNav' => 'menu'], 'menu');
    }
    /** Sobe/desce uma categoria ou produto dentro do seu grupo (renumera a ordem, sem lacunas). */
    private static function admMove(): void
    {
        Auth::require('menu');
        $id = (int)($_POST['id'] ?? 0); $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        if (($_POST['kind'] ?? '') === 'category') { $row = Db::one('SELECT id, parent_id FROM categories WHERE id = ?', [$id]); $sib = $row ? Db::all('SELECT id FROM categories WHERE ' . ($row['parent_id'] ? 'parent_id = ?' : 'parent_id IS NULL AND ? IS NOT NULL') . ' ORDER BY sort, id', [$row['parent_id'] ?: 1]) : []; $t = 'categories'; }
        else { $row = Db::one('SELECT id, category_id FROM products WHERE id = ?', [$id]); $sib = $row ? Db::all('SELECT id FROM products WHERE category_id = ? ORDER BY sort, id', [$row['category_id']]) : []; $t = 'products'; }
        $ids = array_map('intval', array_column($sib, 'id')); $i = array_search($id, $ids, true);
        if ($i !== false && isset($ids[$i + $dir])) { [$ids[$i], $ids[$i + $dir]] = [$ids[$i + $dir], $ids[$i]]; foreach ($ids as $k => $x) Db::exec("UPDATE $t SET sort = ? WHERE id = ?", [$k + 1, $x]); Auth::audit('menu.move', "$t #$id $dir"); }
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/cardapio');
    }
    /** Status do produto: Ativo (vende) · Em falta (aparece, não vende) · Oculto (não aparece nem vende). */
    private static function admProductStatus(): void
    {
        Auth::require('menu');
        $id = (int)($_POST['id'] ?? 0); $st = $_POST['status'] ?? '';
        if (!in_array($st, ['active', 'out', 'hidden'], true) || !Db::val('SELECT 1 FROM products WHERE id = ?', [$id])) throw new HttpError(400, 'Status inválido.');
        Db::exec('UPDATE products SET available = ?, hidden = ? WHERE id = ?', [$st === 'out' ? 0 : 1, $st === 'hidden' ? 1 : 0, $id]);
        Auth::audit('menu.status', "produto #$id => $st");
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/cardapio');
    }
    private static function admMenuToggle(): void
    {
        Auth::require('menu');
        $id = (int)($_POST['id'] ?? 0); $on = !empty($_POST['on']) ? 1 : 0;
        $map = ['product' => 'products', 'category' => 'categories', 'size' => 'product_sizes', 'option' => 'options'];
        $t = $map[$_POST['kind'] ?? ''] ?? null;
        if (!$t) throw new HttpError(400, 'Tipo inválido.');
        Db::exec("UPDATE $t SET " . ($t === 'categories' ? 'active' : 'available') . ' = ? WHERE id = ?', [$on, $id]);
        Auth::audit('menu.toggle', "{$_POST['kind']} #$id => $on");
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/cardapio');
    }
    private static function admCategorySave(): void
    {
        Auth::require('menu');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') throw new HttpError(422, 'Informe o nome da categoria.');
        $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
        if ($parent && ($parent === $id || Db::val('SELECT parent_id FROM categories WHERE id = ?', [$parent]) || !Db::val('SELECT 1 FROM categories WHERE id = ?', [$parent]))) throw new HttpError(422, 'Subcategoria inválida (só um nível, e não pode ser filha de si mesma).');
        if ($parent && $id && Db::val('SELECT 1 FROM categories WHERE parent_id = ?', [$id])) throw new HttpError(422, 'Esta categoria já tem subcategorias; não pode virar subcategoria.');
        $mf = (int)($_POST['max_flavors'] ?? 0);
        $row = ['name' => mb_substr($name, 0, 120), 'parent_id' => $parent, 'active' => !empty($_POST['active']) ? 1 : 0, 'is_pizza' => !empty($_POST['is_pizza']) ? 1 : 0,
            'max_flavors' => $mf >= 1 && $mf <= 4 ? $mf : null, 'allow_removals' => !empty($_POST['allow_removals']) ? 1 : 0, 'sector' => ($_POST['sector'] ?? '') === 'balcao' ? 'balcao' : 'cozinha'];
        if ($id) Db::update('categories', $id, $row); else { $row['sort'] = 1 + (int)Db::val('SELECT COALESCE(MAX(sort),0) FROM categories'); $id = Db::insert('categories', $row); }
        Auth::audit('menu.category', "#$id $name");
        self::flash('Categoria salva.');
        redirect("/admin/cardapio?cat=$id&aba=opcoes");
    }    private static function admProductForm(string $id): void
    {
        $p = $id === 'novo' ? ['id' => 0, 'category_id' => (int)($_GET['cat'] ?? 0), 'name' => '', 'description' => '', 'base_price_cents' => 0, 'available' => 1, 'hidden' => 0, 'badge' => '', 'sort' => 0, 'image' => null]
                            : Db::one('SELECT * FROM products WHERE id = ?', [(int)$id]);
        if (!$p) throw new HttpError(404, 'Produto não encontrado.');
        $ings = $p['id'] ? Db::all('SELECT * FROM product_ingredients WHERE product_id = ? ORDER BY sort, id', [$p['id']]) : [];
        self::adm('admin/product', ['title' => $p['id'] ? 'Editar produto' : 'Novo produto', 'p' => $p, 'cats' => Db::all('SELECT * FROM categories ORDER BY COALESCE(parent_id, id), (parent_id IS NOT NULL), sort'),
            'sizes' => $p['id'] ? Db::all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort, id', [$p['id']]) : [], 'ings' => $ings,
            'obs' => $p['id'] ? Db::all("SELECT * FROM channel_observations WHERE product_id = ? AND kind = 'price' ORDER BY id", [$p['id']]) : [], 'activeNav' => 'menu'], 'menu');
    }
    private static function admProductSave(string $id): void
    {
        Auth::require('menu');
        $name = trim((string)($_POST['name'] ?? ''));
        $cat = (int)($_POST['category_id'] ?? 0);
        if ($name === '' || !Db::val('SELECT 1 FROM categories WHERE id = ?', [$cat])) throw new HttpError(422, 'Informe nome e categoria.');
        $st = $_POST['status'] ?? 'active';
        $row = ['category_id' => $cat, 'name' => mb_substr($name, 0, 160), 'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 1000) ?: null,
            'base_price_cents' => parse_money((string)($_POST['price'] ?? '0')), 'available' => $st === 'out' ? 0 : 1, 'hidden' => $st === 'hidden' ? 1 : 0,
            'badge' => mb_substr(trim((string)($_POST['badge'] ?? '')), 0, 80) ?: null, 'sort' => (int)($_POST['sort'] ?? 0)];
        if ($img = self::uploadImage('image')) $row['image'] = $img;
        if ($id === 'novo') { $row['sort'] = 1 + (int)Db::val('SELECT COALESCE(MAX(sort),0) FROM products WHERE category_id = ?', [$cat]); $pid = Db::insert('products', $row); }
        else { $pid = (int)$id; Db::update('products', $pid, $row); }
        // tamanhos: linhas existentes (id) são atualizadas, ausentes removidas, novas inseridas
        $keep = [];
        foreach (($_POST['size_name'] ?? []) as $i => $sn) {
            $sn = trim((string)$sn);
            if ($sn === '') continue;
            $srow = ['product_id' => $pid, 'name' => mb_substr($sn, 0, 60), 'detail' => mb_substr(trim((string)($_POST['size_detail'][$i] ?? '')), 0, 120) ?: null,
                'price_cents' => parse_money((string)($_POST['size_price'][$i] ?? '0')), 'available' => !empty($_POST['size_on'][$i]) ? 1 : 0, 'sort' => (int)$i];
            $sid = (int)($_POST['size_id'][$i] ?? 0);
            if ($sid && Db::val('SELECT 1 FROM product_sizes WHERE id = ? AND product_id = ?', [$sid, $pid])) Db::update('product_sizes', $sid, $srow); else $sid = Db::insert('product_sizes', $srow);
            $keep[] = $sid;
        }
        $ph = $keep ? implode(',', array_fill(0, count($keep), '?')) : '0';
        Db::exec("DELETE FROM product_sizes WHERE product_id = ? AND id NOT IN ($ph)", [$pid, ...$keep]);
        // ingredientes: uma linha por ingrediente; sufixo " ; fixo" = não pode ser retirado
        $seen = []; $k = 0;
        foreach (preg_split('/\R/', (string)($_POST['ingredients'] ?? '')) as $line) {
            $parts = array_map('trim', explode(';', $line)); if ($parts[0] === '') continue;
            $iname = mb_substr($parts[0], 0, 80); $rem = !in_array('fixo', array_map('mb_strtolower', array_slice($parts, 1)), true) ? 1 : 0;
            $iid = (int)Db::val('SELECT id FROM product_ingredients WHERE product_id = ? AND name = ?', [$pid, $iname]);
            if ($iid) Db::update('product_ingredients', $iid, ['removable' => $rem, 'sort' => ++$k]); else $iid = Db::insert('product_ingredients', ['product_id' => $pid, 'name' => $iname, 'removable' => $rem, 'sort' => ++$k]);
            $seen[] = $iid;
        }
        $ph = $seen ? implode(',', array_fill(0, count($seen), '?')) : '0';
        Db::exec("DELETE FROM product_ingredients WHERE product_id = ? AND id NOT IN ($ph)", [$pid, ...$seen]);
        Auth::audit('menu.product', "#$pid $name [$st]");
        redirect('/admin/cardapio?cat=' . $cat);
    }
    /** Valida e re-codifica a imagem (descarta metadados/payloads). Retorna caminho público ou null. */
    private static function uploadImage(string $field): ?string
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 4 * 1024 * 1024) throw new HttpError(422, 'Imagem inválida ou maior que 4 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $im = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']), 'image/png' => @imagecreatefrompng($f['tmp_name']), 'image/webp' => @imagecreatefromwebp($f['tmp_name']), default => false };
        if (!$im) throw new HttpError(422, 'Use imagem JPG, PNG ou WebP.');
        if (imagesx($im) > 1200) $im = imagescale($im, 1200, -1);
        $dir = APP_ROOT . '/public/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($im, "$dir/$name", 82);
        return "/uploads/$name";
    }
    private static function admGroupsForm(string $catId): void
    {
        $c = Db::one('SELECT * FROM categories WHERE id = ?', [(int)$catId]);
        if (!$c) throw new HttpError(404, 'Categoria não encontrada.');
        $groups = Db::all('SELECT * FROM option_groups WHERE category_id = ? ORDER BY sort, id', [$c['id']]);
        foreach ($groups as &$g) {
            $lines = [];
            foreach (Db::all('SELECT * FROM options WHERE group_id = ? ORDER BY sort, id', [$g['id']]) as $o) {
                $sp = array_map(fn($r) => $r['size_name'] . '=' . number_format($r['price_cents'] / 100, 2, ',', ''), Db::all('SELECT * FROM option_size_prices WHERE option_id = ?', [$o['id']]));
                $lines[] = $o['name'] . ' ; ' . number_format($o['price_cents'] / 100, 2, ',', '') . ($sp ? ' ; ' . implode(', ', $sp) : '') . ($o['available'] ? '' : ' ; INDISPONIVEL') . ($o['size_restricted'] ? ' ; SO_TAMANHOS' : '') . ($o['description'] ? ' ; desc=' . $o['description'] : '');
            }
            $g['text'] = implode("\n", $lines);
        }
        self::adm('admin/groups', ['title' => 'Adicionais — ' . $c['name'], 'c' => $c, 'groups' => $groups, 'activeNav' => 'menu'], 'menu');
    }
    private static function admGroupsSave(string $catId): void
    {
        Auth::require('menu');
        $cid = (int)$catId;
        if (!Db::val('SELECT 1 FROM categories WHERE id = ?', [$cid])) throw new HttpError(404, 'Categoria não encontrada.');
        Db::tx(function () use ($cid) {
            $keepG = [];
            foreach (($_POST['g_name'] ?? []) as $i => $gn) {
                $gn = trim((string)$gn);
                if ($gn === '') continue;
                $min = max(0, (int)($_POST['g_min'][$i] ?? 0)); $max = max($min, max(1, (int)($_POST['g_max'][$i] ?? 1)));
                $grow = ['category_id' => $cid, 'name' => mb_substr($gn, 0, 120), 'min_sel' => $min, 'max_sel' => $max, 'sort' => (int)$i];
                $gid = (int)($_POST['g_id'][$i] ?? 0);
                if ($gid && Db::val('SELECT 1 FROM option_groups WHERE id = ? AND category_id = ?', [$gid, $cid])) Db::update('option_groups', $gid, $grow); else $gid = Db::insert('option_groups', $grow);
                $keepG[] = $gid;
                $keepO = [];
                foreach (preg_split('/\R/', (string)($_POST['g_opts'][$i] ?? '')) as $k => $line) {
                    $parts = array_map('trim', explode(';', $line));
                    if ($parts[0] === '') continue;
                    $up = array_map('strtoupper', $parts);
                    $avail = !in_array('INDISPONIVEL', $up, true);
                    $desc = ''; foreach ($parts as $x) if (stripos($x, 'desc=') === 0) $desc = mb_substr(trim(substr($x, 5)), 0, 200);
                    $orow = ['group_id' => $gid, 'name' => mb_substr($parts[0], 0, 120), 'price_cents' => parse_money($parts[1] ?? '0'), 'available' => $avail ? 1 : 0, 'size_restricted' => in_array('SO_TAMANHOS', $up, true) ? 1 : 0, 'description' => $desc ?: null, 'sort' => $k];
                    $oid = (int)Db::val('SELECT id FROM options WHERE group_id = ? AND name = ?', [$gid, $orow['name']]);
                    if ($oid) Db::update('options', $oid, $orow); else $oid = Db::insert('options', $orow);
                    $keepO[] = $oid;
                    Db::exec('DELETE FROM option_size_prices WHERE option_id = ?', [$oid]);
                    foreach (explode(',', (isset($parts[2]) && str_contains($parts[2], '=')) ? $parts[2] : '') as $pair) {
                        if (!str_contains($pair, '=')) continue;
                        [$sn, $sv] = array_map('trim', explode('=', $pair, 2));
                        if ($sn !== '') Db::insert('option_size_prices', ['option_id' => $oid, 'size_name' => $sn, 'price_cents' => parse_money($sv)]);
                    }
                }
                $ph = $keepO ? implode(',', array_fill(0, count($keepO), '?')) : '0';
                Db::exec("DELETE FROM options WHERE group_id = ? AND id NOT IN ($ph)", [$gid, ...$keepO]);
            }
            $ph = $keepG ? implode(',', array_fill(0, count($keepG), '?')) : '0';
            Db::exec("DELETE FROM option_groups WHERE category_id = ? AND id NOT IN ($ph)", [$cid, ...$keepG]);
        });
        Auth::audit('menu.groups', "categoria #$cid");
        redirect('/admin/cardapio');
    }

    // ================= admin: clientes, relatórios, config =================
    private static function admCustomers(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $w = $q !== '' ? 'WHERE c.name LIKE ? OR c.phone LIKE ?' : '';
        $p = $q !== '' ? ['%' . $q . '%', '%' . normalize_phone($q) . '%'] : [];
        $rows = Db::all("SELECT c.*, COUNT(o.id) AS n, COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total_cents END),0) AS total
            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id $w GROUP BY c.id ORDER BY c.last_order_at DESC LIMIT 200", $p);
        $hist = [];
        if (!empty($_GET['id'])) $hist = Db::all('SELECT id, created_at, status, total_cents FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 50', [(int)$_GET['id']]);
        self::adm('admin/customers', ['title' => 'Clientes', 'rows' => $rows, 'q' => $q, 'hist' => $hist, 'sel' => (int)($_GET['id'] ?? 0), 'activeNav' => 'customers'], 'customers');
    }
    private static function range(): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
        return [$from, $to];
    }
    private static function admReports(): void
    {
        [$from, $to] = self::range();
        self::adm('admin/reports', ['title' => 'Vendas', 'from' => $from, 'to' => $to, 'r' => Reports::summary($from, $to), 'activeNav' => 'reports'], 'reports');
    }
    private static function admReportsCsv(): void
    {
        Auth::require('reports');
        [$from, $to] = self::range();
        Auth::audit('report.csv', "$from a $to");
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=pedidos-$from-a-$to.csv");
        echo Reports::csv($from, $to);
    }
    private static function admConfig(): void
    {
        $zones = Db::all('SELECT * FROM delivery_zones ORDER BY name');
        self::adm('admin/config', ['title' => 'Configurações', 'zones' => $zones, 's' => Settings::all(), 'activeNav' => 'config'], 'settings');
    }
    private static function admConfigSave(): void
    {
        Auth::require('settings');
        $in = $_POST;
        $flag = fn($k) => !empty($in[$k]) ? '1' : '0';
        foreach (['orders_open', 'delivery_enabled', 'pickup_enabled'] as $k) Settings::set($k, $flag($k));
        foreach (['estimate_delivery_min', 'estimate_pickup_min'] as $k) Settings::set($k, (string)max(0, min(600, (int)($in[$k] ?? 0))));
        foreach (['store_phone', 'store_address', 'store_whatsapp', 'instagram_url', 'links_url', 'pix_instructions'] as $k) Settings::set($k, mb_substr(trim((string)($in[$k] ?? '')), 0, 400));
        Settings::set('half_rule', ($in['half_rule'] ?? '') === 'highest' ? 'highest' : 'average');
        Settings::set('max_flavors', (string)max(1, min(4, (int)($in['max_flavors'] ?? 2))));
        // salão: quantidade de mesas, taxa de serviço (vazia = nenhuma) e atendimentos independentes
        $n = max(0, min(200, (int)($in['table_count'] ?? 0))); Settings::set('table_count', (string)$n); Floor::syncTables($n);
        $fee = str_replace(',', '.', trim((string)($in['service_fee_percent'] ?? '')));
        if ($fee !== '' && (!is_numeric($fee) || (float)$fee < 0 || (float)$fee > 30)) throw new HttpError(422, 'Taxa de serviço: informe um percentual entre 0 e 30, ou deixe em branco.');
        Settings::set('service_fee_percent', $fee === '' || (float)$fee == 0 ? '' : $fee);
        Settings::set('allow_independent_visits', $flag('allow_independent_visits'));
        $sched = [];
        for ($d = 0; $d < 7; $d++) {
            $a = $in['open'][$d] ?? ''; $b = $in['close'][$d] ?? '';
            $sched[(string)$d] = preg_match('/^\d\d:\d\d$/', $a) && preg_match('/^\d\d:\d\d$/', $b) && !empty($in['day_on'][$d]) ? [[$a, $b]] : [];
        }
        Settings::set('schedule', json_encode($sched));
        $pm = Settings::json('payment_methods');
        foreach ($pm as $k => &$m) { $m['on'] = !empty($in['pay_on'][$k]) ? 1 : 0; if (!empty($in['pay_label'][$k])) $m['label'] = mb_substr(trim($in['pay_label'][$k]), 0, 80); }
        Settings::set('payment_methods', json_encode($pm, JSON_UNESCAPED_UNICODE));
        $ps = Settings::json('print_sectors');
        foreach ($ps as $k => &$s) { $s['on'] = !empty($in['ps_on'][$k]) ? 1 : 0; $s['paper'] = ($in['ps_paper'][$k] ?? '80') === '58' ? 58 : 80; $s['values'] = !empty($in['ps_values'][$k]) ? 1 : 0; }
        Settings::set('print_sectors', json_encode($ps));
        // bairros/zonas de entrega
        foreach (($in['z_name'] ?? []) as $i => $zn) {
            $zn = trim((string)$zn); $zid = (int)($in['z_id'][$i] ?? 0);
            if ($zn === '') { continue; }
            $zrow = ['name' => mb_substr($zn, 0, 120), 'fee_cents' => parse_money((string)($in['z_fee'][$i] ?? '0')), 'min_order_cents' => parse_money((string)($in['z_min'][$i] ?? '0')), 'active' => !empty($in['z_on'][$i]) ? 1 : 0];
            if ($zid) Db::update('delivery_zones', $zid, $zrow); else Db::insert('delivery_zones', $zrow);
        }
        Auth::audit('settings.save');
        self::flash('Configurações salvas.');
        redirect('/admin/config');
    }
    private static function admUsers(): void
    {
        $users = Db::all('SELECT id, name, email, role, active, perms, last_login_at, created_at FROM users ORDER BY role, name');
        foreach ($users as &$u) $u['effective'] = Auth::permsOf($u);
        self::adm('admin/users', ['title' => 'Usuários e permissões', 'users' => $users, 'activeNav' => 'users'], 'users');
    }
    private static function admUsersSave(): void
    {
        $me = Auth::require('users'); // somente o proprietário
        $act = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
        $perms = isset($_POST['perms']) && is_array($_POST['perms']) ? array_keys($_POST['perms']) : [];
        if ($act === 'create') Team::create(array_merge($_POST, ['perms' => $perms]), $me);
        elseif ($act === 'update') Team::update($id, ['name' => $_POST['name'] ?? '', 'role' => $_POST['role'] ?? '', 'perms' => $perms], $me);
        elseif ($act === 'toggle') { $t = Db::one('SELECT active FROM users WHERE id = ?', [$id]); Team::setActive($id, !($t && $t['active']), $me); }
        elseif ($act === 'password') Team::setPassword($id, (string)($_POST['password'] ?? ''), $me);
        elseif ($act === 'revoke') Team::revoke($id, $me);
        else throw new HttpError(400, 'Ação inválida.');
        self::flash('Alteração registrada.');
        redirect('/admin/usuarios');
    }
    private static function admAccess(): void
    {
        $log = Db::all("SELECT * FROM audit_log WHERE action LIKE 'user.%' OR action LIKE 'auth.%' OR action LIKE 'settings.%' OR action LIKE 'cash.%' OR action LIKE 'tab.%' ORDER BY id DESC LIMIT 200");
        $ops = Db::all("SELECT u.id, u.name, u.role, u.last_login_at,
            (SELECT COUNT(*) FROM floor_events e WHERE e.user_id = u.id AND e.kind = 'round') AS rounds,
            (SELECT COUNT(*) FROM tab_payments p WHERE p.reported_by = u.id) AS reported,
            (SELECT COUNT(*) FROM tab_payments p WHERE p.confirmed_by = u.id) AS confirmed FROM users u ORDER BY u.name");
        self::adm('admin/access', ['title' => 'Auditoria de acessos', 'log' => $log, 'ops' => $ops, 'activeNav' => 'access'], 'audit');
    }
    private static function admPrint(): void
    {
        $jobs = Db::all('SELECT * FROM print_jobs ORDER BY id DESC LIMIT 60');
        self::adm('admin/print', ['title' => 'Impressão', 'jobs' => $jobs, 'newToken' => $_SESSION['new_token'] ?? null, 'seen' => Settings::get('print_agent_seen'), 'activeNav' => 'print'], 'print');
        unset($_SESSION['new_token']);
    }
    private static function admPrintToken(): void
    {
        Auth::require('settings');
        $_SESSION['new_token'] = Printing::newAgentToken();
        Auth::audit('print.token');
        redirect('/admin/impressao');
    }
}
