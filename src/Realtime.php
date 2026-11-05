<?php
declare(strict_types=1);

/**
 * Notificação de "houve alteração" pelo Supabase Realtime (canal BROADCAST PRIVADO "orders-ping").
 *
 * Arquitetura (nenhum dado de pedido trafega pelo canal):
 *  1. Depois de qualquer alteração (POST) o SERVIDOR publica {rev} no canal privado, autenticado com a chave secreta (só no servidor).
 *  2. O navegador do painel só assina o canal com um token de curta duração de um usuário técnico do Supabase Auth ("painel-realtime"),
 *     entregue por /admin/api/realtime SOMENTE a sessões com permissão "orders". A política RLS em realtime.messages deixa esse usuário
 *     apenas LER o tópico "orders-ping" (veja bin/realtime-setup.php). Esse token não lê nenhuma tabela (RLS ligada, sem políticas).
 *  3. Ao receber o aviso, o painel consulta /admin/api/orders?rev= (API autenticada pela sessão PHP, com as permissões do usuário).
 * Sem SUPABASE_URL / SUPABASE_PUBLISHABLE_KEY / SUPABASE_SECRET_KEY o recurso fica desligado e o painel usa só o polling incremental.
 * O polling continua como rede de segurança (mais lento quando o canal está conectado).
 */
final class Realtime
{
    public const TOPIC = 'orders-ping';
    public const EMAIL = 'realtime@pizzariabianca.vercel.app';

    private static function cfg(): ?array
    {
        $u = (string)env('SUPABASE_URL', ''); $pk = (string)env('SUPABASE_PUBLISHABLE_KEY', ''); $sk = (string)env('SUPABASE_SECRET_KEY', '');
        if ($u === '' || $pk === '' || $sk === '' || !preg_match('#^https://[a-z0-9]{10,40}\.supabase\.co$#', rtrim($u, '/'))) return null;
        return ['url' => rtrim($u, '/'), 'pk' => $pk, 'sk' => $sk];
    }
    public static function enabled(): bool { return self::cfg() !== null; }
    /** Host para o connect-src do CSP (ou null). */
    public static function wsHost(): ?string { $c = self::cfg(); return $c ? parse_url($c['url'], PHP_URL_HOST) : null; }
    private static function password(array $c): string { return hash_hmac('sha256', 'bianca-realtime-v1', $c['sk']); } // derivada: nada novo para guardar

    /** @return array{0:int,1:array} status HTTP e corpo JSON */
    private static function http(string $method, string $url, array $headers, ?array $body, int $timeoutMs): array
    {
        $payload = $body === null ? null : json_encode($body);
        $hdr = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 1500), CURLOPT_POSTFIELDS => $payload]);
            $raw = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $hdr), 'content' => $payload ?? '', 'timeout' => $timeoutMs / 1000, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx); $st = 0;
            foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $st = (int)$m[1];
        }
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return [$st, is_array($j) ? $j : []];
    }

    /** Token de curta duração do usuário técnico (cache em settings). Cria o usuário na primeira vez. @return ?array{token:string,exp:int} */
    public static function token(): ?array
    {
        $c = self::cfg(); if (!$c) return null;
        $cached = json_decode(Settings::get('rt_token'), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 600 && !empty($cached['token'])) return $cached;
        $login = fn() => self::http('POST', $c['url'] . '/auth/v1/token?grant_type=password', ['apikey: ' . $c['pk']], ['email' => self::EMAIL, 'password' => self::password($c)], 5000);
        [$st, $j] = $login();
        if ($st !== 200 || empty($j['access_token'])) {
            self::http('POST', $c['url'] . '/auth/v1/admin/users', ['apikey: ' . $c['sk'], 'Authorization: Bearer ' . $c['sk']], ['email' => self::EMAIL, 'password' => self::password($c), 'email_confirm' => true], 5000);
            [$st, $j] = $login();
        }
        if ($st !== 200 || empty($j['access_token'])) { app_log('realtime: não foi possível obter o token (HTTP ' . $st . ')'); return null; }
        $tok = ['token' => (string)$j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3600)];
        Settings::set('rt_token', json_encode($tok));
        return $tok;
    }

    /** Publica o aviso mínimo (só a revisão). Nunca lança: falha no canal não pode afetar pedido nem impressão. */
    public static function publish(): void
    {
        $c = self::cfg(); if (!$c) return;
        try {
            self::http('POST', $c['url'] . '/realtime/v1/api/broadcast', ['apikey: ' . $c['sk'], 'Authorization: Bearer ' . $c['sk']],
                ['messages' => [['topic' => self::TOPIC, 'event' => 'changed', 'payload' => ['t' => time()], 'private' => true]]], 1200);
        } catch (Throwable $e) { /* só polling */ }
    }
}
