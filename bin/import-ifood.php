<?php
declare(strict_types=1);
/**
 * Importa o que foi observado no iFood (data/ifood/*.json). Padrão = SIMULAÇÃO (não grava).
 *   php bin/import-ifood.php                         relatório de diferenças
 *   php bin/import-ifood.php --apply                 grava observações e rascunhos OCULTOS (não muda preço nem produto publicado)
 *   php bin/import-ifood.php --apply --photos        também liga fotos a produtos SEM foto (confirme antes o direito de uso)
 *   php bin/import-ifood.php --unlink-photos         desfaz as fotos ligadas por esta importação
 *   --file=data/ifood/outro.json                     outro arquivo de observação
 * Pode ser executado várias vezes sem duplicar nada.
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
require __DIR__ . '/../src/bootstrap.php';
Db::migrate();
$args = array_slice($argv, 1);
if (in_array('--unlink-photos', $args, true)) { echo 'Fotos desligadas: ' . IfoodImport::unlinkPhotos(APP_ROOT . '/public/uploads') . "\n"; exit(0); }
$file = APP_ROOT . '/data/ifood/ifood-2026-11-04.json';
foreach ($args as $a) if (str_starts_with($a, '--file=')) $file = realpath(substr($a, 7)) ?: substr($a, 7);
$data = json_decode((string)file_get_contents($file), true);
if (!is_array($data)) { fwrite(STDERR, "Arquivo inválido: $file\n"); exit(1); }
echo IfoodImport::format(IfoodImport::run($data, ['apply' => in_array('--apply', $args, true), 'photos' => in_array('--photos', $args, true)]));
