<?php
declare(strict_types=1);

/**
 * Convite de acesso por e-mail. Regras:
 *  - o link leva um token aleatório de 256 bits; no banco só fica o SHA-256 (o token nunca é gravado em log, auditoria ou erro);
 *  - vale 48 h, é de USO ÚNICO e fica inválido ao ser usado, revogado ou substituído por um novo convite;
 *  - a base do link vem de APP_URL (nunca do cabeçalho Host); se o endereço do túnel mudar, o proprietário reenvia o convite;
 *  - definir a senha exige o token: informar só um e-mail nunca basta;
 *  - ao ativar, perfil e estado da conta são REVALIDADOS; convite antigo não restaura conta desativada nem permissões revogadas.
 */
final class Invite
{
    public const TTL = 172800;

    /** Conta pendente = criada por convite (invited_by) e ainda não ativada. Usuários antigos/CLI/testes nascem ativos. */
    public static function pending(array $u): bool { return ($u['activated_at'] ?? null) === null && ($u['invited_by'] ?? null) !== null; }

    public static function revokeAll(int $userId): void
    {
        Db::exec('UPDATE user_invites SET revoked_at = ? WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL', [now(), $userId]);
    }

    /** Cria um convite novo (invalidando os anteriores) e envia o e-mail. @return array{sent:bool} */
    public static function issue(int $userId, string $by): array
    {
        $u = Db::one('SELECT id, name, email, role, activated_at, invited_by FROM users WHERE id = ?', [$userId]);
        if (!$u) throw new HttpError(404, 'Usuário não encontrado.');
        if (!self::pending($u)) throw new HttpError(422, 'Esta conta já foi ativada; use a recuperação de senha.');
        $base = rtrim((string)env('APP_URL', ''), '/');
        if ($base === '' || !preg_match('#^https?://#', $base)) throw new HttpError(500, 'APP_URL não está configurado: o link do convite não pode ser montado.');
        self::revokeAll($userId);
        $token = bin2hex(random_bytes(32));
        $id = Db::insert('user_invites', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + self::TTL), 'created_by' => $by, 'created_at' => now()]);
        $role = Auth::ROLES[$u['role']] ?? $u['role'];
        $ok = Mailer::send($u['email'], 'Seu acesso à Pizzaria Bianca (Homologação)',
            "Olá, {$u['name']}.\n\nVocê foi convidado(a) para a Área da equipe da Pizzaria Bianca com o perfil: $role.\n\nDefina a sua senha pelo link abaixo. Ele vale por 48 horas e só pode ser usado uma vez:\n\n$base/equipe/convite?token=$token\n\nSe você não esperava este convite, ignore este e-mail: nada será ativado.\n");
        Db::exec('UPDATE user_invites SET sent_ok = ? WHERE id = ?', [$ok ? 1 : 0, $id]);
        Db::insert('audit_log', ['user_name' => $by, 'action' => 'user.invite', 'detail' => "#$userId {$u['email']} " . ($ok ? 'enviado ao serviço de e-mail' : 'FALHA no envio'), 'created_at' => now()]);
        return ['sent' => $ok];
    }

    /** @return array|null convite válido (não usado, não revogado, não expirado) de uma conta ainda pendente */
    public static function valid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $r = Db::one('SELECT i.*, u.name, u.email, u.role, u.activated_at, u.invited_by FROM user_invites i JOIN users u ON u.id = i.user_id WHERE i.token_hash = ?', [hash('sha256', $token)]);
        if (!$r || $r['used_at'] !== null || $r['revoked_at'] !== null || $r['expires_at'] < now() || !self::pending($r)) return null;
        if (!isset(Auth::ROLES[$r['role']])) return null;   // perfil removido/desconhecido
        return $r;
    }

    public static function accept(string $token, string $password): void
    {
        $r = self::valid($token);
        if (!$r) throw new HttpError(410, 'Link inválido, já usado ou expirado. Peça um novo convite ao proprietário.');
        if ($err = Auth::validatePassword($password)) throw new HttpError(422, $err);
        Db::tx(function () use ($r, $password) {
            $n = Db::exec('UPDATE user_invites SET used_at = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL', [now(), $r['id']]);
            if ($n !== 1) throw new HttpError(410, 'Link inválido, já usado ou expirado.');
            $m = Db::exec('UPDATE users SET password_hash = ?, active = 1, activated_at = ? WHERE id = ? AND activated_at IS NULL AND invited_by IS NOT NULL', [password_hash($password, PASSWORD_DEFAULT), now(), $r['user_id']]);
            if ($m !== 1) throw new HttpError(410, 'Esta conta não está mais pendente de ativação.');
            self::revokeAll((int)$r['user_id']);
            Auth::revokeSessions((int)$r['user_id']);
            Db::insert('audit_log', ['user_id' => $r['user_id'], 'user_name' => $r['name'], 'action' => 'user.activated', 'detail' => "#{$r['user_id']} {$r['role']} ativado por convite", 'created_at' => now()]);
        });
    }
}
