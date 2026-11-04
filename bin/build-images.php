<?php
declare(strict_types=1);
/** Gera variantes menores das imagens críticas (GD): php bin/build-images.php. Reproduzível; não altera as originais. */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$dir = dirname(__DIR__) . '/public/assets/img';
foreach ([['hero-pizza.jpg', 'hero-pizza-640.jpg', 640, 80]] as [$in, $out, $w, $q]) {
    $im = imagecreatefromjpeg("$dir/$in"); $re = imagescale($im, $w, -1, IMG_BICUBIC);
    imagejpeg($re, "$dir/$out", $q); printf("%s: %d -> %d bytes (%d px)\n", $out, filesize("$dir/$in"), filesize("$dir/$out"), $w);
}
