<?php
declare(strict_types=1);

/**
 * Assinatura de requisições do QZ Tray — SIGNER VALIDADOR (nunca assina conteúdo arbitrário).
 *
 * O qz-tray.js calcula SHA-256 do JSON {call, params, timestamp} e pede a assinatura desse hash. O navegador da estação envia aqui o
 * JSON original; o servidor (1) valida chamada, parâmetros, tempo, estação e trabalho, (2) calcula ele mesmo o hash e (3) assina o hash
 * com a chave PRIVADA, que só existe no servidor (arquivo fora de public/ ou variável de ambiente). A chave nunca vai ao JavaScript.
 *
 * Regras: só "printers.find" e "print"; "print" só para o trabalho em estado "sending" desta estação, impressora = a mapeada,
 * dado = um único bloco raw/base64 cujo SHA-256 é o payload_hash registrado no claim; timestamp ±90 s; mensagem idêntica nunca é
 * assinada duas vezes (anti-replay, índice único no digest); limite de 120 assinaturas por minuto por estação; tudo é registrado.
 * Certificado e chave de confiança de produção NÃO são fornecidos aqui (ver docs/IMPRESSAO.md).
 */
final class QzSigner
{
    /** Portas locais do QZ Tray (wss 8181/8282/8383/8484, ws 8182/8283/8384/8485): só a página da estação pode falar com elas (CSP connect-src). */
    public const CONNECT_SRC = 'wss://localhost:8181 wss://localhost:8282 wss://localhost:8383 wss://localhost:8484 ws://localhost:8182 ws://localhost:8283 ws://localhost:8384 ws://localhost:8485 wss://localhost.qz.io:8181 wss://localhost.qz.io:8282 wss://localhost.qz.io:8383 wss://localhost.qz.io:8484';
    private const CALLS =['printers.find', 'print'];
    private const MAX_PER_MIN = 120;
    private const MAX_MSG = 262144;
    private const SKEW_MS = 90000;

    private static function load(string $envPath, string $envInline, string $default): ?string
    {
        $inline = env($envInline);
        if ($inline) return str_replace('\n', "\n", $inline);
        $p = env($envPath) ?: $default;
        if ($p[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $p)) $p = APP_ROOT . '/' . $p;
        return is_file($p) ? (string)file_get_contents($p) : null;
    }
    public static function certificate(): ?string { return self::load('QZ_CERT_PATH', 'QZ_CERT', 'storage/qz/digital-certificate.txt'); }
    private static function privateKey(): ?string { return self::load('QZ_PRIVATE_KEY_PATH', 'QZ_PRIVATE_KEY', 'storage/qz/private-key.pem'); }

    /** @return array{configured:bool,reason:string,subject:?string,expires:?string} (nunca expõe a chave) */
    public static function status(): array
    {
        $c = self::certificate(); $k = self::privateKey();
        if (!$c || !$k) return ['configured' => false, 'reason' => 'Certificado/chave do QZ não configurados: o QZ Tray pedirá confirmação manual a cada impressão (modo não silencioso).', 'subject' => null, 'expires' => null];
        $x = @openssl_x509_parse($c); $pk = @openssl_pkey_get_private($k);
        if (!$x || !$pk) return ['configured' => false, 'reason' => 'Certificado ou chave inválidos.', 'subject' => null, 'expires' => null];
        if (!@openssl_x509_check_private_key($c, $pk)) return ['configured' => false, 'reason' => 'A chave privada não corresponde ao certificado.', 'subject' => null, 'expires' => null];
        return ['configured' => true, 'reason' => 'ok', 'subject' => $x['name'] ?? null, 'expires' => date('d/m/Y', (int)$x['validTo_time_t'])];
    }

