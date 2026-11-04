<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$a = Db::migrate();
echo $a ? 'Migrations aplicadas: ' . implode(', ', $a) . "\n" : "Nada a migrar.\n";
