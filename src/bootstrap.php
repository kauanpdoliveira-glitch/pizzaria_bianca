<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// .env mínimo: KEY=valor, sem expansão
(function () {
    $f = APP_ROOT . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
        $k = trim($k);
        if (!isset($_ENV[$k]) && getenv($k) === false) $_ENV[$k] = $v; // variável de ambiente/valor já definido (ex.: testes e scripts apontando para uma cópia do banco) tem prioridade sobre o .env
    }
})();

function env(string $k, ?string $default = null): ?string
{
    return $_ENV[$k] ?? (getenv($k) !== false ? getenv($k) : $default);
}

date_default_timezone_set(env('APP_TZ', 'America/Sao_Paulo'));
mb_internal_encoding('UTF-8');

spl_autoload_register(function (string $c) {
    $f = APP_ROOT . '/src/' . $c . '.php';
    if (is_file($f)) require $f;
});

// ---------- helpers ----------
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int $cents): string { return 'R$ ' . number_format($cents / 100, 2, ',', '.'); }
function now(): string { return date('Y-m-d H:i:s'); }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }
function base_url(): string { return rtrim(env('APP_URL', ''), '/'); }
function url(string $p = ''): string { return base_url() . '/' . ltrim($p, '/'); }
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function redirect(string $to): never { header('Location: ' . $to); exit; }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function normalize_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    if (str_starts_with($d, '55') && strlen($d) > 11) $d = substr($d, 2);
    return $d;
}
function slugify(string $s): string
{
    $s = strtr(mb_strtolower($s), ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}
function parse_money(string $s): int
{
    $s = trim(str_replace(['R$', ' '], '', $s));
    if (str_contains($s, ',')) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return (int)round((float)$s * 100);
}
function app_log(string $msg): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', '[' . now() . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $msg, public array $extra = []) { parent::__construct($msg); }
}
