<?php
declare(strict_types=1);

/** Cadastro de funcionários. Somente o proprietário (permissão 'users') pode chamar — conferido aqui também, além das rotas. */
final class Team
{
    private static function guard(array $actor): void
    {
        if (($actor['role'] ?? '') !== 'owner' || !in_array('users', Auth::permsOf($actor), true)) throw new HttpError(403, 'Somente o proprietário gerencia acessos.');
    }

    private static function cleanPerms(string $role, array $in): array
    {
        return array_values(array_intersect((array)$in, Auth::GRANTABLE[$role] ?? []));
    }

    private static function target(int $id): array
    {
        $t = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$t) throw new HttpError(404, 'Usuário não encontrado.');
        return $t;
    }

    public static function create(array $in, array $actor): int
    {
        self::guard($actor);
        $role = (string)($in['role'] ?? '');
        if (!in_array($role, ['manager', 'waiter', 'station'], true)) throw new HttpError(422, 'Perfil inválido: só é possível cadastrar gerentes, garçons e estações.');
        $email = mb_strtolower(trim((string)($in['email'] ?? ''))); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') throw new HttpError(422, 'Informe nome e e-mail válidos.');
        if ($err = Auth::validatePassword((string)($in['password'] ?? ''))) throw new HttpError(422, $err);
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) throw new HttpError(422, 'E-mail já cadastrado.');
        $perms = array_key_exists('perms', $in) ? self::cleanPerms($role, $in['perms']) : Auth::ROLE_DEFAULTS[$role];
        $id = Db::insert('users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash((string)$in['password'], PASSWORD_DEFAULT), 'role' => $role,
            'perms' => json_encode($perms), 'active' => 1, 'created_at' => now()]);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.create', 'detail' => "#$id $email $role [" . implode(',', $perms) . ']', 'created_at' => now()]);
        return $id;
    }

    /** Convite: o proprietário informa e-mail, nome e perfil; a PESSOA define a própria senha pelo link. Nada é ativado até lá. */
    public static function invite(array $in, array $actor): array
    {
        self::guard($actor);
        $role = (string)($in['role'] ?? '');
        if (!in_array($role, ['manager', 'waiter', 'station'], true)) throw new HttpError(422, 'Perfil inválido: convide gerentes, garçons ou estações (proprietário só por provisionamento no servidor).');
        $email = mb_strtolower(trim((string)($in['email'] ?? ''))); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') throw new HttpError(422, 'Informe nome e e-mail válidos.');
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) throw new HttpError(422, 'E-mail já cadastrado. Para quem ainda não aceitou, use "Reenviar convite".');
        $perms = ($role === 'manager' && array_key_exists('perms', $in)) ? self::cleanPerms($role, (array)$in['perms']) : Auth::ROLE_DEFAULTS[$role];   // garçom e estação sempre nascem com o mínimo do perfil
        $id = Db::insert('users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'role' => $role,   // senha aleatória inutilizável até o convite
            'perms' => json_encode($perms), 'active' => 0, 'created_at' => now(), 'invited_by' => $actor['name']]);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.create', 'detail' => "#$id $email $role [" . implode(',', $perms) . '] convite pendente', 'created_at' => now()]);
        return ['id' => $id] + Invite::issue($id, $actor['name']);
    }

    public static function resendInvite(int $id, array $actor): array
    {
        self::guard($actor); $t = self::target($id);
        if (!Invite::pending($t)) throw new HttpError(422, 'Esta conta já foi ativada.');
        return Invite::issue($id, $actor['name']);   // invalida os anteriores e usa a APP_URL atual
    }

    public static function revokeInvite(int $id, array $actor): void
    {
        self::guard($actor); $t = self::target($id);
        Invite::revokeAll($id);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.invite_revoked', 'detail' => "#$id {$t['email']}", 'created_at' => now()]);
    }
    /** Altera nome, perfil (gerente ⇄ garçom) e permissões. Derruba as sessões do alvo se algo de acesso mudou. */
    public static function update(int $id, array $in, array $actor): void
    {
        self::guard($actor);
        $t = self::target($id);
        if ($t['role'] === 'owner') throw new HttpError(422, 'Contas de proprietário não são editadas por aqui.');
        $role = (string)($in['role'] ?? $t['role']);
        if (!in_array($role, ['manager', 'waiter', 'station'], true)) throw new HttpError(422, 'Perfil inválido.');
        $name = mb_substr(trim((string)($in['name'] ?? $t['name'])), 0, 120) ?: $t['name'];
        $perms = array_key_exists('perms', $in) ? self::cleanPerms($role, $in['perms']) : array_values(Auth::permsOf($t));
        if ($role !== $t['role'] && !array_key_exists('perms', $in)) $perms = Auth::ROLE_DEFAULTS[$role];
        $before = implode(',', Auth::permsOf($t)); sort($perms); $after = implode(',', $perms);
        $access = $role !== $t['role'] || $before !== implode(',', Auth::permsOf(['role' => $role, 'perms' => json_encode($perms)] + $t));
        Db::update('users', $id, ['name' => $name, 'role' => $role, 'perms' => json_encode($perms)]);
        if ($access) Auth::revokeSessions($id);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.update', 'detail' => "#$id {$t['role']}→$role perms [$before] → [$after]", 'created_at' => now()]);
    }

    public static function setActive(int $id, bool $active, array $actor): void
    {
        self::guard($actor);
        $t = self::target($id);
        if ($id === (int)$actor['id']) throw new HttpError(422, 'Você não pode desativar a si mesmo.');
        if ($t['role'] === 'owner') throw new HttpError(422, 'Contas de proprietário não são desativadas por aqui.');
        if ($active && Invite::pending($t)) throw new HttpError(422, 'Esta conta ainda não aceitou o convite: reenvie o convite em vez de reativar.');
        Db::exec('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        if (!$active) Invite::revokeAll($id);   // convite pendente de quem foi desativado deixa de valer
        Auth::revokeSessions($id);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => $active ? 'user.activate' : 'user.deactivate', 'detail' => "#$id {$t['email']}", 'created_at' => now()]);
    }

    public static function setPassword(int $id, string $password, array $actor): void
    {
        self::guard($actor);
        $t = self::target($id);
        if ($t['role'] === 'owner' && $id !== (int)$actor['id']) throw new HttpError(422, 'A senha de outro proprietário não é alterada por aqui.');
        if ($err = Auth::validatePassword($password)) throw new HttpError(422, $err);
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        Auth::revokeSessions($id);
        if ($id === (int)$actor['id'] && session_status() === PHP_SESSION_ACTIVE) $_SESSION['sv'] = (int)Db::val('SELECT session_version FROM users WHERE id = ?', [$id]); // mantém o próprio proprietário logado
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.password', 'detail' => "#$id {$t['email']}", 'created_at' => now()]);
    }

    public static function revoke(int $id, array $actor): void
    {
        self::guard($actor);
        $t = self::target($id);
        Auth::revokeSessions($id);
        if ($id === (int)$actor['id'] && session_status() === PHP_SESSION_ACTIVE) $_SESSION['sv'] = (int)Db::val('SELECT session_version FROM users WHERE id = ?', [$id]);
        Db::insert('audit_log', ['user_id' => $actor['id'], 'user_name' => $actor['name'], 'action' => 'user.revoke_sessions', 'detail' => "#$id {$t['email']}", 'created_at' => now()]);
    }
}
