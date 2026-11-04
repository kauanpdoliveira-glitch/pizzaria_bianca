<?php
declare(strict_types=1);
/**
 * Gera os derivados otimizados da logo a partir do ORIGINAL preservado em brand/original/ (nunca alterado).
 * Ajustes técnicos seguros: recorte das margens transparentes (com respiro) e redimensionamento com alfa premultiplicado
 * (sem halo escuro nas bordas). Não redesenha nem modifica os traços. Uso: php bin/build-brand.php
 */
ini_set('memory_limit', '1G');
$root = dirname(__DIR__);
$src = imagecreatefromwebp($root . '/brand/original/logo-bianca-branca-original.webp');
$w = imagesx($src); $h = imagesy($src);
$alpha = fn(int $c): int => 255 - (int)round((($c >> 24) & 127) * 255 / 127);

// 1) caixa delimitadora do conteúdo visível (alfa > 3%) + respiro de 10 px
$minx = $w; $miny = $h; $maxx = 0; $maxy = 0;
for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) if ($alpha(imagecolorat($src, $x, $y)) > 8) { $minx = min($minx, $x); $maxx = max($maxx, $x); $miny = min($miny, $y); $maxy = max($maxy, $y); }
$pad = 10; $cx = max(0, $minx - $pad); $cy = max(0, $miny - $pad); $cw = min($w - $cx, $maxx - $minx + 1 + 2 * $pad); $ch = min($h - $cy, $maxy - $miny + 1 + 2 * $pad);

// 2) matriz premultiplicada do recorte
$P = []; 
for ($y = 0; $y < $ch; $y++) { $row = []; for ($x = 0; $x < $cw; $x++) { $c = imagecolorat($src, $cx + $x, $cy + $y); $a = $alpha($c) / 255; $row[] = [(($c >> 16) & 255) * $a, (($c >> 8) & 255) * $a, ($c & 255) * $a, $a]; } $P[] = $row; }

// 3) reamostragem por área (média de caixa) em espaço premultiplicado
$resize = function (int $tw) use ($P, $cw, $ch): GdImage {
    $th = (int)round($ch * $tw / $cw); $out = imagecreatetruecolor($tw, $th); imagealphablending($out, false); imagesavealpha($out, true);
    $sx = $cw / $tw; $sy = $ch / $th;
    for ($y = 0; $y < $th; $y++) {
        $y0 = $y * $sy; $y1 = ($y + 1) * $sy;
        for ($x = 0; $x < $tw; $x++) {
            $x0 = $x * $sx; $x1 = ($x + 1) * $sx; $acc = [0, 0, 0, 0]; $wt = 0;
            for ($yy = (int)floor($y0); $yy < min($ch, (int)ceil($y1)); $yy++) {
                $wy = min($yy + 1, $y1) - max($yy, $y0);
                for ($xx = (int)floor($x0); $xx < min($cw, (int)ceil($x1)); $xx++) {
                    $wx = min($xx + 1, $x1) - max($xx, $x0); $k = $wx * $wy; $p = $P[$yy][$xx];
                    $acc[0] += $p[0] * $k; $acc[1] += $p[1] * $k; $acc[2] += $p[2] * $k; $acc[3] += $p[3] * $k; $wt += $k;
                }
            }
            $a = $acc[3] / $wt;
            if ($a < 0.004) { imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0, 0, 0, 127)); continue; } // transparente: sem cor "fantasma"
            $r = (int)round(min(255, $acc[0] / $wt / $a)); $g = (int)round(min(255, $acc[1] / $wt / $a)); $b = (int)round(min(255, $acc[2] / $wt / $a));
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, 127 - (int)round($a * 127)));
        }
    }
    return $out;
};
$dir = $root . '/public/assets/brand'; @mkdir($dir, 0775, true);
foreach ([360, 600, 1200] as $tw) {
    $img = $resize($tw); imagepng($img, "$dir/logo-bianca-$tw.png", 9);
    imagewebp($img, "$dir/logo-bianca-$tw.webp", IMG_WEBP_LOSSLESS);
    printf("logo-bianca-%d: %dx%d  png %d KB  webp %d KB\n", $tw, imagesx($img), imagesy($img), filesize("$dir/logo-bianca-$tw.png") / 1024, filesize("$dir/logo-bianca-$tw.webp") / 1024);
}
// 4) versão pronta sobre o vermelho da marca (para og:image / dados estruturados, que aparecem sobre fundos claros)
$big = $resize(900); $og = imagecreatetruecolor(1200, 630); imagefill($og, 0, 0, imagecolorallocate($og, 196, 18, 31)); imagealphablending($og, true);
imagecopy($og, $big, intdiv(1200 - imagesx($big), 2), intdiv(630 - imagesy($big), 2), 0, 0, imagesx($big), imagesy($big)); imagepng($og, "$dir/logo-bianca-sobre-vermelho-1200x630.png", 9);
echo "recorte: {$cw}x{$ch} (original {$w}x{$h}); proporção " . round($cw / $ch, 4) . "\n";

// 5) ícones (favicon, iOS, Android/PWA): a logo COMPLETA sobre o vermelho da marca, com respiro. Em 16–48 px a assinatura inteira fica pequena
//    ("Desde 1977" não é legível nesses tamanhos); em 180/192/512 px ela é legível.
$icon = function (int $size, float $fill = 0.86) use ($resize): GdImage {
    $img = imagecreatetruecolor($size, $size); imagefill($img, 0, 0, imagecolorallocate($img, 196, 18, 31)); imagealphablending($img, true);
    $lw = (int)round($size * $fill); $logo = $resize($lw);
    imagecopy($img, $logo, intdiv($size - imagesx($logo), 2), intdiv($size - imagesy($logo), 2), 0, 0, imagesx($logo), imagesy($logo));
    return $img;
};
foreach ([32 => 'favicon-32', 48 => 'favicon-48', 180 => 'apple-touch-icon', 192 => 'icon-192', 512 => 'icon-512'] as $px => $name) { imagepng($icon($px, $px <= 48 ? 0.94 : 0.86), "$dir/$name.png", 9); echo "$name.png\n"; }
// favicon.ico com imagens PNG de 32 e 48 px
$imgs = []; foreach ([32, 48] as $px) $imgs[$px] = file_get_contents("$dir/favicon-$px.png");
$ico = pack('vvv', 0, 1, count($imgs)); $off = 6 + 16 * count($imgs); $data = '';
foreach ($imgs as $px => $png) { $ico .= pack('CCCCvvVV', $px, $px, 0, 0, 1, 32, strlen($png), $off + strlen($data)); $data .= $png; }
file_put_contents($root . '/public/favicon.ico', $ico . $data); echo "favicon.ico\n";
