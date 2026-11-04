<?php
declare(strict_types=1);
/**
 * Build de produção dos assets (sem Node): minifica CSS/JS para public/assets/dist/ e separa o CSS do site
 * em "core" (todas as páginas) e "shop" (cardápio/checkout/acompanhamento), marcado em site.css por /*@shop*​/ … /*@endshop*​/.
 *   php bin/build-assets.php           gera os arquivos
 *   php bin/build-assets.php --check   falha se dist/ estiver desatualizado em relação às fontes
 * O servidor usa dist/ quando APP_ENV=production (ou ASSETS_MIN=1) e o arquivo existe; em desenvolvimento usa as fontes legíveis.
 * Minificação ≠ compressão HTTP: gzip/Brotli são configurados no servidor (ver .htaccess e docs/DESEMPENHO.md).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');

function min_css(string $s): string
{
    $parts = preg_split('/("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/s', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) { $out .= "\x01" . base64_encode($p) . "\x02"; continue; } // protege strings
        $p = preg_replace('#/\*(?!\!).*?\*/#s', '', $p);                              // comentários
        $out .= $p;
    }
    $out = preg_replace('/\s+/', ' ', $out);
    $out = preg_replace('/\s*([{};>])\s*/', '$1', $out);                              // espaços ao redor de { } ; >
    $out = preg_replace('/:\s+/', ':', $out);                                         // espaço depois de ":" (nunca antes: seletor descendente)
    $out = preg_replace('/,\s+/', ',', $out);
    $out = preg_replace('/\(\s+/', '(', $out);
    $out = preg_replace('/\s+\)/', ')', $out);
    $out = preg_replace('/\s+!important/', '!important', $out);
    $out = str_replace(';}', '}', $out);
    $out = preg_replace_callback('/\x01([^\x02]*)\x02/', fn($m) => base64_decode($m[1]), $out);
    return trim($out);
}

/** Fim de uma string '…' ou "…" começando em $i (aspas em $s[$i]). */
function js_str_end(string $s, int $i): int
{
    $q = $s[$i]; $n = strlen($s);
    for ($i++; $i < $n; $i++) { if ($s[$i] === '\\') { $i++; continue; } if ($s[$i] === $q) return $i; }
    return $n - 1;
}
/** Fim de um template literal começando em $i (crase), tratando ${ … } aninhados. */
function js_tpl_end(string $s, int $i): int
{
    $n = strlen($s);
    for ($i++; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '\\') { $i++; continue; }
        if ($c === '`') return $i;
        if ($c === '$' && ($s[$i + 1] ?? '') === '{') {
            $depth = 1; $i += 2;
            for (; $i < $n && $depth > 0; $i++) {
                $d = $s[$i];
                if ($d === '\\') { $i++; continue; }
                if ($d === '"' || $d === "'") { $i = js_str_end($s, $i); continue; }
                if ($d === '`') { $i = js_tpl_end($s, $i); continue; }
                if ($d === '{') $depth++; elseif ($d === '}') $depth--;
            }
            $i--;
        }
    }
    return $n - 1;
}
function js_is_word(string $c): bool { return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80); }

