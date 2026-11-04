<?php
declare(strict_types=1);
/**
 * Exporta SÓ o catálogo (sem clientes, pedidos, usuários, senhas, tokens ou sessões) no mesmo formato do bin/backup.php,
 * para restaurar com: php bin/restore.php arquivo.json
 * Uso: php bin/export-catalog.php [arquivo-de-saída]
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
$out = $argv[1] ?? APP_ROOT . '/database/export/catalogo-' . date('Y-m-d') . '.json';
if (!is_dir(dirname($out))) mkdir(dirname($out), 0775, true);
$tables = ['categories', 'products', 'product_sizes', 'product_ingredients', 'option_groups', 'options', 'option_size_prices', 'channel_observations']; // bairros/taxas, mesas e horários são configuração operacional: configure no painel (não exportados)
$data = ['version' => 1, 'created_at' => now(), 'escopo' => 'catalogo (sem dados pessoais, usuarios, tokens ou pedidos)'];
foreach ($tables as $t) $data['tables'][$t] = Db::all("SELECT * FROM $t");
file_put_contents($out, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$n = array_sum(array_map('count', $data['tables']));
echo "Catálogo exportado em $out ($n linhas, " . round(filesize($out) / 1024) . " KB). Imagens: copie também public/uploads/ e public/assets/img/.\n";
