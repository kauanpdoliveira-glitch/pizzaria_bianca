<?php
declare(strict_types=1);
/**
 * Ativa/desativa uma conta pelo servidor (inclusive proprietário, que o painel não desativa).
 *   php bin/user-status.php email@dominio deactivate|activate
 * Regras: nunca desativa o ÚLTIMO proprietário ativo; desativar derruba as sessões e revoga convites pendentes;
 * conta pendente de convite não é "ativada" por aqui (reenvie o convite); tudo vai para a auditoria.
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
Db::migrate();
[$_, $email, $act] = $argv + [null, null, null];
$email = mb_strtolower(trim((string)$email));
if (!$email || !in_array($act, ['deactivate', 'activate'], true)) exit("Uso: php bin/user-status.php email deactivate|activate\n");
$u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
if (!$u) exit("Conta não encontrada: nada alterado.\n");
if ($act === 'activate') {
    if (Invite::pending($u)) exit("Conta pendente de convite: reenvie o convite (bin/provision-owner.php ou painel). Nada alterado.\n");
    Db::exec('UPDATE users SET active = 1 WHERE id = ?', [$u['id']]);
} else {
    if ($u['role'] === 'owner' && $u['active'] && (int)Db::val("SELECT COUNT(*) FROM users WHERE role = 'owner' AND active = 1 AND id <> ?", [$u['id']]) < 1) exit("Recusado: seria o ÚLTIMO proprietário ativo. Nada alterado.\n");
    Db::exec('UPDATE users SET active = 0 WHERE id = ?', [$u['id']]);
    Invite::revokeAll((int)$u['id']);
}
Auth::revokeSessions((int)$u['id']);
Db::insert('audit_log', ['user_name' => 'terminal', 'action' => 'user.' . $act, 'detail' => "#{$u['id']} $email (CLI)", 'created_at' => now()]);
echo "Conta $email: " . ($act === 'activate' ? 'ativada' : 'desativada') . ". Sessões encerradas.\n";