/** Minificador conservador: remove comentários e espaços/indentação; preserva quebras de linha onde a ASI poderia mudar o sentido. */
function min_js(string $s): string
{
    $n = strlen($s); $o = ''; $i = 0; $nl = false; $sp = false; $last = ''; $word = '';
    $regexAfterWord = ['return', 'typeof', 'case', 'delete', 'void', 'in', 'of', 'instanceof', 'new', 'throw', 'yield', 'else', 'do', 'await'];
    $flush = function (string $next) use (&$o, &$nl, &$sp, &$last) {
        if ($nl) {
            // a quebra só pode sumir quando o contexto deixa claro que a instrução continua/termina
            $drop = $o === '' || str_contains('{([,;:?=*/%&|<>!~^', $last) && $last !== '' || str_contains(')]},;.:?', $next);
            if ($last === '+' || $last === '-') $drop = str_contains(')]},;.:?', $next);
            if (!$drop) $o .= "\n";
        } elseif ($sp) {
            if ((js_is_word($last) && js_is_word($next)) || ($last === '+' && $next === '+') || ($last === '-' && $next === '-') || ($last === '/' && $next === '/') || ($last === '+' && $next === '-') || ($last === '-' && $next === '+')) $o .= ' ';
        }
        $nl = $sp = false;
    };
    while ($i < $n) {
        $c = $s[$i];
        if ($c === "\n") { $nl = true; $i++; continue; }
        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\f" || $c === "\v") { $sp = true; $i++; continue; }
        if ($c === '/' && $i + 1 < $n) {
            $d = $s[$i + 1];
            if ($d === '/') { $e = strpos($s, "\n", $i); if ($e === false) break; $i = $e; continue; }
            if ($d === '*') { $e = strpos($s, '*/', $i + 2); $e = $e === false ? $n : $e + 2; if (str_contains(substr($s, $i, $e - $i), "\n")) $nl = true; else $sp = true; $i = $e; continue; }
        }
        // string
        if ($c === '"' || $c === "'") { $e = js_str_end($s, $i); $flush($c); $o .= substr($s, $i, $e - $i + 1); $last = $c; $word = ''; $i = $e + 1; continue; }
        // template
        if ($c === '`') { $e = js_tpl_end($s, $i); $flush($c); $o .= substr($s, $i, $e - $i + 1); $last = '`'; $word = ''; $i = $e + 1; continue; }
        // regex?
        if ($c === '/') {
            $isRe = $last === '' || str_contains('(,=:[!&|?{;+-*%<>~^', $last) || ($word !== '' && in_array($word, $regexAfterWord, true));
            if ($isRe) {
                $j = $i + 1; $cls = false; $ok = false;
                for (; $j < $n; $j++) {
                    $d = $s[$j];
                    if ($d === "\n") break;
                    if ($d === '\\') { $j++; continue; }
                    if ($d === '[') $cls = true; elseif ($d === ']') $cls = false; elseif ($d === '/' && !$cls) { $ok = true; break; }
                }
                if ($ok) { $j++; while ($j < $n && ctype_alpha($s[$j])) $j++; $flush('/'); $o .= substr($s, $i, $j - $i); $last = ')'; $word = ''; $i = $j; continue; }
            }
        }
        // palavra / número
        if (js_is_word($c)) {
            $j = $i; while ($j < $n && js_is_word($s[$j])) $j++;
            $w = substr($s, $i, $j - $i); $flush($c); $o .= $w; $last = $w[strlen($w) - 1]; $word = $w; $i = $j; continue;
        }
        $flush($c); $o .= $c; $last = $c; $word = ''; $i++;
    }
    return trim($o) . "\n";
}

$root = dirname(__DIR__) . '/public/assets'; $dist = $root . '/dist';
if (!is_dir($dist)) mkdir($dist, 0775, true);
$check = in_array('--check', $argv, true);

$site = file_get_contents($root . '/site.css');
$shop = ''; $core = preg_replace_callback('~/\*@shop\*/(.*?)/\*@endshop\*/~s', function ($m) use (&$shop) { $shop .= $m[1]; return ''; }, $site);
$jobs = [
    'site.min.css' => min_css($core), 'shop.min.css' => min_css($shop),
    'brand.min.css' => min_css(file_get_contents($root . '/brand.css')), 'admin.min.css' => min_css(file_get_contents($root . '/admin.css')), 'garcom.min.css' => min_css(file_get_contents($root . '/garcom.css')),
];
foreach (['app', 'motion', 'admin', 'garcom', 'picker', 'comanda'] as $js) $jobs["$js.min.js"] = min_js(file_get_contents("$root/$js.js"));

$stale = 0; $rows = [];
foreach ($jobs as $name => $code) {
    $f = "$dist/$name";
    $src = str_ends_with($name, '.css') ? ($name === 'site.min.css' || $name === 'shop.min.css' ? 'site.css' : str_replace('.min', '', $name)) : str_replace('.min', '', $name);
    $rows[] = [$name, strlen((string)file_get_contents("$root/$src")), strlen($code), strlen((string)gzencode($code, 9))];
    if ($check) { if (!is_file($f) || file_get_contents($f) !== $code) { $stale++; echo "desatualizado: $name\n"; } continue; }
    file_put_contents($f, $code);
}
if ($check) { echo $stale ? "dist/ desatualizado. Rode: php bin/build-assets.php\n" : "dist/ em dia.\n"; exit($stale ? 1 : 0); }
printf("%-18s %9s %9s %9s\n", 'arquivo', 'fonte', 'minificado', 'gzip -9');
foreach ($rows as [$n, $a, $b, $g]) printf("%-18s %9d %9d %9d\n", $n, $a, $b, $g);
echo "Obs.: site.min.css (core) + shop.min.css somam o site.css; a página inicial carrega só o core.\n";
