<?php
declare(strict_types=1);
/**
 * Agente de impressão (ponte local). Roda no computador da loja, só faz conexões de SAÍDA ao site.
 * Uso: php bin/print-agent.php [caminho/agent.ini]
 * agent.ini: url=https://seusite.com.br  token=...  mode=tcp|share|file  target=192.168.0.50:9100 | \\localhost\NomeDaImpressora | C:\caminho\saida.bin
 * Resultado: ok=true só quando o envio foi concluído. Erro DEPOIS de enviar bytes => sent=true (resultado incerto, risco de duplicar).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$cfg = parse_ini_file($argv[1] ?? __DIR__ . '/../agent.ini');
if (!$cfg || empty($cfg['url']) || empty($cfg['token']) || empty($cfg['mode']) || empty($cfg['target'])) exit("Configure agent.ini (veja agent.ini.example).\n");
$base = rtrim($cfg['url'], '/');
$every = max(2, (int)($cfg['poll_seconds'] ?? 3));

function http(string $url, string $token, ?array $body = null): array
{
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n", 'content' => json_encode($body ?? new stdClass())]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException('sem conexão com o servidor');
    $code = (int)(preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    if ($code === 401) throw new RuntimeException('token recusado (401) — gere um novo em Impressão');
    return json_decode($raw, true) ?: [];
}

/** @return array{ok:bool,sent:bool,error:string} */
function output(array $cfg, string $bytes): array
{
    try {
        switch ($cfg['mode']) {
            case 'tcp':
                [$host, $port] = array_pad(explode(':', $cfg['target']), 2, '9100');
                $s = @stream_socket_client("tcp://$host:$port", $en, $es, 5);
                if (!$s) return ['ok' => false, 'sent' => false, 'error' => "não conectou à impressora ($es)"];
                $w = @fwrite($s, $bytes); fclose($s);
                return $w === strlen($bytes) ? ['ok' => true, 'sent' => true, 'error' => ''] : ['ok' => false, 'sent' => true, 'error' => 'envio parcial à impressora'];
            case 'share': // impressora compartilhada do Windows: copy /b arquivo \\localhost\Nome
                $tmp = tempnam(sys_get_temp_dir(), 'bianca'); file_put_contents($tmp, $bytes);
                exec('copy /b ' . escapeshellarg($tmp) . ' ' . escapeshellarg($cfg['target']) . ' 2>&1', $out, $rc); @unlink($tmp);
                return $rc === 0 ? ['ok' => true, 'sent' => true, 'error' => ''] : ['ok' => false, 'sent' => false, 'error' => 'copy falhou: ' . implode(' ', $out)];
            case 'file':
                return file_put_contents($cfg['target'], $bytes, FILE_APPEND) !== false ? ['ok' => true, 'sent' => true, 'error' => ''] : ['ok' => false, 'sent' => false, 'error' => 'não gravou arquivo'];
        }
        return ['ok' => false, 'sent' => false, 'error' => 'mode inválido'];
    } catch (Throwable $e) { return ['ok' => false, 'sent' => false, 'error' => $e->getMessage()]; }
}

echo "Agente Bianca iniciado ({$cfg['mode']} → {$cfg['target']}). Ctrl+C para sair.\n";
while (true) {
    try {
        $r = http("$base/api/print/claim", $cfg['token']);
        if (!empty($r['job'])) {
            $j = $r['job'];
            $res = output($cfg, base64_decode($j['escpos_b64']));
            echo date('H:i:s') . " {$j['sector']} {$j['uuid']} tentativa {$j['attempt']}: " . ($res['ok'] ? 'ok' : 'FALHA ' . $res['error']) . "\n";
            // se o resultado não puder ser informado, o servidor marca como "incerta" após 2 minutos
            http("$base/api/print/{$j['uuid']}/result", $cfg['token'], $res);
            continue; // há mais trabalhos? pega o próximo sem esperar
        }
    } catch (Throwable $e) { echo date('H:i:s') . ' ' . $e->getMessage() . "\n"; sleep(10); }
    sleep($every);
}
