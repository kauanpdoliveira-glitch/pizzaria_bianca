<?php
declare(strict_types=1);
/**
 * Cria um usuário pelo terminal. Uso: php bin/create-admin.php email@dominio "Nome" [owner|manager|waiter]
 * É o ÚNICO caminho para criar o primeiro PROPRIETÁRIO (perfil padrão). A senha é pedida no terminal. Sem senha padrão;
 * ninguém vira proprietário automaticamente. Depois disso, o proprietário cadastra gerentes e garçons pelo painel.
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
Db::migrate();
[$_, $email, $name] = $argv + [null, null, null];
$role = $argv[3] ?? 'owner';
if ($role === 'admin') $role = 'owner'; // compatibilidade com o nome antigo
if (!$email || !$name || !isset(Auth::ROLES[$role])) exit("Uso: php bin/create-admin.php email \"Nome\" [owner|manager|waiter]\n");
$pass = $argv[4] ?? null; // 4º argumento só para automação/testes
if ($pass === null) {
    fwrite(STDOUT, 'Senha (mín. 10, letras e números): ');
    if (DIRECTORY_SEPARATOR === '\\') $pass = trim((string)fgets(STDIN));
    else { system('stty -echo'); $pass = trim((string)fgets(STDIN)); system('stty echo'); echo "\n"; }
}
if ($err = Auth::validatePassword($pass)) exit($err . "\n");
$email = mb_strtolower(trim($email));
if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) exit("Já existe usuário com esse e-mail.\n");
Db::insert('users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => $role, 'active' => 1, 'created_at' => now()]);
Db::insert('audit_log', ['user_name' => 'terminal', 'action' => 'user.create', 'detail' => "$email $role (CLI)", 'created_at' => now()]);
echo "Usuário $email criado como " . Auth::ROLES[$role] . ".\n";
