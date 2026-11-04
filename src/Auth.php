<?php
declare(strict_types=1);

final class Auth
{
    public const ROLES = ['owner' => 'Proprietário', 'manager' => 'Gerente', 'waiter' => 'Garçom', 'station' => 'Estação de impressão'];

    /** Recursos exclusivos do proprietário: nunca concedidos a outro perfil, nem por permissão individual. */
    public const OWNER_ONLY = ['users', 'audit', 'settings', 'executive'];

    /** Matriz explícita de permissões por perfil (o que não está aqui é NEGADO). */
    public const ROLE_DEFAULTS = [
        'manager' => ['orders', 'menu', 'customers', 'reports', 'print', 'floor', 'cashier', 'discount', 'void'],
        'waiter' => ['floor', 'report_payment'],
        'station' => ['station'],   // identidade própria e revogável da estação: só consome a fila e assina envios legítimos
    ];

    /** Permissões que o proprietário pode ligar/desligar por usuário (limitadas ao teto do perfil). */
    public const GRANTABLE = [
        'manager' => ['orders', 'menu', 'customers', 'reports', 'print', 'floor', 'cashier', 'discount', 'void', 'print_config'],
        'waiter' => ['floor', 'report_payment'],
        'station' => ['station'],
    ];

    public const PERM_LABELS = [
        'orders' => 'Gestor de pedidos', 'menu' => 'Cardápio', 'customers' => 'Clientes', 'reports' => 'Vendas', 'print' => 'Impressão',
        'floor' => 'Salão (mesas e comandas)', 'cashier' => 'Caixa: conferir recebimentos e fechar contas', 'discount' => 'Aplicar descontos e remover taxa de serviço',
        'void' => 'Cancelar pedidos já enviados', 'print_config' => 'Configurar impressoras e estações (só se o proprietário autorizar)', 'report_payment' => 'Informar recebimento ao caixa', 'station' => 'Estação de impressão (consumir a fila)',
        'users' => 'Usuários e permissões', 'audit' => 'Auditoria de acessos', 'settings' => 'Configurações sensíveis', 'executive' => 'Painel executivo',
    ];

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
        session_name('bianca_sid');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        if (env('SESSION_DRIVER') === 'db') session_set_save_handler(new DbSessions(), true);   // hospedagem sem disco persistente
        session_start();
    }

    public static function reset(): void { self::$user = null; }

    /** Usuário da sessão. O cargo e as permissões vêm SEMPRE do banco, nunca do navegador. */
    public static function user(): ?array
    {
        if (self::$user) return self::$user;
        self::start();
        $id = $_SESSION['uid'] ?? null;
        if (!$id) return null;
        $u = Db::one('SELECT id, name, email, role, active, perms, session_version FROM users WHERE id = ?', [$id]);
        // conta inativa, sessão revogada ou permissões/cargo alterados (session_version mudou) => acesso derrubado
        if (!$u || !$u['active'] || (int)$u['session_version'] !== (int)($_SESSION['sv'] ?? -1)) { unset($_SESSION['uid'], $_SESSION['sv']); return null; }
        return self::$user = $u;
    }

    /** Conjunto efetivo de permissões de um usuário (linha do banco). */
    public static function permsOf(array $u): array
    {
        if ($u['role'] === 'owner') return array_keys(self::PERM_LABELS);
        $base = self::ROLE_DEFAULTS[$u['role']] ?? [];
        if ($u['perms'] !== null && $u['perms'] !== '') {
            $stored = json_decode($u['perms'], true);
            if (is_array($stored)) $base = array_values(array_intersect($stored, self::GRANTABLE[$u['role']] ?? []));
        }
        return array_values(array_diff($base, self::OWNER_ONLY));
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        return $u && in_array($perm, self::permsOf($u), true);
    }

    /** Destino pós-login conforme o cadastro (nunca conforme o que o usuário digitou). */
    public static function home(): string
    {
        if (self::can('orders')) return '/admin';
        if (self::can('cashier')) return '/admin/caixa';
        if (self::can('floor')) return '/garcom';
        if (self::can('station')) return '/admin/estacao';
        return '/equipe';
    }

    /** Exige login e permissão; para chamadas de API responde JSON 401/403. */
    public static function require(string $perm, bool $api = false): array
    {
        $u = self::user();
        if (!$u) {
            if ($api) json_out(['error' => 'Não autenticado'], 401);
            redirect('/equipe');
        }
        if (!self::can($perm)) {
            if ($api) json_out(['error' => 'Sem permissão'], 403);
            http_response_code(403); echo 'Sem permissão.'; exit;
        }
        return $u;
    }

    /** Exige login e QUALQUER uma das permissões (ex.: operador com "print" ou a conta da estação com "station"). */
    public static function requireAny(array $perms, bool $api = false): array
    {
        $u = self::user();
        if (!$u) { if ($api) json_out(['error' => 'Não autenticado'], 401); redirect('/equipe'); }
        foreach ($perms as $p) if (self::can($p)) return $u;
        if ($api) json_out(['error' => 'Sem permissão'], 403);
        http_response_code(403); echo 'Sem permissão.'; exit;
    }
    /** @return string|null mensagem de erro (genérica: não revela se o e-mail existe) */
    public static function login(string $email, string $password): ?string
    {
        $email = mb_strtolower(trim($email));
        $since = date('Y-m-d H:i:s', time() - 900);
        $fails = (int)Db::val('SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip = ?) AND at >= ?', [$email, client_ip(), $since]);
        if ($fails >= 8) return 'Muitas tentativas. Aguarde 15 minutos.';
        $u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        // verifica sempre um hash para não revelar se o e-mail existe pelo tempo de resposta
        $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123.');
        if (!$u || !$ok || !$u['active']) {
            Db::insert('login_attempts', ['email' => $email, 'ip' => client_ip(), 'at' => now()]);
            return 'E-mail ou senha inválidos.';
        }
        Db::exec('DELETE FROM login_attempts WHERE email = ?', [$email]);
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['sv'] = (int)$u['session_version'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
        self::$user = null;
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) Db::update('users', (int)$u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        Db::insert('audit_log', ['user_id' => $u['id'], 'user_name' => $u['name'], 'action' => 'auth.login', 'detail' => 'ip ' . client_ip(), 'created_at' => now()]);
        return null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        self::$user = null;
    }

    /** Derruba todas as sessões do usuário (também usado ao mudar cargo, permissões, senha ou ativação). */
    public static function revokeSessions(int $userId): void
    {
        Db::exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    public static function checkCsrf(): void
    {
        self::start();
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (str_contains($uri, '/api/')) json_out(['error' => 'Sessão expirada. Recarregue a página.'], 419);
            http_response_code(419); echo 'Sessão expirada. Volte e recarregue a página.'; exit;
        }
    }

    public static function validatePassword(string $p): ?string
    {
        if (mb_strlen($p) < 10) return 'A senha deve ter ao menos 10 caracteres.';
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'A senha deve ter letras e números.';
        return null;
    }

    public static function audit(string $action, string $detail = ''): void
    {
        $u = self::user();
        Db::insert('audit_log', ['user_id' => $u['id'] ?? null, 'user_name' => $u['name'] ?? null, 'action' => $action, 'detail' => mb_substr($detail, 0, 400), 'created_at' => now()]);
    }
}
