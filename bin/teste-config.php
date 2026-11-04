<?php
declare(strict_types=1);
/** Prepara um banco de TESTE (APP_ENV=staging): loja aberta 24 h e uma zona de entrega fictícia. Recusa rodar fora do ambiente de testes. */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
if (env('APP_ENV') !== 'staging') exit("Recusado: defina APP_ENV=staging (este comando altera o horário da loja).\n");
Db::migrate();
Settings::set('schedule', json_encode(array_fill(0, 7, [['00:00', '23:59']])));
if (!Db::val('SELECT 1 FROM delivery_zones LIMIT 1')) Db::insert('delivery_zones', ['name' => 'Centro (teste)', 'fee_cents' => 500, 'min_order_cents' => 0, 'active' => 1]);
echo "Banco de teste preparado: loja aberta 24 h (somente neste banco).\n";
