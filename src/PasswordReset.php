<?php
declare(strict_types=1);

/**
 * Recuperação de senha por e-mail (autoatendimento do funcionário cadastrado).
 * Regras: resposta SEMPRE igual (não revela se o e-mail existe); conta inativa não recebe link; token aleatório de uso único,
 * guardado só como hash, válido por 60 min; redefinir NUNCA altera cargo, permissões nem reativa conta; encerra sessões abertas.
 */
final class PasswordReset
{
    private const TTL = 3600;

    public static function request(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        // limite por IP e por e-mail (usa a tabela rate_limits)
        foreach (['reset-ip:' . $ip, 'reset-em:' . sha1($email)] as $b) {
            Db::exec('DELETE FROM rate_limits WHERE at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
            if ((int)Db::val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND at >= ?', [$b, date('Y-m-d H:i:s', time() - 3600)]) >= 5) return; // silencioso
            Db::insert('rate_limits', ['bucket' => $b, 'at' => now()]);
        }
        $u = Db::one('SELECT id, name, email, active FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['active']) return; // conta inexistente ou bloqueada: nada acontece (e nada é revelado)
        $base = rtrim((string)env('APP_URL', ''), '/');
        if ($base === '') { app_log('recuperação de senha: APP_URL não configurado'); return; } // nunca monta link com o cabeçalho Host
        Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $u['id']]); // invalida pedidos anteriores
        $token = bin2hex(random_bytes(32));
        Db::insert('password_resets', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + self::TTL), 'created_at' => now(), 'ip' => $ip]);
        Db::insert('audit_log', ['user_id' => $u['id'], 'user_name' => $u['name'], 'action' => 'auth.reset_requested', 'detail' => 'ip ' . $ip, 'created_at' => now()]);
        Mailer::send($u['email'], 'Redefinir sua senha — Pizzaria Bianca',
            "Olá, {$u['name']}.\n\nRecebemos um pedido para redefinir a senha da Área da equipe. Use o link abaixo (vale por 60 minutos e só pode ser usado uma vez):\n\n$base/equipe/redefinir?token=$token\n\nSe não foi você, ignore este e-mail: nada será alterado.\n");
    }

    /** @return array|null linha do pedido válido (não usado, não expirado, usuário ativo) */
    public static function valid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $r = Db::one('SELECT r.*, u.active FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ?', [hash('sha256', $token)]);
        if (!$r || $r['used_at'] !== null || $r['expires_at'] < now() || !$r['active']) return null;
        return $r;
    }

    public static function reset(string $token, string $password): void
    {
        $r = self::valid($token);
        if (!$r) throw new HttpError(410, 'Link inválido ou expirado. Peça um novo.');
        if ($err = Auth::validatePassword($password)) throw new HttpError(422, $err);
        Db::tx(function () use ($r, $password) {
            $n = Db::exec('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $r['id']]);
            if ($n !== 1) throw new HttpError(410, 'Link inválido ou expirado. Peça um novo.');
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ? AND active = 1', [password_hash($password, PASSWORD_DEFAULT), $r['user_id']]); // só a senha
            Auth::revokeSessions((int)$r['user_id']);
            Db::exec('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?)', [$r['user_id']]);
            Db::insert('audit_log', ['user_id' => $r['user_id'], 'action' => 'auth.password_reset', 'detail' => 'senha redefinida por e-mail', 'created_at' => now()]);
        });
    }
}
