<?php
declare(strict_types=1);
/**
 * Converte as fontes TTF em WOFF 1.0 (tabelas comprimidas com zlib), sem ferramentas externas: php bin/build-fonts.php
 * O CSS usa o WOFF e mantém o TTF como alternativa. O conteúdo da fonte não é alterado (mesmas tabelas, só comprimidas).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
foreach (glob(dirname(__DIR__) . '/public/assets/fonts/*.ttf') as $ttf) {
    $d = file_get_contents($ttf); $flavor = substr($d, 0, 4); $num = unpack('n', substr($d, 4, 2))[1];
    $tables = [];
    for ($i = 0; $i < $num; $i++) { $r = unpack('a4tag/Nsum/Noff/Nlen', substr($d, 12 + 16 * $i, 16)); $tables[$r['tag']] = $r; }
    ksort($tables, SORT_STRING);
    $blobs = []; $off = 44 + 20 * $num; $sfnt = 12 + 16 * $num; $dir = '';
    foreach ($tables as $tag => $t) {
        $raw = substr($d, $t['off'], $t['len']); $z = gzcompress($raw, 9); $use = strlen($z) < strlen($raw) ? $z : $raw;
        $pad = (4 - strlen($use) % 4) % 4; $blobs[] = $use . str_repeat("\0", $pad);
        $dir .= pack('a4NNNN', $tag, $off, strlen($use), $t['len'], $t['sum']);
        $off += strlen($use) + $pad; $sfnt += $t['len'] + ((4 - $t['len'] % 4) % 4);
    }
    $hdr = 'wOFF' . $flavor . pack('N', $off) . pack('nn', $num, 0) . pack('N', $sfnt) . pack('nn', 1, 0) . pack('NNNNN', 0, 0, 0, 0, 0);
    $woff = $hdr . $dir . implode('', $blobs);
    $out = preg_replace('/\.ttf$/', '.woff', $ttf); file_put_contents($out, $woff);
    printf("%s: %d -> %d bytes (%.0f%%)\n", basename($ttf), strlen($d), strlen($woff), strlen($woff) / strlen($d) * 100);
}
