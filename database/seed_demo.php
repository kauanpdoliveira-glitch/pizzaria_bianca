<?php
declare(strict_types=1);
/**
 * DADOS FICTÍCIOS — somente desenvolvimento. Recusa rodar com APP_ENV=production.
 * Cria bairros de exemplo (nomes "DEMO") e um usuário gerente com senha aleatória exibida no terminal.
 */
require_once __DIR__ . '/../src/bootstrap.php';
if (env('APP_ENV', 'local') === 'production') exit("Recusado: APP_ENV=production.\n");
Db::migrate();
if (!Db::val('SELECT COUNT(*) FROM delivery_zones')) {
    foreach ([['DEMO Bairro Central', 800, 3000], ['DEMO Bairro Vizinho', 1200, 4000]] as [$n, $f, $m]) Db::insert('delivery_zones', ['name' => $n, 'fee_cents' => $f, 'min_order_cents' => $m, 'active' => 1]);
}
if (!Db::val("SELECT 1 FROM users WHERE email = 'gerente@demo.local'")) {
    $pass = 'Demo' . bin2hex(random_bytes(5)) . '9';
    Db::insert('users', ['name' => 'Gerente Demo', 'email' => 'gerente@demo.local', 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'manager', 'active' => 1, 'created_at' => now()]);
    echo "Usuário demo: gerente@demo.local  senha: $pass\n";
}
echo "Demo pronto.\n";