    /** Valida e assina. @return array{signature:string} @throws HttpError */
    public static function sign(array $station, array $user, string $message): array
    {
        $sid = (int)$station['id'];
        if (!$station['active']) throw new HttpError(403, 'Estação desativada.');
        $digest = hash('sha256', $message);
        $logId = null;
        try {
            if (strlen($message) > self::MAX_MSG) self::deny('mensagem grande demais');
            if ((int)Db::val('SELECT COUNT(*) FROM print_sign_log WHERE station_id = ? AND at >= ?', [$sid, date('Y-m-d H:i:s', time() - 60)]) >= self::MAX_PER_MIN) throw new HttpError(429, 'Limite de assinaturas atingido.');
            if (!self::status()['configured']) throw new HttpError(503, 'Assinatura não configurada neste servidor.');
            $req = json_decode($message, true);
            if (!is_array($req) || array_diff(array_keys($req), ['call', 'params', 'timestamp']) || !isset($req['call'], $req['timestamp'])) self::deny('formato');
            if (!is_string($req['call']) || !in_array($req['call'], self::CALLS, true)) self::deny('chamada não permitida');
            if (!is_int($req['timestamp']) && !is_float($req['timestamp'])) self::deny('timestamp');
            if (abs((float)$req['timestamp'] - microtime(true) * 1000) > self::SKEW_MS) self::deny('timestamp fora da janela');
            try { $logId = Db::insert('print_sign_log', ['station_id' => $sid, 'call_name' => $req['call'], 'digest' => $digest, 'user_name' => $user['name'], 'ok' => 0, 'reason' => 'em validação', 'at' => now()]); }
            catch (PDOException $e) { throw new HttpError(409, 'Requisição já assinada (repetição recusada).'); }
            $jobId = $req['call'] === 'print' ? self::checkPrint($station, (array)($req['params'] ?? [])) : self::checkFind((array)($req['params'] ?? []));
            $key = openssl_pkey_get_private((string)self::privateKey());
            if (!$key || !openssl_sign($digest, $sig, $key, OPENSSL_ALGO_SHA512)) throw new HttpError(500, 'Falha ao assinar.');
            Db::exec('UPDATE print_sign_log SET ok = 1, reason = NULL, job_id = ? WHERE id = ?', [$jobId, $logId]);
            return ['signature' => base64_encode($sig), 'digest' => $digest];
        } catch (HttpError $e) {
            if ($logId) Db::exec('UPDATE print_sign_log SET ok = 0, reason = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 150), $logId]);
            elseif (!in_array($e->getMessage(), ['Requisição já assinada (repetição recusada).'], true)) {
                try { Db::insert('print_sign_log', ['station_id' => $sid, 'call_name' => 'recusada', 'digest' => hash('sha256', $digest . microtime()), 'user_name' => $user['name'], 'ok' => 0, 'reason' => mb_substr($e->getMessage(), 0, 150), 'at' => now()]); } catch (Throwable $ignored) {}
            }
            throw $e;
        }
    }

    private static function deny(string $why): never { throw new HttpError(403, 'Assinatura recusada: ' . $why . '.'); }

    private static function checkFind(array $p): ?int
    {
        if (array_diff(array_keys($p), ['query'])) self::deny('parâmetros de busca');
        if (isset($p['query']) && (!is_string($p['query']) || mb_strlen($p['query']) > 100)) self::deny('consulta de impressoras');
        return null;
    }

    /** Padrões de qz.configs (qz-tray.js 2.3.0, _qz.printing.defaultConfig) + nomes antigos que a biblioteca pode acrescentar por compatibilidade. */
    private const QZ_DEFAULTS = ['bounds' => null, 'colorType' => 'color', 'copies' => 1, 'density' => 0, 'duplex' => false, 'fallbackDensity' => null, 'interpolation' => 'bicubic', 'jobName' => null, 'legacy' => false,
        'margins' => 0, 'orientation' => null, 'paperThickness' => null, 'printerTray' => null, 'rasterize' => false, 'rotation' => 0, 'scaleContent' => true, 'size' => null, 'units' => 'in', 'forceRaw' => false, 'altPrinting' => false, 'encoding' => null, 'spool' => null];

    /** As opções reais do QZ trazem todos os padrões; aceita-se cada chave só se for o padrão ou o valor que o servidor definiu para esta impressora. */
    private static function checkOptions(array $given, array $expect): void
    {
        $want = $expect + self::QZ_DEFAULTS;
        foreach ($given as $k => $v) {
            if (!array_key_exists($k, $want)) self::deny('opção de impressão desconhecida (' . mb_substr((string)$k, 0, 30) . ')');
            $w = $want[$k];
            if (is_array($v) || is_array($w) ? $v !== $w : ($v !== $w && !(is_numeric($v) && is_numeric($w) && (float)$v === (float)$w))) self::deny('opção de impressão fora do esperado (' . $k . ')');
        }
    }

    /** Valida o envio: trabalho "sending" desta estação, impressora mapeada e bytes idênticos aos reservados. */
    private static function checkPrint(array $station, array $p): int
    {
        $sid = (int)$station['id'];
        if (array_diff(array_keys($p), ['printer', 'options', 'data'])) self::deny('parâmetros de impressão');
        $j = Db::one("SELECT * FROM print_jobs WHERE station_id = ? AND status = 'sending' ORDER BY id LIMIT 1", [$sid]);
        if (!$j) self::deny('nenhum trabalho em envio nesta estação');
        $m = Db::one('SELECT * FROM print_station_map WHERE station_id = ? AND sector = ?', [$sid, $j['sector']]);
        if (!$m) self::deny('destino sem impressora mapeada');
        $name = is_array($p['printer'] ?? null) ? ($p['printer']['name'] ?? null) : ($p['printer'] ?? null);
        if (!is_string($name) || !hash_equals($m['printer_name'], $name)) self::deny('impressora diferente da mapeada');
        self::checkOptions((array)($p['options'] ?? []), PrintStation::qzOptions($m, max(1, (int)$j['copies'])));
        $d = $p['data'] ?? null;
        if (!is_array($d) || count($d) !== 1 || !is_array($d[0] ?? null)) self::deny('dados de impressão');
        $d = $d[0];
        $html = ($m['mode'] ?? 'escpos') === 'html';
        $type = $html ? ['pixel', 'html', 'plain'] : ['raw', 'command', 'base64'];
        if (array_diff(array_keys($d), ['type', 'format', 'flavor', 'data', 'options']) || ($d['type'] ?? '') !== $type[0] || ($d['format'] ?? '') !== $type[1] || ($d['flavor'] ?? '') !== $type[2] || array_filter((array)($d['options'] ?? []), fn($v) => $v !== null && $v !== '' && $v !== [])) self::deny('formato dos dados');
        $bytes = !is_string($d['data'] ?? null) ? false : ($html ? $d['data'] : base64_decode($d['data'], true));
        if ($bytes === false || !$j['payload_hash'] || !hash_equals($j['payload_hash'], hash('sha256', $bytes))) self::deny('conteúdo diferente do reservado');
        return (int)$j['id'];
    }
}
