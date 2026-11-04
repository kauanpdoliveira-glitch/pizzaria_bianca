<?php
declare(strict_types=1);
/**
 * Provisiona o PROPRIETÁRIO/fundador por convite (executado no servidor; não existe endpoint público para isto).
 *   php bin/provision-owner.php email@dominio "Nome" [--additional-owner] [--badge=Fundador]
 * Regras:
 *  - nenhuma senha é criada aqui: a pessoa define a própria senha pelo link enviado por e-mail (uso único, 48 h);
 *  - não ativa nem altera conta já ativada; não sobrescreve conta existente (conflito = pare e investigue);
 *  - se já houver proprietário ativo, exige --additional-owner de propósito (mostra quem é);
 *  - reexecutar para um convite ainda pendente do MESMO e-mail só reenvia o convite (invalida os anteriores).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
Db::migrate();
$args = array_slice($argv, 1); $flags = array_filter($args, fn($a) => str_starts_with($a, '--')); $pos = array_values(array_diff($args, $flags));
$email = mb_strtolower(trim((string)($pos[0] ?? ''))); $name = trim((string)($pos[1] ?? ''));
$badge = 'Fundador'; foreach ($flags as $f) if (str_starts_with($f, '--badge=')) $badge = mb_substr(trim(substr($f, 8)), 0, 40);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') exit("Uso: php bin/provision-owner.php email \"Nome\" [--additional-owner] [--badge=Fundador]\n");
$u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
if ($u) {
    if (!Invite::pending($u)) exit("CONFLITO: já existe uma conta com $email (" . (Auth::ROLES[$u['role']] ?? $u['role']) . ($u['active'] ? ', ativa' : ', desativada') . "). Nada foi alterado. Investigue antes de continuar.\n");
    if ($u['role'] !== 'owner') exit("CONFLITO: $email tem convite pendente com outro perfil (" . ($u['role']) . "). Nada foi alterado.\n");
    $id = (int)$u['id'];
} else {
    $owners = Db::all("SELECT email, active FROM users WHERE role = 'owner'");
    if ($owners && !in_array('--additional-owner', $flags, true)) { echo "Já existe proprietário:\n"; foreach ($owners as $o) echo '  - ' . $o['email'] . ($o['active'] ? ' (ativo)' : ' (desativado)') . "\n"; exit("Nada foi criado. Para adicionar outro proprietário de propósito, repita com --additional-owner.\n"); }
    $id = Db::insert('users', ['name' => mb_substr($name, 0, 120), 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'role' => 'owner', 'active' => 0, 'created_at' => now(), 'invited_by' => 'terminal', 'badge' => $badge ?: null]);
    Db::insert('audit_log', ['user_name' => 'terminal', 'action' => 'user.create', 'detail' => "#$id $email owner convite pendente (provisionamento)", 'created_at' => now()]);
}
try { $r = Invite::issue($id, 'terminal'); } catch (HttpError $e) { exit('Convite não criado: ' . $e->getMessage() . "\n"); }
$drv = env('MAIL_DRIVER', 'log');
echo "Conta $email provisionada como proprietário (pendente de ativação).\n";
echo $r['sent'] ? ($drv === 'log' ? "E-mail gravado em storage/mail (MAIL_DRIVER=log): NADA foi enviado. Abra o arquivo localmente para usar o link.\n" : "E-mail aceito pelo serviço de envio ($drv). Isto não prova a chegada à caixa de entrada.\n") : "O e-mail NÃO foi enviado. Configure MAIL_DRIVER/SMTP e rode de novo para reenviar.\n";
